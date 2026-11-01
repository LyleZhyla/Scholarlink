<?php
// ✨ SAVE A SCHOLARSHIP'S APPROVAL NOTICE (requirements approved scholars must still submit)
// Only whoever decides the scholarship's applications may change it.
session_start();
header('Content-Type: application/json');
require '../includes/db_connect.php';
require_once '../includes/application_authority.php';
require_once '../includes/award_notice.php';

$reply = function (bool $ok, string $msg, int $code = 200) {
    http_response_code($code);
    exit(json_encode(['success' => $ok, $ok ? 'message' : 'error' => $msg]));
};

if (empty($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) $reply(false, 'Unauthorized.', 403);
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $reply(false, 'Invalid request.', 400);

$sid = (int) ($in['scholarship_id'] ?? 0);
$authority = getScholarshipAuthority($pdo, $sid);
if (!$authority) $reply(false, 'Scholarship not found.', 404);
if (!canDecideApplication($authority, $_SESSION['role'], $_SESSION['user_id'])) {
    $reply(false, "Only {$authority['DecidedBy']} can set the approval requirements of {$authority['ScholarshipName']}.", 403);
}

$deadline = trim((string) ($in['deadline'] ?? ''));
if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) $reply(false, 'Invalid submission deadline.', 422);
$requirements = cleanAwardRequirements($in['requirements'] ?? []);
$intro = trim(mb_substr((string) ($in['intro'] ?? ''), 0, 255)) ?: AWARD_NOTICE_DEFAULT_INTRO;
$batch = trim(mb_substr((string) ($in['batch'] ?? ''), 0, 60));
$closing = trim(mb_substr((string) ($in['closing'] ?? ''), 0, 2000));

// Heading: name shown, semester, academic year and date (blank = automatic)
$display_name = trim(mb_substr((string) ($in['display_name'] ?? ''), 0, 150));
$semester = trim((string) ($in['semester'] ?? ''));
if ($semester !== '' && !in_array($semester, AWARD_SEMESTERS, true)) $reply(false, 'Choose a semester from the list.', 422);
$academic_year = trim((string) ($in['academic_year'] ?? ''));
if ($academic_year !== '') {
    if (!preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $academic_year, $m) || (int) $m[2] !== (int) $m[1] + 1) $reply(false, 'Academic year must look like 2026-2027.', 422);
    $academic_year = "{$m[1]}-{$m[2]}";
}
$notice_date = trim((string) ($in['notice_date'] ?? ''));
if ($notice_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $notice_date)) $reply(false, 'Invalid notice date.', 422);
// Keep the scholarship's own name stored as "automatic"
if ($display_name === $authority['ScholarshipName']) $display_name = '';

$pdo->prepare("REPLACE INTO scholarship_award_notice (ScholarshipID, DisplayName, Semester, AcademicYear, NoticeDate, BatchLabel, Intro, Requirements, SubmissionDeadline, ClosingNote, UpdatedBy)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([$sid, $display_name ?: null, $semester ?: null, $academic_year ?: null, $notice_date ?: null,
               $batch ?: null, $intro, json_encode($requirements, JSON_UNESCAPED_UNICODE), $deadline ?: null, $closing ?: null, $_SESSION['user_id']]);

logAudit($pdo, $_SESSION['user_id'], 'Approval Notice Updated',
    "Approval requirements of {$authority['ScholarshipName']} (#$sid): " . count($requirements) . ' requirement(s)' . ($deadline ? ", deadline $deadline" : ''));
$reply(true, 'Approval notice saved.');
