<?php
session_start();
include '../includes/session_manager.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') { header('Location: ../student_login.php'); exit(); }
include '../includes/db_connect.php';
require_once '../includes/appeals.php';

$application_id = (int) ($_GET['application_id'] ?? 0);
$stmt = $pdo->prepare("SELECT a.ApplicationID, a.Status AS ApplicationStatus, a.AppealDeadline, s.Name AS ScholarshipName, ap.* FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID LEFT JOIN application_appeals ap ON ap.ApplicationID = a.ApplicationID WHERE a.ApplicationID = ? AND a.UserID = ?");
$stmt->execute([$application_id, $_SESSION['user_id']]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$record) { header('Location: applications.php'); exit(); }
$documents = [];
if (!empty($record['AppealID'])) {
    $docs = $pdo->prepare("SELECT * FROM appeal_documents WHERE AppealID = ? ORDER BY UploadedAt");
    $docs->execute([$record['AppealID']]);
    $documents = $docs->fetchAll(PDO::FETCH_ASSOC);
}
$can_file = empty($record['AppealID']) && in_array($record['ApplicationStatus'], ['Rejected', 'Disqualified'], true)
    && !empty($record['AppealDeadline']) && strtotime($record['AppealDeadline']) >= time();
include '../includes/header.php';
include '../includes/student_sidebar.php';
?>
<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen">
    <header class="max-w-4xl mb-8 flex items-center gap-4">
        <a href="applications.php" class="w-12 h-12 bg-white border border-slate-200 rounded-xl flex items-center justify-center font-black text-slate-500">←</a>
        <div><h2 class="text-3xl lg:text-4xl font-black text-slate-900">Application Appeal</h2><p class="text-slate-500 font-medium mt-1"><?= htmlspecialchars($record['ScholarshipName']) ?> · Application #<?= $application_id ?></p></div>
    </header>
    <?php if (isset($_SESSION['success'])): ?><div class="max-w-4xl bg-green-50 border border-green-200 text-green-700 p-4 rounded-2xl mb-6 font-bold"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?><div class="max-w-4xl bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl mb-6 font-bold"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <?php if (!empty($record['AppealID'])): ?>
        <section class="max-w-4xl bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-10 shadow-sm space-y-7">
            <div class="flex flex-wrap justify-between gap-4"><div><p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Appeal Status</p><span class="inline-block mt-2 px-3 py-1 rounded-full border text-xs font-black uppercase <?= appealStatusClass($record['Status']) ?>"><?= htmlspecialchars($record['Status']) ?></span></div><div class="text-right"><p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Submitted</p><p class="font-bold text-slate-700 mt-2"><?= date('M d, Y g:i A', strtotime($record['SubmittedAt'])) ?></p></div></div>
            <div><h3 class="font-black text-slate-900 mb-2">Your Appeal Statement</h3><div class="p-4 bg-slate-50 rounded-xl whitespace-pre-wrap text-sm text-slate-700"><?= htmlspecialchars($record['AppealReason']) ?></div></div>
            <div><h3 class="font-black text-slate-900 mb-2">Supporting Documents</h3><div class="grid sm:grid-cols-2 gap-3"><?php foreach ($documents as $doc): ?><a target="_blank" href="<?= htmlspecialchars(appealDocumentUrl($doc['FilePath'])) ?>" class="p-4 border border-slate-200 rounded-xl text-sm font-bold text-blue-600 hover:bg-blue-50">📎 <?= htmlspecialchars($doc['OriginalFileName']) ?></a><?php endforeach; ?></div></div>
            <?php if ($record['ReviewerComments']): ?><div><h3 class="font-black text-slate-900 mb-2">Reviewer Comments</h3><div class="p-4 bg-amber-50 border border-amber-100 rounded-xl whitespace-pre-wrap text-sm text-slate-700"><?= htmlspecialchars($record['ReviewerComments']) ?></div></div><?php endif; ?>
            <?php if ($record['FinalResolution']): ?><div><h3 class="font-black text-slate-900 mb-2">Final Resolution</h3><div class="p-4 <?= $record['Status'] === 'Approved' ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' ?> border rounded-xl whitespace-pre-wrap text-sm font-bold text-slate-700"><?= htmlspecialchars($record['FinalResolution']) ?></div><p class="text-xs text-slate-400 font-bold mt-2">Resolved <?= $record['ResolvedAt'] ? date('M d, Y g:i A', strtotime($record['ResolvedAt'])) : '' ?></p></div><?php endif; ?>
        </section>
    <?php elseif ($can_file): ?>
        <form action="../actions/process_appeal.php" method="POST" enctype="multipart/form-data" class="max-w-4xl bg-white border border-slate-200 rounded-[2rem] p-6 lg:p-10 shadow-sm space-y-7">
            <input type="hidden" name="action" value="submit"><input type="hidden" name="application_id" value="<?= $application_id ?>">
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5"><p class="font-black text-amber-900">Appeal deadline</p><p class="text-sm font-bold text-amber-700 mt-1"><?= date('l, F j, Y g:i A', strtotime($record['AppealDeadline'])) ?></p></div>
            <div><label class="block text-xs font-black text-slate-600 uppercase tracking-widest mb-2">Appeal or Reconsideration Statement *</label><textarea name="appeal_reason" rows="8" minlength="20" required placeholder="Explain why the decision should be reconsidered and identify the evidence you are submitting..." class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500"></textarea><p class="text-xs text-slate-400 mt-2">Provide specific facts. Reviewer comments and the final resolution will appear on this page.</p></div>
            <div><label class="block text-xs font-black text-slate-600 uppercase tracking-widest mb-2">Supporting Documents *</label><input type="file" name="supporting_documents[]" accept=".pdf,.jpg,.jpeg,.png" multiple required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl text-sm"><p class="text-xs text-slate-400 mt-2">PDF, JPG, or PNG · maximum 5 MB per file.</p></div>
            <div class="flex justify-end"><button type="submit" onclick="return confirm('Submit this formal appeal? You can file only one appeal for this application.');" class="bg-slate-900 text-white px-8 py-4 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-blue-600">Submit Formal Appeal</button></div>
        </form>
    <?php else: ?>
        <div class="max-w-4xl bg-white border border-slate-200 rounded-[2rem] p-10 text-center"><h3 class="text-xl font-black text-slate-900">Appeal unavailable</h3><p class="text-slate-500 mt-2"><?= empty($record['AppealDeadline']) ? 'This application does not currently have an appeal window.' : 'The appeal deadline has passed.' ?></p></div>
    <?php endif; ?>
</main>
<?php include '../includes/footer.php'; ?>
