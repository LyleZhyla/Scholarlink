<?php
session_start();
require '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    header("Location: ../student_login.php"); exit();
}

$user_id = $_SESSION['user_id'];
$application_id = $_POST['ApplicationID'] ?? null;
$scholarship_id = $_POST['scholarship_id'] ?? null;
$req_id = $_POST['RequirementID'] ?? null;

// ✨ NEW: Capture the Vault selection (if they used Option 1)
$vault_file_path = $_POST['vault_file_path'] ?? null; 

if (!$scholarship_id) {
    $_SESSION['error'] = "Critical Error: Scholarship ID is missing.";
    header("Location: ../student/requirements.php"); exit();
}

try {
    $pdo->beginTransaction();

    // 🚀 STEP 1: PREVENT DUPLICATES - Resolve Application ID
    if (empty($application_id)) {
        $check = $pdo->prepare("SELECT ApplicationID FROM application WHERE UserID = ? AND ScholarshipID = ?");
        $check->execute([$user_id, $scholarship_id]);
        $existing = $check->fetch();
        $application_id = $existing ? $existing['ApplicationID'] : null;

        if (!$application_id) {
            $app_stmt = $pdo->prepare("INSERT INTO application (UserID, ScholarshipID, Status, DateSubmitted) VALUES (?, ?, 'Pending', NOW())");
            $app_stmt->execute([$user_id, $scholarship_id]);
            $application_id = $pdo->lastInsertId();
        }
    }

    $db_save_path = "";

    // ✨ STEP 2: SMART UPLOAD ROUTING (Vault vs Local File)
    if (!empty($vault_file_path)) {
        // Option A: User selected a file from their Vault!
        
        // Clean the path (remove any '../' just in case) so it matches standard DB format
        $db_save_path = ltrim($vault_file_path, '../'); 
        
        // Security Check: Make sure the vault file still actually exists on the server
        if (!file_exists("../" . $db_save_path)) {
            throw new Exception("Vault file could not be found on the server. It may have been deleted.");
        }

    } else {
        // Option B: User uploaded a brand new file from their computer
        
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please select a file to upload or choose one from your Vault.");
        }

        $file_ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'pdf') {
            throw new Exception("Invalid file type! Please upload a PDF file only.");
        }

        $target_dir = "../uploads/documents/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

        $new_name = "STU-{$user_id}-APP-{$application_id}-REQ-{$req_id}-" . uniqid() . "." . $file_ext;
        $db_save_path = "uploads/documents/" . $new_name;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], "../" . $db_save_path)) {
            throw new Exception("Failed to save the uploaded file to the server.");
        }
    }

    // 🚀 STEP 3: ATTACH TO APPLICATION (Works for both Vault and New Uploads)
    $del = $pdo->prepare("DELETE FROM submitted_document WHERE ApplicationID = ? AND RequirementID = ?");
    $del->execute([$application_id, $req_id]);

    $doc_stmt = $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')");
    $doc_stmt->execute([$application_id, $req_id, $db_save_path]);

    $pdo->commit();
    $_SESSION['success'] = "Document attached successfully! ✨";

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

header("Location: ../student/requirements.php");
exit();
?>