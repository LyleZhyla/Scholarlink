<?php
session_start();
require '../includes/db_connect.php';

// Security: Check for the Internal Admin role
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Internal_Admin') {
    exit("Unauthorized access.");
}

// 1. Capture the filters from the URL
$search         = $_GET['search'] ?? '';
$scholarship_id = $_GET['scholarship_id'] ?? '';
$course         = $_GET['course'] ?? '';

// Build dynamic filename
$filename = 'ScholarLink_Active_Scholars_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// CSV Header
fputcsv($output, ['Student Number', 'First Name', 'Last Name', 'Program', 'Scholarship Name', 'Amount']);

try {
    // 2. Build the Dynamic SQL Query (Must match the UI logic)
    $query = "
        SELECT 
            u.StudentID_Num, 
            u.FirstName, 
            u.LastName, 
            u.Major, 
            sch.Name AS ScholarshipName, 
            sch.AwardAmount
        FROM application a
        JOIN users u ON a.UserID = u.UserID
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        WHERE a.Status = 'Approved'
    ";
    
    $params = [];

    if (!empty($search)) {
        $query .= " AND (u.FirstName LIKE :search OR u.LastName LIKE :search OR u.StudentID_Num LIKE :search)";
        $params['search'] = "%$search%";
    }
    if (!empty($scholarship_id)) {
        $query .= " AND a.ScholarshipID = :sid";
        $params['sid'] = $scholarship_id;
    }
    if (!empty($course)) {
        $query .= " AND u.Major = :course";
        $params['course'] = $course;
    }

    $query .= " ORDER BY u.LastName ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['StudentID_Num'],
            $row['FirstName'],
            $row['LastName'],
            $row['Major'],
            $row['ScholarshipName'],
            number_format($row['AwardAmount'], 2)
        ]);
    }
} catch (PDOException $e) {
    fputcsv($output, ['Error generating CSV: ' . $e->getMessage()]);
}

fclose($output);
exit();
?>