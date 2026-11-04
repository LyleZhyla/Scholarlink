<?php
require_once __DIR__ . '/document_security.php';

function applicationBeforeDeadline(PDO $pdo, int $application_id): bool {
    $stmt = $pdo->prepare("SELECT Deadline >= CURDATE() FROM scholarship s JOIN application a ON a.ScholarshipID = s.ScholarshipID WHERE a.ApplicationID = ?");
    $stmt->execute([$application_id]);
    return (bool) $stmt->fetchColumn();
}

function snapshotApplicationVersion(PDO $pdo, int $application_id, string $event, ?int $actor_id, ?string $notes = null): void {
    $stmt = $pdo->prepare("SELECT Status, GPA FROM application WHERE ApplicationID = ? FOR UPDATE");
    $stmt->execute([$application_id]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$application) return;

    $answers = $pdo->prepare("SELECT a.FieldID, f.FieldName, a.AnswerText FROM application_custom_answers a LEFT JOIN scholarship_custom_fields f ON f.FieldID = a.FieldID WHERE a.ApplicationID = ? ORDER BY a.FieldID");
    $answers->execute([$application_id]);
    $documents = $pdo->prepare("SELECT d.SubmittedDocID, d.RequirementID, r.DocumentName, d.FilePath, d.VerificationStatus, d.UploadDate FROM submitted_document d LEFT JOIN document_requirement r ON r.RequirementID = d.RequirementID WHERE d.ApplicationID = ? ORDER BY d.RequirementID");
    $documents->execute([$application_id]);

    $next = $pdo->prepare("SELECT COALESCE(MAX(VersionNumber), 0) + 1 FROM application_versions WHERE ApplicationID = ?");
    $next->execute([$application_id]);
    $version = (int) $next->fetchColumn();

    $insert = $pdo->prepare("INSERT INTO application_versions (ApplicationID, VersionNumber, EventType, Status, GPASnapshot, AnswersSnapshot, DocumentsSnapshot, Notes, CreatedBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insert->execute([
        $application_id, $version, $event, $application['Status'], $application['GPA'],
        json_encode($answers->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($documents->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $notes, $actor_id
    ]);
}

function recordDocumentVersion(PDO $pdo, int $submitted_doc_id, int $application_id, int $requirement_id, string $path, ?string $original_name, ?int $actor_id, string $change = 'Uploaded', ?array $metadata = null): void {
    $next = $pdo->prepare("SELECT COALESCE(MAX(VersionNumber), 0) + 1 FROM submitted_document_versions WHERE ApplicationID = ? AND RequirementID = ?");
    $next->execute([$application_id, $requirement_id]);
    $insert = $pdo->prepare("INSERT INTO submitted_document_versions (SubmittedDocID, ApplicationID, RequirementID, VersionNumber, FilePath, OriginalFileName, MimeType, FileSize, FileChecksum, MalwareScanStatus, ScannerEngine, ScannedAt, VerificationStatus, ChangeType, UploadedBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)");
    $insert->execute([$submitted_doc_id, $application_id, $requirement_id, (int) $next->fetchColumn(), $path, $original_name, $metadata['mime_type'] ?? null, $metadata['file_size'] ?? null, $metadata['checksum'] ?? null, $metadata['malware_status'] ?? 'LegacyUnchecked', $metadata['scanner_engine'] ?? null, $metadata['scanned_at'] ?? null, $change, $actor_id]);
}

function replaceApplicationAnswers(PDO $pdo, int $application_id, array $field_ids, array $answers): void {
    if ($field_ids) {
        $marks = implode(',', array_fill(0, count($field_ids), '?'));
        $pdo->prepare("DELETE FROM application_custom_answers WHERE ApplicationID = ? AND FieldID IN ($marks)")
            ->execute(array_merge([$application_id], $field_ids));
    }
    $insert = $pdo->prepare("INSERT INTO application_custom_answers (ApplicationID, FieldID, AnswerText) VALUES (?, ?, ?)");
    foreach ($answers as $field_id => $answer) $insert->execute([$application_id, $field_id, $answer]);
}

// Why this student can't apply to this scholarship, or null. The same rules as the scholarship page and the apply form,
// checked on the server so an old page or a forged form can't skip them.
function applicationBlocker(PDO $pdo, int $scholarship_id, int $user_id): ?string {
    $st = $pdo->prepare('SELECT * FROM scholarship WHERE ScholarshipID = ?');
    $st->execute([$scholarship_id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s || ($s['Status'] ?? '') !== 'Active' || strtotime((string) $s['Deadline']) < strtotime(date('Y-m-d'))) return 'This scholarship is no longer accepting applications.';

    $st = $pdo->prepare('SELECT GPA, YearLevel, Major, ProgramID, Gender, UnitsEnrolled FROM users WHERE UserID = ?');
    $st->execute([$user_id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return 'Account not found.';

    $gwa = (float) ($u['GPA'] ?? 0);
    if ((float) $s['MinimumGWA'] > 0 && ($gwa == 0.0 || $gwa > (float) $s['MinimumGWA'])) return 'Your GWA does not meet the requirement of ' . number_format((float) $s['MinimumGWA'], 2) . ' for this scholarship.';
    if (!empty($s['YearLevel']) && $s['YearLevel'] !== 'All Levels' && $s['YearLevel'] !== $u['YearLevel']) return "This scholarship is for {$s['YearLevel']} students.";
    if (!empty($s['ProgramID']) && (int) $s['ProgramID'] !== (int) $u['ProgramID']) return 'This scholarship is for a different program.';
    if (!empty($s['GenderRequirement']) && $s['GenderRequirement'] !== 'Any' && $s['GenderRequirement'] !== $u['Gender']) return 'You do not meet the requirements for this scholarship.';

    $st = $pdo->prepare('SELECT p.ProgramName FROM scholarship_programs sp JOIN program p ON p.ProgramID = sp.ProgramID WHERE sp.ScholarshipID = ?');
    $st->execute([$scholarship_id]);
    $programs = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($programs && !in_array($u['Major'], $programs, true)) return 'This scholarship is for a different program.';

    $name = strtolower($s['Name']);
    $min_units = str_contains($name, 'academic scholars - partial') ? 12 : (str_contains($name, 'academic scholars - full') ? 15 : 0);
    if ($min_units && (int) $u['UnitsEnrolled'] < $min_units) return "This scholarship requires at least $min_units enrolled units.";
    return null;
}

// The GWA verified from the student's Report of Grades: what they type on a form is never used
function verifiedStudentGwa(PDO $pdo, int $user_id) {
    $st = $pdo->prepare('SELECT GPA FROM users WHERE UserID = ?');
    $st->execute([$user_id]);
    return $st->fetchColumn() ?: null;
}

function saveRequestedApplicationDocuments(PDO $pdo, int $application_id, int $scholarship_id, int $user_id): void {
    $owner = $pdo->prepare('SELECT 1 FROM application WHERE ApplicationID=? AND ScholarshipID=? AND UserID=?');
    $owner->execute([$application_id, $scholarship_id, $user_id]);
    if (!$owner->fetchColumn()) throw new RuntimeException('You do not own this application.');
    $valid = $pdo->prepare("SELECT RequirementID FROM document_requirement WHERE ScholarshipID = ?");
    $valid->execute([$scholarship_id]);
    $valid_ids = array_flip(array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN)));
    $upload_dir = dirname(__DIR__) . '/uploads/documents/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);

    $save = function (int $req_id, string $source, string $original_name, bool $move) use ($pdo, $application_id, $user_id, $valid_ids, $upload_dir): void {
        if (!isset($valid_ids[$req_id])) throw new RuntimeException('Invalid document requirement.');
        $metadata = inspectDocumentFile($source, $original_name);
        $ext = $metadata['extension'];
        $name = "STU-$user_id-APP-$application_id-REQ-$req_id-" . bin2hex(random_bytes(6)) . ".$ext";
        $absolute = $upload_dir . $name;
        $ok = $move ? move_uploaded_file($source, $absolute) : copy($source, $absolute);
        if (!$ok) throw new RuntimeException('The document could not be saved.');
        $path = '../uploads/documents/' . $name;
        try {
            $current = $pdo->prepare("SELECT SubmittedDocID, FilePath FROM submitted_document WHERE ApplicationID = ? AND RequirementID = ?");
            $current->execute([$application_id, $req_id]);
            $existing = $current->fetch(PDO::FETCH_ASSOC);
            $doc_id = $existing['SubmittedDocID'] ?? null;
            if ($doc_id) {
                $pdo->prepare("UPDATE submitted_document SET FilePath=?, OriginalFileName=?, MimeType=?, FileSize=?, FileChecksum=?, MalwareScanStatus=?, ScannerEngine=?, ScannedAt=?, VerificationStatus='Pending', UploadDate=NOW() WHERE SubmittedDocID=?")
                    ->execute([$path,$metadata['original_name'],$metadata['mime_type'],$metadata['file_size'],$metadata['checksum'],$metadata['malware_status'],$metadata['scanner_engine'],$metadata['scanned_at'],$doc_id]);
                $change = 'Replaced';
            } else {
                $pdo->prepare("INSERT INTO submitted_document (ApplicationID,RequirementID,FilePath,OriginalFileName,MimeType,FileSize,FileChecksum,MalwareScanStatus,ScannerEngine,ScannedAt,VerificationStatus) VALUES (?,?,?,?,?,?,?,?,?,?,'Pending')")
                    ->execute([$application_id,$req_id,$path,$metadata['original_name'],$metadata['mime_type'],$metadata['file_size'],$metadata['checksum'],$metadata['malware_status'],$metadata['scanner_engine'],$metadata['scanned_at']]);
                $doc_id = (int) $pdo->lastInsertId();
                $change = 'Uploaded';
            }
            recordDocumentVersion($pdo, (int) $doc_id, $application_id, $req_id, $path, $metadata['original_name'], $user_id, $change, $metadata);
        } catch (Throwable $e) {
            @unlink($absolute);
            throw $e;
        }
    };

    if (isset($_FILES['files']['tmp_name']) && is_array($_FILES['files']['tmp_name'])) {
        foreach ($_FILES['files']['tmp_name'] as $req_id => $tmp) {
            if (($_FILES['files']['error'][$req_id] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $save((int) $req_id, $tmp, (string) $_FILES['files']['name'][$req_id], true);
            }
        }
    }

    $vault = $_POST['vault_files'] ?? ($_POST['vault_docs'] ?? []);
    if (is_array($vault)) {
        foreach ($vault as $req_id => $vault_id) {
            if (!$vault_id) continue;
            $stmt = $pdo->prepare("SELECT FilePath, OriginalFileName FROM user_vault WHERE VaultID = ? AND UserID = ?");
            $stmt->execute([(int) $vault_id, $user_id]);
            $stored = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$stored) throw new RuntimeException('A selected vault document is unavailable or is not owned by you.');
            $source = resolveProtectedDocumentPath($stored['FilePath']);
            $save((int) $req_id, $source, $stored['OriginalFileName'] ?: basename($stored['FilePath']), false);
        }
    }
}
