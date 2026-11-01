<?php
// ✨ 1. THE ULTIMATE BACK BUTTON KILLER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Force expiration in the past

// ✨ 2. TIMEOUT LOGIC
// The timeout the Super Admin set on Security Settings (see includes/security_settings.php)
require_once __DIR__ . '/db_connect.php';
enforceSessionTimeout($pdo);
?>
