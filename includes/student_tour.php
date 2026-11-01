<?php
// ✨ FIRST-LOGIN GUIDED TOUR (student dashboard)
// Starts by itself the first time a student opens the dashboard (users.TourCompletedAt is NULL) and can be replayed
// any time with startStudentTour() ("Take the tour" button). Each step highlights a part of the page marked with
// data-tour="..."; a step whose target isn't on screen (e.g. the sidebar on a phone) is shown as a centred card.
// Finishing or skipping is saved by actions/tour_done.php.
// Needs: $pdo and $_SESSION['user_id'].

$tour_st = $pdo->prepare("SELECT TourCompletedAt FROM users WHERE UserID = ?");
$tour_st->execute([$_SESSION['user_id']]);
$tour_auto = $tour_st->fetchColumn() === null;
?>
<style>
    #tourLayer { position: fixed; inset: 0; z-index: 100020; display: none; }
    #tourLayer.on { display: block; }
    #tourBlocker { position: absolute; inset: 0; }
    #tourSpot { position: absolute; border-radius: 1.25rem; box-shadow: 0 0 0 9999px rgba(15, 23, 42, .62); outline: 3px solid #22c55e; outline-offset: 3px;
        transition: top .3s ease, left .3s ease, width .3s ease, height .3s ease; pointer-events: none; }
    #tourSpot.centered { top: 50% !important; left: 50% !important; width: 0 !important; height: 0 !important; outline: none; }
    #tourCard { position: absolute; width: min(360px, calc(100vw - 32px)); transition: top .3s ease, left .3s ease; }
    @media (prefers-reduced-motion: reduce) { #tourSpot, #tourCard { transition: none; } }
</style>

<div id="tourLayer" role="dialog" aria-modal="true" aria-labelledby="tourTitle">
    <div id="tourBlocker"></div>
    <div id="tourSpot"></div>
    <div id="tourCard" class="bg-white rounded-[1.5rem] shadow-2xl border border-slate-200 p-6">
        <div class="flex items-center justify-between mb-3">
            <span id="tourCount" class="text-[10px] font-black text-green-600 uppercase tracking-widest"></span>
            <button type="button" onclick="endStudentTour(false)" class="text-xs font-bold text-slate-400 hover:text-slate-700">Skip tour</button>
        </div>
        <div id="tourIcon" class="text-3xl mb-2"></div>
        <h3 id="tourTitle" class="text-lg font-black text-slate-900 leading-snug mb-2"></h3>
        <p id="tourText" class="text-sm text-slate-600 font-medium leading-relaxed" aria-live="polite"></p>
        <div class="flex items-center justify-between mt-5 gap-3">
            <div id="tourDots" class="flex gap-1.5 flex-wrap"></div>
            <div class="flex gap-2 shrink-0">
                <button type="button" id="tourBack" onclick="tourGo(-1)" class="px-4 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest bg-slate-100 text-slate-600 hover:bg-slate-200">Back</button>
                <button type="button" id="tourNext" onclick="tourGo(1)" class="px-4 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest bg-slate-900 text-white hover:bg-green-600">Next</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const STEPS = [
        { icon: '👋', title: 'Welcome to ScholarLink!', text: 'This quick tour shows you how to find scholarships, apply, and follow your applications. It takes less than a minute.' },
        { target: 'gwa', icon: '📊', title: 'Your GWA', text: 'This is the GWA read from your Report of Grades. Scholarships check it, along with your program and year level, to decide if you qualify.' },
        { target: 'find', icon: '🔎', title: 'Scholarships for you', text: 'This counts the programs you qualify for right now. Tap Browse Now to see them.' },
        { target: 'nav-programs', sidebar: true, icon: '🎓', title: 'Programs', text: 'Every scholarship you can apply for, with its slots, deadline and requirements. Open one and press Apply.' },
        { target: 'nav-apps', sidebar: true, icon: '📂', title: 'My Applications', text: 'Follow each application: Pending → Shortlisted → Approved. Approved scholars can open their approval notice here.' },
        { target: 'nav-requirements', sidebar: true, icon: '📜', title: 'Requirements', text: 'Upload the documents each scholarship asks for. Missing or rejected documents show up here so you can fix them.' },
        { target: 'nav-vault', sidebar: true, icon: '🔒', title: 'Document Vault', text: 'Keep documents you use often, like your Report of Grades, so you don\'t have to upload them again for every application.' },
        { target: 'nav-profile', sidebar: true, icon: '👤', title: 'Profile', text: 'Keep your details up to date. Scholarships match you using them, and providers see them when they review your application.' },
        { target: 'bell', icon: '🔔', title: 'Notifications', text: 'Status changes, deadline reminders and messages from the OSSD or providers appear here. We also email the important ones.' },
        { target: 'chatbot', icon: '🤖', title: 'Ask ISKO', text: 'Stuck? ISKO answers questions about scholarships and requirements, and can pass you to an OSSD staff member.' },
        { sel: '.theme-top', icon: '🌙', title: 'Light or dark', text: 'Switch between light and dark mode whenever you like.' },
        { icon: '🎉', title: 'You\'re all set!', text: 'Start with Browse Now to see the scholarships you qualify for. You can replay this tour any time with "Take the tour" at the top of your dashboard.' },
    ];

    const layer = document.getElementById('tourLayer'), spot = document.getElementById('tourSpot'), card = document.getElementById('tourCard');
    let index = 0, sidebarWas = null;

    const targetOf = step => {
        if (!step.target && !step.sel) return null;
        const el = Array.from(document.querySelectorAll(step.sel || '[data-tour="' + step.target + '"]')).find(e => {
            const r = e.getBoundingClientRect();
            return r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth && getComputedStyle(e).visibility !== 'hidden';
        });
        return el || null;
    };

    // Sidebar steps open the sidebar (on wide screens) so its labels show; it goes back to how it was afterwards
    function setSidebar(open) {
        if (window.innerWidth < 768) return;
        if (sidebarWas === null) sidebarWas = document.body.classList.contains('sidebar-expanded');
        document.body.classList.toggle('sidebar-expanded', open || sidebarWas);
    }

    function place() {
        const step = STEPS[index];
        const el = targetOf(step);
        const pad = 8, gap = 16, cw = card.offsetWidth, ch = card.offsetHeight, vw = window.innerWidth, vh = window.innerHeight;
        if (!el) {
            spot.classList.add('centered');
            card.style.left = Math.max(16, (vw - cw) / 2) + 'px';
            card.style.top = Math.max(16, (vh - ch) / 2) + 'px';
            return;
        }
        const r = el.getBoundingClientRect();
        spot.classList.remove('centered');
        Object.assign(spot.style, { top: (r.top - pad) + 'px', left: (r.left - pad) + 'px', width: (r.width + pad * 2) + 'px', height: (r.height + pad * 2) + 'px' });
        // Card beside the target where it fits: right, left, below, then above
        let left, top;
        if (r.right + gap + cw <= vw - 16) { left = r.right + gap; top = r.top; }
        else if (r.left - gap - cw >= 16) { left = r.left - gap - cw; top = r.top; }
        else if (r.bottom + gap + ch <= vh - 16) { left = r.left; top = r.bottom + gap; }
        else { left = r.left; top = r.top - gap - ch; }
        card.style.left = Math.min(Math.max(16, left), vw - cw - 16) + 'px';
        card.style.top = Math.min(Math.max(16, top), vh - ch - 16) + 'px';
    }

    function show() {
        const step = STEPS[index];
        document.getElementById('tourCount').textContent = 'Step ' + (index + 1) + ' of ' + STEPS.length;
        document.getElementById('tourIcon').textContent = step.icon;
        document.getElementById('tourTitle').textContent = step.title;
        document.getElementById('tourText').textContent = step.text;
        document.getElementById('tourBack').style.visibility = index === 0 ? 'hidden' : 'visible';
        document.getElementById('tourNext').textContent = index === 0 ? "Let's go" : (index === STEPS.length - 1 ? 'Finish' : 'Next');
        document.getElementById('tourDots').innerHTML = STEPS.map((_, i) =>
            '<span class="w-1.5 h-1.5 rounded-full ' + (i === index ? 'bg-green-600' : 'bg-slate-300') + '"></span>').join('');

        setSidebar(!!step.sidebar);
        const el = targetOf(step);
        if (el) el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        place();
        setTimeout(place, 350);   // after the scroll / sidebar animation
        document.getElementById('tourNext').focus({ preventScroll: true });
    }

    window.tourGo = function (dir) {
        if (index + dir >= STEPS.length) return endStudentTour(true);
        index = Math.max(0, index + dir);
        show();
    };

    window.startStudentTour = function () {
        index = 0;
        sidebarWas = null;
        layer.classList.add('on');
        document.body.style.overflow = 'hidden';
        show();
    };

    window.endStudentTour = function (finished) {
        layer.classList.remove('on');
        document.body.style.overflow = '';
        if (sidebarWas !== null) document.body.classList.toggle('sidebar-expanded', sidebarWas);
        const body = new URLSearchParams({ how: finished ? 'finished' : 'skipped', step: index + 1 });
        fetch('../actions/tour_done.php', { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});
    };

    // Keep the highlight on its target while the page scrolls to it or the window changes size
    const follow = () => { if (layer.classList.contains('on')) place(); };
    window.addEventListener('resize', follow);
    window.addEventListener('scroll', follow, { passive: true });
    window.addEventListener('scrollend', follow);
    document.addEventListener('keydown', e => {
        if (!layer.classList.contains('on')) return;
        if (e.key === 'Escape') endStudentTour(false);
        else if (e.key === 'ArrowRight') tourGo(1);
        else if (e.key === 'ArrowLeft') tourGo(-1);
    });

    <?php if ($tour_auto): ?>
    // First visit: start once the page has settled
    window.addEventListener('load', () => setTimeout(startStudentTour, 600));
    <?php endif; ?>
})();
</script>
