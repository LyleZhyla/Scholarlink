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
define('GOOGLE_REDIRECT_URL', 'https://scholarlink.college/actions/google_callback.php');
?>