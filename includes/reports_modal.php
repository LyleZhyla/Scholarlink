<?php
// ✨ GENERATE REPORTS WINDOW (Masterlist / manage_applications.php in every admin portal)
// The four System Reports with their filters → Preview, PDF or Excel (actions/export_report.php),
// plus each sponsor's own report format. CHED's Annex 1 stays a separate button, for Tulong Dunong / TES only.
require_once __DIR__ . '/reports.php';

$rm_scope = reportScholarships($pdo, $_SESSION['role'], (int) $_SESSION['user_id']);
$rm_options = reportFilterOptions($pdo);
$rm_input = 'w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 outline-none focus:border-blue-500 cursor-pointer';
$rm_label = 'block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5';

// Status buttons: key => [label, icon, colour], with how many applications this admin can report on
if (!defined('RM_STATUS_BUTTONS')) define('RM_STATUS_BUTTONS', [
    ''            => ['All', 'fa-layer-group', 'slate'],
    'Pending'     => ['Pending', 'fa-clock', 'amber'],
    'Shortlisted' => ['Shortlisted', 'fa-list-check', 'purple'],
    'Approved'    => ['Approved', 'fa-circle-check', 'green'],
    'Rejected'    => ['Rejected', 'fa-circle-xmark', 'red'],
]);
$rm_status_counts = ['' => 0];
if ($rm_scope) {
    $rm_in = implode(',', array_map('intval', array_keys($rm_scope)));
    foreach ($pdo->query("SELECT CASE WHEN Status IN ('Pending', 'Submitted', 'Under Review', 'Under_Review') THEN 'Pending' ELSE Status END AS s, COUNT(*) n
                          FROM application WHERE ScholarshipID IN ($rm_in) GROUP BY s") as $r) {
        $rm_status_counts[$r['s']] = (int) $r['n'];
        $rm_status_counts[''] += (int) $r['n'];
    }
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<div id="reportsModal" onclick="if (event.target === this) closeReportsModal()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-start justify-center p-4 overflow-y-auto">
    <div class="bg-white w-full max-w-6xl rounded-[2rem] shadow-2xl my-6 overflow-hidden">
        <div class="px-6 lg:px-8 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between gap-4">
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">📑 System Reports</h3>
                <p class="text-xs font-bold text-slate-500 mt-0.5">By academic year, semester, sex, citizenship, region and municipality · Preview, PDF or Excel</p>
            </div>
            <button type="button" onclick="closeReportsModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 font-black text-xl">&times;</button>
        </div>

        <form id="rmForm" onsubmit="event.preventDefault(); rmPreview();" class="p-6 lg:p-8 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="<?= $rm_label ?>">Report</label>
                    <select name="type" id="rmType" onchange="rmSync()" class="<?= $rm_input ?>">
                        <?php foreach (REPORT_TYPES as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="<?= $rm_label ?>">Scholarship</label>
                    <select name="scholarship_id" id="rmScholarship" onchange="rmSync()" class="<?= $rm_input ?>">
                        <option value="">All scholarships<?= $_SESSION['role'] === 'External_Admin' ? ' (yours)' : '' ?></option>
                        <?php foreach ($rm_scope as $s): ?>
                            <option value="<?= $s['ScholarshipID'] ?>"><?= htmlspecialchars($s['Name']) ?><?= $_SESSION['role'] !== 'External_Admin' ? ' — ' . htmlspecialchars($s['Provider']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p id="rmAllHint" class="hidden text-[11px] font-bold text-slate-400 mt-1">All scholarships: one section per scholarship, each in its sponsor's format.</p>
                </div>
            </div>

            <!-- Application status: always in view for student reports -->
            <div id="rmStatusWrap">
                <label class="<?= $rm_label ?>">Application Status</label>
                <input type="hidden" name="status" id="rmStatus" value="">
                <div class="flex flex-wrap gap-2">
                    <?php foreach (RM_STATUS_BUTTONS as $key => [$label, $icon, $color]): ?>
                        <button type="button" data-status="<?= $key ?>" data-color="<?= $color ?>" onclick="rmSetStatus('<?= $key ?>')"
                            class="rm-status inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border text-[12px] font-black uppercase tracking-wider transition-all">
                            <i class="fas <?= $icon ?>"></i> <?= $label ?>
                            <span class="rm-status-count px-1.5 py-0.5 rounded-md text-[10px]"><?= number_format($rm_status_counts[$key] ?? 0) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p id="rmStatusHint" class="hidden text-[11px] font-bold text-slate-400 mt-1.5">Scholar lists only include approved applicants. Pick another status to list those applicants instead.</p>
            </div>

            <div id="rmPeople" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
                <div><label class="<?= $rm_label ?>">Academic Year</label><select name="ay" class="<?= $rm_input ?>"><option value="">All years</option><?php foreach ($rm_options['years'] as $y): ?><option value="<?= $y ?>">AY <?= $y ?></option><?php endforeach; ?></select></div>
                <div><label class="<?= $rm_label ?>">Semester</label><select name="semester" class="<?= $rm_input ?>"><option value="">All semesters</option><?php foreach ($rm_options['semesters'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?></select></div>
                <div><label class="<?= $rm_label ?>">Sex</label><select name="gender" class="<?= $rm_input ?>"><option value="">All</option><?php foreach ($rm_options['genders'] as $g): ?><option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option><?php endforeach; ?></select></div>
                <div><label class="<?= $rm_label ?>">Citizenship</label><select name="citizenship" class="<?= $rm_input ?>"><option value="">All</option><?php foreach ($rm_options['citizenships'] as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select></div>
                <div><label class="<?= $rm_label ?>">Region</label><select name="region" class="<?= $rm_input ?>"><option value="">All regions</option><?php foreach ($rm_options['regions'] as $r): ?><option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option><?php endforeach; ?></select></div>
                <div><label class="<?= $rm_label ?>">City / Municipality</label><select name="municipality" class="<?= $rm_input ?>"><option value="">All</option><?php foreach ($rm_options['municipalities'] as $m): ?><option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option><?php endforeach; ?></select></div>
            </div>

            <div class="flex flex-wrap items-end gap-4">
                <label id="rmClosedWrap" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 cursor-pointer pb-2.5">
                    <input type="checkbox" name="include_closed" value="1" class="w-4 h-4 accent-blue-600"> Include closed / past-deadline scholarships
                </label>
                <div class="flex flex-wrap gap-2 ml-auto">
                    <button type="submit" class="bg-slate-900 text-white px-5 py-3 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all"><i class="fas fa-eye"></i> Preview</button>
                    <button type="button" onclick="rmExport('pdf')" class="bg-red-600 text-white px-5 py-3 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-red-700 transition-all"><i class="fas fa-file-pdf"></i> PDF</button>
                    <button type="button" onclick="rmExport('xlsx')" class="bg-green-600 text-white px-5 py-3 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-green-700 transition-all"><i class="fas fa-file-excel"></i> Excel</button>
                </div>
            </div>

            <!-- Information to include: every registration field, grouped like the registration form -->
            <input type="hidden" name="columns" id="rmColumns">
            <div class="rounded-2xl border border-slate-200 overflow-hidden">
                <button type="button" onclick="rmTogglePicker()" class="w-full flex items-center justify-between gap-3 px-5 py-3.5 bg-white hover:bg-slate-50 text-left">
                    <span>
                        <span class="text-sm font-black text-slate-900">🧾 Information to include</span>
                        <span id="rmPickSummary" class="ml-2 text-[11px] font-black uppercase tracking-widest text-slate-400"></span>
                    </span>
                    <i id="rmPickChevron" class="fas fa-chevron-down text-slate-400 transition-transform"></i>
                </button>
                <div id="rmPicker" class="hidden border-t border-slate-100 bg-slate-50/60 px-5 py-4">
                    <div class="flex flex-wrap items-center gap-2 mb-4">
                        <p class="text-xs font-bold text-slate-500 mr-auto">Tick only the information this report needs. Columns follow the report format's order, then the order below.</p>
                        <button type="button" onclick="rmPickSet('all')" class="px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-[11px] font-black uppercase tracking-widest text-slate-600 hover:border-blue-400 hover:text-blue-600">Select all</button>
                        <button type="button" onclick="rmPickSet('none')" class="px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-[11px] font-black uppercase tracking-widest text-slate-600 hover:border-blue-400 hover:text-blue-600">Clear</button>
                        <button type="button" onclick="rmPickSet('default')" class="px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-[11px] font-black uppercase tracking-widest text-slate-600 hover:border-blue-400 hover:text-blue-600">Default format</button>
                    </div>
                    <div id="rmPickGroups" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4"></div>
                </div>
            </div>
        </form>

        <!-- Sponsor format of the chosen scholarship -->
        <div id="rmFormat" class="hidden mx-6 lg:mx-8 mb-6 p-5 rounded-2xl border border-slate-200 bg-slate-50/60"></div>

        <!-- Preview -->
        <div id="rmPreviewWrap" class="hidden border-t border-slate-100">
            <iframe id="rmPreview" class="w-full h-[70vh] bg-slate-200" title="Report preview"></iframe>
        </div>
    </div>
</div>

<script>
    // ---------- Generate Reports window ----------
    function openReportsModal() {
        const m = document.getElementById('reportsModal');
        m.classList.remove('hidden');
        m.classList.add('flex');
        rmSync();
    }
    function closeReportsModal() {
        const m = document.getElementById('reportsModal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    const rmEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // Show only the filters that apply to the chosen report
    function rmSync() {
        const type = document.getElementById('rmType').value;
        const sid = document.getElementById('rmScholarship').value;
        document.getElementById('rmPeople').classList.toggle('hidden', type === 'scholarships');
        document.getElementById('rmStatusWrap').classList.toggle('hidden', type === 'scholarships');
        rmPaintStatus();
        document.getElementById('rmClosedWrap').classList.toggle('hidden', type !== 'scholarships');
        document.getElementById('rmAllHint').classList.toggle('hidden', !(type === 'scholars_per_scholarship' && !sid));
        if (type === 'scholars_per_scholarship' && sid) rmLoadFormat(sid);
        else document.getElementById('rmFormat').classList.add('hidden');
        rmLoadColumns();
    }

    // ---------- Application status ----------
    // Applicant reports use the chosen status; scholar reports are approved applicants by definition,
    // so there "Approved" is fixed and choosing another status switches to the applicants report.
    const RM_STATUS_STYLE = {
        slate: 'bg-slate-900 border-slate-900 text-white', amber: 'bg-amber-500 border-amber-500 text-white',
        purple: 'bg-purple-600 border-purple-600 text-white', green: 'bg-green-600 border-green-600 text-white', red: 'bg-red-600 border-red-600 text-white',
    };
    const RM_STATUS_IDLE = 'bg-white border-slate-200 text-slate-600 hover:border-slate-400';

    function rmSetStatus(status) {
        const typeSel = document.getElementById('rmType');
        if (typeSel.value !== 'applicants') {
            if (status === 'Approved') return;           // scholar reports already are the approved ones
            typeSel.value = 'applicants';
        }
        document.getElementById('rmStatus').value = status;
        rmSync();
    }

    function rmPaintStatus() {
        const type = document.getElementById('rmType').value;
        const scholarReport = type === 'scholars' || type === 'scholars_per_scholarship';
        const current = scholarReport ? 'Approved' : document.getElementById('rmStatus').value;
        if (scholarReport) document.getElementById('rmStatus').value = '';   // not sent: these reports are approved-only anyway
        document.querySelectorAll('#rmStatusWrap .rm-status').forEach(b => {
            const on = b.dataset.status === current;
            b.className = 'rm-status inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border text-[12px] font-black uppercase tracking-wider transition-all '
                + (on ? RM_STATUS_STYLE[b.dataset.color] : RM_STATUS_IDLE);
            b.querySelector('.rm-status-count').className = 'rm-status-count px-1.5 py-0.5 rounded-md text-[10px] ' + (on ? 'bg-white/25' : 'bg-slate-100 text-slate-500');
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        document.getElementById('rmStatusHint').classList.toggle('hidden', !scholarReport);
    }

    // ---------- Information to include ----------
    let rmCols = null;      // { type, groups, defaults, format, picked: Set }
    let rmColsReq = 0;

    async function rmLoadColumns() {
        const type = document.getElementById('rmType').value;
        const sid = document.getElementById('rmScholarship').value;
        const req = ++rmColsReq;
        const res = await fetch('../actions/report_columns.php?type=' + encodeURIComponent(type) + '&scholarship_id=' + encodeURIComponent(sid));
        const data = await res.json();
        if (req !== rmColsReq) return;
        if (!data.success) { document.getElementById('rmPickGroups').innerHTML = `<p class="text-sm font-bold text-red-600">${rmEsc(data.error)}</p>`; return; }

        // Keep a hand-picked selection when switching between student reports; otherwise start from the format
        const keys = new Set(data.groups.flatMap(g => g.columns.map(c => c.key)));
        const kept = rmCols && rmPickIsCustom() && (rmCols.type === 'scholarships') === (type === 'scholarships')
            ? [...rmCols.picked].filter(k => keys.has(k)) : [];
        rmCols = { type, groups: data.groups, defaults: data.defaults, format: data.format, picked: new Set(kept.length ? kept : data.defaults) };
        rmRenderPicker();
    }

    function rmPickIsCustom() {
        return !!rmCols && (rmCols.picked.size !== rmCols.defaults.length || rmCols.defaults.some(k => !rmCols.picked.has(k)));
    }

    // Picked keys in order: the format's own order first, then the order of the groups
    function rmPickedList() {
        const order = [...rmCols.defaults, ...rmCols.groups.flatMap(g => g.columns.map(c => c.key))];
        return [...new Set(order)].filter(k => rmCols.picked.has(k));
    }

    function rmRenderPicker() {
        const custom = rmPickIsCustom();
        // Untouched → no list sent, so each scholarship keeps its sponsor's format (and column labels)
        document.getElementById('rmColumns').value = custom ? rmPickedList().join(',') : '';
        const allSections = rmCols.type === 'scholars_per_scholarship' && !document.getElementById('rmScholarship').value;
        document.getElementById('rmPickSummary').textContent = custom
            ? rmCols.picked.size + ' selected'
            : (allSections ? "Each sponsor's format" : rmCols.format + ' format') + ' · ' + rmCols.defaults.length + ' columns';

        document.getElementById('rmPickGroups').innerHTML = rmCols.groups.map((g, gi) => {
            const on = g.columns.filter(c => rmCols.picked.has(c.key)).length;
            const all = on === g.columns.length;
            return `<div class="bg-white rounded-xl border border-slate-200 p-4">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-[11px] font-black uppercase tracking-widest text-slate-700">${rmEsc(g.name)}</span>
                    <span class="ml-auto text-[10px] font-black ${on ? 'text-blue-600' : 'text-slate-400'}">${on}/${g.columns.length}</span>
                </div>
                <!-- Select all / clear for just this group -->
                <div class="flex items-center gap-3 pb-2 mb-2 border-b border-slate-100">
                    <label class="flex items-center gap-2 text-xs font-black text-blue-700 cursor-pointer">
                        <input type="checkbox" ${all ? 'checked' : ''} ${on && !all ? 'data-mixed="1"' : ''} onchange="rmPickGroup(${gi}, this.checked)" class="w-3.5 h-3.5 accent-blue-600">
                        Select all
                    </label>
                    <button type="button" onclick="rmPickGroup(${gi}, false)" ${on ? '' : 'disabled'} class="ml-auto text-[11px] font-black text-slate-400 hover:text-red-600 disabled:opacity-40 disabled:hover:text-slate-400">Clear</button>
                </div>
                <div class="space-y-1.5 pl-1">${g.columns.map(c => `
                    <label class="flex items-center gap-2 text-sm font-bold text-slate-600 cursor-pointer hover:text-slate-900">
                        <input type="checkbox" value="${rmEsc(c.key)}" ${rmCols.picked.has(c.key) ? 'checked' : ''} onchange="rmPickOne(this)" class="w-3.5 h-3.5 accent-blue-600">
                        ${rmEsc(c.label)}
                    </label>`).join('')}</div>
            </div>`;
        }).join('');
        document.querySelectorAll('#rmPickGroups input[data-mixed]').forEach(el => el.indeterminate = true);
    }

    function rmTogglePicker() {
        const open = !document.getElementById('rmPicker').classList.toggle('hidden');
        document.getElementById('rmPickChevron').classList.toggle('rotate-180', open);
    }
    function rmPickOne(el) {
        el.checked ? rmCols.picked.add(el.value) : rmCols.picked.delete(el.value);
        rmRenderPicker();
    }
    function rmPickGroup(gi, on) {
        rmCols.groups[gi].columns.forEach(c => on ? rmCols.picked.add(c.key) : rmCols.picked.delete(c.key));
        rmRenderPicker();
    }
    function rmPickSet(which) {
        rmCols.picked = new Set(which === 'all' ? rmCols.groups.flatMap(g => g.columns.map(c => c.key)) : which === 'none' ? [] : rmCols.defaults);
        rmRenderPicker();
    }
    function rmPickReady() {
        if (rmCols && rmCols.picked.size === 0) { alert('Tick at least one piece of information to include in the report.'); return false; }
        return true;
    }

    function rmQuery(format) {
        const params = new URLSearchParams(new FormData(document.getElementById('rmForm')));
        [...params.keys()].forEach(k => { if (params.get(k) === '') params.delete(k); });
        params.set('format', format);
        return '../actions/export_report.php?' + params.toString();
    }

    function rmPreview() {
        if (!rmPickReady()) return;
        document.getElementById('rmPreviewWrap').classList.remove('hidden');
        document.getElementById('rmPreview').src = rmQuery('html');
        document.getElementById('rmPreviewWrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function rmExport(format) {
        if (!rmPickReady()) return;
        window.open(rmQuery(format) + (format === 'xlsx' ? '&download=1' : ''), '_blank');
    }

    // ---------- Sponsor report format ----------
    let rmFmt = null;   // { sid, can_edit, available, columns, ... }

    async function rmLoadFormat(sid) {
        const box = document.getElementById('rmFormat');
        box.classList.remove('hidden');
        box.innerHTML = '<p class="text-sm font-bold text-slate-400"><i class="fas fa-spinner fa-spin"></i> Loading report format…</p>';
        const res = await fetch('../actions/report_format_info.php?scholarship_id=' + encodeURIComponent(sid));
        const data = await res.json();
        if (!data.success) { box.innerHTML = `<p class="text-sm font-bold text-red-600">${rmEsc(data.error)}</p>`; return; }
        rmFmt = { ...data, sid, editing: false };
        rmRenderFormat();
    }

    function rmRenderFormat() {
        const f = rmFmt, box = document.getElementById('rmFormat');
        const label = 'block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5';
        const input = 'w-full bg-white border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold outline-none focus:border-blue-500';
        let html = `
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Report format · ${rmEsc(f.name)}</p>
                    <p class="text-base font-black text-slate-900">${f.custom ? '📐 Sponsor format' : '📄 Standard ScholarLink format'} <span class="text-sm font-bold text-slate-400">· ${f.columns.length} columns</span></p>
                    <p class="text-xs font-bold mt-1 ${f.is_tulong_dunong ? 'text-green-700' : 'text-slate-400'}">${f.is_tulong_dunong
                        ? '<i class="fas fa-circle-check"></i> CHED\'s Annex 1 template is also available for this Tulong Dunong / TES scholarship (Annex 1 button on the Masterlist).'
                        : 'CHED\'s Annex 1 template is only for Tulong Dunong / TES. This scholarship uses its sponsor\'s format.'}</p>
                </div>
                ${f.can_edit
                    ? `<button type="button" onclick="rmFmt.editing = !rmFmt.editing; rmRenderFormat();" class="shrink-0 bg-blue-600 text-white px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-700"><i class="fas fa-sliders"></i> ${f.editing ? 'Close editor' : 'Customize format'}</button>`
                    : `<span class="text-xs font-bold text-slate-400">Only ${rmEsc(f.owner)} can change this format.</span>`}
            </div>`;

        if (f.editing) {
            const used = new Set(f.columns.map(c => c.key));
            const keys = Object.keys(f.available).filter(k => !used.has(k));
            const questions = keys.filter(k => /^q\d+$/.test(k)), standard = keys.filter(k => !/^q\d+$/.test(k));
            html += `
            <div class="mt-5 pt-5 border-t border-slate-200">
                <div class="grid md:grid-cols-3 gap-3 mb-4">
                    <div><label class="${label}">Report title</label><input id="rmFmtTitle" type="text" maxlength="200" value="${rmEsc(f.title)}" placeholder="List of Scholars per Scholarship" class="${input}"></div>
                    <div><label class="${label}">Subtitle</label><input id="rmFmtSubtitle" type="text" maxlength="255" value="${rmEsc(f.subtitle)}" placeholder="${rmEsc(f.name)} — ${rmEsc(f.provider)}" class="${input}"></div>
                    <div><label class="${label}">Prepared by</label><input id="rmFmtPrepared" type="text" maxlength="150" value="${rmEsc(f.prepared_by)}" placeholder="Name and position (optional)" class="${input}"></div>
                </div>
                <label class="${label}">Columns, in order <span class="normal-case tracking-normal font-bold">(type over a heading to rename it)</span></label>
                <div class="space-y-2">${f.columns.map((c, i) => `
                    <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-xl px-3 py-2">
                        <span class="w-6 text-xs font-black text-slate-400">${i + 1}.</span>
                        <input type="text" value="${rmEsc(c.label)}" maxlength="100" oninput="rmFmt.columns[${i}].label = this.value" class="flex-1 min-w-0 bg-transparent outline-none text-sm font-bold text-slate-800">
                        <span class="hidden sm:inline text-[10px] font-black text-slate-400 uppercase tracking-wider truncate max-w-[200px]">${rmEsc(f.available[c.key] || c.key)}</span>
                        <button type="button" onclick="rmMove(${i}, -1)" ${i === 0 ? 'disabled' : ''} class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 disabled:opacity-30"><i class="fas fa-arrow-up"></i></button>
                        <button type="button" onclick="rmMove(${i}, 1)" ${i === f.columns.length - 1 ? 'disabled' : ''} class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 disabled:opacity-30"><i class="fas fa-arrow-down"></i></button>
                        <button type="button" onclick="rmFmt.columns.splice(${i}, 1); rmRenderFormat();" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600"><i class="fas fa-xmark"></i></button>
                    </div>`).join('')}
                </div>
                <div class="flex flex-wrap gap-2 mt-3">
                    <select id="rmFmtAdd" class="${input} sm:w-80 cursor-pointer">
                        <option value="">— Choose a column to add —</option>
                        <optgroup label="Student & scholarship data">${standard.map(k => `<option value="${k}">${rmEsc(f.available[k])}</option>`).join('')}</optgroup>
                        ${questions.length ? `<optgroup label="This scholarship's application questions">${questions.map(k => `<option value="${k}">${rmEsc(f.available[k])}</option>`).join('')}</optgroup>` : ''}
                    </select>
                    <button type="button" onclick="rmAdd()" class="px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-slate-700 text-xs font-black hover:border-blue-500"><i class="fas fa-plus"></i> Add column</button>
                </div>
                <div class="flex flex-wrap gap-2 mt-4">
                    <button type="button" onclick="rmSaveFormat()" class="bg-slate-900 text-white px-5 py-3 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600"><i class="fas fa-floppy-disk"></i> Save sponsor format</button>
                    ${f.custom ? `<button type="button" onclick="rmSaveFormat(true)" class="px-5 py-3 rounded-xl text-[11px] font-black uppercase tracking-widest text-slate-600 border border-slate-200 bg-white hover:border-red-400 hover:text-red-600">Reset to standard</button>` : ''}
                </div>
                <p id="rmFmtMsg" class="text-sm font-bold mt-3"></p>
            </div>`;
        }
        box.innerHTML = html;
    }

    function rmMove(i, delta) {
        const c = rmFmt.columns, j = i + delta;
        [c[i], c[j]] = [c[j], c[i]];
        rmRenderFormat();
    }

    function rmAdd() {
        const key = document.getElementById('rmFmtAdd').value;
        if (!key) return;
        rmFmt.columns.push({ key, label: rmFmt.available[key] });
        rmRenderFormat();
    }

    async function rmSaveFormat(reset = false) {
        if (reset && !confirm('Go back to the standard ScholarLink format for this scholarship?')) return;
        const res = await fetch('../actions/save_report_format.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                scholarship_id: Number(rmFmt.sid), reset, columns: rmFmt.columns,
                title: reset ? '' : document.getElementById('rmFmtTitle').value,
                subtitle: reset ? '' : document.getElementById('rmFmtSubtitle').value,
                prepared_by: reset ? '' : document.getElementById('rmFmtPrepared').value,
            })
        });
        const data = await res.json();
        if (!data.success) {
            const msg = document.getElementById('rmFmtMsg');
            if (msg) { msg.className = 'text-sm font-bold mt-3 text-red-600'; msg.textContent = '⚠️ ' + data.error; }
            return;
        }
        await rmLoadFormat(rmFmt.sid);
        rmCols = null;     // the saved format's columns become the new default ticks
        await rmLoadColumns();
        rmPreview();
    }
</script>
