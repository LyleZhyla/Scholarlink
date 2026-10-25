<?php 
session_start();
include '../includes/session_manager.php'; 

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') { 
    header("Location: ../student_login.php"); exit(); 
}
include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/student_sidebar.php'; 

$app_id = $_GET['id'] ?? null;
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT a.*, sch.Name AS scholarship_name, u.Major as course 
    FROM application a
    JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
    JOIN users u ON a.UserID = u.UserID
    WHERE a.ApplicationID = :appid AND a.UserID = :uid
");
$stmt->execute(['appid' => $app_id, 'uid' => $user_id]);
$application = $stmt->fetch();

if (!$application) { header("Location: applications.php"); exit(); }

$ans_stmt = $pdo->prepare("SELECT f.FieldName, f.FieldType, a.AnswerText FROM application_custom_answers a JOIN scholarship_custom_fields f ON a.FieldID = f.FieldID WHERE a.ApplicationID = :appid");
$ans_stmt->execute(['appid' => $app_id]);
$custom_answers = $ans_stmt->fetchAll();

$docs_stmt = $pdo->prepare("SELECT d.*, dr.DocumentName FROM submitted_document d JOIN document_requirement dr ON d.RequirementID = dr.RequirementID WHERE d.ApplicationID = :appid");
$docs_stmt->execute(['appid' => $app_id]);
$documents = $docs_stmt->fetchAll();

// PROGRESS HELPERS
function getProgressWidth($status) { return match($status) { 'Submitted' => 'w-1/4', 'Under Review' => 'w-2/4', 'Shortlisted' => 'w-3/4', 'Approved', 'Rejected' => 'w-full', default => 'w-1/4' }; }
function getProgressColor($status) { return match($status) { 'Approved' => 'bg-green-500', 'Rejected' => 'bg-red-500', 'Shortlisted' => 'bg-purple-500', 'Under Review' => 'bg-amber-500', default => 'bg-blue-500' }; }
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8 lg:mb-10 flex items-center gap-4 lg:gap-6">
        <a href="applications.php" class="w-10 h-10 lg:w-14 lg:h-14 shrink-0 bg-white border border-slate-200 rounded-xl lg:rounded-[1.25rem] flex items-center justify-center font-black text-slate-400 hover:text-slate-900 transition-all">←</a>
        <div>
            <h2 class="text-2xl lg:text-4xl font-black text-slate-900 tracking-tight leading-tight">Application Record</h2>
            <p class="text-slate-500 font-medium text-xs lg:text-sm mt-1">Review your submitted details and attached documents.</p>
        </div>
    </header>

    <div class="bg-white rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm max-w-4xl p-6 lg:p-10 space-y-8">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Scholarship Program</p>
                <p class="font-bold text-slate-900 text-sm lg:text-base"><?= htmlspecialchars($application['scholarship_name']) ?></p>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Current Status</p>
                <span class="<?= getProgressColor($application['Status']) ?> text-white px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm"><?= htmlspecialchars($application['Status']) ?></span>
            </div>
        </div>

        <div class="border-t border-slate-100 pt-6 w-full">
            <div class="flex justify-between text-[9px] lg:text-[10px] font-black uppercase tracking-widest mb-2">
                <span class="text-blue-600">Submitted</span>
                <span class="<?= in_array($application['Status'], ['Under Review', 'Shortlisted', 'Approved', 'Rejected']) ? 'text-amber-600' : 'text-slate-300' ?>">Review</span>
                <span class="<?= in_array($application['Status'], ['Shortlisted', 'Approved', 'Rejected']) ? 'text-purple-600' : 'text-slate-300' ?>">Shortlisted</span>
                <?php if($application['Status'] === 'Rejected'): ?>
                    <span class="text-red-600">Rejected</span>
                <?php else: ?>
                    <span class="<?= $application['Status'] === 'Approved' ? 'text-green-600' : 'text-slate-300' ?>">Approved</span>
                <?php endif; ?>
            </div>
            <div class="w-full h-2.5 bg-slate-100 rounded-full overflow-hidden flex">
                <div class="<?= getProgressWidth($application['Status']) ?> <?= getProgressColor($application['Status']) ?> h-full transition-all duration-1000"></div>
            </div>
        </div>

        <div class="border-t border-slate-100 pt-8 space-y-6">
            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-4">Application Form Answers</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 mb-6">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Declared GWA / GPA</p>
                    <p class="font-black text-blue-600 text-lg lg:text-xl"><?= number_format($application['GPA'], 2) ?></p>
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Submitted On</p>
                    <p class="font-bold text-slate-900 text-sm lg:text-base"><?= date('F j, Y, g:i A', strtotime($application['DateSubmitted'])) ?></p>
                </div>
            </div>

            <?php if(empty($custom_answers)): ?>
                <p class="text-sm font-medium text-slate-400 italic">No custom form questions were required for this application.</p>
            <?php else: ?>
                <?php foreach($custom_answers as $ans): ?>
                    <div class="<?= $ans['FieldType'] === 'Textarea' ? 'col-span-full' : '' ?> mb-4">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2"><?= htmlspecialchars($ans['FieldName']) ?></p>
                        <?php if($ans['FieldType'] === 'Textarea'): ?>
                            <div class="p-5 bg-slate-50 rounded-xl border border-slate-100 text-sm text-slate-700 leading-relaxed whitespace-pre-wrap font-medium">
                                <?= htmlspecialchars($ans['AnswerText']) ?>
                            </div>
                        <?php else: ?>
                            <p class="font-bold text-slate-900 text-sm p-4 bg-slate-50 border border-slate-100 rounded-xl"><?= htmlspecialchars($ans['AnswerText']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="border-t border-slate-100 pt-8">
            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-4">Attached Documents</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php foreach($documents as $doc): ?>
                    <div class="p-4 bg-slate-50 border border-slate-100 rounded-xl flex items-center justify-between">
                        <div class="truncate pr-4">
                            <p class="text-xs font-black text-slate-800 uppercase tracking-widest mb-1 truncate"><?= htmlspecialchars($doc['DocumentName']) ?></p>
                            <span class="text-[10px] font-bold <?= $doc['VerificationStatus'] === 'Verified' ? 'text-green-600' : 'text-slate-500' ?> uppercase tracking-widest">
                                Status: <?= htmlspecialchars($doc['VerificationStatus']) ?>
                            </span>
                        </div>
                        <a href="<?= htmlspecialchars($doc['FilePath']) ?>" target="_blank" class="w-10 h-10 shrink-0 bg-white border border-slate-200 text-blue-600 rounded-xl flex items-center justify-center hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>
<?php include '../includes/footer.php'; ?>