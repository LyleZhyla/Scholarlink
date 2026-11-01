<?php
// ✨ TWO-FACTOR AUTHENTICATION: second step of the admin login (when Super Admin → Security → 2FA is ON)
// The password was already accepted in actions/process_login.php, which emailed a 6-digit code.
session_start();
require 'includes/db_connect.php';

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending) {
    header('Location: admin_login.php');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE UserID = ?");
$stmt->execute([$pending['user_id']]);
$user = $stmt->fetch();
if (!$user) {
    unset($_SESSION['pending_2fa']);
    header('Location: admin_login.php');
    exit();
}
$name = $user['FirstName'] . ' ' . $user['LastName'];

$fail = function (string $message, bool $restart = false) use ($pdo, $user, $name) {
    if ($restart) {
        unset($_SESSION['pending_2fa']);
        $_SESSION['error'] = $message;
        header('Location: admin_login.php');
    } else {
        $_SESSION['error'] = $message;
        header('Location: admin_2fa.php');
    }
    exit();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? 'verify';

    if ($do === 'cancel') {
        logAudit($pdo, $user['UserID'], 'Login Cancelled', "$name cancelled the two-factor step");
        $fail('Sign-in cancelled.', true);
    }

    if ($do === 'resend') {
        if (time() - ($pending['sent_at'] ?? 0) < 30) $fail('Please wait a few seconds before asking for another code.');
        $attempts = $pending['attempts'];
        if (!startTwoFactor($pdo, $user, $pending['portal'])) $fail("We couldn't send a new code. Try again in a moment.");
        $_SESSION['pending_2fa']['attempts'] = $attempts;   // a new code doesn't reset wrong guesses
        logAudit($pdo, $user['UserID'], '2FA Code Sent', "New login code emailed to " . maskEmail($user['Email']));
        $_SESSION['success'] = 'A new code was sent to your email.';
        header('Location: admin_2fa.php');
        exit();
    }

    // Check the code
    $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
    if (time() > $pending['expires']) {
        logAudit($pdo, $user['UserID'], 'Login Failed', "Expired two-factor code for $name");
        $fail('That code has expired. Tap "Send a new code".');
    }
    if (strlen($code) !== 6 || !password_verify($code, $pending['code_hash'])) {
        $_SESSION['pending_2fa']['attempts'] = ++$pending['attempts'];
        logAudit($pdo, $user['UserID'], 'Login Failed', "Wrong two-factor code for $name (attempt {$pending['attempts']} of " . TWO_FA_MAX_ATTEMPTS . ')');
        if ($pending['attempts'] >= TWO_FA_MAX_ATTEMPTS) $fail('Too many wrong codes. Please sign in again.', true);
        $fail('Incorrect code. ' . (TWO_FA_MAX_ATTEMPTS - $pending['attempts']) . ' attempt(s) left.');
    }

    // Archived while waiting for the code: no sign-in
    if ($blocked = accountArchivedMessage($user)) {
        logAudit($pdo, $user['UserID'], 'Login Denied', "$name entered a valid code, but the account is archived");
        $fail($blocked, true);
    }

    completeLogin($pdo, $user, $pending['portal'], ' with two-factor authentication');
    header('Location: ' . dashboardFor($user['Role']));
    exit();
}

$seconds_left = max(0, $pending['expires'] - time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Login - ScholarLink</title>
    <?php require_once __DIR__ . '/includes/theme.php'; themeHead(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="theme-standalone bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-[2rem] shadow-2xl shadow-slate-200/50 border border-slate-100 p-8 sm:p-10">
        <div class="text-center mb-8">
            <img src="assets/img/tau_logo.png" alt="TAU Logo" class="h-16 w-auto mx-auto mb-6">
            <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Two-Factor Authentication</h2>
            <h3 class="text-3xl font-black text-slate-900 tracking-tight">Check your email</h3>
            <p class="text-slate-500 text-sm font-medium mt-3">Hi <?= htmlspecialchars($user['FirstName']) ?>, we sent a 6-digit code to <span class="font-bold text-slate-700"><?= htmlspecialchars(maskEmail($user['Email'])) ?></span>.</p>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-5 text-sm font-bold">⚠️ <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-2xl mb-5 text-sm font-bold">✅ <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus placeholder="••••••"
                oninput="this.value = this.value.replace(/\D/g, '').slice(0, 6); if (this.value.length === 6) this.form.requestSubmit();"
                class="w-full px-5 py-4 rounded-2xl border border-slate-200 bg-white text-center text-3xl font-black tracking-[0.5em] text-slate-900 placeholder:text-slate-300 focus:border-slate-500 focus:ring-4 focus:ring-slate-100 outline-none">
            <p class="text-center text-xs font-bold text-slate-400">Code expires in <span id="tfaLeft" class="tabular-nums text-slate-600"></span></p>
            <button type="submit" class="w-full bg-slate-950 hover:bg-black text-white font-bold py-4 rounded-2xl shadow-lg shadow-slate-900/20 active:scale-[0.98]">Verify &amp; Sign In</button>
        </form>

        <div class="flex items-center justify-between mt-6 pt-6 border-t border-slate-100">
            <form method="POST"><input type="hidden" name="do" value="cancel"><button class="text-sm font-bold text-slate-400 hover:text-slate-700">← Cancel</button></form>
            <form method="POST"><input type="hidden" name="do" value="resend"><button class="text-sm font-bold text-blue-600 hover:text-blue-800">Send a new code</button></form>
        </div>
    </div>

    <script>
        let left = <?= (int) $seconds_left ?>;
        const el = document.getElementById('tfaLeft');
        (function tick() {
            el.textContent = left > 0 ? Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0') : 'expired — send a new code';
            if (left-- > 0) setTimeout(tick, 1000);
        })();
    </script>
    <?php themeToggle('corner'); ?>
</body>
</html>
