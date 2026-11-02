<?php

function privacyPolicyMap(PDO $pdo): array {
    $rows=$pdo->query('SELECT * FROM privacy_retention_policies ORDER BY PolicyID')->fetchAll(PDO::FETCH_ASSOC);
    $out=[];foreach($rows as $row)$out[$row['PolicyKey']]=$row;return $out;
}

function privacyRetentionDays(PDO $pdo,string $key,int $fallback): int {
    try{$s=$pdo->prepare('SELECT RetentionDays FROM privacy_retention_policies WHERE PolicyKey=? AND IsActive=1');$s->execute([$key]);return (int)($s->fetchColumn()?:$fallback);}catch(Throwable $e){return $fallback;}
}

function privacyEligibleAt(?string $date,int $days): string {
    $base=$date?new DateTimeImmutable($date):new DateTimeImmutable();return $base->modify("+$days days")->format('Y-m-d H:i:s');
}

function generatePrivacyRetentionCases(PDO $pdo): int {
    $policies=privacyPolicyMap($pdo);$now=new DateTimeImmutable();$created=0;
    $insert=$pdo->prepare("INSERT IGNORE INTO privacy_retention_cases(PolicyKey,RecordType,RecordID,SubjectUserID,RecordDescription,EligibleAt) VALUES(?,?,?,?,?,?)");
    $add=function(string $key,string $type,string $id,?int $user,string $description,?string $date)use(&$created,$insert,$policies,$now){if(empty($policies[$key]['IsActive']))return;$eligible=privacyEligibleAt($date,(int)$policies[$key]['RetentionDays']);if(new DateTimeImmutable($eligible)>$now)return;$insert->execute([$key,$type,$id,$user,mb_substr($description,0,500),$eligible]);$created+=$insert->rowCount();};
    foreach($pdo->query("SELECT a.ApplicationID,a.UserID,a.Status,a.DecisionAt,a.WithdrawnAt,a.DateSubmitted,s.Name,u.FirstName,u.LastName,(SELECT MAX(br.PaymentDate) FROM benefit_releases br WHERE br.ApplicationID=a.ApplicationID) LastBenefitDate FROM application a JOIN scholarship s ON s.ScholarshipID=a.ScholarshipID JOIN users u ON u.UserID=a.UserID WHERE a.Status IN('Withdrawn','Rejected','Disqualified','Approved')")->fetchAll(PDO::FETCH_ASSOC) as $a){
        $date=$a['Status']==='Approved'?($a['LastBenefitDate']?:$a['DecisionAt']):($a['DecisionAt']?:($a['WithdrawnAt']?:$a['DateSubmitted']));$key=in_array($a['Status'],['Rejected','Disqualified'],true)?'rejected_applicants':($a['Status']==='Approved'?'scholar_records':'applications');$add($key,'Application',(string)$a['ApplicationID'],(int)$a['UserID'],"{$a['Status']} application of {$a['FirstName']} {$a['LastName']} for {$a['Name']}",$date);
    }
    foreach($pdo->query("SELECT d.SubmittedDocID,d.ApplicationID,a.UserID,a.DecisionAt,a.WithdrawnAt,a.DateSubmitted,s.Name FROM submitted_document d JOIN application a ON a.ApplicationID=d.ApplicationID JOIN scholarship s ON s.ScholarshipID=a.ScholarshipID WHERE a.Status IN('Withdrawn','Rejected','Disqualified','Approved','Anonymized')")->fetchAll(PDO::FETCH_ASSOC) as $d)$add('uploaded_documents','SubmittedDocument',(string)$d['SubmittedDocID'],(int)$d['UserID'],"Uploaded document #{$d['SubmittedDocID']} for {$d['Name']}",$d['DecisionAt']?:($d['WithdrawnAt']?:$d['DateSubmitted']));
    foreach($pdo->query("SELECT v.VaultID,v.UserID,v.DocumentType,v.UploadDate FROM user_vault v JOIN users u ON u.UserID=v.UserID WHERE u.AccountStatus='Archived'")->fetchAll(PDO::FETCH_ASSOC) as $v)$add('uploaded_documents','VaultDocument',(string)$v['VaultID'],(int)$v['UserID'],"Archived user's vault document: {$v['DocumentType']}",$v['UploadDate']);
    foreach($pdo->query('SELECT AuditID,ActionPerformed,ActionDate FROM audit_log')->fetchAll(PDO::FETCH_ASSOC) as $a)$add('audit_logs','AuditLog',(string)$a['AuditID'],null,"Audit log: {$a['ActionPerformed']}",$a['ActionDate']);
    $root=dirname(__DIR__);foreach(glob($root.'/backups/*.sql')?:[] as $file)$add('backups','Backup',basename($file),null,'Database backup '.basename($file),date('Y-m-d H:i:s',filemtime($file)));
    return $created;
}

