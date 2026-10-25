<?php
session_start();
require 'includes/db_connect.php';
require 'includes/session_manager.php';

$scholarship_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$is_student = $is_logged_in && ($_SESSION['role'] === 'Student');
$user_id = $_SESSION['user_id'] ?? null;

function getScholarshipImage($programName, $scholarshipName) {
    $combined = strtolower(($programName ?? '') . ' ' . ($scholarshipName ?? ''));
    $params = '?auto=format&fit=crop&w=1200&q=80';

    // Local Image Overrides
    if (strpos($combined, 'indigenous') !== false || strpos($combined, 'ip student') !== false || preg_match('/\bip\b/', $combined)) {
        return 'assets/img/ip_scholarship.jpg';
    }
    if (strpos($combined, 'rotc') !== false || strpos($combined, 'cadet') !== false) {
        return 'assets/img/rotc_cadet.jpg';
    }

    // Unsplash Fallbacks
    $categories = [
        'photo-1461896836934-ffe607ba8211' => ['athlete', 'sport', 'training'],
        'photo-1514525253161-7a46d19cd819' => ['cultural', 'performer', 'socio-cultural', 'dance', 'music'],
        'photo-1504711434969-e33886168f5c' => ['golden harvest', 'publication', 'editorial', 'journalism'],
        'photo-1529156069898-49953e39b3ac' => ['leadership', 'ssc', 'csc', 'council', 'officer'],
        'photo-1517694712202-14dd9538aa97' => ['information technology', 'computer', 'bsit', 'tech', 'software'],
        'photo-1625246333195-78d9c38ad449' => ['agriculture', 'agri', 'forest', 'crop', 'plant', 'soil', 'tulong agri', 'acef'],
        'photo-1581092160562-40aa08e78837' => ['engineer', 'geodetic', 'builder', 'civil', 'arch'],
        'photo-1516467508483-a7212febe31a' => ['veterinary', 'vet', 'animal science', 'bounty', 'philchema'],
        'photo-1524178232363-1fb2b075b655' => ['education', 'teacher', 'teaching', 'beed', 'bsed'],
        'photo-1454165804606-c3d57bc86b40' => ['business', 'entrepreneur', 'economics', 'management'],
        'photo-1523240795612-9a054b0db644' => ['academic scholars', 'full', 'partial', 'honor', 'merit', 'dinos', 'tolentino'],
        'photo-1523050854058-8df90110c9f1' => ['tertiary educational subsidy', 'tes', 'tulong dunong', 'dost', 'subsidy', 'camileño', 'graduating']
    ];

    foreach ($categories as $photoId => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($combined, $kw) !== false) {
                return 'https://images.unsplash.com/' . $photoId . $params;
            }
        }
    }

    return 'https://images.unsplash.com/photo-1562774053-701939374585' . $params;
}

