<?php
// ✨ SECURITY SETTINGS (Super Admin → Security)
// The switches on super_admin/security.php, and what each one enforces:
//   require_2fa      → admin logins need a 6-digit code sent to their email (admin_2fa.php)
//   strict_password  → passwords need 8+ characters, a number and a symbol (otherwise 6+ characters)
//   session_timeout  → inactive users are signed out after this many seconds (server and browser)

const SESSION_TIMEOUT_OPTIONS = [180 => '3 Minutes', 300 => '5 Minutes', 600 => '10 Minutes', 900 => '15 Minutes', 1800 => '30 Minutes'];
const DEFAULT_SESSION_TIMEOUT = 600;
const TWO_FA_CODE_LIFETIME = 300;    // seconds
const TWO_FA_MAX_ATTEMPTS = 5;

function getSecuritySettings(PDO $pdo, bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = ['require_2fa' => '0', 'strict_password' => '1', 'session_timeout' => (string) DEFAULT_SESSION_TIMEOUT];
    try {
        foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings") as $row) $cache[$row['setting_key']] = (string) $row['setting_value'];
    } catch (PDOException $e) { }
    return $cache;
}

function sessionTimeoutSeconds(PDO $pdo): int {
    $t = (int) getSecuritySettings($pdo)['session_timeout'];
    return isset(SESSION_TIMEOUT_OPTIONS[$t]) ? $t : DEFAULT_SESSION_TIMEOUT;
}

function isTwoFactorRequired(PDO $pdo, string $role): bool {
    return $role !== 'Student' && getSecuritySettings($pdo)['require_2fa'] === '1';
}

function isStrictPasswordOn(PDO $pdo): bool {
    return getSecuritySettings($pdo)['strict_password'] === '1';
}

// What the password policy asks for, in words (for forms)
function passwordPolicyText(PDO $pdo): string {
    return isStrictPasswordOn($pdo) ? 'At least 8 characters, with at least 1 number and 1 symbol.' : 'At least 6 characters.';
}

// null when the password is acceptable, otherwise the reason
function passwordPolicyError(PDO $pdo, string $password): ?string {
    if (isStrictPasswordOn($pdo)) {
        if (strlen($password) < 8) return 'Password must be at least 8 characters long.';
        if (!preg_match('/[0-9]/', $password)) return 'Password must contain at least one number.';
        if (!preg_match('/[^A-Za-z0-9]/', $password)) return 'Password must contain at least one symbol (e.g. ! @ # $ %).';
        return null;
    }
    return strlen($password) < 6 ? 'Password must be at least 6 characters long.' : null;
}

// ---------- Session timeout ----------
// Requests the browser makes by itself (live polling); they must not count as the user being active
const SESSION_PASSIVE_SCRIPTS = ['fetch_live_assigned', 'fetch_live_scholars', 'fetch_live_score', 'fetch_live_verify'];

function enforceSessionTimeout(PDO $pdo): void {
    static $done = false;
    if ($done || session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['logged_in'])) return;
    $done = true;

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');

    // An account archived (or deleted) by the Super Admin loses access at once, even in a session already open
    try {
        $st = $pdo->prepare("SELECT AccountStatus, GPA FROM users WHERE UserID = ?");
        $st->execute([$_SESSION['user_id'] ?? 0]);
        $account = $st->fetch(PDO::FETCH_ASSOC);
        $account_status = $account ? $account['AccountStatus'] : false;
    } catch (PDOException $e) { $account_status = 'Active'; $account = null; }
    if ($account_status === false || $account_status === 'Archived') {
        endSessionAndRedirect($pdo, $script, 'Signed Out (Account Archived)',
            $account_status === false ? 'The account no longer exists; its open session was ended.' : 'The account is archived; its open session was ended.',
            'Your account has been archived, so you can no longer use ScholarLink. Please contact the OSSD if you think this is a mistake.');
    }

    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > sessionTimeoutSeconds($pdo)) {
        endSessionAndRedirect($pdo, $script, 'Auto Logout',
            'User session expired after ' . intdiv(sessionTimeoutSeconds($pdo), 60) . ' minutes of inactivity.',
            'For your security, you have been automatically logged out due to inactivity.');
    }
    if (!in_array($script, SESSION_PASSIVE_SCRIPTS, true)) $_SESSION['last_activity'] = time();

    // Students without a GWA (e.g. accounts the Super Admin created) must upload their Report of Grades first
    if (($_SESSION['role'] ?? '') === 'Student' && $account && $account['GPA'] === null) {
        requireGradesFirst($script);
    }
}

// Pages and actions a student without a GWA can't use until student/complete_grades.php has read their ROG
const GWA_WALL_ACTIONS = ['process_application', 'upload_requirement', 'process_renewal', 'process_program_shift'];

