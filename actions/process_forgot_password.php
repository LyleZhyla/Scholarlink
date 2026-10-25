<?php
session_start();
require '../includes/db_connect.php';
require '../includes/email_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
        header("Location: ../student_login.php");
        exit();
    }

    try {
        // 1. Hanapin kung nag-e-exist ang email sa database
        $stmt = $pdo->prepare("SELECT UserID, FirstName FROM Users WHERE Email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // ✨ FIX 1: Ginawang 16 bytes (32 characters) na lang para iwas-putol sa database
            $token = bin2hex(random_bytes(16)); 

            // ✨ FIX 2: Ginamit ang MySQL native function na DATE_ADD(NOW()) para iwas Timezone Mismatch
            $update = $pdo->prepare("UPDATE Users SET ResetToken = :token, ResetTokenExpire = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE Email = :email");
            $update->execute([
                'token' => $token, 
                'email' => $email
            ]);

            // 4. I-generate ang dynamic Link kung saan sila magpapalit ng password
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domainName = $_SERVER['HTTP_HOST'];
            
            // Kukunin ang base folder path (e.g., /scholarlink)
            $project_folder = dirname($_SERVER['PHP_SELF'], 2);
            $reset_link = $protocol . $domainName . $project_folder . "/reset_password.php?token=" . $token;

            // 5. I-send ang email!
            if (sendPasswordResetEmail($email, $user['FirstName'], $reset_link)) {
                // Log the request
                $log_stmt = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'Password Reset Requested', NOW(), 'User requested a password reset link.', ?)");
                $log_stmt->execute([$user['UserID'], $_SERVER['REMOTE_ADDR']]);

                $_SESSION['success'] = "If the email is registered, a password reset link has been sent.";
            } else {
                $_SESSION['error'] = "System Error: Failed to send the reset email. Please try again later.";
            }
        } else {
            // SECURITY BEST PRACTICE: Wag ipaalam sa hacker kung registered o hindi ang email
            $_SESSION['success'] = "If the email is registered, a password reset link has been sent.";
        }

    } catch (PDOException $e) {
        error_log("Database Error in Forgot Password: " . $e->getMessage());
        $_SESSION['error'] = "An error occurred while processing your request.";
    }

    header("Location: ../student_login.php");
    exit();
} else {
    header("Location: ../student_login.php");
    exit();
}
?>