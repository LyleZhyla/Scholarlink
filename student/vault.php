<?php 
session_start();

// ✨ FIX: Session manager MUST be loaded before any HTML is output!
include '../includes/session_manager.php'; 

// 🛑 THE BACK BUTTON KILLER (Must be before any HTML)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- THE SECURITY GATEKEEPER ---
if (!isset($_SESSION['logged_in']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'student') { 
    header("Location: /student_login.php"); 
    exit(); 
}
include '../includes/db_connect.php'; 
include '../includes/header.php'; 
include '../includes/student_sidebar.php'; 

$user_id = $_SESSION['user_id'];
$upload_msg = "";

// --- HANDLE FILE UPLOAD, REPLACE & DELETE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? 'upload';

    // 1. Handle Delete Action
    if ($action === 'delete') {
        $vault_id = $_POST['vault_id'];
        $stmt = $pdo->prepare("SELECT FilePath FROM user_vault WHERE VaultID = ? AND UserID = ?");
        $stmt->execute([$vault_id, $user_id]);
        $doc = $stmt->fetch();
        
        if ($doc) {
            if (file_exists($doc['FilePath'])) { unlink($doc['FilePath']); } // Delete physical file
            $pdo->prepare("DELETE FROM user_vault WHERE VaultID = ?")->execute([$vault_id]); // Delete DB record
            $upload_msg = "<div class='p-4 mb-4 text-md text-green-800 rounded-lg bg-green-50 font-bold border border-green-200'>🗑️ Document deleted successfully.</div>";
        }
    } 

    // 2. Handle Upload/Replace Action
    elseif (($action === 'upload' || $action === 'replace') && isset($_FILES['vault_file'])) {
        $doc_type = $_POST['document_type'];
        $file_name = $_FILES['vault_file']['name'];
        $tmp_name = $_FILES['vault_file']['tmp_name'];
        $file_size = $_FILES['vault_file']['size'];
        $file_err = $_FILES['vault_file']['error'];
        
        // STRICT PDF & 10MB SIZE CHECK INJECTED HERE
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if ($file_err === UPLOAD_ERR_INI_SIZE || $file_err === UPLOAD_ERR_FORM_SIZE || $file_size > 10 * 1024 * 1024) {
            $upload_msg = "<div class='p-4 mb-4 text-md text-red-800 rounded-lg bg-red-50 font-bold border border-red-200'>⚠️ Upload failed. File exceeds the maximum allowed size of 10MB.</div>";
        } elseif ($file_ext !== 'pdf') {
            $upload_msg = "<div class='p-4 mb-4 text-md text-red-800 rounded-lg bg-red-50 font-bold border border-red-200'>⚠️ Upload failed. Only PDF files are allowed in the vault.</div>";
        } else {
            // PROCEED WITH UPLOAD IF IT IS A PDF UNDER 10MB
            $upload_dir = '../uploads/vault/' . $user_id . '/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
            
            $new_file_name = time() . '_' . basename($file_name);
            $target_path = $upload_dir . $new_file_name;

            if (move_uploaded_file($tmp_name, $target_path)) {
                $check_stmt = $pdo->prepare("SELECT VaultID, FilePath FROM user_vault WHERE UserID = ? AND DocumentType = ?");
                $check_stmt->execute([$user_id, $doc_type]);
                $existing_doc = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing_doc) {
                    // Overwrite existing
                    if (file_exists($existing_doc['FilePath'])) { unlink($existing_doc['FilePath']); }
                    $update_stmt = $pdo->prepare("UPDATE user_vault SET FilePath = ?, UploadDate = CURRENT_TIMESTAMP WHERE VaultID = ?");
                    $update_stmt->execute([$target_path, $existing_doc['VaultID']]);
                    $upload_msg = "<div class='p-4 mb-4 text-md text-blue-800 rounded-lg bg-blue-50 font-bold border border-blue-200'>🔄 Document replaced and updated!</div>";
                } else {
                    // Insert new
                    $insert_stmt = $pdo->prepare("INSERT INTO user_vault (UserID, DocumentType, FilePath) VALUES (?, ?, ?)");
                    $insert_stmt->execute([$user_id, $doc_type, $target_path]);
                    $upload_msg = "<div class='p-4 mb-4 text-md text-green-800 rounded-lg bg-green-50 font-bold border border-green-200'>🔒 Document secured in your vault!</div>";
                }
            } else {
                $upload_msg = "<div class='p-4 mb-4 text-md text-red-800 rounded-lg bg-red-50 font-bold border border-red-200'>⚠️ Upload failed. Please try again.</div>";
            }
        }
    }
}

// --- ✨ DYNAMIC DATABASE QUERIES ---

// 1. Fetch ALL documents currently saved in the student's vault
$stmt = $pdo->prepare("SELECT * FROM user_vault WHERE UserID = ? ORDER BY UploadDate DESC");
$stmt->execute([$user_id]);
$vault_files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create a simple array of just the Document Types the user already has for easy checking
$saved_types = array_column($vault_files, 'DocumentType');

