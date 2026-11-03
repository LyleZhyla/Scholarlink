<?php
// Check if the function already exists before trying to declare it
if (!function_exists('getSidebarClass')) {
    
    function getSidebarClass($pageName) {
        // Get the current script name (e.g., 'dashboard.php')
        $currentPage = basename($_SERVER['PHP_SELF']);
        
        // If the current page matches the link, return the active classes
        if ($currentPage === $pageName) {
            return 'bg-blue-600/10 text-blue-500 border-r-4 border-blue-600';
        }
        
        // Otherwise, return the inactive classes
        return 'text-slate-400 hover:bg-slate-800 hover:text-white';
    }

    // A group title in the sidebar menu: words when the sidebar is open, a thin line when it is collapsed to icons
    function sidebarGroup($label) {
        echo '<p class="nav-group"><span>' . htmlspecialchars($label) . '</span></p>';
    }

}
?>