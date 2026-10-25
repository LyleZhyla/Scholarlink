<?php
// Ensure session variables are accessible for the greeting
$user_role = $_SESSION['role'] ?? 'Guest';
$first_name = $_SESSION['first_name'] ?? 'Guest';

$human_mode = !empty($_SESSION['human_mode']);

// ✨ DYNAMIC ROUTING: Auto-detects folder depth so the API path always works!
$base_path = file_exists('actions/process_chat.php') ? '' : '../';

// ✨ DYNAMIC QUICK REPLIES BY ROLE
$quick_chips = [];

switch ($user_role) {
    case 'Student':
        $quick_chips = [
            ['label' => '🚀 Application Steps', 'msg' => 'How do I apply for a scholarship? Please give me the steps.', 'force_ai' => true, 'style' => 'bg-emerald-50 hover:bg-emerald-600 text-emerald-700 hover:text-white border-emerald-200'],
            ['label' => '📋 Missing Docs', 'msg' => 'What are my missing documents?', 'force_ai' => true, 'style' => 'bg-slate-100 hover:bg-slate-900 text-slate-700 hover:text-white border-slate-200'],
            ['label' => '🎯 Matched Grants', 'msg' => 'What scholarships match my course and GPA?', 'force_ai' => true, 'style' => 'bg-blue-50 hover:bg-blue-600 text-blue-700 hover:text-white border-blue-200'],
            ['label' => '👨‍💼 Talk to Admin', 'msg' => 'I want to talk to a human admin please.', 'force_ai' => false, 'style' => 'bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white border-amber-200']
        ];
        break;

    case 'Internal_Admin':
        $quick_chips = [
            ['label' => '📋 Pending Applications', 'msg' => 'How do I review and shortlist pending student applications?', 'force_ai' => true, 'style' => 'bg-emerald-50 hover:bg-emerald-600 text-emerald-700 hover:text-white border-emerald-200'],
            ['label' => '📊 OSSD Reports', 'msg' => 'Where can I export Annex 1 and sponsor reports?', 'force_ai' => true, 'style' => 'bg-blue-50 hover:bg-blue-600 text-blue-700 hover:text-white border-blue-200'],
            ['label' => '💬 Student Inbox', 'msg' => 'Show me how to reply to student messages in my inbox.', 'force_ai' => true, 'style' => 'bg-slate-100 hover:bg-slate-900 text-slate-700 hover:text-white border-slate-200']
        ];
        break;

    case 'External_Admin':
        $quick_chips = [
            ['label' => '⭐ Score Applicants', 'msg' => 'How do I evaluate and score applicants under my grant?', 'force_ai' => true, 'style' => 'bg-purple-50 hover:bg-purple-600 text-purple-700 hover:text-white border-purple-200'],
            ['label' => '📂 Document Vault', 'msg' => 'How do I verify student document uploads?', 'force_ai' => true, 'style' => 'bg-blue-50 hover:bg-blue-600 text-blue-700 hover:text-white border-blue-200'],
            ['label' => '➕ Launch Program', 'msg' => 'How do I create and customize a scholarship program?', 'force_ai' => true, 'style' => 'bg-emerald-50 hover:bg-emerald-600 text-emerald-700 hover:text-white border-emerald-200']
        ];
        break;

    case 'Super_Admin':
        $quick_chips = [
            ['label' => '📜 Audit Logs', 'msg' => 'Where can I track administrative actions and filter audit logs?', 'force_ai' => true, 'style' => 'bg-slate-100 hover:bg-slate-900 text-slate-700 hover:text-white border-slate-200'],
            ['label' => '🛡️ Security & 2FA', 'msg' => 'How do I change session timeouts and strict password policies?', 'force_ai' => true, 'style' => 'bg-red-50 hover:bg-red-600 text-red-700 hover:text-white border-red-200'],
            ['label' => '💾 Backups', 'msg' => 'How do I manage database backups and restore points?', 'force_ai' => true, 'style' => 'bg-blue-50 hover:bg-blue-600 text-blue-700 hover:text-white border-blue-200']
        ];
        break;

    case 'Guest':
    default:
        // No "Talk to Admin" for unauthenticated guests
        $quick_chips = [
            ['label' => '🎓 What is ScholarLink?', 'msg' => 'What is ScholarLink and what scholarships are offered at TAU?', 'force_ai' => true, 'style' => 'bg-emerald-50 hover:bg-emerald-600 text-emerald-700 hover:text-white border-emerald-200'],
            ['label' => '📝 How to Register', 'msg' => 'How can a new student register an account?', 'force_ai' => true, 'style' => 'bg-blue-50 hover:bg-blue-600 text-blue-700 hover:text-white border-blue-200'],
            ['label' => '📄 Required Documents', 'msg' => 'What general documents are required to apply for scholarships?', 'force_ai' => true, 'style' => 'bg-slate-100 hover:bg-slate-900 text-slate-700 hover:text-white border-slate-200']
        ];
        break;
}
?>

