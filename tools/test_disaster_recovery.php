<?php
require __DIR__.'/../includes/db_connect.php';require_once __DIR__.'/../includes/disaster_recovery.php';
$failures=[];function drCheck(bool $ok,string $label):void{global $failures;echo($ok?'PASS':'FAIL').": $label\n";if(!$ok)$failures[]=$label;}
$actor=(int)$pdo->query("SELECT UserID FROM users WHERE Role='Super_Admin' AND AccountStatus='Active' LIMIT 1")->fetchColumn();$backupId=0;$path='';
try{
    $storage=drStorageDirectory($pdo);$project=realpath(dirname(__DIR__));drCheck(!str_starts_with(strtolower($storage),strtolower($project).DIRECTORY_SEPARATOR),'backup storage resolves outside the ScholarLink web directory');
    $sample='ScholarLink disaster recovery authenticated-encryption test';$package=drEncrypt($sample);drCheck(str_starts_with($package,DR_BACKUP_MAGIC)&&drDecrypt($package)===$sample,'AES-256-GCM package encrypts and decrypts correctly');
    $tampered=$package;$tampered[strlen($tampered)-1]=chr(ord($tampered[strlen($tampered)-1])^1);$blocked=false;try{drDecrypt($tampered);}catch(Throwable $e){$blocked=true;}drCheck($blocked,'modified encrypted package is rejected');
    $backup=drCreateBackup($pdo,'Manual',$actor);$backupId=(int)$backup['id'];$path=$backup['path'];drCheck(is_file($path)&&filesize($path)>0,'encrypted backup is written to separate storage');
    $prefix=file_get_contents($path,false,null,0,6);drCheck($prefix===DR_BACKUP_MAGIC&&$prefix!=="-- Sch",'stored backup is encrypted rather than plain SQL');
    [$catalog,$plain]=drLoadBackup($pdo,$backupId);drCheck(str_contains($plain,'CREATE TABLE')&&hash_equals($catalog['PlainChecksum'],hash('sha256',$plain)),'catalog checksums validate encrypted and decrypted content');
    $drill=drRunRestorationDrill($pdo,$backupId,$actor);drCheck($drill['tables']>=4&&$drill['rows']>=1,'isolated restoration drill imports and validates tables and rows');drCheck($drill['rpo']&&$drill['rto'],'measured drill meets configured RPO and RTO');
    $record=$pdo->prepare('SELECT TargetDatabase,Status,CompletedAt,RestoreDurationSeconds,ValidationNotes FROM restoration_drills WHERE DrillID=?');$record->execute([$drill['id']]);$record=$record->fetch(PDO::FETCH_ASSOC);drCheck($record['Status']==='Passed'&&!empty($record['CompletedAt'])&&(float)$record['RestoreDurationSeconds']>0,'restoration evidence is documented');
    $server=drServerPdo();$exists=$server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$record['TargetDatabase']]);drCheck((int)$exists->fetchColumn()===0,'isolated drill database is removed after validation');
    $days=(int)drSettings($pdo)['RetentionDays'];$pdo->prepare('UPDATE backup_records SET CreatedAt=DATE_SUB(NOW(),INTERVAL ? DAY) WHERE BackupID=?')->execute([$days+1,$backupId]);$expired=drEnforceRetention($pdo);$status=$pdo->prepare('SELECT Status FROM backup_records WHERE BackupID=?');$status->execute([$backupId]);drCheck($expired===1&&!is_file($path)&&$status->fetchColumn()==='Expired','retention policy securely expires and removes old backup files');
}finally{
    if($backupId){$pdo->prepare('DELETE FROM restoration_drills WHERE BackupID=?')->execute([$backupId]);if($path&&is_file($path))drSecureDelete($pdo,$path);$pdo->prepare('DELETE FROM backup_records WHERE BackupID=?')->execute([$backupId]);}
}
if($failures){echo "\n".count($failures)." disaster-recovery test(s) failed.\n";exit(1);}echo "\nAll disaster-recovery tests passed.\n";
