<?php
// ✨ APPLICATION FORM VERSIONS
// Every save in the form builder keeps the whole form: the questions, the PDF form chosen, and every change made to that
// PDF (edited / added text, erased areas, added / copied / moved lines, hidden or moved PDF fields, answer boxes). An earlier
// version can be opened in the builder and restored; restoring saves it again as the newest version, so nothing is lost.
require_once __DIR__ . '/form_fields.php';

const FORM_VERSIONS_KEPT = 200;   // per scholarship; the oldest are removed after that

function formVersionsReady(PDO $pdo): bool {
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT 1 FROM application_form_versions LIMIT 1'); $ready = true; }
        catch (PDOException $e) { $ready = false; }
    }
    return $ready;
}

// The form as the builder works with it (see includes/form_builder_view.php)
function loadFormState(PDO $pdo, int $scholarship_id): array {
    $sch = $pdo->prepare('SELECT FormInstructions, FormTemplate FROM scholarship WHERE ScholarshipID = ?');
    $sch->execute([$scholarship_id]);
    $sch = $sch->fetch(PDO::FETCH_ASSOC) ?: [];

    $num = fn($v) => $v === null ? null : (float) $v;
    $fields = array_map(fn($f) => [
        'id'          => (int) $f['FieldID'],
        'label'       => $f['FieldName'],
        'type'        => $f['FieldType'],
        'help'        => (string) ($f['HelpText'] ?? ''),
        'placeholder' => (string) ($f['Placeholder'] ?? ''),
        'options'     => (string) ($f['Options'] ?? ''),
        'required'    => (bool) $f['IsRequired'],
        'pdf'         => (string) ($f['PDFFieldName'] ?? ''),
        'width'       => ($f['Width'] ?? 'full') === 'half' ? 'half' : 'full',   // half: side by side with the next half field
        // Box drawn on the PDF (page + position as fractions of the page)
        'box'         => $f['PdfPage'] === null ? null : [
            'page' => (int) $f['PdfPage'], 'x' => (float) $f['PdfX'], 'y' => (float) $f['PdfY'],
            'w' => (float) $f['PdfW'], 'h' => (float) $f['PdfH'], 'size' => $num($f['PdfFontSize']),
        ],
    ], getFormFields($pdo, $scholarship_id));

    // Changes to the PDF's printed content, in order (inserted / moved lines depend on it); 'break' = where a page that grew continues
    $stmt = $pdo->prepare('SELECT * FROM scholarship_pdf_edits WHERE ScholarshipID = ? ORDER BY SortOrder, EditID');
    $stmt->execute([$scholarship_id]);
    $edits = array_map(fn($e) => [
        'kind' => $e['Kind'], 'page' => (int) $e['PdfPage'], 'x' => (float) $e['PdfX'], 'y' => (float) $e['PdfY'], 'w' => (float) $e['PdfW'], 'h' => (float) $e['PdfH'],
        'text' => (string) ($e['Content'] ?? ''), 'font' => $e['FontFamily'], 'size' => $num($e['FontSize']),
        'bold' => (bool) $e['IsBold'], 'cover' => (bool) $e['Cover'],
    ] + ($e['Kind'] === 'move' ? ['to' => (float) $e['PdfTo']] : []), $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Fields of the PDF that were hidden or moved
    $overrides = [];
    $stmt = $pdo->prepare('SELECT * FROM scholarship_pdf_overrides WHERE ScholarshipID = ? ORDER BY FieldName');
    $stmt->execute([$scholarship_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $overrides[$o['FieldName']] = $o['Action'] === 'hide' ? ['action' => 'hide'] : ['action' => 'move', 'box' => [
            'page' => (int) $o['PdfPage'], 'x' => (float) $o['PdfX'], 'y' => (float) $o['PdfY'], 'w' => (float) $o['PdfW'], 'h' => (float) $o['PdfH'],
            'size' => $num($o['PdfFontSize']),
        ]];
    }

    return [
        'instructions' => (string) ($sch['FormInstructions'] ?? ''),
        'template'     => (string) ($sch['FormTemplate'] ?? ''),
        'fields'       => $fields,
        'edits'        => $edits,
        'overrides'    => $overrides,
    ];
}

// What a version holds, for the list
function formStateCounts(array $state): array {
    $questions = count(array_filter($state['fields'], fn($f) => $f['type'] !== 'Section'));
    $pdf = count(array_filter($state['edits'], fn($e) => $e['kind'] !== 'break')) + count($state['overrides'])
        + count(array_filter($state['fields'], fn($f) => !empty($f['box'])));
    return [$questions, $pdf];
}

// The same form, ignoring the field ids (they change when answered questions are saved again)
function formStateHash(array $state): string {
    $state['fields'] = array_map(function ($f) { unset($f['id']); return $f; }, $state['fields']);
    return hash('sha256', json_encode($state));
}

// Keeps the form as it is now in the database as a new version (unless it is the same as the newest one).
// Returns the version number that holds it.
function saveFormVersion(PDO $pdo, int $scholarship_id, ?int $created_by, ?string $summary = null, ?int $restored_from = null, ?string $created_at = null): int {
    if (!formVersionsReady($pdo)) return 0;
    $state = loadFormState($pdo, $scholarship_id);
    $hash = formStateHash($state);

    $latest = $pdo->prepare('SELECT VersionNumber, StateHash, StateJson FROM application_form_versions WHERE ScholarshipID = ? ORDER BY VersionNumber DESC LIMIT 1 FOR UPDATE');
    $latest->execute([$scholarship_id]);
    $latest = $latest->fetch(PDO::FETCH_ASSOC);
    if ($latest && $latest['StateHash'] === $hash) return (int) $latest['VersionNumber'];

    if ($summary === null) $summary = $restored_from ? "Restored version $restored_from" : formChangeSummary($latest ? json_decode($latest['StateJson'], true) : null, $state);
    [$questions, $pdf_changes] = formStateCounts($state);
    $number = $latest ? (int) $latest['VersionNumber'] + 1 : 1;
    $pdo->prepare('INSERT INTO application_form_versions (ScholarshipID, VersionNumber, StateHash, StateJson, Summary, QuestionCount, PdfChangeCount, TemplateFile, RestoredFrom, CreatedBy, CreatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))')
        ->execute([$scholarship_id, $number, $hash, json_encode($state), mb_substr($summary, 0, 255), $questions, $pdf_changes,
            $state['template'] !== '' ? $state['template'] : null, $restored_from, $created_by, $created_at]);

    // Keep the newest FORM_VERSIONS_KEPT
    $pdo->prepare('DELETE FROM application_form_versions WHERE ScholarshipID = ? AND VersionNumber <= ?')->execute([$scholarship_id, $number - FORM_VERSIONS_KEPT]);
    return $number;
}

// Before the first version is kept: the form as it was already saved, so it can still be brought back
function startFormHistory(PDO $pdo, int $scholarship_id): void {
    if (!formVersionsReady($pdo)) return;
    $any = $pdo->prepare('SELECT COUNT(*) FROM application_form_versions WHERE ScholarshipID = ?');
    $any->execute([$scholarship_id]);
    if ((int) $any->fetchColumn() > 0) return;
    $saved = $pdo->prepare('SELECT FormUpdatedAt FROM scholarship WHERE ScholarshipID = ?');
    $saved->execute([$scholarship_id]);
    $saved_at = $saved->fetchColumn() ?: null;
    $state = loadFormState($pdo, $scholarship_id);
    if (!$saved_at && !$state['fields'] && !$state['edits']) return;   // nothing was ever made
    saveFormVersion($pdo, $scholarship_id, null, 'The form before version history started', null, $saved_at);
}

// "2 questions added, PDF changed" — from the previous version to this one
function formChangeSummary(?array $before, array $after): string {
    if (!$before) return 'First saved version';
    // Both as stored (a JSON round trip turns 1.0 into 1), so only real changes count
    [$before, $after] = [json_decode(json_encode($before), true), json_decode(json_encode($after), true)];
    $parts = [];
    [$q1] = formStateCounts($before);
    [$q2] = formStateCounts($after);
    if ($q2 > $q1) $parts[] = ($q2 - $q1) . ' question' . ($q2 - $q1 === 1 ? '' : 's') . ' added';
    if ($q2 < $q1) $parts[] = ($q1 - $q2) . ' question' . ($q1 - $q2 === 1 ? '' : 's') . ' removed';
    $strip = fn($s) => array_map(function ($f) { unset($f['id'], $f['box'], $f['pdf']); return $f; }, $s['fields']);
    if ($q1 === $q2 && $strip($before) !== $strip($after)) $parts[] = 'questions edited';
    if ($before['template'] !== $after['template']) $parts[] = 'another PDF form chosen';
    else {
        $pdfOf = fn($s) => [array_values(array_filter($s['edits'], fn($e) => $e['kind'] !== 'break')), $s['overrides'],
            array_map(fn($f) => [$f['box'] ?? null, $f['pdf'] ?? ''], $s['fields'])];
        if ($pdfOf($before) !== $pdfOf($after)) $parts[] = 'PDF changed';
    }
    if ($before['instructions'] !== $after['instructions']) $parts[] = 'instructions changed';
    return $parts ? ucfirst(implode(', ', $parts)) : 'Saved';
}
