<?php
session_start();

// --- THE REDIRECT GATEKEEPER ---
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if ($_SESSION['role'] === 'Super_Admin') {
        header("Location: super_admin/dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'Internal_Admin') {
        header("Location: internal_admin/dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'External_Admin') {
        header("Location: external_admin/dashboard.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - ScholarLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 md:p-10 relative">

    <div class="bg-white w-full max-w-7xl rounded-[1.5rem] md:rounded-[2.5rem] shadow-2xl shadow-slate-200/50 border border-slate-100 flex overflow-hidden min-h-[85vh] md:min-h-[80vh]">
        
        <div class="w-1/2 bg-slate-900 p-16 flex flex-col justify-between relative overflow-hidden hidden md:flex">
            <img src="https://images.unsplash.com/photo-1541339907198-e08756defefe?q=80&w=1470" class="absolute inset-0 w-full h-full object-cover opacity-10 grayscale">
            <div class="absolute inset-0 bg-gradient-to-b from-slate-800/80 to-slate-950 z-10"></div>
            <div class="relative z-20">
                <a href="index.php"><img src="assets/img/tau_logo.png" alt="TAU Logo" class="h-28 w-auto mb-10"></a>
                <div class="space-y-3">
                    <span class="inline-block px-4 py-1 rounded-full bg-slate-800 border border-slate-700 text-slate-300 text-xs font-bold uppercase tracking-widest">Administrative Portal</span>
                    <h1 class="text-6xl font-black text-white leading-tight tracking-tighter">Staff<br>Access.</h1>
                    <p class="text-slate-300 text-lg max-w-md font-medium">ScholarLink Secure Management Command Center.</p>
                </div>
            </div>
            <div class="relative z-20 flex items-center justify-between border-t border-white/10 pt-8 mt-16">
                <p class="text-sm font-medium text-white/70">Tarlac Agricultural University Scholarship Management System</p>
                <p class="text-[10px] font-black text-white/50 uppercase tracking-widest">SCHOLARLINK | TAU | ADMIN</p>
            </div>
        </div>

        <div class="w-full md:w-1/2 p-6 sm:p-10 md:p-20 flex flex-col justify-center relative">
            <div class="md:hidden mb-8 text-center">
                <a href="index.php"><img src="assets/img/tau_logo.png" alt="TAU Logo" class="h-20 w-auto mx-auto grayscale opacity-80"></a>
            </div>

            <div class="max-w-md mx-auto w-full">
                <div class="mb-10 md:mb-12 text-center md:text-left">
                    <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-2">System Access</h2>
                    <h3 class="text-3xl md:text-4xl font-black text-slate-900 tracking-tight">Admin Login</h3>
                    <p class="text-slate-500 text-sm md:text-base font-medium mt-2">Enter your staff credentials to access the portal.</p>
                </div>

                <?php if(isset($_SESSION['error'])): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-6 text-sm font-bold flex items-center gap-3">
                        ⚠️ <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <form action="actions/process_login.php" method="POST" class="space-y-5 md:space-y-6">
                    <input type="hidden" name="login_type" value="admin">
                    
                    <div>
                        <label class="block text-xs md:text-sm font-semibold text-slate-800 mb-2">Username / Admin ID</label>
                        <input type="text" name="username" required placeholder="e.g. ADM-001" 
                            class="w-full px-5 py-4 rounded-2xl border border-slate-200 bg-white text-slate-900 font-medium placeholder:text-slate-400 focus:border-slate-500 focus:ring-4 focus:ring-slate-100 transition-all outline-none">
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs md:text-sm font-semibold text-slate-800">Password</label>
                        </div>
                        
                        <div class="relative">
                            <input type="password" name="password" id="admin_password" required placeholder="••••••••" 
                                class="w-full pl-5 pr-12 py-4 rounded-2xl border border-slate-200 bg-white text-slate-900 font-medium placeholder:text-slate-400 focus:border-slate-500 focus:ring-4 focus:ring-slate-100 transition-all outline-none">
                            <button type="button" onclick="togglePassword('admin_password', 'eye_icon_admin')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-slate-800 focus:outline-none transition-colors">
                                <svg id="eye_icon_admin" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-slate-950 hover:bg-black text-white font-bold py-4 px-6 rounded-2xl transition-all shadow-lg shadow-slate-900/20 active:scale-[0.98] mt-4">
                        Sign In to System
                    </button>
                </form>

                <div class="mt-10 md:mt-12 pt-8 border-t border-slate-100 text-center">
                    <p class="text-sm md:text-base text-slate-400 font-medium">
                        Student applicant? <a href="student_login.php" class="font-bold text-slate-600 hover:text-slate-900 transition-colors">Go to Student Portal →</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>';
            }
        }
    </script>
</body>
</html>