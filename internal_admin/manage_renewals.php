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
include '../includes/header.php'; 
include '../includes/internal_sidebar.php'; 

// 1. KUNIN ANG MGA ACADEMIC TERMS AT SCHOLARSHIPS PARA SA FILTERS
$terms = $pdo->query("SELECT * FROM academic_terms ORDER BY TermID DESC")->fetchAll(PDO::FETCH_ASSOC);
$scholarships = $pdo->query("SELECT ScholarshipID, Name FROM scholarship ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);

// 2. KUNIN ANG ACTIVE RENEWALS (Para sa unang tab)
$active_query = "
    SELECT sr.*, u.FirstName, u.LastName, u.StudentID_Num, s.Name AS ScholarshipName, s.MinimumGWA
    FROM scholar_renewals sr
    JOIN application a ON sr.ApplicationID = a.ApplicationID
    JOIN users u ON sr.UserID = u.UserID
    JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID
    JOIN academic_terms t ON sr.TermID = t.TermID
    WHERE sr.Status NOT IN ('Renewed', 'Dropped') AND t.Status = 'Active'
    ORDER BY sr.Status = 'Pending_Submission' ASC, sr.DateSubmitted DESC
";
$active_renewals = $pdo->query($active_query)->fetchAll(PDO::FETCH_ASSOC);

// 3. KUNIN ANG HISTORY/ARCHIVES (Para sa pangalawang tab)
$history_query = "
    SELECT sr.*, u.FirstName, u.LastName, u.StudentID_Num, s.ScholarshipID, s.Name AS ScholarshipName, s.MinimumGWA, t.TermName
    FROM scholar_renewals sr
    JOIN application a ON sr.ApplicationID = a.ApplicationID
    JOIN users u ON sr.UserID = u.UserID
    JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID
    JOIN academic_terms t ON sr.TermID = t.TermID
    WHERE sr.Status IN ('Renewed', 'Dropped') OR t.Status = 'Ended'
    ORDER BY sr.AdminDecisionDate DESC, sr.DateSubmitted DESC
";
$history_renewals = $pdo->query($history_query)->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .modal-active { display: flex !important; animation: fadeIn 0.2s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .tab-active { background-color: #0f172a !important; color: white !important; }
    .tab-inactive { background-color: white; color: #64748b; }
</style>

<main class="flex-1 bg-slate-50 min-h-screen pt-20 lg:pt-0 lg:ml-64 p-4 sm:p-6 lg:p-8 transition-all duration-300">
    <div class="max-w-7xl mx-auto">
        
        <header class="mb-6 flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Renewal Management</h1>
                <p class="text-sm font-bold text-slate-500 mt-1 uppercase tracking-widest">Enforce Retention Policies & View Archives</p>
            </div>
            
            <!-- TABS TOGGLE -->
            <div class="flex items-center gap-2 bg-white p-1.5 rounded-xl border border-slate-200 shadow-sm">
                <button onclick="switchTab('active')" id="tabBtnActive" class="tab-active px-5 py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all">
                    <i class="fas fa-exclamation-circle mr-1"></i> Action Required
                </button>
                <button onclick="switchTab('history')" id="tabBtnHistory" class="tab-inactive px-5 py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all">
                    <i class="fas fa-archive mr-1"></i> Vault & History
                </button>
            </div>
        </header>

        <?php if(isset($_SESSION['success'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-xl mb-6 text-sm font-bold flex items-center gap-3 shadow-sm">
                ✅ <?= $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <!-- ========================================== -->
        <!-- TAB 1: ACTIVE ACTIONS REQUIRED -->
        <!-- ========================================== -->
        <div id="activeSection" class="flex flex-col gap-3">
            <?php if (empty($active_renewals)): ?>
                <div class="py-16 text-center bg-white border border-dashed border-slate-300 rounded-[2rem]">
                    <span class="text-4xl mb-3 block">🎉</span>
                    <h3 class="text-xl font-black text-slate-900 mb-1">All Caught Up!</h3>
                    <p class="text-sm font-bold text-slate-400">There are no pending renewals for the current semester.</p>
                </div>
            <?php else: ?>
                <?php foreach ($active_renewals as $r): 
                    $badgeStyle = match($r['Status']) {
                        'Appeal_Requested' => 'bg-red-50 text-red-700 border-red-200 animate-pulse',
                        'Pending_Submission' => 'bg-orange-50 text-orange-700 border-orange-200',
                        default => 'bg-blue-50 text-blue-700 border-blue-200' 
                    };
                    $displayStatus = str_replace('_', ' ', $r['Status']);
                ?>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition-all p-5 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 border-l-4 border-l-blue-500">
                        <div class="flex items-center gap-4 w-full lg:w-1/3">
                            <div class="w-12 h-12 bg-slate-900 text-white rounded-full flex items-center justify-center font-black text-lg shrink-0">
                                <?= substr(htmlspecialchars($r['FirstName']), 0, 1) ?>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm font-black text-slate-900 leading-tight truncate"><?= htmlspecialchars($r['LastName'] . ', ' . $r['FirstName']) ?></h3>
                                <p class="text-[11px] font-bold text-slate-500 uppercase tracking-widest mt-0.5 truncate"><?= htmlspecialchars($r['ScholarshipName']) ?></p>
                            </div>
                        </div>

                        <div class="flex items-center gap-6 w-full lg:w-auto">
                            <div class="text-center">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Req. GWA</p>
                                <p class="text-[13px] font-black text-slate-700"><?= number_format($r['MinimumGWA'], 2) ?></p>
                            </div>
                            <div class="text-center border-l border-slate-100 pl-6">
                                <p class="text-[10px] font-black text-blue-500 uppercase tracking-widest mb-0.5">AI GWA</p>
                                <p class="text-[14px] font-black <?= ($r['AI_Extracted_GWA'] > $r['MinimumGWA']) ? 'text-red-500' : 'text-green-600' ?>">
                                    <?= $r['AI_Extracted_GWA'] ? number_format($r['AI_Extracted_GWA'], 2) : 'N/A' ?>
                                </p>
                            </div>
                            <div class="w-32 text-right border-l border-slate-100 pl-6">
                                <span class="inline-flex items-center justify-center w-full px-3 py-1.5 rounded-lg <?= $badgeStyle ?> text-[10px] font-black uppercase tracking-widest border">
                                    <?= $displayStatus ?>
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 w-full lg:w-auto border-t lg:border-t-0 lg:border-l border-slate-100 pt-3 lg:pt-0 lg:pl-6 justify-end">
                            <?php if ($r['Status'] === 'Pending_Submission'): ?>
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Waiting for student...</span>
                            <?php else: ?>
                                <button onclick="openDecisionModal(<?= htmlspecialchars(json_encode($r)) ?>, true)" class="h-9 px-5 bg-slate-900 text-white rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-md active:scale-95 flex items-center gap-2">
                                    Review & Decide
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- TAB 2: ARCHIVES & HISTORY WITH PREDICTIVE SEARCH -->
        <!-- ========================================== -->
        <div id="historySection" class="hidden flex-col gap-4">
            
            <!-- ✨ PREDICTIVE SEARCH FILTERS -->
            <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200 grid grid-cols-1 md:grid-cols-3 gap-3">
                
                <!-- Student Search -->
                <div class="relative">
                    <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none"><i class="fas fa-search text-slate-400"></i></div>
                    <input type="text" id="searchHistory" oninput="filterHistory()" placeholder="Search student name or ID..." class="w-full bg-slate-50 border border-slate-200 pl-10 pr-4 py-3 rounded-xl text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20">
                </div>

                <!-- Predictive Semester Search -->
                <div class="relative">
                    <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none"><i class="fas fa-calendar-alt text-slate-400"></i></div>
                    <input type="text" list="termOptions" id="searchTerm" oninput="filterHistory()" placeholder="Type to search semester..." class="w-full bg-slate-50 border border-slate-200 pl-10 pr-4 py-3 rounded-xl text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20">
                    <datalist id="termOptions">
                        <?php foreach($terms as $t): ?>
                            <option value="<?= htmlspecialchars($t['TermName']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Predictive Scholarship Search -->
                <div class="relative">
                    <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none"><i class="fas fa-award text-slate-400"></i></div>
                    <input type="text" list="scholOptions" id="searchScholarship" oninput="filterHistory()" placeholder="Type to search program..." class="w-full bg-slate-50 border border-slate-200 pl-10 pr-4 py-3 rounded-xl text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20">
                    <datalist id="scholOptions">
                        <?php foreach($scholarships as $s): ?>
                            <option value="<?= htmlspecialchars($s['Name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <!-- PREDICTIVE RENDER CONTAINER -->
            <div id="historyGrid" class="flex flex-col gap-3">
                <!-- Data will be injected here by JS -->
            </div>
            
            <div id="historyEmpty" class="hidden py-16 text-center bg-white border border-dashed border-slate-300 rounded-[2rem]">
                <span class="text-4xl mb-3 block">📭</span>
                <h3 class="text-xl font-black text-slate-900 mb-1">No Records Found</h3>
                <p class="text-sm font-bold text-slate-400">Try adjusting your search or filters.</p>
            </div>
        </div>

    </div>
</main>

<!-- DECISION & VIEW MODAL (Shared by Active and History) -->
<div id="decisionModal" onclick="if(event.target === this) closeDecisionModal()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-4xl rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <div class="p-8 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0">
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight" id="modMainTitle">Renewal Evaluation</h3>
                <p id="modName" class="text-md text-blue-600 font-bold mt-1 uppercase tracking-widest">Student Name</p>
            </div>
            <button type="button" onclick="closeDecisionModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="flex flex-col lg:flex-row flex-1 overflow-hidden">
            <!-- Left: Documents -->
            <div class="w-full lg:w-1/2 p-8 border-r border-slate-100 overflow-y-auto bg-slate-50/50">
                <h4 class="text-[12px] font-black uppercase tracking-widest mb-4 text-slate-400">Submitted Documents</h4>
                <div class="space-y-3" id="modDocs"></div>
            </div>

            <!-- Right: Decision Form / Info -->
            <div class="w-full lg:w-1/2 p-8 overflow-y-auto bg-white flex flex-col">
                <div class="bg-blue-50 border border-blue-100 p-4 rounded-xl mb-4 flex justify-between items-center">
                    <div>
                        <p class="text-[10px] font-black text-blue-400 uppercase tracking-widest">Required GWA</p>
                        <p class="text-lg font-black text-blue-800" id="modReqGWA">0.00</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] font-black text-blue-400 uppercase tracking-widest">AI Computed GWA</p>
                        <p class="text-2xl font-black" id="modExtGWA">0.00</p>
                    </div>
                </div>

                <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl mb-6 shadow-inner flex-1">
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5 flex items-center gap-1.5"><i class="fas fa-robot text-purple-500"></i> AI Semester Verification</p>
                    <p class="text-sm font-bold text-slate-700 leading-relaxed" id="modAIRemarks">Loading...</p>
                </div>

                <!-- Show form only if action is needed (Active Tab) -->
                <form id="actionForm" action="../actions/process_crud.php" method="POST" class="space-y-5 hidden">
                    <input type="hidden" name="module" value="renewals">
                    <input type="hidden" name="action" value="admin_decision">
                    <input type="hidden" name="renewal_id" id="modRenewalId">

                    <div>
                        <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Select Admin Action <span class="text-red-500">*</span></label>
                        <select name="decision" required class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-sm text-slate-900 outline-none focus:ring-2 focus:ring-blue-500/20 cursor-pointer">
                            <option value="">-- Choose Option --</option>
                            <option value="Renewed">✅ Option 1: Retain & Renew Scholarship</option>
                            <option value="Appeal_Requested">⚠️ Option 2: Retain conditionally (Require Letter of Appeal)</option>
                            <option value="Dropped">❌ Option 3: Drop Scholarship</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Admin Notes / Reason</label>
                        <textarea name="admin_notes" rows="3" placeholder="Explain your decision..." class="w-full bg-slate-50 border border-slate-200 px-4 py-3 rounded-xl font-medium text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 resize-none"></textarea>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 text-white px-6 py-4 rounded-xl font-black text-[13px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg active:scale-95">
                        Confirm Decision
                    </button>
                </form>

                <!-- Show past decision if looking at history -->
                <div id="historyDecisionBox" class="hidden mt-auto pt-6 border-t border-slate-100">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Final Status</p>
                    <div id="histStatusBadge" class="inline-flex items-center px-4 py-2 rounded-lg text-xs font-black uppercase tracking-widest border mb-3"></div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Admin Notes on this record:</p>
                    <p class="text-sm font-bold text-slate-700 bg-slate-50 p-3 rounded-xl border border-slate-200 italic" id="histAdminNotes"></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DOCUMENT VIEWER MODAL WITH SCANNER -->
<div id="docViewerModal" onclick="if(event.target === this) closeViewerModal()" class="fixed inset-0 z-[100000] bg-slate-950/90 backdrop-blur-md hidden items-center justify-center p-4 md:p-10 transition-all duration-300">
    <div class="bg-white w-full max-w-5xl h-full max-h-[90vh] rounded-[1.5rem] lg:rounded-[2rem] shadow-2xl relative flex flex-col overflow-hidden">
        <div class="flex justify-between items-center p-4 lg:p-6 border-b border-slate-100 bg-slate-50 relative z-20">
            <div>
                <h3 id="modalDocTitle" class="text-lg lg:text-xl font-black text-slate-900 uppercase tracking-tight">Document Viewer</h3>
                <p class="text-[14px] lg:text-xs font-bold text-slate-400 mt-1">ScholarLink Secure File Preview</p>
            </div>
            <button type="button" onclick="closeViewerModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="viewerCanvas" class="flex-1 bg-slate-200 overflow-hidden relative flex items-center justify-center">
            <img id="docImage" src="" draggable="false" class="hidden max-h-[80vh] max-w-full rounded-xl shadow-2xl bg-white select-none">
            <iframe id="docIframe" src="" class="hidden w-full h-[70vh] rounded-xl border border-slate-300 shadow-sm bg-white relative z-10" frameborder="0"></iframe>
            
            <div id="zoomControls" class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-slate-900/90 backdrop-blur-md p-2 rounded-2xl flex items-center gap-2 z-40 hidden shadow-2xl border border-white/10">
                <button type="button" onclick="zoomImage(-0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all">➖</button>
                <button type="button" onclick="resetZoom()" class="px-4 h-10 bg-white/10 hover:bg-white/20 text-white font-black text-[12px] rounded-xl flex items-center justify-center transition-all uppercase tracking-widest">Reset</button>
                <button type="button" onclick="zoomImage(0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all">➕</button>
            </div>
        </div>
    </div>
</div>

<script>
    // TAB SWITCHING LOGIC
    function switchTab(tab) {
        if(tab === 'active') {
            document.getElementById('tabBtnActive').className = 'tab-active px-5 py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all';
            document.getElementById('tabBtnHistory').className = 'tab-inactive px-5 py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all hover:bg-slate-100';
            document.getElementById('activeSection').classList.remove('hidden');
            document.getElementById('activeSection').classList.add('flex');
            document.getElementById('historySection').classList.add('hidden');
            document.getElementById('historySection').classList.remove('flex');
        } else {
            document.getElementById('tabBtnHistory').className = 'tab-active px-5 py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all';
            document.getElementById('tabBtnActive').className = 'tab-inactive px-5 py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all hover:bg-slate-100';
            document.getElementById('activeSection').classList.add('hidden');
            document.getElementById('activeSection').classList.remove('flex');
            document.getElementById('historySection').classList.remove('hidden');
            document.getElementById('historySection').classList.add('flex');
            filterHistory(); // Initial render
        }
    }

    // PREDICTIVE SEARCH LOGIC
    const historyData = <?= json_encode($history_renewals) ?>;
    
    function filterHistory() {
        const query = document.getElementById('searchHistory').value.toLowerCase();
        const term = document.getElementById('filterTerm').value;
        const schol = document.getElementById('filterScholarship').value;
        
        const filtered = historyData.filter(item => {
            const nameMatch = (item.FirstName + ' ' + item.LastName).toLowerCase().includes(query) || item.StudentID_Num.toLowerCase().includes(query);
            const termMatch = (term === 'all') || (item.TermName === term);
            const scholMatch = (schol === 'all') || (item.ScholarshipID == schol);
            return nameMatch && termMatch && scholMatch;
        });

        const grid = document.getElementById('historyGrid');
        const empty = document.getElementById('historyEmpty');

        if(filtered.length === 0) {
            grid.innerHTML = '';
            empty.classList.remove('hidden');
        } else {
            empty.classList.add('hidden');
            let html = '';
            filtered.forEach(r => {
                let badgeClass = r.Status === 'Renewed' ? 'bg-green-50 text-green-700 border-green-200' : (r.Status === 'Dropped' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-slate-100 text-slate-600 border-slate-200');
                
                html += `
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition-all p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                    <div class="flex items-center gap-4 w-full md:w-1/3">
                        <div class="w-10 h-10 bg-slate-100 text-slate-600 rounded-full flex items-center justify-center font-black text-sm shrink-0">
                            ${r.FirstName.charAt(0)}
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-sm font-black text-slate-900 leading-tight truncate">${r.LastName}, ${r.FirstName}</h3>
                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-0.5 truncate">${r.TermName}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-6 w-full md:w-auto">
                        <div class="text-left md:text-center">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Program</p>
                            <p class="text-[11px] font-bold text-slate-700 truncate max-w-[120px]">${r.ScholarshipName}</p>
                        </div>
                        <div class="w-24 text-right">
                            <span class="inline-flex items-center justify-center w-full px-2 py-1 rounded-md ${badgeClass} text-[9px] font-black uppercase tracking-widest border">
                                ${r.Status.replace('_', ' ')}
                            </span>
                        </div>
                        <button onclick='openDecisionModal(${JSON.stringify(r).replace(/'/g, "&#39;")}, false)' class="h-8 px-4 bg-white border border-slate-200 text-slate-600 rounded-lg text-[10px] font-black uppercase tracking-widest hover:bg-blue-50 hover:text-blue-600 transition-all shadow-sm">
                            View Vault
                        </button>
                    </div>
                </div>`;
            });
            grid.innerHTML = html;
        }
    }

    // MODAL LOGIC (Supports both Action Mode and View-Only Mode)
    function openDecisionModal(data, isActionable) {
        document.getElementById('modRenewalId').value = data.RenewalID;
        document.getElementById('modName').innerText = data.FirstName + ' ' + data.LastName;
        document.getElementById('modReqGWA').innerText = parseFloat(data.MinimumGWA).toFixed(2);
        
        document.getElementById('modAIRemarks').innerText = data.AI_Remarks ? data.AI_Remarks : 'No AI verification data available.';

        const extGWA = document.getElementById('modExtGWA');
        if (data.AI_Extracted_GWA) {
            extGWA.innerText = parseFloat(data.AI_Extracted_GWA).toFixed(2);
            extGWA.className = (data.AI_Extracted_GWA > data.MinimumGWA) ? "text-2xl font-black text-red-500" : "text-2xl font-black text-green-600";
        } else {
            extGWA.innerText = 'Unreadable';
            extGWA.className = "text-2xl font-black text-orange-500";
        }

        // Setup Docs List
        let docsHTML = '';
        if (data.ROG_FilePath) {
            docsHTML += `
                <div class="flex items-center gap-2 w-full p-2 bg-white border border-slate-200 rounded-xl hover:border-blue-400 transition-all group">
                    <button type="button" onclick="openViewerModal('${data.ROG_FilePath}', 'Report of Grades')" class="flex items-center gap-3 flex-1 text-left px-2 py-2">
                        <div class="w-10 h-10 bg-red-50 text-red-500 rounded-lg flex items-center justify-center text-lg shrink-0"><i class="fas fa-file-pdf"></i></div>
                        <div>
                            <p class="text-sm font-black text-slate-900 group-hover:text-blue-600">Report of Grades</p>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Click to view file</p>
                        </div>
                    </button>
                    <a href="${data.ROG_FilePath}" download="ROG_${data.LastName}.pdf" class="w-10 h-10 flex items-center justify-center bg-slate-50 text-slate-500 rounded-full hover:bg-slate-200 shrink-0 mr-2"><i class="fas fa-download"></i></a>
                </div>`;
        }
        if (data.COR_FilePath) {
            docsHTML += `
                <div class="flex items-center gap-2 w-full p-2 bg-white border border-slate-200 rounded-xl hover:border-blue-400 transition-all group">
                    <button type="button" onclick="openViewerModal('${data.COR_FilePath}', 'Certificate of Registration')" class="flex items-center gap-3 flex-1 text-left px-2 py-2">
                        <div class="w-10 h-10 bg-purple-50 text-purple-500 rounded-lg flex items-center justify-center text-lg shrink-0"><i class="fas fa-image"></i></div>
                        <div>
                            <p class="text-sm font-black text-slate-900 group-hover:text-blue-600">Certificate of Registration</p>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Click to view file</p>
                        </div>
                    </button>
                    <a href="${data.COR_FilePath}" download="COR_${data.LastName}.pdf" class="w-10 h-10 flex items-center justify-center bg-slate-50 text-slate-500 rounded-full hover:bg-slate-200 shrink-0 mr-2"><i class="fas fa-download"></i></a>
                </div>`;
        }
        if (data.Appeal_FilePath) {
            docsHTML += `
                <div class="flex items-center gap-2 w-full p-2 bg-amber-50 border border-amber-200 rounded-xl hover:border-amber-400 transition-all group">
                    <button type="button" onclick="openViewerModal('${data.Appeal_FilePath}', 'Letter of Appeal')" class="flex items-center gap-3 flex-1 text-left px-2 py-2">
                        <div class="w-10 h-10 bg-amber-100 text-amber-600 rounded-lg flex items-center justify-center text-lg shrink-0">⚠️</div>
                        <div>
                            <p class="text-sm font-black text-amber-900 group-hover:text-amber-700">Letter of Appeal</p>
                            <p class="text-[10px] font-bold text-amber-700 uppercase tracking-widest mt-0.5">Student's Explanation</p>
                        </div>
                    </button>
                    <a href="${data.Appeal_FilePath}" download="Appeal_${data.LastName}.pdf" class="w-10 h-10 flex items-center justify-center bg-amber-200 text-amber-800 rounded-full hover:bg-amber-300 shrink-0 mr-2"><i class="fas fa-download text-sm"></i></a>
                </div>`;
        }
        document.getElementById('modDocs').innerHTML = docsHTML;

        // Toggle Form vs History Info
        if (isActionable) {
            document.getElementById('modMainTitle').innerText = "Renewal Evaluation";
            document.getElementById('actionForm').classList.remove('hidden');
            document.getElementById('historyDecisionBox').classList.add('hidden');
        } else {
            document.getElementById('modMainTitle').innerText = "Archive Details";
            document.getElementById('actionForm').classList.add('hidden');
            document.getElementById('historyDecisionBox').classList.remove('hidden');
            
            let badgeClass = data.Status === 'Renewed' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200';
            document.getElementById('histStatusBadge').className = `inline-flex items-center px-4 py-2 rounded-lg text-xs font-black uppercase tracking-widest border mb-3 ${badgeClass}`;
            document.getElementById('histStatusBadge').innerText = data.Status;
            document.getElementById('histAdminNotes').innerText = data.AdminNotes ? `"${data.AdminNotes}"` : '"No remarks provided by admin."';
        }

        document.getElementById('decisionModal').classList.remove('hidden');
        document.getElementById('decisionModal').classList.add('flex');
    }

    function closeDecisionModal() {
        document.getElementById('decisionModal').classList.add('hidden');
        document.getElementById('decisionModal').classList.remove('flex');
    }

    // VIEWER MODAL LOGIC 
    let currentZoom = 1; let translateX = 0; translateY = 0; 
    const imgElement = document.getElementById('docImage'); 
    const viewerCanvas = document.getElementById('viewerCanvas');

    function applyTransform() { imgElement.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`; }
    function zoomImage(step) { currentZoom = Math.max(0.5, Math.min(5, currentZoom + step)); applyTransform(); }
    function resetZoom() { currentZoom = 1; translateX = 0; translateY = 0; applyTransform(); }

    if(viewerCanvas) {
        viewerCanvas.addEventListener('wheel', e => { 
            if (!imgElement.classList.contains('hidden')) { e.preventDefault(); zoomImage(e.deltaY > 0 ? -0.1 : 0.1); } 
        }, { passive: false });
    }

    function openViewerModal(fileUrl, docType) {
        document.getElementById('modalDocTitle').innerText = docType; 
        resetZoom(); 
        
        const cleanUrl = fileUrl.replace('../', ''); const rootPath = `../${cleanUrl}`;
        const ext = fileUrl.split('.').pop().toLowerCase();
        
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            document.getElementById('docIframe').classList.add('hidden'); 
            document.getElementById('docImage').classList.remove('hidden'); 
            document.getElementById('docImage').src = rootPath; 
            document.getElementById('zoomControls').classList.remove('hidden'); 
        } else {
            document.getElementById('docImage').classList.add('hidden'); 
            document.getElementById('docIframe').classList.remove('hidden'); 
            document.getElementById('docIframe').src = rootPath; 
            document.getElementById('zoomControls').classList.add('hidden'); 
        }
        document.getElementById('docViewerModal').classList.add('modal-active');
    }

    function closeViewerModal() {
        document.getElementById('docViewerModal').classList.remove('modal-active');
        setTimeout(() => { document.getElementById('docIframe').src = ""; document.getElementById('docImage').src = ""; resetZoom(); }, 200); 
    }
</script>

<?php include '../includes/footer.php'; ?>