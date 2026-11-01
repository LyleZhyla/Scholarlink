<?php
// ✨ DEMO AUDIT TRAIL (to go with tools/seed_demo_data.php)
// Writes the activity the demo data implies, in the same wording the system itself uses:
//   students  - register, sign in, upload documents, submit, sign out / time out, open their Approval Notice
//   deciders  - the OSSD for university-funded grants, the provider for their own: sign in, view documents,
//               score, shortlist, approve / reject, generate reports
//   plus a few failed logins, password resets and profile updates.
// Times follow each application's submission date and never go past now.
// Every row written is recorded in demo_data_rows, so tools/remove_demo_data.php removes exactly these.
//
//   php tools/seed_demo_logs.php          (refuses if demo logs already exist)
//   php tools/seed_demo_logs.php --fresh  (replaces them)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db_connect.php';

mt_srand(20261025);
$now = time();

$pdo->exec("CREATE TABLE IF NOT EXISTS demo_data_rows (TableName VARCHAR(64) NOT NULL, RowID INT NOT NULL, PRIMARY KEY (TableName, RowID)) ENGINE=InnoDB");
$already = (int) $pdo->query("SELECT COUNT(*) FROM demo_data_rows WHERE TableName = 'audit_log'")->fetchColumn();
if ($already) {
    if (!in_array('--fresh', $argv ?? [], true)) exit("Demo logs already exist ($already entries). Use --fresh to replace them.\n");
    $pdo->exec("DELETE l FROM audit_log l JOIN demo_data_rows d ON d.TableName = 'audit_log' AND d.RowID = l.AuditID");
    $pdo->exec("DELETE FROM demo_data_rows WHERE TableName = 'audit_log'");
}