function secureDisposeFile(string $storedPath): bool {
    $root=realpath(dirname(__DIR__));$relative=preg_replace('~^(?:\.\.[/\\\\])+~','',$storedPath);$candidates=[$storedPath,$root.DIRECTORY_SEPARATOR.ltrim($storedPath,'/\\'),$root.DIRECTORY_SEPARATOR.ltrim($relative,'/\\')];$path=false;
    foreach($candidates as $candidate){$resolved=realpath($candidate);if($resolved!==false){$path=$resolved;break;}}
    if($path===false)return false;$uploads=realpath($root.'/uploads');$backups=realpath($root.'/backups');$allowed=($uploads&&str_starts_with(strtolower($path),strtolower($uploads).DIRECTORY_SEPARATOR))||($backups&&str_starts_with(strtolower($path),strtolower($backups).DIRECTORY_SEPARATOR));if(!$allowed)throw new RuntimeException('Refused disposal outside the protected upload or backup directories.');
    $size=filesize($path);$fh=fopen($path,'r+b');if($fh){$zero=str_repeat("\0",8192);$left=$size;while($left>0){$written=fwrite($fh,substr($zero,0,min(8192,$left)));if($written===false)break;$left-=$written;}fflush($fh);fclose($fh);}return unlink($path);
}

function anonymizePrivacyApplication(PDO $pdo,int $applicationId): array {
    $stmt=$pdo->prepare('SELECT UserID FROM application WHERE ApplicationID=? FOR UPDATE');$stmt->execute([$applicationId]);$userId=(int)$stmt->fetchColumn();if(!$userId)throw new RuntimeException('Application no longer exists.');
    $docs=$pdo->prepare('SELECT FilePath FROM submitted_document WHERE ApplicationID=?');$docs->execute([$applicationId]);$disposed=0;foreach($docs->fetchAll(PDO::FETCH_COLUMN) as $file){if(secureDisposeFile($file))$disposed++;}
    $appeal=$pdo->prepare('SELECT ad.FilePath FROM appeal_documents ad JOIN application_appeals aa ON aa.AppealID=ad.AppealID WHERE aa.ApplicationID=?');$appeal->execute([$applicationId]);foreach($appeal->fetchAll(PDO::FETCH_COLUMN) as $file){if(secureDisposeFile($file))$disposed++;}
    $pdo->prepare('DELETE FROM submitted_document WHERE ApplicationID=?')->execute([$applicationId]);$pdo->prepare('DELETE FROM application_custom_answers WHERE ApplicationID=?')->execute([$applicationId]);
    $pdo->prepare('DELETE ad FROM appeal_documents ad JOIN application_appeals aa ON aa.AppealID=ad.AppealID WHERE aa.ApplicationID=?')->execute([$applicationId]);
    $pdo->prepare("UPDATE application_appeals SET AppealReason='[REDACTED BY RETENTION POLICY]',ReviewerComments='[REDACTED BY RETENTION POLICY]' WHERE ApplicationID=?")->execute([$applicationId]);
    $pdo->prepare("UPDATE application_versions SET AnswersSnapshot='[REDACTED BY RETENTION POLICY]',DocumentsSnapshot='[SECURELY DISPOSED]',GPASnapshot=NULL,Notes='Personal data disposed under approved retention case' WHERE ApplicationID=?")->execute([$applicationId]);
    $pdo->prepare("UPDATE application SET GPA=NULL,YearLevel=NULL,TotalScore=0,Status='Anonymized',ResubmissionAllowed=0 WHERE ApplicationID=?")->execute([$applicationId]);
    return ['user_id'=>$userId,'files_disposed'=>$disposed];
}

