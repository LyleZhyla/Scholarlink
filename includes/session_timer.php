<?php
// ✨ INACTIVITY TIMER (included by footer.php on every signed-in page)
// Counts down the Super Admin's session timeout in the browser, warns a minute before, and signs the
// user out when it runs out, even if they never click again. Typing, clicking and scrolling count as
// activity and keep the server session alive too. Tabs share one timer, so working in one tab keeps the others open.
if (empty($_SESSION['logged_in']) || !isset($pdo)) return;
require_once __DIR__ . '/security_settings.php';
$st_timeout = sessionTimeoutSeconds($pdo);
$st_base = appBaseUrl();
?>
<div id="sessionWarn" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[20000] hidden items-center justify-center p-4">
    <div class="bg-white w-full max-w-sm rounded-[2rem] shadow-2xl p-8 text-center">
        <div class="w-16 h-16 mx-auto rounded-full bg-amber-50 text-3xl flex items-center justify-center mb-4">⏳</div>
        <h3 class="text-xl font-black text-slate-900">Still there?</h3>
        <p class="text-sm font-medium text-slate-500 mt-2">You've been inactive for a while. For your security, you'll be signed out in</p>
        <p id="sessionWarnCount" class="text-4xl font-black text-amber-600 my-4 tabular-nums">0:60</p>
        <div class="flex gap-2">
            <a href="<?= $st_base ?>/actions/process_logout.php<?= ($_SESSION['role'] ?? '') !== 'Student' ? '?type=admin' : '' ?>" class="flex-1 py-3 rounded-xl border border-slate-200 text-[11px] font-black uppercase tracking-widest text-slate-600 hover:bg-slate-50">Sign out</a>
            <button type="button" onclick="slStayActive()" class="flex-1 py-3 rounded-xl bg-slate-900 text-white text-[11px] font-black uppercase tracking-widest hover:bg-blue-600">Stay signed in</button>
        </div>
    </div>
</div>
<script>
(function () {
    const TIMEOUT = <?= (int) $st_timeout ?> * 1000;
    const WARN = Math.min(60000, TIMEOUT / 3);
    const KEEPALIVE_EVERY = Math.min(60000, TIMEOUT / 3);
    const BASE = <?= json_encode($st_base) ?>;
    const KEY = 'scholarlink_last_active';
    const warnBox = document.getElementById('sessionWarn');
    let lastPing = Date.now(), ended = false;

    const read = () => { try { return Number(localStorage.getItem(KEY)) || 0; } catch (e) { return 0; } };
    const write = t => { try { localStorage.setItem(KEY, String(t)); } catch (e) { } };
    let localActive = Date.now();
    write(localActive);
    const lastActive = () => Math.max(localActive, read());

    function keepAlive() {
        lastPing = Date.now();
        fetch(BASE + '/actions/session_keepalive.php', { method: 'POST', headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(r => r.json()).then(d => { if (d && d.session_expired) expire(); }).catch(() => { });
    }

    function activity() {
        if (ended || !warnBox.classList.contains('hidden')) return;   // while warned, only the button counts
        localActive = Date.now();
        write(localActive);
        if (Date.now() - lastPing > KEEPALIVE_EVERY) keepAlive();
    }
    window.slStayActive = function () {
        warnBox.classList.add('hidden'); warnBox.classList.remove('flex');
        localActive = Date.now(); write(localActive); keepAlive();
    };

    function expire() {
        if (ended) return;
        ended = true;
        sessionStorage.removeItem('scholarlink_chat_history');
        window.location.replace(BASE + '/actions/session_timeout.php');
    }

    ['mousedown', 'keydown', 'scroll', 'touchstart', 'mousemove', 'input'].forEach(ev =>
        document.addEventListener(ev, activity, { passive: true, capture: true }));

    setInterval(() => {
        if (ended) return;
        const left = TIMEOUT - (Date.now() - lastActive());
        if (left <= 0) return expire();
        if (left <= WARN) {
            const s = Math.ceil(left / 1000);
            document.getElementById('sessionWarnCount').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
            warnBox.classList.remove('hidden'); warnBox.classList.add('flex');
        } else if (!warnBox.classList.contains('hidden')) {
            warnBox.classList.add('hidden'); warnBox.classList.remove('flex');   // another tab was used
        }
    }, 1000);
})();
</script>
