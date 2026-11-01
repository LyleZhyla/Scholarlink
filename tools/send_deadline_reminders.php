<?php
// ✨ DEADLINE REMINDERS (run once a day, e.g. Windows Task Scheduler at 8:00 AM)
// Students whose application is still Pending and is missing a required document (or has one marked Rejected)
// get an email and a portal notification 7, 3 and 1 day(s) before the scholarship's deadline, each only once.
// If a day was missed, only the nearest reminder is sent (no burst of three emails).
//
//   php tools/send_deadline_reminders.php             send today's reminders
//   php tools/send_deadline_reminders.php --dry-run   list who would be reminded, send nothing
//   php tools/send_deadline_reminders.php --no-email  portal notifications only
// Demo students (@demo.tau.edu.ph) only get portal notifications, never emails.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/email_config.php';

const REMINDER_DAYS = [7, 3, 1];
$dry_run = in_array('--dry-run', $argv, true);
$no_email = in_array('--no-email', $argv, true);

$pdo->exec("CREATE TABLE IF NOT EXISTS deadline_reminders (ApplicationID INT NOT NULL, DaysBefore TINYINT NOT NULL, SentAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            Emailed TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (ApplicationID, DaysBefore),
            CONSTRAINT fk_reminder_application FOREIGN KEY (ApplicationID) REFERENCES application (ApplicationID) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$env = @parse_ini_file(__DIR__ . '/../.env') ?: [];
$portal_url = rtrim($env['APP_URL'] ?? 'http://localhost/scholarlink', '/') . '/student/applications.php';
$max_days = max(REMINDER_DAYS);

// Pending applications of scholarships closing within the next 7 days
$apps = $pdo->query("
    SELECT a.ApplicationID, a.UserID, u.FirstName, u.Email, s.ScholarshipID, s.Name AS Scholarship, s.Deadline,
           DATEDIFF(s.Deadline, CURDATE()) AS DaysLeft
    FROM application a
    JOIN users u ON u.UserID = a.UserID
    JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
    WHERE a.Status IN ('Pending', 'Submitted') AND s.Status = 'Active'
      AND DATEDIFF(s.Deadline, CURDATE()) BETWEEN 0 AND $max_days
    ORDER BY s.Deadline, a.ApplicationID")->fetchAll(PDO::FETCH_ASSOC);

$missing_stmt = $pdo->prepare("
    SELECT dr.DocumentName, sd.VerificationStatus
    FROM document_requirement dr
    LEFT JOIN submitted_document sd ON sd.RequirementID = dr.RequirementID AND sd.ApplicationID = ?
    WHERE dr.ScholarshipID = ? AND (sd.SubmittedDocID IS NULL OR sd.VerificationStatus = 'Rejected')
    ORDER BY dr.RequirementID");
$sent_stmt = $pdo->prepare("SELECT DaysBefore FROM deadline_reminders WHERE ApplicationID = ?");
$mark = $pdo->prepare("INSERT IGNORE INTO deadline_reminders (ApplicationID, DaysBefore, Emailed) VALUES (?, ?, ?)");
$notify = $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, ?, ?, 'warning')");

$totals = ['reminded' => 0, 'emailed' => 0, 'skipped_complete' => 0, 'already_sent' => 0];
foreach ($apps as $a) {
    $days_left = (int) $a['DaysLeft'];
    // The reminder due now: the tightest of 7 / 3 / 1 that the remaining days fall within
    $due = min(array_filter(REMINDER_DAYS, fn($d) => $days_left <= $d));
    $sent_stmt->execute([$a['ApplicationID']]);
    $sent = array_map('intval', $sent_stmt->fetchAll(PDO::FETCH_COLUMN));
    if (in_array($due, $sent, true)) { $totals['already_sent']++; continue; }

    $missing_stmt->execute([$a['ApplicationID'], $a['ScholarshipID']]);
    $missing = array_map(fn($m) => $m['DocumentName'] . ($m['VerificationStatus'] === 'Rejected' ? ' (rejected — upload a new copy)' : ''), $missing_stmt->fetchAll(PDO::FETCH_ASSOC));
    if (!$missing) { $totals['skipped_complete']++; continue; }

    $when = $days_left <= 0 ? 'today' : ($days_left === 1 ? 'tomorrow' : "in $days_left days");
    $is_demo = str_ends_with(strtolower((string) $a['Email']), '@demo.tau.edu.ph');
    $email_it = !$no_email && !$is_demo && filter_var($a['Email'], FILTER_VALIDATE_EMAIL);

    if ($dry_run) {
        printf("  would remind #%d %s <%s>: %s closes %s, missing %d document(s)%s\n", $a['ApplicationID'], $a['FirstName'], $a['Email'],
            $a['Scholarship'], $when, count($missing), $email_it ? '' : ' (notification only)');
        $totals['reminded']++;
        continue;
    }

    $emailed = $email_it && sendDeadlineReminderEmail($a['Email'], $a['FirstName'], $a['Scholarship'], $a['Deadline'], $days_left, $missing, $portal_url);
    $notify->execute([$a['UserID'], "⏰ {$a['Scholarship']} closes $when",
        "Your application is not complete yet. Still needed: " . implode(', ', $missing) . ". Upload them before " . date('F j, Y', strtotime($a['Deadline'])) . "."]);
    // This reminder and any earlier (longer) ones count as sent
    foreach (REMINDER_DAYS as $d) if ($d >= $due) $mark->execute([$a['ApplicationID'], $d, $d === $due && $emailed ? 1 : 0]);
    $totals['reminded']++;
    if ($emailed) $totals['emailed']++;
}

if (!$dry_run && $totals['reminded']) {
    logAudit($pdo, null, 'Deadline Reminders Sent', "{$totals['reminded']} student(s) reminded about incomplete applications ({$totals['emailed']} by email)");
}
printf("%s%d application(s) closing within %d days: %d reminded (%d by email), %d already complete, %d already reminded.\n",
    $dry_run ? '[dry run] ' : '', count($apps), $max_days, $totals['reminded'], $totals['emailed'], $totals['skipped_complete'], $totals['already_sent']);
