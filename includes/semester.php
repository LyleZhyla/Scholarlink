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
        $next = $pdo->prepare("SELECT e.EmailID, r.RenewalID, u.Email, u.FirstName, a.ScholarshipID, s.Name AS ScholarshipName FROM semester_emails e
            JOIN scholar_renewals r ON r.RenewalID = e.RenewalID JOIN users u ON u.UserID = r.UserID
            JOIN application a ON a.ApplicationID = r.ApplicationID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
            WHERE e.TermID = ? AND e.Status = 'Queued' ORDER BY e.EmailID LIMIT " . max(1, min(25, $limit)));
        $next->execute([$term_id]);
        $mark = $pdo->prepare('UPDATE semester_emails SET Status = ?, SentAt = NOW() WHERE EmailID = ?');
        $link = appPublicUrl() . '/student/renewal.php';
        foreach ($next->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (empty($row['Email'])) { $mark->execute(['No Email', $row['EmailID']]); continue; }
            $ok = sendSemesterRequirementsEmail($row['Email'], $row['FirstName'], termDisplayName($term), $row['ScholarshipName'],
                renewalRequirementsFor($pdo, $term_id, (int) $row['ScholarshipID']), $term['RenewalDeadline'], $link);
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
