<?php
session_start();
require '../includes/db_connect.php';

// Security Check
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'External_Admin') {
    exit("Unauthorized access.");
}

$scholarship_id = $_GET['scholarship_id'] ?? '';
$status         = $_GET['status'] ?? '';

$filename = 'ScholarLink_Evaluations_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// CSV Headers
fputcsv($output, ['Application ID', 'Student Number', 'First Name', 'Last Name', 'Program', 'Scholarship Name', 'Status', 'GWA', 'Evaluator Score']);

try {
    $query = "
        SELECT 
            a.ApplicationID,
            u.StudentID_Num, 
            u.FirstName, 
            u.LastName, 
            u.Major, 
            sch.Name AS ScholarshipName, 
            a.Status,
            a.GPA,
            a.TotalScore
        FROM application a
        JOIN users u ON a.UserID = u.UserID
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        WHERE 1=1
    ";
    
    $params = [];

    if (!empty($scholarship_id)) {
        $query .= " AND a.ScholarshipID = :sid";
        $params['sid'] = $scholarship_id;
    }
    if (!empty($status)) {
        $query .= " AND a.Status = :status";
        $params['status'] = $status;
    }

    $query .= " ORDER BY sch.Name ASC, a.TotalScore DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Add APP- prefix to ID for cleaner reading in Excel
        $row['ApplicationID'] = 'APP-' . str_pad($row['ApplicationID'], 4, '0', STR_PAD_LEFT);
        fputcsv($output, $row);
    }
} catch (PDOException $e) {
    fputcsv($output, ['Error generating report: ' . $e->getMessage()]);
}

fclose($output);
exit();
?>