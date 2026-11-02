<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/application_authority.php';
require_once '../includes/approval_workflow.php';

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['Internal_Admin','External_Admin','Super_Admin'], true)) {
    header('Location: ../admin_login.php'); exit;
}

$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];
$base = $role === 'Internal_Admin' ? '../internal_admin/' : ($role === 'External_Admin' ? '../external_admin/' : '../super_admin/');
$action = $_POST['action'] ?? '';

try {
    if ($action === 'save') {
        $scholarshipId = (int) ($_POST['scholarship_id'] ?? 0);
        if (!canManageScholarshipWorkflow($pdo, $scholarshipId, $role, $userId)) throw new RuntimeException('You are not allowed to edit this scholarship workflow.');
        $names = $_POST['stage_name'] ?? [];
        if (!is_array($names) || count($names) < 1 || count($names) > 12) throw new RuntimeException('A workflow must contain 1 to 12 stages.');
        $pdo->beginTransaction();
        $authority = getScholarshipAuthority($pdo, $scholarshipId);
        $pdo->prepare('DELETE FROM scholarship_workflow_stages WHERE ScholarshipID = ?')->execute([$scholarshipId]);
        $insert = $pdo->prepare('INSERT INTO scholarship_workflow_stages (ScholarshipID, StageOrder, StageKey, StageName, AssignedRole, CompletionAction, Instructions) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($names as $i => $rawName) {
            $name = trim((string) $rawName);
            $key = $_POST['stage_key'][$i] ?? 'custom';
            $assigned = $_POST['assigned_role'][$i] ?? '';
            $completion = $_POST['completion_action'][$i] ?? 'none';
            $instructions = trim((string) ($_POST['instructions'][$i] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Every stage needs a name of at most 120 characters.');
            if (!isset(WORKFLOW_STAGE_TYPES[$key])) $key = 'custom';
            if (!in_array($assigned, ['Internal_Admin','External_Admin','Super_Admin'], true)) throw new RuntimeException('Invalid reviewer role.');
            if ($assigned === 'External_Admin' && $authority['IsInternal']) throw new RuntimeException('An external-review stage requires a scholarship owned by an external sponsor account.');
            if (!isset(WORKFLOW_COMPLETION_ACTIONS[$completion])) throw new RuntimeException('Invalid completion action.');
            $insert->execute([$scholarshipId, $i + 1, $key, $name, $assigned, $completion, $instructions ?: null]);
        }
        $pdo->commit();
        logAudit($pdo, $userId, 'Approval Workflow Updated', "Updated the approval workflow for Scholarship #$scholarshipId");
        $_SESSION['success'] = 'Approval workflow saved. Existing applications kept their original stage history; new applications will use this sequence.';
        header("Location: {$base}workflow_builder.php?id=$scholarshipId"); exit;
    }

    if ($action === 'complete') {
        $progressId = (int) ($_POST['progress_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT p.*, a.UserID, a.Status AS ApplicationStatus, a.ScholarshipID, s.Name AS ScholarshipName, s.CreatedBy FROM application_workflow_progress p JOIN application a ON a.ApplicationID = p.ApplicationID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE p.ProgressID = ? FOR UPDATE');
        $pdo->beginTransaction();
        $stmt->execute([$progressId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task || $task['Status'] !== 'In Progress') throw new RuntimeException('This workflow task is no longer active.');
        if (!canReviewWorkflowTask($task, $role, $userId)) throw new RuntimeException('This task is assigned to a different reviewer.');
        $comments = trim((string) ($_POST['comments'] ?? ''));
        $nextStatus = null;
        $waitlisted = null;
        if ($task['CompletionAction'] === 'under_review') $nextStatus = 'Under Review';
        if ($task['CompletionAction'] === 'shortlisted') $nextStatus = 'Shortlisted';
        if ($task['CompletionAction'] === 'approve') {
            require_once '../includes/waitlist.php';
            if (!in_array($task['ApplicationStatus'], ['Shortlisted','Scored','Waitlisted'], true)) {
                $pdo->prepare("UPDATE application SET Status = 'Shortlisted', LastSavedAt = NOW() WHERE ApplicationID = ?")->execute([$task['ApplicationID']]);
            }
            $waitlisted = waitlistIfScholarshipFull($pdo, (int) $task['ApplicationID'], $userId);
            $nextStatus = $waitlisted ? null : 'Approved';
        }
        if ($nextStatus) $pdo->prepare('UPDATE application SET Status = ?, LastSavedAt = NOW() WHERE ApplicationID = ?')->execute([$nextStatus, $task['ApplicationID']]);
        $pdo->prepare("UPDATE application_workflow_progress SET Status = 'Completed', ReviewerComments = ?, CompletedAt = NOW(), CompletedBy = ? WHERE ProgressID = ?")->execute([$comments ?: null, $userId, $progressId]);
        $next = $pdo->prepare("SELECT ProgressID FROM application_workflow_progress WHERE ApplicationID = ? AND Status = 'Pending' ORDER BY StageOrder LIMIT 1");
        $next->execute([$task['ApplicationID']]);
        if (!$waitlisted && ($nextId = $next->fetchColumn())) $pdo->prepare("UPDATE application_workflow_progress SET Status = 'In Progress', StartedAt = NOW() WHERE ProgressID = ?")->execute([$nextId]);
        $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, 'Application workflow updated', ?, 'info')")->execute([$task['UserID'], $task['StageName'] . ' was completed for ' . $task['ScholarshipName'] . '.']);
        $pdo->commit();
        logAudit($pdo, $userId, 'Workflow Stage Completed', "Completed {$task['StageName']} for Application #{$task['ApplicationID']}");
        if ($nextStatus === 'Approved') {
            try { require_once '../includes/award_notice.php'; notifyAwardee($pdo, (int) $task['ApplicationID']); } catch (Throwable $noticeError) { error_log($noticeError->getMessage()); }
        }
        $_SESSION['success'] = $waitlisted ? 'Final approval completed, but all slots are filled. The qualified applicant was placed on the ranked waitlist.' : $task['StageName'] . ' completed. The application moved to the next configured stage.';
        header("Location: {$base}workflow_tasks.php"); exit;
    }
    throw new RuntimeException('Invalid workflow action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? $base . 'workflow_tasks.php')); exit;
}

