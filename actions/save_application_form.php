<?php
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';
require_once '../includes/application_authority.php';
require_once '../includes/form_fields.php';
require_once '../includes/pdf_templates.php';

// ✨ SAVES A SCHOLARSHIP'S APPLICATION FORM FROM THE FORM BUILDER
// Body (JSON): { scholarship_id, instructions, fields: [{ id|null, label, type, help, placeholder, options, required, pdf }] }

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit();
}

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) {
    fail('Unauthorized.', 403);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['fields']) || !is_array($payload['fields'])) {
    fail('Invalid form data.');
}

$scholarship_id = (int) ($payload['scholarship_id'] ?? 0);
$authority = getScholarshipAuthority($pdo, $scholarship_id);
if (!$authority) fail('Scholarship not found.', 404);
if (!canDecideApplication($authority, $_SESSION['role'], $_SESSION['user_id'])) {
    fail("Only {$authority['DecidedBy']} can edit the application form of {$authority['ScholarshipName']}.", 403);
}

// --- VALIDATE ---
// Official PDF form: '' = automatic, otherwise a file in /forms
$template = (string) ($payload['template'] ?? '');
if ($template !== '' && !isset(listPdfTemplates()[$template])) fail('That PDF form was not found in the forms folder.');
$effective_template = resolvePdfTemplate($template ?: null, $authority['ScholarshipName']);
$pdf_field_names = array_column(getPdfTemplateFields($effective_template), 'name');
$pdf_page_count = count(getPdfPageSizes($effective_template));
$used_pdf_fields = [];

$auto_types = getAutoFillTypes($pdo);
$clean = [];
foreach ($payload['fields'] as $i => $f) {
    $n = $i + 1;
    $type = (string) ($f['type'] ?? '');
    $label = trim((string) ($f['label'] ?? ''));

    if (!isset(FORM_FIELD_TYPES[$type]) && !isset($auto_types[$type])) fail("Question $n has an unknown type.");
    if ($label === '') fail("Question $n needs a label.");
    if (mb_strlen($label) > 255) fail("Question $n's label is too long (255 characters max).");

    $options = fieldTypeNeedsOptions($type) ? parseFieldOptions($f['options'] ?? '') : [];
    if (fieldTypeNeedsOptions($type) && count($options) < 2) fail("\"$label\" needs at least 2 choices.");
    if (count($options) !== count(array_unique($options))) fail("\"$label\" has duplicate choices.");

    $pdf = $type === 'Section' ? '' : trim((string) ($f['pdf'] ?? ''));
    if ($pdf !== '') {
        // Only checked when pdftk could read the template's fields
        if ($pdf_field_names && !in_array($pdf, $pdf_field_names, true)) fail("\"$label\" is linked to \"$pdf\", which is not a field of the selected PDF form.");
        if (isset($used_pdf_fields[$pdf])) fail("\"$label\" and \"{$used_pdf_fields[$pdf]}\" are both linked to the PDF field \"$pdf\".");
        $used_pdf_fields[$pdf] = $label;
    }

    // A box drawn on the PDF: page + position as fractions of the page (top-left origin)
    $box = null;
    if ($type !== 'Section' && !empty($f['box']) && is_array($f['box'])) {
        if ($pdf !== '') fail("\"$label\" can either fill a PDF field or a drawn box, not both.");
        $b = $f['box'];
        $page = (int) ($b['page'] ?? 0);
        [$bx, $by, $bw, $bh] = array_map(fn($k) => round((float) ($b[$k] ?? -1), 6), ['x', 'y', 'w', 'h']);
        $size = isset($b['size']) && $b['size'] !== '' && $b['size'] !== null ? (float) $b['size'] : null;
        if ($page < 1 || ($pdf_page_count && $page > $pdf_page_count)) fail("\"$label\" has a box on a page that isn't in the selected PDF.");
        if ($bx < 0 || $by < 0 || $bw < 0.005 || $bh < 0.003 || $bx + $bw > 1.0001 || $by + $bh > 1.0001) fail("\"$label\" has a box outside the page.");
        if ($size !== null && ($size < 5 || $size > 24)) fail("\"$label\": text size must be between 5 and 24.");
        $box = ['page' => $page, 'x' => $bx, 'y' => $by, 'w' => $bw, 'h' => $bh, 'size' => $size];
    }

    $clean[] = [
        'id'          => isset($f['id']) && $f['id'] !== null && $f['id'] !== '' ? (int) $f['id'] : null,
        'label'       => $label,
        'type'        => $type,
        'help'        => mb_substr(trim((string) ($f['help'] ?? '')), 0, 255) ?: null,
        'placeholder' => mb_substr(trim((string) ($f['placeholder'] ?? '')), 0, 150) ?: null,
        'options'     => $options ? implode("\n", $options) : null,
        'required'    => ($type !== 'Section' && !empty($f['required'])) ? 1 : 0,
        'pdf'         => mb_substr($pdf, 0, 100) ?: null,
        'box'         => $box,
        'order'       => $n,
    ];
}

