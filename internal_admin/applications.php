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
require_once '../includes/application_authority.php'; 
include '../includes/header.php'; 
include '../includes/internal_sidebar.php';

$filter_program = $_GET['program'] ?? '';
$filter_scholarship = $_GET['scholarship'] ?? '';
$filter_status = $_GET['status'] ?? '';

$query = "
    SELECT a.ApplicationID, a.Status, a.DateSubmitted, 
           u.FirstName, u.LastName, u.StudentID_Num, u.Major,
           sch.Name AS scholarship_name,
           " . INTERNALLY_FUNDED_SQL . " AS IsInternal,
           COALESCE(NULLIF(cu.Organization, ''), CONCAT(cu.FirstName, ' ', cu.LastName)) AS ProviderName
    FROM application a
    JOIN users u ON a.UserID = u.UserID
    JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
    LEFT JOIN users cu ON cu.UserID = sch.CreatedBy
    WHERE a.Status NOT IN ('Draft', 'Withdrawn')";

$params = [];

if (!empty($filter_program)) {
    $query .= " AND u.Major LIKE :program";
    $params['program'] = "%" . trim($filter_program) . "%";
}
if (!empty($filter_scholarship)) {
    $query .= " AND sch.Name LIKE :sch_name";
    $params['sch_name'] = "%" . trim($filter_scholarship) . "%";
}
if (!empty($filter_status)) {
    $query .= " AND a.Status = :status";
    $params['status'] = $filter_status;
}

