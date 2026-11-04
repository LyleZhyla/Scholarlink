<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php'; // records the activity in the audit trail
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_otp = $_POST['otp'] ?? '';
    
    if (!isset($_SESSION['registration_otp']) || !isset($_SESSION['otp_expiry'])) {
        echo json_encode(['success' => false, 'error' => 'Session expired. Please request a new code.']);
        exit;
    }

    if (time() > $_SESSION['otp_expiry']) {
        echo json_encode(['success' => false, 'error' => 'Code has expired.']);
        exit;
    }

    if (hash_equals((string) $_SESSION['registration_otp'], trim((string) $user_otp))) {
        unset($_SESSION['registration_otp']);
        unset($_SESSION['otp_expiry']);
        
        // ✨ Security Flag: Tells process_register.php which email was verified
        $_SESSION['otp_verified_email'] = $_SESSION['otp_email'] ?? '';
        
        echo json_encode(['success' => true]);
    } else {
        // A wrong guess uses up the code after 5 tries, so it can't be guessed by trying every number
        $_SESSION['otp_attempts'] = (int) ($_SESSION['otp_attempts'] ?? 0) + 1;
        if ($_SESSION['otp_attempts'] >= 5) {
            unset($_SESSION['registration_otp'], $_SESSION['otp_expiry']);
            echo json_encode(['success' => false, 'error' => 'Too many wrong codes. Please request a new code.']);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'Incorrect verification code.']);
    }
}
?>