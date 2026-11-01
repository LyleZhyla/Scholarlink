<?php

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

function recordDocumentVersion(PDO $pdo, int $submitted_doc_id, int $application_id, int $requirement_id, string $path, ?string $original_name, ?int $actor_id, string $change = 'Uploaded'): void {
    $next = $pdo->prepare("SELECT COALESCE(MAX(VersionNumber), 0) + 1 FROM submitted_document_versions WHERE ApplicationID = ? AND RequirementID = ?");
    $next->execute([$application_id, $requirement_id]);
    $insert = $pdo->prepare("INSERT INTO submitted_document_versions (SubmittedDocID, ApplicationID, RequirementID, VersionNumber, FilePath, OriginalFileName, VerificationStatus, ChangeType, UploadedBy) VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, ?)");
    $insert->execute([$submitted_doc_id, $application_id, $requirement_id, (int) $next->fetchColumn(), $path, $original_name, $change, $actor_id]);
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

function saveRequestedApplicationDocuments(PDO $pdo, int $application_id, int $scholarship_id, int $user_id): void {
    $valid = $pdo->prepare("SELECT RequirementID FROM document_requirement WHERE ScholarshipID = ?");
    $valid->execute([$scholarship_id]);
    $valid_ids = array_flip(array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN)));
    $upload_dir = dirname(__DIR__) . '/uploads/documents/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);

    $save = function (int $req_id, string $source, string $original_name, bool $move) use ($pdo, $application_id, $user_id, $valid_ids, $upload_dir): void {
        if (!isset($valid_ids[$req_id])) throw new RuntimeException('Invalid document requirement.');
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) throw new RuntimeException('Only PDF, JPG, and PNG files are allowed.');
        $name = "STU-$user_id-APP-$application_id-REQ-$req_id-" . bin2hex(random_bytes(6)) . ".$ext";
        $absolute = $upload_dir . $name;
        $ok = $move ? move_uploaded_file($source, $absolute) : copy($source, $absolute);
        if (!$ok) throw new RuntimeException('The document could not be saved.');
        $path = '../uploads/documents/' . $name;

        $current = $pdo->prepare("SELECT SubmittedDocID FROM submitted_document WHERE ApplicationID = ? AND RequirementID = ?");
        $current->execute([$application_id, $req_id]);
        $doc_id = $current->fetchColumn();
        if ($doc_id) {
            $pdo->prepare("UPDATE submitted_document SET FilePath = ?, VerificationStatus = 'Pending', UploadDate = NOW() WHERE SubmittedDocID = ?")->execute([$path, $doc_id]);
            $change = 'Replaced';
        } else {
            $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')")->execute([$application_id, $req_id, $path]);
            $doc_id = (int) $pdo->lastInsertId();
            $change = 'Uploaded';
        }
        recordDocumentVersion($pdo, (int) $doc_id, $application_id, $req_id, $path, $original_name, $user_id, $change);
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
            $stmt = $pdo->prepare("SELECT FilePath FROM user_vault WHERE VaultID = ? AND UserID = ?");
            $stmt->execute([(int) $vault_id, $user_id]);
            $stored = $stmt->fetchColumn();
            if (!$stored) throw new RuntimeException('A selected vault document is unavailable.');
            $source = dirname(__DIR__) . '/' . ltrim(str_replace('..', '', $stored), '/\\');
            if (!is_file($source)) throw new RuntimeException('A selected vault document file is missing.');
            $save((int) $req_id, $source, basename($stored), false);
        }
    }
}
