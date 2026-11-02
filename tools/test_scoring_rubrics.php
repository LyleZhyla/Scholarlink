<?php
require __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/scoring_rubrics.php';
try {
    $pdo->beginTransaction();
    $app = $pdo->query("SELECT a.ApplicationID,a.ScholarshipID FROM application a WHERE EXISTS(SELECT 1 FROM scholarship_criteria c WHERE c.ScholarshipID=a.ScholarshipID AND c.IsActive=1) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $user = (int)$pdo->query("SELECT UserID FROM users WHERE Role='Super_Admin' LIMIT 1")->fetchColumn();
    if(!$app||!$user) throw new RuntimeException('Rubric test fixture is unavailable.');
    $rubric=scholarshipRubric($pdo,(int)$app['ScholarshipID']);
    $count=count($rubric['criteria']);$equal=round(100/$count,2);
    foreach($rubric['criteria'] as &$criterion){$criterion['WeightPercent']=$equal;$criterion['MaximumScore']=100;$criterion['CommentRequired']=1;}unset($criterion);
    if($count>1)$rubric['criteria'][$count-1]['WeightPercent']=round(100-$equal*($count-1),2);
    $rubric['PassingScore']=75;$rubric['GeneralCommentsRequired']=1;
    $scores=[];$comments=[];foreach($rubric['criteria'] as $c){$scores[$c['CriteriaID']]=80;$comments[$c['CriteriaID']]='Evidence supports this criterion.';}
    $calc=calculateRubricScore($rubric,$scores,$comments,'Complete evaluator justification for the result.');
    if(abs($calc['weighted_score']-80)>0.01||$calc['result']!=='Passed')throw new RuntimeException('Weighted score or passing rule is incorrect.');
    $tampered=calculateRubricScore($rubric,$scores,$comments,'Client total is ignored; rubric values are recalculated.');
    if($tampered['weighted_score']!==$calc['weighted_score'])throw new RuntimeException('Server calculation is not deterministic.');
    $scorecard=saveApplicationScorecard($pdo,(int)$app['ApplicationID'],$user,$rubric,$calc,'Complete evaluator justification for the result.');
    $items=$pdo->prepare('SELECT COUNT(*) FROM application_score_items WHERE ScorecardID=?');$items->execute([$scorecard]);if((int)$items->fetchColumn()!==$count)throw new RuntimeException('Criterion score items were not retained.');
    $pdo->prepare('INSERT INTO score_overrides(ApplicationID,ScorecardID,PreviousScore,OverrideScore,Reason,AuthorizedBy) VALUES(?,?,?,?,?,?)')->execute([$app['ApplicationID'],$scorecard,80,82,'Authorized test override with documented evidence.',$user]);
    $original=$pdo->prepare('SELECT WeightedScore FROM application_scorecards WHERE ScorecardID=?');$original->execute([$scorecard]);if((float)$original->fetchColumn()!==80.0)throw new RuntimeException('Override changed the immutable original scorecard.');
    $pdo->rollBack();
    echo "Scoring rubric test passed: weighted calculation, passing rule, required comments, criterion history, and immutable override trail work.\n";
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1); }