// Changes to the PDF's own fields: { fieldName: { action: 'hide' } | { action: 'move', box: {page, x, y, w, h, size} } }
$overrides = [];
foreach ((array) ($payload['overrides'] ?? []) as $name => $o) {
    $name = (string) $name;
    if ($pdf_field_names && !in_array($name, $pdf_field_names, true)) fail("The PDF field \"$name\" is not on the selected PDF form.");
    $action = $o['action'] ?? '';
    if ($action === 'hide') {
        $overrides[$name] = ['action' => 'hide', 'box' => null];
    } elseif ($action === 'move' && is_array($o['box'] ?? null)) {
        $b = $o['box'];
        $page = (int) ($b['page'] ?? 0);
        [$bx, $by, $bw, $bh] = array_map(fn($k) => round((float) ($b[$k] ?? -1), 6), ['x', 'y', 'w', 'h']);
        $size = isset($b['size']) && $b['size'] !== '' && $b['size'] !== null ? (float) $b['size'] : null;
        if ($page < 1 || ($pdf_page_count && $page > $pdf_page_count)) fail("The moved PDF field \"$name\" is on a page that isn't in the selected PDF.");
        if ($bx < 0 || $by < 0 || $bw < 0.005 || $bh < 0.003 || $bx + $bw > 1.0001 || $by + $bh > 1.0001) fail("The moved PDF field \"$name\" is outside the page.");
        if ($size !== null && ($size < 5 || $size > 24)) fail("The moved PDF field \"$name\": text size must be between 5 and 24.");
        $overrides[$name] = ['action' => 'move', 'box' => ['page' => $page, 'x' => $bx, 'y' => $by, 'w' => $bw, 'h' => $bh, 'size' => $size]];
    } else {
        fail("Unknown change for the PDF field \"$name\".");
    }
}

// Edits to the PDF's printed content: [{ kind: 'erase'|'text', page, x, y, w, h, text, font, size, bold, cover }]
$edits = [];
foreach ((array) ($payload['edits'] ?? []) as $i => $e) {
    $n = $i + 1;
    $kind = $e['kind'] ?? '';
    if (!in_array($kind, ['erase', 'text'], true)) fail("Content edit $n has an unknown type.");
    $page = (int) ($e['page'] ?? 0);
    [$ex, $ey, $ew, $eh] = array_map(fn($k) => round((float) ($e[$k] ?? -1), 6), ['x', 'y', 'w', 'h']);
    if ($page < 1 || ($pdf_page_count && $page > $pdf_page_count)) fail("Content edit $n is on a page that isn't in the selected PDF.");
    if ($ex < 0 || $ey < 0 || $ew < 0.002 || $eh < 0.002 || $ex + $ew > 1.0001 || $ey + $eh > 1.0001) fail("Content edit $n is outside the page.");
    $text = $kind === 'text' ? mb_substr(trim((string) ($e['text'] ?? '')), 0, 1000) : null;
    if ($kind === 'text' && $text === '') fail("Text edit $n is empty. Type some text or delete it.");
    $font = in_array($e['font'] ?? '', ['Helvetica', 'Times', 'Courier'], true) ? $e['font'] : 'Helvetica';
    $size = isset($e['size']) && $e['size'] !== '' && $e['size'] !== null ? (float) $e['size'] : null;
    if ($size !== null && ($size < 5 || $size > 48)) fail("Text edit $n: text size must be between 5 and 48.");
    $edits[] = [$kind, $page, $ex, $ey, $ew, $eh, $text, $font, $size, !empty($e['bold']) ? 1 : 0, !empty($e['cover']) ? 1 : 0, $n];
}

