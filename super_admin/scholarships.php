<?php
session_start();
include '../includes/session_manager.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['External_Admin', 'Super_Admin'])) {
    header("Location: ../admin_login.php"); 
    exit();
}

$is_super_admin = ($_SESSION['role'] === 'Super_Admin');

include '../includes/db_connect.php'; 
include '../includes/header.php'; 

// Load the appropriate sidebar for the logged-in role
if ($is_super_admin) {
    include file_exists('../includes/super_sidebar.php') ? '../includes/super_sidebar.php' : '../includes/super_admin_sidebar.php';
} else {
    include '../includes/external_sidebar.php';
}

$current_admin_id = $_SESSION['user_id'] ?? $_SESSION['UserID'] ?? null;
$filter_program = isset($_GET['program']) ? trim($_GET['program']) : '';

// ✨ CATEGORY FILTER (Scholarship / Incentive / Allowance)
require_once '../includes/scholarship_categories.php';
$filter_category = isset($_GET['category']) && array_key_exists($_GET['category'], SCHOLARSHIP_CATEGORIES) ? $_GET['category'] : '';

// ✨ ALL PROGRAMS
$programs = [];
try {
    $programs_stmt = $pdo->query("SELECT ProgramID, ProgramName FROM program ORDER BY ProgramName ASC");
    $programs = $programs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $programs = [];
}

// ✨ UNREAD SYSTEM NOTIFICATIONS FOR THIS ADMIN
$notifications = [];
try {
    $notif_stmt = $pdo->prepare("SELECT NotifID, Title, Message, DateCreated 
                                  FROM system_notifications 
                                  WHERE RecipientID = :uid AND IsRead = 0 
                                  ORDER BY DateCreated DESC");
    $notif_stmt->execute(['uid' => $current_admin_id]);
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $notifications = [];
}

// ✨ SCHOLARSHIPS (Super Admin sees ALL; External Admin sees only their own)
$scholarships = [];
try {
    $sql = "SELECT 
                s.*,
                GROUP_CONCAT(DISTINCT sp.ProgramID) AS ProgramIDs,
                GROUP_CONCAT(DISTINCT p.ProgramName ORDER BY p.ProgramName SEPARATOR ', ') AS ProgramNames,
                GROUP_CONCAT(DISTINCT CONCAT(dr.RequirementID, '::', dr.DocumentName) SEPARATOR '||') AS Requirements,
                GROUP_CONCAT(DISTINCT CONCAT(sc.CriteriaID, '::', sc.CriteriaName) SEPARATOR '||') AS CriteriaList,
                GROUP_CONCAT(DISTINCT CONCAT(scf.FieldID, '::', scf.FieldName, '::', scf.FieldType, '::', COALESCE(scf.PDFFieldName, '')) SEPARATOR '||') AS CustomFields            
            FROM scholarship s
            LEFT JOIN scholarship_programs sp ON sp.ScholarshipID = s.ScholarshipID
            LEFT JOIN program p ON p.ProgramID = sp.ProgramID
            LEFT JOIN document_requirement dr ON dr.ScholarshipID = s.ScholarshipID
            LEFT JOIN scholarship_criteria sc ON sc.ScholarshipID = s.ScholarshipID
            LEFT JOIN scholarship_custom_fields scf ON scf.ScholarshipID = s.ScholarshipID
            WHERE 1=1";

    $params = [];

    if (!$is_super_admin) {
        $sql .= " AND s.CreatedBy = :uid";
        $params['uid'] = $current_admin_id;
    }

    if ($filter_program !== '') {
        $sql .= " AND (
            -- 1. Scholarship is Open to All Programs (no specific restrictions in scholarship_programs)
            NOT EXISTS (
                SELECT 1 FROM scholarship_programs sp_check 
                WHERE sp_check.ScholarshipID = s.ScholarshipID 
                  AND sp_check.ProgramID IS NOT NULL 
                  AND sp_check.ProgramID != '' 
                  AND sp_check.ProgramID != 'all' 
                  AND sp_check.ProgramID != 0
            )
            -- 2. OR the scholarship explicitly includes the searched program
            OR EXISTS (
                SELECT 1 FROM scholarship_programs sp_sub
                JOIN program p_sub ON p_sub.ProgramID = sp_sub.ProgramID
                WHERE sp_sub.ScholarshipID = s.ScholarshipID
                  AND p_sub.ProgramName LIKE :filter_program
            )
        )";
        $params['filter_program'] = '%' . $filter_program . '%';
    }

    if ($filter_category !== '') {
        $sql .= " AND s.Category = :filter_category";
        $params['filter_category'] = $filter_category;
    }

    $sql .= " GROUP BY s.ScholarshipID ORDER BY s.ScholarshipID DESC";

    $scholarships_stmt = $pdo->prepare($sql);
    $scholarships_stmt->execute($params);
    $scholarships = $scholarships_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $scholarships = [];
}

$auto_fill_dictionary = [
    'Auto_FirstName'     => 'Auto: First Name',
    'Auto_LastName'      => 'Auto: Last Name',
    'Auto_MiddleName'    => 'Auto: Middle Name',
    'Auto_StudentNumber' => 'Auto: Student Number',
    'Auto_Email'         => 'Auto: Email Address',
    'Auto_Program'       => 'Auto: Course / Program',
    'Auto_YearLevel'     => 'Auto: Year Level',
    'Auto_Gender'        => 'Auto: Gender',
    'Auto_Contact'       => 'Auto: Contact Number',
    'Auto_DOB'           => 'Auto: Date of Birth',
    'Auto_Religion'      => 'Auto: Religion',
    'Auto_Address'       => 'Auto: Home Address',
    'Auto_Income'        => 'Auto: Family Income'
];

