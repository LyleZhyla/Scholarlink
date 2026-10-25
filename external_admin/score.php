<?php
session_start();
include '../includes/session_manager.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'External_Admin') {
    header("Location: ../admin_login.php"); exit();
}

include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/external_sidebar.php'; 

$courses = $pdo->query("SELECT DISTINCT Major FROM users WHERE Major IS NOT NULL AND Major != ''")->fetchAll();
$scholarships = $pdo->query("SELECT ScholarshipID, Name FROM scholarship WHERE Status = 'Active' ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Merit Evaluation</h2>
            <p class="text-slate-500 text-md lg:text-base font-medium mt-1">Score applicants dynamically based on the scholarship's assigned criteria.</p>
        </div>
        
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
    </header>

    <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm mb-10">
        <div class="flex flex-wrap items-end gap-5">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[14px] font-black text-black uppercase tracking-widest mb-2">🔍 Search Applicant</label>
                <input type="search" id="live_search" onkeyup="liveScoreFilter()" placeholder="Name or ID..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-5 py-3.5 rounded-xl font-bold text-sm text-black outline-none focus:ring-2 focus:ring-green-500/20 shadow-inner transition-all">
            </div>
            
            <div class="w-36">
                <label class="block text-[14px] font-black text-black uppercase tracking-widest mb-2">🔍 Year</label>
                <select id="filter_year" onchange="liveScoreFilter()" class="w-full bg-slate-50 border border-slate-100 px-4 py-3.5 rounded-xl font-bold text-sm text-black cursor-pointer shadow-inner outline-none focus:ring-2 focus:ring-green-500/20">
                    <option value="">All</option>
                    <option value="1st Year">1st Year</option>
                    <option value="2nd Year">2nd Year</option>
                    <option value="3rd Year">3rd Year</option>
                    <option value="4th Year">4th Year</option>
                </select>
            </div>

            <div class="flex-1 min-w-[150px]">
                <label class="block text-[14px] font-black text-black uppercase tracking-widest mb-2">🔍 Search Program</label>
                <input type="search" id="filter_course" list="courseList" onchange="updateScholarshipAndFilter(this.value, 'score')" placeholder="Type program..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-5 py-3.5 rounded-xl font-bold text-sm text-black outline-none focus:ring-2 focus:ring-green-500/20 shadow-inner transition-all">
                <datalist id="courseList">
                    <option value="">All Programs</option>
                    <?php foreach($courses as $c): ?>
                        <option value="<?= htmlspecialchars($c['Major']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="flex-1 min-w-[200px]">
                <label class="block text-[14px] font-black text-black uppercase tracking-widest mb-2">🔍 Search Scholarship</label>
                <input type="search" id="filter_scholarship" list="scholarshipList" onchange="liveScoreFilter()" placeholder="Type scholarship..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-5 py-3.5 rounded-xl font-bold text-sm text-black outline-none focus:ring-2 focus:ring-green-500/20 shadow-inner transition-all">
                <datalist id="scholarshipList">
                    <option value="">All Scholarships</option>
                    <?php foreach($scholarships as $s): ?>
                        <option value="<?= $s['ScholarshipID'] ?>"><?= htmlspecialchars($s['Name']) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
    </div>

    <div id="scoreGrid" class="flex flex-col gap-3"></div>

    <div class="flex flex-col items-center justify-center mt-12 gap-4 border-t border-slate-200 pt-8 pb-10">
        <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
        <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2">
            Showing 1 to X of X Applicants
        </div>
    </div>

    <div id="scoreModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] hidden items-center justify-center p-4">
        <div class="bg-white w-full max-w-4xl rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <form action="../actions/process_crud.php" method="POST" class="flex flex-col h-full">
                <input type="hidden" name="module" value="applications">
                <input type="hidden" name="action" value="score">
                <input type="hidden" id="modalAppId" name="id">
                <input type="hidden" id="finalScoreInput" name="score">

                <div class="p-8 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Merit Rubric</h3>
                        <p id="modalStudentName" class="text-md text-blue-600 font-bold mt-1 uppercase tracking-widest">Name</p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-black text-green-600"><span id="displayTotal">0</span><span class="text-md text-slate-300">/100</span></p>
                    </div>
                </div>

                <div class="p-8 overflow-y-auto flex-1 space-y-6 custom-scrollbar">
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-blue-50 p-4 rounded-xl border border-blue-100 col-span-2 sm:col-span-1">
                            <p class="text-[9px] font-black text-blue-500 uppercase tracking-widest mb-1">Declared GWA</p>
                            <p class="font-black text-blue-700 text-lg" id="refGPA"></p>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-6">
                        <h4 class="text-[14px] font-black uppercase tracking-widest mb-4 text-slate-900">Applicant's Form Answers</h4>
                        <div id="modalCustomAnswers" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            </div>
                    </div>

                    <div class="border-t border-slate-100 pt-6">
                        <h4 class="text-[14px] font-black uppercase tracking-widest mb-4 text-slate-900">Scoring Breakdown</h4>
                        <div id="dynamicCriteriaContainer" class="space-y-3"></div>
                    </div>
                </div>

                <div class="p-8 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 shrink-0">
                    <button type="button" onclick="closeScoreModal()" class="px-6 py-3 font-black text-[14px] uppercase tracking-widest text-slate-500 hover:text-red-500 transition-colors">Cancel</button>
                    <button type="submit" id="submitBtn" disabled class="bg-slate-300 text-slate-500 px-10 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest transition-all">Submit Final Score</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    let scoringData = [];
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage')?.value) || 10;

    function renderPagination() {
        const totalItems = scoringData.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;
        const grid = document.getElementById('scoreGrid');
        grid.innerHTML = '';

        if(totalItems === 0) {
            grid.innerHTML = '<div class="col-span-full bg-white p-10 lg:p-20 rounded-[2rem] border border-slate-200 text-center shadow-sm"><div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4 grayscale opacity-50">🔍</div><h3 class="text-lg font-black text-slate-900">No Applicants Found</h3><p class="text-slate-500 text-sm mt-1">Adjust your filters to see more students.</p></div>';
            document.getElementById('paginationControls').innerHTML = '';
            document.getElementById('pageInfo').innerText = 'Showing 0 to 0 of 0 Applicants';
            return;
        }

        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        const pageData = scoringData.slice(start, end);

        pageData.forEach(app => {
            grid.insertAdjacentHTML('beforeend', `
                <div class="score-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-green-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-5 relative overflow-hidden group">
                    <div class="absolute top-0 left-0 w-1 h-full bg-green-500"></div>
                    
                    <div class="flex-1 min-w-0 pr-4 lg:pr-8 pl-3 w-full lg:w-auto">
                        <div class="flex items-center gap-3 mb-1.5 flex-wrap">
                            <span class="bg-green-50 text-green-700 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-green-200 shrink-0">Awaiting Score</span>
                            <h4 class="text-base font-black text-slate-900 truncate">${app.FirstName} ${app.LastName}</h4>
                        </div>
                        <div class="flex items-center gap-2 text-xs font-medium text-slate-500 truncate">
                            <span class="text-slate-700 font-bold">ID: ${app.StudentID_Num || 'N/A'}</span>
                            <span class="hidden md:inline">•</span>
                            <span class="truncate hidden md:inline">${app.YearLevel || 'N/A'}</span>
                        </div>
                    </div>

                    <div class="hidden md:flex items-center justify-center px-6 border-x border-slate-100 shrink-0 min-w-[200px]">
                        <span class="text-[11px] font-black text-blue-600 uppercase tracking-widest truncate">${app.scholarship_name}</span>
                    </div>

                    <div class="mt-4 lg:mt-0 flex items-center justify-end w-full lg:w-auto shrink-0 gap-3 pl-0 lg:pl-6">
                        <button onclick="triggerScoreModal(${app.ApplicationID})" class="bg-slate-900 text-white hover:bg-blue-600 px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all shadow-md active:scale-95 whitespace-nowrap">Evaluate ✍️</button>
                    </div>
                </div>
            `);
        });

        // Pagination Buttons
        let btnHtml = '';
        btnHtml += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&larr;</button>`;
        
        let startPage = Math.max(1, currentPage - 1);
        let endPage = Math.min(totalPages, currentPage + 1);

        if (currentPage === 1) endPage = Math.min(totalPages, 3);
        else if (currentPage === totalPages) startPage = Math.max(1, totalPages - 2);

        if (startPage > 1) {
            btnHtml += `<button onclick="changePage(1)" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100">1</button>`;
            if (startPage > 2) btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
        }

        for(let i = startPage; i <= endPage; i++) {
            btnHtml += `<button onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-green-600 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
            btnHtml += `<button onclick="changePage(${totalPages})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100">${totalPages}</button>`;
        }
        
        btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&rarr;</button>`;
        
        document.getElementById('paginationControls').innerHTML = btnHtml;
        document.getElementById('pageInfo').innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Applicants`;
    }

    function changePage(page) {
        const totalPages = Math.ceil(scoringData.length / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
        document.getElementById('scoreGrid').scrollIntoView({ behavior: 'smooth' });
    }

    function changeItemsPerPage() {
        const selector = document.getElementById('itemsPerPage');
        if(selector) {
            itemsPerPage = parseInt(selector.value);
            currentPage = 1;
            renderPagination();
        }
    }

    // Modal and Filter Logic
    function calculateTotal() {
        const inputs = document.querySelectorAll('.dynamic-score-input');
        let total = 0;
        inputs.forEach(input => { total += parseInt(input.value) || 0; });
        if (total > 100) total = 100; 

        document.getElementById('displayTotal').innerText = total;
        document.getElementById('finalScoreInput').value = total;
        
        const btn = document.getElementById('submitBtn');
        if (total > 0) { 
            btn.disabled = false; 
            btn.className = 'bg-slate-900 text-white px-10 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-green-600 shadow-xl transition-all active:scale-[0.98]'; 
        } else { 
            btn.disabled = true; 
            btn.className = 'bg-slate-300 text-slate-500 px-10 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest transition-all'; 
        }
    }

    function triggerScoreModal(appId) {
        const app = scoringData.find(a => a.ApplicationID == appId);
        if(!app) return;
        
        document.getElementById('modalAppId').value = app.ApplicationID;
        document.getElementById('modalStudentName').innerText = app.FirstName + ' ' + app.LastName;
        document.getElementById('refGPA').innerText = app.GPA || 'N/A';

        // ✨ RENDER DYNAMIC CUSTOM ANSWERS (Replacing Essay & Income)
        const ansContainer = document.getElementById('modalCustomAnswers');
        ansContainer.innerHTML = '';
        
        if (app.custom_answers && app.custom_answers.length > 0) {
            app.custom_answers.forEach(ans => {
                let safeAnswer = ans.AnswerText ? ans.AnswerText.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '<i>No answer provided</i>';
                let html = `<div class="${ans.FieldType === 'Textarea' ? 'col-span-full' : ''}">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">${ans.FieldName}</p>`;
                
                if (ans.FieldType === 'Textarea') {
                    html += `<div class="p-5 bg-slate-50 rounded-xl border border-slate-100 text-sm text-slate-700 leading-relaxed whitespace-pre-wrap font-medium">${safeAnswer}</div>`;
                } else {
                    html += `<p class="font-bold text-slate-900 text-sm p-4 bg-slate-50 border border-slate-100 rounded-xl">${safeAnswer}</p>`;
                }
                html += `</div>`;
                ansContainer.innerHTML += html;
            });
        } else {
            ansContainer.innerHTML = '<p class="text-sm font-medium text-slate-400 italic col-span-full">No custom form answers provided for this application.</p>';
        }

        // Render Dynamic Criteria Scoring
        const container = document.getElementById('dynamicCriteriaContainer');
        container.innerHTML = '';

        let criteria = [];
        if (app.CriteriaList) {
            criteria = app.CriteriaList.split('||').filter(c => c.trim() !== '');
        }

        if (criteria.length > 0) {
            criteria.forEach(crit => {
                container.innerHTML += `
                    <div class="bg-white p-4 border border-slate-200 rounded-xl flex items-center justify-between gap-4 hover:border-purple-300 transition-colors">
                        <label class="text-md font-black uppercase tracking-wide text-black flex-1">${crit}</label>
                        <div class="w-32 relative">
                            <input type="number" oninput="calculateTotal()" required min="0" max="100" placeholder="Score" class="dynamic-score-input w-full bg-slate-50 border border-slate-200 px-8 py-2 rounded-lg font-black text-black outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all text-center">
                        </div>
                    </div>`;
            });
        } else {
            container.innerHTML = `
                <div class="bg-white p-4 border border-slate-200 rounded-xl flex items-center justify-between gap-4 hover:border-purple-300 transition-colors">
                    <label class="text-md font-black uppercase tracking-wide text-black flex-1">Overall Merit Score</label>
                    <div class="w-32 relative">
                        <input type="number" oninput="calculateTotal()" required min="0" max="100" placeholder="Score" class="dynamic-score-input w-full bg-slate-50 border border-slate-200 px-8 py-2 rounded-lg font-black text-black outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all text-center">
                    </div>
                </div>`;
        }

        document.getElementById('displayTotal').innerText = '0';
        document.getElementById('finalScoreInput').value = '0';
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('submitBtn').className = 'bg-slate-300 text-slate-500 px-10 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest transition-all';
        document.getElementById('scoreModal').classList.remove('hidden');
        document.getElementById('scoreModal').classList.add('flex');
    }

    function closeScoreModal() { document.getElementById('scoreModal').classList.add('hidden'); }
    document.getElementById('scoreModal').addEventListener('click', function(e) { if(e.target === this) closeScoreModal(); });

    function liveScoreFilter() {
        const search = document.getElementById('live_search').value;
        const year = document.getElementById('filter_year').value;
        const course = document.getElementById('filter_course').value;
        const scholarship = document.getElementById('filter_scholarship').value;

        const params = new URLSearchParams({ search, year, course, scholarship_name: scholarship }).toString();
        fetch(`../actions/fetch_live_score.php?${params}`)
            .then(res => res.json())
            .then(data => {
                scoringData = data;
                currentPage = 1;
                renderPagination();
            });
    }

    function updateScholarshipAndFilter(major, type) {
        const schList = document.getElementById('scholarshipList');
        fetch(`../actions/get_scholarships_by_program.php?major=${encodeURIComponent(major)}`)
            .then(res => res.json())
            .then(data => {
                schList.innerHTML = '<option value="">All Scholarships</option>';
                data.forEach(sch => {
                    const opt = document.createElement('option');
                    opt.value = sch.Name; 
                    schList.appendChild(opt);
                });
                if(type === 'score') liveScoreFilter();
            });
    }

    window.onload = liveScoreFilter;
    </script>
</main>
<?php include '../includes/footer.php'; ?>