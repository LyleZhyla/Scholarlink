<?php
// Remembers that the signed-in student finished or skipped the first-login tour (includes/student_tour.php)
session_start();
require '../includes/db_connect.php';
header('Content-Type: application/json');

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'Student' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit();
}

$finished = ($_POST['how'] ?? '') === 'finished';
$pdo->prepare("UPDATE users SET TourCompletedAt = NOW() WHERE UserID = ? AND TourCompletedAt IS NULL")->execute([$_SESSION['user_id']]);
logAudit($pdo, $_SESSION['user_id'], $finished ? 'Guided Tour Completed' : 'Guided Tour Skipped',
    ($_SESSION['first_name'] ?? 'Student') . ($finished ? ' finished the first-login tour' : ' skipped the first-login tour at step ' . (int) ($_POST['step'] ?? 0)));
echo json_encode(['success' => true]);
