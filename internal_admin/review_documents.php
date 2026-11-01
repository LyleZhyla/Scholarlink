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

$student_user_id = $_GET['user_id'] ?? null;

if (!$student_user_id) {
    echo "<script>alert('No student selected!'); window.location='applications.php';</script>";
    exit();
}

try {
    // 1. THE FIX: Fetch the student's name independently first!
    $user_stmt = $pdo->prepare("SELECT FirstName, LastName FROM Users WHERE UserID = :uid");
    $user_stmt->execute(['uid' => $student_user_id]);
    $student_info = $user_stmt->fetch();
    
    $fullName = $student_info ? htmlspecialchars($student_info['FirstName'] . ' ' . $student_info['LastName']) : "Unknown Student";

    // 2. Fetch the documents
    $stmt = $pdo->prepare("
        SELECT sd.*, dr.DocumentName 
        FROM Submitted_Document sd
        JOIN Document_Requirement dr ON sd.RequirementID = dr.RequirementID
        JOIN Application a ON sd.ApplicationID = a.ApplicationID
        WHERE a.UserID = :uid
    ");
    $stmt->execute(['uid' => $student_user_id]);
    $docs = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="flex flex-col sm:flex-row sm:justify-between sm:items-end gap-4 mb-8 lg:mb-10">
        <div>
            <h2 class="text-2xl lg:text-4xl font-black text-slate-900 tracking-tight">Review Documents</h2>
            <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Viewing files for: <strong class="text-blue-600"><?= $fullName ?></strong></p>
        </div>
        <a href="view_student_profile.php?user_id=<?= $student_user_id ?>" class="inline-flex items-center justify-center bg-white border border-slate-200 text-slate-600 px-6 py-3 rounded-xl font-black text-[10px] lg:text-xs uppercase tracking-widest hover:bg-slate-50 hover:text-slate-900 transition-all shadow-sm">
            ← Back to Profile
        </a>
    </header>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
        <?php foreach($docs as $doc): ?>
            <div class="bg-white p-6 lg:p-8 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between group hover:border-green-300 transition-all">
                <div>
                    <div class="flex justify-between items-start mb-6">
                        <div class="w-12 h-12 lg:w-14 lg:h-14 bg-slate-900 rounded-[1rem] lg:rounded-2xl flex items-center justify-center text-white text-xl lg:text-2xl shadow-md group-hover:bg-green-600 transition-colors">📄</div>
                        <span class="bg-slate-50 text-slate-400 px-3 py-1 rounded-full text-[9px] lg:text-[10px] font-black uppercase tracking-widest border border-slate-100">
                            <?= htmlspecialchars($doc['VerificationStatus']) ?>
                        </span>
                    </div>
                    <h4 class="text-base lg:text-lg font-black text-slate-900 mb-1"><?= htmlspecialchars($doc['DocumentName']) ?></h4>
                    <p class="text-[9px] lg:text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                        Uploaded: <?= date('M d, Y', strtotime($doc['UploadDate'])) ?>
                    </p>
                </div>
                <div class="mt-8">
                    <a href="<?= htmlspecialchars($doc['FilePath']) ?>" target="_blank" class="block w-full text-center bg-slate-100 text-slate-900 py-3.5 lg:py-4 rounded-xl lg:rounded-2xl font-black text-[10px] lg:text-xs uppercase tracking-widest hover:bg-green-600 hover:text-white transition-all">
                        View File 👁️
                    </a>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if(empty($docs)): ?>
            <div class="col-span-full bg-white p-10 lg:p-20 rounded-[2rem] border border-slate-200 text-center shadow-sm">
                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4 grayscale opacity-50">📂</div>
                <h3 class="text-lg font-black text-slate-900 mb-1">No Documents Found</h3>
                <p class="text-slate-500 font-medium text-sm">This student hasn't uploaded any required files yet.</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include '../includes/footer.php'; ?>