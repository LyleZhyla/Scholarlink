<?php
// ✨ SEMESTERS
// Starting a semester (OSSD / Super Admin): the old one ends, every student must upload their COR for the new one
// before using ScholarLink (the wall, includes/security_settings.php → student/semester_cor.php, checked by
// includes/cor_scanner.php), and every current scholar gets a renewal with the semester's requirements (the
// semester's list + their scholarship's own), emailed to them. An approved renewal adds that semester's benefit.
// Approved scholars stay Approved: the renewal is what tracks the new semester.

// Who sets a scholarship's own renewal requirements: whoever manages that scholarship; the Super Admin for any
function canSetRenewalRequirements(PDO $pdo, int $scholarship_id, string $role, int $user_id): bool {
    if ($role === 'Super_Admin') return true;
    require_once __DIR__ . '/application_authority.php';
    require_once __DIR__ . '/approval_workflow.php';
    return canManageScholarshipWorkflow($pdo, $scholarship_id, $role, $user_id);
}

const SEMESTER_NAMES = ['1st Semester', '2nd Semester', 'Midyear', 'Summer'];
// The Report of Grades is always asked of scholars (its GWA is read by the AI); listed with the others as requirement 0
const RENEWAL_ROG = ['RequirementID' => 0, 'Name' => 'Report of Grades (ROG) of last semester', 'Description' => 'Your grades are read automatically to check your GWA.', 'Source' => 'Always'];

