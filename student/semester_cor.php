<?php
// ✨ NEW-SEMESTER WALL: when the OSSD starts a semester, every student lands here and can't use the rest of the student
// portal until they upload their Certificate of Registration (COR) for it. The AI (includes/cor_scanner.php) checks it
// is a genuine COR for the declared semester, in the student's name: a COR it rejects keeps the wall closed; when the
// AI can't run, the COR is accepted and flagged for an admin (the Semester page).
// includes/security_settings.php (requireSemesterCorFirst) sends students here.
session_start();
require '../includes/db_connect.php';
require_once '../includes/semester.php';
require_once '../includes/cor_scanner.php';
require_once '../includes/document_security.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}
$user_id = (int) $_SESSION['user_id'];
$term = activeTerm($pdo);
if (!$term || !studentNeedsCor($pdo, $user_id)) { header('Location: dashboard.php'); exit(); }
$term_name = termDisplayName($term);
$stmt = $pdo->prepare("SELECT FirstName, MiddleName, LastName FROM users WHERE UserID = ?");
$stmt->execute([$user_id]);
$me = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['FirstName' => '', 'MiddleName' => '', 'LastName' => ''];
$full_name = trim(preg_replace('/\s+/', ' ', "{$me['FirstName']} {$me['MiddleName']} {$me['LastName']}"));
$previous = studentTermCor($pdo, $user_id, (int) $term['TermID']);   // a COR rejected earlier (kept to say why)

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(120);   // the AI reading takes a few seconds
    $file = $_FILES['cor_file'] ?? null;
    $saved = null;
    try {
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose your Certificate of Registration first.');
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new RuntimeException('That file is larger than 10MB.');
        if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The upload failed. Please try again.');
        $meta = inspectDocumentFile($file['tmp_name'], (string) $file['name']);   // a real PDF / JPG / PNG, malware-scanned

        $scan = scanCorForTerm((string) file_get_contents($file['tmp_name']), $meta['mime_type'], (string) $term['Semester'], (string) $term['SchoolYear'], $full_name);
        if ($scan['verdict'] === 'fail') throw new RuntimeException($scan['reason']);

        $dir = dirname(__DIR__) . '/uploads/semester_cor/';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = "STU-$user_id-COR-T{$term['TermID']}-" . bin2hex(random_bytes(6)) . '.' . $meta['extension'];
        if (!move_uploaded_file($file['tmp_name'], $dir . $name)) throw new RuntimeException('The file could not be saved. Please try again.');
        $saved = $dir . $name;
        $path = '../uploads/semester_cor/' . $name;
        $status = $scan['verdict'] === 'pass' ? 'Verified' : 'Needs Review';

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO student_term_cor (UserID, TermID, FilePath, OriginalFileName, MimeType, FileSize, FileChecksum, AIStatus, AIRemarks, SemesterFound)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE FilePath = VALUES(FilePath), OriginalFileName = VALUES(OriginalFileName), MimeType = VALUES(MimeType), FileSize = VALUES(FileSize),
                FileChecksum = VALUES(FileChecksum), AIStatus = VALUES(AIStatus), AIRemarks = VALUES(AIRemarks), SemesterFound = VALUES(SemesterFound),
                UploadedAt = NOW(), ReviewedBy = NULL, ReviewedAt = NULL")
            ->execute([$user_id, $term['TermID'], $path, $meta['original_name'], $meta['mime_type'], $meta['file_size'], $meta['checksum'], $status, mb_substr($scan['reason'], 0, 1000), $scan['semester_found']]);
        // a scholar's renewal uses this COR too
        $pdo->prepare('UPDATE scholar_renewals SET COR_FilePath = ? WHERE UserID = ? AND TermID = ?')->execute([$path, $user_id, $term['TermID']]);
        logAudit($pdo, $user_id, 'Semester COR Uploaded', "COR for $term_name uploaded: " . ($status === 'Verified' ? 'verified by the AI' : 'kept for an admin to check (' . $scan['reason'] . ')'));
        $pdo->commit();
        if ($previous && is_file(__DIR__ . '/' . $previous['FilePath'])) @unlink(__DIR__ . '/' . $previous['FilePath']);

        $_SESSION['success'] = $status === 'Verified'
            ? "Your COR for $term_name is verified. Welcome back!"
            : "Your COR for $term_name was received. An OSSD staff member will check it.";
        header('Location: dashboard.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($saved && is_file($saved)) @unlink($saved);
        $error = $e->getMessage();
        logAudit($pdo, $user_id, 'Semester COR Rejected', mb_substr("COR for $term_name: $error", 0, 250));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Your COR - ScholarLink</title>
    <?php require_once '../includes/theme.php'; themeHead(); ?>
    <script src="../assets/lib/tailwind.js"></script>
    <link href="../assets/lib/fonts/fonts.css" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="theme-standalone bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl shadow-slate-200/50 border border-slate-100 p-8 sm:p-10">
        <div class="text-center mb-8">
            <img src="../assets/img/tau_logo.png" alt="TAU Logo" class="h-16 w-auto mx-auto mb-6">
            <h2 class="text-xs font-black text-green-600 uppercase tracking-widest mb-2">New semester · <?= htmlspecialchars($term_name) ?></h2>
            <h3 class="text-3xl font-black text-slate-900 tracking-tight">Upload your Certificate of Registration</h3>
            <p class="text-slate-500 text-sm font-medium mt-3">
                Welcome back, <?= htmlspecialchars($me['FirstName'] ?: 'student') ?>! <b><?= htmlspecialchars($term_name) ?></b> has started.
                Upload your COR for this semester to continue using ScholarLink.
            </p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-5 text-sm font-bold">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php elseif ($previous && $previous['AIStatus'] === 'Rejected'): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-5 text-sm font-bold">⚠️ The OSSD could not accept your COR<?= $previous['AIRemarks'] ? ': ' . htmlspecialchars($previous['AIRemarks']) : '' ?>. Please upload it again.</div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="corForm" class="space-y-5">
            <label for="corFile" class="block border-2 border-dashed border-slate-200 hover:border-green-400 rounded-2xl p-6 text-center cursor-pointer transition-colors">
                <div class="text-4xl mb-2">🧾</div>
                <p class="font-black text-slate-800" id="corName">Choose your COR (PDF, JPG or PNG)</p>
                <p class="text-xs font-semibold text-slate-400 mt-1">For <?= htmlspecialchars($term_name) ?> · up to 10MB</p>
                <input type="file" name="cor_file" id="corFile" accept="application/pdf,.pdf,image/jpeg,image/png,.jpg,.jpeg,.png" required class="sr-only"
                       onchange="document.getElementById('corName').textContent = this.files[0] ? this.files[0].name : 'Choose your COR (PDF, JPG or PNG)'">
            </label>

            <ul class="text-xs font-semibold text-slate-500 space-y-1.5 bg-slate-50 rounded-2xl p-4 border border-slate-100">
                <li>✔ It must be your COR for <b><?= htmlspecialchars($term_name) ?></b>, not an older semester's.</li>
                <li>✔ It must show your own name. Upload it exactly as you got it: edited files are rejected.</li>
                <li>✔ It is checked automatically and kept for the OSSD.</li>
            </ul>

            <button type="submit" id="corSubmit" class="w-full bg-slate-950 hover:bg-green-600 text-white font-bold py-4 rounded-2xl shadow-lg shadow-slate-900/20 active:scale-[0.98] transition-colors flex items-center justify-center gap-3">
                <span id="corSpin" class="hidden w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                <span id="corLabel">Upload My COR</span>
            </button>
        </form>

        <div class="flex items-center justify-between mt-6 pt-6 border-t border-slate-100 text-sm font-bold">
            <span class="text-slate-400">Need help? Contact the OSSD.</span>
            <a href="../actions/process_logout.php" onclick="sessionStorage.removeItem('scholarlink_chat_history');" class="text-slate-400 hover:text-red-600">Sign out</a>
        </div>
    </div>

    <script>
        // Checking takes a few seconds: show progress and stop double submits
        document.getElementById('corForm').addEventListener('submit', () => {
            const btn = document.getElementById('corSubmit');
            btn.disabled = true;
            btn.classList.add('opacity-80', 'cursor-wait');
            document.getElementById('corSpin').classList.remove('hidden');
            document.getElementById('corLabel').textContent = 'Checking your COR...';
        });
    </script>
    <?php themeToggle('corner'); ?>
</body>
</html>
