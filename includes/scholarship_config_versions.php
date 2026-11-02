<?php

function configurationVersioningReady(PDO $pdo): bool {
    static $ready;
    if ($ready !== null) return $ready;
    return $ready = (bool) $pdo->query("SHOW TABLES LIKE 'scholarship_configuration_versions'")->fetchColumn();
}

function captureScholarshipConfiguration(PDO $pdo, int $scholarshipId): array {
    $sch = $pdo->prepare('SELECT ScholarshipID, Name, MinimumGWA, YearLevel, GenderRequirement, FormInstructions, FormTemplate FROM scholarship WHERE ScholarshipID = ?');
    $sch->execute([$scholarshipId]);
    $scholarship = $sch->fetch(PDO::FETCH_ASSOC);
    if (!$scholarship) throw new RuntimeException('Scholarship not found.');

    $docs = $pdo->prepare('SELECT RequirementID, DocumentName FROM document_requirement WHERE ScholarshipID = ? AND IsActive = 1 ORDER BY RequirementID');
    $docs->execute([$scholarshipId]);
    $criteria = $pdo->prepare('SELECT CriteriaID, CriteriaName FROM scholarship_criteria WHERE ScholarshipID = ? AND IsActive = 1 ORDER BY CriteriaID');
    $criteria->execute([$scholarshipId]);
    $fields = $pdo->prepare('SELECT FieldID, FieldName, FieldType, HelpText, Placeholder, Options, IsRequired, SortOrder, PDFFieldName, PdfPage, PdfX, PdfY, PdfW, PdfH, PdfFontSize FROM scholarship_custom_fields WHERE ScholarshipID = ? AND IsActive = 1 ORDER BY SortOrder, FieldID');
    $fields->execute([$scholarshipId]);

    $criteriaRows = $criteria->fetchAll(PDO::FETCH_ASSOC);
    return [
        'scholarship' => $scholarship,
        'requirements' => $docs->fetchAll(PDO::FETCH_ASSOC),
        'criteria' => $criteriaRows,
        'scoring_rubric' => $criteriaRows,
        'form_fields' => $fields->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function currentScholarshipConfigurationVersion(PDO $pdo, int $scholarshipId, ?int $createdBy = null, string $summary = 'Initial launched configuration'): int {
    if (!configurationVersioningReady($pdo)) return 0;
    $snapshot = captureScholarshipConfiguration($pdo, $scholarshipId);
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $hash = hash('sha256', $json);
    $latest = $pdo->prepare('SELECT ConfigurationVersionID, SnapshotHash FROM scholarship_configuration_versions WHERE ScholarshipID = ? ORDER BY VersionNumber DESC LIMIT 1');
    $latest->execute([$scholarshipId]);
    $row = $latest->fetch(PDO::FETCH_ASSOC);
    if ($row && hash_equals($row['SnapshotHash'], $hash)) return (int) $row['ConfigurationVersionID'];

    $next = $pdo->prepare('SELECT COALESCE(MAX(VersionNumber),0)+1 FROM scholarship_configuration_versions WHERE ScholarshipID = ? FOR UPDATE');
    $next->execute([$scholarshipId]);
    $version = (int) $next->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO scholarship_configuration_versions (ScholarshipID, VersionNumber, SnapshotHash, SnapshotJson, ChangeSummary, CreatedBy) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([$scholarshipId, $version, $hash, $json, mb_substr($summary, 0, 255), $createdBy ?: null]);
    return (int) $pdo->lastInsertId();
}

function freezeUnversionedApplications(PDO $pdo, int $scholarshipId, ?int $createdBy = null): int {
    $versionId = currentScholarshipConfigurationVersion($pdo, $scholarshipId, $createdBy);
    if ($versionId) {
        $stmt = $pdo->prepare("UPDATE application SET ConfigurationVersionID = ? WHERE ScholarshipID = ? AND ConfigurationVersionID IS NULL AND Status <> 'Draft'");
        $stmt->execute([$versionId, $scholarshipId]);
    }
    return $versionId;
}

function assignApplicationConfigurationVersion(PDO $pdo, int $applicationId, ?int $createdBy = null): int {
    if (!configurationVersioningReady($pdo)) return 0;
    $stmt = $pdo->prepare('SELECT ScholarshipID, ConfigurationVersionID FROM application WHERE ApplicationID = ? FOR UPDATE');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) throw new RuntimeException('Application not found.');
    if ($app['ConfigurationVersionID']) return (int) $app['ConfigurationVersionID'];
    $versionId = currentScholarshipConfigurationVersion($pdo, (int) $app['ScholarshipID'], $createdBy);
    $pdo->prepare('UPDATE application SET ConfigurationVersionID = ? WHERE ApplicationID = ? AND ConfigurationVersionID IS NULL')->execute([$versionId, $applicationId]);
    return $versionId;
}

function applicationConfigurationSnapshot(PDO $pdo, int $applicationId): ?array {
    if (!configurationVersioningReady($pdo)) return null;
    $appStmt = $pdo->prepare('SELECT ScholarshipID, Status, ConfigurationVersionID FROM application WHERE ApplicationID = ?');
    $appStmt->execute([$applicationId]);
    $application = $appStmt->fetch(PDO::FETCH_ASSOC);
    if (!$application) return null;
    if (!$application['ConfigurationVersionID'] && $application['Status'] === 'Draft') {
        $snapshot = captureScholarshipConfiguration($pdo, (int) $application['ScholarshipID']);
        $snapshot['_version'] = null;
        return $snapshot;
    }
    assignApplicationConfigurationVersion($pdo, $applicationId);
    $stmt = $pdo->prepare('SELECT cv.VersionNumber, cv.SnapshotJson, cv.CreatedAt FROM application a JOIN scholarship_configuration_versions cv ON cv.ConfigurationVersionID = a.ConfigurationVersionID WHERE a.ApplicationID = ?');
    $stmt->execute([$applicationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $snapshot = json_decode($row['SnapshotJson'], true, 512, JSON_THROW_ON_ERROR);
    $snapshot['_version'] = (int) $row['VersionNumber'];
    $snapshot['_created_at'] = $row['CreatedAt'];
    return $snapshot;
}

function applicationCriteriaList(PDO $pdo, int $applicationId): array {
    $snapshot = applicationConfigurationSnapshot($pdo, $applicationId);
    return array_values(array_filter(array_map(fn($row) => trim((string) ($row['CriteriaName'] ?? '')), $snapshot['scoring_rubric'] ?? [])));
}

