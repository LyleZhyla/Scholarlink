<?php
// ✨ PROFILE CHANGE REQUESTS: students' changes outside Personal Details wait here for the Super Admin.
// One table row per request; "Review" opens the details and the decision form under the row.
session_start();
include '../includes/session_manager.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') {
    header("Location: ../admin_login.php");
    exit();
}

include '../includes/db_connect.php';
require_once '../includes/profile_fields.php';

$tabs = ['Pending' => 'Waiting', 'Approved' => 'Approved', 'Rejected' => 'Rejected'];
$tab = isset($tabs[$_GET['status'] ?? '']) ? $_GET['status'] : 'Pending';
$is_pending = $tab === 'Pending';

$counts = $pdo->query("SELECT Status, COUNT(*) FROM profile_change_requests GROUP BY Status")->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $pdo->prepare("
    SELECT r.*, u.FirstName, u.LastName, u.StudentID_Num, u.YearLevel, p.ProgramName,
           CONCAT(a.FirstName, ' ', a.LastName) AS ReviewerName
    FROM profile_change_requests r
    JOIN users u ON u.UserID = r.UserID
    LEFT JOIN program p ON p.ProgramID = u.ProgramID
    LEFT JOIN users a ON a.UserID = r.ReviewedBy
    WHERE r.Status = ?
    ORDER BY " . ($is_pending ? 'r.CreatedAt ASC' : 'r.ReviewedAt DESC') . "
    LIMIT 1000");
$st->execute([$tab]);
$requests = $st->fetchAll(PDO::FETCH_ASSOC);

// Group each request's changes by profile section
$section_names = array_values(array_unique(array_column(PROFILE_APPROVAL_FIELDS, 2)));
foreach ($requests as &$r) {
    $r['sections'] = [];
    foreach (json_decode($r['Changes'], true) ?: [] as $field => $change) {
        if (!isset(PROFILE_APPROVAL_FIELDS[$field])) continue;
        [, $label, $section] = PROFILE_APPROVAL_FIELDS[$field];
        $r['sections'][$section][] = [$label, $change['from'], $change['to']];
    }
    $r['field_count'] = array_sum(array_map('count', $r['sections']));
}
unset($r);

function timeAgo(string $when): string {
    $s = time() - strtotime($when);
    if ($s < 3600) return max(1, (int) ($s / 60)) . ' min ago';
    if ($s < 86400) return (int) ($s / 3600) . ' h ago';
    $d = (int) ($s / 86400);
    return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
}

include '../includes/header.php';
include '../includes/super_sidebar.php';
$cell = 'px-4 py-3 align-middle';
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Profile Change Requests</h2>
        <p class="text-slate-500 font-medium mt-1">Students update their Personal Details themselves. Changes to their student ID, email, year level, IDs, address, family and special status wait for your approval.</p>
    </header>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-50 text-green-700 p-4 rounded-2xl mb-6 font-bold border border-green-200 text-sm">✅ <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-50 text-red-600 p-4 rounded-2xl mb-6 font-bold border border-red-100 text-sm">⚠️ <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2 mb-5">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="?status=<?= $key ?>" class="px-4 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-colors <?= $key === $tab ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100' ?>">
                <?= $label ?> (<?= (int) ($counts[$key] ?? 0) ?>)
            </a>
        <?php endforeach; ?>
    </div>

    <div class="bg-white border border-slate-200 rounded-[1.75rem] shadow-sm overflow-hidden">
        <!-- Toolbar -->
        <div class="p-4 border-b border-slate-100 flex flex-col lg:flex-row gap-3 lg:items-center">
            <input type="search" id="reqSearch" placeholder="Search name or student no..." class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium outline-none focus:border-blue-500">
            <select id="reqSection" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 outline-none cursor-pointer" aria-label="What changed">
                <option value="">Any section</option>
                <?php foreach ($section_names as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
            </select>
            <select id="reqPerPage" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 outline-none cursor-pointer" aria-label="Rows per page">
                <option value="10">10 per page</option><option value="25">25 per page</option><option value="50">50 per page</option>
            </select>
        </div>

        <?php if ($is_pending): ?>
            <!-- Approve several at once -->
            <form id="bulkForm" action="../actions/process_profile_request.php" method="POST" class="hidden px-4 py-3 bg-blue-50 border-b border-blue-100 flex flex-wrap items-center gap-3"
                  onsubmit="return confirm('Approve the selected requests? Each one is checked before it is applied.');">
                <input type="hidden" name="decision" value="approve_many">
                <input type="hidden" name="return" value="status=Pending">
                <span id="bulkCount" class="text-sm font-black text-blue-800"></span>
                <button type="submit" class="bg-slate-900 text-white px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-green-600">Approve selected</button>
                <button type="button" onclick="setChecks(false)" class="text-xs font-bold text-blue-700 hover:underline">Clear</button>
                <span class="text-xs font-medium text-blue-700">To reject, open a request: each rejection needs its own reason.</span>
            </form>
        <?php endif; ?>

        <div class="overflow-x-auto">
            <table class="no-datatable w-full text-sm min-w-[760px]">
                <thead>
                    <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest bg-slate-50 text-left">
                        <?php if ($is_pending): ?><th class="pl-4 py-3 w-8"><input type="checkbox" id="checkPage" class="w-4 h-4 accent-blue-600 cursor-pointer" aria-label="Select all on this page"></th><?php endif; ?>
                        <th class="px-4 py-3">Student</th>
                        <th class="px-4 py-3">What changed</th>
                        <th class="px-4 py-3">Requested</th>
                        <?php if (!$is_pending): ?><th class="px-4 py-3"><?= $tabs[$tab] ?></th><?php endif; ?>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody id="reqBody">
                    <?php foreach ($requests as $r):
                        $name = $r['FirstName'] . ' ' . $r['LastName'];
                        $search = strtolower($name . ' ' . $r['StudentID_Num']);
                        $id = (int) $r['RequestID'];
                    ?>
                        <tr class="req-row border-t border-slate-100 hover:bg-slate-50/70" data-id="<?= $id ?>" data-search="<?= htmlspecialchars($search) ?>" data-sections="<?= htmlspecialchars(implode('|', array_keys($r['sections']))) ?>">
                            <?php if ($is_pending): ?><td class="pl-4 py-3"><input type="checkbox" class="req-check w-4 h-4 accent-blue-600 cursor-pointer" value="<?= $id ?>" aria-label="Select <?= htmlspecialchars($name) ?>"></td><?php endif; ?>
                            <td class="<?= $cell ?>">
                                <p class="font-black text-slate-900"><?= htmlspecialchars($name) ?></p>
                                <p class="text-[11px] font-bold text-slate-400"><?= htmlspecialchars(implode(' · ', array_filter([$r['StudentID_Num'], $r['ProgramName'] ?? 'Program not set', $r['YearLevel']]))) ?></p>
                            </td>
                            <td class="<?= $cell ?>">
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach (array_keys($r['sections']) as $s): ?>
                                        <span class="px-2 py-0.5 rounded-md bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black"><?= htmlspecialchars($s) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <p class="text-[11px] font-bold text-slate-400 mt-1"><?= $r['field_count'] ?> field<?= $r['field_count'] === 1 ? '' : 's' ?></p>
                            </td>
                            <td class="<?= $cell ?> whitespace-nowrap">
                                <p class="font-bold text-slate-700"><?= date('M j, Y', strtotime($r['CreatedAt'])) ?></p>
                                <p class="text-[11px] font-bold <?= $is_pending && time() - strtotime($r['CreatedAt']) > 3 * 86400 ? 'text-amber-600' : 'text-slate-400' ?>"><?= timeAgo($r['CreatedAt']) ?></p>
                            </td>
                            <?php if (!$is_pending): ?>
                                <td class="<?= $cell ?> whitespace-nowrap">
                                    <p class="font-bold text-slate-700"><?= $r['ReviewedAt'] ? date('M j, Y', strtotime($r['ReviewedAt'])) : '' ?></p>
                                    <p class="text-[11px] font-bold text-slate-400"><?= $r['ReviewerName'] ? 'by ' . htmlspecialchars($r['ReviewerName']) : '' ?></p>
                                </td>
                            <?php endif; ?>
                            <td class="<?= $cell ?> text-right">
                                <button type="button" onclick="toggleDetail(<?= $id ?>)" aria-expanded="false" id="btn-<?= $id ?>"
                                        class="px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-widest border border-slate-200 bg-white hover:bg-slate-900 hover:text-white transition-colors whitespace-nowrap">
                                    <?= $is_pending ? 'Review' : 'View' ?> <span class="inline-block transition-transform" id="chev-<?= $id ?>">▾</span>
                                </button>
                            </td>
                        </tr>
                        <tr class="req-detail hidden" id="detail-<?= $id ?>">
                            <td colspan="<?= $is_pending ? 5 : 5 ?>" class="px-4 pb-5 pt-1 bg-slate-50/70 border-t border-slate-100">
                                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                                    <table class="no-datatable w-full text-sm">
                                        <thead>
                                            <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest bg-slate-50 text-left">
                                                <th class="px-5 py-2">Field</th><th class="px-5 py-2">On record now</th><th class="px-5 py-2">Requested</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php foreach ($r['sections'] as $section => $rows): ?>
                                                <tr><td colspan="3" class="px-5 pt-3 pb-1 text-[10px] font-black text-blue-600 uppercase tracking-widest"><?= htmlspecialchars($section) ?></td></tr>
                                                <?php foreach ($rows as [$label, $from, $to]): ?>
                                                    <tr>
                                                        <td class="px-5 py-2 font-bold text-slate-700"><?= htmlspecialchars($label) ?></td>
                                                        <td class="px-5 py-2 text-slate-500 line-through decoration-slate-300"><?= htmlspecialchars(profileValueText($from)) ?></td>
                                                        <td class="px-5 py-2 font-black text-slate-900"><span class="bg-amber-50 border border-amber-200 rounded-md px-2 py-0.5"><?= htmlspecialchars(profileValueText($to)) ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <?php if ($is_pending): ?>
                                        <form action="../actions/process_profile_request.php" method="POST" class="p-4 border-t border-slate-100 bg-slate-50/50 flex flex-col lg:flex-row gap-3 lg:items-center">
                                            <input type="hidden" name="request_id" value="<?= $id ?>">
                                            <input type="hidden" name="return" value="status=Pending">
                                            <input type="text" name="note" maxlength="500" placeholder="Note to the student (required when rejecting)"
                                                   class="flex-1 bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium outline-none focus:border-blue-500">
                                            <div class="flex gap-2 shrink-0">
                                                <button type="submit" name="decision" value="reject"
                                                        onclick="const n = this.form.note; if (!n.value.trim()) { n.focus(); n.placeholder = 'Please write why it is rejected'; n.classList.add('border-red-400'); return false; } return confirm('Reject this request? The student will see your reason.');"
                                                        class="bg-white border border-red-200 text-red-600 px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-red-50">Reject</button>
                                                <button type="submit" name="decision" value="approve" onclick="return confirm('Approve and apply these changes to <?= htmlspecialchars(addslashes($name), ENT_QUOTES) ?>\'s profile?');"
                                                        class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-green-600">Approve</button>
                                            </div>
                                        </form>
                                    <?php elseif ($r['ReviewNote']): ?>
                                        <p class="px-5 py-3 border-t border-slate-100 text-sm text-slate-600"><b>Note to the student:</b> <?= htmlspecialchars($r['ReviewNote']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="reqEmpty" class="<?= $requests ? 'hidden' : '' ?> p-14 text-center">
            <div class="text-4xl mb-3 opacity-30">📭</div>
            <p class="text-slate-500 font-bold text-sm" id="reqEmptyText"><?= $is_pending ? 'No requests are waiting. You are all caught up.' : 'Nothing ' . strtolower($tabs[$tab]) . ' yet.' ?></p>
        </div>

        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-100">
            <p id="reqInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest"></p>
            <div id="reqPager" class="flex flex-wrap gap-1.5"></div>
        </div>
    </div>
</main>

<script>
    const rows = Array.from(document.querySelectorAll('.req-row'));
    let page = 1, matches = rows;
    const $ = id => document.getElementById(id);

    function detailOf(row) { return $('detail-' + row.dataset.id); }

    function applyFilters() {
        const q = $('reqSearch').value.trim().toLowerCase();
        const section = $('reqSection').value;
        matches = rows.filter(r => (!q || r.dataset.search.includes(q)) && (!section || r.dataset.sections.split('|').includes(section)));
        page = 1;
        render();
    }

    function render() {
        const per = parseInt($('reqPerPage').value, 10);
        const pages = Math.max(1, Math.ceil(matches.length / per));
        page = Math.min(page, pages);
        const start = (page - 1) * per, shown = matches.slice(start, start + per);
        rows.forEach(r => { r.classList.add('hidden'); detailOf(r).classList.add('hidden'); setChevron(r.dataset.id, false); });
        shown.forEach(r => r.classList.remove('hidden'));

        const total = rows.length;
        $('reqEmpty').classList.toggle('hidden', matches.length > 0);
        if (total && !matches.length) $('reqEmptyText').textContent = 'No requests match your search.';
        $('reqInfo').textContent = matches.length ? `Showing ${start + 1}–${start + shown.length} of ${matches.length}` + (matches.length < total ? ` (filtered from ${total})` : '') : '';

        const pager = $('reqPager');
        pager.innerHTML = '';
        if (pages > 1) {
            const btn = (label, target, active = false, disabled = false) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = label;
                b.disabled = disabled;
                b.className = 'min-w-9 h-9 px-3 rounded-lg text-xs font-black ' + (active ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 disabled:opacity-40');
                b.onclick = () => { page = target; render(); window.scrollTo({ top: 0, behavior: 'smooth' }); };
                pager.appendChild(b);
            };
            btn('←', page - 1, false, page === 1);
            for (let i = 1; i <= pages; i++) {
                if (i === 1 || i === pages || Math.abs(i - page) <= 1) btn(String(i), i, i === page);
                else if (Math.abs(i - page) === 2) { const s = document.createElement('span'); s.textContent = '…'; s.className = 'px-1 text-slate-400 self-center'; pager.appendChild(s); }
            }
            btn('→', page + 1, false, page === pages);
        }
        syncBulk();
    }

    function setChevron(id, open) {
        const c = $('chev-' + id), b = $('btn-' + id);
        if (c) c.style.transform = open ? 'rotate(180deg)' : '';
        if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function toggleDetail(id) {
        const detail = $('detail-' + id);
        const opening = detail.classList.contains('hidden');
        detail.classList.toggle('hidden', !opening);
        setChevron(id, opening);
        if (opening) { const note = detail.querySelector('input[name="note"]'); if (note) note.focus({ preventScroll: true }); }
    }

    // ---- approve several ----
    const bulk = $('bulkForm');
    function visibleChecks() { return matches.filter(r => !r.classList.contains('hidden')).map(r => r.querySelector('.req-check')).filter(Boolean); }
    function syncBulk() {
        if (!bulk) return;
        const picked = document.querySelectorAll('.req-check:checked');
        bulk.classList.toggle('hidden', picked.length === 0);
        $('bulkCount').textContent = picked.length + ' selected';
        bulk.querySelectorAll('input[name="request_ids[]"]').forEach(i => i.remove());
        picked.forEach(c => bulk.insertAdjacentHTML('beforeend', `<input type="hidden" name="request_ids[]" value="${c.value}">`));
        const vis = visibleChecks();
        $('checkPage').checked = vis.length > 0 && vis.every(c => c.checked);
    }
    function setChecks(on) { document.querySelectorAll('.req-check').forEach(c => c.checked = on); syncBulk(); }
    if (bulk) {
        $('checkPage').addEventListener('change', e => { visibleChecks().forEach(c => c.checked = e.target.checked); syncBulk(); });
        document.querySelectorAll('.req-check').forEach(c => c.addEventListener('change', syncBulk));
    }

    $('reqSearch').addEventListener('input', applyFilters);
    $('reqSection').addEventListener('input', applyFilters);
    $('reqPerPage').addEventListener('input', () => { page = 1; render(); });
    applyFilters();
</script>

<?php include '../includes/footer.php'; ?>
