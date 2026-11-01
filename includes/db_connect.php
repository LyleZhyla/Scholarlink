<?php

$host = '127.0.0.1'; // Using '127.0.0.1' instead of 'localhost' prevents Windows named pipe resolution issues
$db   = 'scholarlink_db';
$user = 'root';
$pass = ''; // Default XAMPP root password is empty
$port = 3307;

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Emulation must stay on: many queries reuse a named placeholder (e.g. :search twice),
        // which native prepares reject with SQLSTATE[HY093]
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Every activity goes to the audit trail
require_once __DIR__ . '/audit.php';
auditTrackRequest($pdo);

// Super Admin → Security settings; signs out inactive users on every page and action
require_once __DIR__ . '/security_settings.php';
enforceSessionTimeout($pdo);
// Refuses a signed-in user's POST that lacks the session's security token (forged cross-site requests)
enforceCsrf($pdo);