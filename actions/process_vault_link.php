<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/application_lifecycle.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../student_login.php'); exit;
}

$user_id = (int)$_SESSION['user_id'];
$application_id = (int)($_POST['application_id'] ?? 0);
$requirement_id = (int)($_POST['requirement_id'] ?? 0);
$vault_id = (int)($_POST['vault_id'] ?? 0);

try {
    if (!$application_id || !$requirement_id || !$vault_id) throw new RuntimeException('Missing application or vault data.');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT a.ScholarshipID,a.Status,a.ResubmissionAllowed,s.Deadline FROM application a JOIN scholarship s ON s.ScholarshipID=a.ScholarshipID JOIN document_requirement r ON r.ScholarshipID=a.ScholarshipID WHERE a.ApplicationID=? AND a.UserID=? AND r.RequirementID=? FOR UPDATE");
    $stmt->execute([$application_id,$user_id,$requirement_id]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$application) throw new RuntimeException('You do not own this application or requirement.');
    $before_deadline = strtotime($application['Deadline']) >= strtotime(date('Y-m-d'));
    $editable = $application['Status']==='For Resubmission' || (in_array($application['Status'],['Draft','Submitted'],true)&&$before_deadline) || ($application['Status']==='Withdrawn'&&(int)$application['ResubmissionAllowed']&&$before_deadline);
    if (!$editable) throw new RuntimeException('Documents can no longer be changed for this application.');

    $_POST['vault_files'] = [$requirement_id => $vault_id];
    saveRequestedApplicationDocuments($pdo,$application_id,(int)$application['ScholarshipID'],$user_id);
    snapshotApplicationVersion($pdo,$application_id,'Document Updated',$user_id,"Requirement #$requirement_id linked from owned vault file #$vault_id");
    logAudit($pdo,$user_id,'Vault Document Linked',"Validated vault file #$vault_id for application #$application_id requirement #$requirement_id");
    $pdo->commit();
    $_SESSION['success']='Vault document ownership, content, malware scan, and checksum validated.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error']=$e->getMessage();
}
header('Location: ../student/requirements.php');
exit;
