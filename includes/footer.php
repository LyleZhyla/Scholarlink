<script>
    // The remembered open/closed state is applied at the top of the page (includes/header.php).
    // While the sidebar is collapsed, each icon shows its name on hover; the menu buttons report the state to screen readers.
    function syncSidebarHints() {
        const open = document.body.classList.contains('sidebar-expanded');
        document.querySelectorAll('#main-sidebar .nav-link').forEach(link => {
            const label = link.querySelector('.sidebar-text');
            if (label) link.title = open ? '' : label.textContent.trim();
        });
        document.querySelectorAll('[onclick^="toggleSidebar"]').forEach(btn => {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.setAttribute('aria-label', open ? 'Collapse menu' : 'Expand menu');
        });
    }
    syncSidebarHints();

    // The single toggle function
    function toggleSidebar() {
        const isNowExpanded = document.body.classList.toggle('sidebar-expanded');

        // Only save the state to memory if they are on Desktop.
        // We want mobile to always default to closed on a new page load!
        if (window.innerWidth > 768) {
            try { localStorage.setItem('scholarlink_sidebar_expanded', isNowExpanded); } catch (e) {}
        }
        syncSidebarHints();
    }
</script>

<?php
// ✨ LEFTOVER MESSAGES: some pages never print $_SESSION['success'] / ['error'] (e.g. the result of scoring or verifying),
// so whatever the page didn't show appears here as a notice that hides itself. Messages may contain <b> (see process_crud).
$flash = array_filter(['success' => $_SESSION['success'] ?? null, 'error' => $_SESSION['error'] ?? null]);
unset($_SESSION['success'], $_SESSION['error']);
if ($flash): ?>
<div id="flashStack" class="fixed top-4 left-1/2 -translate-x-1/2 z-[100030] flex flex-col gap-2 w-[min(560px,calc(100vw-32px))]" role="status" aria-live="polite">
    <?php foreach ($flash as $kind => $text): ?>
        <div class="flash-item flex items-start gap-3 p-4 rounded-2xl border shadow-xl text-sm font-bold transition-opacity duration-500 <?= $kind === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-700' ?>">
            <span class="shrink-0"><?= $kind === 'success' ? '✅' : '⚠️' ?></span>
            <span class="flex-1 leading-relaxed"><?= str_replace(['&lt;b&gt;', '&lt;/b&gt;', '&lt;br&gt;'], ['<b>', '</b>', '<br>'], htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false)) ?></span>
            <button type="button" onclick="this.parentElement.remove()" class="shrink-0 opacity-60 hover:opacity-100" aria-label="Dismiss">&times;</button>
        </div>
    <?php endforeach; ?>
</div>
<script>
    // Success notices fade after a few seconds; errors stay until dismissed
    setTimeout(() => document.querySelectorAll('#flashStack .bg-green-50').forEach(el => { el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }), 6000);
</script>
<?php endif; ?>

<?php include __DIR__ . '/chatbot.php'; ?>
<?php include __DIR__ . '/session_timer.php'; ?>

<!-- Pinned: "@latest" let a new release change every admin table without warning -->
<link href="https://cdn.jsdelivr.net/npm/simple-datatables@10.3.0/dist/style.css" rel="stylesheet" type="text/css">
<script src="https://cdn.jsdelivr.net/npm/simple-datatables@10.3.0" type="text/javascript"></script>
<style>
    .dataTable-wrapper { font-family: 'Inter', sans-serif; }
    .dataTable-input { border-radius: 0.75rem; border: 1px solid #e2e8f0; padding: 0.5rem 1rem; font-size: 0.875rem; outline: none; }
    .dataTable-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.1); }
    .dataTable-selector { border-radius: 0.5rem; border: 1px solid #e2e8f0; padding: 0.25rem 2rem 0.25rem 0.5rem; font-weight: bold; font-size: 0.875rem; }
    .dataTable-pagination a { border-radius: 0.5rem; font-weight: bold; transition: all 0.2s; padding: 0.5rem 0.75rem; }
    .dataTable-pagination .active a { background-color: #16a34a !important; color: white !important; box-shadow: 0 4px 6px -1px rgba(22, 163, 74, 0.2); }
    .dataTable-info { color: #64748b; font-size: 0.875rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; }
    
    /* ✨ RESPONSIVE MOBILE FIXES */
    .dataTable-top, .dataTable-bottom { padding: 1rem 0; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .dataTable-pagination ul { display: flex; flex-wrap: wrap; gap: 0.25rem; justify-content: flex-end; margin: 0; padding: 0; list-style: none; }
    
    @media (max-width: 768px) {
        .dataTable-top, .dataTable-bottom { flex-direction: column; text-align: center; justify-content: center; }
        .dataTable-search { width: 100%; }
        .dataTable-input { width: 100%; text-align: center; }
        .dataTable-pagination ul { justify-content: center; }
        .dataTable-pagination li:not(.active):not(:first-child):not(:last-child) { display: none; }
        .dataTable-info { text-align: center; width: 100%; }
    }
</style>

<script>
    // ✨ THE CORRECT ADMIN TABLE SCRIPT
    document.addEventListener('DOMContentLoaded', function() {
        const tables = document.querySelectorAll('table');
        
        tables.forEach(table => {
            if(!table.classList.contains('no-datatable')) {
                new simpleDatatables.DataTable(table, {
                    perPage: 5, 
                    perPageSelect: [5, 10, 25, 50],
                    searchable: true,
                    truncatePager: true, // ✨ ITO ANG MAGTATAGO SA MAHABANG NUMBERS SA ADMIN TABLES
                    labels: {
                        placeholder: "Search records...",
                        perPage: "entries per page",
                        noRows: "No entries found",
                        info: "Showing {start} to {end} of {rows}",
                    }
                });
            }
        });
    });
</script>

<!-- ✨ SCHOLARLINK SMART AUTO-BACKUP TRIGGER -->
<script>
    // Mag-aantay ng 5 seconds para makapag-load muna ang buong page nang mabilis
    // bago niya tawagin ang backup script sa background.
    setTimeout(() => {
        // TAWAGIN ANG BACKUP SCRIPT NANG TAHIMIK (ASYNCHRONOUS)
        // Note: Make sure the path '/scholarlink/' matches your actual local folder name.
        fetch('/scholarlink/actions/auto_backup.php')
            .catch(error => { 
                // Ignore errors visually so the user never gets interrupted
                console.log("Background service check complete."); 
            });
    }, 5000); 
</script>
</body>
</html>