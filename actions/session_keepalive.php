<?php
// ✨ KEEP-ALIVE: the browser saw the user typing / clicking, so their session counts as active.
// db_connect.php does the work: it refreshes the session, or answers {session_expired: true} when it already ran out.
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';

if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'session_expired' => true]));
}
echo json_encode(['success' => true, 'timeout' => sessionTimeoutSeconds($pdo)]);
