<?php
// ✨ FORM BUILDER VIEW (shared by the Internal, External and Super Admin portals)
// The form is shown exactly as students see it; admins add, edit, reorder and delete questions in place.
// Expects: $pdo, $scholarship (row), $back_url. Saves through actions/save_application_form.php.
require_once __DIR__ . '/form_fields.php';
require_once __DIR__ . '/pdf_templates.php';

$builder_fields = array_map(function ($f) {
    return [
        'id'          => (int) $f['FieldID'],
        'label'       => $f['FieldName'],
        'type'        => $f['FieldType'],
        'help'        => (string) ($f['HelpText'] ?? ''),
        'placeholder' => (string) ($f['Placeholder'] ?? ''),
        'options'     => (string) ($f['Options'] ?? ''),
        'required'    => (bool) $f['IsRequired'],
        'pdf'         => (string) ($f['PDFFieldName'] ?? ''),
        // Box drawn on the PDF in the designer (page + position as fractions of the page)
        'box'         => $f['PdfPage'] === null ? null : [
            'page' => (int) $f['PdfPage'], 'x' => (float) $f['PdfX'], 'y' => (float) $f['PdfY'],
            'w' => (float) $f['PdfW'], 'h' => (float) $f['PdfH'], 'size' => $f['PdfFontSize'] === null ? null : (float) $f['PdfFontSize'],
        ],
    ];
}, getFormFields($pdo, $scholarship['ScholarshipID']));

$doc_stmt = $pdo->prepare("SELECT DocumentName FROM document_requirement WHERE ScholarshipID = ? ORDER BY RequirementID ASC");
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

// Official PDF forms in /forms: their fillable fields and which ones the profile fills automatically
$pdf_templates = [];
foreach (listPdfTemplates() as $file => $label) {
    $pdf_templates[$file] = ['label' => $label, 'fields' => getPdfTemplateFields($file), 'auto' => profilePdfFieldNames($file)];
}
$chosen_template = isset($pdf_templates[$scholarship['FormTemplate'] ?? '']) ? $scholarship['FormTemplate'] : '';

// Edits to the PDF's printed content (erased areas, new / replaced text) for this scholarship
$edit_stmt = $pdo->prepare("SELECT * FROM scholarship_pdf_edits WHERE ScholarshipID = ? ORDER BY SortOrder, EditID");
$edit_stmt->execute([$scholarship['ScholarshipID']]);
$pdf_edits = array_map(fn($e) => [
    'kind' => $e['Kind'], 'page' => (int) $e['PdfPage'], 'x' => (float) $e['PdfX'], 'y' => (float) $e['PdfY'], 'w' => (float) $e['PdfW'], 'h' => (float) $e['PdfH'],
    'text' => (string) ($e['Content'] ?? ''), 'font' => $e['FontFamily'], 'size' => $e['FontSize'] === null ? null : (float) $e['FontSize'],
    'bold' => (bool) $e['IsBold'], 'cover' => (bool) $e['Cover'],
], $edit_stmt->fetchAll(PDO::FETCH_ASSOC));

// Fields of the PDF this scholarship hid or moved in the PDF designer
$pdf_overrides = [];
$ovr_stmt = $pdo->prepare("SELECT * FROM scholarship_pdf_overrides WHERE ScholarshipID = ?");
$ovr_stmt->execute([$scholarship['ScholarshipID']]);
foreach ($ovr_stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $pdf_overrides[$o['FieldName']] = $o['Action'] === 'hide' ? ['action' => 'hide'] : ['action' => 'move', 'box' => [
        'page' => (int) $o['PdfPage'], 'x' => (float) $o['PdfX'], 'y' => (float) $o['PdfY'], 'w' => (float) $o['PdfW'], 'h' => (float) $o['PdfH'],
        'size' => $o['PdfFontSize'] === null ? null : (float) $o['PdfFontSize'],
    ]];
}
$default_template = defaultPdfTemplate($scholarship['Name']);
?>

