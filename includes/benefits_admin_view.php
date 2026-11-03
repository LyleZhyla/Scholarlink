<?php
// ✨ DISBURSEMENT LEDGER (internal_admin, external_admin and super_admin benefits.php)
// One table row per scholar, filtered, sorted and paged by the database, so it stays short and quick with any number of
// scholars. "Manage" opens that scholar's forms and release history under the row; only the current page loads them.
require_once __DIR__ . '/benefit_releases.php';
$role = $_SESSION['role'] ?? ''; $me = (int) ($_SESSION['user_id'] ?? 0);

// ---------- Who may see what ----------
$authority_where = "(a.Status = 'Approved' OR ba.BenefitAccountID IS NOT NULL)"; $authority_params = [];
if ($role === 'Internal_Admin') $authority_where .= " AND (creator.Role IS NULL OR creator.Role IN ('Internal_Admin','Super_Admin'))";
elseif ($role === 'External_Admin') { $authority_where .= ' AND s.CreatedBy = ?'; $authority_params[] = $me; }

$ledger_sql = "
    SELECT a.ApplicationID, a.UserID, a.Status AS ApplicationStatus, a.DecisionAt AS ApprovedAt,
           u.StudentID_Num, u.FirstName, u.LastName, u.YearLevel, u.ProgramID, p.ProgramName,
           s.ScholarshipID, s.Name AS ScholarshipName,
           ba.BenefitAccountID, COALESCE(ba.TotalEntitlement, s.AwardAmount, 0) AS TotalEntitlement,
           COALESCE(ba.ReleaseFrequency, NULLIF(s.ReleaseFrequency, ''), 'Per Semester') AS ReleaseFrequency, ba.Notes,
           COALESCE(rel.Released, 0) AS ReleasedAmount,
           GREATEST(COALESCE(ba.TotalEntitlement, s.AwardAmount, 0) - COALESCE(rel.Released, 0), 0) AS UnpaidAmount
    FROM application a
    JOIN users u ON u.UserID = a.UserID
    JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
    LEFT JOIN program p ON p.ProgramID = u.ProgramID
    LEFT JOIN users creator ON creator.UserID = s.CreatedBy
    LEFT JOIN scholar_benefit_accounts ba ON ba.ApplicationID = a.ApplicationID
    LEFT JOIN (SELECT ApplicationID, SUM(Amount) AS Released FROM benefit_releases GROUP BY ApplicationID) rel ON rel.ApplicationID = a.ApplicationID
    WHERE $authority_where";

// ---------- What the filters can offer (only what this admin may see) ----------
$options = $pdo->prepare("SELECT ScholarshipID, ScholarshipName, ProgramID, ProgramName FROM ($ledger_sql) l");
$options->execute($authority_params);
$scholarship_options = []; $program_options = [];
foreach ($options->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $scholarship_options[(int) $o['ScholarshipID']] = $o['ScholarshipName'];
    if ($o['ProgramID']) $program_options[(int) $o['ProgramID']] = $o['ProgramName'];
}
asort($scholarship_options, SORT_NATURAL | SORT_FLAG_CASE);
asort($program_options, SORT_NATURAL | SORT_FLAG_CASE);

// ---------- Filters and sorting from the address bar ----------
$sort_columns = [
    'name'        => ['LastName %s, FirstName %s', 'Name'],
    'date'        => ['ApprovedAt IS NULL, ApprovedAt %s', 'Date approved'],
    'entitlement' => ['TotalEntitlement %s', 'Entitlement'],
    'released'    => ['ReleasedAmount %s', 'Released'],
    'unpaid'      => ['UnpaidAmount %s', 'Unpaid'],
];
$sort_choices = [
    'name-asc'         => 'Name: A to Z',
    'name-desc'        => 'Name: Z to A',
    'date-desc'        => 'Date approved: newest first',
    'date-asc'         => 'Date approved: oldest first',
    'entitlement-desc' => 'Entitlement: largest to lowest',
    'entitlement-asc'  => 'Entitlement: lowest to largest',
    'released-desc'    => 'Released: largest to lowest',
    'released-asc'     => 'Released: lowest to largest',
    'unpaid-desc'      => 'Unpaid: largest to lowest',
    'unpaid-asc'       => 'Unpaid: lowest to largest',
];
$payment_choices = ['' => 'Any payment status', 'none' => 'Nothing released yet', 'partial' => 'Partly released', 'paid' => 'Fully released'];
$per_choices = [10, 25, 50, 100];

