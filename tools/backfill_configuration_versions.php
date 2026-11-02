<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/scholarship_config_versions.php';

$pdo->beginTransaction();
try {
    $scholarships = $pdo->query('SELECT ScholarshipID, CreatedBy FROM scholarship ORDER BY ScholarshipID')->fetchAll(PDO::FETCH_ASSOC);
    $applications = 0;
    foreach ($scholarships as $scholarship) {
        $versionId = freezeUnversionedApplications($pdo, (int) $scholarship['ScholarshipID'], $scholarship['CreatedBy'] ? (int) $scholarship['CreatedBy'] : null);
        $count = $pdo->prepare('SELECT COUNT(*) FROM application WHERE ScholarshipID = ? AND ConfigurationVersionID = ?');
        $count->execute([$scholarship['ScholarshipID'], $versionId]);
        $applications += (int) $count->fetchColumn();
    }
    $pdo->commit();
    echo 'Configuration baselines created for ' . count($scholarships) . " scholarships; $applications submitted applications linked.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