<!-- The admin header doesn't load Font Awesome; the builder's icons need it -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* ---- question blocks (shown as the student sees them) ---- */
    .q-block { position: relative; border-radius: 18px; padding: 14px 16px; margin: 0 -16px; border: 2px solid transparent; transition: border-color 0.15s, background 0.15s; cursor: pointer; }
    .q-block:hover { border-color: #bfdbfe; background: #f8fbff; }
    .q-block.is-dragging { opacity: 0.35; }
    .q-block.drop-before { box-shadow: 0 -3px 0 0 #2563eb; }
    .q-block.drop-after { box-shadow: 0 3px 0 0 #2563eb; }
    .q-toolbar { position: absolute; top: -16px; right: 12px; display: flex; gap: 2px; padding: 3px; border-radius: 12px; background: #0f172a; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.25); opacity: 0; transform: translateY(4px); pointer-events: none; transition: all 0.15s; z-index: 5; }
    .q-block:hover .q-toolbar { opacity: 1; transform: none; pointer-events: auto; }
    .q-toolbar button { width: 30px; height: 30px; border-radius: 9px; color: #cbd5e1; font-size: 12px; }
    .q-toolbar button:hover:not(:disabled) { background: rgba(255,255,255,0.12); color: #fff; }
    .q-toolbar button:disabled { opacity: 0.3; cursor: not-allowed; }
    .q-toolbar .danger:hover { background: #dc2626 !important; }
    .q-type-tag { position: absolute; top: -10px; left: 14px; padding: 1px 8px; border-radius: 999px; background: #dbeafe; color: #1d4ed8; font-size: 9px; font-weight: 900; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0; transition: opacity 0.15s; }
    .q-block:hover .q-type-tag { opacity: 1; }

    /* ---- the question being edited ---- */
    .q-edit { position: relative; margin: 6px -16px; padding: 20px; border-radius: 18px; background: #fff; border: 2px solid #2563eb; border-left-width: 6px; box-shadow: 0 20px 40px -12px rgba(37, 99, 235, 0.25); }
    .q-edit .inline-input { width: 100%; background: transparent; border: none; border-bottom: 2px solid #e2e8f0; outline: none; padding: 6px 2px; transition: border-color 0.15s; }
    .q-edit .inline-input:focus { border-bottom-color: #2563eb; }
    .q-edit .opt-row input[type=text] { flex: 1; min-width: 0; background: transparent; border: none; border-bottom: 1px dashed transparent; outline: none; padding: 6px 2px; font-weight: 700; font-size: 14px; color: #334155; }
    .q-edit .opt-row input[type=text]:hover { border-bottom-color: #cbd5e1; }
    .q-edit .opt-row input[type=text]:focus { border-bottom: 2px solid #2563eb; }
    .switch { position: relative; width: 40px; height: 22px; border-radius: 999px; background: #cbd5e1; transition: background 0.15s; flex-shrink: 0; }
    .switch::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
    .switch.on { background: #2563eb; }
    .switch.on::after { transform: translateX(18px); }

    /* ---- "+" insert points between questions ---- */
    .insert-zone { position: relative; height: 18px; display: flex; align-items: center; justify-content: center; }
    .insert-zone::before { content: ''; position: absolute; left: 0; right: 0; top: 50%; border-top: 2px dashed #bfdbfe; opacity: 0; transition: opacity 0.15s; }
    .insert-zone button { position: relative; width: 26px; height: 26px; border-radius: 50%; background: #fff; border: 2px solid #bfdbfe; color: #2563eb; font-size: 11px; opacity: 0; transform: scale(0.8); transition: all 0.15s; }
    .insert-zone:hover::before, .insert-zone:hover button { opacity: 1; transform: none; }
    .insert-zone button:hover { background: #2563eb; color: #fff; border-color: #2563eb; }

    /* ---- type picker ---- */
    #typeMenu { position: absolute; z-index: 60; width: 320px; max-width: calc(100vw - 32px); }
    #typeMenu .type-btn { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 10px; font-size: 13px; font-weight: 700; color: #334155; text-align: left; }
    #typeMenu .type-btn:hover { background: #eff6ff; color: #1d4ed8; }
    #typeMenu .type-btn i { width: 18px; text-align: center; color: #3b82f6; }

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
    .pd-mode.active { background: #fff; color: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
    .pd-mode:not(.active) { color: #64748b; }
    .pd-tool.active { background: #f59e0b; color: #fff; }
    .pd-tool:not(.active) { color: #92400e; }
    /* content edits: erased areas (white) and new / replaced text */
    .pd-edit { position: absolute; overflow: hidden; white-space: nowrap; color: #000; pointer-events: none; }
    .pd-edit.erase, .pd-edit.cover { background: #fff; }
    .content-mode .pd-edit { pointer-events: auto; cursor: pointer; }
    .content-mode .pd-edit.erase { outline: 1.5px dashed #dc2626; outline-offset: -1px; }
    .content-mode .pd-edit.text { outline: 1.5px dashed #f59e0b; outline-offset: -1px; }
    .content-mode .pd-edit.sel { outline: 2.5px solid #d97706; overflow: visible; z-index: 4; cursor: move; }
    .pd-edit .pd-handle { background: #d97706; }
    .pd-text { position: absolute; border-radius: 2px; cursor: pointer; }
    .pd-text:hover { background: rgba(37, 99, 235, 0.12); outline: 1px solid rgba(37, 99, 235, 0.6); }
    .pd-text.sel { background: rgba(37, 99, 235, 0.18); outline: 2px solid #2563eb; }
    .content-mode.tool-erase .pd-text, .content-mode.tool-addtext .pd-text { pointer-events: none; }
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

    /* ---- preview mode hides every editing control ---- */
    body.fb-preview .q-toolbar, body.fb-preview .q-type-tag, body.fb-preview .insert-zone, body.fb-preview .edit-only { display: none !important; }
    body.fb-preview .q-block { cursor: default; }
    body.fb-preview .q-block:hover { border-color: transparent; background: transparent; }
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
            <button type="button" id="fbPreviewBtn" onclick="togglePreview()" class="bg-white border border-slate-200 text-slate-700 hover:border-slate-900 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all">
                <i class="fas fa-eye"></i> <span>Preview as student</span>
            </button>
            <button type="button" id="fbSaveBtn" onclick="saveForm()" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg disabled:opacity-50">
                <i class="fas fa-floppy-disk"></i> Save Form
            </button>
        </div>
    </header>

    <div class="max-w-4xl mx-auto">
        <div id="fbAlert" class="hidden mb-5 px-5 py-4 rounded-2xl text-sm font-bold"></div>

        <div class="edit-only mb-5 flex items-start gap-3 px-5 py-4 rounded-2xl bg-blue-50 border border-blue-100 text-blue-900 text-sm">
            <i class="fas fa-hand-pointer mt-0.5"></i>
            <p><strong>This is the form exactly as students see it.</strong> Click a question to edit it, hover for move / duplicate / delete, and use <strong>+</strong> to add a question anywhere.
            <?php if ($application_count > 0): ?><br><span class="text-amber-700 font-bold"><?= $application_count ?> application<?= $application_count === 1 ? ' has' : 's have' ?> already been submitted — removed questions are archived so those answers are kept.</span><?php endif; ?></p>
        </div>

        <!-- OFFICIAL PDF FORM: which template is printed, and which of its fields the questions fill -->
        <div class="edit-only mb-8 bg-white border border-slate-200 rounded-[1.5rem] p-5 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center gap-4">
                <div class="w-11 h-11 shrink-0 rounded-xl bg-red-50 text-red-600 flex items-center justify-center text-lg"><i class="fas fa-file-pdf"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Official PDF form <span class="normal-case tracking-normal font-bold">(from the /forms folder)</span></p>
                    <select id="fbTemplate" onchange="changeTemplate(this.value)" class="mt-1 w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold outline-none focus:border-blue-500 cursor-pointer">
                        <option value="">Automatic — <?= htmlspecialchars($pdf_templates[$default_template]['label'] ?? $default_template) ?></option>
                        <?php foreach ($pdf_templates as $file => $t): ?>
                            <option value="<?= htmlspecialchars($file) ?>" <?= $file === $chosen_template ? 'selected' : '' ?>><?= htmlspecialchars($t['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
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

        <!-- THE FORM (mirrors student/apply_form.php) -->
        <div class="mb-6 flex items-center gap-4">
            <span class="w-12 h-12 shrink-0 bg-white border border-slate-200 rounded-2xl flex items-center justify-center text-slate-300"><i class="fas fa-arrow-left"></i></span>
            <div>
                <h2 class="text-2xl lg:text-4xl font-black text-slate-900 tracking-tight leading-tight">Apply for <?= htmlspecialchars($scholarship['Name']) ?></h2>
                <p class="text-slate-500 font-medium text-xs lg:text-sm mt-1">Complete the dynamic form and upload your requirements.</p>
            </div>
        </div>

        <div class="bg-white p-6 lg:p-10 rounded-[2rem] border border-slate-200 shadow-sm space-y-8">
            <!-- Fixed part of every form -->
            <div class="space-y-4 relative">
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Academic Profile
                    <span class="edit-only ml-2 text-[9px] font-black text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full align-middle"><i class="fas fa-lock"></i> Always included</span>
                </h3>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Declared GWA / GPA</label>
                    <div class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl font-bold text-slate-400">1.50</div>
                </div>
            </div>

            <div class="space-y-3">
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Application Questions</h3>

                <!-- Instructions: edited in place -->
                <div class="relative group">
                    <textarea id="fbInstructions" rows="2" oninput="markDirty(); autoGrow(this); document.getElementById('fbInstructionsBox').classList.toggle('is-empty', !this.value.trim());"
                        placeholder="✏️ Click to add instructions shown at the top of the form (optional)"
                        class="w-full p-4 bg-blue-50 border border-blue-100 rounded-xl text-sm text-blue-900 font-medium outline-none resize-none focus:ring-4 focus:ring-blue-500/10 focus:border-blue-400 placeholder:text-blue-300 edit-only"><?= htmlspecialchars($scholarship['FormInstructions'] ?? '') ?></textarea>
                    <div id="fbInstructionsBox" class="hidden p-4 bg-blue-50 border border-blue-100 rounded-xl text-sm text-blue-900 font-medium whitespace-pre-line"></div>
                </div>

                <div id="fbCanvas"></div>

                <button type="button" onclick="openTypeMenu(this, fbFields.length)" class="edit-only w-full mt-2 py-4 rounded-2xl border-2 border-dashed border-blue-200 text-blue-600 hover:bg-blue-50 hover:border-blue-400 text-sm font-black uppercase tracking-widest transition-all">
                    <i class="fas fa-plus"></i> Add question
                </button>
            </div>

            <div class="space-y-4">
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Required Documents
                    <span class="edit-only ml-2 text-[9px] font-black text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full align-middle"><i class="fas fa-gear"></i> Managed in scholarship settings</span>
                </h3>
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
</main>

<!-- PDF DESIGNER: the real PDF with its fields and the boxes admins draw -->
<div id="pdfDesigner" class="hidden fixed inset-0 z-[9998] bg-slate-900/80 backdrop-blur-sm flex flex-col">
    <div class="bg-white px-4 lg:px-6 py-3 flex flex-wrap items-center justify-between gap-3 shadow-lg">
        <div class="min-w-0">
            <p class="text-[10px] font-black text-red-600 uppercase tracking-widest"><i class="fas fa-file-pdf"></i> PDF Designer</p>
            <p id="pdTitle" class="text-sm font-black text-slate-900 truncate"></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-xl bg-slate-100 p-1">
                <button type="button" id="pdModeFields" onclick="setDesignerMode('fields')" class="pd-mode px-3 py-2 rounded-lg text-[11px] font-black uppercase tracking-widest">🧩 Fields &amp; questions</button>
                <button type="button" id="pdModeContent" onclick="setDesignerMode('content')" class="pd-mode px-3 py-2 rounded-lg text-[11px] font-black uppercase tracking-widest">✏️ Edit content</button>
            </div>
            <div id="pdTools" class="hidden inline-flex rounded-xl bg-amber-50 border border-amber-200 p-1">
                <button type="button" data-tool="select" onclick="setContentTool('select')" class="pd-tool px-3 py-2 rounded-lg text-[11px] font-black"><i class="fas fa-i-cursor"></i> Select text</button>
                <button type="button" data-tool="erase" onclick="setContentTool('erase')" class="pd-tool px-3 py-2 rounded-lg text-[11px] font-black"><i class="fas fa-eraser"></i> Erase area</button>
                <button type="button" data-tool="addtext" onclick="setContentTool('addtext')" class="pd-tool px-3 py-2 rounded-lg text-[11px] font-black"><i class="fas fa-font"></i> Add text</button>
            </div>
        </div>
        <button type="button" onclick="closePdfDesigner()" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600">Done</button>
    </div>
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row">
        <div id="pdPages" class="flex-1 min-h-0 overflow-auto p-6 pt-10"></div>
        <aside class="lg:w-80 shrink-0 bg-white border-t lg:border-t-0 lg:border-l border-slate-200 overflow-y-auto max-h-[45vh] lg:max-h-none">
            <div id="pdHelpFields" class="p-4 border-b border-slate-100 text-xs text-slate-600 leading-relaxed bg-blue-50/60">
                <p class="font-black text-slate-900 mb-1"><i class="fas fa-circle-info text-blue-600"></i> Fields &amp; questions</p>
                <p><strong>Add:</strong> drag on an empty spot to draw a new box. With no question selected, this creates a new question.</p>
                <p class="mt-1"><strong>Edit / delete a PDF field:</strong> click any dashed field, then choose what fills it, <em>Move / resize</em>, or <em>Delete from PDF</em>.</p>
                <p class="mt-1"><strong>Selected box:</strong> drag to move, drag its corner to resize, × to delete.</p>
            </div>
            <div id="pdHelpContent" class="hidden p-4 border-b border-slate-100 text-xs text-slate-600 leading-relaxed bg-amber-50/70">
                <p class="font-black text-slate-900 mb-1"><i class="fas fa-circle-info text-amber-600"></i> Edit the PDF's content</p>
                <p><strong>Select text:</strong> click any printed text, then delete or replace it.</p>
                <p class="mt-1"><strong>Erase area:</strong> drag over anything (text, lines, a logo) to remove it.</p>
                <p class="mt-1"><strong>Add text:</strong> click where the new text should go.</p>
                <p class="mt-1">Click an edit again to move, resize, change or delete it. Edits apply to this scholarship's PDF only.</p>
            </div>
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

<!-- TYPE PICKER (opened by every "+") -->
<div id="typeMenu" class="hidden bg-white border border-slate-200 rounded-2xl shadow-2xl p-3">
    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2 pb-2">Add a question</p>
    <div class="grid grid-cols-2 gap-1">
        <?php foreach ($field_types as $key => $t): ?>
            <button type="button" class="type-btn" onclick="pickType('<?= $key ?>')"><i class="fas <?= $t['icon'] ?>"></i><?= htmlspecialchars($t['label']) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="mt-2 pt-3 border-t border-slate-100 px-1">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1 pb-2">⚡ Auto-fill from student profile</p>
        <div class="flex gap-2">
            <select id="fbAutoType" class="flex-1 min-w-0 bg-slate-50 border border-slate-200 rounded-xl px-2 py-2 text-xs font-bold outline-none">
                <?php foreach ($auto_types as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" onclick="pickType(document.getElementById('fbAutoType').value)" class="px-3 rounded-xl bg-blue-600 text-white text-xs font-black hover:bg-blue-700">Add</button>
        </div>
    </div>
</div>

<!-- pdf.js renders the official PDF inside the designer -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
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
    let fbInsertAt = 0;
    fbFields.forEach(f => f.key = 'k' + (++fbKeySeq));
    // Edits to the PDF's printed content: [{ kind: 'erase'|'text', page, x, y, w, h, text, font, size, bold, cover }]
    let fbEdits = <?= json_encode($pdf_edits) ?>;
    fbEdits.forEach(e => e.key = 'e' + (++fbKeySeq));

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const isAuto = type => type.startsWith('Auto_');
    const needsOptions = type => !!(FB_TYPES[type] && FB_TYPES[type].options);
    const typeLabel = type => isAuto(type) ? 'Auto-fill: ' + (FB_AUTO_TYPES[type] || type) : (FB_TYPES[type] ? FB_TYPES[type].label : type);
    const typeIcon = type => isAuto(type) ? 'fa-bolt' : (FB_TYPES[type] ? FB_TYPES[type].icon : 'fa-question');
    const getOpts = f => String(f.options || '').split('\n');
    const findIndex = key => fbFields.findIndex(f => f.key === key);
    const findField = key => fbFields.find(f => f.key === key);

    function markDirty() {
        fbDirty = true;
        document.getElementById('fbDirty').classList.remove('hidden');
    }

    function autoGrow(el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }

    // ================= OFFICIAL PDF FORM =================
    const activeTemplate = () => FB_PDF_TEMPLATES[fbTemplate || FB_DEFAULT_TEMPLATE] || { fields: [], auto: [] };
    // "FatherOccupation" -> "Father Occupation", "Zip Code" stays
    const humanize = name => name.replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/\s+/g, ' ').trim();

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
        const tpl = activeTemplate();
        const used = pdfUsage();
        const missing = tpl.fields.filter(p => !tpl.auto.includes(p.name) && !used[p.name] && !(fbOverrides[p.name] && fbOverrides[p.name].action === 'hide'));
        const filled = tpl.fields.length - missing.length;
        const pct = tpl.fields.length ? Math.round(filled / tpl.fields.length * 100) : 0;

        document.getElementById('pdfCoverageText').textContent = tpl.fields.length ? `${filled} / ${tpl.fields.length}` : 'n/a';
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
        addField(pdfField && pdfField.type === 'Checkbox' ? 'YesNo' : 'Text', fbFields.length);
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
    const PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    // Single quotes inside: these go into style="..." attributes
    const PD_FONTS = { Helvetica: "Arial, Helvetica, sans-serif", Times: "'Times New Roman', Times, serif", Courier: "'Courier New', Courier, monospace" };
    let pdDocFile = null;
    let pdPages = [];            // [{ num, overlay, scale, hPt, fields: [...], texts: [...] }]
    let pdMode = 'fields';       // 'fields' | 'content'
    let pdTool = 'select';       // content tool: 'select' | 'erase' | 'addtext'
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
        if (pdDocFile !== file) await loadPdfIntoDesigner(file);
        renderDesigner();
    }

    function closePdfDesigner() {
        document.getElementById('pdfDesigner').classList.add('hidden');
        document.body.style.overflow = '';
        render();
    }

    function setDesignerMode(mode, redraw = true) {
        pdMode = mode;
        pdSelField = pdSelEdit = pdSelText = null;
        const content = mode === 'content';
        document.getElementById('pdModeFields').classList.toggle('active', !content);
        document.getElementById('pdModeContent').classList.toggle('active', content);
        document.getElementById('pdTools').classList.toggle('hidden', !content);
        ['pdHelpFields', 'pdQuestionsWrap', 'pdChangedWrap'].forEach(id => document.getElementById(id).classList.toggle('hidden', content));
        ['pdHelpContent', 'pdEditsWrap'].forEach(id => document.getElementById(id).classList.toggle('hidden', !content));
        setContentTool(pdTool, false);
        if (redraw) renderDesigner();
    }

    function setContentTool(tool, redraw = true) {
        pdTool = tool;
        document.querySelectorAll('.pd-tool').forEach(b => b.classList.toggle('active', b.dataset.tool === tool));
        const holder = document.getElementById('pdPages');
        holder.classList.toggle('content-mode', pdMode === 'content');
        holder.classList.toggle('tool-erase', pdMode === 'content' && tool === 'erase');
        holder.classList.toggle('tool-addtext', pdMode === 'content' && tool === 'addtext');
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
            holder.innerHTML = '';
            pdPages = [];
            const cssWidth = Math.min(880, holder.clientWidth - 48);
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

                await page.render({ canvasContext: canvas.getContext('2d'), viewport: page.getViewport({ scale: viewport.scale * ratio }) }).promise;

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
                    };
                });

                pdPages.push({ num: n, overlay, fields, texts, scale: viewport.scale, hPt: viewport.height / viewport.scale });
                bindDesignerPage(pdPages[pdPages.length - 1]);
            }
            pdDocFile = file;
        } catch (err) {
            holder.innerHTML = `<p class="text-center text-white font-black py-20">Could not open the PDF: ${esc(err.message)}</p>`;
        }
    }

    function renderDesigner() {
        renderDesignerPages();
        renderDesignerPanel();
    }

    function renderDesignerPages() {
        const content = pdMode === 'content';
        const current = content || pdSelField ? null : findField(pdCurrentKey);
        const tpl = activeTemplate();
        const used = pdfUsage();
        const pct = v => (v * 100).toFixed(3) + '%';
        const pos = r => `left:${pct(r.x)};top:${pct(r.y)};width:${pct(r.w)};height:${pct(r.h)}`;
        const handles = '<span class="pd-handle" title="Drag to resize"></span><span class="pd-del" title="Delete">×</span>';

        pdPages.forEach(p => {
            let html = '';

            // Content edits are always shown, so both modes look like the final PDF
            fbEdits.filter(e => e.page === p.num).forEach(e => {
                const sel = content && pdSelEdit === e.key;
                if (e.kind === 'erase') {
                    html += `<div class="pd-edit erase ${sel ? 'sel' : ''}" data-edit="${e.key}" style="${pos(e)}" title="Erased area">${sel ? handles : ''}</div>`;
                } else {
                    const boxPx = e.h * p.hPt * p.scale;
                    const sizePt = e.size || Math.min(14, Math.max(6, e.h * p.hPt * 0.75));
                    html += `<div class="pd-edit text ${e.cover ? 'cover' : ''} ${sel ? 'sel' : ''}" data-edit="${e.key}" title="Added text"
                        style="${pos(e)};font-family:${PD_FONTS[e.font] || PD_FONTS.Helvetica};font-size:${(sizePt * p.scale).toFixed(2)}px;font-weight:${e.bold ? 700 : 400};line-height:${boxPx.toFixed(2)}px">${esc(e.text)}${sel ? handles : ''}</div>`;
                }
            });

            if (content) {
                // Printed text that can be clicked
                p.texts.forEach((t, i) => {
                    const sel = pdSelText && pdSelText.page === p.num && pdSelText.i === i;
                    html += `<div class="pd-text ${sel ? 'sel' : ''}" data-i="${i}" style="${pos(t)}" title="${esc(t.str)}"></div>`;
                });
                p.overlay.innerHTML = html;
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
            p.overlay.innerHTML = html;
        });
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
                </div>
                ${sizeSelect(edit.size, 'setEditSize', [6, 7, 8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 32])}
                <label class="flex items-start gap-2 mt-3 text-xs font-bold text-slate-600 cursor-pointer">
                    <input type="checkbox" ${edit.cover ? 'checked' : ''} onchange="updateEdit('cover', this.checked)" class="mt-0.5 accent-amber-600">
                    White background (hides whatever is printed underneath)
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
        const tips = { select: 'Click any printed text on the PDF to delete or replace it.', erase: 'Drag over anything on the PDF to erase it.', addtext: 'Click where the new text should go.' };
        return `<p class="text-sm font-bold text-slate-500">Nothing selected.</p><p class="text-xs text-slate-400 mt-1">${tips[pdTool]}</p>`;
    }

    function renderEditList() {
        document.getElementById('pdEdits').innerHTML = fbEdits.length
            ? fbEdits.map(e => `<button type="button" onclick="selectEdit('${e.key}')" class="w-full text-left px-3 py-2 rounded-lg border ${pdSelEdit === e.key ? 'border-amber-500 bg-amber-50' : 'border-transparent hover:bg-slate-50'}">
                    <span class="block text-sm font-bold text-slate-800 truncate">${e.kind === 'erase' ? 'Erased area' : esc(e.text || '(empty text)')}</span>
                    <span class="block text-[10px] font-black uppercase tracking-wider ${e.kind === 'erase' ? 'text-red-600' : 'text-amber-600'}">${e.kind === 'erase' ? '✕ Erase' : (e.cover ? '✎ Replaced text' : '＋ Added text')} · page ${e.page}</span>
                </button>`).join('')
            : '<p class="text-xs text-slate-400">No content edits yet.</p>';
    }

    // ---------- Edit content: actions ----------
    function addEdit(edit) {
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
        markDirty();
        renderDesignerPages();   // keep the side panel (and the cursor in the text box) as it is
        renderEditList();
    }

    function setEditSize(value) { updateEdit('size', value === '' ? null : Number(value)); }

    function deleteEdit(key) {
        fbEdits = fbEdits.filter(e => e.key !== key);
        if (pdSelEdit === key) pdSelEdit = null;
        markDirty();
        renderDesigner();
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
            return { x: clamp((e.clientX - r.left) / r.width, 0, 1), y: clamp((e.clientY - r.top) / r.height, 0, 1) };
        };
        const capture = e => {
            try { overlay.setPointerCapture(e.pointerId); } catch (err) { /* some pointers can't be captured; dragging still works inside the page */ }
            e.preventDefault();
        };

        overlay.addEventListener('pointerdown', e => {
            if (e.button !== 0) return;
            const point = toFraction(e);

            // ----- Edit content mode -----
            if (pdMode === 'content') {
                if (e.target.closest('.pd-del')) { deleteEdit(pdSelEdit); return; }
                const editEl = e.target.closest('.pd-edit');
                if (editEl) {
                    if (pdSelEdit !== editEl.dataset.edit) { selectEdit(editEl.dataset.edit); return; }
                    const edit = findEdit(pdSelEdit);
                    pdDrag = { mode: e.target.closest('.pd-handle') ? 'resize' : 'move', start: point, orig: { ...edit }, ref: edit, el: editEl, moved: false };
                } else if (pdTool === 'addtext') {
                    addEdit({ kind: 'text', page: page.num, x: clamp(point.x, 0, 0.97), y: clamp(point.y - 0.011, 0, 0.978),
                        w: clamp(0.3, 0.02, 1 - point.x), h: 0.022, text: 'New text', font: 'Helvetica', size: 10, bold: false, cover: false });
                    return;
                } else if (pdTool === 'erase') {
                    pdDrag = { mode: 'draw-erase', start: point, page: page.num, moved: false };
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
                // A moved PDF field: select it, or drag it when already selected
                if (pdSelField !== boxEl.dataset.field) { selectPdfField(boxEl.dataset.field); return; }
                const box = fbOverrides[pdSelField].box;
                pdDrag = { mode: e.target.closest('.pd-handle') ? 'resize' : 'move', start: point, orig: { ...box }, ref: box, el: boxEl, moved: false };
            } else if (boxEl) {
                // A question's drawn box
                if (pdSelField || boxEl.dataset.key !== pdCurrentKey) { selectDesignerQuestion(boxEl.dataset.key); return; }
                const box = findField(pdCurrentKey).box;
                pdDrag = { mode: e.target.closest('.pd-handle') ? 'resize' : 'move', start: point, orig: { ...box }, ref: box, el: boxEl, moved: false };
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

            if (pdDrag.mode === 'draw' || pdDrag.mode === 'draw-erase') {
                let ghost = overlay.querySelector('.pd-ghost');
                if (!ghost) { ghost = document.createElement('div'); ghost.className = 'pd-ghost'; overlay.appendChild(ghost); }
                if (pdDrag.mode === 'draw-erase') { ghost.style.borderColor = '#dc2626'; ghost.style.background = 'rgba(255,255,255,0.85)'; }
                const x = Math.min(point.x, pdDrag.start.x), y = Math.min(point.y, pdDrag.start.y);
                Object.assign(ghost.style, { left: x * 100 + '%', top: y * 100 + '%', width: Math.abs(dx) * 100 + '%', height: Math.abs(dy) * 100 + '%' });
                return;
            }

            const b = pdDrag.orig, box = pdDrag.ref;
            if (pdDrag.mode === 'move') {
                box.x = clamp(b.x + dx, 0, 1 - b.w);
                box.y = clamp(b.y + dy, 0, 1 - b.h);
            } else {
                box.w = clamp(b.w + dx, 0.01, 1 - b.x);
                box.h = clamp(b.h + dy, 0.006, 1 - b.y);
            }
            Object.assign(pdDrag.el.style, { left: box.x * 100 + '%', top: box.y * 100 + '%', width: box.w * 100 + '%', height: box.h * 100 + '%' });
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
                pdSelText = drag.i === null ? null : { page: drag.page, i: drag.i };
                renderDesigner();
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
            if (drag.moved) markDirty();
            renderDesigner();
        });
    }

    // ================= RENDERING =================
    function render() {
        renderPdfCoverage();
        const canvas = document.getElementById('fbCanvas');
        let html = insertZone(0);
        fbFields.forEach((f, i) => {
            html += f.key === fbEditingKey ? editorBlock(f, i) : questionBlock(f, i);
            html += insertZone(i + 1);
        });
        if (!fbFields.length) {
            html = `<div class="edit-only text-center py-10 text-slate-400"><i class="fas fa-wand-magic-sparkles text-3xl mb-3 text-slate-300"></i><p class="font-black text-slate-600">No questions yet</p><p class="text-sm">Click <strong>Add question</strong> below to start.</p></div>`;
        }
        canvas.innerHTML = html;
        bindDragAndDrop();

        const editing = canvas.querySelector('.q-edit');
        if (editing) {
            editing.querySelectorAll('textarea').forEach(autoGrow);
        }
    }

    function insertZone(index) {
        return `<div class="insert-zone"><button type="button" title="Insert a question here" onclick="openTypeMenu(this, ${index})"><i class="fas fa-plus"></i></button></div>`;
    }

    // A question exactly as the student sees it, with hover controls
    function questionBlock(f, i) {
        const toolbar = `
            <div class="q-toolbar" onclick="event.stopPropagation()">
                <button type="button" class="fb-handle cursor-grab" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></button>
                <button type="button" onclick="moveField('${f.key}', -1)" ${i === 0 ? 'disabled' : ''} title="Move up"><i class="fas fa-arrow-up"></i></button>
                <button type="button" onclick="moveField('${f.key}', 1)" ${i === fbFields.length - 1 ? 'disabled' : ''} title="Move down"><i class="fas fa-arrow-down"></i></button>
                <button type="button" onclick="duplicateField('${f.key}')" title="Duplicate"><i class="fas fa-clone"></i></button>
                <button type="button" onclick="editField('${f.key}')" title="Edit"><i class="fas fa-pen"></i></button>
                <button type="button" class="danger" onclick="deleteField('${f.key}')" title="Delete"><i class="fas fa-trash-can"></i></button>
            </div>`;
        const tag = `<span class="q-type-tag"><i class="fas ${typeIcon(f.type)}"></i> ${esc(typeLabel(f.type))}${f.id === null ? ' · new' : ''}</span>`
            + (f.box && f.type !== 'Section' ? `<span class="edit-only absolute -top-2.5 left-44 px-2 py-px rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-[9px] font-black uppercase tracking-wider" title="Printed in a box drawn on the PDF">▭ Box on PDF page ${f.box.page}</span>` : '')
            + (f.pdf && f.type !== 'Section' ? (activeTemplate().fields.some(p => p.name === f.pdf)
                ? `<span class="edit-only absolute -top-2.5 left-44 px-2 py-px rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-[9px] font-black uppercase tracking-wider" title="Printed on the PDF form">📄 ${esc(f.pdf)}</span>`
                : `<span class="edit-only absolute -top-2.5 left-44 px-2 py-px rounded-full bg-amber-50 border border-amber-300 text-amber-700 text-[9px] font-black uppercase tracking-wider" title="This field is not on the selected PDF, so the answer is not printed">⚠️ ${esc(f.pdf)} — not on this PDF</span>`) : '');
        return `<div class="q-block" data-key="${f.key}" onclick="editField('${f.key}')" title="Click to edit">${tag}${toolbar}${studentView(f)}</div>`;
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

    // The same question turned into an in-place editor
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
                    <button type="button" onclick="closeEditor()" class="ml-2 bg-blue-600 text-white px-5 py-2 rounded-xl text-xs font-black uppercase tracking-widest hover:bg-blue-700">Done</button>
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
    function addField(type, at) {
        const f = { id: null, key: 'k' + (++fbKeySeq), type, required: type !== 'Section', help: '', placeholder: '', pdf: '',
            label: type === 'Section' ? 'New Section' : (isAuto(type) ? FB_AUTO_TYPES[type] : ''),
            options: needsOptions(type) ? 'Option 1\nOption 2' : '' };
        fbFields.splice(at, 0, f);
        markDirty();
        editField(f.key);
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
        markDirty();
        render();
    }

    // ================= TYPE PICKER =================
    function openTypeMenu(button, index) {
        fbInsertAt = index;
        const menu = document.getElementById('typeMenu');
        menu.classList.remove('hidden');
        const r = button.getBoundingClientRect();
        const left = Math.min(window.scrollX + r.left + r.width / 2 - 160, window.scrollX + document.documentElement.clientWidth - menu.offsetWidth - 16);
        menu.style.left = Math.max(16, left) + 'px';
        const below = window.scrollY + r.bottom + 8;
        const fitsBelow = r.bottom + menu.offsetHeight + 16 < window.innerHeight;
        menu.style.top = (fitsBelow ? below : window.scrollY + r.top - menu.offsetHeight - 8) + 'px';
    }

    function closeTypeMenu() { document.getElementById('typeMenu').classList.add('hidden'); }

    function pickType(type) {
        closeTypeMenu();
        addField(type, fbInsertAt);
    }

    document.addEventListener('mousedown', e => {
        const menu = document.getElementById('typeMenu');
        if (!menu.classList.contains('hidden') && !menu.contains(e.target) && !e.target.closest('.insert-zone, [onclick^="openTypeMenu"]')) closeTypeMenu();
    });

    // ================= DRAG & DROP =================
    function bindDragAndDrop() {
        let dragKey = null;
        document.querySelectorAll('.q-block').forEach(block => {
            // Blocks are only draggable while the grip is held
            const handle = block.querySelector('.fb-handle');
            handle.addEventListener('mousedown', () => block.setAttribute('draggable', 'true'));
            handle.addEventListener('mouseup', () => block.removeAttribute('draggable'));

            block.addEventListener('dragstart', e => {
                dragKey = block.dataset.key;
                block.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            block.addEventListener('dragend', () => {
                block.removeAttribute('draggable');
                block.classList.remove('is-dragging');
                document.querySelectorAll('.q-block').forEach(b => b.classList.remove('drop-before', 'drop-after'));
            });
            block.addEventListener('dragover', e => {
                if (!dragKey || block.dataset.key === dragKey) return;
                e.preventDefault();
                const after = e.offsetY > block.offsetHeight / 2;
                block.classList.toggle('drop-after', after);
                block.classList.toggle('drop-before', !after);
            });
            block.addEventListener('dragleave', () => block.classList.remove('drop-before', 'drop-after'));
            block.addEventListener('drop', e => {
                e.preventDefault();
                if (!dragKey || block.dataset.key === dragKey) return;
                const after = block.classList.contains('drop-after');
                const moving = findField(dragKey);
                fbFields = fbFields.filter(f => f.key !== dragKey);
                const target = findIndex(block.dataset.key);
                fbFields.splice(after ? target + 1 : target, 0, moving);
                dragKey = null;
                markDirty();
                render();
            });
        });
    }

    // ================= PREVIEW MODE =================
    function togglePreview() {
        const on = !document.body.classList.contains('fb-preview');
        if (on) { fbEditingKey = null; closeTypeMenu(); render(); }
        document.body.classList.toggle('fb-preview', on);
        const instructions = document.getElementById('fbInstructions').value.trim();
        const box = document.getElementById('fbInstructionsBox');
        box.textContent = 'ℹ️ ' + instructions;
        box.classList.toggle('hidden', !on || !instructions);
        document.querySelector('#fbPreviewBtn span').textContent = on ? 'Back to editing' : 'Preview as student';
        document.querySelector('#fbPreviewBtn i').className = on ? 'fas fa-pen' : 'fas fa-eye';
    }

    // ================= SAVE =================
    function showAlert(type, message) {
        const el = document.getElementById('fbAlert');
        el.className = 'mb-5 px-5 py-4 rounded-2xl text-sm font-bold ' + (type === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700');
        el.innerHTML = (type === 'success' ? '✅ ' : '⚠️ ') + esc(message);
        el.classList.remove('hidden');
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    async function saveForm() {
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
                    edits: fbEdits.map(({ key, ...e }) => e),
                    instructions: document.getElementById('fbInstructions').value,
                    fields: fbFields.map(({ key, ...f }) => ({ ...f, options: getOpts(f).map(o => o.trim()).filter(Boolean).join('\n') }))
                })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Could not save the form.');

            data.ids.forEach((id, i) => { fbFields[i].id = id; });
            fbDirty = false;
            document.getElementById('fbDirty').classList.add('hidden');
            document.getElementById('fbSavedAt').textContent = data.saved_at;
            fbEditingKey = null;
            render();
            showAlert('success', 'Application form saved. Students now see this version.' + (data.archived ? ` ${data.archived} removed question(s) were archived to keep past answers.` : ''));
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
        if (e.key === 'Escape') { closeTypeMenu(); if (fbEditingKey) closeEditor(); }
    });

    autoGrow(document.getElementById('fbInstructions'));
    render();
</script>
