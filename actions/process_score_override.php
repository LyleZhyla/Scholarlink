<?php
session_start();require '../includes/db_connect.php';
if(!isset($_SESSION['logged_in'])||$_SESSION['role']!=='Super_Admin'){http_response_code(403);exit('Only Super Admin may override scores.');}
$applicationId=(int)($_POST['application_id']??0);$scorecardId=(int)($_POST['scorecard_id']??0);$score=$_POST['override_score']??null;$reason=trim($_POST['reason']??'');
try{
 if(!is_numeric($score)||(float)$score<0||(float)$score>100)throw new RuntimeException('Override score must be from 0 to 100.');if(mb_strlen($reason)<15)throw new RuntimeException('A detailed override reason of at least 15 characters is required.');
 $pdo->beginTransaction();$stmt=$pdo->prepare('SELECT a.TotalScore,a.ScholarshipID,sc.ScorecardID FROM application a JOIN application_scorecards sc ON sc.ApplicationID=a.ApplicationID AND sc.IsCurrent=1 WHERE a.ApplicationID=? AND sc.ScorecardID=? FOR UPDATE');$stmt->execute([$applicationId,$scorecardId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('The current scorecard could not be found.');
 $new=round((float)$score,2);$pdo->prepare('INSERT INTO score_overrides(ApplicationID,ScorecardID,PreviousScore,OverrideScore,Reason,AuthorizedBy) VALUES(?,?,?,?,?,?)')->execute([$applicationId,$scorecardId,$row['TotalScore'],$new,$reason,$_SESSION['user_id']]);$pdo->prepare('UPDATE application SET TotalScore=? WHERE ApplicationID=?')->execute([$new,$applicationId]);
 require_once '../includes/waitlist.php';refreshWaitlistRanks($pdo,(int)$row['ScholarshipID']);logAudit($pdo,(int)$_SESSION['user_id'],'Score Override Authorized',"Application #$applicationId score overridden from {$row['TotalScore']} to $new. Reason: $reason");$pdo->commit();$_SESSION['success']='Score override recorded. The original scorecard was preserved.';
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['error']=$e->getMessage();}
header('Location: ../super_admin/score_overrides.php'.keepScholarshipScope());exit;
