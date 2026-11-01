<?php
session_start();

// ✨ 1. SESSION MANAGER (Absolute top, checks for timeouts immediately)
include '../includes/session_manager.php';

// 🛑 2. THE BACK BUTTON KILLER (Crucial for Super Admin security!)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 🔒 3. THE SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') {
    session_destroy();
    header("Location: ../admin_login.php"); 
    exit();
}
include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/super_sidebar.php'; 

try {
    // Each user with what matters for their role: program and applications (students), scholarships owned (providers),
    // and their last sign-in from the audit trail
    $stmt = $pdo->query("
        SELECT u.*, p.ProgramName,
               (SELECT COUNT(*) FROM application a WHERE a.UserID = u.UserID) AS AppCount,
               (SELECT COUNT(*) FROM application a WHERE a.UserID = u.UserID AND a.Status = 'Approved') AS ScholarCount,
               (SELECT COUNT(*) FROM scholarship s WHERE s.CreatedBy = u.UserID) AS ScholarshipCount,
               ll.LastLogin
        FROM users u
        LEFT JOIN program p ON p.ProgramID = u.ProgramID
        LEFT JOIN (SELECT UserID, MAX(ActionDate) AS LastLogin FROM audit_log WHERE ActionPerformed = 'Login' GROUP BY UserID) ll ON ll.UserID = u.UserID
        ORDER BY FIELD(u.Role, 'Super_Admin', 'Internal_Admin', 'External_Admin', 'Student'), u.FirstName, u.LastName");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $users = [];
}

// Friendlier names for the roles stored in the database
const USER_ROLE_LABELS = ['Student' => 'Student', 'Internal_Admin' => 'OSSD (Internal Admin)',
                          'External_Admin' => 'Scholarship Provider', 'Super_Admin' => 'Super Admin'];

// Counts for the summary tiles and the filters
$role_counts = array_count_values(array_column($users, 'Role'));
$status_counts = array_count_values(array_map(fn($u) => ($u['AccountStatus'] ?? 'Active') ?: 'Active', $users));
$students_applied = count(array_filter($users, fn($u) => $u['Role'] === 'Student' && $u['AppCount'] > 0));
$students_total = $role_counts['Student'] ?? 0;
$year_counts = array_count_values(array_map(fn($u) => $u['YearLevel'] ?: 'Not set', array_filter($users, fn($u) => $u['Role'] === 'Student')));
ksort($year_counts);
$role_style = ['Student' => 'bg-blue-50 text-blue-700 border-blue-200', 'Internal_Admin' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
               'External_Admin' => 'bg-amber-50 text-amber-700 border-amber-200', 'Super_Admin' => 'bg-purple-50 text-purple-700 border-purple-200'];
$role_icon = ['Student' => '🎓', 'Internal_Admin' => '🏛️', 'External_Admin' => '🏢', 'Super_Admin' => '🛡️'];

// A failed "create account" comes back with what was typed
$old = $_SESSION['old_user_form'] ?? null;
unset($_SESSION['old_user_form']);
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="flex flex-col sm:flex-row sm:justify-between sm:items-end gap-4 mb-10">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">User Accounts</h2>
            <p class="text-slate-500 font-medium mt-1">Manage system access and permissions</p>
        </div>
        <button type="button" onclick="openAddUserModal()" class="bg-slate-900 text-white px-8 py-4 rounded-2xl font-black shadow-xl shadow-slate-200/50 hover:bg-blue-600 transition-all text-[14px] uppercase tracking-widest active:scale-95">
            + CREATE ACCOUNT
        </button>
    </header>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="bg-green-50 text-green-700 p-4 rounded-2xl mb-6 font-bold border border-green-200 text-sm flex items-center gap-3 shadow-sm">
            <span class="text-lg shrink-0">✅</span> <span class="leading-relaxed"><?= $_SESSION['success']; unset($_SESSION['success']); ?></span>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="bg-red-50 text-red-600 p-4 rounded-2xl mb-6 font-bold border border-red-100 text-sm flex items-center gap-3 shadow-sm">
            <span class="text-lg shrink-0">⚠️</span> <span class="leading-relaxed"><?= $_SESSION['error']; unset($_SESSION['error']); ?></span>
        </div>
    <?php endif; ?>

    <!-- ✨ SUMMARY TILES (click one to filter) -->
    <?php $tile = 'text-left bg-white p-5 rounded-[1.5rem] border border-slate-200 shadow-sm hover:border-blue-400 hover:shadow-md transition-all flex items-center justify-between gap-3'; ?>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <button type="button" onclick="quickFilter('Student', '')" class="<?= $tile ?>">
            <div>
                <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest">Students</p>
                <p class="text-3xl font-black text-slate-900"><?= number_format($students_total) ?></p>
                <p class="text-xs font-bold text-slate-400"><?= number_format($students_applied) ?> have applied</p>
            </div>
            <span class="w-12 h-12 rounded-2xl bg-blue-50 text-2xl flex items-center justify-center">🎓</span>
        </button>
        <button type="button" onclick="quickFilter('External_Admin', '')" class="<?= $tile ?>">
            <div>
                <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest">Scholarship Providers</p>
                <p class="text-3xl font-black text-slate-900"><?= number_format($role_counts['External_Admin'] ?? 0) ?></p>
                <p class="text-xs font-bold text-slate-400">External sponsors</p>
            </div>
            <span class="w-12 h-12 rounded-2xl bg-amber-50 text-2xl flex items-center justify-center">🏢</span>
        </button>
        <button type="button" onclick="quickFilter('admins', '')" class="<?= $tile ?>">
            <div>
                <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest">Administrators</p>
                <p class="text-3xl font-black text-slate-900"><?= number_format(($role_counts['Internal_Admin'] ?? 0) + ($role_counts['Super_Admin'] ?? 0)) ?></p>
                <p class="text-xs font-bold text-slate-400">OSSD and Super Admin</p>
            </div>
            <span class="w-12 h-12 rounded-2xl bg-emerald-50 text-2xl flex items-center justify-center">🏛️</span>
        </button>
        <button type="button" onclick="quickFilter('', 'archived')" class="<?= $tile ?>">
            <div>
                <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest">Archived</p>
                <p class="text-3xl font-black text-slate-900"><?= number_format($status_counts['Archived'] ?? 0) ?></p>
                <p class="text-xs font-bold text-slate-400">Cannot sign in</p>
            </div>
            <span class="w-12 h-12 rounded-2xl bg-slate-100 text-2xl flex items-center justify-center">🗄️</span>
        </button>
    </div>

    <!-- ✨ SEARCH & FILTERS -->
    <?php $filter_input = 'w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all'; ?>
    <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm mb-6">
        <div class="flex flex-col gap-4">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">

                <!-- KEYWORD SEARCH -->
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Search</label>
                    <input type="text" id="filterKeyword" oninput="filterUsers()" placeholder="Name, email, student no., organization..." autocomplete="off" class="<?= $filter_input ?> cursor-text placeholder:font-medium">
                </div>

                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">User Type</label>
                    <select id="filterRole" onchange="filterUsers()" class="<?= $filter_input ?> cursor-pointer">
                        <option value="">All user types (<?= count($users) ?>)</option>
                        <?php foreach (['Student', 'Internal_Admin', 'External_Admin', 'Super_Admin'] as $r): ?>
                            <option value="<?= $r ?>"><?= USER_ROLE_LABELS[$r] ?> (<?= $role_counts[$r] ?? 0 ?>)</option>
                        <?php endforeach; ?>
                        <option value="admins">All administrators (<?= ($role_counts['Internal_Admin'] ?? 0) + ($role_counts['Super_Admin'] ?? 0) ?>)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Account Status</label>
                    <select id="filterStatus" onchange="filterUsers()" class="<?= $filter_input ?> cursor-pointer">
                        <option value="">Active and archived (<?= count($users) ?>)</option>
                        <option value="active">Active (<?= $status_counts['Active'] ?? 0 ?>)</option>
                        <option value="archived">Archived (<?= $status_counts['Archived'] ?? 0 ?>)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Applications</label>
                    <select id="filterApplied" onchange="filterUsers()" class="<?= $filter_input ?> cursor-pointer">
                        <option value="">Any</option>
                        <option value="yes">Students who have applied (<?= $students_applied ?>)</option>
                        <option value="no">Students who never applied (<?= $students_total - $students_applied ?>)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Year Level</label>
                    <select id="filterYear" onchange="filterUsers()" class="<?= $filter_input ?> cursor-pointer">
                        <option value="">Any year</option>
                        <?php foreach ($year_counts as $y => $n): ?>
                            <option value="<?= htmlspecialchars($y) ?>"><?= htmlspecialchars($y) ?> (<?= $n ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>
            <div class="flex justify-end mt-2 pt-4 border-t border-slate-50">
                <!-- ✨ UPGRADED: Solid Black Background for Reset Button -->
                <button type="button" onclick="clearFilters()" class="bg-black text-white px-6 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-slate-800 transition-all shadow-sm active:scale-95 flex items-center justify-center">Reset Filters</button>
            </div>
        </div>
    </div>

    <!-- BULK ACTIONS + SHOW ENTRIES -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6" id="usersContainer">
        <div class="flex flex-wrap items-center gap-3 bg-white px-4 py-2.5 rounded-2xl border border-slate-200 shadow-sm">
            <label class="flex items-center gap-2 cursor-pointer text-sm font-bold text-slate-700">
                <input type="checkbox" id="checkPage" onchange="checkShown(this.checked)" class="w-4 h-4 accent-blue-600 cursor-pointer"> Select shown
            </label>
            <button type="button" id="checkAllMatching" onclick="checkAllMatching()" class="hidden text-xs font-black text-blue-600 hover:underline"></button>
            <span id="bulkCount" class="text-[11px] font-black text-slate-400 uppercase tracking-widest">0 selected</span>
            <button type="button" data-bulk onclick="submitBulk('Archived')" disabled class="bg-white border border-red-200 text-red-500 px-4 py-2 rounded-xl text-[10px] font-black hover:bg-red-50 uppercase tracking-widest disabled:opacity-40 disabled:cursor-not-allowed">Archive selected</button>
            <button type="button" data-bulk onclick="submitBulk('Active')" disabled class="bg-slate-900 text-white px-4 py-2 rounded-xl text-[10px] font-black hover:bg-green-600 uppercase tracking-widest disabled:opacity-40 disabled:cursor-not-allowed">Restore selected</button>
            <button type="button" data-bulk onclick="clearChecks()" disabled class="text-[11px] font-bold text-slate-400 hover:text-slate-700 disabled:hidden">Clear</button>
        </div>
        <form id="bulkForm" action="../actions/process_crud.php" method="POST" class="hidden">
            <input type="hidden" name="module" value="users">
            <input type="hidden" name="action" value="bulk_status">
            <input type="hidden" name="status" id="bulkStatus">
        </form>
        <div class="flex items-center gap-3 bg-white px-4 py-2.5 rounded-2xl border border-slate-200 shadow-sm shrink-0 w-full sm:w-auto justify-between sm:justify-start">
            <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest hidden sm:inline">Show:</span>
            <select id="itemsPerPage" onchange="changeItemsPerPage()" class="bg-slate-50 border border-slate-100 text-slate-900 text-sm font-bold rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 block px-3 py-1.5 outline-none cursor-pointer w-full sm:w-auto">
                <option value="10" selected>10 items</option>
                <option value="25">25 items</option>
                <option value="50">50 items</option>
                <option value="999999">All items</option>
            </select>
        </div>
    </div>

    <!-- ✨ ONE-LINER CARD LAYOUT GRID -->
    <div class="bg-white rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden p-4 lg:p-6 mb-8">
        <div id="usersGrid" class="flex flex-col gap-2.5">
            <?php foreach($users as $u): 
                $status = ($u['AccountStatus'] ?? 'Active') ?: 'Active';
                $is_active = ($status === 'Active');
                $role = $u['Role'];
                $full_name = htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']);
                $username = htmlspecialchars($u['Username'] ?: $u['Email']);
                $email = htmlspecialchars($u['Email'] ?? '');
                $role_display = USER_ROLE_LABELS[$role] ?? str_replace('_', ' ', htmlspecialchars($role));

                // Role-specific details for the card
                $details = [];
                if ($role === 'Student') {
                    $details[] = ['🪪', $u['StudentID_Num'] ? 'ID ' . htmlspecialchars($u['StudentID_Num']) : 'No student number yet'];
                    $program = trim(($u['ProgramName'] ?? '') . ($u['YearLevel'] ? ' · ' . $u['YearLevel'] : ''), ' ·');
                    $details[] = ['📚', $program !== '' ? htmlspecialchars($program) : 'Program not set'];
                    $details[] = ['📄', $u['AppCount'] > 0 ? $u['AppCount'] . ' application' . ($u['AppCount'] == 1 ? '' : 's') . ($u['ScholarCount'] > 0 ? ' · ' . $u['ScholarCount'] . ' approved' : '') : 'Never applied'];
                } elseif ($role === 'External_Admin') {
                    $details[] = ['🏢', $u['Organization'] ? htmlspecialchars($u['Organization']) : 'Organization not set'];
                    $details[] = ['🎓', $u['ScholarshipCount'] . ' scholarship' . ($u['ScholarshipCount'] == 1 ? '' : 's') . ' owned'];
                } elseif ($role === 'Internal_Admin' && $u['ScholarshipCount'] > 0) {
                    $details[] = ['🎓', $u['ScholarshipCount'] . ' scholarship' . ($u['ScholarshipCount'] == 1 ? '' : 's') . ' created'];
                }
                $last_login = $u['LastLogin'] ? date('M j, Y · g:i A', strtotime($u['LastLogin'])) : 'Never signed in';

                // Everything the search box can match
                $search_text = strtolower(implode(' ', [$u['FirstName'], $u['LastName'], $u['Username'], $u['Email'], $u['StudentID_Num'], $u['Organization'], $u['ProgramName'], $role_display]));
            ?>
            <div class="user-card <?= !$is_active ? 'opacity-60 grayscale hover:grayscale-0' : '' ?> bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-4 sm:p-5 relative overflow-hidden group" 
                 data-keyword="<?= htmlspecialchars($search_text) ?>"
                 data-role="<?= htmlspecialchars($role) ?>"
                 data-status="<?= strtolower($status) ?>"
                 data-applied="<?= $role === 'Student' ? ($u['AppCount'] > 0 ? 'yes' : 'no') : '' ?>"
                 data-year="<?= $role === 'Student' ? htmlspecialchars($u['YearLevel'] ?: 'Not set') : '' ?>"
                 data-id="<?= (int) $u['UserID'] ?>">
                
                <!-- Dynamic Status Color Indicator -->
                <div class="absolute top-0 left-0 w-1 h-full <?= $is_active ? 'bg-green-500' : 'bg-slate-400' ?>"></div>
                
                <div class="flex gap-4 items-center flex-1 min-w-0 pl-3 w-full lg:w-auto">
                    <?php $is_self = (int) $u['UserID'] === (int) $_SESSION['user_id']; ?>
                    <input type="checkbox" class="bulk-check w-4 h-4 shrink-0 accent-blue-600 cursor-pointer disabled:opacity-30" value="<?= (int) $u['UserID'] ?>" onchange="updateBulk()"
                           <?= $is_self ? 'disabled title="You can\'t change your own account"' : '' ?> aria-label="Select <?= $full_name ?>">
                    <!-- Icon -->
                    <div class="shrink-0 w-10 h-10 lg:w-12 lg:h-12 <?= $is_active ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-900' ?> rounded-xl lg:rounded-2xl flex items-center justify-center text-lg lg:text-xl font-bold shadow-inner group-hover:bg-blue-600 group-hover:text-white transition-colors"><?= $role_icon[$role] ?? '👤' ?></div>
                    
                    <div class="flex-1 min-w-0 pr-2">
                        <h3 class="text-sm lg:text-[15px] font-black text-slate-900 leading-tight truncate" title="<?= $full_name ?>">
                            <?= $full_name ?>
                        </h3>
                        <p class="text-[11px] font-bold text-slate-500 mt-1 truncate" title="Signs in with <?= $username ?>">
                            <?= $username ?><?= $email && $email !== $username ? ' · ' . $email : '' ?>
                        </p>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1.5 text-[11px] font-semibold text-slate-600">
                            <?php foreach ($details as [$icon, $text]): ?>
                                <span class="truncate max-w-full"><?= $icon ?> <?= $text ?></span>
                            <?php endforeach; ?>
                            <span class="text-slate-400" title="Last successful sign-in, from the audit log">🕒 <?= $last_login ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="flex flex-wrap lg:flex-nowrap items-center justify-between w-full lg:w-auto shrink-0 mt-4 lg:mt-0 border-t lg:border-t-0 lg:border-l border-slate-100 pt-3 lg:pt-0 lg:pl-6 pl-14 gap-4 lg:gap-6">
                    <div class="text-left lg:text-right w-44 flex-1 lg:flex-none">
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">User Type</p>
                        <span class="px-3 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border <?= $role_style[$role] ?? 'border-slate-200 bg-slate-50 text-slate-600' ?> inline-block">
                            <?= $role_display ?>
                        </span>
                    </div>
                    
                    <div class="text-left lg:text-right w-24 shrink-0 border-l border-slate-100 pl-4 lg:pl-6 ml-2 lg:ml-0">
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Status</p>
                        <p class="text-xs font-black <?= $is_active ? 'text-green-600' : 'text-slate-500' ?> uppercase tracking-widest flex items-center lg:justify-end gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full <?= $is_active ? 'bg-green-600' : 'bg-slate-400' ?>"></span> 
                            <?= $status ?>
                        </p>
                    </div>

                    <div class="text-right shrink-0 border-l border-slate-100 pl-4 lg:pl-6 ml-2 lg:ml-0 flex flex-col items-stretch justify-center gap-2 w-36">
                        <?php if ($is_active && filter_var($u['Email'] ?? '', FILTER_VALIDATE_EMAIL)): ?>
                            <form action="../actions/process_crud.php" method="POST" class="m-0 w-full">
                                <input type="hidden" name="module" value="users">
                                <input type="hidden" name="action" value="send_reset">
                                <input type="hidden" name="user_id" value="<?= $u['UserID'] ?>">
                                <button type="submit" onclick="return confirm('Email <?= htmlspecialchars(addslashes($u['FirstName'] . ' ' . $u['LastName'])) ?> a link to set a new password?\n\nIt goes to <?= htmlspecialchars(maskEmail($u['Email'])) ?> and works for 1 hour.');"
                                        title="Email this user a link to choose a new password" class="w-full bg-white border border-blue-200 text-blue-600 px-3 py-2 rounded-xl text-[10px] font-black hover:bg-blue-50 uppercase tracking-widest transition-colors shadow-sm">Reset link</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$is_self): // Prevent archiving yourself ?>
                            <form action="../actions/process_crud.php" method="POST" class="m-0 w-full">
                                <input type="hidden" name="module" value="users">
                                <input type="hidden" name="action" value="toggle_archive">
                                <input type="hidden" name="user_id" value="<?= $u['UserID'] ?>">
                                <input type="hidden" name="current_status" value="<?= $status ?>">
                                
                                <?php if ($is_active): ?>
                                    <button type="submit" onclick="return confirm('Archive this user? They will no longer be able to log in.');" class="w-full bg-white border border-red-200 text-red-500 px-4 py-2 rounded-xl text-[10px] font-black hover:bg-red-50 hover:text-red-700 uppercase tracking-widest transition-colors shadow-sm">Archive</button>
                                <?php else: ?>
                                    <button type="submit" onclick="return confirm('Restore this user? They will regain system access.');" class="w-full bg-slate-900 text-white px-4 py-2 rounded-xl text-[10px] font-black hover:bg-green-600 uppercase tracking-widest transition-colors shadow-sm">Restore</button>
                                <?php endif; ?>
                            </form>
                        <?php else: ?>
                            <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest px-4 py-2">Current User</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div id="noResultsMsg" class="hidden p-20 text-center bg-slate-50 rounded-[2rem] border border-slate-100">
                <div class="text-4xl mb-4 grayscale opacity-30">🔍</div>
                <p class="text-slate-500 font-bold text-sm">No users found matching your predictive filters.</p>
            </div>

            <?php if(empty($users)): ?>
                <div class="p-20 text-center bg-slate-50 rounded-[2rem] border border-slate-100">
                    <div class="text-4xl mb-4 grayscale opacity-30">👤</div>
                    <p class="text-slate-500 font-bold text-sm">No users found in the database.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ✨ CENTERED PAGINATION CONTROLS -->
        <div class="flex flex-col items-center justify-center mt-6 gap-4 border-t border-slate-100 pt-6">
            <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
            <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-1">
                Showing 1 to X of X Users
            </div>
        </div>
    </div>

    <!-- CREATE USER MODAL -->
    <div id="addUserModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm hidden z-[9999] items-center justify-center p-4 opacity-0 transition-opacity duration-300">
        <div class="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl overflow-hidden transform scale-95 transition-transform duration-300" id="addUserModalContent">
            
            <div class="p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <div>
                    <h3 class="text-xl font-black text-slate-900 uppercase tracking-tight">Create New User</h3>
                    <p class="text-xs font-bold text-blue-600 mt-1 uppercase tracking-widest">System Access Provisioning</p>
                </div>
                <button type="button" onclick="closeAddUserModal()" class="w-10 h-10 bg-white border border-slate-200 text-slate-500 rounded-full flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <form action="../actions/process_crud.php" method="POST" class="p-8 space-y-5">
                <input type="hidden" name="module" value="users">
                <input type="hidden" name="action" value="create">

                <?php $field = 'w-full bg-slate-50 border border-slate-200 text-slate-900 rounded-xl px-4 py-3 font-bold outline-none focus:border-blue-500 transition-colors';
                      $label = 'block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2';
                      $o = fn($k) => htmlspecialchars($old[$k] ?? ''); ?>

                <div>
                    <label class="<?= $label ?>">User Type</label>
                    <select name="role" id="newUserRole" onchange="syncRoleFields()" required class="<?= $field ?> cursor-pointer">
                        <?php foreach (['Student', 'Internal_Admin', 'External_Admin'] as $r): ?>
                            <option value="<?= $r ?>" <?= ($old['role'] ?? 'Student') === $r ? 'selected' : '' ?>><?= USER_ROLE_LABELS[$r] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="<?= $label ?>">First Name</label>
                        <input type="text" name="first_name" value="<?= $o('first_name') ?>" required maxlength="50" class="<?= $field ?>">
                    </div>
                    <div>
                        <label class="<?= $label ?>">Last Name</label>
                        <input type="text" name="last_name" value="<?= $o('last_name') ?>" required maxlength="50" class="<?= $field ?>">
                    </div>
                </div>

                <div>
                    <label class="<?= $label ?>">Email Address</label>
                    <input type="email" name="email" value="<?= $o('email') ?>" required placeholder="name@example.com" class="<?= $field ?>">
                </div>

                <div data-for-role="Student">
                    <label class="<?= $label ?>">Student Number (10 digits)</label>
                    <input type="text" name="student_number" id="newStudentNumber" value="<?= $o('student_number') ?>" inputmode="numeric" maxlength="10" pattern="\d{10}" placeholder="e.g. 2021012345"
                           oninput="this.value = this.value.replace(/\D/g, '').slice(0, 10)" class="<?= $field ?>">
                    <p class="text-[11px] font-semibold text-slate-400 mt-1.5">This is also the student's username. They can sign in with it or their email. On their first sign-in they must upload their Report of Grades so their GWA is recorded.</p>
                </div>

                <div data-for-role="External_Admin">
                    <label class="<?= $label ?>">Organization</label>
                    <input type="text" name="organization" id="newOrganization" value="<?= $o('organization') ?>" maxlength="100" placeholder="e.g. CHED Region III, KOICA" class="<?= $field ?>">
                    <p class="text-[11px] font-semibold text-slate-400 mt-1.5">Shown as the sponsor on this provider's scholarships.</p>
                </div>

                <div data-for-role="Internal_Admin External_Admin">
                    <label class="<?= $label ?>">Username <span class="normal-case tracking-normal font-semibold">(optional)</span></label>
                    <input type="text" name="username" value="<?= $o('username') ?>" maxlength="50" placeholder="Leave blank to use the part of the email before @" class="<?= $field ?>">
                </div>

                <div>
                    <label class="<?= $label ?>">Temporary Password</label>
                    <input type="password" name="password" required autocomplete="new-password" class="<?= $field ?>">
                    <p class="text-[11px] font-semibold text-slate-400 mt-1.5">Must follow the password policy set in Security. The user is emailed their username and a link to choose their own password; the temporary password is not emailed.</p>
                </div>

                <div class="pt-4 flex gap-3 border-t border-slate-100">
                    <button type="button" onclick="closeAddUserModal()" class="flex-1 bg-slate-100 text-slate-500 px-6 py-4 rounded-xl font-black text-[12px] uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                    <button type="submit" class="flex-1 bg-slate-900 text-white px-6 py-4 rounded-xl font-black text-[12px] uppercase tracking-widest shadow-xl hover:bg-blue-600 transition-all">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    // ========================================================
    // ✨ PREDICTIVE SEARCH & CENTERED PAGINATION ENGINE
    // ========================================================
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage').value) || 10;
    const allCards = Array.from(document.querySelectorAll('.user-card'));
    let filteredCards = [...allCards]; 

    function clearFilters() {
        document.getElementById('filterKeyword').value = '';
        document.getElementById('filterRole').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterApplied').value = '';
        document.getElementById('filterYear').value = '';
        filterUsers();
    }

    // Summary tiles set the filters in one click
    function quickFilter(role, status) {
        clearFilters();
        document.getElementById('filterRole').value = role;
        document.getElementById('filterStatus').value = status;
        filterUsers();
        document.getElementById('usersContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function filterUsers() {
        const fKeyword = document.getElementById('filterKeyword').value.toLowerCase().trim();
        const fRole = document.getElementById('filterRole').value;
        const fStatus = document.getElementById('filterStatus').value;
        const fApplied = document.getElementById('filterApplied').value;
        const fYear = document.getElementById('filterYear').value;

        filteredCards = allCards.filter(card => {
            const role = card.dataset.role;
            if (fKeyword !== '' && !card.dataset.keyword.includes(fKeyword)) return false;
            if (fRole === 'admins' ? !['Internal_Admin', 'Super_Admin'].includes(role) : (fRole !== '' && role !== fRole)) return false;
            if (fStatus !== '' && card.dataset.status !== fStatus) return false;
            if (fApplied !== '' && card.dataset.applied !== fApplied) return false;   // only students carry yes/no
            if (fYear !== '' && card.dataset.year !== fYear) return false;
            return true;
        });

        currentPage = 1; 
        renderPagination();
    }

    function renderPagination() {
        const totalItems = filteredCards.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;
        
        // Hide all cards first
        allCards.forEach(card => {
            card.style.display = 'none';
        });

        // Show only the paginated slice
        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        filteredCards.slice(start, end).forEach(card => card.style.display = 'flex');
        updateBulk();

        // Update page info
        const pageInfo = document.getElementById('pageInfo');
        const noResultsMsg = document.getElementById('noResultsMsg');

        if (pageInfo) {
            if(totalItems === 0) {
                pageInfo.innerText = "No users match your filters.";
                if(noResultsMsg) noResultsMsg.classList.remove('hidden');
            } else {
                pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Users`;
                if(noResultsMsg) noResultsMsg.classList.add('hidden');
            }
        }

        // Render Pagination Buttons
        let btnHtml = '';
        if(totalItems > 0) {
            btnHtml += `<button type="button" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm' }">&larr;</button>`;
            
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, currentPage + 1);

            if (currentPage === 1) endPage = Math.min(totalPages, 3);
            else if (currentPage === totalPages) startPage = Math.max(1, totalPages - 2);

            if (startPage > 1) {
                btnHtml += `<button type="button" onclick="changePage(1)" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm">1</button>`;
                if (startPage > 2) btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
            }

            for(let i = startPage; i <= endPage; i++) {
                btnHtml += `<button type="button" onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-blue-600 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm'}">${i}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
                btnHtml += `<button type="button" onclick="changePage(${totalPages})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm">${totalPages}</button>`;
            }
            
            btnHtml += `<button type="button" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm' }">&rarr;</button>`;
        }

        const paginationControls = document.getElementById('paginationControls');
        if(paginationControls) paginationControls.innerHTML = btnHtml;
    }

    // ========================================================
    // BULK ARCHIVE / RESTORE
    // ========================================================
    const checkable = card => { const c = card.querySelector('.bulk-check'); return c && !c.disabled ? c : null; };
    const shownCards = () => allCards.filter(c => c.style.display !== 'none');

    function updateBulk() {
        const picked = document.querySelectorAll('.bulk-check:checked').length;
        document.getElementById('bulkCount').textContent = picked + ' selected';
        document.querySelectorAll('[data-bulk]').forEach(b => b.disabled = picked === 0);
        const shown = shownCards().map(checkable).filter(Boolean);
        const page = document.getElementById('checkPage');
        page.checked = shown.length > 0 && shown.every(c => c.checked);
        page.indeterminate = !page.checked && shown.some(c => c.checked);
        // Offer every match across pages once the visible page is ticked
        const matching = filteredCards.map(checkable).filter(Boolean);
        const all = document.getElementById('checkAllMatching');
        all.textContent = 'Select all ' + matching.length + ' matching';
        all.classList.toggle('hidden', !page.checked || matching.length <= shown.length || matching.every(c => c.checked));
    }
    function checkShown(on) { shownCards().map(checkable).filter(Boolean).forEach(c => c.checked = on); updateBulk(); }
    function checkAllMatching() { filteredCards.map(checkable).filter(Boolean).forEach(c => c.checked = true); updateBulk(); }
    function clearChecks() { document.querySelectorAll('.bulk-check').forEach(c => c.checked = false); updateBulk(); }

    function submitBulk(status) {
        const ids = Array.from(document.querySelectorAll('.bulk-check:checked')).map(c => c.value);
        if (!ids.length) return;
        const what = status === 'Archived' ? 'Archive' : 'Restore';
        const effect = status === 'Archived' ? 'They will be signed out and can no longer sign in.' : 'They will be able to sign in again.';
        if (!confirm(what + ' ' + ids.length + ' account' + (ids.length === 1 ? '' : 's') + '? ' + effect)) return;
        const form = document.getElementById('bulkForm');
        form.querySelectorAll('input[name="user_ids[]"]').forEach(i => i.remove());
        ids.forEach(id => form.insertAdjacentHTML('beforeend', '<input type="hidden" name="user_ids[]" value="' + id + '">'));
        document.getElementById('bulkStatus').value = status;
        form.submit();
    }

    function changePage(page) {
        const totalPages = Math.ceil(filteredCards.length / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
        document.getElementById('usersContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function changeItemsPerPage() {
        itemsPerPage = parseInt(document.getElementById('itemsPerPage').value);
        currentPage = 1;
        renderPagination();
    }

    document.addEventListener('DOMContentLoaded', renderPagination);

    // ========================================================
    // CREATE USER MODAL LOGIC
    // ========================================================
    const addUserModal = document.getElementById('addUserModal');
    const addUserModalContent = document.getElementById('addUserModalContent');

    function openAddUserModal() {
        addUserModal.classList.remove('hidden');
        addUserModal.classList.add('flex');
        setTimeout(() => { 
            addUserModal.classList.remove('opacity-0'); 
            addUserModalContent.classList.remove('scale-95'); 
        }, 10);
    }

    function closeAddUserModal() {
        addUserModal.classList.add('opacity-0');
        addUserModalContent.classList.add('scale-95');
        setTimeout(() => { 
            addUserModal.classList.add('hidden'); 
            addUserModal.classList.remove('flex');
        }, 300);
    }

    addUserModal.addEventListener('click', function(e) {
        if (e.target === this) closeAddUserModal();
    });

    // Show only the fields the chosen user type needs
    function syncRoleFields() {
        const role = document.getElementById('newUserRole').value;
        document.querySelectorAll('[data-for-role]').forEach(el => {
            const show = el.dataset.forRole.split(' ').includes(role);
            el.classList.toggle('hidden', !show);
            el.querySelectorAll('input').forEach(i => i.disabled = !show);
        });
        document.getElementById('newStudentNumber').required = role === 'Student';
        document.getElementById('newOrganization').required = role === 'External_Admin';
    }
    syncRoleFields();
<?php if ($old): ?>
    // The last attempt failed: reopen the form with what was typed
    document.addEventListener('DOMContentLoaded', openAddUserModal);
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>