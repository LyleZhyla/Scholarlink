<?php
session_start();
require 'includes/db_connect.php';
require_once 'includes/scholarship_categories.php';

// Fetch CMS Content
$cms = [];
try {
    $cms_stmt = $pdo->query("SELECT * FROM landing_content");
    while($row = $cms_stmt->fetch(PDO::FETCH_ASSOC)) {
        $cms[$row['section_key']] = $row;
    }
} catch(PDOException $e) {}

$hero_body = $cms['hero']['body'] ?? "Don't let finances limit your potential. Access exclusive TAU grants, build your secure document vault, and apply to multiple scholarships with just one click.";
$grants_title = $cms['grants_header']['title'] ?? 'Available Scholarships & Grants';
$grants_body = $cms['grants_header']['body'] ?? 'Explore opportunities tailored for your course and year level.';
$no_grants_title = $cms['no_grants']['title'] ?? 'No scholarships available right now';
$no_grants_body = $cms['no_grants']['body'] ?? 'We are preparing new grants for you. Please check back later!';

// Fetch Scholarships
try {
    $stmt = $pdo->query("
        SELECT s.*, p.ProgramName, cu.Role AS CreatorRole,
               COALESCE(NULLIF(cu.Organization, ''), CONCAT(cu.FirstName, ' ', cu.LastName)) AS Provider
        FROM scholarship s
        LEFT JOIN program p ON s.ProgramID = p.ProgramID
        LEFT JOIN users cu ON cu.UserID = s.CreatedBy
        ORDER BY (s.Status = 'Active') DESC, s.Deadline ASC
    ");
    $all_scholarships = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_scholarships = [];
}

$active_scholarship_count = 0;
$total_active_award_value = 0;
$nearest_active_deadline = null;

foreach ($all_scholarships as $sch) {
    if (strtolower($sch['Status'] ?? '') === 'active') {
        $active_scholarship_count++;
        $total_active_award_value += (float) ($sch['AwardAmount'] ?? 0);
        if (!empty($sch['Deadline'])) {
            $deadlineTime = strtotime($sch['Deadline']);
            if ($deadlineTime && ($nearest_active_deadline === null || $deadlineTime < $nearest_active_deadline)) {
                $nearest_active_deadline = $deadlineTime;
            }
        }
    }
}

$nearest_deadline_label = $nearest_active_deadline ? date('M j, Y', $nearest_active_deadline) : 'None open';

// "Browse by type" tiles: open grants and awards per category
$category_stats = [];
foreach (SCHOLARSHIP_CATEGORIES as $key => $meta) $category_stats[$key] = ['open' => 0, 'total' => 0, 'top' => 0.0];
foreach ($all_scholarships as $sch) {
    $cat = $sch['Category'] ?? DEFAULT_SCHOLARSHIP_CATEGORY;
    if (!isset($category_stats[$cat])) continue;
    $category_stats[$cat]['total']++;
    if (strtolower($sch['Status'] ?? '') === 'active') {
        $category_stats[$cat]['open']++;
        $category_stats[$cat]['top'] = max($category_stats[$cat]['top'], (float) ($sch['AwardAmount'] ?? 0));
    }
}

// Scholarship partners: who funds the grants (the OSSD for internally funded ones)
$partners = [];
foreach ($all_scholarships as $sch) {
    $internal = $sch['CreatorRole'] === null || in_array($sch['CreatorRole'], ['Internal_Admin', 'Super_Admin'], true);
    $name = $internal ? 'Tarlac Agricultural University' : trim((string) $sch['Provider']);
    if ($name === '') continue;
    $partners[$name] = ($partners[$name] ?? ['name' => $name, 'internal' => $internal, 'count' => 0]);
    $partners[$name]['count']++;
}
uasort($partners, fn($a, $b) => [$b['internal'], $b['count']] <=> [$a['internal'], $a['count']]);

// Every program/course for the filter, and which programs each scholarship is open to:
// its list in scholarship_programs plus the older single ProgramID. None at all = open to all programs.
$all_programs = [];
$eligible_programs = [];   // ScholarshipID => [ProgramID => ProgramName]
try {
    $all_programs = $pdo->query("SELECT ProgramID, ProgramName FROM program ORDER BY ProgramName")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($pdo->query("SELECT ScholarshipID, ProgramID FROM scholarship_programs") as $link) {
        if (isset($all_programs[$link['ProgramID']])) $eligible_programs[(int) $link['ScholarshipID']][(int) $link['ProgramID']] = $all_programs[$link['ProgramID']];
    }
} catch (PDOException $e) {}
foreach ($all_scholarships as $sch) {
    if (!empty($sch['ProgramID']) && isset($all_programs[$sch['ProgramID']])) {
        $eligible_programs[(int) $sch['ScholarshipID']][(int) $sch['ProgramID']] = $all_programs[$sch['ProgramID']];
    }
}

$applied_applications = [];
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_id']) && $_SESSION['role'] === 'Student') {
    try {
        $applied_stmt = $pdo->prepare("SELECT ScholarshipID, ApplicationID FROM application WHERE UserID = :uid");
        $applied_stmt->execute(['uid' => $_SESSION['user_id']]);
        $applied_applications = $applied_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {}
}

$heroSlides = [
    'https://images.unsplash.com/photo-1523580846011-d3a5bc25702b?auto=format&fit=crop&w=1800&q=80',
    'https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=1800&q=80',
    'https://images.unsplash.com/photo-1517048676732-d65bc937f952?auto=format&fit=crop&w=1800&q=80'
];

function getProgramImage($programName = '', $scholarshipName = '', $yearLevel = '') {
    $text = strtolower(($programName ?? '') . ' ' . ($scholarshipName ?? '') . ' ' . ($yearLevel ?? ''));
    $params = '?auto=format&fit=crop&w=800&q=80';

    // 1. HIGH-PRIORITY SPECIFIC SCHOLARSHIPS & LOCAL OVERRIDES
    if (strpos($text, 'indigenous') !== false || strpos($text, 'ip student') !== false || preg_match('/\bip\b/', $text)) {
        return 'assets/img/ip_scholarship.jpg';
    }
    if (strpos($text, 'rotc') !== false || strpos($text, 'cadet') !== false) {
        return 'assets/img/rotc_cadet.jpg';
    }
    if (strpos($text, 'athlete') !== false || strpos($text, 'sport') !== false) {
        return 'https://images.unsplash.com/photo-1461896836934-ffe607ba8211' . $params;
    }
    if (strpos($text, 'cultural') !== false || strpos($text, 'performer') !== false) {
        return 'https://images.unsplash.com/photo-1514525253161-7a46d19cd819' . $params;
    }
    if (strpos($text, 'golden harvest') !== false || strpos($text, 'publication') !== false) {
        return 'https://images.unsplash.com/photo-1504711434969-e33886168f5c' . $params;
    }
    if (strpos($text, 'leadership') !== false || strpos($text, 'ssc') !== false || strpos($text, 'csc') !== false) {
        return 'https://images.unsplash.com/photo-1529156069898-49953e39b3ac' . $params;
    }

    // 2. DEGREE PROGRAM MATCHING
    if (strpos($text, 'information technology') !== false || strpos($text, 'computer') !== false || strpos($text, 'bsit') !== false) {
        return 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97' . $params;
    }
    if (strpos($text, 'agri') !== false || strpos($text, 'forest') !== false) {
        return 'https://images.unsplash.com/photo-1625246333195-78d9c38ad449' . $params;
    }
    if (strpos($text, 'engineer') !== false || strpos($text, 'geodetic') !== false || strpos($text, 'builder') !== false) {
        return 'https://images.unsplash.com/photo-1581092160562-40aa08e78837' . $params;
    }
    if (strpos($text, 'vet') !== false || strpos($text, 'animal science') !== false || strpos($text, 'bounty') !== false || strpos($text, 'philchema') !== false) {
        return 'https://images.unsplash.com/photo-1516467508483-a7212febe31a' . $params;
    }
    if (strpos($text, 'education') !== false || strpos($text, 'teach') !== false) {
        return 'https://images.unsplash.com/photo-1524178232363-1fb2b075b655' . $params;
    }
    if (strpos($text, 'business') !== false || strpos($text, 'economics') !== false || strpos($text, 'entrepreneur') !== false) {
        return 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40' . $params;
    }

    // 3. LOW-PRIORITY YEAR LEVEL & GENERIC FALLBACKS
    if (strpos($text, 'camileño') !== false || strpos($text, 'graduating') !== false || strpos($text, '4th year') !== false) {
        return 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1' . $params;
    }
    if (strpos($text, 'dinos') !== false || strpos($text, 'tolentino') !== false || strpos($text, '1st year') !== false) {
        return 'https://images.unsplash.com/photo-1523240795612-9a054b0db644' . $params;
    }

    return 'https://images.unsplash.com/photo-1562774053-701939374585' . $params;
}

function scholarship_status_meta($status, $deadline = null) {
    $status = $status ?: 'Active';
    if (strtolower($status) === 'active' && !empty($deadline)) {
        $days_left = floor((strtotime($deadline) - strtotime('today')) / 86400);
        if ($days_left < 0) {
            return ['label' => 'Expired', 'class' => 'status-closed'];
        } elseif ($days_left === 0) {
            return ['label' => '🚨 Ends Today!', 'class' => 'status-urgent animate-pulse'];
        } elseif ($days_left <= 7) {
            return ['label' => '🔥 ' . $days_left . ' Days Left', 'class' => 'status-urgent'];
        }
    }

    switch (strtolower($status)) {
        case 'active': return ['label' => 'Active', 'class' => 'status-active'];
        case 'closed':
        case 'inactive': return ['label' => 'Closed', 'class' => 'status-closed'];
        case 'draft': return ['label' => 'Draft', 'class' => 'status-draft'];
        case 'upcoming': return ['label' => 'Upcoming', 'class' => 'status-upcoming'];
        default: return ['label' => htmlspecialchars($status), 'class' => 'status-default'];
    }
}

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// Signed-in students: their profile pre-fills the filters so they see the scholarships they qualify for
$my_profile = null;
if ($is_logged_in && ($_SESSION['role'] ?? '') === 'Student') {
    try {
        $prof = $pdo->prepare("SELECT u.FirstName, u.ProgramID, u.YearLevel, u.GPA, u.UnitsEnrolled, p.ProgramName
                               FROM users u LEFT JOIN program p ON p.ProgramID = u.ProgramID WHERE u.UserID = ?");
        $prof->execute([(int) $_SESSION['user_id']]);
        if ($row = $prof->fetch(PDO::FETCH_ASSOC)) {
            $my_profile = [
                'name' => (string) $row['FirstName'],
                'program' => $row['ProgramID'] ? (string) (int) $row['ProgramID'] : '',
                'programName' => (string) ($row['ProgramName'] ?? ''),
                'year' => (string) ($row['YearLevel'] ?? ''),
                'gwa' => $row['GPA'] !== null && (float) $row['GPA'] > 0 ? number_format((float) $row['GPA'], 2) : '',
                'units' => $row['UnitsEnrolled'] ? (string) (int) $row['UnitsEnrolled'] : '',
            ];
        }
    } catch (PDOException $e) {}
}
$role_redirect = "student_login.php";
if ($is_logged_in) {
    if ($_SESSION['role'] === 'Student') $role_redirect = "student/dashboard.php";
    elseif ($_SESSION['role'] === 'Internal_Admin') $role_redirect = "internal_admin/dashboard.php";
    elseif ($_SESSION['role'] === 'External_Admin') $role_redirect = "external_admin/dashboard.php";
    elseif ($_SESSION['role'] === 'Super_Admin') $role_redirect = "super_admin/dashboard.php";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ScholarLink | Tarlac Agricultural University Scholarship Portal</title>
    <?php require_once __DIR__ . '/includes/theme.php'; themeHead(); ?>
    <meta name="description" content="Apply for institutional, private, and government scholarships at Tarlac Agricultural University through ScholarLink. Upload requirements to your vault and apply with 1-click.">
    <meta name="keywords" content="ScholarLink, TAU scholarship, Tarlac Agricultural University, TAU OSSD, student financial assistance, TES, Tulong Dunong">

    <!-- Social & OpenGraph Sharing (Facebook / Messenger / Viber Previews) -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://scholarlink.college/">
    <meta property="og:title" content="ScholarLink | Tarlac Agricultural University Scholarship Portal">
    <meta property="og:description" content="Official scholarship portal of Tarlac Agricultural University. Explore grants, build your document vault, and apply online.">
    <meta property="og:image" content="https://scholarlink.college/assets/img/tau_logo.png">

    <link rel="icon" type="image/png" href="assets/img/tau_logo.png">
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#198754">
    <link rel="apple-touch-icon" href="assets/img/tau_logo.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        :root {
            --ink: #0d2b1e;
            --ink-soft: #3d5a4c;
            --muted: #6b8178;
            --line: #e1ece5;
            --wash: #f5f9f6;
            --forest: #0b3d2a;
            --green: #198754;
            --green-soft: #e8f5ee;
            --mint: #a7f3d0;
            --gold: #c9971c;
            --gold-soft: #fdf6e3;
            --sky: #2563eb;
            --sky-soft: #eaf1fe;
            --page-max: 1200px;
            --page-gutter: clamp(16px, 4vw, 48px);
            --radius-lg: 24px;
            --shadow-sm: 0 1px 2px rgba(13, 43, 30, 0.04), 0 4px 14px rgba(13, 43, 30, 0.05);
            --shadow-md: 0 2px 6px rgba(13, 43, 30, 0.05), 0 18px 40px rgba(13, 43, 30, 0.09);
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; scroll-padding-top: 80px; }
        body {
            margin: 0; color: var(--ink); font-family: 'Plus Jakarta Sans', Arial, sans-serif; line-height: 1.55; overflow-x: hidden;
            background:
                radial-gradient(900px 500px at 100% 0%, rgba(167, 243, 208, 0.25), transparent 60%),
                radial-gradient(700px 500px at 0% 40%, rgba(201, 151, 28, 0.07), transparent 60%),
                var(--wash);
        }
        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; }
        .page-inner { width: min(var(--page-max), calc(100% - (var(--page-gutter) * 2))); margin: 0 auto; }

        /* ---------- ANNOUNCEMENT ---------- */
        .announce { background: linear-gradient(90deg, #f5c542, #f8d76b 50%, #f5c542); color: #4a3500; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; text-align: center; padding: 7px 16px; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .announce .dot { position: relative; width: 8px; height: 8px; flex-shrink: 0; }
        .announce .dot::before, .announce .dot::after { content: ''; position: absolute; inset: 0; border-radius: 50%; background: #dc2626; }
        .announce .dot::before { animation: ping 1.6s cubic-bezier(0, 0, 0.2, 1) infinite; opacity: 0.6; }
        @keyframes ping { 75%, 100% { transform: scale(2.4); opacity: 0; } }

        /* ---------- HEADER ---------- */
        .site-header { position: sticky; top: 0; z-index: 50; background: rgba(255, 255, 255, 0.85); border-bottom: 1px solid rgba(225, 236, 229, 0.8); backdrop-filter: saturate(180%) blur(16px); -webkit-backdrop-filter: saturate(180%) blur(16px); }
        .nav-shell { min-height: 68px; display: flex; align-items: center; justify-content: space-between; gap: 16px; position: relative; }
        .brand { display: inline-flex; align-items: center; gap: 11px; min-width: 0; }
        .brand img { width: 40px; height: 40px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(13, 43, 30, 0.15)); }
        .brand strong { display: block; font-size: 1.05rem; font-weight: 800; letter-spacing: -0.02em; color: var(--ink); line-height: 1.15; }
        .brand small { display: block; color: var(--gold); font-size: 0.64rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }

        .nav-links { display: flex; align-items: center; gap: 6px; font-size: 0.9rem; }
        .nav-links a:not(.login-link) { color: var(--ink-soft); font-weight: 700; padding: 8px 14px; border-radius: 999px; transition: background 0.2s, color 0.2s; }
        .nav-links a:not(.login-link):hover { color: var(--green); background: var(--green-soft); }
        .hamburger { display: none; background: transparent; border: none; font-size: 1.3rem; color: var(--ink); cursor: pointer; padding: 8px; }

        .login-link, .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 20px; border-radius: 12px;
            background: linear-gradient(135deg, #1f9d63, var(--green)) !important; border: 1px solid #157347 !important; color: #fff !important; font-weight: 800;
            transition: transform 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 0 rgba(255,255,255,0.25) inset, 0 8px 20px rgba(25, 135, 84, 0.25);
        }
        .nav-links .login-link { margin-left: 8px; min-height: 40px; }
        .login-link:hover, .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 1px 0 rgba(255,255,255,0.25) inset, 0 12px 26px rgba(25, 135, 84, 0.32); }
        .btn-ghost { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 20px; border-radius: 12px; font-weight: 800; background: rgba(255,255,255,0.08); color: #fff !important; border: 1px solid rgba(255,255,255,0.35); backdrop-filter: blur(10px); transition: background 0.2s ease; }
        .btn-ghost:hover { background: rgba(255, 255, 255, 0.18); }
        .btn-gold { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 0 22px; border-radius: 12px; font-weight: 800; color: #3b2a00 !important; background: linear-gradient(135deg, #f8d76b, #f0b429); box-shadow: 0 10px 24px rgba(240, 180, 41, 0.3); transition: transform 0.2s ease; white-space: nowrap; }
        .btn-gold:hover { transform: translateY(-1px); }

        /* ---------- FILTER BAR (top) ---------- */
        .search-command-center { margin: 18px auto 18px; background: #fff; border: 1px solid var(--line); border-radius: 20px; box-shadow: var(--shadow-md); padding: 12px; }
        .search-grid { display: grid; grid-template-columns: 1.6fr 1fr 1.25fr 0.95fr 0.75fr 0.75fr auto; gap: 8px; }
        .field { position: relative; display: flex; align-items: center; width: 100%; }
        .field > i { position: absolute; left: 14px; color: var(--green); font-size: 0.85rem; pointer-events: none; }
        .field.gold > i { color: var(--gold); }
        .field-input {
            width: 100%; min-height: 46px; padding: 10px 12px 10px 38px; border-radius: 12px; outline: none;
            background: #f7faf8; border: 1px solid transparent; color: var(--ink); font-size: 0.86rem; font-weight: 700;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
        }
        .field-input::placeholder { color: #94a69c; font-weight: 600; }
        select.field-input {
            appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 34px; text-overflow: ellipsis;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23198754' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 13px center;
        }
        .field-input:hover { background-color: #f1f7f3; }
        .field-input:focus { border-color: var(--green); background-color: #fff; box-shadow: 0 0 0 4px rgba(25, 135, 84, 0.1); }
        .reset-btn { min-height: 46px; width: 46px; border-radius: 12px; border: 1px solid var(--line); background: #fff; color: var(--muted); cursor: pointer; transition: all 0.2s; }
        .reset-btn:hover { color: var(--green); border-color: var(--green); transform: rotate(-90deg); }

        /* ---------- HERO (inset banner) ---------- */
        .hero { position: relative; overflow: hidden; border-radius: var(--radius-lg); color: #fff; padding: clamp(28px, 4vw, 48px); background: var(--forest); box-shadow: var(--shadow-md); }
        .hero-slideshow { position: absolute; inset: 0; z-index: 0; overflow: hidden; }
        .hero-slide { position: absolute; inset: 0; opacity: 0; background-position: center; background-size: cover; transform: scale(1.05); transition: opacity 1.2s ease, transform 6s ease; }
        .hero-slide.is-active { opacity: 1; transform: scale(1); }
        /* The hero takes the colour of the scholarship on show (set by heroRender via data-theme);
           registered so the colours cross-fade instead of jumping */
        @property --hero-deep { syntax: '<color>'; inherits: true; initial-value: #04291c; }
        @property --hero-mid { syntax: '<color>'; inherits: true; initial-value: #047857; }
        @property --hero-glow { syntax: '<color>'; inherits: true; initial-value: #34d399; }
        @property --hero-accent { syntax: '<color>'; inherits: true; initial-value: #a7f3d0; }
        .hero { --hero-deep: #04291c; --hero-mid: #047857; --hero-glow: #34d399; --hero-accent: #a7f3d0;
            transition: --hero-deep 0.9s ease, --hero-mid 0.9s ease, --hero-glow 0.9s ease, --hero-accent 0.9s ease; }
        .hero[data-theme="incentive"] { --hero-deep: #3b1d04; --hero-mid: #b45309; --hero-glow: #fbbf24; --hero-accent: #fde68a; }
        .hero[data-theme="allowance"] { --hero-deep: #0b1b4d; --hero-mid: #1d4ed8; --hero-glow: #60a5fa; --hero-accent: #bfdbfe; }
        .hero-slide { filter: saturate(1.2) contrast(1.05); }
        .hero-overlay { position: absolute; inset: 0; z-index: 1; pointer-events: none;
            background:
                radial-gradient(520px 320px at 92% 105%, color-mix(in srgb, var(--hero-glow) 45%, transparent), transparent 65%),
                radial-gradient(420px 260px at 8% -10%, color-mix(in srgb, var(--hero-accent) 22%, transparent), transparent 70%),
                linear-gradient(100deg,
                    color-mix(in srgb, var(--hero-deep) 94%, transparent) 0%,
                    color-mix(in srgb, var(--hero-deep) 80%, transparent) 38%,
                    color-mix(in srgb, var(--hero-mid) 52%, transparent) 72%,
                    color-mix(in srgb, var(--hero-mid) 30%, transparent) 100%); }
        .hero .hero-eyebrow { color: var(--hero-accent); }
        .hero .text-gradient { background-image: linear-gradient(90deg, var(--hero-accent) 0%, #fde68a 100%); }
        .hero .stat-icon.green { color: var(--hero-accent); background: color-mix(in srgb, var(--hero-accent) 18%, transparent); }
        .hero .hero-dots button.active { background: var(--hero-mid); }
        .hero .hero-panel-head > span > i { color: var(--hero-mid); }
        .hero .btn-primary { background: linear-gradient(135deg, color-mix(in srgb, var(--hero-mid) 75%, #fff), var(--hero-mid)) !important;
            border-color: color-mix(in srgb, var(--hero-mid) 80%, #000) !important; box-shadow: 0 1px 0 rgba(255,255,255,0.25) inset, 0 8px 20px color-mix(in srgb, var(--hero-mid) 35%, transparent); }
        .hero .hero-count { background: color-mix(in srgb, var(--hero-mid) 12%, #fff); color: var(--hero-mid); }
        .hero-grid { position: relative; z-index: 3; display: grid; grid-template-columns: 1.45fr 1fr; gap: clamp(20px, 4vw, 48px); align-items: center; }
        .hero-eyebrow { display: inline-flex; align-items: center; gap: 8px; padding: 5px 12px; border-radius: 999px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.18); color: var(--mint); font-size: 0.7rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; }
        /* .hero h1 (not plain h1) so Tailwind's CDN reset, injected after this block, can't shrink it */
        .hero h1 { margin: 16px 0 12px; font-size: clamp(2.1rem, 4.4vw, 3.4rem); line-height: 1.05; font-weight: 800; letter-spacing: -0.035em; }
        .text-gradient { background: linear-gradient(90deg, var(--mint) 0%, #fde68a 100%); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .hero-copy { max-width: 540px; margin: 0 0 24px; color: rgba(255, 255, 255, 0.82); font-size: 1rem; line-height: 1.65; }
        .hero-actions { display: flex; flex-wrap: wrap; gap: 10px; }

        .hero-stats { display: grid; gap: 10px; }
        .stat-tile { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 16px; background: linear-gradient(135deg, rgba(255,255,255,0.14), rgba(255,255,255,0.05)); border: 1px solid rgba(255,255,255,0.18); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); transition: transform 0.25s ease, background 0.25s ease; }
        .stat-tile:hover { transform: translateX(-4px); background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.08)); }
        .stat-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1rem; }
        .stat-icon.green { background: rgba(167, 243, 208, 0.16); color: var(--mint); }
        .stat-icon.gold { background: rgba(253, 230, 138, 0.16); color: #fde68a; }
        .stat-icon.sky { background: rgba(147, 197, 253, 0.16); color: #93c5fd; }
        .stat-tile strong { display: block; font-size: 1.3rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15; }
        .stat-tile > div > span { display: block; font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: rgba(255, 255, 255, 0.62); }

        /* Hero = intro + stats on the left, the available scholarships on the right (no scrolling needed to find them) */
        .hero-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1.08fr); align-items: stretch; }
        .hero-grid > div:first-child { display: flex; flex-direction: column; justify-content: center; min-width: 0; }
        .hero-grid .hero-eyebrow { align-self: flex-start; }
        .hero h1 { font-size: clamp(2rem, 3.6vw, 3rem); }
        .hero-copy { margin-bottom: 20px; }
        .hero-grid .hero-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 24px; }
        .hero-grid .stat-tile { flex-direction: column; align-items: flex-start; gap: 8px; padding: 12px 14px; }
        .hero-grid .stat-tile:hover { transform: translateY(-2px); }
        .hero-grid .stat-tile strong { font-size: 1.05rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
        .hero-grid .stat-tile > div { min-width: 0; max-width: 100%; }
        .hero-panel { display: flex; flex-direction: column; min-width: 0; border-radius: 20px; background: rgba(255, 255, 255, 0.97); color: #0f172a; box-shadow: 0 24px 50px -20px rgba(0, 0, 0, 0.55); overflow: hidden; }
        .hero-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--forest); }
        .hero-panel-head > span > i { margin-right: 6px; color: #059669; }
        .hero-count { margin-left: 6px; padding: 2px 8px; border-radius: 999px; background: #ecfdf5; color: #047857; font-size: 0.68rem; letter-spacing: 0.02em; }
        .hero-per-page { display: inline-flex; align-items: center; gap: 6px; font-size: 0.66rem; color: #64748b; white-space: nowrap; }
        .hero-per-page select { padding: 4px 22px 4px 8px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; font-size: 0.78rem; font-weight: 800; color: #0f172a; cursor: pointer; }
        .hero-pages { position: relative; height: 352px; padding: 12px; overflow: hidden; }
        .hero-page { height: 100%; animation: heroIn 0.35s ease; }
        @keyframes heroIn { from { opacity: 0; transform: translateX(14px); } to { opacity: 1; transform: none; } }

        /* 1 per page: the scholarship's own spotlight */
        .hero-spot { --cat: #059669; --cat-soft: #ecfdf5; display: flex; flex-direction: column; height: 100%; border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0; color: inherit; text-decoration: none; background: #fff; }
        .hero-spot-media { position: relative; height: 128px; flex-shrink: 0; background: var(--forest) center / cover no-repeat; }
        .hero-spot-media .hero-chip { position: absolute; left: 10px; bottom: 10px; }
        .hero-spot-media .hero-status { position: absolute; right: 10px; top: 10px; }
        .hero-spot-body { flex: 1; min-height: 0; display: flex; flex-direction: column; padding: 12px 16px 14px; }
        .hero-spot-meta { font-size: 0.7rem; font-weight: 700; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hero-spot h3 { margin: 4px 0 4px; font-size: 1.15rem; font-weight: 800; line-height: 1.2; color: #0f172a; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .hero-spot p { margin: 0; font-size: 0.8rem; line-height: 1.45; color: #475569; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .hero-spot-foot { margin-top: auto; display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; padding-top: 10px; }
        .hero-spot-award small { display: block; font-size: 0.6rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #94a3b8; }
        .hero-spot-award strong { font-size: 1.35rem; font-weight: 800; color: var(--forest); }
        .hero-spot-award span { margin-left: 4px; font-size: 0.7rem; font-weight: 700; color: #64748b; }
        .hero-spot-cta { display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 10px; background: var(--hero-mid, #059669); color: #fff; font-size: 0.78rem; font-weight: 800; white-space: nowrap; transition: filter 0.15s ease; }
        .hero-spot:hover .hero-spot-cta { filter: brightness(0.9); }
        .hero-spot-award strong, .hero-row-award { color: color-mix(in srgb, var(--hero-mid, #059669) 70%, #0f172a) !important; }
        .hero-spot-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .hero-spot-chips span { padding: 3px 8px; border-radius: 8px; background: #f1f5f9; font-size: 0.68rem; font-weight: 700; color: #475569; }

        /* 2–6 per page: the scholarships as rows */
        .hero-rows { display: grid; height: 100%; gap: 8px; }
        .hero-row { --cat: #059669; --cat-soft: #ecfdf5; display: flex; align-items: center; gap: 12px; min-height: 0; padding: 8px 10px; border-radius: 14px; border: 1px solid #e2e8f0; background: #fff; color: inherit; text-decoration: none; overflow: hidden; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .hero-row:hover { border-color: #6ee7b7; box-shadow: 0 6px 16px -10px rgba(5, 150, 105, 0.6); }
        .hero-row-thumb { align-self: stretch; width: 74px; flex-shrink: 0; border-radius: 10px; background: var(--forest) center / cover no-repeat; position: relative; }
        .hero-row-thumb i { position: absolute; left: 6px; bottom: 6px; width: 22px; height: 22px; border-radius: 7px; display: flex; align-items: center; justify-content: center; background: var(--cat-soft); color: var(--cat); font-size: 0.65rem; }
        .hero-row-main { flex: 1; min-width: 0; }
        .hero-row-main strong { display: block; font-size: 0.9rem; font-weight: 800; color: #0f172a; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hero-row-main small { display: block; margin-top: 3px; font-size: 0.7rem; font-weight: 700; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hero-row-main p { margin: 4px 0 0; font-size: 0.75rem; color: #64748b; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .hero-row-award { flex-shrink: 0; text-align: right; font-size: 0.95rem; font-weight: 800; color: var(--forest); }
        .hero-row-award small { display: block; font-size: 0.62rem; font-weight: 700; color: #94a3b8; }
        .hero-rows.compact .hero-row-thumb { width: 44px; }
        .hero-rows.compact .hero-row-main p { display: none; }

        .hero-spot.cat-incentive, .hero-row.cat-incentive { --cat: var(--gold); --cat-soft: var(--gold-soft); }
        .hero-spot.cat-allowance, .hero-row.cat-allowance { --cat: var(--sky, #0284c7); --cat-soft: var(--sky-soft, #e0f2fe); }
        .hero-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: #fff; color: var(--cat); font-size: 0.66rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
        .hero-status { padding: 3px 9px; border-radius: 999px; background: #fff; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #059669; box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
        .hero-status.status-urgent, .hero-txt-status.status-urgent { color: #dc2626; }
        .hero-status.status-closed, .hero-txt-status.status-closed { color: #94a3b8; }
        .hero-applied { color: #047857; }
        .hero-txt-status { font-weight: 800; color: #059669; background: none !important; border: 0 !important; padding: 0 !important; box-shadow: none !important; animation: none !important; }

        .hero-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; height: 100%; text-align: center; font-size: 0.85rem; font-weight: 700; color: #94a3b8; }
        .hero-empty i { font-size: 1.8rem; color: #cbd5e1; }
        .hero-empty button { padding: 8px 14px; border-radius: 10px; background: #ecfdf5; color: #047857; font-weight: 800; font-size: 0.78rem; }
        .hero-pager { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; border-top: 1px solid #e2e8f0; }
        .hero-nav { width: 34px; height: 34px; flex-shrink: 0; border-radius: 10px; border: 1px solid #e2e8f0; background: #fff; color: #0f172a; font-size: 0.8rem; transition: background 0.15s ease; }
        .hero-nav:hover:not(:disabled) { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .hero-nav:disabled { opacity: 0.35; cursor: default; }
        .hero-pager-mid { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .hero-dots { display: flex; flex-wrap: wrap; justify-content: center; gap: 5px; max-width: 100%; }
        .hero-dots button { width: 7px; height: 7px; padding: 0; border-radius: 999px; background: #cbd5e1; transition: width 0.2s ease, background 0.2s ease; }
        .hero-dots button.active { width: 20px; background: #059669; }
        .hero-page-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; }
        .hero-pager-line { display: flex; align-items: center; gap: 10px; }
        .hero-play { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; border: 1px solid #e2e8f0; background: transparent; color: #64748b; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; cursor: pointer; }
        .hero-play:hover { border-color: var(--hero-mid, #059669); color: var(--hero-mid, #059669); }
        html.dark .hero-play { border-color: var(--line); color: var(--muted); }

        /* ---------- SECTION HEADINGS ---------- */
        section { padding: 36px 0; }
        .eyebrow { display: inline-flex; align-items: center; gap: 8px; color: var(--green); font-size: 0.72rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; }
        .eyebrow::before { content: ''; width: 18px; height: 2px; border-radius: 2px; background: currentColor; }
        .section-title { margin: 6px 0 4px; font-size: clamp(1.4rem, 2.4vw, 1.9rem); font-weight: 800; letter-spacing: -0.03em; line-height: 1.15; color: var(--ink); }
        .section-sub { margin: 0; color: var(--muted); font-size: 0.92rem; }
        .section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
        .section-head.center { justify-content: center; text-align: center; }
        .section-head.center .section-sub { max-width: 560px; margin: 0 auto; }
        .eyebrow.light { color: var(--mint); }

        /* Sections fade up as they scroll into view */
        .reveal { opacity: 0; transform: translateY(18px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .reveal.is-visible { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) { .reveal { opacity: 1; transform: none; transition: none; } .hero-page { animation: none; } }

        /* The filter bar glows while it is narrowing the hero */
        .search-command-center.is-filtering { border-color: #a7f3d0; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12), var(--shadow-md); }
        .search-command-center.is-filtering .reset-btn { color: var(--green); border-color: #a7f3d0; background: var(--green-soft); }

        .status-active { background: rgba(255,255,255,0.92); color: var(--green); }
        .status-urgent { background: #fef2f2; color: #dc2626; box-shadow: 0 0 0 1px #fecaca; }
        .status-closed { background: rgba(241, 242, 244, 0.95); color: #6b7280; }

        /* ---------- BROWSE BY TYPE ---------- */
        .type-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        .type-card { --cat: var(--green); --cat-soft: var(--green-soft);
            position: relative; display: flex; flex-direction: column; align-items: flex-start; gap: 12px; padding: 22px; text-align: left; cursor: pointer;
            border-radius: 20px; border: 1px solid var(--line); background: #fff; box-shadow: var(--shadow-sm); overflow: hidden; font: inherit; color: inherit;
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease; }
        .type-card::after { content: ''; position: absolute; right: -40px; top: -40px; width: 140px; height: 140px; border-radius: 50%; background: var(--cat-soft); transition: transform 0.4s ease; }
        .type-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: color-mix(in srgb, var(--cat) 40%, var(--line)); }
        .type-card:hover::after { transform: scale(1.35); }
        .type-card.cat-incentive { --cat: var(--gold); --cat-soft: var(--gold-soft); }
        .type-card.cat-allowance { --cat: var(--sky); --cat-soft: var(--sky-soft); }
        .type-card > * { position: relative; z-index: 1; }
        .type-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; background: var(--cat-soft); color: var(--cat); font-size: 1.2rem; }
        .type-body strong { display: block; font-size: 1.15rem; font-weight: 800; letter-spacing: -0.02em; color: var(--ink); }
        .type-body span { display: block; margin-top: 4px; font-size: 0.84rem; line-height: 1.5; color: var(--muted); }
        .type-stats { font-size: 0.82rem; font-weight: 700; color: var(--ink-soft); }
        .type-stats b { color: var(--cat); font-weight: 800; }
        .type-go { margin-top: auto; display: inline-flex; align-items: center; gap: 8px; font-size: 0.8rem; font-weight: 800; color: var(--cat); }
        .type-go i { transition: transform 0.2s ease; }
        .type-card:hover .type-go i { transform: translateY(-3px); }

        /* ---------- HOW IT WORKS ---------- */
        .journey { display: grid; grid-template-columns: minmax(0, 0.8fr) minmax(0, 2fr); gap: clamp(20px, 4vw, 48px); align-items: center;
            padding: clamp(24px, 4vw, 44px); border-radius: var(--radius-lg); color: #fff; box-shadow: var(--shadow-md);
            background: radial-gradient(600px 300px at 0% 0%, rgba(167, 243, 208, 0.16), transparent 60%), radial-gradient(500px 260px at 100% 100%, rgba(240, 180, 41, 0.14), transparent 60%), var(--forest); }
        .journey .section-title { color: #fff; }
        .journey .section-sub { color: rgba(255, 255, 255, 0.72); margin-bottom: 20px; }
        .journey-steps { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; counter-reset: none; }
        .journey-steps li { position: relative; padding: 18px 16px; border-radius: 18px; background: linear-gradient(160deg, rgba(255,255,255,0.12), rgba(255,255,255,0.04)); border: 1px solid rgba(255,255,255,0.14); transition: transform 0.25s ease, background 0.25s ease; }
        .journey-steps li:hover { transform: translateY(-4px); background: linear-gradient(160deg, rgba(255,255,255,0.18), rgba(255,255,255,0.06)); }
        .journey-steps li:not(:last-child)::after { content: ''; position: absolute; top: 36px; right: -12px; width: 10px; height: 2px; background: rgba(167, 243, 208, 0.5); }
        .journey-num { position: absolute; top: 14px; right: 14px; font-size: 1.8rem; font-weight: 800; line-height: 1; color: rgba(255,255,255,0.12); }
        .journey-steps li > i { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: rgba(167, 243, 208, 0.16); color: var(--mint); font-size: 1rem; }
        .journey-steps h3 { margin: 14px 0 6px; font-size: 0.95rem; font-weight: 800; color: #fff; line-height: 1.3; }
        .journey-steps p { margin: 0; font-size: 0.8rem; line-height: 1.55; color: rgba(255, 255, 255, 0.7); }

        /* ---------- PARTNERS ---------- */
        .partner-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; }
        .partner-card { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; border: 1px solid var(--line); background: #fff; box-shadow: var(--shadow-sm); min-width: 0; transition: border-color 0.2s ease, transform 0.2s ease; }
        .partner-card:hover { border-color: #a7f3d0; transform: translateY(-2px); }
        .partner-mark { width: 42px; height: 42px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: var(--green-soft); color: var(--green); font-size: 0.8rem; font-weight: 800; letter-spacing: 0.02em; }
        .partner-card.is-tau .partner-mark { background: #fff; border: 1px solid var(--line); }
        .partner-mark img { width: 32px; height: 32px; object-fit: contain; }
        .partner-text { min-width: 0; }
        .partner-text strong { font-size: 0.84rem; font-weight: 800; color: var(--ink); line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .partner-text small { display: block; font-size: 0.7rem; font-weight: 700; color: var(--muted); }

        /* ---------- FAQ ---------- */
        .faq-wrap { display: grid; grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.6fr); gap: clamp(20px, 4vw, 48px); align-items: start; }
        .faq-intro { position: sticky; top: 24px; }
        .faq-intro .section-sub { margin-top: 6px; }
        .faq-list { margin-top: 0; }
        .faq-item { border: 1px solid var(--line); border-radius: 14px; background: #fafcfb; margin-bottom: 10px; transition: background 0.2s, border-color 0.2s; }
        .faq-item.is-open { background: #fff; border-color: #bfdccb; box-shadow: var(--shadow-sm); }
        .faq-item .faq-question { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 15px 18px; background: none; border: none; cursor: pointer; text-align: left; font-weight: 800; font-size: 0.9rem; color: var(--ink); }
        .faq-question i { width: 26px; height: 26px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: var(--green-soft); color: var(--green); flex-shrink: 0; transition: transform 0.25s ease; }
        .faq-item.is-open .faq-question i { transform: rotate(180deg); }
        .faq-answer { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; }
        .faq-answer-inner { padding: 0 18px 16px; color: var(--muted); font-size: 0.86rem; line-height: 1.6; }

        /* ---------- FOOTER ---------- */
        .site-footer { margin-top: 12px; color: rgba(255, 255, 255, 0.72); background: radial-gradient(700px 300px at 90% 0%, rgba(240, 180, 41, 0.14), transparent 60%), linear-gradient(180deg, #0b3d2a, #062417); }
        .footer-cta { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; padding: 30px 0 24px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .footer-cta h2 { margin: 0; color: #fff; font-size: clamp(1.15rem, 2vw, 1.4rem); font-weight: 800; letter-spacing: -0.02em; }
        .footer-cta p { margin: 2px 0 0; font-size: 0.86rem; }
        .footer-bottom { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 16px 0 20px; font-size: 0.78rem; }
        .footer-links { display: flex; flex-wrap: wrap; gap: 18px; font-weight: 700; }
        .footer-links a:hover { color: #fde68a; }

        /* OSSD contact row */
        .footer-contact { display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 28px; padding: 26px 0; border-bottom: 1px solid rgba(255,255,255,0.1); font-size: 0.86rem; }
        .contact-block h3 { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: 0 0 10px; color: #fff; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
        .contact-block h3 > i { color: #fde68a; }
        .contact-block p { margin: 0 0 4px; line-height: 1.5; }
        .contact-strong { color: #fff; font-weight: 800; }
        .contact-muted { font-size: 0.74rem; color: rgba(255,255,255,0.5); margin-top: 6px !important; }
        .contact-link { display: inline-flex; gap: 8px; margin-top: 6px; color: rgba(255,255,255,0.85); line-height: 1.5; }
        .contact-link:hover { color: #fde68a; }
        .contact-link i { margin-top: 3px; color: #fde68a; }
        .hours-row { display: flex; justify-content: space-between; gap: 12px; max-width: 280px; }
        .hours-row b { color: #fff; font-weight: 700; }
        .open-badge { padding: 2px 9px; border-radius: 999px; font-size: 0.66rem; letter-spacing: 0.04em; background: rgba(248, 113, 113, 0.18); color: #fca5a5; }
        .open-badge.is-open { background: rgba(74, 222, 128, 0.18); color: #86efac; }
        .contact-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .contact-btn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.18); color: #fff; font-weight: 700; font-size: 0.8rem; transition: background 0.2s ease; }
        .contact-btn:hover { background: rgba(255,255,255,0.1); }
        .contact-btn.messenger { background: #0866ff; border-color: #0866ff; }
        .contact-btn.messenger:hover { background: #0a58d6; }
        @media (max-width: 900px) { .footer-contact { grid-template-columns: 1fr 1fr; } .footer-contact .contact-block:first-child { grid-column: 1 / -1; } }
        @media (max-width: 600px) { .footer-contact { grid-template-columns: 1fr; text-align: left; } .hours-row { max-width: none; } }

        /* ---------- BREAKPOINTS ---------- */
        @media (max-width: 1100px) {
            .journey { grid-template-columns: minmax(0, 1fr); }
            .search-grid { grid-template-columns: repeat(3, minmax(0, 1fr)) auto; }
            .search-grid .field:first-child { grid-column: 1 / -1; }
        }

        @media (max-width: 900px) {
            .hero-grid { grid-template-columns: minmax(0, 1fr); }
            .hero-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .hero-grid .hero-stats { margin-top: 18px; }
            .stat-tile { flex-direction: column; align-items: flex-start; gap: 8px; padding: 12px; }
            .stat-tile:hover { transform: translateY(-2px); }
            .stat-tile strong { font-size: 1.05rem; }
            .type-grid { grid-template-columns: minmax(0, 1fr); }
            .type-card { display: grid; grid-template-columns: auto minmax(0, 1fr); column-gap: 14px; }
            .type-card .type-icon { grid-row: span 3; }
            .journey-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .journey-steps li::after { display: none; }
            .faq-wrap { grid-template-columns: minmax(0, 1fr); }
            .faq-intro { position: static; }
        }

        @media (max-width: 768px) {
            .nav-links { display: none; position: absolute; top: 100%; left: calc(var(--page-gutter) * -1); right: calc(var(--page-gutter) * -1); background: #fff; flex-direction: column; align-items: stretch; gap: 0; padding: 12px 20px 20px; box-shadow: 0 16px 30px rgba(0,0,0,0.1); border-top: 1px solid var(--line); }
            .nav-links.mobile-open { display: flex; }
            .nav-links a:not(.login-link) { border-radius: 10px; padding: 12px 10px; }
            .nav-links .login-link { margin: 10px 0 0; }
            .hamburger { display: block; }
            .search-grid { grid-template-columns: 1fr 1fr; }
            .search-grid .reset-btn { width: 100%; }
            .hero { border-radius: 20px; }
            .hero-copy { font-size: 0.92rem; }
            .footer-cta, .footer-bottom { flex-direction: column; text-align: center; }
            .footer-cta .btn-gold { width: 100%; }
        }

        @media (max-width: 600px) {
            .hero-stats { gap: 8px; }
            .stat-tile { padding: 10px; border-radius: 12px; min-width: 0; }
            .stat-icon { display: none; }
            .stat-tile strong { font-size: 0.92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .stat-tile > div > span { font-size: 0.56rem; letter-spacing: 0.05em; }
            .hero-actions a { flex: 1 1 auto; }
            /* Phones: get to the scholarships quickly */
            .hero-grid .hero-copy, .hero-grid .hero-stats { display: none; }
            .hero-grid { gap: 20px; }
            .hero-panel-head { padding: 12px 14px; font-size: 0.7rem; }
            .hero-panel-head > span:first-child { white-space: nowrap; }
            /* the hero card sizes itself instead of being squeezed into a fixed height */
            .hero-pages { height: auto; min-height: 280px; }
            .hero-page, .hero-spot, .hero-rows { height: auto; }
            .hero-rows { grid-template-rows: none !important; }
            .hero-spot-media { height: 140px; }
            .hero-spot-foot { flex-wrap: wrap; }
            .hero-spot-award span { display: block; margin: 2px 0 0; }
            .hero-panel-head { flex-wrap: wrap; row-gap: 8px; }
            .hero-row-thumb { width: 52px; }
            .hero-row-main p { display: none; }

            .journey-steps { grid-template-columns: minmax(0, 1fr); gap: 10px; }
            .journey-steps li { display: grid; grid-template-columns: auto minmax(0, 1fr); column-gap: 14px; padding: 14px; }
            .journey-steps li > i { grid-row: span 2; }
            .journey-steps h3 { margin: 0 0 2px; padding-right: 22px; }
            .journey-num { font-size: 1.3rem; }
            .type-card { padding: 18px; }
            .partner-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
            .partner-card { flex-direction: column; align-items: flex-start; gap: 8px; padding: 12px; }
            .partner-mark { width: 36px; height: 36px; }
        }

        /* ---------- PERSONAL MATCHES (signed-in students) ---------- */
        .match-banner { display: flex; align-items: center; gap: 12px; margin-top: 12px; padding: 10px 12px; border-radius: 14px; background: var(--green-soft); color: var(--ink); font-size: 0.86rem; font-weight: 600; }
        .match-banner.is-off { background: transparent; border: 1px dashed var(--line); }
        .match-icon { width: 32px; height: 32px; flex-shrink: 0; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: var(--green); color: #fff; font-size: 0.8rem; }
        .match-banner.is-off .match-icon { background: var(--muted); }
        .match-text { flex: 1; min-width: 0; line-height: 1.45; }
        .match-text b { color: var(--green); }
        .match-btn { flex-shrink: 0; padding: 8px 14px; border-radius: 10px; border: 1px solid var(--line); background: #fff; color: var(--ink); font: inherit; font-size: 0.78rem; font-weight: 800; cursor: pointer; white-space: nowrap; }
        .match-btn:hover { border-color: var(--green); color: var(--green); }
        html.dark .match-btn { background: #101c16; }
        @media (max-width: 600px) { .match-banner { flex-wrap: wrap; } .match-btn { width: 100%; } }

        /* ---------- LIGHT / DARK SWITCH (includes/theme.php) ---------- */
        .theme-pill { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 999px; border: 1px solid var(--line); background: transparent;
            color: var(--ink-soft); font: inherit; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: background 0.2s, color 0.2s; }
        .theme-pill:hover { background: var(--green-soft); color: var(--green); }

        /* ---------- DARK MODE: the page's own colours ---------- */
        html.dark {
            --ink: #e8f1ec; --ink-soft: #b9cbc1; --muted: #8ba398; --line: #22332b; --wash: #08110d;
            --green-soft: rgba(25, 135, 84, 0.2); --gold-soft: rgba(201, 151, 28, 0.2); --sky-soft: rgba(37, 99, 235, 0.22);
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.4), 0 4px 14px rgba(0, 0, 0, 0.3); --shadow-md: 0 2px 6px rgba(0, 0, 0, 0.4), 0 18px 40px rgba(0, 0, 0, 0.45);
        }
        html.dark body { background: radial-gradient(900px 500px at 100% 0%, rgba(52, 211, 153, 0.08), transparent 60%), var(--wash); color: var(--ink); }
        html.dark .site-header { background: rgba(8, 17, 13, 0.85); border-bottom-color: var(--line); }
        html.dark .brand strong, html.dark .section-title, html.dark .type-body strong, html.dark .partner-text strong { color: var(--ink); }
        html.dark .search-command-center, html.dark .reset-btn, html.dark .type-card, html.dark .partner-card,
        html.dark .faq-item.is-open, html.dark .hero-panel, html.dark .hero-spot, html.dark .hero-row, html.dark .hero-nav { background: #101c16; color: var(--ink); border-color: var(--line); }
        html.dark .faq-item { background: #0d1813; border-color: var(--line); }
        html.dark .field-input, html.dark .hero-per-page select { background-color: #0d1813; color: var(--ink); border-color: var(--line); }
        html.dark .field-input:hover, html.dark .field-input:focus { background-color: #13231b; }
        html.dark .hero-panel-head, html.dark .hero-pager { border-color: var(--line); }
        html.dark .hero-row-main strong, html.dark .hero-spot h3 { color: var(--ink); }
        html.dark .hero-spot p, html.dark .hero-row-main p, html.dark .hero-row-main small, html.dark .hero-spot-meta { color: var(--muted); }
        html.dark .hero-spot-chips span { background: #1a2a22; color: var(--ink-soft); }
        html.dark .hero-chip, html.dark .hero-status { background: #101c16; }
        html.dark .partner-card.is-tau .partner-mark { background: #e8f1ec; }
        html.dark .nav-links { background: transparent; }
        @media (max-width: 768px) { html.dark .nav-links { background: #0d1813; } }

        /* ==========================================================
           LIVING BACKGROUND: drifting colour glows, a dot pattern and floating icons behind the page
           ========================================================== */
        body { background: var(--wash); }
        .page-bg { position: fixed; inset: 0; z-index: -1; overflow: hidden; pointer-events: none; }
        .page-bg .glow { position: absolute; border-radius: 50%; will-change: transform; animation: glow-drift 30s ease-in-out infinite alternate; }
        .page-bg .g1 { width: 70vmax; height: 70vmax; top: -30vmax; right: -22vmax; background: radial-gradient(circle, rgba(52, 211, 153, 0.34), rgba(52, 211, 153, 0) 62%); }
        .page-bg .g2 { width: 60vmax; height: 60vmax; top: 22vh; left: -28vmax; background: radial-gradient(circle, rgba(245, 197, 66, 0.24), rgba(245, 197, 66, 0) 62%); animation-duration: 36s; animation-delay: -9s; }
        .page-bg .g3 { width: 64vmax; height: 64vmax; bottom: -34vmax; right: -6vmax; background: radial-gradient(circle, rgba(96, 165, 250, 0.20), rgba(96, 165, 250, 0) 62%); animation-duration: 42s; animation-delay: -18s; }
        .page-bg .g4 { width: 40vmax; height: 40vmax; top: 48vh; left: 38vw; background: radial-gradient(circle, rgba(16, 185, 129, 0.16), rgba(16, 185, 129, 0) 62%); animation-duration: 26s; animation-delay: -4s; }
        @keyframes glow-drift {
            0%   { transform: translate3d(0, 0, 0) scale(1); }
            50%  { transform: translate3d(5vmax, 4vmax, 0) scale(1.1); }
            100% { transform: translate3d(-4vmax, 7vmax, 0) scale(0.94); }
        }
        .page-bg .dots { position: absolute; inset: 0; background-image: radial-gradient(rgba(13, 43, 30, 0.10) 1px, transparent 1.2px); background-size: 22px 22px;
            -webkit-mask-image: radial-gradient(ellipse 85% 55% at 50% 0%, #000 25%, transparent 75%); mask-image: radial-gradient(ellipse 85% 55% at 50% 0%, #000 25%, transparent 75%); }
        .page-bg .float { position: absolute; color: var(--green); opacity: 0.08; font-size: clamp(26px, 3.6vw, 54px); animation: float-bob 10s ease-in-out infinite; }
        .page-bg .float.gold { color: var(--gold); opacity: 0.10; }
        .page-bg .float.sky { color: var(--sky); opacity: 0.07; }
        @keyframes float-bob { 50% { transform: translateY(-20px) rotate(10deg); } }

        /* Thin progress line under the header while scrolling */
        .scroll-progress { position: absolute; left: 0; bottom: -1px; height: 3px; width: 100%; transform-origin: left; transform: scaleX(var(--progress, 0));
            background: linear-gradient(90deg, #10b981, #34d399 50%, #f0b429); border-radius: 0 3px 3px 0; }

        /* Frosted cards: the colours behind show through */
        .search-command-center, .type-card, .partner-card, .faq-item {
            background: rgba(255, 255, 255, 0.72); border-color: rgba(225, 236, 229, 0.9);
            -webkit-backdrop-filter: blur(14px) saturate(160%); backdrop-filter: blur(14px) saturate(160%); }
        .faq-item.is-open { background: rgba(255, 255, 255, 0.92); }
        .type-card:hover { box-shadow: 0 0 0 1px color-mix(in srgb, var(--cat) 35%, transparent), 0 22px 44px color-mix(in srgb, var(--cat) 18%, transparent); }
        .partner-card:hover { box-shadow: 0 14px 30px rgba(16, 185, 129, 0.14); }

        /* Section titles in a soft green gradient (the dark "How it works" panel keeps white) */
        .section-title { background: linear-gradient(100deg, var(--ink) 30%, #157347); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .journey .section-title { background: none; color: #fff; }
        .eyebrow { padding: 4px 10px 4px 8px; border-radius: 999px; background: rgba(25, 135, 84, 0.08); }
        .eyebrow.light { background: rgba(167, 243, 208, 0.12); }

        /* A tinted band behind the partners, so the page has rhythm */
        #partners { position: relative; padding: 56px 0; margin: 18px 0; }
        #partners::before { content: ''; position: absolute; inset: 0; z-index: -1;
            background: radial-gradient(600px 220px at 15% 0%, rgba(52, 211, 153, 0.18), transparent 70%), radial-gradient(520px 220px at 85% 100%, rgba(245, 197, 66, 0.16), transparent 70%),
                        linear-gradient(180deg, rgba(232, 245, 238, 0), rgba(232, 245, 238, 0.85) 18%, rgba(232, 245, 238, 0.85) 82%, rgba(232, 245, 238, 0)); }

        /* Cards arrive one after another when their section scrolls in */
        @keyframes rise-in { from { opacity: 0; transform: translateY(16px) scale(0.98); } }
        .reveal.is-visible .type-card, .reveal.is-visible .journey-steps li, .reveal.is-visible .partner-card, .reveal.is-visible .faq-item {
            animation: rise-in 0.55s cubic-bezier(0.22, 1, 0.36, 1) backwards; animation-delay: calc(var(--i, 0) * 70ms + 80ms); }

        /* Dark mode: deeper glows, light dots */
        html.dark .page-bg .g1 { background: radial-gradient(circle, rgba(16, 185, 129, 0.20), rgba(16, 185, 129, 0) 62%); }
        html.dark .page-bg .g2 { background: radial-gradient(circle, rgba(201, 151, 28, 0.14), rgba(201, 151, 28, 0) 62%); }
        html.dark .page-bg .g3 { background: radial-gradient(circle, rgba(37, 99, 235, 0.14), rgba(37, 99, 235, 0) 62%); }
        html.dark .page-bg .dots { background-image: radial-gradient(rgba(232, 241, 236, 0.07) 1px, transparent 1.2px); }
        html.dark .page-bg .float { opacity: 0.06; }
        html.dark body { background: var(--wash); }
        html.dark .search-command-center, html.dark .type-card, html.dark .partner-card, html.dark .faq-item { background: rgba(16, 28, 22, 0.72); border-color: var(--line); }
        html.dark .faq-item.is-open { background: rgba(16, 28, 22, 0.92); }
        html.dark .section-title { background: linear-gradient(100deg, var(--ink) 30%, #6ee7b7); -webkit-background-clip: text; background-clip: text; color: transparent; }
        html.dark .journey .section-title { background: none; color: #fff; }
        html.dark #partners::before { background: radial-gradient(600px 220px at 15% 0%, rgba(16, 185, 129, 0.12), transparent 70%), linear-gradient(180deg, rgba(16, 28, 22, 0), rgba(16, 28, 22, 0.7) 18%, rgba(16, 28, 22, 0.7) 82%, rgba(16, 28, 22, 0)); }

        @media (prefers-reduced-motion: reduce) {
            .page-bg .glow, .page-bg .float { animation: none; }
            .reveal.is-visible .type-card, .reveal.is-visible .journey-steps li, .reveal.is-visible .partner-card, .reveal.is-visible .faq-item { animation: none; }
        }
        @media (max-width: 768px) { .page-bg .float { display: none; } }
    </style>
</head>
<body>
    <!-- Decorative background (see LIVING BACKGROUND in the styles) -->
    <div class="page-bg" aria-hidden="true">
        <span class="glow g1"></span><span class="glow g2"></span><span class="glow g3"></span><span class="glow g4"></span>
        <span class="dots"></span>
        <i class="float fas fa-graduation-cap" style="top: 16%; left: 4%;"></i>
        <i class="float gold fas fa-star" style="top: 38%; right: 3%; animation-delay: -3s;"></i>
        <i class="float sky fas fa-book-open" style="top: 62%; left: 2.5%; animation-delay: -6s;"></i>
        <i class="float gold fas fa-award" style="top: 80%; right: 4%; animation-delay: -1.5s;"></i>
        <i class="float fas fa-seedling" style="top: 92%; left: 46%; animation-delay: -7s;"></i>
    </div>
    <?php
        // ✨ ANNOUNCEMENT BAR: built from the active term and the open scholarships (or the admins' own text, landing_content "announcement")
        $announcement = trim((string) ($cms['announcement']['body'] ?? ''));
        if ($announcement === '') {
            try { $term_name = (string) $pdo->query("SELECT TermName FROM academic_terms WHERE Status = 'Active' ORDER BY TermID DESC LIMIT 1")->fetchColumn(); } catch (PDOException $e) { $term_name = ''; }
            $open_now = array_values(array_filter($all_scholarships, fn($s) => strtolower($s['Status'] ?? '') === 'active' && !empty($s['Deadline']) && strtotime($s['Deadline']) >= strtotime('today')));
            usort($open_now, fn($a, $b) => strcmp($a['Deadline'], $b['Deadline']));
            if ($open_now) {
                $soonest = $open_now[0];
                $days = (int) floor((strtotime($soonest['Deadline']) - strtotime('today')) / 86400);
                $deadline_note = $days <= 7
                    ? $soonest['Name'] . ' closes ' . ($days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in $days days"))
                    : 'Next deadline: ' . date('M j, Y', strtotime($soonest['Deadline']));
                $announcement = 'Applications' . ($term_name ? " for $term_name" : '') . ' are open · ' . count($open_now)
                    . ' scholarship' . (count($open_now) > 1 ? 's' : '') . ' accepting applications · ' . $deadline_note;
            } else {
                $announcement = 'No scholarships are open right now · New grants' . ($term_name ? " for $term_name" : '') . ' will be announced here';
            }
        }
    ?>
    <div class="announce" role="status">
        <span class="dot"></span>
        <?= htmlspecialchars($announcement) ?>
    </div>

    <header class="site-header">
        <nav class="nav-shell page-inner">
            <a class="brand" href="index.php">
                <img src="assets/img/tau_logo.png" alt="TAU Logo">
                <span>
                    <strong>ScholarLink</strong>
                    <small>Tarlac Agricultural University</small>
                </span>
            </a>

            <div class="nav-links" id="navLinks">
                <a href="#grants" onclick="document.getElementById('navLinks').classList.remove('mobile-open')">Grants</a>
                <a href="#how-it-works" onclick="document.getElementById('navLinks').classList.remove('mobile-open')">How It Works</a>
                <a href="#faq" onclick="document.getElementById('navLinks').classList.remove('mobile-open')">FAQ</a>
                <?php themeToggle('pill'); ?>
                <?php if ($is_logged_in): ?>
                    <a class="login-link" href="<?= $role_redirect ?>">Dashboard <i class="fas fa-arrow-right"></i></a>
                <?php else: ?>
                    <a href="student_login.php">Sign In</a>
                    <a class="login-link" href="student_login.php">Apply Now</a>
                <?php endif; ?>
            </div>

            <button class="hamburger" onclick="document.getElementById('navLinks').classList.toggle('mobile-open')" aria-label="Menu">
                <i class="fas fa-bars"></i>
            </button>
        </nav>
        <span class="scroll-progress" id="scrollProgress" aria-hidden="true"></span>
    </header>

    <main>
        <!-- Smart Match Filter Bar (top of the page) -->
        <div class="search-command-center page-inner" id="searchCommand">
            <div class="search-grid">
                <div class="field">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" id="searchInput" class="field-input" placeholder="Search grants..." oninput="changeSearchFilter()">
                </div>
                <div class="field">
                    <i class="fas fa-layer-group"></i>
                    <select id="matchCategory" class="field-input" onchange="runSmartMatch()" aria-label="Category">
                        <option value="all">All Categories</option>
                        <?php foreach (array_keys(SCHOLARSHIP_CATEGORIES) as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <i class="fas fa-graduation-cap"></i>
                    <select id="matchProgram" class="field-input" onchange="runSmartMatch()" aria-label="Program">
                        <option value="all">All Programs</option>
                        <?php foreach ($all_programs as $prog_id => $prog): ?>
                            <option value="<?= (int) $prog_id ?>"><?= htmlspecialchars($prog) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <i class="fas fa-calendar-check"></i>
                    <select id="matchYear" class="field-input" onchange="runSmartMatch()" aria-label="Year level">
                        <option value="all">All Years</option>
                        <option value="1st Year">1st Year</option>
                        <option value="2nd Year">2nd Year</option>
                        <option value="3rd Year">3rd Year</option>
                        <option value="4th Year">4th Year</option>
                    </select>
                </div>
                <div class="field gold">
                    <i class="fas fa-star"></i>
                    <input type="number" step="0.01" min="1.00" max="5.00" id="matchGwa" class="field-input" placeholder="GWA" onblur="validateGwa(this)" oninput="runSmartMatch()" aria-label="Your GWA">
                </div>
                <div class="field">
                    <i class="fas fa-book-open"></i>
                    <input type="number" min="1" max="35" id="matchUnits" class="field-input" placeholder="Units" oninput="runSmartMatch()" aria-label="Units enrolled">
                </div>
                <button class="reset-btn" onclick="clearSmartMatch()" title="Reset filters" aria-label="Reset filters">
                    <i class="fas fa-rotate-left"></i>
                </button>
            </div>
            <?php if ($my_profile): ?>
                <!-- Signed-in student: the filters start from their profile -->
                <div class="match-banner" id="matchBanner" role="status">
                    <span class="match-icon"><i class="fas fa-wand-magic-sparkles"></i></span>
                    <span class="match-text" id="matchText"></span>
                    <button type="button" class="match-btn" id="matchToggle" onclick="toggleMyMatches()"></button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Hero (inset banner) -->
        <div class="page-inner" id="grants">
            <div class="hero" id="interactiveHero">
                <div class="hero-slideshow">
                    <?php foreach ($heroSlides as $slideIndex => $slideImage): ?>
                        <div class="hero-slide <?= $slideIndex === 0 ? 'is-active' : '' ?>" style="background-image: url('<?= htmlspecialchars($slideImage) ?>');"></div>
                    <?php endforeach; ?>
                </div>
                <div class="hero-overlay"></div>

                <div class="hero-grid">
                    <div>
                        <span class="hero-eyebrow"><i class="fas fa-certificate"></i> Official Scholarship Portal</span>
                        <h1>Fund your <span class="text-gradient">education.</span></h1>
                        <p class="hero-copy"><?= htmlspecialchars($hero_body) ?></p>

                        <div class="hero-actions">
                            <a class="btn-primary" href="#searchCommand" onclick="event.preventDefault(); const el = document.getElementById('searchInput'); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.focus({ preventScroll: true });"><i class="fas fa-wand-magic-sparkles"></i> Find My Match</a>
                            <?php if (!$is_logged_in): ?>
                                <a class="btn-ghost" href="student_login.php"><i class="fas fa-user-plus"></i> Create Account</a>
                            <?php endif; ?>
                        </div>

                        <!-- Live figures from the scholarship table -->
                        <div class="hero-stats">
                            <div class="stat-tile">
                                <span class="stat-icon green"><i class="fas fa-graduation-cap"></i></span>
                                <div>
                                    <strong data-count="<?= (int) $active_scholarship_count ?>"><?= number_format($active_scholarship_count) ?></strong>
                                    <span>Open Grants</span>
                                </div>
                            </div>
                            <div class="stat-tile">
                                <span class="stat-icon gold"><i class="fas fa-peso-sign"></i></span>
                                <div>
                                    <strong data-count="<?= (int) $total_active_award_value ?>" data-prefix="₱">₱<?= number_format($total_active_award_value) ?></strong>
                                    <span>Combined Awards</span>
                                </div>
                            </div>
                            <div class="stat-tile">
                                <span class="stat-icon sky"><i class="fas fa-calendar-day"></i></span>
                                <div>
                                    <strong><?= htmlspecialchars($nearest_deadline_label) ?></strong>
                                    <span>Next Deadline</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- The hero pages through the scholarships (filtered by the search bar above);
                         the visitor picks how many show per page. Rendered by heroRender() from $hero_scholarships. -->
                    <?php
                        $category_icons = ['Scholarship' => 'fa-graduation-cap', 'Incentive' => 'fa-trophy', 'Allowance' => 'fa-wallet'];
                        $hero_scholarships = [];
                        foreach ($all_scholarships as $sch) {
                            $heroCategory = $sch['Category'] ?? DEFAULT_SCHOLARSHIP_CATEGORY;
                            $heroStatus = scholarship_status_meta($sch['Status'] ?? 'Active', $sch['Deadline'] ?? null);
                            $hero_scholarships[] = [
                                'id' => (int) $sch['ScholarshipID'],
                                'name' => $sch['Name'],
                                'desc' => (string) ($sch['Description'] ?? ''),
                                'category' => $heroCategory,
                                'icon' => $category_icons[$heroCategory] ?? 'fa-tag',
                                'status' => $heroStatus['label'],
                                'statusClass' => $heroStatus['class'],
                                'award' => '₱' . number_format((float) ($sch['AwardAmount'] ?? 0)),
                                'freq' => (string) ($sch['ReleaseFrequency'] ?? ''),
                                'gwa' => (string) ($sch['MinimumGWA'] ?? ''),
                                'year' => !empty($sch['YearLevel']) ? $sch['YearLevel'] : 'All Years',
                                'program' => !empty($eligible_programs[$sch['ScholarshipID']]) ? (count($eligible_programs[$sch['ScholarshipID']]) <= 2 ? implode(', ', $eligible_programs[$sch['ScholarshipID']]) : count($eligible_programs[$sch['ScholarshipID']]) . ' eligible programs') : 'Open to All Courses',
                                'deadline' => !empty($sch['Deadline']) ? date('M j, Y', strtotime($sch['Deadline'])) : '',
                                'image' => getProgramImage($sch['ProgramName'] ?? '', $sch['Name'] ?? '', $sch['YearLevel'] ?? ''),
                                'open' => strtolower($sch['Status'] ?? 'Active') === 'active',
                                'applied' => isset($applied_applications[$sch['ScholarshipID']]) ? (int) $applied_applications[$sch['ScholarshipID']] : null,
                                // For the filter bar
                                'search' => strtolower($sch['Name'] ?? ''),
                                'programIds' => array_keys($eligible_programs[$sch['ScholarshipID']] ?? []),   // empty = all programs
                                'yearKey' => (string) ($sch['YearLevel'] ?? ''),
                                'gwaNum' => is_numeric($sch['MinimumGWA'] ?? null) ? (float) $sch['MinimumGWA'] : 5.0,
                                'minUnits' => stripos($sch['Name'] ?? '', 'academic scholars - partial') !== false ? 12 : (stripos($sch['Name'] ?? '', 'academic scholars - full') !== false ? 15 : 0),
                            ];
                        }
                    ?>
                    <div class="hero-panel" id="heroPanel" onmouseenter="heroPause(true)" onmouseleave="heroPause(false)">
                        <div class="hero-panel-head">
                            <span><i class="fas fa-award"></i> Available Scholarships <span class="hero-count"><span id="heroCount"><?= count($all_scholarships) ?></span></span></span>
                            <label class="hero-per-page">
                                Per page
                                <select id="heroPerPage" onchange="heroSetPerPage(this.value)" aria-label="Scholarships per hero page">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                    <option value="6">6</option>
                                </select>
                            </label>
                        </div>
                        <div class="hero-pages" id="heroPages" aria-live="polite">
                            <?php if (empty($all_scholarships)): ?><p class="hero-empty"><i class="fas fa-seedling"></i> <?= htmlspecialchars($no_grants_title) ?></p><?php endif; ?>
                        </div>
                        <div class="hero-pager">
                            <button type="button" class="hero-nav" onclick="heroGo(-1, true)" aria-label="Previous scholarships"><i class="fas fa-chevron-left"></i></button>
                            <div class="hero-pager-mid">
                                <div class="hero-dots" id="heroDots"></div>
                                <div class="hero-pager-line">
                                    <span id="heroPageLabel" class="hero-page-label"></span>
                                    <!-- Pause / play the automatic paging (WCAG 2.2.2) -->
                                    <button type="button" class="hero-play" id="heroPlay" onclick="heroToggleAuto()" aria-pressed="false"></button>
                                </div>
                            </div>
                            <button type="button" class="hero-nav" onclick="heroGo(1, true)" aria-label="Next scholarships"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Browse by type: one click filters the hero to that category -->
        <section id="categories" class="reveal">
            <div class="page-inner">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Browse by type</span>
                        <h2 class="section-title"><?= htmlspecialchars($grants_title) ?></h2>
                        <p class="section-sub"><?= htmlspecialchars($grants_body) ?></p>
                    </div>
                </div>
                <?php
                    $category_blurbs = [
                        'Scholarship' => 'Financial support for your studies based on academic merit or financial need.',
                        'Incentive'   => 'Rewards for student leaders, publication staff and other university service roles.',
                        'Allowance'   => 'Support for athletes, cultural performers, ROTC cadets and other active participants.',
                    ];
                    $category_icons = ['Scholarship' => 'fa-graduation-cap', 'Incentive' => 'fa-trophy', 'Allowance' => 'fa-wallet'];
                ?>
                <div class="type-grid">
                    <?php foreach ($category_stats as $cat => $st): ?>
                        <button type="button" class="type-card cat-<?= htmlspecialchars(strtolower($cat)) ?>" onclick="filterCategory('<?= htmlspecialchars($cat, ENT_QUOTES) ?>')">
                            <span class="type-icon"><i class="fas <?= $category_icons[$cat] ?? 'fa-tag' ?>"></i></span>
                            <span class="type-body">
                                <strong><?= htmlspecialchars($cat) ?>s</strong>
                                <span><?= htmlspecialchars($category_blurbs[$cat] ?? '') ?></span>
                            </span>
                            <span class="type-stats">
                                <b><?= $st['open'] ?></b> open<?= $st['top'] > 0 ? ' · up to <b>₱' . number_format($st['top']) . '</b>' : '' ?>
                            </span>
                            <span class="type-go">Show <?= htmlspecialchars(strtolower($cat)) ?>s <i class="fas fa-arrow-up"></i></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- How it works: the student's journey -->
        <section id="how-it-works" class="reveal">
            <div class="page-inner">
                <div class="journey">
                    <div class="journey-intro">
                        <span class="eyebrow light">Getting started</span>
                        <h2 class="section-title">How ScholarLink Works</h2>
                        <p class="section-sub">From sign-up to your approval notice, everything happens in one place.</p>
                        <?php if (!$is_logged_in): ?>
                            <a class="btn-primary" href="student_login.php"><i class="fas fa-user-plus"></i> Create your account</a>
                        <?php endif; ?>
                    </div>
                    <ol class="journey-steps">
                        <li>
                            <span class="journey-num">1</span>
                            <i class="fas fa-id-card"></i>
                            <h3>Register with your TAU ID</h3>
                            <p>Scan your student ID: your name, student number and program are read for you.</p>
                        </li>
                        <li>
                            <span class="journey-num">2</span>
                            <i class="fas fa-folder-open"></i>
                            <h3>Build your Vault</h3>
                            <p>Upload your grades and certificates once and reuse them for every grant.</p>
                        </li>
                        <li>
                            <span class="journey-num">3</span>
                            <i class="fas fa-bolt"></i>
                            <h3>Apply in minutes</h3>
                            <p>Fill in each scholarship's form; your profile answers most of it for you.</p>
                        </li>
                        <li>
                            <span class="journey-num">4</span>
                            <i class="fas fa-award"></i>
                            <h3>Track &amp; get approved</h3>
                            <p>Follow every step live and receive your Approval Notice by email.</p>
                        </li>
                    </ol>
                </div>
            </div>
        </section>

        <?php if ($partners): ?>
        <!-- Scholarship partners -->
        <section id="partners" class="reveal">
            <div class="page-inner">
                <div class="section-head center">
                    <div>
                        <span class="eyebrow">Our partners</span>
                        <h2 class="section-title">Funded by people who believe in TAU students</h2>
                        <p class="section-sub">Government agencies, foundations and companies working with the OSSD to support your education.</p>
                    </div>
                </div>
                <div class="partner-grid">
                    <?php foreach ($partners as $p):
                        $initials = implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(array_values(array_filter(preg_split('/[\s\-–]+/u', preg_replace('/[^\p{L}\s\-–]/u', '', $p['name'])), fn($w) => mb_strlen($w) > 2 || ctype_upper($w))), 0, 2))); ?>
                        <div class="partner-card <?= $p['internal'] ? 'is-tau' : '' ?>" title="<?= htmlspecialchars($p['name']) ?>">
                            <span class="partner-mark"><?= $p['internal'] ? '<img src="assets/img/tau_logo.png" alt="">' : htmlspecialchars(mb_strtoupper($initials ?: mb_substr($p['name'], 0, 1))) ?></span>
                            <span class="partner-text">
                                <strong><?= htmlspecialchars($p['name']) ?></strong>
                                <small><?= $p['count'] ?> grant<?= $p['count'] > 1 ? 's' : '' ?><?= $p['internal'] ? ' · OSSD' : '' ?></small>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- FAQ -->
        <section id="faq" class="reveal">
            <div class="page-inner faq-wrap">
                <div class="faq-intro">
                    <span class="eyebrow">Need help?</span>
                    <h2 class="section-title">Frequently Asked Questions</h2>
                    <p class="section-sub">Still unsure? Ask <b>Isko</b>, our AI scholarship mentor, at the bottom right of the page, or visit the OSSD.</p>
                </div>
                <div class="faq-list">
                    <?php
                        $faqs = [
                            ['Who is eligible to apply?', 'Any enrolled TAU student who meets the GWA, program and year-level criteria of a grant can apply. Use the filters above to see the grants you qualify for.'],
                            ['What is the difference between a scholarship, incentive and allowance?', 'Scholarships support your studies based on merit or financial need. Incentives reward roles such as student leadership or publication work. Allowances support active participation, such as athletes, cultural performers and ROTC cadets.'],
                            ['How does the Document Vault work?', 'Upload your official documents once in your student portal. When you apply to other grants, your verified files are attached automatically, so you never upload the same thing twice.'],
                            ['How will I know if I am approved?', 'You can track each application live in your dashboard. Once approved, you receive an Approval Notice by email and in the portal, listing anything you still need to submit and the deadline.'],
                            ['Who approves my application?', 'University-funded grants are approved by the Office of Student Services and Development (OSSD). Grants funded by an outside sponsor are approved by that sponsor.'],
                            ['I shifted to another program. What do I do?', 'Update your program from your profile by scanning your new ID or uploading a photo of it, so you are matched with the right grants.'],
                        ];
                    ?>
                    <?php foreach ($faqs as [$q, $a]): ?>
                        <div class="faq-item">
                            <button type="button" class="faq-question" onclick="toggleFaq(this)"><?= htmlspecialchars($q) ?> <i class="fas fa-chevron-down text-xs"></i></button>
                            <div class="faq-answer"><div class="faq-answer-inner"><?= htmlspecialchars($a) ?></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="site-footer">
        <div class="page-inner">
            <div class="footer-cta">
                <div>
                    <h2>Ready to secure your future?</h2>
                    <p>Join ScholarLink and manage all your scholarship requirements in one place.</p>
                </div>
                <a class="btn-gold" href="<?= $is_logged_in ? $role_redirect : 'student_login.php' ?>">
                    <?= $is_logged_in ? 'Go to Dashboard' : 'Create Free Account' ?> <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <!-- OSSD contact details (includes/ossd_contact.php) -->
            <?php
                require_once 'includes/ossd_contact.php';
                $ph_now = new DateTime('now', new DateTimeZone('Asia/Manila'));
                $today_hours = OSSD_HOURS[(int) $ph_now->format('N')] ?? null;
                $office_open = $today_hours && $ph_now->format('H:i') >= $today_hours[0] && $ph_now->format('H:i') < $today_hours[1];
            ?>
            <div class="footer-contact" id="contact">
                <div class="contact-block">
                    <h3><i class="fas fa-building-columns"></i> Office</h3>
                    <p class="contact-strong"><?= htmlspecialchars(OSSD_OFFICE) ?></p>
                    <p><?= htmlspecialchars(OSSD_UNIT) ?></p>
                    <a class="contact-link" href="<?= htmlspecialchars(OSSD_MAP_URL) ?>" target="_blank" rel="noopener"><i class="fas fa-location-dot"></i> <?= htmlspecialchars(OSSD_ADDRESS) ?></a>
                </div>
                <div class="contact-block">
                    <h3><i class="fas fa-clock"></i> Office hours
                        <span class="open-badge <?= $office_open ? 'is-open' : '' ?>" id="officeStatus"><?= $office_open ? 'Open now' : 'Closed now' ?></span>
                    </h3>
                    <p class="hours-row"><span>Monday – Friday</span><b>8:00 AM – 5:00 PM</b></p>
                    <p class="hours-row"><span>Saturday – Sunday</span><b>Closed</b></p>
                    <p class="contact-muted">Philippine time · closed on holidays</p>
                </div>
                <div class="contact-block">
                    <h3><i class="fas fa-comments"></i> Get in touch</h3>
                    <p>Questions about scholarships, requirements or your application?</p>
                    <div class="contact-actions">
                        <a class="contact-btn messenger" href="<?= htmlspecialchars(OSSD_MESSENGER) ?>" target="_blank" rel="noopener"><i class="fab fa-facebook-messenger"></i> Message us</a>
                        <a class="contact-btn" href="<?= htmlspecialchars(OSSD_FACEBOOK) ?>" target="_blank" rel="noopener"><i class="fab fa-facebook"></i> Facebook page</a>
                    </div>
                </div>
            </div>

            <div class="footer-bottom">
                <div class="flex items-center gap-3">
                    <img src="assets/img/tau_logo.png" alt="TAU Logo" class="h-8 w-8">
                    <span>Tarlac Agricultural University &copy; <?= date('Y') ?> · <span class="font-bold text-yellow-400 uppercase tracking-widest">ScholarLink</span></span>
                </div>
                <nav class="footer-links">
                    <a href="#grants">Grants</a>
                    <a href="#how-it-works">How It Works</a>
                    <a href="#faq">FAQ</a>
                    <a href="#contact">Contact</a>
                    <a href="privacy.php">Data Privacy Notice</a>
                    <a href="student_login.php"><?= $is_logged_in ? 'Portal' : 'Sign In' ?></a>
                </nav>
            </div>
        </div>
    </footer>

    <script>
        // Office hours badge stays correct while the page is open (Philippine time, whatever the visitor's clock)
        (function () {
            const HOURS = <?= json_encode(OSSD_HOURS) ?>;
            const badge = document.getElementById('officeStatus');
            if (!badge) return;
            function refresh() {
                const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
                    .formatToParts(new Date()).map(p => [p.type, p.value]));
                const day = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 }[parts.weekday];
                const now = parts.hour + ':' + parts.minute, today = HOURS[day];
                const open = !!today && now >= today[0] && now < today[1];
                badge.textContent = open ? 'Open now' : 'Closed now';
                badge.classList.toggle('is-open', open);
            }
            refresh();
            setInterval(refresh, 60000);
        })();

        function toggleFaq(button) {
            const item = button.closest('.faq-item');
            const answer = item.querySelector('.faq-answer');
            const wasOpen = item.classList.contains('is-open');

            document.querySelectorAll('.faq-item.is-open').forEach(openItem => {
                if (openItem !== item) {
                    openItem.classList.remove('is-open');
                    openItem.querySelector('.faq-answer').style.maxHeight = null;
                }
            });

            if (wasOpen) {
                item.classList.remove('is-open');
                answer.style.maxHeight = null;
            } else {
                item.classList.add('is-open');
                answer.style.maxHeight = answer.scrollHeight + 'px';
            }
        }

        // ---------- Hero: one page per scholarship (or as many per page as the visitor picks) ----------
        const HERO_DATA = <?= json_encode($hero_scholarships, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;
        const HERO_BY_ID = new Map(HERO_DATA.map(s => [s.id, s]));
        const HERO_AUTOPLAY_MS = 7000;
        let heroMatches = HERO_DATA.map(s => s.id);
        let heroPage = 0, heroPerPage = 1, heroPaused = false, heroAuto = true;
        try { heroPerPage = [1, 2, 3, 4, 6].includes(+localStorage.getItem('scholarlink_hero_per_page')) ? +localStorage.getItem('scholarlink_hero_per_page') : 1; } catch (e) { }
        document.getElementById('heroPerPage').value = String(heroPerPage);

        const heroEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const heroPages = () => Math.max(1, Math.ceil(heroMatches.length / heroPerPage));
        const heroLink = s => `scholarship_details.php?id=${s.id}`;

        function heroSpot(s) {
            return `<a class="hero-spot cat-${heroEsc(s.category.toLowerCase())}" href="${heroLink(s)}">
                <div class="hero-spot-media" data-bg="${heroEsc(s.image)}" style="background-image: url('${heroEsc(s.image)}')">
                    <span class="hero-status ${heroEsc(s.statusClass)}">${heroEsc(s.status)}</span>
                    <span class="hero-chip"><i class="fas ${heroEsc(s.icon)}"></i>${heroEsc(s.category)}</span>
                </div>
                <div class="hero-spot-body">
                    <div class="hero-spot-meta"><i class="fas fa-book-open"></i> ${heroEsc(s.program)}${s.deadline ? ` · Deadline ${heroEsc(s.deadline)}` : ''}</div>
                    <h3>${heroEsc(s.name)}</h3>
                    <p>${heroEsc(s.desc)}</p>
                    <div class="hero-spot-chips"><span><i class="fas fa-star"></i> GWA ${heroEsc(s.gwa)}</span><span><i class="fas fa-user-graduate"></i> ${heroEsc(s.year)}</span></div>
                    <div class="hero-spot-foot">
                        <div class="hero-spot-award"><small>Award</small><strong>${heroEsc(s.award)}</strong>${s.freq ? `<span>${heroEsc(s.freq)}</span>` : ''}</div>
                        <span class="hero-spot-cta">${s.applied ? '<i class="fas fa-check"></i> Applied · View' : (s.open ? 'View & Apply' : 'View Details')} <i class="fas fa-arrow-right"></i></span>
                    </div>
                </div>
            </a>`;
        }

        function heroRow(s) {
            return `<a class="hero-row cat-${heroEsc(s.category.toLowerCase())}" href="${heroLink(s)}">
                <span class="hero-row-thumb" data-bg="${heroEsc(s.image)}" style="background-image: url('${heroEsc(s.image)}')"><i class="fas ${heroEsc(s.icon)}"></i></span>
                <span class="hero-row-main">
                    <strong>${heroEsc(s.name)}</strong>
                    <small>${heroEsc(s.category)} · <span class="hero-txt-status ${heroEsc(s.statusClass)}">${heroEsc(s.status)}</span>${s.deadline ? ` · ${heroEsc(s.deadline)}` : ''}${s.applied ? ' · <b class="hero-applied">✓ Applied</b>' : ''}</small>
                    <p>${heroEsc(s.desc)}</p>
                </span>
                <span class="hero-row-award">${heroEsc(s.award)}${s.freq ? `<small>${heroEsc(s.freq)}</small>` : ''}</span>
            </a>`;
        }

        function heroRender() {
            const box = document.getElementById('heroPages');
            if (!HERO_DATA.length) return;   // the server's "no grants" message stays
            const pages = heroPages();
            if (heroPage >= pages) heroPage = 0;
            if (heroPage < 0) heroPage = pages - 1;
            const items = heroMatches.slice(heroPage * heroPerPage, heroPage * heroPerPage + heroPerPage).map(id => HERO_BY_ID.get(id));

            if (!items.length) {
                box.innerHTML = '<div class="hero-empty"><i class="fas fa-filter-circle-xmark"></i>No scholarships match your filters.<button type="button" onclick="clearSmartMatch()">Clear filters</button></div>';
            } else if (heroPerPage === 1) {
                box.innerHTML = `<div class="hero-page">${heroSpot(items[0])}</div>`;
            } else {
                box.innerHTML = `<div class="hero-page"><div class="hero-rows ${heroPerPage >= 4 ? 'compact' : ''}" style="grid-template-rows: repeat(${heroPerPage}, minmax(0, 1fr))">${items.map(heroRow).join('')}</div></div>`;
            }

            // Pager: dots when there are few pages, otherwise just "Page x of y"
            document.getElementById('heroCount').textContent = heroMatches.length;
            document.getElementById('heroDots').innerHTML = pages > 1 && pages <= 14
                ? Array.from({ length: pages }, (_, i) => `<button type="button" class="${i === heroPage ? 'active' : ''}" onclick="heroGoTo(${i})" aria-label="Page ${i + 1}"></button>`).join('') : '';
            document.getElementById('heroPageLabel').textContent = items.length ? `Page ${heroPage + 1} of ${pages}` : '';
            document.querySelectorAll('.hero-nav').forEach(b => b.disabled = pages <= 1);

            heroFixImages(box);

            // The hero's photo and colour follow the scholarship on show
            if (items.length) {
                heroBackground(items[0].image);
                document.getElementById('interactiveHero').dataset.theme = items[0].category.toLowerCase();
            }
        }

        // Cross-fades the hero background between two layers
        const heroLayers = (() => {
            const show = document.querySelector('.hero-slideshow');
            const layers = Array.from(show.querySelectorAll('.hero-slide'));
            while (layers.length < 2) { const d = document.createElement('div'); d.className = 'hero-slide'; show.appendChild(d); layers.push(d); }
            return layers.slice(0, 2);
        })();
        let heroLayer = 0, heroBgUrl = '';
        // A missing picture falls back to the campus photo instead of leaving the hero blank
        const HERO_FALLBACK_IMAGE = 'https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=1800&q=80';
        const heroImageOk = new Map();   // url → Promise<url that loads>
        function heroUsableImage(url) {
            if (!heroImageOk.has(url)) {
                heroImageOk.set(url, new Promise(resolve => {
                    const img = new Image();
                    img.onload = () => resolve(url);
                    img.onerror = () => resolve(HERO_FALLBACK_IMAGE);
                    img.src = url;
                }));
            }
            return heroImageOk.get(url);
        }
        function heroBackground(url) {
            if (!url || url === heroBgUrl) return;
            heroBgUrl = url;
            heroUsableImage(url).then(src => {
                if (heroBgUrl !== url) return;   // the visitor already moved on
                const next = heroLayers[1 - heroLayer];
                next.style.backgroundImage = `url('${src.replace(/'/g, '%27')}')`;
                next.classList.add('is-active');
                heroLayers[heroLayer].classList.remove('is-active');
                heroLayer = 1 - heroLayer;
            });
        }
        // The picture inside a card or row: same fallback
        function heroFixImages(root) {
            root.querySelectorAll('[data-bg]').forEach(el => heroUsableImage(el.dataset.bg).then(src => {
                if (src !== el.dataset.bg) el.style.backgroundImage = `url('${src}')`;
            }));
        }

        function heroGo(delta, byUser = false) {
            if (byUser) heroSetAuto(false);   // once the visitor takes over, stop auto-advancing
            heroPage += delta;
            heroRender();
        }
        function heroGoTo(i) { heroSetAuto(false); heroPage = i; heroRender(); }

        // Pause / play. Paging stops by itself for visitors who asked their device to reduce motion.
        function heroSetAuto(on) {
            heroAuto = on;
            const btn = document.getElementById('heroPlay');
            btn.innerHTML = on ? '<i class="fas fa-pause"></i> Pause' : '<i class="fas fa-play"></i> Play';
            btn.setAttribute('aria-pressed', on ? 'false' : 'true');
            btn.setAttribute('aria-label', on ? 'Pause the automatic scholarship slideshow' : 'Play the automatic scholarship slideshow');
            // Screen readers announce page changes only when the visitor changes the page themselves
            document.getElementById('heroPages').setAttribute('aria-live', on ? 'off' : 'polite');
        }
        function heroToggleAuto() { heroSetAuto(!heroAuto); }
        heroSetAuto(!window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        function heroSetPerPage(n) {
            const first = heroPage * heroPerPage;   // keep the same scholarship in view
            heroPerPage = [1, 2, 3, 4, 6].includes(Number(n)) ? Number(n) : 1;
            heroPage = Math.floor(first / heroPerPage);
            document.getElementById('heroPerPage').value = String(heroPerPage);
            try { localStorage.setItem('scholarlink_hero_per_page', String(heroPerPage)); } catch (e) { }
            heroRender();
        }
        function heroSetMatches(ids) {
            const changed = ids.length !== heroMatches.length || ids.some((id, i) => id !== heroMatches[i]);
            heroMatches = ids;
            if (changed) heroPage = 0;
            heroRender();
        }
        function heroPause(p) { heroPaused = p; }
        setInterval(() => { if (heroAuto && !heroPaused && !document.hidden && heroPages() > 1) heroGo(1); }, HERO_AUTOPLAY_MS);

        // ---------- Filter bar → the hero's scholarships ----------
        function validateGwa(input) {
            let val = parseFloat(input.value);
            if (!isNaN(val)) {
                if (val < 1.00) val = 1.00;
                if (val > 5.00) val = 5.00;
                input.value = val.toFixed(2);
            }
            runSmartMatch();
        }

        // Scholarships matching the search bar and filters, in the page's order
        function matchingScholarships() {
            const val = id => (document.getElementById(id) ? document.getElementById(id).value : '');
            const search = val('searchInput').trim().toLowerCase();
            const category = val('matchCategory') || 'all';
            const program = val('matchProgram') || 'all';
            const year = val('matchYear') || 'all';
            const gwa = parseFloat(val('matchGwa'));
            const units = parseInt(val('matchUnits'));

            return HERO_DATA.filter(s =>
                (search === '' || s.search.includes(search))
                && (category === 'all' || s.category === category)
                && (program === 'all' || !s.programIds.length || s.programIds.includes(Number(program)))
                && (year === 'all' || s.yearKey === '' || s.yearKey.split(',').map(y => y.trim()).includes(year))   // e.g. "2nd Year, 4th Year"
                && (isNaN(gwa) || gwa <= s.gwaNum)
                && (isNaN(units) || s.minUnits === 0 || units >= s.minUnits)
            ).map(s => s.id);
        }

        function runSmartMatch() {
            heroSetMatches(matchingScholarships());
            document.getElementById('searchCommand')?.classList.toggle('is-filtering', heroMatches.length !== HERO_DATA.length);
        }

        function clearSmartMatch() {
            document.getElementById('searchInput').value = '';
            document.getElementById('matchGwa').value = '';
            document.getElementById('matchUnits').value = '';
            document.getElementById('matchCategory').value = 'all';
            document.getElementById('matchProgram').value = 'all';
            document.getElementById('matchYear').value = 'all';
            runSmartMatch();
        }

        let searchDebounce = null;
        function changeSearchFilter() {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(runSmartMatch, 200);
        }

        // "Browse by type" tiles: filter the hero to one category and bring it into view
        function filterCategory(category) {
            document.getElementById('matchCategory').value = category;
            runSmartMatch();
            heroSetAuto(false);
            document.getElementById('grants').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Cards in a section arrive one after another (their order sets the delay)
        document.querySelectorAll('.type-grid, .journey-steps, .partner-grid, .faq-list').forEach(group => {
            Array.from(group.children).forEach((child, i) => child.style.setProperty('--i', Math.min(i, 12)));
        });

        const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Hero numbers count up once
        if (!calm) document.querySelectorAll('[data-count]').forEach(el => {
            const target = parseInt(el.dataset.count, 10), prefix = el.dataset.prefix || '', start = performance.now(), ms = 1400;
            if (!(target > 0)) return;
            const step = now => {
                const t = Math.min(1, (now - start) / ms), eased = 1 - Math.pow(1 - t, 3);
                el.textContent = prefix + Math.round(target * eased).toLocaleString('en-US');
                if (t < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        });

        // Progress line under the header
        (function () {
            const bar = document.getElementById('scrollProgress');
            if (!bar) return;
            let queued = false;
            const update = () => {
                const max = document.documentElement.scrollHeight - window.innerHeight;
                bar.style.setProperty('--progress', max > 0 ? Math.min(1, window.scrollY / max).toFixed(4) : 0);
                queued = false;
            };
            window.addEventListener('scroll', () => { if (!queued) { queued = true; requestAnimationFrame(update); } }, { passive: true });
            update();
        })();

        // Sections fade in as they scroll into view
        (function () {
            const items = document.querySelectorAll('.reveal');
            if (!('IntersectionObserver' in window)) { items.forEach(el => el.classList.add('is-visible')); return; }
            const io = new IntersectionObserver(entries => entries.forEach(e => {
                if (e.isIntersecting) { e.target.classList.add('is-visible'); io.unobserve(e.target); }
            }), { threshold: 0.12 });
            items.forEach(el => io.observe(el));
        })();

        // ---------- Personal matches: a signed-in student's profile fills the filters ----------
        const MY_PROFILE = <?= json_encode($my_profile, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        let myMatchesOn = false;

        function applyMyProfile() {
            const set = (id, v) => { const el = document.getElementById(id); if (el && v !== '' && (el.tagName !== 'SELECT' || [...el.options].some(o => o.value === v))) el.value = v; };
            clearSmartMatch();
            set('matchProgram', MY_PROFILE.program);
            set('matchYear', MY_PROFILE.year);
            set('matchGwa', MY_PROFILE.gwa);
            set('matchUnits', MY_PROFILE.units);
            runSmartMatch();
        }

        function paintMyMatches() {
            const banner = document.getElementById('matchBanner');
            if (!banner) return;
            const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            const about = [MY_PROFILE.programName, MY_PROFILE.year, MY_PROFILE.gwa && 'GWA ' + MY_PROFILE.gwa].filter(Boolean).map(esc).join(' · ');
            banner.classList.toggle('is-off', !myMatchesOn);
            document.getElementById('matchText').innerHTML = myMatchesOn
                ? `Hi ${esc(MY_PROFILE.name)}! Showing the <b>${heroMatches.length} scholarship${heroMatches.length === 1 ? '' : 's'}</b> that match your profile${about ? ' (' + about + ')' : ''}.`
                : `Showing all scholarships. Turn on your matches to see only the ones you qualify for.`;
            document.getElementById('matchToggle').textContent = myMatchesOn ? 'Show all scholarships' : '✨ Show my matches';
        }

        function toggleMyMatches() {
            myMatchesOn = !myMatchesOn;
            myMatchesOn ? applyMyProfile() : clearSmartMatch();
            paintMyMatches();
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (MY_PROFILE) { myMatchesOn = true; applyMyProfile(); paintMyMatches(); }
            else runSmartMatch();
            // Changing a filter by hand: the banner offers the profile matches again
            ['searchInput', 'matchCategory', 'matchProgram', 'matchYear', 'matchGwa', 'matchUnits'].forEach(id => {
                document.getElementById(id)?.addEventListener('input', e => { if (e.isTrusted && myMatchesOn) { myMatchesOn = false; paintMyMatches(); } });
            });
        });
    </script>
    <?php include 'includes/chatbot.php'; ?>
</body>
</html>