try {
    $custom_prof_stmt = $pdo->query("SELECT FieldID, FieldName FROM user_profile_fields ORDER BY FieldName ASC");
    while($cpf = $custom_prof_stmt->fetch(PDO::FETCH_ASSOC)) {
        $auto_fill_dictionary['Auto_Custom_' . $cpf['FieldID']] = 'Auto: ' . $cpf['FieldName'];
    }
} catch (PDOException $e) {}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8 lg:mb-10 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Manage Scholarships</h2>
            <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Create, edit, filter, and monitor official scholarship programs.</p>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="openCreateModal()" class="bg-slate-900 text-white px-6 py-2.5 rounded-xl font-black text-[12px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-xl shadow-slate-200 active:scale-[0.98]">
                + Launch New
            </button>
            <div class="flex items-center gap-3 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-sm shrink-0">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest hidden sm:inline">Show:</span>
                <select id="itemsPerPage" onchange="changeItemsPerPage()" class="bg-slate-50 border border-slate-200 text-slate-900 text-sm font-bold rounded-lg focus:ring-green-500 focus:border-green-500 block px-2 py-1 outline-none cursor-pointer">
                    <option value="5">5 items</option>
                    <option value="10" selected>10 items</option>
                    <option value="25">25 items</option>
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

    <?php if(!empty($notifications)): ?>
        <div class="mb-8 space-y-3">
            <?php foreach($notifications as $notif): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-xl shadow-sm flex justify-between items-start">
                    <div>
                        <h4 class="text-red-700 font-black uppercase tracking-widest text-[11px] mb-1">⚠️ <?= htmlspecialchars($notif['Title']) ?></h4>
                        <p class="text-red-900 font-medium text-sm"><?= htmlspecialchars($notif['Message']) ?></p>
                        <span class="text-[9px] font-bold text-red-400 mt-2 block"><?= date('F j, Y, g:i a', strtotime($notif['DateCreated'])) ?></span>
                    </div>
                    <form action="../actions/process_crud.php" method="POST" class="m-0 shrink-0">
                        <input type="hidden" name="module" value="notifications">
                        <input type="hidden" name="action" value="dismiss">
                        <input type="hidden" name="notif_id" value="<?= $notif['NotifID'] ?>">
                        <button type="submit" class="text-red-400 hover:text-red-700 font-black text-xl leading-none">&times;</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 lg:p-8 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm mb-10">
        <form method="GET" class="flex flex-wrap items-end gap-5">
            <div class="flex-1 min-w-[150px]">
                <label class="block text-[14px] font-black text-black uppercase tracking-widest mb-2">🔍 Search Program</label>
                <input type="search" name="program" list="programList" value="<?= htmlspecialchars($filter_program) ?>" placeholder="Type e.g. Info..." class="w-full bg-slate-50 border border-slate-100 px-4 py-3.5 rounded-xl font-bold text-sm text-black outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white shadow-inner">
                <datalist id="programList">
                    <?php foreach($programs as $p): ?>
                        <option value="<?= htmlspecialchars($p['ProgramName']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="w-full sm:w-auto sm:min-w-[200px]">
                <label class="block text-[14px] font-black text-black uppercase tracking-widest mb-2">🏷️ Category</label>
                <select name="category" class="w-full bg-slate-50 border border-slate-100 px-4 py-3.5 rounded-xl font-bold text-sm text-black outline-none focus:border-blue-500 transition-all cursor-pointer">
                    <option value="">All Categories</option>
                    <?php foreach (SCHOLARSHIP_CATEGORIES as $cat => $icon): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $filter_category === $cat ? 'selected' : '' ?>><?= $icon . ' ' . htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2 w-full sm:w-auto mt-2 sm:mt-0">
                <button type="submit" class="flex-1 sm:flex-none bg-slate-900 text-white px-8 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg">Filter</button>
                <a href="scholarships.php" class="flex-1 sm:flex-none flex items-center justify-center bg-slate-100 text-black px-6 py-3.5 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-slate-200 transition-all border border-slate-200">Reset</a>
            </div>
        </form>
    </div>

    <div id="scholarshipsGrid" class="flex flex-col gap-3">
        <?php foreach($scholarships as $s): ?>
            <!-- Two rows: details + figures on top, the actions below (so five buttons never squeeze the name) -->
            <div class="scholarship-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all flex flex-col p-5 group relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1 h-full <?= $s['Status'] === 'Active' ? 'bg-green-500' : 'bg-slate-300' ?>"></div>
                <div class="flex flex-col md:flex-row md:items-center gap-4 pl-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                            <span class="bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-emerald-200 shrink-0">
                                <?= htmlspecialchars($s['ScholarshipType'] ?? 'Private') ?>
                            </span>
                            <span class="bg-amber-50 text-amber-700 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-amber-200 shrink-0">
                                <?= htmlspecialchars(categoryLabel($s['Category'] ?? null)) ?>
                            </span>
                            <?php if($s['AllowsDual'] === 'No'): ?>
                                <span class="bg-red-50 text-red-600 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-red-200 shrink-0">Strict Dual Policy</span>
                            <?php endif; ?>
                        </div>
                        <h4 class="text-base lg:text-lg font-black text-slate-900 leading-snug line-clamp-2" title="<?= htmlspecialchars($s['Name']) ?>"><?= htmlspecialchars($s['Name']) ?></h4>
                        <p class="mt-1 text-xs font-medium text-slate-500 line-clamp-2">
                            <span class="text-slate-700 font-bold"><?= htmlspecialchars($s['ProgramNames'] ?: '🌍 Open to All Programs') ?></span>
                            <?php if (trim((string) $s['Description']) !== ''): ?><span class="hidden md:inline"> • <?= htmlspecialchars($s['Description']) ?></span><?php endif; ?>
                        </p>
                    </div>

                    <div class="flex items-start gap-6 md:pl-6 md:border-l border-slate-100 shrink-0">
                        <div class="min-w-[90px]">
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-0.5">Target Year</span>
                            <span class="text-sm font-black text-slate-800 block max-w-[160px] leading-snug"><?= htmlspecialchars($s['YearLevel'] ?: 'All Levels') ?></span>
                        </div>
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-0.5">Award Amount</span>
                            <span class="text-sm font-black text-green-600 whitespace-nowrap">₱ <?= number_format($s['AwardAmount'], 2) ?></span>
                            <span class="text-[9px] font-bold text-slate-500 uppercase tracking-widest block mt-0.5"><?= htmlspecialchars($s['ReleaseFrequency'] ?? 'Per Semester') ?></span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-4 pl-3 border-t border-slate-100 flex flex-wrap items-center justify-end gap-2">
                    <!-- ✨ WARN: MOA EXPIRY BUTTON (SUPER ADMIN ONLY) -->
                    <?php if ($is_super_admin): ?>
                        <form action="../actions/process_crud.php" method="POST" class="m-0" onsubmit="return confirm('Send an official MOA Deactivation warning to the External Admin?');">
                            <input type="hidden" name="module" value="super_admin">
                            <input type="hidden" name="action" value="notify_moa">
                            <input type="hidden" name="scholarship_id" value="<?= $s['ScholarshipID'] ?>">
                            <button type="submit" class="bg-amber-100 border border-amber-200 text-amber-700 hover:bg-amber-500 hover:text-white px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all shadow-sm active:scale-95 shrink-0 whitespace-nowrap">
                                Warn: MOA Expiry ⚠️
                            </button>
                        </form>
                    <?php endif; ?>

                    <button type="button" 
                        data-id="<?= $s['ScholarshipID'] ?>"
                        data-name="<?= htmlspecialchars($s['Name'] ?? '', ENT_QUOTES) ?>"
                        data-desc="<?= htmlspecialchars($s['Description'] ?? '', ENT_QUOTES) ?>"
                        data-amount="<?= $s['AwardAmount'] ?? 0 ?>"
                        data-release="<?= htmlspecialchars($s['ReleaseFrequency'] ?? 'Per Semester', ENT_QUOTES) ?>" 
                        data-gpa="<?= $s['MinimumGWA'] ?? 2.00 ?>"
                        data-deadline="<?= $s['Deadline'] ?? '' ?>"
                        data-programs="<?= htmlspecialchars($s['ProgramIDs'] ?? '', ENT_QUOTES) ?>"
                        data-year="<?= htmlspecialchars($s['YearLevel'] ?? '', ENT_QUOTES) ?>"
                        data-status="<?= htmlspecialchars($s['Status'] ?? '', ENT_QUOTES) ?>"
                        data-slots="<?= htmlspecialchars($s['NumberOfSlots'] ?? '', ENT_QUOTES) ?>"
                        data-requirements="<?= htmlspecialchars($s['Requirements'] ?? '', ENT_QUOTES) ?>"
                        data-criteria="<?= htmlspecialchars($s['CriteriaList'] ?? '', ENT_QUOTES) ?>"
                        data-customfields="<?= htmlspecialchars($s['CustomFields'] ?? '', ENT_QUOTES) ?>"
                        data-gender="<?= htmlspecialchars($s['GenderRequirement'] ?? 'Any', ENT_QUOTES) ?>"
                        data-type="<?= htmlspecialchars($s['ScholarshipType'] ?? 'Private', ENT_QUOTES) ?>"
                        data-category="<?= htmlspecialchars($s['Category'] ?? DEFAULT_SCHOLARSHIP_CATEGORY, ENT_QUOTES) ?>"
                        data-dual="<?= htmlspecialchars($s['AllowsDual'] ?? 'No', ENT_QUOTES) ?>"
                        onclick="openEditModal(this)" 
                        class="bg-slate-900 text-white hover:bg-blue-600 px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all shadow-md active:scale-95 shrink-0 whitespace-nowrap">
                        Edit ⚙️
                    </button>
                    <a href="form_builder.php?id=<?= (int) $s['ScholarshipID'] ?>" class="bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-600 hover:text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all shadow-sm active:scale-95 shrink-0 whitespace-nowrap" title="Design the application form students fill in">
                        Design Form 📝
                    </a>
                    <a href="award_notice.php?id=<?= (int) $s['ScholarshipID'] ?>" class="bg-green-50 text-green-700 border border-green-200 hover:bg-green-600 hover:text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all shadow-sm active:scale-95 shrink-0 whitespace-nowrap" title="What approved scholars must still submit (sent with their Approval Notice)">
                        Approval Notice 🏆
                    </a>

                    <form action="../actions/process_crud.php" method="POST" class="m-0" onsubmit="return confirm('Are you sure you want to permanently delete this scholarship? All applications and dynamic forms attached to it will be wiped out.');">
                        <input type="hidden" name="module" value="scholarships">
                        <input type="hidden" name="action" value="delete_single">
                        <input type="hidden" name="scholarship_id" value="<?= $s['ScholarshipID'] ?>">
                        <button type="submit" class="bg-white border border-red-200 text-red-500 hover:bg-red-500 hover:text-white px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all shadow-sm active:scale-95 shrink-0 whitespace-nowrap">
                            Delete 🗑️
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="flex flex-col items-center justify-center mt-12 gap-4 border-t border-slate-200 pt-8 pb-10">
        <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
        <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2"></div>
    </div>
