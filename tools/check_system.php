<?php
// ✨ SYSTEM HEALTH CHECK: run it before a demo or a deployment
//
//   C:\xampp\php\php tools\check_system.php                  full check
//   C:\xampp\php\php tools\check_system.php --quick          skip the PHP syntax check (faster)
//   C:\xampp\php\php tools\check_system.php --url=http://...  check another address than APP_URL in .env
//
// It only READS: it opens pages and downloads reports as each role, and changes nothing. Its temporary
// sign-in sessions are deleted afterwards, and so are any audit-log entries its page visits create.
// Exit code 0 = all good, 1 = something failed (details are printed).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');

$root = realpath(__DIR__ . '/..');
require $root . '/includes/db_connect.php';

$opts = getopt('', ['quick', 'url:']);
$env = is_file("$root/.env") ? (@parse_ini_file("$root/.env") ?: []) : [];
$base = rtrim($opts['url'] ?? ($env['APP_URL'] ?? 'http://localhost/scholarlink'), '/');
$started = microtime(true);

// ---------- Output ----------
$results = ['pass' => 0, 'warn' => 0, 'fail' => 0];
$failures = [];
$color = getenv('NO_COLOR') === false && (function_exists('sapi_windows_vt100_support') ? @sapi_windows_vt100_support(STDOUT, true) : true);
$paint = fn($code, $text) => $color ? "\033[{$code}m$text\033[0m" : $text;
function section(string $title): void { global $paint; echo "\n", $paint('1;36', "▸ $title"), "\n"; }
function pass(string $msg): void { global $results, $paint; $results['pass']++; echo '  ', $paint('32', '✓'), " $msg\n"; }
function warn(string $msg): void { global $results, $paint; $results['warn']++; echo '  ', $paint('33', '!'), " $msg\n"; }
function fail(string $msg): void { global $results, $failures, $paint; $results['fail']++; $failures[] = $msg; echo '  ', $paint('31', '✗'), " $msg\n"; }
function check(bool $ok, string $good, string $bad, bool $warn_only = false): void { $ok ? pass($good) : ($warn_only ? warn($bad) : fail($bad)); }

// ---------- HTTP ----------
function http(string $url, ?string $session = null, ?array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true]);
    if ($session) curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID=$session");
    // Signed-in POSTs need the session's security token (enforceCsrf), which a browser reads from any page
    if ($post !== null && $session && !isset($post['csrf_token'])) $post['csrf_token'] = csrfTokenFor($session);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($ch);
    if ($raw === false) { $err = curl_error($ch); curl_close($ch); return ['code' => 0, 'body' => '', 'location' => '', 'type' => '', 'error' => $err]; }
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $headers = substr($raw, 0, $hsize);
    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);
    return ['code' => $code, 'body' => substr($raw, $hsize), 'location' => trim($m[1] ?? ''), 'type' => $type, 'error' => ''];
}
// The security token of a test session, read from a page like a browser would (cached per session)
function csrfTokenFor(string $session): string {
    static $tokens = [];
    if (!isset($tokens[$session])) {
        $page = http($GLOBALS['base'] . '/index.php', $session);   // index.php starts the session, so it carries the token
        $tokens[$session] = preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $m) ? $m[1] : '';
    }
    return $tokens[$session];
}

// PHP errors printed into a page, or a bounce to a login page
function pageProblems(array $r): array {
    $issues = [];
    if (preg_match_all('#<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>:\s*(.*?)<br#s', $r['body'], $m, PREG_SET_ORDER)) {
        foreach (array_slice($m, 0, 2) as $x) $issues[] = $x[1] . ': ' . mb_strimwidth(strip_tags($x[2]), 0, 140, '…');
    }
    if (stripos($r['body'], 'Uncaught ') !== false && !$issues) $issues[] = 'Uncaught error in page';
    if (preg_match("#location\.replace\('[^']*login#", $r['body']) || preg_match('#login\.php#', $r['location'])) $issues[] = 'sent to the login page (session lost or not allowed)';
    return $issues;
}

