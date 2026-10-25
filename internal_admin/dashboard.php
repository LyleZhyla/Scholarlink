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

$status_labels = [];
$status_counts = [];
$prog_labels = [];
$prog_counts = [];

try {
    $totalPrograms = $pdo->query("SELECT COUNT(*) FROM scholarship")->fetchColumn();
    $pendingApps = $pdo->query("SELECT COUNT(*) FROM application WHERE Status = 'Submitted'")->fetchColumn();
    $activeScholars = $pdo->query("SELECT COUNT(*) FROM application WHERE Status = 'Approved'")->fetchColumn();

    $status_stmt = $pdo->query("SELECT Status, COUNT(*) as count FROM application GROUP BY Status");
    while ($row = $status_stmt->fetch(PDO::FETCH_ASSOC)) {
        $status_labels[] = $row['Status'];
        $status_counts[] = $row['count'];
    }

    $prog_stmt = $pdo->query("
        SELECT p.ProgramName, COUNT(a.ApplicationID) as count 
        FROM program p 
        JOIN users u ON p.ProgramID = u.ProgramID 
        JOIN application a ON u.UserID = a.UserID 
        GROUP BY p.ProgramID
    ");
    while ($row = $prog_stmt->fetch(PDO::FETCH_ASSOC)) {
        $prog_labels[] = $row['ProgramName'];
        $prog_counts[] = $row['count'];
    }

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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">
        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm flex items-center justify-between group transition-all hover:border-blue-400">
            <div>
                <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1">Programs</p>
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

        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm flex items-center justify-between group transition-all hover:border-green-400">
            <div>
                <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1">Scholars</p>
                <p class="text-5xl font-black text-slate-900"><?= $activeScholars ?></p>
            </div>
            <div class="w-14 h-14 bg-green-50 text-green-600 rounded-2xl flex items-center justify-center text-2xl">🌟</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">
        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm transition-all hover:border-blue-400">
            <h3 class="font-black text-slate-900 uppercase tracking-widest text-md mb-6 text-center">Application Status Overview</h3>
            <div class="relative h-64 w-full flex justify-center">
                <canvas id="statusChart"></canvas>
            </div>
        </div>

        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm transition-all hover:border-blue-400">
            <h3 class="font-black text-slate-900 uppercase tracking-widest text-md mb-6 text-center">Applicants by Academic Program</h3>
            <div class="relative h-64 w-full flex justify-center">
                <canvas id="programChart"></canvas>
            </div>
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

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        const statusLabels = <?php echo json_encode($status_labels); ?>;
        const statusData = <?php echo json_encode($status_counts); ?>;
        const progLabels = <?php echo json_encode($prog_labels); ?>;
        const progData = <?php echo json_encode($prog_counts); ?>;

        const ctxStatus = document.getElementById('statusChart').getContext('2d');
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusData,
                    backgroundColor: ['#f59e0b', '#22c55e', '#ef4444', '#3b82f6', '#64748b'], 
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%', 
                plugins: {
                    legend: { position: 'bottom', labels: { font: { family: 'Inter', size: 11, weight: 'bold' } } }
                }
            }
        });

        const ctxProg = document.getElementById('programChart').getContext('2d');
        new Chart(ctxProg, {
            type: 'bar',
            data: {
                labels: progLabels,
                datasets: [{
                    label: 'Total Applicants',
                    data: progData,
                    backgroundColor: '#0f172a', 
                    borderRadius: 8, 
                    barThickness: 35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: { stepSize: 1, font: { family: 'Inter', weight: 'bold' } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: 'Inter', weight: 'bold', size: 10 } }
                    }
                }
            }
        });
    </script>
</main>
<?php include '../includes/footer.php'; ?>