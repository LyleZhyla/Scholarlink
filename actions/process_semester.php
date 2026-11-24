<?php
// ✨ SEMESTER ACTIONS (the Semester page, includes/semester_admin_view.php)
//   start_term          OSSD / Super Admin: declare the new semester (includes/semester.php startNewSemester)
//   add_requirement     a renewal requirement for the active semester (OSSD / Super Admin) or for one scholarship
//                       (whoever manages that scholarship, a provider included)
//   remove_requirement  stop asking for one (earlier submissions stay)
//   send_emails         the next few new-semester emails (JSON, called again by the page until none are left)
//   review_cor          OSSD / Super Admin: accept a COR the AI could not check, or reject it (the student uploads again)
session_start();
require '../includes/db_connect.php';
require_once '../includes/semester.php';
require_once '../includes/application_authority.php';
require_once '../includes/approval_workflow.php';

$role = $_SESSION['role'] ?? '';
$uid = (int) ($_SESSION['user_id'] ?? 0);
$action = $_POST['action'] ?? '';
$is_json = $action === 'send_emails';
$page = ['Internal_Admin' => '../internal_admin/semester.php', 'Super_Admin' => '../super_admin/semester.php', 'External_Admin' => '../external_admin/renewal_requirements.php'][$role] ?? '../admin_login.php';
$done = function (string $kind, string $msg) use ($page) { $_SESSION[$kind] = $msg; header("Location: $page"); exit(); };

if (empty($_SESSION['logged_in']) || !in_array($role, ['Internal_Admin', 'Super_Admin', 'External_Admin'], true)) {
    http_response_code(403);
    exit($is_json ? json_encode(['success' => false, 'error' => 'Unauthorized.']) : 'Unauthorized.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: $page"); exit(); }
$school_wide = in_array($role, ['Internal_Admin', 'Super_Admin'], true);   // the OSSD and the Super Admin run semesters

try {
    if ($action === 'start_term') {
        if (!$school_wide) throw new RuntimeException('Only the OSSD or the Super Admin can start a semester.');
        $extras = [];
        foreach ((array) ($_POST['req_name'] ?? []) as $i => $n) $extras[] = ['name' => $n, 'description' => $_POST['req_description'][$i] ?? ''];
        $r = startNewSemester($pdo, $uid, (string) ($_POST['semester'] ?? ''), trim((string) ($_POST['school_year'] ?? '')), ($_POST['deadline'] ?? '') ?: null, $extras);
        $_SESSION['semester_send'] = $r['term_id'];   // the page starts sending the emails
        $done('success', "{$r['name']} has started. Every student must upload their COR when they next sign in; {$r['scholars']} scholar(s) must renew, and their emails are being sent.");
    }

    if ($action === 'add_requirement' || $action === 'remove_requirement') {
        $sid = (int) ($_POST['scholarship_id'] ?? 0);
        if ($sid > 0) {
            if (!canSetRenewalRequirements($pdo, $sid, $role, $uid)) throw new RuntimeException('You can only set requirements for scholarships you manage.');
        } elseif (!$school_wide) throw new RuntimeException('Only the OSSD or the Super Admin can set the semester\'s requirements.');
        if ($action === 'add_requirement') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Give the requirement a name (up to 150 characters).');
            $term = activeTerm($pdo);
            if ($sid <= 0 && !$term) throw new RuntimeException('Start a semester first.');
            $pdo->prepare('INSERT INTO renewal_requirements (TermID, ScholarshipID, Name, Description, CreatedBy) VALUES (?, ?, ?, ?, ?)')
                ->execute([$sid > 0 ? null : $term['TermID'], $sid > 0 ? $sid : null, $name, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500) ?: null, $uid]);
            logAudit($pdo, $uid, 'Renewal Requirement Added', ($sid > 0 ? "Scholarship #$sid" : 'This semester') . ": $name");
            $done('success', "“{$name}” was added. Scholars who have not submitted yet will be asked for it.");
        }
        $rid = (int) ($_POST['requirement_id'] ?? 0);
        $q = $pdo->prepare('SELECT * FROM renewal_requirements WHERE RequirementID = ?');
        $q->execute([$rid]);
        $req = $q->fetch(PDO::FETCH_ASSOC);
        if (!$req || (int) ($req['ScholarshipID'] ?? 0) !== max(0, $sid)) throw new RuntimeException('Requirement not found.');
        $pdo->prepare('UPDATE renewal_requirements SET IsActive = 0 WHERE RequirementID = ?')->execute([$rid]);
        logAudit($pdo, $uid, 'Renewal Requirement Removed', ($sid > 0 ? "Scholarship #$sid" : 'This semester') . ": {$req['Name']}");
        $done('success', "“{$req['Name']}” is no longer asked for.");
    }

    if ($action === 'send_emails') {
        header('Content-Type: application/json');
        if (!$school_wide) throw new RuntimeException('Not allowed.');
        set_time_limit(120);
        $term = activeTerm($pdo);
        if (!$term) throw new RuntimeException('No semester is open.');
        echo json_encode(['success' => true] + sendSemesterEmails($pdo, (int) $term['TermID'], 6));
        exit();
    }

    if ($action === 'review_cor') {
        if (!$school_wide) throw new RuntimeException('Only the OSSD or the Super Admin can review CORs.');
        $verdict = $_POST['verdict'] ?? '';
        if (!in_array($verdict, ['Verified', 'Rejected'], true)) throw new RuntimeException('Choose accept or reject.');
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
        if ($verdict === 'Rejected' && $note === '') throw new RuntimeException('Say why the COR is rejected (the student will see it).');
        $st = $pdo->prepare('SELECT c.*, u.FirstName, u.LastName FROM student_term_cor c JOIN users u ON u.UserID = c.UserID WHERE c.CorID = ?');
        $st->execute([(int) ($_POST['cor_id'] ?? 0)]);
        $cor = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cor) throw new RuntimeException('COR not found.');
        $pdo->prepare('UPDATE student_term_cor SET AIStatus = ?, AIRemarks = IF(? = \'\', AIRemarks, ?), ReviewedBy = ?, ReviewedAt = NOW() WHERE CorID = ?')
            ->execute([$verdict, $note, $note, $uid, $cor['CorID']]);
        if ($verdict === 'Rejected') {
            $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, 'Upload your COR again', ?, 'warning')")
                ->execute([$cor['UserID'], "Your COR could not be accepted: $note. Please upload it again: ScholarLink asks for it on your next page."]);
        }
        logAudit($pdo, $uid, 'Semester COR Reviewed', "{$cor['FirstName']} {$cor['LastName']}'s COR: $verdict" . ($note ? " ($note)" : ''));
        $done('success', "{$cor['FirstName']} {$cor['LastName']}'s COR was " . ($verdict === 'Verified' ? 'accepted.' : 'rejected: they will be asked to upload it again.'));
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    if ($is_json) { http_response_code(400); echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit(); }
    $done('error', $e->getMessage());
}
