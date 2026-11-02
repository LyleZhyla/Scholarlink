<?php

const WORKFLOW_STAGE_TYPES = [
    'document_verification' => 'Document Verification',
    'eligibility_review' => 'Eligibility Review',
    'external_evaluation' => 'External Evaluation',
    'internal_approval' => 'Internal Approval',
    'final_approval' => 'Final Approval',
    'award_confirmation' => 'Award Confirmation',
    'custom' => 'Custom Stage',
];

const WORKFLOW_COMPLETION_ACTIONS = [
    'none' => 'No status change',
    'under_review' => 'Mark Under Review',
    'shortlisted' => 'Mark Shortlisted',
    'approve' => 'Approve / waitlist if full',
    'award_confirm' => 'Confirm award',
];

function workflowTablesReady(PDO $pdo): bool {
    static $ready;
    if ($ready !== null) return $ready;
    $stmt = $pdo->query("SHOW TABLES LIKE 'scholarship_workflow_stages'");
    return $ready = (bool) $stmt->fetchColumn();
}

function createDefaultScholarshipWorkflow(PDO $pdo, int $scholarshipId): void {
    if (!workflowTablesReady($pdo)) return;
    $exists = $pdo->prepare('SELECT COUNT(*) FROM scholarship_workflow_stages WHERE ScholarshipID = ?');
    $exists->execute([$scholarshipId]);
    if ((int) $exists->fetchColumn() > 0) return;

    $authority = getScholarshipAuthority($pdo, $scholarshipId);
    if (!$authority) return;
    $role = $authority['IsInternal'] ? 'Internal_Admin' : 'External_Admin';
    $stages = $authority['IsInternal']
        ? [['document_verification','Document Verification','none'], ['eligibility_review','Eligibility Review','under_review'], ['internal_approval','Internal Approval','shortlisted'], ['final_approval','Final Approval','approve'], ['award_confirmation','Award Confirmation','award_confirm']]
        : [['document_verification','Document Verification','none'], ['eligibility_review','Eligibility Review','under_review'], ['external_evaluation','External Evaluation','shortlisted'], ['final_approval','Final Approval','approve'], ['award_confirmation','Award Confirmation','award_confirm']];
    $insert = $pdo->prepare('INSERT INTO scholarship_workflow_stages (ScholarshipID, StageOrder, StageKey, StageName, AssignedRole, CompletionAction) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($stages as $i => $stage) $insert->execute([$scholarshipId, $i + 1, $stage[0], $stage[1], $role, $stage[2]]);
}

function initializeApplicationWorkflow(PDO $pdo, int $applicationId): void {
    if (!workflowTablesReady($pdo)) return;
    $app = $pdo->prepare('SELECT ScholarshipID, Status FROM application WHERE ApplicationID = ?');
    $app->execute([$applicationId]);
    $application = $app->fetch(PDO::FETCH_ASSOC);
    if (!$application || in_array($application['Status'], ['Draft', 'Withdrawn'], true)) return;

    $exists = $pdo->prepare('SELECT COUNT(*) FROM application_workflow_progress WHERE ApplicationID = ?');
    $exists->execute([$applicationId]);
    if ((int) $exists->fetchColumn() > 0) return;
    createDefaultScholarshipWorkflow($pdo, (int) $application['ScholarshipID']);

    $stages = $pdo->prepare('SELECT * FROM scholarship_workflow_stages WHERE ScholarshipID = ? AND IsActive = 1 ORDER BY StageOrder');
    $stages->execute([$application['ScholarshipID']]);
    $insert = $pdo->prepare('INSERT INTO application_workflow_progress (ApplicationID, WorkflowStageID, StageOrder, StageKey, StageName, AssignedRole, CompletionAction, Instructions, Status, StartedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($stages->fetchAll(PDO::FETCH_ASSOC) as $i => $stage) {
        $insert->execute([$applicationId, $stage['WorkflowStageID'], $i + 1, $stage['StageKey'], $stage['StageName'], $stage['AssignedRole'], $stage['CompletionAction'], $stage['Instructions'], $i === 0 ? 'In Progress' : 'Pending', $i === 0 ? date('Y-m-d H:i:s') : null]);
    }
}

function canManageScholarshipWorkflow(PDO $pdo, int $scholarshipId, string $role, int $userId): bool {
    $authority = getScholarshipAuthority($pdo, $scholarshipId);
    return $authority && canDecideApplication($authority, $role, $userId);
}

function canReviewWorkflowTask(array $task, string $role, int $userId): bool {
    if ($role === 'Super_Admin') return true;
    if ($task['AssignedRole'] !== $role) return false;
    if ($role === 'External_Admin') return (int) $task['CreatedBy'] === $userId;
    return $role === 'Internal_Admin';
}

function workflowProgress(PDO $pdo, int $applicationId): array {
    initializeApplicationWorkflow($pdo, $applicationId);
    $stmt = $pdo->prepare('SELECT p.*, CONCAT(COALESCE(u.FirstName, \'\'), \' \', COALESCE(u.LastName, \'\')) AS ReviewerName FROM application_workflow_progress p LEFT JOIN users u ON u.UserID = p.CompletedBy WHERE p.ApplicationID = ? ORDER BY p.StageOrder');
    $stmt->execute([$applicationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function applicationUsesConfiguredWorkflow(PDO $pdo, int $applicationId): bool {
    if (!workflowTablesReady($pdo)) return false;
    initializeApplicationWorkflow($pdo, $applicationId);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM application_workflow_progress WHERE ApplicationID = ?');
    $stmt->execute([$applicationId]);
    return (int) $stmt->fetchColumn() > 0;
}

