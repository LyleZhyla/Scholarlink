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
$course = $_GET['course'] ?? '';

$query = "SELECT sd.SubmittedDocID, dr.DocumentName, u.FirstName, u.LastName, u.Major 
          FROM submitted_document sd
          JOIN document_requirement dr ON sd.RequirementID = dr.RequirementID
          JOIN application a ON sd.ApplicationID = a.ApplicationID
          JOIN users u ON a.UserID = u.UserID
          WHERE sd.VerificationStatus = 'Pending' AND a.Status = 'Under Review'";

$params = [];
if (!empty($search)) { $query .= " AND (u.FirstName LIKE :s OR u.LastName LIKE :s)"; $params['s'] = "%$search%"; }
if (!empty($course)) { $query .= " AND u.Major = :c"; $params['c'] = $course; }

$stmt = $pdo->prepare($query); $stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));