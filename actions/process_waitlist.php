<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/waitlist.php';
require_once '../includes/application_authority.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) {
    header('Location: ../admin_login.php');
    exit();
}

$role = $_SESSION['role'];
$admin_id = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$back = match ($role) {
    'Internal_Admin' => '../internal_admin/waitlist.php',
    'External_Admin' => '../external_admin/waitlist.php',
    default => '../super_admin/waitlist.php',
};

try {
    $waitlist_id = (int) ($_POST['waitlist_id'] ?? 0);
    $application_id = (int) ($_POST['application_id'] ?? 0);
    if ($waitlist_id) {
        $lookup = $pdo->prepare("SELECT ApplicationID FROM application_waitlist WHERE WaitlistID = ?");
        $lookup->execute([$waitlist_id]);
        $application_id = (int) $lookup->fetchColumn();
    }
    if (!$application_id) throw new RuntimeException('Waitlist application not found.');
    $authority = getApplicationAuthority($pdo, $application_id);
    if (!$authority || !canDecideApplication($authority, $role, $admin_id)) {
        throw new RuntimeException($authority ? "Only {$authority['DecidedBy']} can manage this scholarship waitlist." : 'Application not found.');
    }

    $pdo->beginTransaction();
    if ($action === 'offer') {
        $stmt = $pdo->prepare("SELECT w.*, a.UserID, s.Name FROM application_waitlist w JOIN application a ON a.ApplicationID = w.ApplicationID JOIN scholarship s ON s.ScholarshipID = w.ScholarshipID WHERE w.WaitlistID = ? FOR UPDATE");
        $stmt->execute([$waitlist_id]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$entry || $entry['Status'] !== 'Waiting') throw new RuntimeException('Only a waiting applicant can receive a slot offer.');
        refreshWaitlistRanks($pdo, (int) $entry['ScholarshipID']);
        $next = $pdo->prepare("SELECT WaitlistID FROM application_waitlist WHERE ScholarshipID = ? AND Status = 'Waiting' ORDER BY RankPosition LIMIT 1");
        $next->execute([$entry['ScholarshipID']]);
        if ((int) $next->fetchColumn() !== $waitlist_id) throw new RuntimeException('The vacant slot must be offered to the next ranked qualified applicant.');
        $capacity = scholarshipVacancies($pdo, (int) $entry['ScholarshipID'], true);
        if ($capacity['slots'] < 1 || $capacity['vacancies'] < 1) throw new RuntimeException('There is no unreserved vacant slot to offer.');
        $pdo->prepare("UPDATE application_waitlist SET Status = 'Offered', OfferedBy = ?, OfferedAt = NOW() WHERE WaitlistID = ?")->execute([$admin_id, $waitlist_id]);
        $pdo->prepare("UPDATE application SET Status = 'Waitlist Offered', LastSavedAt = NOW() WHERE ApplicationID = ?")->execute([$entry['ApplicationID']]);
        snapshotApplicationVersion($pdo, (int) $entry['ApplicationID'], 'Waitlist Slot Offered', $admin_id, 'Vacant slot offered by authorized administrator');
        $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, 'Waitlist Slot Offer', ?, 'success')")
            ->execute([$entry['UserID'], "A vacant slot for {$entry['Name']} has been offered to you as the next ranked qualified applicant. Final promotion remains subject to administrator approval."]);
        logAudit($pdo, $admin_id, 'Waitlist Slot Offered', "Offered a vacant slot to Application #{$entry['ApplicationID']} for {$entry['Name']}");
        $_SESSION['success'] = 'The vacant slot was offered to the next ranked applicant.';
    } elseif ($action === 'promote') {
        $stmt = $pdo->prepare("SELECT w.*, a.UserID, s.Name FROM application_waitlist w JOIN application a ON a.ApplicationID = w.ApplicationID JOIN scholarship s ON s.ScholarshipID = w.ScholarshipID WHERE w.WaitlistID = ? FOR UPDATE");
        $stmt->execute([$waitlist_id]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$entry || $entry['Status'] !== 'Offered') throw new RuntimeException('This applicant does not have an active slot offer.');
        $issue = approvalBlockerDetails($pdo, (int) $entry['ApplicationID']);
        if ($issue) throw new RuntimeException($issue['message']);
        $pdo->prepare("UPDATE application SET Status = 'Approved', LastSavedAt = NOW() WHERE ApplicationID = ?")->execute([$entry['ApplicationID']]);
        $pdo->prepare("UPDATE application_waitlist SET Status = 'Promoted', PromotedBy = ?, PromotedAt = NOW() WHERE WaitlistID = ?")->execute([$admin_id, $waitlist_id]);
        refreshWaitlistRanks($pdo, (int) $entry['ScholarshipID']);
        snapshotApplicationVersion($pdo, (int) $entry['ApplicationID'], 'Waitlist Promotion Approved', $admin_id, 'Administrator approved promotion into a vacant slot');
        logAudit($pdo, $admin_id, 'Waitlist Promotion Approved', "Promoted Application #{$entry['ApplicationID']} into a vacant slot for {$entry['Name']}");
        $_SESSION['success'] = 'Waitlist promotion approved. The applicant is now an official scholar.';
    } elseif ($action === 'vacate') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 10) throw new RuntimeException('Provide a clear vacancy reason of at least 10 characters.');
        $stmt = $pdo->prepare("SELECT a.ApplicationID, a.ScholarshipID, a.UserID, a.Status, s.Name FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.ApplicationID = ? FOR UPDATE");
        $stmt->execute([$application_id]);
        $holder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$holder || $holder['Status'] !== 'Approved') throw new RuntimeException('Only an approved scholar can vacate a slot.');
        $pdo->prepare("UPDATE application SET Status = 'Slot Vacated', LastSavedAt = NOW() WHERE ApplicationID = ?")->execute([$application_id]);
        snapshotApplicationVersion($pdo, $application_id, 'Scholarship Slot Vacated', $admin_id, $reason);
        $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, 'Scholarship Slot Vacated', ?, 'info')")
            ->execute([$holder['UserID'], "Your active slot for {$holder['Name']} was vacated by the administrator. Reason: $reason"]);
        logAudit($pdo, $admin_id, 'Scholarship Slot Vacated', "Vacated Application #$application_id. Reason: $reason");
        $_SESSION['success'] = 'The scholarship slot is now vacant and may be offered to the next ranked applicant.';
    } else {
        throw new RuntimeException('Invalid waitlist action.');
    }
    $pdo->commit();

    if ($action === 'promote') {
        try {
            require_once '../includes/award_notice.php';
            notifyAwardee($pdo, $application_id);
        } catch (Throwable $notice_error) {
            error_log('Waitlist award notice failed: ' . $notice_error->getMessage());
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

header("Location: $back");
exit();
