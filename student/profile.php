<?php
session_start();

// 🔒 1. SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../student_login.php"); exit();
}

include '../includes/db_connect.php';
include '../includes/header.php';
include '../includes/student_sidebar.php';

$user_id = $_SESSION['user_id'];

try {
    // Fetch latest user data
    $stmt = $pdo->prepare("SELECT u.*, p.ProgramName FROM users u LEFT JOIN program p ON u.ProgramID = p.ProgramID WHERE u.UserID = :uid");
    $stmt->execute(['uid' => $user_id]);
    $user = $stmt->fetch();

    // ✨ Changes outside Personal Details wait for the Super Admin (includes/profile_fields.php).
    // The form shows the requested values, each marked with what is on record now.
    require_once '../includes/profile_fields.php';
    $pending_request = pendingProfileRequest($pdo, (int) $user_id);
    $pending_fields = [];
    foreach ($pending_request['ChangesList'] ?? [] as $field => $change) {
        if (!isset(PROFILE_APPROVAL_FIELDS[$field])) continue;
        $user[PROFILE_APPROVAL_FIELDS[$field][0]] = $change['to'];
        $pending_fields[$field] = profileValueText($change['from']);
    }
    // The latest decision on an earlier request (shown for two weeks)
    $decision_stmt = $pdo->prepare("SELECT Status, ReviewNote, ReviewedAt, Changes FROM profile_change_requests
        WHERE UserID = ? AND Status IN ('Approved', 'Rejected') AND ReviewedAt > NOW() - INTERVAL 14 DAY ORDER BY ReviewedAt DESC, RequestID DESC LIMIT 1");
    $decision_stmt->execute([$user_id]);
    $last_decision = $pending_request ? null : $decision_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { 
    die("Profile Fetch Error: " . $e->getMessage()); 
}

// Profile Picture logic: Use DB path or dynamic avatar if empty
$profile_pic = !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'https://ui-avatars.com/api/?name=' . urlencode($user['FirstName'] . ' ' . $user['LastName']) . '&background=059669&color=fff&size=256';
?>

<main class="flex-1 p-5 lg:p-12 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-500">
    <header class="mb-12">
        <h2 class="text-4xl font-black text-slate-900 tracking-tight">My Profile</h2>
        <p class="text-slate-500 font-medium mt-1">Personal Information & Comprehensive Data</p>
    </header>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-8 py-5 rounded-[2rem] mb-10 font-black text-[14px] uppercase tracking-widest flex items-center gap-4 shadow-sm">
            <span class="text-lg">✅</span> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-8 py-5 rounded-[2rem] mb-10 font-black text-[14px] uppercase tracking-widest flex items-center gap-4 shadow-sm">
            <span class="text-lg">⚠️</span> <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-12 items-start">
        
        <!-- SIDEBAR: PROFILE PICTURE & QUICK STATS -->
        <div class="xl:col-span-1">
            <div class="bg-slate-900 rounded-[3.5rem] p-12 text-center shadow-2xl relative overflow-hidden group border border-white/5 sticky top-10">
                <div class="absolute inset-0 bg-gradient-to-b from-emerald-600/20 to-transparent pointer-events-none"></div>
                
                <div class="relative z-10">
                    <div class="relative w-44 h-44 mx-auto mb-10">
                        <div class="w-full h-full rounded-full border-4 border-white/20 shadow-2xl overflow-hidden bg-white ring-8 ring-white/5">
                            <img id="previewImage" src="<?= htmlspecialchars($profile_pic) ?>" alt="Profile" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110">
                            <label for="profileUpload" class="absolute inset-0 bg-slate-900/60 flex flex-col items-center justify-center text-white opacity-0 group-hover:opacity-100 transition-all duration-300 cursor-pointer backdrop-blur-sm">
                                <span class="text-[9px] font-black uppercase tracking-widest mb-1">Update</span>
                                <span class="text-[12px]">📸</span>
                            </label>
                        </div>
                    </div>
                    
                    <button type="button" onclick="openProfileModal('<?= htmlspecialchars($profile_pic) ?>')" class="mb-10 bg-white/10 hover:bg-white/20 text-white border border-white/10 px-8 py-3.5 rounded-2xl text-[9px] font-black uppercase tracking-[0.2em] transition-all active:scale-95 shadow-lg">
                        View Full Photo 👁️
                    </button>
                    
                    <h3 class="text-2xl font-black text-white leading-tight uppercase tracking-tight"><?= htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']) ?></h3>
                    <p class="text-emerald-400 font-black mt-2 uppercase text-[12px] tracking-[0.2em]"><?= htmlspecialchars($user['ProgramName'] ?? 'ScholarLink Student') ?></p>
                </div>
            </div>
        </div>

        <!-- MAIN CONTENT: COMPREHENSIVE FORM -->
        <div class="xl:col-span-2">
            <div class="bg-blue-50 border border-blue-200 text-blue-900 rounded-[1.5rem] p-5 mb-6 text-sm font-medium leading-relaxed">
                <b>✏️ Personal Details and your photo</b> save right away.
                <b>🔒 Everything else</b> (student ID, email, year level, IDs, address, family, special status) is sent to the Super Admin, and changes only after they approve it.
            </div>

            <?php if ($pending_request): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-[1.5rem] p-5 mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-black text-amber-800">⏳ Waiting for Super Admin approval</p>
                            <p class="text-xs font-medium text-amber-700 mt-1">Sent <?= date('M j, Y g:i A', strtotime($pending_request['CreatedAt'])) ?>. Requested changes are shown in the form, marked in amber.</p>
                        </div>
                        <form action="../actions/process_profile.php" method="POST" class="m-0 shrink-0" onsubmit="return confirm('Cancel your change request? Nothing will be changed.');">
                            <input type="hidden" name="action" value="cancel_request">
                            <button type="submit" class="bg-white border border-amber-300 text-amber-800 px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-amber-100">Cancel request</button>
                        </form>
                    </div>
                    <ul class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-xs text-amber-900">
                        <?php foreach ($pending_request['ChangesList'] as $field => $change): if (!isset(PROFILE_APPROVAL_FIELDS[$field])) continue; ?>
                            <li><b><?= htmlspecialchars(PROFILE_APPROVAL_FIELDS[$field][1]) ?>:</b> <?= htmlspecialchars(profileValueText($change['from'])) ?> → <?= htmlspecialchars(profileValueText($change['to'])) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php elseif ($last_decision && $last_decision['Status'] === 'Rejected'): ?>
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-[1.5rem] p-5 mb-6 text-sm">
                    <b>Your last change request was not approved</b> (<?= date('M j', strtotime($last_decision['ReviewedAt'])) ?>).
                    <?php if ($last_decision['ReviewNote']): ?><br><span class="font-medium">Reason: <?= htmlspecialchars($last_decision['ReviewNote']) ?></span><?php endif; ?>
                </div>
            <?php elseif ($last_decision): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-[1.5rem] p-5 mb-6 text-sm">
                    <b>✅ Your last change request was approved</b> (<?= date('M j', strtotime($last_decision['ReviewedAt'])) ?>), and your profile is up to date.
                </div>
            <?php endif; ?>

            <form action="../actions/process_profile.php" method="POST" enctype="multipart/form-data" id="profileForm" class="bg-white rounded-[2.5rem] border border-slate-200/80 shadow-sm p-6 sm:p-10 space-y-10">
                <input type="file" id="profileUpload" name="profile_picture" accept="image/jpeg, image/png, image/webp" class="hidden">
                
                <!-- 1. ACCOUNT ACCESS -->
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-6 w-1.5 bg-slate-900 rounded-full"></div>
                        <h4 class="text-lg font-black text-slate-900 tracking-tight uppercase">Account Access <span class="ml-2 px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-amber-700 text-[9px] font-black uppercase tracking-widest align-middle" title="Changes here need the Super Admin's approval">🔒 Needs approval</span></h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Student ID</label>
                            <input type="text" name="student_number" value="<?= htmlspecialchars($user['StudentID_Num']) ?>" required maxlength="10" pattern="\d{10}" title="Student ID must be exactly 10 digits" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Email Address</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($user['Email']) ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all text-sm truncate">
                        </div>
                    </div>
                </div>

                <div class="h-px bg-slate-100"></div>

                <!-- ACADEMIC INFORMATION -->
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-6 w-1.5 bg-emerald-600 rounded-full"></div>
                        <h4 class="text-lg font-black text-slate-900 tracking-tight uppercase">Academic Information <span class="ml-2 px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-amber-700 text-[9px] font-black uppercase tracking-widest align-middle" title="Changes here need the Super Admin's approval">🔒 Needs approval</span></h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                        <div class="md:col-span-2 space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Program / Course <span class="text-slate-400 font-normal normal-case tracking-normal">(from your student ID)</span></label>
                            <div class="flex gap-2">
                                <input type="text" value="<?= htmlspecialchars($user['ProgramName'] ?? 'Not yet detected') ?>" disabled class="flex-1 min-w-0 bg-slate-100 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-500 cursor-not-allowed text-sm truncate">
                                <!-- ✨ Program can only change through shifting: scan or upload the new ID (see #shiftModal below) -->
                                <button type="button" onclick="openShiftModal()" class="shrink-0 bg-white border border-slate-200 px-4 py-3.5 rounded-xl text-[11px] font-black text-slate-600 uppercase tracking-widest cursor-pointer hover:bg-slate-900 hover:text-white hover:border-slate-900 transition-all" title="Shifted to another program? Scan your updated student ID">
                                    Shifted? Scan New ID
                                </button>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Year Level</label>
                            <select name="year_level" required class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm cursor-pointer">
                                <option value="">Select year level...</option>
                                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yl): ?>
                                    <option value="<?= $yl ?>" <?= ($user['YearLevel'] === $yl) ? 'selected' : '' ?>><?= $yl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="h-px bg-slate-100"></div>

                <!-- 2. PERSONAL INFORMATION -->
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-6 w-1.5 bg-emerald-600 rounded-full"></div>
                        <h4 class="text-lg font-black text-slate-900 tracking-tight uppercase">Personal Details <span class="ml-2 px-2 py-0.5 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-700 text-[9px] font-black uppercase tracking-widest align-middle">✏️ Saves right away</span></h4>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-5">
                        <div class="md:col-span-2 space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">First Name</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($user['FirstName']) ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="md:col-span-2 space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Last Name</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($user['LastName']) ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="md:col-span-3 space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Middle Name</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($user['MiddleName'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="md:col-span-1 space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Ext Name</label>
                            <input type="text" name="ext_name" value="<?= htmlspecialchars($user['ExtName'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Gender</label>
                            <select name="gender" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm cursor-pointer">
                                <option value="Male" <?= ($user['Gender'] == 'Male') ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($user['Gender'] == 'Female') ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Citizenship</label>
                            <input type="text" name="citizenship" value="<?= htmlspecialchars($user['Citizenship'] ?? 'Filipino') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Contact No.</label>
                            <input type="text" name="contact_number" id="phone_mask" value="<?= htmlspecialchars($user['ContactNumber'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Birth Date</label>
                            <input type="date" name="date_of_birth" value="<?= htmlspecialchars($user['DateOfBirth'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm cursor-pointer">
                        </div>
                    </div>
                </div>

                <div class="h-px bg-slate-100"></div>

                <!-- 3. IDENTIFICATIONS -->
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-6 w-1.5 bg-emerald-600 rounded-full"></div>
                        <h4 class="text-lg font-black text-slate-900 tracking-tight uppercase">Identifications <span class="ml-2 px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-amber-700 text-[9px] font-black uppercase tracking-widest align-middle" title="Changes here need the Super Admin's approval">🔒 Needs approval</span></h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">LRN</label>
                            <input type="text" name="lrn" value="<?= htmlspecialchars($user['LRN'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">PhilSys ID</label>
                            <input type="text" name="philsys_id" value="<?= htmlspecialchars($user['PhilSysID'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">4Ps ID No.</label>
                            <input type="text" name="fourps_id" value="<?= htmlspecialchars($user['FourPsID'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                    </div>
                </div>

                <div class="h-px bg-slate-100"></div>

                <!-- 4. PERMANENT ADDRESS -->
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-6 w-1.5 bg-emerald-600 rounded-full"></div>
                        <h4 class="text-lg font-black text-slate-900 tracking-tight uppercase">Permanent Address <span class="ml-2 px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-amber-700 text-[9px] font-black uppercase tracking-widest align-middle" title="Changes here need the Super Admin's approval">🔒 Needs approval</span></h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="md:col-span-2 space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Street & Barangay</label>
                            <input type="text" name="street_brgy" value="<?= htmlspecialchars($user['StreetBarangay'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">City / Municipality</label>
                            <input type="text" name="city" value="<?= htmlspecialchars($user['CityMunicipality'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Province</label>
                            <input type="text" name="province" value="<?= htmlspecialchars($user['Province'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Region</label>
                            <input type="text" name="region" value="<?= htmlspecialchars($user['Region'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Zip Code</label>
                            <input type="text" name="zipcode" value="<?= htmlspecialchars($user['ZipCode'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                    </div>
                </div>

                <div class="h-px bg-slate-100"></div>

                <!-- 5. FAMILY BACKGROUND -->
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-6 w-1.5 bg-emerald-600 rounded-full"></div>
                        <h4 class="text-lg font-black text-slate-900 tracking-tight uppercase">Family Background <span class="ml-2 px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-amber-700 text-[9px] font-black uppercase tracking-widest align-middle" title="Changes here need the Super Admin's approval">🔒 Needs approval</span></h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Father First Name</label>
                            <input type="text" name="father_fn" value="<?= htmlspecialchars($user['FatherFirstName'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Father Middle Name</label>
                            <input type="text" name="father_mn" value="<?= htmlspecialchars($user['FatherMiddleName'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Father Last Name</label>
                            <input type="text" name="father_ln" value="<?= htmlspecialchars($user['FatherLastName'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Mother First Name</label>
                            <input type="text" name="mother_fn" value="<?= htmlspecialchars($user['MotherFirstName'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Mother Middle Name</label>
                            <input type="text" name="mother_mn" value="<?= htmlspecialchars($user['MotherMiddleName'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Mother Maiden Last Name</label>
                            <input type="text" name="mother_ln" value="<?= htmlspecialchars($user['MotherLastName'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                    </div>
                </div>

                <div class="h-px bg-slate-100"></div>

                <!-- 6. SPECIAL STATUS -->
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-6 w-1.5 bg-emerald-600 rounded-full"></div>
                        <h4 class="text-lg font-black text-slate-900 tracking-tight uppercase">Special Status <span class="ml-2 px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-amber-700 text-[9px] font-black uppercase tracking-widest align-middle" title="Changes here need the Super Admin's approval">🔒 Needs approval</span></h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Disability Type <span class="text-slate-400 font-normal lowercase tracking-normal">(Leave blank if none)</span></label>
                            <input type="text" name="disability" value="<?= htmlspecialchars($user['DisabilityType'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Indigenous Group <span class="text-slate-400 font-normal lowercase tracking-normal">(Leave blank if none)</span></label>
                            <input type="text" name="indigenous" value="<?= htmlspecialchars($user['IndigenousGroup'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm truncate">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">Solo Parent Child?</label>
                            <select name="is_solo_parent" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm cursor-pointer">
                                <option value="No" <?= ($user['IsSoloParentChild'] == 'No') ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($user['IsSoloParentChild'] == 'Yes') ? 'selected' : '' ?>>Yes</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-wider ml-1">First-Gen College Student?</label>
                            <select name="is_first_gen" class="w-full bg-slate-50 border border-slate-200 px-4 py-3.5 rounded-xl font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none transition-all text-sm cursor-pointer">
                                <option value="No" <?= ($user['IsFirstGenStudent'] == 'No') ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($user['IsFirstGenStudent'] == 'Yes') ? 'selected' : '' ?>>Yes</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit" class="w-full bg-slate-900 text-white py-5 rounded-[1.5rem] font-black text-xs uppercase tracking-widest hover:bg-emerald-600 shadow-xl shadow-slate-200 transition-all hover:-translate-y-1 active:scale-95 flex items-center justify-center gap-3">
                        <i class="fas fa-save"></i> Save / Send for Approval
                    </button>
                </div>
            </form>

        </div>
    </div>
</main>

<div id="fullPhotoModal" class="fixed inset-0 bg-slate-900/90 backdrop-blur-md z-[9999] hidden items-center justify-center p-8">
    <div class="bg-white rounded-[4rem] shadow-2xl overflow-hidden max-w-xl w-full p-3 animate-in fade-in zoom-in duration-300">
        <div class="relative">
            <img id="modalFullImage" src="" class="w-full aspect-square object-cover rounded-[3.5rem] shadow-inner">
            <button onclick="closeProfileModal()" class="absolute top-8 right-8 w-14 h-14 bg-white/90 backdrop-blur-sm rounded-[1.5rem] flex items-center justify-center text-slate-900 font-bold text-2xl shadow-2xl hover:bg-red-500 hover:text-white transition-all transform hover:rotate-90">
                &times;
            </button>
        </div>
    </div>
</div>

<script>
    // Fields waiting for approval: amber outline and what is on record now
    (function () {
        const pending = <?= json_encode($pending_fields, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        Object.entries(pending).forEach(([name, current]) => {
            const field = document.querySelector('#profileForm [name="' + name + '"]');
            if (!field) return;
            field.classList.add('ring-2', 'ring-amber-300', 'border-amber-300');
            const note = document.createElement('p');
            note.className = 'text-[11px] font-bold text-amber-700 ml-1 mt-1';
            note.textContent = '⏳ Pending approval · on record now: ' + current;
            field.insertAdjacentElement('afterend', note);
        });
    })();

    // 1. MODAL LOGIC
    function openProfileModal(imgSrc) {
        const modal = document.getElementById('fullPhotoModal');
        const modalImg = document.getElementById('modalFullImage');
        modalImg.src = imgSrc;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }

    function closeProfileModal() {
        const modal = document.getElementById('fullPhotoModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = 'auto';
    }

    window.addEventListener('click', function(e) {
        if (e.target.id === 'fullPhotoModal') closeProfileModal();
    });

    // 2. LIVE IMAGE PREVIEW
    document.addEventListener("DOMContentLoaded", function() {
        const profileUpload = document.getElementById('profileUpload');
        if (profileUpload) {
            profileUpload.addEventListener('change', function() {
                const preview = document.getElementById('previewImage');
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onloadend = () => { 
                        preview.src = reader.result;
                        document.getElementById('modalFullImage').src = reader.result;
                    }
                    reader.readAsDataURL(file);
                }
            });
        }

        // 3. ADVANCED PHONE NUMBER MASKING
        const phoneInput = document.getElementById('phone_mask');
        if (phoneInput) {
            let realPhone = phoneInput.value;

            function formatDashes(val) {
                let v = val.replace(/\D/g, '').substring(0, 11);
                if (v.length > 7) v = v.substring(0, 4) + '-' + v.substring(4, 7) + '-' + v.substring(7);
                else if (v.length > 4) v = v.substring(0, 4) + '-' + v.substring(4);
                return v;
            }

            function hideDigits(val) {
                if (val.length < 9) return val;
                return val.substring(0, 5) + '***-****';
            }

            // Initialization
            phoneInput.value = hideDigits(formatDashes(realPhone));

            phoneInput.addEventListener('focus', function() {
                this.value = realPhone;
            });

            phoneInput.addEventListener('blur', function() {
                realPhone = formatDashes(this.value);
                this.value = hideDigits(realPhone);
            });

            phoneInput.addEventListener('input', function() {
                this.value = formatDashes(this.value);
                realPhone = this.value;
            });

            phoneInput.addEventListener('keypress', function(e) {
                if (!/[0-9]/.test(e.key)) e.preventDefault();
            });

            // Clean submission
            const form = document.getElementById('profileForm');
            if (form) {
                form.addEventListener('submit', function() {
                    phoneInput.value = realPhone.replace(/-/g, ''); 
                });
            }
        }
    });
</script>

<!-- ✨ PROGRAM SHIFT MODAL: scan the new ID with the camera or upload a photo of it -->
<div id="shiftModal" class="hidden fixed inset-0 z-[9999] bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
    <form id="shiftForm" action="../actions/process_program_shift.php" method="POST" enctype="multipart/form-data" class="bg-white w-full max-w-lg max-h-[95vh] overflow-y-auto rounded-[2rem] shadow-2xl">
        <div class="p-6 sm:p-8 border-b border-slate-100">
            <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Update Program (Shifting)</h3>
            <p class="text-sm text-slate-500 font-medium mt-1">Your new program will be read from your updated student ID. The ID must show your own student number.</p>
        </div>

        <div class="p-6 sm:p-8 space-y-5">
            <div class="grid grid-cols-2 gap-2 bg-slate-100 p-1 rounded-xl">
                <button type="button" id="shiftTabCamera" onclick="switchShiftTab('camera')" class="py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all">📷 Scan with Camera</button>
                <button type="button" id="shiftTabUpload" onclick="switchShiftTab('upload')" class="py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all">📁 Upload File</button>
            </div>

            <!-- CAMERA (real-time) -->
            <div id="shiftCameraPane" class="space-y-3">
                <!-- Portrait viewfinder with an upright ID-card guide (54 x 86 mm) -->
                <div id="shiftViewfinder" class="relative bg-slate-900 rounded-2xl overflow-hidden aspect-[3/4] h-[50vh] max-h-[460px] max-w-full mx-auto">
                    <video id="shiftVideo" autoplay playsinline muted class="w-full h-full object-cover"></video>
                    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 h-[88%] aspect-[54/86] border-2 border-dashed border-white/70 rounded-xl pointer-events-none"></div>
                    <p id="shiftCameraMsg" class="hidden absolute inset-0 flex items-center justify-center text-center text-white text-sm font-bold p-6"></p>
                </div>
                <p class="text-[11px] font-bold text-slate-400 text-center">Hold your ID upright inside the frame, keep it steady, then capture.</p>
                <button type="button" id="shiftCaptureBtn" onclick="captureShiftId()" class="w-full bg-emerald-600 text-white py-3 rounded-xl text-[13px] font-black uppercase tracking-widest hover:bg-emerald-700 transition-all">Capture ID</button>
            </div>

            <!-- FILE UPLOAD -->
            <div id="shiftUploadPane" class="hidden">
                <label for="shiftIdUpload" class="block border-2 border-dashed border-slate-200 rounded-2xl p-8 text-center cursor-pointer hover:border-emerald-400 hover:bg-emerald-50/50 transition-all">
                    <span class="block text-3xl mb-2">🪪</span>
                    <span class="block text-sm font-black text-slate-700">Choose a photo of your ID</span>
                    <span class="block text-[11px] font-bold text-slate-400 mt-1">JPG, PNG or WEBP</span>
                </label>
                <input type="file" id="shiftIdUpload" name="id_image" accept="image/jpeg, image/png, image/webp" class="hidden" onchange="showShiftPreview(this.files[0])">
            </div>

            <!-- PREVIEW (shared) -->
            <div id="shiftPreviewWrap" class="hidden space-y-2">
                <img id="shiftPreview" alt="ID preview" class="max-w-full max-h-[50vh] mx-auto rounded-2xl border border-slate-200 object-contain">
                <button type="button" onclick="resetShiftImage()" class="w-full text-[12px] font-black text-slate-500 uppercase tracking-widest hover:text-slate-900">↺ Retake / Choose Another</button>
            </div>
        </div>

        <div class="p-6 sm:p-8 pt-0 flex gap-3">
            <button type="button" onclick="closeShiftModal()" class="flex-1 bg-white border border-slate-200 text-slate-600 py-3 rounded-xl text-[13px] font-black uppercase tracking-widest hover:bg-slate-50">Cancel</button>
            <button type="submit" id="shiftSubmitBtn" disabled class="flex-1 bg-slate-900 text-white py-3 rounded-xl text-[13px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all disabled:opacity-40 disabled:cursor-not-allowed">Update Program</button>
        </div>
    </form>
</div>

<script>
    // ========================================================
    // ✨ PROGRAM SHIFT: CAMERA SCAN OR FILE UPLOAD
    // Both paths put the image into #shiftIdUpload, which #shiftForm submits.
    // ========================================================
    let shiftStream = null;

    function openShiftModal() {
        document.getElementById('shiftModal').classList.remove('hidden');
        switchShiftTab('camera');
    }

    function closeShiftModal() {
        stopShiftCamera();
        resetShiftImage(false);
        document.getElementById('shiftModal').classList.add('hidden');
    }

    function switchShiftTab(tab) {
        const isCamera = tab === 'camera';
        document.getElementById('shiftCameraPane').classList.toggle('hidden', !isCamera);
        document.getElementById('shiftUploadPane').classList.toggle('hidden', isCamera);
        document.getElementById('shiftTabCamera').className = 'py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all ' + (isCamera ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500');
        document.getElementById('shiftTabUpload').className = 'py-2.5 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all ' + (!isCamera ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500');
        resetShiftImage(isCamera);
        if (!isCamera) stopShiftCamera();
    }

    async function startShiftCamera() {
        const msg = document.getElementById('shiftCameraMsg');
        const captureBtn = document.getElementById('shiftCaptureBtn');
        msg.classList.add('hidden');
        captureBtn.disabled = false;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            msg.textContent = 'Camera is not available in this browser. Please use Upload File instead.';
            msg.classList.remove('hidden');
            captureBtn.disabled = true;
            return;
        }
        try {
            if (!shiftStream) {
                shiftStream = await navigator.mediaDevices.getUserMedia({
                    // High resolution so the portrait crop still keeps the ID text sharp
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1920 } }
                });
            }
            document.getElementById('shiftVideo').srcObject = shiftStream;
        } catch (e) {
            msg.textContent = 'Camera access was blocked or no camera was found. Please allow camera access or use Upload File instead.';
            msg.classList.remove('hidden');
            captureBtn.disabled = true;
        }
    }

    function stopShiftCamera() {
        if (shiftStream) {
            shiftStream.getTracks().forEach(track => track.stop());
            shiftStream = null;
        }
        document.getElementById('shiftVideo').srcObject = null;
    }

    function captureShiftId() {
        const video = document.getElementById('shiftVideo');
        if (!video.videoWidth) return;

        // Crop the camera frame to the portrait viewfinder (same center crop as object-cover),
        // so the captured image matches what the student saw
        const finder = document.getElementById('shiftViewfinder');
        const viewRatio = finder.clientWidth / finder.clientHeight;
        const vw = video.videoWidth, vh = video.videoHeight;
        let sw = vw, sh = vh;
        if (vw / vh > viewRatio) { sw = vh * viewRatio; } else { sh = vw / viewRatio; }
        const sx = (vw - sw) / 2, sy = (vh - sh) / 2;

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(sw);
        canvas.height = Math.round(sh);
        canvas.getContext('2d').drawImage(video, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

        canvas.toBlob(blob => {
            // Put the captured frame into the same file input used by Upload File
            const file = new File([blob], 'id_capture.jpg', { type: 'image/jpeg' });
            const transfer = new DataTransfer();
            transfer.items.add(file);
            document.getElementById('shiftIdUpload').files = transfer.files;

            stopShiftCamera();
            document.getElementById('shiftCameraPane').classList.add('hidden');
            showShiftPreview(file);
        }, 'image/jpeg', 0.9);
    }

    function showShiftPreview(file) {
        if (!file) return;
        document.getElementById('shiftPreview').src = URL.createObjectURL(file);
        document.getElementById('shiftPreviewWrap').classList.remove('hidden');
        document.getElementById('shiftUploadPane').classList.add('hidden');
        document.getElementById('shiftSubmitBtn').disabled = false;
    }

    // Clears the chosen image; on the camera tab it restarts the live preview
    function resetShiftImage(restartCamera = true) {
        document.getElementById('shiftIdUpload').value = '';
        document.getElementById('shiftPreviewWrap').classList.add('hidden');
        document.getElementById('shiftSubmitBtn').disabled = true;

        const cameraActive = document.getElementById('shiftTabCamera').classList.contains('bg-white');
        if (cameraActive) {
            document.getElementById('shiftCameraPane').classList.remove('hidden');
            if (restartCamera) startShiftCamera();
        } else {
            document.getElementById('shiftUploadPane').classList.remove('hidden');
        }
    }

    document.getElementById('shiftForm').addEventListener('submit', function () {
        const btn = document.getElementById('shiftSubmitBtn');
        btn.disabled = true;
        btn.textContent = 'Reading your ID...';
        stopShiftCamera();
    });
</script>
<?php include '../includes/footer.php'; ?>