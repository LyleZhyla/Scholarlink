<?php
// ✨ THE ROLE GATEKEEPER
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Student' && $_SESSION['role'] !== 'Internal_Admin')) {
    return;
}

// ✨ SMART POLISH: Hide the Chat Head if they are already on the full-screen messages page!
if (basename($_SERVER['PHP_SELF']) === 'messages.php') {
    return; 
}

$chat_role = $_SESSION['role'];
$chat_current_user = $_SESSION['user_id'];

// ✨ THE INVISIBILITY CLOAK: Hide by default for Students ONLY
$hide_chat_head_class = ($chat_role === 'Student') ? 'hidden' : ''; 

// --- FETCH DATA BASED ON ROLE ---
if ($chat_role === 'Student') {
    try {
        $admin_stmt = $pdo->prepare("SELECT UserID, FirstName, LastName FROM users WHERE Role = 'Internal_Admin' LIMIT 1");
        $admin_stmt->execute();
        $admin = $admin_stmt->fetch();
        $chat_partner_id = $admin ? $admin['UserID'] : 0;
    } catch (PDOException $e) { $chat_partner_id = 0; }
} else {
    // Internal Admin: Fetch list of students and their unread message counts!
    try {
        $stmt = $pdo->prepare("
            SELECT u.UserID, u.FirstName, u.LastName, u.Major,
                   (SELECT COUNT(*) FROM messages m WHERE m.SenderID = u.UserID AND m.ReceiverID = :admin_id AND m.IsRead = 0) AS unread_count
            FROM users u WHERE u.Role = 'Student' ORDER BY unread_count DESC, u.FirstName ASC
        ");
        $stmt->execute(['admin_id' => $chat_current_user]);
        $chat_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total_unread = array_sum(array_column($chat_students, 'unread_count'));
    } catch (PDOException $e) { $chat_students = []; $total_unread = 0; }
}
?>

<div id="humanChatWidget" class="fixed bottom-4 right-28 lg:bottom-5 lg:right-24 z-[9999] flex flex-col items-end pointer-events-none <?= $hide_chat_head_class ?>">
    
    <div id="humanChatWindow" class="bg-white w-[90vw] sm:w-[350px] lg:w-[380px] h-[500px] max-h-[70vh] rounded-[2rem] shadow-2xl border border-slate-200 mb-4 flex flex-col overflow-hidden transition-all duration-300 origin-bottom-right scale-0 opacity-0 pointer-events-auto relative">
        
        <?php if ($chat_role === 'Student'): ?>
            <?php if (!$chat_partner_id): ?>
                <div class="absolute inset-0 flex flex-col items-center justify-center bg-slate-50 z-20">
                    <div class="text-5xl mb-3 grayscale opacity-40">🔌</div>
                    <h3 class="text-sm font-black text-slate-400">Support is offline</h3>
                </div>
            <?php endif; ?>

            <div class="bg-blue-600 text-white p-4 flex justify-between items-center shrink-0 shadow-md z-10">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center text-xl">👨‍💼</div>
                    <div>
                        <h4 class="font-black text-sm leading-tight">Scholarship Office</h4>
                        <p class="text-[9px] text-blue-200 font-bold uppercase tracking-widest flex items-center gap-1">
                            <span class="w-1.5 h-1.5 bg-green-400 rounded-full animate-pulse"></span> Live Support
                        </p>
                    </div>
                </div>
                <button onclick="toggleHumanChat()" class="w-8 h-8 flex items-center justify-center bg-white/10 hover:bg-red-500 rounded-full transition-colors font-black text-lg">&times;</button>
            </div>

            <div id="humanChatMessages" class="flex-1 p-4 overflow-y-auto bg-slate-50 flex flex-col gap-3"></div>

            <div class="p-3 bg-white border-t border-slate-100 shrink-0">
                <form onsubmit="sendStudentHeadMessage(event)" class="flex items-center gap-2">
                    <input type="text" id="humanChatInput" placeholder="Message the office..." required autocomplete="off" <?= !$chat_partner_id ? 'disabled' : '' ?>
                        class="flex-1 bg-slate-100 border-none text-slate-900 rounded-full px-4 py-2.5 text-xs font-medium outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                    <button type="submit" <?= !$chat_partner_id ? 'disabled' : '' ?> class="w-10 h-10 bg-blue-600 hover:bg-blue-700 text-white rounded-full flex items-center justify-center transition-all shadow-md">
                        <svg class="w-4 h-4 translate-x-[1px]" fill="currentColor" viewBox="0 0 20 20"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"></path></svg>
                    </button>
                </form>
            </div>

        <?php else: ?>
            <div id="adminStudentListView" class="flex-1 flex flex-col h-full bg-white z-10 relative">
                <div class="bg-slate-900 text-white p-4 flex justify-between items-center shrink-0 shadow-md">
                    <div>
                        <h4 class="font-black text-sm leading-tight">Student Inbox</h4>
                        <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest">Select a thread</p>
                    </div>
                    <button onclick="toggleHumanChat()" class="w-8 h-8 flex items-center justify-center bg-white/10 hover:bg-red-500 rounded-full transition-colors font-black text-lg">&times;</button>
                </div>
                <div class="flex-1 overflow-y-auto p-2 space-y-1 bg-slate-50">
                    <?php foreach($chat_students as $cs): ?>
                        <button onclick="openAdminHeadThread(<?= $cs['UserID'] ?>, '<?= htmlspecialchars($cs['FirstName'], ENT_QUOTES) ?>')" class="w-full flex items-center justify-between p-3 rounded-xl hover:bg-white hover:shadow-sm border border-transparent hover:border-slate-200 transition-all text-left group">
                            <div class="flex items-center gap-3 overflow-hidden">
                                <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center font-black text-xs shrink-0 group-hover:bg-blue-600 group-hover:text-white transition-colors">
                                    <?= strtoupper(substr($cs['FirstName'], 0, 1) . substr($cs['LastName'], 0, 1)) ?>
                                </div>
                                <div class="overflow-hidden">
                                    <h4 class="font-bold text-slate-900 text-xs truncate"><?= htmlspecialchars($cs['FirstName'] . ' ' . $cs['LastName']) ?></h4>
                                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest truncate"><?= htmlspecialchars($cs['Major'] ?? 'Student') ?></p>
                                </div>
                            </div>
                            <?php if($cs['unread_count'] > 0): ?>
                                <span class="bg-red-500 text-white font-black text-[9px] px-2 py-0.5 rounded-full shrink-0 shadow-sm"><?= $cs['unread_count'] ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="adminActiveChatView" class="hidden absolute inset-0 flex-col bg-white z-20">
                <div class="bg-blue-600 text-white p-4 flex items-center gap-3 shrink-0 shadow-md">
                    <button onclick="closeAdminHeadThread()" class="w-8 h-8 flex items-center justify-center bg-white/20 hover:bg-slate-900 rounded-full transition-colors text-xs font-black">&larr;</button>
                    <div class="flex-1 overflow-hidden">
                        <h4 id="adminHeadChatName" class="font-black text-sm leading-tight truncate">Student Name</h4>
                        <p class="text-[9px] text-blue-200 font-bold uppercase tracking-widest">Live Chat</p>
                    </div>
                </div>
                <div id="adminHeadMessages" class="flex-1 p-4 overflow-y-auto bg-slate-50 flex flex-col gap-3"></div>
                <div class="p-3 bg-white border-t border-slate-100 shrink-0">
                    <form onsubmit="sendAdminHeadMessage(event)" class="flex items-center gap-2">
                        <input type="text" id="adminHeadInput" placeholder="Reply to student..." required autocomplete="off" class="flex-1 bg-slate-100 border-none text-slate-900 rounded-full px-4 py-2.5 text-xs font-medium outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                        <button type="submit" class="w-10 h-10 bg-blue-600 hover:bg-slate-900 text-white rounded-full flex items-center justify-center transition-all shadow-md">
                            <svg class="w-4 h-4 translate-x-[1px]" fill="currentColor" viewBox="0 0 20 20"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"></path></svg>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <button onclick="toggleHumanChat()" id="humanChatToggleBtn" class="w-14 h-14 lg:w-16 lg:h-16 <?= $chat_role === 'Student' ? 'bg-blue-600' : 'bg-slate-900' ?> hover:bg-blue-500 text-white rounded-full flex items-center justify-center shadow-xl hover:shadow-2xl transition-all hover:-translate-y-1 relative group pointer-events-auto border-4 border-white">
        <span class="text-2xl lg:text-3xl group-hover:scale-110 transition-transform"><?= $chat_role === 'Student' ? '👨‍💼' : '💬' ?></span>
        
        <?php if($chat_role === 'Internal_Admin' && $total_unread > 0): ?>
            <span class="absolute top-0 right-0 w-4 h-4 bg-red-500 border-2 border-white rounded-full"></span>
        <?php endif; ?>
    </button>
</div>

<script>
    const chatRole = '<?= $chat_role ?>';
    const myUserId = <?= $chat_current_user ?>;
    
    let isHumanChatOpen = false;
    let headActiveStudentId = null; 
    let editingMessageId = null; 

    // ✨ GLOBAL FUNCTION: Called by chatbot.php to reveal the Live Chat
    window.unhideLiveChatProtocol = function() {
        const widget = document.getElementById('humanChatWidget');
        if (widget) {
            widget.classList.remove('hidden');
            // Add a little bounce to draw attention
            widget.classList.add('animate-bounce');
            setTimeout(() => widget.classList.remove('animate-bounce'), 1000);
        }
    };

    function toggleHumanChat() {
        const windowEl = document.getElementById('humanChatWindow');
        const btnEl = document.getElementById('humanChatToggleBtn');
        isHumanChatOpen = !isHumanChatOpen;

        if (isHumanChatOpen) {
            windowEl.classList.remove('scale-0', 'opacity-0');
            windowEl.classList.add('scale-100', 'opacity-100');
            btnEl.classList.add('bg-red-500');
            
            if (chatRole === 'Student') {
                loadStudentHeadMessages();
            } else if (chatRole === 'Internal_Admin' && headActiveStudentId) {
                loadAdminHeadMessages();
            }
        } else {
            windowEl.classList.add('scale-0', 'opacity-0');
            windowEl.classList.remove('scale-100', 'opacity-100');
            btnEl.classList.remove('bg-red-500');
            cancelEdit(); 
            if (chatRole === 'Internal_Admin') closeAdminHeadThread();
        }
    }

    function formatTime(dateString) {
        const d = new Date(dateString.replace(' ', 'T'));
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    window.startEdit = function(msgId, currentText) {
        editingMessageId = msgId;
        const inputField = chatRole === 'Student' ? document.getElementById('humanChatInput') : document.getElementById('adminHeadInput');
        inputField.value = currentText;
        inputField.focus();
        inputField.classList.add('ring-2', 'ring-amber-400', 'bg-amber-50');
    }

    function cancelEdit() {
        editingMessageId = null;
        const inputField = chatRole === 'Student' ? document.getElementById('humanChatInput') : document.getElementById('adminHeadInput');
        if(inputField) {
            inputField.value = '';
            inputField.classList.remove('ring-2', 'ring-amber-400', 'bg-amber-50');
        }
    }

    // ==========================================
    // STUDENT LOGIC
    // ==========================================
    <?php if ($chat_role === 'Student'): ?>
        const chatPartnerId = <?= $chat_partner_id ?>;
        const studentMessagesDiv = document.getElementById('humanChatMessages');

        function loadStudentHeadMessages() {
            if (!chatPartnerId || !isHumanChatOpen) return; 
            fetch(`../actions/chat_handler.php?action=fetch&other_user_id=${chatPartnerId}`)
            .then(res => res.json()).then(data => {
                studentMessagesDiv.innerHTML = '<div class="text-center text-[9px] font-black text-slate-400 uppercase tracking-widest my-2">Chat Started</div>';
                
                data.forEach(msg => {
                    const isMe = (msg.SenderID == myUserId);
                    const safeText = msg.MessageText.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                    const timeStr = formatTime(msg.CreatedAt);
                    const seenStr = msg.IsRead == 1 ? '<span class="text-blue-500">✓ Seen</span>' : '✓ Sent';
                    
                    const wrapper = document.createElement('div');
                    
                    if (isMe) {
                        wrapper.className = 'flex flex-col items-end mb-2 w-full group';
                        wrapper.innerHTML = `
                            <div class="bg-blue-600 text-white p-3 rounded-[1.25rem] rounded-tr-sm text-xs font-medium shadow-sm max-w-[85%]">
                                ${safeText}
                            </div>
                            <div class="flex items-center justify-end gap-2 mt-1 px-1">
                                <button onclick="startEdit(${msg.MessageID}, \`${safeText.replace(/`/g, '\\`')}\`)" class="text-[9px] text-slate-400 hover:text-blue-600 font-bold opacity-0 group-hover:opacity-100 transition-opacity">Edit</button>
                                <span class="text-[9px] text-slate-400 font-bold">${timeStr} • ${seenStr}</span>
                            </div>
                        `;
                    } else {
                        wrapper.className = 'flex flex-col items-start mb-2 w-full';
                        wrapper.innerHTML = `
                            <div class="bg-white border border-slate-200 text-slate-800 p-3 rounded-[1.25rem] rounded-tl-sm text-xs font-medium shadow-sm max-w-[85%]">
                                ${safeText}
                            </div>
                            <div class="flex items-center justify-start mt-1 px-1">
                                <span class="text-[9px] text-slate-400 font-bold">${timeStr}</span>
                            </div>
                        `;
                    }
                    studentMessagesDiv.appendChild(wrapper);
                });
                
                if (!editingMessageId) studentMessagesDiv.scrollTop = studentMessagesDiv.scrollHeight;
            });
        }

        function sendStudentHeadMessage(e) {
            e.preventDefault();
            const input = document.getElementById('humanChatInput');
            if (!input.value.trim() || !chatPartnerId) return;
            
            const formData = new FormData();
            formData.append('action', editingMessageId ? 'edit' : 'send');
            if (editingMessageId) formData.append('message_id', editingMessageId);
            formData.append('receiver_id', chatPartnerId); 
            formData.append('message', input.value.trim());
            
            fetch('../actions/chat_handler.php', { method: 'POST', body: formData }).then(() => { 
                cancelEdit();
                loadStudentHeadMessages(); 
            });
        }
        setInterval(() => { if (isHumanChatOpen) loadStudentHeadMessages(); }, 3000); 

    // ==========================================
    // INTERNAL ADMIN LOGIC
    // ==========================================
    <?php else: ?>
        const adminMessagesDiv = document.getElementById('adminHeadMessages');

        function openAdminHeadThread(studentId, studentName) {
            headActiveStudentId = studentId;
            document.getElementById('adminStudentListView').classList.add('hidden');
            document.getElementById('adminStudentListView').classList.remove('flex');
            document.getElementById('adminActiveChatView').classList.remove('hidden');
            document.getElementById('adminActiveChatView').classList.add('flex');
            document.getElementById('adminHeadChatName').innerText = studentName;
            cancelEdit();
            loadAdminHeadMessages();
        }

        function closeAdminHeadThread() {
            headActiveStudentId = null;
            document.getElementById('adminActiveChatView').classList.add('hidden');
            document.getElementById('adminActiveChatView').classList.remove('flex');
            document.getElementById('adminStudentListView').classList.remove('hidden');
            document.getElementById('adminStudentListView').classList.add('flex');
            cancelEdit();
        }

        function loadAdminHeadMessages() {
            if (!headActiveStudentId || !isHumanChatOpen) return; 
            fetch(`../actions/chat_handler.php?action=fetch&other_user_id=${headActiveStudentId}`)
            .then(res => res.json()).then(data => {
                adminMessagesDiv.innerHTML = '<div class="text-center text-[9px] font-black text-slate-400 uppercase tracking-widest my-2">Live Chat</div>';
                
                data.forEach(msg => {
                    const isMe = (msg.SenderID == myUserId);
                    const safeText = msg.MessageText.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                    const timeStr = formatTime(msg.CreatedAt);
                    const seenStr = msg.IsRead == 1 ? '<span class="text-blue-500">✓ Seen</span>' : '✓ Sent';

                    const wrapper = document.createElement('div');

                    if (isMe) {
                        wrapper.className = 'flex flex-col items-end mb-2 w-full group';
                        wrapper.innerHTML = `
                            <div class="bg-slate-900 text-white p-3 rounded-[1.25rem] rounded-tr-sm text-xs font-medium shadow-sm max-w-[85%]">
                                ${safeText}
                            </div>
                            <div class="flex items-center justify-end gap-2 mt-1 px-1">
                                <button onclick="startEdit(${msg.MessageID}, \`${safeText.replace(/`/g, '\\`')}\`)" class="text-[9px] text-slate-400 hover:text-blue-600 font-bold opacity-0 group-hover:opacity-100 transition-opacity">Edit</button>
                                <span class="text-[9px] text-slate-400 font-bold">${timeStr} • ${seenStr}</span>
                            </div>
                        `;
                    } else {
                        wrapper.className = 'flex flex-col items-start mb-2 w-full';
                        wrapper.innerHTML = `
                            <div class="bg-white border border-slate-200 text-slate-800 p-3 rounded-[1.25rem] rounded-tl-sm text-xs font-medium shadow-sm max-w-[85%]">
                                ${safeText}
                            </div>
                            <div class="flex items-center justify-start mt-1 px-1">
                                <span class="text-[9px] text-slate-400 font-bold">${timeStr}</span>
                            </div>
                        `;
                    }
                    adminMessagesDiv.appendChild(wrapper);
                });
                
                if (!editingMessageId) adminMessagesDiv.scrollTop = adminMessagesDiv.scrollHeight;
            });
        }

        function sendAdminHeadMessage(e) {
            e.preventDefault();
            const input = document.getElementById('adminHeadInput');
            if (!input.value.trim() || !headActiveStudentId) return;
            
            const formData = new FormData();
            formData.append('action', editingMessageId ? 'edit' : 'send');
            if (editingMessageId) formData.append('message_id', editingMessageId);
            formData.append('receiver_id', headActiveStudentId); 
            formData.append('message', input.value.trim());
            
            fetch('../actions/chat_handler.php', { method: 'POST', body: formData }).then(() => { 
                cancelEdit();
                loadAdminHeadMessages(); 
            });
        }
        setInterval(() => { if (isHumanChatOpen && headActiveStudentId) loadAdminHeadMessages(); }, 3000); 
    <?php endif; ?>
</script>