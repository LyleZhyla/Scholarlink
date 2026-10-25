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

// 🔐 4. AUDIT LOG GATE (password re-check before viewing)
require_once '../includes/audit_gate.php';
requireAuditUnlock();

include '../includes/db_connect.php';
include '../includes/header.php'; 
include '../includes/super_sidebar.php'; 

// Fetch the latest 500 logs for client-side predictive filtering
$query = "
    SELECT l.*, u.FirstName, u.LastName, u.Role 
    FROM audit_log l 
    LEFT JOIN users u ON l.UserID = u.UserID 
    ORDER BY l.ActionDate DESC LIMIT 500
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $logs = $stmt->fetchAll();

    $users_stmt = $pdo->query("SELECT UserID, FirstName, LastName FROM users ORDER BY FirstName ASC");
    $all_users = $users_stmt->fetchAll();

    $actions_stmt = $pdo->query("SELECT DISTINCT ActionPerformed FROM audit_log");
    $db_actions = $actions_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $expected_actions = [
        'Account Created', 'Password Reset', 'User Active', 'User Archived', 
        'Security Update', 'System Reset', 'Auto Logout', 'Application Submitted', 
        'Document Verified', 'Document Rejected', 'Application Scored', 
        'Application Approved', 'Application Rejected'
    ];
    
    $all_actions = array_unique(array_merge($expected_actions, $db_actions));
    sort($all_actions);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    
    <header class="flex flex-col sm:flex-row sm:justify-between sm:items-end gap-4 mb-10">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Audit Logs</h2>
            <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Track and monitor all system activities and administrative actions.</p>
        </div>
        
        <div class="flex items-center gap-3">
            <span class="text-[12px] font-black text-slate-400 uppercase tracking-widest hidden sm:inline">
                🔓 Unlocked until <?= date('h:i A', $_SESSION['audit_unlocked_until']) ?>
            </span>
            <a href="audit_unlock.php?action=lock" class="bg-white text-slate-600 border border-slate-200 px-5 py-2.5 rounded-xl text-[14px] font-black uppercase tracking-widest hover:bg-red-600 hover:text-white hover:border-red-600 transition-all shadow-sm flex items-center gap-2">
                🔒 Lock
            </a>
            <a href="export_logs_pdf.php" target="_blank" class="bg-white text-slate-600 border border-slate-200 px-5 py-2.5 rounded-xl text-[14px] font-black uppercase tracking-widest hover:bg-slate-900 hover:text-white hover:border-slate-900 transition-all shadow-sm flex items-center gap-2 group">
                <span class="opacity-50 group-hover:opacity-100 transition-opacity">📄</span> Generate PDF Report
            </a>
        </div>
    </header>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="bg-green-50 text-green-700 p-4 rounded-2xl mb-6 font-bold border border-green-200 text-sm flex items-center gap-3 shadow-sm">
            <span class="text-lg">✅</span> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="bg-red-50 text-red-600 p-4 rounded-2xl mb-6 font-bold border border-red-100 text-sm flex items-center gap-3 shadow-sm">
            <span class="text-lg">⚠️</span> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm mb-8">
        <div class="flex flex-col gap-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">User</label>
                    <input type="text" id="filterUser" list="usersList" oninput="filterLogs()" placeholder="Type to search user..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-text placeholder:font-medium">
                    <datalist id="usersList">
                        <?php foreach($all_users as $u): ?>
                            <option value="<?= htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']) ?>"></option>
                        <?php endforeach; ?>
                        <option value="System / Unknown"></option>
                    </datalist>
                </div>
                
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Action</label>
                    <input type="text" id="filterAction" list="actionsList" oninput="filterLogs()" placeholder="Type to search action..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-text placeholder:font-medium">
                    <datalist id="actionsList">
                        <?php foreach($all_actions as $act): ?>
                            <option value="<?= htmlspecialchars($act) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div><label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Month</label><input type="month" id="filterMonth" onchange="filterLogs()" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-pointer"></div>
                <div><label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Specific Date</label><input type="date" id="filterDate" onchange="filterLogs()" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-pointer"></div>
                <div><label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Start Time</label><input type="time" id="filterTimeStart" onchange="filterLogs()" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-pointer"></div>
                <div><label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">End Time</label><input type="time" id="filterTimeEnd" onchange="filterLogs()" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-pointer"></div>
            </div>
            <div class="flex justify-end mt-2 pt-4 border-t border-slate-50">
                <button type="button" onclick="clearFilters()" class="bg-black text-white px-6 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-slate-800 transition-all shadow-sm active:scale-95 flex items-center justify-center">Reset & Show All</button>
            </div>
        </div>
    </div>

    <div class="flex justify-end mb-6" id="logContainer">
        <div class="flex items-center gap-3 bg-white px-4 py-2.5 rounded-2xl border border-slate-200 shadow-sm shrink-0">
            <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest hidden sm:inline">Show:</span>
            <select id="itemsPerPage" onchange="changeItemsPerPage()" class="bg-slate-50 border border-slate-100 text-slate-900 text-sm font-bold rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 block px-3 py-1.5 outline-none cursor-pointer">
                <option value="10" selected>10 items</option>
                <option value="25">25 items</option>
                <option value="50">50 items</option>
                <option value="999999">All items</option>
            </select>
        </div>
    </div>

    <form action="../actions/process_crud.php" method="POST" id="batchDeleteForm" onsubmit="return confirm('Are you sure you want to delete the selected logs? This cannot be undone.');">
        <input type="hidden" name="module" value="logs">
        <input type="hidden" name="action" value="delete_batch">

        <div class="bg-slate-900 p-4 lg:p-5 rounded-[1.5rem] flex items-center justify-between mb-4 shadow-md sticky top-[5.5rem] z-40 backdrop-blur-md bg-slate-900/90 border border-slate-800">
            <div class="flex items-center gap-3 px-2">
                <input type="checkbox" id="selectAll" class="w-4 h-4 rounded border-slate-700 bg-slate-800 text-blue-500 focus:ring-blue-500 cursor-pointer">
                <label for="selectAll" class="text-[12px] font-black text-slate-300 uppercase tracking-widest cursor-pointer select-none">Select Visible</label>
            </div>
            
            <div class="flex items-center gap-4">
                <span class="hidden sm:flex items-center gap-2 text-[10px] font-black text-slate-500 uppercase tracking-widest">
                    Live Feed <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span>
                </span>
                <button type="submit" id="deleteBtn" disabled class="bg-red-500 text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-red-600 transition-all disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:bg-red-500 shadow-md">
                    Delete Selected
                </button>
            </div>
        </div>
        
        <div id="logsGrid" class="flex flex-col gap-2.5">
            <?php foreach($logs as $log): 
                $user_fullname = $log['FirstName'] ? $log['FirstName'] . ' ' . $log['LastName'] : 'System / Unknown';
            ?>
            <div class="log-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-4 relative overflow-hidden group" 
                 data-user-name="<?= strtolower(htmlspecialchars($user_fullname)) ?>"
                 data-action="<?= strtolower(htmlspecialchars($log['ActionPerformed'])) ?>"
                 data-month="<?= date('Y-m', strtotime($log['ActionDate'])) ?>"
                 data-date="<?= date('Y-m-d', strtotime($log['ActionDate'])) ?>"
                 data-time="<?= date('H:i', strtotime($log['ActionDate'])) ?>">
                
                <div class="absolute top-0 left-0 w-1 h-full bg-blue-500"></div>
                
                <div class="flex gap-4 items-center flex-1 min-w-0 pl-3 w-full lg:w-auto">
                    <input type="checkbox" name="log_ids[]" value="<?= $log['AuditID'] ?>" class="log-checkbox w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer shrink-0">
                    
                    <div class="shrink-0 w-24 lg:w-28 border-r border-slate-100 pr-3 lg:pr-4 hidden sm:block">
                        <p class="text-[11px] lg:text-[12px] font-black text-slate-900 uppercase tracking-widest"><?= date('M d, Y', strtotime($log['ActionDate'])) ?></p>
                        <p class="text-[10px] font-black text-slate-500 font-mono tracking-wider mt-0.5"><?= date('h:i:s A', strtotime($log['ActionDate'])) ?></p>
                    </div>
                    
                    <div class="flex-1 min-w-0 pr-2">
                        <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                            <span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded-md font-black text-[9px] uppercase tracking-widest border border-blue-100 shrink-0">
                                <?= htmlspecialchars($log['ActionPerformed']) ?>
                            </span>
                            <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest sm:hidden"><span class="mr-1">•</span> <?= date('M d, y h:i A', strtotime($log['ActionDate'])) ?></span>
                        </div>
                        <p class="text-sm lg:text-[15px] font-bold text-slate-800 leading-tight truncate" title="<?= htmlspecialchars($log['Description']) ?>">
                            <?= htmlspecialchars($log['Description']) ?>
                        </p>
                    </div>
                </div>
                
                <div class="flex items-center justify-between w-full lg:w-auto shrink-0 mt-3 lg:mt-0 border-t lg:border-t-0 lg:border-l border-slate-100 pt-3 lg:pt-0 lg:pl-5 pl-10 sm:pl-11">
                    <div class="text-left lg:text-right w-40 flex-1 lg:flex-none">
                        <p class="text-[13px] font-black text-slate-900 truncate">
                            <?= htmlspecialchars($user_fullname) ?>
                        </p>
                        <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mt-0.5">
                            <?= htmlspecialchars($log['Role'] ?? 'Automated') ?>
                        </p>
                    </div>
                    
                    <button type="button" onclick="deleteSingleLog(<?= $log['AuditID'] ?>)" class="ml-4 lg:opacity-0 lg:group-hover:opacity-100 text-slate-400 hover:text-red-500 hover:bg-red-50 p-2 rounded-xl transition-all shadow-sm active:scale-95" title="Delete this log">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div id="noResultsMsg" class="hidden p-20 text-center bg-white rounded-[2rem] border border-slate-200">
                <div class="text-4xl mb-4 grayscale opacity-30">📜</div>
                <p class="text-slate-500 font-bold text-sm">No activity logs found matching your specific filters.</p>
            </div>

            <?php if(empty($logs)): ?>
                <div class="p-20 text-center bg-white rounded-[2rem] border border-slate-200">
                    <div class="text-4xl mb-4 grayscale opacity-30">📂</div>
                    <p class="text-slate-500 font-bold text-sm">No logs exist in the database yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="flex flex-col items-center justify-center mt-10 gap-4 border-t border-slate-200 pt-8 mb-6">
            <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
            <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2">
                Showing 1 to X of X Logs
            </div>
        </div>
    </form>
    
    <form id="singleDeleteForm" action="../actions/process_crud.php" method="POST" class="hidden">
        <input type="hidden" name="module" value="logs">
        <input type="hidden" name="action" value="delete_single">
        <input type="hidden" name="log_id" id="singleDeleteId" value="">
    </form>

