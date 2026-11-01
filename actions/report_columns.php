<?php
// ✨ INFORMATION A REPORT CAN INCLUDE (for the "Information to include" picker in the Generate Reports window)
// GET type, scholarship_id → every column grouped like the registration form, plus the ones the format ticks by default
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';
require_once '../includes/reports.php';

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Unauthorized.']));
}

$type = isset(REPORT_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : 'scholars';
$sid = (int) ($_GET['scholarship_id'] ?? 0);
$scope = reportScholarships($pdo, $_SESSION['role'], (int) $_SESSION['user_id']);
if ($sid && !isset($scope[$sid])) {
    http_response_code(404);
    exit(json_encode(['success' => false, 'error' => 'Scholarship not found.']));
}

$pack = fn(array $keys, array $labels) => array_map(fn($k) => ['key' => $k, 'label' => $labels[$k]], array_values(array_filter($keys, fn($k) => isset($labels[$k]))));

if ($type === 'scholarships') {
    echo json_encode([
        'success' => true,
        'groups' => [['name' => 'Scholarship Details', 'columns' => $pack(array_keys(SCHOLARSHIP_REPORT_COLUMNS), SCHOLARSHIP_REPORT_COLUMNS)]],
        'defaults' => array_keys(SCHOLARSHIP_REPORT_COLUMNS),
        'format' => 'Standard',
    ]);
    exit;
}

$available = reportAvailableColumns($pdo, $sid ?: null);
$groups = [];
foreach (REPORT_COLUMN_GROUPS as $name => $keys) $groups[] = ['name' => $name, 'columns' => $pack($keys, $available)];
$profile = array_values(array_filter(array_keys($available), fn($k) => preg_match('/^p\d+$/', $k)));
if ($profile) $groups[] = ['name' => 'Additional Profile Fields', 'columns' => $pack($profile, $available)];
$questions = array_values(array_filter(array_keys($available), fn($k) => preg_match('/^q\d+$/', $k)));
if ($questions) $groups[] = ['name' => 'Application Form Answers', 'columns' => $pack($questions, $available)];

// Ticked by default: the sponsor's format for one scholarship, otherwise the standard layout
$defaults = REPORT_STANDARD_COLUMNS[$type];
$format = 'Standard';
if ($sid && ($custom = getSponsorReportFormat($pdo, $sid))) {
    $custom_keys = array_values(array_filter(array_column($custom['Columns'], 'key'), fn($k) => isset($available[$k])));
    if ($custom_keys) { $defaults = $custom_keys; $format = 'Sponsor format'; }
}

echo json_encode(['success' => true, 'groups' => $groups, 'defaults' => $defaults, 'format' => $format]);
