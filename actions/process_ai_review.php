<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/application_authority.php';
require_once '../includes/application_lifecycle.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['Internal_Admin','External_Admin','Super_Admin'], true)) {
    header('Location: ../admin_login.php'); exit;
}

$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];
$analysisId = (int) ($_POST['analysis_id'] ?? 0);
$decision = $_POST['decision'] ?? '';
$reason = trim((string) ($_POST['reason'] ?? ''));
$allowed = ['Verify Document','Request Correction','Inconclusive'];
$base = $role === 'Internal_Admin' ? '../internal_admin/' : ($role === 'External_Admin' ? '../external_admin/' : '../super_admin/');

try {
    if (!in_array($decision, $allowed, true)) throw new RuntimeException('Select a valid manual decision.');
    if (mb_strlen($reason) < 10) throw new RuntimeException('A specific manual-review reason of at least 10 characters is required.');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT ai.*, a.UserID, s.Name ScholarshipName FROM ai_document_analyses ai JOIN application a ON a.ApplicationID=ai.ApplicationID JOIN scholarship s ON s.ScholarshipID=a.ScholarshipID WHERE ai.AnalysisID=? FOR UPDATE');
    $stmt->execute([$analysisId]);
    $analysis = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$analysis) throw new RuntimeException('AI analysis record not found.');
    if (!in_array($analysis['ReviewStatus'], ['Pending', 'Inconclusive'], true)) {
        throw new RuntimeException('This AI analysis already has a final manual decision.');
    }
    $authority = getApplicationAuthority($pdo, (int) $analysis['ApplicationID']);
    if (!$authority || !canDecideApplication($authority, $role, $userId)) throw new RuntimeException('You are not authorized to review this scholarship analysis.');

    $reviewStatus = $decision === 'Inconclusive' ? 'Inconclusive' : (($analysis['AuthenticitySuggestion'] === 'Potential Issue' && $decision === 'Verify Document') || ($analysis['AuthenticitySuggestion'] === 'Likely Authentic' && $decision === 'Request Correction') ? 'Overridden' : 'Confirmed');
    $pdo->prepare('UPDATE ai_document_analyses SET ReviewStatus=?, ManualDecision=?, OverrideReason=?, ReviewedBy=?, ReviewedAt=NOW() WHERE AnalysisID=?')->execute([$reviewStatus,$decision,$reason,$userId,$analysisId]);
    if ($decision === 'Verify Document') {
        $pdo->prepare("UPDATE submitted_document SET VerificationStatus='Verified' WHERE SubmittedDocID=?")->execute([$analysis['SubmittedDocID']]);
    } elseif ($decision === 'Request Correction') {
        $pdo->prepare("UPDATE submitted_document SET VerificationStatus='Rejected' WHERE SubmittedDocID=?")->execute([$analysis['SubmittedDocID']]);
        $pdo->prepare("UPDATE application SET Status='For Resubmission', ResubmissionAllowed=1, LastSavedAt=NOW() WHERE ApplicationID=? AND Status NOT IN ('Approved','Rejected','Withdrawn')")->execute([$analysis['ApplicationID']]);
        snapshotApplicationVersion($pdo, (int)$analysis['ApplicationID'], 'Manual Correction Requested', $userId, $reason);
        $pdo->prepare("INSERT INTO notifications (UserID,Title,Message,Type) VALUES (?, 'Manual Document Review', ?, 'warning')")->execute([$analysis['UserID'], "An authorized reviewer requested a correction for {$analysis['SourceDocument']} in {$analysis['ScholarshipName']}. Reason: $reason"]);
    }
    logAudit($pdo,$userId,'AI Suggestion Manually Reviewed',"Analysis #$analysisId: $decision. Reason: $reason");
    $pdo->commit();
    $_SESSION['success'] = 'Manual review recorded. The AI output remained a suggestion and the authorized decision was audited.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
}
header('Location: '.$base.'ai_reviews.php');

