<?php 
session_start();

// ✨ 1. SESSION MANAGER & SECURITY
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

$active_tab = $_GET['tab'] ?? 'pending'; 

$status_map = [
    'pending'     => 'Pending',
    'shortlisted' => 'Shortlisted',
    'approved'    => 'Approved',
    'rejected'    => 'Rejected'
];
$db_status = $status_map[$active_tab] ?? 'Pending';

$courses = $pdo->query("SELECT DISTINCT Major FROM users WHERE Major IS NOT NULL AND Major != '' ORDER BY Major ASC")->fetchAll(PDO::FETCH_ASSOC);
$scholarships = $pdo->query("SELECT ScholarshipID, Name FROM scholarship ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);

$all_custom_fields = $pdo->query("SELECT DISTINCT FieldName FROM scholarship_custom_fields ORDER BY FieldName ASC")->fetchAll(PDO::FETCH_COLUMN);

$query = "
    SELECT a.*, u.*, s.Name as ScholarshipName, s.MinimumGWA,
           " . INTERNALLY_FUNDED_SQL . " AS IsInternal,
           COALESCE(NULLIF(cu.Organization, ''), CONCAT(cu.FirstName, ' ', cu.LastName)) AS ProviderName
    FROM application a
    JOIN users u ON a.UserID = u.UserID
    JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID
    LEFT JOIN users cu ON cu.UserID = s.CreatedBy
    WHERE 1=1
";
$params = [];

if ($active_tab === 'pending') {
    $query .= " AND a.Status IN ('Pending', 'Submitted', 'Under Review')";
} elseif ($active_tab === 'shortlisted') {
    $query .= " AND a.Status = 'Shortlisted'";
} elseif ($active_tab === 'approved') {
    $query .= " AND a.Status = 'Approved'";
} elseif ($active_tab === 'rejected') {
    $query .= " AND a.Status = 'Rejected'";
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
                <p class="text-sm font-bold text-slate-500 mt-1 uppercase tracking-widest">Manage All Scholarship Phases</p>
            </div>
            
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" onclick="openReportsModal()" class="bg-blue-600 text-white border border-blue-700 px-5 py-2.5 rounded-xl text-[12px] font-black uppercase tracking-widest hover:bg-blue-700 transition-all shadow-md flex items-center gap-2 active:scale-95">
                    📑 Generate Reports
                </button>

                <button type="button" onclick="openAnnexModal()" class="bg-green-600 text-white border border-green-700 px-4 py-2.5 rounded-xl text-[12px] font-black uppercase tracking-widest hover:bg-green-700 transition-all shadow-sm flex items-center gap-2 active:scale-95">
                    📊 Export <?= htmlspecialchars($db_status) ?> (Annex 1)
                </button>
            </div>
        </div>

        <!-- TABS -->
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
                <a href="?tab=<?= $key ?>" 
                   class="flex items-center gap-2 px-5 py-3 rounded-t-xl text-[13px] font-black uppercase tracking-widest transition-all whitespace-nowrap
                   <?= $isActive ? "bg-{$t['color']}-50 text-{$t['color']}-700 border-b-2 border-{$t['color']}-500" : "text-slate-500 hover:bg-slate-100" ?>">
                    <i class="fas <?= $t['icon'] ?>"></i> <?= $t['label'] ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ✨ PREDICTIVE SEARCH & FILTER BAR -->
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
                    <input type="search" id="courseSearch" list="courseList" oninput="predictiveSearch()" placeholder="Type course/major..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 pl-11 pr-4 py-3.5 rounded-xl text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <datalist id="courseList">
                        <?php foreach($courses as $c): ?>
                            <option value="<?= htmlspecialchars($c['Major']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <button type="button" onclick="clearFilters()" class="w-full lg:w-auto shrink-0 bg-slate-900 text-white px-6 py-3.5 rounded-xl font-black text-[12px] uppercase tracking-widest hover:bg-slate-800 transition-all shadow-sm active:scale-95">
                    Reset
                </button>
            </div>
        </div>

        <div id="applicationsGrid" class="flex flex-col gap-3">
            <!-- Dynamic No Results Message -->
            <div id="emptyState" class="py-16 text-center bg-white border border-dashed border-slate-300 rounded-[2rem] <?= empty($applications) ? '' : 'hidden' ?>">
                <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">
                    <i class="fas fa-folder-open"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900 mb-1">No <?= htmlspecialchars($db_status) ?> records</h3>
                <p class="text-sm font-bold text-slate-400">There are no applications matching your current search or filters.</p>
            </div>

            <?php foreach ($applications as $app): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition-all p-4 flex flex-col xl:flex-row items-start xl:items-center justify-between gap-4 group app-card"
                     data-name="<?= strtolower(htmlspecialchars($app['FirstName'] . ' ' . $app['LastName'] . ' ' . $app['StudentID_Num'])) ?>"
                     data-scholarship="<?= strtolower(htmlspecialchars($app['ScholarshipName'])) ?>"
                     data-course="<?= strtolower(htmlspecialchars($app['Major'] ?? '')) ?>">
                    
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
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">GWA</p>
                            <div class="flex items-center justify-center xl:justify-end gap-1.5">
                                <p class="text-[13px] font-black text-green-600" id="gwa_display_<?= $app['UserID'] ?>"><?= number_format($app['GPA'], 2) ?></p>
                                <button type="button" onclick="editGWA(<?= $app['UserID'] ?>, <?= $app['GPA'] ?>)" class="text-slate-300 hover:text-blue-600 transition-colors" title="Update Verified GWA">
                                    <i class="fas fa-pencil-alt text-[10px]"></i>
                                </button>
                            </div>
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
                        
                        <!-- ✨ VAULT BUTTON VISIBLE FOR ALL STATUSES (Pending, Shortlisted, Approved, Rejected) -->
                        <button onclick="openDocsModal(<?= $app['ApplicationID'] ?>, <?= $app['MinimumGWA'] ?? 0 ?>, '<?= $app['Status'] ?>')" class="h-9 px-3 bg-white text-blue-600 border border-blue-200 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-50 transition-all shadow-sm flex items-center gap-1.5" title="View Uploaded Documents">
                            <i class="fas fa-folder-open"></i> Vault
                        </button>

                        <?php if (!$app['IsInternal'] && in_array($active_tab, ['pending', 'shortlisted'], true)): ?>
                            <!-- Externally funded: only the provider can shortlist/approve/reject -->
                            <span class="h-9 px-3 inline-flex items-center gap-1.5 rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-[10px] font-black uppercase tracking-widest max-w-[220px]" title="Externally funded — decided by <?= htmlspecialchars($app['ProviderName']) ?>">
                                <i class="fas fa-building"></i> <span class="truncate">Decided by <?= htmlspecialchars($app['ProviderName']) ?></span>
                            </span>

                        <?php elseif ($active_tab == 'pending'): ?>
                            <form action="../actions/process_crud.php" method="POST" class="m-0 p-0">
                                <input type="hidden" name="module" value="applications">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $app['ApplicationID'] ?>">
                                <input type="hidden" name="status" value="Shortlisted">
                                <button type="submit" onclick="return confirm('Shortlist this applicant?');" class="h-9 px-4 bg-blue-600 text-white rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-700 transition-all shadow-sm flex items-center justify-center">
                                    Shortlist
                                </button>
                            </form>
                            <form action="../actions/process_crud.php" method="POST" class="m-0 p-0">
                                <input type="hidden" name="module" value="applications">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $app['ApplicationID'] ?>">
                                <input type="hidden" name="status" value="Rejected">
                                <button type="submit" onclick="return confirm('Reject this application?');" class="h-9 w-9 bg-white text-red-500 border border-red-200 rounded-xl flex items-center justify-center hover:bg-red-50 transition-all shadow-sm" title="Reject">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>

                        <?php elseif ($active_tab == 'shortlisted'): ?>
                            <form action="../actions/process_crud.php" method="POST" class="m-0 p-0">
                                <input type="hidden" name="module" value="applications">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $app['ApplicationID'] ?>">
                                <input type="hidden" name="status" value="Approved">
                                <button type="submit" onclick="return confirm('Approve this applicant as an official scholar?');" class="h-9 px-4 bg-green-600 text-white rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-green-700 transition-all shadow-sm flex items-center justify-center">
                                    Approve
                                </button>
                            </form>
                            <form action="../actions/process_crud.php" method="POST" class="m-0 p-0">
                                <input type="hidden" name="module" value="applications">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $app['ApplicationID'] ?>">
                                <input type="hidden" name="status" value="Rejected">
                                <button type="submit" onclick="return confirm('Reject this shortlisted applicant?');" class="h-9 w-9 bg-white text-red-500 border border-red-200 rounded-xl flex items-center justify-center hover:bg-red-50 transition-all shadow-sm" title="Reject">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>

                        <?php elseif ($active_tab == 'approved' || $active_tab == 'rejected'): ?>
                            <button type="button" onclick="openProfileModal(<?= $app['UserID'] ?>)" class="h-9 px-4 bg-white text-slate-700 border border-slate-200 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-slate-100 transition-all shadow-sm flex items-center gap-1.5">
                                <i class="fas fa-user-circle"></i> Profile
                            </button>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
        
        <div id="paginationControls" class="flex justify-center gap-2 mt-8"></div>

    </div>
</main>

<!-- REPORTS MODAL -->
<div id="reportsModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Generate OSSD Reports</h3>
                <p class="text-xs text-blue-600 font-bold mt-1 uppercase tracking-widest">Follow format required by sponsor</p>
            </div>
            <button onclick="closeReportsModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
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
                        <option value="Pending">⏳ Pending Only</option>
                        <option value="Shortlisted">📋 Shortlisted Only</option>
                        <option value="Rejected">❌ Rejected Only</option>
                        <option value="All_Applicants">📝 All Applicants (Pending, Shortlisted, Approved, Rejected)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Target Scholarship</label>
                    <select name="scholarship_id" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20">
                        <option value="all">📂 All Scholarships (Separated by Excel Tabs)</option>
                        <?php foreach($scholarships as $s): ?>
                            <option value="<?= htmlspecialchars($s['ScholarshipID']) ?>"><?= htmlspecialchars($s['Name']) ?></option>
                        <?php endforeach; ?>
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
<div id="annexModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Export <?= htmlspecialchars($db_status) ?> Applicants</h3>
                <p class="text-xs text-green-600 font-bold mt-1 uppercase tracking-widest">Annex 1 - CHED/TES Excel Format</p>
            </div>
            <button onclick="closeAnnexModal()" type="button" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <form action="../actions/export_annex1_excel.php" method="POST" class="p-6">
            <input type="hidden" name="status" value="<?= htmlspecialchars($db_status) ?>">

            <div class="space-y-6">
                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Select Scholarship Group</label>
                    <select name="scholarship_id" required class="w-full bg-slate-50 border border-slate-200 px-4 py-4 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-green-500/20">
                        <option value="all">📂 All Scholarships (Separated by Tabs)</option>
                        <?php foreach($scholarships as $s): ?>
                            <option value="<?= htmlspecialchars($s['ScholarshipID']) ?>"><?= htmlspecialchars($s['Name']) ?></option>
                        <?php endforeach; ?>
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

<!-- DOCS VAULT MODAL -->
<div id="docsModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-3xl rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-xl font-black text-slate-900 tracking-tight">Applicant Document Vault</h3>
                <p class="text-xs font-bold text-blue-600 uppercase tracking-widest mt-1">Review & Verify Uploaded Files</p>
            </div>
            <!-- AFTER: Crisp, guaranteed visible SVG close button -->
                <button type="button" onclick="closeDocsModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-600 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-all shadow-sm active:scale-95" title="Close Vault">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
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

<!-- DOC VIEWER WITH AI SCANNER -->
<div id="docViewerModal" class="fixed inset-0 z-[100000] bg-slate-950/90 backdrop-blur-md hidden items-center justify-center p-4 md:p-10 transition-all duration-300">
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
                <button type="button" onclick="closeViewerModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-600 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-all shadow-sm active:scale-95" title="Close Viewer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
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
                <button onclick="document.getElementById('aiAnalysisResults').classList.add('hidden')" class="w-full bg-slate-900 text-white py-3 rounded-xl text-xs font-black uppercase tracking-widest shadow-md hover:bg-slate-800 transition-all active:scale-95">Dismiss</button>
            </div>

            <div id="zoomControls" class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-slate-900/90 backdrop-blur-md p-2 rounded-2xl flex items-center gap-2 z-40 hidden shadow-2xl border border-white/10">
                <button type="button" onclick="zoomImage(-0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all">➖</button>
                <button type="button" onclick="resetZoom()" class="px-4 h-10 bg-white/10 hover:bg-white/20 text-white font-black text-[12px] rounded-xl flex items-center justify-center transition-all uppercase tracking-widest">Reset</button>
                <button type="button" onclick="zoomImage(0.2)" class="w-10 h-10 bg-white/10 hover:bg-white/20 text-white rounded-xl flex items-center justify-center transition-all">➕</button>
            </div>
        </div>
    </div>
</div>

<!-- STUDENT PROFILE MODAL -->
<div id="profileModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9998] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-4xl rounded-[2.5rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Student Profile</h3>
                <p class="text-[14px] text-blue-600 font-bold mt-1 uppercase tracking-widest">Detailed Scholar Overview</p>
            </div>
            <button onclick="closeProfileModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
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
    let scholarsData = <?= json_encode($applications) ?>;
    const masterCards = Array.from(document.querySelectorAll('.app-card'));
    let filteredCards = [...masterCards];
    const itemsPerPage = 9;
    let currentPage = 1;

    // ✨ PREDICTIVE SEARCH LOGIC
    function predictiveSearch() {
        const query       = document.getElementById('liveSearch').value.toLowerCase().trim();
        const schQuery    = document.getElementById('scholarshipSearch').value.toLowerCase().trim();
        const courseQuery = document.getElementById('courseSearch').value.toLowerCase().trim();

        filteredCards = masterCards.filter(card => {
            const cardName  = card.getAttribute('data-name') || '';
            const cardSch   = card.getAttribute('data-scholarship') || '';
            const cardMajor = card.getAttribute('data-course') || '';

            const matchQuery  = !query || cardName.includes(query);
            const matchSch    = !schQuery || cardSch.includes(schQuery);
            const matchCourse = !courseQuery || cardMajor.includes(courseQuery);

            return matchQuery && matchSch && matchCourse;
        });

        currentPage = 1;
        renderPagination();
    }

    function clearFilters() {
        document.getElementById('liveSearch').value = '';
        document.getElementById('scholarshipSearch').value = '';
        document.getElementById('courseSearch').value = '';
        predictiveSearch();
    }

    function renderPagination() {
        masterCards.forEach(card => card.style.display = 'none');
        
        const emptyState = document.getElementById('emptyState');
        const paginationControls = document.getElementById('paginationControls');

        if (filteredCards.length === 0) {
            if (emptyState) emptyState.classList.remove('hidden');
            if (paginationControls) paginationControls.innerHTML = '';
            return;
        } else {
            if (emptyState) emptyState.classList.add('hidden');
        }

        const totalPages = Math.ceil(filteredCards.length / itemsPerPage);
        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;

        filteredCards.slice(start, end).forEach(card => {
            card.style.display = 'flex';
        });

        let btnHtml = `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&larr;</button>`;
        
        for (let i = 1; i <= totalPages; i++) {
            btnHtml += `<button onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-slate-900 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">${i}</button>`;
        }
        
        btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&rarr;</button>`;
        
        paginationControls.innerHTML = btnHtml;
    }

    function changePage(page) {
        const totalPages = Math.ceil(filteredCards.length / itemsPerPage);
        if (page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
        document.getElementById('applicationsGrid').scrollIntoView({ behavior: 'smooth' });
    }

    document.addEventListener('DOMContentLoaded', renderPagination);

    function openReportsModal() {
        document.getElementById('reportsModal').classList.remove('hidden');
        document.getElementById('reportsModal').classList.add('flex');
    }
    function closeReportsModal() {
        document.getElementById('reportsModal').classList.add('hidden');
        document.getElementById('reportsModal').classList.remove('flex');
    }

    function openAnnexModal() {
        document.getElementById('annexModal').classList.remove('hidden');
        document.getElementById('annexModal').classList.add('flex');
    }
    function closeAnnexModal() {
        document.getElementById('annexModal').classList.add('hidden');
        document.getElementById('annexModal').classList.remove('flex');
    }

    // ✨ AJAX GWA EDITOR 
    async function editGWA(userId, currentGWA) {
        const newGWA = prompt("Update verified GWA based on the submitted Report of Grades:", currentGWA);
        if (newGWA !== null && newGWA.trim() !== "" && !isNaN(newGWA)) {
            const formattedGWA = parseFloat(newGWA).toFixed(2);
            
            const formData = new FormData();
            formData.append('module', 'users');
            formData.append('action', 'update_gwa');
            formData.append('user_id', userId);
            formData.append('gwa', formattedGWA);

            try {
                await fetch('../actions/process_crud.php', { method: 'POST', body: formData });
                
                document.getElementById('gwa_display_' + userId).innerText = formattedGWA;
                
                const appIndex = scholarsData.findIndex(a => a.UserID == userId);
                if (appIndex !== -1) scholarsData[appIndex].GPA = formattedGWA;
                
                alert('Official GWA successfully updated!');
            } catch (e) {
                alert('Error updating GWA.');
            }
        }
    }

    async function openDocsModal(appId, minGWA, appStatus = 'Pending') {
        const modal = document.getElementById('docsModal');
        const list = document.getElementById('docsList');
        const loader = document.getElementById('docsLoading');
        
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        
        list.innerHTML = '';
        loader.classList.remove('hidden');

        try {
            const response = await fetch(`../actions/get_application_docs.php?app_id=${appId}`);
            const docs = await response.json();
            
            loader.classList.add('hidden');
            
            if (!Array.isArray(docs) || docs.length === 0) {
                list.innerHTML = `<div class="text-center py-6 text-slate-500 font-bold text-sm bg-white rounded-xl border border-slate-200">No documents uploaded for this application.</div>`;
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

                // Only render verification buttons if the document is still Pending AND the application is active
                let actionForms = '';
                if (doc.VerificationStatus === 'Pending' && (appStatus === 'Pending' || appStatus === 'Shortlisted')) {
                    actionForms = `
                        <form onsubmit="verifyDocumentAJAX(event, this, ${appId}, ${minGWA})" class="flex gap-2 m-0 ml-2">
                            <input type="hidden" name="module" value="verification">
                            <input type="hidden" name="id" value="${doc.SubmittedDocID}">
                            <input type="hidden" name="return_url" value="manage_applications.php?tab=<?= htmlspecialchars($active_tab) ?>">
                            <button type="submit" name="status" value="Rejected" class="bg-white text-red-500 hover:bg-red-50 border border-red-200 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase transition-all shadow-sm active:scale-95">Reject</button>
                            <button type="submit" name="status" value="Verified" class="bg-slate-900 text-white hover:bg-green-600 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase transition-all shadow-sm active:scale-95">Verify</button>
                        </form>
                    `;
                }

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
                            <button onclick="openViewerModal('${doc.FilePath}', '${doc.DocumentType}', ${minGWA}, ${appId})" class="bg-blue-50 hover:bg-blue-600 text-blue-600 hover:text-white px-4 py-2 rounded-lg text-[11px] font-black uppercase tracking-widest transition-colors border border-blue-200">
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

    function closeDocsModal() {
        document.getElementById('docsModal').classList.add('hidden');
        document.getElementById('docsModal').classList.remove('flex');
        document.body.style.overflow = 'auto';
    }

    async function verifyDocumentAJAX(event, formElement, appId, minGWA) {
        event.preventDefault(); 
        const submitBtn = event.submitter; 
        const status = submitBtn.value;

        if(!confirm(`Are you sure you want to mark this document as ${status}?`)) return;

        const originalText = submitBtn.innerText;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        submitBtn.disabled = true;

        const formData = new FormData(formElement);
        formData.append('status', status); 

        try {
            await fetch('../actions/process_crud.php', {
                method: 'POST',
                body: formData
            });
            openDocsModal(appId, minGWA); 
        } catch (error) {
            alert("Error updating document status.");
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    }

    let currentZoom = 1; let translateX = 0; translateY = 0; 
    let currentViewedFile = ""; let currentReqGWA = 0; let currentAppID = 0;
    const imgElement = document.getElementById('docImage'); const viewerCanvas = document.getElementById('viewerCanvas');

    function applyTransform() { imgElement.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`; }
    function zoomImage(step) { currentZoom = Math.max(0.5, Math.min(5, currentZoom + step)); applyTransform(); }
    function resetZoom() { currentZoom = 1; translateX = 0; translateY = 0; applyTransform(); }

    if (viewerCanvas) {
        viewerCanvas.addEventListener('wheel', e => { if (!imgElement.classList.contains('hidden')) { e.preventDefault(); zoomImage(e.deltaY > 0 ? -0.1 : 0.1); } }, { passive: false });
    }

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
        
        const viewer = document.getElementById('docViewerModal');
        viewer.classList.remove('hidden');
        viewer.classList.add('flex');
    }

    function closeViewerModal() {
        const viewer = document.getElementById('docViewerModal');
        viewer.classList.add('hidden');
        viewer.classList.remove('flex');
        document.getElementById('aiAnalysisResults').classList.add('hidden');
        setTimeout(() => { document.getElementById('docIframe').src = ""; document.getElementById('docImage').src = ""; resetZoom(); }, 300); 
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