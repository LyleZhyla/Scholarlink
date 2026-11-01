<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php'; // records the activity in the audit trail
// This destroys the AI's memory on the server
if (isset($_SESSION['chat_history'])) {
    unset($_SESSION['chat_history']);
}
echo json_encode(['status' => 'success']);
?>