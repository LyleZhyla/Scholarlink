<?php
// ✨ APPROVAL NOTICE EDITOR (shared by the Internal, External and Super Admin portals)
// The scholarship's deciding admin sets what approved scholars must still submit; the notice is
// emailed (as a PDF) to each student when approved. Each copy lists only that student's name.
// Expects: $pdo, $scholarship (row), $back_url. Saves through actions/save_award_notice.php.
require_once __DIR__ . '/award_notice.php';

$notice = getAwardNotice($pdo, (int) $scholarship['ScholarshipID']);
$approved_stmt = $pdo->prepare("SELECT COUNT(*) FROM application WHERE ScholarshipID = ? AND Status = 'Approved'");
$approved_stmt->execute([$scholarship['ScholarshipID']]);
$approved_count = (int) $approved_stmt->fetchColumn();
$preview_url = '../actions/award_notice_pdf.php?scholarship_id=' . (int) $scholarship['ScholarshipID'];
$an_input = 'w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-800 outline-none focus:border-blue-500';
$an_label = 'block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5';
$active_term = awardActiveTerm($pdo);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="sticky top-0 z-40 -mx-5 lg:-mx-10 -mt-5 lg:-mt-10 mb-8 px-5 lg:px-10 py-4 bg-white/90 backdrop-blur border-b border-slate-200 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
            <a href="<?= htmlspecialchars($back_url) ?>" class="w-10 h-10 shrink-0 bg-white border border-slate-200 rounded-xl flex items-center justify-center text-slate-500 hover:text-slate-900 transition-all" title="Back"><i class="fas fa-arrow-left"></i></a>
            <div class="min-w-0">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Approval Notice</p>
                <h2 class="text-xl lg:text-2xl font-black text-slate-900 tracking-tight truncate"><?= htmlspecialchars($scholarship['Name']) ?></h2>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span id="anState" class="text-xs font-bold text-slate-400 mr-2"><?= $notice['custom'] ? 'Saved' : 'Using the default requirements' ?></span>
            <button type="button" onclick="anSave()" class="bg-slate-900 text-white px-6 py-3 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all"><i class="fas fa-floppy-disk"></i> Save</button>
        </div>
    </header>

    <div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-2xl p-4 mb-6 text-sm font-bold flex gap-3">
        <span>ℹ️</span>
        <span>When an application is approved, the student is emailed this notice as a PDF (and can download it from <b>My Applications</b>). It lists <b>only that student's name</b>, never the other grantees.
            <?= $approved_count ? "{$approved_count} approved scholar" . ($approved_count > 1 ? 's' : '') . " will see your changes the next time they open their notice." : '' ?></span>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
        <!-- Editor -->
        <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm p-6 lg:p-8 space-y-5">
            <!-- Notice heading: blank fields use the scholarship's name, the active term and today's date -->
            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4 space-y-4">
                <p class="text-[11px] font-black uppercase tracking-widest text-slate-700">Notice heading <span class="font-bold normal-case tracking-normal text-slate-400">· leave blank to fill in automatically</span></p>
                <div>
                    <label class="<?= $an_label ?>">Scholarship name on the notice</label>
                    <input id="anName" maxlength="150" value="<?= htmlspecialchars($notice['DisplayName']) ?>" placeholder="<?= htmlspecialchars($scholarship['Name']) ?>" class="<?= $an_input ?>">
                    <p class="text-[11px] font-medium text-slate-400 mt-1">Shown as "LIST OF NEW <span id="anNameEcho"><?= htmlspecialchars(mb_strtoupper(awardDisplayName($scholarship, $notice))) ?></span> GRANTEES". E.g. a shorter name like "TES".</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="<?= $an_label ?>">Semester</label>
                        <select id="anSemester" class="<?= $an_input ?> cursor-pointer">
                            <option value="">Automatic (<?= htmlspecialchars($active_term['semester'] ?: 'none') ?>)</option>
                            <?php foreach (AWARD_SEMESTERS as $sem): ?>
                                <option value="<?= htmlspecialchars($sem) ?>" <?= $notice['Semester'] === $sem ? 'selected' : '' ?>><?= htmlspecialchars($sem) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="<?= $an_label ?>">Academic year</label>
                        <input id="anYear" maxlength="9" value="<?= htmlspecialchars($notice['AcademicYear']) ?>" placeholder="<?= htmlspecialchars($active_term['ay']) ?>" pattern="\d{4}-\d{4}" class="<?= $an_input ?>">
                    </div>
                    <div>
                        <label class="<?= $an_label ?>">Notice date</label>
                        <input id="anDate" type="date" value="<?= htmlspecialchars((string) $notice['NoticeDate']) ?>" class="<?= $an_input ?>">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="<?= $an_label ?>">Batch (optional)</label>
                    <input id="anBatch" maxlength="60" value="<?= htmlspecialchars($notice['BatchLabel']) ?>" placeholder="e.g. Batch 2.1" class="<?= $an_input ?>">
                </div>
                <div>
                    <label class="<?= $an_label ?>">Last day of submission</label>
                    <input id="anDeadline" type="date" value="<?= htmlspecialchars((string) $notice['SubmissionDeadline']) ?>" class="<?= $an_input ?>">
                </div>
            </div>
            <div>
                <label class="<?= $an_label ?>">Instruction</label>
                <input id="anIntro" maxlength="255" value="<?= htmlspecialchars($notice['Intro']) ?>" class="<?= $an_input ?>">
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="<?= $an_label ?> mb-0">Additional requirements to submit</label>
                    <button type="button" onclick="anAdd()" class="text-[11px] font-black uppercase tracking-widest text-blue-600 hover:text-blue-800"><i class="fas fa-plus"></i> Add requirement</button>
                </div>
                <div id="anList" class="space-y-3"></div>
            </div>

            <div>
                <label class="<?= $an_label ?>">Closing note (optional)</label>
                <textarea id="anClosing" rows="3" maxlength="2000" placeholder="e.g. We will be conducting an orientation on the scholarship program; the date shall be announced once approved." class="<?= $an_input ?> font-medium"><?= htmlspecialchars($notice['ClosingNote']) ?></textarea>
            </div>
            <p id="anMsg" class="text-sm font-bold hidden"></p>
        </div>

        <!-- Preview -->
        <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden xl:sticky xl:top-28">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <p class="text-sm font-black text-slate-900">📄 Preview <span class="text-xs font-bold text-slate-400">(sample student; saved version)</span></p>
                <a href="<?= $preview_url ?>" target="_blank" class="text-[11px] font-black uppercase tracking-widest text-blue-600 hover:text-blue-800">Open <i class="fas fa-up-right-from-square"></i></a>
            </div>
            <iframe id="anPreview" src="<?= $preview_url ?>" class="w-full h-[75vh] bg-slate-200" title="Approval notice preview"></iframe>
        </div>
    </div>
