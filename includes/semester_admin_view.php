<?php
// ✨ SEMESTER PAGE (internal_admin/semester.php, super_admin/semester.php; providers get only the scholarship
// requirements part, external_admin/renewal_requirements.php). Actions: actions/process_semester.php
require_once __DIR__ . '/semester.php';
require_once __DIR__ . '/application_authority.php';
require_once __DIR__ . '/approval_workflow.php';
require_once __DIR__ . '/benefit_releases.php';   // benefitReceiptUrl()

$role = $_SESSION['role'];
$uid = (int) $_SESSION['user_id'];
$school_wide = in_array($role, ['Internal_Admin', 'Super_Admin'], true);
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$term = activeTerm($pdo);
$tid = $term ? (int) $term['TermID'] : 0;

if ($school_wide && $term) {
    $students = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE Role = 'Student' AND AccountStatus = 'Active'")->fetchColumn();
    $cor_counts = $pdo->prepare('SELECT AIStatus, COUNT(*) FROM student_term_cor WHERE TermID = ? GROUP BY AIStatus');
    $cor_counts->execute([$tid]);
    $cor_counts = $cor_counts->fetchAll(PDO::FETCH_KEY_PAIR);
    $renew_counts = $pdo->prepare('SELECT Status, COUNT(*) FROM scholar_renewals WHERE TermID = ? GROUP BY Status');
    $renew_counts->execute([$tid]);
    $renew_counts = $renew_counts->fetchAll(PDO::FETCH_KEY_PAIR);
    $mail_counts = $pdo->prepare('SELECT Status, COUNT(*) FROM semester_emails WHERE TermID = ? GROUP BY Status');
    $mail_counts->execute([$tid]);
    $mail_counts = $mail_counts->fetchAll(PDO::FETCH_KEY_PAIR);
    $review = $pdo->prepare("SELECT c.*, u.FirstName, u.LastName, u.StudentID_Num FROM student_term_cor c JOIN users u ON u.UserID = c.UserID
        WHERE c.TermID = ? AND c.AIStatus = 'Needs Review' ORDER BY c.UploadedAt");
    $review->execute([$tid]);
    $review = $review->fetchAll(PDO::FETCH_ASSOC);
    $term_reqs = $pdo->prepare('SELECT * FROM renewal_requirements WHERE TermID = ? AND ScholarshipID IS NULL AND IsActive = 1 ORDER BY RequirementID');
    $term_reqs->execute([$tid]);
    $term_reqs = $term_reqs->fetchAll(PDO::FETCH_ASSOC);
}
// the scholarships this admin manages, each with its own renewal requirements
$my_sch = array_values(array_filter($pdo->query("SELECT ScholarshipID, Name FROM scholarship ORDER BY Name")->fetchAll(PDO::FETCH_ASSOC),
    fn($s) => canSetRenewalRequirements($pdo, (int) $s['ScholarshipID'], $role, $uid)));
$sch_reqs = [];
if ($my_sch) {
    $ids = implode(',', array_map('intval', array_column($my_sch, 'ScholarshipID')));
    foreach ($pdo->query("SELECT * FROM renewal_requirements WHERE ScholarshipID IN ($ids) AND TermID IS NULL AND IsActive = 1 ORDER BY RequirementID")->fetchAll(PDO::FETCH_ASSOC) as $r) $sch_reqs[(int) $r['ScholarshipID']][] = $r;
}
// the scholarship shown: the one asked for, if this admin manages it, else the first
$pick = (int) ($_GET['scholarship_id'] ?? 0);
if (!in_array($pick, array_map('intval', array_column($my_sch, 'ScholarshipID')), true)) $pick = (int) ($my_sch[0]['ScholarshipID'] ?? 0);
$send_now = $school_wide && $term && (!empty($_SESSION['semester_send']) || ($mail_counts['Queued'] ?? 0) > 0);
unset($_SESSION['semester_send']);
$y = (int) date('Y');
$next_year = (int) date('n') >= 6 ? "$y-" . ($y + 1) : ($y - 1) . "-$y";
$input = 'w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500';
$label = 'block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5';
?>
<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen">
    <header class="mb-8">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900"><?= $school_wide ? 'Semester' : 'Renewal Requirements' ?></h2>
        <p class="text-slate-500 mt-2"><?= $school_wide ? 'Start a new semester: every student uploads their COR (checked by AI), scholars renew, and approved renewals add the semester\'s benefit.' : 'What your scholars must submit each semester to keep your scholarship.' ?></p>
    </header>
    <?php if (!empty($_SESSION['success'])): ?><div class="mb-6 p-4 rounded-2xl border bg-green-50 border-green-200 text-green-800 text-sm font-bold">✅ <?= flashHtml($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?><div class="mb-6 p-4 rounded-2xl border bg-red-50 border-red-200 text-red-700 text-sm font-bold">⚠️ <?= flashHtml($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

<?php if ($school_wide): ?>
    <!-- the current semester -->
    <section class="bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-8 mb-6">
        <?php if ($term): ?>
            <p class="text-xs font-black text-green-600 uppercase tracking-widest">Current semester</p>
            <h3 class="text-2xl font-black text-slate-900 mt-1"><?= $e(termDisplayName($term)) ?></h3>
            <p class="text-sm font-semibold text-slate-500 mt-1">Started <?= $e(date('F j, Y', strtotime($term['StartDate']))) ?><?= $term['RenewalDeadline'] ? ' · renewals due ' . $e(date('F j, Y', strtotime($term['RenewalDeadline']))) : '' ?></p>
            <?php if (empty($term['SchoolYear'])): ?>
                <p class="mt-3 text-sm font-bold text-amber-700">This semester was started before the COR wall existed, so students are not asked for a COR. Start the next semester here to use it.</p>
            <?php else: ?>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
                <?php $uploaded = array_sum($cor_counts) - ($cor_counts['Rejected'] ?? 0); ?>
                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200"><p class="text-[10px] font-black text-slate-400 uppercase">CORs uploaded</p><p class="text-2xl font-black"><?= $uploaded ?> <span class="text-sm text-slate-400">/ <?= $students ?></span></p></div>
                <div class="p-4 rounded-2xl bg-green-50 border border-green-200"><p class="text-[10px] font-black text-green-700 uppercase">Verified by AI</p><p class="text-2xl font-black text-green-700"><?= (int) ($cor_counts['Verified'] ?? 0) ?></p></div>
                <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200"><p class="text-[10px] font-black text-amber-700 uppercase">To check</p><p class="text-2xl font-black text-amber-700"><?= count($review) ?></p></div>
                <div class="p-4 rounded-2xl bg-blue-50 border border-blue-200"><p class="text-[10px] font-black text-blue-700 uppercase">Renewals</p><p class="text-sm font-black text-blue-900 mt-1"><?= (int) ($renew_counts['Pending_Submission'] ?? 0) ?> to submit · <?= (int) ($renew_counts['Under_Review'] ?? 0) ?> to review · <?= (int) ($renew_counts['Renewed'] ?? 0) ?> renewed</p>
                    <a href="manage_renewals.php" class="text-xs font-black text-blue-700 underline">Review renewals →</a></div>
            </div>
            <p class="mt-4 text-sm font-semibold text-slate-600" id="mailStatus">Renewal emails: <?= (int) ($mail_counts['Sent'] ?? 0) ?> sent<?= ($mail_counts['Queued'] ?? 0) ? ', ' . (int) $mail_counts['Queued'] . ' waiting' : '' ?><?= ($mail_counts['Failed'] ?? 0) ? ', ' . (int) $mail_counts['Failed'] . ' could not be sent (check the email settings)' : '' ?><?= ($mail_counts['No Email'] ?? 0) ? ', ' . (int) $mail_counts['No Email'] . ' scholar(s) have no email address' : '' ?>.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-sm font-bold text-slate-500">No semester is open. Start one below.</p>
        <?php endif; ?>
    </section>

    <!-- start the next semester -->
    <section class="bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-8 mb-6">
        <h3 class="text-xl font-black text-slate-900">Start a new semester</h3>
        <p class="text-sm text-slate-500 mt-1 mb-5">The current semester ends. Students are blocked until they upload their COR for the new one; the AI checks it is for exactly this semester. Every current scholar is asked to renew and is emailed their requirements.</p>
        <form action="../actions/process_semester.php" method="POST" class="space-y-5" onsubmit="return confirm('Start ' + this.semester.value + ' AY ' + this.school_year.value + '?\n\nEvery student will have to upload their COR before using ScholarLink, and every scholar will be emailed to renew.');">
            <input type="hidden" name="action" value="start_term">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label><span class="<?= $label ?>">Semester</span>
                    <select name="semester" required class="<?= $input ?>"><?php foreach (SEMESTER_NAMES as $n): ?><option><?= $e($n) ?></option><?php endforeach; ?></select></label>
                <label><span class="<?= $label ?>">School year</span>
                    <input name="school_year" required pattern="\d{4}-\d{4}" placeholder="2026-2027" value="<?= $e($next_year) ?>" class="<?= $input ?>"></label>
                <label><span class="<?= $label ?>">Renewal deadline (optional)</span>
                    <input type="date" name="deadline" min="<?= date('Y-m-d') ?>" class="<?= $input ?>"></label>
            </div>
            <div>
                <span class="<?= $label ?>">Renewal requirements for every scholar (the Report of Grades is always asked)</span>
                <div id="reqRows" class="space-y-2"></div>
                <button type="button" onclick="addReqRow()" class="mt-2 text-xs font-black text-blue-700 uppercase tracking-widest">+ Add a requirement</button>
                <p class="text-xs text-slate-400 mt-1">Each scholarship can also have its own list (below). Both are asked.</p>
            </div>
            <button type="submit" class="bg-slate-900 hover:bg-green-700 text-white px-8 py-4 rounded-xl font-black text-sm uppercase tracking-widest">Start semester</button>
        </form>
    </section>

    <?php if ($term && !empty($term['SchoolYear'])): ?>
    <!-- this semester's requirements -->
    <section class="bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-8 mb-6">
        <h3 class="text-xl font-black text-slate-900">This semester's renewal requirements</h3>
        <ul class="mt-4 space-y-2">
            <li class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-sm font-bold"><?= $e(RENEWAL_ROG['Name']) ?> <span class="text-xs text-slate-400">(always)</span></li>
            <?php foreach ($term_reqs as $q): ?>
                <li class="p-3 rounded-xl border border-slate-200 text-sm flex justify-between items-center gap-3"><span><b><?= $e($q['Name']) ?></b><?= $q['Description'] ? '<br><span class="text-xs text-slate-500">' . $e($q['Description']) . '</span>' : '' ?></span>
                    <form action="../actions/process_semester.php" method="POST" onsubmit="return confirm('Stop asking for this?')"><input type="hidden" name="action" value="remove_requirement"><input type="hidden" name="requirement_id" value="<?= (int) $q['RequirementID'] ?>"><button class="text-xs font-black text-red-600">Remove</button></form></li>
            <?php endforeach; ?>
        </ul>
        <form action="../actions/process_semester.php" method="POST" class="mt-4 flex flex-col md:flex-row gap-2">
            <input type="hidden" name="action" value="add_requirement">
            <input name="name" required maxlength="150" placeholder="e.g. Certificate of Good Moral" class="<?= $input ?>">
            <input name="description" maxlength="500" placeholder="Note for scholars (optional)" class="<?= $input ?>">
            <button class="shrink-0 bg-blue-700 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-widest">Add</button>
        </form>
    </section>

    <!-- CORs the AI could not check -->
    <section class="bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-8 mb-6">
        <h3 class="text-xl font-black text-slate-900">CORs to check (<?= count($review) ?>)</h3>
        <p class="text-sm text-slate-500 mt-1">The AI could not check these (no internet or not set up). The students can already use ScholarLink; reject one and they must upload it again.</p>
        <div class="mt-4 space-y-3">
            <?php if (!$review): ?><p class="p-5 border border-dashed rounded-2xl text-center text-sm text-slate-400">Nothing to check.</p><?php endif; ?>
            <?php foreach ($review as $c): ?>
                <div class="p-4 rounded-2xl border border-slate-200 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                    <div><p class="font-black text-slate-900"><?= $e($c['LastName'] . ', ' . $c['FirstName']) ?> <span class="text-xs text-slate-400"><?= $e($c['StudentID_Num']) ?></span></p>
                        <p class="text-xs text-slate-500"><?= $e(date('M j, Y g:i A', strtotime($c['UploadedAt']))) ?> · <?= $e($c['AIRemarks']) ?></p></div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a target="_blank" href="<?= $e(benefitReceiptUrl($c['FilePath'])) ?>" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-black">View COR</a>
                        <form action="../actions/process_semester.php" method="POST"><input type="hidden" name="action" value="review_cor"><input type="hidden" name="cor_id" value="<?= (int) $c['CorID'] ?>"><input type="hidden" name="verdict" value="Verified"><button class="px-4 py-2 rounded-xl bg-green-600 text-white text-xs font-black">Accept</button></form>
                        <form action="../actions/process_semester.php" method="POST" class="flex gap-2" onsubmit="return this.note.value.trim() !== '' || (alert('Say why it is rejected.'), false)"><input type="hidden" name="action" value="review_cor"><input type="hidden" name="cor_id" value="<?= (int) $c['CorID'] ?>"><input type="hidden" name="verdict" value="Rejected">
                            <input name="note" maxlength="500" placeholder="Why (the student sees it)" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold"><button class="px-4 py-2 rounded-xl bg-red-600 text-white text-xs font-black">Reject</button></form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
<?php endif; ?>

    <!-- each scholarship's own renewal requirements -->
    <section class="bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-8">
        <h3 class="text-xl font-black text-slate-900">Requirements by scholarship</h3>
        <p class="text-sm text-slate-500 mt-1 mb-4">Asked of that scholarship's scholars every semester, together with the semester's list.</p>
        <?php if (!$my_sch): ?><p class="text-sm text-slate-400">You don't manage any scholarship.</p><?php else: ?>
        <form method="GET" class="mb-4"><select name="scholarship_id" onchange="this.form.submit()" class="<?= $input ?>"><?php foreach ($my_sch as $s): ?><option value="<?= (int) $s['ScholarshipID'] ?>" <?= (int) $s['ScholarshipID'] === $pick ? 'selected' : '' ?>><?= $e($s['Name']) ?><?= !empty($sch_reqs[(int) $s['ScholarshipID']]) ? ' (' . count($sch_reqs[(int) $s['ScholarshipID']]) . ')' : '' ?></option><?php endforeach; ?></select></form>
        <ul class="space-y-2">
            <?php foreach ($sch_reqs[$pick] ?? [] as $q): ?>
                <li class="p-3 rounded-xl border border-slate-200 text-sm flex justify-between items-center gap-3"><span><b><?= $e($q['Name']) ?></b><?= $q['Description'] ? '<br><span class="text-xs text-slate-500">' . $e($q['Description']) . '</span>' : '' ?></span>
                    <form action="../actions/process_semester.php" method="POST" onsubmit="return confirm('Stop asking for this?')"><input type="hidden" name="action" value="remove_requirement"><input type="hidden" name="scholarship_id" value="<?= $pick ?>"><input type="hidden" name="requirement_id" value="<?= (int) $q['RequirementID'] ?>"><button class="text-xs font-black text-red-600">Remove</button></form></li>
            <?php endforeach; ?>
            <?php if (empty($sch_reqs[$pick])): ?><li class="p-4 border border-dashed rounded-2xl text-center text-sm text-slate-400">No extra requirements for this scholarship.</li><?php endif; ?>
        </ul>
        <form action="../actions/process_semester.php" method="POST" class="mt-4 flex flex-col md:flex-row gap-2">
            <input type="hidden" name="action" value="add_requirement"><input type="hidden" name="scholarship_id" value="<?= $pick ?>">
            <input name="name" required maxlength="150" placeholder="e.g. Updated Barangay Certificate of Indigency" class="<?= $input ?>">
            <input name="description" maxlength="500" placeholder="Note for scholars (optional)" class="<?= $input ?>">
            <button class="shrink-0 bg-blue-700 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-widest">Add</button>
        </form>
        <?php endif; ?>
    </section>
</main>
<script>
    function addReqRow() {
        const row = document.createElement('div');
        row.className = 'flex flex-col md:flex-row gap-2';
        row.innerHTML = `<input name="req_name[]" maxlength="150" placeholder="Requirement (e.g. Certificate of Good Moral)" class="<?= $input ?>">
            <input name="req_description[]" maxlength="500" placeholder="Note for scholars (optional)" class="<?= $input ?>">
            <button type="button" onclick="this.parentElement.remove()" class="shrink-0 px-4 text-red-600 font-black">✕</button>`;
        document.getElementById('reqRows').appendChild(row);
    }
    <?php if ($send_now): ?>
    // the renewal emails go a few at a time, while this page is open
    (async function sendEmails() {
        const status = document.getElementById('mailStatus'), token = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
        let sent = 0, failed = 0;
        for (let k = 0; k < 200; k++) {
            const body = new FormData(); body.append('action', 'send_emails'); body.append('csrf_token', token);
            let r;
            try { r = await (await fetch('../actions/process_semester.php', { method: 'POST', body })).json(); } catch (err) { status.textContent = 'Renewal emails paused (connection lost): reload this page to continue.'; return; }
            if (!r.success) { status.textContent = 'Renewal emails: ' + r.error; return; }
            sent += r.sent; failed += r.failed;
            status.textContent = `Sending renewal emails… ${sent} sent${failed ? ', ' + failed + ' could not be sent' : ''}, ${r.left} left. Keep this page open.`;
            if (!r.left) { status.textContent = `Renewal emails done: ${sent} sent${failed ? ', ' + failed + ' could not be sent (check the email settings)' : ''}.`; return; }
        }
    })();
    <?php endif; ?>
</script>
