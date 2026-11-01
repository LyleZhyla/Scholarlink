<?php require_once __DIR__ . '/theme.php'; ?>
<?php include 'sidebar_logic.php'; ?>

<aside id="main-sidebar" class="fixed left-0 top-0 h-full bg-slate-900 text-white py-6 z-[999] flex flex-col border-r border-slate-800 shadow-2xl transition-all duration-300 overflow-hidden">
    
    <div class="logo-container whitespace-nowrap">
        <div class="hamburger-wrapper">
            <button type="button" onclick="toggleSidebar()" class="relative z-[1000] w-10 h-10 flex items-center justify-center rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-all cursor-pointer shadow-sm">
                <svg class="w-6 h-6 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
        </div>
        <div class="brand-text">
            <h1 class="text-xl font-black tracking-tighter leading-none">ScholarLink</h1>
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">Evaluator</p>
        </div>
    </div>

    <nav class="flex-1 mt-4">
        <?php
        $nav_items = [
            ['dashboard.php',           '📊', 'Overview'],
            ['scholarships.php',        '🎓', 'Scholarships'],
            ['manage_applications.php', '🗂️', 'Masterlist'],
        ];

        foreach ($nav_items as $item):
            $active = getSidebarClass($item[0]);
        ?>
            <a href="<?= $item[0] ?>" class="nav-link font-bold text-sm <?= $active ?> group">
                <div class="icon-box"><?= $item[1] ?></div>
                <span class="sidebar-text"><?= $item[2] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

   <div class="pt-6 border-t border-slate-800">
        <a href="../actions/process_logout.php" class="nav-link font-bold text-sm text-red-400 hover:bg-red-500/10 group">
            <div class="icon-box">🚪</div>
            <span class="sidebar-text">Sign Out</span>
        </a>
    </div>
</aside>
<?php themeToggle('top'); // light/dark switch, top-right of every page ?>