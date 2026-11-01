<?php
// Super Admin approves or rejects students' profile change requests (super_admin/profile_requests.php):
// one request (approve / reject with a reason) or several selected ones at once (approve only)
session_start();
require '../includes/db_connect.php';
require_once '../includes/profile_fields.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin_login.php");
    exit();
}

$back = function (string $kind, string $message) {
    $_SESSION[$kind] = $message;
    header("Location: ../super_admin/profile_requests.php" . (!empty($_POST['return']) ? '?' . preg_replace('/[^a-zA-Z0-9=&_%-]/', '', $_POST['return']) : ''));
    exit();
};

// Decides one request. Returns [true, summary] or [false, why it could not be done].
function decideProfileRequest(PDO $pdo, int $request_id, string $decision, string $note): array {
    $st = $pdo->prepare("SELECT r.*, u.FirstName, u.LastName FROM profile_change_requests r JOIN users u ON u.UserID = r.UserID WHERE r.RequestID = ?");
    $st->execute([$request_id]);
    $request = $st->fetch(PDO::FETCH_ASSOC);
    if (!$request) return [false, 'Request not found.'];
    $student = $request['FirstName'] . ' ' . $request['LastName'];
    if ($request['Status'] !== 'Pending') return [false, "$student's request was already " . strtolower($request['Status']) . '.'];

    $changes = json_decode($request['Changes'], true) ?: [];
    $labels = implode(', ', array_map(fn($f) => PROFILE_APPROVAL_FIELDS[$f][1] ?? $f, array_keys($changes)));
    $user_id = (int) $request['UserID'];

    try {
        $pdo->beginTransaction();
        if ($decision === 'approve') {
            // Check again: e.g. another account may have taken the email meanwhile
            $values = array_map(fn($c) => (string) $c['to'], $changes);
            if ($problem = profileChangeError($pdo, $user_id, $values)) {
                $pdo->rollBack();
                return [false, "$student: $problem"];
            }
            applyProfileChanges($pdo, $user_id, $values);
        }
        $pdo->prepare("UPDATE profile_change_requests SET Status = ?, ReviewNote = ?, ReviewedBy = ?, ReviewedAt = NOW() WHERE RequestID = ?")
            ->execute([$decision === 'approve' ? 'Approved' : 'Rejected', $note !== '' ? mb_substr($note, 0, 500) : null, $_SESSION['user_id'], $request_id]);
        $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, ?, ?, ?)")->execute($decision === 'approve'
            ? [$user_id, 'Profile changes approved ✅', "Your requested changes ($labels) were approved and are now on your profile.", 'success']
            : [$user_id, 'Profile changes not approved', "Your requested changes ($labels) were not approved. Reason: $note", 'danger']);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [false, "$student: could not save the decision."];
    }
    logAudit($pdo, $_SESSION['user_id'], $decision === 'approve' ? 'Profile Change Approved' : 'Profile Change Rejected',
        "$student: $labels" . ($note !== '' ? " · Note: $note" : ''));
    return [true, "$student ($labels)"];
}

$decision = $_POST['decision'] ?? '';
$note = trim((string) ($_POST['note'] ?? ''));

// Several selected requests: approve each one (each is checked on its own)
if ($decision === 'approve_many') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['request_ids'] ?? [])))));
    if (!$ids) $back('error', 'Select at least one request first.');
    $done = 0;
    $problems = [];
    foreach ($ids as $id) {
        [$ok, $text] = decideProfileRequest($pdo, $id, 'approve', '');
        $ok ? $done++ : $problems[] = $text;
    }
    if (!$done) $back('error', 'Nothing was approved. ' . implode(' ', $problems));
    $back('success', "Approved $done request" . ($done === 1 ? '' : 's') . '.' . ($problems ? ' Not approved: ' . implode(' ', $problems) : ''));
}

if (!in_array($decision, ['approve', 'reject'], true)) $back('error', 'Choose approve or reject.');
if ($decision === 'reject' && $note === '') $back('error', 'Please give the student a reason when rejecting.');

[$ok, $text] = decideProfileRequest($pdo, (int) ($_POST['request_id'] ?? 0), $decision, $note);
if (!$ok) $back('error', $decision === 'approve' ? "Can't approve. $text Reject it with this reason instead." : $text);
$back('success', $decision === 'approve' ? "Approved: $text." : "Rejected: $text. The student was told why.");
