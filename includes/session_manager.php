<?php
// ✨ 1. THE ULTIMATE BACK BUTTON KILLER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Force expiration in the past


// ✨ 3. TIMEOUT LOGIC
require_once __DIR__ . '/db_connect.php';

$timeout_seconds = 1800; // Default 30 mins
try {
    $timeout_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout'");
    $result = $timeout_stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $timeout_seconds = (int)$result['setting_value'];
    }
} catch (PDOException $e) { }

if (isset($_SESSION['last_activity'])) {
    $time_inactive = time() - $_SESSION['last_activity'];
    
    if ($time_inactive > $timeout_seconds) {
        
        // Log the automatic timeout
        try {
            $log_stmt = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'Auto Logout', NOW(), 'User session expired due to inactivity.', ?)");
            $log_stmt->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR']]);
        } catch (PDOException $e) { }

        // ✨ THE FIX: We use session_unset() instead of completely destroying the cookie.
        // This securely wipes all sensitive user data but keeps the session alive JUST long enough to hold the error message!
        $role = $_SESSION['role'] ?? ''; 
        session_unset(); 
        
        $_SESSION['error'] = "For your security, you have been automatically logged out due to inactivity.";
        
     $login_page = ($role === 'Student') ? '../student_login.php' : '../admin_login.php';
        
        // ✨ FORCE THE BROWSER WIPE ON AUTO-TIMEOUT
        echo "<script>
                sessionStorage.removeItem('scholarlink_chat_history');
                window.location.replace('$login_page');
              </script>";
        exit();
    }
}
// Update the timestamp to RIGHT NOW every time they click or load a page
$_SESSION['last_activity'] = time();
?>