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

    // A dropdown group in the sidebar menu: sidebarGroup('Title'), its links, then sidebarGroupEnd().
    // While the sidebar is collapsed to icons the title is a thin line and all icons stay visible.
    function sidebarGroup($label) {
        $id = 'nav-group-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
        echo '<button type="button" class="nav-group" data-group="' . htmlspecialchars($label) . '" aria-expanded="true" aria-controls="' . $id . '" onclick="toggleNavGroup(this)">'
           . '<span>' . htmlspecialchars($label) . '</span>'
           . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>'
           . '</button><div class="nav-group-items" id="' . $id . '"><div class="nav-group-inner">';
    }

    function sidebarGroupEnd() {
        echo '</div></div>';
    }

    // Goes right after </aside>, so the menu is sized correctly before anything is drawn.
    // Each group: the one holding the current page is open; otherwise the visitor's last choice;
    // otherwise open if the whole menu fits on the screen, closed if it doesn't.
    function sidebarMenuScript() { ?>
<script>
    (function () {
        const KEY = 'scholarlink_nav_groups';
        const nav = document.querySelector('#main-sidebar > nav');
        if (!nav) return;
        let saved = {};
        try { saved = JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) {}
        const setGroup = (btn, open) => {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.getElementById(btn.getAttribute('aria-controls')).classList.toggle('is-closed', !open);
        };
        const fits = nav.scrollHeight <= nav.clientHeight;
        const here = location.pathname.split('/').pop();
        nav.querySelectorAll('.nav-group').forEach(btn => {
            const items = document.getElementById(btn.getAttribute('aria-controls'));
            const holdsCurrentPage = !!items.querySelector('a[href="' + here + '"]');
            const choice = saved[btn.dataset.group];
            setGroup(btn, holdsCurrentPage || (choice === undefined ? fits : choice));
        });
        window.toggleNavGroup = function (btn) {
            const open = btn.getAttribute('aria-expanded') !== 'true';
            setGroup(btn, open);
            saved[btn.dataset.group] = open;
            try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {}
        };
    })();
</script>
<?php }

}
?>