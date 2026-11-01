<?php 
session_start();

// ✨ Session manager MUST be loaded before any HTML
include '../includes/session_manager.php'; 

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- THE SECURITY GATEKEEPER ---
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') { 
    header("Location: ../student_login.php"); 
    exit(); 
}

include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/student_sidebar.php'; 

$student_id = $_SESSION['user_id'];

// ✨ SMART ROUTING: Automatically find the Internal Admin to route messages to (FIXED UNDERSCORE)
try {
    $admin_stmt = $pdo->prepare("SELECT UserID, FirstName, LastName FROM users WHERE Role = 'Internal_Admin' LIMIT 1");
    $admin_stmt->execute();
    $admin = $admin_stmt->fetch();
    
    // Fallback if no admin is found
    $admin_id = $admin ? $admin['UserID'] : 0;
    $admin_name = $admin ? $admin['FirstName'] . ' ' . $admin['LastName'] : 'University Admin';
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300 flex flex-col">
    <header class="mb-6 lg:mb-8 shrink-0">
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Messages</h2>
        <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Chat directly with the Scholarship Office.</p>
    </header>

    <div class="flex-1 bg-white border border-slate-200 rounded-[2rem] shadow-sm flex flex-col overflow-hidden min-h-[600px] max-h-[80vh] relative">
        
        <?php if (!$admin_id): ?>
            <div class="absolute inset-0 flex flex-col items-center justify-center bg-slate-50 z-10">
                <div class="text-6xl mb-4 grayscale opacity-40">🔌</div>
                <h3 class="text-lg font-black text-slate-400">Support is currently offline</h3>
            </div>
        <?php endif; ?>

        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-white shrink-0 shadow-sm z-10">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-600 text-white rounded-full flex items-center justify-center font-black text-xl shadow-md shrink-0">
                    👨‍💼
                </div>
                <div>
                    <h3 class="font-black text-slate-900 text-lg lg:text-xl leading-tight">Scholarship Office</h3>
                    <p class="text-[10px] lg:text-xs font-bold text-green-500 uppercase tracking-widest flex items-center gap-1">
                        <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span> Online & Ready to Help
                    </p>
                </div>
            </div>
            <div class="hidden sm:block text-[10px] font-black text-slate-400 uppercase tracking-widest bg-slate-100 px-3 py-1.5 rounded-lg border border-slate-200">
                Live Chat
            </div>
        </div>

        <div id="chatMessages" class="flex-1 p-6 lg:p-8 overflow-y-auto bg-slate-50 flex flex-col gap-4">
            </div>

        <div class="p-5 lg:p-6 bg-white border-t border-slate-100 shrink-0">
            <form id="studentChatForm" onsubmit="sendStudentMessage(event)" class="flex items-center gap-3">
                <input type="text" id="studentChatInput" placeholder="Type your message here..." required autocomplete="off" <?= !$admin_id ? 'disabled' : '' ?>
                    class="flex-1 bg-slate-50 border border-slate-200 text-slate-900 rounded-xl px-5 py-4 text-sm font-medium outline-none focus:border-blue-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <button type="submit" <?= !$admin_id ? 'disabled' : '' ?> class="h-12 lg:h-14 px-8 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-black text-xs uppercase tracking-widest transition-all shadow-md disabled:opacity-50 disabled:cursor-not-allowed hover:-translate-y-0.5 active:translate-y-0">
                    Send &rarr;
                </button>
            </form>
        </div>
    </div>
</main>

<script>
    const adminId = <?= $admin_id ?>;
    const myStudentId = <?= $student_id ?>;
    const chatMessagesDiv = document.getElementById('chatMessages');

    function loadStudentMessages() {
        if (!adminId) return;

        fetch(`../actions/chat_handler.php?action=fetch&other_user_id=${adminId}`)
        .then(response => response.json())
        .then(data => {
            chatMessagesDiv.innerHTML = '<div class="text-center text-[10px] font-bold text-slate-400 uppercase tracking-widest my-4 border-b border-slate-200 pb-2">Conversation Started</div>';
            
            data.forEach(msg => {
                const isMe = (msg.SenderID == myStudentId);
                const bubble = document.createElement('div');
                
                if (isMe) {
                    // Student's message (Blue, Right side)
                    bubble.className = 'bg-blue-600 text-white p-4 lg:p-5 rounded-[1.5rem] rounded-tr-sm self-end mb-2 max-w-[85%] sm:max-w-[70%] text-sm font-medium shadow-md';
                } else {
                    // Admin's message (White/Gray, Left side)
                    bubble.className = 'bg-white border border-slate-200 text-slate-800 p-4 lg:p-5 rounded-[1.5rem] rounded-tl-sm self-start mb-2 max-w-[85%] sm:max-w-[70%] text-sm font-medium shadow-sm';
                }
                
                bubble.innerText = msg.MessageText;
                chatMessagesDiv.appendChild(bubble);
            });
            
            // Keep scrolled to bottom
            chatMessagesDiv.scrollTop = chatMessagesDiv.scrollHeight;
        })
        .catch(error => console.error('Chat Error:', error));
    }

    function sendStudentMessage(e) {
        e.preventDefault();
        const input = document.getElementById('studentChatInput');
        const text = input.value.trim();
        
        if (!text || !adminId) return;

        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('receiver_id', adminId);
        formData.append('message', text);

        fetch('../actions/chat_handler.php', {
            method: 'POST',
            body: formData
        }).then(() => {
            input.value = ''; // Clear input
            loadStudentMessages(); // Reload instantly
        });
    }

    // Ping the server every 2 seconds for new messages from the admin!
    if (adminId) {
        setInterval(loadStudentMessages, 2000); 
        loadStudentMessages(); // Load immediately on page open
    }
</script>

<?php include '../includes/footer.php'; ?>