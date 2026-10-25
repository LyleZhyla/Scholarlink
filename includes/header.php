<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScholarLink | TAU</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 5.5rem;
            --transition-speed: 0.4s;
            --transition-curve: cubic-bezier(0.4, 0, 0.2, 1);
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
            transition: margin-left var(--transition-speed) var(--transition-curve);
            width: calc(100vw - var(--sidebar-width));
        }

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

        .sidebar-text {
            position: absolute;
            left: 4.5rem; 
            opacity: 0;
            transform: translateX(-10px);
            transition: opacity 0.3s ease, transform 0.3s ease;
            white-space: nowrap;
            font-weight: 700;
        }

        body.sidebar-expanded .sidebar-text {
            opacity: 1;
            transform: translateX(0);
        }

        .logo-container {
            width: 100%;
            height: 4rem;
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .hamburger-wrapper { width: 5.5rem; display: flex; justify-content: center; flex-shrink: 0; }
        .brand-text { position: absolute; left: 4.5rem; opacity: 0; transition: opacity 0.3s ease; }
        body.sidebar-expanded .brand-text { opacity: 1; }

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

            /* Slide the drawer in when active */
            body.sidebar-expanded aside#main-sidebar {
                transform: translateX(0);
            }

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
<body class="bg-slate-50/50 flex min-h-screen">