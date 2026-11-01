<?php 
session_start();
require_once '../includes/session_manager.php';
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

// 📱 CP LIVE BEHAVIOR: Background AJAX handler to clear unread alerts on click
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_mark_read'])) {
    header('Content-Type: application/json');
    $notif_id = intval($_POST['notif_id']);
    $user_id = $_SESSION['user_id'];
    
    try {
        $update_stmt = $pdo->prepare("UPDATE notifications SET IsRead = 1 WHERE NotificationID = :nid AND UserID = :uid");
        $update_stmt->execute(['nid' => $notif_id, 'uid' => $user_id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$user_id = $_SESSION['user_id'];

// =========================================================================================
// ✨ STEP 1: RENEWAL WALL CHECKER
// =========================================================================================
$show_renewal_wall = false;
$renewal_status = '';
$renewal_data = [];
$active_term_name = '';

try {
    $term_stmt = $pdo->query("SELECT * FROM academic_terms WHERE Status = 'Active' LIMIT 1");
    $active_term = $term_stmt->fetch(PDO::FETCH_ASSOC);

    if ($active_term) {
        $active_term_name = $active_term['TermName'];
        
        $renewal_stmt = $pdo->prepare("SELECT * FROM scholar_renewals WHERE UserID = ? AND TermID = ?");
        $renewal_stmt->execute([$user_id, $active_term['TermID']]);
        $renewal_data = $renewal_stmt->fetch(PDO::FETCH_ASSOC);

        if ($renewal_data) {
            if ($renewal_data['Status'] !== 'Renewed') {
                $show_renewal_wall = true;
                $renewal_status = $renewal_data['Status'];
            }
        }
    }
} catch (PDOException $e) {
    // Ignore error
}

include '../includes/header.php'; 

// =========================================================================================
// ✨ STEP 2: DISPLAY THE RE-CENTERED RESPONSIVE WALL
// =========================================================================================
if ($show_renewal_wall): 
?>
    <main class="min-h-screen w-full bg-slate-50 flex items-center justify-center fixed inset-0 z-[999999] p-4 sm:p-8">
        
        <div class="bg-white w-full max-w-xl md:max-w-2xl lg:max-w-3xl rounded-[2rem] sm:rounded-[2.5rem] shadow-2xl overflow-hidden p-6 sm:p-10 lg:p-12 text-center border border-slate-200">
            
            <?php if ($renewal_status === 'Pending_Submission'): ?>
                <div class="w-16 h-16 sm:w-20 sm:h-20 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center text-3xl sm:text-4xl mx-auto mb-4 sm:mb-6">📄</div>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight mb-2">Semester Renewal Required</h2>
                <p class="text-sm sm:text-base text-slate-500 font-bold mb-6 sm:mb-8 px-2 sm:px-6">Welcome to <strong><?= htmlspecialchars($active_term_name) ?></strong>! To retain your scholarship, please upload your latest Report of Grades (ROG) and Certificate of Registration (COR).</p>
                
                <form action="../actions/process_renewal.php" method="POST" enctype="multipart/form-data" class="space-y-4 sm:space-y-6 text-left bg-slate-50 p-4 sm:p-6 rounded-xl sm:rounded-2xl border border-slate-100 relative">
                    <input type="hidden" name="renewal_id" value="<?= $renewal_data['RenewalID'] ?>">
                    
                    <div>
                        <label class="block text-[10px] sm:text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 sm:mb-2">Report of Grades (ROG) <span class="text-red-500">*</span></label>
                        <input type="file" name="rog_file" accept=".pdf,.jpg,.jpeg,.png" required class="w-full bg-white border border-slate-200 px-3 sm:px-4 py-2.5 sm:py-3 rounded-lg sm:rounded-xl font-bold text-xs sm:text-sm text-slate-700 outline-none file:mr-3 sm:file:mr-4 file:py-1.5 sm:file:py-2 file:px-3 sm:file:px-4 file:rounded-md sm:file:rounded-lg file:border-0 file:text-[10px] sm:file:text-xs file:font-black file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer">
                        <p class="text-[9px] sm:text-[10px] text-slate-400 mt-1 font-bold">This will be automatically scanned by our AI to verify your GWA.</p>
                    </div>

                    <div>
                        <label class="block text-[10px] sm:text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 sm:mb-2">Certificate of Registration (COR) <span class="text-red-500">*</span></label>
                        <input type="file" name="cor_file" accept=".pdf,.jpg,.jpeg,.png" required class="w-full bg-white border border-slate-200 px-3 sm:px-4 py-2.5 sm:py-3 rounded-lg sm:rounded-xl font-bold text-xs sm:text-sm text-slate-700 outline-none file:mr-3 sm:file:mr-4 file:py-1.5 sm:file:py-2 file:px-3 sm:file:px-4 file:rounded-md sm:file:rounded-lg file:border-0 file:text-[10px] sm:file:text-xs file:font-black file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer">
                    </div>

                    <div class="pt-2 flex flex-col gap-3">
                        <button type="submit" class="w-full bg-blue-600 text-white px-4 sm:px-6 py-3.5 sm:py-4 rounded-lg sm:rounded-xl font-black text-[11px] sm:text-[13px] uppercase tracking-widest hover:bg-blue-700 transition-all shadow-lg active:scale-95">
                            Submit Requirements & Run AI Scan 🪄
                        </button>
                        <!-- ✨ EXIT BUTTON (RED & MOVED DOWN) -->
                        <a href="../actions/process_logout.php" class="text-center text-[10px] sm:text-[11px] font-bold text-red-500 hover:text-red-700 uppercase tracking-widest transition-colors mt-4 block">
                            Log out and return later
                        </a>
                    </div>
                </form>

            <?php elseif ($renewal_status === 'Under_Review'): ?>
                <div class="w-16 h-16 sm:w-20 sm:h-20 bg-orange-50 text-orange-600 rounded-full flex items-center justify-center text-3xl sm:text-4xl mx-auto mb-4 sm:mb-6"><i class="fas fa-spinner fa-spin"></i></div>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight mb-2">Under Verification</h2>
                <p class="text-sm sm:text-base text-slate-500 font-bold px-4 sm:px-10">Your documents are currently being scanned by our AI and evaluated by the OSSD. Please check back later.</p>
                <a href="../actions/process_logout.php" class="mt-6 sm:mt-8 inline-block px-6 sm:px-8 py-2.5 sm:py-3 bg-slate-200 text-slate-700 rounded-lg sm:rounded-xl font-black uppercase tracking-widest text-[10px] sm:text-xs hover:bg-slate-300 transition-colors">Log Out</a>

            <?php elseif ($renewal_status === 'Appeal_Requested'): ?>
                <div class="w-16 h-16 sm:w-20 sm:h-20 bg-red-50 text-red-600 rounded-full flex items-center justify-center text-3xl sm:text-4xl mx-auto mb-4 sm:mb-6">⚠️</div>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight mb-2">Action Required: Letter of Appeal</h2>
                <p class="text-xs sm:text-sm text-slate-600 font-bold mb-4 sm:mb-6 px-2 sm:px-8">Your extracted GWA did not meet the required threshold. The OSSD has requested a Letter of Appeal regarding your academic performance.</p>
                
                <?php if(!empty($renewal_data['AdminNotes'])): ?>
                    <div class="p-3 sm:p-4 bg-red-50 border border-red-100 rounded-lg sm:rounded-xl text-left mb-4 sm:mb-6 shadow-inner">
                        <span class="text-[9px] sm:text-[10px] font-black text-red-400 uppercase tracking-widest">Admin Note:</span>
                        <p class="text-xs sm:text-sm font-bold text-red-900 mt-1 sm:mt-1.5">"<?= htmlspecialchars($renewal_data['AdminNotes']) ?>"</p>
                    </div>
                <?php endif; ?>

                <form action="../actions/process_renewal.php" method="POST" enctype="multipart/form-data" class="space-y-4 sm:space-y-6 text-left bg-slate-50 p-4 sm:p-6 rounded-xl sm:rounded-2xl border border-slate-100">
                    <input type="hidden" name="renewal_id" value="<?= $renewal_data['RenewalID'] ?>">
                    <input type="hidden" name="is_appeal" value="1">
                    
                    <div>
                        <label class="block text-[10px] sm:text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 sm:mb-2">Upload Letter of Appeal <span class="text-red-500">*</span></label>
                        <input type="file" name="appeal_file" accept=".pdf,.jpg,.png" required class="w-full bg-white border border-slate-200 px-3 sm:px-4 py-2.5 sm:py-3 rounded-lg sm:rounded-xl font-bold text-xs sm:text-sm text-slate-700 outline-none file:mr-3 sm:file:mr-4 file:py-1.5 sm:file:py-2 file:px-3 sm:file:px-4 file:rounded-md sm:file:rounded-lg file:border-0 file:text-[10px] sm:file:text-xs file:font-black file:bg-red-50 file:text-red-700 hover:file:bg-red-100 transition-all cursor-pointer">
                    </div>

                    <div class="pt-2 flex flex-col gap-3">
                        <button type="submit" class="w-full bg-slate-900 text-white px-4 sm:px-6 py-3.5 sm:py-4 rounded-lg sm:rounded-xl font-black text-[11px] sm:text-[13px] uppercase tracking-widest hover:bg-slate-800 transition-all shadow-lg active:scale-95 mt-2">
                            Submit Appeal
                        </button>
                        <!-- ✨ EXIT BUTTON (RED & MOVED DOWN) -->
                        <a href="../actions/process_logout.php" class="text-center text-[10px] sm:text-[11px] font-bold text-red-500 hover:text-red-700 uppercase tracking-widest transition-colors mt-4 block">
                            Log out and return later
                        </a>
                    </div>
                </form>

            <?php elseif ($renewal_status === 'Dropped'): ?>
                <div class="w-16 h-16 sm:w-20 sm:h-20 bg-slate-100 text-slate-600 rounded-full flex items-center justify-center text-3xl sm:text-4xl mx-auto mb-4 sm:mb-6">❌</div>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight mb-2">Scholarship Dropped</h2>
                <p class="text-sm sm:text-base text-slate-500 font-bold mb-4 px-4 sm:px-10">We regret to inform you that your scholarship has been dropped for this academic term.</p>
                <?php if(!empty($renewal_data['AdminNotes'])): ?>
                    <div class="p-3 sm:p-4 bg-slate-100 border border-slate-200 rounded-lg sm:rounded-xl mb-4 sm:mb-6 shadow-inner mx-4 sm:mx-10">
                        <p class="text-xs sm:text-sm font-bold text-slate-700">"<?= htmlspecialchars($renewal_data['AdminNotes']) ?>"</p>
                    </div>
                <?php endif; ?>
                <a href="../actions/process_logout.php" class="inline-block px-6 sm:px-8 py-2.5 sm:py-3 bg-slate-900 text-white rounded-lg sm:rounded-xl font-black uppercase tracking-widest text-[10px] sm:text-xs shadow-md hover:bg-slate-800 transition-colors">Log Out</a>
            
            <?php endif; ?>
        </div>
    </main>
<?php 
    exit(); 
endif; 

// =========================================================================================
// NORMAL DASHBOARD CONTINUES HERE KUNG WALA SILANG RENEWAL NA NAKABINBIN
// =========================================================================================
include '../includes/student_sidebar.php'; 

try {
    $user_stmt = $pdo->prepare("SELECT FirstName, LastName, GPA, ProgramID, Major FROM users WHERE UserID = :uid");
    $user_stmt->execute(['uid' => $user_id]);
    $user = $user_stmt->fetch();

    $app_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM application WHERE UserID = :uid AND Status != 'Rejected'");
    $app_count_stmt->execute(['uid' => $user_id]);
    $active_apps = $app_count_stmt->fetchColumn();

    $sch_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM scholarship WHERE (ProgramID = :pid OR ProgramID IS NULL) AND Status = 'Active'");
    $sch_count_stmt->execute(['pid' => $user['ProgramID'] ?? 0]);
    $available_sch = $sch_count_stmt->fetchColumn();

    $history_stmt = $pdo->prepare("
        SELECT a.ApplicationID, a.Status, a.DateSubmitted, sch.Name AS scholarship_name
        FROM application a
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        WHERE a.UserID = :uid ORDER BY a.DateSubmitted DESC LIMIT 3
    ");
    $history_stmt->execute(['uid' => $user_id]);
    $recent_history = $history_stmt->fetchAll();

    // Requirements of the student's open applications (not rejected) and where each one stands:
    // nothing uploaded = Missing, Pending = In Review, Verified, Rejected. What needs action comes first.
    $req_stmt = $pdo->prepare("
        SELECT dr.DocumentName, sch.Name AS scholarship_name, sd.VerificationStatus
        FROM application a
        JOIN scholarship sch ON sch.ScholarshipID = a.ScholarshipID
        JOIN document_requirement dr ON dr.ScholarshipID = a.ScholarshipID
        LEFT JOIN submitted_document sd ON sd.ApplicationID = a.ApplicationID AND sd.RequirementID = dr.RequirementID
        WHERE a.UserID = :uid AND a.Status <> 'Rejected'
        ORDER BY CASE WHEN sd.SubmittedDocID IS NULL THEN 0 WHEN sd.VerificationStatus = 'Rejected' THEN 1
                      WHEN sd.VerificationStatus = 'Verified' THEN 3 ELSE 2 END, a.DateSubmitted DESC, dr.DocumentName");
    $req_stmt->execute(['uid' => $user_id]);
    $requirements = $req_stmt->fetchAll(PDO::FETCH_ASSOC);
    $req_outstanding = count(array_filter($requirements, fn($r) => $r['VerificationStatus'] === null || $r['VerificationStatus'] === 'Rejected'));

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE UserID = :uid AND IsRead = 0");
    $count_stmt->execute(['uid' => $user_id]);
    $unread_count = $count_stmt->fetchColumn();

    $unread_stmt = $pdo->prepare("SELECT * FROM notifications WHERE UserID = :uid AND IsRead = 0 ORDER BY CreatedAt DESC LIMIT 3");
    $unread_stmt->execute(['uid' => $user_id]);
    $unread_notifications = $unread_stmt->fetchAll();

} catch (PDOException $e) { die("Dashboard Error: " . $e->getMessage()); }

function getNotificationStyle($type) {
    return match($type) {
        'success' => ['bg' => 'bg-green-50', 'text' => 'text-green-600', 'badge' => 'bg-green-100 text-green-800', 'icon' => '🎉', 'label' => 'Success'],
        'warning' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-600', 'badge' => 'bg-amber-100 text-amber-800', 'icon' => '⚠️', 'label' => 'Warning'],
        'danger'  => ['bg' => 'bg-red-50', 'text' => 'text-red-600', 'badge' => 'bg-red-100 text-red-800', 'icon' => '🛑', 'label' => 'Alert'],
        default   => ['bg' => 'bg-blue-50', 'text' => 'text-blue-600', 'badge' => 'bg-blue-100 text-blue-800', 'icon' => '🔔', 'label' => 'Update'],
    };
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;
    $string = ['y' => 'year','m' => 'month','w' => 'week','d' => 'day','h' => 'hour','i' => 'minute','s' => 'second'];
    foreach ($string as $k => &$v) {
        if ($diff->$k) { $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : ''); } 
        else { unset($string[$k]); }
    }
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'Just now';
}
?>

<style>
@keyframes wiggle {
    0%, 100% { transform: rotate(0deg); }
    25% { transform: rotate(-10deg); }
    75% { transform: rotate(10deg); }
}
.animate-wiggle { animation: wiggle 0.3s ease-in-out infinite; }
</style>

<div id="phone-notification-center" class="fixed top-5 right-5 z-[100000] flex flex-col gap-3 w-full max-w-sm px-4 sm:px-0 pointer-events-none"></div>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    
    <header class="mb-8 lg:mb-10 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <span class="px-3 py-1 bg-green-100 text-green-700 text-[10px] font-black uppercase tracking-widest rounded-full">Student Portal</span>
            </div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Welcome back, <?= htmlspecialchars($user['FirstName']) ?>! 👋</h2>
            <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Here's what's happening with your scholarship applications today.</p>
        </div>

        <div class="flex items-center gap-3 shrink-0">
        <button type="button" onclick="startStudentTour()" class="px-4 py-3 bg-white border border-slate-200 hover:border-green-500 hover:text-green-700 rounded-2xl text-xs font-black uppercase tracking-widest text-slate-600 shadow-sm transition-all">🧭 Take the tour</button>
        <button onclick="openBellDrawerModal()" data-tour="bell" class="relative p-3.5 bg-white border border-slate-200 hover:border-blue-500 hover:shadow-md rounded-2xl transition-all group shrink-0 active:scale-95 shadow-sm">
            <span class="text-2xl group-hover:animate-wiggle block">🔔</span>
            <span id="global-bell-count" class="absolute -top-1.5 -right-1.5 bg-blue-500 text-white text-[10px] font-black w-5 h-5 rounded-full flex items-center justify-center border-2 border-white shadow-md transition-transform <?= ($unread_count == 0) ? 'hidden' : '' ?>">
                <?= $unread_count ?>
            </span>
        </button>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 lg:gap-8 mb-8 lg:mb-12">
        <div class="bg-white p-6 lg:p-8 rounded-[2rem] border border-slate-200 shadow-sm hover:border-blue-400 transition-all group">
            <div class="flex justify-between items-start mb-4">
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-slate-900 rounded-xl lg:rounded-2xl flex items-center justify-center text-white text-lg shadow-lg group-hover:bg-blue-600 transition-colors">📝</div>
            </div>
            <p class="text-4xl lg:text-5xl font-black text-slate-900 leading-none"><?= $active_apps ?></p>
            <p class="text-[10px] lg:text-xs font-bold text-slate-500 mt-2 uppercase tracking-widest">Active Applications</p>
        </div>

        <div data-tour="gwa" class="bg-white p-6 lg:p-8 rounded-[2rem] border border-slate-200 shadow-sm hover:border-green-400 transition-all group">
            <div class="flex justify-between items-start mb-4">
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-slate-900 rounded-xl lg:rounded-2xl flex items-center justify-center text-white text-lg shadow-lg group-hover:bg-green-600 transition-colors">📊</div>
            </div>
            <p class="text-4xl lg:text-5xl font-black text-slate-900 leading-none"><?= number_format($user['GPA'] ?? 0.00, 2) ?></p>
            <p class="text-[10px] lg:text-xs font-bold text-slate-500 mt-2 uppercase tracking-widest">Current GWA</p>
        </div>

        <div class="bg-white p-6 lg:p-8 rounded-[2rem] border border-slate-200 shadow-sm hover:border-purple-400 transition-all group">
            <div class="flex justify-between items-start mb-4">
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-slate-900 rounded-xl lg:rounded-2xl flex items-center justify-center text-white text-lg shadow-lg group-hover:bg-purple-600 transition-colors">🎓</div>
            </div>
            <p class="text-4xl lg:text-5xl font-black text-slate-900 leading-none"><?= $available_sch ?></p>
            <p class="text-[10px] lg:text-xs font-bold text-slate-500 mt-2 uppercase tracking-widest">Eligible Programs</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 bg-white rounded-[2rem] lg:rounded-[2.5rem] border border-slate-200 shadow-sm p-6 lg:p-10">
            <div class="flex justify-between items-center mb-8">
                <h3 class="text-xl lg:text-2xl font-black text-slate-900 tracking-tight">Recent Activity</h3>
                <a href="applications.php" class="text-xs font-black text-blue-600 uppercase tracking-widest hover:text-blue-800 transition-colors">View All &rarr;</a>
            </div>

            <div class="space-y-4">
                <?php if (empty($recent_history)): ?>
                    <div class="text-center py-10 bg-slate-50 rounded-3xl border border-dashed border-slate-200">
                        <span class="text-3xl mb-3 block">📭</span>
                        <p class="text-slate-400 font-bold text-sm">No recent applications found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach($recent_history as $history): 
                        $badgeStyle = match($history['Status']) {
                            'Approved' => 'bg-green-50 text-green-600 border-green-200',
                            'Rejected' => 'bg-red-50 text-red-600 border-red-200',
                            'Pending', 'Under Review' => 'bg-amber-50 text-amber-600 border-amber-200',
                            default => 'bg-blue-50 text-blue-600 border-blue-200'
                        };
                    ?>
                        <div class="flex items-center justify-between p-5 border border-slate-100 rounded-2xl hover:border-slate-300 transition-all hover:bg-slate-50 group">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 bg-white border border-slate-200 text-slate-400 rounded-full flex items-center justify-center text-sm shadow-sm group-hover:text-blue-600 transition-colors">📄</div>
                                <div>
                                    <h4 class="font-black text-slate-900 text-sm lg:text-base"><?= htmlspecialchars($history['scholarship_name']) ?></h4>
                                    <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">
                                        Submitted on <?= date('M d, Y', strtotime($history['DateSubmitted'])) ?>
                                    </p>
                                </div>
                            </div>
                            <span class="<?= $badgeStyle ?> px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest border">
                                <?= $history['Status'] ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex flex-col gap-6">
            <div data-tour="find" class="bg-gradient-to-br from-slate-900 to-slate-800 rounded-[2rem] p-8 shadow-xl relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -translate-y-16 translate-x-16 group-hover:scale-150 transition-transform duration-700"></div>
                <h3 class="text-white text-xl font-black tracking-tight mb-2 relative z-10">Find Scholarships</h3>
                <p class="text-slate-400 text-xs font-medium mb-6 relative z-10">You have <?= $available_sch ?> eligible programs waiting for your application.</p>
                <a href="programs.php" class="inline-block bg-white text-slate-900 px-6 py-3 rounded-xl text-xs font-black uppercase tracking-widest shadow-md hover:bg-blue-600 hover:text-white transition-all relative z-10">Browse Now</a>
            </div>

            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-8 shadow-sm">
                <?php
                    $requirements = $requirements ?? [];
                    $req_outstanding = $req_outstanding ?? 0;
                    $req_badges = [
                        'missing'  => ['Missing', 'bg-red-50 text-red-600 border-red-200 animate-pulse', '📄'],
                        'Rejected' => ['Re-upload', 'bg-red-50 text-red-600 border-red-200', '⚠️'],
                        'Pending'  => ['In Review', 'bg-amber-50 text-amber-600 border-amber-200', '⏳'],
                        'Verified' => ['Verified', 'bg-green-50 text-green-600 border-green-200', '✅'],
                    ];
                ?>
                <div class="flex items-center justify-between mb-5">
                    <div class="flex items-center gap-3">
                        <span class="text-xl">📋</span>
                        <h3 class="text-slate-900 text-sm font-black uppercase tracking-widest">Pending Requirements</h3>
                    </div>
                    <?php if ($req_outstanding > 0): ?>
                        <span class="bg-red-50 text-red-600 border border-red-200 px-2.5 py-1 rounded-lg text-[10px] font-black" title="Missing or need re-uploading"><?= $req_outstanding ?> to do</span>
                    <?php endif; ?>
                </div>

                <div class="space-y-3.5">
                    <?php if (empty($requirements)): ?>
                        <div class="text-center py-6 bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                            <span class="text-2xl block mb-2">🗂️</span>
                            <p class="text-slate-400 font-bold text-xs">No requirements yet. They appear here once you apply for a scholarship.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($requirements, 0, 5) as $req):
                            [$label, $style, $icon] = $req_badges[$req['VerificationStatus'] ?? 'missing'] ?? $req_badges['Pending'];
                        ?>
                            <div class="flex items-center justify-between gap-3 p-3.5 border border-slate-100 rounded-xl bg-slate-50/50">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="text-sm shrink-0"><?= $icon ?></span>
                                    <div class="min-w-0">
                                        <p class="text-xs font-black text-slate-900 truncate" title="<?= htmlspecialchars($req['DocumentName']) ?>"><?= htmlspecialchars($req['DocumentName']) ?></p>
                                        <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mt-0.5 truncate" title="<?= htmlspecialchars($req['scholarship_name']) ?>"><?= htmlspecialchars($req['scholarship_name']) ?></p>
                                    </div>
                                </div>
                                <span class="<?= $style ?> border px-2.5 py-1 rounded-lg text-[9px] font-black uppercase tracking-wider shrink-0"><?= $label ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($req_outstanding === 0): ?>
                            <p class="text-center text-[11px] font-bold text-green-600">Nothing to upload right now 🎉</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($requirements)): ?>
                    <a href="requirements.php" class="block text-center mt-5 text-xs font-black text-blue-600 uppercase tracking-widest hover:text-blue-800 transition-colors">
                        <?= count($requirements) > 5 ? 'View all ' . count($requirements) . ' requirements' : 'Manage requirements' ?> &rarr;
                    </a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<div id="notifModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm hidden z-[100005] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300" id="notifModalContent">
        <div class="w-12 h-1.5 bg-slate-200 rounded-full mx-auto mt-4 shrink-0"></div>
        <div class="p-6 pt-4 flex flex-col items-center text-center">
            <div id="modalNotifCircle" class="w-16 h-16 rounded-full flex items-center justify-center text-3xl mb-4 shadow-inner">🔔</div>
            <span id="modalNotifBadge" class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest mb-3">Update</span>
            <h3 id="modalNotifTitle" class="text-lg font-black text-slate-900 leading-snug px-2 mb-2">Notification Title</h3>
            <p id="modalNotifDate" class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-5">Date Display Here</p>
            <div class="w-full max-h-[25vh] overflow-y-auto bg-slate-50 rounded-2xl p-4 border border-slate-100 mb-6 text-left">
                <p id="modalNotifMessage" class="text-xs lg:text-sm text-slate-600 font-medium leading-relaxed"></p>
            </div>
            <button type="button" onclick="closeNotifModal()" class="w-full bg-slate-900 text-white py-3.5 rounded-xl font-black text-xs uppercase tracking-widest shadow-xl hover:bg-blue-600 active:scale-95 transition-all">Got it 👍</button>
        </div>
    </div>
</div>

<div id="bellDrawerModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm hidden z-[100001] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[85vh]" id="bellDrawerContent">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div class="flex items-center gap-2">
                <span class="text-xl">🔔</span>
                <h3 class="text-base font-black text-slate-900 uppercase tracking-wide">Notifications Center</h3>
            </div>
            <button onclick="closeBellDrawerModal()" class="w-8 h-8 flex items-center justify-center bg-slate-200 hover:bg-red-500 hover:text-white rounded-full text-slate-600 font-bold transition-all text-sm">&times;</button>
        </div>
        <div class="p-6 overflow-y-auto space-y-4 flex-1">
            <?php 
            try {
                $drawer_stmt = $pdo->prepare("SELECT * FROM notifications WHERE UserID = :uid ORDER BY CreatedAt DESC LIMIT 10");
                $drawer_stmt->execute(['uid' => $user_id]);
                $drawer_notifications = $drawer_stmt->fetchAll();
            } catch(PDOException $e) { $drawer_notifications = []; }
            
            if(empty($drawer_notifications)): ?>
                <p class="text-center text-slate-400 text-xs py-8 font-medium">No alerts cataloged yet.</p>
            <?php else: 
                foreach($drawer_notifications as $d_notif): 
                    $d_style = getNotificationStyle($d_notif['Type']);
                    $d_unread = ($d_notif['IsRead'] == 0);
                ?>
                    <div onclick="closeBellDrawerModal(); setTimeout(() => openNotifModal(this), 150)"
                         data-id="<?= $d_notif['NotificationID'] ?>"
                         data-title="<?= htmlspecialchars($d_notif['Title'], ENT_QUOTES) ?>"
                         data-message="<?= htmlspecialchars($d_notif['Message'], ENT_QUOTES) ?>"
                         data-type="<?= $d_notif['Type'] ?>"
                         data-unread="<?= $d_unread ? '1' : '0' ?>"
                         data-date="<?= date('F j, Y, g:i A', strtotime($d_notif['CreatedAt'])) ?>"
                         class="p-4 rounded-xl border <?= $d_unread ? 'border-blue-400 bg-blue-50/10' : 'border-slate-100 bg-white' ?> flex gap-3 transition-all cursor-pointer relative hover:border-blue-500 group">
                        
                        <?php if($d_unread): ?>
                            <div class="unread-dot absolute top-2 right-2 w-2 h-2 bg-blue-500 rounded-full"></div>
                        <?php endif; ?>

                        <div class="w-8 h-8 <?= $d_style['bg'] ?> <?= $d_style['text'] ?> rounded-full flex items-center justify-center text-xs shrink-0"><?= $d_style['icon'] ?></div>
                        <div class="flex-1 min-w-0">
                            <h4 class="font-black text-slate-900 text-xs truncate group-hover:text-blue-600 transition-colors"><?= htmlspecialchars($d_notif['Title']) ?></h4>
                            <p class="text-[11px] text-slate-500 truncate mt-0.5"><?= htmlspecialchars($d_notif['Message']) ?></p>
                            <span class="text-[9px] font-bold text-slate-400 uppercase mt-1 block"><?= time_elapsed_string($d_notif['CreatedAt']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="p-4 bg-slate-50 border-t border-slate-100 text-center shrink-0">
            <a href="notifications.php" class="text-xs font-black text-blue-600 uppercase tracking-widest hover:text-blue-800 transition-colors">See complete history &rarr;</a>
        </div>
    </div>
</div>

<script>
    const notifModal = document.getElementById('notifModal');
    const notifModalContent = document.getElementById('notifModalContent');
    const bellDrawerModal = document.getElementById('bellDrawerModal');
    const bellDrawerContent = document.getElementById('bellDrawerContent');

    function openBellDrawerModal() {
        bellDrawerModal.classList.remove('hidden');
        setTimeout(() => {
            bellDrawerModal.classList.remove('opacity-0');
            bellDrawerContent.classList.remove('scale-95');
        }, 10);
    }
    function closeBellDrawerModal() {
        bellDrawerModal.classList.add('opacity-0');
        bellDrawerContent.classList.add('scale-95');
        setTimeout(() => { bellDrawerModal.classList.add('hidden'); }, 300);
    }

    function openNotifModal(card) {
        if (!card) return;
        const notifId = card.getAttribute('data-id');
        const title = card.getAttribute('data-title');
        const message = card.getAttribute('data-message');
        const type = card.getAttribute('data-type');
        const date = card.getAttribute('data-date');
        const isUnread = card.getAttribute('data-unread') === '1';

        document.getElementById('modalNotifTitle').textContent = title;
        document.getElementById('modalNotifMessage').textContent = message;
        document.getElementById('modalNotifDate').textContent = date;

        const modalCircle = document.getElementById('modalNotifCircle');
        const modalBadge = document.getElementById('modalNotifBadge');
        modalCircle.className = "w-16 h-16 rounded-full flex items-center justify-center text-3xl mb-4 shadow-inner";
        modalBadge.className = "px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest mb-3";

        switch(type) {
            case 'success':
                modalCircle.innerText = '🎉'; modalCircle.classList.add('bg-green-100', 'text-green-600');
                modalBadge.textContent = 'Success'; modalBadge.classList.add('bg-green-100', 'text-green-800');
                break;
            case 'danger':
                modalCircle.innerText = '🛑'; modalCircle.classList.add('bg-red-100', 'text-red-600');
                modalBadge.textContent = 'Alert'; modalBadge.classList.add('bg-red-100', 'text-red-800');
                break;
            case 'warning':
                modalCircle.innerText = '⚠️'; modalCircle.classList.add('bg-amber-100', 'text-amber-600');
                modalBadge.textContent = 'Warning'; modalBadge.classList.add('bg-amber-100', 'text-amber-800');
                break;
            default:
                modalCircle.innerText = '🔔'; modalCircle.classList.add('bg-blue-100', 'text-blue-600');
                modalBadge.textContent = 'Update'; modalBadge.classList.add('bg-blue-100', 'text-blue-800');
        }

        if (isUnread && notifId) {
            const formData = new FormData();
            formData.append('ajax_mark_read', '1');
            formData.append('notif_id', notifId);

            fetch('dashboard.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll(`[data-id='${notifId}']`).forEach(syncCard => {
                            syncCard.querySelectorAll('.unread-dot').forEach(dot => dot.remove());
                            syncCard.classList.remove('border-blue-400', 'bg-blue-50/10');
                            syncCard.classList.add('border-slate-100', 'bg-white');
                            syncCard.setAttribute('data-unread', '0');
                        });

                        const bellCountNode = document.getElementById('global-bell-count');
                        if (bellCountNode) {
                            let count = parseInt(bellCountNode.textContent) - 1;
                            if (count <= 0) { bellCountNode.classList.add('hidden'); }
                            else { bellCountNode.textContent = count; }
                        }
                    }
                }).catch(err => console.error("AJAX Error:", err));
        }

        notifModal.classList.remove('hidden');
        setTimeout(() => {
            notifModal.classList.remove('opacity-0');
            notifModalContent.classList.remove('scale-95');
        }, 10);
    }

    function closeNotifModal() {
        notifModal.classList.add('opacity-0');
        notifModalContent.classList.add('scale-95');
        setTimeout(() => { notifModal.classList.add('hidden'); }, 300);
    }

    document.addEventListener("DOMContentLoaded", function() {
        const unreadNotifications = <?php echo json_encode($unread_notifications); ?>;
        const container = document.getElementById('phone-notification-center');

        function getToastDetails(type) {
            switch(type) {
                case 'success': return { icon: '🎉', color: 'border-l-green-500 text-green-600' };
                case 'danger': return { icon: '🛑', color: 'border-l-red-500 text-red-600' };
                case 'warning': return { icon: '⚠️', color: 'border-l-amber-500 text-amber-600' };
                default: return { icon: '🔔', color: 'border-l-blue-500 text-blue-600' };
            }
        }

        function createPushToast(title, message, type, id, index) {
            const details = getToastDetails(type);
            const toast = document.createElement('div');
            toast.className = "pointer-events-auto w-full bg-white/95 backdrop-blur-md border border-slate-200 border-l-4 " + details.color + " p-4 rounded-2xl shadow-xl transition-all duration-500 transform translate-x-full opacity-0 flex gap-3 max-w-sm cursor-pointer";
            
            toast.innerHTML = `
                <div class="text-lg shrink-0 mt-0.5">${details.icon}</div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-0.5">
                        <h4 class="text-xs font-black text-slate-900 truncate pr-2">${title}</h4>
                        <span class="text-[9px] font-bold text-slate-400 uppercase tracking-wider shrink-0">Just Now</span>
                    </div>
                    <p class="text-[11px] text-slate-600 font-medium leading-normal line-clamp-2">${message}</p>
                </div>
                <button class="text-slate-400 hover:text-slate-600 font-bold text-xs shrink-0 px-1 self-start" onclick="event.stopPropagation(); this.parentElement.remove()">✕</button>
            `;

            toast.addEventListener('click', function() {
                const referenceCard = document.querySelector(`[data-id='${id}']`);
                openNotifModal(referenceCard || toast);
                toast.remove();
            });

            container.appendChild(toast);
            setTimeout(() => { toast.classList.remove('translate-x-full', 'opacity-0'); toast.classList.add('translate-x-0', 'opacity-100'); }, index * 400 + 100);
            setTimeout(() => { toast.classList.remove('translate-x-0', 'opacity-100'); toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 500); }, 6000 + (index * 400));
        }

        if (unreadNotifications && unreadNotifications.length > 0) {
            unreadNotifications.forEach((notif, index) => {
                createPushToast(notif.Title, notif.Message, notif.Type, notif.NotificationID, index);
            });
        }
    });
</script>

<?php include '../includes/student_tour.php'; // first-login guided tour ?>
<?php include '../includes/footer.php'; ?>