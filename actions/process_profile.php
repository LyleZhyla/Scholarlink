<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/profile_fields.php';

// Security: Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../student_login.php");
    exit();
}

$back = function (string $kind, string $message) {
    $_SESSION[$kind] = $message;
    header("Location: ../student/profile.php");
    exit();
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../student/profile.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

// ✨ The student withdraws their pending change request
if (($_POST['action'] ?? '') === 'cancel_request') {
    $st = $pdo->prepare("UPDATE profile_change_requests SET Status = 'Cancelled', ReviewedAt = NOW() WHERE UserID = ? AND Status = 'Pending'");
    $st->execute([$user_id]);
    if ($st->rowCount()) logAudit($pdo, $user_id, 'Profile Change Request Cancelled', "$name withdrew their profile change request");
    $back('success', 'Your change request was cancelled. Nothing was changed.');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE UserID = ?");
    $stmt->execute([$user_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) $back('error', 'Account not found.');

    // 1. PERSONAL DETAILS: saved right away
    $personal = [];
    foreach (PROFILE_DIRECT_FIELDS as $field => [$col]) {
        $personal[$col] = trim((string) ($_POST[$field] ?? ''));
    }
    if ($personal['FirstName'] === '' || $personal['LastName'] === '') $back('error', 'First name and last name are required.');
    if ($personal['Gender'] === '') $personal['Gender'] = 'Not Specified';
    if ($personal['Citizenship'] === '') $personal['Citizenship'] = 'Filipino';
    if ($personal['DateOfBirth'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $personal['DateOfBirth'])) $back('error', 'Please enter a valid date of birth.');
    foreach (['ContactNumber', 'DateOfBirth', 'MiddleName', 'ExtName'] as $nullable) {
        if ($personal[$nullable] === '') $personal[$nullable] = null;
    }

    // 2. EVERYTHING ELSE: only what actually changed, sent for the Super Admin's approval
    $changes = [];
    $diff = [];
    foreach (PROFILE_APPROVAL_FIELDS as $field => [$col]) {
        if (!array_key_exists($field, $_POST)) continue;
        $new = trim((string) $_POST[$field]);
        $old = trim((string) ($current[$col] ?? ''));
        if ($new !== $old) {
            $changes[$field] = $new;
            $diff[$field] = ['from' => $old, 'to' => $new];
        }
    }
    if ($changes && ($problem = profileChangeError($pdo, $user_id, $changes))) $back('error', $problem);

    // 3. PROFILE PHOTO (personal): the type is read from the file itself, never from its name
    $photo_path = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($types[$mime])) $back('error', 'Invalid image type. Please upload a JPG, PNG, or WEBP.');
        if ($file['size'] > 5 * 1024 * 1024) $back('error', 'Image is too large. Maximum size is 5MB.');

        $target_dir = "../uploads/profiles/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $photo_path = $target_dir . "USER_{$user_id}_PROFILE_" . time() . '.' . $types[$mime];
        if (!move_uploaded_file($file['tmp_name'], $photo_path)) $back('error', 'Failed to save the image to the server.');
        $personal['ProfilePicture'] = $photo_path;
    }

    $pdo->beginTransaction();
    $sets = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($personal)));
    $pdo->prepare("UPDATE users SET $sets WHERE UserID = ?")->execute([...array_values($personal), $user_id]);

    // One open request per student: a new submission replaces the previous one (the form shows the pending values)
    $pending = pendingProfileRequest($pdo, $user_id);
    if ($diff) {
        if ($pending) {
            $pdo->prepare("UPDATE profile_change_requests SET Changes = ?, CreatedAt = NOW() WHERE RequestID = ?")
                ->execute([json_encode($diff, JSON_UNESCAPED_UNICODE), $pending['RequestID']]);
        } else {
            $pdo->prepare("INSERT INTO profile_change_requests (UserID, Changes) VALUES (?, ?)")
                ->execute([$user_id, json_encode($diff, JSON_UNESCAPED_UNICODE)]);
        }
    } elseif ($pending) {
        // Everything was set back to the current values: nothing left to approve
        $pdo->prepare("UPDATE profile_change_requests SET Status = 'Cancelled', ReviewedAt = NOW() WHERE RequestID = ?")->execute([$pending['RequestID']]);
    }
    $pdo->commit();

    $_SESSION['first_name'] = $personal['FirstName'];
    $_SESSION['last_name']  = $personal['LastName'];

    $labels = array_map(fn($f) => PROFILE_APPROVAL_FIELDS[$f][1], array_keys($diff));
    if ($diff) {
        logAudit($pdo, $user_id, 'Profile Change Requested', "{$personal['FirstName']} {$personal['LastName']} asked to change: " . implode(', ', $labels));
        $back('success', 'Your personal details are saved. Your other changes (' . implode(', ', $labels) . ') were sent to the Super Admin for approval.');
    }
    logAudit($pdo, $user_id, 'Profile Updated', "{$personal['FirstName']} {$personal['LastName']} updated their personal details" . ($photo_path ? ' and photo' : ''));
    $back('success', $pending ? 'Personal details saved. Your pending change request was withdrawn because nothing is different any more.' : 'Your personal details are saved.');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $back('error', 'Update failed. Please try again.');
}
