<?php
// ✨ SAVES (OR RESETS) A SPONSOR'S OWN REPORT FORMAT FOR ITS SCHOLARSHIP
// Body (JSON): { scholarship_id, reset?, title, subtitle, prepared_by, columns: [{ key, label }] }
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';
require_once '../includes/reports.php';

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit();
}

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) fail('Unauthorized.', 403);

$payload = json_decode(file_get_contents('php://input'), true);
$scholarship_id = (int) ($payload['scholarship_id'] ?? 0);
$authority = getScholarshipAuthority($pdo, $scholarship_id);
if (!$authority) fail('Scholarship not found.', 404);
// The scholarship's owner sets its format: the provider, or the OSSD for internally funded ones
if (!canDecideApplication($authority, $_SESSION['role'], $_SESSION['user_id'])) {
    fail("Only {$authority['DecidedBy']} can set the report format of {$authority['ScholarshipName']}.", 403);
}

if (!empty($payload['reset'])) {
    $pdo->prepare("DELETE FROM scholarship_report_formats WHERE ScholarshipID = ?")->execute([$scholarship_id]);
    echo json_encode(['success' => true, 'reset' => true]);
    exit();
}

$available = reportAvailableColumns($pdo, $scholarship_id);
$columns = [];
foreach ((array) ($payload['columns'] ?? []) as $c) {
    $key = (string) ($c['key'] ?? '');
    if (!isset($available[$key])) fail("Unknown report column \"$key\".");
    if (isset($columns[$key])) fail("The column \"{$available[$key]}\" was added twice.");
    $columns[$key] = ['key' => $key, 'label' => mb_substr(trim((string) ($c['label'] ?? '')) ?: $available[$key], 0, 100)];
}
if (!$columns) fail('Choose at least one column for the report.');
if (count($columns) > 30) fail('A report can have at most 30 columns.');

$clip = fn($v, $n) => mb_substr(trim((string) $v), 0, $n) ?: null;
$pdo->prepare("REPLACE INTO scholarship_report_formats (ScholarshipID, Title, Subtitle, Columns, PreparedBy, UpdatedBy) VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([$scholarship_id, $clip($payload['title'] ?? '', 200), $clip($payload['subtitle'] ?? '', 255), json_encode(array_values($columns)), $clip($payload['prepared_by'] ?? '', 150), $_SESSION['user_id']]);
$pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'Report Format Updated', NOW(), ?, ?)")
    ->execute([$_SESSION['user_id'], "Updated the report format of {$authority['ScholarshipName']} (" . count($columns) . " columns)", $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);

echo json_encode(['success' => true]);
