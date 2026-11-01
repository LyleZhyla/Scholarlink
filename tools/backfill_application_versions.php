<?php
// Run once after migrations/2026-11-01_application_lifecycle.sql on an existing installation.
// Safe to rerun: only applications without a history record are captured.
require __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/application_lifecycle.php';

$ids = $pdo->query("SELECT a.ApplicationID FROM application a LEFT JOIN application_versions v ON v.ApplicationID = a.ApplicationID WHERE v.ApplicationID IS NULL")
    ->fetchAll(PDO::FETCH_COLUMN);

foreach ($ids as $id) {
    $pdo->beginTransaction();
    snapshotApplicationVersion($pdo, (int) $id, 'Baseline', null, 'Existing application captured when version history was enabled');
    $pdo->commit();
}

echo 'Backfilled ' . count($ids) . " application(s).\n";
