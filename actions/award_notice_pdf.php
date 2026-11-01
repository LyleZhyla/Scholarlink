<?php
// ✨ APPROVAL NOTICE PDF
//   ?application_id=N        → that student's own copy (the student, or an admin who decides that scholarship)
//   ?scholarship_id=N&preview → the admins' preview, with a sample student
session_start();
require '../includes/db_connect.php';
require_once '../includes/application_authority.php';
require_once '../includes/award_notice.php';

$fail = function (int $code, string $msg) {
    http_response_code($code);
    exit($msg);
};
if (empty($_SESSION['logged_in'])) $fail(401, 'Please sign in.');
$role = $_SESSION['role'];
$uid = (int) $_SESSION['user_id'];
$inline = ['Content-Disposition' => 'inline'];

if (isset($_GET['application_id'])) {
    $app_id = (int) $_GET['application_id'];
    if ($role === 'Student') {
        $own = $pdo->prepare("SELECT 1 FROM application WHERE ApplicationID = ? AND UserID = ?");
        $own->execute([$app_id, $uid]);
        if (!$own->fetchColumn()) $fail(404, 'Application not found.');
    } else {
        $authority = getApplicationAuthority($pdo, $app_id);
        if (!$authority || !canDecideApplication($authority, $role, $uid)) $fail(403, 'You cannot view this notice.');
    }
    $copy = awardNoticeForApplication($pdo, $app_id);
    if (!$copy) $fail(404, 'The Approval Notice is available once the application is approved.');
    $pdf = $copy['pdf'];
    $filename = $copy['filename'];
} else {
    $sid = (int) ($_GET['scholarship_id'] ?? 0);
    $authority = getScholarshipAuthority($pdo, $sid);
    if ($role === 'Student' || !$authority || !canDecideApplication($authority, $role, $uid)) $fail(403, 'You cannot view this notice.');
    $pdf = awardNoticePreviewPdf($pdo, $sid);
    $filename = 'Preview_' . awardNoticeFileName(awardScholarship($pdo, $sid));
}

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
