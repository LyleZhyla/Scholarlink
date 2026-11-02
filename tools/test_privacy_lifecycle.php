<?php
require __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/privacy_lifecycle.php';

$failures = [];
function checkPrivacy(bool $condition, string $label): void {
    global $failures;
    echo ($condition ? "PASS" : "FAIL") . ": $label\n";
    if (!$condition) $failures[] = $label;
}

$policies = privacyPolicyMap($pdo);
checkPrivacy(count($policies) === 6, 'six lifecycle policies are configured');
checkPrivacy(($policies['backups']['RetentionDays'] ?? null) == 30, 'backup retention is policy-driven');
checkPrivacy(($policies['scholar_records']['DisposalMethod'] ?? null) === 'Review Only', 'scholar records require records-officer review');

$actor = (int)$pdo->query("SELECT UserID FROM users WHERE Role='Super_Admin' LIMIT 1")->fetchColumn();
checkPrivacy($actor > 0, 'authorized Super Admin test actor exists');

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO audit_log(UserID,ActionPerformed,ActionDate,Description,IPAddress) VALUES(?, 'Privacy Lifecycle Test', DATE_SUB(NOW(),INTERVAL 8 YEAR), 'Synthetic old audit record', '127.0.0.1')")->execute([$actor]);
    $auditId = (int)$pdo->lastInsertId();
    generatePrivacyRetentionCases($pdo);
    $case = $pdo->prepare("SELECT CaseID FROM privacy_retention_cases WHERE PolicyKey='audit_logs' AND RecordType='AuditLog' AND RecordID=?");
    $case->execute([(string)$auditId]);
    $caseId = (int)$case->fetchColumn();
    checkPrivacy($caseId > 0, 'retention scan identifies an expired audit record');

    $pdo->prepare("UPDATE privacy_retention_cases SET Status='Approved',ApprovedBy=?,ApprovedAt=NOW(),ApprovalReason='Automated lifecycle verification',LegalHold=1,LegalHoldReason='Automated hold verification' WHERE CaseID=?")->execute([$actor,$caseId]);
    $blocked = false;
    try { executePrivacyRetentionCase($pdo,$caseId,$actor); } catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(),'legal hold'); }
    checkPrivacy($blocked, 'legal hold blocks disposition execution');

    $pdo->prepare("UPDATE privacy_retention_cases SET LegalHold=0,LegalHoldReason=NULL WHERE CaseID=?")->execute([$caseId]);
    $evidence = executePrivacyRetentionCase($pdo,$caseId,$actor);
    $exists = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE AuditID=?');$exists->execute([$auditId]);
    checkPrivacy((int)$exists->fetchColumn() === 0, 'approved audit disposition removes the target record');
    checkPrivacy(str_contains($evidence,'securely removed'), 'disposition evidence is returned');

    $student = (int)$pdo->query("SELECT UserID FROM users WHERE Role='Student' LIMIT 1")->fetchColumn();
    $scholarship = (int)$pdo->query('SELECT ScholarshipID FROM scholarship LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO application(ScholarshipID,UserID,Status,DecisionAt,GPA,YearLevel) VALUES(?,?,'Rejected',NOW(),1.50,'4th Year')")->execute([$scholarship,$student]);
    $applicationId = (int)$pdo->lastInsertId();
    $anonymized = anonymizePrivacyApplication($pdo,$applicationId);
    $applicationStatus = $pdo->prepare('SELECT Status FROM application WHERE ApplicationID=?');$applicationStatus->execute([$applicationId]);
    checkPrivacy($applicationStatus->fetchColumn() === 'Anonymized' && $anonymized['user_id'] === $student, 'application anonymization clears the transactional application record');
} finally { $pdo->rollBack(); }

$testDir = __DIR__ . '/../uploads/privacy_lifecycle_test';
if (!is_dir($testDir)) mkdir($testDir, 0777, true);
$testFile = $testDir . '/secure-disposal-test.tmp';
file_put_contents($testFile, str_repeat('sensitive-test-data', 64));
checkPrivacy(secureDisposeFile($testFile) && !file_exists($testFile), 'secure disposal overwrites and removes an allowed upload');
@rmdir($testDir);

if ($failures) { echo "\n" . count($failures) . " privacy lifecycle test(s) failed.\n"; exit(1); }
echo "\nAll privacy lifecycle tests passed.\n";