$apps = $pdo->query("
    SELECT a.ApplicationID, a.UserID, a.ScholarshipID, a.Status, a.TotalScore, UNIX_TIMESTAMP(a.DateSubmitted) AS Submitted,
           u.FirstName, u.LastName, u.StudentID_Num, s.Name AS Scholarship, s.CreatedBy, cu.Role AS CreatorRole
    FROM application a
    JOIN users u ON u.UserID = a.UserID
    JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
    LEFT JOIN users cu ON cu.UserID = s.CreatedBy
    WHERE u.Email LIKE '%@demo.tau.edu.ph'
    ORDER BY a.DateSubmitted")->fetchAll(PDO::FETCH_ASSOC);
if (!$apps) exit("No demo applications found. Run tools/seed_demo_data.php first.\n");

$admins = $pdo->query("SELECT UserID, Role, FirstName, LastName FROM users WHERE Role IN ('Internal_Admin', 'Super_Admin', 'External_Admin')")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
$ossd_id = (int) $pdo->query("SELECT UserID FROM users WHERE Role = 'Internal_Admin' ORDER BY UserID LIMIT 1")->fetchColumn();
$super_id = (int) $pdo->query("SELECT UserID FROM users WHERE Role = 'Super_Admin' ORDER BY UserID LIMIT 1")->fetchColumn();
$role_word = ['Internal_Admin' => 'Internal Admin', 'External_Admin' => 'External Admin', 'Super_Admin' => 'Super Admin'];

// ---------- Helpers ----------
$rows = [];   // [time, user, action, description, ip]
$log = function (?int $user, string $action, string $desc, int $time, string $ip) use (&$rows, $now) {
    if ($time > $now) $time = $now - mt_rand(60, 3600);
    $rows[] = [$time, $user, $action, $desc, $ip];
};
// Students on mobile data / home internet; admins on the campus network
$student_ip = fn() => [ '136.158.', '112.198.', '175.176.', '49.145.', '110.54.', '180.190.' ][mt_rand(0, 5)] . mt_rand(1, 254) . '.' . mt_rand(1, 254);
$campus_ip = fn(int $uid) => '203.177.' . (40 + $uid % 6) . '.' . (10 + $uid);
$name = fn($a) => $a['FirstName'] . ' ' . $a['LastName'];
$app_ref = fn($a) => "Application #{$a['ApplicationID']}: {$a['FirstName']} {$a['LastName']} — {$a['Scholarship']}";
$sch_ref = fn($a) => "Scholarship: {$a['Scholarship']} (#{$a['ScholarshipID']})";
$sign_out = function (int $uid, int $time, string $ip) use ($log) {
    mt_rand(0, 99) < 55
        ? $log($uid, 'Logout', 'Signed out.', $time + mt_rand(120, 1500), $ip)
        : $log($uid, 'Auto Logout', 'User session expired after 3 minutes of inactivity.', $time + mt_rand(300, 1800), $ip);
};

// ---------- Students ----------
$by_student = [];
foreach ($apps as $a) $by_student[$a['UserID']][] = $a;
foreach ($by_student as $uid => $list) {
    $first = $list[0];
    $ip = $student_ip();
    $registered = $first['Submitted'] - mt_rand(1, 14) * 86400 - mt_rand(0, 40000);
    $log(null, 'Account Created', "Student account registered: {$name($first)} ({$first['StudentID_Num']})", $registered, $ip);
    $log($uid, 'ID Scanned', 'Scanned their student ID.', $registered - mt_rand(60, 400), $ip);

    // A few forgot their password or mistyped it
    if (mt_rand(0, 99) < 6) {
        $t = $registered + mt_rand(2, 6) * 86400;
        $log(null, 'Login Failed', "Wrong password for {$first['StudentID_Num']} via Student Portal", $t, $ip);
        $log($uid, 'Password Reset Requested', 'User requested a password reset link.', $t + 120, $ip);
        $log($uid, 'Password Reset', 'User successfully changed their password via reset link.', $t + 600, $ip);
    } elseif (mt_rand(0, 99) < 8) {
        $log(null, 'Login Failed', "Wrong password for {$first['StudentID_Num']} via Student Portal", $registered + mt_rand(1, 5) * 86400, $ip);
    }
    if (mt_rand(0, 99) < 25) {
        $t = $registered + mt_rand(1, 3) * 86400;
        $log($uid, 'Login', "{$name($first)} logged in as Student via Student Portal", $t, $ip);
        $log($uid, 'Profile Updated', 'Updated their profile.', $t + mt_rand(60, 600), $ip);
        $sign_out($uid, $t + 600, $ip);
    }

    foreach ($list as $a) {
        $t = $a['Submitted'];
        $log($uid, 'Login', "{$name($a)} logged in as Student via Student Portal", $t - mt_rand(600, 1800), $ip);
        $docs = mt_rand(1, 3);
        for ($d = 0; $d < $docs; $d++) {
            $log($uid, 'Requirement Uploaded', "{$sch_ref($a)}", $t - mt_rand(60, 540), $ip);
        }
        $log($uid, 'Application Submitted', "{$sch_ref($a)}", $t, $ip);
        $sign_out($uid, $t, $ip);
    }
}

// ---------- Deciders ----------
$decider_of = function (array $a) use ($ossd_id) {
    $internal = $a['CreatorRole'] === null || in_array($a['CreatorRole'], ['Internal_Admin', 'Super_Admin'], true);
    return $internal ? $ossd_id : (int) $a['CreatedBy'];
};
$admin_days = [];   // "uid|Y-m-d" => earliest action time that day (for their Login entry)
// $time is the earliest the step can happen; it lands in office hours (8:00-17:00, Mon-Fri) at or after that
$admin_act = function (int $uid, string $action, string $desc, int $time) use ($log, $campus_ip, &$admin_days) {
    $day = strtotime(date('Y-m-d', $time));
    $earliest = max($time, $day + 8 * 3600);
    if ($earliest > $day + 17 * 3600 || date('N', $day) >= 6) {   // after hours or weekend: next working day
        do { $day = strtotime('+1 day', $day); } while (date('N', $day) >= 6);
        $earliest = $day + 8 * 3600;
    }
    $time = mt_rand($earliest, max($earliest, $day + 17 * 3600));
    // Never in the future: then it happened during the last working hours before now
    while ($time > time()) {
        $day = strtotime('-1 day', $day);
        while (date('N', $day) >= 6) $day = strtotime('-1 day', $day);
        $time = $day + mt_rand(8 * 3600, 17 * 3600);
    }
    $log($uid, $action, $desc, $time, $campus_ip($uid));
    $key = $uid . '|' . date('Y-m-d', $time);
    $admin_days[$key] = min($admin_days[$key] ?? PHP_INT_MAX, $time);
    return $time;
};

foreach ($apps as $a) {
    $who = $decider_of($a);
    $role = $role_word[$admins[$who]['Role'] ?? 'Internal_Admin'] ?? 'Admin';
    $t = $a['Submitted'] + mt_rand(1, 4) * 86400;

    if ($a['Status'] === 'Pending') {
        if (mt_rand(0, 99) < 40) $admin_act($who, 'Application Documents Viewed', "{$app_ref($a)}", $t);
        continue;
    }
    $admin_act($who, 'Application Documents Viewed', "{$app_ref($a)}", $t);
    $admin_act($who, 'Document Verified', "Evaluator marked the documents of Application #{$a['ApplicationID']} as Verified", $t + 3600);
    $scored = $admin_act($who, 'Application Scored', "{$app_ref($a)} · Score: {$a['TotalScore']}", $t + mt_rand(1, 3) * 86400);

    if ($a['Status'] === 'Rejected') {
        $admin_act($who, 'Application Rejected', "$role marked Application #{$a['ApplicationID']} as Rejected", $scored + mt_rand(1, 4) * 86400);
        continue;
    }
    $shortlisted = $admin_act($who, 'Application Status Changed', "{$app_ref($a)} · Status: Shortlisted", $scored + mt_rand(0, 2) * 86400);
    if ($a['Status'] === 'Approved') {
        $approved = $admin_act($who, 'Application Approved', "$role marked Application #{$a['ApplicationID']} as Approved", $shortlisted + mt_rand(1, 5) * 86400);
        // Most new scholars open their Approval Notice soon after
        if (mt_rand(0, 99) < 70) {
            $ip = $student_ip();
            $seen = $approved + mt_rand(1800, 3 * 86400);
            $log((int) $a['UserID'], 'Login', "{$name($a)} logged in as Student via Student Portal", $seen - 300, $ip);
            $log((int) $a['UserID'], 'Approval Notice Opened', "{$sch_ref($a)}", $seen, $ip);
            $sign_out((int) $a['UserID'], $seen, $ip);
        }
    }
}

// Reports in the last two weeks (OSSD, Super Admin and a few providers)
$report_types = ['scholars_per_scholarship', 'applicants', 'scholars', 'scholarships'];
$reporters = array_merge([$ossd_id, $ossd_id, $super_id], array_slice(array_keys(array_filter($admins, fn($r) => $r['Role'] === 'External_Admin')), 0, 4));
for ($i = 0; $i < 18; $i++) {
    $who = $reporters[mt_rand(0, count($reporters) - 1)];
    $admin_act($who, 'Report Generated', 'Type: ' . $report_types[mt_rand(0, 3)] . '', $now - mt_rand(1, 14) * 86400);
}

// One sign-in per admin per working day, just before their first action; the session then times out or is closed
foreach ($admin_days as $key => $first) {
    [$uid] = explode('|', $key);
    $uid = (int) $uid;
    $a = $admins[$uid] ?? null;
    if (!$a) continue;
    $log($uid, 'Login', "{$a['FirstName']} {$a['LastName']} logged in as {$a['Role']} via Admin Portal", $first - mt_rand(120, 900), $campus_ip($uid));
    mt_rand(0, 99) < 60
        ? $log($uid, 'Logout', 'Signed out.', strtotime(date('Y-m-d', $first)) + mt_rand(17 * 3600, 18 * 3600), $campus_ip($uid))
        : $log($uid, 'Auto Logout', 'User session expired after 3 minutes of inactivity.', strtotime(date('Y-m-d', $first)) + mt_rand(12 * 3600, 17 * 3600), $campus_ip($uid));
}

// ---------- Write ----------
usort($rows, fn($x, $y) => $x[0] <=> $y[0]);
$insert = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, ?, ?, ?, ?)");
$track = $pdo->prepare("INSERT INTO demo_data_rows (TableName, RowID) VALUES ('audit_log', ?)");
$pdo->beginTransaction();
foreach ($rows as [$time, $user, $action, $desc, $ip]) {
    $insert->execute([$user, $action, date('Y-m-d H:i:s', $time), $desc, $ip]);
    $track->execute([(int) $pdo->lastInsertId()]);
}
$pdo->commit();

$by_action = array_count_values(array_column($rows, 2));
arsort($by_action);
printf("Demo audit trail added: %d entries from %s to %s.\n", count($rows), date('M j, Y', $rows[0][0]), date('M j, Y', end($rows)[0]));
foreach ($by_action as $action => $n) printf("  %-30s %d\n", $action, $n);
