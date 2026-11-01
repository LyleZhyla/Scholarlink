<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScholarLink | TAU</title>
    <?php require_once __DIR__ . '/theme.php'; themeHead(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 5.5rem;
            --transition-speed: 0.38s;
            --transition-curve: cubic-bezier(0.22, 1, 0.36, 1);   /* quick start, soft landing */
        }

        body.sidebar-expanded {
            --sidebar-width: 18rem;
        }

        body { font-family: 'Plus Jakarta Sans', sans-serif; letter-spacing: -0.02em; overflow-x: hidden; }
        
        /* --- DESKTOP FRAMEWORK --- */
        aside#main-sidebar { 
            width: var(--sidebar-width); 
            transition: width var(--transition-speed) var(--transition-curve), transform var(--transition-speed) var(--transition-curve);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            padding: 0 !important;
        }
        
        main { 
            margin-left: var(--sidebar-width) !important; 
            width: calc(100vw - var(--sidebar-width));
        }
        /* Same speed and curve as the sidebar, so their edges move together. main[class] outranks the pages' own
           Tailwind "transition-all duration-300", which made the content lag behind the sidebar */
        main[class] { transition: margin-left var(--transition-speed) var(--transition-curve), width var(--transition-speed) var(--transition-curve); }

        /* First paint: the saved open/closed state is applied before anything shows, without animating */
        body.sb-preload aside#main-sidebar, body.sb-preload aside#main-sidebar *, body.sb-preload main[class], body.sb-preload #mobile-overlay { transition: none !important; }

        .nav-link {
            display: flex;
            align-items: center;
            height: 3.25rem;
            position: relative;
            margin: 0.25rem 0.5rem; 
            border-radius: 0.75rem;
            transition: background-color 0.3s ease, color 0.3s ease;
            overflow: hidden;
            padding: 0 !important;
        }

        .nav-link > .icon-box {
            width: 4.5rem; 
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.25rem;
            z-index: 10;
        }
        /* Icons lift a little on hover */
        .nav-link > span:first-child { transition: transform 0.25s var(--transition-curve); }
        .nav-link:hover > span:first-child { transform: scale(1.12); }

        /* Closing: every label fades out together, fast */
        .sidebar-text {
            position: absolute;
            left: 4.5rem; 
            opacity: 0;
            transform: translateX(-8px);
            transition: opacity 0.15s ease, transform 0.15s ease;
            white-space: nowrap;
            font-weight: 700;
        }

        /* Opening: labels arrive one after another, top to bottom */
        body.sidebar-expanded .sidebar-text {
            opacity: 1;
            transform: translateX(0);
            transition: opacity 0.3s ease calc(0.08s + var(--sb-i, 0) * 0.035s), transform 0.35s var(--transition-curve) calc(0.08s + var(--sb-i, 0) * 0.035s);
        }
        nav .nav-link:nth-child(1) { --sb-i: 0; } nav .nav-link:nth-child(2) { --sb-i: 1; } nav .nav-link:nth-child(3) { --sb-i: 2; }
        nav .nav-link:nth-child(4) { --sb-i: 3; } nav .nav-link:nth-child(5) { --sb-i: 4; } nav .nav-link:nth-child(6) { --sb-i: 5; }
        nav .nav-link:nth-child(7) { --sb-i: 6; } nav .nav-link:nth-child(8) { --sb-i: 7; } nav .nav-link:nth-child(9) { --sb-i: 8; }
        nav .nav-link:nth-child(n+10) { --sb-i: 9; }

        /* The menu button turns as the sidebar opens */
        #main-sidebar .logo-container button svg { transition: transform var(--transition-speed) var(--transition-curve); }
        body.sidebar-expanded #main-sidebar .logo-container button svg { transform: rotate(90deg); }

        .logo-container {
            width: 100%;
            height: 4rem;
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .hamburger-wrapper { width: 5.5rem; display: flex; justify-content: center; flex-shrink: 0; }
        .brand-text { position: absolute; left: 4.5rem; opacity: 0; transform: translateX(-8px); transition: opacity 0.15s ease, transform 0.15s ease; }
        body.sidebar-expanded .brand-text { opacity: 1; transform: none; transition: opacity 0.3s ease 0.05s, transform 0.35s var(--transition-curve) 0.05s; }

        /* --- MOBILE OVERLAY LOGIC --- */
        #mobile-overlay {
            transition: opacity var(--transition-speed) var(--transition-curve);
        }
        body.sidebar-expanded #mobile-overlay {
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        /* ==========================================
           MOBILE OPTIMIZATION (SLIDING DRAWER)
           ========================================== */
        @media (max-width: 768px) {
            :root { --sidebar-width: 0px; }
            
            main {
                width: 100vw !important;
                margin-left: 0 !important;
                padding-top: 5.5rem !important; /* Makes room for the new mobile top bar */
            }

            /* Turn sidebar into a hidden drawer */
            aside#main-sidebar {
                width: 18rem !important;
                transform: translateX(-100%);
                z-index: 9999;
            }

            /* Slide the drawer in when active, with a shadow over the page */
            body.sidebar-expanded aside#main-sidebar {
                transform: translateX(0);
                box-shadow: 12px 0 40px rgba(2, 6, 23, 0.35);
            }
            /* The page behind the open drawer stays still */
            body.sidebar-expanded { overflow: hidden; }

            /* Force text to always show inside the mobile drawer */
            .sidebar-text {
                position: static !important;
                opacity: 1 !important;
                transform: none !important;
            }
            .brand-text { opacity: 1 !important; }
            .nav-link { width: 100%; justify-content: flex-start; }
            .nav-link > .icon-box { width: 4.5rem; }
        }

        /* People who asked their system for less motion get none */
        @media (prefers-reduced-motion: reduce) {
            aside#main-sidebar, aside#main-sidebar *, main[class], #mobile-overlay { transition: none !important; }
        }
    </style>

    <script>
    window.addEventListener('pageshow', function (event) {
        // If the browser loaded this page from its history "screenshot"
        if (event.persisted) {
            // Force the browser to secretly refresh and ask the server for the real page
            window.location.reload(); 
        }
    });
</script>

</head>
<body class="bg-slate-50/50 flex min-h-screen sb-preload">
<script>
    // Apply the remembered open/closed sidebar now (not at the end of the page), so it doesn't slide on every page load
    try {
        if (window.innerWidth > 768 && localStorage.getItem('scholarlink_sidebar_expanded') === 'true') document.body.classList.add('sidebar-expanded');
    } catch (e) {}
    // Animations back on once the first frame has been drawn
    requestAnimationFrame(() => requestAnimationFrame(() => document.body.classList.remove('sb-preload')));
</script>