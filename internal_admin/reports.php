<?php 
session_start();

// ✨ 1. SESSION MANAGER (Prevents timeout crashes)
include '../includes/session_manager.php';

// 🛑 2. THE BACK BUTTON KILLER (Crucial for Admin pages)
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

try {
    // 1. Total Scholars (Approved Applications)
    $total_scholars = $pdo->query("SELECT COUNT(*) FROM application WHERE Status = 'Approved'")->fetchColumn();

    // 2. Total Funds (Sum of AwardAmount from linked Scholarships)
    $total_funds_query = $pdo->query("
        SELECT SUM(sch.AwardAmount) 
        FROM application a 
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID 
        WHERE a.Status = 'Approved'
    ");
    $total_funds = $total_funds_query->fetchColumn() ?? 0;

    // 3. Fetch Data for Predictive Filters
    $programs = $pdo->query("SELECT ProgramName FROM program ORDER BY ProgramName ASC")->fetchAll(PDO::FETCH_COLUMN);
    $scholarships = $pdo->query("SELECT ScholarshipID, Name FROM scholarship ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8 lg:mb-10">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">System Reports</h2>
        <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Filter, generate, and export official scholarship data.</p>
    </header>

    <form method="GET" target="_blank">
        
        <div class="bg-white p-6 lg:p-8 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm mb-8 lg:mb-10">
            <h3 class="text-md font-black text-slate-900 uppercase tracking-widest mb-4 lg:mb-6">Report Filters</h3>
            
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:gap-6">
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">🔍 Search Program</label>
                    <input type="search" name="course" list="courseList" placeholder="Type to search e.g. Info..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3.5 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner">
                    <datalist id="courseList">
                        <?php foreach($programs as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">🔍 Search Scholarship</label>
                    <input type="search" name="scholarship" list="scholarshipList" placeholder="Type to search scholarship name..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3.5 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner">
                    <datalist id="scholarshipList">
                        <?php foreach($scholarships as $s): ?>
                            <option value="<?= htmlspecialchars($s['Name']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">🔍 Search Year Level</label>
                    <input type="search" name="year_level" list="yearList" placeholder="e.g. 1st Year..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3.5 rounded-xl font-bold text-sm text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 focus:bg-white transition-all shadow-inner">
                    <datalist id="yearList">
                        <option value="1st Year">
                        <option value="2nd Year">
                        <option value="3rd Year">
                        <option value="4th Year">
                    </datalist>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 mb-10 lg:mb-12">
            <div class="bg-white p-8 lg:p-10 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm hover:border-green-400 transition-all group flex flex-col justify-between">
                <div>
                    <div class="w-14 h-14 lg:w-16 lg:h-16 bg-green-50 text-green-600 rounded-[1.25rem] flex items-center justify-center text-2xl lg:text-3xl mb-6 group-hover:bg-green-600 group-hover:text-white transition-all">📊</div>
                    <h3 class="text-xl lg:text-2xl font-black text-slate-900 mb-2">Scholar Roster (CSV)</h3>
                    <p class="text-slate-900 text-md lg:text-sm mb-8 font-medium">Export a spreadsheet of scholars matching your selected filters.</p>
                </div>
                <button type="submit" formaction="../actions/export_scholars_csv.php" class="w-full sm:w-auto self-start bg-slate-900 text-white px-8 py-4 rounded-xl lg:rounded-2xl font-black text-[14px] lg:text-md uppercase tracking-widest hover:bg-green-600 transition-all shadow-xl shadow-slate-200 active:scale-[0.98]">
                    Download CSV Data
                </button>
            </div>

            <div class="bg-white p-8 lg:p-10 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm hover:border-blue-400 transition-all group flex flex-col justify-between">
                <div>
                    <div class="w-14 h-14 lg:w-16 lg:h-16 bg-blue-50 text-blue-600 rounded-[1.25rem] flex items-center justify-center text-2xl lg:text-3xl mb-6 group-hover:bg-blue-600 group-hover:text-white transition-all">📄</div>
                    <h3 class="text-xl lg:text-2xl font-black text-slate-900 mb-2">Scholar Summary (PDF)</h3>
                    <p class="text-slate-900 text-md lg:text-sm mb-8 font-medium">Generate a formal PDF report of scholars matching your selected filters.</p>
                </div>
                <button type="submit" formaction="../actions/export_scholars_pdf.php" class="w-full sm:w-auto self-start bg-slate-900 text-white px-8 py-4 rounded-xl lg:rounded-2xl font-black text-[14px] lg:text-md uppercase tracking-widest hover:bg-blue-600 transition-all shadow-xl shadow-slate-200 active:scale-[0.98]">
                    Generate PDF Report
                </button>
            </div>
        </div>
    </form>

    <div class="bg-slate-900 rounded-[1.5rem] lg:rounded-[2rem] p-8 lg:p-10 text-white shadow-2xl shadow-slate-300">
        <h4 class="text-[9px] lg:text-[14px] font-black uppercase tracking-widest text-slate-500 mb-6">Lifetime System Summary</h4>
        <div class="flex flex-col sm:flex-row gap-8 sm:gap-20">
            <div>
                <p class="text-md lg:text-md font-bold text-slate-500">Total Scholars</p>
                <p class="text-3xl lg:text-4xl font-black"><?= $total_scholars ?></p>
            </div>
            <div>
                <p class="text-md lg:text-md font-bold text-slate-500">Total Disbursed Funds</p>
                <p class="text-3xl lg:text-4xl font-black text-green-400">₱<?= number_format($total_funds, 2) ?></p>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>