$query .= " ORDER BY a.DateSubmitted DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $all_programs = $pdo->query("SELECT ProgramID, ProgramName FROM program ORDER BY ProgramName ASC")->fetchAll(PDO::FETCH_ASSOC);
    $all_scholarships = $pdo->query("SELECT ScholarshipID, Name FROM scholarship ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);

    $docs_by_app = [];
    $ans_by_app = [];
    
    if (!empty($applications)) {
        $app_ids = array_column($applications, 'ApplicationID');
        $placeholders = implode(',', array_fill(0, count($app_ids), '?'));
        
        $doc_stmt = $pdo->prepare("
            SELECT sd.SubmittedDocID, sd.FilePath, sd.VerificationStatus, sd.UploadDate,
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
    die("Filter Error: " . $e->getMessage());
}
?>

<style>
    .modal-active { display: flex !important; animation: fadeIn 0.2s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
</style>

<main class="flex-1 lg:ml-72 p-5 lg:p-10 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-10 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Review Applications</h2>
            <p class="text-slate-500 font-medium mt-1">Manage, review documents, and filter student submissions.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="bg-blue-50 text-blue-600 border border-blue-200 px-4 py-2 rounded-xl text-sm font-black uppercase tracking-widest inline-block shadow-sm">
                <?= count($applications) ?> Submissions
            </span>
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

    <?php if(isset($_SESSION['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-xl mb-6 text-sm font-bold flex items-center gap-3">
            ✅ <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm mb-10">
        <form method="GET" class="flex flex-wrap items-end gap-6">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">🔍 Search Program</label>
                <input type="search" name="program" list="programList" value="<?= htmlspecialchars($filter_program) ?>" placeholder="Type to search e.g. Info..." class="w-full bg-slate-50 border border-slate-200 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 shadow-inner">
                <datalist id="programList">
                    <?php foreach($all_programs as $p): ?>
                        <option value="<?= htmlspecialchars($p['ProgramName']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="flex-1 min-w-[200px]">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">🔍 Search Scholarship</label>
                <input type="search" name="scholarship" list="scholarshipList" value="<?= htmlspecialchars($filter_scholarship) ?>" placeholder="Type to search scholarship name..." class="w-full bg-slate-50 border border-slate-200 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 shadow-inner">
                <datalist id="scholarshipList">
                    <?php foreach($all_scholarships as $s): ?>
                        <option value="<?= htmlspecialchars($s['Name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="flex-1 min-w-[150px]">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Status</label>
                <select name="status" class="w-full bg-slate-50 border border-slate-200 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 cursor-pointer shadow-inner">
                    <option value="">Any Status</option>
                    <option value="Submitted" <?= $filter_status == 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                    <option value="Under Review" <?= $filter_status == 'Under Review' ? 'selected' : '' ?>>Under Review</option>
                    <option value="For Resubmission" <?= $filter_status == 'For Resubmission' ? 'selected' : '' ?>>For Resubmission</option>
                    <option value="Shortlisted" <?= $filter_status == 'Shortlisted' ? 'selected' : '' ?>>Shortlisted</option>
                    <option value="Approved" <?= $filter_status == 'Approved' ? 'selected' : '' ?>>Approved</option>
                </select>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="bg-slate-900 text-white px-8 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 active:scale-95">Apply Filters</button>
                <a href="applications.php" class="bg-slate-100 text-slate-900 px-4 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-slate-200 transition-all flex items-center active:scale-95">Reset</a>
            </div>
        </form>
    </div>

    <div id="applicationsGrid" class="flex flex-col gap-3">
        <?php if(empty($applications)): ?>
            <div class="col-span-full bg-white p-10 lg:p-20 rounded-[2rem] border border-slate-200 text-center shadow-sm">
                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4 grayscale opacity-50">📂</div>
                <h3 class="text-lg font-black text-slate-900 mb-1">No Applications Found</h3>
                <p class="text-slate-500 font-medium text-sm">No student applications match your current filters.</p>
            </div>
        <?php else: ?>
            <?php foreach($applications as $app): ?>
                <?php
                    $status_color = match($app['Status']) { 'Approved' => 'bg-green-500', 'Shortlisted' => 'bg-purple-500', 'Rejected' => 'bg-red-500', default => 'bg-blue-500' };
                    $status_badge = match($app['Status']) { 'Approved' => 'bg-green-50 text-green-600 border-green-200', 'Shortlisted' => 'bg-purple-50 text-purple-600 border-purple-200', 'Rejected' => 'bg-red-50 text-red-600 border-red-200', default => 'bg-blue-50 text-blue-600 border-blue-200' };
                ?>
                <div class="application-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-5 group relative overflow-hidden">
                    
                    <div class="absolute top-0 left-0 w-1 h-full <?= $status_color ?>"></div>

                    <div class="flex-1 min-w-0 pr-4 lg:pr-8 pl-3 w-full lg:w-auto">
                        <div class="flex items-center gap-3 mb-1.5 flex-wrap">
                            <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-slate-200 shrink-0">
                                APP-<?= str_pad($app['ApplicationID'], 4, '0', STR_PAD_LEFT) ?>
                            </span>
                            <h4 class="text-base font-black text-slate-900 truncate">
                                <?= htmlspecialchars($app['FirstName'].' '.$app['LastName']) ?>
                            </h4>
                            <span class="text-xs font-black text-blue-600 uppercase tracking-widest truncate">
                                <?= htmlspecialchars($app['scholarship_name']) ?>
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-xs font-medium text-slate-500 truncate mb-2 lg:mb-0">
                            <span class="text-slate-700 font-bold">ID: <?= htmlspecialchars($app['StudentID_Num']) ?></span>
                            <span class="hidden md:inline">•</span>
                            <span class="truncate hidden md:inline"><?= htmlspecialchars($app['Major']) ?></span>
                            <span class="hidden md:inline">•</span>
                            <span class="truncate hidden md:inline">Applied: <?= date('M d, Y', strtotime($app['DateSubmitted'])) ?></span>
                        </div>
                    </div>

                    <div class="hidden md:flex items-center justify-center px-6 border-x border-slate-100 shrink-0 min-w-[140px]">
                        <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border <?= $status_badge ?>">
                            <?= $app['Status'] ?>
                        </span>
                    </div>

                    <div class="mt-4 lg:mt-0 flex items-center justify-between lg:justify-end w-full lg:w-auto shrink-0 gap-3 pl-0 lg:pl-6">
                        <button type="button" onclick="openDocsModal(<?= $app['ApplicationID'] ?>, '<?= htmlspecialchars(addslashes($app['FirstName'].' '.$app['LastName'])) ?>')" class="bg-blue-50 text-blue-600 px-4 py-2 rounded-xl text-[11px] font-black shadow-sm border border-blue-100 hover:bg-blue-600 hover:text-white transition-all uppercase tracking-widest shrink-0">
                            Review 📄
                        </button>

                        <?php if (!$app['IsInternal'] && $app['Status'] !== 'Approved'): ?>
                            <!-- Externally funded: only the provider can change the status -->
                            <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-[10px] font-black uppercase tracking-widest max-w-[220px]" title="Externally funded — decided by <?= htmlspecialchars($app['ProviderName']) ?>">
                                <i class="fas fa-building"></i> <span class="truncate">Decided by <?= htmlspecialchars($app['ProviderName']) ?></span>
                            </span>
                        <?php elseif ($app['Status'] === 'Shortlisted'): ?>
                            <a href="shortlist.php" class="bg-purple-600 text-white px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-widest shadow-md hover:bg-purple-700 transition-all shrink-0">Review 🏆</a>
                        <?php elseif ($app['Status'] === 'Approved'): ?>
                            <a href="scholars.php" class="bg-green-600 text-white px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-widest shadow-md hover:bg-green-700 transition-all shrink-0">Scholar 🎓</a>
                        <?php else: ?>
                            <form action="../actions/process_crud.php" method="POST" class="flex items-center justify-center gap-2 m-0 w-full">
                                <input type="hidden" name="module" value="applications">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $app['ApplicationID'] ?>">
                                <select name="status" class="bg-slate-50 border border-slate-100 rounded-xl px-2 py-1.5 text-[11px] font-black uppercase text-slate-600 outline-none focus:ring-2 focus:ring-green-500/20 cursor-pointer">
                                    <option value="Submitted" <?= $app['Status']=='Submitted'?'selected':'' ?>>Submitted</option>
                                    <option value="Under Review" <?= $app['Status']=='Under Review'?'selected':'' ?>>Review</option>
                                    <option value="For Resubmission" <?= $app['Status']=='For Resubmission'?'selected':'' ?>>Request Corrections</option>
                                    <option value="Rejected" <?= $app['Status']=='Rejected'?'selected':'' ?>>Reject</option>
                                    <option value="Disqualified" <?= $app['Status']=='Disqualified'?'selected':'' ?>>Disqualify</option>
                                </select>
                                <button type="submit" class="bg-slate-900 text-white px-3 py-1.5 rounded-xl text-[11px] font-black shadow-md hover:bg-green-600 transition-all uppercase tracking-widest shrink-0">SAVE</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="flex flex-col items-center justify-center mt-12 gap-4 border-t border-slate-200 pt-8 pb-10">
        <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
        <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2">
            Showing 1 to X of X Applications
        </div>
    </div>
</main>

<div id="docsModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9998] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-[95%] sm:max-w-6xl rounded-[1.5rem] lg:rounded-[2.5rem] shadow-2xl overflow-hidden">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50">
            <div>
                <h3 class="text-xl lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Application Documents</h3>
                <p class="text-[14px] text-slate-500 font-bold mt-1 uppercase tracking-widest">Viewing files & answers for: <span id="docs-student-name" class="text-blue-600">Student</span></p>
            </div>
            
            <button onclick="closeDocsModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <div class="p-6 lg:p-8 max-h-[75vh] overflow-y-auto bg-slate-50/30 custom-scrollbar">
            <!-- Dynamic Answers Area -->
            <div id="answersContainer" class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-4"></div>
            
            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-4">Attached Documents</h3>
            <div id="docsContainer" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"></div>
        </div>
    </div>
</div>

<div id="viewerModal" class="fixed inset-0 z-[9999] bg-slate-900/80 backdrop-blur-md hidden items-center justify-center p-4 md:p-10">
    <div class="bg-white w-full max-w-5xl h-full max-h-[90vh] rounded-[1.5rem] lg:rounded-[2rem] shadow-2xl relative flex flex-col overflow-hidden">
        <div class="flex justify-between items-center p-4 lg:p-6 border-b border-slate-100 bg-slate-50 relative z-20">
            <div>
                <h3 id="viewerDocTitle" class="text-lg lg:text-xl font-black text-slate-900 uppercase tracking-tight">Document Viewer</h3>
            </div>
            <button onclick="closeViewerModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all z-10 shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <div class="flex-1 bg-slate-200 p-4 overflow-hidden relative flex items-center justify-center">
            <img id="viewerImage" src="" class="hidden max-w-full max-h-[80vh] rounded-xl shadow-2xl bg-white select-none">
            <iframe id="viewerIframe" src="" class="hidden w-full h-[70vh] rounded-xl border border-slate-300 shadow-sm bg-white relative z-10" frameborder="0"></iframe>
        </div>
    </div>
</div>

<script>
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage')?.value) || 10;
    const cards = Array.from(document.querySelectorAll('.application-card'));
    const totalItems = cards.length;

    function renderPagination() {
        if (totalItems === 0) return;
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        cards.forEach(card => card.style.display = 'none');
        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        cards.slice(start, end).forEach(card => card.style.display = 'flex');

        let btnHtml = '';
        btnHtml += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center bg-white border border-slate-200 hover:bg-slate-100">&larr;</button>`;
        btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center bg-white border border-slate-200 hover:bg-slate-100">&rarr;</button>`;
        document.getElementById('paginationControls').innerHTML = btnHtml;
    }

    function changePage(page) { currentPage = page; renderPagination(); }
    function changeItemsPerPage() { itemsPerPage = parseInt(document.getElementById('itemsPerPage').value); currentPage = 1; renderPagination(); }
    document.addEventListener('DOMContentLoaded', renderPagination);

    // --- MODAL LOGIC FOR DOCS & ANSWERS ---
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
        document.getElementById('viewerDocTitle').innerText = docType;
        const rootPath = `../${fileUrl.replace('../', '')}`;
        const ext = fileUrl.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            document.getElementById('viewerIframe').classList.add('hidden');
            document.getElementById('viewerImage').classList.remove('hidden');
            document.getElementById('viewerImage').src = rootPath; 
        } else {
            document.getElementById('viewerImage').classList.add('hidden');
            document.getElementById('viewerIframe').classList.remove('hidden');
            document.getElementById('viewerIframe').src = rootPath; 
        }
        document.getElementById('viewerModal').classList.add('modal-active');
    }

    function closeViewerModal() {
        document.getElementById('viewerModal').classList.remove('modal-active');
        setTimeout(() => { document.getElementById('viewerIframe').src = ""; document.getElementById('viewerImage').src = ""; }, 200); 
    }
</script>
</main>
<?php include '../includes/footer.php'; ?>