// 1. FETCH SCHOLARSHIP DETAILS & SPONSOR INFO
try {
    $stmt = $pdo->prepare("
        SELECT s.*, p.ProgramName, u.Organization as SponsorName, u.FirstName, u.LastName 
        FROM scholarship s 
        LEFT JOIN program p ON s.ProgramID = p.ProgramID 
        LEFT JOIN users u ON s.CreatedBy = u.UserID 
        WHERE s.ScholarshipID = ?
    ");
    $stmt->execute([$scholarship_id]);
    $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scholarship) {
        die("<h2>Scholarship not found.</h2><a href='index.php'>Go back</a>");
    }

    $sponsor_name = !empty($scholarship['SponsorName']) ? $scholarship['SponsorName'] : trim($scholarship['FirstName'] . ' ' . $scholarship['LastName']);
    if (empty($sponsor_name)) $sponsor_name = 'TAU Institutional Fund';

    $scholarshipNameLower = strtolower($scholarship['Name']); 
    $minUnits = 0; 
    if (strpos($scholarshipNameLower, 'academic scholars - partial') !== false) {
        $minUnits = 12;
    } elseif (strpos($scholarshipNameLower, 'academic scholars - full') !== false) {
        $minUnits = 15;
    }

    // 2. FETCH REQUIREMENTS FOR THIS SCHOLARSHIP
    $req_stmt = $pdo->prepare("SELECT * FROM document_requirement WHERE ScholarshipID = ?");
    $req_stmt->execute([$scholarship_id]);
    $requirements = $req_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. STUDENT ELIGIBILITY CHECKER
    $has_applied = false;
    $has_other_active_app = false;
    $is_eligible_gwa = true;
    $is_eligible_units = true;
    $is_eligible_year = true;
    $is_eligible_program = true;
    $missing_docs = [];
    $student_gwa = 5.00;
    $student_year = '';
    $student_major = '';
    $student_units = 0;
    $application_status = '';
    $application_id_for_pdf = null; 

    $custom_fields_stmt = $pdo->prepare("SELECT COUNT(*) FROM scholarship_custom_fields WHERE ScholarshipID = ? AND IsActive = 1 AND FieldType <> 'Section'");
    $custom_fields_stmt->execute([$scholarship_id]);
    $has_custom_fields = $custom_fields_stmt->fetchColumn() > 0;
    
    $app_stmt = $pdo->prepare("SELECT Status, ApplicationID FROM application WHERE ScholarshipID = ? AND UserID = ?");
    $app_stmt->execute([$scholarship_id, $user_id]);
    $existing_app = $app_stmt->fetch();
    
    $other_app_stmt = $pdo->prepare("SELECT Status FROM application WHERE UserID = ? AND ScholarshipID != ? AND Status != 'Rejected'");
    $other_app_stmt->execute([$user_id, $scholarship_id]);
    if ($other_app_stmt->fetch()) {
        $has_other_active_app = true;
    }

    if ($existing_app) {
        $has_applied = true;
        $application_status = $existing_app['Status'];
        $application_id_for_pdf = $existing_app['ApplicationID'];
    } else {
        $user_stmt = $pdo->prepare("SELECT GPA, YearLevel, Major, UnitsEnrolled FROM users WHERE UserID = ?");
        $user_stmt->execute([$user_id]);
        $student_data = $user_stmt->fetch();
        
        $student_gwa = (float)($student_data['GPA'] ?? 5.00);
        $student_year = $student_data['YearLevel'] ?? '';
        $student_major = $student_data['Major'] ?? '';
        $student_units = (int)($student_data['UnitsEnrolled'] ?? 0);

        if ($student_gwa > (float)$scholarship['MinimumGWA'] || $student_gwa == 0.00) {
            $is_eligible_gwa = false;
        }

        if (isset($minUnits) && $minUnits > 0 && $student_units < $minUnits) {
            $is_eligible_units = false;
        }

        $req_year = $scholarship['YearLevel'] ?? 'All Levels';
        if ($req_year !== 'All Levels' && !empty($req_year) && $req_year !== $student_year) {
            $is_eligible_year = false;
        }

        $prog_stmt = $pdo->prepare("
            SELECT p.ProgramName 
            FROM scholarship_programs sp
            JOIN program p ON sp.ProgramID = p.ProgramID
            WHERE sp.ScholarshipID = ?
        ");
        $prog_stmt->execute([$scholarship_id]);
        $allowed_programs = $prog_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($allowed_programs) && !in_array($student_major, $allowed_programs)) {
            $is_eligible_program = false;
        }

        $vault_stmt = $pdo->prepare("SELECT DocumentType FROM user_vault WHERE UserID = ?");
        $vault_stmt->execute([$user_id]);
        $vault_docs = $vault_stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($requirements as $req) {
            if (!in_array($req['DocumentName'], $vault_docs)) {
                $missing_docs[] = $req['DocumentName'];
            }
        }
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($scholarship['Name']) ?> - ScholarLink</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; color: #0f172a; }
        .glass-panel { background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(10px); border: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <nav class="bg-white shadow-sm border-b border-slate-200 sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <a href="index.php" class="flex items-center gap-3">
                <img src="assets/img/tau_logo.png" alt="TAU Logo" class="h-10 w-10">
                <div>
                    <h1 class="font-black text-slate-900 leading-tight">ScholarLink</h1>
                    <p class="text-[10px] font-bold text-green-600 uppercase tracking-widest">Tarlac Agricultural University</p>
                </div>
            </a>
            <div class="flex items-center gap-3">
                <?php if ($is_student): ?>
                    <a href="student/dashboard.php" class="text-sm font-bold text-slate-600 hover:text-green-600">My Dashboard</a>
                <?php endif; ?>
                <a href="index.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-bold py-2 px-4 rounded-xl transition-all">Back to Home</a>
            </div>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
        <?php if(isset($_SESSION['success'])): ?>
            <div class="mb-6 p-4 rounded-xl bg-green-50 border border-green-200 text-green-800 font-bold flex items-center gap-3">
                <i class="fas fa-check-circle text-xl"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
            <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 font-bold flex items-center gap-3">
                <i class="fas fa-exclamation-circle text-xl"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- LEFT COLUMN: Details & Visual Banner -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Visual Scholarship Header Card -->
                <div class="glass-panel rounded-[2rem] shadow-sm relative overflow-hidden">
                    <div class="relative h-56 sm:h-72 w-full overflow-hidden">
                        <img src="<?= htmlspecialchars(getScholarshipImage($scholarship['ProgramName'] ?? '', $scholarship['Name'])) ?>" 
                             alt="<?= htmlspecialchars($scholarship['Name']) ?>" 
                             class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-900/40 to-transparent"></div>
                        <div class="absolute top-4 left-4">
                            <span class="inline-block px-3 py-1 bg-green-600/90 backdrop-blur-md text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm">
                                <?= htmlspecialchars($scholarship['ScholarshipType']) ?> Grant
                            </span>
                        </div>
                        <div class="absolute bottom-4 left-4 sm:left-8 right-4 text-white">
                            <h1 class="text-2xl sm:text-4xl font-black leading-tight drop-shadow-md">
                                <?= htmlspecialchars($scholarship['Name']) ?>
                            </h1>
                            <div class="flex items-center gap-2 text-slate-200 text-sm font-semibold mt-2 drop-shadow-sm">
                                <i class="fas fa-building text-yellow-400"></i>
                                <span>Funded by <strong class="text-white"><?= htmlspecialchars($sponsor_name) ?></strong></span>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 sm:p-8">
                        <p class="text-slate-600 text-base sm:text-lg leading-relaxed mb-8">
                            <?= nl2br(htmlspecialchars($scholarship['Description'])) ?>
                        </p>

                        <!-- Fast Facts Grid -->
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 p-5 bg-slate-50 rounded-2xl border border-slate-100">
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Target Program</p>
                                <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($scholarship['ProgramName'] ?? 'Open to All') ?></p>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Target Year</p>
                                <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($scholarship['YearLevel'] ?? 'All Levels') ?></p>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Min. GWA</p>
                                <p class="font-bold text-slate-800 text-sm"><?= number_format($scholarship['MinimumGWA'], 2) ?></p>
                            </div>
                            <?php if ($minUnits > 0): ?>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Min. Units</p>
                                <p class="font-bold text-slate-800 text-sm"><?= $minUnits ?> Units</p>
                            </div>
                            <?php endif; ?>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Award Amount</p>
                                <p class="font-black text-green-600 text-sm">₱<?= number_format($scholarship['AwardAmount'], 2) ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Required Documents Panel -->
                <div class="glass-panel p-6 sm:p-8 rounded-[2rem] shadow-sm">
                    <h3 class="text-xl font-black text-slate-900 mb-6 flex items-center gap-3">
                        <i class="fas fa-file-contract text-blue-500"></i> Required Documents
                    </h3>
                    
                    <?php if (empty($requirements)): ?>
                        <p class="text-slate-500 font-medium italic">No specific documents required.</p>
                    <?php else: ?>
                        <ul class="space-y-4">
                            <?php foreach($requirements as $req): ?>
                                <?php $is_in_vault = $is_student && !in_array($req['DocumentName'], $missing_docs); ?>
                                <li class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-xl <?= $is_in_vault ? 'bg-green-50 border border-green-100' : 'bg-slate-50 border border-slate-200' ?>">
                                    <div class="flex items-center gap-3">
                                        <i class="fas fa-file-pdf <?= $is_in_vault ? 'text-green-500' : 'text-red-400' ?> text-xl"></i>
                                        <span class="font-bold <?= $is_in_vault ? 'text-green-800' : 'text-slate-700' ?> text-sm sm:text-base"><?= htmlspecialchars($req['DocumentName']) ?></span>
                                    </div>
                                    <?php if ($is_student): ?>
                                        <?php if ($is_in_vault): ?>
                                            <span class="text-[11px] font-black bg-green-200 text-green-700 px-3 py-1 rounded-full uppercase tracking-widest w-fit">Ready in Vault</span>
                                        <?php else: ?>
                                            <span class="text-[11px] font-black bg-red-100 text-red-600 px-3 py-1 rounded-full uppercase tracking-widest w-fit">Missing</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT COLUMN: Application Panel & Timer -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Countdown Timer Widget -->
                <div class="glass-panel p-6 rounded-[2rem] shadow-sm text-center">
                    <div class="w-12 h-12 bg-slate-100 text-slate-600 rounded-full flex items-center justify-center mx-auto mb-4 text-xl">
                        <i class="fas fa-hourglass-half animate-pulse"></i>
                    </div>
                    <h3 class="text-sm font-black text-slate-400 uppercase tracking-widest mb-1">Application Deadline</h3>
                    <p class="font-bold text-slate-800 mb-6"><?= date('F j, Y', strtotime($scholarship['Deadline'])) ?></p>
                    
                    <div id="countdown" class="grid grid-cols-3 gap-2" data-deadline="<?= $scholarship['Deadline'] ?> 23:59:59">
                        <div class="bg-slate-900 text-white rounded-xl p-3">
                            <span id="days" class="block text-2xl font-black">--</span>
                            <span class="text-[9px] uppercase tracking-widest opacity-70 font-bold">Days</span>
                        </div>
                        <div class="bg-slate-900 text-white rounded-xl p-3">
                            <span id="hours" class="block text-2xl font-black">--</span>
                            <span class="text-[9px] uppercase tracking-widest opacity-70 font-bold">Hours</span>
                        </div>
                        <div class="bg-slate-900 text-white rounded-xl p-3">
                            <span id="mins" class="block text-2xl font-black">--</span>
                            <span class="text-[9px] uppercase tracking-widest opacity-70 font-bold">Mins</span>
                        </div>
                    </div>
                </div>

                <!-- Application Command Center -->
                <div class="glass-panel p-6 rounded-[2rem] shadow-sm border-t-4 border-t-blue-500">
                    <h3 class="font-black text-slate-900 mb-4 text-lg">Application Status</h3>
                    
                    <?php if (!$is_logged_in): ?>
                        <div class="text-center p-4 bg-slate-50 rounded-xl border border-slate-200 mb-4">
                            <i class="fas fa-lock text-3xl text-slate-300 mb-2"></i>
                            <p class="text-sm text-slate-600 font-medium">Log in to your student account to check eligibility and apply.</p>
                        </div>
                        <a href="student_login.php" class="block w-full text-center bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-xl transition-all shadow-md">
                            Log In to Apply
                        </a>
                    <?php elseif (!$is_student): ?>
                        <div class="text-center p-4 bg-orange-50 rounded-xl border border-orange-200 text-orange-800">
                            <i class="fas fa-shield-alt text-2xl mb-2"></i>
                            <p class="text-sm font-bold">Administrators cannot apply for scholarships.</p>
                        </div>
                    <?php elseif (strtolower($scholarship['Status']) !== 'active'): ?>
                        <div class="text-center p-4 bg-slate-100 rounded-xl border border-slate-300 text-slate-600">
                            <i class="fas fa-times-circle text-2xl mb-2"></i>
                            <p class="text-sm font-bold">This scholarship is currently closed.</p>
                        </div>
                    <?php elseif ($has_applied): ?>
                        <div class="text-center p-6 bg-green-50 rounded-xl border border-green-200">
                            <div class="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-3 text-3xl">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h4 class="font-black text-green-800 text-lg mb-1">Application Submitted</h4>
                            <p class="text-xs font-bold text-green-600 uppercase tracking-widest mb-4">Status: <?= htmlspecialchars($application_status) ?></p>
                            <div class="flex flex-col sm:flex-row gap-2 justify-center">
                                <a href="student/applications.php" class="flex-1 bg-white border border-green-200 text-green-700 font-bold py-2 px-4 rounded-lg hover:bg-green-100 transition-colors text-sm">Track Progress</a>
                                <a href="actions/generate_application_pdf.php?app_id=<?= $application_id_for_pdf ?>" target="_blank" class="flex-1 bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition-colors text-sm">
                                    Download Form 📄
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Eligibility Diagnostics -->
                        <div class="space-y-3 mb-6">
                            <?php if ($has_other_active_app): ?>
                                <div class="flex items-start gap-3 p-3 bg-red-50 rounded-lg border border-red-100">
                                    <i class="fas fa-hand-paper text-red-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-sm font-bold text-red-800">Multiple Applications Not Allowed</p>
                                        <p class="text-xs text-red-600 mt-1">You can only apply for one active scholarship at a time.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($minUnits) && $minUnits > 0 && !$is_eligible_units): ?>
                                <div class="flex items-start gap-3 p-3 bg-red-50 rounded-lg border border-red-100">
                                    <i class="fas fa-book-open text-red-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-sm font-bold text-red-800">Unit Requirement Not Met</p>
                                        <p class="text-xs text-red-600 mt-1">Your enrolled units: <?= $student_units ?> (Required: <?= $minUnits ?>).</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!$is_eligible_gwa): ?>
                                <div class="flex items-start gap-3 p-3 bg-red-50 rounded-lg border border-red-100">
                                    <i class="fas fa-times-circle text-red-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-sm font-bold text-red-800">GWA Requirement Not Met</p>
                                        <p class="text-xs text-red-600 mt-1">Your GWA is <?= number_format($student_gwa, 2) ?> (Required: <?= number_format($scholarship['MinimumGWA'], 2) ?>).</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!$is_eligible_year): ?>
                                <div class="flex items-start gap-3 p-3 bg-red-50 rounded-lg border border-red-100">
                                    <i class="fas fa-user-times text-red-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-sm font-bold text-red-800">Year Level Mismatch</p>
                                        <p class="text-xs text-red-600 mt-1">Restricted to <?= htmlspecialchars($scholarship['YearLevel']) ?>.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!$is_eligible_program): ?>
                                <div class="flex items-start gap-3 p-3 bg-red-50 rounded-lg border border-red-100">
                                    <i class="fas fa-ban text-red-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-sm font-bold text-red-800">Program Mismatch</p>
                                        <p class="text-xs text-red-600 mt-1">Not offered for your registered major.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($missing_docs)): ?>
                                <div class="flex items-start gap-3 p-3 bg-orange-50 rounded-lg border border-orange-100">
                                    <i class="fas fa-exclamation-triangle text-orange-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-sm font-bold text-orange-800">Missing Vault Documents</p>
                                        <p class="text-xs text-orange-600 mt-1">Missing <?= count($missing_docs) ?> required document(s).</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!$has_other_active_app && $is_eligible_gwa && $is_eligible_units && $is_eligible_year && $is_eligible_program && empty($missing_docs)): ?>
                                <div class="flex items-center gap-3 p-3 bg-green-50 rounded-lg border border-green-100">
                                    <i class="fas fa-check-circle text-green-500"></i>
                                    <span class="text-sm font-bold text-green-800">You are eligible for this grant!</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!$has_other_active_app && $is_eligible_gwa && $is_eligible_units && $is_eligible_year && $is_eligible_program && empty($missing_docs)): ?>
                            <?php if ($has_custom_fields): ?>
                                <a href="student/apply_form.php?id=<?= $scholarship_id ?>" class="block w-full text-center bg-blue-600 hover:bg-blue-700 text-white font-black py-3.5 px-4 rounded-xl transition-all shadow-md text-base">
                                    Start Application Form <i class="fas fa-arrow-right ml-2"></i>
                                </a>
                            <?php else: ?>
                                <form action="actions/process_crud.php" method="POST">
                                    <input type="hidden" name="module" value="student_apply">
                                    <input type="hidden" name="action" value="one_click_apply">
                                    <input type="hidden" name="scholarship_id" value="<?= $scholarship_id ?>">
                                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-black py-3.5 px-4 rounded-xl transition-all shadow-md flex items-center justify-center gap-2 text-base">
                                        <i class="fas fa-bolt"></i> 1-Click Apply Now
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <button disabled class="w-full bg-slate-200 text-slate-400 font-black py-3.5 px-4 rounded-xl cursor-not-allowed text-base">
                                <i class="fas fa-lock"></i> Apply Now
                            </button>
                            <?php if (!empty($missing_docs)): ?>
                                <a href="student/vault.php" class="mt-3 block w-full text-center bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold py-2.5 px-4 rounded-xl transition-colors border border-blue-200 text-sm">
                                    Go to Document Vault <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        const countdownEl = document.getElementById('countdown');
        if (countdownEl) {
            const deadline = new Date(countdownEl.dataset.deadline).getTime();
            const timer = setInterval(() => {
                const now = new Date().getTime();
                const distance = deadline - now;
                if (distance < 0) {
                    clearInterval(timer);
                    document.getElementById('days').innerText = "00";
                    document.getElementById('hours').innerText = "00";
                    document.getElementById('mins').innerText = "00";
                    return;
                }
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const mins = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                document.getElementById('days').innerText = days < 10 ? '0' + days : days;
                document.getElementById('hours').innerText = hours < 10 ? '0' + hours : hours;
                document.getElementById('mins').innerText = mins < 10 ? '0' + mins : mins;
            }, 1000);
        }
    </script>
</body>
</html>