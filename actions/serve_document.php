<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/application_authority.php';
require_once '../includes/document_security.php';
require_once '../includes/evaluation_conflicts.php';

if (!isset($_SESSION['logged_in'])) { http_response_code(401); exit('Authentication required.'); }
$type = $_GET['type'] ?? '';
$id = (int) ($_GET['id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$user_id = (int) ($_SESSION['user_id'] ?? 0);

try {
    if ($type === 'vault') {
        if ($role !== 'Student') throw new RuntimeException('Vault files are private to their owner.');
        $stmt = $pdo->prepare('SELECT FilePath, OriginalFileName, MimeType, FileChecksum FROM user_vault WHERE VaultID=? AND UserID=?');
        $stmt->execute([$id, $user_id]);
    } elseif ($type === 'submitted') {
        $stmt = $pdo->prepare('SELECT sd.FilePath, sd.OriginalFileName, sd.MimeType, sd.FileChecksum, a.ApplicationID, a.UserID FROM submitted_document sd JOIN application a ON a.ApplicationID=sd.ApplicationID WHERE sd.SubmittedDocID=?');
        $stmt->execute([$id]);
    } else throw new RuntimeException('Invalid document request.');
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) throw new RuntimeException('Document not found or access denied.');

    if ($type === 'submitted') {
        $allowed = $role === 'Student' && (int)$document['UserID'] === $user_id;
        if (in_array($role, ['Internal_Admin','External_Admin','Super_Admin'], true)) {
            $authority = getApplicationAuthority($pdo, (int)$document['ApplicationID']);
            $allowed = $authority && (canDecideApplication($authority, $role, $user_id) || canEvaluateApplication($pdo,(int)$document['ApplicationID'],$role,$user_id));
        }
        if (!$allowed) throw new RuntimeException('You are not authorized to access this document.');
    }

    $path = resolveProtectedDocumentPath($document['FilePath']);
    if (!empty($document['FileChecksum']) && !hash_equals($document['FileChecksum'], hash_file('sha256', $path))) {
        throw new RuntimeException('Document integrity check failed.');
    }
    $mime = $document['MimeType'] ?: (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $document['OriginalFileName'] ?: basename($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    readfile($path);
} catch (Throwable $e) {
    http_response_code(str_contains($e->getMessage(), 'authorized') || str_contains($e->getMessage(), 'private') ? 403 : 404);
    echo htmlspecialchars($e->getMessage());
}