function requireGradesFirst(string $script): void {
    $folder = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $walled = ($folder === 'student' && $script !== 'complete_grades') || ($folder === 'actions' && in_array($script, GWA_WALL_ACTIONS, true));
    if (!$walled) return;

    $wants_json = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    if ($wants_json || headers_sent()) {
        if (!headers_sent()) { http_response_code(403); header('Content-Type: application/json'); }
        echo json_encode(['success' => false, 'error' => 'Upload your Report of Grades first so we can record your GWA.', 'redirect' => appBaseUrl() . '/student/complete_grades.php']);
        exit();
    }
    header('Location: ' . appBaseUrl() . '/student/complete_grades.php');
    exit();
}

// Ends the session (logged in the audit trail) and sends the user to their login page with $message
function endSessionAndRedirect(PDO $pdo, string $script, string $action, string $log, string $message): void {
    $role = $_SESSION['role'] ?? '';
    try {
        $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, ?, NOW(), ?, ?)")
            ->execute([$_SESSION['user_id'] ?? null, $action, $log, $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
    } catch (PDOException $e) { }

    // Wipe the user's data but keep the session just long enough to carry the message
    session_unset();
    if (!headers_sent()) session_regenerate_id(true);
    $_SESSION['error'] = $message;
    $login_page = ($role === 'Student' ? 'student_login.php' : 'admin_login.php');
    $base = appBaseUrl();

    // Background / AJAX calls get JSON; pages and forms are sent to the login screen
    $wants_json = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || in_array($script, SESSION_PASSIVE_SCRIPTS, true)
        || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    if ($wants_json) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'session_expired' => true, 'error' => $message, 'redirect' => "$base/$login_page"]);
        exit();
    }
    echo "<script>sessionStorage.removeItem('scholarlink_chat_history'); window.location.replace('$base/$login_page');</script>";
    exit();
}

// Sign-in is refused for archived accounts (password, Google and the 2FA step all check this)
function accountArchivedMessage(array $user): ?string {
    return ($user['AccountStatus'] ?? 'Active') === 'Archived'
        ? 'This account has been archived and can no longer sign in. Please contact the OSSD if you think this is a mistake.'
        : null;
}

// ---------- Two-factor authentication (admins) ----------
// Holds the signed-in user in $_SESSION['pending_2fa'] until the emailed code is entered
function startTwoFactor(PDO $pdo, array $user, string $portal_label): bool {
    require_once __DIR__ . '/email_config.php';
    $code = (string) random_int(100000, 999999);
    $_SESSION['pending_2fa'] = [
        'user_id' => (int) $user['UserID'], 'portal' => $portal_label,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT), 'expires' => time() + TWO_FA_CODE_LIFETIME,
        'attempts' => 0, 'sent_at' => time(), 'email' => $user['Email'],
    ];
    return sendLoginCodeEmail($user['Email'], $user['FirstName'], $code);
}

// Signs the user in for real (after the password, and the code when 2FA is on)
function completeLogin(PDO $pdo, array $user, string $portal_label, string $method = ''): void {
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa'], $_SESSION['csrf_token']);   // a new security token for the new sign-in
    $_SESSION['logged_in']  = true;
    $_SESSION['user_id']    = $user['UserID'];
    $_SESSION['username']   = $user['Username'];
    $_SESSION['role']       = $user['Role'];
    $_SESSION['first_name'] = $user['FirstName'];
    $_SESSION['last_name']  = $user['LastName'];
    $_SESSION['email']      = $user['Email'];
    $_SESSION['last_activity'] = time();
    logAudit($pdo, $user['UserID'], 'Login', $user['FirstName'] . ' ' . $user['LastName'] . ' logged in as ' . $user['Role'] . ' via ' . $portal_label . $method);
}

// The site's web path (e.g. /scholarlink), wherever the current script sits
function appBaseUrl(): string {
    $root = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $app = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    return ($root !== '' && stripos($app, $root) === 0) ? substr($app, strlen($root)) : '';
}

// ✨ TYPED BACKUP CODE for password links (e.g. K7F2-9QXD): emailed next to the link, so the user can still finish on
// reset_code.php if the link's address no longer opens (e.g. this PC's IP changed). The code is derived from the
// reset token, so it lives and dies with the link: used, replaced by a newer request, or expired.
const RESET_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';   // no 0/O, 1/I/L mix-ups
const RESET_CODE_MAX_TRIES = 8;                                   // wrong codes per address per 15 minutes

function resetCodeFor(string $token): string {
    $bytes = hash_hmac('sha256', $token, 'scholarlink-reset-code', true);
    $code = '';
    for ($i = 0; $i < 8; $i++) $code .= RESET_CODE_ALPHABET[ord($bytes[$i]) % strlen(RESET_CODE_ALPHABET)];
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

// The still-valid reset token a typed code belongs to, or null
function findResetTokenByCode(PDO $pdo, string $typed): ?string {
    $typed = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $typed));
    if (strlen($typed) !== 8) return null;
    $rows = $pdo->query("SELECT ResetToken FROM users WHERE ResetToken IS NOT NULL AND ResetTokenExpire > NOW()")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $token) {
        if (hash_equals(str_replace('-', '', resetCodeFor($token)), $typed)) return $token;
    }
    return null;
}

