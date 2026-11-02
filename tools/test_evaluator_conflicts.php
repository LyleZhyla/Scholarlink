<?php
require __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/evaluation_conflicts.php';

$app=(int)$pdo->query('SELECT ApplicationID FROM application ORDER BY ApplicationID DESC LIMIT 1')->fetchColumn();
$super=(int)$pdo->query("SELECT UserID FROM users WHERE Role='Super_Admin' AND AccountStatus='Active' ORDER BY UserID LIMIT 1")->fetchColumn();
$replacement=(int)$pdo->query("SELECT UserID FROM users WHERE Role='External_Admin' AND AccountStatus='Active' ORDER BY UserID LIMIT 1")->fetchColumn();
if(!$app||!$super||!$replacement){fwrite(STDERR,"Evaluator conflict fixtures are unavailable.\n");exit(1);}

try{
    $pdo->beginTransaction();
    if(!canEvaluateApplication($pdo,$app,'Super_Admin',$super))throw new RuntimeException('Initial authorized evaluator was rejected.');
    $assignment=discloseEvaluationConflict($pdo,$app,$super,'Relative','Controlled test disclosure for a close relative.');
    if(canEvaluateApplication($pdo,$app,'Super_Admin',$super))throw new RuntimeException('Conflicted evaluator remained able to score.');
    $status=$pdo->query("SELECT ResolutionStatus FROM evaluation_conflict_declarations WHERE AssignmentID=".(int)$assignment)->fetchColumn();
    if($status!=='Pending Reassignment')throw new RuntimeException('Conflict was not queued for reassignment.');
    $pdo->prepare("UPDATE evaluation_assignments SET AssignmentStatus='Reassigned' WHERE AssignmentID=?")->execute([$assignment]);
    $pdo->prepare("INSERT INTO evaluation_assignments(ApplicationID,EvaluatorID,AssignedBy,AssignmentReason) VALUES (?,?,?,'Controlled reassignment test')")->execute([$app,$replacement,$super]);
    if(!canEvaluateApplication($pdo,$app,'External_Admin',$replacement))throw new RuntimeException('Replacement evaluator did not receive access.');
    recordNoConflictDeclaration($pdo,$app,$replacement);
    $declared=$pdo->prepare("SELECT ConflictStatus FROM evaluation_conflict_declarations d JOIN evaluation_assignments ea ON ea.AssignmentID=d.AssignmentID WHERE ea.ApplicationID=? AND ea.EvaluatorID=?");$declared->execute([$app,$replacement]);
    if($declared->fetchColumn()!=='No Conflict')throw new RuntimeException('Replacement no-conflict certification was not recorded.');
    $pdo->rollBack();
    echo "Evaluator conflict test passed: disclosure blocks scoring, queues reassignment, replacement access works, and certification is retained.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Evaluator conflict test FAILED: {$e->getMessage()}\n");exit(1);}
