<?php
session_start();

// ✨ Session manager loaded before any HTML output
include '../includes/session_manager.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- THE SECURITY GATEKEEPER (FIXED UNDERSCORE) ---
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Internal_Admin') {
    header("Location: ../admin_login.php");
    exit();
}

include '../includes/db_connect.php';
require_once '../includes/chat_threads.php';

$admin_id = (int) $_SESSION['user_id'];

// Every student (with what identifies them) plus, for those who have written, their conversation summary
try {
    $students = $pdo->query("
        SELECT u.UserID, u.FirstName, u.LastName, u.StudentID_Num, u.YearLevel, p.ProgramName
        FROM users u
        LEFT JOIN program p ON p.ProgramID = u.ProgramID
        WHERE u.Role = 'Student' AND COALESCE(u.AccountStatus, 'Active') <> 'Archived'
        ORDER BY u.FirstName, u.LastName
    ")->fetchAll(PDO::FETCH_ASSOC);
    $threads = adminChatThreads($pdo, $admin_id);
} catch (PDOException $e) {
    die("Error fetching students: " . $e->getMessage());
}

$roster = array_map(fn($s) => [
    'id'      => (int) $s['UserID'],
    'name'    => trim($s['FirstName'] . ' ' . $s['LastName']),
    'number'  => (string) ($s['StudentID_Num'] ?? ''),
    'program' => (string) ($s['ProgramName'] ?? ''),
    'year'    => (string) ($s['YearLevel'] ?? ''),
], $students);
$programs = array_values(array_unique(array_filter(array_column($roster, 'program'))));
sort($programs);
$years = array_values(array_unique(array_filter(array_column($roster, 'year'))));
sort($years);

include '../includes/header.php';
include '../includes/internal_sidebar.php';

$quick_replies = [
    'Hello! How can I help you today?',
    'Please upload the missing documents on your Requirements page.',
    'Your application is being reviewed. We will notify you once there is an update.',
    'You may visit the OSSD office during office hours for assistance.',
];
?>

<style>
    /* Phones: the list and the open conversation take turns on the screen */
    @media (max-width: 767px) {
        #inbox.chat-open #threadPane { display: none; }
        #inbox:not(.chat-open) #chatPane { display: none; }
    }
    #chatInput { max-height: 140px; }
    /* The inbox ends above the floating ISKO button, which used to cover the Send button */
    #inbox { height: calc(100vh - 16rem); min-height: 480px; margin-bottom: 5.5rem; }
    @media (max-width: 767px) { #inbox { height: calc(100vh - 19rem); } }
    /* Rows that scroll sideways without a scrollbar */
    .no-scrollbar { scrollbar-width: none; } .no-scrollbar::-webkit-scrollbar { display: none; }
</style>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300 flex flex-col">
    <header class="mb-6 lg:mb-8 shrink-0">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Student Support Inbox</h2>
        <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Reply to students' questions, including conversations ISKO hands over to the OSSD.</p>
    </header>

    <div id="inbox" class="bg-white border border-slate-200 rounded-[2rem] shadow-sm flex overflow-hidden">

        <!-- ===== CONVERSATION LIST ===== -->
        <section id="threadPane" class="w-full md:w-[38%] lg:w-[34%] border-r border-slate-200 bg-slate-50/30 flex flex-col shrink-0 min-w-0">
            <div class="p-4 border-b border-slate-100 bg-white space-y-3">
                <input type="search" id="threadSearch" placeholder="Search name or student no..." autocomplete="off"
                    class="w-full bg-slate-100 border-none text-slate-900 rounded-xl px-4 py-3 text-sm font-medium outline-none focus:ring-2 focus:ring-blue-500 transition-all">

                <div id="threadTabs" class="no-scrollbar flex gap-1.5 overflow-x-auto" role="tablist" aria-label="Filter conversations"></div>

                <div class="grid grid-cols-2 gap-2">
                    <select id="programFilter" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-xs font-bold text-slate-700 outline-none cursor-pointer min-w-0" aria-label="Program">
                        <option value="">All programs</option>
                        <?php foreach ($programs as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                    </select>
                    <select id="yearFilter" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-xs font-bold text-slate-700 outline-none cursor-pointer" aria-label="Year level">
                        <option value="">All years</option>
                        <?php foreach ($years as $y): ?><option value="<?= htmlspecialchars($y) ?>"><?= htmlspecialchars($y) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="threadList" class="flex-1 overflow-y-auto p-2 space-y-1" role="listbox" aria-label="Conversations"></div>
            <p id="threadCount" class="px-4 py-2 border-t border-slate-100 text-[10px] font-black text-slate-400 uppercase tracking-widest bg-white"></p>
        </section>

        <!-- ===== OPEN CONVERSATION ===== -->
        <section id="chatPane" class="flex-1 flex flex-col bg-white relative min-w-0">
            <div id="emptyChatState" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-50 z-10 text-center px-6">
                <div class="text-5xl mb-3 grayscale opacity-30">💬</div>
                <h3 class="text-sm font-black text-slate-400 uppercase tracking-widest">Select a conversation</h3>
                <p class="text-xs font-medium text-slate-400 mt-2">Students who need a reply are under <b>Needs reply</b>.</p>
            </div>

            <div class="px-4 lg:px-6 py-4 border-b border-slate-100 flex items-center gap-3 bg-white shrink-0 shadow-sm">
                <button type="button" onclick="closeChat()" class="md:hidden w-9 h-9 rounded-xl bg-slate-100 text-slate-600 font-black shrink-0" aria-label="Back to conversations">←</button>
                <div id="activeAvatar" class="w-10 h-10 bg-blue-100 text-blue-700 rounded-full flex items-center justify-center font-black text-sm shrink-0"></div>
                <div class="min-w-0">
                    <h3 id="activeChatName" class="font-black text-slate-900 text-base lg:text-lg leading-tight truncate"></h3>
                    <p id="activeChatMeta" class="text-[11px] font-bold text-slate-400 truncate"></p>
                </div>
            </div>

            <div id="chatMessages" class="flex-1 p-4 lg:p-6 overflow-y-auto bg-slate-50/50 flex flex-col gap-1" aria-live="polite"></div>

            <div class="p-3 lg:p-4 bg-white border-t border-slate-100 shrink-0">
                <div class="no-scrollbar flex gap-1.5 overflow-x-auto mb-2">
                    <?php foreach ($quick_replies as $q): ?>
                        <button type="button" onclick="useQuickReply(this)" class="shrink-0 px-3 py-1.5 rounded-full border border-slate-200 bg-slate-50 hover:bg-blue-50 hover:border-blue-300 text-[11px] font-bold text-slate-600 whitespace-nowrap"><?= htmlspecialchars($q) ?></button>
                    <?php endforeach; ?>
                </div>
                <form id="adminChatForm" class="flex items-end gap-2 lg:gap-3">
                    <textarea id="chatInput" rows="1" maxlength="2000" placeholder="Write a reply…" autocomplete="off"
                        class="flex-1 resize-none overflow-y-hidden bg-slate-50 border border-slate-200 text-slate-900 rounded-xl px-4 py-3 text-sm font-medium outline-none focus:border-blue-500 focus:bg-white transition-all"></textarea>
                    <button type="submit" id="sendBtn" class="h-12 px-5 lg:px-6 bg-slate-900 hover:bg-blue-600 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl font-black text-[11px] uppercase tracking-widest transition-colors shadow-md active:scale-95 shrink-0" disabled>
                        Send
                    </button>
                </form>
                <p class="hidden md:block mt-1.5 text-[10px] font-bold text-slate-400">Enter to send · Shift+Enter for a new line</p>
                <p id="sendError" class="hidden mt-2 text-xs font-bold text-red-600"></p>
            </div>
        </section>
    </div>
</main>

<script>
    const API = '../actions/chat_handler.php';
    const ME = <?= $admin_id ?>;
    const ESCALATION = <?= json_encode(ISKO_ESCALATION_MARK) ?>;
    const ROSTER = <?= json_encode($roster, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    let THREADS = <?= json_encode((object) $threads, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const TABS = [
        { key: 'conversations', label: 'Conversations', test: s => !!THREADS[s.id] },
        { key: 'awaiting',      label: 'Needs reply',   test: s => THREADS[s.id] && !THREADS[s.id].last_is_me },
        { key: 'unread',        label: 'Unread',        test: s => THREADS[s.id] && THREADS[s.id].unread > 0 },
        { key: 'escalated',     label: 'From ISKO',     test: s => THREADS[s.id] && THREADS[s.id].escalated },
        { key: 'all',           label: 'All students',  test: () => true },
    ];
    let activeTab = Object.keys(THREADS).length ? 'conversations' : 'all';
    let currentId = null, lastRenderedKey = '';

    const $ = id => document.getElementById(id);
    const initials = name => name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
    const parseTime = t => t ? new Date(t.replace(' ', 'T')) : null;
    function ago(t) {
        const d = parseTime(t); if (!d) return '';
        const s = (Date.now() - d) / 1000;
        if (s < 60) return 'now';
        if (s < 3600) return Math.floor(s / 60) + 'm';
        if (s < 86400) return Math.floor(s / 3600) + 'h';
        if (s < 7 * 86400) return Math.floor(s / 86400) + 'd';
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
    // "⚠️ ISKO Auto-Escalate: question" → "question" (whatever symbol comes first)
    const stripEscalation = text => text.replace(new RegExp('^\\S*\\s*' + ESCALATION + ':\\s*'), '');
    const metaLine = s => [s.number, s.program || 'Program not set', s.year].filter(Boolean).join(' · ');

    // ---------- conversation list ----------
    function filtered(ignoreTab = false) {
        const q = $('threadSearch').value.trim().toLowerCase();
        const prog = $('programFilter').value, year = $('yearFilter').value;
        const tab = TABS.find(t => t.key === activeTab);
        return ROSTER.filter(s =>
            (ignoreTab || tab.test(s)) &&
            (!q || s.name.toLowerCase().includes(q) || s.number.includes(q)) &&
            (!prog || s.program === prog) && (!year || s.year === year));
    }

    function renderTabs() {
        const base = filtered(true);
        $('threadTabs').innerHTML = '';
        TABS.forEach(t => {
            const n = base.filter(t.test).length;
            const b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'tab');
            b.setAttribute('aria-selected', t.key === activeTab);
            b.className = 'shrink-0 px-3 py-1.5 rounded-lg text-[11px] font-black whitespace-nowrap transition-colors ' +
                (t.key === activeTab ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200');
            b.textContent = `${t.label} (${n})`;
            b.onclick = () => { activeTab = t.key; renderList(); };
            $('threadTabs').appendChild(b);
        });
    }

    function renderList() {
        renderTabs();
        const list = filtered().sort((a, b) => {
            const ta = THREADS[a.id], tb = THREADS[b.id];
            if (ta && tb) return (tb.unread > 0) - (ta.unread > 0) || (parseTime(tb.last_at) - parseTime(ta.last_at));
            if (ta || tb) return ta ? -1 : 1;
            return a.name.localeCompare(b.name);
        });
        const box = $('threadList');
        box.innerHTML = '';
        if (!list.length) {
            box.innerHTML = '<div class="text-center py-12 px-4"><div class="text-3xl mb-2 opacity-30">🔍</div><p class="text-xs font-bold text-slate-400">No conversations match these filters.</p></div>';
        }
        list.forEach(s => box.appendChild(threadRow(s)));
        $('threadCount').textContent = list.length + ' of ' + ROSTER.length + ' students';
    }

    function threadRow(s) {
        const t = THREADS[s.id];
        const row = document.createElement('button');
        row.type = 'button';
        row.dataset.id = s.id;
        row.setAttribute('role', 'option');
        row.setAttribute('aria-selected', s.id === currentId);
        row.className = 'w-full flex items-start gap-3 p-3 rounded-xl border text-left transition-all focus:outline-none focus:ring-2 focus:ring-blue-400 ' +
            (s.id === currentId ? 'bg-blue-50 border-blue-200' : 'border-transparent hover:bg-white hover:border-slate-200');
        row.onclick = () => openChat(s.id);

        const avatar = document.createElement('div');
        avatar.className = 'w-10 h-10 rounded-full flex items-center justify-center font-black text-xs shrink-0 ' + (t && t.unread ? 'bg-blue-600 text-white' : 'bg-slate-900 text-white');
        avatar.textContent = initials(s.name);

        const body = document.createElement('div');
        body.className = 'min-w-0 flex-1';
        const top = document.createElement('div');
        top.className = 'flex items-center justify-between gap-2';
        const name = document.createElement('span');
        name.className = 'text-sm truncate ' + (t && t.unread ? 'font-black text-slate-900' : 'font-bold text-slate-800');
        name.textContent = s.name;
        const when = document.createElement('span');
        when.className = 'text-[10px] font-bold shrink-0 ' + (t && t.unread ? 'text-blue-600' : 'text-slate-400');
        when.textContent = t ? ago(t.last_at) : '';
        top.append(name, when);

        const bottom = document.createElement('div');
        bottom.className = 'flex items-center justify-between gap-2 mt-0.5';
        const preview = document.createElement('span');
        preview.className = 'text-xs truncate ' + (t && t.unread ? 'text-slate-700 font-semibold' : 'text-slate-400 font-medium');
        preview.textContent = t ? (t.last_is_me ? 'You: ' : '') + stripEscalation(t.last_text) : metaLine(s);
        bottom.appendChild(preview);
        const badges = document.createElement('span');
        badges.className = 'flex items-center gap-1 shrink-0';
        if (t && t.escalated) badges.insertAdjacentHTML('beforeend', '<span class="bg-amber-100 text-amber-700 text-[9px] font-black px-1.5 py-0.5 rounded-md" title="Handed over by ISKO">ISKO</span>');
        if (t && t.unread) badges.insertAdjacentHTML('beforeend', `<span class="bg-red-500 text-white text-[10px] font-black min-w-[20px] text-center px-1.5 py-0.5 rounded-full">${t.unread}</span>`);
        bottom.appendChild(badges);

        body.append(top, bottom);
        row.append(avatar, body);
        return row;
    }

    ['threadSearch', 'programFilter', 'yearFilter'].forEach(id => $(id).addEventListener('input', renderList));

    // ---------- open conversation ----------
    function openChat(id) {
        const s = ROSTER.find(x => x.id === id);
        if (!s) return;
        currentId = id;
        lastRenderedKey = '';
        $('emptyChatState').classList.add('hidden');
        $('inbox').classList.add('chat-open');
        $('activeAvatar').textContent = initials(s.name);
        $('activeChatName').textContent = s.name;
        $('activeChatMeta').textContent = metaLine(s);
        $('chatMessages').innerHTML = '<p class="text-center text-xs font-bold text-slate-400 mt-6">Loading…</p>';
        if (THREADS[id]) THREADS[id].unread = 0;   // opening it marks it read (chat_handler fetch)
        renderList();
        loadMessages(true);
        $('chatInput').focus();
    }

    function closeChat() {
        $('inbox').classList.remove('chat-open');
    }

    function dayLabel(d) {
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const day = new Date(d); day.setHours(0, 0, 0, 0);
        const diff = (today - day) / 86400000;
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Yesterday';
        return d.toLocaleDateString('en-US', { weekday: diff < 7 ? 'long' : undefined, month: 'long', day: 'numeric', year: today.getFullYear() !== d.getFullYear() ? 'numeric' : undefined });
    }

    function loadMessages(forceScroll = false) {
        if (!currentId) return;
        const requested = currentId;
        fetch(`${API}?action=fetch&other_user_id=${requested}`)
            .then(r => r.json())
            .then(data => {
                if (requested !== currentId || !Array.isArray(data)) return;
                const last = data[data.length - 1];
                const key = requested + ':' + data.length + ':' + (last ? last.MessageID + ':' + last.MessageText : '');
                if (key === lastRenderedKey) return;
                lastRenderedKey = key;

                const box = $('chatMessages');
                const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
                box.innerHTML = '';
                if (!data.length) {
                    box.innerHTML = '<div class="m-auto text-center"><div class="text-4xl mb-2 opacity-30">👋</div><p class="text-xs font-bold text-slate-400">No messages yet. Say hello, or pick a quick reply below.</p></div>';
                }
                let lastDay = '';
                data.forEach(msg => {
                    const when = parseTime(msg.CreatedAt);
                    const day = when ? dayLabel(when) : '';
                    if (day !== lastDay) {
                        const sep = document.createElement('div');
                        sep.className = 'self-center my-3 px-3 py-1 rounded-full bg-white border border-slate-200 text-[10px] font-black text-slate-400 uppercase tracking-widest';
                        sep.textContent = day;
                        box.appendChild(sep);
                        lastDay = day;
                    }
                    const mine = Number(msg.SenderID) === ME;
                    const escalated = !mine && msg.MessageText.includes(ESCALATION);
                    const wrap = document.createElement('div');
                    wrap.className = 'flex flex-col max-w-[80%] mb-1 ' + (mine ? 'self-end items-end' : 'self-start items-start');
                    if (escalated) {
                        const tag = document.createElement('span');
                        tag.className = 'text-[10px] font-black text-amber-600 uppercase tracking-widest mb-1';
                        tag.textContent = '🤖 Handed over by ISKO';
                        wrap.appendChild(tag);
                    }
                    const bubble = document.createElement('div');
                    bubble.className = 'px-3.5 py-2.5 text-sm font-medium shadow-sm whitespace-pre-wrap break-words ' + (mine
                        ? 'bg-slate-900 text-white rounded-[1.1rem] rounded-tr-sm'
                        : escalated ? 'bg-amber-50 border border-amber-200 text-slate-800 rounded-[1.1rem] rounded-tl-sm'
                                    : 'bg-white border border-slate-200 text-slate-800 rounded-[1.1rem] rounded-tl-sm');
                    bubble.textContent = escalated ? stripEscalation(msg.MessageText) : msg.MessageText;
                    const time = document.createElement('span');
                    time.className = 'text-[10px] font-bold text-slate-400 mt-1 px-1';
                    time.textContent = when ? when.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : '';
                    wrap.append(bubble, time);
                    box.appendChild(wrap);
                });
                if (forceScroll || nearBottom) box.scrollTop = box.scrollHeight;
            })
            .catch(err => console.error('Inbox:', err));
    }

    // ---------- composer ----------
    const input = $('chatInput');
    const grow = () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 140) + 'px';
        input.style.overflowY = input.scrollHeight > 140 ? 'auto' : 'hidden';   // a scrollbar only once it's really long
        $('sendBtn').disabled = !input.value.trim() || !currentId;
    };
    input.addEventListener('input', grow);
    input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('adminChatForm').requestSubmit(); } });
    function useQuickReply(btn) { input.value = btn.textContent.trim(); grow(); input.focus(); }

    $('adminChatForm').addEventListener('submit', e => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text || !currentId) return;
        $('sendBtn').disabled = true;
        $('sendError').classList.add('hidden');
        const body = new FormData();
        body.append('action', 'send');
        body.append('receiver_id', currentId);
        body.append('message', text);
        fetch(API, { method: 'POST', body })
            .then(r => r.json().catch(() => ({ error: 'The server did not answer properly.' })))
            .then(res => {
                if (!res || !res.success) throw new Error(res && res.error ? res.error : 'Message not sent.');
                input.value = '';
                grow();
                lastRenderedKey = '';
                loadMessages(true);
                refreshThreads();
            })
            .catch(err => {
                $('sendError').textContent = '⚠️ ' + err.message + ' Your text is still in the box; try again.';
                $('sendError').classList.remove('hidden');
                grow();
            });
    });

    // ---------- live updates ----------
    function refreshThreads() {
        fetch(`${API}?action=threads`)
            .then(r => r.json())
            .then(data => {
                if (!data || data.error) return;
                if (currentId && data[currentId]) data[currentId].unread = 0;   // the open one is being read
                const changed = JSON.stringify(data) !== JSON.stringify(THREADS);
                THREADS = data;
                if (changed && !$('threadList').matches(':hover')) renderList();
            })
            .catch(() => {});
    }
    setInterval(() => { if (!document.hidden) loadMessages(); }, 3000);
    setInterval(() => { if (!document.hidden) refreshThreads(); }, 5000);
    setInterval(() => { if (!document.hidden && !$('threadList').matches(':hover')) renderList(); }, 60000);   // keep "5m ago" current

    renderList();
</script>

<?php include '../includes/footer.php'; ?>
