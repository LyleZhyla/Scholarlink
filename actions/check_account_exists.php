<?php
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';

$email = trim($_POST['email'] ?? '');
$student_number = trim($_POST['student_number'] ?? '');

$response = [
    'exists' => false,
    'email_exists' => false,
    'student_number_exists' => false,
    'message' => ''
];

try {
    if (!empty($email)) {
        $stmt = $pdo->prepare("SELECT UserID FROM users WHERE Email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $response['exists'] = true;
            $response['email_exists'] = true;
            $response['message'] = "An account with this email address already exists.";
        }
    }

    if (!empty($student_number)) {
        $stmt = $pdo->prepare("SELECT UserID FROM users WHERE StudentID_Num = ? OR Username = ?");
        $stmt->execute([$student_number, $student_number]);
        if ($stmt->fetch()) {
            $response['exists'] = true;
            $response['student_number_exists'] = true;
            $response['message'] = "An account with this student number already exists.";
        }
    }

    echo json_encode($response);
    exit();

} catch (PDOException $e) {
    echo json_encode(['exists' => false, 'error' => 'Database check failed.']);
    exit();
}