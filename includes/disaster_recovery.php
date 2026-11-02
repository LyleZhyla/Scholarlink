<?php

const DR_BACKUP_MAGIC = "SLBK1\n";

function drEnvironment(): array {
    static $env=null;
    if($env===null)$env=is_file(dirname(__DIR__).'/.env')?(@parse_ini_file(dirname(__DIR__).'/.env')?:[]):[];
    return $env;
}

function drDatabaseConfig(): array {
    $env=drEnvironment();
    return ['host'=>(string)($env['DB_HOST']??'127.0.0.1'),'port'=>(int)($env['DB_PORT']??3307),'user'=>(string)($env['DB_USER']??'root'),'password'=>(string)($env['DB_PASSWORD']??'')];
}

function drEncryptionKeyInfo(): array {
    $env=drEnvironment();$configured=trim((string)($env['BACKUP_ENCRYPTION_KEY']??getenv('BACKUP_ENCRYPTION_KEY')?:''));
    if($configured!=='')return ['key'=>hash('sha256',$configured,true),'source'=>'Environment secret'];
    $directory=dirname(__DIR__,3).DIRECTORY_SEPARATOR.'scholarlink_secrets';
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Cannot create the external backup-key directory.');
    $path=$directory.DIRECTORY_SEPARATOR.'backup.key';
    if(!is_file($path)){if(file_put_contents($path,base64_encode(random_bytes(32)),LOCK_EX)===false)throw new RuntimeException('Cannot create the external backup encryption key.');@chmod($path,0600);}
    $secret=trim((string)file_get_contents($path));if($secret==='')throw new RuntimeException('The external backup encryption key is empty.');
    return ['key'=>hash('sha256',$secret,true),'source'=>'External key file: '.$path];
}

function drSettings(PDO $pdo): array {
    $row=$pdo->query('SELECT * FROM disaster_recovery_settings WHERE SettingsID=1')->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Disaster-recovery settings are missing. Run the migration.');
    return $row;
}

