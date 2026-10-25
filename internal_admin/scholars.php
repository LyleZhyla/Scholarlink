<?php 
session_start();

// ✨ 1. SESSION MANAGER
include '../includes/session_manager.php';

// 🛑 2. THE BACK BUTTON KILLER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 🔒 3. THE SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Internal_Admin') { 
    header("Location: ../admin_login.php"); 
    exit(); 
}

include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/internal_sidebar.php';

try {
    // --- STEP 1: CAPTURE ACTIVE FILTERS ---
    $filter_course      = $_GET['course'] ?? '';
    $filter_scholarship = $_GET['scholarship'] ?? '';

    // --- STEP 2: FETCH DROPDOWN OPTIONS FOR PREDICTIVE SEARCH ---
    $courses = $pdo->query("SELECT DISTINCT Major FROM users WHERE Major IS NOT NULL AND Major != '' ORDER BY Major ASC")->fetchAll(PDO::FETCH_ASSOC);
    $scholarships = $pdo->query("SELECT ScholarshipID, Name FROM scholarship WHERE Status = 'Active' ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // --- STEP 3: BUILD THE DYNAMIC QUERY ---
    $query = "
        SELECT a.ApplicationID, a.UserID, u.FirstName, u.LastName, u.StudentID_Num, u.Major, u.GPA, u.Email, u.ContactNumber,
               sch.Name AS scholarship_name, sch.AwardAmount 
        FROM application a 
        JOIN users u ON a.UserID = u.UserID 
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID 
        WHERE a.Status = 'Approved'
    ";

    $params = [];
    
    // ✨ UPGRADED: Predictive Partial Search Matching!
    if (!empty($filter_scholarship)) {
        $query .= " AND sch.Name LIKE :sch_name";
        $params['sch_name'] = "%" . trim($filter_scholarship) . "%";
    }
    if (!empty($filter_course)) {
        $query .= " AND u.Major LIKE :course";
        $params['course'] = "%" . trim($filter_course) . "%";
    }

    $query .= " ORDER BY u.LastName ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $active_scholars = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("<div class='p-10 text-red-500 font-black'>Database Error: " . $e->getMessage() . "</div>");
}
?>

<style>
    .modal-active { display: flex !important; animation: fadeIn 0.2s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
</style>

<main class="flex-1 lg:ml-72 p-5 lg:p-10 bg-slate-50/50 min-h-screen transition-all duration-300 relative">
    
    <header class="mb-6 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Active Scholars</h2>
            <p class="text-slate-500 font-medium mt-1 text-sm lg:text-base">Monitoring and managing approved scholarship recipients in real-time.</p>
        </div>
        
        <?php $export_params = http_build_query(['scholarship' => $filter_scholarship, 'course' => $filter_course]); ?>
        <div class="text-left sm:text-right flex flex-wrap items-center justify-start sm:justify-end gap-3">
            <!-- ✨ OSSD REPORTS BUTTON -->
            <button type="button" onclick="openReportsModal()" class="bg-blue-600 text-white border border-blue-700 px-5 py-2.5 rounded-xl text-[12px] font-black uppercase tracking-widest hover:bg-blue-700 transition-all shadow-md flex items-center gap-2 active:scale-95">
                📑 Generate Reports
            </button>
            
            <button type="button" onclick="openAnnexModal()" class="bg-green-600 text-white border border-green-700 px-4 py-2.5 rounded-xl text-[12px] font-black uppercase tracking-widest hover:bg-green-700 transition-all shadow-sm flex items-center gap-2 hidden lg:flex active:scale-95">
                📊 Download Annex 1
            </button>
            <div class="flex items-center gap-3 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-sm shrink-0">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest hidden sm:inline">Show:</span>
                <select id="itemsPerPage" onchange="changeItemsPerPage()" class="bg-slate-50 border border-slate-200 text-slate-900 text-sm font-bold rounded-lg focus:ring-green-500 focus:border-green-500 block px-2 py-1 outline-none cursor-pointer">
                    <option value="5">5 items</option>
                    <option value="10" selected>10 items</option>
                    <option value="25">25 items</option>
                    <option value="50">50 items</option>
                    <option value="999">All items</option>
                </select>
            </div>
        </div>
    </header>

    <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm mb-10">
        <form method="GET" class="flex flex-wrap items-end gap-6">
            
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">🔍 Search Program</label>
                <input type="search" name="course" list="courseList" value="<?= htmlspecialchars($filter_course) ?>" placeholder="Type to search e.g. Info..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner">
                <datalist id="courseList">
                    <?php foreach($courses as $c): ?>
                        <option value="<?= htmlspecialchars($c['Major']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="flex-1 min-w-[200px]">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">🔍 Search Scholarship</label>
                <input type="search" name="scholarship" list="scholarshipList" value="<?= htmlspecialchars($filter_scholarship) ?>" placeholder="Type to search scholarship name..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner">
                <datalist id="scholarshipList">
                    <?php foreach($scholarships as $s): ?>
                        <option value="<?= htmlspecialchars($s['Name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="flex gap-2 w-full sm:w-auto mt-2 sm:mt-0">
                <button type="submit" class="flex-1 sm:flex-none bg-slate-900 text-white px-8 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 active:scale-95">
                    Apply Filters
                </button>
                <a href="scholars.php" class="flex-1 sm:flex-none flex items-center justify-center bg-slate-100 text-slate-900 px-6 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-slate-200 transition-all active:scale-95">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div id="scholarsGrid" class="flex flex-col gap-3">
        <?php if(empty($active_scholars)): ?>
            <div class="col-span-full bg-white p-10 lg:p-20 rounded-[2rem] border border-slate-200 text-center shadow-sm">
                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4 grayscale opacity-50">📂</div>
                <h3 class="text-lg font-black text-slate-900 mb-1">No Scholars Found</h3>
                <p class="text-slate-500 font-medium text-sm">No active scholars match your current filters.</p>
            </div>
        <?php else: ?>
            <?php foreach($active_scholars as $s): ?>
                <div class="scholar-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-green-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-5 group relative overflow-hidden">
                    
                    <div class="absolute top-0 left-0 w-1 h-full bg-green-500"></div>

                    <div class="flex-1 min-w-0 pr-4 lg:pr-8 pl-3 w-full lg:w-auto">
                        <div class="flex items-center gap-3 mb-1.5 flex-wrap">
                            <span class="bg-green-50 text-green-700 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-green-200 shrink-0">
                                Scholar
                            </span>
                            <h4 class="text-base font-black text-slate-900 truncate">
                                <?= htmlspecialchars($s['FirstName'].' '.$s['LastName']) ?>
                            </h4>
                            <span class="text-xs text-slate-400 font-bold hidden md:inline">•</span>
                            <span class="text-xs font-black text-blue-600 uppercase tracking-widest truncate">
                                <?= htmlspecialchars($s['scholarship_name']) ?>
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-xs font-medium text-slate-500 truncate mb-2 lg:mb-0">
                            <span class="text-slate-700 font-bold">ID: <?= htmlspecialchars($s['StudentID_Num']) ?></span>
                            <span class="hidden md:inline">•</span>
                            <span class="truncate hidden md:inline"><?= htmlspecialchars($s['Major']) ?></span>
                        </div>
                    </div>

                    <div class="hidden md:flex items-center justify-center px-8 border-x border-slate-100 shrink-0 min-w-[180px]">
                        <div class="text-center">
                            <span class="text-2xl font-black text-green-600 leading-none block mb-1">₱<?= number_format($s['AwardAmount'] ?? 0, 2) ?></span>
                            <span class="text-[9px] font-black text-green-500 uppercase tracking-widest">Grant Amount</span>
                        </div>
                    </div>

                    <div class="mt-4 lg:mt-0 flex items-center justify-between lg:justify-end w-full lg:w-auto shrink-0 gap-3 pl-0 lg:pl-6">
                        <button type="button" onclick="openProfileModal(<?= $s['UserID'] ?>)" class="bg-slate-900 text-white hover:bg-blue-600 px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all shadow-md shrink-0 active:scale-95">
                            View Profile 👤
                        </button>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="flex flex-col items-center justify-center mt-12 gap-4 border-t border-slate-200 pt-8 pb-10">
        <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
        <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2">
            Showing 1 to X of X Scholars
        </div>
    </div>

    <!-- ✨ OSSD REPORTS MODAL ✨ -->
<div id="reportsModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Generate OSSD Reports</h3>
                <p class="text-xs text-blue-600 font-bold mt-1 uppercase tracking-widest">Follow format required by sponsor</p>
            </div>
            <button onclick="closeReportsModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <form action="../actions/export_annex1_excel.php" method="POST" class="p-6 lg:p-8">
            
            <input type="hidden" name="export_tables[]" value="personal_info">
            <input type="hidden" name="export_tables[]" value="academic_profile">
            <input type="hidden" name="export_tables[]" value="family_background">

            <div class="space-y-6">
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Select Report Group</label>
                    <select name="status" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner cursor-pointer">
                        <option value="Approved">🎓 Approved Scholars Only</option>
                        <option value="All_Applicants">📝 All Applicants (Pending, Shortlisted, Approved)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Target Scholarship</label>
                    <select name="scholarship_id" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner cursor-pointer">
                        <option value="all">📂 All Scholarships (Separated by Excel Tabs)</option>
                        <?php foreach($scholarships as $s): ?>
                            <option value="<?= htmlspecialchars($s['ScholarshipID']) ?>"><?= htmlspecialchars($s['Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Reporting Period</label>
                    <select name="period" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner cursor-pointer">
                        <option value="all">Overall (All Time)</option>
                        <option value="monthly">This Month</option>
                        <option value="quarterly">This Quarter</option>
                        <option value="annual">This Year</option>
                    </select>
                </div>
            </div>

            <div class="mt-8 pt-6 border-t border-slate-100">
                <button type="submit" onclick="closeReportsModal()" class="w-full bg-blue-600 text-white px-6 py-4 rounded-xl font-black text-[13px] uppercase tracking-widest hover:bg-blue-700 transition-all shadow-md active:scale-95 flex items-center justify-center gap-2">
                    <i class="fas fa-file-excel"></i> Generate Report
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Script para buksan at isara ang Reports Modal
    function openReportsModal() {
        document.getElementById('reportsModal').classList.remove('hidden');
        document.getElementById('reportsModal').classList.add('flex');
    }
    function closeReportsModal() {
        document.getElementById('reportsModal').classList.add('hidden');
        document.getElementById('reportsModal').classList.remove('flex');
    }
</script>
<div id="annexModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-[2rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Export Annex 1</h3>
                <p class="text-xs text-green-600 font-bold mt-1 uppercase tracking-widest">CHED/TES Excel Format</p>
            </div>
            <button onclick="closeAnnexModal()" type="button" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <form action="../actions/export_annex1_excel.php" method="POST" class="p-6 lg:p-8">
            <input type="hidden" name="status" value="Approved">
            <input type="hidden" name="export_tables[]" value="personal_info">
            <input type="hidden" name="export_tables[]" value="academic_profile">
            <input type="hidden" name="export_tables[]" value="family_background">

            <div class="space-y-6">
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Select Scholarship Group</label>
                    <select name="scholarship_id" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-green-500/20 focus:bg-white transition-all shadow-inner cursor-pointer">
                        <option value="all">📂 All Scholarships (Separated by Tabs)</option>
                        <?php foreach($scholarships as $s): ?>
                            <option value="<?= htmlspecialchars($s['ScholarshipID']) ?>"><?= htmlspecialchars($s['Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-8 pt-6 border-t border-slate-100">
                <button type="submit" onclick="closeAnnexModal()" class="w-full bg-green-600 text-white px-6 py-4 rounded-xl font-black text-[13px] uppercase tracking-widest hover:bg-green-700 transition-all shadow-md active:scale-95 flex items-center justify-center gap-2">
                    <i class="fas fa-file-excel"></i> Generate Excel File
                </button>
            </div>
        </form>
    </div>
</div>
<script>
    function openAnnexModal() {
        document.getElementById('annexModal').classList.remove('hidden');
        document.getElementById('annexModal').classList.add('flex');
    }
    function closeAnnexModal() {
        document.getElementById('annexModal').classList.add('hidden');
        document.getElementById('annexModal').classList.remove('flex');
    }
</script>
</main>

<div id="profileModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9998] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-4xl rounded-[2.5rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Student Profile</h3>
                <p class="text-[14px] text-blue-600 font-bold mt-1 uppercase tracking-widest">Detailed Scholar Overview</p>
            </div>
            <button onclick="closeProfileModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <div class="p-10">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="text-center bg-slate-50 p-8 rounded-[2rem] border border-slate-200">
                    <div id="prof-initial" class="w-24 h-24 bg-blue-600 text-white rounded-[2rem] flex items-center justify-center text-4xl font-black mx-auto mb-6 shadow-xl shadow-blue-200">A</div>
                    <h3 id="prof-name" class="text-xl font-black text-slate-900 mb-1 leading-tight">Name</h3>
                    <p id="prof-id" class="text-[14px] font-black text-slate-900 uppercase tracking-widest">ID Number</p>
                </div>

                <div class="lg:col-span-2 space-y-6">
                    <div class="grid grid-cols-2 gap-6">
                        <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                            <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1">Program</p>
                            <p id="prof-program" class="text-sm font-black text-slate-900">N/A</p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-xl border border-green-100">
                            <p class="text-[14px] font-black text-green-600 uppercase tracking-widest mb-1">GWA / Score</p>
                            <p id="prof-gwa" class="text-sm font-black text-green-700">0.00</p>
                        </div>
                        <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                            <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1">Email</p>
                            <p id="prof-email" class="text-md font-bold text-blue-600 break-all">N/A</p>
                        </div>
                        <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                            <p class="text-[14px] font-black text-slate-900 uppercase tracking-widest mb-1">Contact</p>
                            <p id="prof-contact" class="text-sm font-bold text-slate-900">N/A</p>
                        </div>
                    </div>
                    <a id="prof-docs-link" href="applications.php" class="block w-full text-center bg-slate-900 text-white py-4 rounded-xl font-black text-md uppercase tracking-widest hover:bg-blue-600 transition-all shadow-md active:scale-95">View Application History 📄</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // --- STORE DATA FOR MODAL ---
    let scholarsData = <?= json_encode($active_scholars) ?>;

    // --- MODAL FUNCTIONS ---
    function openProfileModal(uid) {
        const s = scholarsData.find(item => item.UserID == uid);
        if(!s) return;

        document.getElementById('prof-initial').innerText = s.FirstName.charAt(0);
        document.getElementById('prof-name').innerText = s.FirstName + ' ' + s.LastName;
        document.getElementById('prof-id').innerText = s.StudentID_Num || 'N/A';
        document.getElementById('prof-program').innerText = s.Major || 'N/A';
        document.getElementById('prof-gwa').innerText = s.GPA || '0.00';
        document.getElementById('prof-email').innerText = s.Email;
        document.getElementById('prof-contact').innerText = s.ContactNumber || 'N/A';
        
        // Link to applications page filtered for this student
        document.getElementById('prof-docs-link').href = `applications.php?program=${encodeURIComponent(s.Major)}`;

        document.getElementById('profileModal').classList.remove('hidden');
        document.getElementById('profileModal').classList.add('flex');
    }

    function closeProfileModal() {
        document.getElementById('profileModal').classList.add('hidden');
        document.getElementById('profileModal').classList.remove('flex');
    }

    // --- PAGINATION SCRIPT ---
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage') ? document.getElementById('itemsPerPage').value : 10) || 10;
    const cards = Array.from(document.querySelectorAll('.scholar-card'));
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
            pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Scholars`;
        }

        let btnHtml = '';
        btnHtml += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&larr;</button>`;
        
        let startPage = Math.max(1, currentPage - 1);
        let endPage = Math.min(totalPages, currentPage + 1);

        if (currentPage === 1) {
            endPage = Math.min(totalPages, 3);
        } else if (currentPage === totalPages) {
            startPage = Math.max(1, totalPages - 2);
        }

        if (startPage > 1) {
            btnHtml += `<button onclick="changePage(1)" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100">1</button>`;
            if (startPage > 2) {
                btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
            }
        }

        for(let i = startPage; i <= endPage; i++) {
            btnHtml += `<button onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-green-600 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
            }
            btnHtml += `<button onclick="changePage(${totalPages})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100">${totalPages}</button>`;
        }
        
        btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&rarr;</button>`;
        
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
        document.getElementById('scholarsGrid').scrollIntoView({ behavior: 'smooth' });
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
</script>

<?php include '../includes/footer.php'; ?>