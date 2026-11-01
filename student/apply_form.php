<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') { 
    header("Location: ../student_login.php"); exit(); 
}
include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/student_sidebar.php'; 

$user_id = $_SESSION['user_id'];
$scholarship_id = $_GET['id'] ?? 1;

// 1. Fetch Scholarship Details & Requirements
$stmt = $pdo->prepare("SELECT * FROM scholarship WHERE ScholarshipID = ?");
$stmt->execute([$scholarship_id]);
$scholarship = $stmt->fetch();

// 2. Security Check (Block if restricted)
$user_stmt = $pdo->prepare("SELECT * FROM users WHERE UserID = ?");
$user_stmt->execute([$user_id]);
$student = $user_stmt->fetch();

if (($scholarship['ProgramID'] && $scholarship['ProgramID'] != $student['ProgramID']) || 
    ($scholarship['YearLevel'] && $scholarship['YearLevel'] != $student['YearLevel']) ||
    (isset($scholarship['GenderRequirement']) && $scholarship['GenderRequirement'] !== 'Any' && $scholarship['GenderRequirement'] !== $student['Gender'])) {
    echo "<script>alert('You do not meet the core demographic requirements for this scholarship.'); window.location.href='programs.php';</script>";
    exit();
}

$req_stmt = $pdo->prepare("SELECT * FROM document_requirement WHERE ScholarshipID = ?");
$req_stmt->execute([$scholarship_id]);
$requirements = $req_stmt->fetchAll();

// ✨ 3. FETCH THE SCHOLARSHIP'S APPLICATION FORM (designed in the Form Builder)
require_once '../includes/form_fields.php';
$customFields = getFormFields($pdo, $scholarship_id);

