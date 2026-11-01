<?php
session_start();
include '../includes/session_manager.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') {
    session_destroy();
    header("Location: ../admin_login.php"); 
    exit();
}
include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/super_sidebar.php'; 

try {
    $programs = $pdo->query("SELECT * FROM scholarship ORDER BY ScholarshipID DESC")->fetchAll();
    
    // ✨ FETCH UNREAD NOTIFICATIONS FOR SUPER ADMIN
    $notif_stmt = $pdo->prepare("SELECT * FROM system_notifications WHERE RecipientID = ? AND IsRead = 0 ORDER BY DateCreated DESC");
    $notif_stmt->execute([$_SESSION['user_id']]);
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $programs = [];
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">All Programs (Read-Only)</h2>
        <p class="text-slate-500 font-medium mt-1">System-wide view of all active and inactive scholarship programs.</p>
    </header>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="bg-green-50 text-green-700 p-4 rounded-2xl mb-6 font-bold border border-green-200 text-sm flex items-center gap-3">
            ✅ <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="bg-red-50 text-red-700 p-4 rounded-2xl mb-6 font-bold border border-red-200 text-sm flex items-center gap-3">
            ❌ <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- ✨ SYSTEM NOTIFICATIONS BANNER -->
    <?php if(!empty($notifications)): ?>
        <div class="mb-8 space-y-3">
            <?php foreach($notifications as $notif): ?>
                <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-r-xl shadow-sm flex justify-between items-start">
                    <div>
                        <h4 class="text-blue-700 font-black uppercase tracking-widest text-[11px] mb-1">⚠️ <?= htmlspecialchars($notif['Title']) ?></h4>
                        <p class="text-blue-900 font-medium text-sm"><?= htmlspecialchars($notif['Message']) ?></p>
                        <span class="text-[9px] font-bold text-blue-400 mt-2 block"><?= date('F j, Y, g:i a', strtotime($notif['DateCreated'])) ?></span>
                    </div>
                    <form action="../actions/process_crud.php" method="POST" class="m-0 shrink-0">
                        <input type="hidden" name="module" value="notifications">
                        <input type="hidden" name="action" value="dismiss">
                        <input type="hidden" name="notif_id" value="<?= $notif['NotifID'] ?>">
                        <button type="submit" class="text-blue-400 hover:text-blue-700 font-black text-xl leading-none">&times;</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm mb-6">
        <div class="flex flex-col gap-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Program Name</label>
                    <input type="text" id="filterName" oninput="filterPrograms()" placeholder="Search by name..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-text placeholder:font-medium">
                </div>
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Status</label>
                    <input type="text" id="filterStatus" list="statusList" oninput="filterPrograms()" placeholder="Type 'Active' or 'Inactive'..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-text placeholder:font-medium">
                    <datalist id="statusList"><option value="Active"></option><option value="Inactive"></option></datalist>
                </div>
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Target Level</label>
                    <input type="text" id="filterLevel" list="levelList" oninput="filterPrograms()" placeholder="Type a year level..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-text placeholder:font-medium">
                    <datalist id="levelList"><option value="All Years"></option><option value="1st Year"></option><option value="2nd Year"></option><option value="3rd Year"></option><option value="4th Year"></option></datalist>
                </div>
            </div>
            <div class="flex justify-end mt-2 pt-4 border-t border-slate-50">
                <button type="button" onclick="clearFilters()" class="bg-black text-white px-6 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-slate-800 transition-all shadow-sm active:scale-95 flex items-center justify-center">Reset Filters</button>
            </div>
        </div>
    </div>

    <div class="flex justify-end mb-6" id="programContainer">
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

    <div class="bg-white rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden p-4 lg:p-6 mb-8">
        <div id="programsGrid" class="flex flex-col gap-2.5">
            <?php foreach($programs as $p): 
                $status = htmlspecialchars($p['Status']);
                $level = htmlspecialchars($p['YearLevel'] ?? 'All Years');
            ?>
            <div class="program-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-4 sm:p-5 relative overflow-hidden group" 
                 data-name="<?= strtolower(htmlspecialchars($p['Name'])) ?>"
                 data-status="<?= strtolower($status) ?>"
                 data-level="<?= strtolower($level) ?>">
                
                <div class="absolute top-0 left-0 w-1 h-full <?= $status === 'Active' ? 'bg-green-500' : 'bg-red-500' ?>"></div>
                
                <div class="flex gap-4 items-center flex-1 min-w-0 pl-3 w-full lg:w-auto">
                    <div class="shrink-0 w-10 h-10 lg:w-12 lg:h-12 bg-slate-50 text-slate-900 rounded-xl lg:rounded-2xl flex items-center justify-center text-lg lg:text-xl font-bold shadow-inner group-hover:bg-blue-600 group-hover:text-white transition-colors">🎓</div>
                    
                    <div class="flex-1 min-w-0 pr-2">
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            <span class="<?= $status === 'Active' ? 'bg-green-50 text-green-600 border-green-100' : 'bg-red-50 text-red-600 border-red-100' ?> px-2 py-0.5 rounded-md font-black text-[9px] uppercase tracking-widest border shrink-0">
                                <?= $status ?>
                            </span>
                        </div>
                        <h3 class="text-sm lg:text-[15px] font-black text-slate-900 leading-tight truncate" title="<?= htmlspecialchars($p['Name']) ?>">
                            <?= htmlspecialchars($p['Name']) ?>
                        </h3>
                        <p class="text-[10px] lg:text-[11px] font-bold text-slate-500 uppercase tracking-widest mt-1 truncate">
                            Target: <?= $level ?>
                        </p>
                    </div>
                </div>
                
                <div class="flex items-center justify-between w-full lg:w-auto shrink-0 mt-4 lg:mt-0 border-t lg:border-t-0 lg:border-l border-slate-100 pt-3 lg:pt-0 lg:pl-6 pl-14">
                    <div class="text-left lg:text-right w-32 flex-1 lg:flex-none">
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Deadline</p>
                        <p class="text-xs lg:text-[13px] font-black text-red-500">
                            <?= date('M d, Y', strtotime($p['Deadline'])) ?>
                        </p>
                    </div>
                    
                    <div class="text-right w-32 shrink-0 border-l border-slate-100 pl-4 lg:pl-6 ml-2 lg:ml-0">
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Grant Amount</p>
                        <p class="text-sm lg:text-base font-black text-green-600">
                            ₱<?= number_format($p['AwardAmount']) ?>
                        </p>
                    </div>

                    <!-- ✨ SUPER ADMIN MOA DEACTIVATION BUTTON -->
                    <div class="text-right shrink-0 border-l border-slate-100 pl-4 lg:pl-6 ml-2 lg:ml-0 flex items-center">
                        <form action="../actions/process_crud.php" method="POST" class="m-0" onsubmit="return confirm('Send an official MOA Deactivation warning to the External Admin?');">
                            <input type="hidden" name="module" value="super_admin">
                            <input type="hidden" name="action" value="notify_moa">
                            <input type="hidden" name="scholarship_id" value="<?= $p['ScholarshipID'] ?>">
                            <button type="submit" class="bg-amber-100 text-amber-700 border border-amber-200 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-amber-500 hover:text-white transition-all shadow-sm active:scale-95 whitespace-nowrap">
                                Warn: MOA Expiry ⚠️
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div id="noResultsMsg" class="hidden p-20 text-center bg-slate-50 rounded-[2rem] border border-slate-100">
                <div class="text-4xl mb-4 grayscale opacity-30">🔍</div>
                <p class="text-slate-500 font-bold text-sm">No programs found matching your predictive filters.</p>
            </div>
            
            <?php if(empty($programs)): ?>
                <div class="p-20 text-center bg-slate-50 rounded-[2rem] border border-slate-100">
                    <div class="text-4xl mb-4 grayscale opacity-30">📂</div>
                    <p class="text-slate-500 font-bold text-sm">No programs found in the database.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="flex flex-col items-center justify-center mt-6 gap-4 border-t border-slate-100 pt-6">
            <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
            <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-1">
                Showing 1 to X of X Programs
            </div>
        </div>
    </div>
</main>

<script>
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage').value) || 10;
    const allCards = Array.from(document.querySelectorAll('.program-card'));
    let filteredCards = [...allCards]; 

    function clearFilters() {
        document.getElementById('filterName').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterLevel').value = '';
        filterPrograms();
    }

    function filterPrograms() {
        const fName = document.getElementById('filterName').value.toLowerCase().trim();
        const fStatus = document.getElementById('filterStatus').value.toLowerCase().trim();
        const fLevel = document.getElementById('filterLevel').value.toLowerCase().trim();

        filteredCards = allCards.filter(card => {
            const cardName = card.getAttribute('data-name');
            const cardStatus = card.getAttribute('data-status');
            const cardLevel = card.getAttribute('data-level');

            let match = true;
            if(fName !== "" && !cardName.includes(fName)) match = false;
            if(fStatus !== "" && !cardStatus.includes(fStatus)) match = false;
            if(fLevel !== "" && !cardLevel.includes(fLevel)) match = false;
            return match;
        });

        currentPage = 1; 
        renderPagination();
    }

    function renderPagination() {
        const totalItems = filteredCards.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;
        allCards.forEach(card => card.style.display = 'none');

        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        filteredCards.slice(start, end).forEach(card => card.style.display = 'flex');

        const pageInfo = document.getElementById('pageInfo');
        const noResultsMsg = document.getElementById('noResultsMsg');

        if (pageInfo) {
            if(totalItems === 0) {
                pageInfo.innerText = "No programs match your search.";
                if(noResultsMsg) noResultsMsg.classList.remove('hidden');
            } else {
                pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Programs`;
                if(noResultsMsg) noResultsMsg.classList.add('hidden');
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
    }

    function changePage(page) {
        const totalPages = Math.ceil(filteredCards.length / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
        document.getElementById('programContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function changeItemsPerPage() { itemsPerPage = parseInt(document.getElementById('itemsPerPage').value); currentPage = 1; renderPagination(); }
    document.addEventListener('DOMContentLoaded', renderPagination);
</script>
<?php include '../includes/footer.php'; ?>