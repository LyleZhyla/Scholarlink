<?php
// ✨ DEMO SLOTS: gives every scholarship a random number of slots from 20 to 100 (in steps of 5)
// The original values are saved in demo_scholarship_slots, and tools/remove_demo_data.php puts them back.
//   php tools/seed_demo_slots.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db_connect.php';

// Save the real values once (running this again re-randomises but keeps the first saved originals)
$pdo->exec("CREATE TABLE IF NOT EXISTS demo_scholarship_slots (ScholarshipID INT NOT NULL PRIMARY KEY, OriginalSlots INT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT IGNORE INTO demo_scholarship_slots (ScholarshipID, OriginalSlots) SELECT ScholarshipID, NumberOfSlots FROM scholarship");

$update = $pdo->prepare("UPDATE scholarship SET NumberOfSlots = ? WHERE ScholarshipID = ?");
$approved = $pdo->query("SELECT ScholarshipID, COUNT(*) FROM application WHERE Status = 'Approved' GROUP BY ScholarshipID")->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->beginTransaction();
foreach ($pdo->query("SELECT ScholarshipID, Name FROM scholarship ORDER BY ScholarshipID")->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $slots = 5 * mt_rand(4, 20);   // 20, 25, ... 100
    $slots = max($slots, (int) ($approved[$s['ScholarshipID']] ?? 0));   // never fewer than the scholars it already has
    $update->execute([$slots, $s['ScholarshipID']]);
    printf("  %-45s %3d slots\n", mb_strimwidth($s['Name'], 0, 45, '…'), $slots);
}
$pdo->commit();
echo "Slots randomised. Originals saved in demo_scholarship_slots (restored by tools/remove_demo_data.php).\n";