function activeTerm(PDO $pdo): ?array {
    try {
        $t = $pdo->query("SELECT * FROM academic_terms WHERE Status = 'Active' ORDER BY TermID DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $t ?: null;
    } catch (PDOException $e) { return null; }
}

// The semester as students read it: "1st Semester AY 2026-2027"
function termDisplayName(array $term): string {
    return !empty($term['Semester']) && !empty($term['SchoolYear']) ? "{$term['Semester']} AY {$term['SchoolYear']}" : (string) $term['TermName'];
}

function studentTermCor(PDO $pdo, int $user_id, int $term_id): ?array {
    $st = $pdo->prepare('SELECT * FROM student_term_cor WHERE UserID = ? AND TermID = ?');
    $st->execute([$user_id, $term_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Whether the student must still upload a COR for the active semester (semesters started before this feature, which
// recorded no school year, do not ask). Checked on every page, so a COR the OSSD rejects closes the wall at once.
function studentNeedsCor(PDO $pdo, int $user_id): bool {
    $term = activeTerm($pdo);
    if (!$term || empty($term['SchoolYear'])) return false;
    try { $cor = studentTermCor($pdo, $user_id, (int) $term['TermID']); } catch (PDOException $e) { return false; }
    return !$cor || $cor['AIStatus'] === 'Rejected';
}

// A scholar's renewal requirements: the Report of Grades, the semester's list, then their scholarship's own list
function renewalRequirementsFor(PDO $pdo, int $term_id, int $scholarship_id): array {
    $st = $pdo->prepare("SELECT RequirementID, Name, Description, IF(TermID IS NULL, 'Scholarship', 'Semester') AS Source FROM renewal_requirements
        WHERE IsActive = 1 AND ((TermID = ? AND ScholarshipID IS NULL) OR (ScholarshipID = ? AND TermID IS NULL)) ORDER BY TermID IS NULL, RequirementID");
    $st->execute([$term_id, $scholarship_id]);
    return array_merge([RENEWAL_ROG], $st->fetchAll(PDO::FETCH_ASSOC));
}

// The admin declares the new semester. Returns ['term_id' => .., 'scholars' => .., 'name' => ..]
function startNewSemester(PDO $pdo, int $admin_id, string $semester, string $school_year, ?string $deadline, array $extra_requirements): array {
    if (!in_array($semester, SEMESTER_NAMES, true)) throw new RuntimeException('Choose the semester.');
    if (!preg_match('/^(\d{4})-(\d{4})$/', $school_year, $m) || (int) $m[2] !== (int) $m[1] + 1) throw new RuntimeException('Write the school year like 2026-2027.');
    if ($deadline !== null && $deadline !== '' && (strtotime($deadline) === false || strtotime($deadline) < strtotime(date('Y-m-d')))) throw new RuntimeException('The renewal deadline must be today or later.');
    $deadline = $deadline ?: null;
    $name = "$semester AY $school_year";
    $dup = $pdo->prepare('SELECT COUNT(*) FROM academic_terms WHERE Semester = ? AND SchoolYear = ?');
    $dup->execute([$semester, $school_year]);
    if ($dup->fetchColumn()) throw new RuntimeException("$name was already started. Pick the next semester.");

    $pdo->beginTransaction();
    try {
        $pdo->query("UPDATE academic_terms SET Status = 'Ended', EndDate = CURRENT_DATE() WHERE Status = 'Active'");
        $pdo->prepare("INSERT INTO academic_terms (TermName, Semester, SchoolYear, Status, StartDate, RenewalDeadline, StartedBy) VALUES (?, ?, ?, 'Active', CURRENT_DATE(), ?, ?)")
            ->execute([$name, $semester, $school_year, $deadline, $admin_id]);
        $term_id = (int) $pdo->lastInsertId();

        $add = $pdo->prepare('INSERT INTO renewal_requirements (TermID, Name, Description, CreatedBy) VALUES (?, ?, ?, ?)');
        foreach ($extra_requirements as $r) {
            $n = trim((string) ($r['name'] ?? ''));
            if ($n === '') continue;
            $add->execute([$term_id, mb_substr($n, 0, 150), mb_substr(trim((string) ($r['description'] ?? '')), 0, 500) ?: null, $admin_id]);
        }

        // Every current scholar renews (they stay Approved meanwhile), and is told so
        $scholars = $pdo->query("SELECT a.ApplicationID, a.UserID, s.Name FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.Status = 'Approved'")->fetchAll(PDO::FETCH_ASSOC);
        $renew = $pdo->prepare("INSERT INTO scholar_renewals (ApplicationID, UserID, TermID, Status, DateCreated) VALUES (?, ?, ?, 'Pending_Submission', NOW())");
        $queue = $pdo->prepare("INSERT IGNORE INTO semester_emails (TermID, RenewalID) VALUES (?, ?)");
        $notify = $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, ?, ?, 'warning')");
        foreach ($scholars as $s) {
            $renew->execute([$s['ApplicationID'], $s['UserID'], $term_id]);
            $queue->execute([$term_id, (int) $pdo->lastInsertId()]);
            $notify->execute([$s['UserID'], "Renew your scholarship for $name",
                "$name has started. Upload your COR, then submit your renewal requirements for {$s['Name']}" . ($deadline ? ' by ' . date('F j, Y', strtotime($deadline)) : '') . '. Open Renewal from your dashboard.']);
        }
        logAudit($pdo, $admin_id, 'Semester Started', "Started $name: " . count($scholars) . ' scholar(s) to renew' . ($deadline ? ", due $deadline" : '') . '; every student must upload their COR.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['term_id' => $term_id, 'scholars' => count($scholars), 'name' => $name];
}

// Sends the next few queued new-semester emails; returns ['sent' => n, 'failed' => n, 'left' => n]
function sendSemesterEmails(PDO $pdo, int $term_id, int $limit = 8): array {
    require_once __DIR__ . '/email_config.php';
    $term = $pdo->prepare('SELECT * FROM academic_terms WHERE TermID = ?');
    $term->execute([$term_id]);
    $term = $term->fetch(PDO::FETCH_ASSOC);
    $sent = $failed = 0;
    if ($term) {
        $next = $pdo->prepare("SELECT e.EmailID, r.RenewalID, u.Email, u.FirstName, u.LastName, a.ScholarshipID, s.Name AS ScholarshipName FROM semester_emails e
            JOIN scholar_renewals r ON r.RenewalID = e.RenewalID JOIN users u ON u.UserID = r.UserID
            JOIN application a ON a.ApplicationID = r.ApplicationID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
            WHERE e.TermID = ? AND e.Status = 'Queued' ORDER BY e.EmailID LIMIT " . max(1, min(25, $limit)));
        $next->execute([$term_id]);
        $mark = $pdo->prepare('UPDATE semester_emails SET Status = ?, SentAt = NOW() WHERE EmailID = ?');
        $link = appPublicUrl() . '/student/renewal.php';
        foreach ($next->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (empty($row['Email'])) { $mark->execute(['No Email', $row['EmailID']]); continue; }
            // the student's own Renewal Notice goes with it (the email is still sent if the PDF cannot be made)
            try { $pdf = renewalNoticePdf($pdo, $term, $row); } catch (Throwable $err) { $pdf = null; error_log('Renewal notice PDF: ' . $err->getMessage()); }
            $ok = sendSemesterRequirementsEmail($row['Email'], $row['FirstName'], termDisplayName($term), $row['ScholarshipName'],
                renewalNoticeItems($pdo, $term, $row), $term['RenewalDeadline'], $link, $pdf, renewalNoticeFileName($term, $row));
            $mark->execute([$ok ? 'Sent' : 'Failed', $row['EmailID']]);
            $ok ? $sent++ : $failed++;
        }
    }
    $left = $pdo->prepare("SELECT COUNT(*) FROM semester_emails WHERE TermID = ? AND Status = 'Queued'");
    $left->execute([$term_id]);
    return ['sent' => $sent, 'failed' => $failed, 'left' => (int) $left->fetchColumn()];
}

// An approved renewal adds that semester's benefit (the scholarship's award amount) to the scholar's balance, once
function addSemesterBenefit(PDO $pdo, int $renewal_id, int $admin_id): ?float {
    require_once __DIR__ . '/benefit_releases.php';
    $st = $pdo->prepare("SELECT r.ApplicationID, r.TermID, s.AwardAmount, t.TermName FROM scholar_renewals r JOIN application a ON a.ApplicationID = r.ApplicationID
        JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID JOIN academic_terms t ON t.TermID = r.TermID WHERE r.RenewalID = ?");
    $st->execute([$renewal_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || (float) $r['AwardAmount'] <= 0) return null;
    ensureBenefitAccount($pdo, (int) $r['ApplicationID'], $admin_id);   // the scholar's balance exists (first semester's amount)
    $add = $pdo->prepare('INSERT IGNORE INTO scholar_term_benefits (ApplicationID, TermID, Amount, AddedBy) VALUES (?, ?, ?, ?)');
    $add->execute([$r['ApplicationID'], $r['TermID'], $r['AwardAmount'], $admin_id]);
    if ($add->rowCount() === 0) return null;   // already added for this semester
    $pdo->prepare('UPDATE scholar_benefit_accounts SET TotalEntitlement = TotalEntitlement + ?, UpdatedAt = NOW() WHERE ApplicationID = ?')
        ->execute([$r['AwardAmount'], $r['ApplicationID']]);
    logAudit($pdo, $admin_id, 'Semester Benefit Added', 'Added ' . number_format((float) $r['AwardAmount'], 2) . " for {$r['TermName']} to application #{$r['ApplicationID']} (renewal approved).");
    return (float) $r['AwardAmount'];
}

// ✨ RENEWAL NOTICE: a scholar's semester renewal as a letter on the TAU letterhead (like the Approval Notice):
// what to submit for this semester and by when. Attached to the new-semester email; downloadable from Renewal.
// $r: a scholar_renewals row joined with the scholarship (ScholarshipID, ScholarshipName) and the student (FirstName, LastName)
function renewalNoticeItems(PDO $pdo, array $term, array $r): array {
    $items = [['Name' => 'Certificate of Registration (COR) for ' . termDisplayName($term), 'Description' => 'Uploaded when you sign in to ScholarLink; it is checked automatically.']];
    foreach (renewalRequirementsFor($pdo, (int) $term['TermID'], (int) $r['ScholarshipID']) as $q) $items[] = $q;
    return $items;
}

function renewalNoticePdf(PDO $pdo, array $term, array $r): string {
    require_once __DIR__ . '/award_notice.php';   // the letterhead's PDF maker (dompdf)
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $logo_path = __DIR__ . '/../assets/img/tau_logo.png';
    $logo = is_file($logo_path) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path)) : '';
    $term_html = preg_replace('/\b(\d+)(st|nd|rd|th)\b/i', '$1<sup>$2</sup>', $e(mb_strtoupper(termDisplayName($term))));
    $deadline = !empty($term['RenewalDeadline']) ? date('l, F j, Y', strtotime($term['RenewalDeadline'])) : '';
    $items = '';
    foreach (renewalNoticeItems($pdo, $term, $r) as $q) $items .= '<li>' . $e($q['Name']) . (!empty($q['Description']) ? '<div class="note">' . $e($q['Description']) . '</div>' : '') . '</li>';
    $name = trim(($r['FirstName'] ?? '') . ' ' . ($r['LastName'] ?? ''));
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { margin: 28px 40px 40px; }
        body { font-family: "Times New Roman", Times, serif; font-size: 11pt; color: #111; }
        .band { background: #138a1f; border-bottom: 4px solid #d8d8d8; color: #fff; padding: 8px 14px; margin: 0 -40px 14px; }
        .band table { width: 100%; border-collapse: collapse; } .band td { border: 0; padding: 0; vertical-align: middle; } .band img { height: 74px; }
        .rp { font-size: 11pt; font-weight: bold; } .uni { font-size: 21pt; font-weight: bold; letter-spacing: .5px; } .loc { font-size: 10pt; }
        .office { text-align: center; color: #12a83c; font-size: 15pt; font-weight: bold; margin-top: 6px; }
        .unit { text-align: center; color: #12a83c; font-size: 11pt; margin-bottom: 16px; }
        .title { text-align: center; font-weight: bold; font-size: 12pt; } .term { text-align: center; font-weight: bold; font-size: 11pt; }
        .date { text-align: right; margin-top: 12px; } p { margin: 10px 0; line-height: 1.45; }
        ol { margin: 6px 0 0 18px; padding-left: 22px; } ol li { margin: 6px 0; } .note { font-size: 9.5pt; color: #555; }
        .box { border: 1px solid #a9d08e; background: #f3f8ef; padding: 10px 14px; margin-top: 14px; }
        .foot { margin-top: 24px; font-size: 8pt; color: #666; font-family: Helvetica, Arial, sans-serif; }
    </style></head><body>
        <div class="band"><table><tr>' . ($logo ? '<td style="width: 84px;"><img src="' . $logo . '"></td>' : '') . '
            <td><div class="rp">Republic of the Philippines</div><div class="uni">TARLAC AGRICULTURAL UNIVERSITY</div><div class="loc">Camiling, Tarlac</div></td></tr></table></div>
        <div class="office">OFFICE OF STUDENT SERVICES AND DEVELOPMENT</div>
        <div class="unit">FINANCIAL AND EDUCATIONAL ASSISTANCE SERVICES</div>
        <div class="title">SEMESTER RENEWAL NOTICE</div>
        <div class="term">' . $term_html . '</div>
        <div class="date">' . $e(date('F j, Y')) . '</div>
        <p>Dear <b>' . $e($name ?: 'Scholar') . '</b>,</p>
        <p><b>' . $e(termDisplayName($term)) . '</b> has started. To keep your <b>' . $e($r['ScholarshipName'] ?? '') . '</b> this semester, submit the following in ScholarLink (open <b>Renewal</b> after you sign in):</p>
        <ol>' . $items . '</ol>
        ' . ($deadline ? '<p>Last day of submission of the requirements is <b>' . $e($deadline) . '</b>.</p>' : '') . '
        <div class="box">Your benefits for this semester are released once your renewal is approved by the OSSD.</div>
        <p style="margin-top: 22px;">Thank you, and keep up the good work.</p>
        <p><b>Office of Student Services and Development</b><br>Tarlac Agricultural University</p>
        <div class="foot">Generated by ScholarLink on ' . date('F j, Y g:i A') . ' · Personal copy for ' . $e($name) . '</div>
    </body></html>';
    return awardNoticeRenderPdf($html);
}

function renewalNoticeFileName(array $term, array $r): string {
    return 'Renewal_Notice_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', termDisplayName($term)), '_') . '_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', $r['ScholarshipName'] ?? ''), '_') . '.pdf';
}
