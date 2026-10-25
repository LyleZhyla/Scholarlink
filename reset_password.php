<?php
session_start();
require 'includes/db_connect.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : null;
$valid_token = false;
$user_id = null;

if ($token) {
    // Debug: Tingnan natin kung ano ang nakukuha mula sa URL
    // echo "Token from URL: " . htmlspecialchars($token) . "<br>";

    $stmt = $pdo->prepare("SELECT UserID FROM Users WHERE ResetToken = :token AND ResetTokenExpire > NOW()");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if ($user) {
        $valid_token = true;
        $user_id = $user['UserID'];
    } else {
        // ✨ DEBUG LOG: Tingnan natin kung may kaparehong email pero ibang token
        // Error logging can help track why the query returned false.
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - ScholarLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 md:p-10 relative">

    <div class="bg-white w-full max-w-[95%] sm:max-w-md rounded-[1.5rem] md:rounded-[2rem] shadow-2xl relative overflow-hidden p-6 md:p-10">
        
        <?php if (!$valid_token): ?>
            <div class="mb-8 text-center">
                <h3 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Invalid Link</h3>
            </div>
            <div class="bg-red-50 border border-red-200 text-red-700 p-6 rounded-2xl mb-6 text-sm font-bold text-center">
                <p class="text-3xl mb-2">⚠️</p>
                This password reset link is invalid or has already been used. Please request a new one.
            </div>
            <a href="student_login.php" onclick="sessionStorage.removeItem('scholarlink_chat_history');" class="block w-full text-center bg-slate-900 hover:bg-slate-800 text-white font-bold py-4 px-6 rounded-xl transition-all">
                 Return to Login
            </a>
        <?php else: ?>
            <div class="mb-8">
                <h3 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Create New Password</h3>
                <p class="text-[14px] text-green-600 font-bold mt-1 uppercase tracking-widest">Global Password Rule Enforced (8+ Chars)</p>
            </div>

            <?php if(isset($_SESSION['error'])): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl mb-6 text-md md:text-sm font-bold flex items-center gap-3">
                    ⚠️ <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <form action="actions/process_reset_password.php" method="POST" class="space-y-4 md:space-y-5">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="user_id" value="<?= $user_id ?>">

                <div>
                    <label class="block text-[14px] md:text-md font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">New Password</label>
                    <div class="relative">
                        <input type="password" name="new_password" id="new_pass" required class="w-full pl-4 pr-10 py-4 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        <button type="button" onclick="togglePassword('new_pass', 'eye_1')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-green-600 focus:outline-none">
                            <svg id="eye_1" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        </button>
                    </div>
                    
                    <div id="password-feedback" class="text-[14px] font-bold mt-3 space-y-1 hidden bg-slate-50 p-3 rounded-xl border border-slate-100 shadow-sm">
                        <p id="rule-length" class="text-red-500 transition-colors">✗ At least 8 characters</p>
                        <p id="rule-number" class="text-red-500 transition-colors">✗ At least 1 number (0-9)</p>
                        <p id="rule-symbol" class="text-red-500 transition-colors">✗ At least 1 special symbol (!@#$%^&*)</p>
                    </div>
                </div>

                <div>
                    <label class="block text-[14px] md:text-md font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Confirm New Password</label>
                    <div class="relative">
                        <input type="password" name="confirm_password" id="confirm_pass" required class="w-full pl-4 pr-10 py-4 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        <button type="button" onclick="togglePassword('confirm_pass', 'eye_2')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-green-600 focus:outline-none">
                            <svg id="eye_2" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        </button>
                    </div>
                    <p id="match-feedback" class="text-[14px] font-bold mt-2 hidden"></p>
                </div>

                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-xl transition-all shadow-lg active:scale-[0.98] mt-6">
                    Save New Password 🔒
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>';
            }
        }

        // --- REAL-TIME PASSWORD VALIDATION ---
        const passInput = document.getElementById('new_pass');
        const confirmInput = document.getElementById('confirm_pass');
        const feedbackBox = document.getElementById('password-feedback');
        const ruleLength = document.getElementById('rule-length');
        const ruleNumber = document.getElementById('rule-number');
        const ruleSymbol = document.getElementById('rule-symbol');
        const matchFeedback = document.getElementById('match-feedback');

        if (passInput && confirmInput) {
            function updateRuleUI(element, isValid, text) {
                if (isValid) {
                    element.classList.remove('text-red-500');
                    element.classList.add('text-green-600');
                    element.innerText = '✓ ' + text;
                } else {
                    element.classList.remove('text-green-600');
                    element.classList.add('text-red-500');
                    element.innerText = '✗ ' + text;
                }
            }

            function validatePassword() {
                const val = passInput.value;
                
                if (val.length > 0) feedbackBox.classList.remove('hidden');
                else feedbackBox.classList.add('hidden');

                const isValidLength = val.length >= 8;
                const isValidNumber = /[0-9]/.test(val);
                const isValidSymbol = /[!@#$%^&*()\-_=+{};:,<.>]/.test(val);

                updateRuleUI(ruleLength, isValidLength, "At least 8 characters");
                updateRuleUI(ruleNumber, isValidNumber, "At least 1 number (0-9)");
                updateRuleUI(ruleSymbol, isValidSymbol, "At least 1 special symbol");

                checkMatch();
            }

            function checkMatch() {
                const val1 = passInput.value;
                const val2 = confirmInput.value;

                if (val2.length > 0) {
                    matchFeedback.classList.remove('hidden');
                    if (val1 === val2) {
                        matchFeedback.classList.remove('text-red-500');
                        matchFeedback.classList.add('text-green-600');
                        matchFeedback.innerText = '✓ Passwords match';
                    } else {
                        matchFeedback.classList.remove('text-green-600');
                        matchFeedback.classList.add('text-red-500');
                        matchFeedback.innerText = '✗ Passwords do not match';
                    }
                } else {
                    matchFeedback.classList.add('hidden');
                }
            }

            passInput.addEventListener('input', validatePassword);
            confirmInput.addEventListener('input', checkMatch);
        }
    </script>
</body>
</html>