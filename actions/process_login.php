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

            // --- SESSION INITIALIZATION ---
            // We use the exact casing from your DB columns
            $_SESSION['logged_in']  = true;
            $_SESSION['user_id']    = $user['UserID'];
            $_SESSION['username']   = $user['Username'];
            $_SESSION['role']       = $user['Role']; // Ensure this is 'Student', 'Internal_Admin', etc.
            $_SESSION['first_name'] = $user['FirstName'];
            $_SESSION['last_name']  = $user['LastName'];
            $_SESSION['email']      = $user['Email'];
            $_SESSION['last_activity'] = time();

            // ✨ Log the successful login
            logAudit($pdo, $user['UserID'], 'Login', $user['FirstName'] . ' ' . $user['LastName'] . ' logged in as ' . $user['Role'] . ' via ' . $portal_label);

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