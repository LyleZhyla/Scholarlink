<?php
// ✨ SCHOLAR RENEWAL FOR A NEW SEMESTER (student/renewal.php)
//   Submit: the Report of Grades + every other requirement of the semester and of the scholarship (includes/semester.php);
//           the COR is the one uploaded at the new-semester wall. The AI reads the GWA and checks the two documents agree.
//   Appeal: when the OSSD asks for it, a letter of appeal.
// All of it or nothing: a refused file leaves the renewal as it was.
session_start();
include '../includes/db_connect.php';
require_once '../includes/semester.php';
require_once '../includes/document_security.php';
require_once '../includes/api_config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../student_login.php");
    exit();
}
$user_id = (int) $_SESSION['user_id'];
$back = fn(string $kind, string $msg) => [$_SESSION[$kind] = $msg, header('Location: ../student/renewal.php'), exit()];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../student/renewal.php'); exit(); }

$renewal_id = (int) ($_POST['renewal_id'] ?? 0);
$is_appeal = (int) ($_POST['is_appeal'] ?? 0) === 1;

// Students can only submit their own renewal, and only at the step that expects it
$own = $pdo->prepare("SELECT r.*, a.ScholarshipID, t.TermName, t.Semester, t.SchoolYear FROM scholar_renewals r JOIN application a ON a.ApplicationID = r.ApplicationID
    JOIN academic_terms t ON t.TermID = r.TermID WHERE r.RenewalID = ? AND r.UserID = ?");
$own->execute([$renewal_id, $user_id]);
$renewal = $own->fetch(PDO::FETCH_ASSOC);
if (!$renewal || $renewal['Status'] !== ($is_appeal ? 'Appeal_Requested' : 'Pending_Submission')) $back('error', 'This renewal cannot be submitted right now.');

$upload_dir = dirname(__DIR__) . '/uploads/renewals/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);
$saved = [];   // files written by this attempt (removed if it fails)
// a file checked by its real contents, saved under a name of ours; returns its path as stored ('../uploads/renewals/...')
$store = function (array $file, string $label) use ($upload_dir, $user_id, $renewal_id, &$saved): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException("Upload your $label.");
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new RuntimeException("Your $label is larger than 10MB.");
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException("Your $label could not be uploaded. Please try again.");
    $meta = inspectDocumentFile($file['tmp_name'], (string) $file['name']);
    $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $label);
    $name = "STU-$user_id-RENEW-$renewal_id-" . substr($slug, 0, 30) . '-' . bin2hex(random_bytes(5)) . '.' . $meta['extension'];
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $name)) throw new RuntimeException("Your $label could not be saved. Please try again.");
    $saved[] = $upload_dir . $name;
    return ['path' => '../uploads/renewals/' . $name, 'meta' => $meta];
};

