<?php
// ✨ UPLOADED FILES GATEKEEPER
// uploads/.htaccess sends every request under uploads/ here, so files can't be opened by guessing a link.
// A file is served only to someone signed in who may see it:
//   - the student it belongs to
//   - the OSSD (Internal Admin) and the Super Admin
//   - a provider (External Admin), for students who applied to one of their scholarships
session_start();
require __DIR__ . '/../includes/db_connect.php';

function deny(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
}

if (empty($_SESSION['logged_in'])) deny(401, 'Please sign in to view this file.');
$role = $_SESSION['role'] ?? '';
$me = (int) ($_SESSION['user_id'] ?? 0);

// The requested file must really be inside uploads/ (no "../" tricks), and not this script or .htaccess
$base = realpath(__DIR__);
$rel = str_replace('\\', '/', (string) ($_GET['f'] ?? ''));
$path = realpath(__DIR__ . '/' . $rel);
if ($path === false || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) deny(404, 'File not found.');
$rel = str_replace('\\', '/', substr($path, strlen($base) + 1));
if ($rel === 'serve.php' || basename($rel)[0] === '.') deny(404, 'File not found.');

// Who the file belongs to: the student (owner) and, for application files, the scholarship
$stored = ['../uploads/' . $rel, 'uploads/' . $rel];
$owner = null;
$scholarship_ids = [];
$lookups = [
    "SELECT a.UserID, a.ScholarshipID FROM submitted_document d JOIN application a ON a.ApplicationID = d.ApplicationID WHERE d.FilePath IN (?, ?)",
    "SELECT ap.ApplicantID, a.ScholarshipID FROM appeal_documents d JOIN application_appeals ap ON ap.AppealID = d.AppealID JOIN application a ON a.ApplicationID = ap.ApplicationID WHERE d.FilePath IN (?, ?)",
    "SELECT a.UserID, a.ScholarshipID FROM benefit_releases r JOIN application a ON a.ApplicationID = r.ApplicationID WHERE r.AcknowledgementReceipt IN (?, ?)",
    "SELECT r.UserID, a.ScholarshipID FROM scholar_renewals r LEFT JOIN application a ON a.ApplicationID = r.ApplicationID WHERE ? IN (r.ROG_FilePath, r.COR_FilePath, r.Appeal_FilePath) OR ? IN (r.ROG_FilePath, r.COR_FilePath, r.Appeal_FilePath)",
    "SELECT UserID, NULL FROM user_vault WHERE FilePath IN (?, ?)",
    "SELECT UserID, NULL FROM users WHERE ProfilePicture IN (?, ?)",
];
foreach ($lookups as $sql) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($stored);
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$uid, $sid]) {
        $owner = $owner ?? (int) $uid;
        if ($sid !== null) $scholarship_ids[] = (int) $sid;
    }
    if ($owner !== null) break;
}
// Files not (or no longer) in the database: the owner is in the name or folder (vault/<id>/, STU-<id>-, USER_<id>_)
if ($owner === null && preg_match('#^vault/(\d+)/|(?:^|/)(?:STU-|USER_)(\d+)[-_]#', $rel, $m)) {
    $owner = (int) ($m[1] !== '' ? $m[1] : $m[2]);
}

$allowed = false;
if (in_array($role, ['Super_Admin', 'Internal_Admin'], true)) {
    $allowed = true;
} elseif ($role === 'Student') {
    $allowed = $owner !== null && $owner === $me;
} elseif ($role === 'External_Admin' && $owner !== null) {
    // The provider's own scholarships: the file's application, or any application by this student
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
                           WHERE s.CreatedBy = ? AND " . ($scholarship_ids ? 'a.ScholarshipID IN (' . implode(',', array_map('intval', $scholarship_ids)) . ')' : 'a.UserID = ?'));
    $stmt->execute($scholarship_ids ? [$me] : [$me, $owner]);
    $allowed = (int) $stmt->fetchColumn() > 0;
}
if (!$allowed) deny(403, 'You do not have access to this file.');

// Serve it. Only documents and images display in the browser; anything else downloads as a file.
$inline_types = [
    'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp',
];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$type = $inline_types[$ext] ?? 'application/octet-stream';
$name = str_replace(['"', "\r", "\n"], '', basename($path));

header('Content-Type: ' . $type);
header('Content-Disposition: ' . (isset($inline_types[$ext]) ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);
