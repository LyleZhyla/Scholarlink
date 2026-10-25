<?php
session_start();
require '../includes/db_connect.php';

// Security Check
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Internal_Admin') {
    exit("Unauthorized access.");
}

$filename = 'ScholarLink_Shortlist_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// CSV Headers
fputcsv($output, ['Application ID', 'Student Number', 'First Name', 'Last Name', 'Program', 'Scholarship Name', 'GPA', 'Evaluator Score']);

try {
    $query = "
        SELECT 
            a.ApplicationID,
            u.StudentID_Num, 
            u.FirstName, 
            u.LastName, 
            u.Major, 
            sch.Name AS ScholarshipName, 
            a.GPA,
            a.TotalScore
        FROM application a
        JOIN users u ON a.UserID = u.UserID
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        WHERE a.Status = 'Shortlisted'
        ORDER BY a.TotalScore DESC, a.GPA ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Format the Application ID to look professional (e.g., APP-0042)
        $formatted_id = 'APP-' . str_pad($row['ApplicationID'], 4, '0', STR_PAD_LEFT);
        
        fputcsv($output, [
            $formatted_id,
            $row['StudentID_Num'],
            $row['FirstName'],
            $row['LastName'],
            $row['Major'],
            $row['ScholarshipName'],
            $row['GPA'],
            $row['TotalScore']
        ]);
    }
} catch (PDOException $e) {
    fputcsv($output, ['Error generating CSV: ' . $e->getMessage()]);
}

fclose($output);
exit();
?>