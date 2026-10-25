<?php include 'sidebar_logic.php'; ?>

<div class="md:hidden fixed top-0 left-0 w-full h-16 bg-slate-900 text-white z-[9990] flex items-center justify-between px-5 shadow-lg">
    <div class="flex items-center gap-3">
        <button onclick="toggleSidebar()" class="p-2 -ml-2 bg-slate-800 rounded-lg text-slate-300 hover:text-white transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path></svg>
        </button>
        <h1 class="text-lg font-black tracking-tighter leading-none">ScholarLink</h1>
    </div>
   <a href="../actions/process_logout.php" onclick="sessionStorage.removeItem('scholarlink_chat_history');" class="text-red-400 bg-red-500/10 hover:bg-red-500/20 px-3 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors">
    Logout
</a>
</div>

<div id="mobile-overlay" onclick="toggleSidebar()" class="md:hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9995] opacity-0 pointer-events-none transition-opacity duration-300"></div>

<aside id="main-sidebar" class="fixed left-0 top-0 h-full bg-gradient-to-b from-green-950 to-slate-900 text-white py-6 shadow-2xl z-[9999]">
    
    <div class="logo-container flex items-center mb-6">
        <div class="hamburger-wrapper w-[5.5rem] flex justify-center shrink-0">
            <button onclick="toggleSidebar()" class="w-10 h-10 flex items-center justify-center rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition-all">
                <svg class="w-6 h-6 hidden md:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                <svg class="w-6 h-6 md:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="brand-text">
            <h1 class="text-xl font-black tracking-tighter leading-none">ScholarLink</h1>
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">Student Portal</p>
        </div>
    </div>

    <nav class="space-y-1 flex-1 overflow-y-auto overflow-x-hidden">
        <a href="dashboard.php" class="nav-link <?= getSidebarClass('dashboard.php') ?>">
            <span class="icon-box">📊</span>
            <span class="sidebar-text">Dashboard</span>
        </a>

        <!-- ✨ DITO NATIN IDINAGDAG ANG BROWSE GRANTS (LANDING PAGE LINK) -->
        <a href="../index.php" class="nav-link">
            <span class="icon-box">🌐</span>
            <span class="sidebar-text">Browse Grants</span>
        </a>

        <a href="programs.php" class="nav-link <?= getSidebarClass('programs.php') ?>">
            <span class="icon-box">🎓</span>
            <span class="sidebar-text">Programs</span>
        </a>
        <a href="applications.php" class="nav-link <?= getSidebarClass('applications.php') ?>">
            <span class="icon-box">📂</span>
            <span class="sidebar-text">Apps</span>
        </a>
        <a href="requirements.php" class="nav-link <?= getSidebarClass('requirements.php') ?>">
            <span class="icon-box">📜</span>
            <span class="sidebar-text">Requirements</span>
        </a>
        
        <a href="vault.php" class="nav-link <?= getSidebarClass('vault.php') ?>">
            <span class="icon-box">🔒</span>
            <span class="sidebar-text">Vault</span>
        </a>

        <a href="profile.php" class="nav-link <?= getSidebarClass('profile.php') ?>">
            <span class="icon-box">👤</span>
            <span class="sidebar-text">Profile</span>
        </a>
    </nav>

    <div class="pt-6 border-t border-slate-800">
        <a href="../actions/process_logout.php" class="nav-link text-red-400 hover:bg-red-500/10">
            <span class="icon-box">🚪</span>
            <span class="sidebar-text">Sign Out</span>
        </a>
    </div>
</aside>