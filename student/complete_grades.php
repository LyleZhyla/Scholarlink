<?php
// ✨ GWA WALL: students without a GWA (e.g. accounts the Super Admin created) land here after signing in
// and can't use the rest of the student portal until their Report of Grades is read.
// includes/security_settings.php (requireGradesFirst) sends them here; the ROG is checked by includes/rog_scanner.php.
session_start();
require '../includes/db_connect.php';
require_once '../includes/rog_scanner.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}
$user_id = (int) $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT FirstName, LastName, GPA, StudentID_Num FROM users WHERE UserID = ?");
$stmt->execute([$user_id]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if ($me && $me['GPA'] !== null) {     // already done
    header('Location: dashboard.php');
    exit();
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(120);   // the AI reading takes a few seconds
    $file = $_FILES['rog_pdf'] ?? null;
    try {
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) throw new Exception('Choose your Report of Grades (PDF) first.');
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > 10 * 1024 * 1024) throw new Exception('That file is larger than 10MB.');
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('The upload failed. Please try again.');
        $binary = (string) file_get_contents($file['tmp_name']);
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf' || strncmp($binary, '%PDF', 4) !== 0) throw new Exception('Only PDF files are accepted. Download your Report of Grades as a PDF from the TAU portal.');

        $result = scanReportOfGrades($binary);
        if (empty($result['success'])) throw new Exception($result['error'] ?? 'The document could not be read. Please upload a clear Report of Grades.');
        $gwa = is_numeric($result['computed_gwa'] ?? null) ? round((float) $result['computed_gwa'], 2) : null;
        if (empty($result['is_valid'])) {
            throw new Exception("The computed average ({$result['computed_gwa']}) doesn't match the GWA on the document ({$result['stated_gwa']}). Please upload your original Report of Grades.");
        }
        if ($gwa === null || $gwa < 1 || $gwa > 5) throw new Exception("We couldn't read a GWA from this document. Please upload your original Report of Grades.");
        $units = (int) ($result['total_units'] ?? 0);

        // Keep the ROG in the student's vault, where applications can reuse it
        $dir = '../uploads/vault/' . $user_id . '/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $path = $dir . time() . '_report_of_grades.pdf';
        if (!move_uploaded_file($file['tmp_name'], $path)) throw new Exception('The file could not be saved. Please try again.');
        $old = $pdo->prepare("SELECT VaultID, FilePath FROM user_vault WHERE UserID = ? AND DocumentType = 'Report of Grades'");
        $old->execute([$user_id]);
        if ($existing = $old->fetch(PDO::FETCH_ASSOC)) {
            if (is_file($existing['FilePath'])) @unlink($existing['FilePath']);
            $pdo->prepare("UPDATE user_vault SET FilePath = ?, UploadDate = CURRENT_TIMESTAMP WHERE VaultID = ?")->execute([$path, $existing['VaultID']]);
        } else {
            $pdo->prepare("INSERT INTO user_vault (UserID, DocumentType, FilePath) VALUES (?, 'Report of Grades', ?)")->execute([$user_id, $path]);
        }

        $pdo->prepare("UPDATE users SET GPA = ?, UnitsEnrolled = ? WHERE UserID = ?")->execute([$gwa, $units ?: null, $user_id]);
        logAudit($pdo, $user_id, 'Report of Grades Uploaded', "{$me['FirstName']} {$me['LastName']} uploaded their Report of Grades; GWA $gwa recorded" . ($units ? " ($units units)" : ''));
        $_SESSION['success'] = "Your GWA of " . number_format($gwa, 2) . " is recorded. You can now browse and apply for scholarships.";
        header('Location: dashboard.php');
        exit();
    } catch (Throwable $e) {
        $error = $e->getMessage();
        logAudit($pdo, $user_id, 'Report of Grades Rejected', mb_substr($error, 0, 250));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Your Report of Grades - ScholarLink</title>
    <?php require_once '../includes/theme.php'; themeHead(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="theme-standalone bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl shadow-slate-200/50 border border-slate-100 p-8 sm:p-10">
        <div class="text-center mb-8">
            <img src="../assets/img/tau_logo.png" alt="TAU Logo" class="h-16 w-auto mx-auto mb-6">
            <h2 class="text-xs font-black text-green-600 uppercase tracking-widest mb-2">One last step</h2>
            <h3 class="text-3xl font-black text-slate-900 tracking-tight">Upload your Report of Grades</h3>
            <p class="text-slate-500 text-sm font-medium mt-3">
                Welcome, <?= htmlspecialchars($me['FirstName'] ?? 'student') ?>! Scholarships match you by your GWA, so we read it from your latest
                Report of Grades before you can continue.
            </p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-5 text-sm font-bold">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="rogForm" class="space-y-5">
            <label for="rogFile" class="block border-2 border-dashed border-slate-200 hover:border-green-400 rounded-2xl p-6 text-center cursor-pointer transition-colors">
                <div class="text-4xl mb-2">📄</div>
                <p class="font-black text-slate-800" id="rogName">Choose your Report of Grades (PDF)</p>
                <p class="text-xs font-semibold text-slate-400 mt-1">The original PDF downloaded from the TAU portal · up to 10MB</p>
                <input type="file" name="rog_pdf" id="rogFile" accept="application/pdf,.pdf" required class="sr-only"
                       onchange="document.getElementById('rogName').textContent = this.files[0] ? this.files[0].name : 'Choose your Report of Grades (PDF)'">
            </label>

            <ul class="text-xs font-semibold text-slate-500 space-y-1.5 bg-slate-50 rounded-2xl p-4 border border-slate-100">
                <li>✔ Upload the PDF exactly as downloaded. Edited, scanned or re-saved files are rejected.</li>
                <li>✔ Your GWA is computed from your subjects and units and checked against the GWA printed on it.</li>
                <li>✔ The file is also saved to your Document Vault for your applications.</li>
            </ul>

            <button type="submit" id="rogSubmit" class="w-full bg-slate-950 hover:bg-green-600 text-white font-bold py-4 rounded-2xl shadow-lg shadow-slate-900/20 active:scale-[0.98] transition-colors flex items-center justify-center gap-3">
                <span id="rogSpin" class="hidden w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                <span id="rogLabel">Read My Grades</span>
            </button>
        </form>

        <div class="flex items-center justify-between mt-6 pt-6 border-t border-slate-100 text-sm font-bold">
            <span class="text-slate-400">Need help? Contact the OSSD.</span>
            <a href="../actions/process_logout.php" onclick="sessionStorage.removeItem('scholarlink_chat_history');" class="text-slate-400 hover:text-red-600">Sign out</a>
        </div>
    </div>

    <script>
        // Reading takes a few seconds: show progress and stop double submits
        document.getElementById('rogForm').addEventListener('submit', () => {
            const btn = document.getElementById('rogSubmit');
            btn.disabled = true;
            btn.classList.add('opacity-80', 'cursor-wait');
            document.getElementById('rogSpin').classList.remove('hidden');
            document.getElementById('rogLabel').textContent = 'Checking your document and computing your GWA...';
        });
    </script>
    <?php themeToggle('corner'); ?>
</body>
</html>
