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
require_once '../includes/application_authority.php';
include '../includes/header.php';
include '../includes/internal_sidebar.php';

try {
    // --- STEP 1: CAPTURE ACTIVE FILTERS ---
    $filter_course      = $_GET['course'] ?? '';
    $filter_scholarship = $_GET['scholarship'] ?? '';

    // --- STEP 2: FETCH DROPDOWN OPTIONS FOR PREDICTIVE SEARCH ---
    $courses = $pdo->query("SELECT ProgramName AS Major FROM program ORDER BY ProgramName ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Only internally funded scholarships are decided here; providers decide their own in their portal
    $scholarships = $pdo->query("SELECT s.Name FROM scholarship s LEFT JOIN users cu ON cu.UserID = s.CreatedBy WHERE s.Status = 'Active' AND " . INTERNALLY_FUNDED_SQL . " ORDER BY s.Name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // --- STEP 3: BUILD THE DYNAMIC STRICT SHORTLIST QUERY (Removed AnnualIncome!) ---
    $query = "
        SELECT a.ApplicationID, a.TotalScore, a.GPA, 
               u.FirstName, u.LastName, u.StudentID_Num, u.Major, 
               sch.Name AS ScholarshipName, sch.AwardAmount
        FROM application a
        JOIN users u ON a.UserID = u.UserID
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        LEFT JOIN users cu ON cu.UserID = sch.CreatedBy
        WHERE a.Status = 'Shortlisted'
        AND " . INTERNALLY_FUNDED_SQL . "
        AND (
            SELECT COUNT(DISTINCT dr.RequirementID)
            FROM document_requirement dr
            WHERE dr.ScholarshipID = a.ScholarshipID
        ) = (
            SELECT COUNT(DISTINCT sd.RequirementID)
            FROM submitted_document sd
            WHERE sd.ApplicationID = a.ApplicationID 
            AND sd.VerificationStatus = 'Verified'
        )
    ";
    
    $params = [];
    
    if (!empty($filter_scholarship)) {
        $query .= " AND sch.Name LIKE :sch_name";
        $params['sch_name'] = "%" . trim($filter_scholarship) . "%";
    }
    if (!empty($filter_course)) {
        $query .= " AND u.Major LIKE :course";
        $params['course'] = "%" . trim($filter_course) . "%";
    }

    $query .= " ORDER BY a.TotalScore DESC, a.GPA ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $shortlisted = $stmt->fetchAll();

    // ✨ STEP 4: FETCH DOCUMENTS AND CUSTOM ANSWERS FOR MODALS
    $docs_by_app = [];
    $ans_by_app = [];
    
    if (!empty($shortlisted)) {
        $app_ids = array_column($shortlisted, 'ApplicationID');
        $placeholders = implode(',', array_fill(0, count($app_ids), '?'));
        
        // Fetch Documents
        $doc_stmt = $pdo->prepare("
            SELECT sd.FilePath, sd.VerificationStatus, sd.UploadDate,
                   dr.DocumentName, sd.ApplicationID
            FROM submitted_document sd
            JOIN document_requirement dr ON sd.RequirementID = dr.RequirementID
            WHERE sd.ApplicationID IN ($placeholders)
        ");
        $doc_stmt->execute($app_ids);
        foreach($doc_stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $docs_by_app[$d['ApplicationID']][] = $d;
        }

        // Fetch Dynamic Answers
        $ans_stmt = $pdo->prepare("
            SELECT a.ApplicationID, f.FieldName, f.FieldType, a.AnswerText 
            FROM application_custom_answers a
            JOIN scholarship_custom_fields f ON a.FieldID = f.FieldID
            WHERE a.ApplicationID IN ($placeholders)
        ");
        $ans_stmt->execute($app_ids);
        foreach($ans_stmt->fetchAll(PDO::FETCH_ASSOC) as $ans) {
            $ans_by_app[$ans['ApplicationID']][] = $ans;
        }
    }

} catch (PDOException $e) {
    die("<div class='p-10 text-red-500 font-black'>Database Error: " . $e->getMessage() . "</div>");
}
?>

<style>
    .modal-active { display: flex !important; animation: fadeIn 0.2s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    #docImage {
        transition: transform 0.1s ease-out;
        transform-origin: center center;
    }
</style>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300 relative">
    
    <header class="mb-6 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Shortlisted Candidates</h2>
            <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Review fully verified applicants of <strong>internally funded</strong> scholarships for final approval.</p>
            <p class="text-slate-400 text-xs font-bold mt-1">🏢 Externally funded scholarships are approved by their provider in the External portal.</p>
        </div>
        
        <?php $export_params = http_build_query(['scholarship' => $filter_scholarship, 'course' => $filter_course]); ?>
        <div class="text-left sm:text-right flex flex-wrap items-center justify-start sm:justify-end gap-3">
            <!-- ✨ OSSD REPORTS BUTTON -->
            <button type="button" onclick="openReportsModal()" class="bg-blue-600 text-white border border-blue-700 px-5 py-2.5 rounded-xl text-[12px] font-black uppercase tracking-widest hover:bg-blue-700 transition-all shadow-md flex items-center gap-2 active:scale-95">
                📑 Generate Reports
            </button>
            
            <a href="export_scholars_pdf.php?<?= $export_params ?>" target="_blank" class="bg-white text-slate-600 border border-slate-200 px-4 py-2.5 rounded-xl text-[12px] font-black uppercase tracking-widest hover:bg-slate-900 hover:text-white transition-all shadow-sm flex items-center gap-2 hidden lg:flex">
                📄 Quick PDF
            </a>
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

            <div class="flex gap-2">
                <button type="submit" class="bg-slate-900 text-white px-8 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 active:scale-95">Apply Filters</button>
                <a href="shortlist.php" class="bg-slate-100 text-slate-900 px-4 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-slate-200 transition-all flex items-center active:scale-95">Reset</a>
            </div>
        </form>
    </div>

    <div id="shortlistGrid" class="flex flex-col gap-3">
        <?php if(empty($shortlisted)): ?>
            <div class="col-span-full bg-white p-10 lg:p-20 rounded-[2rem] border border-slate-200 text-center shadow-sm">
                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4 grayscale opacity-50">📂</div>
                <h3 class="text-lg font-black text-slate-900 mb-1">No Candidates Found</h3>
                <p class="text-slate-500 font-medium text-sm">Applicants must be fully verified and scored to appear here.</p>
            </div>
        <?php else: ?>
            <?php foreach($shortlisted as $app): ?>
                <div class="shortlist-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-purple-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-5 group relative overflow-hidden">
                    
                    <div class="absolute top-0 left-0 w-1 h-full bg-purple-500"></div>

                    <div class="flex-1 min-w-0 pr-4 lg:pr-8 pl-3 w-full lg:w-auto">
                        <div class="flex items-center gap-3 mb-1.5 flex-wrap">
                            <span class="bg-purple-50 text-purple-700 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-purple-200 shrink-0">
                                APP-<?= str_pad($app['ApplicationID'], 4, '0', STR_PAD_LEFT) ?>
                            </span>
                            <h4 class="text-base font-black text-slate-900 truncate">
                                <?= htmlspecialchars($app['FirstName'].' '.$app['LastName']) ?>
                            </h4>
                            <span class="text-xs text-slate-400 font-bold hidden md:inline">•</span>
                            <span class="text-xs font-black text-blue-600 uppercase tracking-widest truncate">
                                <?= htmlspecialchars($app['ScholarshipName']) ?>
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-xs font-medium text-slate-500 truncate mb-2 lg:mb-0">
                            <span class="text-slate-700 font-bold">ID: <?= htmlspecialchars($app['StudentID_Num']) ?></span>
                            <span class="hidden md:inline">•</span>
                            <span class="truncate hidden md:inline"><?= htmlspecialchars($app['Major']) ?></span>
                            <span class="hidden md:inline">•</span>
                            <span class="text-green-600 font-bold">Grant: ₱<?= number_format($app['AwardAmount'], 2) ?></span>
                        </div>
                    </div>

                    <div class="hidden md:flex items-center justify-center px-8 border-x border-slate-100 shrink-0 min-w-[150px]">
                        <div class="text-center">
                            <span class="text-3xl font-black text-purple-600 leading-none block mb-1"><?= $app['TotalScore'] > 0 ? $app['TotalScore'] : '—' ?></span>
                            <span class="text-[9px] font-black text-purple-400 uppercase tracking-widest">Final Score</span>
                        </div>
                    </div>

                    <div class="mt-4 lg:mt-0 flex items-center justify-between lg:justify-end w-full lg:w-auto shrink-0 gap-3 pl-0 lg:pl-6">
                        <button type="button" onclick="openDocsModal(<?= $app['ApplicationID'] ?>, '<?= htmlspecialchars(addslashes($app['FirstName'].' '.$app['LastName'])) ?>')" class="bg-blue-50 text-blue-600 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600 hover:text-white transition-all shadow-sm border border-blue-100 shrink-0">
                            Review 📄
                        </button>

                        <form action="../actions/process_crud.php" method="POST" class="flex items-center justify-center gap-2 m-0">
                            <input type="hidden" name="module" value="applications">
                            <input type="hidden" name="action" value="final_decision">
                            <input type="hidden" name="application_id" value="<?= $app['ApplicationID'] ?>">
                            
                            <button type="submit" name="status" value="Rejected" onclick="return confirm('Reject this candidate? They will be notified via email.');" class="bg-white text-red-500 border border-slate-200 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-red-50 hover:border-red-200 transition-all shrink-0 active:scale-95">
                                Reject
                            </button>
                            <button type="submit" name="status" value="Approved" onclick="return confirm('Award this scholarship? An official approval email will be sent.');" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-green-600 transition-all shadow-md shrink-0 active:scale-95">
                                Approve 🎓
                            </button>
                        </form>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="flex flex-col items-center justify-center mt-12 gap-4 border-t border-slate-200 pt-8 pb-10">
        <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
        <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2">
            Showing 1 to X of X Candidates
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
        
        <form action="../actions/generate_agency_report.php" method="GET" target="_blank" class="p-6 lg:p-8">
            <div class="space-y-6">
                
                <!-- REPORT TYPE -->
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Select Report Type</label>
                    <select name="report_type" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner cursor-pointer">
                        <option value="scholars_per_scholarship">🎓 Scholars per Scholarship</option>
                        <option value="scholarships_available">📋 Scholarships Available</option>
                        <option value="scholarship_applicants">📝 Scholarship Applicants / Application</option>
                    </select>
                </div>

                <!-- TIME PERIOD -->
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Reporting Period</label>
                    <select name="period" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner cursor-pointer">
                        <option value="all">Overall (All Time)</option>
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="semestral">Semestral</option>
                        <option value="annual">Annual</option>
                    </select>
                </div>

                <!-- FILE FORMAT -->
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Export Format</label>
                    <div class="flex gap-4">
                        <label class="flex-1 cursor-pointer flex items-center justify-center gap-2 p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition-all has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                            <input type="radio" name="format" value="pdf" checked class="w-4 h-4 text-blue-600 border-slate-300 focus:ring-blue-500"> 
                            <span class="text-sm font-bold text-slate-700">PDF</span>
                        </label>
                        <label class="flex-1 cursor-pointer flex items-center justify-center gap-2 p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition-all has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                            <input type="radio" name="format" value="csv" class="w-4 h-4 text-blue-600 border-slate-300 focus:ring-blue-500"> 
                            <span class="text-sm font-bold text-slate-700">CSV / Excel</span>
                        </label>
                    </div>
                </div>

            </div>

            <div class="mt-8 pt-6 border-t border-slate-100">
                <button type="submit" onclick="closeReportsModal()" class="w-full bg-slate-900 text-white px-6 py-4 rounded-xl font-black text-[13px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-md active:scale-95 flex items-center justify-center gap-2">
                    <i class="fas fa-download"></i> Generate & Download
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
</main>

<div id="docsModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9998] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-[95%] sm:max-w-6xl rounded-[1.5rem] lg:rounded-[2.5rem] shadow-2xl overflow-hidden">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50">
            <div>
                <h3 class="text-xl lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Review Application</h3>
                <p class="text-[14px] text-slate-500 font-bold mt-1 uppercase tracking-widest">Viewing files & answers for: <span id="docs-student-name" class="text-blue-600">Student</span></p>
            </div>
            <button onclick="closeDocsModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <div class="p-6 lg:p-8 max-h-[75vh] overflow-y-auto bg-slate-50/30">
            <!-- Dynamic Answers Area -->
            <div id="answersContainer" class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-4"></div>
            
            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-4">Attached Documents</h3>
            <div id="docsContainer" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"></div>
        </div>
    </div>
</div>

<div id="docViewerModal" class="fixed inset-0 z-[9999] bg-slate-900/80 backdrop-blur-md hidden items-center justify-center p-4 md:p-10">
    <div class="bg-white w-full max-w-5xl h-full max-h-[90vh] rounded-[1.5rem] lg:rounded-[2rem] shadow-2xl relative flex flex-col overflow-hidden">
        <div class="flex justify-between items-center p-4 lg:p-6 border-b border-slate-100 bg-slate-50 relative z-20">
            <div>
                <h3 id="modalDocTitle" class="text-lg lg:text-xl font-black text-slate-900 uppercase tracking-tight">Document Archive Viewer</h3>
                <p class="text-[14px] lg:text-md font-bold text-slate-900 mt-1">ScholarLink Secure File Preview</p>
            </div>
            <div class="flex items-center gap-4">
                <button onclick="closeViewerModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        </div>

        <div id="viewerCanvas" class="flex-1 bg-slate-200 overflow-hidden relative flex items-center justify-center">
            <img id="docImage" src="" draggable="false" class="hidden max-h-[80vh] max-w-full rounded-xl shadow-2xl bg-white select-none">
            <iframe id="docIframe" src="" class="hidden w-full h-[70vh] rounded-xl border border-slate-300 shadow-sm bg-white relative z-10" frameborder="0"></iframe>
        </div>
    </div>
</div>

<script>
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage')?.value) || 10;
    const cards = Array.from(document.querySelectorAll('.shortlist-card'));
    const totalItems = cards.length;

    function renderPagination() {
        if (totalItems === 0) return;
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        cards.forEach(card => card.style.display = 'none');
        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        cards.slice(start, end).forEach(card => card.style.display = 'flex');

        const pageInfo = document.getElementById('pageInfo');
        if (pageInfo) pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Candidates`;
        
        let btnHtml = '';
        btnHtml += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&larr;</button>`;
        btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&rarr;</button>`;
        const paginationControls = document.getElementById('paginationControls');
        if(paginationControls) paginationControls.innerHTML = btnHtml;
    }

    function changePage(page) {
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
    }
    function changeItemsPerPage() {
        itemsPerPage = parseInt(document.getElementById('itemsPerPage').value);
        currentPage = 1; renderPagination();
    }
    document.addEventListener('DOMContentLoaded', renderPagination);

    // --- MODAL: SHOW DOCUMENTS AND ANSWERS ---
    const appDocs = <?= json_encode($docs_by_app) ?>;
    const appAnswers = <?= json_encode($ans_by_app) ?>;

    function openDocsModal(appId, studentName) {
        document.getElementById('docs-student-name').innerText = studentName;
        const container = document.getElementById('docsContainer');
        const ansContainer = document.getElementById('answersContainer');
        
        // Render Custom Answers
        ansContainer.innerHTML = '';
        const answers = appAnswers[appId] || [];
        if (answers.length > 0) {
            ansContainer.innerHTML = '<div class="col-span-full"><h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-2">Application Form Answers</h3></div>';
            answers.forEach(ans => {
                let safeAnswer = ans.AnswerText ? ans.AnswerText.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '<i>No answer provided</i>';
                let html = `<div class="${ans.FieldType === 'Textarea' ? 'col-span-full' : ''}">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">${ans.FieldName}</p>`;
                if (ans.FieldType === 'Textarea') {
                    html += `<div class="p-5 bg-white rounded-xl border border-slate-200 text-sm text-slate-700 leading-relaxed whitespace-pre-wrap font-medium shadow-sm">${safeAnswer}</div>`;
                } else {
                    html += `<p class="font-bold text-slate-900 text-sm p-4 bg-white border border-slate-200 rounded-xl shadow-sm">${safeAnswer}</p>`;
                }
                html += `</div>`;
                ansContainer.innerHTML += html;
            });
        }

        // Render Documents
        container.innerHTML = ''; 
        const docs = appDocs[appId] || [];
        if (docs.length === 0) {
            container.innerHTML = `<div class="col-span-full bg-white p-10 rounded-[2rem] text-center border border-slate-200 shadow-sm"><p class="text-slate-500 font-medium text-sm">No Documents Found.</p></div>`;
        } else {
            docs.forEach(doc => {
                const safeUrl = doc.FilePath.replace(/'/g, "\\'");
                const safeName = doc.DocumentName.replace(/'/g, "\\'");
                const actualDownloadPath = `../${doc.FilePath.replace('../', '')}`;
                container.innerHTML += `
                    <div class="bg-white p-6 rounded-[1.5rem] border border-slate-200 shadow-sm flex flex-col justify-between group hover:border-blue-300 transition-all">
                        <div>
                            <h4 class="text-sm font-black text-slate-900 mb-1 leading-tight">${doc.DocumentName}</h4>
                            <span class="bg-green-50 text-green-600 border-green-200 px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border">${doc.VerificationStatus}</span>
                        </div>
                        <div class="mt-6 flex gap-2">
                            <button type="button" onclick="openViewerModal('${safeUrl}', '${safeName}')" class="flex-1 text-center bg-slate-100 text-slate-900 py-3 rounded-xl font-black text-[12px] uppercase tracking-widest hover:bg-blue-600 hover:text-white transition-all">View 👁️</button>
                            <a href="${actualDownloadPath}" download="${doc.DocumentName}" class="flex-1 text-center bg-slate-900 text-white py-3 rounded-xl font-black text-[12px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-md active:scale-95">Save 💾</a>
                        </div>
                    </div>
                `;
            });
        }
        
        document.getElementById('docsModal').classList.remove('hidden');
        document.getElementById('docsModal').classList.add('flex');
        document.body.style.overflow = 'hidden';
    }

    function closeDocsModal() {
        document.getElementById('docsModal').classList.add('hidden');
        document.getElementById('docsModal').classList.remove('flex');
        document.body.style.overflow = 'auto';
    }

    function openViewerModal(fileUrl, docType) {
        document.getElementById('modalDocTitle').innerText = docType;
        const rootPath = `../${fileUrl.replace('../', '')}`;
        const ext = fileUrl.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            document.getElementById('docIframe').classList.add('hidden');
            document.getElementById('docImage').classList.remove('hidden');
            document.getElementById('docImage').src = rootPath; 
        } else {
            document.getElementById('docImage').classList.add('hidden');
            document.getElementById('docIframe').classList.remove('hidden');
            document.getElementById('docIframe').src = rootPath; 
        }
        document.getElementById('docViewerModal').classList.add('modal-active');
    }

    function closeViewerModal() {
        document.getElementById('docViewerModal').classList.remove('modal-active');
        setTimeout(() => { document.getElementById('docIframe').src = ""; document.getElementById('docImage').src = ""; }, 200); 
    }
</script>
</main>
<?php include '../includes/footer.php'; ?>