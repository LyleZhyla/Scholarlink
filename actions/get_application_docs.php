<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/application_authority.php';
require_once '../includes/document_security.php';
require_once '../includes/evaluation_conflicts.php';

// Tanging mga Admin ang pwedeng kumuha ng application documents
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin', 'Super_Admin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$app_id = (int) ($_GET['app_id'] ?? 0);

// Every admin role is restricted to its scholarship decision authority.
$authority = getApplicationAuthority($pdo, $app_id);
if (!$authority || (!canDecideApplication($authority, $_SESSION['role'], $_SESSION['user_id']) && !canEvaluateApplication($pdo,$app_id,$_SESSION['role'],(int)$_SESSION['user_id']))) {
    http_response_code(403);
    echo json_encode(['error' => 'You are not authorized to access these documents.']);
    exit();
}

try {
    // Kukunin lang natin ang mga dokumento na nakakabit sa Application ID na ito
    $stmt = $pdo->prepare("
        SELECT sd.SubmittedDocID, dr.DocumentName AS DocumentType, sd.FilePath, sd.VerificationStatus
        FROM submitted_document sd
        JOIN document_requirement dr ON sd.RequirementID = dr.RequirementID
        WHERE sd.ApplicationID = ?
        ORDER BY dr.DocumentName ASC
    ");
    $stmt->execute([$app_id]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($docs as &$doc) $doc['FilePath'] = protectedDocumentUrl((int)$doc['SubmittedDocID']);
    unset($doc);
    
    echo json_encode($docs);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