// ✨ CSRF PROTECTION: stops other websites from submitting forms as a signed-in user.
// Every session gets one secret token. csrfHead() (called by themeHead) prints it and a small script that attaches it
// to every POST form and every same-site fetch/XHR, so pages need no changes. enforceCsrf() (called by db_connect.php
// on every request) refuses a signed-in user's POST without the right token.
function csrfToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrfHead(): void {
    static $printed = false;
    $token = csrfToken();
    if ($printed || $token === '') return;
    $printed = true;
    ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($token) ?>">
    <script>
    (function () {
        const token = document.querySelector('meta[name="csrf-token"]').content;
        const sameSite = url => { try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; } };
        // getAttribute: many forms have inputs named "action", which hide form.action
        const stamp = form => {
            if (!(form instanceof HTMLFormElement) || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
            if (!sameSite(form.getAttribute('action') || location.href)) return;
            let input = form.querySelector('input[name="csrf_token"]');
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'csrf_token'; form.appendChild(input); }
            input.value = token;
        };
        document.addEventListener('submit', e => stamp(e.target), true);
        const nativeSubmit = HTMLFormElement.prototype.submit;   // form.submit() fires no submit event
        HTMLFormElement.prototype.submit = function () { stamp(this); return nativeSubmit.call(this); };

        const nativeFetch = window.fetch;
        window.fetch = function (input, init = {}) {
            const url = typeof input === 'string' || input instanceof URL ? String(input) : (input && input.url) || '';
            const method = (init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
            if (!['GET', 'HEAD'].includes(method) && sameSite(url)) {
                const headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
                headers.set('X-CSRF-Token', token);
                init = Object.assign({}, init, { headers });
            }
            return nativeFetch.call(this, input, init);
        };
        const nativeOpen = XMLHttpRequest.prototype.open, nativeSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            this._csrf = !/^(GET|HEAD)$/i.test(method) && sameSite(url);
            return nativeOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            if (this._csrf) this.setRequestHeader('X-CSRF-Token', token);
            return nativeSend.apply(this, arguments);
        };
    })();
    </script>
    <?php
}

function enforceCsrf(PDO $pdo): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || PHP_SAPI === 'cli') return;
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['logged_in'])) return;   // nothing to act on behalf of
    // An upload over post_max_size arrives with empty $_POST/$_FILES: nothing can run, so let the page report the size
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) return;

    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected !== '' && is_string($sent) && hash_equals($expected, $sent)) return;

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    logAudit($pdo, $_SESSION['user_id'] ?? null, 'Blocked Request',
        "A submission to $script was refused: its security token was " . ($sent === '' ? 'missing' : 'wrong') . ' (possible forged request from another site)');
    $message = 'For your security, that action was blocked because the page had expired. Please reload the page and try again.';

    $wants_json = !empty($_SERVER['HTTP_X_CSRF_TOKEN']) || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || !empty($_SERVER['HTTP_SEC_FETCH_MODE']) && $_SERVER['HTTP_SEC_FETCH_MODE'] !== 'navigate';
    if ($wants_json) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit();
    }
    $_SESSION['error'] = $message;
    $back = $_SERVER['HTTP_REFERER'] ?? '';
    if (parse_url($back, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        $back = appBaseUrl() . '/' . (dashboardFor($_SESSION['role'] ?? '') ?? 'index.php');
    }
    header('Location: ' . $back);
    exit();
}

// Full address for links in emails (e.g. http://192.168.1.36/scholarlink), so they open on the recipient's device.
// A "localhost" address only works on this PC, so it is swapped for LAN_URL in .env, or else this PC's
// network (LAN) address. Any other address (the live domain, or an IP someone browsed with) is kept as is.
function appPublicUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostname = strtolower(preg_replace('/:\d+$/', '', trim($host, '[]')));
    if (!in_array($hostname, ['localhost', '127.0.0.1', '::1'], true)) {
        return $scheme . $host . appBaseUrl();
    }

    $env = @parse_ini_file(__DIR__ . '/../.env') ?: [];
    if (!empty($env['LAN_URL'])) return rtrim($env['LAN_URL'], '/');

    $port = preg_match('/:(\d+)$/', $host, $m) ? ':' . $m[1] : '';
    $ip = lanAddress();
    return $scheme . ($ip ?? $hostname) . $port . appBaseUrl();
}

// This PC's address on the local network: the one Windows uses for its default route (Wi-Fi/Ethernet),
// found by opening a UDP socket (no data is sent). Falls back to the host name lookup.
function lanAddress(): ?string {
    $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
    if ($sock) {
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $ip = $name ? preg_replace('/:\d+$/', '', $name) : '';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')) return $ip;
    }
    $ip = gethostbyname(gethostname());
    return (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')) ? $ip : null;
}

function dashboardFor(string $role): ?string {
    return ['Student' => 'student/dashboard.php', 'Internal_Admin' => 'internal_admin/dashboard.php',
            'External_Admin' => 'external_admin/dashboard.php', 'Super_Admin' => 'super_admin/dashboard.php'][$role] ?? null;
}

// Shows "a****@gmail.com" on the code screen
function maskEmail(string $email): string {
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($name, 0, 1) . str_repeat('*', max(2, mb_strlen($name) - 1)) . '@' . $domain;
}