function anonymizePrivacyUserIfEligible(PDO $pdo,int $userId): bool {
    $s=$pdo->prepare("SELECT COUNT(*) FROM application WHERE UserID=? AND Status NOT IN('Withdrawn','Rejected','Disqualified','Anonymized')");$s->execute([$userId]);if((int)$s->fetchColumn()>0)return false;
    $p=$pdo->prepare('SELECT ProfilePicture FROM users WHERE UserID=? FOR UPDATE');$p->execute([$userId]);$picture=$p->fetchColumn();if($picture)secureDisposeFile($picture);
    $vault=$pdo->prepare('SELECT FilePath FROM user_vault WHERE UserID=?');$vault->execute([$userId]);foreach($vault->fetchAll(PDO::FETCH_COLUMN) as $file)secureDisposeFile($file);$pdo->prepare('DELETE FROM user_vault WHERE UserID=?')->execute([$userId]);
    $token='anon-'.$userId.'-'.bin2hex(random_bytes(5));$pdo->prepare("UPDATE users SET Username=?,Email=?,PasswordHash=?,FirstName='Anonymized',LastName='Data Subject',MiddleName=NULL,ExtName=NULL,AccountStatus='Archived',StudentID_Num=NULL,LRN=NULL,PhilSysID=NULL,FourPsID=NULL,YearLevel=NULL,GPA=NULL,Major=NULL,ProgramID=NULL,Department=NULL,Organization=NULL,ContactNumber=NULL,DateOfBirth=NULL,ProfilePicture=NULL,ResetToken=NULL,ResetTokenExpire=NULL,Gender='Not Specified',FatherLastName=NULL,FatherFirstName=NULL,FatherMiddleName=NULL,MotherLastName=NULL,MotherFirstName=NULL,MotherMiddleName=NULL,StreetBarangay=NULL,CityMunicipality=NULL,Province=NULL,Region=NULL,ZipCode=NULL,DisabilityType=NULL,IndigenousGroup=NULL,GoogleID=NULL WHERE UserID=? AND Role='Student'")->execute([$token,$token.'@anonymized.invalid',password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$userId]);return $pdo->rowCount()>0;
}

function executePrivacyRetentionCase(PDO $pdo,int $caseId,int $actorId): string {
    $stmt=$pdo->prepare('SELECT c.*,p.DisposalMethod FROM privacy_retention_cases c JOIN privacy_retention_policies p ON p.PolicyKey=c.PolicyKey WHERE c.CaseID=? FOR UPDATE');$stmt->execute([$caseId]);$case=$stmt->fetch(PDO::FETCH_ASSOC);if(!$case)throw new RuntimeException('Retention case not found.');if($case['Status']!=='Approved')throw new RuntimeException('The case must be approved before execution.');if($case['LegalHold'])throw new RuntimeException('This record is under legal hold.');if(strtotime($case['EligibleAt'])>time())throw new RuntimeException('The retention period has not ended.');if($case['DisposalMethod']==='Review Only')throw new RuntimeException('This policy requires records-officer review and cannot automatically dispose data.');
    $evidence='';if($case['RecordType']==='AuditLog'){$pdo->prepare('DELETE FROM audit_log WHERE AuditID=?')->execute([(int)$case['RecordID']]);$evidence='Audit entry securely removed after retention approval.';}
    elseif($case['RecordType']==='Backup'){$ok=secureDisposeFile(dirname(__DIR__).'/backups/'.basename($case['RecordID']));$evidence=$ok?'Backup overwritten and removed.':'Backup was already absent; disposition recorded.';}
    elseif($case['RecordType']==='SubmittedDocument'){$d=$pdo->prepare('SELECT FilePath FROM submitted_document WHERE SubmittedDocID=? FOR UPDATE');$d->execute([(int)$case['RecordID']]);$path=$d->fetchColumn();if($path)secureDisposeFile($path);$pdo->prepare('DELETE FROM submitted_document WHERE SubmittedDocID=?')->execute([(int)$case['RecordID']]);$evidence='Document content overwritten, file removed, and metadata deleted.';}
    elseif($case['RecordType']==='VaultDocument'){$d=$pdo->prepare('SELECT FilePath FROM user_vault WHERE VaultID=? FOR UPDATE');$d->execute([(int)$case['RecordID']]);$path=$d->fetchColumn();if($path)secureDisposeFile($path);$pdo->prepare('DELETE FROM user_vault WHERE VaultID=?')->execute([(int)$case['RecordID']]);$evidence='Archived-user vault file overwritten and metadata deleted.';}
    elseif($case['RecordType']==='Application'){$result=anonymizePrivacyApplication($pdo,(int)$case['RecordID']);$userAnon=$case['PolicyKey']==='rejected_applicants'&&anonymizePrivacyUserIfEligible($pdo,(int)$result['user_id']);$evidence="Application data anonymized; {$result['files_disposed']} file(s) overwritten and removed".($userAnon?'; inactive applicant identity anonymized.':'.');}
    else throw new RuntimeException('Unsupported retention record type.');
    $pdo->prepare("UPDATE privacy_retention_cases SET Status='Completed',ExecutedBy=?,ExecutedAt=NOW(),DispositionEvidence=? WHERE CaseID=?")->execute([$actorId,$evidence,$caseId]);return $evidence;
}
