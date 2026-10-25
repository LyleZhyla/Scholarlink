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
    $stmt = $pdo->query("SELECT * FROM users ORDER BY Role ASC, FirstName ASC");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $users = [];
}
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
            <span class="text-lg">✅</span> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="bg-red-50 text-red-600 p-4 rounded-2xl mb-6 font-bold border border-red-100 text-sm flex items-center gap-3 shadow-sm">
            <span class="text-lg">⚠️</span> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- ✨ PREDICTIVE SEARCH & DROPDOWN FILTERS (DATALIST) -->
    <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm mb-6">
        <div class="flex flex-col gap-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                
                <!-- KEYWORD SEARCH -->
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">User Details</label>
                    <input type="text" id="filterKeyword" oninput="filterUsers()" placeholder="Search name, email..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-text placeholder:font-medium">
                </div>
                
                <!-- SEARCHABLE ROLE DROPDOWN -->
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">System Role</label>
                    <input type="text" id="filterRole" list="roleList" oninput="filterUsers()" placeholder="Type a role..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-text placeholder:font-medium">
                    <datalist id="roleList">
                        <option value="Student"></option>
                        <option value="Internal_Admin"></option>
                        <option value="External_Admin"></option>
                        <option value="Super_Admin"></option>
                    </datalist>
                </div>

                <!-- SEARCHABLE STATUS DROPDOWN -->
                <div>
                    <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-2">Account Status</label>
                    <input type="text" id="filterStatus" list="statusList" oninput="filterUsers()" placeholder="Type 'Active' or 'Archived'..." autocomplete="off" class="w-full bg-slate-50 border border-slate-100 px-4 py-3 rounded-xl font-bold text-md text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/20 transition-all cursor-text placeholder:font-medium">
                    <datalist id="statusList">
                        <option value="Active"></option>
                        <option value="Archived"></option>
                    </datalist>
                </div>

            </div>
            <div class="flex justify-end mt-2 pt-4 border-t border-slate-50">
                <!-- ✨ UPGRADED: Solid Black Background for Reset Button -->
                <button type="button" onclick="clearFilters()" class="bg-black text-white px-6 py-3 rounded-xl font-black text-[14px] uppercase tracking-widest hover:bg-slate-800 transition-all shadow-sm active:scale-95 flex items-center justify-center">Reset Filters</button>
            </div>
        </div>
    </div>

    <!-- SHOW ENTRIES ONLY -->
    <div class="flex justify-end mb-6" id="usersContainer">
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
                $status = $u['AccountStatus'] ?? 'Active';
                $is_active = ($status === 'Active');
                $full_name = htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']);
                $contact = htmlspecialchars($u['Username'] ?? $u['Email']);
                $role_display = str_replace('_', ' ', htmlspecialchars($u['Role']));

                // Compile data for predictive search
                $search_text = strtolower($full_name . ' ' . $contact);
            ?>
            <div class="user-card <?= !$is_active ? 'opacity-60 grayscale hover:grayscale-0' : '' ?> bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-4 sm:p-5 relative overflow-hidden group" 
                 data-keyword="<?= $search_text ?>"
                 data-role="<?= strtolower(htmlspecialchars($u['Role'])) ?>"
                 data-status="<?= strtolower($status) ?>">
                
                <!-- Dynamic Status Color Indicator -->
                <div class="absolute top-0 left-0 w-1 h-full <?= $is_active ? 'bg-green-500' : 'bg-slate-400' ?>"></div>
                
                <div class="flex gap-4 items-center flex-1 min-w-0 pl-3 w-full lg:w-auto">
                    <!-- Icon -->
                    <div class="shrink-0 w-10 h-10 lg:w-12 lg:h-12 <?= $is_active ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-900' ?> rounded-xl lg:rounded-2xl flex items-center justify-center text-lg lg:text-xl font-bold shadow-inner group-hover:bg-blue-600 group-hover:text-white transition-colors">👤</div>
                    
                    <div class="flex-1 min-w-0 pr-2">
                        <h3 class="text-sm lg:text-[15px] font-black text-slate-900 leading-tight truncate" title="<?= $full_name ?>">
                            <?= $full_name ?>
                        </h3>
                        <p class="text-[11px] font-bold text-slate-500 mt-1 truncate">
                            <?= $contact ?>
                        </p>
                    </div>
                </div>
                
                <div class="flex flex-wrap lg:flex-nowrap items-center justify-between w-full lg:w-auto shrink-0 mt-4 lg:mt-0 border-t lg:border-t-0 lg:border-l border-slate-100 pt-3 lg:pt-0 lg:pl-6 pl-14 gap-4 lg:gap-6">
                    <div class="text-left lg:text-right w-32 flex-1 lg:flex-none">
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">System Role</p>
                        <span class="px-3 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border border-slate-200 bg-slate-50 text-slate-600 inline-block">
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

                    <div class="text-right shrink-0 border-l border-slate-100 pl-4 lg:pl-6 ml-2 lg:ml-0 flex items-center justify-end w-32">
                        <?php if ($u['UserID'] !== $_SESSION['user_id']): // Prevent archiving yourself ?>
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

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">First Name</label>
                        <input type="text" name="first_name" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 rounded-xl px-4 py-3 font-bold outline-none focus:border-blue-500 transition-colors">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Last Name</label>
                        <input type="text" name="last_name" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 rounded-xl px-4 py-3 font-bold outline-none focus:border-blue-500 transition-colors">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Email / Username</label>
                    <input type="text" name="email" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 rounded-xl px-4 py-3 font-bold outline-none focus:border-blue-500 transition-colors">
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Assign Role</label>
                    <select name="role" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 rounded-xl px-4 py-3 font-bold outline-none focus:border-blue-500 transition-colors cursor-pointer">
                        <option value="Student">Student Applicant</option>
                        <option value="Internal_Admin">Internal Admin</option>
                        <option value="External_Admin">External Admin</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Temporary Password</label>
                    <input type="password" name="password" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 rounded-xl px-4 py-3 font-bold outline-none focus:border-blue-500 transition-colors">
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
        filterUsers();
    }

    function filterUsers() {
        const fKeyword = document.getElementById('filterKeyword').value.toLowerCase().trim();
        const fRole = document.getElementById('filterRole').value.toLowerCase().trim().replace(' ', '_'); // handle UI spaces
        const fStatus = document.getElementById('filterStatus').value.toLowerCase().trim();

        filteredCards = allCards.filter(card => {
            const cardKeyword = card.getAttribute('data-keyword');
            const cardRole = card.getAttribute('data-role');
            const cardStatus = card.getAttribute('data-status');

            let match = true;

            if(fKeyword !== "" && !cardKeyword.includes(fKeyword)) match = false;
            // Map the typed UI string ("Internal Admin") to DB format ("internal_admin")
            if(fRole !== "" && !cardRole.includes(fRole)) match = false;
            if(fStatus !== "" && cardStatus !== fStatus) match = false;

            return match;
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
</script>

<?php include '../includes/footer.php'; ?>