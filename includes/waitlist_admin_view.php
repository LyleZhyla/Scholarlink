<?php
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/scholarship_categories.php';

// The Waitlist tab of Manage Applications: every scholarship this admin manages, those with people waiting first.
// Search, filters and "Show 5/10/25/50" come from article_pager.php. Messages after an action appear as the footer's toast.
function waitlistPanel(PDO $pdo): void {
$role = $_SESSION['role'] ?? '';
$me = (int) ($_SESSION['user_id'] ?? 0);
$where = '1=1'; $params = [];
if ($role === 'Internal_Admin') $where = "(creator.Role IS NULL OR creator.Role IN ('Internal_Admin', 'Super_Admin'))";
elseif ($role === 'External_Admin') { $where = 's.CreatedBy = ?'; $params[] = $me; }

$stmt = $pdo->prepare("SELECT s.ScholarshipID, s.Name, s.Category FROM scholarship s LEFT JOIN users creator ON creator.UserID = s.CreatedBy WHERE $where ORDER BY s.Name");
$stmt->execute($params);
$groups = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $scholarship) $groups[(int) $scholarship['ScholarshipID']] = ['name' => $scholarship['Name'], 'category' => $scholarship['Category'] ?: DEFAULT_SCHOLARSHIP_CATEGORY, 'waitlist' => [], 'holders' => []];

