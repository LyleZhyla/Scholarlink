<?php
session_start();

ignore_user_abort(true);
set_time_limit(0);

require '../includes/db_connect.php';
require '../includes/database_backup.php';

$allowedRoles = ['Internal_Admin', 'External_Admin', 'Super_Admin'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['logged_in'])
    || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(204);
    exit;
}

$backupDirectory = scholarlinkBackupDirectory();
$backupFile = $backupDirectory . DIRECTORY_SEPARATOR . 'AutoBackup_' . date('Y-m-d') . '.sql';
$lockPath = $backupDirectory . DIRECTORY_SEPARATOR . '.auto-backup.lock';

if (!is_dir($backupDirectory)) {
    mkdir($backupDirectory, 0750, true);
}

$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    http_response_code(204);
    exit;
}

try {
    if (!is_file($backupFile) || filesize($backupFile) === 0) {
        $size = scholarlinkCreateDatabaseBackup($backupFile);
        scholarlinkPruneAutomaticBackups(7);

        $log = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'System Auto-Backup', NOW(), ?, ?)");
        $log->execute([
            $_SESSION['user_id'] ?? null,
            'Automated daily database backup completed (' . number_format($size / 1024, 1) . ' KB).',
            $_SERVER['REMOTE_ADDR'] ?? 'System',
        ]);
    }
    http_response_code(204);
} catch (Throwable $e) {
    error_log('Auto Backup Error: ' . $e->getMessage());
    http_response_code(500);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
