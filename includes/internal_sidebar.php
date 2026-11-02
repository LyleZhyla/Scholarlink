<?php require_once __DIR__ . '/theme.php'; ?>
<?php include 'sidebar_logic.php'; ?>
<aside id="main-sidebar" class="fixed left-0 top-0 h-full w-72 bg-slate-900 text-white py-6 z-50 flex flex-col border-r border-slate-800 shadow-2xl transition-all duration-300 overflow-hidden">
    
    <div class="mb-10 flex items-center gap-3 px-5 logo-container whitespace-nowrap">
        <button onclick="toggleSidebar()" class="hamburger-btn w-10 h-10 flex items-center justify-center rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-all shrink-0 shadow-sm">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path></svg>
        </button>
        <div class="brand-text">
            <h1 class="text-xl font-black tracking-tighter leading-none">ScholarLink</h1>
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">Internal Admin</p>
        </div>
    </div>

    <nav class="space-y-2 flex-1 px-3">
        <a href="dashboard.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm <?= getSidebarClass('dashboard.php') ?> whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">🏠</span>
            <span class="sidebar-text">Dashboard</span>
        </a>
        
        <a href="programs.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm <?= getSidebarClass('programs.php') ?> whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">🎓</span>
            <span class="sidebar-text">Programs</span>
        </a>
        
        <a href="manage_applications.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm <?= getSidebarClass('manage_applications.php') ?> whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">📋</span>
            <span class="sidebar-text">Masterlist</span>
        </a>

        <a href="workflow_tasks.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm <?= getSidebarClass('workflow_tasks.php') ?> whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">🔀</span><span class="sidebar-text">Workflow Tasks</span>
        </a>

        <a href="appeals.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm <?= getSidebarClass('appeals.php') ?> whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">⚖️</span>
            <span class="sidebar-text">Appeals</span>
        </a>

        <a href="waitlist.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm <?= getSidebarClass('waitlist.php') ?> whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">📋</span><span class="sidebar-text">Waitlist</span>
        </a>

        <a href="benefits.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm <?= getSidebarClass('benefits.php') ?> whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">💳</span><span class="sidebar-text">Benefits</span>
        </a>

        <!-- ✨ ITO ANG BAGONG RENEWALS BUTTON 
        <a href="manage_renewals.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm <?= getSidebarClass('manage_renewals.php') ?> whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">🔄</span>
            <span class="sidebar-text">Renewals</span>
        </a>
            -->
        
        <a href="messages.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm <?= getSidebarClass('messages.php') ?> whitespace-nowrap">
            <span class="w-10 flex justify-center text-xl shrink-0">💬</span>
            <span class="sidebar-text">Messages</span>
        </a>
    </nav>

    <div class="pt-6 border-t border-slate-800 px-3">
        <a href="../actions/process_logout.php" class="nav-link flex items-center gap-3 px-2 py-3 rounded-xl font-bold text-sm text-red-400 hover:bg-red-500/10 whitespace-nowrap transition-all">
            <span class="w-10 flex justify-center text-xl shrink-0">🚪</span>
            <span class="sidebar-text">Sign Out</span>
        </a>
    </div>
</aside>
<?php themeToggle('top'); // light/dark switch, top-right of every page ?>