try {
    if ($is_appeal) {
        $letter = $store($_FILES['appeal_file'] ?? [], 'letter of appeal');
        $pdo->prepare("UPDATE scholar_renewals SET Appeal_FilePath = ?, Status = 'Under_Review', DateSubmitted = CURRENT_TIMESTAMP WHERE RenewalID = ?")->execute([$letter['path'], $renewal_id]);
        logAudit($pdo, $user_id, 'Renewal Appeal Submitted', "Letter of appeal for renewal #$renewal_id ({$renewal['TermName']})");
        $back('success', 'Appeal letter submitted. The OSSD will review your case.');
    }

    // The COR: the one from the new-semester wall
    $cor = studentTermCor($pdo, $user_id, (int) $renewal['TermID']);
    if (!$cor || $cor['AIStatus'] === 'Rejected') throw new RuntimeException('Upload your COR for this semester first.');

    // the file sent for requirement $id (req[<id>] in the form; 0 is the Report of Grades)
    $file_for = fn(int $id) => isset($_FILES['req']['tmp_name'][$id])
        ? ['name' => $_FILES['req']['name'][$id], 'tmp_name' => $_FILES['req']['tmp_name'][$id], 'error' => $_FILES['req']['error'][$id], 'size' => $_FILES['req']['size'][$id]]
        : [];
    $requirements = renewalRequirementsFor($pdo, (int) $renewal['TermID'], (int) $renewal['ScholarshipID']);
    $rog = $store($file_for(0), 'Report of Grades');
    $extras = [];
    foreach ($requirements as $req) {
        $id = (int) $req['RequirementID'];
        if ($id !== 0) $extras[$id] = $store($file_for($id), $req['Name']);
    }

    // The AI reads the GWA from the grades and checks the grades and the COR are for this semester (when it is set up)
    $extracted_gwa = null;
    $ai_remarks = 'AI scan not available: the OSSD will check the documents.';
    if (GEMINI_API_KEY !== '') {
        $rog_abs = dirname(__DIR__) . '/uploads/renewals/' . basename($rog['path']);
        $cor_abs = __DIR__ . '/' . $cor['FilePath'];
        $term = termDisplayName($renewal);
        $prompt = "You are a strict registrar for a Philippine university. The current semester is '$term'. The 1st file is the student's Report of Grades (ROG) for last semester; the 2nd is their Certificate of Registration (COR) for the current semester.\n"
            . "1. COMPUTE the GWA from the ROG: multiply each subject's final grade by its units, add them up, divide by the total units.\n"
            . "2. Check the COR is for '$term'.\n"
            . "Respond ONLY in JSON: {\"extracted_gwa\": <number, 2 decimals>, \"ai_remarks\": \"<short computation and whether the COR matches the semester>\"}";
        $payload = ['contents' => [['parts' => [['text' => $prompt],
            ['inline_data' => ['mime_type' => $rog['meta']['mime_type'], 'data' => base64_encode((string) file_get_contents($rog_abs))]],
            ['inline_data' => ['mime_type' => (string) $cor['MimeType'], 'data' => base64_encode((string) @file_get_contents($cor_abs))]]]]],
            'generationConfig' => ['response_mime_type' => 'application/json', 'temperature' => 0]];
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . GEMINI_API_KEY);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response !== false && $code === 200) {
            $text = (string) (json_decode($response, true)['candidates'][0]['content']['parts'][0]['text'] ?? '{}');
            $ai = json_decode(trim(preg_replace(['/^```(?:json)?\s*/i', '/\s*```$/'], '', trim($text))), true) ?: [];
            $extracted_gwa = is_numeric($ai['extracted_gwa'] ?? null) ? round((float) $ai['extracted_gwa'], 2) : null;
            $ai_remarks = (string) ($ai['ai_remarks'] ?? 'Semester and computation verification unclear.');
        }
    }

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE scholar_renewals SET ROG_FilePath = ?, COR_FilePath = ?, AI_Extracted_GWA = ?, AI_Remarks = ?, Status = 'Under_Review', DateSubmitted = CURRENT_TIMESTAMP WHERE RenewalID = ?")
        ->execute([$rog['path'], $cor['FilePath'], $extracted_gwa, mb_substr($ai_remarks, 0, 2000), $renewal_id]);
    $sub = $pdo->prepare("INSERT INTO renewal_submissions (RenewalID, RequirementID, FilePath, OriginalFileName, MimeType, FileSize, FileChecksum) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE FilePath = VALUES(FilePath), OriginalFileName = VALUES(OriginalFileName), MimeType = VALUES(MimeType), FileSize = VALUES(FileSize), FileChecksum = VALUES(FileChecksum), UploadedAt = NOW()");
    foreach ($extras as $id => $f) $sub->execute([$renewal_id, $id, $f['path'], $f['meta']['original_name'], $f['meta']['mime_type'], $f['meta']['file_size'], $f['meta']['checksum']]);
    logAudit($pdo, $user_id, 'Renewal Submitted', "Renewal #$renewal_id for {$renewal['TermName']}: Report of Grades + " . count($extras) . ' other requirement(s)' . ($extracted_gwa !== null ? "; GWA read: $extracted_gwa" : ''));
    $pdo->commit();
    $back('success', 'Your renewal requirements were submitted. The OSSD will review them; your benefits for this semester follow once it is approved.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($saved as $f) if (is_file($f)) @unlink($f);
    $back('error', $e->getMessage() . ' Nothing was submitted: please fix it and submit again.');
}
