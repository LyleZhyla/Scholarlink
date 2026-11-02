<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/scholarship_config_versions.php';

$app = $pdo->query("SELECT ApplicationID, ScholarshipID FROM application WHERE Status <> 'Draft' ORDER BY ApplicationID LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$app) { fwrite(STDERR, "No submitted application is available for the isolation test.\n"); exit(1); }

$pdo->beginTransaction();
try {
    $applicationId = (int) $app['ApplicationID'];
    $scholarshipId = (int) $app['ScholarshipID'];
    $old = applicationConfigurationSnapshot($pdo, $applicationId);
    $oldJson = json_encode($old['criteria'] ?? []);
    $oldVersion = (int) ($old['_version'] ?? 0);

    $pdo->prepare("INSERT INTO scholarship_criteria (ScholarshipID, CriteriaName) VALUES (?, 'ROLLBACK VERSION ISOLATION TEST')")->execute([$scholarshipId]);
    $newVersionId = currentScholarshipConfigurationVersion($pdo, $scholarshipId, null, 'Automated rollback test');
    $newVersion = $pdo->prepare('SELECT VersionNumber, SnapshotJson FROM scholarship_configuration_versions WHERE ConfigurationVersionID = ?');
    $newVersion->execute([$newVersionId]);
    $new = $newVersion->fetch(PDO::FETCH_ASSOC);
    $oldAgain = applicationConfigurationSnapshot($pdo, $applicationId);

    if ((int) $new['VersionNumber'] <= $oldVersion) throw new RuntimeException('A changed configuration did not create a newer version.');
    if (json_encode($oldAgain['criteria'] ?? []) !== $oldJson) throw new RuntimeException('The submitted application changed after a new configuration version was created.');
    if (!str_contains($new['SnapshotJson'], 'ROLLBACK VERSION ISOLATION TEST')) throw new RuntimeException('The new version did not capture the changed rubric.');
    $pdo->rollBack();
    echo "PASS: version $oldVersion stayed immutable while a newer version was created (all test writes rolled back).\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

