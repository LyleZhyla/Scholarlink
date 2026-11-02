<?php
ignore_user_abort(true);set_time_limit(0);ini_set('memory_limit','512M');
require __DIR__.'/../includes/db_connect.php';require_once __DIR__.'/../includes/disaster_recovery.php';
try{
    if((int)$pdo->query("SELECT GET_LOCK('scholarlink_scheduled_backup',0)")->fetchColumn()!==1)exit;
    if(!drScheduleDue($pdo))exit;
    $actor=(int)$pdo->query("SELECT UserID FROM users WHERE Role='Super_Admin' AND AccountStatus='Active' ORDER BY UserID LIMIT 1")->fetchColumn();
    $backup=drCreateBackup($pdo,'Scheduled',$actor?:null);$expired=drEnforceRetention($pdo);
    logAudit($pdo,$actor?:null,'Scheduled Encrypted Backup',"Encrypted backup #{$backup['id']} stored outside the web root; $expired expired backup(s) securely disposed.");
}catch(Throwable $e){error_log('Scheduled Backup Error: '.$e->getMessage());try{logAudit($pdo,null,'Scheduled Backup Failed',mb_substr($e->getMessage(),0,900));}catch(Throwable $ignored){} }
finally{try{$pdo->query("SELECT RELEASE_LOCK('scholarlink_scheduled_backup')");}catch(Throwable $ignored){}}
