<?php
require_once __DIR__ . '/approval_rules.php';
require_once __DIR__ . '/application_lifecycle.php';

function refreshWaitlistRanks(PDO $pdo, int $scholarship_id): void {
    $stmt = $pdo->prepare("
        SELECT w.WaitlistID, COALESCE(a.TotalScore, 0) AS RankingScore
        FROM application_waitlist w
        JOIN application a ON a.ApplicationID = w.ApplicationID
        WHERE w.ScholarshipID = ? AND w.Status IN ('Waiting', 'Offered')
        ORDER BY COALESCE(a.TotalScore, 0) DESC, COALESCE(a.GPA, 99.99) ASC, a.DateSubmitted ASC, a.ApplicationID ASC
        FOR UPDATE
    ");
    $stmt->execute([$scholarship_id]);
    $update = $pdo->prepare("UPDATE application_waitlist SET RankPosition = ?, RankingScore = ? WHERE WaitlistID = ?");
    $rank = 1;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $update->execute([$rank++, $row['RankingScore'], $row['WaitlistID']]);
    }
}

function scholarshipVacancies(PDO $pdo, int $scholarship_id, bool $reserve_offers = false): array {
    $stmt = $pdo->prepare("
        SELECT s.NumberOfSlots,
               (SELECT COUNT(*) FROM application a WHERE a.ScholarshipID = s.ScholarshipID AND a.Status = 'Approved') AS ApprovedCount,
               (SELECT COUNT(*) FROM application_waitlist w WHERE w.ScholarshipID = s.ScholarshipID AND w.Status = 'Offered') AS OfferedCount
        FROM scholarship s WHERE s.ScholarshipID = ?
    ");
    $stmt->execute([$scholarship_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['NumberOfSlots' => 0, 'ApprovedCount' => 0, 'OfferedCount' => 0];
    $slots = max(0, (int) $row['NumberOfSlots']);
    $approved = (int) $row['ApprovedCount'];
    $offered = (int) $row['OfferedCount'];
    $used = $approved + ($reserve_offers ? $offered : 0);
    return ['slots' => $slots, 'approved' => $approved, 'offered' => $offered, 'vacancies' => max(0, $slots - $used)];
}

function placeApplicationOnWaitlist(PDO $pdo, int $application_id, int $admin_id): array {
    $owns_transaction = !$pdo->inTransaction();
    if ($owns_transaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT a.ApplicationID, a.ScholarshipID, a.UserID, a.Status, COALESCE(a.TotalScore, 0) AS TotalScore,
                   s.Name AS ScholarshipName
            FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
            WHERE a.ApplicationID = ? FOR UPDATE
        ");
        $stmt->execute([$application_id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app || !in_array($app['Status'], ['Shortlisted', 'Scored', 'Waitlisted'], true)) {
            throw new RuntimeException('Only a qualified or shortlisted applicant can be placed on the waitlist.');
        }
        $capacity = scholarshipVacancies($pdo, (int) $app['ScholarshipID']);
        if ($capacity['slots'] < 1 || $capacity['approved'] < $capacity['slots']) {
            throw new RuntimeException('A slot is available; this qualified applicant can be approved directly.');
        }
        $pdo->prepare("
            INSERT INTO application_waitlist (ApplicationID, ScholarshipID, RankPosition, RankingScore, Status, PlacedBy)
            VALUES (?, ?, 1, ?, 'Waiting', ?)
            ON DUPLICATE KEY UPDATE Status = 'Waiting', PlacedBy = VALUES(PlacedBy), PlacedAt = NOW(), OfferedBy = NULL, OfferedAt = NULL
        ")->execute([$application_id, $app['ScholarshipID'], $app['TotalScore'], $admin_id]);
        $pdo->prepare("UPDATE application SET Status = 'Waitlisted', LastSavedAt = NOW() WHERE ApplicationID = ?")->execute([$application_id]);
        refreshWaitlistRanks($pdo, (int) $app['ScholarshipID']);
        $rank_stmt = $pdo->prepare("SELECT RankPosition FROM application_waitlist WHERE ApplicationID = ?");
        $rank_stmt->execute([$application_id]);
        $rank = (int) $rank_stmt->fetchColumn();
        snapshotApplicationVersion($pdo, $application_id, 'Waitlisted', $admin_id, "Qualified applicant placed at waitlist rank #$rank because all slots are filled");
        $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, 'Placed on Ranked Waitlist', ?, 'info')")
            ->execute([$app['UserID'], "You qualified for {$app['ScholarshipName']}, but all available slots are filled. You are currently ranked #$rank on the waitlist."]);
        logAudit($pdo, $admin_id, 'Applicant Waitlisted', "Placed Application #$application_id at rank #$rank for {$app['ScholarshipName']}");
        if ($owns_transaction) $pdo->commit();
        return ['rank' => $rank, 'scholarship_id' => (int) $app['ScholarshipID'], 'scholarship_name' => $app['ScholarshipName']];
    } catch (Throwable $e) {
        if ($owns_transaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Returns placement details when capacity is full, null when direct approval is allowed,
// and throws for non-capacity blockers such as the dual-scholarship policy.
function waitlistIfScholarshipFull(PDO $pdo, int $application_id, int $admin_id): ?array {
    $issue = approvalBlockerDetails($pdo, $application_id);
    if (!$issue) return null;
    if (($issue['code'] ?? '') !== 'slots_full') throw new RuntimeException($issue['message']);
    return placeApplicationOnWaitlist($pdo, $application_id, $admin_id);
}

function waitlistStatusClass(string $status): string {
    return match ($status) {
        'Offered' => 'bg-amber-50 text-amber-700 border-amber-200',
        'Promoted' => 'bg-green-50 text-green-700 border-green-200',
        'Removed' => 'bg-slate-100 text-slate-600 border-slate-200',
        default => 'bg-blue-50 text-blue-700 border-blue-200',
    };
}