foreach (['q', 'scholarship', 'program', 'payment', 'sort', 'per', 'page', 'open'] as $param) {
    if (isset($_GET[$param]) && !is_string($_GET[$param])) unset($_GET[$param]);   // e.g. a hand-edited ?sort[]=x
}
$q = trim((string) ($_GET['q'] ?? ''));
$f_scholarship = isset($scholarship_options[(int) ($_GET['scholarship'] ?? 0)]) ? (int) $_GET['scholarship'] : 0;
$f_program = isset($program_options[(int) ($_GET['program'] ?? 0)]) ? (int) $_GET['program'] : 0;
$f_payment = isset($payment_choices[$_GET['payment'] ?? '']) ? (string) ($_GET['payment'] ?? '') : '';
$sort = isset($sort_choices[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'name-asc';
$per = in_array((int) ($_GET['per'] ?? 0), $per_choices, true) ? (int) $_GET['per'] : 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
[$sort_key, $sort_dir] = explode('-', $sort);

$filter_sql = ['1 = 1']; $filter_params = [];
if ($q !== '') {
    $filter_sql[] = "(CONCAT(FirstName, ' ', LastName) LIKE ? OR CONCAT(LastName, ', ', FirstName) LIKE ? OR StudentID_Num LIKE ?)";
    array_push($filter_params, "%$q%", "%$q%", "%$q%");
}
if ($f_scholarship) { $filter_sql[] = 'ScholarshipID = ?'; $filter_params[] = $f_scholarship; }
if ($f_program) { $filter_sql[] = 'ProgramID = ?'; $filter_params[] = $f_program; }
if ($f_payment === 'none') $filter_sql[] = 'ReleasedAmount = 0';
elseif ($f_payment === 'partial') $filter_sql[] = 'ReleasedAmount > 0 AND UnpaidAmount > 0';
elseif ($f_payment === 'paid') $filter_sql[] = 'ReleasedAmount > 0 AND UnpaidAmount = 0';
$filtered_sql = "FROM ($ledger_sql) l WHERE " . implode(' AND ', $filter_sql);
$all_params = array_merge($authority_params, $filter_params);

// ---------- Totals for everything that matches, then just this page ----------
$totals = $pdo->prepare("SELECT COUNT(*) AS Scholars, COALESCE(SUM(TotalEntitlement), 0) AS Entitlement, COALESCE(SUM(ReleasedAmount), 0) AS Released, COALESCE(SUM(UnpaidAmount), 0) AS Unpaid $filtered_sql");
$totals->execute($all_params);
$totals = $totals->fetch(PDO::FETCH_ASSOC);
$match_count = (int) $totals['Scholars'];
$pages = max(1, (int) ceil($match_count / $per));
$page = min($page, $pages);
$offset = ($page - 1) * $per;

$direction = $sort_dir === 'desc' ? 'DESC' : 'ASC';
$order_by = str_replace('%s', $direction, $sort_columns[$sort_key][0]);
$stmt = $pdo->prepare("SELECT * $filtered_sql ORDER BY $order_by, LastName ASC, FirstName ASC, ApplicationID ASC LIMIT $per OFFSET $offset");
$stmt->execute($all_params);
$scholars = $stmt->fetchAll(PDO::FETCH_ASSOC);

$releases = [];
if ($scholars) {
    $ids = array_map('intval', array_column($scholars, 'ApplicationID'));
    $rs = $pdo->query("SELECT * FROM benefit_releases WHERE ApplicationID IN (" . implode(',', $ids) . ") ORDER BY PaymentDate DESC, BenefitReleaseID DESC");
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $release) $releases[(int) $release['ApplicationID']][] = $release;
}
$today = (new DateTime('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

// Links and forms keep the current filters
$current = array_filter(['q' => $q, 'scholarship' => $f_scholarship ?: '', 'program' => $f_program ?: '', 'payment' => $f_payment, 'sort' => $sort, 'per' => $per, 'page' => $page], fn($v) => $v !== '');
$ledger_url = fn(array $changes) => '?' . http_build_query(array_merge($current, $changes));
$return_query = http_build_query($current);
$open_id = (int) ($_GET['open'] ?? 0);
$has_filters = $q !== '' || $f_scholarship || $f_program || $f_payment !== '';

// A column heading sorts by that column; clicking it again reverses the order
$sort_heading = function (string $key, string $first_dir, string $class = '') use ($sort_columns, $sort_key, $sort_dir, $ledger_url) {
    $active = $key === $sort_key;
    $next = $active ? ($sort_dir === 'asc' ? 'desc' : 'asc') : $first_dir;
    $arrow = $active ? ($sort_dir === 'asc' ? '↑' : '↓') : '';
    echo '<th class="px-4 py-3 ' . $class . '" aria-sort="' . ($active ? ($sort_dir === 'asc' ? 'ascending' : 'descending') : 'none') . '">'
       . '<a href="' . htmlspecialchars($ledger_url(['sort' => "$key-$next", 'page' => 1])) . '" class="hover:text-slate-900 ' . ($active ? 'text-slate-900' : '') . '">'
       . htmlspecialchars($sort_columns[$key][1]) . ' <span class="text-blue-600">' . $arrow . '</span></a></th>';
};
$field = 'bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 outline-none focus:border-blue-500 min-w-0 w-full';
?>
<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen">
    <header class="mb-6"><p class="text-xs font-black text-green-600 uppercase tracking-[0.25em]">Disbursement Ledger</p><h2 class="text-3xl lg:text-4xl font-black text-slate-900 mt-2">Scholarship Benefits</h2><p class="text-slate-500 font-medium mt-2">Record actual releases and monitor each scholar’s unpaid balance.</p></header>
    <?php if(isset($_SESSION['success'])):?><div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-2xl mb-6 font-bold"><?=htmlspecialchars($_SESSION['success']);unset($_SESSION['success']);?></div><?php endif;?>
    <?php if(isset($_SESSION['error'])):?><div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-6 font-bold"><?=htmlspecialchars($_SESSION['error']);unset($_SESSION['error']);?></div><?php endif;?>

    <!-- Totals for what is currently filtered -->
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 mb-5">
        <?php foreach ([['Scholars', number_format($match_count), 'text-slate-900'], ['Total entitlement', '₱' . number_format($totals['Entitlement'], 2), 'text-slate-900'], ['Released', '₱' . number_format($totals['Released'], 2), 'text-green-600'], ['Unpaid', '₱' . number_format($totals['Unpaid'], 2), 'text-amber-600']] as [$label, $value, $tone]): ?>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm min-w-0">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?= $label ?></p>
                <p class="text-lg lg:text-xl font-black <?= $tone ?> truncate" title="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($value) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-white border border-slate-200 rounded-[1.75rem] shadow-sm overflow-hidden">
        <!-- Filters -->
        <form method="GET" id="ledgerFilters" class="p-4 border-b border-slate-100 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            <input type="search" name="q" id="ledgerSearch" value="<?= htmlspecialchars($q) ?>" placeholder="Search name or student no..." aria-label="Search scholars" class="<?= $field ?> font-medium">
            <select name="scholarship" aria-label="Scholarship" class="<?= $field ?> cursor-pointer">
                <option value="">All scholarships</option>
                <?php foreach ($scholarship_options as $id => $name): ?><option value="<?= $id ?>" <?= $id === $f_scholarship ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
            </select>
            <select name="program" aria-label="Course" class="<?= $field ?> cursor-pointer">
                <option value="">All courses</option>
                <?php foreach ($program_options as $id => $name): ?><option value="<?= $id ?>" <?= $id === $f_program ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
            </select>
            <select name="payment" aria-label="Payment status" class="<?= $field ?> cursor-pointer">
                <?php foreach ($payment_choices as $value => $label): ?><option value="<?= $value ?>" <?= $value === $f_payment ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
            <select name="sort" aria-label="Sort by" class="<?= $field ?> cursor-pointer">
                <?php foreach ($sort_choices as $value => $label): ?><option value="<?= $value ?>" <?= $value === $sort ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
            <div class="flex gap-2 min-w-0">
                <select name="per" aria-label="Rows per page" class="<?= $field ?> cursor-pointer">
                    <?php foreach ($per_choices as $n): ?><option value="<?= $n ?>" <?= $n === $per ? 'selected' : '' ?>><?= $n ?> per page</option><?php endforeach; ?>
                </select>
                <?php if ($has_filters): ?><a href="?<?= htmlspecialchars(http_build_query(['sort' => $sort, 'per' => $per])) ?>" class="shrink-0 px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-black uppercase tracking-widest text-slate-600 hover:bg-slate-100 flex items-center">Clear</a><?php endif; ?>
            </div>
            <noscript><button class="bg-slate-900 text-white rounded-xl py-2.5 font-black text-xs uppercase tracking-widest">Apply</button></noscript>
        </form>

        <?php if (!$scholars): ?>
            <div class="p-14 text-center">
                <div class="text-4xl mb-3"><?= $has_filters ? '🔍' : '💳' ?></div>
                <h3 class="text-lg font-black text-slate-900"><?= $has_filters ? 'No scholars match these filters' : 'No approved scholars' ?></h3>
                <p class="text-slate-500 text-sm mt-1"><?= $has_filters ? 'Try a different search, or clear the filters.' : 'Approved scholars within your authority will appear here.' ?></p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="no-datatable w-full text-sm min-w-[920px]">
                <thead>
                    <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest bg-slate-50 text-left">
                        <?php $sort_heading('name', 'asc'); ?>
                        <th class="px-4 py-3">Scholarship</th>
                        <?php $sort_heading('date', 'desc'); ?>
                        <?php $sort_heading('entitlement', 'desc', 'text-right'); ?>
                        <?php $sort_heading('released', 'desc', 'text-right'); ?>
                        <?php $sort_heading('unpaid', 'desc', 'text-right'); ?>
                        <th class="px-4 py-3" aria-label="Manage"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($scholars as $scholar):
                    $app_id = (int) $scholar['ApplicationID'];
                    $total = (float) $scholar['TotalEntitlement']; $released = (float) $scholar['ReleasedAmount']; $remaining = (float) $scholar['UnpaidAmount'];
                    $percent = $total > 0 ? min(100, ($released / $total) * 100) : 0;
                    $history = $releases[$app_id] ?? [];
                    $is_open = $open_id === $app_id;
                ?>
                    <tr class="border-t border-slate-100 hover:bg-slate-50/70">
                        <td class="px-4 py-3 align-middle">
                            <p class="font-black text-slate-900"><?= htmlspecialchars($scholar['LastName'] . ', ' . $scholar['FirstName']) ?></p>
                            <p class="text-[11px] font-bold text-slate-400"><?= htmlspecialchars(implode(' · ', array_filter([$scholar['StudentID_Num'], $scholar['ProgramName'] ?? 'Course not set', $scholar['YearLevel']]))) ?></p>
                        </td>
                        <td class="px-4 py-3 align-middle max-w-[220px]">
                            <p class="font-bold text-slate-700 truncate" title="<?= htmlspecialchars($scholar['ScholarshipName']) ?>"><?= htmlspecialchars($scholar['ScholarshipName']) ?></p>
                            <?php if ($scholar['ApplicationStatus'] !== 'Approved'): ?><span class="inline-block mt-0.5 px-1.5 py-0.5 rounded bg-amber-50 border border-amber-200 text-amber-700 text-[10px] font-black"><?= htmlspecialchars($scholar['ApplicationStatus']) ?></span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 align-middle whitespace-nowrap font-bold <?= $scholar['ApprovedAt'] ? 'text-slate-700' : 'text-slate-400 italic' ?>"><?= $scholar['ApprovedAt'] ? date('M j, Y', strtotime($scholar['ApprovedAt'])) : 'Not recorded' ?></td>
                        <td class="px-4 py-3 align-middle text-right whitespace-nowrap font-black text-slate-900">₱<?= number_format($total, 2) ?></td>
                        <td class="px-4 py-3 align-middle text-right whitespace-nowrap">
                            <p class="font-black text-green-600">₱<?= number_format($released, 2) ?></p>
                            <div class="h-1 w-20 ml-auto mt-1 bg-slate-100 rounded-full overflow-hidden" title="<?= round($percent) ?>% released"><div class="h-full bg-green-500" style="width:<?= $percent ?>%"></div></div>
                        </td>
                        <td class="px-4 py-3 align-middle text-right whitespace-nowrap font-black <?= $remaining > 0 ? 'text-amber-600' : 'text-slate-400' ?>">₱<?= number_format($remaining, 2) ?></td>
                        <td class="px-4 py-3 align-middle text-right">
                            <button type="button" onclick="toggleLedgerRow(<?= $app_id ?>)" id="ledger-btn-<?= $app_id ?>" aria-expanded="<?= $is_open ? 'true' : 'false' ?>" aria-controls="ledger-row-<?= $app_id ?>" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] font-black uppercase tracking-widest hover:bg-slate-900 hover:text-white transition-colors whitespace-nowrap">Manage <span class="inline-block transition-transform <?= $is_open ? 'rotate-180' : '' ?>" id="ledger-chev-<?= $app_id ?>">▾</span></button>
                        </td>
                    </tr>
                    <tr id="ledger-row-<?= $app_id ?>" class="<?= $is_open ? '' : 'hidden' ?>">
                        <td colspan="7" class="px-4 pb-5 pt-1 bg-slate-50/70">
                            <div class="grid xl:grid-cols-2 gap-5 pt-3">
                                <div class="space-y-4">
                                    <?php if ($scholar['ApplicationStatus'] === 'Approved'): ?>
                                    <form action="../actions/process_benefit_release.php" method="POST" class="p-4 bg-white rounded-2xl border border-slate-200">
                                        <input type="hidden" name="action" value="save_entitlement"><input type="hidden" name="application_id" value="<?= $app_id ?>"><input type="hidden" name="return" value="<?= htmlspecialchars($return_query) ?>">
                                        <h4 class="font-black text-sm text-slate-900 mb-3">Benefit Account</h4>
                                        <div class="grid sm:grid-cols-2 gap-3">
                                            <div><label class="text-[10px] font-black uppercase text-slate-500">Total Entitlement *</label><input type="number" name="total_entitlement" min="0.01" step="0.01" required value="<?= number_format($total, 2, '.', '') ?>" class="w-full mt-1 p-2.5 border border-slate-200 rounded-xl"></div>
                                            <div><label class="text-[10px] font-black uppercase text-slate-500">Release Frequency</label><input value="<?= htmlspecialchars($scholar['ReleaseFrequency']) ?>" disabled class="w-full mt-1 p-2.5 border border-slate-200 rounded-xl bg-slate-100"></div>
                                        </div>
                                        <label class="block text-[10px] font-black uppercase text-slate-500 mt-3">Notes</label>
                                        <input name="notes" maxlength="500" value="<?= htmlspecialchars($scholar['Notes'] ?? '') ?>" placeholder="Optional entitlement notes" class="w-full mt-1 p-2.5 border border-slate-200 rounded-xl">
                                        <button class="mt-3 bg-slate-900 text-white px-4 py-2.5 rounded-xl font-black text-[11px] uppercase tracking-widest">Save Entitlement</button>
                                    </form>
                                    <form action="../actions/process_benefit_release.php" method="POST" enctype="multipart/form-data" class="p-4 bg-green-50/50 rounded-2xl border border-green-200" onsubmit="return confirm('Record this actual scholarship benefit release?');">
                                        <input type="hidden" name="action" value="add_release"><input type="hidden" name="application_id" value="<?= $app_id ?>"><input type="hidden" name="return" value="<?= htmlspecialchars($return_query) ?>">
                                        <h4 class="font-black text-sm text-slate-900 mb-3">Record Actual Release</h4>
                                        <div class="grid sm:grid-cols-2 gap-3">
                                            <div><label class="text-[10px] font-black uppercase text-slate-500">Amount *</label><input type="number" name="amount" min="0.01" max="<?= number_format($remaining, 2, '.', '') ?>" step="0.01" required class="w-full mt-1 p-2.5 border border-slate-200 rounded-xl"></div>
                                            <div><label class="text-[10px] font-black uppercase text-slate-500">Release Period *</label><input name="release_period" maxlength="100" required placeholder="e.g. 1st Sem AY 2026-2027" class="w-full mt-1 p-2.5 border border-slate-200 rounded-xl"></div>
                                            <div><label class="text-[10px] font-black uppercase text-slate-500">Payment Date *</label><input type="date" name="payment_date" max="<?= $today ?>" required class="w-full mt-1 p-2.5 border border-slate-200 rounded-xl"></div>
                                            <div><label class="text-[10px] font-black uppercase text-slate-500">Mode *</label><select name="release_mode" required class="w-full mt-1 p-2.5 border border-slate-200 rounded-xl"><option value="">Select mode</option><option>Cash</option><option>Bank Transfer</option><option>Check</option><option>E-Wallet</option><option>Other</option></select></div>
                                            <div><label class="text-[10px] font-black uppercase text-slate-500">Reference Number</label><input name="reference_number" maxlength="100" class="w-full mt-1 p-2.5 border border-slate-200 rounded-xl"></div>
                                            <div><label class="text-[10px] font-black uppercase text-slate-500">Acknowledgement Receipt *</label><input type="file" name="acknowledgement_receipt" accept=".pdf,.jpg,.jpeg,.png" required class="w-full mt-1 p-2 border border-slate-200 rounded-xl text-xs bg-white"></div>
                                        </div>
                                        <button <?= $remaining <= 0 ? 'disabled' : '' ?> class="mt-3 bg-green-600 disabled:bg-slate-300 text-white px-4 py-2.5 rounded-xl font-black text-[11px] uppercase tracking-widest">Record Release</button>
                                    </form>
                                    <?php else: ?>
                                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-2xl"><p class="font-black text-amber-800">Historical benefit account</p><p class="text-sm text-amber-700 mt-1">This application is <?= htmlspecialchars($scholar['ApplicationStatus']) ?>. Previous releases remain visible, but new payments are disabled.</p></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h4 class="font-black text-sm text-slate-900 mb-3">Release History <span class="text-slate-400">(<?= count($history) ?>)</span></h4>
                                    <div class="space-y-2 max-h-[420px] overflow-y-auto pr-1">
                                        <?php if (!$history): ?><div class="p-6 text-center border border-dashed border-slate-300 rounded-2xl text-sm text-slate-400 bg-white">No benefit releases recorded yet.</div><?php endif; ?>
                                        <?php foreach ($history as $release): ?>
                                            <div class="p-3 bg-white border border-slate-200 rounded-2xl">
                                                <div class="flex justify-between gap-3"><div><p class="font-black text-slate-900">₱<?= number_format($release['Amount'], 2) ?></p><p class="text-xs font-bold text-slate-500 mt-0.5"><?= htmlspecialchars($release['ReleasePeriod']) ?></p></div><span class="text-xs font-black text-green-700 whitespace-nowrap"><?= date('M d, Y', strtotime($release['PaymentDate'])) ?></span></div>
                                                <div class="mt-2 flex flex-wrap gap-2 text-[10px] font-bold text-slate-500"><span class="px-2 py-1 bg-slate-100 rounded-lg"><?= htmlspecialchars($release['ReleaseMode']) ?></span><?php if ($release['ReferenceNumber']): ?><span class="px-2 py-1 bg-slate-100 rounded-lg">Ref: <?= htmlspecialchars($release['ReferenceNumber']) ?></span><?php endif; ?><a target="_blank" href="<?= htmlspecialchars(benefitReceiptUrl($release['AcknowledgementReceipt'])) ?>" class="px-2 py-1 bg-blue-50 text-blue-700 rounded-lg">View Receipt</a></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Paging -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-100">
            <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest">Showing <?= number_format($offset + 1) ?>–<?= number_format($offset + count($scholars)) ?> of <?= number_format($match_count) ?></p>
            <?php if ($pages > 1): ?>
                <nav class="flex flex-wrap gap-1.5" aria-label="Pages">
                    <?php
                    $page_link = function ($label, int $target, bool $active = false, bool $disabled = false) use ($ledger_url) {
                        $class = 'min-w-9 h-9 px-3 rounded-lg text-xs font-black flex items-center justify-center ';
                        if ($disabled) { echo '<span class="' . $class . 'bg-white border border-slate-200 text-slate-300">' . $label . '</span>'; return; }
                        echo '<a href="' . htmlspecialchars($ledger_url(['page' => $target])) . '"' . ($active ? ' aria-current="page"' : '') . ' class="' . $class . ($active ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100') . '">' . $label . '</a>';
                    };
                    $page_link('←', $page - 1, false, $page === 1);
                    for ($i = 1; $i <= $pages; $i++) {
                        if ($i === 1 || $i === $pages || abs($i - $page) <= 1) $page_link((string) $i, $i, $i === $page);
                        elseif (abs($i - $page) === 2) echo '<span class="px-1 text-slate-400 self-center">…</span>';
                    }
                    $page_link('→', $page + 1, false, $page === $pages);
                    ?>
                </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</main>
<script>
    function toggleLedgerRow(id) {
        const row = document.getElementById('ledger-row-' + id);
        const opening = row.classList.contains('hidden');
        row.classList.toggle('hidden', !opening);
        document.getElementById('ledger-btn-' + id).setAttribute('aria-expanded', opening ? 'true' : 'false');
        document.getElementById('ledger-chev-' + id).classList.toggle('rotate-180', opening);
    }
    // Filters apply as soon as they change; the search waits until typing pauses
    (function () {
        const form = document.getElementById('ledgerFilters'), search = document.getElementById('ledgerSearch');
        form.querySelectorAll('select').forEach(s => s.addEventListener('change', () => form.submit()));
        let timer;
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => form.submit(), 500); });
        const url = new URLSearchParams(location.search);
        if (url.get('q') && !url.get('page') && !url.get('open')) { search.focus(); search.setSelectionRange(search.value.length, search.value.length); }
        const opened = url.get('open') && document.getElementById('ledger-row-' + url.get('open'));
        if (opened) opened.scrollIntoView({ block: 'center' });
    })();
</script>