</main>

<!-- CREATE MODAL -->
<div id="createModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-[95%] sm:max-w-5xl rounded-[1.5rem] lg:rounded-[2.5rem] shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
            <div>
                <h3 class="font-black text-black text-lg uppercase tracking-tight">Launch Scholarship</h3>
                <p class="text-[14px] text-black font-bold mt-1 uppercase tracking-widest">Define core parameters and build your dynamic form</p>
            </div>
            <button type="button" onclick="closeCreateModal()" class="w-8 h-8 bg-slate-200 text-black rounded-full font-bold hover:bg-red-500 hover:text-white transition-colors">&times;</button>
        </div>
        
        <div class="overflow-y-auto p-6 lg:p-8 custom-scrollbar">
            <form action="../actions/process_crud.php" method="POST" class="space-y-6">
                <input type="hidden" name="module" value="scholarships">
                <input type="hidden" name="action" value="create">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Scholarship Name</label><input type="text" name="name" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-5 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Description</label><input type="text" name="description" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-5 py-3 font-medium text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4">
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Amount (₱)</label><input type="number" name="award_amount" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div>
                        <label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Release</label>
                        <select name="release_frequency" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all">
                            <option value="Per Semester">Per Semester</option>
                            <option value="Per Year">Per Year</option>
                            <option value="Per Month">Per Month</option>
                            <option value="Upon Completion">Upon Completion</option>
                        </select>
                    </div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Slots</label><input type="number" name="slots" min="1" placeholder="Unlimited" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Min. GPA</label><input type="number" step="0.01" name="min_gpa" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Deadline</label><input type="date" name="deadline" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Status</label><select name="status" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                    <div class="flex items-end">
                        <button type="button" onclick="addUnifiedField('create-fields-container')" class="w-full h-[46px] bg-blue-100 text-blue-700 rounded-xl font-black text-[11px] uppercase tracking-widest hover:bg-blue-200 transition-all shadow-sm whitespace-nowrap active:scale-95">
                            + Add Field
                        </button>
                    </div>
                </div>

                <div id="create-fields-container" class="space-y-3 empty:hidden"></div>
                <p class="text-[11px] font-bold text-slate-400 -mt-1"><i class="fas fa-circle-info"></i> Add file uploads and grading criteria here. Application questions are designed with <strong class="text-blue-600">Design Form 📝</strong> on the scholarship card.</p>

                <div class="p-5 bg-blue-50/50 border border-blue-100 rounded-2xl flex flex-col gap-4">
                    <select name="category" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-xs lg:text-[11px] uppercase tracking-widest outline-none focus:border-blue-500 transition-all">
                        <?php foreach (SCHOLARSHIP_CATEGORIES as $cat => $icon): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= $icon . ' ' . htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <select name="scholarship_type" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-xs lg:text-[11px] uppercase tracking-widest outline-none focus:border-blue-500 transition-all"><option value="Private">🏢 Private</option><option value="Government">🏛️ Government</option></select>
                        <select name="gender_req" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-xs lg:text-[11px] uppercase tracking-widest outline-none focus:border-blue-500 transition-all"><option value="Any">⚧ Any Gender</option><option value="Female">Female Only</option><option value="Male">Male Only</option></select>
                        <select name="allows_dual" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-xs lg:text-[11px] uppercase tracking-widest outline-none focus:border-blue-500 transition-all">
                            <option value="No">🚫 No Dual Grants</option>
                            <option value="Yes">✅ Allow Dual Grants</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-2 bg-white border border-blue-200 rounded-xl p-4 shadow-sm flex flex-col">
                            <h4 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-3">🎯 Target Programs</h4>
                            <div class="flex-1 overflow-y-auto custom-scrollbar grid grid-cols-1 sm:grid-cols-2 gap-2 pr-2" style="max-height: 140px;">
                                <label class="group flex items-start gap-3 p-2.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition-all">
                                    <input type="checkbox" name="program_ids[]" value="all" class="mt-0.5 w-4 h-4 accent-blue-600 rounded" checked>
                                    <div><span class="block text-[11px] font-black text-blue-600 uppercase tracking-widest leading-tight">🌍 Open to All Programs</span></div>
                                </label>
                                <?php foreach($programs as$p): ?>
                                    <label class="group flex items-start gap-3 p-2.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition-all">
                                        <input type="checkbox" name="program_ids[]" value="<?= $p['ProgramID'] ?>" class="mt-0.5 w-4 h-4 accent-blue-600 rounded">
                                        <div><span class="block text-[10px] font-bold text-slate-700 group-hover:text-blue-700 transition-colors uppercase tracking-widest leading-tight"><?= htmlspecialchars($p['ProgramName']) ?></span></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="bg-white border border-blue-200 rounded-xl p-4 shadow-sm flex flex-col">
                            <h4 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-3">📅 Target Years</h4>
                            <div class="flex-1 overflow-y-auto custom-scrollbar flex flex-col gap-2 pr-2" style="max-height: 140px;">
                                <label class="group flex items-center gap-3 p-2.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition-all">
                                    <input type="checkbox" name="year_levels[]" value="all" class="w-4 h-4 accent-blue-600 rounded" checked>
                                    <span class="text-[10px] font-black text-blue-600 uppercase tracking-widest">🌍 Any Year</span>
                                </label>
                                <?php foreach(['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yl): ?>
                                <label class="group flex items-center gap-3 p-2.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition-all">
                                    <input type="checkbox" name="year_levels[]" value="<?= $yl ?>" class="w-4 h-4 accent-blue-600 rounded">
                                    <span class="text-[10px] font-bold text-slate-700 group-hover:text-blue-700 uppercase tracking-widest"><?= $yl ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeCreateModal()" class="flex-1 bg-slate-200 text-black py-4 rounded-xl font-black text-sm uppercase tracking-widest hover:bg-slate-300 transition-all">Cancel</button>
                    <button type="submit" class="flex-1 bg-slate-900 text-white py-4 rounded-xl font-black text-sm uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg active:scale-95">Launch Scholarship</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-[95%] sm:max-w-5xl rounded-[1.5rem] lg:rounded-[2.5rem] shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
            <div>
                <h3 class="font-black text-black text-lg uppercase tracking-tight">Edit Scholarship</h3>
                <p class="text-[14px] text-blue-600 font-bold mt-1 uppercase tracking-widest">Update core rules and dynamic fields</p>
            </div>
            <button type="button" onclick="closeEditModal()" class="w-8 h-8 bg-slate-200 text-black rounded-full font-bold hover:bg-red-500 hover:text-white transition-colors">&times;</button>
        </div>
        
        <div class="overflow-y-auto p-6 lg:p-8 custom-scrollbar">
            <form action="../actions/process_crud.php" method="POST" class="space-y-6">
                <input type="hidden" name="module" value="scholarships">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="scholarship_id" id="edit_id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Scholarship Name</label><input type="text" name="name" id="edit_name" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-5 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Description</label><input type="text" name="description" id="edit_desc" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-5 py-3 font-medium text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4">
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Amount (₱)</label><input type="number" name="award_amount" id="edit_amount" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div>
                        <label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Release</label>
                        <select name="release_frequency" id="edit_release" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all">
                            <option value="Per Semester">Per Semester</option>
                            <option value="Per Year">Per Year</option>
                            <option value="Per Month">Per Month</option>
                            <option value="Upon Completion">Upon Completion</option>
                        </select>
                    </div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Slots</label><input type="number" name="slots" id="edit_slots" min="1" placeholder="Unlimited" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Min. GPA</label><input type="number" step="0.01" name="min_gpa" id="edit_gpa" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Deadline</label><input type="date" name="deadline" id="edit_deadline" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"></div>
                    <div><label class="block text-[10px] font-black text-black uppercase tracking-widest mb-2">Status</label><select name="status" id="edit_status" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-sm outline-none focus:border-blue-500 transition-all"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                    <div class="flex items-end">
                        <button type="button" onclick="addUnifiedField('edit-fields-container')" class="w-full h-[46px] bg-blue-100 text-blue-700 rounded-xl font-black text-[11px] uppercase tracking-widest hover:bg-blue-200 transition-all shadow-sm whitespace-nowrap active:scale-95">
                            + Add Field
                        </button>
                    </div>
                </div>

                <div id="edit-fields-container" class="space-y-3 empty:hidden"></div>
                <p class="text-[11px] font-bold text-slate-400 -mt-1"><i class="fas fa-circle-info"></i> Add file uploads and grading criteria here. Application questions are designed with <strong class="text-blue-600">Design Form 📝</strong> on the scholarship card.</p>

                <div class="p-5 bg-blue-50/50 border border-blue-100 rounded-2xl flex flex-col gap-4">
                    <select name="category" id="edit_category" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-xs lg:text-[11px] uppercase tracking-widest outline-none focus:border-blue-500 transition-all">
                        <?php foreach (SCHOLARSHIP_CATEGORIES as $cat => $icon): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= $icon . ' ' . htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <select name="scholarship_type" id="edit_type" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-xs lg:text-[11px] uppercase tracking-widest outline-none focus:border-blue-500 transition-all"><option value="Private">🏢 Private</option><option value="Government">🏛️ Government</option></select>
                        <select name="gender_req" id="edit_gender" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-xs lg:text-[11px] uppercase tracking-widest outline-none focus:border-blue-500 transition-all"><option value="Any">⚧ Any Gender</option><option value="Female">Female Only</option><option value="Male">Male Only</option></select>
                        <select name="allows_dual" id="edit_dual" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-bold text-black text-xs lg:text-[11px] uppercase tracking-widest outline-none focus:border-blue-500 transition-all">
                            <option value="No">🚫 No Dual Grants</option>
                            <option value="Yes">✅ Allow Dual Grants</option>
                        </select>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-2 bg-white border border-blue-200 rounded-xl p-4 shadow-sm flex flex-col">
                            <h4 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-3">🎯 Target Programs</h4>
                            <div class="flex-1 overflow-y-auto custom-scrollbar grid grid-cols-1 sm:grid-cols-2 gap-2 pr-2" style="max-height: 140px;">
                                <label class="group flex items-start gap-3 p-2.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition-all">
                                    <input type="checkbox" name="program_ids[]" value="all" class="mt-0.5 w-4 h-4 accent-blue-600 rounded">
                                    <div><span class="block text-[11px] font-black text-blue-600 uppercase tracking-widest leading-tight">🌍 Open to All Programs</span></div>
                                </label>
                                <?php foreach($programs as$p): ?>
                                    <label class="group flex items-start gap-3 p-2.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition-all">
                                        <input type="checkbox" name="program_ids[]" value="<?= $p['ProgramID'] ?>" class="mt-0.5 w-4 h-4 accent-blue-600 rounded">
                                        <div><span class="block text-[10px] font-bold text-slate-700 group-hover:text-blue-700 transition-colors uppercase tracking-widest leading-tight"><?= htmlspecialchars($p['ProgramName']) ?></span></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="bg-white border border-blue-200 rounded-xl p-4 shadow-sm flex flex-col">
                            <h4 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-3">📅 Target Years</h4>
                            <div class="flex-1 overflow-y-auto custom-scrollbar flex flex-col gap-2 pr-2" style="max-height: 140px;">
                                <label class="group flex items-center gap-3 p-2.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition-all">
                                    <input type="checkbox" name="year_levels[]" value="all" class="w-4 h-4 accent-blue-600 rounded" checked>
                                    <span class="text-[10px] font-black text-blue-600 uppercase tracking-widest">🌍 Any Year</span>
                                </label>
                                <?php foreach(['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yl): ?>
                                <label class="group flex items-center gap-3 p-2.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition-all">
                                    <input type="checkbox" name="year_levels[]" value="<?= $yl ?>" class="w-4 h-4 accent-blue-600 rounded">
                                    <span class="text-[10px] font-bold text-slate-700 group-hover:text-blue-700 uppercase tracking-widest"><?= $yl ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeEditModal()" class="flex-1 bg-slate-200 text-black py-4 rounded-xl font-black text-sm uppercase tracking-widest hover:bg-slate-300 transition-all">Cancel</button>
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-4 rounded-xl font-black text-sm uppercase tracking-widest hover:bg-blue-700 transition-all shadow-lg active:scale-[0.98]">Save Changes 💾</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let currentPage = 1; let itemsPerPage = 10;
    const cards = Array.from(document.querySelectorAll('.scholarship-card'));
    const totalItems = cards.length;

    function renderPagination() {
        if (totalItems === 0) return;
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        cards.forEach(card => card.style.display = 'none');
        const start = (currentPage - 1) * itemsPerPage; const end = start + itemsPerPage;
        cards.slice(start, end).forEach(card => card.style.display = 'flex');

        const pageInfo = document.getElementById('pageInfo');
        if (pageInfo) pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Scholarships`;
        
        let btnHtml = `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center bg-white border border-slate-200 hover:bg-slate-100">&larr;</button>`;
        btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center bg-white border border-slate-200 hover:bg-slate-100">&rarr;</button>`;
        document.getElementById('paginationControls').innerHTML = btnHtml;
    }
    function changePage(page) { currentPage = page; renderPagination(); }
    function changeItemsPerPage() { itemsPerPage = parseInt(document.getElementById('itemsPerPage').value, 10); currentPage = 1; renderPagination(); }
    document.addEventListener('DOMContentLoaded', renderPagination);

    const autoFillDict = <?php echo json_encode($auto_fill_dictionary); ?>;
    const dynamicAutoFillOptions = Object.keys(autoFillDict).map(key => {
        return { val: key, label: autoFillDict[key] };
    });

    // ✨ CRASH-FREE JAVASCRIPT BUILDER
    function addUnifiedField(containerId, presetId='', presetName='', presetType='Text', presetCategory='Custom', presetPdfName='') {
        const container = document.getElementById(containerId);
        if (!container) return;
        
       const div = document.createElement('div');
       div.className = 'unified-field-row flex flex-col sm:flex-row gap-2 sm:gap-3 items-center bg-slate-50 border border-slate-200 p-3 rounded-2xl shadow-sm transition-all';
        
        if(presetId !== '') {
            div.id = `existing-field-${presetCategory}-${presetId}`;
            div.innerHTML += `<input type="hidden" name="existing_${presetCategory}_ids[]" value="${presetId}">`;
        }

        const inputId = 'dyn_input_' + Math.random().toString(36).substr(2, 9);
        
        const inputNameAttr = presetId !== '' ? 'existing_' + presetCategory + '_names[]' : 'new_dynamic_names[]';
        const typeNameAttr = presetId !== '' ? 'existing_' + presetCategory + '_types[]' : 'new_dynamic_types[]';
        const pdfNameAttr = presetId !== '' ? 'existing_' + presetCategory + '_pdfnames[]' : 'new_pdf_field_names[]';

        const inputWrapper = document.createElement('div');
        inputWrapper.className = 'flex-1 w-full relative';
        inputWrapper.innerHTML = `
            <input type="text" id="${inputId}" name="${inputNameAttr}" value="${presetName}" required placeholder="Type requirement or question name..." class="w-full bg-white border border-slate-200 rounded-xl pl-4 pr-10 py-3 font-bold text-sm outline-none focus:border-blue-500 shadow-sm transition-all">
        `;

        const typeWrapper = document.createElement('div');
        typeWrapper.className = 'w-full sm:w-60 shrink-0'; 
        
        const groups = [
            {
                label: 'Standard Requirements',
                options: [
                    { val: 'Document', label: '📄 File Upload' },
                    { val: 'Criteria', label: '⭐ Grading Criteria' }
                ]
            },

        ];

        let mappedType = presetType;
        if (presetCategory === 'Req') mappedType = 'Document';
        if (presetCategory === 'Crit') mappedType = 'Criteria';
        if (presetType === 'Textarea') mappedType = 'Paragraph';

        const autoChangeLogic = presetId === '' 
            ? `onchange="if(this.value.startsWith('Auto_')) { document.getElementById('${inputId}').value = this.options[this.selectedIndex].text.replace('Auto: ', ''); document.getElementById('${inputId}').classList.add('bg-blue-50', 'text-blue-700'); } else { document.getElementById('${inputId}').classList.remove('bg-blue-50', 'text-blue-700'); }"` 
            : `onchange="window.handleTypeChange && window.handleTypeChange(this, '${mappedType}')"`;

        let selectHtml = `<select name="${typeNameAttr}" ${autoChangeLogic} class="w-full bg-white border border-slate-200 rounded-xl px-3 py-3 font-bold text-xs uppercase tracking-widest outline-none focus:border-blue-500 cursor-pointer shadow-sm">`;
        
        groups.forEach(g => {
            selectHtml += `<optgroup label="${g.label}">`;
            g.options.forEach(t => {
                selectHtml += `<option value="${t.val}" ${t.val === mappedType ? 'selected' : ''}>${t.label}</option>`;
            });
            selectHtml += `</optgroup>`;
        });
        selectHtml += `</select>`;
        
        typeWrapper.innerHTML = selectHtml;

        const pdfWrapper = document.createElement('div');
        pdfWrapper.className = 'w-full sm:w-48 shrink-0';
        pdfWrapper.innerHTML = `
            <input type="text" name="${pdfNameAttr}" value="${presetPdfName}" placeholder="PDF Field Name..." title="Eksaktong pangalan sa PDF (Hal: religion)" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-3 font-bold text-xs outline-none focus:border-blue-500 shadow-sm transition-all">
        `;

        const btnWrapper = document.createElement('div');
        btnWrapper.className = 'w-full sm:w-auto shrink-0 flex justify-end';
        btnWrapper.innerHTML = `<button type="button" onclick="removeUnifiedField(this, '${presetId}', '${presetCategory}')" class="w-11 h-11 bg-white text-red-500 border border-slate-200 rounded-xl flex items-center justify-center hover:bg-red-500 hover:text-white transition-colors shadow-sm font-black text-xl leading-none">&times;</button>`;

        div.appendChild(inputWrapper); 
        div.appendChild(typeWrapper); 
        div.appendChild(pdfWrapper); 
        div.appendChild(btnWrapper); 
        container.appendChild(div);
        
        if(mappedType.startsWith('Auto_')) {
            document.getElementById(inputId).classList.add('bg-blue-50', 'text-blue-700');
        }
    }

    function removeUnifiedField(btnElement, id, category) {
        const row = btnElement.closest('.unified-field-row');
        if (id !== '') {
            if (confirm("⚠️ Delete this requirement? Existing data from students will be lost!")) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = `delete_${category}_ids[]`;
                hiddenInput.value = id;
                document.getElementById('edit-fields-container').appendChild(hiddenInput);
                if (row) row.remove();
            }
        } else {
            if (row) row.remove();
        }
    }

    // ✨ SHARED CHECKBOX LOCKING LOGIC (FOR BOTH CREATE & EDIT MODALS)
    function applyCheckboxLockState(modal, groupName) {
        const checkboxes = modal.querySelectorAll(`input[name="${groupName}[]"]`);
        const allCheckbox = modal.querySelector(`input[name="${groupName}[]"][value="all"]`);
        if (!checkboxes.length || !allCheckbox) return;

        const isAllChecked = allCheckbox.checked;

        checkboxes.forEach(cb => {
            if (cb !== allCheckbox) {
                const label = cb.closest('label');
                if (isAllChecked) {
                    cb.checked = false;
                    cb.disabled = true;
                    if (label) {
                        label.classList.add('opacity-40', 'cursor-not-allowed', 'pointer-events-none');
                        label.classList.remove('cursor-pointer', 'hover:border-blue-500', 'hover:bg-blue-50');
                    }
                } else {
                    cb.disabled = false;
                    if (label) {
                        label.classList.remove('opacity-40', 'cursor-not-allowed', 'pointer-events-none');
                        label.classList.add('cursor-pointer', 'hover:border-blue-500', 'hover:bg-blue-50');
                    }
                }
            }
        });
    }

    function setupCheckboxExclusivity(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        ['program_ids', 'year_levels'].forEach(groupName => {
            const checkboxes = modal.querySelectorAll(`input[name="${groupName}[]"]`);
            const allCheckbox = modal.querySelector(`input[name="${groupName}[]"][value="all"]`);
            if (!checkboxes.length || !allCheckbox) return;

            checkboxes.forEach(cb => {
                cb.addEventListener('change', () => {
                    applyCheckboxLockState(modal, groupName);
                });
            });

            // Initialize lock state immediately
            applyCheckboxLockState(modal, groupName);
        });
    }

    // Initialize Create Modal checkboxes on page load
    document.addEventListener('DOMContentLoaded', () => {
        setupCheckboxExclusivity('createModal');
    });

    function openCreateModal() {
        document.getElementById('create-fields-container').innerHTML = '';
        const modal = document.getElementById('createModal');
        
        // Reset "All" checkboxes to checked and lock specific options by default
        modal.querySelectorAll('input[value="all"]').forEach(cb => cb.checked = true);
        applyCheckboxLockState(modal, 'program_ids');
        applyCheckboxLockState(modal, 'year_levels');

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    
    function closeCreateModal() { 
        document.getElementById('createModal').classList.add('hidden');
        document.getElementById('createModal').classList.remove('flex'); 
    }

    function openEditModal(btn) {
        window.handleTypeChange = function(selectElement, originalType) {
            const newType = selectElement.value;
            if (newType !== originalType) {
                if (!confirm(`⚠️ WARNING: You are changing a requirement type.\n\nChanging from '${originalType}' to '${newType}' will permanently delete all student data already submitted for this specific requirement.\n\nThis action cannot be undone. Are you sure you want to proceed?`)) {
                    selectElement.value = originalType;
                }
            }
        };

        const newEditModal = document.getElementById('editModal').cloneNode(true);
        document.getElementById('editModal').parentNode.replaceChild(newEditModal, document.getElementById('editModal'));
        
        newEditModal.querySelector('[onclick="closeEditModal()"]').addEventListener('click', closeEditModal);

        document.getElementById('edit_id').value = btn.dataset.id;
        document.getElementById('edit_name').value = btn.dataset.name;
        document.getElementById('edit_desc').value = btn.dataset.desc;
        document.getElementById('edit_amount').value = btn.dataset.amount;
        document.getElementById('edit_release').value = btn.dataset.release || 'Per Semester';
        document.getElementById('edit_slots').value = btn.dataset.slots; 
        document.getElementById('edit_gpa').value = btn.dataset.gpa;
        document.getElementById('edit_deadline').value = btn.dataset.deadline;
        document.getElementById('edit_status').value = btn.dataset.status;
        document.getElementById('edit_gender').value = btn.dataset.gender;
        document.getElementById('edit_type').value = btn.dataset.type || 'Private';
        document.getElementById('edit_category').value = btn.dataset.category || 'Scholarship';
        document.getElementById('edit_dual').value = btn.dataset.dual || 'No';

        const fieldsContainer = document.getElementById('edit-fields-container');
        fieldsContainer.innerHTML = '';
        
        if (btn.dataset.requirements) { btn.dataset.requirements.split('||').forEach(req => { const parts = req.split('::'); addUnifiedField('edit-fields-container', parts[0], parts[1], 'Document', 'Req'); }); }
        if (btn.dataset.criteria) { btn.dataset.criteria.split('||').forEach(crit => { const parts = crit.split('::'); addUnifiedField('edit-fields-container', parts[0], parts[1], 'Criteria', 'Crit'); }); }
        // Application questions are managed in the Form Builder (form_builder.php)

        document.querySelectorAll('#editModal input[name="program_ids[]"]').forEach(cb => cb.checked = false);
        const progIds = btn.dataset.programs ? btn.dataset.programs.split(',').filter(Boolean) : [];
        if (progIds.length === 0) {
            document.querySelector('#editModal input[name="program_ids[]"][value="all"]').checked = true;
        } else {
            progIds.forEach(id => {
                const cb = document.querySelector(`#editModal input[name="program_ids[]"][value="${id.trim()}"]`);
                if(cb) cb.checked = true;
            });
        }

        document.querySelectorAll('#editModal input[name="year_levels[]"]').forEach(cb => cb.checked = false);
        const yearVals = btn.dataset.year ? btn.dataset.year.split(',').filter(Boolean) : [];
        if (yearVals.length === 0 || btn.dataset.year === '') {
            document.querySelector('#editModal input[name="year_levels[]"][value="all"]').checked = true;
        } else {
            yearVals.forEach(y => {
                const cleanY = y.trim();
                const cb = document.querySelector(`#editModal input[name="year_levels[]"][value="${cleanY}"]`);
                if(cb) cb.checked = true;
            });
        }

        setupCheckboxExclusivity('editModal');

        document.getElementById('editModal').classList.remove('hidden');
        document.getElementById('editModal').classList.add('flex');
    }
    
    function closeEditModal() { 
        const modal = document.getElementById('editModal');
        if(modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); }
    }
</script>
<?php include '../includes/footer.php'; ?>