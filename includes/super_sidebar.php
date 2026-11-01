<?php require_once __DIR__ . '/theme.php'; ?>
<?php include 'sidebar_logic.php'; ?>
<aside id="main-sidebar" class="fixed left-0 top-0 h-full w-72 bg-slate-900 text-white py-6 z-[999] flex flex-col border-r border-slate-800 shadow-2xl transition-all duration-300 overflow-hidden">
    
    <div class="mb-10 flex items-center gap-3 px-5 logo-container whitespace-nowrap">
        <button onclick="toggleSidebar()" class="hamburger-btn w-10 h-10 flex items-center justify-center rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-all shrink-0 shadow-sm">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path></svg>
        </button>
        <div class="brand-text">
            <h1 class="text-xl font-black tracking-tighter leading-none">ScholarLink</h1>
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">Super Admin</p>
        </div>
    </div>

    <nav class="space-y-2 flex-1 px-3">
        <?php
        // Profile change requests waiting for approval (badge on the menu item)
        try { $profile_requests_waiting = (int) $pdo->query("SELECT COUNT(*) FROM profile_change_requests WHERE Status = 'Pending'")->fetchColumn(); }
        catch (Throwable $e) { $profile_requests_waiting = 0; }
        $nav_items = [
            ['dashboard.php',           '📊', 'Dashboard'],
            ['scholarships.php',        '🎓', 'Manage Scholarships'],
            ['manage_applications.php', '📂', 'Manage Applications'],
            ['users.php',               '👥', 'Users'],
            ['profile_requests.php',    '📝', 'Profile Requests'],
            ['logs.php',                '📋', 'Logs'],
            ['security.php',            '🛡️', 'Security'],
            ['database.php',            '🗄️', 'Database']
        ];

        foreach ($nav_items as $item):
            $active = getSidebarClass($item[0]);
        ?>
            <a href="<?= $item[0] ?>" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-md <?= $active ?> whitespace-nowrap group">
                <span class="w-10 flex justify-center text-xl shrink-0 group-hover:scale-110 transition-transform"><?= $item[1] ?></span>
                <span class="sidebar-text"><?= $item[2] ?></span>
                <?php if ($item[0] === 'profile_requests.php' && $profile_requests_waiting > 0): ?>
                    <span class="absolute left-9 top-2 min-w-[18px] h-[18px] px-1 rounded-full bg-amber-500 text-white text-[10px] font-black flex items-center justify-center" title="<?= $profile_requests_waiting ?> waiting"><?= $profile_requests_waiting ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="pt-6 border-t border-slate-800">
        <a href="../actions/process_logout.php" onclick="sessionStorage.removeItem('scholarlink_chat_history');" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-md text-red-400 hover:bg-red-500/10 transition-all whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">🚪</span>
            <span class="sidebar-text">Sign Out</span>
        </a>
    </div>
</aside>
<?php themeToggle('top'); // light/dark switch, top-right of every page ?>