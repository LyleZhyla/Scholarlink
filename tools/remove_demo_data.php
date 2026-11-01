<?php
// ✨ REMOVE THE DEMO DATA (see tools/seed_demo_data.php)
// Deletes every student with an @demo.tau.edu.ph email and everything attached to them.
// Real students, applications and scholarships are never touched.
//   php tools/remove_demo_data.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db_connect.php';

// Demo audit entries (tools/seed_demo_logs.php) are listed in demo_data_rows, including the admins' ones
try {
    $n = $pdo->exec("DELETE l FROM audit_log l JOIN demo_data_rows d ON d.TableName = 'audit_log' AND d.RowID = l.AuditID");
    $pdo->exec("DROP TABLE demo_data_rows");
    echo "Demo audit entries removed: $n.\n";
} catch (PDOException $e) { }   // no demo logs

// Scholarship slots changed by tools/seed_demo_slots.php go back to their real values
try {
    $n = $pdo->exec("UPDATE scholarship s JOIN demo_scholarship_slots d ON d.ScholarshipID = s.ScholarshipID SET s.NumberOfSlots = d.OriginalSlots");
    $pdo->exec("DROP TABLE demo_scholarship_slots");
    echo "Original slots restored for $n scholarship(s).\n";
} catch (PDOException $e) { }   // slots were not changed

$demo_ids = $pdo->query("SELECT UserID FROM users WHERE Role = 'Student' AND Email LIKE '%@demo.tau.edu.ph'")->fetchAll(PDO::FETCH_COLUMN);
if (!$demo_ids) {
    echo "No demo data found.\n";
    return;
}
$in = implode(',', array_map('intval', $demo_ids));
$apps = "SELECT ApplicationID FROM application WHERE UserID IN ($in)";

$pdo->beginTransaction();
$removed = [];
foreach ([
    'scores'        => "DELETE FROM score WHERE ApplicationID IN ($apps)",
    'answers'       => "DELETE FROM application_custom_answers WHERE ApplicationID IN ($apps)",
    'documents'     => "DELETE FROM submitted_document WHERE ApplicationID IN ($apps)",
    'renewals'      => "DELETE FROM scholar_renewals WHERE UserID IN ($in)",
    'applications'  => "DELETE FROM application WHERE UserID IN ($in)",
    'vault'         => "DELETE FROM user_vault WHERE UserID IN ($in)",
    'profile'       => "DELETE FROM user_profile_answers WHERE UserID IN ($in)",
    'notifications' => "DELETE FROM notifications WHERE UserID IN ($in)",
    'audit entries' => "DELETE FROM audit_log WHERE UserID IN ($in)",
    'students'      => "DELETE FROM users WHERE UserID IN ($in)",
] as $what => $sql) {
    try { $removed[$what] = $pdo->exec($sql); } catch (PDOException $e) { $removed[$what] = 0; }   // table may not exist
}
$pdo->commit();

echo "Demo data removed: " . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($removed), $removed)) . ".\n";
