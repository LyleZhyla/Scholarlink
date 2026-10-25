<?php 
session_start();

// ✨ 1. SESSION MANAGER & SECURITY
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
if ($is_super_admin) {
    include file_exists('../includes/super_sidebar.php') ? '../includes/super_sidebar.php' : '../includes/super_admin_sidebar.php';
} else {
    include '../includes/external_sidebar.php';
}

$active_tab         = $_GET['tab'] ?? 'pending'; 
$filter_course      = $_GET['course'] ?? '';
$filter_scholarship = $_GET['scholarship'] ?? '';

// Note: Ensure 'Pending' or 'Submitted' matches what your DB uses for new applications
$status_map = [
    'pending'     => 'Pending',
    'shortlisted' => 'Shortlisted',
    'approved'    => 'Approved',
    'rejected'    => 'Rejected'
];
$db_status = $status_map[$active_tab] ?? 'Pending';

$ext_admin_id = $_SESSION['user_id'] ?? $_SESSION['UserID'] ?? null;
$courses = $pdo->query("SELECT DISTINCT Major FROM users WHERE Major IS NOT NULL AND Major != '' ORDER BY Major ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($is_super_admin) {
    $scholarships = $pdo->query("SELECT ScholarshipID, Name FROM scholarship ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $all_custom_fields = $pdo->query("SELECT DISTINCT FieldName FROM scholarship_custom_fields ORDER BY FieldName ASC")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt_sch = $pdo->prepare("SELECT ScholarshipID, Name FROM scholarship WHERE CreatedBy = ? ORDER BY Name ASC");
    $stmt_sch->execute([$ext_admin_id]);
    $scholarships = $stmt_sch->fetchAll(PDO::FETCH_ASSOC);

    $stmt_cf = $pdo->prepare("
        SELECT DISTINCT scf.FieldName 
        FROM scholarship_custom_fields scf 
        JOIN scholarship s ON scf.ScholarshipID = s.ScholarshipID 
        WHERE s.CreatedBy = ? 
        ORDER BY scf.FieldName ASC
    ");
    $stmt_cf->execute([$ext_admin_id]);
    $all_custom_fields = $stmt_cf->fetchAll(PDO::FETCH_COLUMN);
}

// ✨ INSTANT SCORING QUERY (Supports both 'Pending' and 'Submitted' on the pending tab)
$status_condition = ($active_tab === 'pending') ? "a.Status IN ('Pending', 'Submitted')" : "a.Status = :status";

$query = "
    SELECT a.*, u.*, s.Name as ScholarshipName, s.MinimumGWA,
        (
            SELECT GROUP_CONCAT(sc.CriteriaName SEPARATOR '||')
            FROM scholarship_criteria sc
            WHERE sc.ScholarshipID = s.ScholarshipID
        ) AS CriteriaList,
        (
            SELECT GROUP_CONCAT(CONCAT(scf.FieldName, '~^~', scf.FieldType, '~^~', COALESCE(aca.AnswerText, '')) SEPARATOR '|~|')
            FROM application_custom_answers aca
            JOIN scholarship_custom_fields scf ON aca.FieldID = scf.FieldID
            WHERE aca.ApplicationID = a.ApplicationID
        ) AS CustomAnswers
    FROM application a
    JOIN users u ON a.UserID = u.UserID
    JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID
    WHERE $status_condition
";

$params = [];
if ($active_tab !== 'pending') {
    $params[':status'] = $db_status;
}

if (!$is_super_admin) {
    $query .= " AND s.CreatedBy = :ext_admin_id";
    $params[':ext_admin_id'] = $ext_admin_id;
}

if (!empty($filter_course)) {
    $query .= " AND u.Major = :course";
    $params[':course'] = $filter_course;
}
if (!empty($filter_scholarship)) {
    $query .= " AND a.ScholarshipID = :scholarship";
    $params[':scholarship'] = $filter_scholarship;
}

$query .= " ORDER BY a.DateSubmitted ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .modal-active { display: flex !important; animation: fadeIn 0.2s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
</style>

<main class="flex-1 bg-slate-50 min-h-screen pt-20 lg:pt-0 lg:ml-64 p-4 sm:p-6 lg:p-8 transition-all duration-300">
    <div class="max-w-7xl mx-auto">
        
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-8">
            <div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Application Masterlist</h1>
                <p class="text-sm font-bold text-slate-500 mt-1 uppercase tracking-widest">Evaluate & Verify Scholarships</p>
            </div>
            
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" onclick="openReportsModal()" class="bg-blue-600 text-white border border-blue-700 px-5 py-2.5 rounded-xl text-[12px] font-black uppercase tracking-widest hover:bg-blue-700 transition-all shadow-md flex items-center gap-2 active:scale-95">
                    📑 Generate Reports
                </button>

                <button type="button" onclick="openAnnexModal()" class="bg-green-600 text-white border border-green-700 px-4 py-2.5 rounded-xl text-[12px] font-black uppercase tracking-widest hover:bg-green-700 transition-all shadow-sm flex items-center gap-2 active:scale-95">
                    📊 Download Annex 1
                </button>
            </div>
        </div>

        <div class="flex overflow-x-auto gap-2 mb-6 border-b border-slate-200 pb-2 hide-scrollbar">
            <?php 
                $tabs = [
                    'pending' => ['label' => '1. Pending', 'icon' => 'fa-clock', 'color' => 'orange'],
                    'shortlisted' => ['label' => '2. Shortlisted', 'icon' => 'fa-star', 'color' => 'blue'],
                    'approved' => ['label' => '3. Approved Scholars', 'icon' => 'fa-check-circle', 'color' => 'green'],
                    'rejected' => ['label' => 'Rejected', 'icon' => 'fa-times-circle', 'color' => 'red']
                ];
                foreach ($tabs as $key => $t): 
                    $isActive = ($active_tab === $key);
            ?>
                <a href="?tab=<?= $key ?>&course=<?= urlencode($filter_course) ?>&scholarship=<?= urlencode($filter_scholarship) ?>" 
                   class="flex items-center gap-2 px-5 py-3 rounded-t-xl text-[13px] font-black uppercase tracking-widest transition-all whitespace-nowrap
                   <?= $isActive ? "bg-{$t['color']}-50 text-{$t['color']}-700 border-b-2 border-{$t['color']}-500" : "text-slate-500 hover:bg-slate-100" ?>">
                    <i class="fas <?= $t['icon'] ?>"></i> <?= $t['label'] ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="bg-white p-3 sm:p-4 rounded-[1.5rem] shadow-sm border border-slate-200 mb-8">
            <div class="flex flex-col lg:flex-row items-center gap-3">
                <div class="flex-1 w-full relative group">
                    <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                        <i class="fas fa-search text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                    </div>
                    <input type="text" id="liveSearch" oninput="predictiveSearch()" placeholder="Search name or ID..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 pl-11 pr-4 py-3.5 rounded-xl text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                </div>
                <div class="flex-1 w-full relative group">
                    <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                        <i class="fas fa-award text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                    </div>
                    <input type="search" id="scholarshipSearch" list="scholarshipList" oninput="predictiveSearch()" placeholder="Type scholarship..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 pl-11 pr-4 py-3.5 rounded-xl text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <datalist id="scholarshipList">
                        <?php foreach($scholarships as $s): ?>
                            <option value="<?= htmlspecialchars($s['Name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="flex-1 w-full relative group">
                    <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                        <i class="fas fa-graduation-cap text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                    </div>
                    <input type="search" id="courseSearch" list="courseList" oninput="predictiveSearch()" placeholder="Type course..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 pl-11 pr-4 py-3.5 rounded-xl text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <datalist id="courseList">
                        <?php foreach($courses as $c): ?>
                            <option value="<?= htmlspecialchars($c['Major']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>
        </div>

        <div id="applicationsGrid" class="flex flex-col gap-3">
            <div id="emptyState" class="py-16 text-center bg-white border border-dashed border-slate-300 rounded-[2rem] <?= empty($applications) ? '' : 'hidden' ?>">
                <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">
                    <i class="fas fa-folder-open"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900 mb-1">No <?= htmlspecialchars($db_status) ?> records</h3>
                <p class="text-sm font-bold text-slate-400">There are no applications matching your current search or filters.</p>
            </div>

            <?php foreach ($applications as $app): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition-all p-4 flex flex-col xl:flex-row items-start xl:items-center justify-between gap-4 group app-card">
                    
                    <div class="flex items-center gap-4 w-full xl:w-1/4 shrink-0">
                        <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center font-black text-lg shrink-0">
                            <?= substr(htmlspecialchars($app['FirstName']), 0, 1) ?>
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-sm font-black text-slate-900 leading-tight truncate" title="<?= htmlspecialchars($app['LastName'] . ', ' . $app['FirstName']) ?>">
                                <?= htmlspecialchars($app['LastName'] . ', ' . $app['FirstName']) ?>
                            </h3>
                            <p class="text-[11px] font-bold text-slate-500 uppercase tracking-widest mt-0.5 truncate">
                                APP #<?= str_pad($app['ApplicationID'], 5, '0', STR_PAD_LEFT) ?> &bull; <?= htmlspecialchars($app['StudentID_Num']) ?>
                            </p>
                        </div>
                    </div>

                    <div class="flex-1 min-w-0 w-full xl:w-auto xl:px-4">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Applied For & Course</p>
                        <p class="text-[13px] font-bold text-slate-800 truncate" title="<?= htmlspecialchars($app['ScholarshipName']) ?>"><?= htmlspecialchars($app['ScholarshipName']) ?></p>
                        <p class="text-[11px] font-bold text-slate-500 truncate"><?= htmlspecialchars($app['Major'] ?? 'N/A') ?></p>
                    </div>

                    <div class="flex items-center gap-6 w-full xl:w-auto shrink-0 justify-between xl:justify-end">
                        <div class="text-center xl:text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Min GWA</p>
                            <p class="text-[13px] font-black text-blue-600"><?= number_format($app['MinimumGWA'], 2) ?></p>
                        </div>
                        <div class="w-32 text-right">
                            <?php if($app['Status'] == 'Approved'): ?>
                                <span class="inline-flex items-center justify-center w-full gap-1.5 px-3 py-1.5 rounded-lg bg-green-50 text-green-700 text-[10px] font-black uppercase tracking-widest border border-green-200">
                                    <i class="fas fa-check-circle"></i> Active
                                </span>
                            <?php elseif($app['Status'] == 'Shortlisted'): ?>
                                <span class="inline-flex items-center justify-center w-full gap-1.5 px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 text-[10px] font-black uppercase tracking-widest border border-blue-200">
                                    <i class="fas fa-star"></i> Shortlisted
                                </span>
                            <?php elseif($app['Status'] == 'Rejected'): ?>
                                <span class="inline-flex items-center justify-center w-full gap-1.5 px-3 py-1.5 rounded-lg bg-red-50 text-red-700 text-[10px] font-black uppercase tracking-widest border border-red-200">
                                    <i class="fas fa-times-circle"></i> Rejected
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center justify-center w-full gap-1.5 px-3 py-1.5 rounded-lg bg-orange-50 text-orange-700 text-[10px] font-black uppercase tracking-widest border border-orange-200">
                                    <i class="fas fa-clock"></i> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 w-full xl:w-auto shrink-0 border-t xl:border-t-0 xl:border-l border-slate-100 pt-3 xl:pt-0 xl:pl-4 mt-2 xl:mt-0 justify-end">
                        <?php if ($active_tab == 'approved' || $active_tab == 'rejected'): ?>
                            <button type="button" onclick="openProfileModal(<?= $app['UserID'] ?>)" class="h-9 px-4 bg-white text-slate-700 border border-slate-200 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-slate-100 transition-all shadow-sm flex items-center gap-1.5">
                                <i class="fas fa-user-circle"></i> Profile
                            </button>
                        <?php else: ?>
                            <button onclick="openDocsModal(<?= $app['ApplicationID'] ?>, <?= $app['MinimumGWA'] ?: 0 ?>)" class="h-9 px-3 bg-white text-blue-600 border border-blue-200 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-50 transition-all shadow-sm flex items-center gap-1.5" title="Vault & Verify">
                                <i class="fas fa-folder-open"></i> Verify
                            </button>
                            <button type="button" onclick="triggerScoreModal(<?= $app['ApplicationID'] ?>)" class="h-9 px-4 bg-slate-900 text-white rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-slate-800 transition-all shadow-sm flex items-center justify-center gap-1.5">
                                <i class="fas fa-pen"></i> Evaluate
                            </button>

                            <!-- ✨ ITO ANG BAGONG APPROVE AT REJECT BUTTONS PARA SA EXTERNAL ADMIN -->
                            <?php if ($active_tab == 'shortlisted'): ?>
                                <form action="../actions/process_crud.php" method="POST" class="m-0 p-0 ml-2 border-l border-slate-200 pl-2 flex gap-2">
                                    <input type="hidden" name="module" value="applications">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= $app['ApplicationID'] ?>">
                                    
                                    <button type="submit" name="status" value="Rejected" onclick="return confirm('Reject this applicant?');" class="h-9 w-9 bg-white text-red-500 border border-red-200 rounded-xl flex items-center justify-center hover:bg-red-50 transition-all shadow-sm" title="Reject">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <button type="submit" name="status" value="Approved" onclick="return confirm('Approve this applicant as an official scholar?');" class="h-9 px-4 bg-green-600 text-white rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-green-700 transition-all shadow-sm flex items-center justify-center">
                                        Approve
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div id="paginationControls" class="flex justify-center gap-2 mt-8"></div>
    </div>
</main>

<!-- REPORTS MODAL -->
<div id="reportsModal" onclick="if(event.target === this) closeReportsModal()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Generate OSSD Reports</h3>
                <p class="text-xs text-blue-600 font-bold mt-1 uppercase tracking-widest">Follow format required by sponsor</p>
            </div>
            <button type="button" onclick="closeReportsModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm"><i class="fas fa-times"></i></button>
        </div>
        <form action="../actions/export_annex1_excel.php" method="POST" class="p-6 lg:p-8">
            <input type="hidden" name="export_tables[]" value="personal_info">
            <input type="hidden" name="export_tables[]" value="academic_profile">
            <input type="hidden" name="export_tables[]" value="family_background">
            <div class="space-y-6">
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Select Report Group</label>
                    <select name="status" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20">
                        <option value="Approved">🎓 Approved Scholars Only</option>
                        <option value="All_Applicants">📝 All Applicants (Pending, Shortlisted, Approved)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Target Scholarship</label>
                    <select name="scholarship_id" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20">
                        <option value="all">📂 All Scholarships (Separated by Excel Tabs)</option>
                        <?php foreach($scholarships as $s): ?><option value="<?= htmlspecialchars($s['ScholarshipID']) ?>"><?= htmlspecialchars($s['Name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Reporting Period</label>
                    <select name="period" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20">
                        <option value="all">Overall (All Time)</option>
                        <option value="monthly">This Month</option>
                        <option value="quarterly">This Quarter</option>
                        <option value="annual">This Year</option>
                    </select>
                </div>
            </div>
            <div class="mt-8 pt-6 border-t border-slate-100">
                <button type="submit" onclick="closeReportsModal()" class="w-full bg-blue-600 text-white px-6 py-4 rounded-xl font-black text-[13px] uppercase tracking-widest hover:bg-blue-700 transition-all shadow-md flex items-center justify-center gap-2">
                    <i class="fas fa-file-excel"></i> Generate Comprehensive Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ANNEX MODAL -->
<div id="annexModal" onclick="if(event.target === this) closeAnnexModal()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Export Annex 1</h3>
                <p class="text-xs text-green-600 font-bold mt-1 uppercase tracking-widest">CHED/TES Excel Format</p>
            </div>
            <button type="button" onclick="closeAnnexModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm"><i class="fas fa-times"></i></button>
        </div>
        <form action="../actions/export_annex1_excel.php" method="POST" class="p-6">
            <div class="space-y-6">
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Select Applicant Status</label>
                    <select name="status" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-green-500/20">
                        <option value="Approved" <?= $db_status == 'Approved' ? 'selected' : '' ?>>🎓 Approved Scholars</option>
                        <option value="Pending" <?= $db_status == 'Pending' ? 'selected' : '' ?>>🟠 Pending Applicants</option>
                        <option value="Shortlisted" <?= $db_status == 'Shortlisted' ? 'selected' : '' ?>>⭐ Shortlisted Applicants</option>
                        <option value="Rejected" <?= $db_status == 'Rejected' ? 'selected' : '' ?>>🔴 Rejected Applicants</option>
                        <option value="All_Applicants">📝 All Applicants (Combined)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Select Scholarship Group</label>
                    <select name="scholarship_id" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-green-500/20">
                        <option value="all">📂 All Scholarships (Separated by Tabs)</option>
                        <?php foreach($scholarships as $s): ?><option value="<?= htmlspecialchars($s['ScholarshipID']) ?>"><?= htmlspecialchars($s['Name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-3">Data to Include in Excel</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 bg-slate-50 p-4 rounded-xl border border-slate-200 h-48 overflow-y-auto">
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="lrn" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">LRN</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="philsys" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">PhilSys ID</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="4ps" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">4Ps ID</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="program" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Program/Course</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="year_level" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Year Level</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="father" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Father's Name</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="mother" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Mother's Name</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="street" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Street/Brgy</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="city" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">City</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="province" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Province</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="region" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Region</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="zipcode" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Zip Code</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="contact" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Contact No.</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="email" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Email</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="disability" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Disability</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="solo_parent" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">Solo Parent</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="first_gen" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">First Gen</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="fields[]" value="indigenous" checked class="w-4 h-4 text-green-600"> <span class="text-[10px] font-bold text-slate-700 uppercase">IP Group</span></label>
                        
                        <?php if(!empty($all_custom_fields)): ?>
                            <div class="col-span-full my-1 border-t border-slate-200"></div>
                            <?php foreach($all_custom_fields as $cf): ?>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="custom_fields_check[]" value="<?= htmlspecialchars($cf) ?>" checked class="w-4 h-4 text-blue-600"> 
                                    <span class="text-[10px] font-bold text-blue-800 uppercase"><?= htmlspecialchars($cf) ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <p class="text-[9px] text-slate-400 font-bold mt-2 uppercase tracking-widest text-center">Note: Names, Gender, and Birthdate are always included.</p>
                </div>
            </div>
            <div class="mt-8 pt-6 border-t border-slate-100">
                <button type="submit" onclick="closeAnnexModal()" class="w-full bg-green-600 text-white px-6 py-4 rounded-xl font-black text-[13px] uppercase tracking-widest hover:bg-green-700 shadow-md flex items-center justify-center gap-2 transition-all active:scale-95">
                    <i class="fas fa-file-excel"></i> Generate Excel File
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ULTIMATE VAULT MODAL (May Ajax Re-Verification Capability na!) -->
<div id="docsModal" onclick="if(event.target === this) closeDocsModal()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-3xl rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-xl font-black text-slate-900 tracking-tight">Applicant Document Vault</h3>
                <p class="text-xs font-bold text-blue-600 uppercase tracking-widest mt-1">Review & Re-Verify Uploaded Files</p>
            </div>
            <button type="button" onclick="closeDocsModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto bg-slate-50/50 flex-1">
            <div id="docsLoading" class="text-center py-10 hidden">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-600 mb-3"></i>
                <p class="text-sm font-bold text-slate-500">Retrieving documents...</p>
            </div>
            <div id="docsList" class="space-y-3"></div>
        </div>
    </div>
</div>

<!-- ✨ TRUE SCORING MODAL (Submit Fix & Close Button Added) -->
<div id="scoreModal" onclick="if(event.target === this) closeScoreModal()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-4xl rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <form onsubmit="submitScoreAJAX(event)" class="flex flex-col h-full">
            <input type="hidden" name="module" value="applications">
            <input type="hidden" name="action" value="score">
            <input type="hidden" id="modalAppId" name="id">
            <input type="hidden" id="finalScoreInput" name="score">

            <div class="p-8 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0">
                <div>
                    <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Merit Rubric</h3>
                    <p id="modalStudentName" class="text-md text-blue-600 font-bold mt-1 uppercase tracking-widest">Name</p>
                </div>
                <div class="flex items-center gap-6">
                    <div class="text-right">
                        <p class="text-3xl font-black text-green-600"><span id="displayTotal">0</span><span class="text-md text-slate-300">/100</span></p>
                    </div>
                    <button type="button" onclick="closeScoreModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                        <i class="fas fa-times"></i>
                    </button>
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
                    <div id="modalCustomAnswers" class="grid grid-cols-1 md:grid-cols-2 gap-4"></div>
                </div>

                <div class="border-t border-slate-100 pt-6">
                    <h4 class="text-[14px] font-black uppercase tracking-widest mb-4 text-slate-900">Scoring Breakdown</h4>
                    <div id="dynamicCriteriaContainer" class="space-y-3"></div>
                </div>
            </div>

            <div class="p-8 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeScoreModal()" class="px-6 py-3 font-black text-[14px] uppercase tracking-widest text-slate-500 hover:text-red-500 transition-colors">Cancel</button>
                <!-- ✨ FIX: Ang button na ito ay nag-e-enable na agad basta may natype kang grade kahit zero! -->
                <button type="submit" id="submitBtn" disabled class="bg-slate-300 text-slate-500 px-10 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest transition-all shadow-sm">Submit Final Score</button>
            </div>
        </form>
    </div>
</div>

<!-- DOCUMENT VIEWER MODAL WITH AI SCANNER PANEL -->
<div id="docViewerModal" onclick="if(event.target === this) closeViewerModal()" class="fixed inset-0 z-[100000] bg-slate-950/90 backdrop-blur-md hidden items-center justify-center p-4 md:p-10">
    <div class="bg-white w-full max-w-5xl h-full max-h-[90vh] rounded-[1.5rem] lg:rounded-[2rem] shadow-2xl relative flex flex-col overflow-hidden">
        <div class="flex justify-between items-center p-4 lg:p-6 border-b border-slate-100 bg-slate-50 relative z-20">
            <div>
                <h3 id="modalDocTitle" class="text-lg lg:text-xl font-black text-slate-900 uppercase tracking-tight">Document Viewer</h3>
                <p class="text-[14px] lg:text-xs font-bold text-slate-400 mt-1">ScholarLink Secure File Preview</p>
            </div>
            <div class="flex items-center gap-4">
                <button type="button" id="aiScanBtn" onclick="runAIScanner()" class="flex items-center justify-center gap-2 bg-gradient-to-r from-purple-600 to-indigo-600 text-white px-6 py-2.5 rounded-full font-black text-[12px] uppercase tracking-widest shadow-lg shadow-purple-500/30 hover:scale-105 transition-all">
                    <span id="aiScanIcon" class="text-lg">🪄</span> 
                    <span id="aiScanText">AI Verify Authenticity</span>
                </button>
                <button type="button" onclick="closeViewerModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <div id="viewerCanvas" class="flex-1 bg-slate-200 overflow-hidden relative flex items-center justify-center">
            <img id="docImage" src="" draggable="false" class="hidden max-h-[80vh] max-w-full rounded-xl shadow-2xl bg-white select-none">
            <iframe id="docIframe" src="" class="hidden w-full h-[70vh] rounded-xl border border-slate-300 shadow-sm bg-white relative z-10" frameborder="0"></iframe>
            
            <div id="aiAnalysisResults" class="hidden absolute top-4 right-4 md:top-8 md:right-8 w-[90%] md:w-80 bg-white/95 backdrop-blur-xl border border-slate-200 shadow-2xl rounded-[1.5rem] p-6 z-50 transition-all">
                <h4 class="text-sm font-black text-slate-900 mb-4 border-b border-slate-100 pb-3 flex items-center gap-2">
                    <span class="text-lg">🤖</span> AI Analysis Report
                </h4>
                <div class="mb-5">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Authenticity & Layout Check</p>
                    <div id="aiAuthStatus" class="flex items-center gap-2 text-sm font-black text-slate-900 bg-slate-50 p-2.5 rounded-xl border border-slate-100">--</div>
                    <p id="aiAuthDetails" class="text-[10px] font-bold text-slate-500 mt-2 leading-relaxed">Waiting for scan...</p>
                </div>
                <div class="mb-6">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Qualification Check</p>
                    <div id="aiQualStatus" class="flex items-center gap-2 text-sm font-black text-slate-900 bg-slate-50 p-2.5 rounded-xl border border-slate-100">--</div>
                    <p id="aiQualDetails" class="text-[10px] font-bold text-slate-500 mt-2 leading-relaxed">Waiting for scan...</p>
                </div>
                <button type="button" onclick="document.getElementById('aiAnalysisResults').classList.add('hidden')" class="w-full bg-slate-900 text-white py-3 rounded-xl text-xs font-black uppercase tracking-widest shadow-md hover:bg-slate-800 transition-all active:scale-95">Dismiss</button>
            </div>

            <div id="zoomControls" class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-slate-900/90 backdrop-blur-md p-2 rounded-2xl flex items-center gap-2 z-40 hidden shadow-2xl border border-white/10">
                <button type="button" onclick="zoomImage(-0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all">➖</button>
                <button type="button" onclick="resetZoom()" class="px-4 h-10 bg-white/10 hover:bg-white/20 text-white font-black text-[12px] rounded-xl flex items-center justify-center transition-all uppercase tracking-widest">Reset</button>
                <button type="button" onclick="zoomImage(0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all">➕</button>
            </div>
        </div>
    </div>
</div>

<script>
    let scholarsData = <?= json_encode($applications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    
    const masterCards = Array.from(document.querySelectorAll('.app-card'));
    let filteredCards = [...masterCards];
    const itemsPerPage = 9;
    let currentPage = 1;

    function renderPagination() {
        masterCards.forEach(card => card.style.display = 'none');
        
        const emptyState = document.getElementById('emptyState');
        const paginationControls = document.getElementById('paginationControls');

        if(filteredCards.length === 0) {
            if(emptyState) emptyState.classList.remove('hidden');
            if(paginationControls) paginationControls.innerHTML = '';
            return;
        } else {
            if(emptyState) emptyState.classList.add('hidden');
        }

        const totalPages = Math.ceil(filteredCards.length / itemsPerPage);
        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;

        filteredCards.slice(start, end).forEach(card => {
            card.style.display = 'flex';
        });

        let btnHtml = `<button type="button" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&larr;</button>`;
        for(let i = 1; i <= totalPages; i++) {
            btnHtml += `<button type="button" onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-slate-900 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">${i}</button>`;
        }
        btnHtml += `<button type="button" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&rarr;</button>`;
        
        if(paginationControls) paginationControls.innerHTML = btnHtml;
    }

    function changePage(page) {
        const totalPages = Math.ceil(filteredCards.length / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
        document.getElementById('applicationsGrid').scrollIntoView({ behavior: 'smooth' });
    }

    function predictiveSearch() {
        const query = document.getElementById('liveSearch').value.toLowerCase();
        const schQuery = document.getElementById('scholarshipSearch').value.toLowerCase();
        const courseQuery = document.getElementById('courseSearch').value.toLowerCase();

        filteredCards = masterCards.filter(card => {
            const text = card.textContent.toLowerCase();
            return text.includes(query) && text.includes(schQuery) && text.includes(courseQuery);
        });
        
        currentPage = 1;
        renderPagination();
    }
    
    document.addEventListener('DOMContentLoaded', () => { renderPagination(); });

    function openReportsModal() { document.getElementById('reportsModal').classList.remove('hidden'); document.getElementById('reportsModal').classList.add('flex'); }
    function closeReportsModal() { document.getElementById('reportsModal').classList.add('hidden'); document.getElementById('reportsModal').classList.remove('flex'); }
    function openAnnexModal() { document.getElementById('annexModal').classList.remove('hidden'); document.getElementById('annexModal').classList.add('flex'); }
    function closeAnnexModal() { document.getElementById('annexModal').classList.add('hidden'); document.getElementById('annexModal').classList.remove('flex'); }

    // ✨ SCORING MODAL LOGIC (Submit Button Fix)
    function triggerScoreModal(appId) {
        const app = scholarsData.find(a => a.ApplicationID == appId);

        if(!app) {
            alert("Applicant data not found.");
            return;
        }

        document.getElementById('modalAppId').value = app.ApplicationID;
        document.getElementById('modalStudentName').innerText = app.FirstName + ' ' + app.LastName;
        document.getElementById('refGPA').innerText = app.GPA || 'N/A';

        const ansContainer = document.getElementById('modalCustomAnswers');
        ansContainer.innerHTML = '';
        
        if (app.CustomAnswers && app.CustomAnswers.trim() !== '') {
            const answers = app.CustomAnswers.split('|~|');
            answers.forEach(ans => {
                const parts = ans.split('~^~');
                if(parts.length >= 3) {
                    const fieldName = parts[0];
                    const fieldType = parts[1];
                    const answerText = parts[2];

                    let safeAnswer = answerText ? answerText.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '<i>No answer provided</i>';
                    let html = `<div class="${fieldType === 'Paragraph' || fieldType === 'Textarea' ? 'col-span-full' : ''}">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">${fieldName}</p>`;
                    if (fieldType === 'Paragraph' || fieldType === 'Textarea') {
                        html += `<div class="p-5 bg-slate-50 rounded-xl border border-slate-100 text-sm text-slate-700 leading-relaxed whitespace-pre-wrap font-medium">${safeAnswer}</div>`;
                    } else {
                        html += `<p class="font-bold text-slate-900 text-sm p-4 bg-slate-50 border border-slate-100 rounded-xl">${safeAnswer}</p>`;
                    }
                    html += `</div>`;
                    ansContainer.innerHTML += html;
                }
            });
        } else {
            ansContainer.innerHTML = '<p class="text-sm font-medium text-slate-400 italic col-span-full">No custom form answers provided.</p>';
        }

        const criteriaContainer = document.getElementById('dynamicCriteriaContainer');
        criteriaContainer.innerHTML = '';
        let criteria = [];
        if (app.CriteriaList) { 
            criteria = app.CriteriaList.split('||').filter(c => c.trim() !== ''); 
        }

        if (criteria.length > 0) {
            criteria.forEach(crit => {
                criteriaContainer.innerHTML += `
                    <div class="bg-white p-4 border border-slate-200 rounded-xl flex items-center justify-between gap-4 hover:border-purple-300 transition-colors">
                        <label class="text-md font-black uppercase tracking-wide text-black flex-1">${crit}</label>
                        <div class="w-32 relative">
                            <input type="number" oninput="calculateTotal()" required min="0" max="100" placeholder="Score" class="dynamic-score-input w-full bg-slate-50 border border-slate-200 px-8 py-2 rounded-lg font-black text-black outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all text-center">
                        </div>
                    </div>`;
            });
        } else {
            criteriaContainer.innerHTML = `
                <div class="bg-white p-4 border border-slate-200 rounded-xl flex items-center justify-between gap-4 hover:border-purple-300 transition-colors">
                    <label class="text-md font-black uppercase tracking-wide text-black flex-1">Overall Merit Score</label>
                    <div class="w-32 relative">
                        <input type="number" oninput="calculateTotal()" required min="0" max="100" placeholder="Score" class="dynamic-score-input w-full bg-slate-50 border border-slate-200 px-8 py-2 rounded-lg font-black text-black outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all text-center">
                    </div>
                </div>`;
        }

        calculateTotal();
        document.getElementById('scoreModal').classList.remove('hidden');
        document.getElementById('scoreModal').classList.add('flex');
        document.body.style.overflow = 'hidden';
    }

    // ✨ FIX: Awtomatiko nang mao-open ang button kapag may itinype ka, kahit "0" pa ang score!
    function calculateTotal() {
        const inputs = document.querySelectorAll('.dynamic-score-input');
        let total = 0;
        let isFilled = false;

        inputs.forEach(input => { 
            total += parseInt(input.value) || 0; 
            if(input.value !== '') isFilled = true; // Basta may nai-type, isFilled na
        });
        if (total > 100) total = 100; 

        document.getElementById('displayTotal').innerText = total;
        document.getElementById('finalScoreInput').value = total;
        
        const btn = document.getElementById('submitBtn');
        if (isFilled) { 
            btn.disabled = false; 
            btn.className = 'bg-slate-900 text-white px-10 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-green-600 shadow-xl transition-all active:scale-[0.98]'; 
        } else { 
            btn.disabled = true; 
            btn.className = 'bg-slate-300 text-slate-500 px-10 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest transition-all'; 
        }
    }

    async function submitScoreAJAX(event) {
        event.preventDefault();
        const btn = document.getElementById('submitBtn');
        const origText = btn.innerText;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        btn.disabled = true;

        const formData = new FormData(event.target);
        
        try {
            await fetch('../actions/process_crud.php', { method: 'POST', body: formData });
            alert('Applicant has been successfully evaluated!');
            closeScoreModal();
            location.reload(); 
        } catch(e) {
            alert('Failed to submit score.');
            btn.innerHTML = origText;
            btn.disabled = false;
        }
    }

    function closeScoreModal() {
        document.getElementById('scoreModal').classList.add('hidden');
        document.getElementById('scoreModal').classList.remove('flex');
        document.body.style.overflow = 'auto';
    }

    // ✨ ALWAYS-ACTIVE VAULT BUTTONS LOGIC
    async function openDocsModal(appId, minGWA) {
        const modal = document.getElementById('docsModal');
        const list = document.getElementById('docsList');
        const loader = document.getElementById('docsLoading');
        
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        
        list.innerHTML = '';
        loader.classList.remove('hidden');

        try {
            const timestamp = new Date().getTime(); 
            const response = await fetch(`../actions/get_application_docs.php?app_id=${appId}&t=${timestamp}`);
            const docs = await response.json();
            
            loader.classList.add('hidden');
            
            if (docs.length === 0) {
                list.innerHTML = `<div class="text-center py-6 text-slate-500 font-bold text-sm bg-white rounded-xl border border-slate-200">No documents uploaded yet.</div>`;
                return;
            }

            docs.forEach(doc => {
                const ext = doc.FilePath.split('.').pop().toLowerCase();
                const isImage = ['jpg', 'jpeg', 'png'].includes(ext);
                const icon = isImage ? 'fa-image text-purple-500' : 'fa-file-pdf text-red-500';
                
                let statusBadge = '';
                if(doc.VerificationStatus === 'Verified') {
                    statusBadge = `<span class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border border-green-200 ml-2">Verified</span>`;
                } else if(doc.VerificationStatus === 'Rejected') {
                    statusBadge = `<span class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border border-red-200 ml-2">Rejected</span>`;
                } else {
                    statusBadge = `<span class="bg-orange-100 text-orange-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border border-orange-200 ml-2">Pending</span>`;
                }

                // ✨ Pinasadyang Verify at Reject Buttons na laging nakikita!
                let actionForms = `
                    <div class="flex gap-2 m-0 ml-2">
                        <button type="button" 
                            onclick="verifyDocAJAX(${doc.SubmittedDocID}, 'Rejected', ${appId}, ${minGWA})" 
                            class="px-3 py-1.5 rounded-lg text-[10px] font-black uppercase transition-all shadow-sm active:scale-95 border 
                            ${doc.VerificationStatus === 'Rejected' ? 'bg-red-500 text-white border-red-600' : 'bg-white text-red-500 hover:bg-red-50 border-red-200'}">
                            ${doc.VerificationStatus === 'Rejected' ? 'Rejected' : 'Reject'}
                        </button>
                        
                        <button type="button" 
                            onclick="verifyDocAJAX(${doc.SubmittedDocID}, 'Verified', ${appId}, ${minGWA})" 
                            class="px-3 py-1.5 rounded-lg text-[10px] font-black uppercase transition-all shadow-sm active:scale-95 border 
                            ${doc.VerificationStatus === 'Verified' ? 'bg-green-600 text-white border-green-700' : 'bg-slate-900 text-white hover:bg-green-600 border-slate-900'}">
                            ${doc.VerificationStatus === 'Verified' ? 'Verified' : 'Verify'}
                        </button>
                    </div>
                `;

                list.innerHTML += `
                    <div class="flex items-center justify-between p-4 bg-white rounded-xl border border-slate-200 shadow-sm hover:border-blue-300 transition-colors">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg bg-slate-50 flex items-center justify-center text-xl shrink-0"><i class="fas ${icon}"></i></div>
                            <div class="min-w-0 pr-2">
                                <h4 class="text-sm font-bold text-slate-900 flex items-center flex-wrap gap-y-1">${doc.DocumentType} ${statusBadge}</h4>
                                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">${ext.toUpperCase()} File</p>
                            </div>
                        </div>
                        <div class="flex items-center">
                            <button type="button" onclick="openViewerModal('${doc.FilePath}', '${doc.DocumentType}', ${minGWA}, ${appId})" class="bg-blue-50 hover:bg-blue-600 text-blue-600 hover:text-white px-4 py-2 rounded-lg text-[11px] font-black uppercase tracking-widest transition-colors border border-blue-200">
                                View 👁️
                            </button>
                            ${actionForms}
                        </div>
                    </div>
                `;
            });
        } catch (error) {
            loader.classList.add('hidden');
            list.innerHTML = `<div class="text-center py-6 text-red-500 font-bold text-sm bg-red-50 rounded-xl">Error loading documents.</div>`;
        }
    }

    async function verifyDocAJAX(docId, status, appId, minGWA) {
        if(!confirm(`Are you sure you want to mark this document as ${status}?`)) return;

        try {
            await fetch('../actions/process_crud.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'module': 'verification',
                    'action': 'update_status', 
                    'id': docId,
                    'status': status,
                    'return_url': 'manage_applications.php'
                })
            });
            openDocsModal(appId, minGWA); 
        } catch (error) {
            alert("Error updating document status.");
        }
    }

    function closeDocsModal() {
        document.getElementById('docsModal').classList.add('hidden');
        document.getElementById('docsModal').classList.remove('flex');
        document.body.style.overflow = 'auto';
    }

    let currentZoom = 1; let translateX = 0; translateY = 0; 
    let currentViewedFile = ""; let currentReqGWA = 0; let currentAppID = 0;
    const imgElement = document.getElementById('docImage'); const viewerCanvas = document.getElementById('viewerCanvas');

    function applyTransform() { imgElement.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`; }
    function zoomImage(step) { currentZoom = Math.max(0.5, Math.min(5, currentZoom + step)); applyTransform(); }
    function resetZoom() { currentZoom = 1; translateX = 0; translateY = 0; applyTransform(); }

    viewerCanvas.addEventListener('wheel', e => { if (!imgElement.classList.contains('hidden')) { e.preventDefault(); zoomImage(e.deltaY > 0 ? -0.1 : 0.1); } }, { passive: false });

    function openViewerModal(fileUrl, docType, reqGWA, appID) {
        currentViewedFile = fileUrl; currentReqGWA = reqGWA; currentAppID = appID;
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
        document.getElementById('docViewerModal').classList.add('modal-active');
    }

    function closeViewerModal() {
        document.getElementById('docViewerModal').classList.remove('modal-active');
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

                const authEl = document.getElementById('aiAuthStatus');
                const authDet = document.getElementById('aiAuthDetails');
                
                if(data.authenticity.is_authentic) {
                    authEl.innerHTML = `<span class="text-green-500 text-lg">🛡️</span> <span class="text-green-700">Valid Document Match</span>`;
                    authDet.innerHTML = `Layout and structure match a standard <b>${expectedDocType}</b>.<br><b>Confidence:</b> ${data.authenticity.confidence}%`;
                } else {
                    authEl.innerHTML = `<span class="text-red-500 text-lg">🚨</span> <span class="text-red-700">MISMATCH / FAKE</span>`;
                    authDet.innerHTML = `<b>Reason:</b> ${data.authenticity.flag_reason}`;
                }

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

            } else { throw new Error(data.error || "Scan failed"); }
        } catch (error) {
            icon.classList.remove('animate-spin'); btn.classList.remove('bg-slate-800'); btn.classList.add('from-red-500', 'to-rose-600'); icon.innerText = "❌"; text.innerText = "SCAN ERROR";
            alert(error.message);
        }

        setTimeout(() => { btn.classList.remove('from-green-500', 'to-emerald-600', 'from-red-500', 'to-rose-600', 'cursor-wait'); btn.classList.add('from-purple-600', 'to-indigo-600'); icon.innerText = "🪄"; text.innerText = "AI Verify Authenticity"; }, 3000);
    }
</script>

<!-- STUDENT PROFILE MODAL -->
<div id="profileModal" onclick="if(event.target === this) closeProfileModal()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9998] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-4xl rounded-[2.5rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Student Profile</h3>
                <p class="text-[14px] text-blue-600 font-bold mt-1 uppercase tracking-widest">Detailed Scholar Overview</p>
            </div>
            <button type="button" onclick="closeProfileModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-10">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="text-center bg-slate-50 p-8 rounded-[2rem] border border-slate-200">
                    <div id="prof-initial" class="w-24 h-24 bg-blue-600 text-white rounded-[2rem] flex items-center justify-center text-4xl font-black mx-auto mb-6 shadow-xl shadow-blue-200">A</div>
                    <h3 id="prof-name" class="text-xl font-black text-slate-900 mb-1 leading-tight">Name</h3>
                    <p id="prof-id" class="text-[14px] font-black text-slate-900 uppercase tracking-widest">ID Number</p>
                </div>
                
                <div class="lg:col-span-2 space-y-6 max-h-[60vh] overflow-y-auto pr-2">
                    <h4 class="text-[11px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-2">Basic Info</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Program</p>
                            <p id="prof-program" class="text-sm font-bold text-slate-900 truncate">N/A</p>
                        </div>
                        <div class="bg-green-50 p-3 rounded-xl border border-green-100">
                            <p class="text-[10px] font-black text-green-600 uppercase tracking-widest mb-1">Verified GWA</p>
                            <p id="prof-gwa" class="text-sm font-black text-green-700">0.00</p>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Email & Contact</p>
                            <p class="text-xs font-bold text-blue-600 truncate mb-0.5" id="prof-email">N/A</p>
                            <p class="text-xs font-bold text-slate-900" id="prof-contact">N/A</p>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Gender & Birth Date</p>
                            <p class="text-xs font-bold text-slate-900 mb-0.5" id="prof-gender">N/A</p>
                            <p class="text-xs font-bold text-slate-900" id="prof-dob">N/A</p>
                        </div>
                    </div>

                    <h4 class="text-[11px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-2 mt-4">Identifications</h4>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">LRN</p>
                            <p id="prof-lrn" class="text-xs font-bold text-slate-900 truncate">N/A</p>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">PhilSys ID</p>
                            <p id="prof-philsys" class="text-xs font-bold text-slate-900 truncate">N/A</p>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">4Ps ID</p>
                            <p id="prof-4ps" class="text-xs font-bold text-slate-900 truncate">N/A</p>
                        </div>
                    </div>

                    <h4 class="text-[11px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-2 mt-4">Background & Status</h4>
                    <div class="grid grid-cols-1 gap-4">
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Complete Address</p>
                            <p id="prof-address" class="text-xs font-bold text-slate-900">N/A</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Father's Name</p>
                                <p id="prof-father" class="text-xs font-bold text-slate-900 truncate">N/A</p>
                            </div>
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Mother's Maiden Name</p>
                                <p id="prof-mother" class="text-xs font-bold text-slate-900 truncate">N/A</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Disability / IP Group</p>
                                <p class="text-xs font-bold text-slate-900 truncate"><span id="prof-disability">None</span> / <span id="prof-indigenous">None</span></p>
                            </div>
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Solo Parent Child / First Gen</p>
                                <p class="text-xs font-bold text-slate-900 truncate"><span id="prof-soloparent">No</span> / <span id="prof-firstgen">No</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function openProfileModal(uid) {
        const s = scholarsData.find(item => item.UserID == uid);
        if(!s) return;

        document.getElementById('prof-initial').innerText = s.FirstName.charAt(0);
        document.getElementById('prof-name').innerText = s.FirstName + ' ' + s.LastName;
        document.getElementById('prof-id').innerText = s.StudentID_Num || 'N/A';
        document.getElementById('prof-program').innerText = s.Major || 'N/A';
        document.getElementById('prof-gwa').innerText = s.GPA || '0.00';
        document.getElementById('prof-email').innerText = s.Email || 'N/A';
        document.getElementById('prof-contact').innerText = s.ContactNumber || 'N/A';
        
        document.getElementById('prof-gender').innerText = s.Gender || 'N/A';
        document.getElementById('prof-dob').innerText = s.DateOfBirth || 'N/A';
        document.getElementById('prof-lrn').innerText = s.LRN || 'N/A';
        document.getElementById('prof-philsys').innerText = s.PhilSysID || 'N/A';
        document.getElementById('prof-4ps').innerText = s.FourPsID || 'N/A';
        
        const address = [s.StreetBarangay, s.CityMunicipality, s.Province, s.ZipCode].filter(Boolean).join(', ');
        document.getElementById('prof-address').innerText = address || 'N/A';

        const father = [s.FatherFirstName, s.FatherMiddleName, s.FatherLastName].filter(Boolean).join(' ');
        const mother = [s.MotherFirstName, s.MotherMiddleName, s.MotherLastName].filter(Boolean).join(' ');
        document.getElementById('prof-father').innerText = father || 'N/A';
        document.getElementById('prof-mother').innerText = mother || 'N/A';

        document.getElementById('prof-disability').innerText = s.DisabilityType || 'None';
        document.getElementById('prof-indigenous').innerText = s.IndigenousGroup || 'None';
        document.getElementById('prof-soloparent').innerText = s.IsSoloParentChild || 'No';
        document.getElementById('prof-firstgen').innerText = s.IsFirstGenStudent || 'No';
        
        document.getElementById('profileModal').classList.remove('hidden');
        document.getElementById('profileModal').classList.add('flex');
    }

    function closeProfileModal() {
        document.getElementById('profileModal').classList.add('hidden');
        document.getElementById('profileModal').classList.remove('flex');
    }
</script>

<?php include '../includes/footer.php'; ?>