<?php
// ✨ APPROVAL NOTICE ("LIST OF NEW <SCHOLARSHIP> GRANTEES")
// Laid out like the OSSD's posted grantee lists: TAU letterhead, what approved scholars must still
// submit and by when, then the grantees (SEQ, name, sex, course). Each scholarship's requirements
// are set by whoever decides its applications (OSSD for internally funded, the provider otherwise).
//   - Students get their own copy by email and in the portal once approved. For confidentiality,
//     each copy lists only that student's name, never the other grantees.
//   - Admins edit the requirements and preview the notice (with a sample student).
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

const AWARD_NOTICE_DEFAULT_INTRO = 'Visit the OSSD and submit the following:';
const AWARD_NOTICE_DEFAULT_REQUIREMENTS = [
    ['text' => 'Authenticated copy of the Certificate of Registration (COR) for the current semester', 'sub' => []],
    ['text' => 'Photocopy of TAU ID with three (3) signatures (five (5) copies)', 'sub' => []],
];

// The scholarship's notice settings, or the defaults when nobody has set them yet
// DisplayName / Semester / AcademicYear / NoticeDate left blank mean: scholarship name / active term / date generated
function getAwardNotice(PDO $pdo, int $scholarship_id): array {
    $notice = ['DisplayName' => '', 'Semester' => '', 'AcademicYear' => '', 'NoticeDate' => null,
               'BatchLabel' => '', 'Intro' => AWARD_NOTICE_DEFAULT_INTRO, 'Requirements' => AWARD_NOTICE_DEFAULT_REQUIREMENTS,
               'SubmissionDeadline' => null, 'ClosingNote' => '', 'custom' => false];
    try {
        $stmt = $pdo->prepare("SELECT * FROM scholarship_award_notice WHERE ScholarshipID = ?");
        $stmt->execute([$scholarship_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notice = [
                'DisplayName' => (string) ($row['DisplayName'] ?? ''), 'Semester' => (string) ($row['Semester'] ?? ''),
                'AcademicYear' => (string) ($row['AcademicYear'] ?? ''), 'NoticeDate' => $row['NoticeDate'] ?? null,
                'BatchLabel' => (string) $row['BatchLabel'], 'Intro' => (string) $row['Intro'],
                'Requirements' => json_decode($row['Requirements'], true) ?: [],
                'SubmissionDeadline' => $row['SubmissionDeadline'], 'ClosingNote' => (string) $row['ClosingNote'], 'custom' => true,
            ];
        }
    } catch (PDOException $e) { }   // table not migrated yet: defaults
    return $notice;
}

const AWARD_SEMESTERS = ['1st Semester', '2nd Semester', 'Midyear / Summer'];

// The active academic term as ['semester' => '1st Semester', 'ay' => '2026-2027']
function awardActiveTerm(PDO $pdo): array {
    try {
        $name = (string) $pdo->query("SELECT TermName FROM academic_terms WHERE Status = 'Active' ORDER BY TermID DESC LIMIT 1")->fetchColumn();
    } catch (PDOException $e) { $name = ''; }
    $ay = preg_match('/(\d{4})\s*[-–]\s*(\d{4})/', $name, $m) ? "{$m[1]}-{$m[2]}" : date('Y') . '-' . (date('Y') + 1);
    $semester = preg_match('/\b(1st|first)\b/i', $name) ? '1st Semester'
        : (preg_match('/\b(2nd|second)\b/i', $name) ? '2nd Semester' : (preg_match('/mid\s*-?\s*year|summer/i', $name) ? 'Midyear / Summer' : ''));
    return ['semester' => $semester, 'ay' => $ay];
}

// The name used in the notice heading
function awardDisplayName(array $scholarship, array $notice): string {
    return trim($notice['DisplayName'] ?? '') !== '' ? trim($notice['DisplayName']) : $scholarship['Name'];
}

// Cleans requirements posted by the editor: [{text, sub: [...]}], empty lines dropped
function cleanAwardRequirements($input): array {
    $out = [];
    foreach ((array) $input as $r) {
        $text = trim(mb_substr((string) ($r['text'] ?? ''), 0, 300));
        if ($text === '') continue;
        $sub = array_values(array_filter(array_map(fn($s) => trim(mb_substr((string) $s, 0, 200)), (array) ($r['sub'] ?? [])), 'strlen'));
        $out[] = ['text' => $text, 'sub' => array_slice($sub, 0, 10)];
    }
    return array_slice($out, 0, 20);
}

// "1ST SEMESTER AY 2026-2027": the notice's own semester / year, or the active term's
function awardTermLabel(PDO $pdo, array $notice = []): string {
    $active = awardActiveTerm($pdo);
    $semester = trim($notice['Semester'] ?? '') !== '' ? trim($notice['Semester']) : $active['semester'];
    $ay = trim($notice['AcademicYear'] ?? '') !== '' ? trim($notice['AcademicYear']) : $active['ay'];
    return mb_strtoupper(trim($semester . ' AY ' . $ay));
}

function awardScholarship(PDO $pdo, int $scholarship_id): ?array {
    $stmt = $pdo->prepare("
        SELECT s.ScholarshipID, s.Name, cu.Role AS CreatorRole,
               COALESCE(NULLIF(cu.Organization, ''), CONCAT(cu.FirstName, ' ', cu.LastName)) AS Provider
        FROM scholarship s LEFT JOIN users cu ON cu.UserID = s.CreatedBy WHERE s.ScholarshipID = ?");
    $stmt->execute([$scholarship_id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $s['IsInternal'] = $s['CreatorRole'] === null || in_array($s['CreatorRole'], ['Internal_Admin', 'Super_Admin'], true);
    return $s;
}

// The notice as HTML (dompdf). For confidentiality it lists only the student it is addressed to;
// $for_student is null for the admins' preview, which shows a sample row.
function awardNoticeHtml(PDO $pdo, array $scholarship, array $notice, array $rows, ?array $for_student = null): string {
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $logo_path = __DIR__ . '/../assets/img/tau_logo.png';
    $logo = is_file($logo_path) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path)) : '';
    $term = awardTermLabel($pdo, $notice);
    // "1ST" → "1<sup>ST</sup>" like the printed lists
    $term_html = preg_replace('/\b(\d+)(ST|ND|RD|TH)\b/', '$1<sup>$2</sup>', $e($term));
    $title = 'LIST OF NEW ' . mb_strtoupper(awardDisplayName($scholarship, $notice)) . ' GRANTEES' . ($notice['BatchLabel'] !== '' ? ' (' . mb_strtoupper($notice['BatchLabel']) . ')' : '');
    $notice_date = date('F j, Y', !empty($notice['NoticeDate']) ? strtotime($notice['NoticeDate']) : time());
    $deadline = $notice['SubmissionDeadline'] ? date('l, F j, Y', strtotime($notice['SubmissionDeadline'])) : '';

    ob_start(); ?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
    @page { margin: 28px 40px 40px; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 10.5pt; color: #111; }
    .band { background: #138a1f; border-bottom: 4px solid #d8d8d8; color: #fff; padding: 8px 14px; margin: 0 -40px 14px; }
    .band table { width: 100%; border-collapse: collapse; } .band td { border: 0; padding: 0; vertical-align: middle; }
    .band img { height: 74px; }
    .band .rp, .band .uni, .band .loc { color: #fff; }
    .band .rp { font-size: 11pt; font-weight: bold; } .band .uni { font-size: 21pt; font-weight: bold; letter-spacing: .5px; } .band .loc { font-size: 10pt; }
    .office { text-align: center; color: #12a83c; font-size: 15pt; font-weight: bold; margin-top: 6px; }
    .unit { text-align: center; color: #12a83c; font-size: 11pt; margin-bottom: 16px; }
    .title { text-align: center; font-weight: bold; font-size: 11pt; } .term { text-align: center; font-weight: bold; font-size: 11pt; margin-bottom: 4px; }
    .sponsor { text-align: center; font-size: 9.5pt; color: #444; margin-bottom: 6px; }
    .date { text-align: right; font-size: 10.5pt; margin-top: 12px; }
    .greet { margin: 14px 0 4px; font-size: 10.5pt; }
    ol.req { margin: 4px 0 0 18px; padding-left: 26px; } ol.req li { margin: 1px 0; }
    ul.sub { list-style: none; margin: 0 0 0 10px; padding-left: 14px; } ul.sub li:before { content: "\2713\00a0\00a0"; font-family: DejaVu Sans, sans-serif; font-size: 9pt; }
    p { margin: 10px 0; }
    table.list { width: 100%; border-collapse: collapse; margin-top: 14px; font-family: Helvetica, Arial, sans-serif; font-size: 9pt; }
    table.list th { background: #70ad47; font-weight: bold; text-align: center; padding: 4px 5px; border: 1px solid #a9d08e; }
    table.list td { padding: 3px 6px; border: 1px solid #a9d08e; vertical-align: top; text-transform: uppercase; }
    table.list tr:nth-child(even) td { background: #e2efda; }
    table.list td.seq { font-weight: bold; width: 34px; }
    .empty { text-align: center; color: #666; padding: 14px; text-transform: none; }
    .foot { margin-top: 18px; font-size: 8pt; color: #666; font-family: Helvetica, Arial, sans-serif; }
</style></head><body>
    <div class="band"><table><tr>
        <?php if ($logo): ?><td style="width: 84px;"><img src="<?= $logo ?>"></td><?php endif; ?>
        <td><div class="rp">Republic of the Philippines</div><div class="uni">TARLAC AGRICULTURAL UNIVERSITY</div><div class="loc">Camiling, Tarlac</div></td>
    </tr></table></div>

    <div class="office">OFFICE OF STUDENT SERVICES AND DEVELOPMENT</div>
    <div class="unit">FINANCIAL AND EDUCATIONAL ASSISTANCE SERVICES</div>
    <div class="title"><?= $e($title) ?></div>
    <div class="term"><?= $term_html ?></div>
    <?php if (!$scholarship['IsInternal'] && $scholarship['Provider']): ?><div class="sponsor">Sponsored by <?= $e($scholarship['Provider']) ?></div><?php endif; ?>
    <div class="date"><?= $e($notice_date) ?></div>

    <?php if ($for_student): ?>
        <p class="greet">Congratulations, <b><?= $e(trim($for_student['FirstName'] . ' ' . $for_student['LastName'])) ?></b>! Your application for the <b><?= $e(awardDisplayName($scholarship, $notice)) ?></b> has been approved, and you are included in the list of new grantees.</p>
    <?php endif; ?>

    <?php if ($notice['Requirements']): ?>
        <p style="margin-bottom: 2px;"><?= $e($notice['Intro']) ?></p>
        <ol class="req">
            <?php foreach ($notice['Requirements'] as $r): ?>
                <li><?= $e($r['text']) ?>
                    <?php if (!empty($r['sub'])): ?><ul class="sub"><?php foreach ($r['sub'] as $s): ?><li><?= $e($s) ?></li><?php endforeach; ?></ul><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <?php if ($deadline): ?><p>Last day of submission of the requirements is <b><?= $e($deadline) ?></b>.</p><?php endif; ?>
    <?php if (trim($notice['ClosingNote']) !== ''): ?><p><?= nl2br($e($notice['ClosingNote'])) ?></p><?php endif; ?>

    <table class="list">
        <thead><tr><th>SEQ.</th><th>LAST NAME</th><th>FIRST NAME</th><th>EXT.</th><th>MIDDLE NAME</th><th>SEX</th><th>COURSE/ DEGREE PROGRAM</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="seq"><?= (int) $r['Seq'] ?>.</td><td><?= $e($r['LastName']) ?></td><td><?= $e($r['FirstName']) ?></td><td><?= $e($r['ExtName']) ?></td>
                <td><?= $e($r['MiddleName']) ?></td><td><?= $e(in_array($r['Gender'], ['Male', 'Female'], true) ? $r['Gender'] : '') ?></td><td><?= $e($r['Program']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">No approved grantees yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <div class="foot">Generated by ScholarLink on <?= date('F j, Y g:i A') ?><?= $for_student ? ' · Personal copy for ' . $e($for_student['FirstName'] . ' ' . $for_student['LastName']) : '' ?></div>
</body></html>
<?php
    return ob_get_clean();
}

function awardNoticeRenderPdf(string $html): string {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    // Page numbers
    $canvas = $dompdf->getCanvas();
    $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('Helvetica'), 8, [0.4, 0.4, 0.4]);
    return $dompdf->output();
}

function awardNoticeFileName(array $scholarship, string $suffix = ''): string {
    $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $scholarship['Name']), '_');
    return "Approval_Notice_{$slug}" . ($suffix !== '' ? "_$suffix" : '') . '.pdf';
}

// The admins' preview: the notice with a sample student in the list
function awardNoticePreviewPdf(PDO $pdo, int $scholarship_id, ?array $notice = null): ?string {
    $scholarship = awardScholarship($pdo, $scholarship_id);
    if (!$scholarship) return null;
    $sample = ['Seq' => 1, 'LastName' => 'Dela Cruz', 'FirstName' => 'Juan', 'ExtName' => '', 'MiddleName' => 'Santos', 'Gender' => 'Male', 'Program' => 'BS Agriculture'];
    return awardNoticeRenderPdf(awardNoticeHtml($pdo, $scholarship, $notice ?? getAwardNotice($pdo, $scholarship_id), [$sample], ['FirstName' => 'Juan', 'LastName' => 'Dela Cruz']));
}

// One student's copy: only their own name is listed (confidentiality). null when not approved.
function awardNoticeForApplication(PDO $pdo, int $application_id): ?array {
    $stmt = $pdo->prepare("SELECT a.ApplicationID, a.ScholarshipID, a.Status, u.UserID, u.FirstName, u.LastName, u.ExtName, u.MiddleName, u.Gender, u.Email,
                                  COALESCE(p.ProgramName, u.Major) AS Program
                           FROM application a JOIN users u ON u.UserID = a.UserID LEFT JOIN program p ON p.ProgramID = u.ProgramID
                           WHERE a.ApplicationID = ?");
    $stmt->execute([$application_id]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app || $app['Status'] !== 'Approved' || !($scholarship = awardScholarship($pdo, (int) $app['ScholarshipID']))) return null;

    $notice = getAwardNotice($pdo, (int) $app['ScholarshipID']);
    $html = awardNoticeHtml($pdo, $scholarship, $notice, [['Seq' => 1] + $app], $app);
    return ['app' => $app, 'scholarship' => $scholarship, 'notice' => $notice, 'pdf' => awardNoticeRenderPdf($html),
            'filename' => awardNoticeFileName($scholarship, $app['LastName'])];
}

// Called whenever an application becomes Approved: portal notification + email with the notice attached
function notifyAwardee(PDO $pdo, int $application_id): void {
    try {
        $copy = awardNoticeForApplication($pdo, $application_id);
        if (!$copy) return;
        $app = $copy['app'];
        $name = awardDisplayName($copy['scholarship'], $copy['notice']);
        $deadline = $copy['notice']['SubmissionDeadline'] ? ' on or before ' . date('F j, Y', strtotime($copy['notice']['SubmissionDeadline'])) : '';
        $count = count($copy['notice']['Requirements']);

        $msg = "Congratulations! Your application for the $name has been officially Approved."
             . ($count ? " Please submit the $count additional requirement" . ($count > 1 ? 's' : '') . " listed in your Approval Notice$deadline." : '')
             . " Download your Approval Notice from My Applications.";
        $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, ?, ?, 'success')")
            ->execute([$app['UserID'], 'Application Approved! 🏆', $msg]);

        if (!empty($app['Email'])) {
            require_once __DIR__ . '/email_config.php';
            if (function_exists('sendAwardNoticeEmail')) {
                sendAwardNoticeEmail($app['Email'], $app['FirstName'], $name, $copy['notice'], $copy['pdf'], $copy['filename']);
            }
        }
    } catch (Throwable $e) {
        // A failed email or PDF must never undo the approval
        error_log('Approval notice failed for application ' . $application_id . ': ' . $e->getMessage());
    }
}
