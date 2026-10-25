<?php
session_start();
require '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $application_id = $_POST['application_id'] ?? null;
    $requirement_id = $_POST['requirement_id'] ?? null;
    $vault_id = $_POST['vault_id'] ?? null;

    if (!$application_id || !$requirement_id || !$vault_id) {
        $_SESSION['error'] = "Missing application or vault data.";
        header("Location: ../student/requirements.php");
        exit();
    }

    try {
        // 1. Verify the user owns this vault document and fetch its path
        $stmt = $pdo->prepare("SELECT FilePath FROM user_vault WHERE VaultID = ? AND UserID = ?");
        $stmt->execute([$vault_id, $user_id]);
        $vault_file = $stmt->fetch();

        if ($vault_file && file_exists($vault_file['FilePath'])) {
            
            // Ensure the application uploads directory exists
            $target_dir = "../uploads/documents/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

            // 2. Generate a new secure filename and COPY the file
            // We copy it so if they delete it from their vault later, the application still has a copy!
            $file_ext = pathinfo($vault_file['FilePath'], PATHINFO_EXTENSION);
            $new_name = "STU-" . $user_id . "-APP-" . $application_id . "-REQ-" . $requirement_id . "-VAULT-" . uniqid() . "." . $file_ext;
            $final_file_path = $target_dir . $new_name;

            if (copy($vault_file['FilePath'], $final_file_path)) {
                
                // 3. Check if we are inserting a new requirement or replacing an old one
                $check_stmt = $pdo->prepare("SELECT SubmittedDocID FROM submitted_document WHERE ApplicationID = ? AND RequirementID = ?");
                $check_stmt->execute([$application_id, $requirement_id]);
                $existing = $check_stmt->fetch();

                if ($existing) {
                    // Update existing
                    $upd = $pdo->prepare("UPDATE submitted_document SET FilePath = ?, VerificationStatus = 'Pending' WHERE SubmittedDocID = ?");
                    $upd->execute([$final_file_path, $existing['SubmittedDocID']]);
                } else {
                    // Insert new
                    $ins = $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')");
                    $ins->execute([$application_id, $requirement_id, $final_file_path]);
                }
                
                $_SESSION['success'] = "Document successfully linked from your Vault! 🔒";
            } else {
                $_SESSION['error'] = "Failed to copy the file from your Vault.";
            }
        } else {
            $_SESSION['error'] = "Vault document not found or may have been deleted.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database Error: " . $e->getMessage();
    }
    
    header("Location: ../student/requirements.php");
    exit();
}