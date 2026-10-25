<?php
session_start();

// 1. Wipe the Server Backend Memory
session_unset();
session_destroy();

// 2. Determine the correct login screen
$redirect_url = (isset($_GET['type']) && $_GET['type'] === 'admin') ? '../admin_login.php' : '../student_login.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logging Out...</title>
    <script>
        // ✨ 3. THE BULLETPROOF FRONTEND WIPE
        // This forces the browser to delete the chat memory BEFORE moving to the login screen.
        sessionStorage.removeItem('scholarlink_chat_history');
        
        // 4. Instantly redirect to login
        window.location.replace("<?php echo $redirect_url; ?>");
    </script>
</head>
<body style="background-color: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; font-family: sans-serif; margin: 0;">
    <h3 style="color: #64748b;">Securely wiping memory and logging out...</h3>
</body>
</html>