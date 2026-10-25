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

// --- CAPTURE FILTER PARAMETERS ---
$filter_program = $_GET['program'] ?? '';
$filter_year    = $_GET['year_level'] ?? '';
$filter_status  = $_GET['status'] ?? 'All';

// ✨ CATEGORY FILTER (Scholarship / Incentive / Allowance)
require_once '../includes/scholarship_categories.php';
$filter_category = isset($_GET['category']) && array_key_exists($_GET['category'], SCHOLARSHIP_CATEGORIES) ? $_GET['category'] : '';
$sort_amount    = $_GET['sort_amount'] ?? '';

try {
    $all_programs = $pdo->query("SELECT ProgramName FROM program ORDER BY ProgramName ASC")->fetchAll(PDO::FETCH_COLUMN);

    $query = "
        SELECT sch.*, 
            COALESCE(
                p.ProgramName,
                (
                    SELECT GROUP_CONCAT(p_sub.ProgramName SEPARATOR ', ')
                    FROM scholarship_programs sp_sub
                    JOIN program p_sub ON sp_sub.ProgramID = p_sub.ProgramID
                    WHERE sp_sub.ScholarshipID = sch.ScholarshipID
                ),
                'All Programs'
            ) AS ProgramName,
            (cu.Role IS NULL OR cu.Role IN ('Internal_Admin', 'Super_Admin')) AS IsInternal
        FROM scholarship sch
        LEFT JOIN program p ON sch.ProgramID = p.ProgramID
        LEFT JOIN users cu ON cu.UserID = sch.CreatedBy
        WHERE 1=1
    ";
    
    $params = [];

    // ✨ INCLUDES MATCHING COURSES + SCHOLARSHIPS OPEN TO ALL
    if (!empty($filter_program)) {
        $query .= " AND (
            -- Case 1: Open to all (sch.ProgramID is NULL/0 AND no entries in scholarship_programs)
            (
                (sch.ProgramID IS NULL OR sch.ProgramID = 0 OR sch.ProgramID = 'all')
                AND NOT EXISTS (
                    SELECT 1 FROM scholarship_programs sp_check 
                    WHERE sp_check.ScholarshipID = sch.ScholarshipID 
                      AND sp_check.ProgramID IS NOT NULL 
                      AND sp_check.ProgramID != '' 
                      AND sp_check.ProgramID != 'all' 
                      AND sp_check.ProgramID != 0
                )
            )
            -- Case 2: Direct match on sch.ProgramID
            OR p.ProgramName LIKE :program
            -- Case 3: Match in scholarship_programs junction table
            OR EXISTS (
                SELECT 1 FROM scholarship_programs sp_match
                JOIN program p_match ON sp_match.ProgramID = p_match.ProgramID
                WHERE sp_match.ScholarshipID = sch.ScholarshipID
                  AND p_match.ProgramName LIKE :program
            )
        )";
        $params['program'] = "%" . trim($filter_program) . "%";
    }

    if (!empty($filter_year)) {
        // Also matches 'All Years' or empty year requirements
        $query .= " AND (sch.YearLevel LIKE :year OR sch.YearLevel IS NULL OR sch.YearLevel = '' OR sch.YearLevel = 'All Years')";
        $params['year'] = "%" . trim($filter_year) . "%";
    }

    if ($filter_status !== 'All') {
        $query .= " AND sch.Status = :status";
        $params['status'] = $filter_status;
    }

    if ($filter_category !== '') {
        $query .= " AND sch.Category = :category";
        $params['category'] = $filter_category;
    }

    if ($sort_amount === 'asc') {
        $query .= " ORDER BY sch.AwardAmount ASC, sch.Name ASC";
    } elseif ($sort_amount === 'desc') {
        $query .= " ORDER BY sch.AwardAmount DESC, sch.Name ASC";
    } else {
        $query .= " ORDER BY sch.Status ASC, sch.Name ASC";
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✨ FETCH UNREAD NOTIFICATIONS FOR INTERNAL ADMIN
    $notif_stmt = $pdo->prepare("SELECT * FROM system_notifications WHERE RecipientID = ? AND IsRead = 0 ORDER BY DateCreated DESC");
    $notif_stmt->execute([$_SESSION['user_id']]);
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("<div class='p-10 text-red-500 font-black'>Database Error: " . $e->getMessage() . "</div>");
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8 lg:mb-10 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Available Scholarships</h2>
            <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">View active scholarship programs and their target courses.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="bg-blue-50 text-blue-600 border border-blue-200 px-4 py-2 rounded-xl text-sm font-black uppercase tracking-widest inline-block shadow-sm">
                <?= count($programs) ?> Results
            </span>
            <div class="flex items-center gap-3 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-sm shrink-0">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest hidden sm:inline">Show:</span>
                <select id="itemsPerPage" onchange="changeItemsPerPage()" class="bg-slate-50 border border-slate-200 text-slate-900 text-sm font-bold rounded-lg focus:ring-green-500 focus:border-green-500 block px-2 py-1 outline-none cursor-pointer">
                    <option value="5">5 items</option>
                    <option value="10" selected>10 items</option>
                    <option value="15">15 items</option>
                    <option value="25">25 items</option>
                    <option value="999">All items</option>
                </select>
            </div>
        </div>
    </header>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="bg-green-50 text-green-700 p-4 rounded-2xl mb-6 font-bold border border-green-200 text-sm flex items-center gap-3">
            ✅ <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if(!empty($notifications)): ?>
        <div class="mb-8 space-y-3">
            <?php foreach($notifications as $notif): ?>
                <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-r-xl shadow-sm flex justify-between items-start">
                    <div>
                        <h4 class="text-blue-700 font-black uppercase tracking-widest text-[11px] mb-1">ℹ️ <?= htmlspecialchars($notif['Title']) ?></h4>
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

    <div class="bg-white p-6 lg:p-8 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm mb-10">
        <form method="GET" class="flex flex-wrap items-end gap-5">
            
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">🔍 Search Program</label>
                <input type="search" name="program" list="programList" value="<?= htmlspecialchars($filter_program) ?>" placeholder="Type to search e.g. Info..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner">
                <datalist id="programList">
                    <?php foreach($all_programs as $prog): ?>
                        <option value="<?= htmlspecialchars($prog) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="w-full sm:w-48">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">🔍 Search Year</label>
                <input type="search" name="year_level" list="yearList" value="<?= htmlspecialchars($filter_year) ?>" placeholder="e.g. 1st Year..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner">
                <datalist id="yearList">
                    <option value="1st Year">
                    <option value="2nd Year">
                    <option value="3rd Year">
                    <option value="4th Year">
                </datalist>
            </div>

            <div class="w-full sm:w-48">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Category</label>
                <select name="category" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all cursor-pointer shadow-sm">
                    <option value="">All Categories</option>
                    <?php foreach (SCHOLARSHIP_CATEGORIES as $cat => $icon): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $filter_category === $cat ? 'selected' : '' ?>><?= $icon . ' ' . htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="w-full sm:w-40">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Validity</label>
                <select name="status" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all cursor-pointer shadow-inner">
                    <option value="All" <?= $filter_status === 'All' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Active" <?= $filter_status === 'Active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="Inactive" <?= $filter_status === 'Inactive' ? 'selected' : '' ?>>Inactive Only</option>
                </select>
            </div>

            <div class="w-full sm:w-48">
                <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Sort Amount</label>
                <select name="sort_amount" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all cursor-pointer shadow-inner">
                    <option value="">Default Sorting</option>
                    <option value="desc" <?= $sort_amount === 'desc' ? 'selected' : '' ?>>Highest to Lowest (⬇)</option>
                    <option value="asc" <?= $sort_amount === 'asc' ? 'selected' : '' ?>>Lowest to Highest (⬆)</option>
                </select>
            </div>

            <div class="flex gap-2 w-full sm:w-auto mt-2 sm:mt-0">
                <button type="submit" class="flex-1 sm:flex-none bg-slate-900 text-white px-8 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-blue-600 active:scale-95 transition-all shadow-lg shadow-slate-200">
                    Apply Search
                </button>
                <a href="programs.php" class="flex-1 sm:flex-none flex items-center justify-center bg-slate-100 text-slate-900 px-6 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-slate-200 active:scale-95 transition-all">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div id="scholarshipsGrid" class="flex flex-col gap-3">
        <?php if(empty($programs)): ?>
            <div class="col-span-full bg-white p-10 lg:p-20 rounded-[2rem] border border-slate-200 text-center shadow-sm">
                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4 grayscale opacity-50">📂</div>
                <h3 class="text-lg font-black text-slate-900 mb-1">No Scholarships Found</h3>
                <p class="text-slate-500 font-medium text-sm">Adjust your filters or add new scholarships to the system.</p>
            </div>
        <?php else: ?>
            <?php foreach($programs as $p): ?>
                <?php 
                    $is_expired = (strtotime($p['Deadline']) < strtotime('today')); 
                    $display_status = ($p['Status'] === 'Active' && !$is_expired) ? 'Active' : 'Inactive';
                ?>
                <div class="scholarship-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-slate-400 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-5 group relative overflow-hidden">
                    
                    <div class="absolute top-0 left-0 w-1 h-full <?= $display_status === 'Active' ? 'bg-green-500' : 'bg-red-500' ?>"></div>

                    <div class="flex-1 min-w-0 pr-4 lg:pr-8 pl-3 w-full lg:w-auto">
                        <div class="flex items-center gap-3 mb-1.5 flex-wrap">
                            <span class="<?= $display_status === 'Active' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?> px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border shrink-0">
                                <?= $display_status ?>
                            </span>
                            <span class="bg-amber-50 text-amber-700 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-amber-200 shrink-0"><?= htmlspecialchars(categoryLabel($p['Category'] ?? null)) ?></span>
                            <h4 class="text-base font-black text-slate-900 truncate">
                                <?= htmlspecialchars($p['Name']) ?>
                            </h4>
                        </div>
                        <div class="flex items-center gap-2 text-xs font-medium text-slate-500 truncate mb-2 lg:mb-0">
                            <span class="text-slate-700 font-bold"><?= htmlspecialchars($p['ProgramName'] ?? 'All Programs') ?></span>
                            <span class="hidden md:inline">•</span>
                            <span class="truncate hidden md:inline"><?= htmlspecialchars($p['Description']) ?></span>
                        </div>
                    </div>

                    <div class="hidden md:flex items-center gap-6 px-6 border-x border-slate-100 shrink-0">
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-0.5">Target Year</span>
                            <span class="text-sm font-black text-slate-800"><?= htmlspecialchars($p['YearLevel'] ?? 'All Years') ?></span>
                        </div>
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-0.5">Min GPA</span>
                            <span class="text-sm font-black text-slate-800"><?= number_format($p['MinimumGWA'] ?? $p['MinimumGPA'] ?? 2.00, 2) ?></span>
                        </div>
                    </div>

                    <div class="mt-4 lg:mt-0 flex items-center justify-between lg:justify-end w-full lg:w-auto shrink-0 gap-6 pl-0 lg:pl-6">
                        <div class="text-left md:text-right">
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-0.5">Award Amount</span>
                            <span class="text-sm font-black text-green-600">₱ <?= number_format($p['AwardAmount'] ?? 0, 2) ?></span>
                        </div>
                        <div class="text-right border-r pr-6 border-slate-100">
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-0.5">Deadline</span>
                            <span class="text-xs font-black <?= $is_expired ? 'text-red-500' : 'text-slate-800' ?>">
                                <?= date('M d, Y', strtotime($p['Deadline'])) ?>
                            </span>
                        </div>
                        
                        <div class="text-right">
                            <?php if ($p['IsInternal']): ?>
                            <!-- Internally funded: the OSSD designs its application form -->
                            <a href="form_builder.php?id=<?= (int) $p['ScholarshipID'] ?>" class="inline-block bg-blue-50 text-blue-700 border border-blue-200 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-600 hover:text-white transition-all whitespace-nowrap" title="Design the application form students fill in">
                                Design Form 📝
                            </a>
                            <?php else: ?>
                            <form action="../actions/process_crud.php" method="POST" class="m-0" onsubmit="return confirm('Flag this scholarship for MOA Deactivation? The External Admin will be notified.');">
                                <input type="hidden" name="module" value="admin_actions">
                                <input type="hidden" name="action" value="notify_moa">
                                <input type="hidden" name="scholarship_id" value="<?= $p['ScholarshipID'] ?>">
                                <button type="submit" class="bg-amber-50 text-amber-600 border border-amber-200 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-amber-500 hover:text-white transition-all shadow-sm active:scale-95 whitespace-nowrap">
                                    MOA Expiry ⚠️
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="flex flex-col items-center justify-center mt-12 gap-4 border-t border-slate-200 pt-8 pb-10">
        <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
        <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2">
            Showing 1 to X of X Grants
        </div>
    </div>
</main>

<script>
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage') ? document.getElementById('itemsPerPage').value : 10) || 10;
    const cards = Array.from(document.querySelectorAll('.scholarship-card'));
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
            pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Grants`;
        }

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
        
        const paginationControls = document.getElementById('paginationControls');
        if(paginationControls) paginationControls.innerHTML = btnHtml;
    }

    function changePage(page) {
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page; renderPagination();
        document.getElementById('scholarshipsGrid').scrollIntoView({ behavior: 'smooth' });
    }

    function changeItemsPerPage() {
        const selector = document.getElementById('itemsPerPage');
        if(selector) { itemsPerPage = parseInt(selector.value); currentPage = 1; renderPagination(); }
    }

    document.addEventListener('DOMContentLoaded', renderPagination);
</script>

<?php include '../includes/footer.php'; ?>