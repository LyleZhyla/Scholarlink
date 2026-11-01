<?php
session_start();

// ✨ 1. SESSION MANAGER (Absolute top, checks for timeouts immediately)
include '../includes/session_manager.php';

// 🛑 2. THE BACK BUTTON KILLER (Prevents browser caching of sensitive admin data)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 🔒 3. THE SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'External_Admin') {
    header("Location: ../admin_login.php"); 
    exit();
}


include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/external_sidebar.php'; 

try {
    // Everything here is about this provider's own scholarships (the reports themselves are scoped the same way)
    $me = [(int) $_SESSION['user_id']];

    // Metric 1: How many of their applications have been scored?
    $st = $pdo->prepare("SELECT COUNT(*) FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE s.CreatedBy = ? AND a.TotalScore > 0");
    $st->execute($me);
    $scored_apps = $st->fetchColumn();

    // Metric 2: How many of their scholarships are active?
    $st = $pdo->prepare("SELECT COUNT(*) FROM scholarship WHERE CreatedBy = ? AND Status = 'Active'");
    $st->execute($me);
    $active_scholars = $st->fetchColumn();

    // Their scholarships for the filter dropdown
    $st = $pdo->prepare("SELECT ScholarshipID, Name FROM scholarship WHERE CreatedBy = ? ORDER BY Name ASC");
    $st->execute($me);
    $scholarships = $st->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8 lg:mb-10">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Evaluation Reports</h2>
        <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Export official applicant scores and evaluation statuses.</p>
    </header>

    <form method="GET" target="_blank">
        <div class="bg-white p-6 lg:p-8 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm mb-8 lg:mb-10">
            <h3 class="text-s font-black text-black uppercase tracking-widest mb-4 lg:mb-6">Report Filters</h3>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 lg:gap-6">
                <div>
                    <label class="block text-[14px] font-black text-black uppercase tracking-widest mb-2">Target Scholarship</label>
                    <select name="scholarship_id" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-s text-black outline-none focus:ring-2 focus:ring-blue-500/20 transition-all">
                        <option value="">All Scholarships</option>
                        <?php foreach($scholarships as $s): ?>
                            <option value="<?= $s['ScholarshipID'] ?>"><?= htmlspecialchars($s['Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[14px] font-black text-black uppercase tracking-widest mb-2">Application Status</label>
                    <select name="status" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-s text-black outline-none focus:ring-2 focus:ring-blue-500/20 transition-all">
                        <option value="">All Statuses</option>
                        <option value="Under Review">Under Review (Pending Score)</option>
                        <option value="Shortlisted">Shortlisted (Scored)</option>
                        <option value="Approved">Approved (Finalized)</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 mb-10 lg:mb-12">
            <div class="bg-white p-8 lg:p-10 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm hover:border-green-400 transition-all group flex flex-col justify-between">
                <div>
                    <div class="w-14 h-14 lg:w-16 lg:h-16 bg-green-50 text-green-600 rounded-[1.25rem] flex items-center justify-center text-2xl lg:text-3xl mb-6 group-hover:bg-green-600 group-hover:text-white transition-all">📊</div>
                    <h3 class="text-xl lg:text-2xl font-black text-slate-900 mb-2">Raw Data (CSV)</h3>
                    <p class="text-slate-500 text-s lg:text-sm mb-8 font-medium">Export a spreadsheet of applicants and their evaluator scores.</p>
                </div>
                <button type="button" onclick="openExportModal()" class="w-full sm:w-auto self-start bg-slate-900 text-white px-8 py-4 rounded-xl lg:rounded-2xl font-black text-[14px] lg:text-sm uppercase tracking-widest hover:bg-green-600 transition-all shadow-xl shadow-slate-200 active:scale-[0.98]">
                    Export Custom Excel
                </button>
            </div>

            <div class="bg-white p-8 lg:p-10 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm hover:border-blue-400 transition-all group flex flex-col justify-between">
                <div>
                    <div class="w-14 h-14 lg:w-16 lg:h-16 bg-blue-50 text-blue-600 rounded-[1.25rem] flex items-center justify-center text-2xl lg:text-3xl mb-6 group-hover:bg-blue-600 group-hover:text-white transition-all">📄</div>
                    <h3 class="text-xl lg:text-2xl font-black text-slate-900 mb-2">Evaluation Summary (PDF)</h3>
                    <p class="text-slate-500 text-s lg:text-sm mb-8 font-medium">Generate a formal, printable PDF report of evaluated applicants.</p>
                </div>
                <button type="submit" formaction="../actions/export_evaluations_pdf.php" class="w-full sm:w-auto self-start bg-slate-900 text-white px-8 py-4 rounded-xl lg:rounded-2xl font-black text-[14px] lg:text-s uppercase tracking-widest hover:bg-blue-600 transition-all shadow-xl shadow-slate-200 active:scale-[0.98]">
                    Generate PDF Report
                </button>
            </div>
        </div>
    </form>

    <div class="bg-slate-900 rounded-[1.5rem] lg:rounded-[2rem] p-8 lg:p-10 text-white shadow-2xl shadow-slate-300">
        <h4 class="text-[9px] lg:text-[14px] font-black uppercase tracking-widest text-slate-400 mb-6">Evaluator Milestones</h4>
        <div class="flex flex-col sm:flex-row gap-8 sm:gap-20">
            <div>
                <p class="text-s lg:text-sm font-bold text-slate-400">Applications Scored</p>
                <p class="text-3xl lg:text-4xl font-black text-green-400"><?= $scored_apps ?></p>
            </div>
            <div>
                <p class="text-s lg:text-sm font-bold text-slate-400">Active Scholarships Monitored</p>
                <p class="text-3xl lg:text-4xl font-black text-blue-400"><?= $active_scholars ?></p>
            </div>
        </div>
    </div>

        <!-- EXPORT SELECTION MODAL -->
    <div id="exportModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] hidden items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
            <div class="p-6 lg:p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <div>
                    <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Custom Excel Export</h3>
                    <p class="text-[12px] text-blue-600 font-bold mt-1 uppercase tracking-widest">Select tables to include in Annex 1</p>
                </div>
                <button type="button" onclick="closeExportModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">&times;</button>
            </div>
            
            <form action="../actions/export_annex1_excel.php" method="POST" class="p-6 lg:p-8">
                <!-- Hidden inputs to pass the current filters -->
                <input type="hidden" name="scholarship_id" value="<?= htmlspecialchars($_GET['scholarship_id'] ?? '') ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($_GET['status'] ?? '') ?>">

                <div class="space-y-3 mb-8">
                    <!-- CHECKBOXES PARA SA TABLES -->
                    <label class="flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-green-500 hover:bg-green-50 transition-all">
                        <input type="checkbox" name="export_tables[]" value="personal_info" class="w-5 h-5 accent-green-600 rounded" checked>
                        <span class="font-bold text-slate-700 uppercase tracking-widest text-sm">Personal Information</span>
                    </label>

                    <label class="flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-green-500 hover:bg-green-50 transition-all">
                        <input type="checkbox" name="export_tables[]" value="family_background" class="w-5 h-5 accent-green-600 rounded" checked>
                        <span class="font-bold text-slate-700 uppercase tracking-widest text-sm">Family Background</span>
                    </label>

                    <label class="flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-green-500 hover:bg-green-50 transition-all">
                        <input type="checkbox" name="export_tables[]" value="academic_profile" class="w-5 h-5 accent-green-600 rounded" checked>
                        <span class="font-bold text-slate-700 uppercase tracking-widest text-sm">Academic Profile & Scores</span>
                    </label>
                    
                    <label class="flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-green-500 hover:bg-green-50 transition-all">
                        <input type="checkbox" name="export_tables[]" value="documents" class="w-5 h-5 accent-green-600 rounded">
                        <span class="font-bold text-slate-700 uppercase tracking-widest text-sm">Submitted Documents Tracker</span>
                    </label>
                </div>

                <div class="flex gap-3">
                    <button type="button" onclick="closeExportModal()" class="flex-1 bg-slate-100 text-slate-600 py-4 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                    <button type="submit" class="flex-1 bg-green-600 text-white py-4 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-green-700 transition-all shadow-lg active:scale-95">Generate Excel 📊</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openExportModal() {
            document.getElementById('exportModal').classList.remove('hidden');
            document.getElementById('exportModal').classList.add('flex');
        }
        function closeExportModal() {
            document.getElementById('exportModal').classList.add('hidden');
            document.getElementById('exportModal').classList.remove('flex');
        }
    </script>
    </main>

<?php include '../includes/footer.php'; ?>