function drStorageDirectory(PDO $pdo): string {
    $settings=drSettings($pdo);$configured=trim((string)$settings['SecondaryStoragePath']);
    $env=drEnvironment();$environmentPath=trim((string)($env['BACKUP_SECONDARY_PATH']??getenv('BACKUP_SECONDARY_PATH')?:''));
    $directory=$configured!==''?$configured:($environmentPath!==''?$environmentPath:dirname(__DIR__,3).DIRECTORY_SEPARATOR.'scholarlink_secure_backups');
    if(!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#',$directory))throw new RuntimeException('Secondary backup storage must use an absolute path outside the web application.');
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Cannot create the secondary backup storage directory.');
    $resolved=realpath($directory);$project=realpath(dirname(__DIR__));
    if(!$resolved||!$project)throw new RuntimeException('Cannot resolve the backup storage path.');
    $r=strtolower(rtrim(str_replace('\\','/',$resolved),'/'));$p=strtolower(rtrim(str_replace('\\','/',$project),'/'));
    if($r===$p||str_starts_with($r,$p.'/'))throw new RuntimeException('Secondary backup storage must be outside the ScholarLink web directory.');
    return $resolved;
}

function drBuildSqlDump(PDO $pdo): string {
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$sql="-- ScholarLink encrypted recovery backup\n-- Generated: ".date('c')."\nSET FOREIGN_KEY_CHECKS=0;\n";
    foreach($tables as $table){$safe=str_replace('`','``',$table);$create=$pdo->query("SHOW CREATE TABLE `$safe`")->fetch(PDO::FETCH_NUM);$sql.="DROP TABLE IF EXISTS `$safe`;\n{$create[1]};\n";
        $rows=$pdo->query("SELECT * FROM `$safe`");while($row=$rows->fetch(PDO::FETCH_ASSOC)){$columns=array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',array_keys($row));$values=array_map(fn($v)=>$v===null?'NULL':$pdo->quote($v),array_values($row));$sql.='INSERT INTO `'.$safe.'` ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n";}}
    return $sql."SET FOREIGN_KEY_CHECKS=1;\n";
}

function drEncrypt(string $plain): string {
    $key=drEncryptionKeyInfo()['key'];$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'ScholarLink-DR-v1',16);
    if($cipher===false)throw new RuntimeException('Backup encryption failed.');
    $header=json_encode(['cipher'=>'AES-256-GCM','iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'created'=>date(DATE_ATOM)],JSON_UNESCAPED_SLASHES);
    return DR_BACKUP_MAGIC.$header."\n".$cipher;
}

function drDecrypt(string $package): string {
    if(!str_starts_with($package,DR_BACKUP_MAGIC))throw new RuntimeException('Not a ScholarLink encrypted backup package.');
    $rest=substr($package,strlen(DR_BACKUP_MAGIC));$newline=strpos($rest,"\n");if($newline===false)throw new RuntimeException('Backup header is damaged.');
    $meta=json_decode(substr($rest,0,$newline),true);$cipher=substr($rest,$newline+1);
    if(($meta['cipher']??'')!=='AES-256-GCM')throw new RuntimeException('Unsupported backup encryption format.');
    $plain=openssl_decrypt($cipher,'aes-256-gcm',drEncryptionKeyInfo()['key'],OPENSSL_RAW_DATA,base64_decode($meta['iv']??'',true)?:'',base64_decode($meta['tag']??'',true)?:'','ScholarLink-DR-v1');
    if($plain===false)throw new RuntimeException('Backup authentication failed: wrong key or modified content.');return $plain;
}

function drCreateBackup(PDO $pdo,string $type,?int $actor=null): array {
    if(!in_array($type,['Scheduled','Manual','Pre-Restore'],true))throw new InvalidArgumentException('Invalid backup type.');
    $plain=drBuildSqlDump($pdo);$encrypted=drEncrypt($plain);$directory=drStorageDirectory($pdo);$name='ScholarLink_'.$type.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.slbk';$path=$directory.DIRECTORY_SEPARATOR.$name;
    if(file_put_contents($path,$encrypted,LOCK_EX)!==strlen($encrypted))throw new RuntimeException('Encrypted backup could not be written completely.');
    $plainHash=hash('sha256',$plain);$encryptedHash=hash_file('sha256',$path);$size=filesize($path);
    $pdo->prepare('INSERT INTO backup_records(BackupType,FileName,StoragePath,PlainChecksum,EncryptedChecksum,FileSize,CreatedBy) VALUES(?,?,?,?,?,?,?)')->execute([$type,$name,$path,$plainHash,$encryptedHash,$size,$actor]);$id=(int)$pdo->lastInsertId();
    if($type==='Scheduled')$pdo->exec('UPDATE disaster_recovery_settings SET LastScheduledAt=NOW() WHERE SettingsID=1');
    return ['id'=>$id,'name'=>$name,'path'=>$path,'size'=>$size,'plain_checksum'=>$plainHash,'encrypted_checksum'=>$encryptedHash];
}

function drLoadBackup(PDO $pdo,int $backupId): array {
    $s=$pdo->prepare("SELECT * FROM backup_records WHERE BackupID=? AND Status='Available'");$s->execute([$backupId]);$backup=$s->fetch(PDO::FETCH_ASSOC);if(!$backup)throw new RuntimeException('Available backup record not found.');
    $storage=realpath(drStorageDirectory($pdo));$path=realpath($backup['StoragePath']);if(!$path||!$storage||!str_starts_with(strtolower($path),strtolower($storage).DIRECTORY_SEPARATOR))throw new RuntimeException('Backup file is missing or outside approved secondary storage.');
    if(!hash_equals($backup['EncryptedChecksum'],hash_file('sha256',$path)))throw new RuntimeException('Encrypted backup checksum mismatch.');
    $package=(string)file_get_contents($path);$plain=drDecrypt($package);if(!hash_equals($backup['PlainChecksum'],hash('sha256',$plain)))throw new RuntimeException('Decrypted backup checksum mismatch.');
    return [$backup,$plain,$path];
}

function drSecureDelete(PDO $pdo,string $path): void {
    $storage=realpath(drStorageDirectory($pdo));$resolved=realpath($path);if(!$resolved)return;
    if(!$storage||!str_starts_with(strtolower($resolved),strtolower($storage).DIRECTORY_SEPARATOR))throw new RuntimeException('Refused backup deletion outside approved secondary storage.');
    $size=filesize($resolved);$fh=fopen($resolved,'r+b');if($fh){$chunk=str_repeat("\0",8192);for($left=$size;$left>0;){$written=fwrite($fh,substr($chunk,0,min(8192,$left)));if(!$written)break;$left-=$written;}fflush($fh);fclose($fh);}if(!unlink($resolved))throw new RuntimeException('Expired backup could not be removed.');
}

function drEnforceRetention(PDO $pdo): int {
    $days=(int)drSettings($pdo)['RetentionDays'];$s=$pdo->prepare("SELECT BackupID,StoragePath FROM backup_records WHERE Status='Available' AND CreatedAt<DATE_SUB(NOW(),INTERVAL ? DAY)");$s->execute([$days]);$count=0;
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){drSecureDelete($pdo,$row['StoragePath']);$pdo->prepare("UPDATE backup_records SET Status='Expired' WHERE BackupID=?")->execute([$row['BackupID']]);$count++;}return $count;
}

function drServerPdo(?string $database=null): PDO {
    $c=drDatabaseConfig();$dsn="mysql:host={$c['host']};port={$c['port']}".($database?";dbname=$database":'').';charset=utf8mb4';return new PDO($dsn,$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>true]);
}

function drRunRestorationDrill(PDO $pdo,int $backupId,int $actor): array {
    [$backup,$sql]=drLoadBackup($pdo,$backupId);$target='scholarlink_drill_'.date('YmdHis').'_'.bin2hex(random_bytes(2));$settings=drSettings($pdo);$started=microtime(true);
    $pdo->prepare("INSERT INTO restoration_drills(BackupID,TargetDatabase,ExecutedBy) VALUES(?,?,?)")->execute([$backupId,$target,$actor]);$drillId=(int)$pdo->lastInsertId();$server=drServerPdo();
    try{$server->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");$test=drServerPdo($target);$test->exec($sql);$tables=$test->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$required=['users','scholarship','application','audit_log'];$missing=array_values(array_diff($required,$tables));if($missing)throw new RuntimeException('Missing required tables: '.implode(', ',$missing));
        $rows=0;foreach($tables as $table){$safe=str_replace('`','``',$table);$rows+=(int)$test->query("SELECT COUNT(*) FROM `$safe`")->fetchColumn();}$duration=microtime(true)-$started;$ageQuery=$pdo->prepare('SELECT GREATEST(0,TIMESTAMPDIFF(SECOND,?,NOW())/3600)');$ageQuery->execute([$backup['CreatedAt']]);$age=(float)$ageQuery->fetchColumn();$rpo=$age<=(float)$settings['RPOHours'];$rto=($duration/3600)<=(float)$settings['RTOHours'];$notes=count($tables)." tables and $rows rows restored in an isolated database; required tables verified.";
        $pdo->prepare("UPDATE restoration_drills SET Status='Passed',CompletedAt=NOW(),BackupAgeHours=?,RestoreDurationSeconds=?,TableCount=?,RowCount=?,RPOCompliant=?,RTOCompliant=?,ValidationNotes=? WHERE DrillID=?")->execute([round($age,2),round($duration,3),count($tables),$rows,$rpo?1:0,$rto?1:0,$notes,$drillId]);$pdo->prepare('UPDATE backup_records SET VerifiedAt=NOW() WHERE BackupID=?')->execute([$backupId]);return ['id'=>$drillId,'duration'=>$duration,'tables'=>count($tables),'rows'=>$rows,'rpo'=>$rpo,'rto'=>$rto];
    }catch(Throwable $e){$duration=microtime(true)-$started;$pdo->prepare("UPDATE restoration_drills SET Status='Failed',CompletedAt=NOW(),RestoreDurationSeconds=?,ValidationNotes=? WHERE DrillID=?")->execute([round($duration,3),mb_substr($e->getMessage(),0,2000),$drillId]);throw $e;
    }finally{try{$server->exec("DROP DATABASE IF EXISTS `$target`");}catch(Throwable $ignored){}}
}

function drScheduleDue(PDO $pdo): bool {
    return (bool)$pdo->query('SELECT ScheduleEnabled=1 AND (LastScheduledAt IS NULL OR DATE_ADD(LastScheduledAt,INTERVAL ScheduleIntervalHours HOUR)<=NOW()) FROM disaster_recovery_settings WHERE SettingsID=1')->fetchColumn();
}
