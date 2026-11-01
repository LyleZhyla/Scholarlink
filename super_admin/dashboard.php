<?php
session_start();

// ✨ 1. SESSION MANAGER
include '../includes/session_manager.php';

// 🛑 2. THE BACK BUTTON KILLER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 🔒 3. THE SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') {
    session_destroy();
    header("Location: ../admin_login.php"); 
    exit();
}

include '../includes/db_connect.php';
require_once '../includes/audit_gate.php';
$logs_unlocked = isAuditUnlocked();
include '../includes/header.php';
include '../includes/super_sidebar.php';

$admin_name = $_SESSION['first_name'] ?? 'Super Admin';

$totalUsers = 0;
$activePrograms = 0;
$totalLogs = 0;
$recentLogs = [];

try {
    // 1. Fetch Top-Level Metrics
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activePrograms = $pdo->query("SELECT COUNT(*) FROM scholarship WHERE Status = 'Active'")->fetchColumn(); 
    $totalLogs = $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();

    // 2. Fetch the Recent Audit Logs only when unlocked (Increased LIMIT for Pagination to work well)
    if ($logs_unlocked) {
        $log_stmt = $pdo->query("
            SELECT al.AuditID, al.ActionPerformed, al.ActionDate, al.Description, al.IPAddress,
                   u.FirstName, u.LastName, u.Role, u.Username
            FROM audit_log al
            LEFT JOIN users u ON al.UserID = u.UserID
            ORDER BY al.ActionDate DESC
            LIMIT 500
        ");
        $recentLogs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Super Admin Dashboard Error: " . $e->getMessage());
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    
    <header class="mb-10 flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">System Command Center</h2>
            <p class="text-slate-500 font-medium mt-1">Welcome back, <?= htmlspecialchars($admin_name) ?>. Here is your system oversight dashboard.</p>
        </div>
        <div class="text-right hidden sm:block">
            <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest"><?= date('l, F j, Y') ?></p>
        </div>
    </header>

    <div class="mb-10 bg-gradient-to-br from-slate-900 to-slate-800 rounded-[2rem] p-8 lg:p-10 shadow-2xl relative overflow-hidden group">
        <div class="absolute top-0 right-0 w-64 h-64 bg-white/5 rounded-full -translate-y-32 translate-x-32 group-hover:scale-110 transition-transform duration-700"></div>

        <div class="relative z-10 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 mb-6 border-b border-white/10 pb-6">
            <div>
                <h3 class="text-2xl font-black text-white flex items-center gap-3">
                    <span class="text-3xl">🧠</span> Executive AI Analyst
                </h3>
                <p class="text-slate-400 text-sm font-medium mt-1">Generates strategic insights based on live system data.</p>
            </div>
            <button id="generateReportBtn" onclick="generateAIReport()" class="bg-blue-600 hover:bg-blue-500 text-white px-8 py-4 rounded-xl font-black text-[12px] uppercase tracking-widest transition-all shadow-lg shadow-blue-500/30 flex items-center gap-2 shrink-0 active:scale-95">
                <span id="btnIcon" class="text-lg">🪄</span> <span id="btnText">Generate Live Report</span>
            </button>
        </div>

        <div id="aiReportContainer" class="relative z-10 bg-white/5 rounded-2xl p-6 border border-white/10 hidden">
            <p id="aiReportText" class="text-slate-300 text-sm lg:text-base leading-relaxed whitespace-pre-wrap font-medium"></p>
        </div>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8 mb-10">
        
        <a href="users.php" class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm transition-all hover:-translate-y-1 hover:border-blue-400 hover:shadow-xl hover:shadow-blue-500/5 group block relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-1 bg-blue-500 opacity-0 group-hover:opacity-100 transition-opacity"></div>
            <div class="flex items-center justify-between mb-4">
                <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-2xl group-hover:bg-blue-600 group-hover:text-white transition-all">👥</div>
                <span class="text-[14px] font-black text-blue-400 uppercase tracking-widest opacity-0 group-hover:opacity-100 transition-opacity">Manage Users →</span>
            </div>
            <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1 group-hover:text-blue-500 transition-colors">Total Registered Users</p>
            <p class="text-4xl font-black text-slate-900"><?= number_format($totalUsers) ?></p>
        </a>

        <a href="programs.php" class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm transition-all hover:-translate-y-1 hover:border-green-400 hover:shadow-xl hover:shadow-green-500/5 group block relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-1 bg-green-500 opacity-0 group-hover:opacity-100 transition-opacity"></div>
            <div class="flex items-center justify-between mb-4">
                <div class="w-14 h-14 bg-green-50 text-green-600 rounded-2xl flex items-center justify-center text-2xl group-hover:bg-green-600 group-hover:text-white transition-all">🎓</div>
                <span class="text-[14px] font-black text-green-400 uppercase tracking-widest opacity-0 group-hover:opacity-100 transition-opacity">View Programs →</span>
            </div>
            <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1 group-hover:text-green-500 transition-colors">Active Scholarships</p>
            <p class="text-4xl font-black text-slate-900"><?= number_format($activePrograms) ?></p>
        </a>

        <a href="logs.php" class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm transition-all hover:-translate-y-1 hover:border-slate-900 hover:shadow-xl hover:shadow-slate-900/5 group block relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-1 bg-slate-900 opacity-0 group-hover:opacity-100 transition-opacity"></div>
            <div class="flex items-center justify-between mb-4">
                <div class="w-14 h-14 bg-slate-100 text-slate-600 rounded-2xl flex items-center justify-center text-2xl group-hover:bg-slate-900 group-hover:text-white transition-all">📜</div>
                <span class="text-[14px] font-black text-slate-900 uppercase tracking-widest opacity-0 group-hover:opacity-100 transition-opacity">Review Full Logs →</span>
            </div>
            <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1 group-hover:text-slate-900 transition-colors">Total Audit Logs</p>
            <p class="text-4xl font-black text-slate-900"><?= number_format($totalLogs) ?></p>
        </a>

    </div>

    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden mb-10" id="dashboardTableArea">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-slate-50/50">
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Recent System Activity</h3>
                <p class="text-[14px] text-blue-500 font-bold mt-1 uppercase tracking-widest">Live feed of the latest administrative actions</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-3 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-sm shrink-0">
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-widest hidden sm:inline">Show:</span>
                    <select id="itemsPerPage" onchange="changeItemsPerPage()" class="bg-slate-50 border border-slate-200 text-slate-900 text-sm font-bold rounded-lg focus:ring-blue-500 focus:border-blue-500 block px-2 py-1 outline-none cursor-pointer">
                        <option value="5" selected>5 items</option>
                        <option value="10">10 items</option>
                        <option value="25">25 items</option>
                        <option value="999">All items</option>
                    </select>
                </div>
                <a href="logs.php" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[14px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-md active:scale-95 hidden sm:block">
                    View All Logs
                </a>
            </div>
        </div>
        
        <div class="p-6 lg:p-8">
            <div id="dashboardGrid" class="flex flex-col gap-2.5">
                <?php foreach($recentLogs as $log): 
                    // Determine Colors based on Role
                    $roleClass = 'bg-slate-50 text-slate-600 border-slate-200';
                    $borderClass = 'bg-slate-400';
                    if ($log['Role'] === 'Super_Admin') { $roleClass = 'bg-purple-50 text-purple-700 border-purple-200'; $borderClass = 'bg-purple-500'; }
                    if ($log['Role'] === 'Internal_Admin') { $roleClass = 'bg-blue-50 text-blue-700 border-blue-200'; $borderClass = 'bg-blue-500'; }
                    if ($log['Role'] === 'External_Admin') { $roleClass = 'bg-orange-50 text-orange-700 border-orange-200'; $borderClass = 'bg-orange-500'; }
                    if ($log['Role'] === 'Student') { $roleClass = 'bg-green-50 text-green-700 border-green-200'; $borderClass = 'bg-green-500'; }
                ?>
                <div class="dashboard-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-4 sm:p-5 relative overflow-hidden group">
                    
                    <div class="absolute top-0 left-0 w-1 h-full <?= $borderClass ?>"></div>

                    <div class="flex gap-4 items-center flex-1 min-w-0 pl-3 w-full lg:w-auto">
                        <div class="shrink-0 w-24 lg:w-28 border-r border-slate-100 pr-3 lg:pr-4 hidden sm:block">
                            <p class="text-[11px] lg:text-[12px] font-black text-slate-900 uppercase tracking-widest"><?= date('M d, Y', strtotime($log['ActionDate'])) ?></p>
                            <p class="text-[10px] font-black text-slate-500 font-mono tracking-wider mt-0.5"><?= date('h:i:s A', strtotime($log['ActionDate'])) ?></p>
                        </div>
                        
                        <div class="flex-1 min-w-0 pr-2">
                            <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                                <span class="<?= $roleClass ?> px-2 py-0.5 rounded-md font-black text-[9px] uppercase tracking-widest border shrink-0">
                                    <?= htmlspecialchars($log['ActionPerformed']) ?>
                                </span>
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest sm:hidden"><span class="mr-1">•</span> <?= date('M d, y h:i A', strtotime($log['ActionDate'])) ?></span>
                            </div>
                            <p class="text-sm lg:text-[15px] font-bold text-slate-800 leading-tight truncate" title="<?= htmlspecialchars($log['Description']) ?>">
                                <?= htmlspecialchars($log['Description']) ?>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between w-full lg:w-auto shrink-0 mt-3 lg:mt-0 border-t lg:border-t-0 lg:border-l border-slate-100 pt-3 lg:pt-0 lg:pl-5 pl-3 sm:pl-4">
                        <div class="text-left lg:text-right w-40 flex-1 lg:flex-none">
                            <p class="text-[13px] font-black text-slate-900 truncate">
                                <?= $log['FirstName'] ? htmlspecialchars($log['FirstName'] . ' ' . $log['LastName']) : 'System / Unknown' ?>
                            </p>
                            <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mt-0.5">
                                <?= htmlspecialchars($log['Role'] ?? 'Automated') ?>
                            </p>
                        </div>
                        <div class="text-right w-24 shrink-0 border-l border-slate-100 pl-4 lg:pl-5 ml-2 lg:ml-0 hidden sm:block">
                            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">IP Address</p>
                            <p class="text-[11px] font-black text-slate-600 truncate"><?= htmlspecialchars($log['IPAddress'] ?? 'Hidden') ?></p>
                        </div>
                    </div>

                </div>
                <?php endforeach; ?>
                
                <?php if(!$logs_unlocked): ?>
                    <div class="col-span-full p-16 lg:p-20 bg-slate-50 rounded-[2rem] border border-slate-100 text-center shadow-sm">
                        <div class="text-4xl mb-4">🔒</div>
                        <h3 class="text-lg font-black text-slate-900">Activity Feed Locked</h3>
                        <p class="text-slate-400 font-medium text-sm mt-1 mb-6">Audit records are restricted. Re-enter your password to view them.</p>
                        <a href="audit_unlock.php?next=dashboard.php" class="inline-block bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[14px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-md active:scale-95">
                            Unlock Audit Logs
                        </a>
                    </div>
                <?php elseif(empty($recentLogs)): ?>
                    <div class="col-span-full p-16 lg:p-20 bg-slate-50 rounded-[2rem] border border-slate-100 text-center shadow-sm">
                        <div class="text-4xl mb-4 grayscale opacity-30">🛡️</div>
                        <h3 class="text-lg font-black text-slate-900">No Activity Found</h3>
                        <p class="text-slate-400 font-medium text-sm mt-1">The system audit log is currently empty.</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($recentLogs)): ?>
            <div class="flex flex-col items-center justify-center mt-8 gap-4 border-t border-slate-100 pt-6">
                <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
                <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2">
                    Showing 1 to X of X Logs
                </div>
            </div>
            <?php endif; ?>

            <div class="mt-6 text-center sm:hidden">
                 <a href="logs.php" class="inline-block w-full bg-slate-900 text-white px-5 py-3 rounded-xl text-[14px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-md active:scale-95">
                    View All Logs
                </a>
            </div>
        </div>
    </div>

    <script>
    // ========================================================
    // ✨ CENTERED PAGINATION ENGINE
    // ========================================================
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage') ? document.getElementById('itemsPerPage').value : 5) || 5;
    const cards = Array.from(document.querySelectorAll('.dashboard-card'));
    const totalItems = cards.length;

    function renderPagination() {
        if (totalItems === 0) return;
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        
        cards.forEach(card => card.style.display = 'none');

        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        cards.slice(start, end).forEach(card => card.style.display = 'flex');

        const pageInfo = document.getElementById('pageInfo');
        if (pageInfo) {
            pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Logs`;
        }

        let btnHtml = '';
        btnHtml += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm'}">&larr;</button>`;
        
        let startPage = Math.max(1, currentPage - 1);
        let endPage = Math.min(totalPages, currentPage + 1);

        if (currentPage === 1) {
            endPage = Math.min(totalPages, 3);
        } else if (currentPage === totalPages) {
            startPage = Math.max(1, totalPages - 2);
        }

        if (startPage > 1) {
            btnHtml += `<button onclick="changePage(1)" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm">1</button>`;
            if (startPage > 2) {
                btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
            }
        }

        for(let i = startPage; i <= endPage; i++) {
            btnHtml += `<button onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-blue-600 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm'}">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
            }
            btnHtml += `<button onclick="changePage(${totalPages})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm">${totalPages}</button>`;
        }
        
        btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm'}">&rarr;</button>`;
        
        const paginationControls = document.getElementById('paginationControls');
        if(paginationControls) {
            paginationControls.innerHTML = btnHtml;
        }
    }

    function changePage(page) {
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
        document.getElementById('dashboardTableArea').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function changeItemsPerPage() {
        const selector = document.getElementById('itemsPerPage');
        if(selector) {
            itemsPerPage = parseInt(selector.value);
            currentPage = 1;
            renderPagination();
        }
    }

    document.addEventListener('DOMContentLoaded', renderPagination);

    // AI REPORT LOGIC
    async function generateAIReport() {
        const btn = document.getElementById('generateReportBtn');
        const icon = document.getElementById('btnIcon');
        const text = document.getElementById('btnText');
        const container = document.getElementById('aiReportContainer');
        const reportText = document.getElementById('aiReportText');

        // Loading State
        btn.classList.add('opacity-75', 'cursor-wait');
        icon.classList.add('animate-spin');
        icon.innerText = "⏳";
        text.innerText = "Analyzing Database...";
        container.classList.remove('hidden');
        reportText.innerHTML = "<span class='text-slate-400 animate-pulse'>Running predictive analysis on user and application data...</span>";

        try {
            const response = await fetch('../actions/ai_system_report.php');
            const data = await response.json();

            icon.classList.remove('animate-spin');

            if (data.success) {
                reportText.innerText = data.report;
                icon.innerText = "✅";
                text.innerText = "Report Updated";
            } else {
                reportText.innerText = "⚠️ Error: " + data.error;
                icon.innerText = "❌";
                text.innerText = "Analysis Failed";
            }
        } catch (error) {
            icon.classList.remove('animate-spin');
            reportText.innerText = "⚠️ Network error occurred while contacting the AI.";
            icon.innerText = "❌";
        }

        // Reset button
        setTimeout(() => {
            btn.classList.remove('opacity-75', 'cursor-wait');
            icon.innerText = "🪄";
            text.innerText = "Refresh Report";
        }, 4000);
    }
    </script>
</main>

<?php include '../includes/footer.php'; ?>