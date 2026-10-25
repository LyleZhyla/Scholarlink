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
        SELECT s.*, p.ProgramName 
        FROM scholarship s 
        LEFT JOIN program p ON s.ProgramID = p.ProgramID 
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

$distinct_programs = array_values(array_unique(array_filter(array_column($all_scholarships, 'ProgramName'))));
sort($distinct_programs);

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
        .hero-overlay { position: absolute; inset: 0; z-index: 1; pointer-events: none;
            background:
                radial-gradient(600px 300px at 85% 110%, rgba(240, 180, 41, 0.22), transparent 60%),
                linear-gradient(100deg, rgba(6, 36, 23, 0.96) 0%, rgba(11, 61, 42, 0.88) 50%, rgba(21, 128, 61, 0.55) 100%); }
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

        /* ---------- SECTION HEADINGS ---------- */
        section { padding: 36px 0; }
        .eyebrow { display: inline-flex; align-items: center; gap: 8px; color: var(--green); font-size: 0.72rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; }
        .eyebrow::before { content: ''; width: 18px; height: 2px; border-radius: 2px; background: currentColor; }
        .section-title { margin: 6px 0 4px; font-size: clamp(1.4rem, 2.4vw, 1.9rem); font-weight: 800; letter-spacing: -0.03em; line-height: 1.15; color: var(--ink); }
        .section-sub { margin: 0; color: var(--muted); font-size: 0.92rem; }
        .count-chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 999px; background: var(--green-soft); color: var(--green); font-size: 0.74rem; font-weight: 800; }
        .show-select { appearance: none; -webkit-appearance: none; cursor: pointer; padding: 8px 32px 8px 12px; border-radius: 10px; border: 1px solid var(--line); background-color: #fff; color: var(--ink); font-size: 0.78rem; font-weight: 700; outline: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%236b8178' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 11px center; }

        /* ---------- GRANT CARDS ---------- */
        .program-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; }
        .program-card { --cat: var(--green); --cat-soft: var(--green-soft);
            position: relative; display: flex; flex-direction: column; overflow: hidden; border-radius: 20px; background: #fff; border: 1px solid var(--line); box-shadow: var(--shadow-sm);
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease; }
        .program-card.cat-incentive { --cat: var(--gold); --cat-soft: var(--gold-soft); }
        .program-card.cat-allowance { --cat: var(--sky); --cat-soft: var(--sky-soft); }
        .program-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: color-mix(in srgb, var(--cat) 35%, var(--line)); }

        .program-media { position: relative; height: 128px; overflow: hidden; background: var(--forest); flex-shrink: 0; }
        .program-media img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.6s ease; }
        .program-media::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0) 45%, rgba(6, 36, 23, 0.55)); pointer-events: none; }
        .program-card:hover .program-media img { transform: scale(1.07); }
        .category-chip { position: absolute; left: 10px; bottom: 10px; z-index: 2; display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 4px; border-radius: 999px; background: rgba(255,255,255,0.95); color: var(--ink); font-size: 0.66rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
        .category-chip i { width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--cat); color: #fff; font-size: 0.6rem; }

        .status-pill { position: absolute; top: 10px; right: 10px; z-index: 2; padding: 4px 10px; border-radius: 999px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; backdrop-filter: blur(8px); }
        .status-active { background: rgba(255,255,255,0.92); color: var(--green); }
        .status-urgent { background: #fef2f2; color: #dc2626; box-shadow: 0 0 0 1px #fecaca; }
        .status-closed { background: rgba(241, 242, 244, 0.95); color: #6b7280; }

        .program-body { padding: 14px 16px 16px; flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .program-meta { display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 0.7rem; font-weight: 700; margin-bottom: 4px; min-width: 0; }
        .program-meta span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .program-meta i { color: var(--cat); font-size: 0.7rem; }
        .program-title { margin: 0 0 4px; font-size: 0.98rem; font-weight: 800; letter-spacing: -0.015em; line-height: 1.3; color: var(--ink); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .program-desc { margin: 0 0 12px; color: var(--muted); font-size: 0.78rem; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        .award-row { margin-top: auto; display: flex; align-items: flex-end; justify-content: space-between; gap: 8px; padding: 10px 12px; border-radius: 14px; background: var(--cat-soft); }
        .award-row small { display: block; color: var(--muted); font-size: 0.6rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
        .award-amount { display: block; color: var(--ink); font-size: 1.15rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1.2; }
        .award-freq { color: var(--cat); font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
        .chip-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .mini-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; border-radius: 8px; background: #f4f7f5; color: var(--ink-soft); font-size: 0.7rem; font-weight: 700; }
        .mini-chip i { color: var(--muted); font-size: 0.65rem; }

        .card-actions { display: flex; gap: 8px; margin-top: 12px; }
        .program-link { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px; border-radius: 12px; background: #fff; color: var(--green); font-size: 0.84rem; font-weight: 800; border: 1px solid var(--line); transition: all 0.2s ease; }
        .program-link i { transition: transform 0.2s ease; }
        .program-card:hover .program-link.primary { background: var(--green); color: #fff; border-color: var(--green); }
        .program-card:hover .program-link.primary i { transform: translateX(3px); }
        .program-link.secondary:hover { border-color: var(--green); }

        .empty-box { text-align: center; background: #fff; border: 1px dashed #c9dccf; border-radius: 20px; padding: 40px 20px; }

        .pagination-controls { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 6px; margin-top: 26px; }
        .pagination-controls .page-btn { min-width: 40px; height: 40px; padding: 0 10px; border-radius: 12px; border: 1px solid var(--line); background: #fff; color: var(--ink-soft); font-weight: 800; cursor: pointer; transition: all 0.2s; }
        .pagination-controls .page-btn:hover:not(:disabled) { border-color: var(--green); color: var(--green); }
        .pagination-controls .page-btn.active { background: var(--green); color: #fff; border-color: var(--green); box-shadow: 0 6px 16px rgba(25, 135, 84, 0.25); }
        .pagination-controls .page-btn:disabled { opacity: 0.35; cursor: not-allowed; }

        /* ---------- HOW IT WORKS + FAQ ---------- */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
        .info-panel { background: #fff; border: 1px solid var(--line); border-radius: var(--radius-lg); padding: clamp(20px, 3vw, 30px); box-shadow: var(--shadow-sm); }
        .steps { margin-top: 18px; }
        .step-row { position: relative; display: flex; gap: 16px; align-items: flex-start; padding-bottom: 20px; }
        .step-row:last-child { padding-bottom: 0; }
        .step-row:not(:last-child)::after { content: ''; position: absolute; left: 21px; top: 48px; bottom: 4px; width: 2px; background: repeating-linear-gradient(180deg, #cfe3d6 0 5px, transparent 5px 10px); }
        .step-icon { position: relative; width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; background: var(--green-soft); color: var(--green); font-size: 1rem; }
        .step-icon b { position: absolute; top: -6px; right: -6px; width: 20px; height: 20px; border-radius: 50%; background: var(--green); color: #fff; font-size: 0.65rem; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; }
        .step-row h3 { margin: 2px 0 2px; font-size: 0.95rem; font-weight: 800; color: var(--ink); }
        .step-row p { margin: 0; font-size: 0.82rem; color: var(--muted); }

        .faq-list { margin-top: 16px; }
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

        /* ---------- BREAKPOINTS ---------- */
        @media (max-width: 1100px) {
            .program-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .search-grid { grid-template-columns: repeat(3, minmax(0, 1fr)) auto; }
            .search-grid .field:first-child { grid-column: 1 / -1; }
        }

        @media (max-width: 900px) {
            .hero-grid { grid-template-columns: 1fr; }
            .hero-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .stat-tile { flex-direction: column; align-items: flex-start; gap: 8px; padding: 12px; }
            .stat-tile:hover { transform: translateY(-2px); }
            .stat-tile strong { font-size: 1.05rem; }
            .program-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .info-grid { grid-template-columns: 1fr; }
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

        /* Phones: each grant becomes a compact horizontal row */
        @media (max-width: 600px) {
            .hero-stats { gap: 8px; }
            .stat-tile { padding: 10px; border-radius: 12px; min-width: 0; }
            .stat-icon { display: none; }
            .stat-tile strong { font-size: 0.92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .stat-tile > div > span { font-size: 0.56rem; letter-spacing: 0.05em; }
            .hero-actions a { flex: 1 1 auto; }

            .program-grid { grid-template-columns: 1fr; gap: 12px; }
            .program-card { flex-direction: row; border-radius: 16px; }
            .program-media { width: 108px; height: auto; min-height: 100%; }
            .category-chip { left: 6px; right: 6px; bottom: 6px; justify-content: center; padding: 3px 6px; font-size: 0.56rem; }
            .category-chip i { display: none; }
            .status-pill { top: 6px; left: 6px; right: auto; font-size: 0.56rem; padding: 3px 7px; }
            .program-card .program-desc { display: none; }
            .program-body { padding: 12px; }
            .program-title { font-size: 0.9rem; margin-bottom: 8px; }
            .award-row { padding: 8px 10px; }
            .award-amount { font-size: 1rem; }
            .chip-row { margin-top: 8px; }
            .card-actions { margin-top: 10px; }
            .program-link { padding: 8px; }
        }
    </style>
</head>
<body>
    <div class="announce">
        <span class="dot"></span>
        Application for A.Y. 2026-2027 is now open! Apply early via ScholarLink.
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
    </header>

    <main>
        <!-- Smart Match Filter Bar (top of the page) -->
        <div class="search-command-center page-inner">
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
                        <?php foreach ($distinct_programs as $prog): ?>
                            <option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?></option>
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
        </div>

        <!-- Hero (inset banner) -->
        <div class="page-inner">
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
                            <a class="btn-primary" href="#grants"><i class="fas fa-magnifying-glass"></i> Browse Grants</a>
                            <?php if (!$is_logged_in): ?>
                                <a class="btn-ghost" href="student_login.php"><i class="fas fa-user-plus"></i> Create Account</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Live figures from the scholarship table -->
                    <div class="hero-stats">
                        <div class="stat-tile">
                            <span class="stat-icon green"><i class="fas fa-graduation-cap"></i></span>
                            <div>
                                <strong><?= number_format($active_scholarship_count) ?></strong>
                                <span>Open Grants</span>
                            </div>
                        </div>
                        <div class="stat-tile">
                            <span class="stat-icon gold"><i class="fas fa-peso-sign"></i></span>
                            <div>
                                <strong>₱<?= number_format($total_active_award_value) ?></strong>
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
            </div>
        </div>

        <!-- Grants Grid -->
        <section id="grants">
            <div class="page-inner">
                <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-6">
                    <div>
                        <span class="eyebrow">Find your match</span>
                        <h2 class="section-title"><?= htmlspecialchars($grants_title) ?></h2>
                        <p class="section-sub"><?= htmlspecialchars($grants_body) ?></p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="count-chip"><i class="fas fa-circle-check"></i> <span id="resultCount"><?= count($all_scholarships) ?></span> found</span>
                        <select id="itemsPerPage" class="show-select" onchange="changeItemsPerPage()" aria-label="Grants per page">
                            <option value="4">Show 4</option>
                            <option value="8" selected>Show 8</option>
                            <option value="12">Show 12</option>
                            <option value="999">Show all</option>
                        </select>
                    </div>
                </div>

                <?php if (empty($all_scholarships)): ?>
                    <div class="empty-box">
                        <i class="fas fa-seedling text-4xl text-slate-300 mb-3"></i>
                        <h3 class="text-lg font-bold text-slate-900 mb-1"><?= htmlspecialchars($no_grants_title) ?></h3>
                        <p class="text-slate-500 text-sm"><?= htmlspecialchars($no_grants_body) ?></p>
                    </div>
                <?php endif; ?>

                <?php $category_icons = ['Scholarship' => 'fa-graduation-cap', 'Incentive' => 'fa-trophy', 'Allowance' => 'fa-wallet']; ?>
                <div id="scholarshipsGrid" class="program-grid">
                    <?php foreach ($all_scholarships as $sch): ?>
                        <?php
                            $statusValue = $sch['Status'] ?? 'Active';
                            $statusMeta = scholarship_status_meta($statusValue, $sch['Deadline'] ?? null);
                            $isActiveScholarship = strtolower($statusValue) === 'active';
                            $category = $sch['Category'] ?? DEFAULT_SCHOLARSHIP_CATEGORY;
                            $programLabel = $sch['ProgramName'] ?? 'Open to All Courses';

                            $scholarshipNameLower = strtolower($sch['Name'] ?? '');
                            $minUnits = 0;
                            if (strpos($scholarshipNameLower, 'academic scholars - partial') !== false) {
                                $minUnits = 12;
                            } elseif (strpos($scholarshipNameLower, 'academic scholars - full') !== false) {
                                $minUnits = 15;
                            }
                        ?>
                        <article class="program-card cat-<?= htmlspecialchars(strtolower($category)) ?>"
                                 data-status="<?= htmlspecialchars($statusValue) ?>"
                                 data-category="<?= htmlspecialchars($category) ?>"
                                 data-program="<?= htmlspecialchars($programLabel) ?>"
                                 data-name="<?= htmlspecialchars(strtolower($sch['Name'])) ?>"
                                 data-year="<?= htmlspecialchars($sch['YearLevel'] ?? '') ?>"
                                 data-gwa="<?= htmlspecialchars($sch['MinimumGWA'] ?? '') ?>"
                                 data-min-units="<?= $minUnits ?>">

                            <div class="program-media">
                                <img src="<?= htmlspecialchars(getProgramImage($sch['ProgramName'] ?? '', $sch['Name'] ?? '', $sch['YearLevel'] ?? '')) ?>"
                                    onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=800&q=80';"
                                    alt="<?= htmlspecialchars($sch['Name']) ?>" loading="lazy">
                                <span class="status-pill <?= $statusMeta['class'] ?>"><?= $statusMeta['label'] ?></span>
                                <span class="category-chip"><i class="fas <?= $category_icons[$category] ?? 'fa-tag' ?>"></i><?= htmlspecialchars($category) ?></span>
                            </div>

                            <div class="program-body">
                                <div class="program-meta" title="<?= htmlspecialchars($programLabel) ?>"><i class="fas fa-book-open"></i><span><?= htmlspecialchars($programLabel) ?></span></div>
                                <h3 class="program-title"><?= htmlspecialchars($sch['Name']) ?></h3>
                                <p class="program-desc"><?= htmlspecialchars($sch['Description']) ?></p>

                                <div class="award-row">
                                    <div>
                                        <small>Award</small>
                                        <strong class="award-amount">₱<?= number_format((float) ($sch['AwardAmount'] ?? 0)) ?></strong>
                                    </div>
                                    <?php if (!empty($sch['ReleaseFrequency'])): ?>
                                        <span class="award-freq"><?= htmlspecialchars($sch['ReleaseFrequency']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="chip-row">
                                    <span class="mini-chip"><i class="fas fa-star"></i> GWA <?= htmlspecialchars($sch['MinimumGWA']) ?></span>
                                    <span class="mini-chip"><i class="fas fa-user-graduate"></i> <?= !empty($sch['YearLevel']) ? htmlspecialchars($sch['YearLevel']) : 'All Years' ?></span>
                                </div>

                                <div class="card-actions">
                                    <?php if (isset($applied_applications[$sch['ScholarshipID']])): ?>
                                        <a href="actions/generate_application_pdf.php?app_id=<?= $applied_applications[$sch['ScholarshipID']] ?>" target="_blank" class="program-link secondary" title="Download Form">
                                            <i class="fas fa-file-pdf"></i> Form
                                        </a>
                                        <a href="scholarship_details.php?id=<?= (int) $sch['ScholarshipID'] ?>" class="program-link primary">
                                            View <i class="fas fa-eye"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="scholarship_details.php?id=<?= (int) $sch['ScholarshipID'] ?>" class="program-link primary">
                                            <?= $isActiveScholarship ? 'View & Apply <i class="fas fa-arrow-right"></i>' : 'View Details <i class="fas fa-eye"></i>' ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="empty-box" id="noFilterResults" style="display:none;">
                    <i class="fas fa-filter-circle-xmark text-4xl text-slate-300 mb-3"></i>
                    <h3 class="text-lg font-bold text-slate-900 mb-1">No scholarships match your criteria</h3>
                    <p class="text-slate-500 text-sm">Try clearing filters or checking other years.</p>
                    <button onclick="clearSmartMatch()" class="mt-4 px-5 py-2.5 bg-emerald-50 text-emerald-700 text-sm font-bold rounded-xl hover:bg-emerald-100">Clear Filters</button>
                </div>
                <div class="pagination-controls" id="paginationControls"></div>
                <div id="pageInfo" class="text-center text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-3"></div>
            </div>
        </section>

        <!-- How It Works + FAQ (side by side) -->
        <section id="how-it-works" style="padding-top: 8px;">
            <div class="page-inner info-grid">
                <div class="info-panel">
                    <span class="eyebrow">Getting Started</span>
                    <h2 class="section-title">How ScholarLink Works</h2>
                    <div class="steps">
                        <div class="step-row">
                            <div class="step-icon"><i class="fas fa-user-plus"></i><b>1</b></div>
                            <div>
                                <h3>Create an Account</h3>
                                <p>Register with your student credentials to unlock your application dashboard.</p>
                            </div>
                        </div>
                        <div class="step-row">
                            <div class="step-icon"><i class="fas fa-folder-open"></i><b>2</b></div>
                            <div>
                                <h3>Build your Vault</h3>
                                <p>Upload your grades and certificates once to reuse them across all grants.</p>
                            </div>
                        </div>
                        <div class="step-row">
                            <div class="step-icon"><i class="fas fa-bolt"></i><b>3</b></div>
                            <div>
                                <h3>Apply with 1-Click</h3>
                                <p>Submit your complete application packet instantly without tedious re-uploads.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="info-panel" id="faq">
                    <span class="eyebrow">Need Help?</span>
                    <h2 class="section-title">Frequently Asked Questions</h2>
                    <div class="faq-list">
                        <div class="faq-item">
                            <button type="button" class="faq-question" onclick="toggleFaq(this)">
                                Who is eligible to apply? <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            <div class="faq-answer">
                                <div class="faq-answer-inner">Any enrolled TAU student who meets the specific GWA and program criteria listed on the scholarship card can apply.</div>
                            </div>
                        </div>
                        <div class="faq-item">
                            <button type="button" class="faq-question" onclick="toggleFaq(this)">
                                How does the Document Vault work? <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            <div class="faq-answer">
                                <div class="faq-answer-inner">Upload your official documents once in your student portal. When applying to new grants, the system automatically binds your verified files.</div>
                            </div>
                        </div>
                        <div class="faq-item">
                            <button type="button" class="faq-question" onclick="toggleFaq(this)">
                                What is the difference between a scholarship, incentive and allowance? <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            <div class="faq-answer">
                                <div class="faq-answer-inner">Scholarships support your studies based on merit or financial need. Incentives reward roles such as student leadership or publication work. Allowances support active participation, such as athletes, cultural performers and ROTC cadets. Use the category filter above to see each type.</div>
                            </div>
                        </div>
                    </div>
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
            <div class="footer-bottom">
                <div class="flex items-center gap-3">
                    <img src="assets/img/tau_logo.png" alt="TAU Logo" class="h-8 w-8">
                    <span>Tarlac Agricultural University &copy; <?= date('Y') ?> · <span class="font-bold text-yellow-400 uppercase tracking-widest">ScholarLink</span></span>
                </div>
                <nav class="footer-links">
                    <a href="#grants">Grants</a>
                    <a href="#how-it-works">How It Works</a>
                    <a href="#faq">FAQ</a>
                    <a href="student_login.php"><?= $is_logged_in ? 'Portal' : 'Sign In' ?></a>
                </nav>
            </div>
        </div>
    </footer>

    <script>
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

        // Hero background slideshow
        (function () {
            const slides = document.querySelectorAll('.hero-slide');
            if (slides.length < 2) return;
            let active = 0;
            setInterval(() => {
                slides[active].classList.remove('is-active');
                active = (active + 1) % slides.length;
                slides[active].classList.add('is-active');
            }, 6000);
        })();

        // Smart Match Pagination & Filtering
        let currentPage = 1;
        const itemsSelect = document.getElementById('itemsPerPage');
        let itemsPerPage = itemsSelect ? parseInt(itemsSelect.value) : 8;
        const allCards = Array.from(document.querySelectorAll('.program-card'));

        function validateGwa(input) {
            let val = parseFloat(input.value);
            if (!isNaN(val)) {
                if (val < 1.00) val = 1.00;
                if (val > 5.00) val = 5.00;
                input.value = val.toFixed(2);
            }
            runSmartMatch();
        }

        function changeItemsPerPage() {
            itemsPerPage = parseInt(document.getElementById('itemsPerPage').value) || 8;
            currentPage = 1;
            renderPagination();
        }

        function runSmartMatch() {
            currentPage = 1;
            renderPagination();
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

        function getFilteredCards() {
            const searchInput = document.getElementById('searchInput') ? document.getElementById('searchInput').value.trim().toLowerCase() : '';
            const matchGwa = document.getElementById('matchGwa') ? document.getElementById('matchGwa').value : '';
            const matchUnits = document.getElementById('matchUnits') ? document.getElementById('matchUnits').value : '';
            const matchCategory = document.getElementById('matchCategory') ? document.getElementById('matchCategory').value : 'all';
            const matchProgram = document.getElementById('matchProgram') ? document.getElementById('matchProgram').value : 'all';
            const matchYear = document.getElementById('matchYear') ? document.getElementById('matchYear').value : 'all';

            return allCards.filter(card => {
                const cardName = card.dataset.name;
                const cardProgram = card.dataset.program;
                const cardYear = card.dataset.year;
                const cardGwa = parseFloat(card.dataset.gwa) || 5.0;
                const cardMinUnits = parseInt(card.dataset.minUnits) || 0;

                const searchMatch = searchInput === '' || cardName.includes(searchInput);
                const categoryMatch = matchCategory === 'all' || card.dataset.category === matchCategory;
                const progMatch = matchProgram === 'all' || cardProgram === 'Open to All Courses' || cardProgram === '' || cardProgram === matchProgram;
                const yearMatch = matchYear === 'all' || cardYear === '' || cardYear === matchYear;

                let gwaMatch = true;
                if (matchGwa !== '') {
                    const userGwa = parseFloat(matchGwa);
                    if (!isNaN(userGwa) && !isNaN(cardGwa)) {
                        gwaMatch = userGwa <= cardGwa;
                    }
                }

                let unitsMatch = true;
                if (matchUnits !== '' && cardMinUnits > 0) {
                    const userUnits = parseInt(matchUnits);
                    if (!isNaN(userUnits)) {
                        unitsMatch = userUnits >= cardMinUnits;
                    }
                }

                return searchMatch && categoryMatch && progMatch && yearMatch && gwaMatch && unitsMatch;
            });
        }

        function renderPagination() {
            const cards = getFilteredCards();
            const totalItems = cards.length;
            const noFilterResults = document.getElementById('noFilterResults');
            const paginationControls = document.getElementById('paginationControls');
            const pageInfo = document.getElementById('pageInfo');
            const resultCount = document.getElementById('resultCount');

            allCards.forEach(card => card.style.display = 'none');
            if (resultCount) resultCount.innerText = totalItems;

            if (totalItems === 0) {
                if (noFilterResults) noFilterResults.style.display = allCards.length ? 'block' : 'none';
                if (paginationControls) paginationControls.innerHTML = '';
                if (pageInfo) pageInfo.innerText = '';
                return;
            }

            if (noFilterResults) noFilterResults.style.display = 'none';

            const totalPages = Math.ceil(totalItems / itemsPerPage);
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            cards.slice(start, end).forEach(card => {
                card.style.display = 'flex';
            });

            if (pageInfo) pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} Grants`;

            let btnHtml = '';
            if (totalPages > 1) {
                btnHtml = `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="page-btn" aria-label="Previous page"><i class="fas fa-chevron-left"></i></button>`;
                for (let i = 1; i <= totalPages; i++) {
                    btnHtml += `<button onclick="changePage(${i})" class="page-btn ${currentPage === i ? 'active' : ''}">${i}</button>`;
                }
                btnHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="page-btn" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>`;
            }

            if (paginationControls) paginationControls.innerHTML = btnHtml;
        }

        function changePage(page) {
            const totalPages = Math.ceil(getFilteredCards().length / itemsPerPage);
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            renderPagination();
            document.getElementById('grants').scrollIntoView({ behavior: 'smooth' });
        }

        let searchDebounce = null;
        function changeSearchFilter() {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => {
                currentPage = 1;
                renderPagination();
            }, 200);
        }

        document.addEventListener('DOMContentLoaded', renderPagination);
    </script>
    <?php include 'includes/chatbot.php'; ?>
</body>
</html>
