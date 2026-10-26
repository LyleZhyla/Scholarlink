<script>
    (function() {
        const isExpanded = localStorage.getItem('scholarlink_sidebar_expanded') === 'true';
        
        // Only auto-expand if we are on a Desktop screen
        if (isExpanded && window.innerWidth > 768) {
            document.body.classList.add('sidebar-expanded');
        } else {
            document.body.classList.remove('sidebar-expanded');
        }
    })();

    // 2. The Single Toggle Function
    function toggleSidebar() {
        const isNowExpanded = document.body.classList.toggle('sidebar-expanded');
        
        // Only save the state to memory if they are on Desktop. 
        // We want mobile to always default to closed on a new page load!
        if (window.innerWidth > 768) {
            localStorage.setItem('scholarlink_sidebar_expanded', isNowExpanded);
        }
    }
</script>

<?php include __DIR__ . '/chatbot.php'; ?>

<link href="https://cdn.jsdelivr.net/npm/simple-datatables@latest/dist/style.css" rel="stylesheet" type="text/css">
<script src="https://cdn.jsdelivr.net/npm/simple-datatables@latest" type="text/javascript"></script>
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
        fetch('/scholarlink/actions/auto_backup.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .catch(error => { 
                // Ignore errors visually so the user never gets interrupted
                console.log("Background service check complete."); 
            });
    }, 5000); 
</script>
</body>
</html>
