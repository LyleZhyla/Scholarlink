<?php
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';
require_once '../includes/application_authority.php';
require_once '../includes/form_versions.php';

// ✨ THE SAVED VERSIONS OF A SCHOLARSHIP'S APPLICATION FORM (for the PDF designer's History)
// GET scholarship_id            → the list, newest first
// GET scholarship_id & version  → that version's whole form (questions, PDF form, PDF changes)

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit();
}

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) fail('Unauthorized.', 403);

$scholarship_id = (int) ($_GET['scholarship_id'] ?? 0);
$authority = getScholarshipAuthority($pdo, $scholarship_id);
if (!$authority) fail('Scholarship not found.', 404);
// The same people who can edit the form
if (!canDecideApplication($authority, $_SESSION['role'], $_SESSION['user_id'])) fail("Only {$authority['DecidedBy']} can edit the application form of {$authority['ScholarshipName']}.", 403);
if (!formVersionsReady($pdo)) fail('Form versions are not set up yet. Run migrations/2026-11-14_form_versions.sql.', 500);

if (isset($_GET['version'])) {
    $stmt = $pdo->prepare("SELECT v.VersionNumber, v.StateJson, DATE_FORMAT(v.CreatedAt, '%b %e, %Y %l:%i %p') AS SavedAt, TRIM(CONCAT_WS(' ', u.FirstName, u.LastName)) AS SavedBy
        FROM application_form_versions v LEFT JOIN users u ON u.UserID = v.CreatedBy WHERE v.ScholarshipID = ? AND v.VersionNumber = ?");
    $stmt->execute([$scholarship_id, (int) $_GET['version']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('That version was not found.', 404);
    echo json_encode(['success' => true, 'version' => (int) $row['VersionNumber'], 'saved_at' => $row['SavedAt'], 'saved_by' => $row['SavedBy'] ?: null,
        'state' => json_decode($row['StateJson'], true)]);
    exit();
}

$stmt = $pdo->prepare("SELECT v.VersionNumber, v.Summary, v.QuestionCount, v.PdfChangeCount, v.TemplateFile, v.RestoredFrom,
        DATE_FORMAT(v.CreatedAt, '%b %e, %Y %l:%i %p') AS SavedAt, TRIM(CONCAT_WS(' ', u.FirstName, u.LastName)) AS SavedBy
    FROM application_form_versions v LEFT JOIN users u ON u.UserID = v.CreatedBy WHERE v.ScholarshipID = ? ORDER BY v.VersionNumber DESC");
$stmt->execute([$scholarship_id]);
echo json_encode(['success' => true, 'versions' => array_map(fn($v) => [
    'version' => (int) $v['VersionNumber'], 'summary' => $v['Summary'], 'questions' => (int) $v['QuestionCount'],
    'pdf_changes' => (int) $v['PdfChangeCount'], 'template' => (string) ($v['TemplateFile'] ?? ''),
    'restored_from' => $v['RestoredFrom'] === null ? null : (int) $v['RestoredFrom'],
    'saved_at' => $v['SavedAt'], 'saved_by' => $v['SavedBy'] ?: null,
], $stmt->fetchAll(PDO::FETCH_ASSOC))]);
