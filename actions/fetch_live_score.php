<?php
session_start();
require '../includes/db_connect.php';

// Only scorers may load applicant data
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['External_Admin', 'Super_Admin'], true)) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$search = $_GET['search'] ?? '';
$year = $_GET['year'] ?? '';
$course = $_GET['course'] ?? '';
$scholarship = $_GET['scholarship_name'] ?? '';

// ✨ FIX: Inalis na ang a.AnnualIncome at a.Essay sa query dahil wala na ito sa database!
$query = "
    SELECT a.ApplicationID, a.GPA, 
           u.FirstName, u.LastName, u.StudentID_Num, u.YearLevel, 
           sch.Name AS scholarship_name,
           (SELECT GROUP_CONCAT(CriteriaName SEPARATOR '||') 
            FROM scholarship_criteria sc 
            WHERE sc.ScholarshipID = a.ScholarshipID) AS CriteriaList
    FROM application a
    JOIN users u ON a.UserID = u.UserID
    JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
    WHERE (a.Status = 'Submitted' OR a.Status = 'Under Review' OR a.Status = 'Verified')
";

$params = [];

// ✨ Providers only see (and score) applicants of the scholarships they fund
if ($_SESSION['role'] === 'External_Admin') {
    $query .= " AND sch.CreatedBy = :provider_id";
    $params['provider_id'] = $_SESSION['user_id'];
}

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
if (!empty($scholarship)) { 
    $query .= " AND sch.Name LIKE :sch"; 
    $params['sch'] = "%$scholarship%"; 
}

$stmt = $pdo->prepare($query); 
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✨ NEW: FETCH DYNAMIC CUSTOM ANSWERS PARA SA EVALUATOR MODAL
foreach ($applications as &$app) {
    $ans_stmt = $pdo->prepare("
        SELECT f.FieldName, f.FieldType, ans.AnswerText 
        FROM application_custom_answers ans
        JOIN scholarship_custom_fields f ON ans.FieldID = f.FieldID
        WHERE ans.ApplicationID = ?
    ");
    $ans_stmt->execute([$app['ApplicationID']]);
    $app['custom_answers'] = $ans_stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode($applications);
?>