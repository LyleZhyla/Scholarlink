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
    $user_id = (int) ($_GET['user_id'] ?? 0);
    if ($_SESSION['role'] === 'External_Admin') {
        $own = $pdo->prepare('SELECT COUNT(*) FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.UserID = ? AND s.CreatedBy = ?');
        $own->execute([$user_id, $_SESSION['user_id']]);
        if (!$own->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit();
        }
    }
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