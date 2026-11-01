<?php
// Load environment variables from .env file, following the pattern in your other scripts.
$env_path = __DIR__ . '/../.env';
$google_client_id = '';
$google_client_secret = '';

if (file_exists($env_path)) {
    // Using parse_ini_file is a simple way to read .env files without extra libraries.
    $env = parse_ini_file($env_path);
    if ($env) {
        $google_client_id = $env['GOOGLE_CLIENT_ID'] ?? '';
        $google_client_secret = $env['GOOGLE_CLIENT_SECRET'] ?? '';
    }
}

// Google API configuration
// These constants will now be populated from your .env file.
define('GOOGLE_CLIENT_ID', $google_client_id);
define('GOOGLE_CLIENT_SECRET', $google_client_secret);
// Where Google sends the student back: the address ScholarLink was opened with (http://localhost/scholarlink on this PC,
// https://scholarlink.college live), unless GOOGLE_REDIRECT_URL is set in .env. Every address used must also be listed
// under "Authorized redirect URIs" for this client in Google Cloud Console, or Google shows redirect_uri_mismatch.
if (!defined('GOOGLE_REDIRECT_URL')) {
    $redirect = $env['GOOGLE_REDIRECT_URL'] ?? '';
    if ($redirect === '') {
        $doc_root = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $app_dir = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
        $base_path = ($doc_root !== '' && stripos($app_dir, $doc_root) === 0) ? substr($app_dir, strlen($doc_root)) : '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $redirect = $scheme . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base_path . '/actions/google_callback.php';
    }
    define('GOOGLE_REDIRECT_URL', $redirect);
}
?>