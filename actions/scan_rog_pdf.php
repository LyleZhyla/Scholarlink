<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php'; // records the activity in the audit trail
// Suppress HTML error output so JSON never breaks
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// Catch fatal errors (like memory limits or missing extensions) that even Throwable might miss
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'error' => 'PHP Fatal Error: ' . $error['message'] . ' on line ' . $error['line']
        ]);
    }
});

require_once __DIR__ . '/../includes/rog_scanner.php';

try {
    // Validate File Upload & Catch PHP INI Size Limits
    if (!isset($_FILES['rog_pdf'])) {
        throw new Exception('No file received by server. The PDF might exceed your server post_max_size limit.');
    }

    if ($_FILES['rog_pdf']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded PDF exceeds the server upload_max_filesize limit in php.ini (default is 2MB).',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded PDF exceeds the MAX_FILE_SIZE directive.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        $errCode = $_FILES['rog_pdf']['error'];
        throw new Exception($uploadErrors[$errCode] ?? 'Unknown upload error (Code: ' . $errCode . ').');
    }

    $fileTmpPath = $_FILES['rog_pdf']['tmp_name'];
    $rawBinary = file_get_contents($fileTmpPath);

    if ($rawBinary === false || empty($rawBinary)) {
        throw new Exception('Could not read uploaded PDF file.');
    }

    // Tampering checks and the AI reading live in includes/rog_scanner.php
    $result = scanReportOfGrades($rawBinary);

    // Remember the verified GWA on the server: registration saves this one, never a GWA sent by the browser
    $gwa = is_numeric($result['computed_gwa'] ?? null) ? round((float) $result['computed_gwa'], 2) : null;
    if (!empty($result['success']) && !empty($result['is_valid']) && $gwa !== null && $gwa >= 1 && $gwa <= 5) {
        $_SESSION['rog_verified'] = ['gwa' => $gwa, 'units' => (int) ($result['total_units'] ?? 0), 'at' => time()];
    } else {
        unset($_SESSION['rog_verified']);
    }
    echo json_encode($result);

} catch (Throwable $e) {
    // Throwable catches BOTH Exceptions AND PHP 7/8 Fatal Errors!
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>