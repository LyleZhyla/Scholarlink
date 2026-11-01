<?php
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';
require_once '../includes/pdf_templates.php';

// ✨ UPLOAD OR DELETE AN ADMIN'S OWN PDF FORM (used by the Form Builder)
// POST action=upload: file (PDF), label  → saved in /forms, recorded in form_templates
// POST action=delete: file               → only the uploader / Super Admin, and only when no scholarship uses it

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit();
}

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) {
    fail('Unauthorized.', 403);
}
$user_id = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? 'upload';

// ---------- DELETE ----------
if ($action === 'delete') {
    $file = basename((string) ($_POST['file'] ?? ''));
    $stmt = $pdo->prepare("SELECT * FROM form_templates WHERE FileName = ?");
    $stmt->execute([$file]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) fail('Only uploaded forms can be deleted. The built-in forms stay.');
    if ((int) $template['UploadedBy'] !== $user_id && $_SESSION['role'] !== 'Super_Admin') fail('You can only delete forms you uploaded.', 403);

    $used = $pdo->prepare("SELECT Name FROM scholarship WHERE FormTemplate = ?");
    $used->execute([$file]);
    $used_by = $used->fetchAll(PDO::FETCH_COLUMN);
    if ($used_by) fail('This form is still used by: ' . implode(', ', $used_by) . '. Switch those scholarships to another form first.');

    $pdo->prepare("DELETE FROM form_templates WHERE TemplateID = ?")->execute([$template['TemplateID']]);
    @unlink(PDF_FORMS_DIR . DIRECTORY_SEPARATOR . $file);
    $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'PDF Form Deleted', NOW(), ?, ?)")
        ->execute([$user_id, "Deleted the uploaded PDF form \"{$template['Label']}\"", $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
    echo json_encode(['success' => true]);
    exit();
}

// ---------- UPLOAD ----------
if (!isset($_FILES['form']) || $_FILES['form']['error'] !== UPLOAD_ERR_OK) {
    $too_big = isset($_FILES['form']) && in_array($_FILES['form']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    fail($too_big ? 'The PDF is too large.' : 'Please choose a PDF file to upload.');
}
$upload = $_FILES['form'];
if ($upload['size'] > 10 * 1024 * 1024) fail('The PDF is too large (10 MB maximum).');
if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf') fail('Only PDF files can be uploaded.');
$handle = fopen($upload['tmp_name'], 'rb');
$magic = fread($handle, 5);
fclose($handle);
if ($magic !== '%PDF-') fail('That file is not a real PDF.');

$label = trim((string) ($_POST['label'] ?? ''));
if ($label === '') $label = trim(preg_replace('/[_\s]+/', ' ', pathinfo($upload['name'], PATHINFO_FILENAME)));
$label = mb_substr($label, 0, 150);

// Safe, unique file name: custom_<user>_<time>_<slug>.pdf
$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($upload['name'], PATHINFO_FILENAME)), '-') ?: 'form';
$file = 'custom_' . $user_id . '_' . date('YmdHis') . '_' . substr($slug, 0, 60) . '.pdf';
$path = PDF_FORMS_DIR . DIRECTORY_SEPARATOR . $file;
if (!move_uploaded_file($upload['tmp_name'], $path)) fail('The form could not be saved on the server.', 500);

// pdftk must be able to read it (password-protected or damaged PDFs can't be filled)
$pages = getPdfPageSizes($file);
if (!$pages) {
    @unlink($path);
    fail('This PDF could not be read. It may be password-protected or damaged. Remove the protection and try again.');
}
$fields = getPdfTemplateFields($file);

$pdo->prepare("INSERT INTO form_templates (FileName, Label, UploadedBy) VALUES (?, ?, ?)")->execute([$file, $label, $user_id]);
$pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'PDF Form Uploaded', NOW(), ?, ?)")
    ->execute([$user_id, "Uploaded the PDF form \"$label\" (" . count($pages) . " page(s), " . count($fields) . " fillable field(s))", $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);

echo json_encode([
    'success' => true,
    'file' => $file,
    'template' => ['label' => $label, 'fields' => $fields, 'auto' => profilePdfFieldNames($file), 'custom' => true, 'mine' => true],
    'pages' => count($pages),
]);
