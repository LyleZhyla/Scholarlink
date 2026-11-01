<?php
session_start();

// ✨ 1. SESSION MANAGER
include '../includes/session_manager.php';

// 🔒 2. THE SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') {
    session_destroy();
    header("Location: ../admin_login.php");
    exit();
}

include '../includes/db_connect.php';
require_once '../includes/audit_gate.php';

$max_attempts = 5;

// Only allow redirects back to the audit pages (no open redirect)
$next = $_GET['next'] ?? 'logs.php';
if (!preg_match('/^(logs|export_logs_pdf|dashboard)\.php(\?.*)?$/', $next)) {
    $next = 'logs.php';
}

function writeAudit(PDO $pdo, string $action, string $description): void {
    try {
        $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, ?, NOW(), ?, ?)")
            ->execute([$_SESSION['user_id'], $action, $description, $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
    } catch (PDOException $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

$admin_label = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');

// --- MANUAL LOCK ---
if (($_GET['action'] ?? '') === 'lock') {
    unset($_SESSION['audit_unlocked_until']);
    writeAudit($pdo, 'Audit Log Locked', "$admin_label locked the audit logs");
    header("Location: dashboard.php");
    exit();
}

if (isAuditUnlocked()) {
    header("Location: " . $next);
    exit();
}

// --- PASSWORD RE-VERIFICATION ---
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("SELECT PasswordHash FROM users WHERE UserID = ? AND Role = 'Super_Admin' AND AccountStatus = 'Active'");
    $stmt->execute([$_SESSION['user_id']]);
    $hash = $stmt->fetchColumn();

    if ($hash && password_verify($_POST['password'] ?? '', $hash)) {
        session_regenerate_id(true);
        $_SESSION['audit_unlocked_until'] = time() + AUDIT_UNLOCK_SECONDS;
        unset($_SESSION['audit_unlock_attempts']);
        writeAudit($pdo, 'Audit Log Access', "$admin_label unlocked the audit logs");
        header("Location: " . $next);
        exit();
    }

    $_SESSION['audit_unlock_attempts'] = ($_SESSION['audit_unlock_attempts'] ?? 0) + 1;
    writeAudit($pdo, 'Audit Log Access Denied', "$admin_label entered a wrong password for the audit logs (attempt {$_SESSION['audit_unlock_attempts']} of $max_attempts)");

    // Too many wrong passwords: end the whole session
    if ($_SESSION['audit_unlock_attempts'] >= $max_attempts) {
        writeAudit($pdo, 'Auto Logout', "$admin_label was logged out after $max_attempts failed audit log unlock attempts");
        session_unset();
        $_SESSION['error'] = "You were logged out after too many failed attempts to access the audit logs.";
        header("Location: ../admin_login.php");
        exit();
    }

    $remaining = $max_attempts - $_SESSION['audit_unlock_attempts'];
    $error = "Incorrect password. $remaining attempt" . ($remaining === 1 ? '' : 's') . " left before you are logged out.";
}

include '../includes/header.php';
include '../includes/super_sidebar.php';
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300 flex items-center justify-center">
    <div class="bg-white w-full max-w-md rounded-[2rem] border border-slate-200 shadow-sm p-8 lg:p-10 text-center">
        <div class="w-16 h-16 bg-slate-900 text-white rounded-2xl flex items-center justify-center text-3xl mx-auto mb-6">🔒</div>
        <h2 class="text-2xl font-black text-slate-900 tracking-tight">Restricted: Audit Logs</h2>
        <p class="text-slate-500 text-sm font-medium mt-2 mb-8">
            Audit logs contain sensitive activity records. Re-enter your password to continue.
            Access stays open for <?= AUDIT_UNLOCK_SECONDS / 60 ?> minutes and is itself recorded.
        </p>

        <?php if ($error): ?>
            <div class="p-3 mb-6 bg-red-50 border border-red-100 rounded-xl text-sm font-bold text-red-700 text-left"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="audit_unlock.php?next=<?= urlencode($next) ?>" class="space-y-4 text-left">
            <label for="password" class="text-[12px] font-black text-slate-500 uppercase tracking-widest">Password</label>
            <input type="password" id="password" name="password" required autofocus autocomplete="current-password"
                   class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-2 focus:ring-blue-500">
            <button type="submit" class="w-full bg-slate-900 text-white px-5 py-3 rounded-xl text-[14px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-md active:scale-95">
                Verify &amp; Unlock
            </button>
        </form>

        <a href="dashboard.php" class="inline-block mt-6 text-[12px] font-black text-slate-400 uppercase tracking-widest hover:text-slate-900">← Back to Dashboard</a>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
