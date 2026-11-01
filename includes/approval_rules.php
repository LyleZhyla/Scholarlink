<?php
// ✨ APPROVAL RULES: checked before any application is approved
//   1. Slots: a scholarship cannot have more approved scholars than its NumberOfSlots (no limit when empty).
//   2. Single grant: a student cannot hold two scholarships when either one does not allow dual grants
//      (scholarship.AllowsDual = 'No').
// approvalBlocker() returns the reason it cannot be approved, or null when it can.
// Renewals (scholar_renewals → 'Renewed') re-confirm an existing scholar and are not checked again.

if (!function_exists('approvalBlocker')) {

function approvalBlocker(PDO $pdo, int $application_id): ?string {
    $stmt = $pdo->prepare("
        SELECT a.ApplicationID, a.UserID, a.Status, s.ScholarshipID, s.Name, s.NumberOfSlots, s.AllowsDual,
               CONCAT(u.FirstName, ' ', u.LastName) AS Student
        FROM application a
        JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
        JOIN users u ON u.UserID = a.UserID
        WHERE a.ApplicationID = ?");
    $stmt->execute([$application_id]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app || $app['Status'] === 'Approved') return null;   // nothing to check / already counted

    // 1. Slots
    if ($app['NumberOfSlots'] !== null && (int) $app['NumberOfSlots'] > 0) {
        $count = $pdo->prepare("SELECT COUNT(*) FROM application WHERE ScholarshipID = ? AND Status = 'Approved'");
        $count->execute([$app['ScholarshipID']]);
        $approved = (int) $count->fetchColumn();
        if ($approved >= (int) $app['NumberOfSlots']) {
            return "{$app['Name']} is already full ({$approved} of {$app['NumberOfSlots']} slots taken). Increase its slots or decline another scholar before approving {$app['Student']}.";
        }
    }

    // 2. Single grant
    $held = $pdo->prepare("
        SELECT s.Name, s.AllowsDual
        FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
        WHERE a.UserID = ? AND a.Status = 'Approved' AND a.ApplicationID <> ?");
    $held->execute([$app['UserID'], $application_id]);
    foreach ($held->fetchAll(PDO::FETCH_ASSOC) as $h) {
        if ($app['AllowsDual'] !== 'Yes' || $h['AllowsDual'] !== 'Yes') {
            $which = $app['AllowsDual'] !== 'Yes' ? $app['Name'] : $h['Name'];
            return "{$app['Student']} is already a scholar of {$h['Name']}, and {$which} does not allow holding another scholarship at the same time.";
        }
    }
    return null;
}

// How full a scholarship is, for showing next to the approve buttons: ['approved' => n, 'slots' => n|null]
function scholarshipSlotUse(PDO $pdo, int $scholarship_id): array {
    $stmt = $pdo->prepare("SELECT NumberOfSlots, (SELECT COUNT(*) FROM application WHERE ScholarshipID = s.ScholarshipID AND Status = 'Approved') AS approved FROM scholarship s WHERE ScholarshipID = ?");
    $stmt->execute([$scholarship_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['NumberOfSlots' => null, 'approved' => 0];
    return ['approved' => (int) $r['approved'], 'slots' => $r['NumberOfSlots'] !== null ? (int) $r['NumberOfSlots'] : null];
}

}