<style>
    /* Custom Scrollbar for a cleaner look */
    .custom-scrollbar::-webkit-scrollbar { height: 4px; width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    .custom-scrollbar { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
    
    /* Smooth Message Entry Animation */
    @keyframes slideUpFade {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .animate-msg { animation: slideUpFade 0.3s ease-out forwards; }

    /* Subtle Floating Pulse for ISKO Launcher */
    @keyframes floatSlow {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-4px); }
    }
    .isko-float { animation: floatSlow 3.5s ease-in-out infinite; }
</style>

<!-- ✨ UPGRADED ISKO LAUNCHER BUTTON (Fixed Online Dot Position) -->
<button id="chatbotToggle" onclick="toggleChatbot()" class="isko-float fixed bottom-6 right-6 bg-slate-900 hover:bg-emerald-600 text-white p-2 pr-6 rounded-full shadow-2xl shadow-slate-900/40 border-2 border-emerald-500/40 flex items-center gap-3.5 hover:scale-105 active:scale-95 transition-all duration-300 z-50 group">
    <div class="relative w-14 h-14 rounded-full bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center shadow-md shrink-0">
        <!-- 🐃 Custom Tamaraw Vector Icon -->
        <svg class="w-10 h-10 text-white group-hover:scale-110 transition-transform" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M50 12L15 26L50 40L85 26L50 12Z" fill="#0F172A" stroke="#34D399" stroke-width="3" stroke-linejoin="round"/>
            <path d="M73 31.5V47C73 47 73 55 50 55C27 55 27 47 27 47V31.5" fill="#334155" stroke="#0F172A" stroke-width="4"/>
            <path d="M30 42C20 35 15 22 25 22C32 22 36 32 38 40" stroke="#0F172A" stroke-width="7" stroke-linecap="round"/>
            <path d="M30 35C24 30 21 24 25 24C28 24 31 30 34 35" stroke="#34D399" stroke-width="2" stroke-linecap="round"/>
            <path d="M70 42C80 35 85 22 75 22C68 22 64 32 62 40" stroke="#0F172A" stroke-width="7" stroke-linecap="round"/>
            <path d="M70 35C76 30 79 24 75 24C72 24 69 30 66 35" stroke="#34D399" stroke-width="2" stroke-linecap="round"/>
            <path d="M22 52C16 50 12 56 16 62C19 66 26 62 27 58" fill="#334155" stroke="#0F172A" stroke-width="5" stroke-linejoin="round"/>
            <path d="M78 52C84 50 88 56 84 62C81 66 74 62 73 58" fill="#334155" stroke="#0F172A" stroke-width="5" stroke-linejoin="round"/>
            <path d="M28 38C28 38 31 68 50 68C69 68 72 38 72 38C72 38 65 32 50 32C35 32 28 38 28 38Z" fill="#334155" stroke="#0F172A" stroke-width="5"/>
            <circle cx="39" cy="48" r="8" fill="white" stroke="#0F172A" stroke-width="5"/>
            <circle cx="39" cy="48" r="3" fill="#0F172A"/>
            <circle cx="61" cy="48" r="8" fill="white" stroke="#0F172A" stroke-width="5"/>
            <circle cx="61" cy="48" r="3" fill="#0F172A"/>
            <path d="M34 58C34 58 36 78 50 78C64 78 66 58 66 58C66 58 60 54 50 54C40 54 34 58 34 58Z" fill="#475569" stroke="#0F172A" stroke-width="5"/>
            <ellipse cx="43" cy="66" rx="3" ry="2" fill="#0F172A"/>
            <ellipse cx="57" cy="66" rx="3" ry="2" fill="#0F172A"/>
        </svg>
        <!-- Perfectly Positioned Online Pulse Dot -->
        <span class="absolute top-1 right-1 w-3.5 h-3.5 bg-emerald-400 border-2 border-slate-900 rounded-full shadow-md"></span>
    </div>
    <div class="text-left pr-1">
        <div class="flex items-center gap-1.5">
            <span class="font-black text-xs uppercase tracking-wider text-white">Ask ISKO</span>
            <span class="px-1.5 py-0.5 text-[8px] font-black uppercase tracking-widest bg-emerald-500/20 text-emerald-300 rounded-md border border-emerald-400/30">AI</span>
        </div>
        <p class="text-[10px] font-medium text-slate-300 group-hover:text-emerald-100 leading-tight">Scholarship Mentor</p>
    </div>
</button>

<!-- ✨ UPGRADED ISKO CHAT WINDOW -->
<div id="chatWindow" class="fixed bottom-24 right-6 w-80 sm:w-96 bg-white rounded-[2rem] shadow-2xl border border-slate-200/90 flex flex-col z-50 transform scale-0 opacity-0 pointer-events-none transition-all duration-300 origin-bottom-right overflow-hidden" style="height: 600px; max-height: 85vh;">
    
    <!-- Header -->
    <div class="bg-slate-900 p-4 px-5 flex justify-between items-center text-white shadow-md shrink-0 relative overflow-hidden border-b border-emerald-500/30">
        <div class="absolute inset-0 bg-gradient-to-r from-emerald-600/20 via-transparent to-transparent pointer-events-none"></div>
        
        <div class="flex items-center gap-3.5 relative z-10">
            <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center shadow-lg shadow-emerald-900/40 border border-white/15 shrink-0">
                <!-- 🐃 Custom Tamaraw Vector Icon -->
                <svg class="w-9 h-9 text-white" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M50 12L15 26L50 40L85 26L50 12Z" fill="#0F172A" stroke="#34D399" stroke-width="3" stroke-linejoin="round"/>
                    <path d="M73 31.5V47C73 47 73 55 50 55C27 55 27 47 27 47V31.5" fill="#334155" stroke="#0F172A" stroke-width="4"/>
                    <path d="M30 42C20 35 15 22 25 22C32 22 36 32 38 40" stroke="#0F172A" stroke-width="7" stroke-linecap="round"/>
                    <path d="M70 42C80 35 85 22 75 22C68 22 64 32 62 40" stroke="#0F172A" stroke-width="7" stroke-linecap="round"/>
                    <path d="M22 52C16 50 12 56 16 62C19 66 26 62 27 58" fill="#334155" stroke="#0F172A" stroke-width="5" stroke-linejoin="round"/>
                    <path d="M78 52C84 50 88 56 84 62C81 66 74 62 73 58" fill="#334155" stroke="#0F172A" stroke-width="5" stroke-linejoin="round"/>
                    <path d="M28 38C28 38 31 68 50 68C69 68 72 38 72 38C72 38 65 32 50 32C35 32 28 38 28 38Z" fill="#334155" stroke="#0F172A" stroke-width="5"/>
                    <circle cx="39" cy="48" r="8" fill="white" stroke="#0F172A" stroke-width="5"/>
                    <circle cx="39" cy="48" r="3" fill="#0F172A"/>
                    <circle cx="61" cy="48" r="8" fill="white" stroke="#0F172A" stroke-width="5"/>
                    <circle cx="61" cy="48" r="3" fill="#0F172A"/>
                    <path d="M34 58C34 58 36 78 50 78C64 78 66 58 66 58C66 58 60 54 50 54C40 54 34 58 34 58Z" fill="#475569" stroke="#0F172A" stroke-width="5"/>
                    <ellipse cx="43" cy="66" rx="3" ry="2" fill="#0F172A"/>
                    <ellipse cx="57" cy="66" rx="3" ry="2" fill="#0F172A"/>
                </svg>
                <!-- Perfectly Positioned Online Pulse Dot -->
                <span class="absolute top-0.5 right-0.5 w-3.5 h-3.5 bg-emerald-400 border-2 border-slate-900 rounded-full shadow-md"></span>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="font-black text-base tracking-tight text-white">ISKO</h3>
                    <span class="px-2 py-0.5 rounded-full bg-emerald-500/20 border border-emerald-400/30 text-emerald-300 text-[9px] font-black uppercase tracking-widest">Online</span>
                </div>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">TAU ScholarLink AI Mentor</p>
            </div>
        </div>

        <div class="flex items-center gap-1.5 relative z-10">
            <button onclick="clearChatHistory()" class="w-8 h-8 rounded-xl bg-white/5 hover:bg-red-500/20 text-slate-300 hover:text-red-300 flex items-center justify-center transition-all" title="Clear Conversation">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
            </button>
            <button onclick="downloadTranscript()" class="w-8 h-8 rounded-xl bg-white/5 hover:bg-emerald-500/20 text-slate-300 hover:text-emerald-300 flex items-center justify-center transition-all" title="Download Chat Transcript">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
            </button>
            <button onclick="toggleChatbot()" class="w-8 h-8 rounded-xl bg-white/5 hover:bg-white/15 text-slate-300 hover:text-white flex items-center justify-center transition-all" title="Minimize">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </div>

    <!-- ✨ HUMAN CHAT BANNER -->
    <div id="humanBanner" class="<?= $human_mode ? '' : 'hidden' ?> bg-amber-50 border-b border-amber-200 px-4 py-2 flex items-center justify-between gap-2 shrink-0">
        <span class="text-[10px] font-black uppercase tracking-widest text-amber-700">👨‍💼 Chatting with the Scholarship Office</span>
        <button onclick="resumeAI()" class="text-[10px] font-black uppercase tracking-widest bg-slate-900 hover:bg-emerald-600 text-white px-3 py-1.5 rounded-full transition-all active:scale-95 whitespace-nowrap">Back to ISKO</button>
    </div>

    <!-- Chat Messages Box -->
    <div id="chatBox" class="flex-1 p-4 overflow-y-auto custom-scrollbar flex flex-col gap-4 bg-slate-50/80">
        <!-- Default Greeting -->
        <div class="flex gap-3 animate-msg w-full justify-start">
            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center text-white shadow-md shadow-emerald-600/20 shrink-0 text-sm">
                🐃
            </div>
            <div class="bg-white border border-slate-200/80 border-l-4 border-l-emerald-500 p-3.5 rounded-2xl rounded-tl-sm text-sm text-slate-700 shadow-sm flex flex-col gap-1 items-start w-full max-w-[85%]">
                <div class="font-black text-[10px] uppercase tracking-widest text-emerald-600 mb-0.5">ISKO • ScholarLink AI</div>
                <div class="leading-relaxed">Kumusta, <strong><?= htmlspecialchars($first_name) ?></strong>! 👋 I'm <strong>ISKO</strong>, your TAU scholarship buddy. I can match you with programs, check your missing documents, or guide you step-by-step. How can I help you today?</div>
            </div>
        </div>

        <!-- ✨ MEMORY HYDRATION LOOP -->
        <?php
        if (isset($_SESSION['chat_history']) && is_array($_SESSION['chat_history'])) {
            foreach ($_SESSION['chat_history'] as$msg) {
                $role =$msg['role'];
                $text = htmlspecialchars($msg['parts'][0]['text'] ?? '');

                if ($role === 'user') {
                    $clean_text = str_replace("[User Attached an Image/Document] ", "", $text);
                    echo '
                    <div class="flex gap-3 animate-msg w-full justify-end">
                        <div class="bg-slate-900 text-white p-3.5 rounded-2xl rounded-tr-sm text-sm shadow-sm flex flex-col gap-1 items-end max-w-[85%]">
                            <div class="leading-relaxed">' . nl2br($clean_text) . '</div>
                        </div>
                        <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-800 border border-emerald-200 flex items-center justify-center shrink-0 font-black text-xs uppercase shadow-sm">
                            ' . substr($first_name, 0, 1) . '
                        </div>
                    </div>';
                } else if ($role === 'model') {$text = str_replace('/ScholarLink/', '/', $text);$formattedText = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);$formattedText = preg_replace('/\[GOTO:(.*?)\]/', '<button onclick="window.location.href=\'$1\'" class="mt-2.5 text-[10px] font-black uppercase tracking-widest bg-emerald-600 text-white hover:bg-slate-900 px-3.5 py-2.5 rounded-xl transition-all shadow-sm active:scale-95 flex items-center justify-center gap-1.5 w-full">Open Page <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg></button>', $formattedText);
                    echo '
                    <div class="flex gap-3 animate-msg w-full justify-start">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center text-white shadow-md shadow-emerald-600/20 shrink-0 text-sm">
                            🐃
                        </div>
                        <div class="bg-white border border-slate-200/80 border-l-4 border-l-emerald-500 p-4 rounded-2xl rounded-tl-sm text-sm text-slate-700 shadow-sm flex flex-col gap-1 items-start w-full max-w-[85%]">
                            <div class="font-black text-[10px] uppercase tracking-widest text-emerald-600 mb-0.5">ISKO • ScholarLink AI</div>
                            <div class="leading-relaxed whitespace-pre-line">' . $formattedText . '</div>
                        </div>
                    </div>';
                }
            }
        }
        ?>
    </div>

    <!-- ✨ AI SUGGESTION CHIPS (ROLE-AWARE) -->
    <div class="px-3 py-2 bg-white flex gap-2 overflow-x-auto custom-scrollbar shrink-0 border-t border-slate-100">
        <?php foreach ($quick_chips as $chip): ?>
            <button onclick="sendQuickReply(<?= htmlspecialchars(json_encode($chip['msg']), ENT_QUOTES) ?>, <?= $chip['force_ai'] ? 'true' : 'false' ?>)" 
                    class="<?= $chip['style'] ?> border px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest whitespace-nowrap transition-all shadow-sm active:scale-95">
                <?= htmlspecialchars($chip['label']) ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- Input Area -->
    <div class="p-3 bg-white shrink-0 border-t border-slate-100">
        <div class="flex gap-2 items-end bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-2xl focus-within:border-emerald-500 focus-within:bg-white focus-within:ring-4 focus-within:ring-emerald-500/10 transition-all">
            <textarea id="chatInput" rows="1" class="flex-1 bg-transparent border-none text-sm py-1.5 focus:ring-0 resize-none custom-scrollbar outline-none text-slate-800 placeholder:text-slate-400 font-medium" placeholder="Ask ISKO anything..."></textarea>
            <button onclick="sendMessage()" class="bg-emerald-600 hover:bg-slate-900 text-white w-9 h-9 rounded-xl flex items-center justify-center shrink-0 transition-all mb-0.5 shadow-md shadow-emerald-600/20 active:scale-95">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
            </button>
        </div>
    </div>
