<?php
session_start();

// ✨ 1. SESSION MANAGER
include '../includes/session_manager.php';

// 🛑 2. THE BACK BUTTON KILLER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 🔒 3. THE SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'External_Admin') {
    header("Location: ../admin_login.php"); 
    exit();
}

include '../includes/db_connect.php';

// --- FETCH METRICS (only this provider's own scholarships, like manage_applications.php) ---
$provider_id = (int) $_SESSION['user_id'];
$pending_reviews = 0;
$completed_evals = 0;
$avg_score = 0;
$recent_apps = [];
try {
    $own = "FROM application a JOIN scholarship sch ON sch.ScholarshipID = a.ScholarshipID WHERE sch.CreatedBy = ?";
    $one = function (string $sql) use ($pdo, $provider_id) {
        $st = $pdo->prepare($sql);
        $st->execute([$provider_id]);
        return $st->fetchColumn();
    };

    // 1. New applications still waiting for a score (saved as 'Submitted'; the demo data uses 'Pending')
    $pending_reviews = (int) $one("SELECT COUNT(*) $own AND a.Status IN ('Pending', 'Submitted', 'Under Review')");

    // 2. Applications this provider has already scored
    $completed_evals = (int) $one("SELECT COUNT(*) $own AND a.TotalScore > 0");

    // 3. Average score given (to track grading trends)
    $avg_score = (float) $one("SELECT AVG(a.TotalScore) $own AND a.TotalScore > 0");

    // 4. "Priority Applications": the oldest pending ones first
    $recent_stmt = $pdo->prepare("
        SELECT a.ApplicationID, u.FirstName, u.LastName, sch.Name AS scholarship_name, a.DateSubmitted
        FROM application a
        JOIN users u ON a.UserID = u.UserID
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        WHERE sch.CreatedBy = ? AND a.Status IN ('Pending', 'Submitted', 'Under Review')
        ORDER BY a.DateSubmitted ASC
    ");
    $recent_stmt->execute([$provider_id]);
    $recent_apps = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Evaluator Dashboard Error: " . $e->getMessage());
}

include '../includes/header.php';
include '../includes/external_sidebar.php'; 
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-10">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Evaluator Dashboard</h2>
        <p class="text-slate-500 font-medium mt-1">Review applicant data, verify documents, and assign merit scores.</p>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8 mb-10">
        
        <div class="bg-white p-6 lg:p-8 rounded-[2rem] border border-slate-200 shadow-sm relative overflow-hidden group">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 shrink-0 bg-orange-50 text-orange-600 rounded-2xl text-xl flex items-center justify-center group-hover:bg-orange-600 group-hover:text-white transition-all shadow-sm">📝</div>
                <span class="text-[10px] lg:text-[12px] font-black text-orange-400 uppercase tracking-widest">Awaiting Score</span>
            </div>
            <p class="text-[12px] lg:text-[14px] font-black text-slate-500 uppercase tracking-widest mb-1">Pending Review</p>
            <p class="text-4xl font-black text-slate-900"><?= number_format($pending_reviews) ?></p>
            <div class="absolute bottom-0 left-0 h-1 bg-orange-500 transition-all w-full opacity-20 group-hover:opacity-100"></div>
        </div>

        <div class="bg-white p-6 lg:p-8 rounded-[2rem] border border-slate-200 shadow-sm relative overflow-hidden group">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 shrink-0 bg-green-50 text-green-600 rounded-2xl text-xl flex items-center justify-center group-hover:bg-green-600 group-hover:text-white transition-all shadow-sm">✅</div>
                <span class="text-[10px] lg:text-[12px] font-black text-green-400 uppercase tracking-widest">Scored</span>
            </div>
            <p class="text-[12px] lg:text-[14px] font-black text-slate-500 uppercase tracking-widest mb-1">Evaluations Finished</p>
            <p class="text-4xl font-black text-slate-900"><?= number_format($completed_evals) ?></p>
            <div class="absolute bottom-0 left-0 h-1 bg-green-500 transition-all w-full opacity-20 group-hover:opacity-100"></div>
        </div>

        <div class="bg-white p-6 lg:p-8 rounded-[2rem] border border-slate-200 shadow-sm relative overflow-hidden group">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 shrink-0 bg-blue-50 text-blue-600 rounded-2xl text-xl flex items-center justify-center group-hover:bg-blue-600 group-hover:text-white transition-all shadow-sm">📊</div>
                <span class="text-[10px] lg:text-[12px] font-black text-blue-400 uppercase tracking-widest">System Avg</span>
            </div>
            <p class="text-[12px] lg:text-[14px] font-black text-slate-500 uppercase tracking-widest mb-1">Average Merit Score</p>
            <p class="text-4xl font-black text-slate-900"><?= number_format($avg_score, 1) ?> <span class="text-sm text-slate-300">/ 100</span></p>
            <div class="absolute bottom-0 left-0 h-1 bg-blue-500 transition-all w-full opacity-20 group-hover:opacity-100"></div>
        </div>

    </div>

   <!-- PRIORITY APPLICATIONS TABLE -->
    <div class="bg-white rounded-[1.5rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col mb-8">
        
        <!-- Header -->
        <div class="p-6 lg:p-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white border-b border-slate-100">
            <div>
                <h3 class="font-black text-slate-900 uppercase tracking-widest text-sm">Priority Applications</h3>
                <p class="text-[12px] text-slate-500 font-bold mt-1">Oldest submissions requiring immediate score assignment.</p>
            </div>
            <a href="manage_applications.php" class="bg-slate-900 text-white px-6 py-3 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-md active:scale-95 whitespace-nowrap">
                View All Tasks
            </a>
        </div>
        
        <!-- ✨ RE-ADDED PREMIUM CONTROLS (Search & Entries) -->
        <div class="px-6 lg:px-8 py-4 flex flex-col sm:flex-row justify-between items-center gap-4 bg-slate-50/50 border-b border-slate-100">
            <div class="flex items-center gap-3 w-full sm:w-auto">
                <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest">Show</span>
                <select id="dt-entries" onchange="changeDtEntries()" class="bg-white border border-slate-200 text-slate-700 text-sm font-bold rounded-lg focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 block px-3 py-2 outline-none cursor-pointer shadow-sm transition-all">
                    <option value="5">5</option>
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest">Entries</span>
            </div>
            
            <div class="relative w-full sm:w-64">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                    <i class="fas fa-search text-slate-400 text-sm"></i>
                </div>
                <input type="text" id="dt-search" onkeyup="searchTable()" placeholder="Search records..." class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-700 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all shadow-sm placeholder:text-slate-400">
            </div>
        </div>

        <!-- The Table (Ghost Container) -->
        <div class="overflow-x-auto" id="table-container">
            <!-- Ghost Table -->
        </div>

        <!-- Empty State (Hidden by default) -->
        <div id="dt-empty" class="hidden p-16 text-center bg-slate-50/30">
            <div class="w-14 h-14 bg-slate-200/50 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl grayscale opacity-60 shadow-inner">☕</div>
            <h3 class="text-base font-black text-slate-900">All caught up!</h3>
            <p class="text-slate-400 font-medium text-[13px] mt-1">There are no pending applications awaiting your evaluation.</p>
        </div>

        <!-- Pagination Footer (Naka-Center at Malaki) -->
        <div class="flex flex-col items-center justify-center pt-8 pb-8 border-t border-slate-100 bg-white gap-3">
            <div id="dt-pagination" class="flex flex-wrap justify-center gap-2">
                <!-- Pagination buttons injected here -->
            </div>
            <div id="dt-info" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-1">
                Showing 0 to 0 of 0
            </div>
        </div>
    </div>

<script>
    // --- DataTables Custom Vanilla JS Implementation ---
    let rawData = <?= json_encode($recent_apps, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    // Names come from what students typed, so escape them before putting them in the table
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let filteredData = [...rawData];
    let currentPage = 1;
    let rowsPerPage = 10; 
    let sortCol = -1;
    let sortAsc = true;

    function initTableStructure() {
        document.getElementById('table-container').innerHTML = `
            <table class="w-full text-left border-collapse min-w-[600px]">
                <thead class="border-b border-slate-100">
                    <tr class="text-[11px] font-black text-slate-900 uppercase tracking-widest bg-white">
                        <th class="p-5 cursor-pointer hover:bg-slate-50 transition-colors" onclick="sortTable(0)">Applicant <span class="text-slate-300 ml-1">⇕</span></th>
                        <th class="p-5 cursor-pointer hover:bg-slate-50 transition-colors" onclick="sortTable(1)">Scholarship <span class="text-slate-300 ml-1">⇕</span></th>
                        <th class="p-5 cursor-pointer hover:bg-slate-50 transition-colors" onclick="sortTable(2)">Submission Date <span class="text-slate-300 ml-1">⇕</span></th>
                        <th class="p-5 text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="dt-body" class="divide-y divide-slate-100 bg-white">
                </tbody>
            </table>
        `;
    }

    function renderTable() {
        const tbody = document.getElementById('dt-body');
        const emptyState = document.getElementById('dt-empty');
        const info = document.getElementById('dt-info');
        
        tbody.innerHTML = '';
        
        if (filteredData.length === 0) {
            emptyState.classList.remove('hidden');
            info.innerText = `Showing 0 to 0 of 0`;
            renderPagination(0);
            return;
        } else {
            emptyState.classList.add('hidden');
        }

        const totalPages = Math.ceil(filteredData.length / rowsPerPage);
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const startIdx = (currentPage - 1) * rowsPerPage;
        const endIdx = Math.min(startIdx + rowsPerPage, filteredData.length);
        
        const pageData = filteredData.slice(startIdx, endIdx);

        pageData.forEach(app => {
            let dateObj = new Date(app.DateSubmitted);
            let formattedDate = dateObj.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });

            const row = document.createElement('tr');
            row.className = "hover:bg-slate-50/50 transition-colors group";
            row.innerHTML = `
                <td class="p-5">
                    <p class="font-black text-slate-900 text-sm leading-none mb-1">${esc(app.FirstName)} ${esc(app.LastName)}</p>
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">APP-${String(app.ApplicationID).padStart(4, '0')}</p>
                </td>
                <td class="p-5 font-bold text-slate-700 text-sm">${esc(app.scholarship_name)}</td>
                <td class="p-5 text-[12px] font-bold text-slate-500 uppercase tracking-tight">${formattedDate}</td>
                <td class="p-5 text-center">
                    <a href="score.php?id=${app.ApplicationID}" class="inline-block bg-slate-100 border border-slate-200 text-slate-700 px-4 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-slate-900 hover:text-white transition-all shadow-sm">Score ✍️</a>
                </td>
            `;
            tbody.appendChild(row);
        });

        info.innerText = `Showing ${startIdx + 1} to ${endIdx} of ${filteredData.length} pending apps`;
        renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
        const pagination = document.getElementById('dt-pagination');
        pagination.innerHTML = '';
        if (totalPages <= 1) return;

        let html = `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&larr;</button>`;
        
        for (let i = 1; i <= totalPages; i++) {
            html += `<button onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-slate-900 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">${i}</button>`;
        }

        html += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&rarr;</button>`;
        
        pagination.innerHTML = html;
    }

    function changePage(page) {
        currentPage = page;
        renderTable();
    }

    // ✨ Search Function
    function searchTable() {
        const query = document.getElementById('dt-search').value.toLowerCase();
        filteredData = rawData.filter(app => {
            const name = (app.FirstName + " " + app.LastName).toLowerCase();
            const id = ("APP-" + String(app.ApplicationID).padStart(4, '0')).toLowerCase();
            const sch = app.scholarship_name.toLowerCase();
            return name.includes(query) || id.includes(query) || sch.includes(query);
        });
        currentPage = 1;
        renderTable();
    }

    // ✨ Entries per page Function
    function changeDtEntries() {
        const selectEl = document.getElementById('dt-entries');
        if (selectEl) {
            rowsPerPage = parseInt(selectEl.value);
            currentPage = 1;
            renderTable();
        }
    }

    function sortTable(colIndex) {
        if (sortCol === colIndex) {
            sortAsc = !sortAsc; 
        } else {
            sortCol = colIndex;
            sortAsc = true;
        }

        filteredData.sort((a, b) => {
            let valA, valB;
            if (colIndex === 0) {
                valA = (a.FirstName + " " + a.LastName).toLowerCase();
                valB = (b.FirstName + " " + b.LastName).toLowerCase();
            } else if (colIndex === 1) {
                valA = a.scholarship_name.toLowerCase();
                valB = b.scholarship_name.toLowerCase();
            } else if (colIndex === 2) {
                valA = new Date(a.DateSubmitted).getTime();
                valB = new Date(b.DateSubmitted).getTime();
            }

            if (valA < valB) return sortAsc ? -1 : 1;
            if (valA > valB) return sortAsc ? 1 : -1;
            return 0;
        });
        renderTable();
    }

    // Initialize Table using the Ghost Technique
    window.addEventListener('load', () => {
        setTimeout(() => {
            initTableStructure();
            changeDtEntries(); 
        }, 200); 
    });
</script>
</main>

<?php include '../includes/footer.php'; ?>