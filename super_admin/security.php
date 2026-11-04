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
    
    header("Location: ../admin_login.php"); 
    exit();
}
include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/super_sidebar.php'; 

// Current settings (includes/security_settings.php enforces them across the system)
$settings = getSecuritySettings($pdo, true);
$two_fa_enabled = ($settings['require_2fa'] === '1');
$strict_password_enabled = ($settings['strict_password'] === '1');
$session_timeout = sessionTimeoutSeconds($pdo);
$absolute_timeout = absoluteSessionTimeoutSeconds($pdo);
$login_max = securitySettingInt($pdo,'login_max_attempts',5,3,20);
$login_lock_minutes = securitySettingInt($pdo,'login_lock_minutes',15,1,1440);
$recovery_max = securitySettingInt($pdo,'recovery_max_requests',3,1,10);
$locked_accounts = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE LockedUntil>NOW()')->fetchColumn();
$providers = $pdo->query("SELECT u.UserID,u.FirstName,u.LastName,u.Organization,u.ProviderAccessStatus,u.ProviderAccessUntil,u.ProviderAccessReason,(SELECT COUNT(*) FROM scholarship s WHERE s.CreatedBy=u.UserID AND s.Status='Active') ActiveScholarships FROM users u WHERE u.Role='External_Admin' ORDER BY u.Organization,u.LastName")->fetchAll(PDO::FETCH_ASSOC);
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-10">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Security Settings</h2>
        <p class="text-slate-500 font-medium mt-1">Enforce MFA, lockouts, session expiration, recovery protection, and provider access</p>
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
                    <p class="text-xs text-slate-400 font-bold mt-1"><?= $two_fa_enabled ? '✅ Admins enter a 6-digit code sent to their email after their password.' : 'Admins sign in with their password only.' ?></p>
                </div>
                <span class="bg-green-600 text-white px-6 py-2 rounded-full font-black text-[10px] uppercase tracking-widest shadow-md shadow-green-200">Required</span>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
                <div>
                    <p class="font-bold text-slate-800 text-lg">Strict Password Requirements</p>
                    <p class="text-sm text-slate-500 font-medium">Globally enforce minimum 8 characters, at least 1 symbol, and 1 number.</p>
                    <p class="text-xs text-slate-400 font-bold mt-1">New passwords (registration, password reset, new admin accounts): <?= htmlspecialchars(passwordPolicyText($pdo)) ?></p>
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
                    <p class="text-xs text-slate-400 font-bold mt-1">Everyone is signed out after <?= SESSION_TIMEOUT_OPTIONS[$session_timeout] ?> without activity, with a warning shortly before.</p>
                </div>
                <form action="../actions/process_crud.php" method="POST" class="m-0 w-full sm:w-auto">
                    <input type="hidden" name="module" value="security">
                    <input type="hidden" name="action" value="toggle_setting">
                    <input type="hidden" name="setting_key" value="session_timeout">
                    
                  <select name="new_value" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-sm text-slate-700 outline-none w-full sm:w-auto cursor-pointer focus:border-blue-500 transition-colors">
                    <?php foreach (SESSION_TIMEOUT_OPTIONS as $secs => $label): ?>
                        <option value="<?= $secs ?>" <?= $session_timeout === $secs ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                </form>
            </div>

            <div class="grid md:grid-cols-3 gap-4 pt-6 border-t border-slate-100">
                <div class="p-4 rounded-2xl bg-slate-50"><p class="text-xs font-black uppercase text-slate-400">Login lockout</p><p class="font-black mt-2"><?= $login_max ?> attempts · <?= $login_lock_minutes ?> minutes</p><p class="text-xs text-slate-500 mt-1"><?= $locked_accounts ?> account(s) currently locked</p></div>
                <div class="p-4 rounded-2xl bg-slate-50"><p class="text-xs font-black uppercase text-slate-400">Absolute session limit</p><p class="font-black mt-2"><?= (int)($absolute_timeout/3600) ?> hours</p><p class="text-xs text-slate-500 mt-1">Applies even while the account remains active.</p></div>
                <div class="p-4 rounded-2xl bg-slate-50"><p class="text-xs font-black uppercase text-slate-400">Recovery throttle</p><p class="font-black mt-2"><?= $recovery_max ?> requests · 30 minutes</p><p class="text-xs text-slate-500 mt-1">Links expire after one hour and are single-use.</p></div>
            </div>
            
        </div>
    </div>

    <div class="bg-white p-6 lg:p-8 rounded-[2rem] border border-slate-200 shadow-sm mt-8">
        <div class="mb-6"><h3 class="font-black text-slate-900 text-lg uppercase tracking-tight">Scholarship Provider Access</h3><p class="text-sm text-slate-500 mt-1">Suspend, revoke, restore, or time-limit provider access. Any change immediately invalidates open sessions.</p></div>
        <div class="space-y-4"><?php foreach($providers as $provider): $expired=$provider['ProviderAccessUntil']&&strtotime($provider['ProviderAccessUntil'].' 23:59:59')<time(); $shown=$expired&&$provider['ProviderAccessStatus']==='Active'?'Expired':$provider['ProviderAccessStatus']; ?>
            <form action="../actions/process_crud.php" method="post" class="p-5 border rounded-2xl grid lg:grid-cols-[1fr_160px_170px_1.5fr_auto] gap-3 items-end">
                <input type="hidden" name="module" value="security"><input type="hidden" name="action" value="provider_access"><input type="hidden" name="provider_id" value="<?= (int)$provider['UserID'] ?>">
                <div><p class="font-black"><?= htmlspecialchars($provider['Organization']?:$provider['FirstName'].' '.$provider['LastName']) ?></p><p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($provider['FirstName'].' '.$provider['LastName']) ?> · <?= (int)$provider['ActiveScholarships'] ?> active scholarship(s) · <b><?= htmlspecialchars($shown) ?></b></p></div>
                <label class="text-[10px] font-black uppercase text-slate-500">Access status<select name="provider_status" class="mt-1 w-full p-3 border rounded-xl"><?php foreach(['Active','Suspended','Revoked'] as $status):?><option <?= $provider['ProviderAccessStatus']===$status?'selected':'' ?>><?= $status ?></option><?php endforeach;?></select></label>
                <label class="text-[10px] font-black uppercase text-slate-500">Access until<input type="date" name="access_until" value="<?= htmlspecialchars($provider['ProviderAccessUntil']??'') ?>" class="mt-1 w-full p-3 border rounded-xl"></label>
                <label class="text-[10px] font-black uppercase text-slate-500">Review reason<input name="reason" minlength="10" required value="<?= htmlspecialchars($provider['ProviderAccessReason']??'') ?>" placeholder="MOA expiry, inactivity, suspension, or restoration basis" class="mt-1 w-full p-3 border rounded-xl"></label>
                <button class="px-4 py-3 bg-slate-900 text-white rounded-xl text-xs font-black uppercase">Update Access</button>
            </form>
        <?php endforeach;?></div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
