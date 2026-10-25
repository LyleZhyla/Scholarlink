<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/id_scanner.php';

// ✨ PROGRAM SHIFTING
// A student's program can only change by scanning their updated student ID.
// The ID must belong to the same student, and every shift is written to the audit log.

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../student_login.php");
    exit();
}

function backToProfile(string $type, string $message): void {
    $_SESSION[$type] = $message;
    header("Location: ../student/profile.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['id_image']) || $_FILES['id_image']['error'] !== UPLOAD_ERR_OK) {
    backToProfile('error', "Please upload a photo of your updated student ID.");
}

$user_id = $_SESSION['user_id'];

try {
    $user_stmt = $pdo->prepare("SELECT u.StudentID_Num, u.FirstName, u.LastName, u.ProgramID, p.ProgramName FROM users u LEFT JOIN program p ON u.ProgramID = p.ProgramID WHERE u.UserID = ?");
    $user_stmt->execute([$user_id]);
    $student = $user_stmt->fetch();

    // 1. Basahin ang ID
    $ocr = readIdText($_FILES['id_image']['tmp_name']);
    if ($ocr['error'] !== '') {
        backToProfile('error', "ID scan failed. " . $ocr['error']);
    }
    $id_data = parseIdData($ocr['text']);

    // 2. Siguraduhing ID ito ng parehong estudyante
    if ($id_data['student_id'] === '') {
        backToProfile('error', "We couldn't read the student number on that ID. Please upload a clearer photo.");
    }
    if ($id_data['student_id'] !== $student['StudentID_Num']) {
        backToProfile('error', "The student number on that ID does not match your account.");
    }

    // 3. Kunin ang program mula sa ID
    $program = matchProgramFromText($pdo, $ocr['text']);
    if (!$program) {
        backToProfile('error', "We couldn't read a program from that ID. Please upload a clearer photo.");
    }
    if ((int)$program['ProgramID'] === (int)$student['ProgramID']) {
        backToProfile('success', "Your ID still shows " . $program['ProgramName'] . ". No change was made.");
    }

    // 4. I-save ang bagong program at itala sa audit log
    $pdo->prepare("UPDATE users SET ProgramID = ? WHERE UserID = ?")->execute([$program['ProgramID'], $user_id]);

    $old_program = $student['ProgramName'] ?? 'None';
    $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'Program Shift', NOW(), ?, ?)")
        ->execute([
            $user_id,
            "{$student['FirstName']} {$student['LastName']} ({$student['StudentID_Num']}) shifted from {$old_program} to {$program['ProgramName']} (read from scanned ID)",
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);

    backToProfile('success', "Program updated to " . $program['ProgramName'] . " based on your new ID.");

} catch (PDOException $e) {
    backToProfile('error', "Update failed: " . $e->getMessage());
}
