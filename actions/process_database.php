<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/disaster_recovery.php';

if(empty($_SESSION['logged_in'])||($_SESSION['role']??'')!=='Super_Admin'){$_SESSION['error']='Unauthorized access.';header('Location: ../admin_login.php');exit;}
$action=$_POST['action']??$_GET['action']??'';$actor=(int)$_SESSION['user_id'];set_time_limit(0);ini_set('memory_limit','512M');
$back=function(string $type,string $message):never{$_SESSION[$type]=$message;header('Location: ../super_admin/database.php');exit;};

try{
    if($action==='manual_backup'){
        $backup=drCreateBackup($pdo,'Manual',$actor);$expired=drEnforceRetention($pdo);logAudit($pdo,$actor,'Encrypted Backup Created',"Manual encrypted backup #{$backup['id']} stored in approved secondary storage; checksum {$backup['encrypted_checksum']}.");
        $back('success',"Encrypted backup #{$backup['id']} created and verified (".number_format($backup['size']/1024,1)." KB). $expired expired backup(s) securely disposed.");
    }
    if($action==='download'){
        $id=(int)($_GET['id']??0);[$backup,,$path]=drLoadBackup($pdo,$id);logAudit($pdo,$actor,'Encrypted Backup Downloaded',"Encrypted backup #$id downloaded by Super Admin.");
        header('Content-Type: application/octet-stream');header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename="'.basename($backup['FileName']).'"');header('X-Content-Type-Options: nosniff');readfile($path);exit;
    }
    if($action==='save_settings'){
        $enabled=isset($_POST['schedule_enabled'])?1:0;$interval=(int)($_POST['interval_hours']??24);$rpo=(float)($_POST['rpo_hours']??24);$rto=(float)($_POST['rto_hours']??4);$retention=(int)($_POST['retention_days']??30);$path=trim((string)($_POST['secondary_path']??''));
        if(!in_array($interval,[1,6,12,24,48,168],true)||$rpo<0.25||$rpo>720||$rto<0.25||$rto>168||$retention<1||$retention>3650)throw new RuntimeException('Schedule, RPO, RTO, or retention value is outside the allowed range.');
        $pdo->beginTransaction();$pdo->prepare('UPDATE disaster_recovery_settings SET ScheduleEnabled=?,ScheduleIntervalHours=?,RPOHours=?,RTOHours=?,SecondaryStoragePath=?,RetentionDays=?,UpdatedBy=? WHERE SettingsID=1')->execute([$enabled,$interval,$rpo,$rto,$path?:null,$retention,$actor]);
        $pdo->prepare("UPDATE privacy_retention_policies SET RetentionDays=?,UpdatedBy=? WHERE PolicyKey='backups'")->execute([$retention,$actor]);drStorageDirectory($pdo);drEncryptionKeyInfo();$pdo->commit();
        logAudit($pdo,$actor,'Disaster Recovery Settings Updated',"Backup interval $interval hour(s), RPO $rpo hour(s), RTO $rto hour(s), retention $retention day(s), scheduled=".($enabled?'yes':'no').'.');$back('success','Disaster-recovery standards updated and storage/key paths validated.');
    }
    if($action==='drill'){
        $id=(int)($_POST['backup_id']??0);$result=drRunRestorationDrill($pdo,$id,$actor);logAudit($pdo,$actor,'Restoration Drill Passed',"Backup #$id restored to an isolated database in ".round($result['duration'],3)." seconds; {$result['tables']} tables and {$result['rows']} rows validated. RPO=".($result['rpo']?'pass':'fail').', RTO='.($result['rto']?'pass':'fail').'.');
        $back('success',"Restoration drill #{$result['id']} passed in ".round($result['duration'],3)." seconds. {$result['tables']} tables and {$result['rows']} rows validated; isolated drill database removed.");
    }
    if($action==='restore'){
        $id=(int)($_POST['backup_id']??0);if(trim((string)($_POST['confirmation']??''))!=='RESTORE')throw new RuntimeException('Type RESTORE exactly to authorize live recovery.');
        [$selected,$sql]=drLoadBackup($pdo,$id);$safety=drCreateBackup($pdo,'Pre-Restore',$actor);$started=microtime(true);$pdo->exec($sql);$duration=microtime(true)-$started;
        $pdo->prepare("INSERT INTO backup_records(BackupType,FileName,StoragePath,PlainChecksum,EncryptedChecksum,FileSize,CreatedBy) VALUES('Pre-Restore',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE StoragePath=VALUES(StoragePath),PlainChecksum=VALUES(PlainChecksum),EncryptedChecksum=VALUES(EncryptedChecksum),FileSize=VALUES(FileSize),Status='Available'")->execute([$safety['name'],$safety['path'],$safety['plain_checksum'],$safety['encrypted_checksum'],$safety['size'],$actor]);
        $safetyId=(int)($pdo->lastInsertId()?:$safety['id']);logAudit($pdo,$actor,'Live System Restore',"Live database restored from encrypted backup #$id in ".round($duration,3)." seconds. Pre-restore safety backup #$safetyId was created first.");
        $_SESSION['success']="Live restore completed in ".round($duration,3)." seconds. Pre-restore safety backup #$safetyId was created.";header('Location: ../super_admin/database.php');exit;
    }
    throw new RuntimeException('Invalid database recovery action.');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();logAudit($pdo,$actor,'Disaster Recovery Action Failed',mb_substr($action.': '.$e->getMessage(),0,1000));$back('error',$e->getMessage());}
