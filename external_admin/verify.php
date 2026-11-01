<?php 
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'External_Admin') { 
    header("Location: ../admin_login.php"); 
    exit(); 
}
include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/external_sidebar.php'; 

// --- STEP 1: DYNAMIC SEARCH QUERY ---
$search_query = $_GET['search'] ?? '';
// ✨ FIX: Idinagdag ang `a.Status != 'Rejected'` para hindi na lumabas ang mga rejected applicants!
// Providers only see documents of applicants to their own scholarships
$where_clause = "WHERE sd.VerificationStatus = 'Pending' AND a.Status != 'Rejected' AND sch.CreatedBy = :provider_id";
$params = ['provider_id' => $_SESSION['user_id']];

if (!empty($search_query)) {
    $where_clause .= " AND (u.FirstName LIKE :search OR u.LastName LIKE :search OR u.StudentID_Num LIKE :search OR dr.DocumentName LIKE :search)";
    $params['search'] = "%" . trim($search_query) . "%";
}

try {
    // --- STEP 2: FETCH PENDING DOCUMENTS ---
    // ✨ FIX: Idinagdag natin ang u.UserID at inayos ang ORDER BY para magkasunod-sunod ang dokumento ng bawat estudyante
    $stmt = $pdo->prepare("
        SELECT sd.SubmittedDocID AS document_id, 
               dr.DocumentName AS document_type, 
               sd.VerificationStatus AS verification_status, 
               sd.FilePath AS file_path, 
               u.UserID,
               u.FirstName AS first_name, 
               u.LastName AS last_name, 
               u.StudentID_Num AS student_number,
               a.ApplicationID,
               sch.Name AS ScholarshipName,
               sch.MinimumGWA 
        FROM submitted_document sd
        JOIN document_requirement dr ON sd.RequirementID = dr.RequirementID
        JOIN application a ON sd.ApplicationID = a.ApplicationID
        JOIN users u ON a.UserID = u.UserID
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        $where_clause
        ORDER BY u.LastName ASC, u.FirstName ASC, sd.SubmittedDocID ASC
    ");
    $stmt->execute($params);
    $pending_docs_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✨ STEP 3: GROUP DOCUMENTS BY USER
    $grouped_docs = [];
    foreach ($pending_docs_raw as $row) {
        $uid = $row['UserID'];
        if (!isset($grouped_docs[$uid])) {
            $grouped_docs[$uid] = [
                'student_name' => $row['last_name'] . ', ' . $row['first_name'],
                'student_number' => $row['student_number'],
                'scholarship_name' => $row['ScholarshipName'],
                'documents' => []
            ];
        }
        $grouped_docs[$uid]['documents'][] = $row;
    }

} catch (PDOException $e) {
    die("<div class='p-10 text-red-500 font-black'>Database Error: " . $e->getMessage() . "</div>");
}

$highlight_doc_id = $_GET['doc_id'] ?? null;
?>

<style>
    .modal-active { display: flex !important; animation: fadeIn 0.2s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .highlight-card { animation: highlightFade 3s ease-out; }
    @keyframes highlightFade { 0% { border-color: #f97316; box-shadow: 0 0 15px rgba(249,115,22,0.3); } 100% { border-color: #e2e8f0; } }
</style>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen relative transition-all duration-300">
    <header class="mb-8 lg:mb-10 flex flex-col sm:flex-row sm:justify-between sm:items-end gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Verify Documents</h2>
            <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Review student requirements and run AI authenticity & layout checks.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="bg-orange-100 text-orange-600 border border-orange-200 px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest inline-block shrink-0">
                <?= count($pending_docs_raw) ?> Pending Docs
            </span>
            <div class="flex items-center gap-3 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-sm shrink-0">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest hidden sm:inline">Show Students:</span>
                <select id="itemsPerPage" onchange="changeItemsPerPage()" class="bg-slate-50 border border-slate-200 text-slate-900 text-sm font-bold rounded-lg focus:ring-orange-500 focus:border-orange-500 outline-none cursor-pointer">
                    <option value="5">5</option>
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="999">All</option>
                </select>
            </div>
        </div>
    </header>

    <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm mb-8">
        <form method="GET" class="flex flex-wrap items-end gap-5">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[14px] font-black text-black uppercase tracking-widest mb-2">🔍 Fast Search</label>
                <input type="search" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Type name, ID, or document type..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 px-4 py-3 rounded-xl font-bold text-sm text-black outline-none focus:ring-2 focus:ring-orange-500/20 transition-all shadow-inner">
            </div>
            <div class="flex gap-2 w-full sm:w-auto mt-2 sm:mt-0">
                <button type="submit" class="flex-1 sm:flex-none bg-slate-900 text-white px-6 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-orange-600 transition-all shadow-lg active:scale-95">Filter</button>
            </div>
        </form>
    </div>

    <!-- ✨ GROUPED BY USER GRID -->
    <div id="verifyGrid" class="flex flex-col gap-8">
        <?php if(empty($grouped_docs)): ?>
            <div class="col-span-full p-10 lg:p-20 bg-white rounded-[2rem] border border-slate-200 text-center shadow-sm">
                <div class="text-4xl mb-4">🎉</div>
                <h3 class="text-lg font-black text-slate-900">All caught up!</h3>
                <p class="text-slate-400 font-medium text-sm mt-1">There are no documents currently awaiting verification.</p>
            </div>
        <?php else: ?>
            <?php foreach($grouped_docs as $uid => $group): ?>
                
                <!-- STUDENT GROUP CARD -->
                <div class="student-group bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                    
                    <!-- Student Header -->
                    <div class="bg-slate-900 p-5 lg:px-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-white/10 text-white rounded-full flex items-center justify-center text-xl font-black shrink-0">
                                <?= substr(htmlspecialchars($group['student_name']), 0, 1) ?>
                            </div>
                            <div>
                                <h3 class="text-lg font-black text-white leading-tight"><?= htmlspecialchars($group['student_name']) ?></h3>
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">ID: <?= htmlspecialchars($group['student_number']) ?> &bull; <?= htmlspecialchars($group['scholarship_name']) ?></p>
                            </div>
                        </div>
                        <span class="bg-white/10 text-white border border-white/20 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest shrink-0">
                            <?= count($group['documents']) ?> Pending Docs
                        </span>
                    </div>

                    <!-- Student's Documents List -->
                    <div class="p-5 lg:p-8 flex flex-col gap-3 bg-slate-50/50">
                        <?php foreach($group['documents'] as $doc): 
                            $isHighlight = ($highlight_doc_id == $doc['document_id']) ? 'highlight-card' : '';
                        ?>
                            <div class="verify-card <?= $isHighlight ?> bg-white rounded-xl border border-slate-200 shadow-sm hover:shadow-md hover:border-orange-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-4 relative overflow-hidden group">
                                <div class="absolute top-0 left-0 w-1 h-full bg-orange-500"></div>
                                
                                <div class="flex-1 min-w-0 pl-3 w-full lg:w-auto">
                                    <div class="flex items-center gap-3 mb-1 flex-wrap">
                                        <span class="bg-orange-50 text-orange-600 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-orange-200 shrink-0">
                                            #DOC-<?= $doc['document_id'] ?>
                                        </span>
                                        <span class="text-sm font-black text-slate-900 uppercase tracking-tight truncate"><?= htmlspecialchars($doc['document_type']) ?></span>
                                    </div>
                                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">Min GWA Needed: <span class="text-blue-600"><?= number_format($doc['MinimumGWA'], 2) ?></span></p>
                                </div>

                                <div class="mt-3 lg:mt-0 flex items-center justify-end w-full lg:w-auto shrink-0 gap-2 pl-0 lg:pl-6">
                                    <button type="button" onclick="openDocModal('<?= htmlspecialchars($doc['file_path']) ?>', '<?= htmlspecialchars(addslashes($doc['document_type'])) ?>', <?= $doc['MinimumGWA'] ?>, <?= $doc['ApplicationID'] ?>)" class="bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all border border-blue-100 shadow-sm whitespace-nowrap active:scale-95">
                                        View 👁️
                                    </button>
                                    <form action="../actions/process_crud.php" method="POST" class="flex gap-2 m-0">
                                        <input type="hidden" name="module" value="verification">
                                        <input type="hidden" name="id" value="<?= $doc['document_id'] ?>">
                                        <button type="submit" name="status" value="Rejected" onclick="return confirm('Reject this document? The student will need to re-upload.');" class="bg-white text-red-500 hover:bg-red-50 hover:border-red-200 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase border border-slate-200 transition-all shadow-sm active:scale-95 whitespace-nowrap">
                                            Reject
                                        </button>
                                        <button type="submit" name="status" value="Verified" onclick="return confirm('Approve this document as officially verified?');" class="bg-slate-900 text-white hover:bg-green-600 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase transition-all shadow-md active:scale-95 whitespace-nowrap">
                                            Verify
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="flex flex-col items-center justify-center mt-12 gap-4 border-t border-slate-200 pt-8 pb-10">
        <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
        <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2">
            Showing 1 to X of X Students
        </div>
    </div>
</main>

<!-- DOCUMENT VIEWER MODAL WITH AI SCANNER PANEL -->
<div id="docViewerModal" class="fixed inset-0 z-[9999] bg-slate-900/80 backdrop-blur-md hidden items-center justify-center p-4 md:p-10">
    <div class="bg-white w-full max-w-5xl h-full max-h-[90vh] rounded-[1.5rem] lg:rounded-[2rem] shadow-2xl relative flex flex-col overflow-hidden">
        <div class="flex justify-between items-center p-4 lg:p-6 border-b border-slate-100 bg-slate-50 relative z-20">
            <div>
                <h3 id="modalDocTitle" class="text-lg lg:text-xl font-black text-slate-900 uppercase tracking-tight">Document Viewer</h3>
                <p class="text-[14px] lg:text-xs font-bold text-slate-400 mt-1">ScholarLink Secure File Preview</p>
            </div>
            <div class="flex items-center gap-4">
                <button id="aiScanBtn" onclick="runAIScanner()" class="flex items-center justify-center gap-2 bg-gradient-to-r from-purple-600 to-indigo-600 text-white px-6 py-2.5 rounded-full font-black text-[12px] uppercase tracking-widest shadow-lg shadow-purple-500/30 hover:scale-105 transition-all">
                    <span id="aiScanIcon" class="text-lg">🪄</span> 
                    <span id="aiScanText">AI Verify Authenticity</span>
                </button>
                <button onclick="closeDocModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        </div>
        
        <div id="viewerCanvas" class="flex-1 bg-slate-200 overflow-hidden relative flex items-center justify-center">
            <img id="docImage" src="" draggable="false" class="hidden max-h-[80vh] max-w-full rounded-xl shadow-2xl bg-white select-none">
            <iframe id="docIframe" src="" class="hidden w-full h-[70vh] rounded-xl border border-slate-300 shadow-sm bg-white relative z-10" frameborder="0"></iframe>
            
            <!-- AI ANALYSIS REPORT PANEL -->
            <div id="aiAnalysisResults" class="hidden absolute top-4 right-4 md:top-8 md:right-8 w-[90%] md:w-80 bg-white/95 backdrop-blur-xl border border-slate-200 shadow-2xl rounded-[1.5rem] p-6 z-50 transition-all">
                <h4 class="text-sm font-black text-slate-900 mb-4 border-b border-slate-100 pb-3 flex items-center gap-2">
                    <span class="text-lg">🤖</span> AI Analysis Report
                </h4>
                
                <!-- Authenticity & Mismatch Section -->
                <div class="mb-5">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Authenticity & Layout Check</p>
                    <div id="aiAuthStatus" class="flex items-center gap-2 text-sm font-black text-slate-900 bg-slate-50 p-2.5 rounded-xl border border-slate-100">
                        --
                    </div>
                    <p id="aiAuthDetails" class="text-[10px] font-bold text-slate-500 mt-2 leading-relaxed">Waiting for scan...</p>
                </div>

                <!-- Qualification Section -->
                <div class="mb-6">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Qualification Check</p>
                    <div id="aiQualStatus" class="flex items-center gap-2 text-sm font-black text-slate-900 bg-slate-50 p-2.5 rounded-xl border border-slate-100">
                        --
                    </div>
                    <p id="aiQualDetails" class="text-[10px] font-bold text-slate-500 mt-2 leading-relaxed">Waiting for scan...</p>
                </div>
                
                <button onclick="document.getElementById('aiAnalysisResults').classList.add('hidden')" class="w-full bg-slate-900 text-white py-3 rounded-xl text-xs font-black uppercase tracking-widest shadow-md hover:bg-slate-800 transition-all active:scale-95">Dismiss</button>
            </div>

            <!-- Zoom Controls -->
            <div id="zoomControls" class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-slate-900/90 backdrop-blur-md p-2 rounded-2xl flex items-center gap-2 z-40 hidden shadow-2xl border border-white/10">
                <button type="button" onclick="zoomImage(-0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all">➖</button>
                <button type="button" onclick="resetZoom()" class="px-4 h-10 bg-white/10 hover:bg-white/20 text-white font-black text-[12px] rounded-xl flex items-center justify-center transition-all uppercase tracking-widest">Reset</button>
                <button type="button" onclick="zoomImage(0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all">➕</button>
            </div>
        </div>
    </div>
</div>

<script>
    // ✨ UPDATED PAGINATION LOGIC: Bibilangin na ang mga STUDENT GROUPS, hindi ang individual documents
    let currentPage = 1; 
    let itemsPerPage = 10; 
    const studentGroups = Array.from(document.querySelectorAll('.student-group')); 
    const totalItems = studentGroups.length;

    function renderPagination() {
        if(totalItems === 0) return;
        studentGroups.forEach(group => group.style.display = 'none');

        const totalPages = Math.ceil(totalItems / itemsPerPage);
        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;

        studentGroups.slice(start, end).forEach(group => {
            group.style.display = 'block';
        });

        document.getElementById('pageInfo').innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Students`;

        let btnHtml = `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&larr;</button>`;
        for(let i = 1; i <= totalPages; i++) {
            btnHtml += `<button onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-slate-900 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">${i}</button>`;
        }
        btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&rarr;</button>`;
        
        document.getElementById('paginationControls').innerHTML = btnHtml;
    }

    function changePage(page) {
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
        document.getElementById('verifyGrid').scrollIntoView({ behavior: 'smooth' });
    }

    function changeItemsPerPage() {
        itemsPerPage = parseInt(document.getElementById('itemsPerPage').value);
        currentPage = 1;
        renderPagination();
    }
    
    document.addEventListener('DOMContentLoaded', renderPagination);

    // ✨ ZOOM & AI ENGINE REMAINS THE SAME
    let currentZoom = 1; let translateX = 0; translateY = 0; 
    let currentViewedFile = ""; let currentReqGWA = 0; let currentAppID = 0;
    const imgElement = document.getElementById('docImage'); const viewerCanvas = document.getElementById('viewerCanvas');

    function applyTransform() { imgElement.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`; }
    function zoomImage(step) { currentZoom = Math.max(0.5, Math.min(5, currentZoom + step)); applyTransform(); }
    function resetZoom() { currentZoom = 1; translateX = 0; translateY = 0; applyTransform(); }

    viewerCanvas.addEventListener('wheel', e => { if (!imgElement.classList.contains('hidden')) { e.preventDefault(); zoomImage(e.deltaY > 0 ? -0.1 : 0.1); } }, { passive: false });

    function openDocModal(fileUrl, docType, reqGWA, appID) {
        currentViewedFile = fileUrl; 
        currentReqGWA = reqGWA;
        currentAppID = appID;
        document.getElementById('modalDocTitle').innerText = docType; 
        document.getElementById('aiAnalysisResults').classList.add('hidden'); 
        resetZoom(); 
        
        const cleanUrl = fileUrl.replace('../', ''); const rootPath = `../${cleanUrl}`;
        const ext = fileUrl.split('.').pop().toLowerCase();
        
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            document.getElementById('docIframe').classList.add('hidden'); document.getElementById('docImage').classList.remove('hidden'); document.getElementById('docImage').src = rootPath; document.getElementById('zoomControls').classList.remove('hidden'); 
        } else {
            document.getElementById('docImage').classList.add('hidden'); document.getElementById('docIframe').classList.remove('hidden'); document.getElementById('docIframe').src = rootPath; document.getElementById('zoomControls').classList.add('hidden'); 
        }
        document.getElementById('docViewerModal').classList.add('modal-active'); document.body.style.overflow = 'hidden';
    }

    function closeDocModal() {
        document.getElementById('docViewerModal').classList.remove('modal-active'); document.body.style.overflow = 'auto';
        document.getElementById('aiAnalysisResults').classList.add('hidden');
        setTimeout(() => { document.getElementById('docIframe').src = ""; document.getElementById('docImage').src = ""; resetZoom(); }, 200); 
    }

    async function runAIScanner() {
        const btn = document.getElementById('aiScanBtn'); 
        const icon = document.getElementById('aiScanIcon'); 
        const text = document.getElementById('aiScanText');
        const reportPanel = document.getElementById('aiAnalysisResults');
        
        if (!currentViewedFile) return;
        
        btn.classList.remove('from-purple-600', 'to-indigo-600'); 
        btn.classList.add('bg-slate-800', 'cursor-wait'); 
        icon.classList.add('animate-spin'); icon.innerText = "⏳"; 
        text.innerText = "Scanning Structure...";
        reportPanel.classList.add('hidden');

        try {
            const expectedDocType = document.getElementById('modalDocTitle').innerText;

            const response = await fetch('../actions/ai_scan_document.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' }, 
                body: JSON.stringify({ 
                    file_url: currentViewedFile,
                    document_type: expectedDocType, 
                    required_gwa: currentReqGWA,
                    application_id: currentAppID
                }) 
            });
            const data = await response.json();
            
            icon.classList.remove('animate-spin');
            reportPanel.classList.remove('hidden');
            
            if (data.success) {
                btn.classList.remove('bg-slate-800'); 
                btn.classList.add('from-green-500', 'to-emerald-600'); 
                icon.innerText = "✅"; text.innerText = "AI SCAN COMPLETE";

                // 1. AUTHENTICITY & MISMATCH CHECK
                const authEl = document.getElementById('aiAuthStatus');
                const authDet = document.getElementById('aiAuthDetails');
                
                if(data.authenticity.is_authentic) {
                    authEl.innerHTML = `<span class="text-green-500 text-lg">🛡️</span> <span class="text-green-700">Valid Document Match</span>`;
                    authDet.innerHTML = `Layout and structure match a standard <b>${expectedDocType}</b>.<br><b>Confidence:</b> ${data.authenticity.confidence}%`;
                } else {
                    authEl.innerHTML = `<span class="text-red-500 text-lg">🚨</span> <span class="text-red-700">MISMATCH / FAKE</span>`;
                    authDet.innerHTML = `<b>Reason:</b> ${data.authenticity.flag_reason}`;
                }

                // 2. QUALIFICATION CHECK
                const qualEl = document.getElementById('aiQualStatus');
                const qualDet = document.getElementById('aiQualDetails');
                
                if(data.qualification.is_applicable) {
                    if(data.qualification.is_qualified) {
                        qualEl.innerHTML = `<span class="text-blue-500 text-lg">🎯</span> <span class="text-blue-700">Qualified (GWA Met)</span>`;
                        qualDet.innerHTML = `Extracted GWA: <b>${data.qualification.extracted_gwa}</b> (Required: ${currentReqGWA})`;
                    } else {
                        qualEl.innerHTML = `<span class="text-orange-500 text-lg">⚠️</span> <span class="text-orange-700">Unqualified GWA</span>`;
                        qualDet.innerHTML = `Extracted GWA: <b>${data.qualification.extracted_gwa}</b> (Required: ${currentReqGWA})`;
                    }
                } else {
                    qualEl.innerHTML = `<span class="text-slate-400 text-lg">ℹ️</span> <span class="text-slate-600">N/A for this file</span>`;
                    qualDet.innerHTML = `This document type does not require GWA threshold evaluation.`;
                }

            } else {
                throw new Error(data.error || "Scan failed");
            }
        } catch (error) {
            icon.classList.remove('animate-spin'); btn.classList.remove('bg-slate-800'); btn.classList.add('from-red-500', 'to-rose-600'); icon.innerText = "❌"; text.innerText = "SCAN ERROR";
            alert(error.message);
        }

        setTimeout(() => { btn.classList.remove('from-green-500', 'to-emerald-600', 'from-red-500', 'to-rose-600', 'cursor-wait'); btn.classList.add('from-purple-600', 'to-indigo-600'); icon.innerText = "🪄"; text.innerText = "AI Verify Authenticity"; }, 3000);
    }
</script>

<?php include '../includes/footer.php'; ?>