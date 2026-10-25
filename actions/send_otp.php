<?php
session_start();
require '../includes/email_config.php'; // ✨ FIX: Added the 'includes/' folder path!

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $first_name = htmlspecialchars($_POST['first_name']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email format.']);
        exit;
    }

    $otp_code = rand(100000, 999999);
    $_SESSION['registration_otp'] = $otp_code;
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