// 2. Fetch ALL UNIQUE document requirements from ACTIVE scholarships in the database
$req_stmt = $pdo->query("
    SELECT DISTINCT dr.DocumentName 
    FROM document_requirement dr
    JOIN scholarship s ON dr.ScholarshipID = s.ScholarshipID
    WHERE s.Status = 'Active'
    ORDER BY dr.DocumentName ASC
");
$all_requirements = $req_stmt->fetchAll(PDO::FETCH_COLUMN);

?>

<main class="flex-1 ml-72 p-10 bg-slate-50/50 min-h-screen">
    <header class="mb-10">
        <h2 class="text-4xl font-black text-slate-900 tracking-tight">My Document Vault</h2>
        <p class="text-slate-500 font-medium mt-1">Upload once, apply anywhere. Manage your reusable requirements here.</p>
    </header>

    <?= $upload_msg ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- ✨ SMART UPLOAD PANEL -->
        <div class="lg:col-span-1">
            <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                <h3 class="font-black text-slate-900 uppercase tracking-widest text-md mb-6">Upload to Vault</h3>
                
                <form action="vault.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="upload">
                    <div>
                        <label class="block text-md font-bold text-slate-700 mb-2">Document Type</label>
                        <select name="document_type" required class="w-full border border-slate-300 rounded-xl px-4 py-3 text-md focus:outline-none focus:border-slate-900 focus:ring-1 focus:ring-slate-900">
                            <option value="">-- Select Requirement --</option>
                            
                            <?php foreach ($all_requirements as $req): ?>
                                <?php $is_saved = in_array($req, $saved_types); ?>
                                <option value="<?= htmlspecialchars($req) ?>">
                                    <?= htmlspecialchars($req) ?> <?= $is_saved ? '✅ (Saved)' : '⚠️ (Missing)' ?>
                                </option>
                            <?php endforeach; ?>
                            
                        </select>
                        <p class="text-xs font-bold text-slate-400 mt-2">Options match active scholarships.</p>
                    </div>

                    <div>
                        <label class="flex items-center justify-between text-md font-bold text-slate-700 mb-2 mt-4">
                            <span>Select File (PDF Only)</span>
                            <span class="text-xs font-semibold text-slate-400">Max: 10MB</span>
                        </label>
                        <input type="file" name="vault_file" accept=".pdf,application/pdf" required onchange="validateVaultFileSize(this)" class="w-full text-md text-slate-500 file:mr-4 file:py-3 file:px-4 file:rounded-xl file:border-0 file:text-md file:font-bold file:bg-slate-900 file:text-white hover:file:bg-slate-800 cursor-pointer border border-slate-300 rounded-xl">
                        <p class="text-[11px] font-medium text-slate-400 mt-1.5">Ensure scanned PDF has clear, readable resolution (up to 10MB).</p>
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-xl transition-colors mt-4">
                        Secure in Vault 🔒
                    </button>
                </form>
            </div>
        </div>

        <!-- SECURED DOCUMENTS PANEL -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="font-black text-slate-900 uppercase tracking-widest text-md">Secured Documents</h3>
                    <span class="bg-slate-100 text-slate-600 text-md font-bold px-3 py-1 rounded-full"><?= count($vault_files) ?> Files</span>
                </div>
                
                <div class="p-4">
                    <?php if (count($vault_files) > 0): ?>
                        <div class="flex flex-col gap-4">
                            <?php foreach($vault_files as $file): ?>
                            <div class="border border-slate-200 rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 hover:border-blue-400 transition-colors">
                                
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0 text-xl">📄</div>
                                    <div>
                                        <p class="text-md font-black text-slate-900"><?= htmlspecialchars($file['DocumentType']) ?></p>
                                        <p class="text-md text-slate-500 mt-1">Uploaded: <?= date('M d, Y', strtotime($file['UploadDate'])) ?></p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-4 sm:ml-auto pt-3 sm:pt-0 border-t sm:border-0 border-slate-100 w-full sm:w-auto">
                                    <button onclick="openDocumentModal('<?= htmlspecialchars($file['FilePath']) ?>', '<?= htmlspecialchars($file['DocumentType']) ?>')" type="button" class="text-md font-bold text-blue-600 hover:text-blue-800">
                                        View
                                    </button>

                                    <div class="w-px h-4 bg-slate-300"></div>

                                    <form action="vault.php" method="POST" enctype="multipart/form-data" id="replaceForm_<?= $file['VaultID'] ?>" class="hidden">
                                        <input type="hidden" name="action" value="replace">
                                        <input type="hidden" name="document_type" value="<?= htmlspecialchars($file['DocumentType']) ?>">
                                        <input type="file" name="vault_file" id="replaceFile_<?= $file['VaultID'] ?>" accept=".pdf,application/pdf" onchange="handleVaultReplace(this, '<?= htmlspecialchars($file['DocumentType'], ENT_QUOTES) ?>', 'replaceForm_<?= $file['VaultID'] ?>')">
                                    </form>
                                    <button onclick="document.getElementById('replaceFile_<?= $file['VaultID'] ?>').click();" type="button" class="text-md font-bold text-orange-500 hover:text-orange-700">
                                        Replace
                                    </button>

                                    <div class="w-px h-4 bg-slate-300"></div>

                                    <form action="vault.php" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this document from your vault?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="vault_id" value="<?= $file['VaultID'] ?>">
                                        <button type="submit" class="text-md font-bold text-red-500 hover:text-red-700">
                                            Delete
                                        </button>
                                    </form>
                                </div>

                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-10 text-center">
                            <div class="text-6xl mb-4 opacity-50">📂</div>
                            <p class="text-slate-500 font-medium">Your vault is empty. Upload documents to get started!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<div id="documentModal" class="fixed inset-0 z-[9999] hidden bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4 sm:p-10 transition-opacity duration-300 opacity-0">
    <div id="modalContent" class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl h-[85vh] flex flex-col overflow-hidden relative transform transition-transform duration-300 scale-95">
        <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 shrink-0">
            <h3 id="modalTitle" class="font-black text-slate-900 uppercase tracking-widest text-md">Document Preview</h3>
            <button onclick="closeDocumentModal()" class="text-slate-400 hover:text-red-500 transition-colors bg-white hover:bg-red-50 rounded-full p-1 border border-transparent hover:border-red-100">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div id="modalBody" class="flex-1 bg-slate-800/5 p-4 flex items-center justify-center overflow-auto relative"></div>
    </div>
</div>

<script>
    // Modal Script Logic
    const docModal = document.getElementById('documentModal');
    const modalContent = document.getElementById('modalContent');
    const modalBody = document.getElementById('modalBody');
    const modalTitle = document.getElementById('modalTitle');

    function openDocumentModal(filePath, docName) {
        modalTitle.innerText = docName + " Preview";
        const ext = filePath.split('.').pop().toLowerCase();
        modalBody.innerHTML = '<div class="animate-pulse text-slate-400 font-bold">Loading document...</div>';
        
        docModal.classList.remove('hidden');
        setTimeout(() => {
            docModal.classList.remove('opacity-0');
            modalContent.classList.remove('scale-95');
            modalContent.classList.add('scale-100');
        }, 10);

        setTimeout(() => {
            if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                modalBody.innerHTML = `<img src="${filePath}" alt="${docName}" class="max-w-full max-h-full object-contain rounded-lg shadow-sm border border-slate-200 bg-white">`;
            } else if (ext === 'pdf') {
                modalBody.innerHTML = `<iframe src="${filePath}#toolbar=0" class="w-full h-full rounded-lg shadow-sm bg-white border border-slate-200" title="${docName}"></iframe>`;
            } else {
                modalBody.innerHTML = `
                    <div class="text-center p-10 bg-white rounded-xl shadow-sm">
                        <div class="text-4xl mb-3">⚠️</div>
                        <p class="text-slate-600 font-bold">Preview not available for this file type.</p>
                        <a href="${filePath}" target="_blank" class="mt-4 inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition-colors">Download</a>
                    </div>`;
            }
        }, 300);
    }

    function closeDocumentModal() {
        docModal.classList.add('opacity-0');
        modalContent.classList.remove('scale-100');
        modalContent.classList.add('scale-95');
        setTimeout(() => {
            docModal.classList.add('hidden');
            modalBody.innerHTML = ''; 
        }, 300);
    }

    docModal.addEventListener('click', function(e) { if (e.target === this) closeDocumentModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && !docModal.classList.contains('hidden')) closeDocumentModal(); });

    // ✨ 10MB CLIENT-SIDE FILE SIZE VALIDATOR
    function validateVaultFileSize(input) {
        if (!input.files || input.files.length === 0) return false;
        const file = input.files[0];
        const MAX_MB = 10;

        if (file.type !== "application/pdf" && !file.name.toLowerCase().endsWith('.pdf')) {
            alert("⚠️ INVALID FILE:\n\nOnly PDF documents are allowed in the vault.");
            input.value = '';
            return false;
        }

        if (file.size > MAX_MB * 1024 * 1024) {
            alert(`⚠️ FILE TOO LARGE:\n\nYour PDF is ${(file.size / (1024 * 1024)).toFixed(2)}MB. The maximum allowed file size is ${MAX_MB}MB.`);
            input.value = '';
            return false;
        }
        return true;
    }

    function handleVaultReplace(input, docType, formId) {
        if (!validateVaultFileSize(input)) return;
        if (confirm(`Are you sure you want to replace your saved ${docType}? The old file will be permanently deleted.`)) {
            document.getElementById(formId).submit();
        } else {
            input.value = '';
        }
    }
</script>

<?php include '../includes/footer.php'; ?>