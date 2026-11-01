<?php
session_start();
require '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$my_id = (int) $_SESSION['user_id'];

// Release the session lock right away. Both chat windows poll this file every 3 seconds, and
// without this each request would wait for the previous one (and for process_chat.php).
session_write_close();

// 1. FETCH MESSAGES
if ($action === 'fetch') {
    $other_person_id = (int) ($_GET['other_user_id'] ?? 0); // The admin or student you are talking to

    if ($other_person_id <= 0) {
        echo json_encode([]);
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT * FROM messages
        WHERE (SenderID = :me AND ReceiverID = :them)
           OR (SenderID = :them AND ReceiverID = :me)
        ORDER BY CreatedAt ASC, MessageID ASC
    ");
    $stmt->execute(['me' => $my_id, 'them' => $other_person_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark messages as read if I am the receiver
    $update = $pdo->prepare("UPDATE messages SET IsRead = 1 WHERE SenderID = :them AND ReceiverID = :me AND IsRead = 0");
    $update->execute(['them' => $other_person_id, 'me' => $my_id]);

    echo json_encode($messages);
    exit();
}

// 2. WHICH ADMIN SHOULD A STUDENT TALK TO?
// Returns the admin from the student's most recent thread, otherwise the first Internal_Admin.
// This replaces the hardcoded "admin ID 2" in chatbot.php.
if ($action === 'get_admin') {
    $stmt = $pdo->prepare("
        SELECT u.UserID
        FROM messages m
        JOIN users u ON u.UserID = IF(m.SenderID = ?, m.ReceiverID, m.SenderID)
        WHERE (m.SenderID = ? OR m.ReceiverID = ?)
          AND u.Role = 'Internal_Admin'
        ORDER BY m.MessageID DESC
        LIMIT 1
    ");
    $stmt->execute([$my_id, $my_id, $my_id]);
    $admin_id = $stmt->fetchColumn();

    if (!$admin_id) {
        $admin_id = $pdo->query("SELECT UserID FROM users WHERE Role = 'Internal_Admin' ORDER BY UserID ASC LIMIT 1")->fetchColumn();
    }

    echo json_encode(['admin_id' => $admin_id ? (int) $admin_id : null]);
    exit();
}

// 3. UNREAD COUNTS PER SENDER (used for the live badges in messages.php)
if ($action === 'unread_counts') {
    $stmt = $pdo->prepare("SELECT SenderID, COUNT(*) FROM messages WHERE ReceiverID = ? AND IsRead = 0 GROUP BY SenderID");
    $stmt->execute([$my_id]);
    echo json_encode((object) $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    exit();
}

// 3b. THREAD SUMMARY for an admin's inbox: last message, time, unread and ISKO hand-overs per student (live list in messages.php)
if ($action === 'threads') {
    if (!in_array($_SESSION['role'] ?? '', ['Internal_Admin', 'Super_Admin'], true)) {
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    require_once '../includes/chat_threads.php';
    echo json_encode((object) adminChatThreads($pdo, $my_id));
    exit();
}

// 4. SEND MESSAGE
if ($action === 'send') {
    $receiver_id = (int) ($_POST['receiver_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    if (mb_strlen($message) > 2000) {
        echo json_encode(['error' => 'Message is too long (2,000 characters at most)']);
    } elseif ($receiver_id > 0 && $message !== '') {
        $stmt = $pdo->prepare("INSERT INTO messages (SenderID, ReceiverID, MessageText) VALUES (?, ?, ?)");
        $stmt->execute([$my_id, $receiver_id, $message]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Empty message']);
    }
    exit();
}

// 5. EDIT MESSAGE
if ($action === 'edit') {
    $message_id = $_POST['message_id'] ?? 0;
    $new_text = trim($_POST['message'] ?? '');

    if (!empty($new_text) && $message_id > 0) {
        // Security Check: Only allow the actual sender to edit their own message!
        $stmt = $pdo->prepare("UPDATE messages SET MessageText = ? WHERE MessageID = ? AND SenderID = ?");
        $stmt->execute([$new_text, $message_id, $my_id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Invalid text or message ID']);
    }
    exit();
}
?>