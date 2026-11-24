<?php
// ✨ SEMESTER RENEWAL (scholars): what to submit to keep the scholarship this semester (the semester's list and the
// scholarship's own, includes/semester.php), where it stands, and the semester's benefit once approved.
// Sent by actions/process_renewal.php; reviewed by the OSSD in Renewals.
session_start();
include '../includes/session_manager.php';
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'Student') { header('Location: ../student_login.php'); exit(); }
include '../includes/db_connect.php';
require_once '../includes/semester.php';
require_once '../includes/benefit_releases.php';   // benefitReceiptUrl(): a stored file as a link

$user_id = (int) $_SESSION['user_id'];
$term = activeTerm($pdo);
$renewals = [];
if ($term) {
    $st = $pdo->prepare("SELECT r.*, a.ScholarshipID, s.Name AS ScholarshipName, s.AwardAmount, tb.Amount AS BenefitAdded
        FROM scholar_renewals r JOIN application a ON a.ApplicationID = r.ApplicationID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
        LEFT JOIN scholar_term_benefits tb ON tb.ApplicationID = r.ApplicationID AND tb.TermID = r.TermID
        WHERE r.UserID = ? AND r.TermID = ? ORDER BY r.RenewalID");
    $st->execute([$user_id, $term['TermID']]);
    $renewals = $st->fetchAll(PDO::FETCH_ASSOC);
}
$cor = $term ? studentTermCor($pdo, $user_id, (int) $term['TermID']) : null;
$submitted = [];
if ($renewals) {
    $ids = implode(',', array_map('intval', array_column($renewals, 'RenewalID')));
    foreach ($pdo->query("SELECT * FROM renewal_submissions WHERE RenewalID IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) as $s) $submitted[(int) $s['RenewalID']][(int) $s['RequirementID']] = $s;
}
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$badges = [
    'Pending_Submission' => ['To submit', 'bg-amber-100 text-amber-800'], 'Under_Review' => ['Under review', 'bg-blue-100 text-blue-800'],
    'Appeal_Requested' => ['Appeal requested', 'bg-orange-100 text-orange-800'], 'Failed_GWA' => ['GWA below requirement', 'bg-red-100 text-red-700'],
    'Renewed' => ['Renewed', 'bg-green-100 text-green-800'], 'Dropped' => ['Dropped', 'bg-red-100 text-red-700'],
];
include '../includes/header.php';
include '../includes/student_sidebar.php';
?>
<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen">
    <header class="mb-8">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900">Semester Renewal</h2>
        <p class="text-slate-500 mt-2"><?= $term ? 'Keep your scholarship for <b>' . $e(termDisplayName($term)) . '</b>.' : 'No semester is open right now.' ?></p>
    </header>
    <?php if (!empty($_SESSION['success'])): ?><div class="mb-6 p-4 rounded-2xl border bg-green-50 border-green-200 text-green-800 text-sm font-bold">✅ <?= flashHtml($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?><div class="mb-6 p-4 rounded-2xl border bg-red-50 border-red-200 text-red-700 text-sm font-bold">⚠️ <?= flashHtml($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <?php if (!$renewals): ?>
        <div class="bg-white border border-slate-200 rounded-[2rem] p-12 text-center"><div class="text-4xl mb-4">🎓</div>
            <h3 class="text-xl font-black">Nothing to renew this semester</h3>
            <p class="text-slate-500 mt-2">A renewal appears here when a new semester starts and you hold a scholarship.</p></div>
    <?php endif; ?>

    <div class="space-y-6">
    <?php foreach ($renewals as $r):
        $reqs = renewalRequirementsFor($pdo, (int) $r['TermID'], (int) $r['ScholarshipID']);
        [$label, $cls] = $badges[$r['Status']] ?? [$r['Status'], 'bg-slate-100 text-slate-700'];
        $mine = $submitted[(int) $r['RenewalID']] ?? []; ?>
        <section class="bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-8">
            <div class="flex flex-col md:flex-row md:items-start justify-between gap-4 mb-6">
                <div>
                    <p class="text-xs font-black text-blue-600 uppercase tracking-widest"><?= $e(termDisplayName($term)) ?></p>
                    <h3 class="text-xl font-black text-slate-900 mt-1"><?= $e($r['ScholarshipName']) ?></h3>
                    <?php if ($term['RenewalDeadline'] && $r['Status'] === 'Pending_Submission'): ?>
                        <p class="text-sm font-bold text-red-600 mt-1">Due <?= $e(date('F j, Y', strtotime($term['RenewalDeadline']))) ?></p>
                    <?php endif; ?>
                </div>
                <span class="self-start flex flex-col items-end gap-2">
                    <span class="px-4 py-1.5 rounded-full text-xs font-black uppercase tracking-widest <?= $cls ?>"><?= $e($label) ?></span>
                    <a target="_blank" href="../actions/renewal_notice_pdf.php?renewal_id=<?= (int) $r['RenewalID'] ?>" class="text-xs font-black text-blue-600 hover:underline"><i class="fas fa-file-pdf"></i> Renewal Notice</a>
                </span>
            </div>

            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 mb-5 flex flex-wrap items-center justify-between gap-3 text-sm">
                <span class="font-bold text-slate-700">🧾 Certificate of Registration
                    <span class="ml-2 text-xs font-black <?= ($cor['AIStatus'] ?? '') === 'Verified' ? 'text-green-700' : 'text-amber-700' ?>"><?= $cor ? $e($cor['AIStatus'] === 'Verified' ? 'Verified' : 'Received, being checked') : 'Missing' ?></span></span>
                <?php if ($cor): ?><a target="_blank" href="<?= $e(benefitReceiptUrl($cor['FilePath'])) ?>" class="text-xs font-black text-blue-600">View</a><?php endif; ?>
            </div>

            <?php if ($r['Status'] === 'Pending_Submission'): ?>
                <form action="../actions/process_renewal.php" method="POST" enctype="multipart/form-data" class="space-y-3" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Submitting and reading your grades…';">
                    <input type="hidden" name="renewal_id" value="<?= (int) $r['RenewalID'] ?>">
                    <?php foreach ($reqs as $q): ?>
                        <label class="block p-4 rounded-2xl border border-slate-200 hover:border-blue-300">
                            <span class="flex flex-wrap items-center gap-2 font-black text-slate-800"><?= $e($q['Name']) ?> <span class="text-red-500">*</span>
                                <span class="text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full <?= $q['Source'] === 'Scholarship' ? 'bg-purple-100 text-purple-700' : 'bg-slate-100 text-slate-500' ?>"><?= $q['Source'] === 'Scholarship' ? 'For this scholarship' : ($q['Source'] === 'Semester' ? 'This semester' : 'Always') ?></span></span>
                            <?php if (!empty($q['Description'])): ?><span class="block text-xs font-semibold text-slate-500 mt-1"><?= $e($q['Description']) ?></span><?php endif; ?>
                            <input type="file" name="req[<?= (int) $q['RequirementID'] ?>]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required class="mt-3 block w-full text-xs font-bold text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-black">
                        </label>
                    <?php endforeach; ?>
                    <p class="text-xs font-semibold text-slate-400">PDF, JPG or PNG, up to 10MB each. Your grades are read automatically to check your GWA.</p>
                    <button type="submit" class="w-full sm:w-auto bg-slate-900 hover:bg-blue-700 text-white px-8 py-4 rounded-xl font-black text-sm uppercase tracking-widest">Submit renewal</button>
                </form>
            <?php else: ?>
                <div class="space-y-2">
                    <?php if ($r['ROG_FilePath']): ?><div class="p-3 rounded-xl border border-slate-200 flex justify-between text-sm"><span class="font-bold">Report of Grades<?= $r['AI_Extracted_GWA'] !== null ? ' · GWA ' . $e($r['AI_Extracted_GWA']) : '' ?></span><a target="_blank" href="<?= $e(benefitReceiptUrl($r['ROG_FilePath'])) ?>" class="text-xs font-black text-blue-600">View</a></div><?php endif; ?>
                    <?php foreach ($reqs as $q): if (!$q['RequirementID'] || empty($mine[(int) $q['RequirementID']])) continue; ?>
                        <div class="p-3 rounded-xl border border-slate-200 flex justify-between text-sm"><span class="font-bold"><?= $e($q['Name']) ?></span><a target="_blank" href="<?= $e(benefitReceiptUrl($mine[(int) $q['RequirementID']]['FilePath'])) ?>" class="text-xs font-black text-blue-600">View</a></div>
                    <?php endforeach; ?>
                </div>
                <?php if ($r['AdminNotes']): ?><p class="mt-4 p-4 rounded-2xl bg-slate-50 border border-slate-200 text-sm text-slate-700"><b>OSSD note:</b> <?= $e($r['AdminNotes']) ?></p><?php endif; ?>
                <?php if ($r['Status'] === 'Under_Review'): ?><p class="mt-4 text-sm font-bold text-blue-700">The OSSD is reviewing your renewal. You'll be notified of the result.</p><?php endif; ?>
                <?php if ($r['Status'] === 'Renewed'): ?><p class="mt-4 text-sm font-bold text-green-700">🎉 Renewed for this semester<?= $r['BenefitAdded'] !== null ? ': ₱' . number_format((float) $r['BenefitAdded'], 2) . ' was added to your benefits.' : '.' ?> <a href="benefits.php" class="underline">See my benefits</a></p><?php endif; ?>
                <?php if ($r['Status'] === 'Appeal_Requested'): ?>
                    <form action="../actions/process_renewal.php" method="POST" enctype="multipart/form-data" class="mt-5 p-5 rounded-2xl bg-orange-50 border border-orange-200 space-y-3">
                        <input type="hidden" name="renewal_id" value="<?= (int) $r['RenewalID'] ?>"><input type="hidden" name="is_appeal" value="1">
                        <p class="font-black text-orange-900">Upload your letter of appeal</p>
                        <input type="file" name="appeal_file" required accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-xs font-bold">
                        <button type="submit" class="bg-orange-600 hover:bg-orange-700 text-white px-6 py-3 rounded-xl font-black text-xs uppercase tracking-widest">Send appeal</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
</main>
<?php include '../includes/footer.php'; ?>
