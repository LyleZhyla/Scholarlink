<?php
session_start();
include '../includes/db_connect.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../student_login.php");
    exit();
}

// ✨ KUNIN ANG API KEY MULA SA .ENV
$envPath = __DIR__ . '/../.env';
$GEMINI_API_KEY = '';
if (file_exists($envPath)) {
    $envVars = parse_ini_file($envPath);
    $GEMINI_API_KEY = $envVars['GEMINI_API_KEY'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $renewal_id = $_POST['renewal_id'] ?? 0;
    $is_appeal = $_POST['is_appeal'] ?? 0;

    if ($renewal_id == 0) { die("Invalid Renewal ID"); }

    // ✨ Students can only submit their own renewal, and only at the step that expects it
    $own_stmt = $pdo->prepare("SELECT Status FROM scholar_renewals WHERE RenewalID = ? AND UserID = ?");
    $own_stmt->execute([$renewal_id, $_SESSION['user_id']]);
    $current_status = $own_stmt->fetchColumn();
    $expected_status = ($is_appeal == 1) ? 'Appeal_Requested' : 'Pending_Submission';

    if ($current_status !== $expected_status) {
        $_SESSION['error'] = "This renewal cannot be submitted right now.";
        header("Location: ../student/dashboard.php");
        exit();
    }

    $upload_dir = '../uploads/renewals/';
    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

    // ========================================================
    // SCENARIO A: STUDENT UPLOADS APPEAL LETTER
    // ========================================================
    if ($is_appeal == 1) {
        if (isset($_FILES['appeal_file']) && $_FILES['appeal_file']['error'] == 0) {
            $ext = pathinfo($_FILES['appeal_file']['name'], PATHINFO_EXTENSION);
            $appeal_path = $upload_dir . 'appeal_' . $renewal_id . '_' . time() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['appeal_file']['tmp_name'], $appeal_path)) {
                $stmt = $pdo->prepare("UPDATE scholar_renewals SET Appeal_FilePath = ?, Status = 'Under_Review', DateSubmitted = CURRENT_TIMESTAMP WHERE RenewalID = ?");
                $stmt->execute([$appeal_path, $renewal_id]);
                
                $_SESSION['success'] = "Appeal letter submitted. The OSSD will review your case.";
                header("Location: ../student/dashboard.php");
                exit();
            }
        }
    } 
    // ========================================================
    // SCENARIO B: INITIAL RENEWAL UPLOAD (DUAL AI SCAN)
    // ========================================================
    else {
        if (isset($_FILES['rog_file']) && isset($_FILES['cor_file'])) {
            
            $rog_ext = pathinfo($_FILES['rog_file']['name'], PATHINFO_EXTENSION);
            $cor_ext = pathinfo($_FILES['cor_file']['name'], PATHINFO_EXTENSION);
            
            $rog_path = $upload_dir . 'rog_' . $renewal_id . '_' . time() . '.' . $rog_ext;
            $cor_path = $upload_dir . 'cor_' . $renewal_id . '_' . time() . '.' . $cor_ext;

            if (move_uploaded_file($_FILES['rog_file']['tmp_name'], $rog_path) && move_uploaded_file($_FILES['cor_file']['tmp_name'], $cor_path)) {
                
                // 1. KUNIN ANG ACTIVE ACADEMIC TERM
                $term_stmt = $pdo->query("SELECT TermName FROM academic_terms WHERE Status = 'Active' LIMIT 1");
                $active_term = $term_stmt->fetchColumn() ?: "Unknown Semester";

                // 2. THE DUAL-DOCUMENT AI SCANNER ENGINE
                $extracted_gwa = null;
                $ai_remarks = "AI Scan failed or API key missing.";

                if (!empty($GEMINI_API_KEY)) {
                    $rog_mime = mime_content_type($rog_path);
                    $rog_data = base64_encode(file_get_contents($rog_path));
                    
                    $cor_mime = mime_content_type($cor_path);
                    $cor_data = base64_encode(file_get_contents($cor_path));
                    
                    // ✨ NA-UPDATE NA PROMPT NA MAY COMPPUTATION LOGIC
                    $prompt = "You are a strict AI Registrar and Auditor for a Philippine University. The current active semester is '{$active_term}'. I am providing two files: 1st is the Report of Grades (ROG), 2nd is the Certificate of Registration (COR). 
                    Tasks: 
                    1. COMPUTE the General Weighted Average (GWA) from the ROG by multiplying each subject's grade by its corresponding units, summing them up, and dividing by the total units: GWA = sum(Grade * Units) / sum(Units). Provide the final computed numerical GWA.
                    2. Check the Semester and Year indicated on the ROG.
                    3. Check the Semester and Year indicated on the COR.
                    4. Verify if BOTH documents match each other AND if they match the current active semester ('{$active_term}').
                    Respond STRICTLY in JSON format: {\"extracted_gwa\": <computed number rounded to 2 decimal places>, \"ai_remarks\": \"<Detail the computation breakdown or steps briefly, and state clearly if the semesters match the current active term and each other>\"}";
                    
                    $payload = [
                        "contents" => [
                            [
                                "parts" => [
                                    ["text" => $prompt],
                                    ["inline_data" => ["mime_type" => $rog_mime, "data" => $rog_data]],
                                    ["inline_data" => ["mime_type" => $cor_mime, "data" => $cor_data]]
                                ]
                            ]
                        ],
                        "generationConfig" => ["response_mime_type" => "application/json"]
                    ];

                    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=" . $GEMINI_API_KEY);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($http_code === 200) {
                        $resultData = json_decode($response, true);
                        $ai_text = $resultData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
                        $ai_json = json_decode(trim($ai_text), true);
                        
                        $extracted_gwa = $ai_json['extracted_gwa'] ?? null;
                        $ai_remarks = $ai_json['ai_remarks'] ?? "Semester and computation verification unclear.";
                    }
                }

                // 3. I-UPDATE ANG DATABASE (ALWAYS 'Under_Review' FOR MANUAL ADMIN DECISION)
                $update_stmt = $pdo->prepare("
                    UPDATE scholar_renewals 
                    SET ROG_FilePath = ?, COR_FilePath = ?, AI_Extracted_GWA = ?, AI_Remarks = ?, Status = 'Under_Review', DateSubmitted = CURRENT_TIMESTAMP 
                    WHERE RenewalID = ?
                ");
                $update_stmt->execute([$rog_path, $cor_path, $extracted_gwa, $ai_remarks, $renewal_id]);

                // 4. BALIK SA DASHBOARD
                $_SESSION['success'] = "Documents submitted! Please wait for the OSSD to review your submission.";
                header("Location: ../student/dashboard.php");
                exit();
            }
        }
    }
}
?>