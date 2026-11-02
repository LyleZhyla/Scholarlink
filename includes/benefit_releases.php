<?php

function benefitReceiptUrl(string $path): string {
    $relative = preg_replace('#^(?:\.\./)?uploads/#', '', str_replace('\\', '/', $path));
    return '../uploads/serve.php?f=' . rawurlencode($relative);
}

function benefitAccountSummary(PDO $pdo, int $application_id): ?array {
    $stmt = $pdo->prepare("
        SELECT ba.*, COALESCE(SUM(br.Amount), 0) AS ReleasedAmount,
               GREATEST(ba.TotalEntitlement - COALESCE(SUM(br.Amount), 0), 0) AS RemainingBalance
        FROM scholar_benefit_accounts ba
        LEFT JOIN benefit_releases br ON br.BenefitAccountID = ba.BenefitAccountID
        WHERE ba.ApplicationID = ? GROUP BY ba.BenefitAccountID
    ");
    $stmt->execute([$application_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ensureBenefitAccount(PDO $pdo, int $application_id, int $admin_id): array {
    $stmt = $pdo->prepare("
        SELECT a.ApplicationID, a.Status, s.AwardAmount, COALESCE(NULLIF(s.ReleaseFrequency, ''), 'Per Semester') AS ReleaseFrequency
        FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
        WHERE a.ApplicationID = ? FOR UPDATE
    ");
    $stmt->execute([$application_id]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$application) throw new RuntimeException('Scholar application not found.');
    $pdo->prepare("
        INSERT IGNORE INTO scholar_benefit_accounts (ApplicationID, TotalEntitlement, ReleaseFrequency, SetBy)
        VALUES (?, ?, ?, ?)
    ")->execute([$application_id, max(0, (float) $application['AwardAmount']), $application['ReleaseFrequency'], $admin_id]);
    return $application;
}
