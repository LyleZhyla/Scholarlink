<?php
// Prevent any PHP errors from messing up the JSON output
error_reporting(0); 
header('Content-Type: application/json');

require '../includes/db_connect.php';

$major = $_GET['major'] ?? '';

try {
    // We want scholarships that are 'General' (ProgramID is NULL) 
    // OR that specifically match the ProgramName of the chosen major.
    $query = "
        SELECT s.ScholarshipID, s.Name 
        FROM scholarship s
        LEFT JOIN program p ON s.ProgramID = p.ProgramID
        WHERE s.Status = 'Active'
    ";

    if (!empty($major)) {
        $query .= " AND (p.ProgramName = :major OR s.ProgramID IS NULL)";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['major' => $major]);
    } else {
        $stmt = $pdo->query($query);
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return the data as a clean JSON list
    echo json_encode($results);

} catch (Exception $e) {
    // If something breaks, send an empty array so the JS doesn't crash
    echo json_encode([]);
}
exit();