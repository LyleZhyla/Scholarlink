<?php 
session_start();
include '../includes/session_manager.php'; 

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') { 
    header("Location: ../student_login.php"); 
    exit(); 
}
include '../includes/db_connect.php';
require_once '../includes/scholarship_categories.php';
require_once '../includes/form_fields.php';
require_once '../includes/scholarship_eligibility.php';
include '../includes/header.php';
include '../includes/student_sidebar.php'; 

$user_id = $_SESSION['user_id'];
try {
    $user_stmt = $pdo->prepare("SELECT u.*, p.ProgramName FROM users u LEFT JOIN program p ON p.ProgramID = u.ProgramID WHERE u.UserID = :uid");
    $user_stmt->execute(['uid' => $user_id]);
    $student = $user_stmt->fetch();
    
    $student_program_id = $student['ProgramID'];
    $student_year_level = $student['YearLevel'];
    $current_db_gpa = (float)($student['GPA'] ?? 0);

    $applied_stmt = $pdo->prepare("SELECT ScholarshipID, ApplicationID FROM application WHERE UserID = :uid");
    $applied_stmt->execute(['uid' => $user_id]);
    $applied_applications = $applied_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // ✨ STRICT ONE ACTIVE APPLICATION CHECKER
    $app_stmt = $pdo->prepare("SELECT COUNT(*) FROM application WHERE UserID = :uid AND Status != 'Rejected'");
    $app_stmt->execute(['uid' => $user_id]);
    $has_active_app = $app_stmt->fetchColumn() > 0; 

    // Fetch every active scholarship, then let the shared eligibility engine explain
    // exact matches, missing profile data, and failed requirements.
    $stmt = $pdo->query("
        SELECT sch.*,
               (SELECT GROUP_CONCAT(CriteriaName SEPARATOR '||')
                FROM scholarship_criteria sc WHERE sc.ScholarshipID = sch.ScholarshipID) AS CriteriaList
        FROM scholarship sch
        WHERE sch.Status = 'Active'
    ");
    $available_scholarships = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $program_map = [];
    foreach ($pdo->query("SELECT ScholarshipID, ProgramID FROM scholarship_programs")->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
        $program_map[(int)$mapping['ScholarshipID']][] = (int)$mapping['ProgramID'];
    }

    foreach ($available_scholarships as &$scholarship) {
        $scholarship['_eligibility'] = scholarshipEligibilityCheck(
            $student,
            $scholarship,
            $program_map[(int)$scholarship['ScholarshipID']] ?? []
        );
    }
    unset($scholarship);

    $status_order = ['eligible' => 0, 'needs_profile' => 1, 'ineligible' => 2, 'closed' => 3];
    usort($available_scholarships, static function ($a, $b) use ($status_order) {
        $aStatus = $a['_eligibility']['status'];
        $bStatus = $b['_eligibility']['status'];
        return ($status_order[$aStatus] <=> $status_order[$bStatus])
            ?: ($b['_eligibility']['score'] <=> $a['_eligibility']['score'])
            ?: (strcmp((string)$a['Deadline'], (string)$b['Deadline']));
    });

    $eligibility_counts = ['eligible' => 0, 'needs_profile' => 0, 'ineligible' => 0, 'closed' => 0];
    foreach ($available_scholarships as $scholarship) {
        $eligibility_counts[$scholarship['_eligibility']['status']]++;
    }

    // FETCH DYNAMIC DATA FOR MODALS
    $jsScholarshipData = [];
    foreach ($available_scholarships as $sch) {
        $sid = $sch['ScholarshipID'];
        
        // ✨ Application form designed in the Form Builder, rendered by the shared renderer
        $sch['custom_fields'] = getFormFields($pdo, $sid);
        $sch['form_html'] = renderApplicationForm($pdo, $sch['custom_fields'], $student, [], $sch['FormInstructions'] ?? null);

        $r_stmt = $pdo->prepare("SELECT * FROM document_requirement WHERE ScholarshipID = ?");
        $r_stmt->execute([$sid]);
        $sch['requirements'] = $r_stmt->fetchAll(PDO::FETCH_ASSOC);

        $jsScholarshipData[$sid] = $sch;
    }

    // FETCH USER VAULT ITEMS
    $v_stmt = $pdo->prepare("SELECT * FROM user_vault WHERE UserID = ?");
    $v_stmt->execute([$user_id]);
    $vault_files = $v_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) { die("Error: " . $e->getMessage()); }
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8 lg:mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black text-slate-900 tracking-tight">Scholarship Matches</h2>
            <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">
                Personalized for <strong class="text-green-600"><?= htmlspecialchars($student['ProgramName'] ?? $student['Major'] ?? 'Program not set') ?></strong>,
                <?= htmlspecialchars($student['YearLevel'] ?: 'year level not set') ?> · GWA <?= $current_db_gpa > 0 ? number_format($current_db_gpa, 2) : 'not set' ?>
            </p>
        </div>
        
        <div class="flex items-center gap-3 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-sm shrink-0">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-widest">Show:</span>
            <select id="itemsPerPage" onchange="changeItemsPerPage()" class="bg-slate-50 border border-slate-200 text-slate-900 text-sm font-bold rounded-lg focus:ring-green-500 focus:border-green-500 block px-2 py-1 outline-none cursor-pointer">
                <option value="5">5 items</option>
                <option value="10" selected>10 items</option>
                <option value="15">15 items</option>
                <option value="25">25 items</option>
                <option value="999">All items</option>
            </select>
        </div>
    </header>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <button type="button" onclick="setEligibilityFilter('eligible')" class="text-left bg-emerald-50 border border-emerald-200 rounded-2xl p-4 hover:border-emerald-400 transition-colors">
            <span class="text-2xl font-black text-emerald-700"><?= $eligibility_counts['eligible'] ?></span>
            <span class="block text-[10px] font-black uppercase tracking-widest text-emerald-700 mt-1">Eligible now</span>
        </button>
        <button type="button" onclick="setEligibilityFilter('needs_profile')" class="text-left bg-amber-50 border border-amber-200 rounded-2xl p-4 hover:border-amber-400 transition-colors">
            <span class="text-2xl font-black text-amber-700"><?= $eligibility_counts['needs_profile'] ?></span>
            <span class="block text-[10px] font-black uppercase tracking-widest text-amber-700 mt-1">Complete profile</span>
        </button>
        <button type="button" onclick="setEligibilityFilter('ineligible')" class="text-left bg-red-50 border border-red-200 rounded-2xl p-4 hover:border-red-400 transition-colors">
            <span class="text-2xl font-black text-red-700"><?= $eligibility_counts['ineligible'] ?></span>
            <span class="block text-[10px] font-black uppercase tracking-widest text-red-700 mt-1">Requirements unmet</span>
        </button>
        <button type="button" onclick="setEligibilityFilter('closed')" class="text-left bg-slate-100 border border-slate-200 rounded-2xl p-4 hover:border-slate-400 transition-colors">
            <span class="text-2xl font-black text-slate-700"><?= $eligibility_counts['closed'] ?></span>
            <span class="block text-[10px] font-black uppercase tracking-widest text-slate-600 mt-1">Closed</span>
        </button>
    </div>

    <div class="mb-5 rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm text-blue-900">
        <strong>Preliminary eligibility:</strong> matches are based on your saved profile and the scholarship's configured rules. Document verification and committee review still determine the final decision.
    </div>

    <?php if (!empty($available_scholarships)): ?>
        <?php
            // Count matched grants per category for the filter chips
            $category_counts = array_fill_keys(array_keys(SCHOLARSHIP_CATEGORIES), 0);
            foreach ($available_scholarships as $s) {
                $cat = $s['Category'] ?? DEFAULT_SCHOLARSHIP_CATEGORY;
                if (isset($category_counts[$cat])) $category_counts[$cat]++;
            }
        ?>
        <!-- ✨ CATEGORY FILTER (Scholarship / Incentive / Allowance) -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm mb-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex flex-wrap gap-2" id="categoryChips">
                <button type="button" data-filter-category="" onclick="setCategoryFilter(this)" class="category-chip px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-widest border transition-all">
                    All <span class="opacity-60">(<?= count($available_scholarships) ?>)</span>
                </button>
                <?php foreach (SCHOLARSHIP_CATEGORIES as $cat => $icon): ?>
                    <button type="button" data-filter-category="<?= htmlspecialchars($cat) ?>" onclick="setCategoryFilter(this)" class="category-chip px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-widest border transition-all">
                        <?= $icon . ' ' . htmlspecialchars($cat) ?> <span class="opacity-60">(<?= $category_counts[$cat] ?>)</span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 w-full md:w-auto">
                <select id="eligibilityFilter" onchange="applyFilters()" class="bg-slate-50 border border-slate-200 text-slate-900 text-sm font-bold rounded-lg px-3 py-2 outline-none focus:ring-green-500 focus:border-green-500 cursor-pointer shrink-0">
                    <option value="">All Eligibility Results</option>
                    <option value="eligible">✅ Eligible Now</option>
                    <option value="needs_profile">⚠️ Complete Profile</option>
                    <option value="ineligible">❌ Not Eligible</option>
                    <option value="closed">🔒 Closed</option>
                </select>
                <select id="typeFilter" onchange="applyFilters()" class="bg-slate-50 border border-slate-200 text-slate-900 text-sm font-bold rounded-lg px-3 py-2 outline-none focus:ring-green-500 focus:border-green-500 cursor-pointer shrink-0">
                    <option value="">All Sources</option>
                    <option value="Government">🏛️ Government</option>
                    <option value="Private">🏢 Private</option>
                </select>
            </div>
        </div>
    <?php endif; ?>

    <div id="scholarshipsGrid" class="flex flex-col gap-3">
        <?php if(empty($available_scholarships)): ?>
            <div class="col-span-full bg-white p-10 lg:p-20 rounded-[2rem] border border-slate-200 text-center shadow-sm">
                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4 grayscale opacity-50">🔍</div>
                <h3 class="text-lg font-black text-slate-900 mb-1">No Matches Found</h3>
                <p class="text-slate-500 font-medium text-sm">There are currently no active scholarships matching your specific course and year level.</p>
            </div>
        <?php else: ?>
            <?php foreach($available_scholarships as $s): ?>
                <?php
                    $match = $s['_eligibility'];
                    $match_styles = [
                        'eligible' => ['bar' => 'bg-emerald-500', 'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => '✅'],
                        'needs_profile' => ['bar' => 'bg-amber-500', 'badge' => 'bg-amber-50 text-amber-700 border-amber-200', 'icon' => '⚠️'],
                        'ineligible' => ['bar' => 'bg-red-500', 'badge' => 'bg-red-50 text-red-700 border-red-200', 'icon' => '❌'],
                        'closed' => ['bar' => 'bg-slate-400', 'badge' => 'bg-slate-100 text-slate-600 border-slate-200', 'icon' => '🔒'],
                    ];
                    $match_style = $match_styles[$match['status']];
                ?>
                <div data-category="<?= htmlspecialchars($s['Category'] ?? DEFAULT_SCHOLARSHIP_CATEGORY) ?>"
                     data-type="<?= htmlspecialchars($s['ScholarshipType'] ?? 'Private') ?>"
                     data-eligibility="<?= htmlspecialchars($match['status']) ?>"
                     class="scholarship-card bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition-all flex flex-col lg:flex-row items-start lg:items-center justify-between p-5 group relative overflow-hidden">
                    
                    <div class="absolute top-0 left-0 w-1 h-full <?= $match_style['bar'] ?>"></div>

                    <div class="flex-1 min-w-0 pr-4 lg:pr-8 pl-3 w-full lg:w-auto">
                        <div class="flex items-center gap-3 mb-1.5 flex-wrap">
                            <span class="bg-amber-50 text-amber-700 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-amber-200 shrink-0"><?= htmlspecialchars(categoryLabel($s['Category'] ?? null)) ?></span>
                            <span class="px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border shrink-0 <?= $match_style['badge'] ?>">
                                <?= $match_style['icon'] ?> <?= htmlspecialchars($match['label']) ?> · <?= (int)$match['score'] ?>% match
                            </span>
                            <?php if(!$s['ProgramID'] && !$s['YearLevel']): ?>
                                <span class="bg-purple-50 text-purple-700 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-purple-100 shrink-0">🌍 Open to All</span>
                            <?php else: ?>
                                <?php if($s['ProgramID']): ?>
                                    <span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest border border-blue-100 shrink-0">🎯 Course Match</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <h4 class="text-base font-black text-slate-900 group-hover:text-green-600 transition-colors truncate">
                                <?= htmlspecialchars($s['Name']) ?>
                            </h4>
                        </div>
                        <div class="flex items-center gap-2 text-xs font-medium text-slate-500 truncate mb-2 lg:mb-0">
                            <span class="text-slate-700 font-bold"><?= htmlspecialchars($s['YearLevel'] ? $s['YearLevel'] . ' Only' : 'All Levels') ?></span>
                            <span class="hidden md:inline">•</span>
                            <span class="truncate hidden md:inline"><?= htmlspecialchars($s['Description']) ?></span>
                        </div>
                        <details class="mt-3 text-xs">
                            <summary class="cursor-pointer font-black text-slate-600 hover:text-green-700 select-none">Why this result?</summary>
                            <div class="grid sm:grid-cols-2 gap-2 mt-3">
                                <?php foreach ($match['checks'] as $check): ?>
                                    <?php
                                        $check_style = $check['state'] === 'pass'
                                            ? 'bg-emerald-50 text-emerald-800 border-emerald-100'
                                            : ($check['state'] === 'missing'
                                                ? 'bg-amber-50 text-amber-800 border-amber-100'
                                                : 'bg-red-50 text-red-800 border-red-100');
                                        $check_icon = $check['state'] === 'pass' ? '✓' : ($check['state'] === 'missing' ? '!' : '×');
                                    ?>
                                    <div class="rounded-xl border p-2.5 <?= $check_style ?>">
                                        <span class="font-black"><?= $check_icon ?> <?= htmlspecialchars($check['label']) ?></span>
                                        <span class="block mt-0.5 opacity-80"><?= htmlspecialchars($check['detail']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    </div>

                    <div class="hidden md:flex items-center gap-6 px-6 border-x border-slate-100 shrink-0">
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-0.5">Min GPA</span>
                            <span class="text-sm font-black text-slate-800"><?= number_format($s['MinimumGWA'] ?? 2.00, 2) ?></span>
                        </div>
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-0.5">Award Amount</span>
                            <span class="text-sm font-black text-green-600">₱ <?= number_format($s['AwardAmount'], 2) ?></span>
                        </div>
                    </div>

                    <div class="mt-4 lg:mt-0 flex items-center justify-between lg:justify-end w-full lg:w-auto shrink-0 gap-6 pl-0 lg:pl-6">
                        <?php if (array_key_exists($s['ScholarshipID'], $applied_applications)): ?>
                            <!-- ALREADY APPLIED -->
                            <div class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto">
                                <button disabled class="flex-1 bg-slate-100 text-slate-400 px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest cursor-not-allowed border border-slate-200 shadow-inner">
                                    Applied ✓
                                </button>
                                <a href="../actions/generate_application_pdf.php?app_id=<?= $applied_applications[$s['ScholarshipID']] ?>" target="_blank" class="flex-1 text-center bg-blue-600 text-white px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-blue-700 transition-all shadow-md whitespace-nowrap">
                                    View Form 📄
                                </a>
                            </div>
                        
                        <?php elseif ($has_active_app): ?>
                            <!-- ✨ ONE ACTIVE APPLICATION BLOCKER UI -->
                            <button disabled class="bg-red-50 text-red-500 px-6 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest cursor-not-allowed border border-red-200 w-full lg:w-auto" title="You already have a pending or approved application. You can only apply for one grant at a time.">
                                1 Active App Only 🛑
                            </button>

                        <?php elseif (!$match['eligible']): ?>
                            <button disabled class="bg-slate-100 text-slate-500 px-6 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest cursor-not-allowed border border-slate-200 shadow-inner w-full lg:w-auto" title="<?= htmlspecialchars(scholarshipEligibilityError($match), ENT_QUOTES) ?>">
                                <?= htmlspecialchars($match['label']) ?> <?= $match_style['icon'] ?>
                            </button>
                        
                        <?php else: ?>
                            <!-- START APPLICATION -->
                            <button type="button" onclick="openApplyModal(<?= $s['ScholarshipID'] ?>)"
                               class="bg-slate-900 text-center text-white hover:bg-green-600 px-6 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest active:scale-[0.98] transition-all shadow-md w-full lg:w-auto whitespace-nowrap">
                                Start Application
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="noFilterMatches" class="hidden bg-white p-10 rounded-[2rem] border border-slate-200 text-center shadow-sm">
        <div class="text-3xl mb-3 grayscale opacity-50">🏷️</div>
        <h3 class="text-lg font-black text-slate-900 mb-1">Nothing in This Category</h3>
        <p class="text-slate-500 font-medium text-sm">None of your matched grants fit this filter. Try another category.</p>
    </div>

    <div class="flex flex-col items-center justify-center mt-12 gap-4 border-t border-slate-200 pt-8 pb-10">
        <div id="paginationControls" class="flex flex-wrap justify-center gap-2"></div>
        <div id="pageInfo" class="text-[11px] font-black text-slate-400 uppercase tracking-widest text-center mt-2"></div>
    </div>
</main>

<div id="applyModal" class="fixed inset-0 z-[9999] hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="applyModalContent" class="bg-white rounded-[1.5rem] lg:rounded-[2.5rem] shadow-2xl w-full max-w-4xl overflow-hidden relative transform transition-transform duration-300 scale-95 flex flex-col max-h-[90vh]">
        
        <div class="px-6 lg:px-8 py-6 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
            <div>
                <h3 class="font-black text-slate-900 text-xl lg:text-2xl uppercase tracking-tight">Application Form</h3>
                <p id="applyModalTitle" class="text-xs font-bold text-blue-600 mt-1">Scholarship Name</p>
            </div>
            <button type="button" onclick="closeApplyModal()" class="w-8 h-8 flex items-center justify-center bg-slate-200 text-slate-600 rounded-full hover:bg-red-500 hover:text-white transition-colors font-bold">&times;</button>
        </div>
        
        <div class="p-6 lg:p-8 overflow-y-auto custom-scrollbar">
            <form action="../actions/process_crud.php" method="POST" enctype="multipart/form-data" id="scholarshipApplyForm">
                <input type="hidden" name="module" value="student_apply">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="scholarship_id" id="applyScholarshipId" value="">
                
                <div class="space-y-4 mb-8">
                    <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Academic Profile</h3>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Verified Profile GWA</label>
                        <input type="number" step="0.01" name="gpa" id="applyGpa" value="<?= htmlspecialchars($current_db_gpa) ?>" readonly class="w-full md:w-1/3 p-4 bg-slate-100 border border-slate-200 rounded-xl font-bold text-slate-600 cursor-not-allowed">
                        <p class="text-[10px] text-slate-500 mt-2">Update your academic profile if this value is incorrect.</p>
                    </div>
                </div>

                <div id="applyModalCustomFields" class="mb-8 space-y-4"></div>
                <div id="applyModalRequirements" class="mb-8 space-y-4"></div>

                <div class="mt-8 pt-6 border-t border-slate-100 flex justify-end gap-3">
                    <button type="button" onclick="closeApplyModal()" class="bg-slate-100 text-slate-600 px-6 py-4 rounded-xl font-black text-sm uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                    <button type="submit" class="bg-slate-900 text-white px-10 py-4 rounded-xl font-black text-sm uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg active:scale-95">Submit Application 🚀</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="vaultModal" class="fixed inset-0 z-[10000] hidden bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="vaultModalContent" class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden relative transform transition-transform duration-300 scale-95 flex flex-col max-h-[80vh]">
        <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 shrink-0">
            <h3 class="font-black text-slate-900 uppercase tracking-widest text-sm">Select from Vault</h3>
            <button type="button" onclick="closeVaultPicker()" class="text-slate-400 hover:text-red-500 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="p-6 overflow-y-auto">
            <?php if (count($vault_files) > 0): ?>
                <div class="grid grid-cols-1 gap-3">
                    <?php foreach($vault_files as $file): ?>
                    <button type="button" onclick="selectVaultFile(<?= $file['VaultID'] ?>, '<?= htmlspecialchars($file['DocumentType'], ENT_QUOTES) ?>')" class="w-full text-left border border-slate-200 rounded-xl p-4 flex items-center gap-4 hover:border-blue-500 hover:bg-blue-50 transition-all group">
                        <div class="w-10 h-10 rounded-lg bg-slate-100 group-hover:bg-blue-100 flex items-center justify-center text-lg transition-colors">📄</div>
                        <div>
                            <p class="font-bold text-slate-900"><?= htmlspecialchars($file['DocumentType']) ?></p>
                            <p class="text-xs text-slate-500 mt-1">Uploaded: <?= date('M d, Y', strtotime($file['UploadDate'])) ?></p>
                        </div>
                        <div class="ml-auto text-blue-600 opacity-0 group-hover:opacity-100 font-bold text-sm transition-opacity">Select &rarr;</div>
                    </button>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-10">
                    <p class="text-slate-500 font-medium">Your vault is empty. You can upload files directly in the form.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // System contextual reference state
  // System contextual reference state
    const jsScholarshipData = <?= json_encode($jsScholarshipData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    
    // ✨ NEW: Ipasa ang buong student profile sa JavaScript!
    const studentProfile = <?= json_encode($student, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    // ✨ Escape helper to prevent XSS when injecting auto-filled DB values into template literals
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Required checkbox questions: at least one box must be ticked (browsers can't require a checkbox group)
    document.getElementById('scholarshipApplyForm').addEventListener('submit', function (e) {
        const missing = [...this.querySelectorAll('[data-required-group]')].find(group => !group.querySelector('input:checked'));
        if (missing) {
            e.preventDefault();
            alert('Please select at least one option for: ' + missing.closest('[data-field-id]').querySelector('label').innerText.replace('*', '').trim());
            missing.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    // --- APPLY MODAL LOGIC ---
    function openApplyModal(id) {
        const data = jsScholarshipData[id];
        if (!data) return;

        document.getElementById('applyScholarshipId').value = id;
        document.getElementById('applyModalTitle').innerText = 'Applying for: ' + data.Name;
        
        // Render Custom Form Questions
        const fieldsContainer = document.getElementById('applyModalCustomFields');
        fieldsContainer.innerHTML = '';
        if (data.form_html) {
            fieldsContainer.innerHTML = '<h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2 mb-4">Application Form</h3>'
                + '<div class="space-y-5">' + data.form_html + '</div>';
        }

        // Render Document Requirements
        const reqContainer = document.getElementById('applyModalRequirements');
        reqContainer.innerHTML = '';
        if(data.requirements && data.requirements.length > 0) {
            reqContainer.innerHTML = '<h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2 mb-4">Required Documents</h3>';
            data.requirements.forEach(r => {
                reqContainer.innerHTML += `
                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 border border-slate-200 rounded-xl bg-slate-50 gap-4 mb-3 transition-all hover:border-blue-400">
                    <div>
                        <p class="font-bold text-slate-900 text-sm">${r.DocumentName}</p>
                        <p class="text-xs text-slate-500 mt-1">Status: <span id="status_${r.RequirementID}" class="text-red-500 font-bold">Pending</span></p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <input type="file" name="new_docs[${r.RequirementID}]" id="file_${r.RequirementID}" accept=".pdf,.png,.jpg,.jpeg" class="hidden" onchange="handleNewUpload(${r.RequirementID}, this)" required>
                            <button type="button" onclick="document.getElementById('file_${r.RequirementID}').click()" class="bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 font-bold py-2 px-4 rounded-xl text-sm transition-colors shadow-sm">
                                Upload PC File
                            </button>
                        </div>
                        <span class="text-xs text-slate-400 font-bold uppercase">OR</span>
                        <button type="button" onclick="openVaultPicker(${r.RequirementID})" class="bg-slate-900 hover:bg-slate-800 text-white font-bold py-2 px-4 rounded-xl text-sm transition-colors shadow-sm flex items-center gap-2">
                            <span>🔒</span> Vault
                        </button>
                        <input type="hidden" name="vault_docs[${r.RequirementID}]" id="vault_input_${r.RequirementID}" value="">
                    </div>
                </div>`;
            });
        }

        const modal = document.getElementById('applyModal');
        const content = document.getElementById('applyModalContent');
        modal.classList.remove('hidden');
        setTimeout(() => { modal.classList.remove('opacity-0'); content.classList.remove('scale-95'); }, 10);
    }

    function closeApplyModal() {
        const modal = document.getElementById('applyModal');
        const content = document.getElementById('applyModalContent');
        modal.classList.add('opacity-0');
        content.classList.add('scale-95');
        setTimeout(() => { modal.classList.add('hidden'); }, 300);
    }

    // --- VAULT & UPLOAD LOGIC ---
    let currentRequirementId = null;
    const vaultModal = document.getElementById('vaultModal');
    const vaultModalContent = document.getElementById('vaultModalContent');

    function handleNewUpload(reqId, inputElement) {
        if (inputElement.files.length > 0) {
            const fileName = inputElement.files[0].name;
            const statusBadge = document.getElementById('status_' + reqId);
            
            // Remove Vault trace if standard PC upload is selected
            document.getElementById('vault_input_' + reqId).value = "";
            
            statusBadge.className = "text-green-600 font-bold";
            statusBadge.innerText = "Attached: " + fileName;
        }
    }

    function openVaultPicker(reqId) {
        currentRequirementId = reqId;
        vaultModal.classList.remove('hidden');
        setTimeout(() => {
            vaultModal.classList.remove('opacity-0');
            vaultModalContent.classList.remove('scale-95');
        }, 10);
    }

    function closeVaultPicker() {
        vaultModal.classList.add('opacity-0');
        vaultModalContent.classList.add('scale-95');
        setTimeout(() => {
            vaultModal.classList.add('hidden');
            currentRequirementId = null;
        }, 300);
    }

    function selectVaultFile(vaultId, docType) {
        if (!currentRequirementId) return;

        // Apply vault ID and strip away standard 'required' state on the actual file input since it's sourced from Vault
        document.getElementById('vault_input_' + currentRequirementId).value = vaultId;
        document.getElementById('file_' + currentRequirementId).value = "";
        document.getElementById('file_' + currentRequirementId).removeAttribute('required');

        const statusBadge = document.getElementById('status_' + currentRequirementId);
        statusBadge.className = "text-blue-600 font-bold";
        statusBadge.innerText = "Vault Linked: " + docType;

        closeVaultPicker();
    }

    // --- PAGINATION LOGIC ---
    let currentPage = 1;
    let itemsPerPage = parseInt(document.getElementById('itemsPerPage') ? document.getElementById('itemsPerPage').value : 10) || 10;
    const allCards = Array.from(document.querySelectorAll('.scholarship-card'));
    let cards = allCards;          // cards that pass the current filters
    let totalItems = cards.length;

    // --- CATEGORY / SOURCE FILTERS ---
    let activeCategory = '';

    function setCategoryFilter(chip) {
        activeCategory = chip.dataset.filterCategory;
        applyFilters();
    }

    function setEligibilityFilter(value) {
        const eligibilityFilter = document.getElementById('eligibilityFilter');
        if (eligibilityFilter) eligibilityFilter.value = value;
        applyFilters();
        document.getElementById('scholarshipsGrid').scrollIntoView({ behavior: 'smooth' });
    }

    function applyFilters() {
        const typeFilter = document.getElementById('typeFilter');
        const activeType = typeFilter ? typeFilter.value : '';
        const eligibilityFilter = document.getElementById('eligibilityFilter');
        const activeEligibility = eligibilityFilter ? eligibilityFilter.value : '';

        cards = allCards.filter(card =>
            (!activeCategory || card.dataset.category === activeCategory) &&
            (!activeType || card.dataset.type === activeType) &&
            (!activeEligibility || card.dataset.eligibility === activeEligibility)
        );
        totalItems = cards.length;
        currentPage = 1;

        document.querySelectorAll('.category-chip').forEach(chip => {
            const isActive = chip.dataset.filterCategory === activeCategory;
            chip.classList.toggle('bg-green-600', isActive);
            chip.classList.toggle('text-white', isActive);
            chip.classList.toggle('border-green-600', isActive);
            chip.classList.toggle('bg-white', !isActive);
            chip.classList.toggle('text-slate-600', !isActive);
            chip.classList.toggle('border-slate-200', !isActive);
        });

        const noMatches = document.getElementById('noFilterMatches');
        if (noMatches) noMatches.classList.toggle('hidden', totalItems > 0 || allCards.length === 0);

        allCards.forEach(card => card.style.display = 'none');
        if (totalItems === 0) {
            document.getElementById('paginationControls').innerHTML = '';
            document.getElementById('pageInfo').innerText = '';
            return;
        }
        renderPagination();
    }

    function renderPagination() {
        if (totalItems === 0) return;
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        allCards.forEach(card => card.style.display = 'none');
        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        cards.slice(start, end).forEach(card => card.style.display = 'flex');

        const pageInfo = document.getElementById('pageInfo');
        if (pageInfo) pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Grants`;

        let btnHtml = '';
        btnHtml += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&larr;</button>`;
        
        let startPage = Math.max(1, currentPage - 1);
        let endPage = Math.min(totalPages, currentPage + 1);

        if (currentPage === 1) endPage = Math.min(totalPages, 3);
        else if (currentPage === totalPages) startPage = Math.max(1, totalPages - 2);

        if (startPage > 1) {
            btnHtml += `<button onclick="changePage(1)" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100">1</button>`;
            if (startPage > 2) btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
        }

        for(let i = startPage; i <= endPage; i++) {
            btnHtml += `<button onclick="changePage(${i})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === i ? 'bg-green-600 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) btnHtml += `<span class="w-10 h-10 flex items-center justify-center text-slate-400 font-bold">...</span>`;
            btnHtml += `<button onclick="changePage(${totalPages})" class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all bg-white border border-slate-200 text-slate-700 hover:bg-slate-100">${totalPages}</button>`;
        }
        
        btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="w-10 h-10 rounded-xl font-black text-sm flex items-center justify-center transition-all ${currentPage === totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'}">&rarr;</button>`;
        
        const paginationControls = document.getElementById('paginationControls');
        if(paginationControls) paginationControls.innerHTML = btnHtml;
    }

    function changePage(page) {
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        if(page < 1 || page > totalPages) return;
        currentPage = page;
        renderPagination();
        document.getElementById('scholarshipsGrid').scrollIntoView({ behavior: 'smooth' });
    }

    function changeItemsPerPage() {
        itemsPerPage = parseInt(document.getElementById('itemsPerPage').value) || 10;
        currentPage = 1;
        renderPagination();
    }

    // applyFilters styles the "All" chip as active and renders the first page
    document.addEventListener('DOMContentLoaded', applyFilters);
</script>

<?php include '../includes/footer.php'; ?>
