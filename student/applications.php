<?php 
session_start();

// ✨ Session manager MUST be loaded before any HTML is output!
include '../includes/session_manager.php'; 

// 🛑 THE BACK BUTTON KILLER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- THE SECURITY GATEKEEPER ---
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') { 
    header("Location: ../student_login.php"); 
    exit(); 
}
include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/student_sidebar.php'; 

$user_id = $_SESSION['user_id'];

try {
    // 1. Fetch Base Applications
    $stmt = $pdo->prepare("
        SELECT a.ApplicationID AS application_id, a.Status AS status, a.DateSubmitted AS application_date, 
               a.GPA AS gpa, sch.ScholarshipID, sch.Name AS scholarship_name, sch.MinimumGWA AS min_gpa,
               sch.ReleaseFrequency,
               u.Major AS course, u.YearLevel AS year_level
        FROM application a
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        JOIN users u ON a.UserID = u.UserID
        WHERE a.UserID = :uid
        ORDER BY a.DateSubmitted DESC
    ");
    $stmt->execute(['uid' => $user_id]);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch all dynamic details (Custom Answers & Documents) for each application
    $jsAppData = [];
    foreach ($applications as $app) {
        $aid = $app['application_id'];
        $sid = $app['ScholarshipID'];
        
        // Fetch Custom Fields & Answers
        $fields_stmt = $pdo->prepare("
            SELECT f.FieldID, f.FieldName, f.FieldType, a.AnswerText 
            FROM scholarship_custom_fields f
            LEFT JOIN application_custom_answers a ON f.FieldID = a.FieldID AND a.ApplicationID = ?
            WHERE f.ScholarshipID = ?
        ");
        $fields_stmt->execute([$aid, $sid]);
        $app['custom'] = $fields_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch Documents
        $docs_stmt = $pdo->prepare("
            SELECT dr.RequirementID, dr.DocumentName, d.SubmittedDocID, d.FilePath, d.VerificationStatus 
            FROM document_requirement dr 
            LEFT JOIN submitted_document d ON dr.RequirementID = d.RequirementID AND d.ApplicationID = ?
            WHERE dr.ScholarshipID = ?
        ");
        $docs_stmt->execute([$aid, $sid]);
        $docs = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✨ THE VAULT FIX: Retain exact paths (so Vault files look in /vault/ and normal in /documents/)
        foreach ($docs as &$doc) {
            if (!empty($doc['FilePath'])) {
                // Pinapanatili natin kung ano ang nasa database para walang ma-Not Found!
                $doc['SafePath'] = $doc['FilePath'];
            } else {
                $doc['SafePath'] = null;
            }
        }
        $app['docs'] = $docs;
        
        $app['ReleaseFrequency'] = $app['ReleaseFrequency'] ?? 'Per Semester';
        $jsAppData[$aid] = $app;
    }

}

catch (PDOException $e) { 
    die("<div style='padding: 30px; margin: 30px; background-color: #fee2e2; color: #991b1b; border-radius: 15px; font-family: sans-serif;'>
            <h2 style='font-size: 24px; margin-bottom: 10px;'>🚨 Mismatch Error sa Applications.php!</h2>
            <p><strong>System Message:</strong> " . $e->getMessage() . "</p>
            <p style='margin-top: 15px; font-size: 14px;'>I-copy ang error message na ito at i-send mo sa akin. Aayusin natin yung SQL Query natin!</p>
         </div>");
}

// Visual Helpers
function getStatusStyle($status) {
    return match($status) {
        'Submitted' => 'bg-blue-50 text-blue-600 border-blue-100',
        'Under Review', 'Verified' => 'bg-amber-50 text-amber-600 border-amber-100',
        'Shortlisted', 'Scored' => 'bg-purple-50 text-purple-600 border-purple-100',
        'Approved' => 'bg-green-50 text-green-600 border-green-100',
        'Rejected' => 'bg-red-50 text-red-600 border-red-100',
        default => 'bg-slate-50 text-slate-600 border-slate-100'
    };
}
function getProgressWidth($status) {
    return match($status) { 'Submitted' => 'w-1/4', 'Under Review', 'Verified' => 'w-2/4', 'Shortlisted', 'Scored' => 'w-3/4', 'Approved', 'Rejected' => 'w-full', default => 'w-1/4' };
}
function getProgressColor($status) {
    return match($status) { 'Approved' => 'bg-green-500', 'Rejected' => 'bg-red-500', 'Shortlisted', 'Scored' => 'bg-purple-500', 'Under Review', 'Verified' => 'bg-amber-500', default => 'bg-blue-500' };
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen">
    <header class="mb-8 lg:mb-10">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">My Applications</h2>
        <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Track your scholarship statuses and update recent submissions.</p>
    </header>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="bg-green-50 text-green-700 p-4 rounded-2xl mb-6 font-bold border border-green-200 text-sm flex items-center gap-3">
            ✅ <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <div class="space-y-4 lg:space-y-6">
        <?php foreach($applications as $app): ?>
            <div class="bg-white p-5 lg:p-8 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm flex flex-col group hover:border-blue-400 transition-all">
                
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 lg:gap-6 w-full">
                    <div class="flex-1 w-full">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 lg:w-10 lg:h-10 bg-slate-50 text-slate-900 rounded-xl flex items-center justify-center text-sm lg:text-lg font-bold shadow-inner">📄</div>
                            <span class="<?= getStatusStyle($app['status']) ?> px-3 py-1 rounded-full text-[9px] lg:text-[10px] font-black uppercase tracking-widest border">
                                <?= $app['status'] ?>
                            </span>
                        </div>
                        <h3 class="text-xl lg:text-2xl font-black text-slate-900 leading-tight"><?= htmlspecialchars($app['scholarship_name']) ?></h3>
                        <p class="text-[10px] lg:text-xs text-slate-400 font-bold uppercase tracking-widest mt-1">
                            Applied on: <?= date('M d, Y', strtotime($app['application_date'])) ?>
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 w-full md:w-auto justify-end shrink-0">
                        <?php if($app['status'] === 'Submitted'): ?>
                            <button type="button" onclick="openEditModal(<?= $app['application_id'] ?>)" class="flex-1 md:flex-none bg-slate-100 text-slate-600 border border-slate-200 px-4 lg:px-6 py-2.5 lg:py-3 rounded-xl font-black text-[10px] lg:text-xs uppercase tracking-widest hover:bg-slate-200 transition-all text-center active:scale-95">
                                Edit Form ✏️
                            </button>
                        <?php endif; ?>
                        
                        <button type="button" onclick="openTrackModal(<?= $app['application_id'] ?>)" class="flex-1 md:flex-none bg-slate-900 text-white px-4 lg:px-6 py-2.5 lg:py-3 rounded-xl font-black text-[10px] lg:text-xs uppercase tracking-widest hover:bg-blue-600 transition-all text-center shadow-md active:scale-95">
                            Track Status 🔎
                        </button>
                    </div>
                </div>

                <div class="mt-6 border-t border-slate-100 pt-5 w-full hidden sm:block">
                    <div class="flex justify-between text-[9px] lg:text-[10px] font-black uppercase tracking-widest mb-2">
                        <span class="text-blue-600">Submitted</span>
                        <span class="<?= in_array($app['status'], ['Under Review', 'Verified', 'Shortlisted', 'Scored', 'Approved', 'Rejected']) ? 'text-amber-600' : 'text-slate-300' ?>">Docs Checked</span>
                        <span class="<?= in_array($app['status'], ['Shortlisted', 'Scored', 'Approved', 'Rejected']) ? 'text-purple-600' : 'text-slate-300' ?>">Shortlisted</span>
                        <?php if($app['status'] === 'Rejected'): ?>
                            <span class="text-red-600">Rejected</span>
                        <?php else: ?>
                            <span class="<?= $app['status'] === 'Approved' ? 'text-green-600' : 'text-slate-300' ?>">Approved</span>
                        <?php endif; ?>
                    </div>
                    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden flex">
                        <div class="<?= getProgressWidth($app['status']) ?> <?= getProgressColor($app['status']) ?> h-full transition-all duration-1000"></div>
                    </div>
                </div>
            </div>
        <?php endforeach; if(empty($applications)): ?>
            <div class="p-10 lg:p-20 text-center bg-white border border-slate-200 rounded-[2rem]">
                <p class="text-slate-400 font-bold mb-4">You haven't applied for any scholarships yet.</p>
                <a href="programs.php" class="bg-slate-900 text-white px-6 py-3 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-blue-600 transition-all">Browse Programs</a>
            </div>
        <?php endif; ?>
    </div>
</main>

<div id="trackModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-[1.5rem] lg:rounded-[2.5rem] shadow-2xl w-full max-w-[95%] sm:max-w-4xl overflow-hidden max-h-[90vh] flex flex-col transform scale-95 transition-transform duration-300" id="trackModalContent">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
            <div>
                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Application Record</h3>
                <h2 id="trackModalTitle" class="text-lg lg:text-2xl font-black text-slate-900 leading-tight">Scholarship Name</h2>
            </div>
            <button type="button" onclick="closeTrackModal()" class="w-8 h-8 flex items-center justify-center bg-slate-200 text-slate-600 rounded-full hover:bg-red-50 hover:text-white transition-colors font-bold">&times;</button>
        </div>
        
        <div class="p-6 lg:p-10 overflow-y-auto custom-scrollbar">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6 lg:gap-8 mb-8">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Current Status</p>
                    <span id="trackModalStatus" class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border bg-slate-900 text-white">Submitted</span>
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Declared GWA</p>
                    <p id="trackModalGwa" class="font-black text-blue-600 text-lg lg:text-xl"></p>
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Release Freq.</p>
                    <p id="trackModalRelease" class="font-bold text-slate-900 text-sm lg:text-base"></p>
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Submitted On</p>
                    <p id="trackModalDate" class="font-bold text-slate-900 text-sm lg:text-base"></p>
                </div>
            </div>

            <div class="border-t border-slate-100 pt-8 mb-8">
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-4">Application Form Answers</h3>
                <div id="trackModalCustomAnswers" class="grid grid-cols-1 md:grid-cols-2 gap-4"></div>
            </div>

            <div class="border-t border-slate-100 pt-8">
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-4">Attached Documents</h3>
                <div id="trackModalDocs" class="grid grid-cols-1 sm:grid-cols-2 gap-4"></div>
            </div>
        </div>
    </div>
</div>

<div id="editModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-[1.5rem] lg:rounded-[2.5rem] shadow-2xl w-full max-w-[95%] sm:max-w-4xl overflow-hidden max-h-[90vh] flex flex-col transform scale-95 transition-transform duration-300" id="editModalContent">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
            <div>
                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Modify Application</h3>
                <h2 id="editModalTitle" class="text-lg lg:text-2xl font-black text-slate-900 leading-tight">Scholarship Name</h2>
            </div>
            <button type="button" onclick="closeEditModal()" class="w-8 h-8 flex items-center justify-center bg-slate-200 text-slate-600 rounded-full hover:bg-red-500 hover:text-white transition-colors font-bold">&times;</button>
        </div>
        
        <form action="../actions/process_crud.php" method="POST" enctype="multipart/form-data" class="p-6 lg:p-10 overflow-y-auto custom-scrollbar flex-1">
            <input type="hidden" name="module" value="student_apply">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="application_id" id="editModalAppId" value="">

            <div class="mb-8">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Update GWA / GPA</label>
                <input type="number" step="0.01" name="gpa" id="editModalGpa" required class="w-full md:w-1/3 p-4 bg-slate-50 border border-slate-200 rounded-xl font-bold outline-none focus:border-blue-500 transition-colors">
            </div>

            <div class="border-t border-slate-100 pt-8 mb-8">
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-4">Update Form Answers</h3>
                <div id="editModalCustomFields" class="grid grid-cols-1 md:grid-cols-2 gap-4 lg:gap-6"></div>
            </div>

            <div class="border-t border-slate-100 pt-8 mb-8">
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-4">Update Documents</h3>
                <div id="editModalDocs" class="space-y-4"></div>
            </div>

            <div class="flex gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeEditModal()" class="flex-1 bg-slate-100 text-slate-500 py-4 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                <button type="submit" class="flex-1 bg-green-600 text-white py-4 rounded-xl font-black text-xs uppercase tracking-widest shadow-xl hover:bg-green-700 active:scale-95 transition-all">Save Changes 💾</button>
            </div>
        </form>
    </div>
</div>

<div id="viewerModal" class="fixed inset-0 z-[10000] bg-slate-900/80 backdrop-blur-md hidden items-center justify-center p-4 md:p-10 opacity-0 transition-opacity duration-300">
    <div id="viewerModalContent" class="bg-white w-full max-w-5xl h-full max-h-[90vh] rounded-[1.5rem] lg:rounded-[2rem] shadow-2xl relative flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="flex justify-between items-center p-4 lg:p-6 border-b border-slate-100 bg-slate-50 relative z-20">
            <div>
                <h3 id="viewerDocTitle" class="text-lg lg:text-xl font-black text-slate-900 uppercase tracking-tight">Document Viewer</h3>
            </div>
            <button onclick="closeViewerModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all z-10 shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <div id="viewerCanvas" class="flex-1 bg-slate-200 p-4 overflow-hidden relative flex items-center justify-center">
            <img id="viewerImage" src="" class="hidden max-w-full max-h-[80vh] rounded-xl shadow-2xl bg-white select-none transition-transform duration-100">
            <iframe id="viewerIframe" src="" class="hidden w-full h-[70vh] rounded-xl border border-slate-300 shadow-sm bg-white relative z-10" frameborder="0"></iframe>
            
            <div id="zoomControls" class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-slate-900/90 backdrop-blur-md p-2 rounded-2xl flex items-center gap-2 z-50 hidden shadow-2xl border border-white/10">
                <button type="button" onclick="zoomImage(-0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all" title="Zoom Out"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"></path></svg></button>
                <button type="button" onclick="resetZoom()" class="px-4 h-10 bg-white/10 hover:bg-white/20 text-white font-black text-[14px] rounded-xl flex items-center justify-center transition-all uppercase tracking-widest">Reset</button>
                <button type="button" onclick="zoomImage(0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all" title="Zoom In"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"></path></svg></button>
            </div>
        </div>
    </div>
</div>

<script>
    // Load PHP generated data into JS!
    const appData = <?= json_encode($jsAppData) ?>;

    const trackModal = document.getElementById('trackModal');
    const trackModalContent = document.getElementById('trackModalContent');
    const editModal = document.getElementById('editModal');
    const editModalContent = document.getElementById('editModalContent');

    // --- MODAL: TRACK STATUS (VIEW) ---
    function openTrackModal(appId) {
        const data = appData[appId];
        
        document.getElementById('trackModalTitle').innerText = data.scholarship_name;
        document.getElementById('trackModalStatus').innerText = data.status;
        document.getElementById('trackModalGwa').innerText = parseFloat(data.gpa).toFixed(2);
        document.getElementById('trackModalRelease').innerText = data.ReleaseFrequency || 'Per Semester';
        
        const dateObj = new Date(data.application_date);
        document.getElementById('trackModalDate').innerText = dateObj.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute:'2-digit' });

        // Render Custom Answers
        const answersContainer = document.getElementById('trackModalCustomAnswers');
        answersContainer.innerHTML = '';
        if(data.custom && data.custom.length > 0) {
             data.custom.forEach(f => {
                 let safeAnswer = f.AnswerText ? f.AnswerText.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '<i>No answer provided</i>';
                 let html = `<div class="${f.FieldType === 'Textarea' ? 'col-span-full' : ''}">
                     <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">${f.FieldName}</p>
                     <div class="p-4 bg-slate-50 border border-slate-100 rounded-xl text-sm text-slate-700 font-medium ${f.FieldType === 'Textarea' ? 'whitespace-pre-wrap' : ''}">${safeAnswer}</div>
                 </div>`;
                 answersContainer.innerHTML += html;
             });
        } else {
             answersContainer.innerHTML = '<p class="col-span-full text-sm font-medium text-slate-400 italic">No custom questions for this scholarship.</p>';
        }

        // Render Documents
        const docsContainer = document.getElementById('trackModalDocs');
        docsContainer.innerHTML = '';
        if(data.docs && data.docs.length > 0) {
            data.docs.forEach(d => {
                 let statusColor = d.VerificationStatus === 'Verified' ? 'text-green-600' : (d.VerificationStatus === 'Rejected' ? 'text-red-500' : 'text-amber-500');
                 
                 let safePath = d.SafePath ? d.SafePath.replace(/'/g, "\\'") : '';
                 let safeName = d.DocumentName ? d.DocumentName.replace(/'/g, "\\'") : '';

                 // ✨ BULLETPROOF FIX: The viewer button passes exact vault or document path
                 let linkHtml = d.SafePath 
                    ? `<button type="button" onclick="openViewerModal('${safePath}', '${safeName}')" class="w-10 h-10 shrink-0 bg-white border border-slate-200 text-blue-600 rounded-xl flex items-center justify-center hover:bg-blue-600 hover:text-white transition-colors shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg></button>` 
                    : `<span class="text-[10px] font-black text-red-400 uppercase tracking-widest bg-red-50 px-2 py-1 rounded">Missing</span>`;
                 
                 let html = `<div class="p-4 bg-slate-50 border border-slate-100 rounded-xl flex items-center justify-between">
                     <div class="truncate pr-4">
                         <p class="text-xs font-black text-slate-800 uppercase tracking-widest mb-1 truncate">${d.DocumentName}</p>
                         <span class="text-[10px] font-bold ${statusColor} uppercase tracking-widest">Status: ${d.VerificationStatus || 'Pending'}</span>
                     </div>
                     ${linkHtml}
                 </div>`;
                 docsContainer.innerHTML += html;
            });
        }

        trackModal.classList.remove('hidden');
        trackModal.classList.add('flex');
        setTimeout(() => { trackModal.classList.remove('opacity-0'); trackModalContent.classList.remove('scale-95'); }, 10);
    }

    function closeTrackModal() {
        trackModal.classList.add('opacity-0');
        trackModalContent.classList.add('scale-95');
        setTimeout(() => { trackModal.classList.add('hidden'); trackModal.classList.remove('flex'); }, 300);
    }

    // --- MODAL: EDIT APPLICATION ---
    function openEditModal(appId) {
        const data = appData[appId];
        
        document.getElementById('editModalAppId').value = appId;
        document.getElementById('editModalTitle').innerText = data.scholarship_name;
        document.getElementById('editModalGpa').value = data.gpa;
        
        const fieldsContainer = document.getElementById('editModalCustomFields');
        fieldsContainer.innerHTML = '';
        if(data.custom && data.custom.length > 0) {
            data.custom.forEach(f => {
                let inputHtml = '';
                let val = f.AnswerText ? f.AnswerText.replace(/"/g, '&quot;') : '';
                if(f.FieldType === 'Textarea') {
                    inputHtml = `<textarea name="custom_answers[${f.FieldID}]" rows="4" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium outline-none focus:border-blue-500">${val}</textarea>`;
                } else if (f.FieldType === 'Number') {
                    inputHtml = `<input type="number" step="any" name="custom_answers[${f.FieldID}]" value="${val}" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl font-bold outline-none focus:border-blue-500">`;
                } else if (f.FieldType === 'Date') {
                    inputHtml = `<input type="date" name="custom_answers[${f.FieldID}]" value="${val}" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl font-bold outline-none focus:border-blue-500 text-slate-700">`;
                } else {
                    inputHtml = `<input type="text" name="custom_answers[${f.FieldID}]" value="${val}" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl font-bold outline-none focus:border-blue-500">`;
                }
                
                fieldsContainer.innerHTML += `
                <div class="${f.FieldType === 'Textarea' ? 'col-span-full' : ''} space-y-2">
                    <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest block">${f.FieldName}</label>
                    ${inputHtml}
                </div>`;
            });
        } else {
            fieldsContainer.innerHTML = '<p class="col-span-full text-sm font-medium text-slate-400 italic">No custom questions for this scholarship.</p>';
        }
        
        const docsContainer = document.getElementById('editModalDocs');
        docsContainer.innerHTML = '';
        if(data.docs && data.docs.length > 0) {
            data.docs.forEach(d => {
                let statusText = d.SafePath 
                    ? '<p class="text-[10px] font-black text-green-600 uppercase tracking-widest mt-1">Currently Uploaded</p>' 
                    : '<p class="text-[10px] font-black text-red-500 uppercase tracking-widest mt-1">Missing</p>';
                
                let html = `
                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 border border-slate-200 rounded-xl bg-slate-50 gap-4">
                    <div class="flex-1">
                        <p class="font-bold text-slate-900 text-sm">${d.DocumentName}</p>
                        ${statusText}
                    </div>
                    <div class="flex gap-2">
                        <input type="file" name="files[${d.RequirementID}]" accept=".pdf,.png,.jpg,.jpeg" class="text-xs font-bold text-slate-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-black file:bg-slate-200 file:text-slate-700 hover:file:bg-slate-300 cursor-pointer">
                    </div>
                </div>`;
                docsContainer.innerHTML += html;
            });
        }

        editModal.classList.remove('hidden');
        editModal.classList.add('flex');
        setTimeout(() => { editModal.classList.remove('opacity-0'); editModalContent.classList.remove('scale-95'); }, 10);
    }

    function closeEditModal() {
        editModal.classList.add('opacity-0');
        editModalContent.classList.add('scale-95');
        setTimeout(() => { editModal.classList.add('hidden'); editModal.classList.remove('flex'); }, 300);
    }

    // --- VIEWER MODAL LOGIC (ZOOM & PAN) ---
    let currentZoom = 1;
    let isDragging = false;
    let startX, startY, translateX = 0, translateY = 0;
    const imgElement = document.getElementById('viewerImage');
    const viewerCanvas = document.getElementById('viewerCanvas');

    function applyTransform() {
        imgElement.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`;
        if (currentZoom > 1) {
            imgElement.style.cursor = isDragging ? 'grabbing' : 'grab';
        } else {
            imgElement.style.cursor = 'default';
        }
    }

    function zoomImage(step) {
        currentZoom += step;
        if (currentZoom < 0.5) currentZoom = 0.5; 
        if (currentZoom > 5) currentZoom = 5;     
        applyTransform();
    }

    function resetZoom() {
        currentZoom = 1; translateX = 0; translateY = 0;
        applyTransform();
    }

    viewerCanvas.addEventListener('wheel', function(e) {
        if (!imgElement.classList.contains('hidden')) {
            e.preventDefault(); 
            let delta = e.deltaY > 0 ? -0.1 : 0.1; 
            zoomImage(delta);
        }
    }, { passive: false });

    imgElement.addEventListener('mousedown', (e) => {
        if (currentZoom > 1) {
            isDragging = true;
            startX = e.clientX - translateX;
            startY = e.clientY - translateY;
            applyTransform();
        }
    });

    window.addEventListener('mousemove', (e) => {
        if (isDragging && !imgElement.classList.contains('hidden')) {
            translateX = e.clientX - startX;
            translateY = e.clientY - startY;
            applyTransform();
        }
    });

    window.addEventListener('mouseup', () => {
        isDragging = false;
        applyTransform();
    });

    // ✨ BULLETPROOF PATH RESOLUTION IN VIEWER
    function openViewerModal(fileUrl, docType) {
        document.getElementById('viewerDocTitle').innerText = docType;
        resetZoom(); 
        
        // This ensures whether the path is from /vault/ or /documents/, it roots correctly!
        const cleanUrl = fileUrl.replace('../', '');
        const rootPath = `../${cleanUrl}`;
        
        const ext = fileUrl.split('.').pop().toLowerCase();
        const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (imageExtensions.includes(ext)) {
            document.getElementById('viewerIframe').classList.add('hidden');
            document.getElementById('viewerImage').classList.remove('hidden');
            document.getElementById('viewerImage').src = rootPath;
            document.getElementById('zoomControls').classList.remove('hidden'); 
        } else {
            document.getElementById('viewerImage').classList.add('hidden');
            document.getElementById('viewerIframe').classList.remove('hidden');
            document.getElementById('viewerIframe').src = rootPath;
            document.getElementById('zoomControls').classList.add('hidden'); 
        }
        
        const modal = document.getElementById('viewerModal');
        const content = document.getElementById('viewerModalContent');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => { modal.classList.remove('opacity-0'); content.classList.remove('scale-95'); }, 10);
    }

    function closeViewerModal() {
        const modal = document.getElementById('viewerModal');
        const content = document.getElementById('viewerModalContent');
        modal.classList.add('opacity-0');
        content.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.getElementById('viewerIframe').src = "";
            document.getElementById('viewerImage').src = "";
            resetZoom();
        }, 300); 
    }
</script>

<?php include '../includes/footer.php'; ?>