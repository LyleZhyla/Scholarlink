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

// Capture UserID from URL (Updated from student_id)
$view_user_id = $_GET['user_id'] ?? null;

if (!$view_user_id) {
    echo "<script>alert('No student selected!'); window.location='scholars.php';</script>";
    exit();
}

try {
    // Single query to fetch everything from the Users table
    $stmt = $pdo->prepare("SELECT * FROM Users WHERE UserID = :uid");
    $stmt->execute(['uid' => $view_user_id]);
    $student = $stmt->fetch();

    if (!$student) { die("<div class='p-10 text-center font-black text-red-500'>Error: Student record not found.</div>"); }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<main class="flex-1 ml-72 p-10 bg-slate-50/50 min-h-screen">
    <header class="flex justify-between items-center mb-10">
        <div>
            <h2 class="text-4xl font-black text-slate-900 tracking-tight">Student Profile</h2>
            <p class="text-slate-500 font-medium mt-1">Detailed overview for <span class="text-slate-900"><?= htmlspecialchars($student['FirstName'] . ' ' . $student['LastName']) ?></span></p>
        </div>
        <button onclick="history.back()" class="text-slate-400 font-bold hover:text-slate-900 transition-colors">← Back</button>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white p-10 rounded-[2.5rem] border border-slate-200 shadow-sm text-center">
                <div class="w-24 h-24 bg-blue-600 text-white rounded-3xl flex items-center justify-center text-4xl font-black mx-auto mb-6 shadow-xl shadow-blue-200">
                    <?= substr($student['FirstName'], 0, 1) ?>
                </div>
                <h3 class="text-2xl font-black text-slate-900 mb-1"><?= htmlspecialchars($student['FirstName'] . ' ' . $student['LastName']) ?></h3>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?= htmlspecialchars($student['StudentID_Num']) ?></p>
            </div>
            
            <a href="review_documents.php?user_id=<?= $student['UserID'] ?>" class="block w-full text-center bg-slate-900 text-white py-5 rounded-2xl font-black text-xs uppercase tracking-widest hover:bg-green-600 transition-all shadow-xl shadow-slate-200">
                Review Documents 📄
            </a>
        </div>

        <div class="lg:col-span-2 bg-white p-10 rounded-[2.5rem] border border-slate-200 shadow-sm">
            <h4 class="text-lg font-black text-slate-900 mb-8 uppercase tracking-tight flex items-center gap-2">
                <span class="w-2 h-6 bg-green-500 rounded-full"></span> Academic & Contact Info
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div><p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Program</p><p class="text-lg font-black text-slate-900"><?= htmlspecialchars($student['Major'] ?? 'N/A') ?></p></div>
                <div><p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">GWA</p><p class="text-3xl font-black text-green-600"><?= htmlspecialchars($student['GPA'] ?? '0.00') ?></p></div>
                <div><p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Email</p><p class="text-sm font-bold text-slate-900"><?= htmlspecialchars($student['Email']) ?></p></div>
                <div><p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Contact</p><p class="text-sm font-bold text-slate-900"><?= htmlspecialchars($student['ContactNumber'] ?? 'N/A') ?></p></div>
            </div>
        </div>
    </div>
</main>
<?php include '../includes/footer.php'; ?>