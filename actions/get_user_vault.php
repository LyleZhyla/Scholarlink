<?php
session_start();
require '../includes/db_connect.php';

// ✨ FIX: Allow Students AND Admins
if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Kung Admin, kunin ang ID sa URL. Kung Student, sariling ID lang.
if ($_SESSION['role'] === 'Student') {
    $user_id = $_SESSION['user_id'];
} else {
    $user_id = $_GET['user_id'] ?? 0;
}

try {
    $stmt = $pdo->prepare("SELECT VaultID, DocumentType, FilePath, UploadDate FROM user_vault WHERE UserID = ? ORDER BY UploadDate DESC");
    $stmt->execute([$user_id]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($docs);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>