<?php
session_start();
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

    if ($user_otp == $_SESSION['registration_otp']) {
        unset($_SESSION['registration_otp']);
        unset($_SESSION['otp_expiry']);
        
        // ✨ Security Flag: Tells process_register.php that OTP was verified!
        $_SESSION['otp_verified'] = true; 
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Incorrect verification code.']);
    }
}
?>