</main>

<script>
    const AN_SID = <?= (int) $scholarship['ScholarshipID'] ?>;
    const AN_PREVIEW = <?= json_encode($preview_url) ?>;
    let anReqs = <?= json_encode(array_map(fn($r) => ['text' => $r['text'], 'sub' => $r['sub'] ?? []], $notice['Requirements']), JSON_UNESCAPED_UNICODE) ?>;
    const anEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function anDirty() {
        const s = document.getElementById('anState');
        s.textContent = 'Unsaved changes';
        s.className = 'text-xs font-bold text-amber-600 mr-2';
    }
    ['anName', 'anSemester', 'anYear', 'anDate', 'anBatch', 'anDeadline', 'anIntro', 'anClosing'].forEach(id => {
        document.getElementById(id).addEventListener('input', anDirty);
        document.getElementById(id).addEventListener('change', anDirty);
    });
    // Live echo of the heading name
    document.getElementById('anName').addEventListener('input', e => {
        document.getElementById('anNameEcho').textContent = (e.target.value.trim() || e.target.placeholder).toUpperCase();
    });

    function anRender() {
        const list = document.getElementById('anList');
        if (!anReqs.length) {
            list.innerHTML = '<p class="text-sm font-bold text-slate-400 bg-slate-50 rounded-xl p-4 text-center">No additional requirements. Approved scholars will only get their congratulations notice.</p>';
            return;
        }
        list.innerHTML = anReqs.map((r, i) => `
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50/60">
                <div class="flex items-start gap-2">
                    <span class="w-7 h-7 shrink-0 rounded-lg bg-slate-900 text-white text-xs font-black flex items-center justify-center mt-1.5">${i + 1}</span>
                    <textarea rows="2" maxlength="300" oninput="anReqs[${i}].text = this.value; anDirty()" placeholder="e.g. Photocopy of TAU ID with three (3) signatures"
                        class="flex-1 min-w-0 bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm font-bold text-slate-800 outline-none focus:border-blue-500 resize-y">${anEsc(r.text)}</textarea>
                    <div class="flex flex-col">
                        <button type="button" onclick="anMove(${i}, -1)" ${i === 0 ? 'disabled' : ''} class="w-8 h-7 rounded-lg text-slate-400 hover:bg-white disabled:opacity-30" title="Move up"><i class="fas fa-arrow-up"></i></button>
                        <button type="button" onclick="anMove(${i}, 1)" ${i === anReqs.length - 1 ? 'disabled' : ''} class="w-8 h-7 rounded-lg text-slate-400 hover:bg-white disabled:opacity-30" title="Move down"><i class="fas fa-arrow-down"></i></button>
                    </div>
                    <button type="button" onclick="anReqs.splice(${i}, 1); anRender(); anDirty();" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 mt-1" title="Remove"><i class="fas fa-trash"></i></button>
                </div>
                <div class="pl-9 mt-2 space-y-1.5">
                    ${r.sub.map((s, j) => `
                        <div class="flex items-center gap-2">
                            <span class="text-green-600 text-sm">✓</span>
                            <input maxlength="200" value="${anEsc(s)}" oninput="anReqs[${i}].sub[${j}] = this.value; anDirty()" placeholder="Option / detail"
                                class="flex-1 min-w-0 bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-sm font-medium text-slate-700 outline-none focus:border-blue-500">
                            <button type="button" onclick="anReqs[${i}].sub.splice(${j}, 1); anRender(); anDirty();" class="w-7 h-7 rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600" title="Remove"><i class="fas fa-xmark"></i></button>
                        </div>`).join('')}
                    <button type="button" onclick="anReqs[${i}].sub.push(''); anRender(); anDirty();" class="text-[10px] font-black uppercase tracking-widest text-slate-500 hover:text-blue-600"><i class="fas fa-plus"></i> Add sub-item (e.g. accepted documents)</button>
                </div>
            </div>`).join('');
    }

    function anAdd() {
        anReqs.push({ text: '', sub: [] });
        anRender(); anDirty();
        const boxes = document.querySelectorAll('#anList textarea');
        boxes[boxes.length - 1].focus();
    }
    function anMove(i, d) {
        const j = i + d;
        [anReqs[i], anReqs[j]] = [anReqs[j], anReqs[i]];
        anRender(); anDirty();
    }

    async function anSave() {
        const msg = document.getElementById('anMsg');
        const res = await fetch('../actions/save_award_notice.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                scholarship_id: AN_SID, requirements: anReqs,
                display_name: document.getElementById('anName').value, semester: document.getElementById('anSemester').value,
                academic_year: document.getElementById('anYear').value.trim(), notice_date: document.getElementById('anDate').value,
                batch: document.getElementById('anBatch').value, deadline: document.getElementById('anDeadline').value,
                intro: document.getElementById('anIntro').value, closing: document.getElementById('anClosing').value,
            })
        });
        const data = await res.json().catch(() => ({ success: false, error: 'Could not save. Please try again.' }));
        msg.classList.remove('hidden');
        msg.className = 'text-sm font-bold ' + (data.success ? 'text-green-600' : 'text-red-600');
        msg.textContent = data.success ? '✅ ' + data.message : '⚠️ ' + data.error;
        if (!data.success) return;
        anReqs = anReqs.filter(r => r.text.trim()).map(r => ({ text: r.text.trim(), sub: r.sub.map(s => s.trim()).filter(Boolean) }));
        anRender();
        const s = document.getElementById('anState');
        s.textContent = 'Saved'; s.className = 'text-xs font-bold text-green-600 mr-2';
        document.getElementById('anPreview').src = AN_PREVIEW + '&t=' + Date.now();
    }

    window.addEventListener('beforeunload', e => { if (document.getElementById('anState').textContent === 'Unsaved changes') e.preventDefault(); });
    anRender();
</script>
