<?php
session_start();
require '../includes/db_connect.php';
require '../includes/email_config.php';

// Staff ask from the admin login page, students from the student login page; send them back where they came from
$back = (($_POST['portal'] ?? '') === 'admin') ? '../admin_login.php' : '../student_login.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
        header("Location: " . $back);
        exit();
    }

    try {
        if (!recoveryRequestAllowed($pdo, $email)) {
            logAudit($pdo, null, 'Recovery Rate Limited', 'Repeated password-recovery requests were blocked for this address.');
            $_SESSION['success'] = "If the email is registered and eligible for recovery, instructions will be sent. Please wait before requesting another link.";
            header('Location: ' . $back);exit();
        }
        // 1. Hanapin kung nag-e-exist ang email sa database
        $stmt = $pdo->prepare("SELECT UserID,FirstName,Role,AccountStatus,ProviderAccessStatus,ProviderAccessUntil FROM Users WHERE Email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && !accountArchivedMessage($user)) {
            // A 256-bit, single-use recovery token; only the newest unexpired token works.
            $token = bin2hex(random_bytes(32));

            // ✨ FIX 2: Ginamit ang MySQL native function na DATE_ADD(NOW()) para iwas Timezone Mismatch
            $update = $pdo->prepare("UPDATE Users SET ResetToken = :token, ResetTokenExpire = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE Email = :email");
            $update->execute([
                'token' => $token, 
                'email' => $email
            ]);

            // 4. I-generate ang dynamic Link kung saan sila magpapalit ng password
            // appPublicUrl() swaps "localhost" for this PC's network address, so the link opens on the student's phone too
            $reset_link = appPublicUrl() . "/reset_password.php?token=" . $token;

            // 5. I-send ang email!
            if (sendPasswordResetEmail($email, $user['FirstName'], $reset_link)) {
                // Log the request
                $log_stmt = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'Password Reset Requested', NOW(), 'User requested a password reset link.', ?)");
                $log_stmt->execute([$user['UserID'], $_SERVER['REMOTE_ADDR']]);

                $_SESSION['success'] = "If the email is registered, a password reset link has been sent. Check your inbox (and Spam); only the newest link works.";
            } else {
                $_SESSION['error'] = "System Error: Failed to send the reset email. Please try again later.";
            }
        } else {
            if ($user) logAudit($pdo, $user['UserID'], 'Recovery Denied', 'Password recovery was denied because account access is inactive or revoked.');
            // SECURITY BEST PRACTICE: Wag ipaalam sa hacker kung registered o hindi ang email
            $_SESSION['success'] = "If the email is registered, a password reset link has been sent. Check your inbox (and Spam); only the newest link works.";
        }

    } catch (PDOException $e) {
        error_log("Database Error in Forgot Password: " . $e->getMessage());
        $_SESSION['error'] = "An error occurred while processing your request.";
    }

    header("Location: " . $back);
    exit();
} else {
    header("Location: ../student_login.php");
    exit();
}
?>
