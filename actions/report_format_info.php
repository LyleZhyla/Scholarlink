<?php
// ✨ REPORT FORMAT OF ONE SCHOLARSHIP (for the Generate Reports window on the Masterlist)
// GET scholarship_id → its columns (sponsor's own or standard), every available column, Tulong Dunong?, can this admin edit it?
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';
require_once '../includes/reports.php';

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Unauthorized.']));
}

$sid = (int) ($_GET['scholarship_id'] ?? 0);
$scope = reportScholarships($pdo, $_SESSION['role'], (int) $_SESSION['user_id']);
if (!isset($scope[$sid])) {
    http_response_code(404);
    exit(json_encode(['success' => false, 'error' => 'Scholarship not found.']));
}

$authority = getScholarshipAuthority($pdo, $sid);
$available = reportAvailableColumns($pdo, $sid);
$custom = getSponsorReportFormat($pdo, $sid);
$columns = $custom
    ? array_values(array_filter($custom['Columns'], fn($c) => isset($available[$c['key']])))
    : array_map(fn($k) => ['key' => $k, 'label' => $available[$k]], REPORT_STANDARD_COLUMNS['scholars_per_scholarship']);

echo json_encode([
    'success' => true,
    'name' => $scope[$sid]['Name'],
    'provider' => $scope[$sid]['Provider'],
    'is_tulong_dunong' => $scope[$sid]['IsTulongDunong'],
    'can_edit' => $authority && canDecideApplication($authority, $_SESSION['role'], $_SESSION['user_id']),
    'owner' => $authority['DecidedBy'] ?? '',
    'custom' => (bool) $custom,
    'title' => $custom['Title'] ?? '',
    'subtitle' => $custom['Subtitle'] ?? '',
    'prepared_by' => $custom['PreparedBy'] ?? '',
    'columns' => $columns,
    'available' => $available,
]);