// --- SAVE ---
try {
    $pdo->beginTransaction();

    $existing_ids = $pdo->prepare("SELECT FieldID FROM scholarship_custom_fields WHERE ScholarshipID = ? AND IsActive = 1");
    $existing_ids->execute([$scholarship_id]);
    $existing_ids = array_map('intval', $existing_ids->fetchAll(PDO::FETCH_COLUMN));

    $update = $pdo->prepare("UPDATE scholarship_custom_fields SET FieldName = ?, FieldType = ?, HelpText = ?, Placeholder = ?, Options = ?, IsRequired = ?, PDFFieldName = ?, PdfPage = ?, PdfX = ?, PdfY = ?, PdfW = ?, PdfH = ?, PdfFontSize = ?, SortOrder = ?, IsActive = 1 WHERE FieldID = ? AND ScholarshipID = ?");
    $insert = $pdo->prepare("INSERT INTO scholarship_custom_fields (ScholarshipID, FieldName, FieldType, HelpText, Placeholder, Options, IsRequired, PDFFieldName, PdfPage, PdfX, PdfY, PdfW, PdfH, PdfFontSize, SortOrder, IsActive) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

    $kept_ids = [];
    $saved = [];
    foreach ($clean as $f) {
        $b = $f['box'] ?? ['page' => null, 'x' => null, 'y' => null, 'w' => null, 'h' => null, 'size' => null];
        $values = [$f['label'], $f['type'], $f['help'], $f['placeholder'], $f['options'], $f['required'], $f['pdf'], $b['page'], $b['x'], $b['y'], $b['w'], $b['h'], $b['size'], $f['order']];
        if ($f['id'] !== null && in_array($f['id'], $existing_ids, true)) {
            $update->execute(array_merge($values, [$f['id'], $scholarship_id]));
            $kept_ids[] = $f['id'];
            $saved[] = $f['id'];
        } else {
            $insert->execute(array_merge([$scholarship_id], $values));
            $saved[] = (int) $pdo->lastInsertId();
        }
    }

    // Removed questions: archive the ones students already answered (keeps past applications intact), delete the rest
    $removed = array_diff($existing_ids, $kept_ids);
    $archived = 0;
    if ($removed) {
        $has_answers = $pdo->prepare("SELECT COUNT(*) FROM application_custom_answers WHERE FieldID = ?");
        $archive = $pdo->prepare("UPDATE scholarship_custom_fields SET IsActive = 0 WHERE FieldID = ?");
        $delete = $pdo->prepare("DELETE FROM scholarship_custom_fields WHERE FieldID = ?");
        foreach ($removed as $rid) {
            $has_answers->execute([$rid]);
            if ($has_answers->fetchColumn() > 0) { $archive->execute([$rid]); $archived++; }
            else { $delete->execute([$rid]); }
        }
    }

    // Replace this scholarship's changes to the PDF's own fields
    $pdo->prepare("DELETE FROM scholarship_pdf_overrides WHERE ScholarshipID = ?")->execute([$scholarship_id]);
    $ovr_insert = $pdo->prepare("INSERT INTO scholarship_pdf_overrides (ScholarshipID, FieldName, Action, PdfPage, PdfX, PdfY, PdfW, PdfH, PdfFontSize) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($overrides as $name => $o) {
        $b = $o['box'] ?? ['page' => null, 'x' => null, 'y' => null, 'w' => null, 'h' => null, 'size' => null];
        $ovr_insert->execute([$scholarship_id, $name, $o['action'], $b['page'], $b['x'], $b['y'], $b['w'], $b['h'], $b['size']]);
    }

    // Replace this scholarship's edits to the PDF's printed content
    $pdo->prepare("DELETE FROM scholarship_pdf_edits WHERE ScholarshipID = ?")->execute([$scholarship_id]);
    $edit_insert = $pdo->prepare("INSERT INTO scholarship_pdf_edits (ScholarshipID, Kind, PdfPage, PdfX, PdfY, PdfW, PdfH, Content, FontFamily, FontSize, IsBold, Cover, SortOrder) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($edits as $e) {
        $edit_insert->execute(array_merge([$scholarship_id], $e));
    }

    $instructions = trim((string) ($payload['instructions'] ?? ''));
    $pdo->prepare("UPDATE scholarship SET FormInstructions = ?, FormTemplate = ?, FormUpdatedAt = NOW() WHERE ScholarshipID = ?")
        ->execute([$instructions !== '' ? $instructions : null, $template !== '' ? $template : null, $scholarship_id]);

    $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'Application Form Updated', NOW(), ?, ?)")
        ->execute([
            $_SESSION['user_id'],
            "Updated the application form of {$authority['ScholarshipName']} (" . count($clean) . " questions" . ($removed ? ", " . count($removed) . " removed" : "") . ")",
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);

    $pdo->commit();

    // Use the database's clock, the same one the builder page shows on load
    $saved_at = $pdo->prepare("SELECT DATE_FORMAT(FormUpdatedAt, '%b %e, %Y %l:%i %p') FROM scholarship WHERE ScholarshipID = ?");
    $saved_at->execute([$scholarship_id]);
    echo json_encode(['success' => true, 'ids' => $saved, 'archived' => $archived, 'saved_at' => trim($saved_at->fetchColumn())]);
} catch (PDOException $e) {
    $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
