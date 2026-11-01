<?php
// Student data: OSSD (Internal Admin) and Super Admin only
session_start();
require '../includes/db_connect.php';
header('Content-Type: application/json');
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['Internal_Admin', 'Super_Admin'], true)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized.']));
}

$search = $_GET['search'] ?? '';
$year = $_GET['year'] ?? '';
$course = $_GET['course'] ?? '';
$scholarship_id = $_GET['scholarship_id'] ?? '';

$query = "
    SELECT a.ApplicationID, u.FirstName, u.LastName, u.StudentID_Num, u.Major, u.YearLevel,
           sch.Name as scholarship_name, sch.AwardAmount
    FROM application a 
    JOIN users u ON a.UserID = u.UserID 
    JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID 
    WHERE a.Status = 'Approved'
";

$params = [];

if (!empty($search)) {
    $query .= " AND (u.FirstName LIKE :s OR u.LastName LIKE :s OR u.StudentID_Num LIKE :s)";
    $params['s'] = "%$search%";
}
if (!empty($year)) {
    $query .= " AND u.YearLevel = :y";
    $params['y'] = $year;
}
if (!empty($course)) {
    $query .= " AND u.Major = :c";
    $params['c'] = $course;
}
if (!empty($scholarship_id)) {
    $query .= " AND a.ScholarshipID = :sid";
    $params['sid'] = $scholarship_id;
}

$query .= " ORDER BY u.LastName ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));