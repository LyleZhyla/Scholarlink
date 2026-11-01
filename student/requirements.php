<?php
session_start();

// 🔒 1. SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../student_login.php"); exit();
}

include '../includes/db_connect.php';
include '../includes/header.php';
include '../includes/student_sidebar.php';

$user_id = $_SESSION['user_id'];

try {
    // Fetch Applications with the Awesome Layout logic
    $app_query = $pdo->prepare("
        SELECT a.ApplicationID, s.ScholarshipID, s.Name AS scholarship_name, a.Status 
        FROM application a 
        JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID 
        WHERE a.UserID = :uid 
        ORDER BY a.DateSubmitted DESC
    ");
    $app_query->execute(['uid' => $user_id]);
    $my_applications = $app_query->fetchAll();

    // ✨ NEW: Fetch the student's Vault Documents
    $vault_stmt = $pdo->prepare("SELECT VaultID, DocumentType, FilePath FROM user_vault WHERE UserID = ?");
    $vault_stmt->execute([$user_id]);
    $vault_files = $vault_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
}
?>

<main class="flex-1 p-5 lg:p-12 lg:ml-72 bg-slate-50/50 min-h-screen transition-all duration-500">
    <header class="mb-12">
        <h2 class="text-4xl font-black text-slate-900 tracking-tight">My Requirements</h2>
        <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Document Tracking & Submission Portal</p>
    </header>

    <?php if (empty($my_applications)): ?>
        <div class="bg-white p-24 rounded-[3.5rem] border border-slate-200/60 shadow-sm text-center">
            <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6">
                <span class="text-3xl">📂</span>
            </div>
            <p class="text-slate-400 font-black uppercase tracking-widest text-[14px]">No active scholarship applications found.</p>
            <a href="programs.php" class="mt-8 inline-block bg-slate-900 text-white px-10 py-4 rounded-2xl font-black text-[14px] uppercase tracking-[0.2em] hover:bg-blue-600 hover:-translate-y-1 transition-all shadow-lg">Browse Scholarships</a>
        </div>
    <?php else: ?>
        <?php foreach ($my_applications as $app): ?>
            <div class="mb-16">
                <div class="flex flex-wrap items-center justify-between gap-6 mb-8 px-4">
                    <div class="flex items-center gap-5">
                        <div class="h-12 w-2 bg-blue-600 rounded-full"></div>
                        <div>
                            <h3 class="text-2xl font-black text-slate-900 uppercase tracking-tight"><?= htmlspecialchars($app['scholarship_name']) ?></h3>
                            <p class="text-slate-500 text-sm lg:text-base font-medium mt-1">Application ID: #<?= $app['ApplicationID'] ?></p>
                        </div>
                    </div>
                    <span class="px-6 py-2 bg-slate-900 text-white rounded-full text-[12px] font-black uppercase tracking-widest shadow-md">
                        <?= $app['Status'] ?>
                    </span>
                </div>

                <div class="bg-white rounded-[2.5rem] border border-slate-200/60 shadow-sm overflow-hidden transition-all hover:shadow-md">
                    <table class="w-full text-left no-datatable">
                        <thead class="bg-slate-900 text-[14px] font-black text-slate-400 uppercase tracking-[0.2em]">
                            <tr>
                                <th class="p-8 text-white">Requirement Name</th>
                                <th class="p-8 text-white text-center">Current Status</th>
                                <th class="p-8 text-white text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php
                            $req_stmt = $pdo->prepare("
                                SELECT dr.RequirementID, dr.DocumentName, sd.FilePath, sd.VerificationStatus
                                FROM document_requirement dr
                                LEFT JOIN submitted_document sd ON dr.RequirementID = sd.RequirementID AND sd.ApplicationID = ?
                                WHERE dr.ScholarshipID = ?
                            ");
                            $req_stmt->execute([$app['ApplicationID'], $app['ScholarshipID']]);
                            $requirements = $req_stmt->fetchAll();

                            foreach ($requirements as $req):
                                $statusColor = $req['VerificationStatus'] === 'Verified' ? 'text-green-600 bg-green-50' : ($req['VerificationStatus'] === 'Rejected' ? 'text-red-600 bg-red-50' : 'text-orange-600 bg-orange-50');
                                $isUploaded = !empty($req['FilePath']);
                                // ✨ LOCK EDITING IF APPLICATION IS APPROVED OR DOCUMENT IS ALREADY VERIFIED
                                $isLocked = ($app['Status'] === 'Approved' || $req['VerificationStatus'] === 'Verified');
                            ?>
                            <tr class="hover:bg-slate-50/80 transition-all group">
                                <td class="p-8">
                                    <p class="font-black text-slate-900 uppercase text-md tracking-tight group-hover:text-blue-600 transition-colors"><?= htmlspecialchars($req['DocumentName']) ?></p>
                                </td>
                                <td class="p-8 text-center">
                                    <?php if ($isUploaded): ?>
                                        <span class="px-5 py-2 rounded-full text-[12px] font-black uppercase tracking-widest <?= $statusColor ?> shadow-sm">
                                            <?= $req['VerificationStatus'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="px-5 py-2 rounded-full text-[12px] font-black uppercase tracking-widest text-amber-600 bg-amber-50 border border-dashed border-amber-300">
                                            Pending Submission
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-8 text-center">
                                    <div class="flex justify-center items-center gap-3">
                                    <?php if ($isUploaded): ?>
                                        <button onclick="openViewerModal('<?= $req['FilePath'] ?>', '<?= htmlspecialchars(addslashes($req['DocumentName'])) ?>')" class="px-6 py-3 bg-slate-50 text-slate-900 rounded-xl text-[14px] font-black uppercase tracking-widest hover:bg-slate-900 hover:text-white transition-all">View 👁️</button>
                                        
                                        <?php if ($isLocked): ?>
                                            <span class="px-5 py-3 bg-slate-100 text-slate-400 rounded-xl text-[12px] font-black uppercase tracking-widest cursor-not-allowed select-none flex items-center gap-1.5" title="Locked after approval/verification">
                                                🔒 Locked
                                            </span>
                                        <?php else: ?>
                                            <button onclick="openUploadModal(<?= $app['ApplicationID'] ?>, <?= $req['RequirementID'] ?>, <?= $app['ScholarshipID'] ?>, '<?= htmlspecialchars(addslashes($req['DocumentName'])) ?>', true)" class="px-6 py-3 border border-slate-100 text-slate-400 rounded-xl text-[14px] font-black uppercase tracking-widest hover:border-blue-600 hover:text-blue-600 transition-all">Edit</button>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <?php if ($app['Status'] === 'Approved'): ?>
                                            <span class="px-5 py-3 bg-slate-100 text-slate-400 rounded-xl text-[12px] font-black uppercase tracking-widest cursor-not-allowed select-none">
                                                🔒 Application Approved
                                            </span>
                                        <?php else: ?>
                                            <button onclick="openUploadModal(<?= $app['ApplicationID'] ?>, <?= $req['RequirementID'] ?>, <?= $app['ScholarshipID'] ?>, '<?= htmlspecialchars(addslashes($req['DocumentName'])) ?>')" class="bg-slate-900 text-white px-8 py-3.5 rounded-xl text-[14px] font-black uppercase tracking-widest shadow-lg hover:bg-blue-600 hover:-translate-y-1 transition-all">Upload File 📤</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

  <div id="uploadModal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-[9999] hidden items-center justify-center p-6">
        <div class="bg-white w-full max-w-lg rounded-[3rem] shadow-2xl overflow-hidden animate-in zoom-in duration-300">
            <form action="../actions/upload_requirement.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="ApplicationID" id="modal_app_id">
                <input type="hidden" name="RequirementID" id="modal_req_id">
                <input type="hidden" name="scholarship_id" id="modal_sch_id">

                <div class="p-10 border-b border-slate-100 bg-slate-50/50">
                    <h3 id="modal_action_title" class="text-2xl font-black text-slate-900 uppercase tracking-tight">Upload Document</h3>
                    <p id="modal_doc_name" class="text-[14px] text-blue-600 font-black mt-2 uppercase tracking-[0.2em]">Requirement Name</p>
                </div>
                <div class="p-10 space-y-6 bg-white">
                    
                    <?php if (!empty($vault_files)): ?>
                    <div class="bg-blue-50/50 p-6 rounded-[1.5rem] border border-blue-100 hover:border-blue-300 transition-all">
                        <label class="block text-[14px] font-black text-blue-900 uppercase tracking-widest mb-3">Option 1: Use Document from Vault</label>
                        <select name="vault_file_path" id="vault_select" class="w-full p-4 rounded-xl border border-blue-200 text-slate-700 font-medium focus:outline-none focus:border-blue-600 focus:ring-1 focus:ring-blue-600" onchange="toggleUploadRequirement()">
                            <option value="">-- Select a saved document --</option>
                            <?php foreach($vault_files as $vf): ?>
                                <option value="<?= htmlspecialchars($vf['FilePath']) ?>"><?= htmlspecialchars($vf['DocumentType']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex items-center justify-center gap-4 py-2">
                        <div class="h-px bg-slate-200 flex-1"></div>
                        <span class="text-slate-400 font-black uppercase tracking-widest text-[11px]">OR</span>
                        <div class="h-px bg-slate-200 flex-1"></div>
                    </div>
                    <?php endif; ?>

                    <div class="bg-slate-50 p-8 rounded-[1.5rem] border-2 border-dashed border-slate-200 text-center hover:border-slate-400 transition-all">
                        <label class="block text-[14px] font-black text-slate-900 uppercase tracking-widest mb-4">Option 2: Upload New Local File</label>
                        <input type="file" name="file" id="local_file" accept=".pdf" <?= empty($vault_files) ? 'required' : '' ?> class="w-full text-md font-black text-slate-500 file:mr-4 file:py-3 file:px-6 file:rounded-full file:border-0 file:text-[14px] file:font-black file:bg-slate-900 file:text-white hover:file:bg-blue-600 transition-all cursor-pointer" onchange="toggleVaultRequirement()">
                    </div>
                </div>
                <div class="p-10 bg-slate-50 border-t border-slate-100 flex justify-end gap-4">
                    <button type="button" onclick="closeUploadModal()" class="px-8 py-4 font-black text-[14px] uppercase text-slate-400 hover:text-slate-900 tracking-widest">Cancel</button>
                    <button type="submit" class="bg-slate-900 text-white px-12 py-4 rounded-2xl font-black text-[14px] uppercase tracking-[0.2em] shadow-xl hover:bg-blue-600 hover:-translate-y-1 transition-all">Submit Document</button>
                </div>
            </form>
        </div>
    </div>

    <div id="viewerModal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-[9999] hidden items-center justify-center p-6">
        <div class="bg-white w-full max-w-5xl h-[85vh] rounded-[3.5rem] shadow-2xl overflow-hidden flex flex-col animate-in fade-in zoom-in duration-300">
            <div class="p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <div>
                    <h3 id="viewerDocTitle" class="font-black text-slate-900 uppercase text-md tracking-widest">Document Inspector</h3>
                    <p class="text-[12px] font-black text-blue-500 uppercase mt-1 tracking-widest">Preview Mode</p>
                </div>
                <button onclick="closeViewerModal()" class="w-12 h-12 flex items-center justify-center bg-white border border-slate-100 rounded-2xl text-slate-400 hover:text-red-500 transition-all shadow-sm">&times;</button>
            </div>
            <div class="flex-1 bg-slate-100 p-8 flex items-center justify-center overflow-hidden">
                <img id="viewerImage" class="hidden max-h-full rounded-[2rem] shadow-2xl border border-white/50 object-contain">
                <iframe id="viewerIframe" class="hidden w-full h-full rounded-[2rem] border border-white/50 shadow-2xl bg-white"></iframe>
            </div>
        </div>
    </div>

    <script>
    // 1. UPLOAD MODAL LOGIC
    function openUploadModal(appId, reqId, schId, docName, isReplace = false) {
        document.getElementById('modal_app_id').value = appId;
        document.getElementById('modal_req_id').value = reqId;
        document.getElementById('modal_sch_id').value = schId;
        document.getElementById('modal_doc_name').textContent = docName;
        document.getElementById('modal_action_title').textContent = isReplace ? 'Edit Document' : 'Upload Document';
        
        const modal = document.getElementById('uploadModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeUploadModal() {
        const modal = document.getElementById('uploadModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // 2. VIEWER MODAL LOGIC (With 404 Path Fix)
    function openViewerModal(fileUrl, docType) {
        document.getElementById('viewerDocTitle').innerText = docType;
        
        // 🚀 THE LOGIC FIX: Standardize path to root uploads folder
        const cleanUrl = fileUrl.replace('../', '');
        const rootPath = `../${cleanUrl}`;
        
        const ext = fileUrl.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);

        if (isImage) {
            document.getElementById('viewerIframe').classList.add('hidden');
            document.getElementById('viewerImage').classList.remove('hidden');
            document.getElementById('viewerImage').src = rootPath;
        } else {
            document.getElementById('viewerImage').classList.add('hidden');
            document.getElementById('viewerIframe').classList.remove('hidden');
            document.getElementById('viewerIframe').src = rootPath;
        }
        
        const modal = document.getElementById('viewerModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeViewerModal() {
        const modal = document.getElementById('viewerModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
    // 4. CLICK OUTSIDE TO CLOSE
    window.addEventListener('click', function(e) {
        if (e.target.id === 'uploadModal') closeUploadModal();
        if (e.target.id === 'viewerModal') closeViewerModal();
    });

    // ✨ SMART FORM VALIDATOR: Toggle Required Fields
    function toggleUploadRequirement() {
        const vaultSelect = document.getElementById('vault_select');
        const localFile = document.getElementById('local_file');
        
        if (vaultSelect && vaultSelect.value !== "") {
            localFile.required = false; // Vault selected, local file not needed
        } else {
            localFile.required = true;  // Nothing selected, enforce local file
        }
    }

    function toggleVaultRequirement() {
        const vaultSelect = document.getElementById('vault_select');
        const localFile = document.getElementById('local_file');
        
        if (localFile && localFile.value !== "") {
            if(vaultSelect) {
                vaultSelect.value = ""; // Clear vault dropdown if local file is chosen
                vaultSelect.required = false;
            }
        }
    }
    </script>
</main>
<?php include '../includes/footer.php'; ?>