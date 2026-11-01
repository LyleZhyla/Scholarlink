<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/appeals.php';
require_once '../includes/application_lifecycle.php';
require_once '../includes/application_authority.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['logged_in'])) {
    header('Location: ../student_login.php');
    exit();
}

$role = $_SESSION['role'] ?? '';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($role === 'Student' && $action === 'submit') {
    $application_id = (int) ($_POST['application_id'] ?? 0);
    $reason = trim((string) ($_POST['appeal_reason'] ?? ''));
    $saved_files = [];
    try {
        if (mb_strlen($reason) < 20) throw new RuntimeException('Please provide a clear appeal statement of at least 20 characters.');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT a.ApplicationID, a.AppealDeadline, a.Status, s.Name FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.ApplicationID = ? AND a.UserID = ? FOR UPDATE");
        $stmt->execute([$application_id, $user_id]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$application || !in_array($application['Status'], ['Rejected', 'Disqualified'], true)) throw new RuntimeException('Only a rejected or disqualified application may be appealed.');
        if (!$application['AppealDeadline'] || strtotime($application['AppealDeadline']) < time()) throw new RuntimeException('The appeal deadline has passed.');
        $duplicate = $pdo->prepare("SELECT AppealID FROM application_appeals WHERE ApplicationID = ?");
        $duplicate->execute([$application_id]);
        if ($duplicate->fetchColumn()) throw new RuntimeException('An appeal has already been filed for this application.');

        $files = $_FILES['supporting_documents'] ?? null;
        if (!$files || !is_array($files['tmp_name'] ?? null)) throw new RuntimeException('Attach at least one supporting document.');
        $valid_uploads = [];
        $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($files['tmp_name'] as $i => $tmp) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('A supporting document could not be uploaded.');
            if (($files['size'][$i] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Each supporting document must be 5 MB or smaller.');
            $mime = $finfo->file($tmp);
            if (!isset($allowed[$mime])) throw new RuntimeException('Supporting documents must be PDF, JPG, or PNG files.');
            $valid_uploads[] = ['tmp' => $tmp, 'ext' => $allowed[$mime], 'original' => basename((string) $files['name'][$i])];
        }
        if (!$valid_uploads) throw new RuntimeException('Attach at least one supporting document.');

        $pdo->prepare("INSERT INTO application_appeals (ApplicationID, ApplicantID, AppealReason, Status, Deadline) VALUES (?, ?, ?, 'Submitted', ?)")
            ->execute([$application_id, $user_id, $reason, $application['AppealDeadline']]);
        $appeal_id = (int) $pdo->lastInsertId();
        $dir = dirname(__DIR__) . '/uploads/appeals/';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $insert_doc = $pdo->prepare("INSERT INTO appeal_documents (AppealID, FilePath, OriginalFileName) VALUES (?, ?, ?)");
        foreach ($valid_uploads as $file) {
            $name = "STU-$user_id-APPEAL-$appeal_id-" . bin2hex(random_bytes(6)) . '.' . $file['ext'];
            if (!move_uploaded_file($file['tmp'], $dir . $name)) throw new RuntimeException('A supporting document could not be saved.');
            $saved_files[] = $dir . $name;
            $insert_doc->execute([$appeal_id, '../uploads/appeals/' . $name, $file['original']]);
        }

        $authority = getApplicationAuthority($pdo, $application_id);
        $recipients = [];
        if ($authority && !$authority['IsInternal']) $recipients[] = (int) $authority['CreatedBy'];
        else $recipients = array_map('intval', $pdo->query("SELECT UserID FROM users WHERE Role = 'Internal_Admin' AND AccountStatus = 'Active'")->fetchAll(PDO::FETCH_COLUMN));
        $notify = $pdo->prepare("INSERT INTO system_notifications (RecipientID, Title, Message) VALUES (?, 'New Application Appeal', ?)");
        foreach ($recipients as $recipient) $notify->execute([$recipient, "A student filed an appeal for {$application['Name']} (Application #$application_id)."]);
        logAudit($pdo, $user_id, 'Application Appeal Submitted', "Filed Appeal #$appeal_id for Application #$application_id");
        $pdo->commit();
        $_SESSION['success'] = 'Your appeal and supporting documents were submitted for formal review.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($saved_files as $saved_file) if (is_file($saved_file)) @unlink($saved_file);
        $_SESSION['error'] = $e->getMessage();
    }
    header("Location: ../student/appeal.php?application_id=$application_id");
    exit();
}

if (in_array($role, ['Internal_Admin', 'External_Admin', 'Super_Admin'], true) && $action === 'review') {
    $appeal_id = (int) ($_POST['appeal_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    $comments = trim((string) ($_POST['reviewer_comments'] ?? ''));
    $resolution = trim((string) ($_POST['final_resolution'] ?? ''));
    $back = '../' . strtolower(str_replace('_Admin', '_admin', $role)) . '/appeals.php';
    if ($role === 'Super_Admin') $back = '../super_admin/appeals.php';
    try {
        if (!in_array($new_status, ['Under Review', 'Approved', 'Denied'], true)) throw new RuntimeException('Invalid appeal status.');
        if ($comments === '') throw new RuntimeException('Reviewer comments are required.');
        if (in_array($new_status, ['Approved', 'Denied'], true) && $resolution === '') throw new RuntimeException('A final resolution is required.');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT ap.*, a.UserID, s.Name FROM application_appeals ap JOIN application a ON a.ApplicationID = ap.ApplicationID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE ap.AppealID = ? FOR UPDATE");
        $stmt->execute([$appeal_id]);
        $appeal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$appeal) throw new RuntimeException('Appeal not found.');
        $authority = getApplicationAuthority($pdo, (int) $appeal['ApplicationID']);
        if (!$authority || !canDecideApplication($authority, $role, $user_id)) {
            throw new RuntimeException($authority
                ? "Only {$authority['DecidedBy']} can review this appeal."
                : 'Application not found.');
        }
        if (in_array($appeal['Status'], ['Approved', 'Denied'], true)) throw new RuntimeException('This appeal already has a final resolution.');

        $resolved = in_array($new_status, ['Approved', 'Denied'], true);
        $pdo->prepare("UPDATE application_appeals SET Status = ?, ReviewerComments = ?, FinalResolution = ?, ReviewedBy = ?, ResolvedAt = ? WHERE AppealID = ?")
            ->execute([$new_status, $comments, $resolved ? $resolution : null, $user_id, $resolved ? date('Y-m-d H:i:s') : null, $appeal_id]);
        if ($new_status === 'Approved') {
            $pdo->prepare("UPDATE application SET Status = 'Under Review', DecisionAt = NULL, AppealDeadline = NULL WHERE ApplicationID = ?")->execute([$appeal['ApplicationID']]);
            snapshotApplicationVersion($pdo, (int) $appeal['ApplicationID'], 'Appeal Approved', $user_id, $resolution);
        } elseif ($new_status === 'Denied') {
            snapshotApplicationVersion($pdo, (int) $appeal['ApplicationID'], 'Appeal Denied', $user_id, $resolution);
        }
        $title = $resolved ? "Appeal $new_status" : 'Appeal Under Review';
        $message = $resolved ? "Your appeal for {$appeal['Name']} was $new_status. Resolution: $resolution" : "Your appeal for {$appeal['Name']} is now under review. Reviewer note: $comments";
        $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, ?, ?, ?)")
            ->execute([$appeal['UserID'], $title, $message, $new_status === 'Approved' ? 'success' : ($new_status === 'Denied' ? 'danger' : 'info')]);
        logAudit($pdo, $user_id, "Appeal $new_status", "Reviewed Appeal #$appeal_id for Application #{$appeal['ApplicationID']}");
        $pdo->commit();
        $_SESSION['success'] = $resolved ? "Appeal finalized as $new_status." : 'Appeal marked as Under Review.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    header("Location: $back");
    exit();
}

http_response_code(403);
echo 'Unauthorized appeal action.';
