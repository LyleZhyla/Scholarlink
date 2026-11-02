<?php
require __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/application_lifecycle.php';

$app = $pdo->query("SELECT a.ApplicationID,a.UserID,a.ScholarshipID,dr.RequirementID FROM application a JOIN document_requirement dr ON dr.ScholarshipID=a.ScholarshipID ORDER BY a.ApplicationID DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$app) { fwrite(STDERR, "No application fixture is available.\n"); exit(1); }

$vaults = $pdo->prepare('SELECT VaultID,FilePath,OriginalFileName FROM user_vault WHERE UserID=? ORDER BY VaultID');
$vaults->execute([$app['UserID']]);
$vault = null;
foreach ($vaults->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
    try {
        $path = resolveProtectedDocumentPath($candidate['FilePath']);
        inspectDocumentFile($path, $candidate['OriginalFileName'] ?: basename($candidate['FilePath']));
        $vault = $candidate;
        break;
    } catch (Throwable $ignored) {}
}
if (!$vault) { fwrite(STDERR, "No clean owned vault fixture is available.\n"); exit(1); }

$created_path = null;
try {
    $pdo->beginTransaction();
    $_POST['vault_files'] = [(int)$app['RequirementID'] => 2147483647];
    try {
        saveRequestedApplicationDocuments($pdo,(int)$app['ApplicationID'],(int)$app['ScholarshipID'],(int)$app['UserID']);
        throw new RuntimeException('Unowned vault ID was accepted.');
    } catch (RuntimeException $expected) {
        if (!str_contains($expected->getMessage(), 'not owned')) throw $expected;
    }
    $pdo->rollBack();

    $pdo->beginTransaction();
    $_POST['vault_files'] = [(int)$app['RequirementID'] => (int)$vault['VaultID']];
    saveRequestedApplicationDocuments($pdo,(int)$app['ApplicationID'],(int)$app['ScholarshipID'],(int)$app['UserID']);
    $check = $pdo->prepare('SELECT FilePath,MimeType,FileSize,FileChecksum,MalwareScanStatus,ScannerEngine,ScannedAt FROM submitted_document WHERE ApplicationID=? AND RequirementID=?');
    $check->execute([$app['ApplicationID'],$app['RequirementID']]);
    $saved = $check->fetch(PDO::FETCH_ASSOC);
    if (!$saved || strlen((string)$saved['FileChecksum']) !== 64 || $saved['MalwareScanStatus'] !== 'Clean' || !$saved['MimeType'] || !$saved['FileSize'] || !$saved['ScannedAt']) {
        throw new RuntimeException('Secure metadata was not persisted correctly.');
    }
    $created_path = resolveProtectedDocumentPath($saved['FilePath']);
    $pdo->rollBack();
    if (is_file($created_path)) unlink($created_path);
    echo "Secure upload integration passed: vault ownership, reinspection, malware status, metadata, checksum, and rollback cleanup.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($created_path && is_file($created_path)) unlink($created_path);
    fwrite(STDERR, "Secure upload integration FAILED: {$e->getMessage()}\n");
    exit(1);
}
