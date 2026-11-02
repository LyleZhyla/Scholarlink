<?php
require __DIR__ . '/../includes/db_connect.php';

$failures=[];
function securityCheck(bool $condition,string $label):void{global $failures;echo($condition?'PASS':'FAIL').": $label\n";if(!$condition)$failures[]=$label;}

securityCheck(isTwoFactorRequired($pdo,'Super_Admin'),'MFA is mandatory for Super Admin');
securityCheck(isTwoFactorRequired($pdo,'Internal_Admin'),'MFA is mandatory for Internal Admin');
securityCheck(isTwoFactorRequired($pdo,'External_Admin'),'MFA is mandatory for scholarship providers');
securityCheck(!isTwoFactorRequired($pdo,'Student'),'student accounts are outside the privileged MFA policy');
securityCheck(absoluteSessionTimeoutSeconds($pdo)===28800,'absolute session limit is eight hours');

$pdo->beginTransaction();
try{
    $_SERVER['REMOTE_ADDR']='127.0.0.77';
    $identifier='security-test-'.bin2hex(random_bytes(4));
    for($i=1;$i<=5;$i++)recordLoginFailure($pdo,$identifier,null);
    securityCheck(loginThrottleStatus($pdo,$identifier,null)>0,'five failed attempts activate persistent rate limiting');

    $user=$pdo->query("SELECT * FROM users WHERE Role='Student' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $known='known-security-test-'.bin2hex(random_bytes(3));
    for($i=1;$i<=5;$i++)recordLoginFailure($pdo,$known,(int)$user['UserID']);
    $state=$pdo->prepare('SELECT FailedLoginCount,LockedUntil FROM users WHERE UserID=?');$state->execute([$user['UserID']]);$locked=$state->fetch(PDO::FETCH_ASSOC);
    securityCheck((int)$locked['FailedLoginCount']===5&&strtotime($locked['LockedUntil'])>time(),'known account is locked after repeated failures');
    clearLoginFailures($pdo,$known,(int)$user['UserID']);$state->execute([$user['UserID']]);$cleared=$state->fetch(PDO::FETCH_ASSOC);
    securityCheck((int)$cleared['FailedLoginCount']===0&&$cleared['LockedUntil']===null,'successful authentication clears account lockout state');

    $recovery='recovery-test-'.bin2hex(random_bytes(4)).'@example.invalid';
    securityCheck(recoveryRequestAllowed($pdo,$recovery),'first recovery request is allowed');
    securityCheck(recoveryRequestAllowed($pdo,$recovery),'second recovery request is allowed');
    securityCheck(recoveryRequestAllowed($pdo,$recovery),'third recovery request is allowed');
    securityCheck(!recoveryRequestAllowed($pdo,$recovery),'fourth recovery request is throttled');

    $provider=['Role'=>'External_Admin','ProviderAccessStatus'=>'Active','ProviderAccessUntil'=>date('Y-m-d',strtotime('+1 day'))];
    securityCheck(providerAccessMessage($provider)===null,'active provider with valid review date may sign in');
    $provider['ProviderAccessUntil']=date('Y-m-d',strtotime('-1 day'));
    securityCheck(str_contains((string)providerAccessMessage($provider),'expired'),'expired provider access is refused');
    $provider['ProviderAccessStatus']='Revoked';
    securityCheck(str_contains((string)providerAccessMessage($provider),'revoked'),'revoked provider access is refused');
}finally{$pdo->rollBack();}

if($failures){echo "\n".count($failures)." account security test(s) failed.\n";exit(1);}echo "\nAll account security tests passed.\n";
