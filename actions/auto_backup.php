<?php
// actions/auto_backup.php

// Force the script to run in the background even if the user closes the browser
ignore_user_abort(true);
set_time_limit(0);

require '../includes/db_connect.php';

$backup_dir = '../backups/';

// Gumawa ng folder kung sakaling nakalimutan sa Step 1
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0777, true);
}

// Set the filename based on the CURRENT DATE
$current_date = date('Y-m-d');
$backup_file_name = $backup_dir . 'AutoBackup_' . $current_date . '.sql';

// ✨ SMART CHECK: Kung may backup na ngayong araw, STOP agad.
// Pinipigilan nito ang system na mag-backup nang paulit-ulit sa iisang araw.
if (file_exists($backup_file_name)) {
    exit; 
}

try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $sqlScript = "-- ScholarLink Automated Daily Backup\n";
    $sqlScript .= "-- Date Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sqlScript .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";
        $sqlScript .= $createStmt[1] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $escaped_values = array_map(function($val) use ($pdo) {
                return $val === null ? 'NULL' : $pdo->quote($val);
            }, array_values($row));
            
            $sqlScript .= "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $escaped_values) . ");\n";
        }
        $sqlScript .= "\n";
    }
    $sqlScript .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    // I-save ang file sa loob ng backups folder
    file_put_contents($backup_file_name, $sqlScript);

    // ✨ STORAGE MANAGEMENT: Auto-Delete ng mga backups na 7 days old na
    $files = glob($backup_dir . '*.sql');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) >= 60 * 60 * 24 * 7) { // 7 Days limit
                unlink($file);
            }
        }
    }

    // I-log sa Audit Trail (Using UserID 1 or System Default)
    $log = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (1, 'System Auto-Backup', NOW(), 'Automated daily database backup completed.', 'System')");
    $log->execute();

} catch (Exception $e) {
    // Kung mag-error, isusulat sa error log ng server pero hindi makikita ng user
    error_log("Auto Backup Error: " . $e->getMessage());
}
?>