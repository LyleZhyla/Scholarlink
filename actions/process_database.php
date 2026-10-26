<?php
session_start();

require '../includes/db_connect.php';
require '../includes/database_backup.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'Super_Admin') {
    $_SESSION['error'] = 'Unauthorized access.';
    header('Location: ../admin_login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid database operation request.';
    header('Location: ../super_admin/database.php');
    exit;
}

$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['database_csrf'] ?? '';
if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    $_SESSION['error'] = 'Security token expired. Please try again.';
    header('Location: ../super_admin/database.php');
    exit;
}

set_time_limit(0);
$action = $_POST['action'] ?? '';
$adminId = $_SESSION['user_id'] ?? null;
$backupDirectory = scholarlinkBackupDirectory();
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

try {
    if ($action === 'export') {
        $filename = 'ScholarLink_Backup_' . date('Y-m-d_H-i-s') . '.sql';
        $path = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
        $size = scholarlinkCreateDatabaseBackup($path);

        $log = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'System Backup', NOW(), ?, ?)");
        $log->execute([$adminId, 'Super Admin created database backup ' . $filename . '.', $ipAddress]);

        header('Content-Type: application/sql');
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    if ($action === 'restore') {
        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed. Select a valid SQL backup within the server upload limit.');
        }

        $uploadedPath = $_FILES['backup_file']['tmp_name'];
        $uploadedName = basename($_FILES['backup_file']['name'] ?? 'backup.sql');
        scholarlinkValidateBackupFile($uploadedPath, $uploadedName);

        $safetyFilename = 'PreRestore_' . date('Y-m-d_H-i-s') . '.sql';
        $safetyPath = $backupDirectory . DIRECTORY_SEPARATOR . $safetyFilename;
        scholarlinkCreateDatabaseBackup($safetyPath);
        scholarlinkRestoreDatabaseBackup($uploadedPath);

        $log = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'System Restore', NOW(), ?, ?)");
        $log->execute([$adminId, "Database restored from {$uploadedName}. Safety backup: {$safetyFilename}.", $ipAddress]);
        $_SESSION['success'] = "Database restored successfully. Safety backup created: {$safetyFilename}.";
    } elseif ($action === 'reset') {
        $safetyFilename = 'PreReset_' . date('Y-m-d_H-i-s') . '.sql';
        scholarlinkCreateDatabaseBackup($backupDirectory . DIRECTORY_SEPARATOR . $safetyFilename);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (['score', 'submitted_document', 'application_custom_answers', 'application', 'user_vault', 'audit_log'] as $table) {
                $pdo->exec("TRUNCATE TABLE `{$table}`");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $log = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'System Reset', NOW(), ?, ?)");
        $log->execute([$adminId, "Transactional data reset. Safety backup: {$safetyFilename}.", $ipAddress]);
        $_SESSION['success'] = "System reset completed. Safety backup created: {$safetyFilename}.";
    } else {
        throw new RuntimeException('Unknown database operation.');
    }
} catch (Throwable $e) {
    error_log('Database management error: ' . $e->getMessage());
    $_SESSION['error'] = 'Database operation failed: ' . $e->getMessage();
}

header('Location: ../super_admin/database.php');
exit;
