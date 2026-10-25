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
include '../includes/header.php'; 
include '../includes/internal_sidebar.php'; 

$admin_id = $_SESSION['user_id'];

// Fetch all students and dynamically count unread messages from them
try {
    $stmt = $pdo->prepare("
        SELECT u.UserID, u.FirstName, u.LastName, u.Major,
               (SELECT COUNT(*) FROM messages m WHERE m.SenderID = u.UserID AND m.ReceiverID = :admin_id AND m.IsRead = 0) AS unread_count
        FROM users u
        WHERE u.Role = 'Student' 
        ORDER BY unread_count DESC, u.FirstName ASC
    ");
    $stmt->execute(['admin_id' => $admin_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching students: " . $e->getMessage());
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300 flex flex-col">
    <header class="mb-6 lg:mb-8 shrink-0">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Student Support Inbox</h2>
        <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Manage chat threads and assist scholarship applicants in real-time.</p>
    </header>

    <div class="flex-1 bg-white border border-slate-200 rounded-[2rem] shadow-sm flex overflow-hidden min-h-[550px] max-h-[75vh]">
        
        <div class="w-full md:w-1/3 border-r border-slate-200 bg-slate-50/30 flex flex-col shrink-0">
            <div class="p-4 border-b border-slate-100 bg-white">
                <input type="text" id="studentSearch" oninput="searchStudents()" placeholder="Search student directory..." 
                    class="w-full bg-slate-100 border-none text-slate-900 rounded-xl px-4 py-3 text-xs lg:text-sm font-medium outline-none focus:ring-2 focus:ring-blue-500 transition-all">
            </div>
            
            <div id="studentListContainer" class="flex-1 overflow-y-auto p-3 space-y-1">
                <?php foreach($students as $student): ?>
                    <button data-id="<?= $student['UserID'] ?>" onclick="openChat(<?= $student['UserID'] ?>, '<?= htmlspecialchars($student['FirstName'] . ' ' . $student['LastName'], ENT_QUOTES) ?>', this)" 
                            data-name="<?= strtolower(htmlspecialchars($student['FirstName'] . ' ' . $student['LastName'], ENT_QUOTES)) ?>"
                            class="student-thread-btn w-full flex items-center justify-between p-3 rounded-xl hover:bg-white hover:shadow-sm border border-transparent hover:border-slate-200 transition-all text-left group focus:outline-none">
                        
                        <div class="flex items-center gap-3 overflow-hidden">
                            <div class="w-10 h-10 bg-slate-900 text-white rounded-full flex items-center justify-center font-black text-sm shrink-0 shadow-sm group-hover:bg-blue-600 transition-colors">
                                <?= strtoupper(substr($student['FirstName'], 0, 1) . substr($student['LastName'], 0, 1)) ?>
                            </div>
                            <div class="overflow-hidden">
                                <h4 class="font-black text-slate-900 text-xs lg:text-sm truncate group-hover:text-blue-600 transition-colors">
                                    <?= htmlspecialchars($student['FirstName'] . ' ' . $student['LastName']) ?>
                                </h4>
                                <p class="text-[9px] lg:text-[10px] font-black text-slate-400 uppercase tracking-widest truncate">
                                    <?= htmlspecialchars($student['Major'] ?? 'Information Technology') ?>
                                </p>
                            </div>
                        </div>

                        <span class="unread-badge bg-red-500 text-white font-black text-[9px] px-2 py-0.5 rounded-full shrink-0 shadow-sm transition-all <?= $student['unread_count'] > 0 ? '' : 'hidden' ?>">
                            <?= $student['unread_count'] ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="hidden md:flex w-2/3 flex-col bg-white relative">
            
            <div id="emptyChatState" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-50 z-10 transition-opacity duration-300">
                <div class="text-5xl mb-3 grayscale opacity-30">💬</div>
                <h3 class="text-sm font-black text-slate-400 uppercase tracking-widest">Select a student thread to reply</h3>
            </div>

            <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-4 bg-white shrink-0 shadow-sm">
                <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center font-black text-lg shadow-inner">
                    🎓
                </div>
                <div>
                    <h3 id="activeChatName" class="font-black text-slate-900 text-base lg:text-lg leading-tight">Student Workspace</h3>
                    <p class="text-[9px] font-bold text-green-500 uppercase tracking-widest flex items-center gap-1">
                        <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span> Conversation Feed
                    </p>
                </div>
            </div>

            <div id="chatMessages" class="flex-1 p-6 overflow-y-auto bg-slate-50/50 flex flex-col gap-3">
                </div>

            <div class="p-4 bg-white border-t border-slate-100 shrink-0">
                <form id="adminChatForm" onsubmit="sendAdminMessage(event)" class="flex items-center gap-3">
                    <input type="text" id="adminChatInput" placeholder="Write a direct instruction or reply..." required autocomplete="off"
                        class="flex-1 bg-slate-50 border border-slate-200 text-slate-900 rounded-xl px-4 py-3.5 text-xs lg:text-sm font-medium outline-none focus:border-blue-500 focus:bg-white transition-all">
                    <button type="submit" class="h-11 lg:h-12 px-6 bg-slate-900 hover:bg-blue-600 text-white rounded-xl font-black text-[10px] lg:text-xs uppercase tracking-widest transition-colors shadow-md active:scale-95">
                        Send Response
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
    const API = '../actions/chat_handler.php';
    let currentStudentId = null;
    let lastRenderedKey = '';
    const myAdminId = <?= $admin_id ?>;
    const chatMessagesDiv = document.getElementById('chatMessages');

    function openChat(studentId, studentName, buttonEl) {
        currentStudentId = studentId;
        lastRenderedKey = '';

        // UI Handling: Display Chat Area for desktop
        document.getElementById('emptyChatState').classList.add('hidden');
        document.getElementById('activeChatName').innerText = studentName;

        // Handle visual thread tracking selections
        document.querySelectorAll('.student-thread-btn').forEach(btn => btn.classList.remove('bg-blue-50', 'border-slate-200'));
        buttonEl.classList.add('bg-blue-50', 'border-slate-200');

        // Hide unread badge upon admin review entry
        const badge = buttonEl.querySelector('.unread-badge');
        if (badge) badge.classList.add('hidden');

        // Execute background fetch sequence
        loadAdminMessages(true);
    }

    function loadAdminMessages(forceScroll = false) {
        if (!currentStudentId) return;
        const requestedId = currentStudentId;

        fetch(`${API}?action=fetch&other_user_id=${requestedId}`)
        .then(response => response.json())
        .then(data => {
            // Ignore stale responses (admin switched threads) and error payloads
            if (requestedId !== currentStudentId) return;
            if (!Array.isArray(data)) return;

            // Only redraw when something actually changed (keeps scroll position stable)
            const last = data.length ? data[data.length - 1] : null;
            const key = requestedId + ':' + data.length + ':' + (last ? last.MessageID + ':' + last.MessageText : '');
            if (key === lastRenderedKey) return;
            lastRenderedKey = key;

            const nearBottom = (chatMessagesDiv.scrollHeight - chatMessagesDiv.scrollTop - chatMessagesDiv.clientHeight) < 80;

            chatMessagesDiv.innerHTML = '<div class="text-center text-[9px] font-black text-slate-400 uppercase tracking-widest my-2 border-b border-slate-100 pb-2">Conversation Log Locked</div>';

            if (data.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'text-center text-xs font-medium text-slate-400 mt-6';
                empty.innerText = 'No messages in this thread yet.';
                chatMessagesDiv.appendChild(empty);
            }

            data.forEach(msg => {
                const isMe = (msg.SenderID == myAdminId);
                const bubble = document.createElement('div');

                if (isMe) {
                    bubble.className = 'bg-slate-900 text-white p-3.5 rounded-[1.25rem] rounded-tr-sm self-end mb-1 max-w-[75%] text-xs lg:text-sm font-medium shadow-sm';
                } else {
                    bubble.className = 'bg-white border border-slate-200 text-slate-800 p-3.5 rounded-[1.25rem] rounded-tl-sm self-start mb-1 max-w-[75%] text-xs lg:text-sm font-medium shadow-sm';
                }

                bubble.innerText = msg.MessageText;
                chatMessagesDiv.appendChild(bubble);
            });

            if (forceScroll === true || nearBottom) {
                chatMessagesDiv.scrollTop = chatMessagesDiv.scrollHeight;
            }
        })
        .catch(error => console.error('Workspace Stream Error:', error));
    }

    function sendAdminMessage(e) {
        e.preventDefault();
        const input = document.getElementById('adminChatInput');
        const text = input.value.trim();

        if (!text || !currentStudentId) return;

        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('receiver_id', currentStudentId);
        formData.append('message', text);

        fetch(API, {
            method: 'POST',
            body: formData
        }).then(() => {
            input.value = '';
            lastRenderedKey = '';
            loadAdminMessages(true);
        });
    }

    function searchStudents() {
        const query = document.getElementById('studentSearch').value.toLowerCase();
        const items = document.querySelectorAll('.student-thread-btn');

        items.forEach(item => {
            const name = item.getAttribute('data-name');
            if (name.includes(query)) {
                item.classList.remove('hidden');
            } else {
                item.classList.add('hidden');
            }
        });
    }

    // ✨ Live unread badges (updates without a page refresh)
    function refreshUnread() {
        fetch(`${API}?action=unread_counts`)
        .then(r => r.json())
        .then(counts => {
            if (!counts || counts.error) return;
            document.querySelectorAll('.student-thread-btn').forEach(btn => {
                const id = btn.dataset.id;
                const badge = btn.querySelector('.unread-badge');
                if (!badge) return;
                const n = (id == currentStudentId) ? 0 : (parseInt(counts[id], 10) || 0);
                badge.textContent = n;
                badge.classList.toggle('hidden', n === 0);
            });
        })
        .catch(() => {});
    }

    setInterval(loadAdminMessages, 3000);
    setInterval(refreshUnread, 3000);
    refreshUnread();
</script>

<?php include '../includes/footer.php'; ?>