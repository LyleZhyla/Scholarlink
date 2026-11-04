<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php'; // records the activity in the audit trail
require '../includes/email_config.php'; // ✨ FIX: Added the 'includes/' folder path!

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Each code is an email sent through the school's account, so anyone could otherwise use it to flood an inbox
    if (!rateLimitAllowed($pdo, 'RegistrationCodeIP', 'all', 20, 3600) || !rateLimitAllowed($pdo, 'RegistrationCode', (string) ($_POST['email'] ?? ''), 5, 600)) {
        echo json_encode(['success' => false, 'error' => 'Too many codes requested. Please wait a few minutes and try again.']);
        exit;
    }

    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $first_name = htmlspecialchars($_POST['first_name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email format.']);
        exit;
    }

    $otp_code = random_int(100000, 999999);
    $_SESSION['registration_otp'] = $otp_code;
    $_SESSION['otp_email'] = strtolower($email);
    $_SESSION['otp_attempts'] = 0;
    $_SESSION['otp_expiry'] = time() + 600; 

    if (function_exists('sendRegistrationOTP')) {
        if (sendRegistrationOTP($email, $first_name, $otp_code)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to send OTP email. Please check SMTP settings.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Email function sendRegistrationOTP not found.']);
    }
}
?>