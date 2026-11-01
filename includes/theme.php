<?php
// ✨ LIGHT / DARK MODE
// themeHead()   in <head>: applies the saved choice before the page is drawn (no white flash) and adds the
//               dark styles. Pages are built with Tailwind colour classes, so dark mode remaps those classes
//               (white cards, slate text/borders, the pale -50/-100 badges...) instead of editing every page.
// themeToggle() the switch: 'top' (portal pages, fixed top-right), 'corner' (login pages), 'pill' (landing page),
//               'sidebar' / 'floating' (older placements, still supported).
// The choice is kept per browser in localStorage ("scholarlink_theme"); light is the default.

if (!function_exists('themeHead')) {

function themeHead(): void {
    // Tailwind's colours for the tinted badges, as [600, 400, 300] on a dark background
    $tints = [
        'blue' => ['37,99,235', '#60a5fa', '#93c5fd'], 'sky' => ['2,132,199', '#38bdf8', '#7dd3fc'], 'indigo' => ['79,70,229', '#818cf8', '#a5b4fc'],
        'violet' => ['124,58,237', '#a78bfa', '#c4b5fd'], 'purple' => ['147,51,234', '#c084fc', '#d8b4fe'], 'pink' => ['219,39,119', '#f472b6', '#f9a8d4'],
        'rose' => ['225,29,72', '#fb7185', '#fda4af'], 'red' => ['220,38,38', '#f87171', '#fca5a5'], 'orange' => ['234,88,12', '#fb923c', '#fdba74'],
        'amber' => ['217,119,6', '#fbbf24', '#fcd34d'], 'yellow' => ['202,138,4', '#facc15', '#fde047'], 'lime' => ['101,163,13', '#a3e635', '#bef264'],
        'green' => ['22,163,74', '#4ade80', '#86efac'], 'emerald' => ['5,150,105', '#34d399', '#6ee7b7'], 'teal' => ['13,148,136', '#2dd4bf', '#5eead4'],
        'cyan' => ['8,145,178', '#22d3ee', '#67e8f9'],
    ];
    $tint_css = '';
    foreach ($tints as $c => [$rgb, $c400, $c300]) {
        $tint_css .= "html.dark .bg-$c-50, html.dark .bg-$c-50\\/50, html.dark .bg-$c-50\\/60, html.dark .hover\\:bg-$c-50:hover { background-color: rgba($rgb, .14) !important; }\n"
            . "html.dark .bg-$c-100, html.dark .hover\\:bg-$c-100:hover { background-color: rgba($rgb, .22) !important; }\n"
            . "html.dark .border-$c-100, html.dark .border-$c-200, html.dark .border-$c-300 { border-color: rgba($rgb, .38) !important; }\n"
            . "html.dark .text-$c-700, html.dark .text-$c-800, html.dark .text-$c-900 { color: $c300 !important; }\n"
            . "html.dark .text-$c-600 { color: $c400 !important; }\n";
    }
    // Every page that loads the theme also gets the CSRF token script (includes/security_settings.php)
    if (function_exists('csrfHead')) csrfHead();
    ?>
<script>
    // Before anything is drawn: the visitor's saved choice (light by default)
    (function () {
        try { if (localStorage.getItem('scholarlink_theme') === 'dark') document.documentElement.classList.add('dark'); } catch (e) { }
    })();
    function toggleTheme() {
        const dark = document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('scholarlink_theme', dark ? 'dark' : 'light'); } catch (e) { }
        document.querySelectorAll('[data-theme-label]').forEach(el => el.textContent = dark ? 'Light mode' : 'Dark mode');
        document.querySelectorAll('[data-theme-toggle]').forEach(el => el.setAttribute('aria-pressed', dark ? 'true' : 'false'));
    }
    document.addEventListener('DOMContentLoaded', () => {
        const dark = document.documentElement.classList.contains('dark');
        document.querySelectorAll('[data-theme-label]').forEach(el => el.textContent = dark ? 'Light mode' : 'Dark mode');
        document.querySelectorAll('[data-theme-toggle]').forEach(el => el.setAttribute('aria-pressed', dark ? 'true' : 'false'));
    });
</script>
<style>
    /* The switch's icon: moon in light mode, sun in dark mode */
    .theme-sun { display: none; } html.dark .theme-sun { display: inline; } html.dark .theme-moon { display: none; }
    .theme-floating { position: fixed; left: 16px; bottom: 16px; z-index: 9990; width: 44px; height: 44px; border-radius: 999px; display: flex; align-items: center; justify-content: center;
        background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 6px 20px rgba(15, 23, 42, .15); font-size: 1.15rem; cursor: pointer; transition: transform .2s ease; }
    .theme-floating:hover { transform: scale(1.08); }
    html.dark .theme-floating { background: #1e293b; border-color: #334155; }
    /* Round switch fixed to the top-right of every portal page; 'theme-top-inline' sits inside a bar instead */
    .theme-top { position: fixed; top: 12px; right: 16px; z-index: 9991; width: 40px; height: 40px; border-radius: 999px; display: flex; align-items: center; justify-content: center;
        background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 4px 14px rgba(15, 23, 42, .12); font-size: 1.05rem; cursor: pointer; transition: transform .2s ease, box-shadow .2s ease; }
    .theme-top:hover { transform: scale(1.08); box-shadow: 0 8px 20px rgba(15, 23, 42, .18); }
    .theme-top:focus-visible { outline: 3px solid #3b82f6; outline-offset: 2px; }
    html.dark .theme-top { background: #1e293b; border-color: #334155; }
    .theme-top.theme-top-inline { position: static; width: 36px; height: 36px; box-shadow: none; background: #1e293b; border-color: #334155; }
    @media (max-width: 767px) { .theme-top.theme-top-desktop { display: none; } }
    /* Labelled switch in the top-right corner (login pages) */
    .theme-corner { position: fixed; top: 16px; right: 16px; z-index: 9990; display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 999px;
        background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 6px 20px rgba(15, 23, 42, .12); color: #334155; font: 700 13px/1 'Inter', 'Plus Jakarta Sans', sans-serif; cursor: pointer; transition: transform .2s ease, box-shadow .2s ease; }
    .theme-corner:hover { transform: translateY(-1px); box-shadow: 0 10px 24px rgba(15, 23, 42, .18); }
    html.dark .theme-corner { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    @media (max-width: 640px) { body.theme-standalone { padding-top: 68px !important; } }   /* room for the switch above the card */

    /* ---------- Dark theme ---------- */
    html.dark { color-scheme: dark; }
    html.dark body { background-color: #0b1120; color: #e2e8f0; }

    /* Surfaces */
    html.dark .bg-white, html.dark .bg-white\/90, html.dark .bg-white\/95, html.dark .bg-white\/80 { background-color: #131c2e !important; }
    html.dark .bg-slate-50, html.dark .bg-slate-50\/50, html.dark .bg-slate-50\/30, html.dark .bg-slate-50\/60, html.dark .bg-gray-50 { background-color: #0f172a !important; }
    html.dark .bg-slate-100, html.dark .bg-gray-100 { background-color: #1e293b !important; }
    html.dark .bg-slate-200, html.dark .bg-gray-200 { background-color: #334155 !important; }
    html.dark .bg-slate-300 { background-color: #475569 !important; }
    html.dark .hover\:bg-slate-50:hover, html.dark .hover\:bg-slate-100:hover, html.dark .hover\:bg-gray-50:hover, html.dark .hover\:bg-white:hover { background-color: #1e293b !important; }
    html.dark .hover\:bg-slate-200:hover, html.dark .hover\:bg-slate-300:hover { background-color: #334155 !important; }

    /* Text */
    html.dark .text-slate-900, html.dark .text-slate-800, html.dark .text-black, html.dark .text-gray-900, html.dark .text-gray-800 { color: #f1f5f9 !important; }
    html.dark .text-slate-700, html.dark .text-gray-700 { color: #e2e8f0 !important; }
    html.dark .text-slate-600, html.dark .text-gray-600 { color: #cbd5e1 !important; }
    html.dark .text-slate-500, html.dark .text-gray-500 { color: #94a3b8 !important; }
    html.dark .text-slate-400, html.dark .text-gray-400 { color: #7c8aa0 !important; }
    html.dark .text-slate-300 { color: #5b6b82 !important; }
    html.dark .hover\:text-slate-900:hover, html.dark .hover\:text-slate-800:hover, html.dark .hover\:text-black:hover { color: #fff !important; }
    html.dark .placeholder\:text-slate-400::placeholder, html.dark ::placeholder { color: #64748b !important; }

    /* Borders and dividers */
    html.dark .border-slate-50, html.dark .border-slate-100, html.dark .border-gray-100 { border-color: #1f2a3c !important; }
    html.dark .border-slate-200, html.dark .border-slate-200\/80, html.dark .border-slate-200\/60, html.dark .border-gray-200 { border-color: #273449 !important; }
    html.dark .border-slate-300, html.dark .border-gray-300 { border-color: #334155 !important; }
    html.dark .divide-slate-100 > * + *, html.dark .divide-slate-200 > * + * { border-color: #1f2a3c !important; }
    html.dark .shadow-sm, html.dark .shadow, html.dark .shadow-md, html.dark .shadow-lg, html.dark .shadow-xl, html.dark .shadow-2xl { --tw-shadow-color: rgba(0, 0, 0, .45); }

    /* Dark buttons and chips become light so they still stand out (the sidebars stay as they are) */
    html.dark main .bg-slate-900.text-white, html.dark .fixed.inset-0 .bg-slate-900.text-white, html.dark .theme-standalone .bg-slate-900.text-white,
    html.dark main .bg-black.text-white, html.dark .bg-slate-950.text-white, html.dark .theme-standalone .bg-black.text-white { background-color: #e2e8f0 !important; color: #0f172a !important; }
    html.dark main .bg-slate-900.text-white:hover, html.dark .fixed.inset-0 .bg-slate-900.text-white:hover, html.dark .theme-standalone .bg-slate-900.text-white:hover,
    html.dark .bg-slate-950.text-white:hover { background-color: #2563eb !important; color: #fff !important; }
    html.dark aside.bg-slate-900 { background-color: #020617 !important; }

    /* Form controls without their own colours */
    html.dark input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]), html.dark select, html.dark textarea { color: #e2e8f0; }
    html.dark option { background: #131c2e; color: #e2e8f0; }

    /* Tables from simple-datatables (footer.php) */
    html.dark .dataTable-input, html.dark .dataTable-selector { background: #0f172a; border-color: #273449; color: #e2e8f0; }
    html.dark .dataTable-table > thead > tr > th, html.dark .dataTable-table > tbody > tr > td { border-color: #1f2a3c; }
    html.dark .dataTable-pagination a { color: #cbd5e1; }

    /* Tinted badges and alerts */
<?= $tint_css ?>
</style>
    <?php
}

// The switch. $style: 'sidebar' (matches the sidebar links), 'floating' (round button, bottom left), 'pill' (landing page menu)
// $icon_class: how that sidebar styles its icons, so the switch lines up with its other links
function themeToggle(string $style = 'floating', string $extra_class = '', string $icon_class = 'icon-box'): void {
    $icons = '<span class="theme-moon" aria-hidden="true">🌙</span><span class="theme-sun" aria-hidden="true">☀️</span>';
    if ($style === 'sidebar') {
        echo '<button type="button" onclick="toggleTheme()" data-theme-toggle aria-pressed="false" title="Switch between light and dark mode" class="' . htmlspecialchars($extra_class) . '">'
            . '<span class="' . htmlspecialchars($icon_class) . '">' . $icons . '</span><span class="sidebar-text" data-theme-label>Dark mode</span></button>';
    } elseif ($style === 'corner') {
        echo '<button type="button" onclick="toggleTheme()" data-theme-toggle aria-pressed="false" title="Switch between light and dark mode" class="theme-corner ' . htmlspecialchars($extra_class) . '">'
            . $icons . '<span data-theme-label>Dark mode</span></button>';
    } elseif ($style === 'top') {
        echo '<button type="button" onclick="toggleTheme()" data-theme-toggle aria-pressed="false" title="Switch between light and dark mode" aria-label="Switch between light and dark mode" class="theme-top ' . htmlspecialchars($extra_class) . '">' . $icons . '</button>';
    } elseif ($style === 'pill') {
        echo '<button type="button" onclick="toggleTheme()" data-theme-toggle aria-pressed="false" title="Switch between light and dark mode" class="theme-pill ' . htmlspecialchars($extra_class) . '">'
            . $icons . '<span data-theme-label>Dark mode</span></button>';
    } else {
        echo '<button type="button" onclick="toggleTheme()" data-theme-toggle aria-pressed="false" title="Switch between light and dark mode" aria-label="Switch between light and dark mode" class="theme-floating ' . htmlspecialchars($extra_class) . '">' . $icons . '</button>';
    }
}

}