// Continue the student's saved draft instead of starting another application.
$draft_stmt = $pdo->prepare("SELECT ApplicationID, GPA, LastSavedAt FROM application WHERE UserID = ? AND ScholarshipID = ? AND Status = 'Draft' ORDER BY ApplicationID DESC LIMIT 1");
$draft_stmt->execute([$user_id, $scholarship_id]);
$draftApplication = $draft_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
$existingAnswers = [];
$draftDocuments = [];
if ($draftApplication) {
    $answer_stmt = $pdo->prepare("SELECT FieldID, AnswerText FROM application_custom_answers WHERE ApplicationID = ?");
    $answer_stmt->execute([$draftApplication['ApplicationID']]);
    $existingAnswers = $answer_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $document_stmt = $pdo->prepare("SELECT RequirementID, FilePath FROM submitted_document WHERE ApplicationID = ?");
    $document_stmt->execute([$draftApplication['ApplicationID']]);
    $draftDocuments = $document_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ✨ 4. FETCH STUDENT'S VAULT DOCUMENTS (needed for the "From Vault" picker)
$vault_stmt = $pdo->prepare("SELECT VaultID, DocumentType, FilePath, UploadDate FROM user_vault WHERE UserID = ? ORDER BY DocumentType ASC");
$vault_stmt->execute([$user_id]);
$vaultDocs = $vault_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-300">
    <header class="mb-8 lg:mb-10 flex items-center gap-4 lg:gap-6">
        <a href="programs.php" class="w-10 h-10 lg:w-14 lg:h-14 shrink-0 bg-white border border-slate-200 rounded-xl lg:rounded-[1.25rem] flex items-center justify-center font-black text-slate-400 hover:text-slate-900 transition-all">←</a>
        <div>
            <h2 class="text-2xl lg:text-4xl font-black text-slate-900 tracking-tight leading-tight">Apply for <?= htmlspecialchars($scholarship['Name']) ?></h2>
            <p class="text-slate-500 font-medium text-xs lg:text-sm mt-1">Complete the dynamic form and upload your requirements.</p>
        </div>
    </header>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="max-w-4xl bg-red-50 border border-red-200 text-red-700 px-6 py-4 rounded-2xl mb-6 text-sm font-bold">
            ⚠️ <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="max-w-4xl bg-green-50 border border-green-200 text-green-700 px-6 py-4 rounded-2xl mb-6 text-sm font-bold">
            ✅ <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if ($draftApplication): ?>
        <div class="max-w-4xl bg-amber-50 border border-amber-200 text-amber-800 px-6 py-4 rounded-2xl mb-6 text-sm font-bold">
            Draft restored<?= $draftApplication['LastSavedAt'] ? ' · last saved ' . date('M d, Y g:i A', strtotime($draftApplication['LastSavedAt'])) : '' ?>.
        </div>
    <?php endif; ?>

    <form action="../actions/process_crud.php" method="POST" id="scholarshipApplyForm" enctype="multipart/form-data" class="bg-white p-6 lg:p-10 rounded-[1.5rem] lg:rounded-[2rem] border border-slate-200 shadow-sm max-w-4xl space-y-8 lg:space-y-10">
        <input type="hidden" name="module" value="student_apply">
        <input type="hidden" name="scholarship_id" value="<?= $scholarship_id ?>">
        <input type="hidden" name="application_id" value="<?= (int) ($draftApplication['ApplicationID'] ?? 0) ?>">
        
        <div class="space-y-4">
            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Academic Profile</h3>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Declared GWA / GPA</label>
                <input type="number" step="0.01" name="gpa" id="modalGpa" value="<?= htmlspecialchars($draftApplication['GPA'] ?? $student['GPA'] ?? '') ?>" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl font-bold outline-none focus:border-blue-500 transition-colors">
            </div>
        </div>

        <?php if (!empty($customFields) || !empty($scholarship['FormInstructions'])): ?>
        <div class="space-y-6">
            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Application Questions</h3>
            <?= renderApplicationForm($pdo, $customFields, $student, $existingAnswers, $scholarship['FormInstructions'] ?? null) ?>
        </div>
        <?php endif; ?>

        <div class="space-y-4">
            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2">Required Documents</h3>
            <?php foreach ($requirements as $req): ?>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 border border-slate-200 rounded-xl bg-slate-50 gap-4">
                    <div class="flex-1">
                        <p class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($req['DocumentName']) ?></p>
                        <p id="status_<?= $req['RequirementID'] ?>" class="text-[10px] font-black <?= isset($draftDocuments[$req['RequirementID']]) ? 'text-green-600' : 'text-slate-400' ?> uppercase tracking-widest mt-1"><?= isset($draftDocuments[$req['RequirementID']]) ? 'Saved in draft: ' . htmlspecialchars(basename($draftDocuments[$req['RequirementID']])) : 'Pending Upload' ?></p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" onclick="openVaultPicker(<?= $req['RequirementID'] ?>)" class="bg-blue-100 text-blue-700 px-4 py-2 rounded-lg text-xs font-black uppercase tracking-widest hover:bg-blue-200">From Vault</button>
                        <input type="file" name="files[<?= $req['RequirementID'] ?>]" id="file_<?= $req['RequirementID'] ?>" class="text-xs font-bold text-slate-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-black file:bg-slate-200 file:text-slate-700 hover:file:bg-slate-300">
                        <input type="hidden" name="vault_files[<?= $req['RequirementID'] ?>]" id="vault_input_<?= $req['RequirementID'] ?>">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="flex flex-col-reverse sm:flex-row justify-end gap-3 pt-4">
            <button type="submit" name="action" value="save_draft" formnovalidate class="w-full sm:w-auto bg-white border border-slate-300 text-slate-700 px-8 py-4 rounded-xl font-black text-sm uppercase tracking-widest hover:bg-slate-100 transition-all">Save Draft</button>
            <button type="submit" name="action" value="<?= $draftApplication ? 'submit_draft' : 'create' ?>" class="w-full sm:w-auto bg-slate-900 text-white px-10 py-4 rounded-xl font-black text-sm uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg">Submit Application</button>
        </div>
    </form>
</main>

<!-- ✨ VAULT PICKER MODAL -->
<div id="vaultPickerModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md max-h-[80vh] overflow-y-auto">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-black text-slate-900 text-lg">Select from Vault</h3>
            <button type="button" onclick="closeVaultPicker()" class="text-slate-400 hover:text-slate-900 font-black text-xl leading-none">&times;</button>
        </div>
        <div id="vaultPickerList" class="p-4 space-y-2">
            <?php if (empty($vaultDocs)): ?>
                <p class="text-sm text-slate-400 font-bold p-4 text-center">Your vault is empty. Upload documents in the Document Vault first.</p>
            <?php else: ?>
                <?php foreach ($vaultDocs as $vd): ?>
                    <button type="button"
                        class="vaultPickerItem w-full text-left p-4 rounded-xl border border-slate-200 hover:border-blue-500 hover:bg-blue-50 transition-colors"
                        data-vault-id="<?= (int)$vd['VaultID'] ?>"
                        data-doc-type="<?= htmlspecialchars($vd['DocumentType']) ?>">
                        <p class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($vd['DocumentType']) ?></p>
                        <p class="text-[10px] text-slate-400 font-bold mt-1"><?= htmlspecialchars(basename($vd['FilePath'])) ?></p>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
let currentVaultRequirementId = null;

function openVaultPicker(requirementId) {
    currentVaultRequirementId = requirementId;
    document.getElementById('vaultPickerModal').classList.remove('hidden');
}

function closeVaultPicker() {
    document.getElementById('vaultPickerModal').classList.add('hidden');
    currentVaultRequirementId = null;
}

document.querySelectorAll('.vaultPickerItem').forEach(function (item) {
    item.addEventListener('click', function () {
        if (currentVaultRequirementId === null) return;

        const vaultId = this.getAttribute('data-vault-id');
        const docType = this.getAttribute('data-doc-type');

        // Fill the hidden input the backend actually reads
        document.getElementById('vault_input_' + currentVaultRequirementId).value = vaultId;

        // Update the status label so the student sees it registered
        const statusEl = document.getElementById('status_' + currentVaultRequirementId);
        if (statusEl) {
            statusEl.textContent = 'From Vault: ' + docType;
            statusEl.classList.remove('text-slate-400');
            statusEl.classList.add('text-blue-600');
        }

        // A vault file was chosen, so clear any direct-upload file for this requirement
        const fileInput = document.getElementById('file_' + currentVaultRequirementId);
        if (fileInput) fileInput.value = '';

        closeVaultPicker();
    });
});

// Required checkbox questions: at least one box must be ticked (browsers can't require a checkbox group)
document.getElementById('scholarshipApplyForm').addEventListener('submit', function (e) {
    if (e.submitter && e.submitter.value === 'save_draft') return;
    const missing = [...this.querySelectorAll('[data-required-group]')].find(group => !group.querySelector('input:checked'));
    if (missing) {
        e.preventDefault();
        alert('Please select at least one option for: ' + missing.closest('[data-field-id]').querySelector('label').innerText.replace('*', '').trim());
        missing.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});

// If the student picks a direct file after choosing a vault file, the direct file should win
document.querySelectorAll('input[type="file"][id^="file_"]').forEach(function (input) {
    input.addEventListener('change', function () {
        const reqId = this.id.replace('file_', '');
        const vaultInput = document.getElementById('vault_input_' + reqId);
        if (vaultInput) vaultInput.value = '';

        const statusEl = document.getElementById('status_' + reqId);
        if (statusEl && this.files.length > 0) {
            statusEl.textContent = 'Ready to upload: ' + this.files[0].name;
            statusEl.classList.remove('text-slate-400');
            statusEl.classList.add('text-green-600');
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
