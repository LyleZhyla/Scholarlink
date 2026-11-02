<?php
session_start(); require '../includes/db_connect.php'; require_once '../includes/scholarship_config_versions.php';
if(!isset($_SESSION['logged_in'])||!in_array($_SESSION['role'],['External_Admin','Super_Admin'],true)){http_response_code(403);exit('Unauthorized');}
$id=(int)($_POST['scholarship_id']??0); $back=$_SESSION['role']==='Super_Admin'?'../super_admin/scoring_rubrics.php':'../external_admin/scoring_rubrics.php';
try {
    $owner=$pdo->prepare('SELECT CreatedBy FROM scholarship WHERE ScholarshipID=?');$owner->execute([$id]);$createdBy=(int)$owner->fetchColumn();
    if(!$createdBy||($_SESSION['role']==='External_Admin'&&$createdBy!==(int)$_SESSION['user_id']))throw new RuntimeException('You cannot configure this scholarship rubric.');
    $ids=array_map('intval',$_POST['criteria_ids']??[]);$weights=$_POST['weights']??[];$maxes=$_POST['maximum_scores']??[];
    if(!$ids||count($ids)!==count($weights)||count($ids)!==count($maxes))throw new RuntimeException('At least one complete criterion is required.');
    $weightTotal=array_sum(array_map('floatval',$weights));if(abs($weightTotal-100)>0.01)throw new RuntimeException('Criterion weights must total exactly 100%.');
    $passing=(float)($_POST['passing_score']??-1);if($passing<0||$passing>100)throw new RuntimeException('Passing score must be from 0 to 100.');
    $tie=trim($_POST['tie_breaking_rules']??'');$procedure=trim($_POST['override_procedure']??'');if(mb_strlen($tie)<10||mb_strlen($procedure)<10)throw new RuntimeException('Tie-breaking and override procedures must each contain at least 10 characters.');
    $pdo->beginTransaction(); freezeUnversionedApplications($pdo,$id,(int)$_SESSION['user_id']);
    $up=$pdo->prepare('INSERT INTO scholarship_scoring_rubrics (ScholarshipID,PassingScore,TotalMaximumScore,TieBreakingRules,GeneralCommentsRequired,OverrideProcedure,UpdatedBy) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE PassingScore=VALUES(PassingScore),TotalMaximumScore=VALUES(TotalMaximumScore),TieBreakingRules=VALUES(TieBreakingRules),GeneralCommentsRequired=VALUES(GeneralCommentsRequired),OverrideProcedure=VALUES(OverrideProcedure),UpdatedBy=VALUES(UpdatedBy)');
    $up->execute([$id,$passing,(float)$_POST['total_maximum'],$tie,isset($_POST['general_comments_required'])?1:0,$procedure,$_SESSION['user_id']]);
    $allowed=$pdo->prepare('SELECT CriteriaID FROM scholarship_criteria WHERE ScholarshipID=? AND IsActive=1');$allowed->execute([$id]);$allowedIds=array_map('intval',$allowed->fetchAll(PDO::FETCH_COLUMN));if(array_diff($ids,$allowedIds))throw new RuntimeException('A criterion does not belong to this scholarship.');
    $crit=$pdo->prepare('UPDATE scholarship_criteria SET WeightPercent=?,MaximumScore=?,PassingScore=?,CommentRequired=?,TieBreakPriority=?,SortOrder=? WHERE CriteriaID=? AND ScholarshipID=? AND IsActive=1');
    foreach($ids as $i=>$cid){$max=(float)$maxes[$i];$cp=($_POST['criterion_passing_scores'][$i]??'')===''?null:(float)$_POST['criterion_passing_scores'][$i];if($max<=0||($cp!==null&&($cp<0||$cp>$max)))throw new RuntimeException('Criterion maximum and passing scores are invalid.');$priority=($_POST['tie_priorities'][$i]??'')===''?null:(int)$_POST['tie_priorities'][$i];$crit->execute([(float)$weights[$i],$max,$cp,(int)($_POST['comment_required'][$i]??0),$priority,$i+1,$cid,$id]);}
    currentScholarshipConfigurationVersion($pdo,$id,(int)$_SESSION['user_id'],'Scoring rubric rules updated');
    logAudit($pdo,(int)$_SESSION['user_id'],'Scoring Rubric Updated',"Scholarship #$id rubric saved with passing score $passing and 100% total weight.");$pdo->commit();$_SESSION['success']='Scoring rubric saved and versioned. Existing submissions keep their previous rubric.';
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['error']=$e->getMessage();}
header('Location: '.$back.'?scholarship_id='.$id);exit;