</main>

<script>
    // ========================================================
    // ✨ MASTER PREDICTIVE SEARCH & PAGINATION ENGINE
    // ========================================================
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage').value) || 10;
    const allCards = Array.from(document.querySelectorAll('.log-card'));
    let filteredCards = [...allCards]; 

    function clearFilters() {
        document.getElementById('filterUser').value = '';
        document.getElementById('filterAction').value = '';
        document.getElementById('filterMonth').value = '';
        document.getElementById('filterDate').value = '';
        document.getElementById('filterTimeStart').value = '';
        document.getElementById('filterTimeEnd').value = '';
        filterLogs();
    }

    function filterLogs() {
        const fUser = document.getElementById('filterUser').value.toLowerCase().trim();
        const fAction = document.getElementById('filterAction').value.toLowerCase().trim();
        const fMonth = document.getElementById('filterMonth').value;
        const fDate = document.getElementById('filterDate').value;
        const fTimeStart = document.getElementById('filterTimeStart').value;
        const fTimeEnd = document.getElementById('filterTimeEnd').value;

        filteredCards = allCards.filter(card => {
            const cardUserName = card.getAttribute('data-user-name');
            const cardAction = card.getAttribute('data-action');
            const cardMonth = card.getAttribute('data-month');
            const cardDate = card.getAttribute('data-date');
            const cardTime = card.getAttribute('data-time');

            let match = true;

            // Checking if the typed string exists in the user's name or action
            if(fUser !== "" && !cardUserName.includes(fUser)) match = false;
            if(fAction !== "" && !cardAction.includes(fAction)) match = false;
            if(fMonth !== "" && cardMonth !== fMonth) match = false;
            if(fDate !== "" && cardDate !== fDate) match = false;
            if(fTimeStart !== "" && cardTime < fTimeStart) match = false;
            if(fTimeEnd !== "" && cardTime > fTimeEnd) match = false;

            return match;
        });

        currentPage = 1; 
        renderPagination();
        updateDeleteButton(); 
    }

    function renderPagination() {
        const totalItems = filteredCards.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;
        
        allCards.forEach(card => {
            card.style.display = 'none';
            card.querySelector('.log-checkbox').checked = false; 
        });

        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        filteredCards.slice(start, end).forEach(card => card.style.display = 'flex');

        const pageInfo = document.getElementById('pageInfo');
        const noResultsMsg = document.getElementById('noResultsMsg');

        if (pageInfo) {
            if(totalItems === 0) {
                pageInfo.innerText = "No logs match your predictive filters.";
                noResultsMsg.classList.remove('hidden');
            } else {
                pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Logs`;
                noResultsMsg.classList.add('hidden');
            }
        }

        let btnHtml = '';
        if(totalItems > 0) {
            btnHtml += `<button type="button" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm' }">&larr;</button>`;
            
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, currentPage + 1);

            if (currentPage === 1) endPage = Math.min(totalPages, 3);
            else if (currentPage === totalPages) startPage = Math.max(1, totalPages - 2);

            if (startPage > 1) {
                btnHtml += `<button type="button" onclick="changePage(1)" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm">1</button>`;
                if (startPage > 2) btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
            }

            for(let i = startPage; i <= endPage; i++) {
                btnHtml += `<button type="button" onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-blue-600 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm'}">${i}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
                btnHtml += `<button type="button" onclick="changePage(${totalPages})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm">${totalPages}</button>`;
            }
            
            btnHtml += `<button type="button" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm' }">&rarr;</button>`;
        }

        const paginationControls = document.getElementById('paginationControls');
        if(paginationControls) paginationControls.innerHTML = btnHtml;

        document.getElementById('selectAll').checked = false;
        updateDeleteButton();
    }

    function changePage(page) {
        const totalPages = Math.ceil(filteredCards.length / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
        document.getElementById('logContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function changeItemsPerPage() {
        itemsPerPage = parseInt(document.getElementById('itemsPerPage').value);
        currentPage = 1;
        renderPagination();
    }

    document.addEventListener('DOMContentLoaded', renderPagination);

    // ========================================================
    // BATCH DELETE LOGIC
    // ========================================================
    const selectAllBtn = document.getElementById('selectAll');
    const deleteBtn = document.getElementById('deleteBtn');

    function getVisibleCheckboxes() {
        return Array.from(document.querySelectorAll('.log-card[style*="display: flex"] .log-checkbox'));
    }

    function updateDeleteButton() {
        const visibleCheckboxes = getVisibleCheckboxes();
        const anyChecked = visibleCheckboxes.some(cb => cb.checked);
        deleteBtn.disabled = !anyChecked;
    }

    selectAllBtn.addEventListener('change', function() {
        const visibleCheckboxes = getVisibleCheckboxes();
        visibleCheckboxes.forEach(cb => cb.checked = this.checked);
        updateDeleteButton();
    });

    document.getElementById('logsGrid').addEventListener('change', function(e) {
        if(e.target.classList.contains('log-checkbox')) {
            const visibleCheckboxes = getVisibleCheckboxes();
            if (!e.target.checked) selectAllBtn.checked = false;
            if (visibleCheckboxes.length > 0 && visibleCheckboxes.every(c => c.checked)) selectAllBtn.checked = true;
            updateDeleteButton();
        }
    });

    function deleteSingleLog(id) {
        if(confirm('Are you sure you want to delete this specific log entry?')) {
            document.getElementById('singleDeleteId').value = id;
            document.getElementById('singleDeleteForm').submit();
        }
    }
</script>

<?php include '../includes/footer.php'; ?>