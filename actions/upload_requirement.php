<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/application_lifecycle.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$application_id = (int) ($_POST['ApplicationID'] ?? 0);
$scholarship_id = (int) ($_POST['scholarship_id'] ?? 0);
$req_id = (int) ($_POST['RequirementID'] ?? 0);

try {
    $pdo->beginTransaction();
    $owner = $pdo->prepare("SELECT a.Status, a.ResubmissionAllowed, s.Deadline FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID JOIN document_requirement r ON r.ScholarshipID = a.ScholarshipID WHERE a.ApplicationID = ? AND a.UserID = ? AND a.ScholarshipID = ? AND r.RequirementID = ? FOR UPDATE");
    $owner->execute([$application_id, $user_id, $scholarship_id, $req_id]);
    $application = $owner->fetch(PDO::FETCH_ASSOC);
    if (!$application) throw new RuntimeException('Application or document requirement not found.');
    $before_deadline = strtotime($application['Deadline']) >= strtotime(date('Y-m-d'));
    $editable = $application['Status'] === 'For Resubmission'
        || (in_array($application['Status'], ['Draft', 'Submitted'], true) && $before_deadline)
        || ($application['Status'] === 'Withdrawn' && (int) $application['ResubmissionAllowed'] && $before_deadline);
    if (!$editable) throw new RuntimeException('Documents can no longer be changed for this application.');

    if (!empty($_POST['vault_id'])) {
        $_POST['vault_files'] = [$req_id => (int) $_POST['vault_id']];
    } elseif (isset($_FILES['file'])) {
        $_FILES['files'] = [
            'name' => [$req_id => $_FILES['file']['name']],
            'type' => [$req_id => $_FILES['file']['type']],
            'tmp_name' => [$req_id => $_FILES['file']['tmp_name']],
            'error' => [$req_id => $_FILES['file']['error']],
            'size' => [$req_id => $_FILES['file']['size']],
        ];
    } else {
        throw new RuntimeException('Please select a file or a document from your vault.');
    }

    saveRequestedApplicationDocuments($pdo, $application_id, $scholarship_id, $user_id);
    snapshotApplicationVersion($pdo, $application_id, 'Document Updated', $user_id, "Requirement #$req_id updated");
    logAudit($pdo, $user_id, 'Application Document Updated', "Updated requirement #$req_id for application #$application_id");
    $pdo->commit();
    $_SESSION['success'] = 'Document saved and added to the version history.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../student/requirements.php');
exit();
