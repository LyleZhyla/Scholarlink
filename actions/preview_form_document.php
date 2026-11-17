<?php
session_start();
require '../includes/db_connect.php';
require_once '../vendor/autoload.php';
require_once '../includes/application_authority.php';
require_once '../includes/form_document.php';

// ✨ PREVIEW OF A WORD-STYLE APPLICATION FORM (from the form builder, before or after saving)
// POST JSON { scholarship_id, document: { body, header, footer, paper, header_mm, footer_mm } } → the PDF, answer spots shown by name

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) fail('Unauthorized.', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
$raw = file_get_contents('php://input');
if (strlen($raw) > FORM_DOCUMENT_MAX_BYTES * 3 + 10000) fail('The document is too big (pictures make it bigger: use smaller ones).', 413);
$payload = json_decode($raw, true);
$scholarship_id = (int) ($payload['scholarship_id'] ?? 0);
$authority = getScholarshipAuthority($pdo, $scholarship_id);
if (!$authority) fail('Scholarship not found.', 404);
if (!canDecideApplication($authority, $_SESSION['role'], $_SESSION['user_id'])) fail("Only {$authority['DecidedBy']} can edit the application form of {$authority['ScholarshipName']}.", 403);

$d = (array) ($payload['document'] ?? []);
$doc = [
    'body' => sanitizeFormDocumentHtml((string) ($d['body'] ?? '')),
    'header' => sanitizeFormDocumentHtml((string) ($d['header'] ?? '')),
    'footer' => sanitizeFormDocumentHtml((string) ($d['footer'] ?? '')),
    'paper' => isset(FORM_DOCUMENT_PAPERS[$d['paper'] ?? '']) ? $d['paper'] : 'A4',
    'header_mm' => (float) ($d['header_mm'] ?? 20), 'footer_mm' => (float) ($d['footer_mm'] ?? 12),
];
// Answer spots carry their question's place in the form (data-qi); its name comes with them (labels: place => name)
$labels = [];
foreach ((array) ($payload['labels'] ?? []) as $i => $name) $labels[900000000 + (int) $i] = mb_substr((string) $name, 0, 255);
$ids = array_map(fn($i) => 900000000 + $i, range(0, 999));
foreach (['body', 'header', 'footer'] as $part) $doc[$part] = formDocumentLinkFields($doc[$part], $ids);

try {
    $pdf = formDocumentPdf($doc, [], [], $labels, true);
} catch (Throwable $e) {
    fail('Could not make the preview: ' . $e->getMessage(), 500);
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Form_preview.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
