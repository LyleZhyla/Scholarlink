<?php
require_once __DIR__ . '/application_authority.php';

function activeEvaluationAssignment(PDO $pdo, int $application_id): ?array {
    $stmt=$pdo->prepare("SELECT ea.*,u.FirstName,u.LastName,u.Organization FROM evaluation_assignments ea JOIN users u ON u.UserID=ea.EvaluatorID WHERE ea.ApplicationID=? AND ea.AssignmentStatus='Active' ORDER BY ea.AssignmentID DESC LIMIT 1");
    $stmt->execute([$application_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function canEvaluateApplication(PDO $pdo, int $application_id, string $role, int $user_id): bool {
    if (!in_array($role,['External_Admin','Super_Admin'],true)) return false;
    $active=activeEvaluationAssignment($pdo,$application_id);
    if ($active) return (int)$active['EvaluatorID']===$user_id;
    $history=$pdo->prepare('SELECT COUNT(*) FROM evaluation_assignments WHERE ApplicationID=?');$history->execute([$application_id]);
    if ((int)$history->fetchColumn()>0) return false;
    $authority=getApplicationAuthority($pdo,$application_id);
    return $authority && canDecideApplication($authority,$role,$user_id);
}

function ensureEvaluationAssignment(PDO $pdo, int $application_id, int $evaluator_id, int $actor_id): array {
    $active=activeEvaluationAssignment($pdo,$application_id);
    if ($active) {
        if ((int)$active['EvaluatorID']!==$evaluator_id) throw new RuntimeException('This application is assigned to another evaluator.');
        return $active;
    }
    $history=$pdo->prepare('SELECT COUNT(*) FROM evaluation_assignments WHERE ApplicationID=?');$history->execute([$application_id]);
    if ((int)$history->fetchColumn()>0) throw new RuntimeException('This evaluation is awaiting reassignment.');
    $pdo->prepare("INSERT INTO evaluation_assignments (ApplicationID,EvaluatorID,AssignedBy,AssignmentReason) VALUES (?,?,?,'Initial evaluator assignment')")->execute([$application_id,$evaluator_id,$actor_id]);
    return activeEvaluationAssignment($pdo,$application_id);
}

function recordNoConflictDeclaration(PDO $pdo, int $application_id, int $evaluator_id): void {
    $assignment=ensureEvaluationAssignment($pdo,$application_id,$evaluator_id,$evaluator_id);
    $existing=$pdo->prepare('SELECT ConflictStatus FROM evaluation_conflict_declarations WHERE AssignmentID=?');$existing->execute([$assignment['AssignmentID']]);
    $status=$existing->fetchColumn();
    if ($status==='Conflict Disclosed') throw new RuntimeException('You disclosed a conflict for this application and cannot evaluate it.');
    $pdo->prepare("INSERT INTO evaluation_conflict_declarations (AssignmentID,ApplicationID,EvaluatorID,ConflictStatus,RelationshipType,Details,ResolutionStatus) VALUES (?,?,?,'No Conflict','None','Evaluator certified no prohibited relationship before scoring.','Cleared') ON DUPLICATE KEY UPDATE ConflictStatus='No Conflict',RelationshipType='None',Details=VALUES(Details),ResolutionStatus='Cleared',DeclaredAt=NOW()")
        ->execute([$assignment['AssignmentID'],$application_id,$evaluator_id]);
}

function discloseEvaluationConflict(PDO $pdo,int $application_id,int $evaluator_id,string $relationship,string $details): int {
    $allowed=['Relative','Personally Known','Financial or Business Interest','Other Prohibited Relationship'];
    if(!in_array($relationship,$allowed,true)) throw new RuntimeException('Select a valid prohibited relationship.');
    if(mb_strlen(trim($details))<10) throw new RuntimeException('Provide a specific conflict explanation of at least 10 characters.');
    $assignment=ensureEvaluationAssignment($pdo,$application_id,$evaluator_id,$evaluator_id);
    $pdo->prepare("UPDATE evaluation_assignments SET AssignmentStatus='Conflict',AssignmentReason=?,EndedAt=NOW() WHERE AssignmentID=? AND AssignmentStatus='Active'")->execute([$relationship.': '.$details,$assignment['AssignmentID']]);
    $pdo->prepare("INSERT INTO evaluation_conflict_declarations (AssignmentID,ApplicationID,EvaluatorID,ConflictStatus,RelationshipType,Details,ResolutionStatus) VALUES (?,?,?,'Conflict Disclosed',?,?,'Pending Reassignment') ON DUPLICATE KEY UPDATE ConflictStatus='Conflict Disclosed',RelationshipType=VALUES(RelationshipType),Details=VALUES(Details),ResolutionStatus='Pending Reassignment',DeclaredAt=NOW()")
        ->execute([$assignment['AssignmentID'],$application_id,$evaluator_id,$relationship,trim($details)]);
    return (int)$assignment['AssignmentID'];
}
