<?php
// ✨ RENEWAL NOTICE PDF (?renewal_id=N): the scholar's own copy, or an admin's who handles that scholarship
// (the same letter that was attached to the new-semester email, includes/semester.php renewalNoticePdf)
session_start();
require '../includes/db_connect.php';
require_once '../includes/semester.php';
require_once '../includes/application_authority.php';

if (empty($_SESSION['logged_in'])) { http_response_code(401); exit('Please sign in.'); }
$role = $_SESSION['role'];
$uid = (int) $_SESSION['user_id'];
$st = $pdo->prepare("SELECT r.*, a.ScholarshipID, s.Name AS ScholarshipName, u.FirstName, u.LastName FROM scholar_renewals r
    JOIN application a ON a.ApplicationID = r.ApplicationID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID JOIN users u ON u.UserID = r.UserID
    WHERE r.RenewalID = ?");
$st->execute([(int) ($_GET['renewal_id'] ?? 0)]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('Renewal not found.'); }
if ($role === 'Student' ? (int) $r['UserID'] !== $uid : !(($a = getApplicationAuthority($pdo, (int) $r['ApplicationID'])) && canDecideApplication($a, $role, $uid))) {
    http_response_code($role === 'Student' ? 404 : 403);
    exit($role === 'Student' ? 'Renewal not found.' : 'You cannot view this notice.');
}
$term = $pdo->prepare('SELECT * FROM academic_terms WHERE TermID = ?');
$term->execute([$r['TermID']]);
$term = $term->fetch(PDO::FETCH_ASSOC);
$pdf = renewalNoticePdf($pdo, $term, $r);
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . renewalNoticeFileName($term, $r) . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
