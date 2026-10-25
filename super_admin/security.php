<?php
session_start();

// ✨ 1. SESSION MANAGER (Absolute top, checks for timeouts immediately)
include '../includes/session_manager.php';

// 🛑 2. THE BACK BUTTON KILLER (Crucial for Super Admin security!)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 🔒 3. THE SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') {
    session_destroy();
    header("Location: ../admin_login.php"); 
    exit();
}
include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/super_sidebar.php'; 

// Fetch current settings from the database
$settings = [
    'require_2fa' => '0',
    'strict_password' => '1',
    'session_timeout' => '1800'
];

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // If table doesn't exist yet, it will just use the defaults above
}

$two_fa_enabled = ($settings['require_2fa'] === '1');
$strict_password_enabled = ($settings['strict_password'] === '1');
$session_timeout = $settings['session_timeout'];
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-10">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Security Settings</h2>
        <p class="text-slate-500 font-medium mt-1">Configure 2FA, password policies, and session timeouts</p>
    </header>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-xl mb-6 text-sm font-bold flex items-center gap-3">
            ✅ <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl mb-6 text-sm font-bold flex items-center gap-3">
            ⚠️ <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-8 lg:p-10 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm">
        <h3 class="font-black text-slate-900 text-lg uppercase tracking-tight mb-8">Authentication Policy</h3>
        
        <div class="space-y-6">
            
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
                <div>
                    <p class="font-bold text-slate-800 text-lg">Two-Factor Authentication (2FA)</p>
                    <p class="text-sm text-slate-500 font-medium">Require all Admin roles to use 2FA upon login.</p>
                </div>
                <form action="../actions/process_crud.php" method="POST" class="m-0 shrink-0">
                    <input type="hidden" name="module" value="security">
                    <input type="hidden" name="action" value="toggle_setting">
                    <input type="hidden" name="setting_key" value="require_2fa">
                    <input type="hidden" name="new_value" value="<?= $two_fa_enabled ? '0' : '1' ?>">
                    
                    <?php if ($two_fa_enabled): ?>
                        <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-full font-black text-[10px] uppercase tracking-widest shadow-md shadow-green-200 active:scale-95 transition-all">ON</button>
                    <?php else: ?>
                        <button type="submit" class="bg-slate-200 text-slate-500 px-6 py-2 rounded-full font-black text-[10px] uppercase tracking-widest active:scale-95 transition-all hover:bg-slate-300">OFF</button>
                    <?php endif; ?>
                </form>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
                <div>
                    <p class="font-bold text-slate-800 text-lg">Strict Password Requirements</p>
                    <p class="text-sm text-slate-500 font-medium">Globally enforce minimum 8 characters, at least 1 symbol, and 1 number.</p>
                </div>
                <form action="../actions/process_crud.php" method="POST" class="m-0 shrink-0">
                    <input type="hidden" name="module" value="security">
                    <input type="hidden" name="action" value="toggle_setting">
                    <input type="hidden" name="setting_key" value="strict_password">
                    <input type="hidden" name="new_value" value="<?= $strict_password_enabled ? '0' : '1' ?>">
                    
                    <?php if ($strict_password_enabled): ?>
                        <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-full font-black text-[10px] uppercase tracking-widest shadow-md shadow-green-200 active:scale-95 transition-all">ON</button>
                    <?php else: ?>
                        <button type="submit" class="bg-slate-200 text-slate-500 px-6 py-2 rounded-full font-black text-[10px] uppercase tracking-widest active:scale-95 transition-all hover:bg-slate-300">OFF</button>
                    <?php endif; ?>
                </form>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <p class="font-bold text-slate-800 text-lg">Session Timeout</p>
                    <p class="text-sm text-slate-500 font-medium">Automatically log out inactive users.</p>
                </div>
                <form action="../actions/process_crud.php" method="POST" class="m-0 w-full sm:w-auto">
                    <input type="hidden" name="module" value="security">
                    <input type="hidden" name="action" value="toggle_setting">
                    <input type="hidden" name="setting_key" value="session_timeout">
                    
                  <select name="new_value" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-sm text-slate-700 outline-none w-full sm:w-auto cursor-pointer focus:border-blue-500 transition-colors">
                    <option value="180" <?= $session_timeout == '180' ? 'selected' : '' ?>>3 Minutes</option>
                    <option value="300" <?= $session_timeout == '300' ? 'selected' : '' ?>>5 Minutes</option>
                    <option value="600" <?= $session_timeout == '600' ? 'selected' : '' ?>>10 Minutes (Maximum)</option>
                </select>
                </form>
            </div>
            
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>