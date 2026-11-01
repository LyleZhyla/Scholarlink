<?php
// ✨ CHAT THREAD SUMMARY for an admin's inbox (internal_admin/messages.php and chat_handler.php?action=threads):
// one row per person the admin has messaged with: last message, its time and sender, unread count, and whether the
// student is waiting on a hand-over from ISKO (an "ISKO Auto-Escalate" message after the admin's last reply).
if (!function_exists('adminChatThreads')) {

    define('ISKO_ESCALATION_MARK', 'ISKO Auto-Escalate');   // prefix includes/chatbot.php puts on hand-overs

    function adminChatThreads(PDO $pdo, int $admin_id): array {
        $stmt = $pdo->prepare("
            SELECT t.other_id, m.MessageText AS last_text, m.CreatedAt AS last_at, m.SenderID AS last_sender,
                   t.unread, (t.last_escalation > t.last_reply) AS escalated
            FROM (
                SELECT IF(SenderID = :me, ReceiverID, SenderID) AS other_id,
                       MAX(MessageID) AS last_id,
                       SUM(ReceiverID = :me AND IsRead = 0) AS unread,
                       MAX(IF(SenderID = :me, MessageID, 0)) AS last_reply,
                       MAX(IF(SenderID <> :me AND MessageText LIKE :mark, MessageID, 0)) AS last_escalation
                FROM messages
                WHERE SenderID = :me OR ReceiverID = :me
                GROUP BY other_id
            ) t
            JOIN messages m ON m.MessageID = t.last_id");
        $stmt->execute(['me' => $admin_id, 'mark' => '%' . ISKO_ESCALATION_MARK . '%']);

        $threads = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $threads[(int) $row['other_id']] = [
                'last_text'   => mb_strimwidth((string) $row['last_text'], 0, 120, '…'),
                'last_at'     => $row['last_at'],
                'last_is_me'  => (int) $row['last_sender'] === $admin_id,
                'unread'      => (int) $row['unread'],
                'escalated'   => (bool) $row['escalated'],
            ];
        }
        return $threads;
    }
}
