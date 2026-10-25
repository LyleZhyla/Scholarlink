<?php
session_start();
// This destroys the AI's memory on the server
if (isset($_SESSION['chat_history'])) {
    unset($_SESSION['chat_history']);
}
echo json_encode(['status' => 'success']);
?>