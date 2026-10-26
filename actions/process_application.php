<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../includes/session_manager.php';
require '../includes/db_connect.php';
require '../includes/scholarship_eligibility.php';

// 1. SECURITY CHECK
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scholarship_id'])) {
    $user_id = $_SESSION['user_id'];
    $scholarship_id = (int)$_POST['scholarship_id'];

    try {
        $eligibility = scholarshipEligibilityForStudent($pdo, (int)$user_id, $scholarship_id);
        if (!$eligibility['eligible']) {
            $_SESSION['error'] = scholarshipEligibilityError($eligibility);
            header("Location: ../student/programs.php");
            exit();
        }

        // ✨ Gagamit tayo ng Transaction para kapag pumalya ang document upload, maka-cancel ang buong application
        $pdo->beginTransaction();

        // 2. FETCH STUDENT GPA
        $user_stmt = $pdo->prepare("SELECT GPA FROM users WHERE UserID = ?");
        $user_stmt->execute([$user_id]);
        $student = $user_stmt->fetch(PDO::FETCH_ASSOC);
        $student_gwa = $eligibility['student_gwa'];

        // 3. CREATE APPLICATION
        $app_stmt = $pdo->prepare("
            INSERT INTO application (ScholarshipID, UserID, Status, GPA, DateSubmitted) 
            VALUES (?, ?, 'Submitted', ?, NOW())
        ");
        $app_stmt->execute([$scholarship_id, $user_id, $student_gwa]);
        $application_id = $pdo->lastInsertId();

        // 4. FETCH SCHOLARSHIP REQUIREMENTS
        $req_stmt = $pdo->prepare("SELECT RequirementID, DocumentName FROM document_requirement WHERE ScholarshipID = ?");
        $req_stmt->execute([$scholarship_id]);
        $requirements = $req_stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. FETCH STUDENT'S VAULT DOCUMENTS
        $vault_stmt = $pdo->prepare("SELECT DocumentType, FilePath FROM user_vault WHERE UserID = ?");
        $vault_stmt->execute([$user_id]);
        $vault_docs = $vault_stmt->fetchAll(PDO::FETCH_ASSOC);

        // 6. THE BULLETPROOF COPY-PASTE MAGIC
        $target_dir = "../uploads/documents/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

        $doc_insert_stmt = $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')");

        $docs_inserted = 0;

        foreach ($requirements as $req) {
            // Gawing lowercase at tanggalin ang spaces para 100% mag-match
            $reqName = strtolower(trim($req['DocumentName']));
            $final_file_path = null;
            
            // Hanapin ang katumbas na dokumento sa Vault
            foreach ($vault_docs as $v) {
                if (strtolower(trim($v['DocumentType'])) === $reqName) {
                    $vault_file_path = $v['FilePath'];
                    $final_file_path = $vault_file_path; // Default Fallback kung "dummy data" lang ito
                    
                    // Kopyahin ang physical file KUNG NAG-E-EXIST sa folder
                    if (file_exists($vault_file_path)) {
                        $file_ext = pathinfo($vault_file_path, PATHINFO_EXTENSION);
                        $new_file_name = "STU-" . $user_id . "-APP-" . $application_id . "-REQ-" . $req['RequirementID'] . "-" . uniqid() . "." . $file_ext;
                        $target_path = $target_dir . $new_file_name;

                        if (copy($vault_file_path, $target_path)) {
                            $final_file_path = $target_path;
                        }
                    }
                    
                    // Isave sa Submitted Documents
                    $doc_insert_stmt->execute([$application_id, $req['RequirementID'], $final_file_path]);
                    $docs_inserted++;
                    break; // Move to next requirement
                }
            }
        }

        // ✨ FAILSAFE: Kung walang nai-save na dokumento, bawiin (rollback) ang application!
        if ($docs_inserted < count($requirements)) {
            $pdo->rollBack();
            $_SESSION['error'] = "System Error: Hindi nagtugma ang pangalan ng iyong Vault documents sa hinihinging requirements. Paki-check ang iyong Vault.";
            header("Location: ../student/scholarships.php");
            exit();
        }

        // 7. SUCCESS REDIRECT
        $pdo->commit(); // I-save na lahat dahil successful!
        $_SESSION['success'] = "⚡ 1-Click Application submitted successfully!";
        header("Location: ../student/applications.php");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = "🚨 Database Error: " . $e->getMessage();
        header("Location: ../student/scholarships.php");
        exit();
    }
} else {
    die("Invalid Request.");
}
?>
