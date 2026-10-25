<?php
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';
require_once '../includes/id_scanner.php';

// 1. Check kung may na-receive na image mula sa frontend scanner
if (!isset($_FILES['id_image']) || $_FILES['id_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No image uploaded or upload failed.']);
    exit;
}

// 2. Basahin ang text ng ID gamit ang Google Vision
$ocr = readIdText($_FILES['id_image']['tmp_name']);
if ($ocr['error'] !== '') {
    echo json_encode(['success' => false, 'error' => $ocr['error']]);
    exit;
}

$data = parseIdData($ocr['text']);

// 3. ✨ PROGRAM IS PARSED FROM THE ID, NOT ENCODED
// The matched program is kept in the session; process_register.php reads it from there
// and ignores any program value sent by the browser.
$program = matchProgramFromText($pdo, $ocr['text']);
$data['program_id'] = $program['ProgramID'] ?? null;
$data['program_name'] = $program['ProgramName'] ?? null;

if ($program) {
    $_SESSION['scanned_program_id'] = (int)$program['ProgramID'];
}

// 4. I-bato pabalik sa frontend ang extracted data
echo json_encode([
    'success' => true,
    'data' => $data
]);
