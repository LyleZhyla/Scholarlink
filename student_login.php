<?php
session_start();
// If already logged in, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: student/dashboard.php");
    exit;
}

// Fetch programs for the registration dropdown
require 'includes/db_connect.php';
try {
    $stmt = $pdo->query("SELECT ProgramID as program_id, ProgramName as program_name FROM program");
    $programs = $stmt->fetchAll();
} catch (PDOException $e) {
    $programs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - ScholarLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal-active { display: flex !important; animation: fadeIn 0.2s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 md:p-10 relative">

    <div class="bg-white w-full max-w-7xl rounded-[1.5rem] md:rounded-[2.5rem] shadow-2xl shadow-slate-200/50 border border-slate-100 flex overflow-hidden min-h-[85vh] md:min-h-[80vh]">
        
        <div class="w-1/2 bg-slate-900 p-16 flex flex-col justify-between relative overflow-hidden hidden md:flex">
            <img src="https://images.unsplash.com/photo-1541339907198-e08756defefe?q=80&w=1470" class="absolute inset-0 w-full h-full object-cover opacity-20">
            <div class="absolute inset-0 bg-gradient-to-b from-green-600/50 to-slate-900/90 z-10"></div>
            <div class="relative z-20">
                <a href="index.php"><img src="assets/img/tau_logo.png" alt="TAU Logo" class="h-28 w-auto mb-10"></a>
                <div class="space-y-3">
                    <span class="inline-block px-4 py-1 rounded-full bg-green-500/20 text-green-300 text-xs font-bold uppercase tracking-widest">Student Portal</span>
                    <h1 class="text-6xl font-black text-white leading-tight tracking-tighter">Welcome<br>Back.</h1>
                    <p class="text-green-100 text-lg max-w-md font-medium">Access your ScholarLink account to manage applications, view requirements, and check scholarship status.</p>
                </div>
            </div>
            <div class="relative z-20 flex items-center justify-between border-t border-white/10 pt-8 mt-16">
                <p class="text-sm font-medium text-white/70">Tarlac Agricultural University Scholarship Management System</p>
                <p class="text-[10px] font-black text-white/50 uppercase tracking-widest">SCHOLARLINK | TAU | CAMPUS</p>
            </div>
        </div>

        <div class="w-full md:w-1/2 p-6 sm:p-10 md:p-20 flex flex-col justify-center relative">
            <div class="md:hidden mb-8 text-center">
                <a href="index.php"><img src="assets/img/tau_logo.png" alt="TAU Logo" class="h-20 w-auto mx-auto"></a>
            </div>

            <div class="max-w-md mx-auto w-full">
                <div class="mb-10 md:mb-12 text-center md:text-left">
                    <h2 class="text-xs font-black text-green-600 uppercase tracking-widest mb-2">Secure Access</h2>
                    <h3 class="text-3xl md:text-4xl font-black text-slate-900 tracking-tight">Login</h3>
                    <p class="text-slate-500 text-sm md:text-base font-medium mt-2">Enter your credentials below to access the portal.</p>
                </div>

                <?php if(isset($_SESSION['error'])): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-6 text-sm font-bold flex items-center gap-3">
                        ⚠️ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                <?php if(isset($_SESSION['success'])): ?>
                    <div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-2xl mb-6 text-sm font-bold flex items-center gap-3">
                        ✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                <?php if(isset($_SESSION['info'])): ?>
                    <div class="bg-blue-50 border border-blue-200 text-blue-700 p-4 rounded-2xl mb-6 text-sm font-bold flex items-center gap-3">
                        ℹ️ <?php echo $_SESSION['info']; unset($_SESSION['info']); ?>
                    </div>
                <?php endif; ?>

                <form action="actions/process_login.php" method="POST" id="loginForm" class="space-y-5 md:space-y-6">
                    <input type="hidden" name="login_type" value="student">
                    
                    <div>
                        <label class="block text-xs md:text-sm font-semibold text-slate-800 mb-2">Email or Student Number</label>
                        <input type="text" name="username" id="login_identifier" required placeholder="e.g. student@tau.edu.ph or 2023100194"
                            class="w-full px-5 py-4 rounded-2xl border border-slate-200 bg-white text-slate-900 font-bold placeholder:text-slate-400 focus:border-green-400 focus:ring-4 focus:ring-green-100 transition-all outline-none">
                        <p class="text-[10px] text-slate-400 font-medium mt-1">You can use your registered email or your 10-digit student number.</p>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs md:text-sm font-semibold text-slate-800">Password</label>
                            <button type="button" onclick="openForgotModal()" class="text-xs md:text-sm font-semibold text-green-600 hover:text-green-700">Forgot?</button>
                        </div>
                        
                        <div class="relative">
                            <input type="password" name="password" id="login_password" required placeholder="••••••••" 
                                class="w-full pl-5 pr-12 py-4 rounded-2xl border border-slate-200 bg-white text-slate-900 font-medium placeholder:text-slate-400 focus:border-green-400 focus:ring-4 focus:ring-green-100 transition-all outline-none">
                            <button type="button" onclick="togglePassword('login_password', 'eye_icon_login')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-green-600 focus:outline-none">
                                <svg id="eye_icon_login" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-4 px-6 rounded-2xl transition-all shadow-lg shadow-slate-900/20 active:scale-[0.98]">
                        Sign In to Portal
                    </button>
                </form>

                <div class="relative flex items-center justify-center my-8">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-slate-200"></div>
                    </div>
                    <div class="relative px-3 bg-white text-sm font-medium text-slate-400">
                        Or continue with
                    </div>
                </div>

                <div>
                    <a href="actions/google_login.php" class="w-full flex items-center justify-center gap-3 bg-white border-2 border-slate-200 hover:border-slate-300 hover:bg-slate-50 text-slate-800 font-bold py-3.5 px-6 rounded-2xl transition-all active:scale-[0.98]">
                        <svg class="w-5 h-5" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"></path><path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"></path><path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"></path><path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571l6.19,5.238C44.572,36.833,48,30.826,48,24C48,22.659,47.862,21.35,47.611,20.083z"></path></svg>
                        Sign in with Google
                    </a>
                </div>

                <div class="mt-10 md:mt-12 pt-8 border-t border-slate-100 text-center space-y-4">
                    <p class="text-sm md:text-base text-slate-600 font-medium">New Student Applicant? <br class="sm:hidden">
                        <button onclick="openRegisterModal()" type="button" class="font-bold text-green-600 hover:text-green-700 mt-2 sm:mt-0 transition-colors">Create an Account →</button>
                    </p>
                    
                    <p class="text-sm md:text-base text-slate-600 font-medium">Are you an Administrator? <br class="sm:hidden">
                        <a href="admin_login.php" class="font-bold text-green-600 hover:text-green-700 mt-2 sm:mt-0 transition-colors">Go to Staff Portal →</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- REGISTRATION MODAL -->
    <div id="registerModal" class="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-white w-full max-w-[95%] md:max-w-3xl rounded-[1.5rem] md:rounded-[2rem] shadow-2xl relative max-h-[90vh] overflow-y-auto">
            <button onclick="closeRegisterModal()" class="absolute top-4 right-4 md:top-6 md:right-6 w-8 h-8 md:w-10 md:h-10 bg-slate-100 text-slate-500 rounded-full flex items-center justify-center hover:bg-slate-200 hover:text-slate-900 transition-colors z-10">
                <svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>

            <div class="p-6 md:p-10">
                
                <div class="mb-6 md:mb-8 pr-8 flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                    <div>
                        <h3 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Register Account</h3>
                        <p class="text-slate-500 text-sm md:text-base font-medium mt-1">Create your ScholarLink profile to apply for programs.</p>
                    </div>
                    
                    <a href="actions/google_login.php?intent=register" class="shrink-0 w-full sm:w-auto inline-flex items-center justify-center gap-3 bg-white border-2 border-slate-200 hover:border-slate-300 hover:bg-slate-50 text-slate-800 font-bold py-2.5 px-5 rounded-xl transition-all shadow-sm active:scale-[0.98]">
                        <svg class="w-5 h-5" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"></path><path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"></path><path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"></path><path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571l6.19,5.238C44.572,36.833,48,30.826,48,24C48,22.659,47.862,21.35,47.611,20.083z"></path></svg>
                        Speed up with Google
                    </a>
                </div>

                <div class="mb-6 p-5 border-2 border-dashed border-green-300 bg-green-50/50 rounded-2xl text-center relative transition-all duration-300" id="scanArea">
                    <label class="block text-xs font-bold text-green-800 mb-3 uppercase tracking-widest" id="scanAreaLabel">📸 Auto-Fill via ID Scan (Front & Back)</label>
                    
                    <div id="previewContainer" class="hidden flex justify-center gap-4 mb-4">
                        <div class="relative w-16 h-24 bg-slate-200 rounded-lg overflow-hidden border-2 border-green-400 shadow-sm">
                            <img id="frontPreview" class="w-full h-full object-cover hidden">
                            <div id="frontPreviewText" class="absolute inset-0 flex items-center justify-center text-slate-400 text-[9px] font-black uppercase">Front</div>
                        </div>
                        <div class="relative w-16 h-24 bg-slate-200 rounded-lg overflow-hidden border-2 border-dashed border-slate-400 opacity-70" id="backPreviewBox">
                            <img id="backPreview" class="w-full h-full object-cover hidden">
                            <div id="backPreviewText" class="absolute inset-0 flex items-center justify-center text-slate-400 text-[9px] font-black uppercase">Back</div>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3 justify-center items-center" id="scanMethodToggle">
                        
                        <input type="file" id="fallbackCameraInput" accept="image/*" capture="environment" class="hidden">

                        <button type="button" onclick="startCamera()" id="btnStartCamera" class="w-full sm:w-auto bg-green-600 text-white px-6 py-2.5 rounded-full text-[11px] font-black uppercase tracking-widest shadow-md hover:bg-green-700 transition-all hover:scale-105 active:scale-95 flex items-center justify-center gap-2">
                            <span>📷</span> <span id="cameraBtnText">Take Photo Live</span>
                        </button>
                        <span class="text-xs font-bold text-green-700/50" id="orDivider">OR</span>
                        <div class="relative w-full sm:w-auto" id="uploadWrapper">
                            <input type="file" id="idUploader" accept="image/*" multiple class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                            <div class="bg-white border border-green-300 text-green-700 px-6 py-2.5 rounded-full text-[11px] font-black uppercase tracking-widest hover:bg-green-50 transition-all flex items-center justify-center gap-2">
                                <span>📁</span> <span id="uploadBtnText">Upload Files</span>
                            </div>
                        </div>
                    </div>

                    <div id="cameraContainer" class="hidden flex-col items-center gap-4 mt-2 relative">
                        <div class="absolute -top-3 z-30 bg-green-600 text-white px-4 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-md" id="camInstructionBadge">
                            Position FRONT of ID
                        </div>
                        <!-- Portrait viewfinder with an upright ID-card guide (54 x 86 mm) -->
                        <div id="cameraViewfinder" class="relative aspect-[3/4] h-[45vh] max-h-[420px] max-w-full rounded-xl overflow-hidden bg-black shadow-inner border border-green-400/50 mt-2">
                            <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 h-[88%] aspect-[54/86] border-[3px] border-green-500/50 rounded-lg pointer-events-none z-10 border-dashed"></div>
                            <video id="cameraFeed" autoplay playsinline class="w-full h-full object-cover"></video>
                        </div>
                        
                        <div class="flex gap-2 w-full max-w-sm">
                            <button type="button" onclick="capturePhoto()" class="flex-1 bg-slate-900 text-white py-3 rounded-xl text-[11px] font-black uppercase tracking-widest shadow-lg hover:bg-blue-600 transition-all active:scale-95">
                                Snap Photo 📸
                            </button>
                            <button type="button" onclick="stopCamera()" class="px-5 bg-red-50 text-red-600 border border-red-200 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-red-600 hover:text-white transition-all active:scale-95">
                                Cancel
                            </button>
                        </div>
                        <canvas id="cameraCanvas" class="hidden"></canvas>
                    </div>

                    <div id="scanLoader" class="hidden mt-4 text-[11px] uppercase tracking-widest text-green-700 font-black flex items-center justify-center gap-2">
                        <span class="w-4 h-4 border-2 border-green-600 border-t-transparent rounded-full animate-spin"></span>
                        Analyzing and Extracting...
                    </div>
                    <div id="scanSuccess" class="hidden mt-4 text-[11px] uppercase tracking-widest text-green-600 font-black bg-green-100 py-2 rounded-lg border border-green-200">
                        ✅ ID Scanned! Verify details below.
                    </div>
                </div>

                <form id="registerForm" action="actions/process_register.php" method="POST" class="space-y-4 md:space-y-5">
                    
                    <!-- ✨ HIDDEN INPUT & PREVIEW FOR EXTRACTED ID PORTRAIT -->
                    <input type="hidden" name="extracted_id_photo" id="reg_extracted_photo">
                    <div id="extractedPhotoBox" class="hidden flex items-center gap-3 p-3 bg-emerald-50 border border-emerald-200 rounded-2xl">
                        <img id="extractedPhotoPreview" src="" class="w-12 h-12 rounded-full object-cover border-2 border-emerald-500 shadow-sm shrink-0">
                        <div class="text-left">
                            <span class="block text-[10px] font-black text-emerald-800 uppercase tracking-widest">🔒 Official ID Portrait Detected</span>
                            <span class="block text-[10px] font-medium text-emerald-600">This photo will be locked as your official ScholarLink profile picture.</span>
                        </div>
                    </div>
                    <!-- 1. PERSONAL INFORMATION -->
                    <div class="col-span-full mt-2"><h4 class="text-[11px] font-black text-green-600 uppercase tracking-widest border-b border-slate-100 pb-2">Personal Information</h4></div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 md:gap-5">
                        <div class="sm:col-span-2">
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">First Name <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" id="reg_first_name" required class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Last Name <span class="text-red-500">*</span></label>
                            <input type="text" name="last_name" id="reg_last_name" required class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Middle Name</label>
                            <input type="text" name="middle_name" placeholder="Optional" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div class="sm:col-span-1">
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Ext Name</label>
                            <input type="text" name="ext_name" placeholder="e.g. Jr., III" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 md:gap-5">
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Gender <span class="text-red-500">*</span></label>
                            <select name="gender" required class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all text-slate-700 cursor-pointer">
                                <option value="">Select...</option>
                                <option value="Female">Female</option>
                                <option value="Male">Male</option>
                            </select>
                        </div>
                        <!-- ✨ BAGONG CITIZENSHIP FIELD -->
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Citizenship <span class="text-red-500">*</span></label>
                            <input type="text" name="citizenship" required value="Filipino" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all font-medium">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Contact No. <span class="text-red-500">*</span></label>
                            <input type="text" name="contact_number" required placeholder="09xxxxxxxxx" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all font-medium">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Date of Birth <span class="text-red-500">*</span></label>
                            <input type="date" name="date_of_birth" id="reg_dob" required class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all text-slate-600 font-medium cursor-pointer">
                        </div>
                    </div>

                    <!-- 2. ACADEMIC & IDENTIFICATIONS -->
                    <div class="col-span-full mt-6"><h4 class="text-[11px] font-black text-green-600 uppercase tracking-widest border-b border-slate-100 pb-2">Academic & Identifications</h4></div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-5">
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Student Number <span class="text-red-500">*</span></label>
                            <input type="text" name="student_number" id="reg_student_number" required placeholder="e.g. 2023100194" maxlength="10" pattern="[0-9]{10}" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-slate-900 font-bold focus:border-green-400 outline-none transition-all">
                            <p id="student-number-feedback" class="text-[10px] font-bold mt-1 hidden"></p>
                        </div>
                        <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-2 flex items-center justify-between">
                            <span>📄 Upload Report of Grades (PDF) <span class="text-red-500">*</span></span>
                            <span class="text-[9px] text-slate-400 font-semibold">Max Size: 10MB (Clear Resolution)</span>
                        </label>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Email Address <span class="text-red-500">*</span></label>
                            <input type="email" name="email" id="reg_email" required placeholder="student@tau.edu.ph" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                            <p id="email-feedback" class="text-[10px] font-bold mt-1 hidden"></p>
                        </div>
                    </div>

                    <!-- ROW: COURSE & YEAR LEVEL -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-5 mt-4 md:mt-5">
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Course / Program <span class="text-red-500">*</span></label>
                            <!-- ✨ Program is read from the scanned ID, not encoded by the student -->
                            <input type="text" id="reg_course" readonly required placeholder="Scan your student ID to fill this in" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-100 outline-none transition-all text-slate-700 cursor-not-allowed shadow-sm">
                            <p class="text-[10px] font-bold text-slate-400 mt-1">Detected automatically from your student ID.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Year Level <span class="text-red-500">*</span></label>
                            <select name="year_level" required class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all text-slate-700 cursor-pointer shadow-sm">
                                <option value="">Select year level...</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>
                    </div>

                    <!-- ✨ PREMIUM AI UPLOAD ZONE (Full Width) -->
                    <div class="mt-4 md:mt-5 bg-slate-50/50 border border-slate-200 rounded-[1.5rem] p-5 md:p-6 shadow-sm">
                        <div class="flex flex-col md:flex-row gap-6 items-start md:items-center">
                            
                            <!-- UPLOAD SECTION -->
                            <div class="flex-1 w-full">
                                <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-2 flex items-center gap-2">
                                    📄 Upload Report of Grades (PDF) <span class="text-red-500">*</span>
                                </label>
                                <!-- ✨ Magandang Styling ng File Input -->
                                <input type="file" id="rog_upload" accept="application/pdf" class="w-full text-sm text-slate-500 file:mr-4 file:py-3 file:px-6 file:rounded-xl file:border-0 file:text-[11px] file:font-black file:uppercase file:tracking-widest file:bg-slate-900 file:text-white hover:file:bg-green-600 file:transition-all file:shadow-md file:cursor-pointer cursor-pointer bg-white border border-slate-200 rounded-xl hover:border-green-400 transition-all focus:outline-none" onchange="processROG(this)">
                                
                                <!-- AI Loader -->
                                <div id="rog_loader" class="hidden mt-4 text-[10px] uppercase tracking-widest text-blue-600 font-bold flex items-center gap-3 bg-blue-50 p-4 rounded-xl border border-blue-200">
                                    <span class="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></span> 
                                    <span>AI is analyzing document metadata & grades...</span>
                                </div>
                                
                                <!-- AI Result Box -->
                                <div id="rog_result" class="hidden mt-4 text-xs font-bold bg-white p-5 rounded-xl border border-slate-200 shadow-sm relative overflow-hidden">
                                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-green-500"></div>
                                    <div class="flex justify-between items-center mb-2 pb-2 border-b border-slate-100">
                                        <span class="text-slate-500 uppercase tracking-widest text-[10px]">Stated GWA on Doc:</span>
                                        <span id="stated_gwa" class="text-slate-900 text-sm">--</span>
                                    </div>
                                    <div class="flex justify-between items-center mb-2 pb-2 border-b border-slate-100">
                                        <span class="text-slate-500 uppercase tracking-widest text-[10px]">AI Computed GWA:</span>
                                        <span id="computed_gwa" class="text-slate-900 text-sm">--</span>
                                    </div>
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-slate-500 uppercase tracking-widest text-[10px]">Total Valid Units:</span>
                                        <span id="computed_units" class="text-slate-900 text-sm font-black text-green-600">--</span>
                                    </div>
                                    <p id="rog_status" class="mt-2 text-[10px] uppercase tracking-widest text-center py-2 rounded-lg"></p>
                                </div>
                            </div>

                            <!-- GWA DISPLAY BOX -->
                            <div class="w-full md:w-56 bg-white p-5 rounded-2xl border border-slate-200 shadow-inner flex flex-col items-center justify-center text-center shrink-0 h-full relative overflow-hidden">
                                <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-green-400 to-emerald-600"></div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Final Verified GWA</label>
                                
                                <input type="number" step="0.01" name="gpa" id="reg_gpa" required readonly placeholder="0.00" class="w-full text-center bg-transparent text-4xl lg:text-5xl font-black text-slate-900 focus:outline-none cursor-not-allowed placeholder:text-slate-200" title="This is automatically extracted by AI">
                                
                                <div class="mt-4 text-[9px] text-slate-400 font-bold uppercase tracking-widest bg-slate-50 px-3 py-2 rounded-lg border border-slate-100 w-full flex items-center justify-center gap-1.5">
                                    <span class="text-green-500 text-sm">🪄</span> AI Computed
                                </div>
                                <!-- Hidden inputs -->
                                <input type="hidden" name="units_enrolled" id="reg_units" required>
                            </div>
                            
                        </div>
                    </div>


                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 md:gap-5">
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">LRN <span class="text-slate-400 font-normal">(If available)</span></label>
                            <input type="text" name="lrn" placeholder="Learner Ref. No." class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all font-medium">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">PhilSys ID</label>
                            <input type="text" name="philsys_id" placeholder="Optional" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all font-medium">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">4Ps ID No.</label>
                            <input type="text" name="fourps_id" placeholder="Optional" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all font-medium">
                        </div>
                    </div>

                    <!-- 3. PERMANENT ADDRESS -->
                    <div class="col-span-full mt-6"><h4 class="text-[11px] font-black text-green-600 uppercase tracking-widest border-b border-slate-100 pb-2">Permanent Address</h4></div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-5">
                        <div class="sm:col-span-2">
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Street & Barangay</label>
                            <input type="text" name="street_brgy" placeholder="House No., Street, Barangay" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">City / Municipality</label>
                            <input type="text" name="city" placeholder="e.g. Camiling" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Province</label>
                            <input type="text" name="province" placeholder="e.g. Tarlac" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Region</label>
                            <input type="text" name="region" placeholder="e.g. Region III" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Zip Code</label>
                            <input type="text" name="zipcode" placeholder="e.g. 2306" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                    </div>

                    <!-- 4. FAMILY BACKGROUND -->
                    <div class="col-span-full mt-6"><h4 class="text-[11px] font-black text-green-600 uppercase tracking-widest border-b border-slate-100 pb-2">Family Background</h4></div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 md:gap-5">
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Father First Name</label>
                            <input type="text" name="father_fn" placeholder="Given Name" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Father Middle Name</label>
                            <input type="text" name="father_mn" placeholder="Middle Name" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Father Last Name</label>
                            <input type="text" name="father_ln" placeholder="Last Name" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Mother First Name</label>
                            <input type="text" name="mother_fn" placeholder="Given Name" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Mother Middle Name</label>
                            <input type="text" name="mother_mn" placeholder="Middle Name" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Mother Maiden Last Name</label>
                            <input type="text" name="mother_ln" placeholder="Maiden Last Name" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                    </div>

                    <!-- 5. SPECIAL STATUS (TES Requirements) -->
                    <div class="col-span-full mt-6"><h4 class="text-[11px] font-black text-green-600 uppercase tracking-widest border-b border-slate-100 pb-2">Special Status</h4></div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-5">
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Disability Type (If applicable)</label>
                            <input type="text" name="disability" placeholder="Leave blank if none" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Indigenous Group (If applicable)</label>
                            <input type="text" name="indigenous" placeholder="Indicate specific IP group" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Solo Parent Child?</label>
                            <select name="is_solo_parent" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all text-slate-700">
                                <option value="No">No</option>
                                <option value="Yes">Yes</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">First-Gen College Student?</label>
                            <select name="is_first_gen" class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all text-slate-700">
                                <option value="No">No</option>
                                <option value="Yes">Yes</option>
                            </select>
                        </div>
                    </div>

                    <!-- 6. ACCOUNT SECURITY -->
                    <div class="col-span-full mt-6"><h4 class="text-[11px] font-black text-green-600 uppercase tracking-widest border-b border-slate-100 pb-2">Account Security</h4></div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-5" id="securitySection">
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Password <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="password" name="password" id="reg_password" required class="w-full pl-4 pr-10 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                                <button type="button" onclick="togglePassword('reg_password', 'eye_icon_reg')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-green-600 focus:outline-none">
                                    <svg id="eye_icon_reg" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                </button>
                            </div>
                             <div id="password-feedback" class="text-xs font-bold mt-3 space-y-1.5 hidden bg-white p-4 rounded-xl border border-slate-100 shadow-sm">
                                <p id="rule-length" class="text-red-500 transition-colors">✗ At least 8 characters</p>
                                <p id="rule-number" class="text-red-500 transition-colors">✗ At least 1 number (0-9)</p>
                                <p id="rule-symbol" class="text-red-500 transition-colors">✗ At least 1 special symbol (!@#$%^&*)</p>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Confirm Password <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="reg_confirm" required class="w-full pl-4 pr-10 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                                <button type="button" onclick="togglePassword('reg_confirm', 'eye_icon_confirm')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-green-600 focus:outline-none">
                                    <svg id="eye_icon_confirm" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                </button>
                            </div>
                             <p id="match-feedback" class="text-xs font-bold mt-2 hidden"></p>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-xl transition-all mt-6 shadow-xl shadow-green-600/20 active:scale-[0.98]">
                        Complete Registration
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Confirm Registration Sub-Modal -->
    <div id="confirmRegisterModal" class="fixed inset-0 z-[100000] bg-slate-950/80 backdrop-blur-md hidden items-center justify-center p-4 opacity-0 transition-opacity duration-300">
        <div class="bg-white w-full max-w-sm rounded-[2rem] shadow-2xl p-6 text-center transform scale-95 transition-transform duration-300" id="confirmModalContent">
            <div class="w-16 h-16 bg-amber-50 text-amber-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4 border border-amber-200">⚠️</div>
            <h3 class="text-xl font-black text-slate-900 mb-2">Confirm Registration Details</h3>
            <p class="text-xs text-slate-500 font-medium leading-relaxed mb-6">
                Please make sure all entered records are 100% correct. <br><br>
                <span class="bg-red-50 text-red-600 border border-red-200 px-3 py-1.5 rounded-xl font-black uppercase tracking-wide inline-block text-[10px]">
                    The Student Number cannot be edited once submitted.
                </span>
            </p>
            <div class="flex gap-3">
                <button type="button" onclick="closeConfirmModal()" class="flex-1 bg-slate-100 border border-slate-200 text-slate-700 py-3 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-slate-200 transition-all">Go Back</button>
                <button type="button" onclick="commitRegistrationForm()" class="flex-1 bg-green-600 text-white py-3 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-green-700 shadow-lg shadow-green-600/20 transition-all">Confirm & Submit</button>
            </div>
        </div>
    </div>

    <!-- OTP VERIFICATION MODAL -->
    <div id="otpModal" class="fixed inset-0 z-[100000] bg-slate-950/80 backdrop-blur-md hidden items-center justify-center p-4 opacity-0 transition-opacity duration-300">
        <div class="bg-white w-full max-w-sm rounded-[2rem] shadow-2xl p-8 text-center transform scale-95 transition-transform duration-300" id="otpModalContent">
            <div class="w-16 h-16 bg-green-50 text-green-600 rounded-full flex items-center justify-center text-3xl mx-auto mb-4 border border-green-200">📧</div>
            <h3 class="text-xl font-black text-slate-900 mb-2">Check Your Email</h3>
            <p class="text-xs text-slate-500 font-medium leading-relaxed mb-6">
                We've sent a 6-digit verification code to <br> <strong id="otpDisplayEmail" class="text-slate-800"></strong>.
            </p>
            
            <div class="mb-6">
                <input type="text" id="otpInput" placeholder="• • • • • •" maxlength="6" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full text-center text-3xl tracking-[0.5em] font-black text-slate-900 py-4 border-b-2 border-slate-200 focus:border-green-500 outline-none transition-all placeholder:text-slate-300 placeholder:font-normal">
                <p id="otpErrorMsg" class="text-[10px] font-bold text-red-500 mt-2 hidden">Invalid code. Please try again.</p>
            </div>

            <button type="button" onclick="verifyOTPAndSubmit()" id="btnVerifyOTP" class="w-full bg-green-600 text-white py-4 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-green-700 shadow-lg shadow-green-600/20 transition-all">Verify & Create Account</button>
            
            <button type="button" onclick="closeOtpModal()" class="w-full mt-3 text-[10px] font-bold text-slate-400 hover:text-slate-600 uppercase tracking-widest transition-colors">Cancel</button>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotModal" class="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-white w-full max-w-[95%] sm:max-w-md rounded-[1.5rem] md:rounded-[2rem] shadow-2xl relative overflow-hidden">
            <button onclick="closeForgotModal()" class="absolute top-4 right-4 md:top-6 md:right-6 w-8 h-8 md:w-10 md:h-10 bg-slate-100 text-slate-500 rounded-full flex items-center justify-center hover:bg-slate-200 hover:text-slate-900 transition-colors z-10">
                <svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>

            <div class="p-6 md:p-10">
                <div class="w-12 h-12 bg-green-50 rounded-full flex items-center justify-center text-green-600 mb-6">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                </div>
                <h3 class="text-2xl font-black text-slate-900 tracking-tight mb-2">Reset Password</h3>
                <p class="text-slate-500 text-sm font-medium mb-8">Enter the email address associated with your account, and we'll send you a link to reset your password.</p>

                <form id="forgotForm" action="actions/process_forgot_password.php" method="POST" class="space-y-4">
                    <div>
                        <label class="block text-[10px] md:text-xs font-bold text-slate-700 uppercase tracking-widest mb-1 md:mb-2">Email Address</label>
                        <input type="email" name="email" required placeholder="student@tau.edu.ph" class="w-full px-4 py-4 rounded-xl border border-slate-200 bg-slate-50 focus:border-green-400 outline-none transition-all">
                    </div>
                    
                    <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-4 px-6 rounded-xl transition-all shadow-lg active:scale-[0.98]">
                        Send Reset Link 📧
                    </button>
                </form>
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

        function resetPasswordUI() {
            if(feedbackBox) {
                feedbackBox.classList.add('hidden');
                updateRuleUI(ruleLength, false, "At least 8 characters");
                updateRuleUI(ruleNumber, false, "At least 1 number (0-9)");
                updateRuleUI(ruleSymbol, false, "At least 1 special symbol");
            }
            if(matchFeedback) matchFeedback.classList.add('hidden');
            
            document.getElementById('reg_password').type = 'password';
            document.getElementById('reg_confirm').type = 'password';

            const emailFeedback = document.getElementById('email-feedback');
            if(emailFeedback) emailFeedback.classList.add('hidden');
            
            const studNumFeedback = document.getElementById('student-number-feedback');
            if(studNumFeedback) studNumFeedback.classList.add('hidden');
        }

        const regModal = document.getElementById('registerModal');
        function openRegisterModal() { 
            regModal.classList.add('modal-active'); 
            document.body.style.overflow = 'hidden'; 
        }
        
        function closeRegisterModal() { 
            stopCamera(); 
            resetScanState(); 
            regModal.classList.remove('modal-active'); 
            document.body.style.overflow = 'auto'; 
            document.getElementById('registerForm').reset(); 
            resetPasswordUI();
            if (document.getElementById('extractedPhotoBox')) document.getElementById('extractedPhotoBox').classList.add('hidden');
            if (document.getElementById('reg_extracted_photo')) document.getElementById('reg_extracted_photo').value = '';
        }
        regModal.addEventListener('click', function(e) { if (e.target === regModal) closeRegisterModal(); });

        const forgotModal = document.getElementById('forgotModal');
        function openForgotModal() { 
            forgotModal.classList.add('modal-active'); 
            document.body.style.overflow = 'hidden'; 
        }
        function closeForgotModal() { 
            forgotModal.classList.remove('modal-active'); 
            document.body.style.overflow = 'auto'; 
            document.getElementById('forgotForm').reset(); 
        }
        forgotModal.addEventListener('click', function(e) { if (e.target === forgotModal) closeForgotModal(); });

        // ========================================================
        // ✨ REAL-TIME EMAIL TYPO, FORMAT, & EXISTENCE VALIDATION
        // ========================================================
        const emailInput = document.getElementById('reg_email');
        const emailFeedback = document.getElementById('email-feedback');

        const studNumInput = document.getElementById('reg_student_number');
        const studNumFeedback = document.getElementById('student-number-feedback');

        let isStudentNumberTaken = false;
        let isEmailTaken = false;

        if (studNumInput) {
            studNumInput.addEventListener('input', async function() {
                const val = this.value.trim();
                if (val.length === 10) {
                    checkAccountAvailability({ student_number: val });
                } else {
                    if (studNumFeedback) studNumFeedback.classList.add('hidden');
                    isStudentNumberTaken = false;
                }
            });
        }

        if(emailInput) {
            emailInput.addEventListener('input', function() {
                const email = this.value.trim();
                if (email.length === 0) {
                    emailFeedback.classList.add('hidden');
                    return;
                }

                emailFeedback.classList.remove('hidden');

                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    emailFeedback.className = "text-[10px] font-bold mt-1 text-red-500";
                    emailFeedback.innerText = "✗ Invalid email format";
                    return;
                }

                const domain = email.split('@')[1].toLowerCase();
                const commonTypos = ['gmai.com', 'gmal.com', 'gmail.con', 'yaho.com', 'yahoo.con', 'outlok.com'];
                
                if (commonTypos.includes(domain)) {
                    emailFeedback.className = "text-[10px] font-bold mt-1 text-amber-500";
                    let suggestion = domain.replace(/gmai\.com|gmal\.com|gmail\.con/, 'gmail.com').replace(/yaho\.com|yahoo\.con/, 'yahoo.com').replace('outlok.com', 'outlook.com');
                    emailFeedback.innerText = "⚠️ Did you mean @" + suggestion + "?";
                    return;
                }

                emailFeedback.className = "text-[10px] font-bold mt-1 text-green-600";
                emailFeedback.innerText = "✓ Valid email format";
            });

            emailInput.addEventListener('blur', async function() {
                const email = this.value.trim();
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (emailRegex.test(email)) {
                    checkAccountAvailability({ email: email });
                }
            });
        }

        async function checkAccountAvailability(data) {
            const formData = new FormData();
            if (data.email) formData.append('email', data.email);
            if (data.student_number) formData.append('student_number', data.student_number);

            try {
                const response = await fetch('actions/check_account_exists.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (data.student_number && studNumFeedback) {
                    studNumFeedback.classList.remove('hidden');
                    if (result.student_number_exists) {
                        isStudentNumberTaken = true;
                        studNumFeedback.className = "text-[10px] font-bold mt-1 text-red-500";
                        studNumFeedback.innerText = "⚠️ This student number is already registered.";
                    } else {
                        isStudentNumberTaken = false;
                        studNumFeedback.className = "text-[10px] font-bold mt-1 text-green-600";
                        studNumFeedback.innerText = "✓ Student number available";
                    }
                }

                if (data.email && emailFeedback) {
                    if (result.email_exists) {
                        isEmailTaken = true;
                        emailFeedback.classList.remove('hidden');
                        emailFeedback.className = "text-[10px] font-bold mt-1 text-red-500";
                        emailFeedback.innerText = "⚠️ This email address is already registered.";
                    } else {
                        isEmailTaken = false;
                    }
                }
            } catch (e) {
                console.error("Error checking account availability:", e);
            }
        }

        // ========================================================
        // REAL-TIME PASSWORD VALIDATION
        // ========================================================
        const passInput = document.getElementById('reg_password');
        const confirmInput = document.getElementById('reg_confirm');
        const feedbackBox = document.getElementById('password-feedback');
        const ruleLength = document.getElementById('rule-length');
        const ruleNumber = document.getElementById('rule-number');
        const ruleSymbol = document.getElementById('rule-symbol');
        const matchFeedback = document.getElementById('match-feedback');

        function updateRuleUI(element, isValid, text) {
            if (isValid) {
                element.classList.remove('text-red-500');
                element.classList.add('text-green-600');
                element.innerText = '✓ ' + text;
            } else {
                element.classList.remove('text-green-600');
                element.classList.add('text-red-500');
                element.innerText = '✗ ' + text;
            }
        }

        function validatePassword() {
            const val = passInput.value;
            if (val.length > 0) feedbackBox.classList.remove('hidden');
            else feedbackBox.classList.add('hidden');

            const isValidLength = val.length >= 8;
            const isValidNumber = /[0-9]/.test(val);
            const isValidSymbol = /[!@#$%^&*()\-_=+{};:,<.>]/.test(val);

            updateRuleUI(ruleLength, isValidLength, "At least 8 characters");
            updateRuleUI(ruleNumber, isValidNumber, "At least 1 number (0-9)");
            updateRuleUI(ruleSymbol, isValidSymbol, "At least 1 special symbol");
            checkMatch();
        }

        function checkMatch() {
            const val1 = passInput.value;
            const val2 = confirmInput.value;

            if (val2.length > 0) {
                matchFeedback.classList.remove('hidden');
                if (val1 === val2) {
                    matchFeedback.classList.remove('text-red-500');
                    matchFeedback.classList.add('text-green-600');
                    matchFeedback.innerText = '✓ Passwords match';
                } else {
                    matchFeedback.classList.remove('text-green-600');
                    matchFeedback.classList.add('text-red-500');
                    matchFeedback.innerText = '✗ Passwords do not match';
                }
            } else {
                matchFeedback.classList.add('hidden');
            }
        }

        if(passInput) passInput.addEventListener('input', validatePassword);
        if(confirmInput) confirmInput.addEventListener('input', checkMatch);

        // ========================================================
        // ✨ UPGRADED: REGISTRATION + OTP INTERCEPT LOGIC
        // ========================================================
        const confirmModal = document.getElementById('confirmRegisterModal');
        const confirmContent = document.getElementById('confirmModalContent');
        const otpModal = document.getElementById('otpModal');
        const otpContent = document.getElementById('otpModalContent');

       document.getElementById('registerForm').addEventListener('submit', function(e) {
            e.preventDefault(); 
            
            const studNum = document.getElementById('reg_student_number').value.trim();
            if(studNum.length !== 10) {
                alert("⚠️ REGISTER VALIDATION ERROR:\n\nStudent number must be exactly 10 digits.");
                return;
            }

            const email = document.getElementById('reg_email').value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if(!emailRegex.test(email)) {
                alert("⚠️ REGISTER VALIDATION ERROR:\n\nPlease enter a valid email address format.");
                return;
            }

            if (isStudentNumberTaken || isEmailTaken) {
                alert("⚠️ ACCOUNT EXISTS:\n\nPlease fix errors regarding registered student number or email.");
                return;
            }

            const isGoogleAuth = document.getElementById('google_id_input') && document.getElementById('google_id_input').value !== '';

            if (!isGoogleAuth) {
                const val = passInput.value;
                if (val.length < 8 || !/[0-9]/.test(val) || !/[!@#$%^&*()\-_=+{};:,<.>]/.test(val) || val !== confirmInput.value) {
                    alert("⚠️ SECURITY POLICY CRITERIA BREACHED:\n\nPlease fix password validation errors before attempting configuration storage saves.");
                    return;
                }
            }

            openConfirmModal();
        });

        function openConfirmModal() {
            confirmModal.classList.remove('hidden');
            confirmModal.classList.add('flex');
            setTimeout(() => {
                confirmModal.classList.remove('opacity-0');
                confirmContent.classList.remove('scale-95');
            }, 10);
        }

        function closeConfirmModal() {
            confirmModal.classList.add('opacity-0');
            confirmContent.classList.add('scale-95');
            setTimeout(() => {
                confirmModal.classList.add('hidden');
                confirmModal.classList.remove('flex');
            }, 300);
        }

        async function commitRegistrationForm() {
            closeConfirmModal();
            
            const isGoogleAuth = document.getElementById('google_id_input') && document.getElementById('google_id_input').value !== '';
            const email = document.getElementById('reg_email').value.trim();
            const studentNumber = document.getElementById('reg_student_number').value.trim();
            const firstName = document.getElementById('reg_first_name').value.trim();

            const checkForm = new FormData();
            checkForm.append('email', email);
            checkForm.append('student_number', studentNumber);

            try {
                const checkRes = await fetch('actions/check_account_exists.php', {
                    method: 'POST',
                    body: checkForm
                });
                const checkData = await checkRes.json();

                if (checkData.exists) {
                    alert("⚠️ REGISTRATION BLOCKED:\n\n" + checkData.message);
                    return;
                }
            } catch (err) {
                alert("System error checking user availability. Please try again.");
                return;
            }
            
            if (isGoogleAuth) {
                document.getElementById('registerForm').submit();
                return;
            }
            
            document.getElementById('otpDisplayEmail').innerText = email;
            
            const formData = new FormData();
            formData.append('email', email);
            formData.append('first_name', firstName);

            try {
                const response = await fetch('actions/send_otp.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if(result.success) {
                    otpModal.classList.remove('hidden'); 
                    otpModal.classList.add('flex');
                    setTimeout(() => { 
                        otpModal.classList.remove('opacity-0'); 
                        otpContent.classList.remove('scale-95'); 
                    }, 10);
                } else {
                    alert("Error sending verification email: " + result.error);
                }
            } catch (error) {
                alert("System error sending email. Please check your connection.");
            }
        }

        function closeOtpModal() {
            otpModal.classList.add('opacity-0'); 
            otpContent.classList.add('scale-95');
            setTimeout(() => { 
                otpModal.classList.add('hidden'); 
                otpModal.classList.remove('flex'); 
            }, 300);
            document.getElementById('otpInput').value = '';
            document.getElementById('otpErrorMsg').classList.add('hidden');
        }

        async function verifyOTPAndSubmit() {
            const userInput = document.getElementById('otpInput').value.trim();
            const errorMsg = document.getElementById('otpErrorMsg');
            const btn = document.getElementById('btnVerifyOTP');

            if(userInput.length !== 6) {
                errorMsg.innerText = "Please enter the 6-digit code.";
                errorMsg.classList.remove('hidden'); return;
            }

            btn.innerText = "Verifying...";
            btn.disabled = true;

            try {
                const response = await fetch('actions/verify_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'otp=' + encodeURIComponent(userInput)
                });
                
                const result = await response.json();
                
                if(result.success) {
                    document.getElementById('registerForm').submit();
                } else {
                    errorMsg.innerText = result.error;
                    errorMsg.classList.remove('hidden');
                    btn.innerText = "Verify & Create Account";
                    btn.disabled = false;
                }
            } catch (error) {
                errorMsg.innerText = "System error verifying code.";
                errorMsg.classList.remove('hidden');
                btn.innerText = "Verify & Create Account";
                btn.disabled = false;
            }
        }

       // ✨ GOOGLE SIGN-UP COMPLETION LOGIC
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') === 'completereg') {
                openRegisterModal();

                <?php
                if (isset($_SESSION['google_signup_data'])) {
                    $google_data = $_SESSION['google_signup_data'];
                    unset($_SESSION['google_signup_data']);
                ?>
                    const regFirstName = document.getElementById('reg_first_name');
                    const regLastName = document.getElementById('reg_last_name');
                    const regEmail = document.getElementById('reg_email');
                    
                    const regDob = document.getElementById('reg_dob');
                    const regGender = document.querySelector('select[name="gender"]');

                    if (regFirstName) regFirstName.value = '<?= htmlspecialchars($google_data['first_name'] ?? '', ENT_QUOTES) ?>';
                    if (regLastName) regLastName.value = '<?= htmlspecialchars($google_data['last_name'] ?? '', ENT_QUOTES) ?>';
                    
                    const gDob = '<?= !empty($google_data['date_of_birth']) ? htmlspecialchars($google_data['date_of_birth'], ENT_QUOTES) : '' ?>';
                    if (regDob && gDob !== '') {
                        regDob.value = gDob;
                        regDob.classList.add('ring-2', 'ring-green-400', 'bg-green-50');
                        setTimeout(() => regDob.classList.remove('ring-2', 'ring-green-400', 'bg-green-50'), 2000);
                    }

                    const gGender = '<?= !empty($google_data['gender']) ? ucfirst(htmlspecialchars($google_data['gender'], ENT_QUOTES)) : '' ?>';
                    if (regGender && (gGender === 'Male' || gGender === 'Female')) {
                        regGender.value = gGender;
                        regGender.classList.add('ring-2', 'ring-green-400', 'bg-green-50');
                        setTimeout(() => regGender.classList.remove('ring-2', 'ring-green-400', 'bg-green-50'), 2000);
                    }

                    if (regEmail) {
                        regEmail.value = '<?= htmlspecialchars($google_data['email'] ?? '', ENT_QUOTES) ?>';
                        regEmail.readOnly = true; 
                        regEmail.classList.add('bg-slate-100', 'cursor-not-allowed', 'focus:ring-0', 'focus:border-slate-200');
                    }
                    
                    document.querySelector('#registerModal h3').innerText = 'Complete Your Profile';
                    document.querySelector('#registerModal h3 + p').innerText = 'Your basic info is pre-filled. Provide your academic and special details to finish.';

                    document.getElementById('reg_password').closest('#securitySection').classList.add('hidden');
                    document.getElementById('reg_password').required = false;
                    document.getElementById('reg_confirm').required = false;

                    const regForm = document.getElementById('registerForm');
                    let googleIdInput = document.getElementById('google_id_input');
                    if (!googleIdInput) {
                        googleIdInput = document.createElement('input');
                        googleIdInput.type = 'hidden';
                        googleIdInput.name = 'google_id';
                        googleIdInput.id = 'google_id_input';
                        regForm.appendChild(googleIdInput);
                    }
                    googleIdInput.value = '<?= htmlspecialchars($google_data['google_id'] ?? '', ENT_QUOTES) ?>';
                <?php
                }
                ?>
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const loginNum = document.getElementById('login_identifier').value.trim();
            if (!loginNum.includes('@') && loginNum.length !== 10 && /^\d+$/.test(loginNum)) {
                e.preventDefault();
                alert("⚠️ AUTHENTICATION BLOCKED:\n\nYour student number entry must contain exactly 10 numerical digits.");
            }
        });

        // ========================================================
        // ✨ CLIENT-SIDE AI FORGERY DETECTION (PRE-FLIGHT CHECKER)
        // ========================================================
        async function checkFileAuthenticity(file, isPdf) {
            return new Promise((resolve) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const content = e.target.result;
                    // Detects editing software & PDF libraries anywhere in the file (header or trailer)
                    const regex = isPdf 
                        ? /(photoshop|illustrator|canva|photopea|gimp|corel|ilovepdf|sejda|smallpdf|jspdf|pdfkit|foxit|nitro)/i 
                        : /(photoshop|gimp|canva|illustrator|corel|photopea|picsart|lightroom|snapseed|vsco)/i;
                    
                    if (regex.test(content)) {
                        resolve(false); // Tampered file detected
                        return;
                    }

                    // If it's a PDF, verify it contains actual font descriptors (not a flattened raster image)
                    if (isPdf && !/\/Font\b/.test(content)) {
                        resolve(false);
                        return;
                    }

                    resolve(true); // Clean file
                };
                reader.onerror = () => resolve(true); // Let backend handle it if reader fails
                reader.readAsText(file); // Read the entire file so trailer metadata at the bottom is caught
            });
        }

        // ========================================================
        // DUAL-CAPTURE (FRONT & BACK) WITH COMPRESSION LOGIC
        // ========================================================
        let videoStream = null;
        const videoElement = document.getElementById('cameraFeed');
        const canvasElement = document.getElementById('cameraCanvas');
        const cameraContainer = document.getElementById('cameraContainer');
        const scanMethodToggle = document.getElementById('scanMethodToggle');
        const scanArea = document.getElementById('scanArea');
        const scanAreaLabel = document.getElementById('scanAreaLabel');

        let captureState = 'front'; // 'front' or 'back'
        let frontBlob = null;
        let backBlob = null;

        function resetScanState() {
            captureState = 'front';
            frontBlob = null;
            backBlob = null;
            document.getElementById('previewContainer').classList.add('hidden');
            document.getElementById('frontPreview').classList.add('hidden');
            document.getElementById('backPreview').classList.add('hidden');
            document.getElementById('frontPreviewText').classList.remove('hidden');
            document.getElementById('backPreviewText').classList.remove('hidden');
            document.getElementById('backPreviewBox').classList.add('border-dashed', 'border-slate-400', 'opacity-70');
            document.getElementById('backPreviewBox').classList.remove('border-green-400', 'opacity-100');
            
            document.getElementById('cameraBtnText').innerText = "Take Photo Live";
            document.getElementById('uploadBtnText').innerText = "Upload Files";
            document.getElementById('scanSuccess').classList.add('hidden');
        }

        document.getElementById('fallbackCameraInput').addEventListener('change', async function() {
            if (this.files.length === 0) return;
            
            // ✨ FORGERY CHECK ON MOBILE CAMERA UPLOADS
            if (!(await checkFileAuthenticity(this.files[0], false))) {
                alert("⚠️ SECURITY ALERT:\nEditing software footprint (e.g. Photopea, GIMP) detected in the image metadata. Please upload an original, unedited photo of your ID.");
                this.value = ''; resetScanState(); return;
            }

            if (captureState === 'front') {
                frontBlob = this.files[0];
                document.getElementById('previewContainer').classList.remove('hidden');
                
                const fp = document.getElementById('frontPreview');
                fp.src = URL.createObjectURL(frontBlob); 
                fp.classList.remove('hidden');
                document.getElementById('frontPreviewText').classList.add('hidden');
                
                document.getElementById('backPreviewBox').classList.remove('border-dashed', 'border-slate-400', 'opacity-70');
                document.getElementById('backPreviewBox').classList.add('border-green-400', 'opacity-100');
                
                captureState = 'back';
                document.getElementById('cameraBtnText').innerText = "Take BACK ID Photo";
            } else {
                backBlob = this.files[0];
                const bp = document.getElementById('backPreview');
                bp.src = URL.createObjectURL(backBlob); 
                bp.classList.remove('hidden');
                document.getElementById('backPreviewText').classList.add('hidden');
                
                mergeAndProcessOCR();
            }
            this.value = ''; 
        });

        async function startCamera() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                document.getElementById('fallbackCameraInput').click();
                return;
            }

            try {
                // High resolution so the portrait crop still keeps the ID text sharp
                videoStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1920 } }
                });
                videoElement.srcObject = videoStream;
                
                cameraContainer.classList.remove('hidden');
                cameraContainer.classList.add('flex');
                scanMethodToggle.classList.add('hidden');
                
                scanArea.classList.remove('border-dashed', 'bg-green-50/50');
                scanArea.classList.add('border-solid', 'bg-slate-900', 'border-slate-800');
                scanAreaLabel.classList.replace('text-green-800', 'text-white');
                
                document.getElementById('previewContainer').classList.remove('hidden');
                updateCameraInstruction();
            } catch (err) {
                alert("Camera access denied. Falling back to native camera/upload app. \n\nError: " + err.message);
                document.getElementById('fallbackCameraInput').click();
            }
        }

        function stopCamera() {
            if (videoStream) {
                videoStream.getTracks().forEach(track => track.stop());
                videoStream = null;
            }
            cameraContainer.classList.add('hidden');
            cameraContainer.classList.remove('flex');
            scanMethodToggle.classList.remove('hidden');
            
            scanArea.classList.add('border-dashed', 'bg-green-50/50');
            scanArea.classList.remove('border-solid', 'bg-slate-900', 'border-slate-800');
            scanAreaLabel.classList.replace('text-white', 'text-green-800');
            
            if (captureState === 'front' && !frontBlob) {
                document.getElementById('previewContainer').classList.add('hidden');
            }
        }

        function updateCameraInstruction() {
            const badge = document.getElementById('camInstructionBadge');
            if(captureState === 'front') {
                badge.innerText = "Position FRONT of ID";
                badge.classList.replace('bg-blue-600', 'bg-green-600');
            } else {
                badge.innerText = "Position BACK of ID";
                badge.classList.replace('bg-green-600', 'bg-blue-600');
            }
        }

        function capturePhoto() {
            // Crop the camera frame to the portrait viewfinder (same center crop as object-cover),
            // so the captured image matches what the student saw
            const finder = document.getElementById('cameraViewfinder');
            const viewRatio = finder.clientWidth / finder.clientHeight;
            const vw = videoElement.videoWidth, vh = videoElement.videoHeight;
            let sw = vw, sh = vh;
            if (vw / vh > viewRatio) { sw = vh * viewRatio; } else { sh = vw / viewRatio; }
            const sx = (vw - sw) / 2, sy = (vh - sh) / 2;

            const context = canvasElement.getContext('2d');
            canvasElement.width = Math.round(sw);
            canvasElement.height = Math.round(sh);
            context.drawImage(videoElement, sx, sy, sw, sh, 0, 0, canvasElement.width, canvasElement.height);

            videoElement.style.opacity = '0.3';
            setTimeout(() => videoElement.style.opacity = '1', 150);

            canvasElement.toBlob(blob => {
                const imgUrl = URL.createObjectURL(blob);
                
                if (captureState === 'front') {
                    frontBlob = blob;
                    
                    const fp = document.getElementById('frontPreview');
                    fp.src = imgUrl; 
                    fp.classList.remove('hidden');
                    document.getElementById('frontPreviewText').classList.add('hidden');
                    
                    document.getElementById('backPreviewBox').classList.remove('border-dashed', 'border-slate-400', 'opacity-70');
                    document.getElementById('backPreviewBox').classList.add('border-green-400', 'opacity-100');
                    
                    captureState = 'back';
                    updateCameraInstruction();
                } else {
                    backBlob = blob;
                    
                    const bp = document.getElementById('backPreview');
                    bp.src = imgUrl; 
                    bp.classList.remove('hidden');
                    document.getElementById('backPreviewText').classList.add('hidden');
                    
                    stopCamera();
                    mergeAndProcessOCR();
                }
            }, 'image/jpeg', 0.95);
        }

        document.getElementById('idUploader').addEventListener('change', async function() {
            if (this.files.length >= 2) {
                
                // ✨ FORGERY CHECK ON MANUAL FILE UPLOADS
                if (!(await checkFileAuthenticity(this.files[0], false)) || !(await checkFileAuthenticity(this.files[1], false))) {
                    alert("⚠️ SECURITY ALERT:\nEditing software footprint (e.g. Photopea, GIMP, Canva) detected in the image metadata. Please upload original, unedited photos of your ID.");
                    this.value = ''; resetScanState(); return;
                }

                frontBlob = this.files[0];
                backBlob = this.files[1];
                
                document.getElementById('previewContainer').classList.remove('hidden');
                
                const fp = document.getElementById('frontPreview');
                fp.src = URL.createObjectURL(frontBlob); 
                fp.classList.remove('hidden');
                document.getElementById('frontPreviewText').classList.add('hidden');
                
                document.getElementById('backPreviewBox').classList.remove('border-dashed', 'border-slate-400', 'opacity-70');
                document.getElementById('backPreviewBox').classList.add('border-green-400', 'opacity-100');
                
                const bp = document.getElementById('backPreview');
                bp.src = URL.createObjectURL(backBlob); 
                bp.classList.remove('hidden');
                document.getElementById('backPreviewText').classList.add('hidden');
                
                mergeAndProcessOCR();
            } else if (this.files.length === 1) {

                // ✨ FORGERY CHECK ON SINGLE UPLOAD
                if (!(await checkFileAuthenticity(this.files[0], false))) {
                    alert("⚠️ SECURITY ALERT:\nEditing software footprint (e.g. Photopea, GIMP, Canva) detected in the image metadata. Please upload an original, unedited photo of your ID.");
                    this.value = ''; resetScanState(); return;
                }

                if (captureState === 'front') {
                    frontBlob = this.files[0];
                    document.getElementById('previewContainer').classList.remove('hidden');
                    
                    const fp = document.getElementById('frontPreview');
                    fp.src = URL.createObjectURL(frontBlob); 
                    fp.classList.remove('hidden');
                    document.getElementById('frontPreviewText').classList.add('hidden');
                    
                    document.getElementById('backPreviewBox').classList.remove('border-dashed', 'border-slate-400', 'opacity-70');
                    document.getElementById('backPreviewBox').classList.add('border-green-400', 'opacity-100');
                    
                    captureState = 'back';
                    document.getElementById('uploadBtnText').innerText = "Upload BACK ID";
                } else {
                    backBlob = this.files[0];
                    const bp = document.getElementById('backPreview');
                    bp.src = URL.createObjectURL(backBlob); 
                    bp.classList.remove('hidden');
                    document.getElementById('backPreviewText').classList.add('hidden');
                    
                    mergeAndProcessOCR();
                }
            }
            this.value = ''; 
        });

        function mergeAndProcessOCR() {
            const loader = document.getElementById('scanLoader');
            const successMsg = document.getElementById('scanSuccess');
            const toggle = document.getElementById('scanMethodToggle');
            
            loader.classList.remove('hidden');
            successMsg.classList.add('hidden');
            toggle.classList.add('hidden'); 

            const mergeCanvas = document.createElement('canvas');
            const ctx = mergeCanvas.getContext('2d');
            
            const imgFront = new Image();
            const imgBack = new Image();

            imgFront.onload = async () => {
                // ✨ 1. EXTRACT & CROP THE STUDENT PORTRAIT FROM FRONT ID
                const portraitCanvas = document.createElement('canvas');
                const pCtx = portraitCanvas.getContext('2d');
                portraitCanvas.width = 300;
                portraitCanvas.height = 300;

                // Default crop coordinates (upper-center portrait area of a vertical ID)
                let cropSize = Math.min(imgFront.width, imgFront.height) * 0.45;
                let cropX = (imgFront.width - cropSize) / 2;
                let cropY = imgFront.height * 0.18;

                // If browser supports native AI FaceDetector, lock onto the exact face on the ID!
                if ('FaceDetector' in window) {
                    try {
                        const faceDetector = new FaceDetector({ fastMode: true, maxDetectedFaces: 1 });
                        const faces = await faceDetector.detect(imgFront);
                        if (faces.length > 0) {
                            const box = faces[0].boundingBox;
                            const pad = Math.max(box.width, box.height) * 0.35;
                            cropSize = Math.max(box.width, box.height) + (pad * 2);
                            cropX = Math.max(0, box.x - pad);
                            cropY = Math.max(0, box.y - pad);
                        }
                    } catch (e) {
                        console.log("Using standard ID portrait crop coordinates.");
                    }
                }

                pCtx.drawImage(imgFront, cropX, cropY, cropSize, cropSize, 0, 0, 300, 300);
                const portraitDataUrl = portraitCanvas.toDataURL('image/jpeg', 0.85);
                
                const photoInput = document.getElementById('reg_extracted_photo');
                if (photoInput) photoInput.value = portraitDataUrl;

                const photoBox = document.getElementById('extractedPhotoBox');
                const photoPreview = document.getElementById('extractedPhotoPreview');
                if (photoBox && photoPreview) {
                    photoPreview.src = portraitDataUrl;
                    photoBox.classList.remove('hidden');
                }

                // ✨ 2. CONTINUE MERGING FRONT & BACK FOR OCR
                imgBack.onload = () => {
                    const MAX_WIDTH = 1000;

                    let fW = imgFront.width, fH = imgFront.height;
                    if(fW > MAX_WIDTH) { fH = fH * (MAX_WIDTH / fW); fW = MAX_WIDTH; }

                    let bW = imgBack.width, bH = imgBack.height;
                    if(bW > MAX_WIDTH) { bH = bH * (MAX_WIDTH / bW); bW = MAX_WIDTH; }

                    mergeCanvas.width = Math.max(fW, bW);
                    mergeCanvas.height = fH + bH;
                    
                    ctx.fillStyle = "#ffffff";
                    ctx.fillRect(0, 0, mergeCanvas.width, mergeCanvas.height);

                    ctx.drawImage(imgFront, 0, 0, fW, fH);
                    ctx.drawImage(imgBack, 0, fH, bW, bH);
                    
                    mergeCanvas.toBlob(mergedBlob => {
                        const finalFile = new File([mergedBlob], "merged_id.jpg", { type: "image/jpeg" });
                        sendToBackend(finalFile);
                    }, 'image/jpeg', 0.6); 
                };
                imgBack.src = URL.createObjectURL(backBlob);
            };
            imgFront.src = URL.createObjectURL(frontBlob);
        }

        async function sendToBackend(file) {
            const loader = document.getElementById('scanLoader');
            const successMsg = document.getElementById('scanSuccess');
            const toggle = document.getElementById('scanMethodToggle');

            const formData = new FormData();
            formData.append('id_image', file);

            try {
                const response = await fetch('actions/scan_id_ocr.php', {
                    method: 'POST',
                    body: formData
                });
                
                const textResult = await response.text();
                let result;
                try {
                    result = JSON.parse(textResult);
                } catch (e) {
                    console.error("PHP Error Output:", textResult);
                    alert("System Error: The uploaded image might be too large or the server returned an error.");
                    loader.classList.add('hidden');
                    toggle.classList.remove('hidden');
                    resetScanState();
                    return;
                }
                
                loader.classList.add('hidden');
                toggle.classList.remove('hidden'); 
                
                if (result.success) {
                    document.getElementById('reg_first_name').value = result.data.first_name || '';
                    document.getElementById('reg_last_name').value = result.data.last_name || '';
                    
                    const studNumEl = document.getElementById('reg_student_number');
                    studNumEl.value = result.data.student_id || '';
                    if(studNumEl.value.length === 10) {
                        checkAccountAvailability({ student_number: studNumEl.value });
                    }

                    if (result.data.date_of_birth) {
                        const dobInput = document.getElementById('reg_dob');
                        if (dobInput) {
                            dobInput.value = result.data.date_of_birth;
                            dobInput.classList.add('ring-2', 'ring-green-400', 'bg-green-50');
                            setTimeout(() => dobInput.classList.remove('ring-2', 'ring-green-400', 'bg-green-50'), 2000);
                        }
                    }

                    // Program is matched on the server and stored in the session; this only displays it
                    const programField = document.getElementById('reg_course');
                    if (programField) {
                        if (result.data.program_name) {
                            programField.value = result.data.program_name;
                            programField.classList.add('ring-2', 'ring-green-400', 'bg-green-50');
                            setTimeout(() => programField.classList.remove('ring-2', 'ring-green-400', 'bg-green-50'), 2000);
                        } else {
                            programField.value = '';
                            alert("We couldn't read your program from the ID. Please scan a clearer photo of your student ID.");
                        }
                    }

                    successMsg.classList.remove('hidden');
                    
                    ['reg_first_name', 'reg_last_name', 'reg_student_number'].forEach(id => {
                        const el = document.getElementById(id);
                        if(el && el.value !== '') {
                            el.classList.add('ring-2', 'ring-green-400', 'bg-green-50');
                            setTimeout(() => el.classList.remove('ring-2', 'ring-green-400', 'bg-green-50'), 2000);
                        }
                    });

                    setTimeout(resetScanState, 3000); 
                } else {
                    alert(result.error);
                    resetScanState();
                }
            } catch (error) {
                alert("System error during AI OCR scan. Please check your console.");
                loader.classList.add('hidden');
                toggle.classList.remove('hidden');
                resetScanState();
            }
        }

        // ✨ BULLETPROOF AI ROG COMPUTATION LOGIC WITH FORGERY DETECTION
        async function processROG(input) {
            if (input.files.length === 0) return;
            
            const file = input.files[0];
            if (file.type !== "application/pdf") {
                alert("Please upload a valid PDF file.");
                input.value = '';
                return;
            }

            const MAX_MB = 10;
            if (file.size > MAX_MB * 1024 * 1024) {
                alert(`⚠️ FILE TOO LARGE:\n\nYour PDF is ${(file.size / (1024 * 1024)).toFixed(2)}MB. Maximum allowed file size is ${MAX_MB}MB.`);
                input.value = '';
                return;
            }

            const loader = document.getElementById('rog_loader');
            const resultBox = document.getElementById('rog_result');
            const statedEl = document.getElementById('stated_gwa');
            const computedEl = document.getElementById('computed_gwa');
            const unitsEl = document.getElementById('computed_units'); 
            const statusEl = document.getElementById('rog_status');
            const hiddenGpa = document.getElementById('reg_gpa');
            const hiddenUnits = document.getElementById('reg_units'); 

            if (loader) loader.classList.remove('hidden');
            if (resultBox) resultBox.classList.add('hidden');
            if (hiddenGpa) hiddenGpa.value = '';
            if (hiddenUnits) hiddenUnits.value = '';

            // ✨ PRE-FLIGHT AUTHENTICITY CHECK FOR PDF (Detects Photopea, GIMP, Canva, etc.)
            const isAuthentic = await checkFileAuthenticity(file, true);
            if (!isAuthentic) {
                if (loader) loader.classList.add('hidden');
                alert("⚠️ SECURITY ALERT:\nDigital tampering or editing software footprint (Photopea, Photoshop, Canva, etc.) detected in the file metadata. Please upload the original, unedited Report of Grades.");
                input.value = '';
                return;
            }

            // Normal processing if authentic
            const formData = new FormData();
            formData.append('rog_pdf', file);

            try {
                const response = await fetch('actions/scan_rog_pdf.php', {
                    method: 'POST',
                    body: formData
                });
                
                const rawText = await response.text(); 
                
                let result;
                try {
                    result = JSON.parse(rawText);
                } catch (jsonError) {
                    if (loader) loader.classList.add('hidden');
                    alert("⚠️ BACKEND ERROR:\nServer did not return a valid JSON. Check if your PDF is too large.\n\nRaw output:\n" + rawText.substring(0, 200));
                    input.value = '';
                    return;
                }
                
                if (loader) loader.classList.add('hidden');
                
                if (result.success) {
                    if (resultBox) resultBox.classList.remove('hidden');
                    if (statedEl) statedEl.innerText = result.stated_gwa;
                    if (computedEl) computedEl.innerText = result.computed_gwa;
                    if (unitsEl) unitsEl.innerText = (result.total_units || '0') + ' Units';

                    if (result.is_valid) {
                        if (statusEl) {
                            statusEl.className = "mt-3 text-[10px] uppercase tracking-widest text-center py-1.5 rounded-lg bg-green-100 text-green-700 font-black border border-green-200";
                            statusEl.innerText = "✅ Grades Verified & Match!";
                        }
                        if (hiddenGpa) hiddenGpa.value = result.computed_gwa; 
                        if (hiddenUnits) hiddenUnits.value = result.total_units || 0;
                        
                    } else {
                        if (statusEl) {
                            statusEl.className = "mt-3 text-[10px] uppercase tracking-widest text-center py-1.5 rounded-lg bg-red-100 text-red-700 font-black border border-red-200";
                            statusEl.innerText = "❌ Warning: Computed grades do not match the stated GWA.";
                        }
                        input.value = ''; 
                        alert("The computed average (" + result.computed_gwa + ") does not match the GWA on the document (" + result.stated_gwa + "). Please upload a legitimate Report of Grades.");
                        
                        if (resultBox) resultBox.classList.add('hidden');
                    }
                } else {
                    let errorMessage = "The document could not be analyzed. Please ensure it is a clear Report of Grades.";
                    if (result.error) errorMessage = result.error;
                    else if (result.message) errorMessage = result.message;
                    else if (result.reason) errorMessage = result.reason;
                    else if (typeof result === 'string') errorMessage = result;

                    alert("⚠️ AI SCANNER ERROR:\n" + errorMessage);
                    input.value = '';
                }
            } catch (error) {
                if (loader) loader.classList.add('hidden');
                alert("⚠️ SYSTEM ERROR:\n" + error.message);
                console.error(error);
                input.value = '';
            }

            
        }
    </script>
<?php include 'includes/chatbot.php'; ?>
</body>
</html>