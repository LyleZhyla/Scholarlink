<?php
require_once __DIR__ . '/appeals.php';

$role = $_SESSION['role'] ?? '';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$where = '1=1';
$params = [];
if ($role === 'Internal_Admin') {
    $where = "(creator.Role IS NULL OR creator.Role IN ('Internal_Admin', 'Super_Admin'))";
} elseif ($role === 'External_Admin') {
    $where = 's.CreatedBy = ?';
    $params[] = $user_id;
}

$stmt = $pdo->prepare("
    SELECT ap.*, a.Status AS ApplicationStatus, s.Name AS ScholarshipName,
           u.StudentID_Num AS StudentID, u.FirstName, u.LastName,
           reviewer.FirstName AS ReviewerFirstName, reviewer.LastName AS ReviewerLastName
    FROM application_appeals ap
    JOIN application a ON a.ApplicationID = ap.ApplicationID
    JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
    JOIN users u ON u.UserID = ap.ApplicantID
    LEFT JOIN users creator ON creator.UserID = s.CreatedBy
    LEFT JOIN users reviewer ON reviewer.UserID = ap.ReviewedBy
    WHERE $where
    ORDER BY FIELD(ap.Status, 'Submitted', 'Under Review', 'Approved', 'Denied'), ap.SubmittedAt DESC
");
$stmt->execute($params);
$appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$documents = [];
if ($appeals) {
    $ids = array_map('intval', array_column($appeals, 'AppealID'));
    $doc_stmt = $pdo->query("SELECT * FROM appeal_documents WHERE AppealID IN (" . implode(',', $ids) . ") ORDER BY UploadedAt");
    foreach ($doc_stmt->fetchAll(PDO::FETCH_ASSOC) as $document) $documents[(int) $document['AppealID']][] = $document;
}
?>
<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen">
    <header class="mb-8">
        <p class="text-xs font-black text-blue-600 uppercase tracking-[0.25em]">Formal Review</p>
        <h2 class="text-3xl lg:text-4xl font-black text-slate-900 mt-2">Application Appeals</h2>
        <p class="text-slate-500 font-medium mt-2">Review supporting evidence, leave comments, and record the final resolution.</p>
    </header>

    <?php if (isset($_SESSION['success'])): ?><div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-2xl mb-6 font-bold"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?><div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-6 font-bold"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="space-y-6">
        <?php if (!$appeals): ?>
            <div class="bg-white border border-slate-200 rounded-[2rem] p-12 text-center"><div class="text-4xl mb-4">⚖️</div><h3 class="text-xl font-black text-slate-900">No appeals found</h3><p class="text-slate-500 mt-2">New appeals within your decision authority will appear here.</p></div>
        <?php endif; ?>
        <?php foreach ($appeals as $appeal): $final = in_array($appeal['Status'], ['Approved', 'Denied'], true); ?>
            <article class="bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-8 shadow-sm">
                <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4 border-b border-slate-100 pb-5">
                    <div><h3 class="text-xl font-black text-slate-900"><?= htmlspecialchars($appeal['FirstName'] . ' ' . $appeal['LastName']) ?></h3><p class="text-sm font-bold text-slate-500 mt-1"><?= htmlspecialchars($appeal['ScholarshipName']) ?> · Application #<?= (int) $appeal['ApplicationID'] ?><?= $appeal['StudentID'] ? ' · ' . htmlspecialchars($appeal['StudentID']) : '' ?></p></div>
                    <div class="lg:text-right"><span class="inline-block px-3 py-1 rounded-full border text-xs font-black uppercase <?= appealStatusClass($appeal['Status']) ?>"><?= htmlspecialchars($appeal['Status']) ?></span><p class="text-xs text-slate-400 font-bold mt-2">Filed <?= date('M d, Y g:i A', strtotime($appeal['SubmittedAt'])) ?></p></div>
                </div>
                <div class="grid lg:grid-cols-2 gap-6 py-6">
                    <div><h4 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Appeal Statement</h4><div class="bg-slate-50 rounded-xl p-4 text-sm text-slate-700 whitespace-pre-wrap"><?= htmlspecialchars($appeal['AppealReason']) ?></div></div>
                    <div><h4 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Supporting Documents</h4><div class="space-y-2"><?php foreach ($documents[(int) $appeal['AppealID']] ?? [] as $document): ?><a href="<?= htmlspecialchars(appealDocumentUrl($document['FilePath'])) ?>" target="_blank" class="block p-3 border border-slate-200 rounded-xl text-sm font-bold text-blue-600 hover:bg-blue-50">📎 <?= htmlspecialchars($document['OriginalFileName']) ?></a><?php endforeach; ?></div><p class="text-xs font-bold text-slate-400 mt-3">Appeal deadline: <?= date('M d, Y g:i A', strtotime($appeal['Deadline'])) ?></p></div>
                </div>
                <?php if ($final): ?>
                    <div class="grid lg:grid-cols-2 gap-4 border-t border-slate-100 pt-5"><div><p class="text-xs font-black text-slate-500 uppercase tracking-widest">Reviewer Comments</p><p class="text-sm text-slate-700 mt-2 whitespace-pre-wrap"><?= htmlspecialchars($appeal['ReviewerComments']) ?></p></div><div><p class="text-xs font-black text-slate-500 uppercase tracking-widest">Final Resolution</p><p class="text-sm font-bold text-slate-700 mt-2 whitespace-pre-wrap"><?= htmlspecialchars($appeal['FinalResolution']) ?></p><p class="text-xs text-slate-400 mt-2">Resolved <?= date('M d, Y g:i A', strtotime($appeal['ResolvedAt'])) ?><?= $appeal['ReviewerFirstName'] ? ' by ' . htmlspecialchars($appeal['ReviewerFirstName'] . ' ' . $appeal['ReviewerLastName']) : '' ?></p></div></div>
                <?php else: ?>
                    <form action="../actions/process_appeal.php" method="POST" class="border-t border-slate-100 pt-5 grid lg:grid-cols-2 gap-4" onsubmit="return validateAppealResolution(this);">
                        <input type="hidden" name="action" value="review"><input type="hidden" name="appeal_id" value="<?= (int) $appeal['AppealID'] ?>">
                        <div><label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Decision</label><select name="status" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl font-bold"><option value="Under Review" <?= $appeal['Status'] === 'Under Review' ? 'selected' : '' ?>>Under Review</option><option value="Approved">Approve Reconsideration</option><option value="Denied">Deny Appeal</option></select></div>
                        <div><label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Reviewer Comments *</label><textarea name="reviewer_comments" required rows="3" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl" placeholder="Explain what was reviewed and any next steps..."><?= htmlspecialchars((string) $appeal['ReviewerComments']) ?></textarea></div>
                        <div class="lg:col-span-2"><label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Final Resolution <span class="normal-case text-slate-400">(required when approving or denying)</span></label><textarea name="final_resolution" rows="3" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl" placeholder="State the final basis and outcome of this appeal..."></textarea></div>
                        <div class="lg:col-span-2 flex justify-end"><button type="submit" class="bg-slate-900 hover:bg-blue-600 text-white px-7 py-3 rounded-xl font-black text-xs uppercase tracking-widest">Save Review Decision</button></div>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</main>
<script>
function validateAppealResolution(form) {
    const status = form.querySelector('[name="status"]').value;
    const resolution = form.querySelector('[name="final_resolution"]');
    if ((status === 'Approved' || status === 'Denied') && !resolution.value.trim()) {
        alert('Enter the final resolution before approving or denying the appeal.');
        resolution.focus();
        return false;
    }
    return confirm('Save this appeal review decision?');
}
</script>
