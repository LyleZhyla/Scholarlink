<?php 
session_start();

include '../includes/session_manager.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Internal_Admin') { 
    header("Location: ../admin_login.php"); 
    exit(); 
}

include '../includes/db_connect.php'; 

// ✨ ACADEMIC TERM CHECKER
try {
    $term_stmt = $pdo->query("SELECT * FROM academic_terms WHERE Status = 'Active' LIMIT 1");
    $active_term = $term_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $active_term = null;
}

include '../includes/header.php'; 
include '../includes/internal_sidebar.php'; 

// ✨ DASHBOARD FIGURES AND CHARTS
// "Pending" covers every not-yet-reviewed status (Pending, Submitted, Under Review); scholars = approved applications.
$pending_sql = "Status IN ('Pending', 'Submitted', 'Under Review', 'Under_Review')";
$charts = [];

try {
    $totalPrograms = $pdo->query("SELECT COUNT(*) FROM scholarship")->fetchColumn();
    $pendingApps = $pdo->query("SELECT COUNT(*) FROM application WHERE $pending_sql")->fetchColumn();
    $shortlistedApps = $pdo->query("SELECT COUNT(*) FROM application WHERE Status = 'Shortlisted'")->fetchColumn();
    $activeScholars = $pdo->query("SELECT COUNT(*) FROM application WHERE Status = 'Approved'")->fetchColumn();

    // Applications by status, always in the review order, with the same colours as the reports
    $status_colors = ['Pending' => '#f59e0b', 'Shortlisted' => '#9333ea', 'Approved' => '#16a34a', 'Rejected' => '#dc2626'];
    $by_status = $pdo->query("SELECT CASE WHEN $pending_sql THEN 'Pending' ELSE Status END AS s, COUNT(*) FROM application GROUP BY s")->fetchAll(PDO::FETCH_KEY_PAIR);
    $charts['status'] = ['labels' => [], 'data' => [], 'colors' => []];
    foreach ($status_colors + array_fill_keys(array_keys($by_status), '#64748b') as $s => $color) {
        if (empty($by_status[$s])) continue;
        $charts['status']['labels'][] = $s;
        $charts['status']['data'][] = (int) $by_status[$s];
        $charts['status']['colors'][] = $color;
    }

    // Scholars by sex, region and program
    $scholar_counts = function (string $expr, int $limit = 0) use ($pdo) {
        $rows = $pdo->query("SELECT COALESCE(NULLIF(TRIM($expr), ''), 'Not specified') AS v, COUNT(*) n
                             FROM application a JOIN users u ON u.UserID = a.UserID LEFT JOIN program p ON p.ProgramID = u.ProgramID
                             WHERE a.Status = 'Approved' GROUP BY v ORDER BY n DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($limit && count($rows) > $limit) {   // the rest as "Others"
            $others = array_sum(array_slice($rows, $limit));
            $rows = array_slice($rows, 0, $limit, true) + ['Others' => $others];
        }
        return ['labels' => array_map('strval', array_keys($rows)), 'data' => array_map('intval', array_values($rows))];
    };
    $charts['sex'] = $scholar_counts("CASE WHEN u.Gender IN ('Male', 'Female') THEN u.Gender END");
    $charts['region'] = $scholar_counts('u.Region', 8);
    $charts['program'] = $scholar_counts('COALESCE(p.ProgramName, u.Major)', 10);

    // Applications and approvals per academic term (terms with the same name are one term), oldest first
    require_once '../includes/reports.php';
    $term_rows = $pdo->query("SELECT TermID, COUNT(*) total, SUM(Status = 'Approved') approved FROM application WHERE TermID IS NOT NULL GROUP BY TermID")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $sem_order = ['1st Semester' => 1, '2nd Semester' => 2, 'Midyear / Summer' => 3];
    $by_term = [];
    foreach (reportTerms($pdo) as $id => $t) {
        if (!$t['ay']) continue;
        $label = ($t['semester'] !== 'Other' ? $t['semester'] . ' ' : '') . 'AY ' . $t['ay'];
        $by_term[$label] ??= ['sort' => $t['ay'] . '-' . ($sem_order[$t['semester']] ?? 9), 'total' => 0, 'approved' => 0];
        $by_term[$label]['total'] += (int) ($term_rows[$id]['total'] ?? 0);
        $by_term[$label]['approved'] += (int) ($term_rows[$id]['approved'] ?? 0);
    }
    uasort($by_term, fn($a, $b) => strcmp($a['sort'], $b['sort']));
    // Future terms set up in advance have no applications yet: end the chart at the latest term with some
    while ($by_term && end($by_term)['total'] === 0) array_pop($by_term);
    $charts['terms'] = ['labels' => array_keys($by_term), 'total' => array_column($by_term, 'total'), 'approved' => array_column($by_term, 'approved')];

    $stmt = $pdo->query("
        SELECT u.FirstName, u.LastName, sch.Name AS scholarship_name, a.DateSubmitted 
        FROM application a
        JOIN users u ON a.UserID = u.UserID
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        ORDER BY a.DateSubmitted DESC LIMIT 5
    ");
    $recentActivity = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<main class="flex-1 ml-72 p-10 bg-slate-50/50 min-h-screen">
    
    <!-- ✨ CURRENT ACADEMIC TERM BANNER (COMMENTED OUT)
    <div class="mb-8 flex items-center justify-between bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
        <div>
            <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1">Current Academic Term</p>
            <h3 class="text-2xl font-black tracking-tight">
                <?= $active_term ? htmlspecialchars($active_term['TermName']) : 'No Active Term Set' ?>
            </h3>
        </div>
    </div>
    -->

    <header class="mb-10">
        <h2 class="text-4xl font-black text-slate-900 tracking-tight">Admin Dashboard</h2>
        <p class="text-slate-500 font-medium mt-1">Hello, <?= htmlspecialchars($_SESSION['first_name']) ?>! Overview for today.</p>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-8 mb-12">
        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm flex items-center justify-between group transition-all hover:border-blue-400">
            <div>
                <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1">Scholarships</p>
                <p class="text-5xl font-black text-slate-900"><?= $totalPrograms ?></p>
            </div>
            <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-2xl">🎓</div>
        </div>
        
        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm flex items-center justify-between group transition-all hover:border-orange-400">
            <div>
                <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1">Pending</p>
                <p class="text-5xl font-black text-slate-900"><?= $pendingApps ?></p>
            </div>
            <div class="w-14 h-14 bg-orange-50 text-orange-600 rounded-2xl flex items-center justify-center text-2xl">⏳</div>
        </div>

        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm flex items-center justify-between group transition-all hover:border-purple-400">
            <div>
                <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1">Shortlisted</p>
                <p class="text-5xl font-black text-slate-900"><?= $shortlistedApps ?></p>
            </div>
            <div class="w-14 h-14 bg-purple-50 text-purple-600 rounded-2xl flex items-center justify-center text-2xl">📋</div>
        </div>

        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm flex items-center justify-between group transition-all hover:border-green-400">
            <div>
                <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1">Scholars</p>
                <p class="text-5xl font-black text-slate-900"><?= $activeScholars ?></p>
            </div>
            <div class="w-14 h-14 bg-green-50 text-green-600 rounded-2xl flex items-center justify-center text-2xl">🌟</div>
        </div>
    </div>

    <!-- Charts (Chart.js) -->
    <?php $chart_card = 'bg-white p-6 lg:p-8 rounded-[2rem] border border-slate-200 shadow-sm'; $chart_title = 'font-black text-slate-900 uppercase tracking-widest text-sm mb-1'; $chart_sub = 'text-xs font-bold text-slate-400 mb-5'; ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
        <div class="<?= $chart_card ?>">
            <h3 class="<?= $chart_title ?>">Applications by Status</h3>
            <p class="<?= $chart_sub ?>"><?= number_format(array_sum($charts['status']['data'])) ?> applications</p>
            <div class="relative h-64"><canvas id="statusChart" aria-label="Applications by status"></canvas></div>
        </div>
        <div class="<?= $chart_card ?>">
            <h3 class="<?= $chart_title ?>">Scholars by Sex</h3>
            <p class="<?= $chart_sub ?>"><?= number_format($activeScholars) ?> scholars</p>
            <div class="relative h-64"><canvas id="sexChart" aria-label="Scholars by sex"></canvas></div>
        </div>
        <div class="<?= $chart_card ?>">
            <h3 class="<?= $chart_title ?>">Scholars by Region</h3>
            <p class="<?= $chart_sub ?>">Where scholars come from</p>
            <div class="relative h-64"><canvas id="regionChart" aria-label="Scholars by region"></canvas></div>
        </div>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">
        <div class="<?= $chart_card ?>">
            <h3 class="<?= $chart_title ?>">Scholars by Program</h3>
            <p class="<?= $chart_sub ?>">Top 10 programs</p>
            <div class="relative h-80"><canvas id="programChart" aria-label="Scholars by program"></canvas></div>
        </div>
        <div class="<?= $chart_card ?>">
            <h3 class="<?= $chart_title ?>">Trend per Academic Term</h3>
            <p class="<?= $chart_sub ?>">Applications received and approved each term</p>
            <div class="relative h-80"><canvas id="termChart" aria-label="Applications and approvals per term"></canvas></div>
        </div>
    </div>

    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden mb-10">
        <div class="p-8 border-b border-slate-100 flex justify-between items-center"><h3 class="font-black text-slate-900 uppercase">Recent Activity</h3></div>
        <div class="p-4">
            <?php foreach($recentActivity as $act): ?>
            <div class="flex items-center justify-between p-4 hover:bg-slate-50 rounded-2xl transition-all">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center font-bold"><?= substr($act['FirstName'], 0, 1) ?></div>
                    <div>
                        <p class="text-sm font-black text-slate-900"><?= htmlspecialchars($act['FirstName'] . ' ' . $act['LastName']) ?></p>
                        <p class="text-[14px] font-bold text-slate-900 uppercase tracking-widest">Applied: <?= htmlspecialchars($act['scholarship_name']) ?></p>
                    </div>
                </div>
                <span class="text-[14px] font-black text-slate-300 uppercase"><?= date('M d', strtotime($act['DateSubmitted'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ✨ ACADEMIC TERM MODAL (COMMENTED OUT)
    <div id="termModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col">
            <form id="termForm" action="../actions/process_crud.php" method="POST" onsubmit="confirmTermStart(event)">
                <input type="hidden" name="module" value="academic_terms">
                <input type="hidden" name="action" value="start_new_term">

                <div class="p-8 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Start New Semester</h3>
                        <p class="text-[14px] text-red-600 font-bold mt-1 uppercase tracking-widest">This will trigger mass renewal</p>
                    </div>
                    <button type="button" onclick="closeTermModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="p-8">
                    <div class="bg-orange-50 border border-orange-200 p-4 rounded-xl mb-6">
                        <p class="text-[12px] font-bold text-orange-800 leading-relaxed">
                            <i class="fas fa-exclamation-triangle mr-1"></i> <strong>WARNING:</strong> Starting a new term will end the current one. 
                            All <strong>Approved Scholars</strong> will instantly be placed under <strong>"Pending Renewal"</strong> status and will be locked out of their dashboard until they upload their new ROG and COR.
                        </p>
                    </div>

                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">New Term Name</label>
                    <input type="text" name="term_name" required placeholder="e.g. 1st Semester, AY 2026-2027" class="w-full bg-slate-50 border border-slate-200 px-5 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                </div>

                <div class="p-8 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 shrink-0">
                    <button type="button" onclick="closeTermModal()" class="px-6 py-3 font-black text-[14px] uppercase tracking-widest text-slate-500 hover:text-slate-800 transition-colors">Cancel</button>
                    <button type="submit" id="termSubmitBtn" class="bg-slate-900 text-white px-8 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-blue-600 shadow-xl transition-all active:scale-[0.98]">Confirm & Start</button>
                </div>
            </form>
        </div>
    </div>
    -->

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script>
        /* --- ACADEMIC TERM MODAL JS (COMMENTED OUT) ---
        function openTermModal() {
            document.getElementById('termModal').classList.remove('hidden');
            document.getElementById('termModal').classList.add('flex');
        }

        function closeTermModal() {
            document.getElementById('termModal').classList.add('hidden');
            document.getElementById('termModal').classList.remove('flex');
        }

        function confirmTermStart(e) {
            const confirmed = confirm("PROCEED WITH MASS RENEWAL?\n\nThis will generate renewal requests for all Active Scholars. This process cannot be easily undone. Are you sure?");
            if(!confirmed) {
                e.preventDefault();
            } else {
                const btn = document.getElementById('termSubmitBtn');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                btn.disabled = true;
                btn.classList.replace('bg-slate-900', 'bg-slate-400');
            }
        }
        ------------------------------------------------ */

        // ---------- Dashboard charts ----------
        const CHARTS = <?= json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        const PALETTE = ['#16a34a', '#2563eb', '#f59e0b', '#9333ea', '#dc2626', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#475569', '#94a3b8'];
        const isDark = () => document.documentElement.classList.contains('dark');
        const ink = () => isDark() ? '#cbd5e1' : '#334155';
        const gridLine = () => isDark() ? 'rgba(148, 163, 184, 0.15)' : '#f1f5f9';
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        Chart.defaults.font.weight = 'bold';
        const legendBottom = { position: 'bottom', labels: { boxWidth: 12, padding: 14 } };
        const made = [];
        // A chart, or "No data yet" in its place when there is nothing to show
        const make = (id, config) => {
            if (!config.data.datasets.flatMap(d => d.data).some(v => v > 0)) {
                document.getElementById(id).parentElement.innerHTML = '<p class="h-full flex items-center justify-center text-sm font-bold text-slate-400">No data yet</p>';
                return;
            }
            made.push(new Chart(document.getElementById(id), config));
        };
        const axes = (horizontal) => ({
            x: { beginAtZero: true, grid: { display: horizontal, color: gridLine() }, ticks: { precision: 0 } },
            y: { beginAtZero: true, grid: { display: !horizontal, color: gridLine() }, ticks: { precision: 0 } },
        });

        make('statusChart', { type: 'doughnut',
            data: { labels: CHARTS.status.labels, datasets: [{ data: CHARTS.status.data, backgroundColor: CHARTS.status.colors, borderWidth: 0, hoverOffset: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: legendBottom } } });

        make('sexChart', { type: 'doughnut',
            data: { labels: CHARTS.sex.labels, datasets: [{ data: CHARTS.sex.data, backgroundColor: CHARTS.sex.labels.map(l => l === 'Female' ? '#db2777' : l === 'Male' ? '#2563eb' : '#94a3b8'), borderWidth: 0, hoverOffset: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: legendBottom } } });

        make('regionChart', { type: 'bar',
            data: { labels: CHARTS.region.labels, datasets: [{ label: 'Scholars', data: CHARTS.region.data, backgroundColor: '#16a34a', borderRadius: 8, maxBarThickness: 36 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: axes(false) } });

        make('programChart', { type: 'bar',
            data: { labels: CHARTS.program.labels, datasets: [{ label: 'Scholars', data: CHARTS.program.data, backgroundColor: CHARTS.program.labels.map((_, i) => PALETTE[i % PALETTE.length]), borderRadius: 6, maxBarThickness: 22 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: axes(true) } });

        make('termChart', { type: 'bar',
            data: { labels: CHARTS.terms.labels, datasets: [
                { label: 'Applications', data: CHARTS.terms.total, backgroundColor: '#2563eb', borderRadius: 6, maxBarThickness: 34 },
                { label: 'Approved', data: CHARTS.terms.approved, backgroundColor: '#16a34a', borderRadius: 6, maxBarThickness: 34 },
            ] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: legendBottom }, scales: axes(false) } });

        // Text and grid lines follow light / dark mode, also when it is switched on this page
        function paintCharts() {
            Chart.defaults.color = ink();
            made.forEach(c => {
                Object.values(c.options.scales || {}).forEach(s => { if (s.grid) s.grid.color = gridLine(); if (s.ticks) s.ticks.color = ink(); });
                if (c.options.plugins.legend.labels) c.options.plugins.legend.labels.color = ink();
                c.update('none');
            });
        }
        paintCharts();
        new MutationObserver(paintCharts).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    </script>
</main>
<?php include '../includes/footer.php'; ?>