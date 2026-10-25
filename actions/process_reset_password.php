<?php
session_start();
require '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token']);
    $user_id = trim($_POST['user_id']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // 1. Check if passwords match
    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "Passwords do not match. Please try again.";
        header("Location: ../reset_password.php?token=" . urlencode($token));
        exit();
    }

    // ==========================================
    // ✨ THE GLOBAL STRICT PASSWORD RULE
    // ==========================================
    $strict_password_enabled = true; 
    
    if ($strict_password_enabled) {
        // Must be 8+ chars, have at least 1 number, and 1 special character
        if (strlen($new_password) < 8 || !preg_match('/[0-9]/', $new_password) || !preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $new_password)) {
            $_SESSION['error'] = "Security Policy: Password must be at least 8 characters long and include at least one number and one symbol.";
            header("Location: ../reset_password.php?token=" . urlencode($token)); 
            exit(); 
        }
    }

    try {
        // 2. Simplified Check: Just verify the token exists
        $stmt = $pdo->prepare("SELECT UserID FROM Users WHERE UserID = :uid AND ResetToken = :token");
        $stmt->execute(['uid' => $user_id, 'token' => $token]);
        
        if ($stmt->fetch()) {
            // 3. Hash the new password securely
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // 4. Update `PasswordHash` and wipe out the reset token
            $update = $pdo->prepare("UPDATE Users SET PasswordHash = :pass, ResetToken = NULL, ResetTokenExpire = NULL WHERE UserID = :uid");
            $update->execute(['pass' => $hashed_password, 'uid' => $user_id]);

            // ✨ THE FIX: Audit Log for Password Reset!
            // ✨ THE FIX: Audit Log for Password Reset!
            $log_stmt = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'Password Reset', NOW(), 'User successfully changed their password via reset link.', ?)");
            $log_stmt->execute([$user_id, $_SERVER['REMOTE_ADDR']]);

            $_SESSION['success'] = "Password reset successfully! You can now log in.";
            
            // Palitan ang header() ng HTML/JS Redirect para mabura ang Chat History
            ?>
            <!DOCTYPE html>
            <html>
            <script>
                // Siguradong burado ang convo para sa security ng bagong password
                sessionStorage.removeItem('scholarlink_chat_history');
                window.location.replace("../student_login.php");
            </script>
            </html>
            <?php
            exit();

        } else {
            $_SESSION['error'] = "This reset link has already been used or is invalid.";
            header("Location: ../student_login.php");
            exit();
        }

    } catch (PDOException $e) {
        $_SESSION['error'] = "Database Error: " . $e->getMessage();
        header("Location: ../reset_password.php?token=" . urlencode($token));
        exit();
    }
}