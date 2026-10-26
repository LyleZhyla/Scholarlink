<?php
session_start();

// ✨ 1. SESSION MANAGER
include '../includes/session_manager.php';

// 🛑 2. THE BACK BUTTON KILLER
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
include '../includes/database_backup.php';
include '../includes/header.php'; 
include '../includes/super_sidebar.php'; 

if (empty($_SESSION['database_csrf'])) {
    $_SESSION['database_csrf'] = bin2hex(random_bytes(32));
}
$database_csrf = $_SESSION['database_csrf'];
$backup_files = glob(scholarlinkBackupDirectory() . DIRECTORY_SEPARATOR . '*.sql') ?: [];
usort($backup_files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
$backup_files = array_slice($backup_files, 0, 10);
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-10">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Data Management</h2>
        <p class="text-slate-500 font-medium mt-1">Enforce system backups, restores, and data resets.</p>
    </header>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-6 py-4 rounded-xl mb-8 font-bold text-sm flex items-center gap-3 shadow-sm">
            ✅ <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-6 py-4 rounded-xl mb-8 font-bold text-sm flex items-center gap-3 shadow-sm">
            ⚠️ <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8">
        
        <div class="bg-white p-8 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm text-center flex flex-col justify-between">
            <div>
                <div class="text-4xl lg:text-5xl mb-6">💾</div>
                <h3 class="text-xl font-black text-slate-900 mb-2">Backup Database</h3>
                <p class="text-slate-500 mb-8 font-medium text-sm">Download a full SQL backup of the current system data.</p>
            </div>
            <form action="../actions/process_database.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($database_csrf) ?>">
                <input type="hidden" name="action" value="export">
                <button type="submit" class="block w-full bg-slate-900 text-white py-4 rounded-xl font-black hover:bg-green-600 transition-all shadow-xl shadow-slate-200 text-xs uppercase tracking-widest text-center">
                    Create &amp; Download .SQL
                </button>
            </form>
        </div>

        <div class="bg-white p-8 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm text-center flex flex-col justify-between border-t-4 border-t-blue-500">
            <div>
                <div class="text-4xl lg:text-5xl mb-6">🔄</div>
                <h3 class="text-xl font-black text-slate-900 mb-2">Restore Database</h3>
                <p class="text-slate-500 mb-6 font-medium text-sm">Upload a previous .SQL backup to restore the system.</p>
            </div>
            <form action="../actions/process_database.php" method="POST" enctype="multipart/form-data" class="flex flex-col gap-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($database_csrf) ?>">
                <input type="hidden" name="action" value="restore">
                <input type="file" name="backup_file" accept=".sql" required class="w-full text-xs font-bold text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:font-black file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer border border-slate-200 rounded-xl p-2 outline-none">
                <button type="submit" class="block w-full bg-blue-600 text-white py-4 rounded-xl font-black hover:bg-blue-700 transition-all shadow-lg shadow-blue-600/20 text-xs uppercase tracking-widest text-center" onclick="return confirm('WARNING: This will overwrite current data with the uploaded file. Proceed?');">
                    Restore Data
                </button>
            </form>
        </div>

        <div class="bg-white p-8 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm text-center flex flex-col justify-between border-t-4 border-t-red-500">
            <div>
                <div class="text-4xl lg:text-5xl mb-6">⚠️</div>
                <h3 class="text-xl font-black text-slate-900 mb-2">Wipe & Reset</h3>
                <p class="text-slate-500 mb-8 font-medium text-sm">Clear applications and logs. This action is irreversible.</p>
            </div>
            <form action="../actions/process_database.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($database_csrf) ?>">
                <input type="hidden" name="action" value="reset">
                <button type="submit"
                        onclick="return confirm('WARNING: A safety backup will be created, then ALL applications and audit logs will be deleted. Are you absolutely sure?');"
                        class="block w-full bg-red-50 text-red-600 py-4 rounded-xl font-black hover:bg-red-600 hover:text-white transition-all border border-red-200 text-xs uppercase tracking-widest text-center">
                    Backup &amp; Reset System
                </button>
            </form>
        </div>

    </div>

    <section class="mt-8 bg-white rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 lg:px-8 py-6 border-b border-slate-100">
            <h3 class="text-lg font-black text-slate-900">Recent Local Backups</h3>
            <p class="text-sm text-slate-500 mt-1">Backups are stored outside the public web folder. Automatic backups are retained for seven days; manual and safety backups remain until removed by an administrator.</p>
        </div>
        <?php if (!$backup_files): ?>
            <p class="px-6 lg:px-8 py-8 text-sm text-slate-500">No local backups have been created yet.</p>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($backup_files as $backup_file): ?>
                    <div class="px-6 lg:px-8 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars(basename($backup_file)) ?></p>
                            <p class="text-xs text-slate-500"><?= htmlspecialchars(date('M j, Y g:i A', filemtime($backup_file))) ?></p>
                        </div>
                        <span class="text-xs font-black text-slate-500 bg-slate-100 px-3 py-1.5 rounded-full">
                            <?= number_format(filesize($backup_file) / 1024, 1) ?> KB
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php include '../includes/footer.php'; ?>
