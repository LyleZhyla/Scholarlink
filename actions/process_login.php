<?php
session_start();
require '../includes/db_connect.php';

// ✨ AUDIT LOGGING HELPER
// Writes one row to audit_log. Wrapped in function_exists so it can later be moved to a shared
// include (e.g. includes/audit.php) without clashing with this copy.
if (!function_exists('logAudit')) {
    function logAudit(PDO $pdo, $userId, string $action, string $description = ''): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress)
                VALUES (?, ?, NOW(), ?, ?)
            ");
            $stmt->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
        } catch (PDOException $e) {
            // Never block a login because logging failed
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Check which login portal is being used
    $login_type = $_POST['login_type'] ?? 'student';
    $error_redirect = ($login_type === 'admin') ? '../admin_login.php' : '../student_login.php';
    $portal_label = ($login_type === 'admin') ? 'Admin Portal' : 'Student Portal';

    try {
        // Query the Users table
        // Allow login with either Student Number (which is the Username) or Email address.
        $stmt = $pdo->prepare("SELECT * FROM users WHERE Username = :identifier OR Email = :identifier");
        $stmt->execute(['identifier' => $username]);
        $user = $stmt->fetch();

        // 1. Verify user and check password
        if ($user && password_verify($password, $user['PasswordHash'])) {

            // --- THE GATEKEEPER LOGIC ---

            // Archived accounts cannot sign in
            if ($blocked = accountArchivedMessage($user)) {
                logAudit($pdo, $user['UserID'], 'Login Denied', $user['FirstName'] . ' ' . $user['LastName'] . ' tried to sign in, but the account is archived (' . $portal_label . ')');
                $_SESSION['error'] = $blocked;
                header("Location: " . $error_redirect);
                exit();
            }

            // Prevent students from using the Admin Login page
            if ($login_type === 'admin' && $user['Role'] === 'Student') {
                logAudit($pdo, $user['UserID'], 'Login Denied', $user['FirstName'] . ' ' . $user['LastName'] . ' (Student) tried to use the Admin Portal');
                $_SESSION['error'] = "Access denied. Students must use the Student Portal.";
                header("Location: " . $error_redirect);
                exit();
            }

            // Prevent staff from using the Student Login page
            if ($login_type === 'student' && $user['Role'] !== 'Student') {
                logAudit($pdo, $user['UserID'], 'Login Denied', $user['FirstName'] . ' ' . $user['LastName'] . ' (' . $user['Role'] . ') tried to use the Student Portal');
                $_SESSION['error'] = "Staff accounts must use the Administrative Portal.";
                header("Location: " . $error_redirect);
                exit();
            }

            if (!dashboardFor($user['Role'])) {
                $_SESSION['error'] = "Account role not recognized.";
                header("Location: " . $error_redirect);
                exit();
            }

            // --- TWO-FACTOR AUTHENTICATION (Super Admin → Security) ---
            // Admins aren't signed in yet: they get a code by email and finish on admin_2fa.php
            if (isTwoFactorRequired($pdo, $user['Role'])) {
                if (empty($user['Email']) || !startTwoFactor($pdo, $user, $portal_label)) {
                    unset($_SESSION['pending_2fa']);
                    logAudit($pdo, $user['UserID'], 'Login Failed', 'Two-factor code could not be emailed to ' . $user['Username']);
                    $_SESSION['error'] = "We couldn't send your login code. Check that your account has a working email address, or contact the Super Admin.";
                    header("Location: " . $error_redirect);
                    exit();
                }
                logAudit($pdo, $user['UserID'], '2FA Code Sent', 'Password accepted for ' . $user['FirstName'] . ' ' . $user['LastName'] . '; login code emailed to ' . maskEmail($user['Email']));
                header("Location: ../admin_2fa.php");
                exit();
            }

            // --- SESSION INITIALIZATION ---
            completeLogin($pdo, $user, $portal_label);

            // --- REDIRECTION ---
            switch ($user['Role']) {
                case 'Student':
                    header("Location: ../student/dashboard.php");
                    exit();
                case 'Internal_Admin':
                    header("Location: ../internal_admin/dashboard.php");
                    exit();
                case 'External_Admin':
                    header("Location: ../external_admin/dashboard.php");
                    exit();
                case 'Super_Admin':
                    header("Location: ../super_admin/dashboard.php");
                    exit();
                default:
                    $_SESSION['error'] = "Account role not recognized.";
                    header("Location: " . $error_redirect);
                    exit();
            }

        } else {
            // ✨ Log the failed attempt (UserID is NULL when the account doesn't exist)
            $shown_name = mb_substr($username, 0, 100);
            if ($user) {
                logAudit($pdo, $user['UserID'], 'Login Failed', 'Wrong password for ' . $user['Username'] . ' via ' . $portal_label);
            } else {
                logAudit($pdo, null, 'Login Failed', 'Unknown account "' . $shown_name . '" via ' . $portal_label);
            }

            $_SESSION['error'] = "Invalid student number or password.";
            header("Location: " . $error_redirect);
            exit();
        }

    } catch (PDOException $e) {
        die("Login Error: " . $e->getMessage());
    }
} else {
    header("Location: ../student_login.php");
    exit();
}