// ---------- Temporary sign-in sessions (deleted at the end) ----------
$session_dir = ini_get('session.save_path') ?: 'C:\xampp\tmp';
$made_sessions = [];
function makeSession(string $role, int $user_id): string {
    global $session_dir, $made_sessions;
    $id = 'hc' . bin2hex(random_bytes(12));
    $data = ['logged_in' => true, 'role' => $role, 'user_id' => $user_id, 'username' => 'healthcheck', 'first_name' => 'Health', 'last_name' => 'Check',
             'email' => 'healthcheck@example.com', 'last_activity' => time(), 'audit_unlocked_until' => time() + 900];
    $encoded = '';
    foreach ($data as $k => $v) $encoded .= $k . '|' . serialize($v);
    file_put_contents(rtrim($session_dir, '/\\') . DIRECTORY_SEPARATOR . "sess_$id", $encoded);
    $made_sessions[] = $id;
    return $id;
}

$audit_start = (int) $pdo->query("SELECT COALESCE(MAX(AuditID), 0) FROM audit_log")->fetchColumn();
register_shutdown_function(function () use (&$made_sessions, $session_dir, $pdo, $audit_start) {
    foreach ($made_sessions as $id) @unlink(rtrim($session_dir, '/\\') . DIRECTORY_SEPARATOR . "sess_$id");
    // Entries written while the check browsed (keeping any demo-data bookkeeping)
    try {
        $keep = $pdo->query("SHOW TABLES LIKE 'demo_data_rows'")->fetchColumn() ? ' AND AuditID NOT IN (SELECT RowID FROM demo_data_rows)' : '';
        $pdo->exec("DELETE FROM audit_log WHERE AuditID > $audit_start$keep");
    } catch (PDOException $e) { }
});

echo $paint('1', "ScholarLink health check"), " — ", date('M j, Y g:i A'), " — $base\n";

// =====================================================================
section('1. Code');
if (isset($opts['quick'])) {
    warn('PHP syntax check skipped (--quick)');
} else {
    $php = PHP_BINARY;
    $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn($f) => !in_array($f->getFilename(), ['vendor', 'node_modules', '.git', 'uploads', 'backups', 'temp'], true)));
    $count = 0; $broken = [];
    foreach ($files as $f) {
        if ($f->getExtension() !== 'php') continue;
        $count++;
        exec(escapeshellarg($php) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $rc);
        if ($rc !== 0) $broken[] = str_replace($root . DIRECTORY_SEPARATOR, '', $f->getPathname()) . ': ' . trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($out, fn($l) => stripos($l, 'error') !== false))));
        $out = [];
    }
    if ($broken) foreach ($broken as $b) fail("Syntax error in $b"); else pass("All $count PHP files pass the syntax check");
}

// =====================================================================
section('2. Database');
pass('Connected to the database');
$need = [
    // [table, column or null, migration that adds it]
    ['scholarship', 'Category', '2026-10-23_add_scholarship_category.sql'],
    ['scholarship_custom_fields', 'IsActive', '2026-10-23_form_builder.sql'],
    ['scholarship', 'FormTemplate', '2026-10-23_form_template.sql'],
    ['scholarship_custom_fields', 'PdfPage', '2026-10-23_pdf_boxes.sql'],
    ['scholarship_pdf_overrides', null, '2026-10-23_pdf_field_overrides.sql'],
    ['scholarship_pdf_edits', null, '2026-10-23_pdf_content_edits.sql'],
    ['form_templates', null, '2026-10-23_form_templates.sql'],
    ['application', 'TermID', '2026-10-24_system_reports.sql'],
    ['scholarship_report_formats', null, '2026-10-24_system_reports.sql'],
    ['scholarship_award_notice', null, '2026-10-25_award_notice.sql'],
    ['scholarship_award_notice', 'DisplayName', '2026-10-24_award_notice_header.sql'],
    ['deadline_reminders', null, '2026-10-24_deadline_reminders.sql'],
];
$missing = [];
foreach ($need as [$table, $column, $migration]) {
    $sql = $column === null
        ? "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        : "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    $st = $pdo->prepare($sql);
    $st->execute($column === null ? [$table] : [$table, $column]);
    if (!(int) $st->fetchColumn()) $missing[$migration][] = $table . ($column ? ".$column" : '');
}
if ($missing) foreach ($missing as $mig => $what) fail("Missing " . implode(', ', $what) . " — run migrations/$mig");
else pass('All tables and columns from the migrations are present');

