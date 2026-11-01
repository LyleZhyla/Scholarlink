<?php
// ✨ "I HAVE A CODE": finish a password reset / first password with the backup code from the email (e.g. K7F2-9QXD),
// for when the email's link won't open (e.g. it points to an old IP address). A valid code continues on
// reset_password.php exactly like the link would. Codes come from resetCodeFor() in includes/security_settings.php.
session_start();
require 'includes/db_connect.php';

$ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$recent_failures = function () use ($pdo, $ip): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE ActionPerformed = 'Reset Code Failed' AND IPAddress = ? AND ActionDate > NOW() - INTERVAL 15 MINUTE");
    $st->execute([$ip]);
    return (int) $st->fetchColumn();
};

$error = null;
$typed = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $typed = strtoupper(trim((string) ($_POST['code'] ?? '')));
    if ($recent_failures() >= RESET_CODE_MAX_TRIES) {
        $error = 'Too many wrong codes. Please wait 15 minutes, or request a new link with "Forgot password".';
    } elseif ($token = findResetTokenByCode($pdo, $typed)) {
        $who = $pdo->prepare("SELECT UserID FROM users WHERE ResetToken = ?");
        $who->execute([$token]);
        logAudit($pdo, $who->fetchColumn() ?: null, 'Reset Code Used', 'Password link opened with the backup code from the email');
        header('Location: reset_password.php?token=' . urlencode($token));
        exit();
    } else {
        logAudit($pdo, null, 'Reset Code Failed', 'Wrong or expired backup code entered');
        $left = RESET_CODE_MAX_TRIES - $recent_failures();
        $error = "That code isn't valid. It may be mistyped, expired, or replaced by a newer email." . ($left > 0 && $left <= 3 ? " $left attempt(s) left." : '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Your Code - ScholarLink</title>
    <?php require_once __DIR__ . '/includes/theme.php'; themeHead(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="theme-standalone bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-[2rem] shadow-2xl shadow-slate-200/50 border border-slate-100 p-8 sm:p-10">
        <div class="text-center mb-8">
            <img src="assets/img/tau_logo.png" alt="TAU Logo" class="h-16 w-auto mx-auto mb-6">
            <h2 class="text-xs font-black text-green-600 uppercase tracking-widest mb-2">Password help</h2>
            <h3 class="text-3xl font-black text-slate-900 tracking-tight">Enter your code</h3>
            <p class="text-slate-500 text-sm font-medium mt-3">Type the 8-character code from your ScholarLink email (welcome or password reset). It's in the box below the green button.</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-5 text-sm font-bold">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <input type="text" name="code" value="<?= htmlspecialchars($typed) ?>" required autofocus autocomplete="one-time-code" autocapitalize="characters" spellcheck="false"
                   maxlength="9" placeholder="XXXX-XXXX"
                   oninput="let v = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8); this.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4) : v;"
                   class="w-full px-5 py-4 rounded-2xl border border-slate-200 bg-white text-center text-3xl font-black tracking-[0.3em] text-slate-900 placeholder:text-slate-300 focus:border-slate-500 focus:ring-4 focus:ring-slate-100 outline-none font-mono">
            <button type="submit" class="w-full bg-slate-950 hover:bg-black text-white font-bold py-4 rounded-2xl shadow-lg shadow-slate-900/20 active:scale-[0.98]">Continue</button>
        </form>

        <div class="flex items-center justify-between mt-6 pt-6 border-t border-slate-100 text-sm font-bold">
            <a href="student_login.php" class="text-slate-400 hover:text-slate-700">← Student login</a>
            <a href="admin_login.php" class="text-slate-400 hover:text-slate-700">Admin login →</a>
        </div>
    </div>
    <?php themeToggle('corner'); ?>
</body>
</html>