$stmt = $pdo->prepare("
    SELECT w.*, a.UserID, a.TotalScore, a.GPA, a.DateSubmitted, u.StudentID_Num, u.FirstName, u.LastName
    FROM application_waitlist w
    JOIN application a ON a.ApplicationID = w.ApplicationID
    JOIN users u ON u.UserID = a.UserID
    JOIN scholarship s ON s.ScholarshipID = w.ScholarshipID
    LEFT JOIN users creator ON creator.UserID = s.CreatedBy
    WHERE $where
    ORDER BY FIELD(w.Status, 'Offered', 'Waiting', 'Promoted', 'Removed'), w.RankPosition
");
$stmt->execute($params);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $groups[(int) $row['ScholarshipID']]['waitlist'][] = $row;

if ($groups) {
    $ids = array_keys($groups);
    $holder_stmt = $pdo->query("SELECT a.ApplicationID, a.ScholarshipID, u.FirstName, u.LastName, u.StudentID_Num FROM application a JOIN users u ON u.UserID = a.UserID WHERE a.Status = 'Approved' AND a.ScholarshipID IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY u.LastName, u.FirstName");
    foreach ($holder_stmt->fetchAll(PDO::FETCH_ASSOC) as $holder) $groups[(int) $holder['ScholarshipID']]['holders'][] = $holder;
}
foreach ($groups as &$group) {
    $statuses = array_column($group['waitlist'], 'Status');
    $group['state'] = in_array('Offered', $statuses, true) ? 'Slot offered' : (in_array('Waiting', $statuses, true) ? 'People waiting' : 'Nobody waiting');
}
unset($group);
uasort($groups, fn($a, $b) => ($a['state'] === 'Nobody waiting') <=> ($b['state'] === 'Nobody waiting'));
?>
    <p class="text-sm font-medium text-slate-500 mb-6">Offer vacant slots strictly by rank, then approve the final promotion.</p>
    <?php if (!$groups): ?><div class="bg-white border border-slate-200 rounded-[2rem] p-12 text-center"><div class="text-4xl mb-4">📋</div><h3 class="text-xl font-black text-slate-900">No scholarships yet</h3><p class="text-slate-500 mt-2">Qualified applicants appear here automatically when all scholarship slots are filled.</p></div><?php endif; ?>
    <div class="space-y-6">
    <?php foreach ($groups as $scholarship_id => $group):
        $list = $group['waitlist']; $name = $group['name'];
        $capacity = scholarshipVacancies($pdo, $scholarship_id, true);
        $next_waiting = null; foreach ($list as $candidate) if ($candidate['Status'] === 'Waiting') { $next_waiting = (int) $candidate['WaitlistID']; break; }
    ?>
        <article data-filter-category="<?= htmlspecialchars($group['category']) ?>" data-filter-scholarship="<?= htmlspecialchars($name) ?>" data-filter-waitlist="<?= $group['state'] ?>" data-filter-slots="<?= $capacity['vacancies'] > 0 ? 'Has vacant slots' : 'Slots full' ?>" class="bg-white border border-slate-200 rounded-[2rem] overflow-hidden shadow-sm">
            <div class="p-6 lg:p-8 bg-slate-900 text-white flex flex-col md:flex-row justify-between gap-4"><div><p class="text-[10px] font-black text-blue-300 uppercase tracking-widest"><?= htmlspecialchars(categoryLabel($group['category'])) ?></p><h3 class="text-xl font-black mt-1"><?= htmlspecialchars($name) ?></h3></div><div class="flex gap-6"><div><p class="text-[10px] font-black text-slate-400 uppercase">Filled</p><p class="text-xl font-black"><?= $capacity['approved'] ?> / <?= $capacity['slots'] ?></p></div><div><p class="text-[10px] font-black text-slate-400 uppercase">Unreserved Vacancies</p><p class="text-xl font-black <?= $capacity['vacancies'] > 0 ? 'text-green-400' : 'text-amber-400' ?>"><?= $capacity['vacancies'] ?></p></div></div></div>
            <?php if (!$list): ?><p class="p-5 lg:p-6 text-sm font-medium text-slate-500">Nobody is on this waitlist.</p><?php endif; ?>
            <div class="divide-y divide-slate-100">
            <?php foreach ($list as $candidate): ?>
                <div class="p-5 lg:p-6 flex flex-col xl:flex-row xl:items-center gap-4">
                    <div class="w-16 h-16 rounded-2xl bg-blue-50 text-blue-700 flex items-center justify-center text-xl font-black shrink-0">#<?= (int) $candidate['RankPosition'] ?></div>
                    <div class="flex-1"><div class="flex flex-wrap items-center gap-2"><h4 class="font-black text-slate-900"><?= htmlspecialchars($candidate['FirstName'] . ' ' . $candidate['LastName']) ?></h4><span class="px-2.5 py-1 rounded-full border text-[10px] font-black uppercase <?= waitlistStatusClass($candidate['Status']) ?>"><?= htmlspecialchars($candidate['Status']) ?></span></div><p class="text-xs font-bold text-slate-400 mt-1"><?= htmlspecialchars($candidate['StudentID_Num'] ?? '') ?> · Score <?= number_format((float) $candidate['TotalScore'], 2) ?> · GWA <?= $candidate['GPA'] !== null ? number_format((float) $candidate['GPA'], 2) : 'N/A' ?></p></div>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($candidate['Status'] === 'Waiting' && (int) $candidate['WaitlistID'] === $next_waiting && $capacity['vacancies'] > 0): ?><form action="../actions/process_waitlist.php" method="POST" onsubmit="return confirm('Offer this vacant slot to the next ranked applicant?');"><input type="hidden" name="action" value="offer"><input type="hidden" name="waitlist_id" value="<?= (int) $candidate['WaitlistID'] ?>"><button class="bg-amber-500 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-amber-600">Offer Vacant Slot</button></form><?php endif; ?>
                        <?php if ($candidate['Status'] === 'Offered'): ?><form action="../actions/process_waitlist.php" method="POST" onsubmit="return confirm('Approve this waitlist promotion and make the applicant an official scholar?');"><input type="hidden" name="action" value="promote"><input type="hidden" name="waitlist_id" value="<?= (int) $candidate['WaitlistID'] ?>"><button class="bg-green-600 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-green-700">Approve Promotion</button></form><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php if (!empty($group['holders'])): ?><details class="border-t border-slate-200"><summary class="p-5 font-black text-sm text-slate-700 cursor-pointer">Current Slot Holders (<?= count($group['holders']) ?>) · Manage Vacancies</summary><div class="p-5 pt-0 space-y-3"><?php foreach ($group['holders'] as $holder): ?><form action="../actions/process_waitlist.php" method="POST" class="flex flex-col md:flex-row md:items-center gap-3 p-4 bg-slate-50 rounded-xl" onsubmit="return confirm('Vacate this scholar’s slot? This action is recorded and the student will be notified.');"><input type="hidden" name="action" value="vacate"><input type="hidden" name="application_id" value="<?= (int) $holder['ApplicationID'] ?>"><div class="flex-1"><p class="font-black text-sm text-slate-800"><?= htmlspecialchars($holder['FirstName'] . ' ' . $holder['LastName']) ?></p><p class="text-xs text-slate-400"><?= htmlspecialchars($holder['StudentID_Num'] ?? '') ?></p></div><input name="reason" minlength="10" required placeholder="Required vacancy reason" class="flex-1 p-3 border border-slate-200 rounded-xl text-sm"><button class="bg-white text-red-600 border border-red-200 px-4 py-3 rounded-xl font-black text-xs uppercase">Vacate Slot</button></form><?php endforeach; ?></div></details><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
    <?php include __DIR__ . '/article_pager.php'; ?>
<?php }