</div>

<script>
    const chatWindow = document.getElementById('chatWindow');
    const chatInput = document.getElementById('chatInput');
    const chatBox = document.getElementById('chatBox');
    const basePath = '<?= $base_path ?>';

    function toggleChatbot() {
        if (chatWindow.classList.contains('scale-0')) {
            chatWindow.classList.remove('scale-0', 'opacity-0', 'pointer-events-none');
            chatWindow.classList.add('scale-100', 'opacity-100', 'pointer-events-auto');
            setTimeout(() => {
                chatInput.focus();
                chatBox.scrollTop = chatBox.scrollHeight;
            }, 300);
        } else {
            chatWindow.classList.add('scale-0', 'opacity-0', 'pointer-events-none');
            chatWindow.classList.remove('scale-100', 'opacity-100', 'pointer-events-auto');
        }
    }

    // ✨ QUICK REPLY TRIGGER
    window.sendQuickReply = function(text, forceAi = false) {
        chatInput.value = text;
        sendMessage(forceAi);
    };

    chatInput.addEventListener('keypress', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    function sendMessage(forceAi = false) {
        const message = chatInput.value.trim();
        if (!message) return;

        // 1. Render User Message
        const userDiv = document.createElement('div');
        userDiv.className = "flex gap-3 animate-msg w-full justify-end";
        userDiv.innerHTML = `
            <div class="bg-slate-900 text-white p-3.5 rounded-2xl rounded-tr-sm text-sm shadow-sm flex flex-col gap-1 items-end max-w-[85%]">
                <div class="leading-relaxed">${message.replace(/\n/g, '<br>')}</div>
            </div>
            <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-800 border border-emerald-200 flex items-center justify-center shrink-0 font-black text-xs uppercase shadow-sm">
                <?= substr($first_name, 0, 1) ?>
            </div>
        `;
        chatBox.appendChild(userDiv);
        chatInput.value = '';
        chatInput.style.height = 'auto';
        chatBox.scrollTop = chatBox.scrollHeight;

        // 2. Render Loading Animation
        const typingIndicator = document.createElement('div');
        typingIndicator.className = "flex gap-3 animate-msg w-full justify-start";
        typingIndicator.innerHTML = `
            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center text-white shadow-md shrink-0 text-sm">
                🐃
            </div>
            <div class="bg-white border border-slate-200/80 p-3.5 rounded-2xl rounded-tl-sm text-xs font-bold text-slate-500 shadow-sm flex items-center gap-2.5 max-w-[85%]">
                <span>ISKO is thinking</span>
                <span class="flex gap-1">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-bounce" style="animation-delay: 0s"></span>
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></span>
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-bounce" style="animation-delay: 0.4s"></span>
                </span>
            </div>
        `;
        chatBox.appendChild(typingIndicator);
        chatBox.scrollTop = chatBox.scrollHeight;

        // 3. Send to Server via Fetch
        const formData = new FormData();
        formData.append('message', message);
        formData.append('current_page', window.location.pathname.split('/').pop());
        if (forceAi) formData.append('force_ai', '1');

        fetch(basePath + 'actions/process_chat.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            typingIndicator.remove();

            setHumanMode(!!data.human_mode);

            // Message was delivered to the human admin: no bot bubble, just a small delivery tick
            if (data.silent) {
                const sentBubble = userDiv.querySelector('.bg-slate-900');
                if (sentBubble) {
                    sentBubble.insertAdjacentHTML('beforeend', '<div class="text-[9px] font-bold uppercase tracking-widest text-emerald-300">✓ Sent to Scholarship Office</div>');
                }
                chatBox.scrollTop = chatBox.scrollHeight;
                return;
            }

            let botReplyText = data.reply || '';
            let needsHandoff = false;

            if (botReplyText.includes('[ACTION:TRANSFER_TO_HUMAN]')) {
                needsHandoff = true;
                botReplyText = botReplyText.replace('[ACTION:TRANSFER_TO_HUMAN]', '').trim();
            }

            const msgDiv = document.createElement('div');
            msgDiv.className = "flex gap-3 animate-msg w-full justify-start";
            
            let formattedBotText = botReplyText.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            formattedBotText = formattedBotText.replace(/\/ScholarLink\//gi, '/');

            // ✨ BEAUTIFIED LINK BUTTONS FOR NAVIGATION
            formattedBotText = formattedBotText.replace(/\[GOTO:(.*?)\]/g, '<button onclick="window.location.href=\'$1\'" class="mt-2.5 text-[10px] font-black uppercase tracking-widest bg-emerald-600 text-white hover:bg-slate-900 px-3.5 py-2.5 rounded-xl transition-all shadow-sm active:scale-95 flex items-center justify-center gap-1.5 w-full">Open Page <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg></button>');
            
            msgDiv.innerHTML = `
                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center text-white shadow-md shadow-emerald-600/20 shrink-0 text-sm">
                    🐃
                </div>
                <div class="bg-white border border-slate-200/80 border-l-4 border-l-emerald-500 p-4 rounded-2xl rounded-tl-sm text-sm text-slate-700 shadow-sm flex flex-col gap-1 items-start w-full max-w-[85%]">
                    <div class="font-black text-[10px] uppercase tracking-widest text-emerald-600 mb-0.5">ISKO • ScholarLink AI</div>
                    <div class="leading-relaxed whitespace-pre-line"></div>
                </div>
            `;
            chatBox.appendChild(msgDiv);
            chatBox.scrollTop = chatBox.scrollHeight;

            // Typewriter Animation Engine
            const contentDiv = msgDiv.querySelector('.leading-relaxed');
            let index = 0;
            let currentHTML = "";
            
            function typeText() {
                if (index < formattedBotText.length) {
                    if (formattedBotText.charAt(index) === '<') {
                        let tag = "";
                        while (formattedBotText.charAt(index) !== '>' && index < formattedBotText.length) {
                            tag += formattedBotText.charAt(index);
                            index++;
                        }
                        tag += '>';
                        currentHTML += tag;
                        index++;
                    } else {
                        currentHTML += formattedBotText.charAt(index);
                        index++;
                    }
                    
                    contentDiv.innerHTML = currentHTML; 
                    chatBox.scrollTop = chatBox.scrollHeight;
                    setTimeout(typeText, 5); 
                } else {
                    if (needsHandoff) {
                        setTimeout(() => {
                            executeHumanHandoff(message); 
                        }, 1200); 
                    }
                }
            }
            typeText();
        })
        .catch(err => {
            typingIndicator.remove();
            console.error("Chatbot communication error: ", err);
        });
    }

    // ✨ Human-chat banner + "Back to ISKO"
    function setHumanMode(on) {
        const banner = document.getElementById('humanBanner');
        if (banner) banner.classList.toggle('hidden', !on);
    }

    function resumeAI() {
        const formData = new FormData();
        formData.append('action', 'resume_ai');

        fetch(basePath + 'actions/process_chat.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(() => {
            setHumanMode(false);
            const msgDiv = document.createElement('div');
            msgDiv.className = "flex gap-3 animate-msg w-full justify-start";
            msgDiv.innerHTML = `
                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center text-white shadow-md shadow-emerald-600/20 shrink-0 text-sm">
                    🐃
                </div>
                <div class="bg-white border border-slate-200/80 border-l-4 border-l-emerald-500 p-4 rounded-2xl rounded-tl-sm text-sm text-slate-700 shadow-sm flex flex-col gap-1 items-start w-full max-w-[85%]">
                    <div class="font-black text-[10px] uppercase tracking-widest text-emerald-600 mb-0.5">ISKO • ScholarLink AI</div>
                    <div class="leading-relaxed">I'm back! What would you like help with?</div>
                </div>
            `;
            chatBox.appendChild(msgDiv);
            chatBox.scrollTop = chatBox.scrollHeight;
        })
        .catch(err => console.error("Resume AI error: ", err));
    }

    // ✨ Finds the Internal Admin to talk to (current thread first, otherwise the first admin)
    let supportAdminId = null;
    function getSupportAdminId() {
        if (supportAdminId) return Promise.resolve(supportAdminId);
        return fetch(basePath + 'actions/chat_handler.php?action=get_admin')
            .then(res => res.json())
            .then(d => {
                if (d && d.admin_id) supportAdminId = d.admin_id;
                return supportAdminId;
            });
    }

    function executeHumanHandoff(userQuery) {
        const currentUserRole = '<?= $user_role ?>';

        // Block guests from escalating to human admin
        if (currentUserRole === 'Guest') {
            const msgDiv = document.createElement('div');
            msgDiv.className = "flex gap-3 animate-msg w-full justify-start";
            msgDiv.innerHTML = `
                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center text-white shadow-md shrink-0 text-sm">
                    🐃
                </div>
                <div class="bg-white border border-slate-200/80 border-l-4 border-l-amber-500 p-4 rounded-2xl rounded-tl-sm text-sm text-slate-700 shadow-sm flex flex-col gap-1 items-start w-full max-w-[85%]">
                    <div class="font-black text-[10px] uppercase tracking-widest text-amber-600 mb-0.5">Authentication Required</div>
                    <div class="leading-relaxed">Direct messaging with the Scholarship Office is available only for registered TAU students. Please <a href="${basePath}student_login.php" class="text-emerald-600 font-bold underline">log in</a> or create an account to start a live support ticket.</div>
                </div>
            `;
            chatBox.appendChild(msgDiv);
            chatBox.scrollTop = chatBox.scrollHeight;
            return;
        }

        getSupportAdminId().then(adminId => {
            if (!adminId) throw new Error('No Internal_Admin account found');

            const formData = new FormData();
            formData.append('action', 'send');
            formData.append('receiver_id', adminId);
            formData.append('message', "⚠️ ISKO Auto-Escalate: " + userQuery);

            return fetch(basePath + 'actions/chat_handler.php', {
                method: 'POST',
                body: formData
            });
        })
        .then(response => response.json())
        .then(data => {
            const msgDiv = document.createElement('div');
            msgDiv.className = "flex gap-3 animate-msg w-full justify-start";
            msgDiv.innerHTML = `
                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-white shadow-md shrink-0 text-sm">
                    👨‍💼
                </div>
                <div class="bg-amber-50 border border-amber-200 p-4 rounded-2xl rounded-tl-sm text-sm text-slate-700 shadow-sm flex flex-col gap-1 items-start w-full max-w-[85%]">
                    <div class="font-black text-[10px] uppercase tracking-widest text-amber-600 mb-0.5">Support Office Notified</div>
                    <div class="leading-relaxed">I have forwarded your concern directly to the Office of Student Affairs. Your next messages will go straight to them. Type "Isko" anytime to bring me back!</div>
                </div>
            `;
            chatBox.appendChild(msgDiv);
            chatBox.scrollTop = chatBox.scrollHeight;
        })
        .catch(err => {
            console.error("Handoff error: ", err);
        });
    }

    function downloadTranscript() {
        let transcript = "ISKO (SCHOLARLINK AI) - CHAT TRANSCRIPT\n";
        transcript += "Date: " + new Date().toLocaleString() + "\n\n";
        transcript += "=========================================\n\n";

        const messages = chatBox.querySelectorAll('.flex.gap-3');
        
        if (messages.length === 0) {
            alert("Your chat is empty! Talk to ISKO first before downloading.");
            return;
        }

        messages.forEach(msg => {
            const isUser = msg.querySelector('.bg-slate-900') !== null;
            const sender = isUser ? "YOU" : "ISKO (SCHOLARLINK AI)";
            if (msg.querySelector('.animate-bounce')) return;

            let textElement = msg.querySelector('.leading-relaxed, .text-sm');
            if(textElement) {
                let text = textElement.innerText.trim();
                transcript += `[${sender}]:\n${text}\n\n`;
                transcript += "-----------------------------------------\n\n";
            }
        });

        const blob = new Blob([transcript], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        
        a.setAttribute('hidden', '');
        a.setAttribute('href', url);
        a.setAttribute('download', 'ISKO_ScholarLink_Transcript.txt');
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    // ✨ CLEAR CHAT FUNCTION
    function clearChatHistory() {
        if (!confirm("Are you sure you want to delete this conversation? ISKO will forget your previous context.")) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'clear_chat');

        fetch(basePath + 'actions/process_chat.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                setHumanMode(false);
                chatBox.innerHTML = `
                    <div class="flex gap-3 animate-msg w-full justify-start">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center text-white shadow-md shadow-emerald-600/20 shrink-0 text-sm">
                            🐃
                        </div>
                        <div class="bg-white border border-slate-200/80 border-l-4 border-l-emerald-500 p-3.5 rounded-2xl rounded-tl-sm text-sm text-slate-700 shadow-sm flex flex-col gap-1 items-start w-full max-w-[85%]">
                            <div class="font-black text-[10px] uppercase tracking-widest text-emerald-600 mb-0.5">ISKO • ScholarLink AI</div>
                            <div class="leading-relaxed">Kumusta, <strong><?= htmlspecialchars($first_name) ?></strong>! 👋 I'm <strong>ISKO</strong>, your TAU scholarship buddy. I can match you with programs, check your missing documents, or guide you step-by-step. How can I help you today?</div>
                        </div>
                    </div>
                `;
            }
        })
        .catch(err => console.error("Error clearing chat:", err));
    }

    // ✨ LIVE INCOMING ADMIN REPLY FETCHER INTO ISKO
    // The last seen admin message id is kept in sessionStorage so old replies are not re-rendered
    // on every page load. On the very first run it only records the current position.
    const POLL_KEY = 'isko_last_admin_msg_id';
    let lastCheckedMessageId = 0;
    let pollBaselineDone = false;
    let pollBusy = false;

    try {
        const stored = sessionStorage.getItem(POLL_KEY);
        if (stored !== null) {
            lastCheckedMessageId = parseInt(stored, 10) || 0;
            pollBaselineDone = true;
        }
    } catch (e) {}

    function escapeHtml(str) {
        return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }

    function renderAdminMessage(text) {
        const adminMsgDiv = document.createElement('div');
        adminMsgDiv.className = "flex gap-3 animate-msg w-full justify-start";
        adminMsgDiv.innerHTML = `
            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center text-white shadow-md shrink-0 text-sm">
                👨‍💼
            </div>
            <div class="bg-white border border-blue-100 border-l-4 border-l-blue-600 p-4 rounded-2xl rounded-tl-sm text-sm text-slate-700 shadow-sm flex flex-col gap-1 items-start w-full max-w-[85%]">
                <div class="font-black text-[10px] uppercase tracking-widest text-blue-600 mb-0.5">Office of Student Affairs (Admin)</div>
                <div class="leading-relaxed whitespace-pre-line">${escapeHtml(text)}</div>
            </div>
        `;
        chatBox.appendChild(adminMsgDiv);
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    function pollAdminReplies() {
        if (pollBusy) return;
        pollBusy = true;

        getSupportAdminId()
        .then(adminId => {
            if (!adminId) return;
            return fetch(basePath + 'actions/chat_handler.php?action=fetch&other_user_id=' + adminId)
            .then(res => res.json())
            .then(data => {
                if (!Array.isArray(data)) return;

                const fromAdmin = data.filter(m => m.SenderID == adminId);
                let maxId = lastCheckedMessageId;

                fromAdmin.forEach(msg => {
                    const id = parseInt(msg.MessageID, 10);
                    if (id > lastCheckedMessageId && pollBaselineDone) {
                        renderAdminMessage(msg.MessageText);
                        setHumanMode(true);
                    }
                    if (id > maxId) maxId = id;
                });

                lastCheckedMessageId = maxId;
                pollBaselineDone = true;
                try { sessionStorage.setItem(POLL_KEY, String(maxId)); } catch (e) {}
            });
        })
        .catch(err => console.error("Admin chat sync error:", err))
        .finally(() => { pollBusy = false; });
    }

    // Check once on load, then every 3 seconds
    pollAdminReplies();
    setInterval(pollAdminReplies, 3000);
</script>