$counts = $pdo->query("SELECT (SELECT COUNT(*) FROM scholarship) s, (SELECT COUNT(*) FROM scholarship WHERE Status = 'Active' AND Deadline >= CURDATE()) open_s,
                              (SELECT COUNT(*) FROM application) a, (SELECT COUNT(*) FROM users WHERE Role = 'Student') st,
                              (SELECT COUNT(*) FROM users WHERE Role = 'Student' AND Email LIKE '%@demo.tau.edu.ph') demo,
                              (SELECT COUNT(*) FROM academic_terms WHERE Status = 'Active') terms")->fetch(PDO::FETCH_ASSOC);
pass("{$counts['s']} scholarships ({$counts['open_s']} open), {$counts['a']} applications, {$counts['st']} students");
check((int) $counts['terms'] === 1, 'Exactly one active academic term', "{$counts['terms']} active academic terms (expected 1): reports and notices use the active term", true);
if ((int) $counts['demo'] > 0) warn("{$counts['demo']} demo students are loaded (remove after the defense: php tools\\remove_demo_data.php)");

// =====================================================================
section('3. Settings (.env)');
check(is_file("$root/.env"), '.env file present', '.env file is missing');
foreach (['GOOGLE_VISION_API_KEY' => 'ID scanning', 'GEMINI_API_KEY' => 'AI document checks', 'GOOGLE_CLIENT_ID' => 'Google sign-in', 'GOOGLE_CLIENT_SECRET' => 'Google sign-in',
          'SMTP_USER' => 'sending email', 'SMTP_PASSWORD' => 'sending email', 'APP_URL' => 'links in emails and previews'] as $key => $why) {
    check(trim((string) ($env[$key] ?? '')) !== '', "$key is set", "$key is empty — needed for $why", in_array($key, ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'], true));
}
// Values still copied from .env.example do not work
$example = is_file("$root/.env.example") ? (@parse_ini_file("$root/.env.example") ?: []) : [];
foreach (['GOOGLE_VISION_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_FROM'] as $key) {
    if (isset($example[$key], $env[$key]) && $env[$key] === $example[$key]) fail("$key is still the placeholder from .env.example — put the real value in .env");
}
if (preg_match('#localhost|127\.0\.0\.1#', (string) ($env['APP_URL'] ?? ''))) warn('APP_URL points to localhost — set it to the real address (e.g. https://scholarlink.college) on the live server');
if (($env['SMTP_ALLOW_SELF_SIGNED'] ?? '0') === '1') warn('SMTP_ALLOW_SELF_SIGNED=1 (fine on XAMPP; use 0 on the live server)');
if (trim((string) ($env['SMTP_USER'] ?? '')) !== '') {
    require_once "$root/includes/email_config.php";
    try {
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        configureMailer($mailer, 'ScholarLink health check');
        $mailer->Timeout = 15;
        check($mailer->smtpConnect(), 'Email: signed in to ' . $mailer->Host . ' as ' . $mailer->Username . ' (nothing sent)', 'Email: could not sign in to the mail server');
        $mailer->smtpClose();
    } catch (Throwable $e) { fail('Email: ' . $e->getMessage()); }
}

// =====================================================================
section('4. Security');
$r = http("$base/index.php");
if ($r['code'] === 0) {
    fail("Cannot reach $base ({$r['error']}) — is Apache running? Use --url=... for another address");
    goto summary;
}
foreach (['.env' => 'API keys', 'scholarlink_db.sql' => 'database dump', 'composer.json' => 'tool settings', 'includes/db_connect.php' => 'internal code',
          'tools/check_system.php' => 'command-line tools', 'migrations/' => 'migrations', 'uploads/' => 'folder listing of student documents'] as $path => $what) {
    $code = http("$base/$path")['code'];
    // 401 = the uploads gatekeeper asking for a sign-in, which is also a refusal
    check(in_array($code, [401, 403, 404], true), "$path is blocked ($code)", "$path can be opened from the web (HTTP $code) — exposes $what. Is .htaccess allowed (AllowOverride All)?");
}
foreach (glob("$root/backups/*.sql") ?: [] as $file) {
    $code = http("$base/backups/" . rawurlencode(basename($file)))['code'];
    check(in_array($code, [403, 404], true), 'Database backups are blocked', "backups/" . basename($file) . " can be downloaded (HTTP $code)");
    break;
}
$doc = $pdo->query("SELECT FilePath FROM submitted_document UNION SELECT FilePath FROM user_vault UNION SELECT ProfilePicture FROM users WHERE ProfilePicture IS NOT NULL LIMIT 1")->fetchColumn();
if ($doc) {
    $code = http($base . '/' . implode('/', array_map('rawurlencode', explode('/', preg_replace('#^(\.\./)+#', '', $doc)))))['code'];
    check(in_array($code, [401, 403, 404], true), "Uploaded documents need a sign-in ($code without one)", "An uploaded document opens without signing in (HTTP $code) — is mod_rewrite on?");
} else {
    warn('No uploaded documents to test the uploads gatekeeper with');
}
$code = http("$base/actions/fetch_live_scholars.php")['code'];
check(in_array($code, [401, 403], true), "Student data endpoints need a sign-in ($code)", "actions/fetch_live_scholars.php answers without a sign-in (HTTP $code)");

// =====================================================================
section('5. Pages');
// One account per role, with something of their own to open
$pick = fn($sql) => $pdo->query($sql)->fetchColumn() ?: null;
$student = $pick("SELECT u.UserID FROM users u JOIN application a ON a.UserID = u.UserID WHERE u.Role = 'Student' AND u.AccountStatus <> 'Archived' ORDER BY (a.Status = 'Pending') DESC, u.UserID LIMIT 1")
        ?? $pick("SELECT UserID FROM users WHERE Role = 'Student' LIMIT 1");
$internal = $pick("SELECT UserID FROM users WHERE Role = 'Internal_Admin' ORDER BY UserID LIMIT 1");
$external = $pick("SELECT u.UserID FROM users u JOIN scholarship s ON s.CreatedBy = u.UserID WHERE u.Role = 'External_Admin' ORDER BY u.UserID LIMIT 1");
$super = $pick("SELECT UserID FROM users WHERE Role = 'Super_Admin' ORDER BY UserID LIMIT 1");
$internal_sch = $pick("SELECT s.ScholarshipID FROM scholarship s LEFT JOIN users cu ON cu.UserID = s.CreatedBy WHERE cu.UserID IS NULL OR cu.Role IN ('Internal_Admin', 'Super_Admin') ORDER BY s.ScholarshipID LIMIT 1");
$external_sch = $external ? $pick("SELECT ScholarshipID FROM scholarship WHERE CreatedBy = $external ORDER BY ScholarshipID LIMIT 1") : null;
$student_app = $student ? $pick("SELECT ApplicationID FROM application WHERE UserID = $student ORDER BY (Status IN ('Pending', 'Submitted')) DESC LIMIT 1") : null;
$any_sch = $pick("SELECT ScholarshipID FROM scholarship WHERE Status = 'Active' ORDER BY ScholarshipID LIMIT 1");

$portals = [
    'Student'        => ['student', $student, ['application' => $student_app, 'scholarship' => $any_sch]],
    'Internal_Admin' => ['internal_admin', $internal, ['scholarship' => $internal_sch]],
    'External_Admin' => ['external_admin', $external, ['scholarship' => $external_sch]],
    'Super_Admin'    => ['super_admin', $super, ['scholarship' => $any_sch]],
];
$sessions = [];
foreach ($portals as $role => [$dir, $uid, $ids]) {
    if (!$uid) { warn("No $role account to test with"); continue; }
    $sessions[$role] = $sid = makeSession($role, (int) $uid);
    $ok = 0; $bad = [];
    foreach (glob("$root/$dir/*.php") as $file) {
        $page = basename($file);
        if (preg_match('/^export_|_(csv|pdf)\.php$/', $page)) continue;   // downloads: checked in "Reports"
        $q = '';
        if (in_array($page, ['form_builder.php', 'award_notice.php'], true)) $q = '?id=' . $ids['scholarship'];
        if (in_array($page, ['view_application.php', 'edit_application.php'], true)) $q = '?id=' . $ids['application'];
        if ($page === 'apply_form.php') $q = '?id=' . $ids['scholarship'] . '&scholarship_id=' . $ids['scholarship'];
        $r = http("$base/$dir/$page$q", $sid);
        $issues = pageProblems($r);
        // Redirects that are the page doing its job (e.g. nothing to edit, audit log already unlocked)
        $expected_redirect = $r['code'] === 302 && !preg_match('#login\.php#', $r['location']);
        if ($r['code'] !== 200 && !$expected_redirect) $issues[] = "HTTP {$r['code']}";
        $issues ? $bad[] = "$dir/$page$q: " . implode('; ', $issues) : $ok++;
    }
    foreach ($bad as $b) fail($b);
    if (!$bad) pass(str_replace('_', ' ', $role) . ": all $ok pages open without errors");
}
foreach (['index.php', 'student_login.php', 'admin_login.php', 'privacy.php', "scholarship_details.php?id=$any_sch"] as $page) {
    $r = http("$base/$page");
    $issues = pageProblems($r);
    if ($r['code'] !== 200) $issues[] = "HTTP {$r['code']}";
    $issues ? fail("$page: " . implode('; ', $issues)) : pass("$page opens");
}

// =====================================================================
section('6. Reports');
$admin_sid = $sessions['Super_Admin'] ?? $sessions['Internal_Admin'] ?? null;
if (!$admin_sid) {
    fail('No admin account to test reports with');
} else {
    foreach (['scholars' => 'All scholars', 'applicants' => 'All applicants', 'scholars_per_scholarship' => 'Scholars per scholarship', 'scholarships' => 'Scholarships available'] as $type => $label) {
        $r = http("$base/actions/export_report.php?type=$type&format=html", $admin_sid);
        preg_match_all('#<table class="data">(.*?)</table>#s', $r['body'], $t);
        $rows = array_sum(array_map(fn($tb) => max(0, substr_count($tb, '<tr') - 1), $t[1]));
        $issues = pageProblems($r);
        ($r['code'] === 200 && !$issues) ? pass("$label: preview has $rows row(s)") : fail("$label preview: HTTP {$r['code']} " . implode('; ', $issues));
    }
    $pdf = http("$base/actions/export_report.php?type=scholars_per_scholarship&format=pdf", $admin_sid);
    check($pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'PDF export works (' . round(strlen($pdf['body']) / 1024) . ' KB)', "PDF export failed (HTTP {$pdf['code']})");
    $xlsx = http("$base/actions/export_report.php?type=applicants&format=xlsx&download=1", $admin_sid);
    check($xlsx['code'] === 200 && str_starts_with($xlsx['body'], 'PK'), 'Excel export works (' . round(strlen($xlsx['body']) / 1024) . ' KB)', "Excel export failed (HTTP {$xlsx['code']})");
    $annex = http("$base/actions/export_annex1_excel.php", $admin_sid, ['status' => 'Approved', 'scholarship_id' => 'all', 'period' => 'all']);
    check($annex['code'] === 200 && str_starts_with($annex['body'], 'PK'), 'CHED Annex 1 export (all Tulong Dunong / TES) works', "CHED Annex 1 export failed (HTTP {$annex['code']})");
    $notice_sch = $pick("SELECT ScholarshipID FROM scholarship ORDER BY ScholarshipID LIMIT 1");
    $notice = http("$base/actions/award_notice_pdf.php?scholarship_id=$notice_sch", $sessions['Super_Admin'] ?? $admin_sid);
    check($notice['code'] === 200 && str_starts_with($notice['body'], '%PDF'), 'Approval Notice PDF works', "Approval Notice PDF failed (HTTP {$notice['code']})");
}

// =====================================================================
summary:
$secs = round(microtime(true) - $started, 1);
echo "\n", str_repeat('─', 60), "\n";
$line = "{$results['pass']} passed, {$results['warn']} warning(s), {$results['fail']} failed  ·  {$secs}s";
echo $results['fail'] ? $paint('1;31', "✗ $line") : $paint('1;32', "✓ $line"), "\n";
if ($failures) {
    echo "\nTo fix:\n";
    foreach ($failures as $f) echo "  • $f\n";
}
exit($results['fail'] ? 1 : 0);
