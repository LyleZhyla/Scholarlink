<?php
// ✨ FORM BUILDER VIEW (shared by the Internal, External and Super Admin portals)
// The form is shown exactly as students see it; admins add, edit, reorder and delete questions in place.
// Expects: $pdo, $scholarship (row), $back_url. Saves through actions/save_application_form.php.
require_once __DIR__ . '/form_fields.php';
require_once __DIR__ . '/pdf_templates.php';

require_once __DIR__ . '/form_versions.php';

// The form as saved (questions, PDF changes); includes/form_versions.php keeps the same shape for every version
$form_state = loadFormState($pdo, (int) $scholarship['ScholarshipID']);
$builder_fields = $form_state['fields'];

$doc_stmt = $pdo->prepare("SELECT DocumentName FROM document_requirement WHERE ScholarshipID = ? AND IsActive = 1 ORDER BY RequirementID ASC");
$doc_stmt->execute([$scholarship['ScholarshipID']]);
$builder_documents = $doc_stmt->fetchAll(PDO::FETCH_COLUMN);

$answered_stmt = $pdo->prepare("SELECT COUNT(*) FROM application WHERE ScholarshipID = ?");
$answered_stmt->execute([$scholarship['ScholarshipID']]);
$application_count = (int) $answered_stmt->fetchColumn();

$field_types = [];
foreach (FORM_FIELD_TYPES as $key => [$label, $icon, $needsOptions]) {
    $field_types[$key] = ['label' => $label, 'icon' => $icon, 'options' => $needsOptions];
}
$auto_types = getAutoFillTypes($pdo);

// Official PDF forms this admin can use (built-in + their own uploads): fillable fields and which ones the profile fills
$pdf_templates = [];
foreach (visiblePdfTemplates($pdo, $_SESSION['role'], (int) $_SESSION['user_id'], $scholarship['FormTemplate'] ?? null) as $file => $t) {
    $pdf_templates[$file] = $t + ['fields' => getPdfTemplateFields($file), 'auto' => profilePdfFieldNames($file)];
}
$chosen_template = isset($pdf_templates[$scholarship['FormTemplate'] ?? '']) ? $scholarship['FormTemplate'] : '';

// Edits to the PDF's printed content, in order; 'break' = where a page that grew continues on a new printed page
// (worked out again in the designer, see pageBreakAt)
$pdf_edits = array_values(array_filter($form_state['edits'], fn($e) => $e['kind'] !== 'break'));
$pdf_breaks = array_values(array_filter($form_state['edits'], fn($e) => $e['kind'] === 'break'));
// Fields of the PDF this scholarship hid or moved in the PDF designer
$pdf_overrides = $form_state['overrides'];
// The Word-style document (when the scholarship has one) and which form prints
$form_mode = $form_state['mode'] ?? 'pdf';
$form_document = $form_state['document'] ?? null;
$default_template = defaultPdfTemplate($scholarship['Name']);
?>

<!-- The admin header doesn't load Font Awesome; the builder's icons need it -->
<link rel="stylesheet" href="../assets/lib/fontawesome/css/all.min.css">

<style>
    /* ---- Edit tab: the form as rows of one or two fields ---- */
    .fb-tabbtn { flex: 1; padding: 14px 10px; font-size: 12px; font-weight: 900; letter-spacing: 0.12em; text-transform: uppercase; color: #64748b; border-bottom: 3px solid transparent; transition: color 0.15s, border-color 0.15s; }
    .fb-tabbtn:hover { color: #0f172a; }
    .fb-tabbtn.active { color: #0f172a; border-bottom-color: #2563eb; }
    .fb-tabbtn .count { margin-left: 6px; padding: 1px 7px; border-radius: 999px; background: #e2e8f0; color: #334155; font-size: 10px; letter-spacing: 0; }
    .fb-tabbtn.active .count { background: #2563eb; color: #fff; }
    .fb-row { display: flex; align-items: stretch; gap: 8px; padding: 10px 12px 10px 4px; border-radius: 16px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
    .fb-grip { display: flex; align-items: center; padding: 0 8px; color: #94a3b8; font-size: 16px; cursor: grab; }
    .fb-grip:hover { color: #0f172a; }
    .fb-tile { display: flex; align-items: center; gap: 4px; min-width: 0; padding: 12px 8px 12px 16px; border-radius: 12px; background: #f8fafc; border: 1.5px solid #e2e8f0; cursor: pointer; transition: border-color 0.15s, background 0.15s; }
    .fb-tile:hover { border-color: #93c5fd; }
    .fb-tile.sel { border-color: #2563eb; background: #eff6ff; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
    .fb-tile.section { background: #0f172a; border-color: #0f172a; }
    .fb-tile.section .fb-tile-title { color: #fff; }
    .fb-tile.section .fb-tile-sub, .fb-tile.section .fb-tile-btn { color: #94a3b8; }
    .fb-tile-title { font-size: 15px; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .fb-tile-sub { margin-top: 2px; font-size: 12px; font-weight: 600; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .fb-tile-btn { width: 36px; height: 36px; flex-shrink: 0; border-radius: 10px; color: #64748b; font-size: 14px; }
    .fb-tile-btn:hover { background: #e2e8f0; color: #0f172a; }
    .fb-tile-btn.danger:hover { background: #fee2e2; color: #dc2626; }
    .fb-dragging .fb-tile, .fb-dragging .fb-row { transition: none; }
    #fbDropMark { position: fixed; z-index: 10000; border-radius: 4px; background: #2563eb; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.2); pointer-events: none; }

    .pdf-row .fb-tile { flex: 1 1 150px; padding: 8px 2px 8px 10px; }
    #pdfRows .fb-row { padding: 6px 6px 6px 0; gap: 4px; border-radius: 12px; }
    #pdfRows .fb-grip { padding: 0 4px 0 6px; font-size: 13px; }
    #pdfRows .fb-tile-title { font-size: 13px !important; }
    #pdfRows .fb-tile-sub { font-size: 11px; }
    #pdfRows .fb-tile-btn { width: 28px; height: 28px; font-size: 12px; }
    #pdfRows .pdf-row.diff { box-shadow: inset 4px 0 0 #f59e0b; }
    /* the gap under each line: a ＋ shows on hover, to add something there */
    .pdf-gap { position: relative; height: 14px; }
    .pdf-gap-line { position: absolute; left: 30px; right: 12px; top: 50%; height: 2px; margin-top: -1px; border-radius: 2px; background: #2563eb; opacity: 0; transition: opacity 0.12s; }
    .pdf-gap-btn { position: absolute; left: 50%; top: 50%; width: 24px; height: 24px; margin: -12px 0 0 -12px; border-radius: 999px; background: #2563eb; color: #fff; font-size: 11px; box-shadow: 0 2px 6px rgba(37, 99, 235, 0.4); opacity: 0; transform: scale(0.7); transition: opacity 0.12s, transform 0.12s; z-index: 2; }
    .pdf-gap:hover .pdf-gap-line, .pdf-gap.open .pdf-gap-line, .pdf-gap:hover .pdf-gap-btn, .pdf-gap.open .pdf-gap-btn, .pdf-gap-btn:focus { opacity: 1; transform: scale(1); }
    #pdGapMenu { position: fixed; z-index: 10001; width: 260px; max-height: 70vh; overflow-y: auto; padding: 6px; border-radius: 14px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 18px 40px rgba(15, 23, 42, 0.25); }
    .pdf-gap-title { padding: 8px 10px 4px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.12em; color: #94a3b8; }
    .pdf-gap-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border-radius: 10px; text-align: left; font-size: 13px; color: #0f172a; }
    .pdf-gap-item:hover, .pdf-gap-item:focus { background: #eff6ff; outline: none; }
    .pdf-gap-item i { width: 18px; text-align: center; color: #2563eb; }
    .pdf-gap-item b { display: block; font-weight: 800; }
    .pdf-gap-item small { display: block; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; }
    html.dark #pdGapMenu { background: #0f172a; border-color: #334155; }
    html.dark .pdf-gap-item { color: #e2e8f0; }
    html.dark .pdf-gap-item:hover { background: #1e293b; }
    .pt-fmt { padding: 8px 12px; border-radius: 10px; border: 1px solid #e2e8f0; background: #f8fafc; font-size: 12px; font-weight: 800; color: #334155; }
    .pt-fmt.on { background: #0f172a; border-color: #0f172a; color: #fff; }
    /* the Word-style ribbon */
    .pd-ribbon { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 3px; padding: 4px; border-radius: 12px; background: #f1f5f9; }
    .pd-ribbon select { height: 32px; padding: 0 6px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; font-size: 12px; font-weight: 700; color: #0f172a; cursor: pointer; }
    .pd-ribbon #rbFont { width: 104px; } .pd-ribbon #rbSize { width: 64px; }
    .pd-ribbon .rb { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 8px; font-size: 13px; color: #334155; display: inline-flex; align-items: center; justify-content: center; gap: 4px; }
    .pd-ribbon .rb:hover:not(:disabled) { background: #e2e8f0; }
    .pd-ribbon .rb.on { background: #0f172a; color: #fff; }
    .pd-ribbon .rb-wide { font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.06em; }
    .pd-ribbon .rb-sep { width: 1px; height: 22px; background: #cbd5e1; margin: 0 3px; }
    .pd-ribbon .rb-color { position: relative; flex-direction: column; gap: 0; cursor: pointer; }
    .pd-ribbon .rb-colorbar { width: 16px; height: 4px; border-radius: 2px; background: #000; }
    .pd-ribbon .rb-color input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .pd-ribbon.off select, .pd-ribbon.off .rb:not(.rb-wide) { opacity: 0.4; pointer-events: none; }
    /* typing on the page */
    .pd-inline { position: absolute; z-index: 30; outline: 2px solid #2563eb; outline-offset: 1px; border-radius: 2px; padding: 0 1px; margin: 0; caret-color: #2563eb; overflow: visible; box-shadow: 0 0 0 5px rgba(37, 99, 235, 0.12); cursor: text; }
    .pd-inline:empty::before { content: 'Type here…'; color: #94a3b8; font-style: italic; }
    /* which form prints */
    .fb-mode { display: flex; align-items: flex-start; gap: 14px; padding: 16px 18px; border-radius: 18px; background: #fff; border: 2px solid #e2e8f0; text-align: left; transition: border-color 0.15s, box-shadow 0.15s; }
    .fb-mode i { font-size: 26px; margin-top: 2px; }
    .fb-mode b { display: block; font-size: 15px; font-weight: 900; color: #0f172a; }
    .fb-mode small { display: block; margin-top: 2px; font-size: 12px; font-weight: 600; color: #64748b; }
    .fb-mode:hover { border-color: #93c5fd; }
    .fb-mode.on { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12); }
    /* the Word-style page (sizes follow the paper; the same look prints, see includes/form_document.php) */
    .doc-page { position: relative; margin: 0 auto; background: #fff; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.25); padding: 15mm 15mm; color: #000;
        font-family: 'Times New Roman', Times, serif; font-size: 11pt; line-height: 1.25; }
    .doc-page .doc-part { outline: none; }
    .doc-page .doc-body { min-height: 120mm; margin: 4mm 0 3mm; }
    .doc-zone { position: relative; min-height: 8mm; border: 1px dashed transparent; border-radius: 3px; }
    .doc-zone:hover, .doc-zone:focus-within { border-color: #94a3b8; }
    .doc-zone::after { content: attr(data-zone); position: absolute; right: 2px; top: -10px; padding: 0 6px; border-radius: 4px; background: #64748b; color: #fff; font: 900 9px/16px 'Plus Jakarta Sans', sans-serif; letter-spacing: 0.1em; text-transform: uppercase; opacity: 0; transition: opacity 0.15s; }
    .doc-zone:hover::after, .doc-zone:focus-within::after { opacity: 1; }
    .doc-zone .doc-part { min-height: 8mm; }
    .doc-page p { margin: 0 0 6pt; }
    .doc-page h1 { font-size: 20pt; font-weight: bold; margin: 0 0 8pt; } .doc-page h2 { font-size: 16pt; font-weight: bold; margin: 0 0 6pt; }
    .doc-page h3 { font-size: 13pt; font-weight: bold; margin: 0 0 6pt; } .doc-page h4, .doc-page h5, .doc-page h6 { font-size: 11pt; font-weight: bold; margin: 0 0 4pt; }
    .doc-page ul { list-style: disc; padding-left: 18pt; margin: 0 0 6pt; } .doc-page ol { list-style: decimal; padding-left: 18pt; margin: 0 0 6pt; }
    .doc-page strong, .doc-page b { font-weight: bold; } .doc-page em, .doc-page i { font-style: italic; } .doc-page u { text-decoration: underline; }
    .doc-page table { border-collapse: collapse; } .doc-page td, .doc-page th { padding: 2pt 4pt; vertical-align: top; }
    .doc-page table[border="1"] td, .doc-page table[border="1"] th { border: 0.75pt solid #000; }
    .doc-page img { display: inline; max-width: 100%; }
    .doc-page hr { border-top: 1px solid #000; margin: 6pt 0; }
    .doc-page .page-break, .doc-page .mce-pagebreak { display: block; width: 100%; height: 0; border: 0; border-top: 2px dashed #94a3b8; margin: 12pt 0; }
    .doc-page .sl-field { background: #dbeafe; color: #1d4ed8; border-bottom: 1.5px dashed #2563eb; padding: 0 3px; border-radius: 3px; font-family: Arial, Helvetica, sans-serif; font-size: 0.9em; white-space: nowrap; cursor: default; }
    .doc-page .sl-field[data-pdf-field] { background: #dcfce7; color: #15803d; border-bottom-color: #16a34a; }
    .doc-page .sl-field[data-sys] { background: #ede9fe; color: #6d28d9; border-bottom-color: #7c3aed; }
    .doc-page .sl-missing { background: #fee2e2; color: #b91c1c; border-bottom-color: #dc2626; }
    #docToolbar .tox-tinymce-inline { position: static !important; width: 100% !important; border: 0 !important; box-shadow: none !important; }
    .pd-side-tab { flex: 1; padding: 11px 8px; font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.12em; color: #64748b; border-bottom: 3px solid transparent; }
    .pd-side-tab.active { color: #0f172a; border-bottom-color: #dc2626; }
    .pd-add > summary { list-style: none; display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; border-radius: 12px; background: #fff; border: 1px solid #e2e8f0; font-size: 12px; font-weight: 900; color: #0f172a; cursor: pointer; }
    .pd-add > summary::-webkit-details-marker { display: none; }
    .pd-add[open] > summary i.chev { transform: rotate(180deg); }
    #pdPalette .fb-group > summary { padding: 8px 10px; font-size: 10px; }
    #pdPalette .fb-el { padding: 7px 10px; font-size: 12px; }
    /* what is selected in the line list, and what an earlier version changed, lit up on the PDF */
    .pd-hl-row { position: absolute; background: rgba(37, 99, 235, 0.10); outline: 2px solid rgba(37, 99, 235, 0.55); border-radius: 3px; pointer-events: none; }
    .pd-hl-part { position: absolute; background: rgba(37, 99, 235, 0.22); outline: 2.5px solid #2563eb; border-radius: 3px; pointer-events: none; animation: pdPulse 1.2s ease-out 1; }
    .pd-diff { position: absolute; background: rgba(245, 158, 11, 0.22); outline: 2px dashed #d97706; border-radius: 3px; pointer-events: none; }
    @keyframes pdPulse { from { box-shadow: 0 0 0 10px rgba(37, 99, 235, 0.35); } to { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); } }
    .pd-ver { padding: 12px; border-radius: 14px; background: #fff; border: 1.5px solid #e2e8f0; }
    .pd-ver.sel { border-color: #f59e0b; background: #fffbeb; }
    .pdf-row.sel { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
    .pdf-row.added { border-style: dashed; border-color: #a78bfa; }
    .pdf-updown { display: flex; flex-direction: column; justify-content: center; gap: 2px; flex-shrink: 0; margin-left: -6px; }
    .pdf-updown button { width: 22px; height: 18px; border-radius: 6px; color: #94a3b8; font-size: 10px; }
    .pdf-updown button:hover:not(:disabled) { background: #e2e8f0; color: #0f172a; }
    .pdf-updown button:disabled { opacity: 0.25; cursor: default; }
    .pdf-row-actions { display: flex; align-items: center; gap: 2px; flex-shrink: 0; }
    .pdf-page-chip { display: inline-block; margin: 14px 0 4px; padding: 4px 12px; border-radius: 999px; background: #0f172a; color: #fff; font-size: 10px; font-weight: 900; letter-spacing: 0.15em; text-transform: uppercase; }
    .pdf-empty-tile { flex: 1; padding: 14px; border-radius: 12px; border: 2px dashed #c4b5fd; color: #7c3aed; font-size: 12px; font-weight: 800; text-align: center; }

    /* ---- Form elements (left) ---- */
    .fb-group > summary { list-style: none; display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-radius: 12px; background: #f1f5f9; font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.1em; color: #334155; cursor: pointer; user-select: none; }
    .fb-group > summary::-webkit-details-marker { display: none; }
    .fb-group > summary i.chev { transition: transform 0.15s; color: #94a3b8; }
    .fb-group[open] > summary i.chev { transform: rotate(180deg); }
    .fb-el { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 12px; border-radius: 12px; background: #f8fafc; border: 1px solid #eef2f7; font-size: 13px; font-weight: 700; color: #334155; text-align: left; cursor: grab; }
    .fb-el:hover { background: #eff6ff; border-color: #bfdbfe; color: #1e3a8a; }
    .fb-el .dots { color: #cbd5e1; font-size: 12px; }
    .fb-el .ico { width: 18px; text-align: center; color: #2563eb; }
    .fb-el:disabled { opacity: 0.45; cursor: default; }
    html.dark .fb-row { background: #0f172a; border-color: #1e293b; }
    html.dark .fb-tile { background: #1e293b; border-color: #334155; }
    html.dark .fb-tile.sel { background: #172554; border-color: #3b82f6; }
    html.dark .fb-tile-title { color: #f1f5f9; }
    html.dark .fb-tile-btn:hover { background: #334155; color: #fff; }
    html.dark .fb-el, html.dark .fb-group > summary { background: #1e293b; border-color: #334155; color: #e2e8f0; }

    /* ---- the question being edited ---- */
    .q-edit { position: relative; margin: 0 0 12px; padding: 24px 20px 20px; border-radius: 18px; background: #fff; border: 2px solid #2563eb; border-left-width: 6px; box-shadow: 0 20px 40px -12px rgba(37, 99, 235, 0.25); }
    .q-edit .inline-input { width: 100%; background: transparent; border: none; border-bottom: 2px solid #e2e8f0; outline: none; padding: 6px 2px; transition: border-color 0.15s; }
    .q-edit .inline-input:focus { border-bottom-color: #2563eb; }
    .q-edit .opt-row input[type=text] { flex: 1; min-width: 0; background: transparent; border: none; border-bottom: 1px dashed transparent; outline: none; padding: 6px 2px; font-weight: 700; font-size: 14px; color: #334155; }
    .q-edit .opt-row input[type=text]:hover { border-bottom-color: #cbd5e1; }
    .q-edit .opt-row input[type=text]:focus { border-bottom: 2px solid #2563eb; }
    .switch { position: relative; width: 40px; height: 22px; border-radius: 999px; background: #cbd5e1; transition: background 0.15s; flex-shrink: 0; }
    .switch::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
    .switch.on { background: #2563eb; }
    .switch.on::after { transform: translateX(18px); }

    /* ---- PDF designer ---- */
    .pd-page { position: relative; margin: 0 auto 28px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.18); background: #fff; }
    .pd-page canvas { display: block; width: 100%; }
    .pd-overlay { position: absolute; inset: 0; touch-action: none; cursor: crosshair; }
    .pd-page-label { position: absolute; top: -22px; left: 0; font-size: 10px; font-weight: 900; letter-spacing: 0.1em; text-transform: uppercase; color: #94a3b8; }
    .pd-field { position: absolute; border: 1.5px dashed rgba(100, 116, 139, 0.55); background: rgba(148, 163, 184, 0.06); border-radius: 2px; cursor: pointer; }
    .pd-field:hover { background: rgba(37, 99, 235, 0.14); border-color: #2563eb; }
    .pd-field.auto { border-color: rgba(147, 51, 234, 0.55); background: rgba(147, 51, 234, 0.07); }
    .pd-field.used { border-style: solid; border-color: rgba(100, 116, 139, 0.5); background: repeating-linear-gradient(45deg, rgba(148,163,184,0.18) 0 4px, transparent 4px 8px); cursor: not-allowed; }
    .pd-field.mine { border: 2px solid #16a34a; background: rgba(22, 163, 74, 0.2); }
    .pd-field.sig { cursor: not-allowed; opacity: 0.45; }
    .pd-box { position: absolute; border: 2px solid #2563eb; background: rgba(37, 99, 235, 0.12); border-radius: 3px; cursor: pointer; }
    .pd-box span.pd-label { position: absolute; left: 0; bottom: 100%; margin-bottom: 2px; max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 1px 6px; border-radius: 4px; background: #2563eb; color: #fff; font-size: 9px; font-weight: 800; pointer-events: none; }
    .pd-box.mine { border-color: #16a34a; background: rgba(22, 163, 74, 0.16); cursor: move; z-index: 3; }
    .pd-box.mine span.pd-label { background: #16a34a; }
    .pd-handle { position: absolute; right: -6px; bottom: -6px; width: 12px; height: 12px; border-radius: 3px; background: #16a34a; border: 2px solid #fff; cursor: nwse-resize; }
    .pd-del { position: absolute; top: -10px; right: -10px; width: 20px; height: 20px; border-radius: 50%; background: #dc2626; color: #fff; font-size: 11px; line-height: 20px; text-align: center; border: 2px solid #fff; cursor: pointer; }
    .pd-ghost { position: absolute; border: 2px dashed #16a34a; background: rgba(22, 163, 74, 0.1); pointer-events: none; }
    .pd-tool.active { background: #0f172a; color: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.18); }
    .pd-tool:not(.active) { color: #475569; }
    .pd-tool:not(.active):hover { background: #fff; color: #0f172a; }
    .pd-edit.insert .pd-handle { background: #7c3aed; cursor: ns-resize; left: 50%; right: auto; bottom: -6px; transform: translateX(-50%); }
    .pd-edit.insert.column .pd-handle { cursor: ew-resize; left: auto; right: -6px; top: 50%; bottom: auto; transform: translateY(-50%); }
    .content-mode .pd-edit.insert.sel { cursor: move; z-index: auto; }   /* stays under its text, so "Change text" can click the text in a copied row */
    /* content edits: erased areas (white) and new / replaced text */
    .pd-edit { position: absolute; overflow: hidden; white-space: nowrap; color: #000; pointer-events: none; }
    .pd-edit.erase, .pd-edit.cover { background: #fff; }
    .content-mode .pd-edit.line { outline: 3px solid rgba(100, 116, 139, 0.25); }
    .content-mode .pd-edit { pointer-events: auto; cursor: pointer; }
    #pdPages .pd-edit:not(.insert) { pointer-events: auto; cursor: move; }   /* added words / lines / erased areas: drag them with any tool */
    #pdPages .pd-box { cursor: move; }
    /* the handle of an added line / column: drag it with any tool (the rest of the space stays free to draw or click in) */
    .pd-ins-grip { position: absolute; left: 2px; top: 50%; transform: translateY(-50%); z-index: 6; pointer-events: auto; cursor: grab; display: flex; align-items: center; justify-content: center;
        min-width: 16px; height: 18px; max-height: 100%; padding: 0 3px; border-radius: 5px; background: #7c3aed; color: #fff; font-size: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.25); opacity: 0.85; }
    .pd-ins-grip:hover { opacity: 1; }
    .pd-edit.insert.column .pd-ins-grip { left: 50%; top: 2px; transform: translateX(-50%); }
    .content-mode .pd-edit.insert .pd-insert-tag { left: 22px; }
    .content-mode .pd-edit.erase { outline: 1.5px dashed #dc2626; outline-offset: -1px; }
    .content-mode .pd-edit.text { outline: 1.5px dashed #f59e0b; outline-offset: -1px; }
    .content-mode .pd-edit.sel { outline: 2.5px solid #d97706; overflow: visible; z-index: 4; cursor: move; }
    .pd-edit .pd-handle { background: #d97706; }
    .pd-text { position: absolute; border-radius: 2px; cursor: pointer; }
    .pd-text:hover { background: rgba(37, 99, 235, 0.12); outline: 1px solid rgba(37, 99, 235, 0.6); }
    .pd-text.sel { background: rgba(37, 99, 235, 0.18); outline: 2px solid #2563eb; }
    .content-mode.tool-erase .pd-text, .content-mode.tool-addtext .pd-text, .content-mode.tool-row .pd-text, .content-mode.tool-column .pd-text { pointer-events: none; }
    .content-mode.tool-row, .content-mode.tool-column { cursor: crosshair; }
    /* inserted rows / columns: only outlined while editing content */
    .pd-edit.insert { background: transparent; }
    .content-mode .pd-edit.insert { outline: 1.5px dashed #7c3aed; outline-offset: -1px; background: rgba(124, 58, 237, 0.05); }
    .content-mode .pd-edit.insert.sel { outline: 2.5px solid #7c3aed; background: rgba(124, 58, 237, 0.1); cursor: pointer; }
    .pd-insert-tag { position: absolute; top: 2px; left: 4px; font: 900 9px/1.4 'Plus Jakarta Sans', sans-serif; letter-spacing: .08em; text-transform: uppercase; color: #6d28d9; background: rgba(255,255,255,.9); padding: 0 4px; border-radius: 4px; }
    .pd-break { position: absolute; left: 0; right: 0; border-top: 2px dashed #2563eb; z-index: 5; pointer-events: none; }
    .pd-break span { position: absolute; right: 8px; top: -11px; padding: 1px 8px; border-radius: 999px; background: #2563eb; color: #fff; font: 800 10px/18px 'Plus Jakarta Sans', sans-serif; white-space: nowrap; }
    .pd-cutnote { position: absolute; left: 8px; right: 8px; bottom: 8px; z-index: 6; padding: 6px 10px; border-radius: 10px; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; font: 700 11px/1.35 'Plus Jakarta Sans', sans-serif; pointer-events: none; }
    .content-mode.tool-addtext { cursor: text; }
    .pd-field.selected { outline: 3px solid #f59e0b; outline-offset: 1px; z-index: 2; }
    .pd-field.hid { border: 2px dashed #dc2626; background: repeating-linear-gradient(45deg, rgba(220, 38, 38, 0.14) 0 4px, transparent 4px 8px); display: flex; align-items: center; justify-content: center; }
    .pd-field.hid .pd-x { color: #dc2626; font-size: 10px; font-weight: 900; pointer-events: none; }
    .pd-field.ghost { border: 1.5px dotted #f59e0b; background: rgba(245, 158, 11, 0.05); }
    .pd-box.moved { border-color: #f59e0b; background: rgba(245, 158, 11, 0.14); }
    .pd-box.moved span.pd-label { background: #f59e0b; }
    .pd-box.moved.mine { border-color: #d97706; background: rgba(245, 158, 11, 0.24); }
    .pd-box.moved .pd-handle { background: #d97706; }

    #pdfMissingPanel > summary { list-style: none; }
    #pdfMissingPanel > summary::-webkit-details-marker { display: none; }

</style>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <!-- STICKY BAR -->
    <header class="sticky top-0 z-40 -mx-5 lg:-mx-10 -mt-5 lg:-mt-10 mb-8 px-5 lg:px-10 py-4 bg-white/90 backdrop-blur border-b border-slate-200 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div class="flex items-center gap-4 min-w-0">
            <a href="<?= htmlspecialchars($back_url) ?>" class="w-10 h-10 shrink-0 bg-white border border-slate-200 rounded-xl flex items-center justify-center text-slate-500 hover:text-slate-900 transition-all" title="Back">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="min-w-0">
                <p class="text-[10px] font-black text-blue-600 uppercase tracking-widest">Application Form Builder</p>
                <p class="text-xs text-slate-500 font-medium truncate">
                    <?php if (!empty($scholarship['FormUpdatedAt'])): ?>
                        Last saved <span id="fbSavedAt"><?= date('M j, Y g:i A', strtotime($scholarship['FormUpdatedAt'])) ?></span>
                    <?php else: ?>
                        <span id="fbSavedAt">Not customised yet</span>
                    <?php endif; ?>
                    <span id="fbDirty" class="hidden text-amber-600 font-black"> · <i class="fas fa-circle text-[6px] align-middle"></i> Unsaved changes</span>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button type="button" onclick="openFormHistory()" title="Earlier versions of this form" class="bg-white border border-slate-200 text-slate-700 hover:border-slate-900 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all"><i class="fas fa-clock-rotate-left"></i> History</button>
            <button type="button" id="fbRedoBtn" onclick="redoChange()" disabled title="Redo (Ctrl+Y)" class="order-last bg-white border border-slate-200 text-slate-700 hover:border-slate-900 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all disabled:opacity-40 disabled:cursor-not-allowed"><i class="fas fa-rotate-right"></i> Redo</button>
            <button type="button" id="fbUndoBtn" onclick="undoChange()" disabled title="Undo (Ctrl+Z)" class="bg-white border border-slate-200 text-slate-700 hover:border-slate-900 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all disabled:opacity-40 disabled:cursor-not-allowed"><i class="fas fa-rotate-left"></i> Undo</button>
            <button type="button" id="fbPreviewBtn" onclick="togglePreview()" class="bg-white border border-slate-200 text-slate-700 hover:border-slate-900 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all">
                <i class="fas fa-eye"></i> <span>Preview as student</span>
            </button>
            <button type="button" id="fbSaveBtn" onclick="saveForm()" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg disabled:opacity-50">
                <i class="fas fa-floppy-disk"></i> Save Form
            </button>
        </div>
    </header>

    <div class="max-w-7xl mx-auto">
        <div id="fbVersionBar" class="hidden mb-5 px-5 py-4 rounded-2xl bg-amber-50 border border-amber-300 text-amber-900 text-sm font-bold flex flex-wrap items-center gap-3"></div>
        <div id="fbAlert" class="hidden mb-5 px-5 py-4 rounded-2xl text-sm font-bold"></div>
        <?php if ($application_count > 0): ?>
            <p class="mb-4 text-xs font-bold text-amber-700"><i class="fas fa-circle-info"></i> <?= $application_count ?> application<?= $application_count === 1 ? ' has' : 's have' ?> already been submitted — removed fields are archived so those answers are kept.</p>
        <?php endif; ?>

        <div id="fbLayout" class="grid lg:grid-cols-[300px_minmax(0,1fr)] gap-6 items-start">
            <!-- FORM ELEMENTS: drag onto the form, or click to add after the selected field -->
            <aside id="fbPalette" class="lg:sticky lg:top-24 bg-white border border-slate-200 rounded-2xl shadow-sm lg:max-h-[calc(100vh-7.5rem)] lg:overflow-y-auto">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2.5">
                    <i class="fas fa-clipboard-list text-orange-500 text-lg"></i>
                    <h2 class="text-base font-black text-slate-900">Form elements</h2>
                </div>
                <p id="fbPaletteHint" class="px-5 pt-3 text-xs font-medium text-slate-500">Drag an element onto the form — between two rows, or beside a field to put them side by side. Or click it to add it after the selected field.</p>
                <div id="fbPaletteGroups" class="p-3 space-y-2"></div>
            </aside>

            <section class="min-w-0">
                <div class="flex bg-white border border-slate-200 rounded-2xl mb-5 overflow-hidden" role="tablist">
                    <button type="button" role="tab" data-fbtab="edit" onclick="showTab('edit')" class="fb-tabbtn active">Edit<span class="count" id="fbQuestionCount">0</span></button>
                    <button type="button" role="tab" data-fbtab="preview" onclick="showTab('preview')" class="fb-tabbtn">Preview</button>
                    <button type="button" role="tab" data-fbtab="pdf" onclick="showTab('pdf')" class="fb-tabbtn">PDF form<span class="count" id="fbPdfCount">–</span></button>
                </div>

                <!-- EDIT: the fields as rows -->
                <div id="fbTabEdit">
                    <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Application form</p>
                        <h2 class="text-xl lg:text-2xl font-black text-slate-900 tracking-tight">Apply for <?= htmlspecialchars($scholarship['Name']) ?></h2>
                        <textarea id="fbInstructions" rows="2" oninput="markDirty(); autoGrow(this);" placeholder="✏️ Instructions shown at the top of the form (optional)"
                            class="mt-3 w-full p-3 bg-blue-50 border border-blue-100 rounded-xl text-sm text-blue-900 font-medium outline-none resize-none focus:ring-4 focus:ring-blue-500/10 focus:border-blue-400 placeholder:text-blue-300"><?= htmlspecialchars($scholarship['FormInstructions'] ?? '') ?></textarea>
                    </div>
                    <div id="fbCanvas" class="space-y-3 min-h-[120px]"></div>
                    <button type="button" onclick="addElement(JSON.stringify({ type: 'Text', edit: true }), true)" class="w-full mt-3 py-4 rounded-2xl border-2 border-dashed border-blue-200 text-blue-600 hover:bg-blue-50 hover:border-blue-400 text-sm font-black uppercase tracking-widest transition-all">
                        <i class="fas fa-plus"></i> Add field
                    </button>
                    <p class="text-xs font-medium text-slate-400 mt-4"><i class="fas fa-lock"></i> Every form also asks for the student's GWA and the required documents (<?= count($builder_documents) ?>), which are set in the scholarship settings.</p>
                </div>

                <!-- PREVIEW: exactly what students see (mirrors student/apply_form.php) -->
                <div id="fbTabPreview" class="hidden">
                    <div class="bg-white p-6 lg:p-10 rounded-[2rem] border border-slate-200 shadow-sm space-y-8">
                        <div>
                            <h2 class="text-2xl lg:text-3xl font-black text-slate-900 tracking-tight leading-tight">Apply for <?= htmlspecialchars($scholarship['Name']) ?></h2>
                            <p class="text-slate-500 font-medium text-xs lg:text-sm mt-1">Complete the dynamic form and upload your requirements.</p>
                        </div>
                        <div class="space-y-4">
                            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Academic Profile</h3>
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Declared GWA / GPA</label>
                                <div class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl font-bold text-slate-400">1.50</div>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Application Questions</h3>
                            <div id="fbInstructionsBox" class="hidden p-4 bg-blue-50 border border-blue-100 rounded-xl text-sm text-blue-900 font-medium whitespace-pre-line"></div>
                            <div id="fbPreviewCanvas" class="grid sm:grid-cols-2 gap-x-4 gap-y-6"></div>
                        </div>
                        <div class="space-y-4">
                            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Required Documents</h3>
                            <?php if (empty($builder_documents)): ?>
                                <p class="text-xs text-slate-400 font-bold">No documents required.</p>
                            <?php endif; ?>
                            <?php foreach ($builder_documents as $doc): ?>
                                <div class="flex items-center justify-between p-4 border border-slate-200 rounded-xl bg-slate-50 gap-4">
                                    <div>
                                        <p class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($doc) ?></p>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-1">Pending Upload</p>
                                    </div>
                                    <span class="bg-blue-100 text-blue-700 px-4 py-2 rounded-lg text-xs font-black uppercase tracking-widest">From Vault</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex justify-end pt-2">
                            <span class="w-full sm:w-auto text-center bg-slate-900/90 text-white px-10 py-4 rounded-xl font-black text-sm uppercase tracking-widest cursor-not-allowed">Submit Application</span>
                        </div>
                    </div>
                </div>

        <!-- OFFICIAL PDF FORM: which template is printed, and which of its fields the questions fill -->
        <div id="fbTabPdf" class="hidden">
        <!-- Which form students print -->
        <div class="grid md:grid-cols-2 gap-3 mb-5" role="radiogroup" aria-label="The printed form">
            <button type="button" role="radio" data-formmode="pdf" onclick="setFormMode('pdf')" class="fb-mode">
                <i class="fas fa-file-pdf text-red-600"></i>
                <span><b>Official PDF form</b><small>Students' answers are printed on the official PDF. Edit it in the PDF designer.</small></span>
            </button>
            <button type="button" role="radio" data-formmode="document" onclick="setFormMode('document')" class="fb-mode">
                <i class="fas fa-file-word text-blue-700"></i>
                <span><b>Word-style document</b><small>Write the form like in Microsoft Word: text, tables, pictures, header and footer. It becomes the PDF.</small></span>
            </button>
        </div>
        <div id="fbModeDoc" class="hidden bg-white border border-slate-200 rounded-[1.5rem] p-6 shadow-sm flex flex-col md:flex-row md:items-center gap-5">
            <div class="w-14 h-14 shrink-0 rounded-2xl bg-blue-50 text-blue-700 flex items-center justify-center text-2xl"><i class="fas fa-file-word"></i></div>
            <div class="flex-1 min-w-0">
                <h3 class="text-lg font-black text-slate-900">Edit the document</h3>
                <p class="text-sm font-medium text-slate-500">It works like Word: type, change the font and size, add tables and pictures, and write the header and footer. <b>Insert answer</b> puts a student's answer in the text. Tip: copy the form from its Word file and paste it in.</p>
                <p id="fbDocInfo" class="text-xs font-bold text-slate-400 mt-1"></p>
            </div>
            <div class="flex flex-col gap-2 shrink-0">
                <button type="button" id="fbDocOpen" onclick="openDocEditor()" class="px-5 py-3 rounded-xl bg-blue-700 text-white text-[11px] font-black uppercase tracking-widest hover:bg-blue-800"><i class="fas fa-pen"></i> Open the document</button>
                <button type="button" onclick="convertPdfToDocument()" class="px-5 py-2.5 rounded-xl bg-white border border-blue-200 text-blue-700 text-[11px] font-black uppercase tracking-widest hover:bg-blue-50"><i class="fas fa-wand-magic-sparkles"></i> Copy the official PDF into it</button>
                <button type="button" onclick="previewDocument()" class="px-5 py-2.5 rounded-xl bg-white border border-slate-200 text-slate-700 text-[11px] font-black uppercase tracking-widest hover:border-slate-900"><i class="fas fa-eye"></i> Preview PDF</button>
            </div>
        </div>
        <div id="fbModePdf">
        <div class="bg-white border border-slate-200 rounded-[1.5rem] p-5 shadow-sm">
            <p class="text-sm font-bold text-slate-600 mb-4">Pick the official PDF students' answers are printed on. Below, the form is read line by line so you can change it.</p>
            <div class="flex flex-col md:flex-row md:items-center gap-4">
                <div class="w-11 h-11 shrink-0 rounded-xl bg-red-50 text-red-600 flex items-center justify-center text-lg"><i class="fas fa-file-pdf"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Official PDF form <span class="normal-case tracking-normal font-bold">(from the /forms folder)</span></p>
                    <select id="fbTemplate" onchange="changeTemplate(this.value)" class="mt-1 w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold outline-none focus:border-blue-500 cursor-pointer">
                        <option value="">Automatic — <?= htmlspecialchars($pdf_templates[$default_template]['label'] ?? $default_template) ?></option>
                        <?php foreach ($pdf_templates as $file => $t): ?>
                            <option value="<?= htmlspecialchars($file) ?>" <?= $file === $chosen_template ? 'selected' : '' ?>><?= htmlspecialchars($t['label']) ?><?= $t['custom'] ? ' (uploaded)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-xs font-black">
                        <button type="button" onclick="toggleUploadPanel()" class="text-blue-600 hover:underline"><i class="fas fa-upload"></i> Upload your own PDF form</button>
                        <button type="button" id="fbDeleteTemplate" onclick="deleteUploadedTemplate()" class="hidden text-red-600 hover:underline"><i class="fas fa-trash-can"></i> Delete this uploaded form</button>
                    </div>
                    <!-- Upload your own PDF form -->
                    <div id="fbUploadPanel" class="hidden mt-3 p-4 rounded-xl bg-blue-50 border border-blue-100 space-y-3">
                        <div class="grid sm:grid-cols-2 gap-3">
                            <input type="text" id="fbUploadLabel" maxlength="150" placeholder="Form name, e.g. DOST Application Form 2026" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold outline-none focus:border-blue-500">
                            <input type="file" id="fbUploadFile" accept="application/pdf,.pdf" class="text-xs font-bold text-slate-600 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-blue-600 file:text-white file:font-black">
                        </div>
                        <p class="text-[11px] text-slate-600 leading-relaxed">
                            <strong>Any PDF works</strong> (10 MB max). A <strong>fillable PDF</strong> keeps its own fields. A <strong>plain or scanned PDF</strong> works too: open the PDF designer and draw boxes where the answers go. Only you (and the Super Admin) will see the forms you upload.
                        </p>
                        <div class="flex gap-2">
                            <button type="button" id="fbUploadBtn" onclick="uploadTemplate()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-xs font-black uppercase tracking-widest hover:bg-blue-700 disabled:opacity-50"><i class="fas fa-upload"></i> Upload &amp; use for this scholarship</button>
                            <button type="button" onclick="toggleUploadPanel()" class="px-4 py-2 rounded-lg text-xs font-black text-slate-500 hover:bg-white">Cancel</button>
                        </div>
                    </div>
                </div>
                <button type="button" onclick="openPdfDesigner()" class="shrink-0 self-stretch md:self-end bg-red-600 text-white px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-red-700 transition-all shadow-md">
                    <i class="fas fa-pen-ruler"></i> Open PDF designer
                </button>
                <div class="md:w-56 shrink-0">
                    <div class="flex items-center justify-between text-xs font-black mb-1.5">
                        <span class="text-slate-500 uppercase tracking-widest">PDF fields filled</span>
                        <span id="pdfCoverageText" class="text-slate-900"></span>
                    </div>
                    <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden"><div id="pdfCoverageBar" class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-green-600 transition-all"></div></div>
                </div>
            </div>
            <details id="pdfMissingPanel" class="mt-4 group">
                <summary class="cursor-pointer text-sm font-black text-blue-600 select-none"><i class="fas fa-chevron-right text-xs transition-transform group-open:rotate-90"></i> <span id="pdfMissingSummary"></span></summary>
                <p class="text-xs text-slate-500 mt-3 mb-2">These fields on the PDF are still blank. Click one to add a question that fills it. Fields marked ☑ are checkboxes on the PDF and become a Yes / No question.</p>
                <div id="pdfMissingList" class="flex flex-wrap gap-2"></div>
            </details>
        </div>

        <!-- The PDF is edited in the PDF designer: the form line by line beside the real page, and its history -->
        <div class="mt-5 bg-white border border-slate-200 rounded-[1.5rem] p-6 shadow-sm flex flex-col md:flex-row md:items-center gap-5">
            <div class="w-14 h-14 shrink-0 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center text-2xl"><i class="fas fa-pen-ruler"></i></div>
            <div class="flex-1 min-w-0">
                <h3 class="text-lg font-black text-slate-900">Edit the PDF form</h3>
                <p class="text-sm font-medium text-slate-500">The PDF designer shows the form line by line beside the real page. Click a line and it lights up on the PDF; change its words or blanks, move it, copy it or add new lines and questions. <b>History</b> brings back an earlier version and shows what is different.</p>
            </div>
            <div class="flex flex-col gap-2 shrink-0">
                <button type="button" onclick="openPdfDesigner()" class="px-5 py-3 rounded-xl bg-red-600 text-white text-[11px] font-black uppercase tracking-widest hover:bg-red-700"><i class="fas fa-pen-ruler"></i> Open the PDF designer</button>
                <button type="button" onclick="openFormHistory()" class="px-5 py-2.5 rounded-xl bg-white border border-slate-200 text-slate-700 text-[11px] font-black uppercase tracking-widest hover:border-slate-900"><i class="fas fa-clock-rotate-left"></i> History</button>
            </div>
        </div>
        </div>
        </div>

            </section>
        </div>
    </div>
</main>

<!-- EDIT ONE FIELD (its pencil) -->
<div id="fieldEditor" class="hidden fixed inset-0 z-[9990] bg-slate-900/50 backdrop-blur-sm items-start justify-center p-4 overflow-y-auto" role="dialog" aria-modal="true" aria-label="Edit field" onmousedown="if (event.target === this) closeEditor()">
    <div id="fieldEditorBody" class="w-full max-w-2xl mt-10 mb-10"></div>
</div>
<div id="fbDropMark" class="hidden"></div>
<div id="pdGapMenu" class="hidden" role="menu" aria-label="Add to the PDF here"></div>

<!-- EDIT ONE PART OF THE PDF (its pencil on the PDF form tab) -->
<div id="pdfTileEditor" class="hidden fixed inset-0 z-[9999] bg-slate-900/50 backdrop-blur-sm items-start justify-center p-4 overflow-y-auto" role="dialog" aria-modal="true" aria-label="Edit this part of the PDF" onmousedown="if (event.target === this) closePdfTile(true)">
    <div id="pdfTileEditorBody" class="w-full max-w-xl mt-16 mb-10 bg-white rounded-2xl shadow-2xl p-6"></div>
</div>

<!-- PDF DESIGNER: the real PDF with its fields and the boxes admins draw -->
<div id="pdfDesigner" class="hidden fixed inset-0 z-[9998] bg-slate-900/80 backdrop-blur-sm flex flex-col">
    <div class="bg-white px-4 lg:px-6 py-3 flex flex-wrap items-center justify-between gap-3 shadow-lg">
        <div class="min-w-0">
            <p class="text-[10px] font-black text-red-600 uppercase tracking-widest"><i class="fas fa-file-pdf"></i> PDF Designer</p>
            <p id="pdTitle" class="text-sm font-black text-slate-900 truncate"></p>
        </div>
        <div class="flex flex-wrap items-center justify-center gap-2 flex-1 min-w-0">
            <!-- Home: how text looks (what is being typed, or the text selected) -->
            <div id="pdRibbon" class="pd-ribbon" role="toolbar" aria-label="Text">
                <select id="rbFont" title="Font" onchange="ribbonSet('font', this.value)">
                    <option value="Helvetica" style="font-family:Arial,Helvetica,sans-serif">Helvetica</option>
                    <option value="Times" style="font-family:'Times New Roman',Times,serif">Times</option>
                    <option value="Courier" style="font-family:'Courier New',monospace">Courier</option>
                </select>
                <select id="rbSize" title="Size" onchange="ribbonSet('size', Number(this.value))"></select>
                <span class="rb-sep"></span>
                <button type="button" class="rb" data-rb="bold" title="Bold (Ctrl+B)" onmousedown="event.preventDefault()" onclick="ribbonToggle('bold')"><b>B</b></button>
                <button type="button" class="rb" data-rb="italic" title="Italic (Ctrl+I)" onmousedown="event.preventDefault()" onclick="ribbonToggle('italic')"><i class="font-serif">I</i></button>
                <button type="button" class="rb" data-rb="underline" title="Underline (Ctrl+U)" onmousedown="event.preventDefault()" onclick="ribbonToggle('underline')"><u>U</u></button>
                <label class="rb rb-color" title="Text colour"><span class="font-black">A</span><span id="rbColorBar" class="rb-colorbar"></span><input type="color" id="rbColor" oninput="ribbonSet('color', this.value)"></label>
                <span class="rb-sep"></span>
                <button type="button" class="rb" data-rb-align="left" title="Align left" onmousedown="event.preventDefault()" onclick="ribbonSet('align', 'left')"><i class="fas fa-align-left"></i></button>
                <button type="button" class="rb" data-rb-align="center" title="Center" onmousedown="event.preventDefault()" onclick="ribbonSet('align', 'center')"><i class="fas fa-align-center"></i></button>
                <button type="button" class="rb" data-rb-align="right" title="Align right" onmousedown="event.preventDefault()" onclick="ribbonSet('align', 'right')"><i class="fas fa-align-right"></i></button>
                <span class="rb-sep"></span>
                <button type="button" class="rb rb-wide" title="Insert something under the selected line" onmousedown="event.preventDefault()" onclick="openInsertMenu(event)"><i class="fas fa-plus"></i> Insert <i class="fas fa-caret-down text-[9px]"></i></button>
            </div>
            <div class="inline-flex flex-wrap rounded-xl bg-slate-100 p-1 gap-0.5" role="toolbar" aria-label="PDF tools">
                <button type="button" data-tool="boxes" onclick="pickTool('boxes')" class="pd-tool px-3 py-2 rounded-lg text-[11px] font-black"><i class="fas fa-square-plus"></i> Answer boxes</button>
                <button type="button" data-tool="select" onclick="pickTool('select')" class="pd-tool px-3 py-2 rounded-lg text-[11px] font-black"><i class="fas fa-i-cursor"></i> Select &amp; type</button>
                <button type="button" data-tool="addtext" onclick="pickTool('addtext')" class="pd-tool px-3 py-2 rounded-lg text-[11px] font-black"><i class="fas fa-font"></i> Add text</button>
                <button type="button" data-tool="erase" onclick="pickTool('erase')" class="pd-tool px-3 py-2 rounded-lg text-[11px] font-black"><i class="fas fa-eraser"></i> Erase</button>
                <button type="button" data-tool="row" onclick="pickTool('row')" class="pd-tool px-3 py-2 rounded-lg text-[11px] font-black"><i class="fas fa-grip-lines"></i> Insert row</button>
                <button type="button" data-tool="column" onclick="pickTool('column')" class="pd-tool px-3 py-2 rounded-lg text-[11px] font-black"><i class="fas fa-grip-lines-vertical"></i> Insert column</button>
            </div>
            <button type="button" id="pdRedoBtn" onclick="redoChange()" disabled title="Redo (Ctrl+Y)" class="fb-undo order-last px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] font-black text-slate-700 hover:border-slate-900 disabled:opacity-40 disabled:cursor-not-allowed"><i class="fas fa-rotate-right"></i> Redo</button>
            <button type="button" id="pdUndoBtn" onclick="undoChange()" disabled title="Undo (Ctrl+Z)" class="fb-undo px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] font-black text-slate-700 hover:border-slate-900 disabled:opacity-40 disabled:cursor-not-allowed"><i class="fas fa-rotate-left"></i> Undo</button>
        </div>
        <button type="button" onclick="closePdfDesigner()" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600">Done</button>
    </div>
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row">
        <!-- LEFT: the form line by line (click one to see it on the PDF), and the earlier versions -->
        <aside id="pdLeft" class="lg:w-[400px] shrink-0 bg-slate-50 border-b lg:border-b-0 lg:border-r border-slate-200 flex flex-col min-h-0 max-h-[45vh] lg:max-h-none">
            <div class="flex bg-white border-b border-slate-200 shrink-0" role="tablist">
                <button type="button" data-pdside="lines" onclick="showDesignerSide('lines')" class="pd-side-tab active"><i class="fas fa-list"></i> Lines</button>
                <button type="button" data-pdside="history" onclick="showDesignerSide('history')" class="pd-side-tab"><i class="fas fa-clock-rotate-left"></i> History</button>
            </div>
            <div id="pdSideLines" class="flex-1 min-h-0 overflow-y-auto p-3">
                <details id="pdAddBox" class="pd-add mb-3">
                    <summary><span><i class="fas fa-plus"></i> Add to the PDF</span><i class="fas fa-chevron-down chev"></i></summary>
                    <p class="text-[11px] text-slate-500 px-1 pt-2">Click a line below, then an element: it goes on a new line under it. Or drag an element onto a line (next to what is there) or between two lines. Quicker: hover between two lines and press ＋.</p>
                    <div id="pdPalette" class="pt-2 space-y-2"></div>
                </details>
                <p class="text-[11px] font-medium text-slate-500 mb-2 px-1">Click a line or a part to light it up on the PDF. <b>✎</b> edits · <b>🗑</b> removes · hover <b>between two lines</b> and press <b>＋</b> to add something there · <b>⋮⋮</b> or the arrows move a line.</p>
                <div id="pdfRows"></div>
            </div>
            <div id="pdSideHistory" class="hidden flex-1 min-h-0 overflow-y-auto p-3">
                <p class="text-[11px] font-medium text-slate-500 mb-3 px-1">Every save is kept. Open one to see it on the PDF: what is different from your form now lights up. Restore it, or go back.</p>
                <div id="pdHistory" class="space-y-2"></div>
            </div>
        </aside>
        <div class="flex-1 min-h-0 flex flex-col">
            <div id="pdVersionBar" class="hidden shrink-0 px-6 py-2.5 bg-amber-300 text-amber-950 text-xs font-black flex flex-wrap items-center gap-3"></div>
            <div id="pdToast" class="hidden shrink-0 px-6 py-2 text-xs font-black"></div>
            <div id="pdHint" class="shrink-0 px-6 py-2.5 bg-slate-800 text-white text-xs font-bold flex items-center gap-2"></div>
            <div id="pdPages" class="flex-1 min-h-0 overflow-auto p-6 pt-8"></div>
        </div>
        <aside class="lg:w-80 shrink-0 bg-white border-t lg:border-t-0 lg:border-l border-slate-200 overflow-y-auto max-h-[45vh] lg:max-h-none">
            <div id="pdSelected" class="p-4 border-b border-slate-100"></div>
            <div id="pdQuestionsWrap" class="p-4 border-b border-slate-100">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Questions</p>
                <div id="pdQuestions" class="space-y-1"></div>
            </div>
            <div id="pdChangedWrap" class="p-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Changed PDF fields</p>
                <div id="pdChanged" class="space-y-1"></div>
            </div>
            <div id="pdEditsWrap" class="hidden p-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Content edits</p>
                <div id="pdEdits" class="space-y-1"></div>
            </div>
        </aside>
    </div>
</div>

<!-- WORD-STYLE DOCUMENT: TinyMCE (assets/lib/tinymce) on a page with a header and a footer -->
<div id="docEditor" class="hidden fixed inset-0 z-[9998] bg-slate-300 flex-col" role="dialog" aria-modal="true" aria-label="Word-style document">
    <div class="bg-white px-4 py-2.5 flex flex-wrap items-center gap-3 shadow-sm">
        <div class="min-w-0">
            <p class="text-[10px] font-black text-blue-700 uppercase tracking-widest"><i class="fas fa-file-word"></i> Word-style document</p>
            <p class="text-sm font-black text-slate-900 truncate">Application form · <?= htmlspecialchars($scholarship['Name']) ?></p>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest">Paper
                <select id="docPaper" onchange="setDocPaper(this.value)" class="ml-1 bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-bold normal-case tracking-normal">
                    <option value="A4">A4</option><option value="Letter">Letter (8.5 × 11 in)</option><option value="Legal">Legal / long (8.5 × 14 in)</option>
                </select>
            </label>
            <button type="button" onclick="convertPdfToDocument()" title="Make the document again from the official PDF" class="px-4 py-2 rounded-xl border border-blue-200 bg-white text-[11px] font-black uppercase tracking-widest text-blue-700 hover:bg-blue-50"><i class="fas fa-wand-magic-sparkles"></i> Copy the PDF in</button>
            <button type="button" onclick="previewDocument()" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-[11px] font-black uppercase tracking-widest text-slate-700 hover:border-slate-900"><i class="fas fa-eye"></i> Preview PDF</button>
            <button type="button" onclick="closeDocEditor()" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600">Done</button>
        </div>
    </div>
    <div id="docToolbar" class="bg-white border-y border-slate-200 min-h-[42px]"><p id="docToolbarHint" class="px-4 py-3 text-xs font-bold text-slate-500"><i class="fas fa-i-cursor"></i> Click in the page to type. The toolbar appears here.</p></div>
    <div id="docScroll" class="flex-1 min-h-0 overflow-auto py-8 px-4">
        <div id="docPage" class="doc-page">
            <div class="doc-zone" data-zone="Header"><div id="docHeader" class="doc-part"></div></div>
            <div id="docBody" class="doc-part doc-body"></div>
            <div class="doc-zone" data-zone="Footer"><div id="docFooter" class="doc-part"></div></div>
        </div>
    </div>
    <div class="bg-white border-t border-slate-200 px-4 py-1.5 text-[11px] font-medium text-slate-500 flex flex-wrap gap-x-5 gap-y-1">
        <span id="docStatus"></span>
        <span><b>Insert answer</b> (toolbar) puts a student's answer in the text · copy from a Word file and paste (Ctrl+V) to bring a form in · <b>Table</b> menu for tables · Ctrl+Z undoes</span>
    </div>
</div>

<!-- pdf.js renders the official PDF inside the designer -->
<script src="../assets/lib/pdfjs/pdf.min.js"></script>
<script>
    const FB_SCHOLARSHIP_ID =<?= (int) $scholarship['ScholarshipID'] ?>;
    const FB_TYPES = <?= json_encode($field_types) ?>;
    const FB_AUTO_TYPES = <?= json_encode($auto_types) ?>;
    const FB_PDF_TEMPLATES = <?= json_encode($pdf_templates) ?>;
    const FB_DEFAULT_TEMPLATE = <?= json_encode($default_template) ?>;
    let fbTemplate = <?= json_encode($chosen_template) ?>;
    // This scholarship's changes to the PDF's own fields: { name: { action: 'hide' } | { action: 'move', box } }
    let fbOverrides = <?= json_encode((object) $pdf_overrides) ?>;
    let fbFields = <?= json_encode($builder_fields) ?>;
    let fbEditingKey = null;
    let fbDirty = false;
    let fbKeySeq = 0;
    let fbSelectedKey = null;   // new fields go right after the selected one's row
    let fbTab = 'edit';
    fbFields.forEach(f => f.key = 'k' + (++fbKeySeq));
    // Edits to the PDF's printed content: [{ kind: 'erase'|'text'|'row'|'column', page, x, y, w, h, text, font, size, bold, cover }]
    // 'row' / 'column' insert a copy of the strip x,y,w,h (cover = copy its lines and labels; otherwise blank space), see layoutPieces()
    let fbEdits = <?= json_encode(array_values($pdf_edits)) ?>;
    let fbSavedBreaks = <?= json_encode($pdf_breaks) ?>;
    // Which form prints ('pdf' | 'document') and the Word-style document ({ body, header, footer, paper, header_mm, footer_mm })
    let fbFormMode = <?= json_encode($form_mode) ?>;
    let fbDoc = <?= json_encode($form_document) ?>;
    fbEdits.forEach(e => e.key = 'e' + (++fbKeySeq));

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const isAuto = type => type.startsWith('Auto_');
    const needsOptions = type => !!(FB_TYPES[type] && FB_TYPES[type].options);
    const typeLabel = type => isAuto(type) ? 'Auto-fill: ' + (FB_AUTO_TYPES[type] || type) : (FB_TYPES[type] ? FB_TYPES[type].label : type);
    const typeIcon = type => isAuto(type) ? 'fa-bolt' : (FB_TYPES[type] ? FB_TYPES[type].icon : 'fa-question');
    const getOpts = f => String(f.options || '').split('\n');
    const findIndex = key => fbFields.findIndex(f => f.key === key);
    const findField = key => fbFields.find(f => f.key === key);

    // Undo: the form as it was before each change (typing in one box counts as one change)
    let fbUndo = [], fbRedo = [], fbLastState = null, fbLastPush = 0;
    const fbState = () => JSON.stringify({ fields: fbFields, edits: fbEdits, overrides: fbOverrides });
    function markDirty() {
        fbDirty = true;
        document.getElementById('fbDirty').classList.remove('hidden');
        const now = fbState();
        if (fbLastState === null || now === fbLastState) return;
        fbRedo = [];   // a new change: nothing to redo
        if (Date.now() - fbLastPush > 800) {
            fbUndo.push(fbLastState);
            if (fbUndo.length > 100) fbUndo.shift();
        }
        fbLastPush = Date.now();
        fbLastState = now;
        updateUndoButtons();
    }
    function updateUndoButtons() {
        document.querySelectorAll('#fbUndoBtn, #pdUndoBtn').forEach(b => { b.disabled = !fbUndo.length; });
        document.querySelectorAll('#fbRedoBtn, #pdRedoBtn').forEach(b => { b.disabled = !fbRedo.length; });
    }
    function redoChange() {
        if (!fbRedo.length) return;
        fbUndo.push(fbLastState);
        restoreState(fbRedo.pop());
    }
    function undoChange() {
        if (!fbUndo.length) return;
        fbRedo.push(fbLastState);
        restoreState(fbUndo.pop());
    }
    function restoreState(previous) {
        if (typeof finishInline === 'function' && pdTile && pdTile.inline) { const el = pdTile.inline.el; if (el) el.remove(); pdTile = null; }
        const state = JSON.parse(previous);
        fbFields = state.fields;
        fbEdits = state.edits;
        fbOverrides = state.overrides;
        fbLastState = previous;
        fbLastPush = 0;
        pdSelField = pdSelEdit = pdSelText = null;
        if (fbEditingKey && !findField(fbEditingKey)) fbEditingKey = null;
        if (pdCurrentKey && !findField(pdCurrentKey)) pdCurrentKey = null;
        updateUndoButtons();
        render();
        if (!document.getElementById('pdfDesigner').classList.contains('hidden')) renderDesigner();
    }

    function autoGrow(el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }

    // ================= OFFICIAL PDF FORM =================
    const activeTemplate = () => FB_PDF_TEMPLATES[fbTemplate || FB_DEFAULT_TEMPLATE] || { fields: [], auto: [] };
    // "FatherOccupation" -> "Father Occupation", "Zip Code" stays
    const humanize = name => name.replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/\s+/g, ' ').trim();

    // ---------- Admins' own PDF forms ----------
    function toggleUploadPanel() {
        document.getElementById('fbUploadPanel').classList.toggle('hidden');
    }

    // "Delete this uploaded form" only for forms the admin uploaded
    function updateTemplateActions() {
        const t = FB_PDF_TEMPLATES[fbTemplate];
        document.getElementById('fbDeleteTemplate').classList.toggle('hidden', !(t && t.custom && t.mine));
    }

    async function uploadTemplate() {
        const file = document.getElementById('fbUploadFile').files[0];
        if (!file) { showAlert('error', 'Choose a PDF file to upload first.'); return; }
        const btn = document.getElementById('fbUploadBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        try {
            const body = new FormData();
            body.append('action', 'upload');
            body.append('form', file);
            body.append('label', document.getElementById('fbUploadLabel').value);
            const res = await fetch('../actions/upload_form_template.php', { method: 'POST', body });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Upload failed.');

            FB_PDF_TEMPLATES[data.file] = data.template;
            const select = document.getElementById('fbTemplate');
            select.add(new Option(data.template.label + ' (uploaded)', data.file));
            select.value = data.file;
            changeTemplate(data.file);
            if (fbTemplate !== data.file) return;   // switch was cancelled
            toggleUploadPanel();
            document.getElementById('fbUploadFile').value = '';
            document.getElementById('fbUploadLabel').value = '';
            showAlert('success', `"${data.template.label}" uploaded (${data.pages} page${data.pages === 1 ? '' : 's'}, ${data.template.fields.length} fillable field${data.template.fields.length === 1 ? '' : 's'}). It is now this scholarship's PDF form — click Save Form to keep it.`
                + (data.template.fields.length ? '' : ' It has no fillable fields, so open the PDF designer and draw boxes where the answers should go.'));
        } catch (err) {
            showAlert('error', err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-upload"></i> Upload &amp; use for this scholarship';
        }
    }

    async function deleteUploadedTemplate() {
        const file = fbTemplate;
        const t = FB_PDF_TEMPLATES[file];
        if (!t || !confirm(`Delete the uploaded form "${t.label}"?\n\nThis scholarship switches back to the automatic form. If other scholarships still use it, it won't be deleted.`)) return;

        // Stop using it here first (this scholarship's choice is saved with the form)
        fbOverrides = {};
        fbEdits = [];
        fbTemplate = '';
        document.getElementById('fbTemplate').value = '';
        render();
        await saveForm();

        const body = new FormData();
        body.append('action', 'delete');
        body.append('file', file);
        const res = await fetch('../actions/upload_form_template.php', { method: 'POST', body });
        const data = await res.json();
        if (!data.success) { showAlert('error', data.error); return; }
        delete FB_PDF_TEMPLATES[file];
        const option = [...document.getElementById('fbTemplate').options].find(o => o.value === file);
        if (option) option.remove();
        updateTemplateActions();
        showAlert('success', `"${t.label}" was deleted.`);
    }

    function changeTemplate(value) {
        // Hidden / moved PDF fields and content edits belong to the old PDF, so they don't carry over
        if ((Object.keys(fbOverrides).length || fbEdits.length) && !confirm('Switching the PDF form clears the fields you hid or moved and the content you edited on the current PDF. Continue?')) {
            document.getElementById('fbTemplate').value = fbTemplate;
            return;
        }
        fbOverrides = {};
        fbEdits = [];
        fbTemplate = value;
        markDirty();
        render();
    }

    // Which question (if any) fills each PDF field
    function pdfUsage() {
        const used = {};
        fbFields.forEach(f => { if (f.pdf && f.type !== 'Section') used[f.pdf] = f; });
        return used;
    }

    function renderPdfCoverage() {
        updateTemplateActions();
        const tpl = activeTemplate();
        const used = pdfUsage();
        const missing = tpl.fields.filter(p => !tpl.auto.includes(p.name) && !used[p.name] && !(fbOverrides[p.name] && fbOverrides[p.name].action === 'hide'));
        const filled = tpl.fields.length - missing.length;
        const pct = tpl.fields.length ? Math.round(filled / tpl.fields.length * 100) : 0;

        document.getElementById('pdfCoverageText').textContent = tpl.fields.length ? `${filled} / ${tpl.fields.length}` : 'n/a';
        document.getElementById('fbPdfCount').textContent = fbFormMode === 'document' ? 'Doc' : (tpl.fields.length ? `${filled}/${tpl.fields.length}` : '–');
        document.getElementById('pdfCoverageBar').style.width = pct + '%';
        document.getElementById('pdfMissingSummary').textContent = missing.length
            ? `${missing.length} PDF field${missing.length === 1 ? ' is' : 's are'} not filled yet — add questions for them`
            : 'Every field on this PDF is filled ✔';
        document.getElementById('pdfMissingList').innerHTML = missing.map(p =>
            `<button type="button" onclick="addQuestionForPdf(${esc(JSON.stringify(p.name))})" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-50 border border-slate-200 text-xs font-bold text-slate-700 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-700 transition-colors">
                ${p.type === 'Checkbox' ? '☑' : '<i class="fas fa-plus text-[9px]"></i>'} ${esc(p.name)}
            </button>`).join('');
    }

    function addQuestionForPdf(name) {
        const pdfField = activeTemplate().fields.find(p => p.name === name);
        showTab('edit');
        addField(pdfField && pdfField.type === 'Checkbox' ? 'YesNo' : 'Text', insertIndex());
        const f = findField(fbEditingKey);
        f.label = humanize(name);
        f.pdf = name;
        render();
        const input = document.getElementById('fb-label-' + f.key);
        if (input) { input.focus(); input.select(); }
    }

    // Dropdown of the template's real fields for one question
    function pdfFieldSelect(f) {
        const tpl = activeTemplate();
        const used = pdfUsage();
        const opt = (p, note, disabled) => `<option value="${esc(p.name)}" ${p.name === f.pdf ? 'selected' : ''} ${disabled ? 'disabled' : ''}>${p.type === 'Checkbox' ? '☑ ' : ''}${esc(p.name)}${note ? ' — ' + esc(note) : ''}</option>`;
        const free = [], taken = [], auto = [];
        tpl.fields.forEach(p => {
            const owner = used[p.name];
            if (owner && owner.key !== f.key) taken.push(opt(p, 'used by "' + (owner.label || 'another question') + '"', true));
            else if (tpl.auto.includes(p.name)) auto.push(opt(p, 'replaces the profile value', false));
            else free.push(opt(p, '', false));
        });
        const unknown = f.pdf && !tpl.fields.some(p => p.name === f.pdf)
            ? `<option value="${esc(f.pdf)}" selected>⚠️ ${esc(f.pdf)} — not in this PDF, pick another</option>` : '';
        return `<select onchange="updateField('${f.key}', 'pdf', this.value); findField('${f.key}').box = null; renderPdfCoverage();" class="mt-2 w-full sm:w-80 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs font-bold outline-none focus:border-blue-500 cursor-pointer">
            <option value="">— Not printed on the PDF —</option>${unknown}
            ${free.length ? `<optgroup label="Blank fields on the PDF">${free.join('')}</optgroup>` : ''}
            ${auto.length ? `<optgroup label="Filled automatically from the profile">${auto.join('')}</optgroup>` : ''}
            ${taken.length ? `<optgroup label="Already used by another question">${taken.join('')}</optgroup>` : ''}
        </select>`;
    }

    // ================= PDF DESIGNER =================
    // Shows the real PDF. Two modes:
    //  - Fields & questions: draw boxes for questions, link questions to the PDF's own fields,
    //    move / resize / delete the PDF's own fields.
    //  - Edit content: delete or replace printed text, erase any area, add new text.
    // Everything applies to this scholarship only; positions are fractions of the page (top-left origin).
    const PDFJS_WORKER = '../assets/lib/pdfjs/pdf.worker.min.js';
    // Single quotes inside: these go into style="..." attributes
    const PD_FONTS = { Helvetica: "Arial, Helvetica, sans-serif", Times: "'Times New Roman', Times, serif", Courier: "'Courier New', Courier, monospace" };
    let pdDocFile = null;
    let pdPdfDoc = null;         // the opened PDF (pdf.js)
    let pdPages = [];            // [{ num, overlay, canvas, source, scale, hPt, fields, texts (as laid out), fieldsOrig, textsOrig (on the untouched page), cut }]
    let pdMode = 'fields';       // 'fields' | 'content'
    let pdTool = 'select';       // content tool: 'select' | 'erase' | 'addtext' | 'row' | 'column'
    let pdCurrentKey = null;     // selected question
    let pdSelField = null;       // selected PDF field (by name)
    let pdSelEdit = null;        // selected content edit (by key)
    let pdSelText = null;        // selected printed text: { page, i }
    let pdLastQuestionKey = null;
    let pdDrag = null;

    const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
    const placeable = f => f && f.type !== 'Section';
    const findEdit = key => fbEdits.find(e => e.key === key);
    const pdfFieldInfo = name => {
        for (const p of pdPages) {
            const fl = p.fields.find(x => x.name === name);
            if (fl) return { ...fl, page: p.num };
        }
        return null;
    };

    async function openPdfDesigner(key = null) {
        pdCurrentKey = key;
        pdSelField = pdSelEdit = pdSelText = null;
        const modal = document.getElementById('pdfDesigner');
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        const file = fbTemplate || FB_DEFAULT_TEMPLATE;
        document.getElementById('pdTitle').textContent = (FB_PDF_TEMPLATES[file] || {}).label || file;
        setDesignerMode(key ? 'fields' : pdMode, false);
        if (pdDocFile !== file) { await loadPdfIntoDesigner(file); colorOldEdits(); }
        renderDesigner();
    }

    function closePdfDesigner() {
        finishInline(true);
        closePdfTile(true);
        document.getElementById('pdfDesigner').classList.add('hidden');
        document.body.style.overflow = '';
        render();
    }

    function setDesignerMode(mode, redraw = true) {
        pdMode = mode;
        pdSelField = pdSelEdit = pdSelText = null;
        const content = mode === 'content';
        ['pdQuestionsWrap', 'pdChangedWrap'].forEach(id => document.getElementById(id).classList.toggle('hidden', content));
        ['pdEditsWrap'].forEach(id => document.getElementById(id).classList.toggle('hidden', !content));
        setContentTool(pdTool, false);
        if (redraw) renderDesigner();
    }

    // One toolbar: "Answer boxes" is the fields mode, every other tool edits the printed content
    const PD_HINTS = {
        boxes: 'Drag on the form to draw a box where an answer prints. Click a dashed field to choose what fills it. Double-click any words to type in them. Drag anything you added to move it.',
        select: 'Click any words on the PDF and type, like in Word. Enter starts a new line · Esc cancels · the toolbar sets font, size, B / I / U, colour and alignment. Drag anything you added to move it.',
        addtext: 'Click where the new text should go, then type it in the panel on the right.',
        erase: 'Drag over anything (text, lines, a logo) to white it out.',
        row: 'Drag across the row you want to copy. The copy goes right under it and everything below moves down.',
        column: 'Drag down the table column you want to copy. The copy goes to its right.',
    };
    function pickTool(tool) {
        if (tool === 'boxes') { setDesignerMode('fields'); return; }
        if (pdMode !== 'content') setDesignerMode('content', false);
        setContentTool(tool);
    }
    function syncToolButtons() {
        const active = pdMode === 'content' ? pdTool : 'boxes';
        document.querySelectorAll('.pd-tool').forEach(b => b.classList.toggle('active', b.dataset.tool === active));
        const hint = document.getElementById('pdHint');
        if (hint) hint.innerHTML = `<i class="fas fa-hand-pointer text-emerald-300"></i> ${esc(PD_HINTS[active])} <span class="ml-auto text-slate-400 font-medium hidden md:inline">Ctrl+Z undoes</span>`;
    }

    function setContentTool(tool, redraw = true) {
        pdTool = tool;
        syncToolButtons();
        const holder = document.getElementById('pdPages');
        holder.classList.toggle('content-mode', pdMode === 'content');
        holder.classList.toggle('tool-erase', pdMode === 'content' && tool === 'erase');
        holder.classList.toggle('tool-addtext', pdMode === 'content' && tool === 'addtext');
        holder.classList.toggle('tool-row', pdMode === 'content' && tool === 'row');
        holder.classList.toggle('tool-column', pdMode === 'content' && tool === 'column');
        if (redraw) renderDesigner();
    }

    async function loadPdfIntoDesigner(file) {
        const holder = document.getElementById('pdPages');
        holder.innerHTML = '<p class="text-center text-white font-black py-20"><i class="fas fa-spinner fa-spin"></i> Loading the PDF…</p>';
        if (!window.pdfjsLib) {
            holder.innerHTML = '<p class="text-center text-white font-black py-20">The PDF viewer could not load. Check your internet connection and try again.</p>';
            return;
        }
        pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
        try {
            const doc = await pdfjsLib.getDocument('../forms/' + encodeURIComponent(file)).promise;
            pdPdfDoc = doc;
            holder.innerHTML = '';
            pdPages = [];
            const cssWidth = Math.min(880, (holder.clientWidth || 928) - 48);   // the designer may still be closed
            const ratio = window.devicePixelRatio || 1;

            for (let n = 1; n <= doc.numPages; n++) {
                const page = await doc.getPage(n);
                const viewport = page.getViewport({ scale: cssWidth / page.getViewport({ scale: 1 }).width });

                const wrap = document.createElement('div');
                wrap.className = 'pd-page';
                wrap.style.width = viewport.width + 'px';
                wrap.innerHTML = `<span class="pd-page-label">Page ${n} of ${doc.numPages}</span>`;
                const canvas = document.createElement('canvas');
                canvas.width = viewport.width * ratio;
                canvas.height = viewport.height * ratio;
                wrap.appendChild(canvas);
                const overlay = document.createElement('div');
                overlay.className = 'pd-overlay';
                wrap.appendChild(overlay);
                holder.appendChild(wrap);

                // The untouched page; the visible canvas is painted from it with the inserted rows / columns (paintPage)
                const source = document.createElement('canvas');
                source.width = canvas.width;
                source.height = canvas.height;
                await page.render({ canvasContext: source.getContext('2d'), viewport: page.getViewport({ scale: viewport.scale * ratio }) }).promise;

                // The PDF's own fillable fields, as fractions of the page
                const fields = (await page.getAnnotations())
                    .filter(a => a.subtype === 'Widget' && a.fieldName && !a.pushButton)
                    .map(a => {
                        const [x1, y1, x2, y2] = viewport.convertToViewportRectangle(a.rect);
                        return { name: a.fieldName, sig: a.fieldType === 'Sig', checkbox: !!a.checkBox,
                            x: Math.min(x1, x2) / viewport.width, y: Math.min(y1, y2) / viewport.height,
                            w: Math.abs(x2 - x1) / viewport.width, h: Math.abs(y2 - y1) / viewport.height };
                    });

                // The page's printed text, so it can be selected, deleted or replaced
                const content = await page.getTextContent();
                const texts = content.items.filter(it => it.str && it.str.trim() && it.width > 0).map(it => {
                    const tx = pdfjsLib.Util.transform(viewport.transform, it.transform);
                    const fontHeight = Math.hypot(tx[2], tx[3]);
                    // Real embedded font name (e.g. "ABCDEF+TimesNewRomanPS-BoldMT") when pdf.js has it, else the generic family
                    let fontName = '';
                    try { fontName = (page.commonObjs.get(it.fontName) || {}).name || ''; } catch (err) { /* font not loaded */ }
                    const family = (fontName + ' ' + ((content.styles[it.fontName] || {}).fontFamily || '')).toLowerCase();
                    return {
                        str: it.str,
                        x: tx[4] / viewport.width, y: (tx[5] - fontHeight * 0.95) / viewport.height,
                        w: (it.width * viewport.scale) / viewport.width, h: (fontHeight * 1.25) / viewport.height,
                        size: Math.round(fontHeight / viewport.scale * 2) / 2,
                        font: /courier|mono/.test(family) ? 'Courier' : (/times|georgia|garamond|cambria|book ?antiqua/.test(family) || (/serif/.test(family) && !/sans/.test(family)) ? 'Times' : 'Helvetica'),
                        bold: /bold|black|heavy/.test(fontName.toLowerCase()),
                        italic: /italic|oblique/.test(fontName.toLowerCase()),
                    };
                });

                pdPages.push({ num: n, overlay, canvas, source, fieldsOrig: fields, textsOrig: texts, fields, texts, cut: [], scale: viewport.scale, hPt: viewport.height / viewport.scale });
                paintPage(pdPages[pdPages.length - 1]);
                bindDesignerPage(pdPages[pdPages.length - 1]);
            }
            pdDocFile = file;
        } catch (err) {
            holder.innerHTML = `<p class="text-center text-white font-black py-20">Could not open the PDF: ${esc(err.message)}</p>`;
        }
    }

    // ---------- Inserted rows and columns ----------
    // The page is cut into pieces that keep their place or move. Inserts are applied in order, each in the page as it looked
    // after the earlier ones: a row pushes everything below its strip down by the strip's height; a column pushes what is
    // right of it (within the strip's height) to the right. With cover, the new space shows a copy of the strip.
    // actions/generate_application_pdf.php prints the same cut (pdfLayoutPieces in includes/pdf_templates.php).
    const isInsert = e => e.kind === 'row' || e.kind === 'column';
    // A moved line: the strip y..y+h is taken out and put back at 'to' (a y on the page as it was before the move)
    const isTransform = e => isInsert(e) || e.kind === 'move';
    const moveTop = m => m.to <= m.y ? m.to : m.to - m.h;   // where the strip ends up
    // Where a point at y ends up after the move
    function mapMove(y, m) {
        const a = m.y, b = m.y + m.h, d = m.to;
        if (y >= a && y < b) return y - a + moveTop(m);
        if (d <= a && y >= d && y < a) return y + m.h;
        if (d >= b && y >= b && y < d) return y - m.h;
        return y;
    }
    function applyMove(pieces, m) {
        let cut = pieces;
        [m.y, m.y + m.h, m.to].forEach(at => { cut = cut.flatMap(p => cutY(p, at).filter(Boolean)); });
        return cut.map(p => ({ ...p, dy: mapMove(p.dy + tallOf(p) / 2, m) - tallOf(p) / 2 }));
    }
    // A piece shows the page's sx, sy, w, h at dx, dy; a stretched piece (dh) is drawn dh tall: blank space is the page's line
    // just above it, stretched, so a box's sides or a table's columns carry on through it instead of breaking off
    const STRETCH_SOURCE = 0.0008;
    const tallOf = p => p.dh ?? p.h;
    function cutY(p, y) {
        const tall = tallOf(p);
        if (y <= p.dy + 1e-9) return [null, p];
        if (y >= p.dy + tall - 1e-9) return [p, null];
        const k = y - p.dy, r = p.h / tall;
        const a = { ...p, h: k * r }, b = { ...p, sy: p.sy + k * r, dy: y, h: (tall - k) * r };
        if (p.dh !== undefined) { a.dh = k; b.dh = tall - k; }
        return [a, b];
    }
    function cutX(p, x) {
        if (x <= p.dx + 1e-9) return [null, p];
        if (x >= p.dx + p.w - 1e-9) return [p, null];
        const k = x - p.dx;
        return [{ ...p, w: k }, { ...p, sx: p.sx + k, dx: x, w: p.w - k }];
    }
    function applyInsert(pieces, ins) {
        const out = [], copies = [];
        pieces.forEach(p => {
            if (ins.kind === 'row') {
                if (ins.cover) {
                    const from = cutY(p, ins.y)[1], strip = from && cutY(from, ins.y + ins.h)[0];
                    if (strip) copies.push({ ...strip, dy: strip.dy + ins.h, copy: true });
                } else {
                    // Blank space: the line just above it, stretched to fill it
                    const line = ins.y + ins.h, E = STRETCH_SOURCE;
                    const from = cutY(p, line - E)[1], strip = from && cutY(from, line)[0];
                    if (strip) copies.push({ ...strip, dy: line + (strip.dy - (line - E)) / E * ins.h, dh: tallOf(strip) / E * ins.h, copy: true });
                }
                const [above, below] = cutY(p, ins.y + ins.h);
                if (above) out.push(above);
                if (below) out.push({ ...below, dy: below.dy + ins.h });
                return;
            }
            const [above, rest] = cutY(p, ins.y);
            if (above) out.push(above);
            if (!rest) return;
            const [band, below] = cutY(rest, ins.y + ins.h);
            if (below) out.push(below);
            if (!band) return;
            if (ins.cover) {
                const from = cutX(band, ins.x)[1], strip = from && cutX(from, ins.x + ins.w)[0];
                if (strip) copies.push({ ...strip, dx: strip.dx + ins.w, copy: true });
            }
            const [left, right] = cutX(band, ins.x + ins.w);
            if (left) out.push(left);
            if (right) out.push({ ...right, dx: right.dx + ins.w });
        });
        return out.concat(copies);
    }
    function layoutPieces(pageNum) {
        return fbEdits.filter(e => e.page === pageNum && isTransform(e))
            .reduce((pieces, e) => e.kind === 'move' ? applyMove(pieces, e) : applyInsert(pieces, e), [{ sx: 0, sy: 0, w: 1, h: 1, dx: 0, dy: 0, copy: false }]);
    }

    // Draws the page with its inserts, and places its fields and text where they now are
    function paintPage(p) {
        const pieces = layoutPieces(p.num);
        // Content pushed past the bottom is not lost: the page is shown taller, and printing continues it on a new page
        p.maxY = Math.max(1, ...pieces.map(c => c.dy + tallOf(c)));
        if (p.maxY < 1.002) p.maxY = 1;
        const W = p.source.width, H = p.source.height;
        const tall = Math.round(H * p.maxY);
        if (p.canvas.height !== tall) p.canvas.height = tall;
        const ctx = p.canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, W, tall);
        pieces.forEach(c => ctx.drawImage(p.source, c.sx * W, c.sy * H, c.w * W, c.h * H, c.dx * W, c.dy * H, c.w * W, tallOf(c) * H));
        // By its middle: a line's box reaches a little above its letters, which may already be in the piece above
        const place = r => {
            const mx = r.x + Math.min(r.w, 0.004) / 2, my = r.y + r.h / 2;
            const c = pieces.find(c => !c.copy && mx >= c.sx - 1e-9 && mx < c.sx + c.w - 1e-9 && my >= c.sy - 1e-9 && my < c.sy + c.h - 1e-9);
            return c ? { ...r, x: r.x - c.sx + c.dx, y: r.y - c.sy + c.dy } : null;
        };
        const placedTexts = p.textsOrig.map(place);
        // Text repeated in copied rows / columns can be changed or deleted like any other text
        pieces.filter(c => c.copy).forEach(c => p.textsOrig.forEach(t => {
            const cx = t.x + t.w / 2, cy = t.y + t.h / 2;
            if (cx >= c.sx && cx < c.sx + c.w && cy >= c.sy && cy < c.sy + c.h) placedTexts.push({ ...t, x: t.x - c.sx + c.dx, y: t.y - c.sy + c.dy, copied: true });
        }));
        // Only a column can still push text off the page (to the right); rows continue on a new page
        p.cut = placedTexts.filter(t => t && t.x + t.w * 0.6 > 1).map(t => t.str);
        p.texts = placedTexts.filter(t => t && t.x < 1);
        p.fields = p.fieldsOrig.map(place).filter(f => f && f.x < 1);
        p.breakAt = p.maxY > 1 ? pageBreakAt(p) : null;
    }

    // Where the printed page ends when a page grew: above the first line of text that would reach the bottom margin, and
    // above the rest of its paragraph (lines closer than about half a line), so nothing is split. Everything below it is
    // printed on a new page right after this one.
    const PAGE_BOTTOM = 0.965, NEW_PAGE_TOP = 0.05, PDF_MAX_Y = 2;   // a page can grow to two pages (PDF_MAX_PAGE_HEIGHT)
    function pageBreakAt(p) {
        let at = Math.min(PAGE_BOTTOM, ...p.texts.filter(t => t.y + t.h > PAGE_BOTTOM).map(t => t.y - 0.004));
        for (let guard = 0; guard < 60; guard++) {
            const touching = p.texts.filter(t => t.y < at && t.y + t.h > at - 0.008);
            if (!touching.length) break;
            at = Math.min(...touching.map(t => t.y)) - 0.004;
        }
        return clamp(at, 0.25, PAGE_BOTTOM);
    }

    // Something placed in an insert's new space: its centre is inside it
    const insideArea = (r, page, a) => !!r && r.page === page && r.x + r.w / 2 >= a.x && r.x + r.w / 2 <= a.x + a.w && r.y + r.h / 2 >= a.y && r.y + r.h / 2 <= a.y + a.h;

    // Where an insert's new space is on the page
    function insertArea(e) {
        return e.kind === 'row' ? { x: 0, y: e.y + e.h, w: 1, h: e.h }
            : { x: e.x + e.w, y: e.y, w: Math.min(e.w, Math.max(0, 1 - e.x - e.w)), h: e.h };
    }

    // An area as it is moved by the inserts / moves that come after it in the list (each works on the page as the earlier left it)
    function carryArea(area, transforms) {
        const r = { ...area };
        transforms.forEach(t => {
            if (t.kind === 'row') { if (r.y >= t.y + t.h - 1e-4) r.y += t.h; }
            else if (t.kind === 'move') r.y = mapMove(r.y + r.h / 2, t) - r.h / 2;
            else if (r.x >= t.x + t.w - 1e-4 && r.y >= t.y - 1e-4 && r.y + r.h <= t.y + t.h + 1e-4) r.x += t.w;
        });
        return r;
    }
    // An insert's new space as seen by the edit at index 'upto' (all of the list: as the page looks now)
    function areaAt(ins, upto = fbEdits.length) {
        const i = fbEdits.indexOf(ins);
        return carryArea(insertArea(ins), i < 0 ? [] : fbEdits.slice(i + 1, upto).filter(t => isTransform(t) && t.page === ins.page));
    }
    const finalArea = ins => areaAt(ins);

    // Moves what was placed on the page (boxes, erased areas, text, moved fields) along with the content around an insert.
    // Those are where they look now, so they use the insert's space as it looks now; a later insert / move (movesInsert)
    // uses it as the page looked when that one was made.
    function shiftAroundInsert(ins, sign, movesInsert = () => false, skip = new Set()) {
        const size = ins.kind === 'row' ? ins.h : ins.w;
        const affected = (r, a) => r && r.page === ins.page && (ins.kind === 'row'
            ? r.y >= a.y - 1e-4
            : r.x >= a.x - 1e-4 && r.y >= a.y - 1e-4 && r.y + r.h <= a.y + a.h + 1e-4);
        const shift = r => {
            if (ins.kind === 'row') r.y = clamp(r.y + sign * size, 0, Math.max(0, PDF_MAX_Y - r.h));
            else r.x = clamp(r.x + sign * size, 0, Math.max(0, 1 - r.w));
        };
        const now = finalArea(ins);
        fbEdits.forEach(e => {
            if (e === ins || skip.has(e)) return;
            if (!isTransform(e)) { if (affected(e, now)) shift(e); return; }
            if (!movesInsert(e) || e.page !== ins.page) return;
            const a = areaAt(ins, fbEdits.indexOf(e));
            if (e.kind === 'move' && ins.kind === 'row' && e.to >= a.y - 1e-4) e.to += sign * size;
            if (affected(e, a)) shift(e);
        });
        fbFields.forEach(f => { if (f.box && !skip.has(f.box) && affected(f.box, now)) shift(f.box); });
        Object.values(fbOverrides).forEach(o => { if (o.box && !skip.has(o.box) && affected(o.box, now)) shift(o.box); });
    }

    // A dragged strip snaps to the printed lines near its edges, so a row is copied from border to border
    // (its top line is not repeated, its bottom line is) and a column from line to line within the table
    function snapToLines(page, drawn, kind) {
        const p = pdPages[page - 1], c = p.canvas, W = c.width, H = p.source.height, HC = c.height;
        const data = c.getContext('2d').getImageData(0, 0, W, HC).data;
        const dark = (x, y) => { const i = (y * W + x) * 4; return data[i] + data[i + 1] + data[i + 2] < 690; };   // anything clearly not white: thin lines are drawn faint
        // Lines across [a, b] (fractions), as [start, end] pixel runs along the other axis
        const lines = (horizontal, a, b, from, to) => {
            const [pa, pb] = horizontal ? [Math.round(a * W), Math.round(b * W)] : [Math.round(a * H), Math.round(b * H)];
            const runs = [];
            for (let v = Math.max(0, Math.round(from * (horizontal ? H : W))); v < Math.min(horizontal ? HC : W, Math.round(to * (horizontal ? H : W))); v++) {
                let n = 0, total = 0;
                for (let u = pa; u < pb; u += 2) { total++; if (horizontal ? dark(u, v) : dark(v, u)) n++; }
                if (total && n / total > 0.85) { const last = runs[runs.length - 1]; if (last && v - last[1] <= 2) last[1] = v; else runs.push([v, v]); }
            }
            // Printed lines are thin and nearly solid; anything thicker is text or a filled shape
            return runs.filter(r => r[1] - r[0] <= Math.max(4, Math.round((horizontal ? H : W) * 0.004)));
        };
        const gap = (r, at, size) => Math.abs((r[0] + r[1]) / 2 / size - at);
        const near = (runs, at, size) => runs.reduce((best, r) => !best || gap(r, at, size) < gap(best, at, size) ? r : best, null);
        const TOL = 0.025;   // how far from a drag edge a line may be (about 20px on screen)
        const out = { ...drawn };
        if (kind === 'row') {
            const runs = lines(true, Math.max(0, drawn.x), Math.min(1, drawn.x + Math.max(drawn.w, 0.05)), drawn.y - TOL, drawn.y + drawn.h + TOL);
            const t = near(runs, drawn.y, H), b = near(runs, drawn.y + drawn.h, H);
            if (t && b && b !== t) { out.y = (t[1] + 1) / H; out.h = (b[1] + 1) / H - out.y; }
            return out;
        }
        const rows = lines(true, drawn.x, drawn.x + drawn.w, drawn.y - TOL, drawn.y + drawn.h + TOL);
        const t = near(rows, drawn.y, H), b = near(rows, drawn.y + drawn.h, H);
        if (t && b && b !== t) { out.y = t[0] / H; out.h = (b[1] + 1) / H - out.y; }
        const cols = lines(false, out.y, out.y + out.h, drawn.x - TOL, drawn.x + drawn.w + TOL);
        const l = near(cols, drawn.x, W), r = near(cols, drawn.x + drawn.w, W);
        if (l && r && r !== l) { out.x = (l[1] + 1) / W; out.w = (r[1] + 1) / W - out.x; }
        return out;
    }

    // Changes an insert's strip (y/h for a row, x/w for a column): the page closes up around the old one and opens around
    // the new one, and whatever was placed inside its new space travels with it
    function setInsertGeometry(ins, change) {
        const index = fbEdits.indexOf(ins), later = e => fbEdits.indexOf(e) > index;
        const before = finalArea(ins);
        const within = r => insideArea(r, ins.page, before);
        const riders = [...fbEdits.filter(e => e !== ins && !isTransform(e)), ...fbFields.map(f => f.box), ...Object.values(fbOverrides).map(o => o.box)]
            .filter(within).map(r => ({ r, ox: r.x - before.x, oy: r.y - before.y }));
        const skip = new Set(riders.map(x => x.r));
        shiftAroundInsert(ins, -1, later, skip);
        Object.assign(ins, change);
        shiftAroundInsert(ins, 1, later, skip);
        const after = finalArea(ins);
        riders.forEach(({ r, ox, oy }) => { r.x = clamp(after.x + ox, 0, Math.max(0, 1 - r.w)); r.y = clamp(after.y + oy, 0, Math.max(0, 1 - r.h)); });
    }

    // Size / position buttons in the side panel (1 pt at a time)
    function nudgeInsert(key, prop, points) {
        const ins = findEdit(key), p = pdPages[ins.page - 1];
        const unit = points / (prop === 'h' || prop === 'y' ? p.hPt : p.hPt * p.source.width / p.source.height);
        const limits = { y: [0, 1 - ins.h], h: [0.006, 1 - ins.y], x: [0, 1 - ins.w], w: [0.01, 1 - ins.x] };
        setInsertGeometry(ins, { [prop]: clamp(ins[prop] + unit, ...limits[prop]) });
        markDirty();
        renderDesigner();
    }

    // Another copy right under (or beside) this one
    function addAnotherInsert(key) {
        const ins = findEdit(key), area = finalArea(ins), strip = { ...ins, ...carryArea({ x: ins.x, y: ins.y, w: ins.w, h: ins.h }, fbEdits.slice(fbEdits.indexOf(ins) + 1).filter(t => isTransform(t) && t.page === ins.page)) };
        const next = { ...ins, ...(ins.kind === 'row' ? { x: 0, y: area.y, w: 1, h: ins.h } : { x: area.x, y: strip.y }) };
        delete next.key;
        shiftAroundInsert(next, 1);
        pdSelText = null;
        addEdit(next);
    }

    function addInsert(kind, page, drawn) {
        const lineX = [drawn.x, Math.max(drawn.w, 0.05)];
        drawn = snapToLines(page, drawn, kind);
        const ins = kind === 'row'
            ? { kind, page, x: 0, y: drawn.y, w: 1, h: drawn.h, text: '', font: 'Helvetica', size: null, bold: false, cover: true, lineX }
            : { kind, page, x: drawn.x, y: drawn.y, w: drawn.w, h: drawn.h, text: '', font: 'Helvetica', size: null, bold: false, cover: true };
        shiftAroundInsert(ins, 1);
        pdSelText = null;
        addEdit(ins);
    }

    function deleteInsert(key) {
        const ins = findEdit(key);
        const index = fbEdits.indexOf(ins);
        const area = finalArea(ins);
        // Things placed inside the new row / column go with it
        const inside = r => insideArea(r, ins.page, area);
        const doomedEdits = fbEdits.filter(e => e !== ins && !isTransform(e) && inside(e));
        const doomedBoxes = fbFields.filter(f => f.box && inside(f.box));
        const count = doomedEdits.length + doomedBoxes.length;
        if (count && !confirm(`Remove this ${ins.kind}? ${count} thing(s) placed inside it will be removed too (question boxes are taken off the PDF; the questions stay on the form).`)) return;
        fbEdits = fbEdits.filter(e => !doomedEdits.includes(e));
        doomedBoxes.forEach(f => { f.box = null; });
        shiftAroundInsert(ins, -1, e => fbEdits.indexOf(e) > index);
        fbEdits = fbEdits.filter(e => e !== ins);
        if (pdSelEdit === key) pdSelEdit = null;
        pdSelText = null;
        markDirty();
        renderDesigner();
    }

    const designerOpen = () => !document.getElementById('pdfDesigner').classList.contains('hidden');
    // The PDF, the side panel, and the line list; text clicked on the PDF selects its line in the list
    function renderDesigner() {
        if (pdSelText && pdPages[pdSelText.page - 1]) {
            const t = pdPages[pdSelText.page - 1].texts[pdSelText.i];
            if (t) { pdSelRowAt = { page: pdSelText.page, y: t.y + t.h / 2 }; pdHlPart = null; pdScrollList = true; }
        }
        renderDesignerPages();
        renderDesignerPanel();
        renderPdfPalette();
        renderPdfRows();
        syncRibbon();
    }

    function renderDesignerPages() {
        const content = pdMode === 'content';
        const current = content || pdSelField ? null : findField(pdCurrentKey);
        const tpl = activeTemplate();
        const used = pdfUsage();
        const pct = v => (v * 100).toFixed(3) + '%';
        let pageTall = 1;   // set per page below: how many page heights the page shows
        const pos = r => `left:${pct(r.x)};top:${pct(r.y / pageTall)};width:${pct(r.w)};height:${pct(r.h / pageTall)}`;
        const handles = '<span class="pd-handle" title="Drag to resize"></span><span class="pd-del" title="Delete">×</span>';

        pdPages.forEach(p => {
            paintPage(p);
            pageTall = p.maxY;
            let html = p.cut.length ? `<div class="pd-cutnote">⚠ ${p.cut.length} piece(s) of printed text no longer fit on this page: “${esc(p.cut.slice(0, 2).join('”, “'))}”${p.cut.length > 2 ? '…' : ''}. Make the inserted column narrower, or remove it.</div>` : '';
            if (p.breakAt) html += `<div class="pd-break" style="top:${pct(p.breakAt / pageTall)}"><span>✂ A new page starts here when printed — everything below continues on it</span></div>`;

            // Content edits are always shown, so both modes look like the final PDF
            fbEdits.filter(e => e.page === p.num && e.kind !== 'move').forEach(e => {
                if (pdTile && pdTile.inline && pdTile.inline.hideKey === e.key) return;   // being typed over
                const sel = content && pdSelEdit === e.key;
                if (isInsert(e)) {
                    html += `<div class="pd-edit insert ${e.kind} ${sel ? 'sel' : ''}" data-edit="${e.key}" style="${pos(finalArea(e))}" title="Inserted ${e.kind}${sel ? ' — drag to move, drag the handle to resize' : ' — click to edit'}"><span class="pd-ins-grip" title="Drag to move this ${e.kind === 'row' ? 'line' : 'column'}"><i class="fas fa-grip-${e.kind === 'row' ? 'vertical' : 'lines'}"></i></span>${content ? `<span class="pd-insert-tag">＋ ${e.kind}</span>` : ''}${sel ? '<span class="pd-handle"></span>' : ''}</div>`;
                } else if (e.kind === 'line') {
                    html += `<div class="pd-edit line ${sel ? 'sel' : ''}" data-edit="${e.key}" style="${pos({ ...e, h: Math.max(e.h, 0.0015) })};background:${e.fill || '#000'}" title="Answer line"></div>`;
                } else if (e.kind === 'erase') {
                    html += `<div class="pd-edit erase ${sel ? 'sel' : ''}" data-edit="${e.key}" style="${pos(e)};background:${e.fill || '#fff'}" title="Erased area">${sel ? handles : ''}</div>`;
                } else {
                    const sizePt = e.size || Math.min(14, Math.max(6, e.h * p.hPt * 0.75));
                    const lines = e.text.includes('\n');   // a paragraph: its lines as they print
                    const boxPx = lines ? sizePt * 1.2 * p.scale : e.h * p.hPt * p.scale;
                    html += `<div class="pd-edit text ${e.cover ? 'cover' : ''} ${sel ? 'sel' : ''}" data-edit="${e.key}" title="Added text"
                        style="${pos(e)};${e.cover ? `background:${e.fill || '#fff'};` : ''}color:${e.color || '#000'};font-family:${PD_FONTS[e.font] || PD_FONTS.Helvetica};font-size:${(sizePt * p.scale).toFixed(2)}px;font-weight:${e.bold ? 700 : 400};font-style:${e.italic ? 'italic' : 'normal'};text-decoration:${e.underline ? 'underline' : 'none'};text-align:${e.align || 'left'};white-space:${lines ? 'pre' : 'nowrap'};line-height:${boxPx.toFixed(2)}px">${esc(e.text)}${sel ? handles : ''}</div>`;
                }
            });

            if (content) {
                // Printed text that can be clicked
                p.texts.forEach((t, i) => {
                    const sel = pdSelText && pdSelText.page === p.num && pdSelText.i === i;
                    html += `<div class="pd-text ${sel ? 'sel' : ''}" data-i="${i}" style="${pos(t)}" title="${esc(t.str)}"></div>`;
                });
                p.overlay.innerHTML = html + designerMarks(p, pos);
                return;
            }

            // The PDF's own fields
            html += p.fields.map(fl => {
                const owner = used[fl.name];
                const ov = fbOverrides[fl.name];
                let state = 'free', tip = `${fl.name} — click to edit`;
                if (fl.sig) { state = 'sig'; tip = `${fl.name} (signature — signed by hand)`; }
                else if (ov && ov.action === 'hide') { state = 'hid'; tip = `${fl.name} — deleted from this scholarship's PDF (click to restore)`; }
                else if (ov && ov.action === 'move') { state = 'ghost'; tip = `${fl.name} — moved (original spot)`; }
                else if (current && owner && owner.key === current.key) { state = 'mine'; tip = `${fl.name} — "${current.label}" prints here`; }
                else if (owner) { state = 'used'; tip = `${fl.name} — filled by "${owner.label}" (click to edit)`; }
                else if (tpl.auto.includes(fl.name)) { state = 'auto'; tip = `${fl.name} — filled from the student profile (click to edit)`; }
                const selected = pdSelField === fl.name && !(ov && ov.action === 'move') ? ' selected' : '';
                return `<div class="pd-field ${state}${selected}" data-field="${esc(fl.name)}" style="${pos(fl)}" title="${esc(tip)}">${state === 'hid' ? '<span class="pd-x">✕</span>' : ''}</div>`;
            }).join('');

            // The PDF's own fields that were moved for this scholarship
            Object.entries(fbOverrides).forEach(([name, ov]) => {
                if (ov.action !== 'move' || ov.box.page !== p.num) return;
                const sel = pdSelField === name;
                html += `<div class="pd-box moved ${sel ? 'mine' : ''}" data-field="${esc(name)}" style="${pos(ov.box)}" title="${esc(name)}${sel ? ' — drag to move' : ' — click to edit'}">
                    <span class="pd-label">${esc(name)}</span>${sel ? handles : ''}
                </div>`;
            });

            // Boxes drawn for questions
            fbFields.filter(f => f.box && f.box.page === p.num && placeable(f)).forEach(f => {
                const mine = current && f.key === current.key;
                html += `<div class="pd-box ${mine ? 'mine' : ''}" data-key="${f.key}" style="${pos(f.box)}" title="${esc(f.label)}${mine ? ' — drag to move' : ' — click to select'}">
                    <span class="pd-label">${esc(f.label || 'Untitled question')}</span>${mine ? handles : ''}
                </div>`;
            });
            p.overlay.innerHTML = html + designerMarks(p, pos);
        });
    }

    // The line / part selected in the list, and what an open earlier version changed
    function designerMarks(p, pos) {
        let html = '';
        const i = pdSelIndex(), row = i >= 0 ? pdRows[i] : null;
        if (row && row.page === p.num) html += `<div class="pd-hl-row" style="${pos({ x: 0.015, y: row.top + 0.001, w: 0.97, h: row.bot - row.top + 0.001 })}"></div>`;
        if (pdHlPart && pdHlPart.page === p.num) html += `<div class="pd-hl-part" style="${pos({ x: pdHlPart.x - 0.003, y: pdHlPart.y + 0.001, w: pdHlPart.w + 0.006, h: pdHlPart.h * 0.85 })}"></div>`;
        fbVersionDiff.filter(r => r.page === p.num).forEach(r => { html += `<div class="pd-diff" style="${pos({ x: r.x - 0.003, y: r.y - 0.002, w: r.w + 0.006, h: r.h + 0.004 })}" title="Different from your form"></div>`; });
        return html;
    }

    function renderDesignerPanel() {
        const sel = document.getElementById('pdSelected');
        if (pdMode === 'content') {
            sel.innerHTML = contentPanel();
            renderEditList();
            return;
        }
        if (pdSelField) {
            sel.innerHTML = pdfFieldPanel();
        } else {
            const current = findField(pdCurrentKey);
            if (!current) {
                sel.innerHTML = `<p class="text-sm font-bold text-slate-500">Nothing selected.</p><p class="text-xs text-slate-400 mt-1">Click a field on the PDF to edit or delete it, pick a question below, or drag on the PDF to add a new field.</p>`;
            } else {
                const where = current.box ? `▭ Drawn box on page ${current.box.page}` : (current.pdf ? `📄 PDF field “${esc(current.pdf)}”` : 'Not printed on the PDF yet');
                sel.innerHTML = `
                    <p class="text-[10px] font-black text-green-600 uppercase tracking-widest mb-1">Selected question</p>
                    <input type="text" value="${esc(current.label)}" maxlength="255" placeholder="Question label" oninput="renameInDesigner('${current.key}', this.value)"
                        class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-black outline-none focus:border-green-500" id="pdLabel">
                    <p class="text-xs font-bold text-slate-600 mt-2">${where}</p>
                    ${current.box ? sizeSelect(current.box.size, 'setBoxSize') : ''}
                    <div class="flex gap-2 mt-3">
                        ${current.box || current.pdf ? `<button type="button" onclick="removeFromPdf('${current.key}'); renderDesigner();" class="flex-1 px-3 py-2 rounded-lg bg-white border border-slate-200 text-xs font-black text-slate-700 hover:border-red-400 hover:text-red-600"><i class="fas fa-eraser"></i> Remove from PDF</button>` : ''}
                        <button type="button" onclick="deleteQuestionFromDesigner('${current.key}')" class="flex-1 px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-xs font-black text-red-600 hover:bg-red-600 hover:text-white"><i class="fas fa-trash-can"></i> Delete question</button>
                    </div>`;
            }
        }
        renderDesignerQuestionList();
    }

    function sizeSelect(size, handler, sizes = [6, 7, 8, 9, 10, 11, 12, 14, 16]) {
        return `<label class="flex items-center justify-between gap-2 mt-3 text-xs font-black text-slate-500 uppercase tracking-widest">Text size
            <select onchange="${handler}(this.value)" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-bold normal-case tracking-normal">
                <option value="">Auto (fit the box)</option>
                ${sizes.map(s => `<option value="${s}" ${size == s ? 'selected' : ''}>${s} pt</option>`).join('')}
            </select>
        </label>`;
    }

    // ---------- Edit content: side panel ----------
    function contentPanel() {
        const btn = 'px-3 py-2 rounded-lg text-xs font-black border';
        const edit = findEdit(pdSelEdit);
        if (edit && edit.kind === 'move') {
            return `<p class="text-[10px] font-black text-sky-700 uppercase tracking-widest mb-1">Moved line · page ${edit.page}</p>
                <p class="text-xs text-slate-500">A line was moved ${edit.to <= edit.y ? 'up' : 'down'} on the PDF form tab. Drag it there again to move it somewhere else.</p>
                <button type="button" onclick="putLineBack('${edit.key}')" class="mt-3 w-full ${btn} bg-white border-slate-200 text-slate-700 hover:border-slate-900"><i class="fas fa-rotate-left"></i> Put it back</button>`;
        }
        if (edit && isInsert(edit)) {
            const p = pdPages[edit.page - 1];
            return `<p class="text-[10px] font-black text-violet-700 uppercase tracking-widest mb-1">Inserted ${edit.kind} · page ${edit.page}</p>
                <p class="text-xs text-slate-500">${edit.kind === 'row' ? 'Everything below it moved down to make room.' : 'The cells to its right moved over to make room.'} Draw question boxes in it, or add text, like anywhere on the PDF.</p>
                <label class="flex items-start gap-2 mt-3 text-xs font-bold text-slate-600 cursor-pointer">
                    <input type="checkbox" ${edit.cover ? 'checked' : ''} onchange="updateEdit('cover', this.checked)" class="mt-0.5 accent-violet-600">
                    Copy the ${edit.kind}'s lines and labels (unticked: blank space). Students' answers are never copied.
                </label>
                <div class="grid grid-cols-2 gap-2 mt-3">
                    <div><p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">${edit.kind === 'row' ? 'Height' : 'Width'}</p>
                        <div class="flex items-center gap-1"><button type="button" onclick="nudgeInsert('${edit.key}', '${edit.kind === 'row' ? 'h' : 'w'}', -1)" class="w-8 h-8 rounded-lg border border-slate-200 font-black hover:bg-slate-100" aria-label="Smaller">−</button>
                        <span class="flex-1 text-center text-xs font-black">${Math.round((edit.kind === 'row' ? edit.h : edit.w) * (p ? (edit.kind === 'row' ? p.hPt : p.hPt * p.source.width / p.source.height) : 0))} pt</span>
                        <button type="button" onclick="nudgeInsert('${edit.key}', '${edit.kind === 'row' ? 'h' : 'w'}', 1)" class="w-8 h-8 rounded-lg border border-slate-200 font-black hover:bg-slate-100" aria-label="Bigger">+</button></div></div>
                    <div><p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Position</p>
                        <div class="flex items-center gap-1"><button type="button" onclick="nudgeInsert('${edit.key}', '${edit.kind === 'row' ? 'y' : 'x'}', -1)" class="flex-1 h-8 rounded-lg border border-slate-200 font-black hover:bg-slate-100" aria-label="Move ${edit.kind === 'row' ? 'up' : 'left'}">${edit.kind === 'row' ? '↑' : '←'}</button>
                        <button type="button" onclick="nudgeInsert('${edit.key}', '${edit.kind === 'row' ? 'y' : 'x'}', 1)" class="flex-1 h-8 rounded-lg border border-slate-200 font-black hover:bg-slate-100" aria-label="Move ${edit.kind === 'row' ? 'down' : 'right'}">${edit.kind === 'row' ? '↓' : '→'}</button></div></div>
                </div>
                <p class="text-[11px] text-slate-400 mt-2">Or drag it on the page; drag the purple handle to resize. To change the text in it, pick <b>Change text</b> and click the text.</p>
                <button type="button" onclick="addAnotherInsert('${edit.key}')" class="mt-3 w-full ${btn} bg-violet-50 border-violet-200 text-violet-700 hover:bg-violet-600 hover:text-white"><i class="fas fa-plus"></i> Add one more ${edit.kind}</button>
                ${p && p.cut.length ? `<p class="mt-3 p-2 rounded-lg bg-red-50 border border-red-100 text-xs font-bold text-red-700">⚠ Some printed text no longer fits on the right of the page. Make the inserted column narrower, or remove it.</p>` : ''}
                <button type="button" onclick="deleteInsert('${edit.key}')" class="mt-3 w-full ${btn} bg-red-50 border-red-200 text-red-600 hover:bg-red-600 hover:text-white"><i class="fas fa-trash-can"></i> Remove this ${edit.kind}</button>`;
        }
        if (edit && edit.kind === 'line') {
            return `<p class="text-[10px] font-black text-slate-600 uppercase tracking-widest mb-1">Answer line · page ${edit.page}</p>
                <p class="text-xs text-slate-500">The printed line of an answer blank. Drag to move it, drag the end to make it longer or shorter.</p>
                <button type="button" onclick="deleteEdit('${edit.key}')" class="mt-3 w-full ${btn} bg-red-50 border-red-200 text-red-600 hover:bg-red-600 hover:text-white"><i class="fas fa-trash-can"></i> Remove the line</button>`;
        }
        if (edit && edit.kind === 'erase') {
            return `<p class="text-[10px] font-black text-red-600 uppercase tracking-widest mb-1">Erased area</p>
                <p class="text-xs text-slate-500">Everything printed in this area is removed from this scholarship's PDF (page ${edit.page}). Drag to move it, drag the corner to resize.</p>
                <button type="button" onclick="deleteEdit('${edit.key}')" class="mt-3 w-full ${btn} bg-white border-slate-200 text-slate-700 hover:border-green-500 hover:text-green-700"><i class="fas fa-rotate-left"></i> Undo erase (show the content again)</button>`;
        }
        if (edit) {
            return `<p class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-1">Added text · page ${edit.page}</p>
                <textarea id="pdEditText" rows="2" oninput="updateEdit('text', this.value)" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold outline-none focus:border-amber-500">${esc(edit.text)}</textarea>
                <div class="grid grid-cols-2 gap-2 mt-2">
                    <select onchange="updateEdit('font', this.value)" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-xs font-bold">
                        ${Object.keys(PD_FONTS).map(f => `<option value="${f}" ${edit.font === f ? 'selected' : ''}>${f === 'Times' ? 'Times (serif)' : f}</option>`).join('')}
                    </select>
                    <label class="flex items-center justify-center gap-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-black cursor-pointer">
                        <input type="checkbox" ${edit.bold ? 'checked' : ''} onchange="updateEdit('bold', this.checked)" class="accent-amber-600"> Bold
                    </label>
                    <label class="flex items-center justify-center gap-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-black cursor-pointer py-2">
                        <input type="checkbox" ${edit.italic ? 'checked' : ''} onchange="updateEdit('italic', this.checked)" class="accent-amber-600"> <i>Italic</i>
                    </label>
                    <label class="flex items-center justify-center gap-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-black cursor-pointer">
                        Colour <input type="color" value="${esc(edit.color || '#000000')}" oninput="updateEdit('color', this.value)" class="w-8 h-6 cursor-pointer bg-transparent">
                    </label>
                </div>
                ${sizeSelect(edit.size, 'setEditSize', [6, 7, 8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 32])}
                <label class="flex items-start gap-2 mt-3 text-xs font-bold text-slate-600 cursor-pointer">
                    <input type="checkbox" ${edit.cover ? 'checked' : ''} onchange="updateEdit('cover', this.checked)" class="mt-0.5 accent-amber-600">
                    Cover what is printed underneath (in the colour behind it)
                </label>
                <button type="button" onclick="deleteEdit('${edit.key}')" class="mt-3 w-full ${btn} bg-red-50 border-red-200 text-red-600 hover:bg-red-600 hover:text-white"><i class="fas fa-trash-can"></i> Delete this text</button>`;
        }
        if (pdSelText) {
            const t = pdPages[pdSelText.page - 1].texts[pdSelText.i];
            return `<p class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-1">Printed text · page ${pdSelText.page}</p>
                <p class="p-3 rounded-lg bg-slate-50 border border-slate-200 text-sm font-bold text-slate-800 break-words">${esc(t.str)}</p>
                <button type="button" onclick="eraseSelectedText()" class="mt-3 w-full ${btn} bg-red-50 border-red-200 text-red-600 hover:bg-red-600 hover:text-white"><i class="fas fa-trash-can"></i> Delete this text</button>
                <label class="block mt-4 text-[10px] font-black text-slate-500 uppercase tracking-widest">Replace with</label>
                <textarea id="pdReplaceText" rows="2" class="mt-1 w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold outline-none focus:border-blue-500">${esc(t.str)}</textarea>
                <button type="button" onclick="replaceSelectedText(document.getElementById('pdReplaceText').value)" class="mt-2 w-full ${btn} bg-blue-600 border-blue-600 text-white hover:bg-blue-700"><i class="fas fa-pen"></i> Replace text</button>`;
        }
        const tips = { select: 'Click any printed text on the PDF to delete or replace it.', erase: 'Drag over anything on the PDF to erase it.', addtext: 'Click where the new text should go.',
            row: 'Drag across the row to copy, from its top line to its bottom line. The copy goes right below it.', column: 'Drag down the column to copy, from the top to the bottom of the table. The copy goes to its right.' };
        return `<p class="text-sm font-bold text-slate-500">Nothing selected.</p><p class="text-xs text-slate-400 mt-1">${tips[pdTool]}</p>`;
    }

    function renderEditList() {
        document.getElementById('pdEdits').innerHTML = fbEdits.length
            ? fbEdits.map(e => `<button type="button" onclick="selectEdit('${e.key}')" class="w-full text-left px-3 py-2 rounded-lg border ${pdSelEdit === e.key ? 'border-amber-500 bg-amber-50' : 'border-transparent hover:bg-slate-50'}">
                    <span class="block text-sm font-bold text-slate-800 truncate">${e.kind === 'move' ? 'Moved line' : e.kind === 'line' ? 'Answer line' : isInsert(e) ? `Inserted ${e.kind}` : e.kind === 'erase' ? 'Erased area' : esc(e.text || '(empty text)')}</span>
                    <span class="block text-[10px] font-black uppercase tracking-wider ${e.kind === 'move' ? 'text-sky-700' : isInsert(e) ? 'text-violet-700' : e.kind === 'erase' ? 'text-red-600' : 'text-amber-600'}">${e.kind === 'move' ? (e.to <= e.y ? '↑ Moved up' : '↓ Moved down') : e.kind === 'line' ? '＿ Line' : isInsert(e) ? (e.cover ? '⧉ Copy' : '▭ Blank space') : e.kind === 'erase' ? '✕ Erase' : (e.cover ? '✎ Replaced text' : '＋ Added text')} · page ${e.page}</span>
                </button>`).join('')
            : '<p class="text-xs text-slate-400">No content edits yet.</p>';
    }

    // ---------- Edit content: actions ----------
    function addEdit(edit) {
        colorEdit(edit);
        edit.key = 'e' + (++fbKeySeq);
        fbEdits.push(edit);
        markDirty();
        selectEdit(edit.key);
        return edit;
    }

    function selectEdit(key) {
        pdSelEdit = key;
        pdSelText = null;
        renderDesigner();
        const textBox = document.getElementById('pdEditText');
        if (textBox && findEdit(key).text === 'New text') { textBox.focus(); textBox.select(); }
    }

    function updateEdit(prop, value) {
        const edit = findEdit(pdSelEdit);
        if (!edit) return;
        edit[prop] = value;
        if (prop === 'cover' && value) colorEdit(edit);   // covering now: take the colour behind it
        markDirty();
        renderDesignerPages();   // keep the side panel (and the cursor in the text box) as it is
        renderEditList();
    }

    function setEditSize(value) { updateEdit('size', value === '' ? null : Number(value)); }

    function deleteEdit(key) {
        if (findEdit(key) && isInsert(findEdit(key))) { deleteInsert(key); return; }
        if (findEdit(key) && findEdit(key).kind === 'move') { putLineBack(key); return; }
        fbEdits = fbEdits.filter(e => e.key !== key);
        if (pdSelEdit === key) pdSelEdit = null;
        markDirty();
        renderDesigner();
    }

    // ---------- Colours ----------
    // Text is changed / erased by covering it, so the cover takes the colour that was behind it (a green header stays
    // green) and new words the colour the old ones had. Both are read from the page as it looks (the canvas has only the
    // PDF itself, not the edits drawn over it).
    const hex = ([r, g, b]) => '#' + [r, g, b].map(v => Math.round(v).toString(16).padStart(2, '0')).join('');
    const luma = ([r, g, b]) => 0.299 * r + 0.587 * g + 0.114 * b;
    function pageColors(page, r) {
        const p = pdPages[page - 1];
        if (!p) return null;
        paintPage(p);
        const c = p.canvas, W = c.width, H = p.source.height, M = 3;
        const x0 = clamp(Math.floor(r.x * W) - M, 0, W - 1), x1 = clamp(Math.ceil((r.x + r.w) * W) + M, 0, W - 1);
        const y0 = clamp(Math.floor(r.y * H) - M, 0, c.height - 1), y1 = clamp(Math.ceil((r.y + r.h) * H) + M, 0, c.height - 1);
        const w = x1 - x0 + 1, h = y1 - y0 + 1;
        if (w < 3 || h < 3) return null;
        const data = c.getContext('2d').getImageData(x0, y0, w, h).data;
        const at = (x, y) => { const i = (y * w + x) * 4; return [data[i], data[i + 1], data[i + 2]]; };
        // The most common colour (in coarse steps), averaged over the pixels of that step
        const common = list => {
            const groups = new Map();
            list.forEach(px => { const k = px.map(v => v >> 4).join(','); const g = groups.get(k) || []; g.push(px); groups.set(k, g); });
            let best = null;
            groups.forEach(g => { if (!best || g.length > best.length) best = g; });
            return best ? [0, 1, 2].map(i => best.reduce((s, px) => s + px[i], 0) / best.length) : null;
        };
        const ring = [];
        for (let x = 0; x < w; x++) for (const y of [0, 1, h - 2, h - 1]) ring.push(at(x, y));
        for (let y = 2; y < h - 2; y++) for (const x of [0, 1, w - 2, w - 1]) ring.push(at(x, y));
        const bg = common(ring);
        // The ink: the strongest quarter of the pixels that stand out from the background (letter edges are blended)
        const dist = px => Math.abs(px[0] - bg[0]) + Math.abs(px[1] - bg[1]) + Math.abs(px[2] - bg[2]);
        const inside = [];
        for (let y = M; y < h - M; y++) for (let x = M; x < w - M; x++) { const px = at(x, y); if (dist(px) > 120) inside.push(px); }
        inside.sort((a, b) => dist(b) - dist(a));
        const core = inside.slice(0, Math.max(1, Math.ceil(inside.length / 4)));
        let ink = inside.length ? [0, 1, 2].map(i => core.reduce((s, px) => s + px[i], 0) / core.length) : (luma(bg) < 140 ? [255, 255, 255] : [0, 0, 0]);
        // Nearly black / white is black / white
        if (ink.every(v => v < 72)) ink = [0, 0, 0];
        if (ink.every(v => v > 215)) ink = [255, 255, 255];
        return { fill: hex(bg), color: hex(ink) };
    }
    // Fills in an edit's colours (once): the background for an erase / a cover, the text colour for text
    // A cover over replaced words stops where other printed words start, above or below (a line's box is taller than its letters)
    function trimCover(e) {
        const p = pdPages[e.page - 1];
        if (!p) return;
        const others = p.texts.filter(t => t.x < e.x + e.w && t.x + t.w > e.x && !centreIn(t, e));
        others.forEach(t => {
            const top = t.y + t.h * 0.12, bottom = t.y + t.h * 0.78;   // roughly where its letters are
            if (top > e.y + e.h * 0.5 && top < e.y + e.h) e.h = Math.max(0.004, top - e.y);
            else if (bottom < e.y + e.h * 0.5 && bottom > e.y) { e.h -= bottom - e.y; e.y = bottom; }
        });
    }
    function colorEdit(e) {
        if (e && e.kind === 'text' && e.cover && !e.key) trimCover(e);
        if (e && e.kind === 'line' && !e.fill && pdPages.length) {
            const c = pageColors(e.page, { x: e.x, y: e.y - 0.004, w: e.w, h: 0.008 });
            e.fill = c && luma(c.fill.match(/\w\w/g).map(v => parseInt(v, 16))) < 140 ? '#ffffff' : '#000000';
            return;
        }
        if (!e || (e.kind !== 'erase' && e.kind !== 'text') || !pdPages.length) return;
        const needFill = (e.kind === 'erase' || e.cover) && !e.fill, needColor = e.kind === 'text' && !e.color;
        if (!needFill && !needColor) return;
        const c = pageColors(e.page, e);
        if (!c) return;
        if (needFill) e.fill = c.fill;
        if (needColor) {
            // New words where nothing was printed: black, or white on a dark background
            e.color = e.cover ? c.color : (luma(c.fill.match(/\w\w/g).map(v => parseInt(v, 16))) < 140 ? '#ffffff' : '#000000');
        }
    }
    // Edits saved before colours were kept: read them once the PDF is open (saved with the next Save form)
    function colorOldEdits() {
        const clean = !fbDirty;
        fbEdits.forEach(colorEdit);
        if (clean) fbLastState = fbState();
    }

    // Box around a piece of printed text, with a little margin
    function textArea(page, t) {
        const x = clamp(t.x - 0.002, 0, 1), y = clamp(t.y - 0.002, 0, 1);
        return { page, x, y, w: clamp(t.w + 0.004, 0.002, 1 - x), h: clamp(t.h + 0.004, 0.002, 1 - y) };
    }

    function eraseSelectedText() {
        const t = pdPages[pdSelText.page - 1].texts[pdSelText.i];
        addEdit({ kind: 'erase', ...textArea(pdSelText.page, t), text: '', font: 'Helvetica', size: null, bold: false, cover: false });
    }

    function replaceSelectedText(text) {
        if (!text.trim()) { eraseSelectedText(); return; }
        const t = pdPages[pdSelText.page - 1].texts[pdSelText.i];
        const area = textArea(pdSelText.page, t);
        // New wording may be longer than the old: give the box room to the right
        area.w = clamp(Math.max(area.w, area.w * text.length / Math.max(1, t.str.length) + 0.01), 0.002, 1 - area.x);
        addEdit({ kind: 'text', ...area, text: text.trim(), font: t.font, size: clamp(t.size, 5, 48), bold: t.bold, cover: true });
    }

    // ---------- the PDF's own fields (fields mode) ----------
    function pdfFieldPanel() {
        const name = pdSelField;
        const info = pdfFieldInfo(name) || {};
        const tpl = activeTemplate();
        const owner = pdfUsage()[name];
        const ov = fbOverrides[name];
        const isAuto = tpl.auto.includes(name);
        const hidden = ov && ov.action === 'hide';
        const moved = ov && ov.action === 'move';
        const state = hidden ? '<span class="text-red-600">Deleted from this scholarship\'s PDF</span>'
            : (moved ? '<span class="text-amber-600">Moved to a new spot</span>' : 'Original position');
        const last = findField(pdLastQuestionKey);
        const questionOptions = fbFields.filter(placeable).map(f =>
            `<option value="${f.key}" ${owner && owner.key === f.key ? 'selected' : ''}>${esc(f.label || 'Untitled question')}${f.box ? ' (has a drawn box)' : ''}</option>`).join('');
        const btn = 'px-3 py-2 rounded-lg text-xs font-black border';

        if (info.sig) {
            return `<p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">PDF field</p>
                <p class="font-black text-slate-900 break-all">${esc(name)}</p>
                <p class="text-xs text-slate-500 mt-2">This is a signature field. It's signed by hand on the printed form, so it can't be filled or changed here.</p>`;
        }

        return `
            <p class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-1">PDF field</p>
            <p class="font-black text-slate-900 break-all">${info.checkbox ? '☑ ' : ''}${esc(name)}</p>
            <p class="text-xs font-bold text-slate-500 mt-1">Page ${info.page || '?'} · ${state}</p>

            ${hidden ? '' : `
            <label class="block mt-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Filled with
                <select onchange="fillPdfFieldWith(this.value)" class="mt-1 w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-xs font-bold normal-case tracking-normal">
                    <option value="">${isAuto ? 'Student profile (automatic)' : '— Nothing —'}</option>
                    ${questionOptions}
                </select>
            </label>
            ${last && (!owner || owner.key !== last.key) ? `<button type="button" onclick="fillPdfFieldWith('${last.key}')" class="mt-2 w-full ${btn} bg-green-50 border-green-200 text-green-700 hover:bg-green-600 hover:text-white">Use for “${esc(last.label || 'Untitled question')}”</button>` : ''}
            ${moved ? sizeSelect(ov.box.size, 'setFieldSize') : ''}`}

            <div class="grid grid-cols-2 gap-2 mt-3">
                ${!ov ? `<button type="button" onclick="startMovingPdfField()" class="${btn} bg-white border-slate-200 text-slate-700 hover:border-amber-500 hover:text-amber-700"><i class="fas fa-up-down-left-right"></i> Move / resize</button>` : ''}
                ${moved ? `<button type="button" onclick="resetPdfField()" class="${btn} bg-white border-slate-200 text-slate-700 hover:border-slate-900"><i class="fas fa-rotate-left"></i> Reset position</button>` : ''}
                ${hidden
                    ? `<button type="button" onclick="resetPdfField()" class="col-span-2 ${btn} bg-white border-slate-200 text-slate-700 hover:border-green-500 hover:text-green-700"><i class="fas fa-eye"></i> Show again</button>`
                    : `<button type="button" onclick="hidePdfField()" class="${btn} bg-red-50 border-red-200 text-red-600 hover:bg-red-600 hover:text-white"><i class="fas fa-trash-can"></i> Delete from PDF</button>`}
            </div>
            <p class="text-[11px] text-slate-400 mt-3">Changes apply to this scholarship's PDF only. To change the printed words, use <strong>Edit content</strong>.</p>`;
    }

    // Renaming keeps the cursor in the box: only the box label and the list are redrawn
    function renameInDesigner(key, value) {
        updateField(key, 'label', value);
        const label = document.querySelector(`.pd-box[data-key="${key}"] .pd-label`);
        if (label) label.textContent = value || 'Untitled question';
        renderDesignerQuestionList();
    }

    function renderDesignerQuestionList() {
        document.getElementById('pdQuestions').innerHTML = fbFields.filter(placeable).map(f => {
            const status = f.box ? `<span class="text-emerald-600">▭ Box p.${f.box.page}</span>` : (f.pdf ? `<span class="text-blue-600">📄 ${esc(f.pdf)}</span>` : '<span class="text-slate-300">Not on PDF</span>');
            return `<button type="button" onclick="selectDesignerQuestion('${f.key}')" class="w-full text-left px-3 py-2 rounded-lg border ${!pdSelField && f.key === pdCurrentKey ? 'border-green-500 bg-green-50' : 'border-transparent hover:bg-slate-50'}">
                <span class="block text-sm font-bold text-slate-800 truncate">${esc(f.label) || 'Untitled question'}</span>
                <span class="block text-[10px] font-black uppercase tracking-wider truncate">${status}</span>
            </button>`;
        }).join('') || '<p class="text-xs text-slate-400">No questions yet — drag on the PDF to add one.</p>';

        // The PDF's own fields this scholarship changed
        const changed = Object.entries(fbOverrides);
        document.getElementById('pdChanged').innerHTML = changed.length
            ? changed.map(([name, ov]) => `<button type="button" onclick="selectPdfField(${esc(JSON.stringify(name))})" class="w-full text-left px-3 py-2 rounded-lg border ${pdSelField === name ? 'border-amber-500 bg-amber-50' : 'border-transparent hover:bg-slate-50'}">
                    <span class="block text-sm font-bold text-slate-800 truncate">${esc(name)}</span>
                    <span class="block text-[10px] font-black uppercase tracking-wider ${ov.action === 'hide' ? 'text-red-600' : 'text-amber-600'}">${ov.action === 'hide' ? '✕ Deleted' : '↔ Moved'}</span>
                </button>`).join('')
            : '<p class="text-xs text-slate-400">None yet. Click a field on the PDF to move or delete it.</p>';
    }

    function selectDesignerQuestion(key) {
        pdSelField = null;
        pdCurrentKey = key;
        renderDesigner();
        scrollDesignerTo(`.pd-box[data-key="${key}"]`);
    }

    function selectPdfField(name) {
        if (!pdSelField && pdCurrentKey) pdLastQuestionKey = pdCurrentKey;
        pdSelField = name;
        renderDesigner();
        scrollDesignerTo(`[data-field="${CSS.escape(name)}"]`);
    }

    // Scroll the PDF (not the page behind the designer)
    function scrollDesignerTo(selector) {
        const el = document.querySelector('#pdPages ' + selector);
        if (!el) return;
        const holder = document.getElementById('pdPages');
        const r = el.getBoundingClientRect(), hr = holder.getBoundingClientRect();
        if (r.top >= hr.top && r.bottom <= hr.bottom) return;
        holder.scrollTo({ top: Math.max(0, r.top - hr.top + holder.scrollTop - holder.clientHeight / 2), behavior: 'smooth' });
    }

    // ---- questions ----
    function removeFromPdf(key) {
        const f = findField(key);
        f.box = null;
        f.pdf = '';
        markDirty();
    }

    function deleteQuestionFromDesigner(key) {
        const f = findField(key);
        if (!confirm(`Delete the question "${f.label || 'Untitled question'}" from the form?` + (f.id ? '\n\nAnswers already submitted for it are kept with those applications.' : ''))) return;
        fbFields = fbFields.filter(x => x.key !== key);
        if (fbEditingKey === key) fbEditingKey = null;
        pdCurrentKey = null;
        markDirty();
        renderDesigner();
    }

    function setBoxSize(value) {
        const f = findField(pdCurrentKey);
        if (!f || !f.box) return;
        f.box.size = value === '' ? null : Number(value);
        markDirty();
    }

    // ---- the PDF's own fields ----
    function fillPdfFieldWith(key) {
        const name = pdSelField;
        const owner = pdfUsage()[name];
        if (owner) owner.pdf = '';
        if (key) {
            const f = findField(key);
            if (f.box && !confirm(`"${f.label}" already prints in a drawn box. Move it to "${name}" instead?`)) { renderDesigner(); return; }
            f.pdf = name;
            f.box = null;
        }
        markDirty();
        renderDesigner();
    }

    function startMovingPdfField() {
        const info = pdfFieldInfo(pdSelField);
        fbOverrides[pdSelField] = { action: 'move', box: { page: info.page, x: info.x, y: info.y, w: info.w, h: info.h, size: null } };
        markDirty();
        renderDesigner();
    }

    function hidePdfField() {
        const name = pdSelField;
        const owner = pdfUsage()[name];
        const why = activeTemplate().auto.includes(name) ? 'It is filled automatically with the student\'s profile data, which will no longer print.'
            : (owner ? `The answer to "${owner.label}" will no longer print (the question stays on the form).` : 'Nothing will print there.');
        if (!confirm(`Delete the field "${name}" from this scholarship's PDF?\n\n${why}`)) return;
        if (owner) owner.pdf = '';
        fbOverrides[name] = { action: 'hide' };
        markDirty();
        renderDesigner();
    }

    function resetPdfField() {
        delete fbOverrides[pdSelField];
        markDirty();
        renderDesigner();
    }

    function setFieldSize(value) {
        const ov = fbOverrides[pdSelField];
        if (!ov || ov.action !== 'move') return;
        ov.box.size = value === '' ? null : Number(value);
        markDirty();
    }

    // ---- mouse / touch on a page ----
    function bindDesignerPage(page) {
        const overlay = page.overlay;
        const toFraction = e => {
            const r = overlay.getBoundingClientRect();
            return { x: clamp((e.clientX - r.left) / r.width, 0, 1), y: clamp((e.clientY - r.top) / r.height * page.maxY, 0, page.maxY) };
        };
        const capture = e => {
            try { overlay.setPointerCapture(e.pointerId); } catch (err) { /* some pointers can't be captured; dragging still works inside the page */ }
            e.preventDefault();
        };

        overlay.addEventListener('pointerdown', e => {
            if (e.button !== 0) return;
            const point = toFraction(e);

            // ----- An added line / column: drag it by its handle, with any tool -----
            const grip = e.target.closest('.pd-ins-grip');
            const insEl = grip && grip.closest('.pd-edit');
            const ins = insEl && findEdit(insEl.dataset.edit);
            if (ins && isInsert(ins)) {
                pdSelEdit = pdMode === 'content' ? ins.key : pdSelEdit;
                pdDrag = { mode: 'insert-move', start: point, orig: { ...ins }, ref: ins, moved: false };
                capture(e);
                return;
            }

            // ----- Something added to the PDF (words, an answer line, an erased area): press and drag it, with any tool -----
            const grabbed = !e.target.closest('.pd-del') && e.target.closest('.pd-edit:not(.insert)');
            const grabbedEdit = grabbed && findEdit(grabbed.dataset.edit);
            if (grabbedEdit) {
                const wasSelected = pdMode === 'content' && pdSelEdit === grabbedEdit.key;
                // Its settings are under "Change text", so that tool is picked
                if (pdMode !== 'content') setDesignerMode('content', false);
                if (pdTool !== 'select') setContentTool('select', false);
                if (!wasSelected) { pdSelEdit = grabbedEdit.key; pdSelText = null; renderDesigner(); }
                pdDrag = { mode: wasSelected && e.target.closest('.pd-handle') ? 'resize' : 'move', start: point, orig: { ...grabbedEdit }, ref: grabbedEdit,
                    selector: `.pd-edit[data-edit="${grabbedEdit.key}"]`, moved: false, typeOnClick: wasSelected && grabbedEdit.kind === 'text' && !e.target.closest('.pd-handle') };
                capture(e);
                return;
            }

            // ----- Edit content mode -----
            if (pdMode === 'content') {
                if (e.target.closest('.pd-del')) { deleteEdit(pdSelEdit); return; }
                const editEl = e.target.closest('.pd-edit');
                if (editEl && isInsert(findEdit(editEl.dataset.edit))) {
                    const ins = findEdit(editEl.dataset.edit);
                    if (pdSelEdit !== ins.key) { selectEdit(ins.key); return; }
                    pdDrag = { mode: e.target.closest('.pd-handle') ? 'insert-resize' : 'insert-move', start: point, orig: { ...ins }, ref: ins, moved: false };
                    capture(e);
                    return;
                }
                if (editEl && !isInsert(findEdit(editEl.dataset.edit))) {
                    if (pdSelEdit !== editEl.dataset.edit) { selectEdit(editEl.dataset.edit); return; }
                    const edit = findEdit(pdSelEdit);
                    pdDrag = { mode: e.target.closest('.pd-handle') ? 'resize' : 'move', start: point, orig: { ...edit }, ref: edit, el: editEl, moved: false };
                } else if (pdTool === 'addtext') {
                    addEdit({ kind: 'text', page: page.num, x: clamp(point.x, 0, 0.97), y: clamp(point.y - 0.011, 0, page.maxY - 0.022),
                        w: clamp(0.3, 0.02, 1 - point.x), h: 0.022, text: 'New text', font: 'Helvetica', size: 10, bold: false, cover: false });
                    return;
                } else if (pdTool === 'erase') {
                    pdDrag = { mode: 'draw-erase', start: point, page: page.num, moved: false };
                } else if (pdTool === 'row' || pdTool === 'column') {
                    pdDrag = { mode: 'draw-insert', kind: pdTool, start: point, page: page.num, moved: false };
                } else {
                    const textEl = e.target.closest('.pd-text');
                    pdDrag = { mode: 'pick-text', start: point, page: page.num, i: textEl ? Number(textEl.dataset.i) : null, moved: false };
                }
                capture(e);
                return;
            }

            // ----- Fields & questions mode -----
            if (e.target.closest('.pd-del')) {
                if (pdSelField) hidePdfField();
                else { removeFromPdf(pdCurrentKey); renderDesigner(); }
                return;
            }

            const boxEl = e.target.closest('.pd-box');
            if (boxEl && boxEl.dataset.field !== undefined) {
                // A moved PDF field: drag it (it is selected on the way)
                const name = boxEl.dataset.field, wasSelected = pdSelField === name;
                if (!wasSelected) selectPdfField(name);
                const box = fbOverrides[name].box;
                pdDrag = { mode: wasSelected && e.target.closest('.pd-handle') ? 'resize' : 'move', start: point, orig: { ...box }, ref: box,
                    selector: `.pd-box[data-field="${CSS.escape(name)}"]`, moved: false };
            } else if (boxEl) {
                // A question's answer box: drag it (it is selected on the way); its printed answer line goes with it
                const key = boxEl.dataset.key, wasSelected = !pdSelField && key === pdCurrentKey;
                if (!wasSelected) selectDesignerQuestion(key);
                const box = findField(key).box;
                pdDrag = { mode: wasSelected && e.target.closest('.pd-handle') ? 'resize' : 'move', start: point, orig: { ...box }, ref: box,
                    selector: `.pd-box[data-key="${key}"]`, riders: answerLinesOf(box).map(l => ({ l, ox: l.x - box.x, oy: l.y - box.y })), moved: false };
            } else {
                const fieldEl = e.target.closest('.pd-field');
                pdDrag = { mode: 'draw', start: point, page: page.num, field: fieldEl ? fieldEl.dataset.field : null, moved: false };
            }
            capture(e);
        });

        overlay.addEventListener('pointermove', e => {
            if (!pdDrag) return;
            const point = toFraction(e);
            const dx = point.x - pdDrag.start.x, dy = point.y - pdDrag.start.y;
            if (Math.abs(dx) > 0.004 || Math.abs(dy) > 0.003) pdDrag.moved = true;
            if (!pdDrag.moved || pdDrag.mode === 'pick-text') return;

            if (pdDrag.mode === 'draw' || pdDrag.mode === 'draw-erase' || pdDrag.mode === 'draw-insert') {
                let ghost = overlay.querySelector('.pd-ghost');
                if (!ghost) { ghost = document.createElement('div'); ghost.className = 'pd-ghost'; overlay.appendChild(ghost); }
                if (pdDrag.mode === 'draw-erase') { ghost.style.borderColor = '#dc2626'; ghost.style.background = 'rgba(255,255,255,0.85)'; }
                if (pdDrag.mode === 'draw-insert') { ghost.style.borderColor = '#7c3aed'; ghost.style.background = 'rgba(124,58,237,0.12)'; }
                const rowStrip = pdDrag.mode === 'draw-insert' && pdDrag.kind === 'row';
                const x = rowStrip ? 0 : Math.min(point.x, pdDrag.start.x), y = Math.min(point.y, pdDrag.start.y);
                Object.assign(ghost.style, { left: x * 100 + '%', top: y / page.maxY * 100 + '%', width: (rowStrip ? 1 : Math.abs(dx)) * 100 + '%', height: Math.abs(dy) / page.maxY * 100 + '%' });
                return;
            }

            if (pdDrag.mode === 'insert-move' || pdDrag.mode === 'insert-resize') {
                const o = pdDrag.orig, row = o.kind === 'row';
                const change = pdDrag.mode === 'insert-move'
                    ? (row ? { y: clamp(o.y + dy, 0, 1 - o.h) } : { x: clamp(o.x + dx, 0, 1 - o.w) })
                    : (row ? { h: clamp(o.h + dy, 0.006, 1 - o.y) } : { w: clamp(o.w + dx, 0.01, 1 - o.x) });
                setInsertGeometry(pdDrag.ref, change);
                renderDesignerPages();
                return;
            }

            const b = pdDrag.orig, box = pdDrag.ref;
            if (pdDrag.mode === 'move') {
                box.x = clamp(b.x + dx, 0, 1 - b.w);
                box.y = clamp(b.y + dy, 0, page.maxY - b.h);
            } else {
                box.w = clamp(b.w + dx, 0.01, 1 - b.x);
                box.h = clamp(b.h + dy, box.kind === 'line' ? 0.0012 : 0.006, page.maxY - b.y);
            }
            if (pdDrag.riders && pdDrag.riders.length) {
                pdDrag.riders.forEach(({ l, ox, oy }) => {
                    if (pdDrag.mode === 'move') { l.x = box.x + ox; l.y = box.y + oy; }
                    else { l.x = box.x; l.w = box.w; l.y = box.y + box.h - 0.0007; }
                });
                renderDesignerPages();
                return;
            }
            const el = pdDrag.el && pdDrag.el.isConnected ? pdDrag.el : (pdDrag.el = pdDrag.selector ? overlay.querySelector(pdDrag.selector) : pdDrag.el);
            if (el) Object.assign(el.style, { left: box.x * 100 + '%', top: box.y / page.maxY * 100 + '%', width: box.w * 100 + '%', height: (box.kind === 'line' ? Math.max(box.h, 0.0015) : box.h) / page.maxY * 100 + '%' });
        });

        overlay.addEventListener('pointerup', e => {
            if (!pdDrag) return;
            const drag = pdDrag;
            pdDrag = null;
            const point = toFraction(e);
            const drawn = { page: drag.page, x: Math.min(point.x, drag.start.x), y: Math.min(point.y, drag.start.y),
                w: Math.abs(point.x - drag.start.x), h: Math.abs(point.y - drag.start.y) };

            if (drag.mode === 'pick-text') {
                pdSelEdit = null;
                // Clicking words puts the cursor in them
                const hit = pdTool === 'select' ? tileAt(drag.page, drag.start) : null;
                if (hit) { startInlineFromTile(hit.i, hit.j); return; }
                pdSelText = drag.i === null ? null : { page: drag.page, i: drag.i };
                renderDesigner();
                return;
            }

            if (drag.mode === 'draw-insert') {
                const tooSmall = drag.kind === 'row' ? drawn.h < 0.006 : drawn.w < 0.01 || drawn.h < 0.01;
                if (!drag.moved || tooSmall) { renderDesigner(); return; }
                addInsert(drag.kind, drag.page, drawn);
                return;
            }

            if (drag.mode === 'draw-erase') {
                if (!drag.moved || drawn.w < 0.005 || drawn.h < 0.004) { renderDesigner(); return; }
                addEdit({ kind: 'erase', ...drawn, text: '', font: 'Helvetica', size: null, bold: false, cover: false });
                return;
            }

            if (drag.mode === 'draw') {
                if (!drag.moved) {
                    // A click on one of the PDF's own fields selects it for editing
                    if (drag.field) selectPdfField(drag.field);
                    return;
                }
                if (drawn.w < 0.01 || drawn.h < 0.006) { renderDesigner(); return; }

                // Drawing always places a question: the selected one, or a new one
                pdSelField = null;
                let f = findField(pdCurrentKey);
                if (!f) {
                    addField('Text', fbFields.length);
                    f = findField(fbEditingKey);
                    f.label = 'New field';
                    pdCurrentKey = f.key;
                }
                f.box = { ...drawn, size: f.box ? f.box.size : null };
                f.pdf = '';
                markDirty();
                renderDesigner();
                const label = document.getElementById('pdLabel');
                if (label && f.label === 'New field') { label.focus(); label.select(); }
                return;
            }
            if (drag.mode === 'insert-move' || drag.mode === 'insert-resize') {
                if (drag.moved) markDirty();   // a move follows the drag exactly; the arrow buttons fine-tune it
                renderDesigner();
                return;
            }
            // A click on added words that were already selected puts the cursor in them
            if (!drag.moved && drag.typeOnClick) { startInlineOnEdit(drag.ref.key); return; }
            if (drag.moved) markDirty();
            renderDesigner();
        });

        // Double-click words (any tool) to type in them
        overlay.addEventListener('dblclick', e => {
            const point = toFraction(e);
            const edEl = e.target.closest('.pd-edit.text');
            if (edEl && findEdit(edEl.dataset.edit)) { startInlineOnEdit(edEl.dataset.edit); return; }
            const hit = tileAt(page.num, point);
            if (hit) startInlineFromTile(hit.i, hit.j);
        });
    }

    // ================= RENDERING =================
    // The form as rows: one field, or two "half" fields side by side (students see the same)
    const isHalf = f => !!f && f.width === 'half' && f.type !== 'Section';
    function formRows() {
        const rows = [];
        for (let i = 0; i < fbFields.length; i++) {
            if (isHalf(fbFields[i]) && isHalf(fbFields[i + 1])) { rows.push([fbFields[i], fbFields[i + 1]]); i++; }
            else rows.push([fbFields[i]]);
        }
        return rows;
    }

    function render() {
        renderPdfCoverage();
        document.getElementById('fbQuestionCount').textContent = fbFields.filter(f => f.type !== 'Section').length;
        document.getElementById('fbCanvas').innerHTML = fbFields.length ? formRows().map(rowHtml).join('')
            : `<div class="text-center py-12 rounded-2xl border-2 border-dashed border-slate-200 text-slate-400"><i class="fas fa-hand-pointer text-3xl mb-3 text-slate-300"></i><p class="font-black text-slate-600">No fields yet</p><p class="text-sm">Drag an element here from the left, or press <b>Scan the PDF form</b> to start from the official form.</p></div>`;
        renderPalettePdf();
        renderFieldEditor();
        if (designerOpen()) renderDesigner();
        renderVersionBars();
        if (!document.getElementById('fbTabPreview').classList.contains('hidden')) renderPreview();
    }

    function rowHtml(row) {
        return `<div class="fb-row" data-keys="${row.map(f => f.key).join(',')}">
            <span class="fb-grip" draggable="true" title="Drag to move this row"><i class="fas fa-grip-vertical"></i></span>
            <div class="flex-1 min-w-0 grid gap-3 ${row.length > 1 ? 'sm:grid-cols-2' : ''}">${row.map(tileHtml).join('')}</div>
        </div>`;
    }

    function tileHtml(f) {
        const section = f.type === 'Section';
        const missing = f.pdf && !f.box && !activeTemplate().fields.some(p => p.name === f.pdf);
        const pdf = section ? '' : f.box ? '<span class="text-emerald-600">▭ box on the PDF</span>' : f.pdf ? `<span class="${missing ? 'text-amber-600' : 'text-emerald-600'}">${missing ? '⚠ PDF field not on this form' : '▣ prints on the PDF'}</span>` : '';
        return `<div class="fb-tile ${section ? 'section' : ''} ${f.key === fbSelectedKey ? 'sel' : ''}" data-key="${f.key}" draggable="true" onclick="selectField('${f.key}')" ondblclick="editField('${f.key}')" title="Click to select, double-click or ✎ to edit">
            <div class="min-w-0 flex-1">
                <p class="fb-tile-title">${esc(f.label) || (section ? 'Untitled section' : 'Untitled question')}${f.required && !section ? ' <span class="text-red-500">*</span>' : ''}</p>
                <p class="fb-tile-sub"><i class="fas ${typeIcon(f.type)}"></i> ${esc(typeLabel(f.type))}${pdf ? ' · ' + pdf : ''}</p>
            </div>
            <button type="button" class="fb-tile-btn" onclick="event.stopPropagation(); editField('${f.key}')" title="Edit"><i class="fas fa-pen"></i></button>
            <button type="button" class="fb-tile-btn danger" onclick="event.stopPropagation(); deleteField('${f.key}')" title="Delete"><i class="fas fa-trash-can"></i></button>
        </div>`;
    }

    function selectField(key) {
        fbSelectedKey = fbSelectedKey === key ? null : key;
        render();
    }

    // The edit window shows the field being edited, redrawn as it changes (keeping the cursor where it was)
    function renderFieldEditor() {
        const modal = document.getElementById('fieldEditor');
        const f = fbEditingKey ? findField(fbEditingKey) : null;
        modal.classList.toggle('hidden', !f);
        modal.classList.toggle('flex', !!f);
        if (!f) return;
        const focused = document.activeElement && modal.contains(document.activeElement) ? document.activeElement.id : null;
        document.getElementById('fieldEditorBody').innerHTML = editorBlock(f, findIndex(f.key));
        modal.querySelectorAll('textarea').forEach(autoGrow);
        if (focused && document.getElementById(focused)) document.getElementById(focused).focus();
    }

    function setWidth(key, width) {
        findField(key).width = width;
        markDirty();
        render();
    }

    // Mirrors renderFormField() in includes/form_fields.php
    function studentView(f) {
        const box = 'w-full p-4 bg-slate-50 border border-slate-200 rounded-xl font-bold text-slate-400';
        if (f.type === 'Section') {
            return `<div class="pt-2"><h4 class="text-base font-black text-slate-900">${esc(f.label) || 'Untitled section'}</h4>${f.help ? `<p class="text-sm text-slate-500 mt-1">${esc(f.help)}</p>` : ''}<div class="h-px bg-slate-100 mt-3"></div></div>`;
        }
        let html = `<div class="space-y-2"><label class="text-[11px] font-black text-slate-500 uppercase tracking-widest block pointer-events-none">${esc(f.label) || 'Untitled question'} ${f.required ? '<span class="text-red-500">*</span>' : '<span class="text-slate-300 normal-case tracking-normal font-bold">(optional)</span>'}</label>`;
        if (f.help) html += `<p class="text-xs text-slate-400 font-medium -mt-1">${esc(f.help)}</p>`;

        if (isAuto(f.type)) {
            html += `<div class="w-full p-4 bg-blue-50 border border-blue-200 rounded-xl font-bold text-blue-700">${esc(f.placeholder) || 'Student\'s ' + esc(FB_AUTO_TYPES[f.type] || '')}</div>
                     <p class="text-[10px] font-bold text-blue-500 uppercase tracking-widest">⚡ Filled from your profile — you can edit it</p>`;
        } else if (f.type === 'Textarea') {
            html += `<div class="${box} h-24 font-medium text-sm">${esc(f.placeholder)}</div>`;
        } else if (f.type === 'Select') {
            html += `<div class="${box} flex justify-between items-center"><span>${esc(f.placeholder || 'Select an option...')}</span><i class="fas fa-chevron-down text-xs"></i></div>`;
        } else if (f.type === 'Radio' || f.type === 'Checkbox' || f.type === 'YesNo') {
            const choices = f.type === 'YesNo' ? ['Yes', 'No'] : getOpts(f).filter(o => o.trim());
            const mark = f.type === 'Checkbox' ? 'rounded' : 'rounded-full';
            html += `<div class="grid gap-2 ${f.type === 'YesNo' ? 'grid-cols-2' : 'sm:grid-cols-2'}">` + choices.map(o =>
                `<div class="flex items-center gap-3 p-3.5 bg-slate-50 border border-slate-200 rounded-xl"><span class="w-4 h-4 border-2 border-slate-300 ${mark} shrink-0"></span><span class="text-sm font-bold text-slate-700">${esc(o)}</span></div>`).join('') + `</div>`;
            if (f.type === 'Checkbox' && f.required) html += `<p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Select at least one</p>`;
        } else {
            const hint = { Number: '0', Date: 'mm/dd/yyyy', Email: 'name@example.com', Phone: '09XX XXX XXXX' }[f.type] || '';
            html += `<div class="${box}">${esc(f.placeholder || hint)}</div>`;
        }
        return html + `</div>`;
    }

    function renderPreview() {
        const instructions = document.getElementById('fbInstructions').value.trim();
        const box = document.getElementById('fbInstructionsBox');
        box.textContent = 'ℹ️ ' + instructions;
        box.classList.toggle('hidden', !instructions);
        document.getElementById('fbPreviewCanvas').innerHTML = fbFields.map(f => `<div class="${isHalf(f) ? '' : 'sm:col-span-2'}">${studentView(f)}</div>`).join('')
            || '<p class="text-sm text-slate-400 font-bold">No questions yet.</p>';
    }

    // The edit window for one field (opened with its pencil)
    function editorBlock(f, i) {
        const isSection = f.type === 'Section';
        const typeOptions = Object.keys(FB_TYPES).map(t => `<option value="${t}" ${t === f.type ? 'selected' : ''}>${esc(FB_TYPES[t].label)}</option>`).join('')
            + `<optgroup label="⚡ Auto-fill from profile">` + Object.keys(FB_AUTO_TYPES).map(t => `<option value="${t}" ${t === f.type ? 'selected' : ''}>${esc(FB_AUTO_TYPES[t])}</option>`).join('') + `</optgroup>`;

        let body = '';
        if (needsOptions(f.type)) {
            const mark = f.type === 'Checkbox' ? 'rounded' : (f.type === 'Select' ? '' : 'rounded-full');
            body = `<div class="space-y-1" id="opts-${f.key}">` + getOpts(f).map((o, n) => `
                <div class="opt-row flex items-center gap-3 group">
                    ${f.type === 'Select' ? `<span class="w-5 text-xs font-black text-slate-400 text-right">${n + 1}.</span>` : `<span class="w-4 h-4 border-2 border-slate-300 ${mark} shrink-0"></span>`}
                    <input type="text" value="${esc(o)}" placeholder="Option ${n + 1}" oninput="setOption('${f.key}', ${n}, this.value)" onkeydown="optionKey(event, '${f.key}', ${n})">
                    <button type="button" onclick="removeOption('${f.key}', ${n})" class="w-7 h-7 rounded-lg text-slate-300 hover:text-red-600 hover:bg-red-50 opacity-0 group-hover:opacity-100 focus:opacity-100" title="Remove option"><i class="fas fa-xmark"></i></button>
                </div>`).join('') + `
                <button type="button" onclick="addOption('${f.key}')" class="ml-7 mt-1 text-sm font-bold text-blue-600 hover:underline"><i class="fas fa-plus text-xs"></i> Add option</button>
            </div>`;
        } else if (f.type === 'YesNo') {
            body = `<div class="grid grid-cols-2 gap-2 opacity-70">${['Yes', 'No'].map(o => `<div class="flex items-center gap-3 p-3.5 bg-slate-50 border border-slate-200 rounded-xl"><span class="w-4 h-4 border-2 border-slate-300 rounded-full"></span><span class="text-sm font-bold text-slate-700">${o}</span></div>`).join('')}</div>`;
        } else if (!isSection) {
            const auto = isAuto(f.type);
            body = `<div class="w-full p-4 ${auto ? 'bg-blue-50 border-blue-200' : 'bg-slate-50 border-slate-200'} border rounded-xl ${f.type === 'Textarea' ? 'min-h-[6rem]' : ''}">
                        <input type="text" value="${esc(f.placeholder)}" maxlength="150" oninput="updateField('${f.key}', 'placeholder', this.value)"
                            placeholder="${auto ? 'Pre-filled with the student\'s ' + esc(FB_AUTO_TYPES[f.type] || '') + ' — placeholder (optional)' : 'Placeholder text shown in the empty box (optional)'}"
                            class="w-full bg-transparent outline-none font-bold ${auto ? 'text-blue-700 placeholder:text-blue-300' : 'text-slate-500 placeholder:text-slate-300'}">
                    </div>`;
        }

        return `
        <div class="q-edit" data-key="${f.key}">
            <div class="flex flex-col sm:flex-row gap-3 sm:items-start mb-3">
                <div class="flex-1 min-w-0">
                    <input id="fb-label-${f.key}" type="text" value="${esc(f.label)}" maxlength="255" oninput="updateField('${f.key}', 'label', this.value)"
                        placeholder="${isSection ? 'Section title' : 'Question'}" class="inline-input ${isSection ? 'text-lg' : 'text-[15px]'} font-black text-slate-900">
                    <input type="text" value="${esc(f.help)}" maxlength="255" oninput="updateField('${f.key}', 'help', this.value)"
                        placeholder="${isSection ? 'Description (optional)' : 'Help text shown under the question (optional)'}" class="inline-input text-xs font-medium text-slate-500 mt-1">
                </div>
                <select onchange="changeType('${f.key}', this.value)" class="sm:w-56 shrink-0 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold outline-none focus:border-blue-500 cursor-pointer">${typeOptions}</select>
            </div>

            ${body}

            <div class="flex flex-wrap items-center justify-between gap-3 mt-5 pt-4 border-t border-slate-100">
                <div class="flex items-center gap-4">
                    ${isSection ? '' : `
                    <button type="button" onclick="toggleRequired('${f.key}')" class="inline-flex items-center gap-2 text-sm font-black text-slate-700">
                        <span class="switch ${f.required ? 'on' : ''}" id="sw-${f.key}"></span> Required
                    </button>
                    <div class="${f.type === 'Section' ? 'hidden' : 'inline-flex'} rounded-lg bg-slate-100 p-0.5 text-[11px] font-black" title="Half: shown side by side with the field next to it">
                        <button type="button" onclick="setWidth('${f.key}', 'full')" class="px-3 py-1.5 rounded-md ${f.width === 'half' ? 'text-slate-500' : 'bg-white shadow-sm text-slate-900'}">Full row</button>
                        <button type="button" onclick="setWidth('${f.key}', 'half')" class="px-3 py-1.5 rounded-md ${f.width === 'half' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500'}">Half</button>
                    </div>
                    <details class="text-xs" ${f.pdf || f.box ? 'open' : ''}>
                        <summary class="cursor-pointer font-black text-slate-400 uppercase tracking-widest"><i class="fas fa-file-pdf text-red-400"></i> Print on PDF</summary>
                        ${f.box
                            ? `<div class="mt-2 flex flex-wrap items-center gap-2">
                                   <span class="px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 font-black">▭ Drawn box on page ${f.box.page}</span>
                                   <button type="button" onclick="openPdfDesigner('${f.key}')" class="px-3 py-2 rounded-lg bg-white border border-slate-200 font-black text-slate-700 hover:border-blue-500"><i class="fas fa-pen"></i> Edit</button>
                                   <button type="button" onclick="removeFromPdf('${f.key}'); render();" class="px-3 py-2 rounded-lg bg-white border border-slate-200 font-black text-red-600 hover:border-red-400"><i class="fas fa-trash-can"></i> Remove</button>
                               </div>`
                            : pdfFieldSelect(f)}
                        <button type="button" onclick="openPdfDesigner('${f.key}')" class="mt-2 block font-black text-blue-600 hover:underline"><i class="fas fa-pen-ruler"></i> ${f.box ? 'Move or resize it on the PDF' : 'Or place it visually / draw a new box on the PDF'}</button>
                    </details>`}
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" onclick="moveField('${f.key}', -1)" ${i === 0 ? 'disabled' : ''} class="w-9 h-9 rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-30" title="Move up"><i class="fas fa-arrow-up"></i></button>
                    <button type="button" onclick="moveField('${f.key}', 1)" ${i === fbFields.length - 1 ? 'disabled' : ''} class="w-9 h-9 rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-30" title="Move down"><i class="fas fa-arrow-down"></i></button>
                    <button type="button" onclick="duplicateField('${f.key}')" class="w-9 h-9 rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-900" title="Duplicate"><i class="fas fa-clone"></i></button>
                    <button type="button" onclick="deleteField('${f.key}')" class="w-9 h-9 rounded-xl text-slate-400 hover:bg-red-50 hover:text-red-600" title="Delete"><i class="fas fa-trash-can"></i></button>
                    <button type="button" onclick="closeEditor()" class="ml-2 bg-blue-600 text-white px-5 py-2 rounded-xl text-xs font-black uppercase tracking-widest hover:bg-blue-700" title="Or click anywhere outside the question">Done</button>
                </div>
            </div>
        </div>`;
    }

    // ================= EDITING =================
    function editField(key) {
        if (document.body.classList.contains('fb-preview')) return;
        fbEditingKey = key;
        render();
        const input = document.getElementById('fb-label-' + key);
        if (input) {
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
            input.closest('.q-edit').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function closeEditor() {
        fbEditingKey = null;
        render();
    }

    // Text edits only touch the state, so the input keeps focus
    function updateField(key, prop, value) {
        const f = findField(key);
        if (!f) return;
        f[prop] = value;
        markDirty();
    }

    function changeType(key, type) {
        const f = findField(key);
        f.type = type;
        if (needsOptions(type) && getOpts(f).filter(o => o.trim()).length < 2) f.options = 'Option 1\nOption 2';
        if (type === 'Section') f.required = false;
        if (isAuto(type) && (!f.label || f.label === 'Untitled question')) f.label = FB_AUTO_TYPES[type];
        markDirty();
        render();
    }

    function toggleRequired(key) {
        const f = findField(key);
        f.required = !f.required;
        document.getElementById('sw-' + key).classList.toggle('on', f.required);
        markDirty();
    }

    // ---- choices ----
    function setOption(key, n, value) {
        const f = findField(key);
        const opts = getOpts(f);
        opts[n] = value.replace(/\n/g, ' ');
        f.options = opts.join('\n');
        markDirty();
    }

    function addOption(key, at = null) {
        const f = findField(key);
        const opts = getOpts(f);
        const pos = at === null ? opts.length : at;
        opts.splice(pos, 0, '');
        f.options = opts.join('\n');
        markDirty();
        render();
        const inputs = document.querySelectorAll(`#opts-${key} input[type=text]`);
        if (inputs[pos]) inputs[pos].focus();
    }

    function removeOption(key, n) {
        const f = findField(key);
        const opts = getOpts(f);
        if (opts.length <= 1) return;
        opts.splice(n, 1);
        f.options = opts.join('\n');
        markDirty();
        render();
    }

    // Enter = new option below, Backspace on an empty option = remove it
    function optionKey(e, key, n) {
        if (e.key === 'Enter') { e.preventDefault(); addOption(key, n + 1); }
        else if (e.key === 'Backspace' && e.target.value === '' && getOpts(findField(key)).length > 1) {
            e.preventDefault();
            removeOption(key, n);
            const inputs = document.querySelectorAll(`#opts-${key} input[type=text]`);
            const prev = inputs[Math.max(0, n - 1)];
            if (prev) { prev.focus(); prev.setSelectionRange(prev.value.length, prev.value.length); }
        }
    }

    // ---- structure ----
    // New fields go after the selected field's row, else at the end
    function insertIndex() {
        if (!fbSelectedKey || !findField(fbSelectedKey)) return fbFields.length;
        const row = formRows().find(r => r.some(f => f.key === fbSelectedKey));
        return findIndex(row[row.length - 1].key) + 1;
    }

    function makeField(spec) {
        const type = spec.type;
        return { id: null, key: 'k' + (++fbKeySeq), type, required: spec.required !== undefined ? !!spec.required : type !== 'Section',
            help: spec.help || '', placeholder: '', pdf: spec.pdf || '', box: null, width: spec.width || 'full',
            label: spec.label !== undefined ? spec.label : (type === 'Section' ? 'New Section' : (isAuto(type) ? FB_AUTO_TYPES[type] : '')),
            options: spec.options || (needsOptions(type) ? 'Option 1\nOption 2' : '') };
    }

    function addField(type, at) {
        const f = makeField({ type });
        fbFields.splice(at, 0, f);
        fbSelectedKey = f.key;
        markDirty();
        editField(f.key);
    }

    // Adds a Form elements item (JSON from its data-el) after the selected row; blank fields open their edit window
    function addElement(json, atEnd = false) {
        const spec = JSON.parse(json);
        const f = makeField(spec);
        if (f.type === 'Section') f.width = 'full';
        fbFields.splice(atEnd ? fbFields.length : insertIndex(), 0, f);
        fbSelectedKey = f.key;
        markDirty();
        if (spec.edit) editField(f.key);
        else { render(); flashTile(f.key); }
    }

    function flashTile(key) {
        const tile = document.querySelector(`.fb-tile[data-key="${key}"]`);
        if (!tile) return;
        tile.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        tile.animate([{ boxShadow: '0 0 0 6px rgba(37,99,235,.35)' }, { boxShadow: '0 0 0 0 rgba(37,99,235,0)' }], { duration: 900 });
    }

    function moveField(key, delta) {
        const i = findIndex(key);
        const j = i + delta;
        if (j < 0 || j >= fbFields.length) return;
        [fbFields[i], fbFields[j]] = [fbFields[j], fbFields[i]];
        markDirty();
        render();
    }

    function duplicateField(key) {
        const i = findIndex(key);
        const copy = { ...fbFields[i], id: null, key: 'k' + (++fbKeySeq), label: fbFields[i].label + ' (copy)' };
        fbFields.splice(i + 1, 0, copy);
        markDirty();
        editField(copy.key);
    }

    function deleteField(key) {
        const f = findField(key);
        if (!confirm(`Delete "${f.label || 'this question'}" from the form?` + (f.id ? '\n\nAnswers already submitted for it are kept with those applications.' : ''))) return;
        fbFields = fbFields.filter(x => x.key !== key);
        if (fbEditingKey === key) fbEditingKey = null;
        if (fbSelectedKey === key) fbSelectedKey = null;
        markDirty();
        render();
    }

    // ================= FORM ELEMENTS (left) =================
    // Ready-made questions most scholarships ask (Form elements → Common questions)
    const FB_SUGGESTIONS = [
        { label: 'Why should you receive this scholarship?', type: 'Textarea', help: 'Write at least three sentences.' },
        { label: 'Total monthly family income', type: 'Auto_Income' },
        { label: 'Number of siblings', type: 'Number' },
        { label: 'How many of your siblings are currently studying?', type: 'Number' },
        { label: "Father's occupation", type: 'Text' },
        { label: "Mother's occupation", type: 'Text' },
        { label: 'Are you currently receiving another scholarship or grant?', type: 'YesNo' },
        { label: 'If yes, which scholarship or grant?', type: 'Text', required: false },
        { label: 'Do you belong to an Indigenous Peoples (IP) group?', type: 'YesNo' },
        { label: 'Are you a person with disability (PWD)?', type: 'YesNo' },
        { label: 'Awards, honors or activities', type: 'Textarea', required: false },
        { label: 'Preferred way to receive the grant', type: 'Radio', options: 'Cash\nBank deposit\nGCash' },
    ];
    function elementButton(spec, icon, label, sub = '', fn = 'addElement') {
        return `<button type="button" class="fb-el" draggable="true" data-el="${esc(JSON.stringify(spec))}" onclick="${fn}(this.dataset.el)">
            <i class="fas fa-grip-vertical dots"></i><i class="fas ${icon} ico"></i><span class="min-w-0 flex-1"><span class="block truncate">${esc(label)}</span>${sub ? `<span class="block text-[10px] font-black uppercase tracking-wider text-slate-400">${esc(sub)}</span>` : ''}</span></button>`;
    }
    function elementGroup(title, body, open = false, id = '') {
        return `<details class="fb-group" ${open ? 'open' : ''} ${id ? `id="${id}"` : ''}><summary>${title}<i class="fas fa-chevron-down chev"></i></summary><div class="pt-2 space-y-1.5">${body}</div></details>`;
    }
    function renderPalette() {
        document.getElementById('fbPaletteGroups').innerHTML =
            elementGroup('Custom field', Object.keys(FB_TYPES).map(t => elementButton({ type: t, edit: true }, FB_TYPES[t].icon, FB_TYPES[t].label)).join(''), true)
            + elementGroup('From the PDF form', `<button type="button" id="fbScanBtn" onclick="scanPdfForm()" class="w-full px-3 py-2.5 rounded-xl bg-blue-600 text-white text-xs font-black uppercase tracking-widest hover:bg-blue-700 disabled:opacity-60"><i class="fas fa-wand-magic-sparkles"></i> Scan the PDF form</button>
                <p class="text-[11px] text-slate-500 px-1">Adds a field for every blank on the official form, laid out the way the form is. Or drag one blank:</p><div id="fbPdfBlanks" class="space-y-1.5"></div>`, true, 'fbPdfGroup')
            + elementGroup('Student profile (filled in for them)', Object.keys(FB_AUTO_TYPES).map(t => elementButton({ type: t }, 'fa-bolt', FB_AUTO_TYPES[t])).join(''))
            + elementGroup('Common scholarship questions', FB_SUGGESTIONS.map(q => elementButton({ type: q.type, label: q.label, help: q.help || '', required: q.required !== false, options: q.options || '' }, typeIcon(q.type), q.label, typeLabel(q.type))).join(''));
    }
    // The official form's blanks that no field fills yet
    function renderPalettePdf() {
        const box = document.getElementById('fbPdfBlanks');
        if (!box) return;
        const tpl = activeTemplate(), used = pdfUsage();
        const blanks = tpl.fields.filter(p => !used[p.name] && !tpl.auto.includes(p.name) && !(fbOverrides[p.name] && fbOverrides[p.name].action === 'hide'));
        box.innerHTML = blanks.slice(0, 40).map(p => elementButton({ type: p.type === 'Checkbox' ? 'YesNo' : 'Text', label: humanize(p.name), pdf: p.name }, p.type === 'Checkbox' ? 'fa-square-check' : 'fa-font', humanize(p.name), 'blank on the PDF')).join('')
            + (blanks.length > 40 ? `<p class="text-[11px] text-slate-400 px-1">…and ${blanks.length - 40} more — use Scan the PDF form.</p>` : '')
            || `<p class="text-[11px] text-slate-400 px-1">${tpl.fields.length ? 'Every blank on the form is filled. 🎉' : 'This PDF has no fillable blanks: Scan reads its printed labels instead.'}</p>`;
    }

    // ================= SCAN THE PDF FORM =================
    // Reads the official form and adds a field for each blank still unfilled, in the form's order: blanks on the same line
    // become one row of two side-by-side fields. A plain or scanned PDF has no blanks, so its printed labels ("Name:") are used.
    async function scanPdfForm() {
        const btn = document.getElementById('fbScanBtn');
        const file = fbTemplate || FB_DEFAULT_TEMPLATE;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reading the form…';
        try {
            if (!window.pdfjsLib) throw new Error('The PDF reader could not load.');
            pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
            const doc = await pdfjsLib.getDocument('../forms/' + encodeURIComponent(file)).promise;
            const tpl = activeTemplate(), used = pdfUsage(), seen = new Set(), blanks = [], labels = [];
            for (let n = 1; n <= doc.numPages; n++) {
                const page = await doc.getPage(n), vp = page.getViewport({ scale: 1 });
                // The printed words on the page, one by one (0–1 of the page; y is the baseline)
                const words = [];
                (await page.getTextContent()).items.forEach(it => {
                    const str = it.str || '';
                    if (!str.trim()) return;
                    const [x, y] = vp.convertToViewportPoint(it.transform[4], it.transform[5]);
                    const h = (Math.hypot(it.transform[2], it.transform[3]) || 8) / vp.height, per = it.width / vp.width / str.length;
                    for (const m of str.matchAll(/\S+/g)) {
                        const text = m[0].replace(/^[()\[\]_.]+|[()\[\]_]+$/g, '');
                        if (/[A-Za-z0-9]/.test(text)) words.push({ text, x: x / vp.width + m.index * per, w: m[0].length * per, y: y / vp.height, h });
                    }
                });
                // A plain PDF's labels: words up to a colon ("Name:", "Date of Birth:")
                phrases(words).forEach(p => {
                    if (/^[A-Za-z][^:]{1,40}:$/.test(p.text)) labels.push({ page: n, x: p.x, y: p.y - p.h, spec: { type: 'Text', label: p.text.slice(0, -1).trim() } });
                });
                const widgets = (await page.getAnnotations()).filter(a => a.subtype === 'Widget' && a.fieldName && !a.pushButton).map(a => {
                    const [x1, y1, x2, y2] = vp.convertToViewportRectangle(a.rect);
                    return { a, box: { x: Math.min(x1, x2) / vp.width, y: Math.min(y1, y2) / vp.height, r: Math.max(x1, x2) / vp.width, b: Math.max(y1, y2) / vp.height } };
                });
                widgets.forEach(({ a, box }) => {
                    if (a.fieldType === 'Sig' || seen.has(a.fieldName) || used[a.fieldName] || tpl.auto.includes(a.fieldName) || (fbOverrides[a.fieldName] && fbOverrides[a.fieldName].action === 'hide')) return;
                    seen.add(a.fieldName);
                    const check = !!(a.checkBox || a.radioButton);
                    blanks.push({ page: n, x: box.x, y: box.y, spec: { type: check ? 'YesNo' : 'Text', label: printedLabel(words, box, widgets.map(w => w.box), check) || humanize(a.fieldName), pdf: a.fieldName } });
                });
            }
            const plain = !tpl.fields.length;
            const items = (plain ? labels : blanks).filter(b => plain ? !fbFields.some(f => f.label.trim().toLowerCase() === b.spec.label.toLowerCase()) : true);
            if (!items.length) { showAlert('success', plain ? 'No new labels found on this form.' : 'Every blank on the PDF form already has a field.'); return; }
            // In the form's order; things on the same line make one row (two side by side)
            items.sort((a, b) => a.page - b.page || a.y - b.y || a.x - b.x);
            const specs = [];
            let line = [];
            const flush = () => {
                for (let i = 0; i < line.length; i += 2) {
                    const pair = line.slice(i, i + 2);
                    pair.forEach(b => specs.push({ ...b.spec, width: pair.length === 2 ? 'half' : 'full' }));
                }
                line = [];
            };
            items.forEach(b => { if (line.length && (b.page !== line[0].page || Math.abs(b.y - line[0].y) > 0.012)) flush(); line.push(b); });
            flush();
            const question = plain
                ? `This PDF has no fillable blanks. Found ${specs.length} printed label(s) like “${specs[0].label}:”. Add them as fields? (Place each on the PDF afterwards in the PDF form tab.)`
                : `Found ${specs.length} blank(s) on “${(FB_PDF_TEMPLATES[file] || {}).label || file}” that no field fills yet. Add them as fields, laid out like the form?`;
            if (!confirm(question)) return;
            const at = insertIndex();
            const added = specs.map(makeField);
            fbFields.splice(at, 0, ...added);
            fbSelectedKey = added[added.length - 1].key;
            markDirty();
            showTab('edit');
            render();
            showAlert('success', `${added.length} field(s) added from the PDF form. Rename, reorder or delete them, then press Save form.`);
        } catch (err) {
            showAlert('error', 'Could not read the PDF form: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Scan the PDF form';
        }
    }

    // Joins words that sit next to each other on one line into phrases
    function phrases(words) {
        const out = [];
        [...words].sort((a, b) => a.y - b.y || a.x - b.x).forEach(w => {
            const p = out[out.length - 1];
            if (p && Math.abs(p.y - w.y) < p.h * 0.4 && w.x - (p.x + p.w) < p.h * 1.2 && w.x >= p.x + p.w - p.h) {
                p.text += (w.x - (p.x + p.w) > p.h * 0.12 ? ' ' : '') + w.text;
                p.w = w.x + w.w - p.x;
            } else out.push({ ...w });
        });
        return out;
    }

    // The words printed next to a blank: before it on its line (after it, for a checkbox), else just above it.
    // A label never reaches past the next blank on the same line.
    function printedLabel(words, box, boxes, check) {
        const mid = (box.y + box.b) / 2, tall = Math.max(0.012, (box.b - box.y) * 0.9);
        const onLine = t => Math.abs((t.y - t.h * 0.35) - mid) < tall;
        const sameLine = boxes.filter(o => o !== box && Math.abs((o.y + o.b) / 2 - mid) < tall);
        const leftEnd = Math.max(0, ...sameLine.filter(o => o.r <= box.x + 0.002).map(o => o.r));
        const rightEnd = Math.min(1, ...sameLine.filter(o => o.x >= box.r - 0.002).map(o => o.x));
        const clean = t => t.replace(/[:*]+$/, '').replace(/\s+/g, ' ').trim().slice(0, 80);
        // the run of words closest to the blank, stopping at a wide gap
        const run = (list, fromRight) => {
            const sorted = list.sort((a, b) => fromRight ? (b.x + b.w) - (a.x + a.w) : a.x - b.x), got = [];
            for (const w of sorted) {
                const prev = got[got.length - 1];
                if (prev && (fromRight ? prev.x - (w.x + w.w) : w.x - (prev.x + prev.w)) > w.h * 1.5) break;
                if (fromRight && prev && /:$/.test(w.text)) break;   // the previous label ends here
                got.push(w);
                if (!fromRight && /:$/.test(w.text)) break;
            }
            return clean(phrases(fromRight ? got.reverse() : got).map(p => p.text).join(' '));
        };
        const after = run(words.filter(t => onLine(t) && t.x >= box.r - 0.004 && t.x + t.w <= rightEnd + 0.004 && t.x - box.r < 0.2), false);
        const before = run(words.filter(t => onLine(t) && t.x + t.w <= box.x + 0.008 && t.x >= leftEnd - 0.004 && box.x - (t.x + t.w) < 0.25), true);
        const lineAbove = words.filter(t => t.y <= box.y + 0.004 && box.y - t.y < 0.03 && t.x < box.r && t.x + t.w > box.x);
        const topY = Math.max(...lineAbove.map(t => t.y));
        const above = lineAbove.length ? clean(phrases(lineAbove.filter(t => Math.abs(t.y - topY) < 0.004)).map(p => p.text).join(' ')) : '';
        return (check ? after || before : before || above) || above;
    }

    // ================= DRAG & DROP =================
    // Elements from the left, a field, or a whole row (its grip) can be dropped between rows, or beside a field on its own row
    let fbDrag = null;
    document.addEventListener('dragstart', e => {
        const el = e.target.closest && e.target.closest('.fb-el, .fb-grip, .fb-tile');
        if (!el) return;
        if (el.classList.contains('fb-el')) { const spec = JSON.parse(el.dataset.el); fbDrag = spec.pdf ? { kind: 'pdf', json: el.dataset.el, spec } : { kind: 'new', spec }; }
        else if (el.closest('#pdfRows')) fbDrag = { kind: 'pdfline', row: +el.closest('.pdf-row').dataset.row };
        else if (el.classList.contains('fb-grip')) fbDrag = { kind: 'row', keys: el.closest('.fb-row').dataset.keys.split(',') };
        else fbDrag = { kind: 'field', key: el.dataset.key };
        e.dataTransfer.effectAllowed = 'copyMove';
        e.dataTransfer.setData('text/plain', 'field');
        document.body.classList.add('fb-dragging');
    });
    document.addEventListener('dragend', () => { fbDrag = null; showDropMark(null); document.body.classList.remove('fb-dragging'); });

    function dropTarget(e) {
        const rows = [...document.querySelectorAll('#fbCanvas .fb-row')];
        const tile = e.target.closest && e.target.closest('.fb-tile');
        if (tile && fbDrag.kind !== 'row') {
            const row = tile.closest('.fb-row'), target = findField(tile.dataset.key);
            const r = tile.getBoundingClientRect(), fx = (e.clientX - r.left) / r.width;
            const dragged = fbDrag.kind === 'new' ? fbDrag.spec : findField(fbDrag.key);
            if (row.dataset.keys.split(',').length === 1 && target.type !== 'Section' && dragged.type !== 'Section' && (fbDrag.kind === 'new' || fbDrag.key !== target.key) && (fx < 0.3 || fx > 0.7))
                return { mode: 'beside', key: target.key, side: fx < 0.3 ? 'left' : 'right', rect: r };
        }
        for (const row of rows) {
            const r = row.getBoundingClientRect();
            if (e.clientY < r.top + r.height / 2) return { mode: 'row', before: row.dataset.keys.split(',')[0], rect: r, edge: 'top' };
        }
        const last = rows.length ? rows[rows.length - 1].getBoundingClientRect() : document.getElementById('fbCanvas').getBoundingClientRect();
        return { mode: 'row', before: null, rect: last, edge: rows.length ? 'bottom' : 'top' };
    }

    function showDropMark(t) {
        const mark = document.getElementById('fbDropMark');
        if (!t) { mark.classList.add('hidden'); return; }
        mark.classList.remove('hidden');
        const r = t.rect;
        Object.assign(mark.style, t.mode === 'beside'
            ? { left: (t.side === 'left' ? r.left - 4 : r.right) + 'px', top: r.top + 'px', width: '4px', height: r.height + 'px' }
            : { left: r.left + 'px', top: (t.edge === 'top' ? r.top - 7 : r.bottom + 3) + 'px', width: r.width + 'px', height: '4px' });
    }

    const canvasEl = () => document.getElementById('fbCanvas');
    document.addEventListener('dragover', e => {
        if (fbDrag && fbDrag.kind.startsWith('pdf')) return;   // handled by the PDF lines
        if (!fbDrag || !canvasEl().contains(e.target) && e.target !== canvasEl()) { if (fbDrag) showDropMark(null); return; }
        e.preventDefault();
        showDropMark(dropTarget(e));
    });
    document.addEventListener('drop', e => {
        if (fbDrag && fbDrag.kind.startsWith('pdf')) return;
        if (!fbDrag || !canvasEl().contains(e.target) && e.target !== canvasEl()) return;
        e.preventDefault();
        const t = dropTarget(e), drag = fbDrag;
        showDropMark(null);
        let moving;
        if (drag.kind === 'new') moving = [makeField(drag.spec)];
        else {
            const keys = drag.kind === 'row' ? drag.keys : [drag.key];
            if (t.mode === 'row' && t.before && keys.includes(t.before)) return;   // dropped where it already is
            // A field leaving a side-by-side pair: the one staying behind takes the whole row
            if (drag.kind === 'field') formRows().find(r => r.some(f => f.key === drag.key)).forEach(f => { if (f.key !== drag.key) f.width = 'full'; });
            moving = keys.map(findField);
            fbFields = fbFields.filter(f => !keys.includes(f.key));
        }
        if (t.mode === 'beside') {
            findField(t.key).width = 'half';
            moving[0].width = 'half';
            fbFields.splice(findIndex(t.key) + (t.side === 'right' ? 1 : 0), 0, moving[0]);
        } else {
            if (drag.kind !== 'row') moving.forEach(f => { f.width = 'full'; });
            fbFields.splice(t.before ? findIndex(t.before) : fbFields.length, 0, ...moving);
        }
        fbSelectedKey = moving[0].key;
        markDirty();
        if (drag.kind === 'new' && drag.spec.edit) editField(moving[0].key);
        else render();
    });

    // Clicking outside the edit window closes it
    document.addEventListener('mousedown', e => {
        if (fbEditingKey && !e.target.closest('.q-edit, #pdfDesigner, header, #fbTabPdf')) closeEditor();
    });

    // ================= THE PDF FORM, LINE BY LINE =================
    // The PDF (as it prints, with this scholarship's changes) read into lines; each line is a row of parts: some printed
    // words and the blank after them (a fillable field, or a box drawn for a question). Changing a part adds the same
    // content edits the PDF designer makes, so printing is unchanged: replaced or added text, erased areas, inserted lines.
    let pdRows = [], pdSelRowAt = null, pdTile = null, pdLoading = null;
    let pdHlPart = null;        // the part selected in the list, lit up on the PDF: { page, x, y, w, h }
    let pdScrollList = false;   // bring the selected line into view in the list (it was picked on the PDF)
    const LINE_H = 0.024;   // a new line: about 19 pt
    const centreIn = (r, a) => r.x + r.w / 2 >= a.x && r.x + r.w / 2 <= a.x + a.w && r.y + r.h / 2 >= a.y && r.y + r.h / 2 <= a.y + a.h;
    const unionOf = list => {
        const x = Math.min(...list.map(q => q.x)), y = Math.min(...list.map(q => q.y));
        return { x, y, w: Math.max(...list.map(q => q.x + q.w)) - x, h: Math.max(...list.map(q => q.y + q.h)) - y };
    };
    const tileParts = t => t.blank ? [...t.labels, t.blank] : t.labels;
    // A part's words. Pieces that touch are one word (a PDF often stores "informati" + "on", or a big first letter apart)
    const tileText = t => {
        const pagePtWide = pdPages.length ? pdPages[0].hPt * pdPages[0].source.width / pdPages[0].source.height : 612;
        let out = '', prev = null;
        t.labels.forEach(l => {
            const touching = prev && Math.abs((l.y + l.h / 2) - (prev.y + prev.h / 2)) < Math.max(l.h, prev.h) * 0.6
                && l.x - (prev.x + prev.w) < (l.size || prev.size || 10) * 0.12 / pagePtWide && l.x >= prev.x;
            out += (prev && !touching ? ' ' : '') + l.str;
            prev = l;
        });
        return out.replace(/_{2,}/g, ' ').replace(/\s+/g, ' ').trim();
    };
    const pushEdit = e => { colorEdit(e); e.key = 'e' + (++fbKeySeq); fbEdits.push(e); return e; };
    // A new line, measured on the page as it looks now, goes at the end of the list (inserts and moves apply in order)
    function addLineInsert(ins) {
        shiftAroundInsert(ins, 1);
        return pushEdit(ins);
    }

    // ---- moving a line ----
    // The strip of a line: from the white space over it to the white space over the next line (so the gap under it goes with it)
    function lineStrip(i) {
        const top = cutOver(i), bottom = cutUnder(i, 'next');
        return { y: top, h: Math.max(0.006, bottom - top) };
    }
    // Moves line i so it comes before line j (j = pdRows.length: after the last line); both on the same page
    function moveLineTo(i, j) {
        const row = pdRows[i];
        if (j === i || j === i + 1) return;
        const before = pdRows[j], after = pdRows[j - 1];
        const samePage = r => r && r.page === row.page;
        if (!samePage(before) && !samePage(after)) { showAlert('error', 'A line can only be moved within its own page.'); return; }
        const strip = lineStrip(i);
        const to = samePage(before) ? cutOver(j) : cutUnder(j - 1, 'next');
        if (to > strip.y - 1e-4 && to < strip.y + strip.h + 1e-4) return;
        const p = pdPages[row.page - 1];
        const intoTable = bordersAt(p, to) > 0, outOfTable = bordersAt(p, strip.y + strip.h / 2) > 0;
        const m = { kind: 'move', page: row.page, x: 0, y: strip.y, w: 1, h: strip.h, to, text: '', font: 'Helvetica', size: null, bold: false, cover: false };
        // What was placed on the page goes with the content it sits on
        const carry = r => { if (r && r.page === m.page) r.y = mapMove(r.y + r.h / 2, m) - r.h / 2; };
        fbEdits.filter(e => !isTransform(e)).forEach(carry);
        fbFields.forEach(f => carry(f.box));
        Object.values(fbOverrides).forEach(o => carry(o.box));
        pushEdit(m);
        pdSelRowAt = { page: row.page, y: mapMove(row.y + row.h / 2, m) };
        markDirty();
        render();
        if (intoTable || outOfTable) showAlert('success', `Line moved. Note: ${intoTable ? 'it is now inside a table, so the side borders of the table run past it' : 'it was part of a table, so its cell borders came with it'}. Press Undo if that is not what you wanted.`);
    }
    function moveLineBy(i, step) {
        const j = step < 0 ? i - 1 : i + 2;
        if (j < 0 || j > pdRows.length || (step < 0 && pdRows[i - 1].page !== pdRows[i].page) || (step > 0 && (!pdRows[i + 1] || pdRows[i + 1].page !== pdRows[i].page))) return;
        moveLineTo(i, j);
    }
    // Undoes a move when nothing was inserted or moved on that page after it (otherwise: drag the line back)
    function putLineBack(key) {
        const m = findEdit(key);
        if (fbEdits.slice(fbEdits.indexOf(m) + 1).some(t => isTransform(t) && t.page === m.page)) {
            alert('Other lines were added or moved on this page after this one. Drag the line back on the PDF form tab, or use Undo.');
            return;
        }
        const back = { kind: 'move', y: moveTop(m), h: m.h, to: m.to <= m.y ? m.y + m.h : m.y };
        const carry = r => { if (r && r.page === m.page) r.y = mapMove(r.y + r.h / 2, back) - r.h / 2; };
        fbEdits.filter(e => !isTransform(e)).forEach(carry);
        fbFields.forEach(f => carry(f.box));
        Object.values(fbOverrides).forEach(o => carry(o.box));
        fbEdits = fbEdits.filter(e => e !== m);
        if (pdSelEdit === key) pdSelEdit = null;
        markDirty();
        render();
        if (!document.getElementById('pdfDesigner').classList.contains('hidden')) renderDesigner();
    }

    // A printed run can hold several labels ("Average:      Date of Graduation:") and ____ answer lines: its phrases,
    // placed by their share of the run's width
    function splitPrinted(t) {
        const str = t.str, per = t.w / Math.max(1, str.length), out = [];
        for (const m of str.matchAll(/[^\s_]+(?: [^\s_]+)*/g)) out.push({ str: m[0], x: t.x + m.index * per, y: t.y, w: m[0].length * per, h: t.h });
        return out;
    }

    // Where one line ends and the next begins, in the white space between them on the page image
    // Nothing printed across the pixel row, except the side borders of a table: dark without a break from well above to
    // well below it (about 5 pt each way), which the letters of two packed lines never are
    // borders = false: completely clear (a cut there splits no box or shape)
    function whiteRow(p, py, borders = true) {
        const c = p.canvas, W = c.width, G = Math.max(4, Math.round(W / 150));
        if (py < 0 || py >= c.height) return true;
        const y0 = Math.max(0, py - G), rows = Math.min(c.height, py + G + 1) - y0;
        const data = c.getContext('2d').getImageData(0, y0, W, rows).data;
        // Any visible grey: the faint top of a tall letter on screen is solid ink when printed
        const dark = (x, r) => { const i = (r * W + x) * 4; return data[i] + data[i + 1] + data[i + 2] < 735; };
        const vertical = x => { for (let r = 0; r < rows; r++) if (!dark(x, r) && !dark(x - 1, r) && !dark(x + 1, r)) return false; return true; };
        let stray = 0;
        for (let x = Math.round(W * 0.03); x < W * 0.97; x++) {
            if (!dark(x, py - y0) || (borders && vertical(x))) continue;
            if (++stray > 1) return false;
        }
        return true;
    }
    // How many table side borders cross the page at y (a fraction of the page height)
    function bordersAt(p, y) {
        const c = p.canvas, W = c.width, H = p.source.height, G = Math.max(4, Math.round(W / 150)), py = Math.round(y * H);
        if (py - G < 0 || py + G >= c.height) return 0;
        const data = c.getContext('2d').getImageData(0, py - G, W, 2 * G + 1).data;
        const dark = (x, r) => { const i = (r * W + x) * 4; return data[i] + data[i + 1] + data[i + 2] < 735; };
        let count = 0;
        for (let x = Math.round(W * 0.03); x < W * 0.97; x++) {
            let solid = true;
            for (let r = 0; r <= 2 * G && solid; r++) solid = dark(x, r) || dark(x - 1, r) || dark(x + 1, r);
            if (solid && !(count && x - count.last <= 3)) count = { n: (count.n || 0) + 1, last: x };
            else if (solid) count.last = x;
        }
        return count ? count.n : 0;
    }

    // lo..hi (fractions of the page height): 'next' = in the white gap just above the content at hi (under any border line),
    // 'mid' = in the gap nearest the middle. The cut is in the middle of that gap, clear of the letters on both sides.
    function cutBetween(page, lo, hi, prefer) {
        const p = pdPages[page - 1], H = p.source.height;
        const from = Math.ceil(lo * H), to = Math.floor(hi * H), mid = (from + to) / 2;
        // Answers print inside the blanks, often taller than the form's own words: keep out of the blanks too
        const blanks = [...p.fields, ...fbFields.filter(f => f.box && f.box.page === page).map(f => f.box),
            ...Object.values(fbOverrides).filter(o => o.box && o.box.page === page).map(o => o.box),
            ...fbEdits.filter(e => e.page === page && e.kind === 'text')].map(r => [r.y, r.y + r.h]);
        const clear = py => !blanks.some(([a, b]) => (py + 0.5) / H > a && (py + 0.5) / H < b);
        // Best: completely clear of print and answers; then crossing only side borders (between two rows of a table)
        for (const [strict, borders] of [[true, false], [true, true], [false, false], [false, true]]) {
            const ok = py => py >= from && py < to && whiteRow(p, py, borders) && (!strict || clear(py));
            let best = null;
            for (let py = to - 1; py >= from; py--) {
                if (!ok(py)) continue;
                if (prefer === 'next') { best = py; break; }
                if (best === null || Math.abs(py - mid) < Math.abs(best - mid)) best = py;
            }
            if (best === null) continue;
            let top = best, bottom = best;
            while (ok(top - 1)) top--;
            while (ok(bottom + 1)) bottom++;
            return (top + bottom + 1) / 2 / H;
        }
        return (lo + hi) / 2;
    }
    const rowAbove = i => i > 0 && pdRows[i - 1].page === pdRows[i].page ? pdRows[i - 1] : null;
    const rowBelow = i => pdRows[i + 1] && pdRows[i + 1].page === pdRows[i].page ? pdRows[i + 1] : null;
    // Searched between the lines' middles (their boxes reach above their letters, and overlap on a packed form), so every
    // line is measured the same way: from just above its letters to just above the next line's
    const mid = r => (r.top + r.bot) / 2;
    const gapOf = (upper, lower) => [mid(upper), mid(lower)];
    function cutUnder(i, prefer) {
        const r = pdRows[i], next = rowBelow(i);
        const [lo, hi] = next ? gapOf(r, next) : [mid(r), Math.min(pdPages[r.page - 1].maxY, r.bot + 0.012)];
        return cutBetween(r.page, lo, hi, prefer);
    }
    function cutOver(i) {
        const r = pdRows[i], prev = rowAbove(i);
        const [lo, hi] = prev ? gapOf(prev, r) : [Math.max(0, r.top - 0.03), mid(r)];
        return cutBetween(r.page, lo, hi, 'next');
    }

    async function ensurePdfLoaded() {
        const file = fbTemplate || FB_DEFAULT_TEMPLATE;
        if (pdDocFile === file) return true;
        if (!pdLoading || pdLoading.file !== file) pdLoading = { file, done: loadPdfIntoDesigner(file).then(() => { if (pdDocFile === file) colorOldEdits(); }) };
        await pdLoading.done;
        return pdDocFile === file;
    }

    function scanPdfRows() {
        const rows = [];
        pdPages.forEach(p => {
            paintPage(p);
            const edits = fbEdits.filter(e => e.page === p.num);
            const covered = r => edits.some(e => (e.kind === 'erase' || (e.kind === 'text' && e.cover)) && centreIn(r, e));
            const items = [];
            p.texts.forEach(t => splitPrinted(t).forEach(c => { if (!covered(c)) items.push({ kind: 'text', ...c, size: t.size, font: t.font, bold: t.bold, italic: !!t.italic, copied: !!t.copied }); }));
            edits.filter(e => e.kind === 'text' && e.text.trim()).forEach(e => items.push({ kind: 'edit', key: e.key, x: e.x, y: e.y, w: e.w, h: e.h, str: e.text.trim(), size: e.size, font: e.font, bold: e.bold, italic: !!e.italic }));
            p.fields.forEach(fl => { if (!fbOverrides[fl.name]) items.push({ kind: 'field', name: fl.name, check: fl.checkbox, sig: fl.sig, x: fl.x, y: fl.y, w: fl.w, h: fl.h }); });
            Object.entries(fbOverrides).forEach(([name, ov]) => {
                if (ov.action === 'move' && ov.box.page === p.num) items.push({ kind: 'field', name, moved: true, check: !!(pdfFieldInfo(name) || {}).checkbox, x: ov.box.x, y: ov.box.y, w: ov.box.w, h: ov.box.h });
            });
            fbFields.filter(f => placeable(f) && f.box && f.box.page === p.num).forEach(f => items.push({ kind: 'box', key: f.key, x: f.box.x, y: f.box.y, w: f.box.w, h: f.box.h }));

            // Lines: things whose middles are level
            const lines = [];
            items.sort((a, b) => (a.y + a.h / 2) - (b.y + b.h / 2)).forEach(it => {
                const cy = it.y + it.h / 2, line = lines[lines.length - 1];
                if (line && Math.abs(cy - line.cy) < Math.max(0.006, Math.min(it.h, line.hMin) * 0.55)) {
                    line.items.push(it);
                    line.cy += (cy - line.cy) / line.items.length;
                    line.hMin = Math.min(line.hMin, it.h);
                } else lines.push({ cy, hMin: it.h, items: [it] });
            });
            const inserts = edits.filter(e => e.kind === 'row');
            lines.forEach(line => {
                // Parts: words next to each other make one label; the blank right after it belongs to it
                const tiles = [];
                line.items.sort((a, b) => a.x - b.x).forEach(it => {
                    const cur = tiles[tiles.length - 1], blank = it.kind === 'field' || it.kind === 'box';
                    const gap = cur ? it.x - Math.max(...tileParts(cur).map(q => q.x + q.w)) : 1;
                    if (!blank) {
                        if (cur && !cur.blank && gap < 0.025) cur.labels.push(it);
                        else if (cur && cur.blank && cur.blank.check && gap < (cur.labels.length ? 0.02 : 0.04)) cur.labels.push(it);   // a checkbox's words come after it
                        else tiles.push({ labels: [it], blank: null });
                    } else if (cur && !cur.blank && cur.labels.length && gap < 0.3) cur.blank = it;
                    else tiles.push({ labels: [], blank: it });
                });
                const box = unionOf(line.items);
                const ins = inserts.find(e => centreIn(box, finalArea(e)));
                const top = Math.min(...line.items.map(it => it.y)), bot = Math.max(...line.items.map(it => it.y + it.h * (it.kind === 'text' || it.kind === 'edit' ? 0.8 : 1)));
                rows.push({ page: p.num, y: box.y, h: box.h, top, bot, tiles, insert: ins ? ins.key : null, copied: line.items.every(it => it.copied) });
            });
            // Inserted lines with nothing in them yet
            inserts.forEach(e => {
                const a = finalArea(e);
                if (!rows.some(r => r.page === p.num && r.insert === e.key)) rows.push({ page: p.num, y: a.y, h: a.h, top: a.y, bot: a.y + a.h, tiles: [], insert: e.key, empty: true });
            });
        });
        return rows.sort((a, b) => a.page - b.page || a.y - b.y);
    }

    async function renderPdfRows() {
        const box = document.getElementById('pdfRows');
        const file = fbTemplate || FB_DEFAULT_TEMPLATE;
        if (pdDocFile !== file) box.innerHTML = '<p class="text-center py-10 text-sm font-black text-slate-400"><i class="fas fa-spinner fa-spin"></i> Reading the PDF form…</p>';
        if (!(await ensurePdfLoaded())) { box.innerHTML = '<p class="text-center py-10 text-sm font-black text-red-600">The PDF form could not be opened.</p>'; return; }
        pdRows = scanPdfRows();
        const selIndex = pdSelIndex();
        let html = '', page = 0;
        pdRows.forEach((r, i) => {
            if (r.page !== page) { page = r.page; html += `<p class="pdf-page-chip">Page ${page} of ${pdPages.length}</p>`; }
            html += pdfRowHtml(r, i, i === selIndex) + pdfGapHtml(i);
        });
        box.innerHTML = html || '<p class="text-center py-10 text-sm font-bold text-slate-400">No printed text was found on this PDF (it may be a scanned image). Use the tools above to draw on it instead.</p>';
        if (pdTile) renderPdfTile();
        if (pdHlPart) { const r = pdRows[pdSelIndex()], t = r && r.tiles[pdHlPart.j]; pdHlPart = t ? { ...unionOf(tileParts(t)), page: r.page, j: pdHlPart.j } : null; }
        if (designerOpen() && (pdSelRowAt || fbVersionDiff.length)) renderDesignerPages();
        if (pdScrollList) { pdScrollList = false; const el = box.querySelector('.pdf-row.sel'); if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    }

    function pdSelIndex() {
        if (!pdSelRowAt) return -1;
        return pdRows.findIndex(r => r.page === pdSelRowAt.page && pdSelRowAt.y >= r.y - 0.003 && pdSelRowAt.y <= r.y + r.h + 0.003);
    }
    function selectPdfRow(i, j = null) {
        const r = pdRows[i], t = j === null ? null : r.tiles[j];
        // A second click unselects, except on an empty line (it is about to be filled)
        const again = !r.empty && pdSelIndex() === i && (j === null || (pdHlPart && pdHlPart.j === j));
        pdSelRowAt = again ? null : { page: r.page, y: r.y + r.h / 2 };
        pdHlPart = !again && t ? { ...unionOf(tileParts(t)), page: r.page, j } : null;
        pdSelText = null;
        document.querySelectorAll('#pdfRows .pdf-row').forEach(el => {
            const on = !!pdSelRowAt && +el.dataset.row === i;
            el.classList.toggle('sel', on);
            el.querySelectorAll('.fb-tile').forEach((tile, k) => tile.classList.toggle('sel', on && !!pdHlPart && pdHlPart.j === k));
        });
        renderDesignerPages();
        if (pdSelRowAt) scrollDesignerTo(pdHlPart ? '.pd-hl-part' : '.pd-hl-row');
    }

    function pdfTileSub(t) {
        const b = t.blank, used = pdfUsage();
        if (!b) return t.labels.some(l => l.kind === 'edit') ? '<span class="text-amber-600">✎ Text you added or changed</span>' : 'Printed text';
        if (b.kind === 'box') { const f = findField(b.key); return `<span class="text-emerald-600">▭ Answer: “${esc(f.label || 'Untitled question')}”</span>`; }
        if (b.sig) return 'Signature — signed by hand';
        const owner = used[b.name], kind = b.check ? '☑ Checkbox' : 'Blank';
        if (owner) return `<span class="text-emerald-600">${kind} · filled by “${esc(owner.label || 'Untitled question')}”</span>`;
        if (activeTemplate().auto.includes(b.name)) return `<span class="text-blue-600">${kind} · filled from the student profile</span>`;
        return `<span class="text-amber-600">${kind} · nothing fills it yet</span>`;
    }

    function pdfRowHtml(r, i, sel) {
        const tiles = r.tiles.map((t, j) => {
            const title = tileText(t) || (t.blank ? (t.blank.kind === 'box' ? (findField(t.blank.key) || {}).label : humanize(t.blank.name)) : '') || 'Blank';
            return `<div class="fb-tile ${pdHlPart && sel && pdHlPart.j === j ? 'sel' : ''}" onclick="selectPdfRow(${i}, ${j})" ondblclick="editPdfTile(${i}, ${j})" title="${esc(title)}">
                <div class="min-w-0 flex-1">
                    <p class="fb-tile-title" style="font-size:14px">${esc(title)}</p>
                    <p class="fb-tile-sub">${pdfTileSub(t)}</p>
                </div>
                <button type="button" class="fb-tile-btn" onclick="event.stopPropagation(); editPdfTile(${i}, ${j})" title="Edit"><i class="fas fa-pen"></i></button>
                <button type="button" class="fb-tile-btn danger" onclick="event.stopPropagation(); deletePdfTile(${i}, ${j})" title="Remove from the PDF"><i class="fas fa-trash-can"></i></button>
            </div>`;
        }).join('') || `<div class="pdf-empty-tile" onclick="selectPdfRow(${i})">Blank space — hover just under it and press ＋, or drag an element here</div>`;
        const first = !rowAbove(i), last = !rowBelow(i);
        const changed = fbVersionDiff.some(d => d.page === r.page && d.y + d.h / 2 >= r.top - 0.004 && d.y + d.h / 2 <= r.bot + 0.004);
        return `<div class="fb-row pdf-row ${sel ? 'sel' : ''} ${r.insert ? 'added' : ''} ${changed ? 'diff' : ''}" data-row="${i}" ${changed ? 'title="Different from your form"' : ''}>
            <span class="fb-grip" draggable="true" title="Drag to move this line"><i class="fas fa-grip-vertical"></i></span>
            <span class="pdf-updown">
                <button type="button" onclick="moveLineBy(${i}, -1)" ${first ? 'disabled' : ''} title="Move this line up"><i class="fas fa-chevron-up"></i></button>
                <button type="button" onclick="moveLineBy(${i}, 1)" ${last ? 'disabled' : ''} title="Move this line down"><i class="fas fa-chevron-down"></i></button>
            </span>
            <div class="flex-1 min-w-0 flex flex-wrap gap-2">${tiles}</div>
            <div class="pdf-row-actions">
                ${r.tiles.length ? `<button type="button" class="fb-tile-btn" onclick="duplicatePdfRow(${i})" title="Add a copy of this line right below it (for one more table row)"><i class="fas fa-clone"></i></button>` : ''}
                <button type="button" class="fb-tile-btn danger" onclick="deletePdfRow(${i})" title="${r.insert ? 'Remove this added line' : 'Erase this whole line from the PDF'}"><i class="fas fa-${r.insert ? 'xmark' : 'eraser'}"></i></button>
            </div>
        </div>`;
    }

    // ---- adding between two lines: hover the gap under a line, press its ＋, pick what to add ----
    function pdfGapHtml(i) {
        const below = rowBelow(i);
        return `<div class="pdf-gap" data-gap="${i}">
            <span class="pdf-gap-line"></span>
            <button type="button" class="pdf-gap-btn" onclick="openGapMenu(event, ${i})" title="Add something ${below ? 'between these two lines' : 'under this line'}"><i class="fas fa-plus"></i></button>
        </div>`;
    }
    let pdGapAt = null;
    function openGapMenu(e, i, fromRibbon = false) {
        e.stopPropagation();
        pdGapAt = i;
        const pick = ['Text', 'Paragraph', 'Number', 'Date', 'YesNo', 'Dropdown'].filter(t => FB_TYPES[t]);
        const off = fbFields.filter(f => placeable(f) && !f.box && !f.pdf);
        const item = (spec, icon, label, sub) => `<button type="button" class="pdf-gap-item" onclick='gapAdd(${esc(JSON.stringify(JSON.stringify(spec)))})'>
            <i class="fas ${icon}"></i><span><b>${esc(label)}</b>${sub ? `<small>${esc(sub)}</small>` : ''}</span></button>`;
        const menu = document.getElementById('pdGapMenu');
        menu.innerHTML = `<p class="pdf-gap-title">${fromRibbon ? 'Insert under “' + esc((pdRows[i] && pdRows[i].tiles[0] ? tileText(pdRows[i].tiles[0]) : 'the last line').slice(0, 30)) + '”' : 'Add here'}</p>`
            + item({ pdf: 'empty' }, 'fa-arrows-up-down', 'Blank space', 'an empty line to fill later')
            + item({ pdf: 'text' }, 'fa-font', 'Printed text', 'words only')
            + item({ pdf: 'heading' }, 'fa-heading', 'Heading', 'bold words')
            + item({ pdf: 'field', type: 'Text' }, 'fa-pen-to-square', 'Question + answer blank', 'students answer it')
            + pick.filter(t => t !== 'Text').map(t => item({ pdf: 'field', type: t }, FB_TYPES[t].icon, FB_TYPES[t].label + ' question', 'with an answer blank')).join('')
            + (fromRibbon && pdRows[i] && pdRows[i].tiles.length ? item({ pdf: 'copyline' }, 'fa-clone', 'Copy of this line', 'one more table row') : '')
            + (off.length ? `<p class="pdf-gap-title">Questions not on the PDF yet</p>` + off.slice(0, 8).map(f => item({ pdf: 'place', key: f.key }, typeIcon(f.type), f.label || 'Untitled question', typeLabel(f.type))).join('') : '');
        menu.classList.remove('hidden');
        // Next to the ＋, kept on screen
        const r = e.currentTarget.getBoundingClientRect(), mw = menu.offsetWidth, mh = menu.offsetHeight;
        menu.style.left = clamp(r.left + r.width / 2 - mw / 2, 8, window.innerWidth - mw - 8) + 'px';
        menu.style.top = (r.bottom + 6 + mh > window.innerHeight - 8 ? Math.max(8, r.top - 6 - mh) : r.bottom + 6) + 'px';
        document.querySelectorAll('.pdf-gap.open').forEach(g => g.classList.remove('open'));
        const gap = document.querySelector(`.pdf-gap[data-gap="${i}"]`);
        if (gap) gap.classList.add('open');
    }
    function closeGapMenu() {
        pdGapAt = null;
        document.getElementById('pdGapMenu').classList.add('hidden');
        document.querySelectorAll('.pdf-gap.open').forEach(g => g.classList.remove('open'));
    }
    function gapAdd(json) {
        const i = pdGapAt;
        closeGapMenu();
        if (i === null || !pdRows[i]) return;
        if (JSON.parse(json).pdf === 'copyline') { duplicatePdfRow(i); return; }
        addPdfElement(json, { mode: 'after', row: i });
        if (JSON.parse(json).pdf === 'empty') showAlert('success', 'Blank space added. Hover it and press ＋, or drag an element onto it, to fill it.');
    }
    // Typing on the page ends with a click anywhere else (not on the ribbon)
    document.addEventListener('pointerdown', e => {
        if (pdTile && pdTile.inline && !e.target.closest('#pdInline, #pdRibbon')) finishInline(true);
    }, true);
    // Any click outside closes it (pointerdown, caught first: the PDF pages stop mouse events)
    document.addEventListener('pointerdown', e => {
        if (pdGapAt !== null && !e.target.closest('#pdGapMenu, .pdf-gap-btn')) closeGapMenu();
    }, true);

    // ---- the parts' pencil ----
    function editPdfTile(i, j) {
        const row = pdRows[i];
        pdSelRowAt = { page: row.page, y: row.y + row.h / 2 };
        finishInline(true);
        const tile = row.tiles[j], fmt = tileFormat(row, tile);
        pdTile = { row, tile, text: tileText(tile), fmt0: fmt && { ...fmt }, fmt, created: null };
        // An added text being edited: what it was, to go back to while trying things
        const own = tile.labels.length === 1 && tile.labels[0].kind === 'edit' ? findEdit(tile.labels[0].key) : null;
        if (own) pdTile.own = { key: own.key, before: editLook(own) };
        renderPdfTile();
        const input = document.getElementById('ptText');
        if (input) { input.focus(); input.select(); }
    }

    function renderPdfTile() {
        const modal = document.getElementById('pdfTileEditor'), show = !!pdTile && !pdTile.inline;   // typing on the page has no window
        modal.classList.toggle('hidden', !show);
        modal.classList.toggle('flex', show);
        if (!show) return;
        const { row, tile } = pdTile, b = tile.blank, used = pdfUsage();
        const label = 'text-[10px] font-black text-slate-400 uppercase tracking-widest';
        const input = 'mt-1 w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-blue-500 focus:bg-white';
        let body = `<div class="flex items-start justify-between gap-3 mb-4">
                <div><p class="${label}">Page ${row.page} · ${row.insert ? 'a line you added' : 'printed line'}</p><h3 class="text-xl font-black text-slate-900">Edit this part of the PDF</h3></div>
                <button type="button" onclick="closePdfTile(true)" class="w-9 h-9 rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-900" title="Close"><i class="fas fa-xmark"></i></button>
            </div>`;
        if (tile.labels.length) {
            const f = pdTile.fmt, sizes = [...new Set([6, 7, 8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 32, 36, f.size])].sort((a, b) => a - b);
            const toggle = (prop, label, mark) => `<button type="button" id="pt-${prop}" onclick="pdfTileToggle('${prop}')" aria-pressed="${f[prop]}" class="pt-fmt ${f[prop] ? 'on' : ''}">${mark} ${label}</button>`;
            body += `<label class="block ${label}">Printed words <span class="normal-case tracking-normal font-bold text-slate-400">(or click the words on the PDF and type there)</span>
                <input id="ptText" type="text" value="${esc(pdTile.typed ?? pdTile.text)}" oninput="pdfTileLive()" class="${input} normal-case tracking-normal" onkeydown="if (event.key === 'Enter') closePdfTile(true)">
            </label>
            <p class="text-[11px] text-slate-400 mt-1">Leave it empty to remove these words from the PDF. Changes show on the PDF as you make them.</p>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 mt-3">
                <label class="block ${label}">Font
                    <select id="ptFont" onchange="pdfTileLive()" class="${input} normal-case tracking-normal cursor-pointer">${Object.keys(PD_FONTS).map(k => `<option value="${k}" ${f.font === k ? 'selected' : ''} style="font-family:${PD_FONTS[k]}">${k === 'Times' ? 'Times (serif)' : k === 'Courier' ? 'Courier (typewriter)' : k}</option>`).join('')}</select>
                </label>
                <label class="block ${label}">Size
                    <select id="ptSize" onchange="pdfTileLive()" class="${input} normal-case tracking-normal cursor-pointer">${sizes.map(n => `<option value="${n}" ${f.size === n ? 'selected' : ''}>${n} pt</option>`).join('')}</select>
                </label>
                <label class="block ${label} col-span-2 sm:col-span-1">Colour
                    <span class="mt-1 flex items-center gap-2 p-1.5 bg-slate-50 border border-slate-200 rounded-xl">
                        <input id="ptColor" type="color" value="${esc(f.color)}" oninput="pdfTileLive()" class="w-10 h-8 rounded-lg cursor-pointer bg-transparent">
                        <span id="ptColorText" class="text-xs font-bold text-slate-600 normal-case tracking-normal">${esc(f.color)}</span>
                    </span>
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-2">
                ${toggle('bold', 'Bold', '<b>B</b>')}${toggle('italic', 'Italic', '<i>I</i>')}${toggle('underline', 'Underline', '<u>U</u>')}
                <select id="ptAlign" onchange="pdfTileLive()" class="pt-fmt cursor-pointer" title="Alignment">${['left', 'center', 'right'].map(a => `<option value="${a}" ${f.align === a ? 'selected' : ''}>${a === 'left' ? '⇤ Left' : a === 'center' ? '↔ Center' : 'Right ⇥'}</option>`).join('')}</select>
                <button type="button" onclick="pdfTileResetFormat()" class="ml-auto text-[11px] font-black text-slate-500 hover:text-slate-900" title="Back to how the words looked">↺ As it was</button>
            </div>
            <p id="ptFit" class="hidden mt-2 text-[11px] font-bold text-amber-700"></p>`;
        }
        if (b && b.kind === 'field' && !b.sig) {
            const owner = used[b.name];
            body += `<label class="block mt-4 ${label}">${b.check ? 'This checkbox' : 'This blank'} is filled with
                <select onchange="pdfTileFill(this.value)" class="${input} normal-case tracking-normal">
                    <option value="">${activeTemplate().auto.includes(b.name) ? 'The student profile (automatic)' : '— Nothing —'}</option>
                    ${fbFields.filter(placeable).map(f => `<option value="${f.key}" ${owner && owner.key === f.key ? 'selected' : ''}>${esc(f.label || 'Untitled question')}</option>`).join('')}
                </select>
            </label>
            ${owner ? '' : `<button type="button" onclick="pdfTileNewQuestion()" class="mt-2 px-3 py-2 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-xs font-black hover:bg-blue-600 hover:text-white"><i class="fas fa-plus"></i> New question that fills it</button>`}`;
        } else if (b && b.kind === 'box') {
            const f = findField(b.key);
            body += `<label class="block mt-4 ${label}">The question whose answer prints here
                <input type="text" value="${esc(f.label)}" oninput="updateField('${f.key}', 'label', this.value)" class="${input} normal-case tracking-normal">
            </label>
            <p class="text-[11px] text-slate-400 mt-1">${esc(typeLabel(f.type))} · students answer it on the application form.</p>`;
        } else if (b && b.sig) {
            body += '<p class="mt-4 text-sm text-slate-500">A signature blank: it is signed by hand on the printed form.</p>';
        } else {
            body += `<button type="button" onclick="pdfTileAddBlank()" class="mt-4 px-3 py-2 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-xs font-black hover:bg-blue-600 hover:text-white"><i class="fas fa-plus"></i> Add an answer blank after these words</button>`;
        }
        body += `<div class="flex flex-wrap items-center justify-between gap-2 mt-6 pt-4 border-t border-slate-100">
                <button type="button" onclick="deletePdfTile(null)" class="px-4 py-2.5 rounded-xl bg-red-50 text-red-600 text-xs font-black hover:bg-red-600 hover:text-white"><i class="fas fa-trash-can"></i> Remove from the PDF</button>
                <span class="flex gap-2">
                    <button type="button" onclick="cancelPdfTile()" class="px-4 py-2.5 rounded-xl bg-slate-100 text-slate-600 text-xs font-black uppercase tracking-widest hover:bg-slate-200">Cancel</button>
                    <button type="button" onclick="closePdfTile(true)" class="px-6 py-2.5 rounded-xl bg-blue-600 text-white text-xs font-black uppercase tracking-widest hover:bg-blue-700">Done</button>
                </span>
            </div>`;
        if (pdTile.msg) body = `<div class="mb-4 px-4 py-3 rounded-xl text-xs font-black ${pdTile.msg.ok ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700'}">${pdTile.msg.ok ? '✅' : '⚠️'} ${esc(pdTile.msg.text)}</div>` + body;
        const focused = document.activeElement && document.activeElement.id === 'ptText' ? document.getElementById('ptText').value : null;
        document.getElementById('pdfTileEditorBody').innerHTML = body;
        // Keep what was being typed (only when the same part is still shown: another part may have no words box)
        const el = focused !== null ? document.getElementById('ptText') : null;
        if (el) { el.value = focused; el.focus(); }
    }

    // Takes back what the window showed on the PDF while trying things
    function revertPdfTile() {
        if (!pdTile) return;
        if (pdTile.created) { fbEdits = fbEdits.filter(e => e.key !== pdTile.created); pdTile.created = null; }
        if (pdTile.own) { const e = findEdit(pdTile.own.key); if (e) Object.assign(e, pdTile.own.before); }
    }
    function cancelPdfTile() {
        revertPdfTile();
        pdTile = null;
        renderPdfTile();
        render();
    }
    function closePdfTile(apply) {
        if (!pdTile) { renderPdfTile(); return; }   // nothing open: just make sure the window is closed
        if (apply) applyPdfTileText();
        pdTile = null;
        renderPdfTile();
        render();
    }

    // ---- the part editor's words and their look (font, size, bold, italic, colour) ----
    // The window can apply again and again (it shows on the PDF as you type): each time it first takes back what it put
    // on the PDF, then puts the words as they are now. Printed words get one replacing text (over the colour behind them);
    // words added earlier are just changed.
    function readTileFormat() {
        if (!pdTile || !pdTile.fmt || pdTile.inline) return;
        const val = id => document.getElementById(id);
        if (val('ptAlign')) pdTile.fmt.align = val('ptAlign').value;
        if (val('ptFont')) pdTile.fmt.font = val('ptFont').value;
        if (val('ptSize')) pdTile.fmt.size = Number(val('ptSize').value);
        if (val('ptColor')) { pdTile.fmt.color = val('ptColor').value; val('ptColorText').textContent = pdTile.fmt.color; }
    }
    function pdfTileToggle(prop) {
        pdTile.fmt[prop] = !pdTile.fmt[prop];
        const b = document.getElementById('pt-' + prop);
        b.classList.toggle('on', pdTile.fmt[prop]);
        b.setAttribute('aria-pressed', pdTile.fmt[prop]);
        pdfTileLive();
    }
    function pdfTileResetFormat() {
        pdTile.fmt = { ...pdTile.fmt0 };
        const input = document.getElementById('ptText'), text = input ? input.value : null;
        renderPdfTile();
        if (text !== null) document.getElementById('ptText').value = text;
        pdfTileLive();
    }
    function pdfTileLive() {
        applyPdfTileText(false);
        if (designerOpen()) renderDesignerPages();
    }

    // How wide words are on the page (fraction of its width), in a font / size / style
    function textWidthOnPage(page, text, fmt) {
        const p = pdPages[page - 1];
        const ctx = textWidthOnPage.ctx || (textWidthOnPage.ctx = document.createElement('canvas').getContext('2d'));
        ctx.font = `${fmt.italic ? 'italic ' : ''}${fmt.bold ? 'bold ' : ''}${fmt.size}px ${PD_FONTS[fmt.font] || PD_FONTS.Helvetica}`;
        return ctx.measureText(text).width / (p.hPt * p.source.width / p.source.height);
    }

    // How an added text looks and sits (to go back to while trying things)
    const editLook = e => ({ text: e.text, font: e.font, size: e.size, bold: e.bold, italic: !!e.italic, underline: !!e.underline, align: e.align || 'left', color: e.color, x: e.x, w: e.w, h: e.h, y: e.y });
    const LOOK_KEYS = ['font', 'size', 'bold', 'italic', 'underline', 'align', 'color'];
    // A box of width w for words that were in `area`, kept where the alignment keeps them (left edge, middle or right edge)
    function alignBox(area, w, align) {
        const x = align === 'center' ? area.x + area.w / 2 - w / 2 : align === 'right' ? area.x + area.w - w : area.x;
        return clamp(x, 0, Math.max(0, 1 - w));
    }

    // value: what was typed on the page (else the window's words box)
    function applyPdfTileText(final = true, value) {
        const input = value === undefined ? document.getElementById('ptText') : null;
        if ((value === undefined && !input) || !pdTile) return;
        readTileFormat();
        const raw = value !== undefined ? value : input.value;
        pdTile.typed = raw;
        const { row, tile } = pdTile, text = raw.trim(), fmt = pdTile.fmt, f0 = pdTile.fmt0;
        const unchanged = text === pdTile.text && LOOK_KEYS.every(k => fmt[k] === f0[k]);
        const fit = document.getElementById('ptFit');
        if (fit) fit.classList.add('hidden');
        // take back what this window put on the PDF so far
        if (pdTile.created) { fbEdits = fbEdits.filter(e => e.key !== pdTile.created); pdTile.created = null; }
        if (pdTile.own) {
            const e = findEdit(pdTile.own.key);
            if (!e) return;
            Object.assign(e, pdTile.own.before);
            // A paragraph is re-wrapped when it is done
            if (pdTile.own.before.text.includes('\n')) {
                if (final) commitParagraph({ ...pdTile, inline: { paragraph: true, width: e.w, rect: { x: e.x, y: e.y, w: e.w, h: e.h } } }, raw);
                return;
            }
            if (!text) {
                if (final) { fbEdits = fbEdits.filter(x => x !== e); markDirty(); }
                else e.text = '';   // hidden while the box is empty
                return;
            }
            if (unchanged) { if (final) markDirty(); return; }
            const grow = fmt.size / (pdTile.own.before.size || fmt.size);
            const h = Math.max(e.h, e.h * grow);
            const w = clamp(Math.max(e.w * 0.5, textWidthOnPage(row.page, text, fmt) + 0.006), 0.01, 1);
            Object.assign(e, { text, font: fmt.font, size: fmt.size, bold: fmt.bold, italic: fmt.italic, underline: fmt.underline, align: fmt.align, color: fmt.color,
                y: e.y - (h - e.h) / 2, h, w, x: alignBox(pdTile.own.before, w, fmt.align) });
            if (e.cover) trimCover(e);   // a bigger cover still stops at the words around it
            if (final) { syncQuestionLabel(text); markDirty(); }
            return;
        }
        if (unchanged) return;
        if (final) syncQuestionLabel(text);
        const area = textArea(row.page, unionOf(tile.labels)), first = tile.labels.find(l => l.kind !== 'edit') || tile.labels[0];
        fbEdits = fbEdits.filter(e => !tile.labels.some(l => l.kind === 'edit' && l.key === e.key));   // words added there before are replaced
        if (!text) {
            pdTile.created = pushEdit({ kind: 'erase', ...area, text: '', font: 'Helvetica', size: null, bold: false, cover: false }).key;
        } else {
            // Bigger letters get a taller box (kept around the same middle); longer wording gets room to the right, up to
            // whatever comes next on the line (wording that still doesn't fit prints smaller)
            const grow = fmt.size / (first.size || fmt.size);
            if (grow > 1) { const h = area.h * grow; area.y = Math.max(0, area.y - (h - area.h) / 2); area.h = h; }
            const next = Math.min(1, ...row.tiles.flatMap(tileParts).filter(q => q.x > area.x + area.w - 0.002 && !tile.labels.includes(q)).map(q => q.x));
            const needed = textWidthOnPage(row.page, text, fmt) + 0.006, room = Math.max(area.w, next - area.x - 0.003);
            if (needed > room && fit) { fit.textContent = '⚠ Too long for its spot at this size: it prints a little smaller to fit. Shorter words or a smaller size avoid that.'; fit.classList.remove('hidden'); }
            // The box keeps covering the old words; centered / right-aligned words stay around the middle / right edge
            const w = clamp(Math.min(needed, room), area.w, 1 - area.x);
            const x = fmt.align === 'left' ? area.x : Math.min(area.x, alignBox(area, w, fmt.align));
            pdTile.created = pushEdit({ kind: 'text', ...area, x, w: Math.max(w, area.x + area.w - x), text, font: fmt.font, size: fmt.size, bold: fmt.bold, italic: fmt.italic,
                underline: fmt.underline, align: fmt.align, color: fmt.color, cover: true }).key;
        }
        if (final) markDirty();
    }
    // Renaming the printed label of a question's blank renames the question too (when they matched)
    function syncQuestionLabel(text) {
        const bare = v => v.replace(/[:*]+$/, '').trim(), tile = pdTile.tile;
        const q = tile.blank && tile.blank.kind === 'box' ? findField(tile.blank.key) : null;
        if (q && text && (q.label === bare(pdTile.text) || q.label === 'New question')) q.label = bare(text);
    }

    function pdfTileFill(key) {
        pdSelField = pdTile.tile.blank.name;
        fillPdfFieldWith(key);
        pdSelField = null;
        render();
    }

    // Where a new question goes in the form: after the question that fills the blank just above it on the PDF
    function questionIndexAt(page, y) {
        let at = -1;
        pdRows.filter(r => r.page < page || (r.page === page && r.y < y)).forEach(r => r.tiles.forEach(t => {
            const b = t.blank, f = b && (b.kind === 'box' ? findField(b.key) : pdfUsage()[b.name]);
            if (f) at = Math.max(at, findIndex(f.key));
        }));
        return at >= 0 ? at + 1 : fbFields.length;
    }
    function addQuestionAt(f, page, y) {
        fbFields.splice(questionIndexAt(page, y), 0, f);
        return f;
    }

    function pdfTileNewQuestion() {
        const { row, tile } = pdTile, b = tile.blank;
        const owner = pdfUsage()[b.name];
        if (owner) owner.pdf = '';
        addQuestionAt(makeField({ type: b.check ? 'YesNo' : 'Text', label: tileText(tile).replace(/[:*]+$/, '').trim() || humanize(b.name), pdf: b.name }), row.page, row.y);
        pdTile.msg = { ok: true, text: 'Question added. Students answer it on the application form, and the answer prints in this blank.' };
        markDirty();
        render();
    }

    // A question whose answer prints after these words: on the same line when there is room, else on a new line under them.
    // An answer line is printed under it, so the blank shows on the paper form too.
    function pdfTileAddBlank() {
        const { row, tile } = pdTile;
        const start = Math.min(...tile.labels.map(l => l.x)), end = Math.max(...tile.labels.map(l => l.x + l.w));
        const next = Math.min(0.95, ...row.tiles.flatMap(tileParts).filter(q => q.x > end).map(q => q.x));
        const ink = (pageColors(row.page, unionOf(tile.labels)) || {}).color;
        // The words become the question's name, unless they are a whole sentence
        const words = tileText(tile).replace(/[:*]+$/, '').trim();
        const f = addQuestionAt(makeField({ type: 'Text', label: words && words.length <= 60 ? words : 'New question' }), row.page, row.y);
        let where;
        if (next - end >= 0.12) {
            f.box = { page: row.page, x: end + 0.006, y: row.y, w: Math.min(0.4, next - end - 0.012), h: Math.max(0.012, row.h), size: null };
            where = 'after these words';
        } else {
            const i = pdRows.findIndex(r => r.page === row.page && Math.abs(r.y - row.y) < 1e-6);
            const at = cutUnder(i >= 0 ? i : pdRows.length - 1, 'mid');
            const ins = addLineInsert({ kind: 'row', page: row.page, x: 0, y: Math.max(0, at - LINE_H), w: 1, h: Math.min(LINE_H, at), text: '', font: 'Helvetica', size: null, bold: false, cover: false });
            const a = finalArea(ins);
            f.box = { page: row.page, x: start, y: a.y + 0.003, w: Math.min(0.6, 0.95 - start), h: LINE_H - 0.006, size: null };
            where = 'on a new line under these words (there was no room after them)';
        }
        addAnswerLine(f.box, ink);
        markDirty();
        pdTile = null;
        pdSelRowAt = { page: f.box.page, y: f.box.y + f.box.h / 2 };
        pdHlPart = null;
        render();
        const done = `Answer blank added ${where}. Name its question below; students answer it on the application form and the answer prints on that line.`;
        showAlert('success', done);
        // Straight on to the new blank, to name its question
        setTimeout(() => {
            const i = pdRows.findIndex(r => r.tiles.some(t => t.blank && t.blank.key === f.key));
            if (i < 0) { renderPdfTile(); return; }
            const j = pdRows[i].tiles.findIndex(t => t.blank && t.blank.key === f.key);
            selectPdfRow(i, j);
            editPdfTile(i, j);
            pdTile.msg = { ok: true, text: done };
            renderPdfTile();
            const q = document.querySelector('#pdfTileEditorBody input[oninput^="updateField"]');
            if (q) { q.focus(); q.select(); }
        }, 80);
    }

    // The printed line of an answer blank, along the bottom of its box
    function addAnswerLine(box, color = null) {
        return pushEdit({ kind: 'line', page: box.page, x: box.x, y: box.y + box.h - 0.0007, w: box.w, h: 0.0012, text: '', font: 'Helvetica', size: null, bold: false, cover: false, ...(color ? { fill: color } : {}) });
    }
    const answerLinesOf = box => fbEdits.filter(e => e.kind === 'line' && e.page === box.page && e.x + e.w / 2 >= box.x && e.x + e.w / 2 <= box.x + box.w
        && e.y >= box.y + box.h * 0.4 && e.y <= box.y + box.h + 0.006);

    // ---- removing ----
    function removeTileParts(page, tile) {
        const added = tile.labels.filter(l => l.kind === 'edit').map(l => l.key), printed = tile.labels.filter(l => l.kind !== 'edit');
        fbEdits = fbEdits.filter(e => !added.includes(e.key));
        if (printed.length) pushEdit({ kind: 'erase', ...textArea(page, unionOf(printed)), text: '', font: 'Helvetica', size: null, bold: false, cover: false });
        const b = tile.blank;
        if (b && b.kind === 'box') {
            const f = findField(b.key), lines = answerLinesOf(f.box);
            fbEdits = fbEdits.filter(e => !lines.includes(e));
            f.box = null;
        }
        if (b && b.kind === 'field' && !b.sig) {
            const owner = pdfUsage()[b.name];
            if (owner) owner.pdf = '';
            fbOverrides[b.name] = { action: 'hide' };
        }
    }
    function deletePdfTile(i, j) {
        const { row, tile } = i === null ? pdTile : { row: pdRows[i], tile: pdRows[i].tiles[j] };
        const what = tileText(tile) || 'this blank';
        if (i === null) revertPdfTile();
        const note = tile.blank && tile.blank.kind === 'box' ? '\n\nThe question stays on the application form; its answer just will not print.' : '';
        if (!confirm(`Remove “${what}” from the PDF?${note}`)) return;
        removeTileParts(row.page, tile);
        pdTile = null;
        markDirty();
        renderPdfTile();
        render();
    }
    function deletePdfRow(i) {
        const row = pdRows[i];
        if (row.insert) { deleteInsert(row.insert); render(); return; }
        if (!confirm('Erase this whole line from the PDF? Its words are whited out and its blanks are removed (questions stay on the application form).')) return;
        row.tiles.forEach(t => removeTileParts(row.page, t));
        markDirty();
        render();
    }

    // ---- adding ----
    // A copy of a line right under it; its blanks get new questions in the same spots (one more row of a table)
    function duplicatePdfRow(i) {
        const row = pdRows[i];
        const top = cutOver(i), bottom = cutUnder(i, 'next');
        const ins = { kind: 'row', page: row.page, x: 0, y: top, w: 1, h: Math.max(0.008, bottom - top), text: '', font: 'Helvetica', size: null, bold: false, cover: true, lineX: [0.05, 0.9] };
        addLineInsert(ins);
        let at = questionIndexAt(row.page, row.y + row.h);
        row.tiles.filter(t => t.blank && !t.blank.sig).forEach(t => {
            const b = t.blank, src = b.kind === 'box' ? findField(b.key) : pdfUsage()[b.name];
            const f = makeField({ type: src ? src.type : (b.check ? 'YesNo' : 'Text'), label: (tileText(t).replace(/[:*]+$/, '').trim() || (src && src.label) || humanize(b.name || 'Answer')) + ' (2)' });
            f.box = { page: row.page, x: b.x, y: b.y + ins.h, w: b.w, h: b.h, size: null };
            fbFields.splice(at++, 0, f);
        });
        pdSelRowAt = { page: row.page, y: row.y + row.h / 2 + ins.h };
        markDirty();
        render();
    }

    const leftMargin = p => clamp(Math.min(0.2, ...p.texts.map(t => t.x)), 0.03, 0.2);
    function pdfTextWidth(p, text, size, bold) {
        const pagePtWide = p.hPt * p.source.width / p.source.height;
        return Math.min(0.85, text.length * size * (bold ? 0.6 : 0.53) / pagePtWide + 0.008);
    }

    // Adds a Form element to the PDF: on a new line under a line ("after"), or on a line next to what is there ("beside")
    function addPdfElement(json, target = null) {
        const spec = JSON.parse(json);
        if (!pdRows.length) { showAlert('error', 'The PDF form is still loading.'); return; }
        const sel = pdSelIndex();
        target = target || { mode: 'after', row: sel >= 0 ? sel : pdRows.length - 1 };
        const row = pdRows[target.row], p = pdPages[row.page - 1];
        const blank = spec.pdf === 'field' || spec.pdf === 'place';
        let slot;
        if (spec.pdf !== 'empty' && (target.mode === 'beside' || row.empty)) {
            const end = row.tiles.length ? Math.max(...row.tiles.flatMap(tileParts).map(q => q.x + q.w)) + 0.02 : leftMargin(p);
            if (0.95 - end < (blank ? 0.22 : 0.1)) { showAlert('error', 'There is no room left on that line. Drop it between two lines to put it on a new line.'); return; }
            slot = { page: row.page, x: end, y: row.y + row.h / 2 - Math.max(row.h, 0.016) / 2, h: Math.max(row.h, 0.016) };
        } else {
            // A new empty line under the row: everything below moves down (a long page continues on a new page)
            const at = cutUnder(target.row, 'mid');
            const ins = { kind: 'row', page: row.page, x: 0, y: Math.max(0, at - LINE_H), w: 1, h: Math.min(LINE_H, at), text: '', font: 'Helvetica', size: null, bold: false, cover: false };
            addLineInsert(ins);
            slot = { page: row.page, x: leftMargin(p), y: finalArea(ins).y, h: LINE_H };
        }
        pdSelRowAt = { page: slot.page, y: slot.y + slot.h / 2 };
        if (spec.pdf === 'empty') { markDirty(); render(); return; }

        const size = spec.pdf === 'heading' ? 11 : 10, bold = spec.pdf === 'heading';
        const th = size * 1.35 / p.hPt, ty = slot.y + (slot.h - th) / 2;
        let label, f = null;
        if (blank) {
            f = spec.pdf === 'place' ? findField(spec.key) : addQuestionAt(makeField({ type: spec.type, label: spec.label || (isAuto(spec.type) ? FB_AUTO_TYPES[spec.type] : 'New question') }), slot.page, slot.y);
            label = (f.label || 'Question') + ':';
        } else label = spec.pdf === 'heading' ? 'NEW HEADING' : 'New text';
        const tw = pdfTextWidth(p, label, size, bold);
        const textEdit = pushEdit({ kind: 'text', page: slot.page, x: slot.x, y: ty, w: Math.min(tw, 1 - slot.x), h: th, text: label, font: 'Helvetica', size, bold, cover: false });
        if (f) {
            const bx = slot.x + tw + 0.004;
            f.pdf = '';
            f.box = { page: slot.page, x: bx, y: slot.y + 0.002, w: clamp(0.95 - bx, 0.06, f.type === 'YesNo' ? 0.1 : 0.35), h: Math.max(0.012, slot.h - 0.004), size: null };
            addAnswerLine(f.box);
        }
        markDirty();
        render();
        // Type it right away, on the page
        setTimeout(() => startInlineOnEdit(textEdit.key, { blank: f ? f.key : null, selectAll: true }), 60);
    }

    // Form elements for the PDF tab
    function renderPdfPalette() {
        const pick = ['Text', 'Paragraph', 'Number', 'Date', 'YesNo', 'Dropdown'].filter(t => FB_TYPES[t]);
        const off = fbFields.filter(f => placeable(f) && !f.box && !f.pdf);
        document.getElementById('pdPalette').innerHTML =
            elementGroup('Text and questions', [
                elementButton({ pdf: 'field', type: 'Text' }, 'fa-pen-to-square', 'Question + answer blank', 'students answer it', 'addPdfElement'),
                ...pick.filter(t => t !== 'Text').map(t => elementButton({ pdf: 'field', type: t }, FB_TYPES[t].icon, FB_TYPES[t].label + ' question', 'with an answer blank', 'addPdfElement')),
                elementButton({ pdf: 'text' }, 'fa-font', 'Printed text', 'words only', 'addPdfElement'),
                elementButton({ pdf: 'heading' }, 'fa-heading', 'Heading', 'bold words', 'addPdfElement'),
                elementButton({ pdf: 'empty' }, 'fa-arrows-up-down', 'Blank space (empty line)', 'room to fill later', 'addPdfElement'),
            ].join(''), true)
            + elementGroup(`Questions not on the PDF yet (${off.length})`, off.map(f => elementButton({ pdf: 'place', key: f.key }, typeIcon(f.type), f.label || 'Untitled question', typeLabel(f.type), 'addPdfElement')).join('')
                || '<p class="text-[11px] text-slate-400 px-1">Every question prints somewhere on the PDF. 🎉</p>', off.length > 0)
            + elementGroup('Student profile (filled in for them)', Object.keys(FB_AUTO_TYPES).map(t => elementButton({ pdf: 'field', type: t }, 'fa-bolt', FB_AUTO_TYPES[t], '', 'addPdfElement')).join(''));
    }

    // Dragging a PDF element: between two lines = a new line, onto a line = next to what is there
    function pdfDropTarget(e) {
        const rows = [...document.querySelectorAll('#pdfRows .pdf-row')];
        if (!rows.length) return null;
        for (const el of rows) {
            const r = el.getBoundingClientRect(), i = +el.dataset.row;
            if (e.clientY < r.top || e.clientY > r.bottom) continue;
            const fy = (e.clientY - r.top) / r.height;
            if (fbDrag.spec.pdf !== 'empty' && (pdRows[i].empty || (fy > 0.28 && fy < 0.72))) return { mode: 'beside', row: i, rect: r };
            if (fy >= 0.5) return { mode: 'after', row: i, rect: r, edge: 'bottom' };
            return i > 0 ? { mode: 'after', row: i - 1, rect: rows[i - 1].getBoundingClientRect(), edge: 'bottom' } : { mode: 'after', row: 0, rect: r, edge: 'bottom' };
        }
        let best = 0;
        rows.forEach((el, k) => { if (el.getBoundingClientRect().top < e.clientY) best = k; });
        return { mode: 'after', row: +rows[best].dataset.row, rect: rows[best].getBoundingClientRect(), edge: 'bottom' };
    }
    const pdfRowsEl = () => document.getElementById('pdfRows');
    // Dragging a line: it goes before the line under the pointer's upper half, or after the one under its lower half
    function pdfLineTarget(e) {
        const rows = [...document.querySelectorAll('#pdfRows .pdf-row')];
        let j = pdRows.length, rect = rows.length ? rows[rows.length - 1].getBoundingClientRect() : null, edge = 'bottom';
        for (const el of rows) {
            const r = el.getBoundingClientRect();
            if (e.clientY < r.top + r.height / 2) { j = +el.dataset.row; rect = r; edge = 'top'; break; }
        }
        return rect ? { j, rect, edge } : null;
    }
    document.addEventListener('dragover', e => {
        if (!fbDrag || fbDrag.kind !== 'pdfline') return;
        if (!pdfRowsEl().contains(e.target)) { showDropMark(null); return; }
        e.preventDefault();
        const t = pdfLineTarget(e);
        showDropMark(t && t.j !== fbDrag.row && t.j !== fbDrag.row + 1 ? { mode: 'row', rect: t.rect, edge: t.edge } : null);
    });
    document.addEventListener('drop', e => {
        if (!fbDrag || fbDrag.kind !== 'pdfline' || !pdfRowsEl().contains(e.target)) return;
        e.preventDefault();
        const t = pdfLineTarget(e), from = fbDrag.row;
        showDropMark(null);
        if (t) moveLineTo(from, t.j);
    });
    document.addEventListener('dragover', e => {
        if (!fbDrag || fbDrag.kind !== 'pdf') return;
        const over = pdfRowsEl().contains(e.target);
        if (!over) { showDropMark(null); return; }
        e.preventDefault();
        const t = pdfDropTarget(e);
        if (!t) return;
        showDropMark(t.mode === 'beside'
            ? { mode: 'beside', side: 'right', rect: { left: t.rect.left, right: t.rect.right - 70, top: t.rect.top + 6, height: t.rect.height - 12 } }
            : { mode: 'row', rect: t.rect, edge: 'bottom' });
    });
    document.addEventListener('drop', e => {
        if (!fbDrag || fbDrag.kind !== 'pdf' || !pdfRowsEl().contains(e.target)) return;
        e.preventDefault();
        const t = pdfDropTarget(e), json = fbDrag.json;
        showDropMark(null);
        if (t) addPdfElement(json, t);
    });


    // ================= TYPING ON THE PAGE (like a word processor) =================
    // Click words on the PDF (Select & type) or double-click them (any tool) and type right there. The ribbon sets the look
    // of what is being typed, or of the added text that is selected. Lines that run on into each other are a paragraph:
    // typed as one and wrapped again within their width; when it needs more lines, the rest of the form moves down.
    const lineHeightOf = (p, size) => size * 1.2 / p.hPt;
    const RIBBON_SIZES = [6, 7, 8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 32, 36];

    // The look of a part's words (font, size, bold, italic, underline, alignment, colour)
    function tileFormat(row, tile) {
        const first = tile.labels.find(l => l.kind !== 'edit') || tile.labels[0];
        if (!first) return null;
        const own = first.kind === 'edit' ? findEdit(first.key) : null, src = own || first;
        return { font: src.font || 'Helvetica', size: Math.round(((own && own.size) || first.size || 10) * 2) / 2, bold: !!src.bold, italic: !!src.italic,
            underline: !!(own && own.underline), align: (own && own.align) || 'left',
            color: (own && own.color) || (pageColors(row.page, unionOf(tile.labels)) || {}).color || '#000000' };
    }

    // Lines of words, at most `width` wide (line breaks typed with Shift+Enter are kept)
    function wrapText(page, text, fmt, width) {
        const out = [];
        text.split('\n').forEach(par => {
            const words = par.split(/\s+/).filter(Boolean);
            if (!words.length) { out.push(''); return; }
            let line = words[0];
            words.slice(1).forEach(w => {
                if (textWidthOnPage(page, line + ' ' + w, fmt) <= width) line += ' ' + w;
                else { out.push(line); line = w; }
            });
            out.push(line);
        });
        return out;
    }

    // The lines around line i that make one paragraph: words only, starting at the same place, the same size, close
    // together, each running on to the next (a line that is not full ends the paragraph)
    function paragraphRows(i) {
        const words = r => r && r.tiles.length === 1 && !r.tiles[0].blank && r.tiles[0].labels.length && r.tiles[0].labels.every(l => l.kind === 'text');
        if (!words(pdRows[i])) return [i];
        const ls = r => r.tiles[0].labels, left = r => Math.min(...ls(r).map(l => l.x)), right = r => Math.max(...ls(r).map(l => l.x + l.w));
        const size = r => ls(r)[0].size || 10, full = r => right(r) - left(r) > 0.45;
        const together = (a, b) => a.page === b.page && Math.abs(left(a) - left(b)) < 0.03 && Math.abs(size(a) - size(b)) < 0.6
            && b.y - a.y > 0 && b.y - a.y < size(a) * 2.1 / pdPages[a.page - 1].hPt;
        let top = i, bottom = i;
        while (top > 0 && words(pdRows[top - 1]) && full(pdRows[top - 1]) && together(pdRows[top - 1], pdRows[top])) top--;
        while (words(pdRows[bottom + 1]) && full(pdRows[bottom]) && together(pdRows[bottom], pdRows[bottom + 1])) bottom++;
        return Array.from({ length: bottom - top + 1 }, (_, k) => top + k);
    }

    // The part under a point of a page
    function tileAt(page, pt) {
        for (let i = 0; i < pdRows.length; i++) {
            const r = pdRows[i];
            if (r.page !== page) continue;
            for (let j = 0; j < r.tiles.length; j++)
                if (r.tiles[j].labels.some(l => pt.x >= l.x - 0.004 && pt.x <= l.x + l.w + 0.004 && pt.y >= l.y - 0.004 && pt.y <= l.y + l.h + 0.004)) return { i, j };
        }
        return null;
    }

    function startInlineFromTile(i, j, opts = {}) {
        finishInline(true);
        const row = pdRows[i], tile = row && row.tiles[j];
        if (!tile || !tile.labels.length) return;
        const own = tile.labels.length === 1 && tile.labels[0].kind === 'edit' ? findEdit(tile.labels[0].key) : null;
        if (own) { startInlineOnEdit(own.key, { ...opts, blank: tile.blank && tile.blank.kind === 'box' ? tile.blank.key : null }); return; }
        const fmt = tileFormat(row, tile);
        pdSelRowAt = { page: row.page, y: row.y + row.h / 2 };
        const block = tile.blank || row.tiles.length > 1 ? [i] : paragraphRows(i);
        if (block.length > 1) {
            const labels = block.flatMap(k => pdRows[k].tiles[0].labels), u = unionOf(labels);
            startSession({ row, tile: { labels, blank: null }, text: block.map(k => tileText(pdRows[k].tiles[0])).join(' '), fmt, paragraph: true,
                rect: textArea(row.page, u), width: u.w + 0.012, ...opts });
            return;
        }
        startSession({ row, tile, text: tileText(tile), fmt, paragraph: false, rect: textArea(row.page, unionOf(tile.labels)), ...opts });
    }

    function startInlineOnEdit(key, opts = {}) {
        finishInline(true);
        const e = findEdit(key);
        if (!e || e.kind !== 'text') return;
        const p = pdPages[e.page - 1], paragraph = e.text.includes('\n');
        let blank = opts.blank;
        if (blank === undefined) {   // the question whose words these are (renaming them renames it)
            const r = pdRows.find(r => r.tiles.some(t => t.labels.some(l => l.key === key)));
            const t = r && r.tiles.find(t => t.labels.some(l => l.key === key));
            blank = t && t.blank && t.blank.kind === 'box' ? t.blank.key : null;
        }
        const fmt = { font: e.font || 'Helvetica', size: Math.round((e.size || Math.min(14, Math.max(6, e.h * p.hPt * 0.75))) * 2) / 2, bold: !!e.bold, italic: !!e.italic,
            underline: !!e.underline, align: e.align || 'left', color: e.color || '#000000' };
        pdSelEdit = key;
        startSession({ row: { page: e.page, y: e.y, h: e.h, tiles: [] }, tile: { labels: [{ kind: 'edit', key, x: e.x, y: e.y, w: e.w, h: e.h, str: e.text }], blank: blank ? { kind: 'box', key: blank } : null },
            text: paragraph ? e.text.replace(/\n/g, ' ') : e.text, fmt, paragraph, rect: { x: e.x, y: e.y, w: e.w, h: e.h }, width: e.w,
            own: { key, before: editLook(e) }, fill: e.cover ? (e.fill || '#ffffff') : 'transparent', ...opts });
    }

    function startSession(o) {
        pdTile = { row: o.row, tile: o.tile, text: o.text, fmt0: { ...o.fmt }, fmt: { ...o.fmt }, created: null, own: o.own || null,
            inline: { paragraph: !!o.paragraph, rect: o.rect, width: o.width || o.rect.w, hideKey: o.own ? o.own.key : null,
                fill: o.fill !== undefined ? o.fill : ((pageColors(o.row.page, o.rect) || {}).fill || '#ffffff'),
                newLine: o.newLine || null, prevRow: o.prevRow || null, selectAll: !!o.selectAll } };
        if (pdMode !== 'content') setDesignerMode('content', false);
        if (pdTool !== 'select') setContentTool('select', false);
        if (o.own) pdSelEdit = o.own.key;   // (switching tools clears the selection)
        renderDesignerPages();
        const page = pdPages[o.row.page - 1], el = document.createElement('div');
        el.className = 'pd-inline';
        el.id = 'pdInline';
        el.contentEditable = 'true';
        el.spellcheck = true;
        el.setAttribute('role', 'textbox');
        el.setAttribute('aria-label', 'Type the words');
        el.textContent = o.text;
        page.overlay.parentElement.appendChild(el);
        pdTile.inline.el = el;
        styleInline();
        el.addEventListener('keydown', inlineKeys);
        el.addEventListener('paste', ev => {
            ev.preventDefault();
            const t = (ev.clipboardData || window.clipboardData).getData('text') || '';
            document.execCommand('insertText', false, pdTile && pdTile.inline && pdTile.inline.paragraph ? t : t.replace(/\s*\n\s*/g, ' '));
        });
        el.addEventListener('blur', ev => {
            // Picking a font or a colour on the ribbon keeps typing; anything else finishes it
            if (ev.relatedTarget && ev.relatedTarget.closest && ev.relatedTarget.closest('#pdRibbon')) return;
            setTimeout(() => { if (pdTile && pdTile.inline && pdTile.inline.el === el && document.activeElement !== el && !(document.activeElement && document.activeElement.closest && document.activeElement.closest('#pdRibbon'))) finishInline(true); }, 0);
        });
        el.focus();
        const range = document.createRange();
        range.selectNodeContents(el);
        if (!pdTile.inline.selectAll) range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        syncRibbon();
    }

    function styleInline() {
        const s = pdTile;
        if (!s || !s.inline || !s.inline.el) return;
        const p = pdPages[s.row.page - 1], f = s.fmt, r = s.inline.rect, tall = p.maxY;
        Object.assign(s.inline.el.style, {
            left: (r.x * 100) + '%', top: (r.y / tall * 100) + '%', minWidth: (r.w * 100) + '%', minHeight: (r.h / tall * 100) + '%',
            width: s.inline.paragraph ? (s.inline.width * 100) + '%' : 'auto', maxWidth: ((1 - r.x) * 100) + '%',
            fontFamily: PD_FONTS[f.font] || PD_FONTS.Helvetica, fontSize: (f.size * p.scale).toFixed(2) + 'px', fontWeight: f.bold ? 700 : 400,
            fontStyle: f.italic ? 'italic' : 'normal', textDecoration: f.underline ? 'underline' : 'none', textAlign: f.align, color: f.color,
            lineHeight: (s.inline.paragraph ? f.size * 1.2 * p.scale : Math.max(r.h * p.hPt, f.size * 1.25) * p.scale).toFixed(2) + 'px',
            background: s.inline.fill, whiteSpace: s.inline.paragraph ? 'pre-wrap' : 'pre',
        });
    }

    // Finishes typing on the page: keep = put the words on the PDF (false: drop them). Returns where they are.
    function finishInline(keep, after = null) {
        const s = pdTile;
        if (!s || !s.inline) return null;
        const el = s.inline.el, value = el ? el.innerText.replace(/\u00a0/g, ' ').replace(/\n+$/, '') : s.text;
        const where = { page: s.row.page, y: s.row.y + s.row.h / 2, key: s.own ? s.own.key : null };
        if (el) el.remove();
        s.inline.el = null;
        if (keep && !(s.inline.newLine && !value.trim())) {
            if (s.inline.paragraph) commitParagraph(s, value);
            else applyPdfTileText(true, value);
            where.key = s.own ? s.own.key : s.created;
        } else if (s.inline.newLine) dropNewLine(s);
        pdTile = null;
        render();
        syncRibbon();
        if (after) after(where, s);
        return where;
    }

    // A new line that was left empty goes away again
    function dropNewLine(s) {
        fbEdits = fbEdits.filter(e => !(s.own && e.key === s.own.key));
        const ins = findEdit(s.inline.newLine);
        if (ins) deleteInsert(ins.key);
    }

    // A paragraph: wrapped again in its width; more lines than it had → the rest of the form moves down to make room
    function commitParagraph(s, value) {
        const text = value.replace(/[ \t]+/g, ' ').replace(/ *\n */g, '\n').trim(), f = s.fmt, page = s.row.page, p = pdPages[page - 1];
        if (text === s.text && LOOK_KEYS.every(k => f[k] === s.fmt0[k])) return;
        let target, base;
        if (s.own) {
            target = findEdit(s.own.key);
            if (!target) return;
            Object.assign(target, s.own.before);
            base = { x: target.x, y: target.y, w: target.w, h: target.h };
            if (!text) { fbEdits = fbEdits.filter(e => e !== target); pushEdit({ kind: 'erase', page, ...base, text: '', font: 'Helvetica', size: null, bold: false, cover: false }); markDirty(); return; }
        } else {
            fbEdits = fbEdits.filter(e => !s.tile.labels.some(l => l.kind === 'edit' && l.key === e.key));
            if (!text) { pushEdit({ kind: 'erase', page, ...s.inline.rect, text: '', font: 'Helvetica', size: null, bold: false, cover: false }); markDirty(); return; }
            target = pushEdit({ kind: 'text', page, ...s.inline.rect, w: Math.max(s.inline.rect.w, s.inline.width), text: 'x', font: f.font, size: f.size, cover: true });
            base = { x: target.x, y: target.y, w: target.w, h: target.h };   // the old words (trimmed off its neighbours)
        }
        const width = Math.max(0.05, Math.min(1 - base.x, s.inline.width));
        const lines = wrapText(page, text, f, width), need = lines.length * lineHeightOf(p, f.size) + 0.002;
        Object.assign(target, { text: lines.join('\n'), font: f.font, size: f.size, bold: f.bold, italic: f.italic, underline: f.underline, align: f.align, color: f.color, w: width });
        const extra = need - base.h;
        if (extra > 0.003) {
            pdRows = scanPdfRows();
            const i = pdRows.findIndex(r => r.tiles.some(t => t.labels.some(l => l.key === target.key)));
            const at = i >= 0 ? cutUnder(i, 'mid') : base.y + base.h;
            addLineInsert({ kind: 'row', page, x: 0, y: Math.max(0, at - extra), w: 1, h: Math.min(extra, at), text: '', font: 'Helvetica', size: null, bold: false, cover: false });
        }
        target.h = Math.max(base.h, need);
        markDirty();
    }

    // Enter: the words are done and a new line opens under them; Backspace on an empty new line takes it away again
    function inlineKeys(ev) {
        const s = pdTile;
        if (!s || !s.inline) return;
        const ctrl = ev.ctrlKey || ev.metaKey, k = ev.key.toLowerCase();
        if (ctrl && (k === 'b' || k === 'i' || k === 'u')) { ev.preventDefault(); ribbonToggle({ b: 'bold', i: 'italic', u: 'underline' }[k]); return; }
        if (ctrl && k === 's') return;   // save: handled by the page (it finishes typing first)
        if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); finishInline(false); return; }
        if (ev.key === 'Tab') { ev.preventDefault(); finishInline(true); return; }
        if (ev.key === 'Enter' && !(ev.shiftKey && s.inline.paragraph)) {
            ev.preventDefault();
            const look = { ...s.fmt }, x = Math.min(...s.tile.labels.map(l => l.x));
            finishInline(true, where => {
                pdRows = scanPdfRows();
                const i = where.key ? pdRows.findIndex(r => r.tiles.some(t => t.labels.some(l => l.key === where.key)))
                    : pdRows.findIndex(r => r.page === where.page && where.y >= r.top - 0.004 && where.y <= r.bot + 0.004);
                if (i >= 0) typeOnNewLine(i, look, x);
            });
            return;
        }
        if (ev.key === 'Backspace' && s.inline.newLine && !s.inline.el.innerText.trim()) {
            ev.preventDefault();
            const prev = s.inline.prevRow;
            finishInline(false, () => {
                pdRows = scanPdfRows();
                const i = prev ? pdRows.findIndex(r => r.page === prev.page && prev.y >= r.top - 0.004 && prev.y <= r.bot + 0.004) : -1;
                if (i >= 0 && pdRows[i].tiles.length) startInlineFromTile(i, pdRows[i].tiles.length - 1);
            });
        }
    }

    // A new line under line i, typed straight away (in the look of the line above)
    function typeOnNewLine(i, look, x) {
        const row = pdRows[i], p = pdPages[row.page - 1];
        const lh = Math.max(LINE_H, look.size * 1.5 / p.hPt), at = cutUnder(i, 'mid');
        const ins = addLineInsert({ kind: 'row', page: row.page, x: 0, y: Math.max(0, at - lh), w: 1, h: Math.min(lh, at), text: '', font: 'Helvetica', size: null, bold: false, cover: false });
        const a = finalArea(ins), th = look.size * 1.35 / p.hPt;
        const e = pushEdit({ kind: 'text', page: row.page, x: clamp(x, 0, 0.9), y: a.y + (a.h - th) / 2, w: 0.3, h: th, text: '', font: look.font, size: look.size,
            bold: look.bold, italic: look.italic, underline: look.underline, align: 'left', color: look.color, cover: false });
        markDirty();
        render();
        startInlineOnEdit(e.key, { newLine: ins.key, prevRow: { page: row.page, y: row.y + row.h / 2 }, blank: null });
    }

    // ---- the ribbon ----
    // What it formats: the words being typed, else the added text selected
    function ribbonTarget() {
        if (pdTile && pdTile.inline) return { kind: 'inline', fmt: pdTile.fmt };
        const e = designerOpen() && pdMode === 'content' && pdSelEdit ? findEdit(pdSelEdit) : null;
        if (e && e.kind === 'text') return { kind: 'edit', edit: e, fmt: { font: e.font || 'Helvetica', size: e.size || 10, bold: !!e.bold, italic: !!e.italic, underline: !!e.underline, align: e.align || 'left', color: e.color || '#000000' } };
        return null;
    }
    function syncRibbon() {
        const bar = document.getElementById('pdRibbon');
        if (!bar) return;
        const t = ribbonTarget(), f = t ? t.fmt : { font: 'Helvetica', size: 10, bold: false, italic: false, underline: false, align: 'left', color: '#000000' };
        bar.classList.toggle('off', !t);
        bar.title = t ? '' : 'Click words on the PDF to type, or select text you added, to format it';
        const size = document.getElementById('rbSize');
        const sizes = [...new Set([...RIBBON_SIZES, f.size])].sort((a, b) => a - b);
        size.innerHTML = sizes.map(n => `<option value="${n}" ${n === f.size ? 'selected' : ''}>${n}</option>`).join('');
        document.getElementById('rbFont').value = f.font;
        ['bold', 'italic', 'underline'].forEach(k => bar.querySelector(`[data-rb="${k}"]`).classList.toggle('on', !!f[k]));
        bar.querySelectorAll('[data-rb-align]').forEach(b => b.classList.toggle('on', b.dataset.rbAlign === f.align));
        document.getElementById('rbColor').value = f.color;
        document.getElementById('rbColorBar').style.background = f.color;
    }
    function ribbonSet(prop, value) {
        const t = ribbonTarget();
        if (!t) return;
        if (t.kind === 'inline') {
            pdTile.fmt[prop] = value;
            styleInline();
            syncRibbon();
            const el = pdTile.inline.el;
            if (el && document.activeElement !== el && prop !== 'color') el.focus();
            return;
        }
        const e = t.edit, p = pdPages[e.page - 1];
        e[prop] = value;
        if (prop === 'size' && !e.text.includes('\n')) { const th = value * 1.35 / p.hPt; if (th > e.h) { e.y -= (th - e.h) / 2; e.h = th; } }
        if (prop === 'size' || prop === 'bold' || prop === 'italic' || prop === 'font') {
            if (!e.text.includes('\n')) { const w = textWidthOnPage(e.page, e.text, { ...t.fmt, [prop]: value }) + 0.006; if (w > e.w) e.w = Math.min(1 - e.x, w); }
        }
        markDirty();
        renderDesigner();
        syncRibbon();
    }
    function ribbonToggle(prop) {
        const t = ribbonTarget();
        if (t) ribbonSet(prop, !t.fmt[prop]);
    }

    // ---- Insert (ribbon): under the selected line, else at the end ----
    function openInsertMenu(e) {
        finishInline(true);
        const i = pdSelIndex() >= 0 ? pdSelIndex() : pdRows.length - 1;
        if (i < 0) return;
        openGapMenu(e, i, true);
    }

    // ---- copy / paste: the selected added thing, or the selected line ----
    let pdClip = null;
    function copySelection() {
        const e = pdSelEdit ? findEdit(pdSelEdit) : null;
        if (e && !isTransform(e)) { const { key, ...rest } = e; pdClip = { edit: JSON.parse(JSON.stringify(rest)) }; showAlert('success', 'Copied. Ctrl+V pastes it.'); return true; }
        const i = pdSelIndex();
        if (i >= 0 && pdRows[i].tiles.length) { const r = pdRows[i]; pdClip = { line: { page: r.page, y: (r.top + r.bot) / 2 } }; showAlert('success', 'Line copied. Select where it goes and press Ctrl+V.'); return true; }
        return false;
    }
    function pasteSelection() {
        if (!pdClip) return false;
        if (pdClip.edit) {
            const c = { ...JSON.parse(JSON.stringify(pdClip.edit)) };
            c.x = clamp(c.x + 0.012, 0, 1 - c.w);
            c.y = clamp(c.y + 0.018, 0, PDF_MAX_Y - c.h);
            if (pdMode !== 'content') setDesignerMode('content', false);
            addEdit(c);
            pdClip.edit = { ...pdClip.edit, x: c.x, y: c.y };   // the next paste goes a little further
            return true;
        }
        const src = pdRows.findIndex(r => r.page === pdClip.line.page && pdClip.line.y >= r.top - 0.004 && pdClip.line.y <= r.bot + 0.004);
        if (src < 0) { showAlert('error', 'The copied line is not on the form any more.'); return true; }
        const tgt = pdSelIndex() >= 0 ? pdSelIndex() : src, tgtRow = pdRows[tgt], srcRow = pdRows[src];
        if (tgtRow.page !== srcRow.page) { showAlert('error', 'A line can only be pasted on its own page.'); return true; }
        const tgtY = (tgtRow.top + tgtRow.bot) / 2;
        duplicatePdfRow(src);
        if (tgt !== src) {
            const ins = fbEdits.filter(isInsert).pop();
            pdRows = scanPdfRows();
            const copy = pdRows.findIndex(r => r.insert === ins.key);
            const y = tgtY + (tgtY > (srcRow.top + srcRow.bot) / 2 ? ins.h : 0);
            const t = pdRows.findIndex(r => r.page === tgtRow.page && y >= r.top - 0.004 && y <= r.bot + 0.004);
            if (copy >= 0 && t >= 0) moveLineTo(copy, t + 1);
        }
        showAlert('success', 'Line pasted.');
        return true;
    }

    // ---- arrows: nudge what is selected ----
    function nudgeSelection(dx, dy) {
        const e = pdSelEdit ? findEdit(pdSelEdit) : null;
        if (e && isInsert(e)) { nudgeInsert(e.key, e.kind === 'row' ? 'y' : 'x', (e.kind === 'row' ? dy : dx) > 0 ? 1 : -1); return true; }
        let box = e && !isTransform(e) ? e : null, riders = [];
        if (!box && pdMode === 'fields' && pdSelField && fbOverrides[pdSelField] && fbOverrides[pdSelField].box) box = fbOverrides[pdSelField].box;
        if (!box && pdMode === 'fields' && !pdSelField && pdCurrentKey && findField(pdCurrentKey) && findField(pdCurrentKey).box) { box = findField(pdCurrentKey).box; riders = answerLinesOf(box); }
        if (!box) return false;
        const p = pdPages[(box.page || 1) - 1], ox = box.x, oy = box.y;
        box.x = clamp(box.x + dx, 0, 1 - box.w);
        box.y = clamp(box.y + dy, 0, (p ? p.maxY : 1) - box.h);
        riders.forEach(l => { l.x += box.x - ox; l.y += box.y - oy; });
        markDirty();
        renderDesignerPages();
        return true;
    }


    // ================= WORD-STYLE DOCUMENT (TinyMCE) =================
    // The form written like in Word: a page with a header, the body and a footer. Answer spots are
    // <span class="sl-field" data-qk="…"> (a question here; saved as data-qi = its place, then data-field-id) or data-sys="…".
    const DOC_SYS = { student_name: 'Student full name', student_no: 'Student number', course: 'Course / program', year_level: 'Year level', email: 'Email address',
        contact: 'Contact number', scholarship_name: 'Scholarship name', application_no: 'Application number', date_today: "Today's date", page_number: 'Page number', page_count: 'Number of pages' };
    const DOC_PAPERS = { A4: [210, 297], Letter: [215.9, 279.4], Legal: [215.9, 355.6] };
    const mmToPx = mm => mm * 96 / 25.4, pxToMm = px => px * 25.4 / 96;
    const docParse = html => new DOMParser().parseFromString('<body>' + (html || ''), 'text/html').body;   // inert: nothing in it runs or loads
    let docEditors = null, docLoading = null;

    // Server form (data-field-id) ⇄ editor form (data-qk); toServer: data-qi (the question's place in the form)
    function docMapFields(html, toEditor) {
        if (!html) return '';
        const body = docParse(html);
        body.querySelectorAll('span.sl-field').forEach(span => {
            span.removeAttribute('data-mce-selected');
            if (toEditor) {
                span.setAttribute('contenteditable', 'false');
                if (span.dataset.sys) { span.textContent = '[' + (DOC_SYS[span.dataset.sys] || span.dataset.sys) + ']'; return; }
                if (span.dataset.pdfField) { span.textContent = '[' + (span.dataset.label || pdfBlankLabel(span.dataset.pdfField)) + ']'; return; }
                const id = Number(span.dataset.fieldId), f = span.dataset.qk ? findField(span.dataset.qk) : fbFields.find(x => x.id === id);
                span.removeAttribute('data-field-id');
                if (f) { span.dataset.qk = f.key; span.classList.remove('sl-missing'); span.textContent = '[' + (f.label || 'Untitled question') + ']'; }
                else { span.removeAttribute('data-qk'); span.classList.add('sl-missing'); span.textContent = '[Removed question]'; }
            } else {
                span.removeAttribute('contenteditable');
                if (span.dataset.sys || span.dataset.pdfField) return;
                const i = span.dataset.qk ? fbFields.findIndex(f => f.key === span.dataset.qk) : -1;
                span.removeAttribute('data-qk');
                if (i >= 0) span.dataset.qi = i; else span.classList.add('sl-missing');
            }
        });
        return body.innerHTML;
    }
    if (fbDoc) fbDoc = { ...fbDoc, body: docMapFields(fbDoc.body, true), header: docMapFields(fbDoc.header, true), footer: docMapFields(fbDoc.footer, true) };

    const docSpan = o => `<span class="sl-field" contenteditable="false" ${o.qk ? `data-qk="${o.qk}"` : `data-sys="${o.sys}"`}>[${esc(o.label || 'Untitled question')}]</span>`;
    // A first document: the university's heading, the scholarship, a table of the questions with their answers, and a signature
    function starterDocument() {
        const sys = k => docSpan({ sys: k, label: DOC_SYS[k] });
        let rows = '';
        fbFields.forEach(f => {
            if (f.type === 'Section') rows += `<tr><td colspan="2" style="background-color: #e2e8f0;"><strong>${esc(f.label || 'Section')}</strong></td></tr>`;
            else rows += `<tr><td style="width: 38%;">${esc(f.label || 'Untitled question')}</td><td>${docSpan({ qk: f.key, label: f.label })}</td></tr>`;
        });
        return {
            header: '<p style="text-align: center; margin: 0;"><span style="font-size: 10pt;">Republic of the Philippines</span></p>'
                + '<p style="text-align: center; margin: 0;"><strong><span style="font-size: 15pt; color: #006400;">TARLAC AGRICULTURAL UNIVERSITY</span></strong></p>'
                + '<p style="text-align: center; margin: 0;"><span style="font-size: 10pt;">Camiling, Tarlac</span></p>',
            body: `<h2 style="text-align: center;">APPLICATION FORM FOR SCHOLARSHIP</h2><p style="text-align: center;"><strong>${sys('scholarship_name')}</strong></p>`
                + `<p>Name: ${sys('student_name')} &nbsp; Student no.: ${sys('student_no')}</p><p>Course: ${sys('course')} &nbsp; Year level: ${sys('year_level')}</p>`
                + (rows ? `<table border="1" style="border-collapse: collapse; width: 100%;"><tbody>${rows}</tbody></table>` : '<p>Write the form here.</p>')
                + `<p>&nbsp;</p><p>I certify that the information above is true and complete.</p><p>&nbsp;</p>`
                + `<p>______________________________ &nbsp; &nbsp; Date: ${sys('date_today')}</p><p>Signature of the applicant over printed name</p>`,
            footer: `<p style="text-align: right; margin: 0;"><span style="font-size: 9pt;">Page ${sys('page_number')} of ${sys('page_count')}</span></p>`,
            paper: 'A4', header_mm: 20, footer_mm: 8,
        };
    }

    function setFormMode(mode) {
        if (mode === fbFormMode) { renderFormMode(); return; }
        fbFormMode = mode;
        markDirty();
        render();
        renderFormMode();
        // The first time: the official PDF is copied into the document, to edit like in Word
        if (mode === 'document' && !fbDoc) convertPdfToDocument();
    }
    function renderFormMode() {
        document.querySelectorAll('[data-formmode]').forEach(b => { b.classList.toggle('on', b.dataset.formmode === fbFormMode); b.setAttribute('aria-checked', b.dataset.formmode === fbFormMode); });
        document.getElementById('fbModePdf').classList.toggle('hidden', fbFormMode !== 'pdf');
        document.getElementById('fbModeDoc').classList.toggle('hidden', fbFormMode !== 'document');
        if (fbDoc) {
            const words = (docParse(fbDoc.body).textContent.match(/\S+/g) || []).length, spots = (fbDoc.body + fbDoc.header + fbDoc.footer).split('sl-field').length - 1;
            document.getElementById('fbDocInfo').textContent = `${fbDoc.paper} paper · about ${words} words · ${spots} answer spot${spots === 1 ? '' : 's'}`;
        }
    }

    function loadTinyMce() {
        if (window.tinymce) return Promise.resolve();
        if (!docLoading) docLoading = new Promise((ok, fail) => {
            const tag = document.createElement('script');
            tag.src = '../assets/lib/tinymce/tinymce.min.js';
            tag.onload = ok;
            tag.onerror = () => fail(new Error('The document editor could not load.'));
            document.head.appendChild(tag);
        });
        return docLoading;
    }

    // Insert answer (toolbar): a question, the student's details, page numbers, or a new question
    function answerMenu(ed) {
        const qs = fbFields.filter(placeable), item = (text, o) => ({ type: 'menuitem', text, onAction: () => ed.insertContent(docSpan(o) + '&nbsp;') });
        const studentKeys = ['student_name', 'student_no', 'course', 'year_level', 'email', 'contact', 'scholarship_name', 'application_no', 'date_today'];
        return [
            { type: 'nestedmenuitem', text: 'A question (students answer it)', getSubmenuItems: () => qs.length ? qs.map(f => item(f.label || 'Untitled question', { qk: f.key, label: f.label }))
                : [{ type: 'menuitem', text: 'No questions yet', enabled: false }] },
            { type: 'nestedmenuitem', text: "The student's details", getSubmenuItems: () => studentKeys.map(k => item(DOC_SYS[k], { sys: k, label: DOC_SYS[k] })) },
            { type: 'nestedmenuitem', text: 'Page number', getSubmenuItems: () => [item('Page number', { sys: 'page_number', label: DOC_SYS.page_number }), item('Number of pages', { sys: 'page_count', label: DOC_SYS.page_count })] },
            { type: 'separator' },
            { type: 'menuitem', text: 'A new question…', onAction: () => {
                const label = (prompt('The question students answer (for example: Mother\'s occupation)') || '').trim();
                if (!label) return;
                const f = makeField({ type: 'Text', label });
                fbFields.push(f);
                markDirty();
                render();
                ed.insertContent(docSpan({ qk: f.key, label: f.label }) + '&nbsp;');
                showAlert('success', `“${label}” was added to the application form too: students answer it there.`);
            } },
        ];
    }

    async function openDocEditor() {
        if (!fbDoc) fbDoc = starterDocument();
        const modal = document.getElementById('docEditor');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        setDocPaper(fbDoc.paper || 'A4', false);
        try { await loadTinyMce(); } catch (err) { document.getElementById('docStatus').textContent = err.message; return; }
        if (!docEditors) {
            const config = target => ({
                target, inline: true, license_key: 'gpl', base_url: '../assets/lib/tinymce', suffix: '.min', promotion: false, branding: false,
                fixed_toolbar_container: '#docToolbar', toolbar_persist: false, menubar: 'edit insert format table', toolbar_mode: 'wrap',
                plugins: 'lists advlist table image pagebreak charmap searchreplace wordcount',
                toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table image hr pagebreak | slanswer | removeformat',
                font_family_formats: 'Times New Roman=times new roman,times,serif; Arial=arial,helvetica,sans-serif; Courier New=courier new,courier,monospace',
                font_size_formats: '8pt 9pt 10pt 11pt 12pt 14pt 16pt 18pt 20pt 24pt 28pt 36pt',
                block_formats: 'Normal text=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Small heading=h4',
                noneditable_class: 'sl-field', extended_valid_elements: 'span[class|style|data-qk|data-sys|contenteditable]',
                pagebreak_separator: '<div class="page-break"></div>', pagebreak_split_block: true,
                table_default_attributes: { border: '1' }, table_default_styles: { 'border-collapse': 'collapse', width: '100%' },
                image_title: false, image_description: true, automatic_uploads: false, paste_data_images: true, convert_urls: false, entity_encoding: 'raw',
                file_picker_types: 'image',
                file_picker_callback: cb => {
                    const input = document.createElement('input');
                    input.type = 'file'; input.accept = 'image/png,image/jpeg,image/gif,image/webp';
                    input.onchange = () => {
                        const file = input.files[0];
                        if (!file) return;
                        if (file.size > 1500000) { alert('That picture is over 1.5 MB. Please use a smaller one.'); return; }
                        const reader = new FileReader();
                        reader.onload = () => cb(reader.result, { alt: file.name.replace(/\.[^.]+$/, '') });
                        reader.readAsDataURL(file);
                    };
                    input.click();
                },
                setup: ed => {
                    ed.ui.registry.addMenuButton('slanswer', { text: 'Insert answer', icon: 'user', tooltip: "Put a student's answer in the text", fetch: cb => cb(answerMenu(ed)) });
                    ed.on('input change undo redo keyup ExecCommand', () => docChanged());
                    ed.on('focus', () => { document.getElementById('docToolbarHint').classList.add('hidden'); docStatus(ed); });
                },
            });
            const [header, body, footer] = await Promise.all(['docHeader', 'docBody', 'docFooter'].map(id => tinymce.init(config(document.getElementById(id))).then(list => list[0])));
            docEditors = { header, body, footer };
        }
        docEditors.header.setContent(fbDoc.header || '');
        docEditors.body.setContent(fbDoc.body || '');
        docEditors.footer.setContent(fbDoc.footer || '');
        [docEditors.header, docEditors.body, docEditors.footer].forEach(ed => ed.undoManager.clear());
        docStatus(docEditors.body);
        docEditors.body.focus();
    }

    function docStatus(ed) {
        if (!docEditors) return;
        const zone = ed === docEditors.header ? 'Header' : ed === docEditors.footer ? 'Footer' : 'Page';
        const words = (docEditors.body.getContent({ format: 'text' }).match(/\S+/g) || []).length;
        document.getElementById('docStatus').textContent = `Typing in: ${zone} · ${words} words · ${fbDoc.paper} paper`;
    }

    // The editors' content back into fbDoc (the editor form)
    function readDocEditors() {
        if (!docEditors || !fbDoc) return;
        fbDoc.header = docEditors.header.getContent();
        fbDoc.body = docEditors.body.getContent();
        fbDoc.footer = docEditors.footer.getContent();
        const zone = id => document.getElementById(id).closest('.doc-zone');
        fbDoc.header_mm = Math.round(pxToMm(zone('docHeader').offsetHeight) * 10) / 10;
        fbDoc.footer_mm = Math.round(pxToMm(zone('docFooter').offsetHeight) * 10) / 10;
    }
    let docChangeTimer = null;
    function docChanged() {
        clearTimeout(docChangeTimer);
        docChangeTimer = setTimeout(() => {
            const before = JSON.stringify([fbDoc.header, fbDoc.body, fbDoc.footer]);
            readDocEditors();
            if (JSON.stringify([fbDoc.header, fbDoc.body, fbDoc.footer]) !== before) { setDirty(true); renderFormMode(); }
            if (docEditors) docStatus(tinymce.activeEditor || docEditors.body);
        }, 250);
    }

    function setDocPaper(paper, changed = true) {
        if (!fbDoc) return;
        if (changed && fbDoc.paper !== paper) { fbDoc.paper = paper; setDirty(true); }
        fbDoc.paper = paper;
        document.getElementById('docPaper').value = paper;
        const [w, h] = DOC_PAPERS[paper] || DOC_PAPERS.A4;
        Object.assign(document.getElementById('docPage').style, { width: mmToPx(w) + 'px', minHeight: mmToPx(h) + 'px' });
        renderFormMode();
    }

    function closeDocEditor() {
        readDocEditors();
        document.getElementById('docEditor').classList.add('hidden');
        document.getElementById('docEditor').classList.remove('flex');
        document.body.style.overflow = '';
        renderFormMode();
    }

    // What is sent: answer spots as data-qi (their question's place in fbFields, the order fields are saved in)
    function docForServer() {
        readDocEditors();
        if (!fbDoc) return null;
        return { body: docMapFields(fbDoc.body, false), header: docMapFields(fbDoc.header, false), footer: docMapFields(fbDoc.footer, false),
            paper: fbDoc.paper || 'A4', header_mm: fbDoc.header_mm || 20, footer_mm: fbDoc.footer_mm || 8 };
    }

    // A PDF of the document as it is now, answer spots shown by name
    async function previewDocument() {
        if (!fbDoc) fbDoc = starterDocument();
        const win = window.open('', '_blank');
        if (win) win.document.write('<p style="font:16px sans-serif;padding:24px">Making the preview…</p>');
        try {
            const res = await fetch('../actions/preview_form_document.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ scholarship_id: FB_SCHOLARSHIP_ID, document: docForServer(), labels: Object.fromEntries(fbFields.map((f, i) => [i, f.label || 'Untitled question'])),
                    pdf_labels: Object.fromEntries(activeTemplate().fields.map(f => [f.name, pdfBlankLabel(f.name)])) }) });
            if (!res.ok) throw new Error(await res.text());
            const url = URL.createObjectURL(await res.blob());
            if (win) win.location.href = url; else window.open(url, '_blank');
        } catch (err) {
            if (win) win.close();
            showAlert('error', 'Could not make the preview: ' + err.message);
        }
    }


    // ================= THE OFFICIAL PDF, COPIED INTO THE WORD-STYLE DOCUMENT =================
    // The PDF as the designer reads it (lines, words with their font / size / colour, blanks, tables) is written out as a
    // document: the coloured letterhead becomes the Header (its pictures cut out, its words still words), the bottom lines the
    // Footer, ruled tables real tables, lines with several labels side-by-side columns, and every blank an answer spot that
    // prints what the official PDF would (data-pdf-field), so nothing is lost. It is a close copy to edit, not a scan.
    const DOC_FONTS = { Helvetica: 'arial, helvetica, sans-serif', Times: "'times new roman', times, serif", Courier: "'courier new', courier, monospace" };
    function pdfBlankLabel(name) {
        const owner = pdfUsage()[name];
        if (owner) return owner.label || 'Untitled question';
        return humanize(name).replace(/^./, c => c.toUpperCase());
    }

    async function convertPdfToDocument() {
        if (fbDoc && !confirm('Make the document again from the official PDF? What is in the document now is replaced (Undo in the editor or Back in History can bring it back).')) return;
        showAlert('success', 'Reading the official PDF and copying it into the document…');
        if (!(await ensurePdfLoaded())) { showAlert('error', 'The official PDF could not be opened.'); return; }
        pdRows = scanPdfRows();
        let made;
        try { made = await pdfToDocument(); }
        catch (err) { showAlert('error', 'Could not copy the PDF: ' + err.message); return; }
        fbDoc = made;
        fbFormMode = 'document';
        setDirty(true);
        render();
        renderFormMode();
        if (docEditors) { docEditors.header.setContent(fbDoc.header); docEditors.body.setContent(fbDoc.body); docEditors.footer.setContent(fbDoc.footer); setDocPaper(fbDoc.paper, false); }
        else await openDocEditor();
        showAlert('success', 'The official PDF is now a Word-style document. It is a close copy: check it, edit anything like in Word, then Save form. Green tags are the PDF\'s blanks (they print what the PDF printed).');
    }

    // ---- what is printed where, from the page image ----
    function pageInk(p) {
        paintPage(p);
        const c = p.canvas, W = c.width, H = p.source.height, HC = c.height;
        const data = c.getContext('2d').getImageData(0, 0, W, HC).data;
        const px = (x, y) => { const i = (y * W + x) * 4; return [data[i], data[i + 1], data[i + 2]]; };
        const near = (a, b, t) => Math.abs(a[0] - b[0]) + Math.abs(a[1] - b[1]) + Math.abs(a[2] - b[2]) < t;
        // Coloured bands (a letterhead): rows of pixels mostly one colour that is not white
        const rowBg = [], bands = [], gapPx = Math.round(0.012 * H);
        for (let y = 0; y < HC; y++) {
            const counts = new Map();
            for (let x = 0; x < W; x += 6) { const c3 = px(x, y), k = c3.map(v => v >> 4).join(','); const g = counts.get(k) || { n: 0, c: c3 }; g.n++; counts.set(k, g); }
            let best = null; counts.forEach(g => { if (luma(g.c) <= 235 && (!best || g.n > best.n)) best = g; });
            rowBg[y] = [255, 255, 255];
            if (!best || best.n / Math.ceil(W / 6) < 0.35) continue;
            const last = bands[bands.length - 1];
            if (last && y - last.y1 <= gapPx && near(last.c, best.c, 90)) last.y1 = y; else bands.push({ y0: y, y1: y, c: best.c });
        }
        const bandList = bands.filter(b => (b.y1 - b.y0) / H > 0.025).map(b => ({ y: b.y0 / H, h: (b.y1 - b.y0 + 1) / H, color: hex(b.c), c: b.c }));
        bandList.forEach(b => { for (let y = Math.round(b.y * H); y < Math.round((b.y + b.h) * H) && y < HC; y++) rowBg[y] = b.c; });
        // Ink that is not text: pictures and lines
        const G = Math.max(2, Math.round(W / 420)), gw = Math.ceil(W / G), gh = Math.ceil(HC / G);
        const skip = [...p.texts, ...p.fields].map(r => ({ x0: (r.x - 0.002) * W, x1: (r.x + r.w + 0.002) * W, y0: (r.y - 0.002) * H, y1: (r.y + r.h + 0.002) * H }));
        const ink = new Uint8Array(gw * gh);
        for (let gy = 0; gy < gh; gy++) for (let gx = 0; gx < gw; gx++) {
            const cx = gx * G + G / 2, cy = gy * G + G / 2;
            if (skip.some(r => cx >= r.x0 && cx <= r.x1 && cy >= r.y0 && cy <= r.y1)) continue;
            for (let dy = 0; dy < G && !ink[gy * gw + gx]; dy++) for (let dx = 0; dx < G; dx++) {
                const x = gx * G + dx, y = gy * G + dy;
                if (x >= W || y >= HC) continue;
                if (!near(px(x, y), rowBg[y], 150)) { ink[gy * gw + gx] = 1; break; }
            }
        }
        // Long thin runs are lines (rules, box sides), set apart so a picture touching one is still found on its own
        const hr = new Uint16Array(gw * gh), vr = new Uint16Array(gw * gh);
        for (let gy = 0; gy < gh; gy++) for (let gx = 0; gx < gw;) {
            if (!ink[gy * gw + gx]) { gx++; continue; }
            let e = gx; while (e < gw && ink[gy * gw + e]) e++;
            for (let k = gx; k < e; k++) hr[gy * gw + k] = e - gx;
            gx = e;
        }
        for (let gx = 0; gx < gw; gx++) for (let gy = 0; gy < gh;) {
            if (!ink[gy * gw + gx]) { gy++; continue; }
            let e = gy; while (e < gh && ink[e * gw + gx]) e++;
            for (let k = gy; k < e; k++) vr[k * gw + gx] = e - gy;
            gy = e;
        }
        const lines = [];
        for (let i = 0; i < ink.length; i++) {
            if (!ink[i]) continue;
            const horizontal = hr[i] >= 0.12 * gw && vr[i] <= 3, vertical = vr[i] >= 0.04 * gh && hr[i] <= 3;
            if (!horizontal && !vertical) continue;
            if (horizontal) {
                const gx = i % gw, gy = (i - gx) / gw;
                if (gx === 0 || !ink[i - 1]) lines.push({ x: gx * G / W, y: gy * G / H, w: hr[i] * G / W, h: G / H });
            }
            ink[i] = 0;
        }
        // one rule drawn a few pixels thick is one line
        lines.sort((a, b) => a.y - b.y || a.x - b.x);
        for (let k = lines.length - 1; k > 0; k--) if (Math.abs(lines[k].y - lines[k - 1].y) < 3 * G / H && Math.abs(lines[k].x - lines[k - 1].x) < 0.01) lines.splice(k, 1);
        const seen = new Uint8Array(gw * gh), images = [];
        for (let i = 0; i < ink.length; i++) {
            if (!ink[i] || seen[i]) continue;
            let x0 = gw, x1 = 0, y0 = gh, y1 = 0, n = 0;
            const stack = [i];
            seen[i] = 1;
            while (stack.length) {
                const k = stack.pop(), x = k % gw, y = (k - x) / gw;
                n++; x0 = Math.min(x0, x); x1 = Math.max(x1, x); y0 = Math.min(y0, y); y1 = Math.max(y1, y);
                [[1, 0], [-1, 0], [0, 1], [0, -1]].forEach(([ax, ay]) => {
                    const nx = x + ax, ny = y + ay, nk = ny * gw + nx;
                    if (nx >= 0 && ny >= 0 && nx < gw && ny < gh && ink[nk] && !seen[nk]) { seen[nk] = 1; stack.push(nk); }
                });
            }
            const r = { x: x0 * G / W, y: y0 * G / H, w: (x1 - x0 + 1) * G / W, h: (y1 - y0 + 1) * G / H };
            const fill = n / ((x1 - x0 + 1) * (y1 - y0 + 1));
            if (r.w > 0.035 && r.h > 0.025 && fill > 0.18) images.push(r);
        }
        return { bands: bandList, images, lines, rowBg, H, W };
    }

    // The side borders of a table's cells across a line: thin vertical lines running from above its letters to below them
    // (a letter's stroke never does), as fractions of the width
    function verticalLinesAt(p, top, bot) {
        const c = p.canvas, W = c.width, H = p.source.height;
        const y0 = Math.max(0, Math.floor((top - 0.004) * H)), y1 = Math.min(c.height - 1, Math.ceil((bot + 0.004) * H)), rows = y1 - y0 + 1;
        if (rows < 4) return [];
        const data = c.getContext('2d').getImageData(0, y0, W, rows).data;
        const dark = (x, r) => { const i = (r * W + x) * 4; return data[i] + data[i + 1] + data[i + 2] < 500; };
        const runs = [];
        for (let x = 1; x < W - 1; x++) {
            let solid = true;
            for (let r = 0; r < rows && solid; r++) solid = dark(x, r) || dark(x - 1, r) || dark(x + 1, r);
            if (!solid) continue;
            const last = runs[runs.length - 1];
            if (last && x - last.x1 <= 1) last.x1 = x; else runs.push({ x0: x, x1: x });
        }
        return runs.filter(r => r.x1 - r.x0 <= Math.max(4, W / 250)).map(r => (r.x0 + r.x1) / 2 / W);
    }

    async function cutPicture(pageNum, r) {
        const page = await pdPdfDoc.getPage(pageNum), vp = page.getViewport({ scale: 3 });
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.ceil(r.w * vp.width)); c.height = Math.max(1, Math.ceil(r.h * vp.height));
        await page.render({ canvasContext: c.getContext('2d'), viewport: vp, transform: [1, 0, 0, 1, -r.x * vp.width, -r.y * vp.height] }).promise;
        return c.toDataURL('image/png');
    }

    async function pdfToDocument() {
        const p0 = pdPages[0], pageWpt = p0.hPt * p0.source.width / p0.source.height, pageHpt = p0.hPt, mmW = pageWpt * 25.4 / 72;
        const paper = Math.abs(pageHpt - 1008) < 12 ? 'Legal' : Math.abs(pageHpt - 842) < 12 && Math.abs(pageWpt - 595) < 12 ? 'A4' : 'Letter';
        const used = pdfUsage(), tplAuto = activeTemplate().auto;
        const parts = { header: '', body: '', footer: '' };
        let headerMm = 0, footerMm = 0;
        const colourOf = new Map();
        const inkColour = (page, t) => {
            const key = page + ':' + t.labels.map(l => l.x.toFixed(4) + l.y.toFixed(4)).join();
            if (!colourOf.has(key)) colourOf.set(key, t.labels.length ? ((pageColors(page, unionOf(t.labels)) || {}).color || '#000000') : '#000000');
            return colourOf.get(key);
        };
        const pagePtWide = pageWpt;
        // A part's words (each piece in its own font / size / weight) and its blank
        const partHtml = (page, t, opts = {}) => {
            const colour = inkColour(page, t), b = t.blank;
            const words = tileText(t).replace(/[:*]+$/, '').replace(/^\(\s*\)\s*/, '').trim();
            const spot = b && b.kind === 'field' && !b.sig
                ? `<span class="sl-field${b.check ? ' sl-check' : ''}" contenteditable="false" data-pdf-field="${esc(b.name)}" data-label="${esc(b.check ? (words || 'Tick') : (pdfUsage()[b.name] ? pdfBlankLabel(b.name) : (words && words.length <= 40 ? words : pdfBlankLabel(b.name))))}">[${esc(b.check ? '✓' : (pdfUsage()[b.name] ? pdfBlankLabel(b.name) : (words && words.length <= 40 ? words : pdfBlankLabel(b.name))))}]</span>` : null;
            let inBrackets = false, html = '', prev = null;
            t.labels.forEach(l => {
                const touching = prev && Math.abs((l.y + l.h / 2) - (prev.y + prev.h / 2)) < Math.max(l.h, prev.h) * 0.6 && l.x - (prev.x + prev.w) < (l.size || 10) * 0.12 / pagePtWide && l.x >= prev.x;
                let text = esc(l.str);
                if (spot && b.check && !inBrackets && /\(\s*\)/.test(text)) { text = text.replace(/\(\s*\)/, '(' + spot + ')'); inBrackets = true; }
                else if (spot && b.check && !inBrackets && /\(\s*$/.test(text)) { text += spot; inBrackets = true; }   // "(" and ")" printed apart
                if (opts.pageNumbers) text = text.replace(/\b(\d+)\s+of\s+(\d+)\b/, () => docSpan({ sys: 'page_number', label: DOC_SYS.page_number }) + ' of ' + docSpan({ sys: 'page_count', label: DOC_SYS.page_count }));
                const st = [`font-family: ${DOC_FONTS[l.font] || DOC_FONTS.Times}`, `font-size: ${Math.round((l.size || 10) * 2) / 2}pt`];
                if (colour !== '#000000') st.push(`color: ${colour}`);
                let piece = `<span style="${st.join('; ')}">${text}</span>`;
                if (l.bold) piece = `<strong>${piece}</strong>`;
                if (l.italic) piece = `<em>${piece}</em>`;
                html += (prev && !touching ? ' ' : '') + piece;
                prev = l;
            });
            if (b && !inBrackets) {
                let piece;
                if (b.kind === 'box') { const f = findField(b.key); piece = f ? docSpan({ qk: f.key, label: f.label }) : ''; }
                else if (b.kind === 'line') piece = '_'.repeat(Math.max(4, Math.min(60, Math.round(b.w * pageWpt / 5.8))));
                else if (b.sig) piece = '_'.repeat(Math.max(8, Math.min(60, Math.round(b.w * pageWpt / 5.8))));
                else piece = b.check ? '(' + spot + ')' : spot;
                html += (html ? ' ' : '') + piece;
            }
            // "1 of 1" in the footer: the page number and the number of pages
            if (opts.pageNumbers && /^\s*\d+\s+of\s+\d+\s*$/.test(tileText(t)) && !b)
                return docSpan({ sys: 'page_number', label: DOC_SYS.page_number }) + ' of ' + docSpan({ sys: 'page_count', label: DOC_SYS.page_count });
            return html;
        };
        const rowLeft = r => Math.min(...r.tiles.flatMap(tileParts).map(q => q.x)), rowRight = r => Math.max(...r.tiles.flatMap(tileParts).map(q => q.x + q.w));

        for (const p of pdPages) {
            const ink = pageInk(p);
            const rows = pdRows.filter(r => r.page === p.num && r.tiles.length);
            // Printed ____ answer lines (not fields) join their line as blanks
            p.texts.filter(t => /_{3,}/.test(t.str) && !/[A-Za-z0-9]/.test(t.str)).forEach(t => {
                const r = rows.find(r => t.y + t.h / 2 >= r.top - 0.004 && t.y + t.h / 2 <= r.bot + 0.006);
                if (r && !r.tiles.some(x => x.blank && x.blank.x < t.x + t.w && x.blank.x + x.blank.w > t.x)) r.tiles.push({ labels: [], blank: { kind: 'line', x: t.x, y: t.y, w: t.w, h: t.h } });
            });
            rows.forEach(r => r.tiles.sort((a, b) => tileParts(a)[0].x - tileParts(b)[0].x));
            const left = Math.min(...rows.map(rowLeft).filter(isFinite), 0.2), right = Math.max(...rows.map(rowRight).filter(isFinite), 0.8);
            const width = Math.max(0.3, right - left);
            const mm = f => Math.round(f * mmW * 10) / 10;

            // Header: the coloured band at the top of the first page; footer: the lines at the bottom of the first page
            const topBand = p.num === 1 ? ink.bands.find(b => b.y < 0.2) : null;
            const inHeader = r => topBand && r.top >= topBand.y - 0.01 && r.bot <= topBand.y + topBand.h + 0.01;
            const inFooter = r => p.num === 1 && r.top > 0.925;
            // Ruled tables: lines crossed by the same 3+ vertical borders, with their words between the outer ones
            const ruled = rows.map(r => {
                const xs = verticalLinesAt(p, r.top, r.bot);
                if (xs.length < 3) return null;
                const inside = r.tiles.every(t => { const c = tileParts(t)[0].x + 0.002; return c >= xs[0] - 0.01 && c <= xs[xs.length - 1]; });
                return inside ? xs : null;
            });
            const sameCols = (a, b) => a && b && a.length === b.length && a.every((x, k) => Math.abs(x - b[k]) < 0.008);
            // A line next to a table, whose borders sit close to its words, joins it when the table's column lines cross it
            for (let changed = true; changed;) {
                changed = false;
                rows.forEach((r, k) => {
                    if (ruled[k]) return;
                    const cols = ruled[k - 1] && Math.abs(rows[k - 1].bot - r.top) < 0.03 ? ruled[k - 1] : ruled[k + 1] && Math.abs(r.bot - rows[k + 1].top) < 0.03 ? ruled[k + 1] : null;
                    if (!cols) return;
                    const mid = (r.top + r.bot) / 2, xs = verticalLinesAt(p, mid + 0.001, mid - 0.001);   // (it widens what it is given by 0.004 each way: ±0.003 around the middle)
                    if (cols.every(x => xs.some(v => Math.abs(v - x) < 0.008))) { ruled[k] = cols; changed = true; }
                });
            }
            const used2 = new Set();
            let lastBot = topBand ? topBand.y + topBand.h : 0.05, html = '';
            // Only the PDF's own spacing between lines (not the editor's usual space after a paragraph)
            const gapStyle = top => { const gap = (top - lastBot) * pageHpt - 3; return 'margin: 0; ' + (gap > 3 ? `margin-top: ${Math.min(40, Math.round(gap))}pt; ` : ''); };
            const pictures = [];
            for (const im of ink.images) pictures.push({ ...im, src: await cutPicture(p.num, im) });
            const picHtml = (im, extra = '') => `<img src="${im.src}" alt="" width="${Math.round(im.w * mmW * 96 / 25.4)}" style="${extra}">`;
            const placedPics = new Set();

            // the letterhead
            if (topBand) {
                const hRows = rows.filter(inHeader), hPics = pictures.filter(im => im.y < topBand.y + topBand.h && im.y + im.h > topBand.y);
                hPics.forEach(im => placedPics.add(im));
                const textLeft = Math.min(...hRows.map(rowLeft)), textRight = Math.max(...hRows.map(rowRight));
                const cells = [];
                hPics.filter(im => im.x + im.w <= textLeft + 0.02).forEach(im => cells.push(`<td style="width: ${Math.round(im.w * 100)}%; vertical-align: middle;">${picHtml(im)}</td>`));
                const lines = hRows.map(r => {
                    const cx = (rowLeft(r) + rowRight(r)) / 2, mid = (textLeft + textRight) / 2;
                    const align = Math.abs(cx - mid) < 0.03 && rowRight(r) - rowLeft(r) < (textRight - textLeft) * 0.95 ? 'center' : 'left';
                    return `<p style="margin: 0; text-align: ${align};">${r.tiles.map(t => partHtml(p.num, t)).join(' &nbsp; ')}</p>`;
                }).join('');
                cells.push(`<td style="vertical-align: middle;">${lines}</td>`);
                hPics.filter(im => im.x >= textRight - 0.02).forEach(im => cells.push(`<td style="width: ${Math.round(im.w * 100)}%; vertical-align: middle;">${picHtml(im)}</td>`));
                parts.header += `<table style="border-collapse: collapse; width: 100%; background-color: ${topBand.color};"><tbody><tr>${cells.join('')}</tr></tbody></table>`;
                headerMm = mm(topBand.h) + 2;
                hRows.forEach(r => used2.add(r));
            }

            // the page in order: pictures, lines (alone, side by side, as a paragraph), ruled tables
            const items = [];
            rows.forEach((r, k) => { if (!used2.has(r)) items.push({ y: r.top, row: r, k }); });
            pictures.filter(im => !placedPics.has(im)).forEach(im => items.push({ y: im.y, pic: im }));
            ink.lines.filter(l => !rows.some(r => l.y >= r.top - 0.012 && l.y <= r.bot + 0.006) && !(topBand && l.y < topBand.y + topBand.h) && !(ruled.some(Boolean) && rows.some((r, k) => ruled[k] && Math.abs(l.y - r.top) < 0.03)))
                .forEach(l => items.push({ y: l.y, line: l }));
            items.sort((a, b) => a.y - b.y);
            let footerHtml = '';
            for (let n = 0; n < items.length; n++) {
                const it = items[n];
                if (it.pic) {
                    const float = it.pic.x > 0.55 ? 'float: right; margin-left: 4mm;' : it.pic.x + it.pic.w < 0.45 ? 'float: left; margin-right: 4mm;' : '';
                    html += float ? `<p style="margin: 0;">${picHtml(it.pic, float)}</p>` : `<p style="${gapStyle(it.y)}text-align: center;">${picHtml(it.pic)}</p>`;
                    if (!float) lastBot = it.pic.y + it.pic.h;
                    continue;
                }
                if (it.line) {
                    if (it.line.y > 0.9 && p.num === 1) continue;
                    if (it.line.w > 0.5) {
                        // a rule across the page (one, when it is drawn double)
                        if (!/<hr[^>]*>$/.test(html)) html += `<hr style="${gapStyle(it.line.y)}border: 0; border-top: 1px solid #888888;">`;
                    } else {
                        const indent = Math.max(0, it.line.x - left), room = Math.max(0.05, width - indent);
                        html += `<p style="${gapStyle(it.line.y)}margin-left: ${mm(indent)}mm;">${'_'.repeat(Math.max(6, Math.round(Math.min(it.line.w, room) * pageWpt / 5.8)))}</p>`;
                    }
                    lastBot = it.line.y + 0.004;
                    continue;
                }
                const r = it.row, k = it.k, footer = inFooter(r);
                let piece = '';
                if (ruled[k]) {
                    // a ruled table: this line and the next with the same columns
                    const group = [r];
                    while (items[n + 1] && items[n + 1].row && sameCols(ruled[items[n + 1].k], ruled[k])) { n++; group.push(items[n].row); }
                    const xs = ruled[k], cols = xs.slice(1).map((x, c) => ({ x0: xs[c], x1: x }));
                    const tw = xs[xs.length - 1] - xs[0];
                    piece = `<table border="1" style="${gapStyle(r.top)}border-collapse: collapse; width: ${Math.min(100, Math.round(tw / width * 100))}%;"><tbody>`
                        + group.map(g => '<tr>' + cols.map(c => `<td style="width: ${Math.round((c.x1 - c.x0) / tw * 100)}%;">${g.tiles.filter(t => { const x = tileParts(t)[0].x + 0.003; return x >= c.x0 - 0.005 && x < c.x1; }).map(t => partHtml(p.num, t, { pageNumbers: footer })).join(' ') || '&nbsp;'}</td>`).join('') + '</tr>').join('')
                        + '</tbody></table>';
                    lastBot = group[group.length - 1].bot;
                } else if (r.tiles.length > 1) {
                    // several labels on one line: side-by-side columns (a borderless table)
                    const L0 = rowLeft(r), cells = [];
                    if (L0 - left > 0.02) cells.push(`<td style="width: ${Math.round((L0 - left) / width * 100)}%;">&nbsp;</td>`);
                    r.tiles.forEach((t, j) => {
                        const x = tileParts(t)[0].x, next = r.tiles[j + 1] ? tileParts(r.tiles[j + 1])[0].x : right;
                        cells.push(`<td style="width: ${Math.max(4, Math.round((next - x) / width * 100))}%; padding: 0 2pt;">${partHtml(p.num, t, { pageNumbers: footer })}</td>`);
                    });
                    piece = `<table style="${gapStyle(r.top)}border-collapse: collapse; width: 100%;"><tbody><tr>${cells.join('')}</tr></tbody></table>`;
                    lastBot = r.bot;
                } else {
                    // one label: a line, or the start of a paragraph that runs over the next lines
                    const gi = pdRows.indexOf(r), block = gi >= 0 ? paragraphRows(gi) : [gi];
                    let group = [r];
                    if (block.length > 1 && block[0] === gi) {
                        group = block.map(b => pdRows[b]);
                        while (items[n + 1] && items[n + 1].row && group.includes(items[n + 1].row)) n++;
                    }
                    const L0 = rowLeft(r), R0 = Math.max(...group.map(rowRight)), cx = (L0 + R0) / 2;
                    const align = group.length === 1 && Math.abs(cx - (left + right) / 2) < 0.035 && R0 - L0 < width * 0.85 ? 'center'
                        : group.length === 1 && L0 > 0.5 && R0 > right - 0.03 ? 'right' : 'left';
                    const indent = align === 'left' ? `margin-left: ${mm(Math.max(0, L0 - left))}mm; ` : '';
                    piece = `<p style="${gapStyle(r.top)}${indent}text-align: ${align === 'left' && group.length > 1 ? 'justify' : align};">${group.map(g => partHtml(p.num, g.tiles[0], { pageNumbers: footer })).join(' ')}</p>`;
                    lastBot = group[group.length - 1].bot;
                }
                if (footer) footerHtml += piece.replace(/margin-top: \d+pt; /, '');   // (no gaps inside the footer)
                else html += piece;
            }
            if (p.num === 1 && footerHtml) {
                parts.footer = footerHtml;
                footerMm = mm(1 - Math.min(...rows.filter(inFooter).map(r => r.top))) + 2;
            } else if (footerHtml) html += footerHtml;
            parts.body += (p.num > 1 ? '<div class="page-break"></div>' : '') + html;
        }
        return { ...parts, paper, header_mm: Math.max(10, headerMm || 20), footer_mm: Math.max(6, footerMm || 8) };
    }

    // ================= HISTORY: earlier versions of the form =================
    // Every save is kept (actions/save_application_form.php → includes/form_versions.php). Opening one loads it into the
    // builder (nothing is saved yet) and lights up on the PDF what is different from the form you had; Restore saves it as
    // the newest version, Back returns to your form.
    let fbHistory = null;        // the list, once loaded
    let fbVersionView = null;    // { number, savedAt, savedBy, backup, dirty } while an earlier version is open
    let fbVersionDiff = [];      // [{ page, x, y, w, h }]: what the open version has that your form did not

    async function openFormHistory() {
        if (!designerOpen()) await openPdfDesigner();
        showDesignerSide('history');
    }
    function showDesignerSide(name) {
        document.querySelectorAll('[data-pdside]').forEach(b => b.classList.toggle('active', b.dataset.pdside === name));
        document.getElementById('pdSideLines').classList.toggle('hidden', name !== 'lines');
        document.getElementById('pdSideHistory').classList.toggle('hidden', name !== 'history');
        if (name === 'history') renderHistory();
    }

    async function renderHistory() {
        const box = document.getElementById('pdHistory');
        if (!fbHistory) {
            box.innerHTML = '<p class="text-center py-8 text-xs font-black text-slate-400"><i class="fas fa-spinner fa-spin"></i> Loading the versions…</p>';
            try {
                const data = await (await fetch(`../actions/get_form_versions.php?scholarship_id=${FB_SCHOLARSHIP_ID}`)).json();
                if (!data.success) throw new Error(data.error);
                fbHistory = data.versions;
            } catch (err) {
                box.innerHTML = `<p class="text-xs font-bold text-red-600">Could not load the versions: ${esc(err.message || 'network error')}</p>`;
                return;
            }
        }
        if (!fbHistory.length) { box.innerHTML = '<p class="text-xs font-bold text-slate-400 px-1">No versions yet. The first one is kept when you press Save form.</p>'; return; }
        const newest = fbHistory[0].version;
        box.innerHTML = fbHistory.map(v => {
            const viewing = fbVersionView && fbVersionView.number === v.version;
            const tpl = v.template ? (FB_PDF_TEMPLATES[v.template] ? FB_PDF_TEMPLATES[v.template].label : 'a PDF form that was deleted') : 'the automatic PDF form';
            return `<div class="pd-ver ${viewing ? 'sel' : ''}">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-black text-slate-900">Version ${v.version}</p>
                    ${v.version === newest ? '<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-black uppercase tracking-wider">Saved now</span>' : ''}
                    ${viewing ? '<span class="px-2 py-0.5 rounded-full bg-amber-200 text-amber-900 text-[10px] font-black uppercase tracking-wider">Open</span>' : ''}
                </div>
                <p class="text-[11px] font-bold text-slate-500">${esc(v.saved_at)}${v.saved_by ? ' · ' + esc(v.saved_by) : ''}</p>
                <p class="text-xs font-bold text-slate-700 mt-1">${esc(v.summary || '')}</p>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mt-1">${v.questions} question${v.questions === 1 ? '' : 's'} · ${v.pdf_changes} PDF change${v.pdf_changes === 1 ? '' : 's'} · ${esc(tpl)}</p>
                ${viewing ? '' : `<button type="button" onclick="viewFormVersion(${v.version})" class="mt-2 w-full px-3 py-2 rounded-lg bg-slate-900 text-white text-[11px] font-black uppercase tracking-widest hover:bg-blue-600">${v.version === newest && !fbVersionView ? (fbDirty ? 'Open (drops unsaved changes)' : 'Open') : 'Open this version'}</button>`}
            </div>`;
        }).join('');
    }

    // The builder's form, as it is now (to come back to)
    function workingForm() {
        return JSON.parse(JSON.stringify({
            fields: fbFields.map(({ key, ...f }) => f), edits: [...fbEdits.map(({ key, ...e }) => e), ...fbSavedBreaks], overrides: fbOverrides,
            template: fbTemplate, instructions: document.getElementById('fbInstructions').value,
            mode: fbFormMode, document: fbDoc ? docForServer() : null,
        }));
    }
    // Puts a form (a version, or the one kept by workingForm) into the builder
    function applyForm(state) {
        state = JSON.parse(JSON.stringify(state));
        fbFields = state.fields.map(f => ({ ...f, key: 'k' + (++fbKeySeq), width: f.width || 'full' }));
        fbEdits = state.edits.filter(e => e.kind !== 'break').map(e => ({ ...e, key: 'e' + (++fbKeySeq) }));
        fbSavedBreaks = state.edits.filter(e => e.kind === 'break');
        fbOverrides = state.overrides && !Array.isArray(state.overrides) ? state.overrides : {};
        fbTemplate = state.template || '';
        fbFormMode = state.mode || 'pdf';
        // (the questions are in place above, so the answer spots find them)
        fbDoc = state.document ? { ...state.document, body: docMapFields(state.document.body, true), header: docMapFields(state.document.header, true), footer: docMapFields(state.document.footer, true) } : null;
        if (docEditors && fbDoc) { docEditors.header.setContent(fbDoc.header || ''); docEditors.body.setContent(fbDoc.body || ''); docEditors.footer.setContent(fbDoc.footer || ''); }
        if (typeof renderFormMode === 'function') renderFormMode();
        document.getElementById('fbTemplate').value = fbTemplate;
        const ins = document.getElementById('fbInstructions');
        ins.value = state.instructions || '';
        autoGrow(ins);
        fbEditingKey = fbSelectedKey = pdCurrentKey = pdSelField = pdSelEdit = pdSelText = null;
        pdTile = pdHlPart = pdSelRowAt = null;
        // Undo starts over: it can't step across a whole other version
        fbUndo = [];
        fbLastState = fbState();
        updateUndoButtons();
    }
    function setDirty(dirty) {
        fbDirty = dirty;
        document.getElementById('fbDirty').classList.toggle('hidden', !dirty);
    }

    async function viewFormVersion(number) {
        let data;
        try {
            data = await (await fetch(`../actions/get_form_versions.php?scholarship_id=${FB_SCHOLARSHIP_ID}&version=${number}`)).json();
            if (!data.success) throw new Error(data.error);
        } catch (err) { showAlert('error', 'Could not open version ' + number + ': ' + (err.message || 'network error')); return; }
        const st = data.state;
        if (st.template && !FB_PDF_TEMPLATES[st.template]) {
            alert(`Version ${number} used a PDF form that is no longer available (${st.template}), so it can't be opened.`);
            return;
        }
        // What you had before opening any version is what it is compared with, and what Back returns to
        if (!fbVersionView) fbVersionView = { backup: workingForm(), dirty: fbDirty };
        Object.assign(fbVersionView, { number, savedAt: data.saved_at, savedBy: data.saved_by });
        applyForm(st);
        setDirty(true);
        await ensurePdfLoaded();
        document.getElementById('pdTitle').textContent = (FB_PDF_TEMPLATES[fbTemplate || FB_DEFAULT_TEMPLATE] || {}).label || fbTemplate;
        fbVersionDiff = versionDifferences(fbVersionView.backup);
        render();
        renderHistory();
        showAlert('success', `Version ${number} is open. ${fbVersionDiff.length ? 'What is different from your form is lit up in orange.' : 'Its PDF looks the same as your form.'}`);
    }

    function backToMyForm() {
        if (!fbVersionView) return;
        const { backup, dirty } = fbVersionView;
        fbVersionView = null;
        fbVersionDiff = [];
        applyForm(backup);
        setDirty(dirty);
        ensurePdfLoaded().then(() => {
            document.getElementById('pdTitle').textContent = (FB_PDF_TEMPLATES[fbTemplate || FB_DEFAULT_TEMPLATE] || {}).label || fbTemplate;
            render();
            if (designerOpen()) renderHistory();
        });
    }

    function restoreVersion() {
        if (!fbVersionView) return;
        if (!confirm(`Restore version ${fbVersionView.number}? It is saved as the newest version (the questions too), and students see it from now on. Your current form stays in the History.`)) return;
        saveForm();
    }

    // Where the open version differs from your form, on the PDF as the version lays it out: what it has that your form
    // does not, and the places where your form has something it does not (there the version shows the PDF as it was)
    function versionDifferences(before) {
        const n4 = v => v === null || v === undefined ? null : +(+v).toFixed(4);
        const sig = e => JSON.stringify([e.kind, e.page, n4(e.x), n4(e.y), n4(e.w), n4(e.h), n4(e.to), e.text || '', !!e.cover, e.size || null, !!e.bold, !!e.italic, e.font || '', e.color || '']);
        const beforeEdits = before.edits.filter(e => e.kind !== 'break');
        const had = new Set(beforeEdits.map(sig)), has = new Set(fbEdits.map(sig));
        const out = [];
        beforeEdits.filter(e => !has.has(sig(e))).forEach(e => {
            if (e.kind === 'erase' || e.kind === 'text' || e.kind === 'line') out.push({ page: e.page, x: e.x, y: e.y, w: e.w, h: Math.max(e.h, 0.004) });
            else if (e.kind === 'move') out.push({ page: e.page, x: 0.02, y: e.y, w: 0.96, h: e.h });             // the line is still in its old place
            else if (e.kind === 'row') out.push({ page: e.page, x: 0.02, y: e.y + e.h - 0.004, w: 0.96, h: 0.008 }); // where your added line would be
            else out.push({ page: e.page, x: e.x + e.w - 0.004, y: e.y, w: 0.008, h: e.h });
        });
        const boxOf = f => f.box ? [f.box.page, n4(f.box.x), n4(f.box.y), n4(f.box.w), n4(f.box.h)] : null;
        const hasBoxes = new Set(fbFields.filter(f => f.box).map(f => JSON.stringify([f.label, boxOf(f)])));
        before.fields.filter(f => f.box && !hasBoxes.has(JSON.stringify([f.label, boxOf(f)]))).forEach(f => out.push({ page: f.box.page, x: f.box.x, y: f.box.y, w: f.box.w, h: f.box.h }));
        Object.entries(before.overrides || {}).forEach(([name, o]) => {
            if (fbOverrides[name]) return;
            const r = pdfFieldInfo(name);
            if (r) out.push({ page: r.page, x: r.x, y: r.y, w: r.w, h: r.h });
        });
        fbEdits.forEach(e => {
            if (had.has(sig(e))) return;
            if (e.kind === 'line') out.push({ page: e.page, x: e.x, y: e.y - 0.002, w: e.w, h: 0.004 });
            else if (e.kind === 'erase' || e.kind === 'text') out.push({ page: e.page, x: e.x, y: e.y, w: e.w, h: e.h });
            else if (isInsert(e)) out.push({ page: e.page, ...finalArea(e) });
            else if (e.kind === 'move') out.push({ page: e.page, ...carryArea({ x: 0.02, y: moveTop(e), w: 0.96, h: e.h }, fbEdits.slice(fbEdits.indexOf(e) + 1).filter(t => isTransform(t) && t.page === e.page)) });
        });
        const hadBoxes = new Set(before.fields.filter(f => f.box).map(f => JSON.stringify([f.label, boxOf(f)])));
        fbFields.filter(f => f.box && !hadBoxes.has(JSON.stringify([f.label, boxOf(f)]))).forEach(f => out.push({ page: f.box.page, x: f.box.x, y: f.box.y, w: f.box.w, h: f.box.h }));
        Object.entries(fbOverrides).forEach(([name, o]) => {
            if (JSON.stringify((before.overrides || {})[name] || null) === JSON.stringify(o)) return;
            const r = o.action === 'move' ? o.box : pdfFieldInfo(name);
            if (r) out.push({ page: r.page, x: r.x, y: r.y, w: r.w, h: r.h });
        });
        return out;
    }

    // The bar shown while an earlier version is open (in the builder and in the designer)
    function renderVersionBars() {
        const v = fbVersionView;
        ['fbVersionBar', 'pdVersionBar'].forEach(id => {
            const bar = document.getElementById(id);
            bar.classList.toggle('hidden', !v);
            if (!v) return;
            const before = v.backup, qBefore = before.fields.filter(f => f.type !== 'Section').length, qNow = fbFields.filter(f => f.type !== 'Section').length;
            const questions = qBefore === qNow ? '' : ` · ${Math.abs(qNow - qBefore)} question${Math.abs(qNow - qBefore) === 1 ? '' : 's'} ${qNow > qBefore ? 'more' : 'fewer'} than your form`;
            bar.innerHTML = `<span><i class="fas fa-clock-rotate-left"></i> Version ${v.number} is open (saved ${esc(v.savedAt)}${v.savedBy ? ' by ' + esc(v.savedBy) : ''}).
                ${fbVersionDiff.length ? `<b>${fbVersionDiff.length}</b> place${fbVersionDiff.length === 1 ? '' : 's'} on the PDF lit up in orange ${fbVersionDiff.length === 1 ? 'differs' : 'differ'} from your form` : 'Its PDF looks the same as your form'}${questions}.</span>
                <span class="ml-auto flex gap-2">
                    <button type="button" onclick="restoreVersion()" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-[11px] font-black uppercase tracking-widest hover:bg-blue-600"><i class="fas fa-rotate-left"></i> Restore this version</button>
                    <button type="button" onclick="backToMyForm()" class="px-4 py-2 rounded-lg bg-white/80 text-slate-900 text-[11px] font-black uppercase tracking-widest hover:bg-white">Back to my form</button>
                </span>`;
        });
    }

    // ================= TABS =================
    function showTab(name) {
        if (name !== 'edit') closeEditor();
        document.querySelectorAll('[data-fbtab]').forEach(b => b.classList.toggle('active', b.dataset.fbtab === name));
        ['edit', 'preview', 'pdf'].forEach(t => document.getElementById({ edit: 'fbTabEdit', preview: 'fbTabPreview', pdf: 'fbTabPdf' }[t]).classList.toggle('hidden', t !== name));
        // The Form elements belong to the Edit tab (the PDF has its own, in the PDF designer)
        fbTab = name;
        document.getElementById('fbPalette').classList.toggle('hidden', name !== 'edit');
        document.getElementById('fbLayout').classList.toggle('lg:grid-cols-[300px_minmax(0,1fr)]', name === 'edit');
        if (name === 'edit') renderPalette();
        if (name === 'preview') renderPreview();
        document.querySelector('#fbPreviewBtn span').textContent = name === 'preview' ? 'Back to editing' : 'Preview as student';
        document.querySelector('#fbPreviewBtn i').className = name === 'preview' ? 'fas fa-pen' : 'fas fa-eye';
    }
    function togglePreview() {
        showTab(document.getElementById('fbTabPreview').classList.contains('hidden') ? 'preview' : 'edit');
    }

    // ================= SAVE =================
    function showAlert(type, message) {
        if (designerOpen()) {
            const t = document.getElementById('pdToast');
            t.className = 'shrink-0 px-6 py-2 text-xs font-black ' + (type === 'success' ? 'bg-emerald-500 text-white' : 'bg-red-600 text-white');
            t.textContent = (type === 'success' ? '✅ ' : '⚠️ ') + message;
            clearTimeout(showAlert.timer);
            showAlert.timer = setTimeout(() => t.classList.add('hidden'), 6000);
        }
        const el = document.getElementById('fbAlert');
        el.className = 'mb-5 px-5 py-4 rounded-2xl text-sm font-bold ' + (type === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700');
        el.innerHTML = (type === 'success' ? '✅ ' : '⚠️ ') + esc(message);
        el.classList.remove('hidden');
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Page breaks for the pages that grew: worked out from the designer when it was opened, else the saved ones are kept
    function pageBreaks() {
        if (!pdPages.length) return fbSavedBreaks;
        pdPages.forEach(paintPage);
        return pdPages.filter(p => p.breakAt).map(p => ({ kind: 'break', page: p.num, x: 0, y: +p.breakAt.toFixed(6), w: 1, h: 0.002, text: '', font: 'Helvetica', size: null, bold: false, cover: false }));
    }

    async function saveForm() {
        finishInline(true);
        const btn = document.getElementById('fbSaveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        try {
            const res = await fetch('../actions/save_application_form.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    scholarship_id: FB_SCHOLARSHIP_ID,
                    template: fbTemplate,
                    overrides: fbOverrides,
                    edits: [...fbEdits.map(({ key, ...e }) => e), ...pageBreaks()],
                    instructions: document.getElementById('fbInstructions').value,
                    restored_from: fbVersionView ? fbVersionView.number : null,
                    mode: fbFormMode,
                    document: fbDoc ? docForServer() : null,
                    fields: fbFields.map(({ key, ...f }) => ({ ...f, options: getOpts(f).map(o => o.trim()).filter(Boolean).join('\n') }))
                })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Could not save the form.');

            data.ids.forEach((id, i) => { fbFields[i].id = id; });
            const restored = fbVersionView ? fbVersionView.number : null;
            fbVersionView = null;
            fbVersionDiff = [];
            fbHistory = null;
            fbDirty = false;
            document.getElementById('fbDirty').classList.add('hidden');
            document.getElementById('fbSavedAt').textContent = data.saved_at;
            fbEditingKey = null;
            render();
            showAlert('success', (restored ? `Version ${restored} restored and saved as version ${data.form_version}.` : `Application form saved${data.form_version ? ` (version ${data.form_version})` : ''}.`) + ' Students now see this version.' + (data.archived ? ` ${data.archived} removed question(s) were archived to keep past answers.` : ''));
            if (designerOpen() && document.getElementById('pdSideHistory').offsetParent) renderHistory();
        } catch (err) {
            showAlert('error', err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save Form';
        }
    }

    window.addEventListener('beforeunload', e => { if (fbDirty) { e.preventDefault(); e.returnValue = ''; } });
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveForm(); }
        if (e.key === 'Escape' && fbEditingKey) closeEditor();
        if (e.key === 'Escape' && pdTile) closePdfTile(true);
        if (e.key === 'Escape' && pdGapAt !== null) closeGapMenu();
        const typing = e.target.closest && e.target.closest('input, textarea, select, [contenteditable]');
        if (typing) return;
        const ctrl = e.ctrlKey || e.metaKey, key = e.key.toLowerCase();
        if (ctrl && key === 'z' && !e.shiftKey) { e.preventDefault(); undoChange(); }
        if (ctrl && (key === 'y' || (key === 'z' && e.shiftKey))) { e.preventDefault(); redoChange(); }
        const inDesigner = designerOpen();
        if (!inDesigner) return;
        if (ctrl && key === 'c' && copySelection()) { e.preventDefault(); return; }
        if (ctrl && key === 'v' && pasteSelection()) { e.preventDefault(); return; }
        // Arrows nudge what is selected (Shift: further)
        const step = e.shiftKey ? 0.01 : 0.001, arrows = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (!ctrl && arrows[e.key] && nudgeSelection(...arrows[e.key])) { e.preventDefault(); return; }
        // Delete / Backspace removes what is selected: an added thing, an empty line, an answer box (its question stays)
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (pdSelEdit && findEdit(pdSelEdit)) { e.preventDefault(); deleteEdit(pdSelEdit); return; }
            const i = pdSelIndex();
            if (i >= 0 && pdRows[i].empty) { e.preventDefault(); deletePdfRow(i); return; }
            if (pdMode === 'fields' && !pdSelField && pdCurrentKey && findField(pdCurrentKey) && findField(pdCurrentKey).box) {
                e.preventDefault();
                const f = findField(pdCurrentKey), lines = answerLinesOf(f.box);
                fbEdits = fbEdits.filter(x => !lines.includes(x));
                removeFromPdf(pdCurrentKey);
                renderDesigner();
            }
        }
    });

    autoGrow(document.getElementById('fbInstructions'));
    fbFields.forEach(f => { if (!f.width) f.width = 'full'; });
    fbLastState = fbState();
    renderPalette();
    render();
    renderFormMode();
</script>
