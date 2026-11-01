<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/application_lifecycle.php';
require_once '../includes/appeals.php';

// Using require_once to ensure the function is defined and paths are correct
if (file_exists('../includes/email_config.php')) {
    require_once '../includes/email_config.php';
}

// Security: Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: ../admin_login.php");
    exit();
}

$module = $_POST['module'] ?? '';
$action = $_POST['action'] ?? '';

// ==========================================
// ROLE GUARD: Each module/action is only allowed for the roles that use it
// ==========================================
$all_admins = ['Internal_Admin', 'External_Admin', 'Super_Admin'];
$allowed_roles = [
    'student_apply'     => ['Student'],
    'student_documents' => ['Student'],
    'requirements'      => ['Student'],
    'scholarships'      => ['External_Admin', 'Super_Admin'],
    'verification'      => $all_admins,
    'notifications'     => $all_admins,
    'super_admin'       => ['Super_Admin'],
    'admin_actions'     => ['Internal_Admin', 'Super_Admin'],
    'logs'              => ['Super_Admin'],
    'security'          => ['Super_Admin'],
    'academic_terms'    => ['Internal_Admin'],
    'renewals'          => ['Internal_Admin'],
    'applications'      => [
        'score'          => ['External_Admin', 'Super_Admin'],
        'update_status'  => $all_admins,
        'final_decision' => $all_admins,
    ],
    'users'             => [
        'create'         => ['Super_Admin'],
        'toggle_archive' => ['Super_Admin'],
        'bulk_status'    => ['Super_Admin'],
        'send_reset'     => ['Super_Admin'],
        'update_gwa'     => ['Internal_Admin', 'Super_Admin'],
    ],
];

$roles = $allowed_roles[$module] ?? [];
if ($roles && !array_is_list($roles)) {
    $roles = $roles[$action] ?? [];
}

if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
    $_SESSION['error'] = "Unauthorized action.";
    header("Location: " . (($_SESSION['role'] ?? '') === 'Student' ? '../student_login.php' : '../admin_login.php'));
    exit();
}

// ✨ DECISION AUTHORITY: internally funded scholarships are decided by the OSSD (Internal Admin),
// externally funded ones only by the provider account that created them (see application_authority.php)
if ($module === 'applications' && in_array($action, ['score', 'update_status', 'final_decision'], true)) {
    require_once '../includes/application_authority.php';
    requireApplicationAuthority($pdo, $_POST['id'] ?? $_POST['application_id'] ?? 0, '../admin_login.php');
}

// ==========================================
// MODULE: STUDENT APPLICATIONS (100% DYNAMIC)
// ==========================================
if ($module === 'student_apply') {
    $user_id = $_SESSION['user_id'];

    // Save an incomplete application without entering the review queue.
    if ($action === 'save_draft') {
        $scholarship_id = (int) ($_POST['scholarship_id'] ?? 0);
        $app_id = (int) ($_POST['application_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $deadline = $pdo->prepare("SELECT Deadline FROM scholarship WHERE ScholarshipID = ? AND Status = 'Active'");
            $deadline->execute([$scholarship_id]);
            $deadline_date = $deadline->fetchColumn();
            if (!$deadline_date || strtotime($deadline_date) < strtotime(date('Y-m-d'))) throw new RuntimeException('This scholarship is no longer accepting applications.');
            if ($app_id) {
                $owned = $pdo->prepare("SELECT ApplicationID FROM application WHERE ApplicationID = ? AND UserID = ? AND ScholarshipID = ? AND Status = 'Draft'");
                $owned->execute([$app_id, $user_id, $scholarship_id]);
                if (!$owned->fetchColumn()) throw new RuntimeException('This draft is no longer editable.');
                $pdo->prepare("UPDATE application SET GPA = ?, LastSavedAt = NOW() WHERE ApplicationID = ?")
                    ->execute([($_POST['gpa'] ?? '') !== '' ? $_POST['gpa'] : null, $app_id]);
            } else {
                $duplicate = $pdo->prepare("SELECT ApplicationID FROM application WHERE UserID = ? AND ScholarshipID = ?");
                $duplicate->execute([$user_id, $scholarship_id]);
                if ($duplicate->fetchColumn()) throw new RuntimeException('You already have an application for this scholarship.');
                $pdo->prepare("INSERT INTO application (UserID, ScholarshipID, Status, DateSubmitted, LastSavedAt, GPA, TermID) VALUES (?, ?, 'Draft', NOW(), NOW(), ?, (SELECT TermID FROM academic_terms WHERE Status = 'Active' ORDER BY TermID DESC LIMIT 1))")
                    ->execute([$user_id, $scholarship_id, ($_POST['gpa'] ?? '') !== '' ? $_POST['gpa'] : null]);
                $app_id = (int) $pdo->lastInsertId();
            }

            require_once '../includes/form_fields.php';
            $fields = getFormFields($pdo, $scholarship_id);
            [$answers, $errors] = collectFormAnswers($fields, $_POST['custom_answers'] ?? [], false);
            if ($errors) throw new RuntimeException(implode(' ', $errors));
            replaceApplicationAnswers($pdo, $app_id, array_column($fields, 'FieldID'), $answers);
            saveRequestedApplicationDocuments($pdo, $app_id, $scholarship_id, $user_id);
            snapshotApplicationVersion($pdo, $app_id, 'Draft Saved', $user_id);
            logAudit($pdo, $user_id, 'Application Draft Saved', "Saved draft application #$app_id");
            $pdo->commit();
            $_SESSION['success'] = 'Draft saved. You can continue it any time before the deadline.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Draft could not be saved: ' . $e->getMessage();
        }
        header("Location: ../student/apply_form.php?id=$scholarship_id");
        exit();
    }

    // Final submission of a saved draft, withdrawn application, or reviewer-authorized correction.
    if ($action === 'submit_draft') {
        $app_id = (int) ($_POST['application_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $owned = $pdo->prepare("SELECT a.ScholarshipID, a.Status, a.ResubmissionAllowed, s.Deadline FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.ApplicationID = ? AND a.UserID = ? FOR UPDATE");
            $owned->execute([$app_id, $user_id]);
            $app = $owned->fetch(PDO::FETCH_ASSOC);
            if (!$app || !in_array($app['Status'], ['Draft', 'Withdrawn', 'For Resubmission'], true)) throw new RuntimeException('This application cannot be submitted from its current status.');
            if ($app['Status'] !== 'For Resubmission' && strtotime($app['Deadline']) < strtotime(date('Y-m-d'))) throw new RuntimeException('The scholarship deadline has passed.');
            if ($app['Status'] === 'Withdrawn' && !(int) $app['ResubmissionAllowed']) throw new RuntimeException('Resubmission has not been permitted.');

            require_once '../includes/form_fields.php';
            $fields = getFormFields($pdo, (int) $app['ScholarshipID']);
            [$answers, $errors] = collectFormAnswers($fields, $_POST['custom_answers'] ?? []);
            if ($errors) throw new RuntimeException(implode(' ', $errors));
            replaceApplicationAnswers($pdo, $app_id, array_column($fields, 'FieldID'), $answers);
            saveRequestedApplicationDocuments($pdo, $app_id, (int) $app['ScholarshipID'], $user_id);
            $event = $app['Status'] === 'Draft' ? 'Submitted' : 'Resubmitted';
            $pdo->prepare("UPDATE application SET GPA = ?, Status = 'Submitted', DateSubmitted = NOW(), LastSavedAt = NOW(), WithdrawnAt = NULL, ResubmissionAllowed = 0 WHERE ApplicationID = ?")
                ->execute([($_POST['gpa'] ?? '') !== '' ? $_POST['gpa'] : null, $app_id]);
            snapshotApplicationVersion($pdo, $app_id, $event, $user_id);
            logAudit($pdo, $user_id, "Application $event", "$event application #$app_id");
            $pdo->commit();
            $_SESSION['success'] = $event === 'Submitted' ? 'Application submitted successfully!' : 'Corrected application resubmitted successfully!';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Application could not be submitted: ' . $e->getMessage();
        }
        header('Location: ../student/applications.php');
        exit();
    }

    if ($action === 'withdraw') {
        $app_id = (int) ($_POST['application_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT a.ApplicationID, s.Deadline FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.ApplicationID = ? AND a.UserID = ? AND a.Status = 'Submitted' FOR UPDATE");
            $stmt->execute([$app_id, $user_id]);
            $app = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$app) throw new RuntimeException('Only your submitted application can be withdrawn.');
            if (strtotime($app['Deadline']) < strtotime(date('Y-m-d'))) throw new RuntimeException('Applications cannot be withdrawn after the deadline.');
            $pdo->prepare("UPDATE application SET Status = 'Withdrawn', WithdrawnAt = NOW(), ResubmissionAllowed = 1, LastSavedAt = NOW() WHERE ApplicationID = ?")->execute([$app_id]);
            snapshotApplicationVersion($pdo, $app_id, 'Withdrawn', $user_id, 'Withdrawn by applicant before the deadline');
            logAudit($pdo, $user_id, 'Application Withdrawn', "Withdrew application #$app_id before the deadline");
            $pdo->commit();
            $_SESSION['success'] = 'Application withdrawn. You may correct and resubmit it before the deadline.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
        header('Location: ../student/applications.php');
        exit();
    }
    // --- ✨ ACTION: ONE CLICK APPLY (NEW) ---
    if ($action === 'one_click_apply') {
        $scholarship_id = (int)$_POST['scholarship_id'];

        // 1-Click skips the questions, so it only works for scholarships whose form has none
        require_once '../includes/form_fields.php';
        if (array_filter(getFormFields($pdo, $scholarship_id), fn($f) => $f['FieldType'] !== 'Section')) {
            $_SESSION['error'] = "This scholarship has application questions. Please complete the form to apply.";
            header("Location: ../student/apply_form.php?id=" . $scholarship_id);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // 1. Check for Duplicate Application
            $check = $pdo->prepare("SELECT COUNT(*) FROM application WHERE UserID = ? AND ScholarshipID = ?");
            $check->execute([$user_id, $scholarship_id]);
            if ($check->fetchColumn() > 0) {
                $pdo->rollBack();
                $_SESSION['error'] = "You have already applied for this scholarship.";
                header("Location: ../student/applications.php");
                exit();
            }

            // 2. Fetch Student GPA
            $user_stmt = $pdo->prepare("SELECT GPA FROM users WHERE UserID = ?");
            $user_stmt->execute([$user_id]);
            $student = $user_stmt->fetch(PDO::FETCH_ASSOC);
            $student_gwa = $student['GPA'] ?? 5.00;

            // 3. Create Application Record
            $sql = "INSERT INTO application (UserID, ScholarshipID, Status, DateSubmitted, GPA, TermID) VALUES (:uid, :sid, 'Submitted', NOW(), :gpa, (SELECT TermID FROM academic_terms WHERE Status = 'Active' ORDER BY TermID DESC LIMIT 1))";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['uid' => $user_id, 'sid' => $scholarship_id, 'gpa' => $student_gwa]);
            $new_app_id = $pdo->lastInsertId();

            // 4. Fetch Requirements & Vault Docs
            $req_stmt = $pdo->prepare("SELECT RequirementID, DocumentName FROM document_requirement WHERE ScholarshipID = ?");
            $req_stmt->execute([$scholarship_id]);
            $requirements = $req_stmt->fetchAll(PDO::FETCH_ASSOC);

            $vault_stmt = $pdo->prepare("SELECT DocumentType, FilePath FROM user_vault WHERE UserID = ?");
            $vault_stmt->execute([$user_id]);
            $vault_docs = $vault_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. The Copy-Paste Magic
            $upload_dir = '../uploads/documents/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

            $doc_insert_stmt = $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')");
            $docs_inserted = 0;

            foreach ($requirements as $req) {
                $reqName = strtolower(trim($req['DocumentName']));
                $final_file_path = null;
                
                foreach ($vault_docs as $v) {
                    if (strtolower(trim($v['DocumentType'])) === $reqName) {
                        $vault_file_path = $v['FilePath'];
                        $final_file_path = $vault_file_path; // Fallback kung dummy DB data
                        
                        // Kopyahin ang physical file kung nandiyan
                        if (file_exists($vault_file_path)) {
                            $file_ext = pathinfo($vault_file_path, PATHINFO_EXTENSION);
                            $new_file_name = "STU-" . $user_id . "-APP-" . $new_app_id . "-REQ-" . $req['RequirementID'] . "-" . uniqid() . "." . $file_ext;
                            $target_path = $upload_dir . $new_file_name;

                            if (copy($vault_file_path, $target_path)) {
                                $final_file_path = $target_path;
                            }
                        }
                        
                        $doc_insert_stmt->execute([$new_app_id, $req['RequirementID'], $final_file_path]);
                        recordDocumentVersion($pdo, (int) $pdo->lastInsertId(), (int) $new_app_id, (int) $req['RequirementID'], $final_file_path, basename($vault_file_path), $user_id, 'Uploaded');
                        $docs_inserted++;
                        break; 
                    }
                }
            }

            // ✨ Failsafe: I-cancel ang application kapag may kulang na document sa vault
            if ($docs_inserted < count($requirements)) {
                $pdo->rollBack();
                $_SESSION['error'] = "System Error: Missing vault documents. Please check your vault.";
                header("Location: ../student/scholarships.php");
                exit();
            }

            snapshotApplicationVersion($pdo, (int) $new_app_id, 'Submitted', $user_id, 'Submitted through 1-Click Apply');
            logAudit($pdo, $user_id, 'Application Submitted', "Submitted application #$new_app_id through 1-Click Apply");
            $pdo->commit();
            $_SESSION['success'] = "⚡ 1-Click Application submitted successfully!";
            header("Location: ../student/applications.php");
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Application Error: " . $e->getMessage();
            header("Location: ../student/applications.php");
            exit();
        }
    }
   // --- ACTION: CREATE ---
    if ($action === 'create') {
        $scholarship_id = $_POST['scholarship_id'];
        $gpa = $_POST['gpa'] ?? 0;

        try {
            $deadline_stmt = $pdo->prepare("SELECT Deadline FROM scholarship WHERE ScholarshipID = ? AND Status = 'Active'");
            $deadline_stmt->execute([$scholarship_id]);
            $deadline_date = $deadline_stmt->fetchColumn();
            if (!$deadline_date || strtotime($deadline_date) < strtotime(date('Y-m-d'))) {
                throw new RuntimeException('This scholarship is no longer accepting applications.');
            }
            // ✨ STRICT DUAL SCHOLARSHIP CHECK (Backend Protection)
            $check_approved = $pdo->prepare("SELECT COUNT(*) FROM application WHERE UserID = ? AND Status = 'Approved'");
            $check_approved->execute([$user_id]);
            
            if ($check_approved->fetchColumn() > 0) {
                // IBABLOCK NATIN SILA AGAD KAPAG SCHOLAR NA
                $_SESSION['error'] = "Action Denied: You are already an active scholar. Multiple scholarships are strictly prohibited.";
                header("Location: ../student/programs.php");
                exit();
            }

            // Check if already applied to this specific one
            $check = $pdo->prepare("SELECT COUNT(*) FROM application WHERE UserID = ? AND ScholarshipID = ?");
            $check->execute([$user_id, $scholarship_id]);
            
            if ($check->fetchColumn() > 0) {
                $_SESSION['error'] = "You have already applied for this scholarship.";
            } else {
                // ✨ Check the answers against the scholarship's current application form (Form Builder)
                require_once '../includes/form_fields.php';
                [$form_answers, $form_errors] = collectFormAnswers(getFormFields($pdo, $scholarship_id), $_POST['custom_answers'] ?? []);
                if ($form_errors) {
                    $_SESSION['error'] = implode('<br>', $form_errors);
                    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '../student/programs.php'));
                    exit();
                }

                // 1. Insert Base Application Data
                $sql = "INSERT INTO application (UserID, ScholarshipID, Status, DateSubmitted, GPA, TermID) 
                        VALUES (:uid, :sid, 'Submitted', NOW(), :gpa, (SELECT TermID FROM academic_terms WHERE Status = 'Active' ORDER BY TermID DESC LIMIT 1))";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['uid' => $user_id, 'sid' => $scholarship_id, 'gpa' => $gpa]);
                
                $new_app_id = $pdo->lastInsertId();

                // ✨ 2. SAVE DYNAMIC CUSTOM ANSWERS (already validated above)
                $ans_stmt = $pdo->prepare("INSERT INTO application_custom_answers (ApplicationID, FieldID, AnswerText) VALUES (?, ?, ?)");
                foreach ($form_answers as $fieldId => $answerText) {
                    $ans_stmt->execute([$new_app_id, $fieldId, $answerText]);
                }

                // ✨ 3. SAVE UPLOADED / VAULT DOCUMENTS
                $upload_dir = '../uploads/documents/';
                if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

                // A. Handling Direct PC Uploads (Sinasalo ang 'files' o 'new_docs' depende sa pinanggalingang form)
                $fileFieldName = isset($_FILES['files']) ? 'files' : (isset($_FILES['new_docs']) ? 'new_docs' : null);

                if ($fileFieldName !== null) {
                    foreach ($_FILES[$fileFieldName]['tmp_name'] as $req_id => $tmp_name) {
                        if ($_FILES[$fileFieldName]['error'][$req_id] === UPLOAD_ERR_OK) {
                            $file_name = $_FILES[$fileFieldName]['name'][$req_id];
                            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                            
                            $new_file_name = "STU-" . $user_id . "-APP-" . $new_app_id . "-REQ-" . $req_id . "-" . uniqid() . "." . $file_ext;
                            $destination = $upload_dir . $new_file_name;

                            if (move_uploaded_file($tmp_name, $destination)) {
                                $ins_doc = $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')");
                                $ins_doc->execute([$new_app_id, $req_id, $destination]);
                                recordDocumentVersion($pdo, (int) $pdo->lastInsertId(), (int) $new_app_id, (int) $req_id, $destination, $file_name, $user_id, 'Uploaded');
                            }
                        }
                    }
                }

                // B. Handling Vault Documents (Sinasalo ang vault_docs o vault_files)
                $vaultInput = $_POST['vault_docs'] ?? ($_POST['vault_files'] ?? []);
                
                if (!empty($vaultInput) && is_array($vaultInput)) {
                    $upload_dir = '../uploads/documents/';
                    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

                    foreach ($vaultInput as $req_id => $vault_id) {
                        if (!empty($vault_id)) {
                            $v_stmt = $pdo->prepare("SELECT FilePath FROM user_vault WHERE VaultID = ? AND UserID = ?");
                            $v_stmt->execute([$vault_id, $user_id]);
                            $vault_file = $v_stmt->fetch();
                            
                            if ($vault_file) {
                                $vault_file_path = $vault_file['FilePath'];
                                $final_file_path = $vault_file_path; 
                                
                                // Kopyahin ang physical file kung nandiyan
                                if (file_exists($vault_file_path)) {
                                    $file_ext = pathinfo($vault_file_path, PATHINFO_EXTENSION);
                                    $new_file_name = "STU-" . $user_id . "-APP-" . $new_app_id . "-REQ-" . $req_id . "-" . uniqid() . "." . $file_ext;
                                    $target_path = $upload_dir . $new_file_name;

                                    if (copy($vault_file_path, $target_path)) {
                                        $final_file_path = $target_path;
                                    }
                                }
                                
                                $ins_doc = $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')");
                                $ins_doc->execute([$new_app_id, $req_id, $final_file_path]);
                                recordDocumentVersion($pdo, (int) $pdo->lastInsertId(), (int) $new_app_id, (int) $req_id, $final_file_path, basename($vault_file_path), $user_id, 'Uploaded');
                            }
                        }
                    }
                }

                snapshotApplicationVersion($pdo, (int) $new_app_id, 'Submitted', $user_id);
                logAudit($pdo, $user_id, 'Application Submitted', "Submitted application #$new_app_id");
                $_SESSION['success'] = "Application submitted successfully!";
            }
        } catch (Throwable $e) { $_SESSION['error'] = "Application Error: " . $e->getMessage(); }
        
        header("Location: ../student/applications.php");
        exit();
    }

    // --- ACTION: UPDATE ---
    if ($action === 'update') {
        $app_id = $_POST['application_id'];
        $gpa    = $_POST['gpa'] ?? 0;

        try {
            // Only the student's own, still-editable application
            $own_stmt = $pdo->prepare("SELECT a.ScholarshipID, a.Status, a.ResubmissionAllowed, s.Deadline FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.ApplicationID = ? AND a.UserID = ?");
            $own_stmt->execute([$app_id, $user_id]);
            $owned_app = $own_stmt->fetch(PDO::FETCH_ASSOC);
            $editable = $owned_app && (
                ($owned_app['Status'] === 'Submitted' && strtotime($owned_app['Deadline']) >= strtotime(date('Y-m-d')))
                || $owned_app['Status'] === 'For Resubmission'
                || ($owned_app['Status'] === 'Withdrawn' && (int) $owned_app['ResubmissionAllowed'] && strtotime($owned_app['Deadline']) >= strtotime(date('Y-m-d')))
            );
            if (!$editable) {
                $_SESSION['error'] = "This application can no longer be edited.";
                header("Location: ../student/applications.php");
                exit();
            }
            $own_scholarship_id = (int) $owned_app['ScholarshipID'];

            // ✨ Check the answers against the scholarship's current application form (Form Builder)
            require_once '../includes/form_fields.php';
            $form_fields = getFormFields($pdo, $own_scholarship_id);
            [$form_answers, $form_errors] = collectFormAnswers($form_fields, $_POST['custom_answers'] ?? []);
            if ($form_errors) {
                $_SESSION['error'] = implode('<br>', $form_errors);
                header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '../student/applications.php'));
                exit();
            }

            // 1. Update Base Application Data
            $stmt = $pdo->prepare("UPDATE application SET GPA = :gpa, LastSavedAt = NOW() WHERE ApplicationID = :aid AND UserID = :uid");
            $stmt->execute(['gpa' => $gpa, 'aid' => $app_id, 'uid' => $user_id]);

            // 2. UPDATE DYNAMIC CUSTOM ANSWERS: replace answers to the current questions only
            // (answers to questions later removed from the form stay with the application)
            $current_ids = array_column($form_fields, 'FieldID');
            if ($current_ids) {
                $placeholders = implode(',', array_fill(0, count($current_ids), '?'));
                $pdo->prepare("DELETE FROM application_custom_answers WHERE ApplicationID = ? AND FieldID IN ($placeholders)")
                    ->execute(array_merge([$app_id], $current_ids));
            }
            $ans_stmt = $pdo->prepare("INSERT INTO application_custom_answers (ApplicationID, FieldID, AnswerText) VALUES (?, ?, ?)");
            foreach ($form_answers as $fieldId => $answerText) {
                $ans_stmt->execute([$app_id, $fieldId, $answerText]);
            }

            // ✨ 3. NEW: HANDLE DOCUMENT UPLOADS FROM THE EDIT MODAL
            if (isset($_FILES['files'])) {
                $upload_dir = '../uploads/documents/';
                if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

                foreach ($_FILES['files']['tmp_name'] as $req_id => $tmp_name) {
                    if ($_FILES['files']['error'][$req_id] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['files']['name'][$req_id];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        
                        // Force a clean, unique file name to avoid overlaps
                        $new_file_name = "STU-" . $user_id . "-APP-" . $app_id . "-REQ-" . $req_id . "-" . uniqid() . "." . $file_ext;
                        $destination = $upload_dir . $new_file_name;

                        if (move_uploaded_file($tmp_name, $destination)) {
                            // Check if this document requirement already has an uploaded file
                            $check_doc = $pdo->prepare("SELECT SubmittedDocID, FilePath FROM submitted_document WHERE ApplicationID = ? AND RequirementID = ?");
                            $check_doc->execute([$app_id, $req_id]);
                            $existing = $check_doc->fetch();

                            if ($existing) {
                                // Update DB record
                                $upd_doc = $pdo->prepare("UPDATE submitted_document SET FilePath = ?, VerificationStatus = 'Pending', UploadDate = NOW() WHERE SubmittedDocID = ?");
                                $upd_doc->execute([$destination, $existing['SubmittedDocID']]);
                                recordDocumentVersion($pdo, (int) $existing['SubmittedDocID'], (int) $app_id, (int) $req_id, $destination, $file_name, $user_id, 'Replaced');
                            } else {
                                // Insert new DB record
                                $ins_doc = $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')");
                                $ins_doc->execute([$app_id, $req_id, $destination]);
                                recordDocumentVersion($pdo, (int) $pdo->lastInsertId(), (int) $app_id, (int) $req_id, $destination, $file_name, $user_id, 'Uploaded');
                            }
                        }
                    }
                }
            }

            $resubmitted = in_array($owned_app['Status'], ['For Resubmission', 'Withdrawn'], true);
            if ($resubmitted) {
                $pdo->prepare("UPDATE application SET Status = 'Submitted', DateSubmitted = NOW(), WithdrawnAt = NULL, ResubmissionAllowed = 0 WHERE ApplicationID = ?")->execute([$app_id]);
            }
            snapshotApplicationVersion($pdo, (int) $app_id, $resubmitted ? 'Resubmitted' : 'Updated', $user_id);
            logAudit($pdo, $user_id, $resubmitted ? 'Application Resubmitted' : 'Application Updated', "Updated application #$app_id");
            $_SESSION['success'] = $resubmitted ? "Corrected application resubmitted successfully!" : "Application updated successfully!";
        } catch (PDOException $e) { 
            $_SESSION['error'] = "Update Error: " . $e->getMessage(); 
        }
        
        header("Location: ../student/applications.php");
        exit();
    }
}

// ==========================================
// MODULE: STUDENT DOCUMENT UPLOADS
// ==========================================
if ($module === 'student_documents') {
    $user_id = (int) $_SESSION['user_id'];
    // Compatibility path for older forms: enforce ownership and route changes through version tracking.
    if (in_array($action, ['upload', 'delete'], true)) {
        $application_id = (int) ($_POST['application_id'] ?? 0);
        $document_id = (int) ($_POST['doc_id'] ?? 0);
        if ($action === 'delete' && $document_id) {
            $lookup = $pdo->prepare("SELECT sd.ApplicationID FROM submitted_document sd JOIN application a ON a.ApplicationID = sd.ApplicationID WHERE sd.SubmittedDocID = ? AND a.UserID = ?");
            $lookup->execute([$document_id, $user_id]);
            $application_id = (int) $lookup->fetchColumn();
        }
        $owned = $pdo->prepare("SELECT a.ScholarshipID, a.Status, a.ResubmissionAllowed, s.Deadline FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.ApplicationID = ? AND a.UserID = ?");
        $owned->execute([$application_id, $user_id]);
        $application = $owned->fetch(PDO::FETCH_ASSOC);
        $before_deadline = $application && strtotime($application['Deadline']) >= strtotime(date('Y-m-d'));
        $editable = $application && ($application['Status'] === 'For Resubmission'
            || (in_array($application['Status'], ['Draft', 'Submitted'], true) && $before_deadline)
            || ($application['Status'] === 'Withdrawn' && (int) $application['ResubmissionAllowed'] && $before_deadline));
        if (!$editable) {
            $_SESSION['error'] = 'This application is not open for document changes.';
            header('Location: ../student/requirements.php');
            exit();
        }

        try {
            $pdo->beginTransaction();
            if ($action === 'upload') {
                $requirement_id = (int) ($_POST['requirement_id'] ?? 0);
                $_FILES['files'] = [
                    'name' => [$requirement_id => $_FILES['document']['name'] ?? ''],
                    'type' => [$requirement_id => $_FILES['document']['type'] ?? ''],
                    'tmp_name' => [$requirement_id => $_FILES['document']['tmp_name'] ?? ''],
                    'error' => [$requirement_id => $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE],
                    'size' => [$requirement_id => $_FILES['document']['size'] ?? 0],
                ];
                saveRequestedApplicationDocuments($pdo, $application_id, (int) $application['ScholarshipID'], $user_id);
                snapshotApplicationVersion($pdo, $application_id, 'Document Updated', $user_id, "Requirement #$requirement_id updated");
                $_SESSION['success'] = 'Document saved and added to the version history.';
            } else {
                $doc = $pdo->prepare("SELECT SubmittedDocID, RequirementID, FilePath, VerificationStatus FROM submitted_document WHERE SubmittedDocID = ? AND ApplicationID = ?");
                $doc->execute([$document_id, $application_id]);
                $row = $doc->fetch(PDO::FETCH_ASSOC);
                if (!$row || $row['VerificationStatus'] === 'Verified') throw new RuntimeException('A verified or unavailable document cannot be removed.');
                recordDocumentVersion($pdo, $document_id, $application_id, (int) $row['RequirementID'], $row['FilePath'], basename($row['FilePath']), $user_id, 'Removed');
                $pdo->prepare("DELETE FROM submitted_document WHERE SubmittedDocID = ?")->execute([$document_id]);
                snapshotApplicationVersion($pdo, $application_id, 'Document Removed', $user_id, "Requirement #{$row['RequirementID']} removed");
                $_SESSION['success'] = 'Document removed from the current application; its history was retained.';
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
        header('Location: ../student/requirements.php');
        exit();
    }
    
    // --- ACTION: UPLOAD / REPLACE ---
    if ($action === 'upload') {
        $application_id = $_POST['application_id'];
        $requirement_id = $_POST['requirement_id'];
        $user_id = $_SESSION['user_id'];
        
        $upload_dir = '../uploads/documents/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['document']['tmp_name'];
            $file_name = $_FILES['document']['name'];
            $file_size = $_FILES['document']['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];
            if (!in_array($file_ext, $allowed_exts)) {
                $_SESSION['error'] = "Invalid file type. Only PDF, JPG, and PNG are allowed.";
                header("Location: ../student/requirements.php"); exit();
            }

            if ($file_size > 5 * 1024 * 1024) {
                $_SESSION['error'] = "File is too large. Maximum size is 5MB.";
                header("Location: ../student/requirements.php"); exit();
            }

            $new_file_name = "APP" . $application_id . "_REQ" . $requirement_id . "_" . time() . "." . $file_ext;
            $destination = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp, $destination)) {
                try {
                    $check_stmt = $pdo->prepare("SELECT SubmittedDocID, FilePath FROM submitted_document WHERE ApplicationID = ? AND RequirementID = ?");
                    $check_stmt->execute([$application_id, $requirement_id]);
                    $existing_doc = $check_stmt->fetch();

                    if ($existing_doc) {
                        if (file_exists($existing_doc['FilePath'])) { unlink($existing_doc['FilePath']); }
                        
                        $update_stmt = $pdo->prepare("UPDATE submitted_document SET FilePath = ?, VerificationStatus = 'Pending', UploadDate = NOW() WHERE SubmittedDocID = ?");
                        $update_stmt->execute([$destination, $existing_doc['SubmittedDocID']]);
                        $_SESSION['success'] = "Document successfully replaced! It is now pending review.";
                    } else {
                        $insert_stmt = $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')");
                        $insert_stmt->execute([$application_id, $requirement_id, $destination]);
                        $_SESSION['success'] = "Document uploaded successfully! It is now pending review.";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Database Error: " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = "Failed to move uploaded file to server.";
            }
        } else {
            $_SESSION['error'] = "Please select a valid file to upload.";
        }
        
        header("Location: ../student/requirements.php");
        exit();
    }

    // --- ACTION: DELETE ---
    if ($action === 'delete') {
        $doc_id = $_POST['doc_id'];

        try {
            $stmt = $pdo->prepare("SELECT FilePath, VerificationStatus FROM submitted_document WHERE SubmittedDocID = ?");
            $stmt->execute([$doc_id]);
            $doc = $stmt->fetch();

            if ($doc) {
                if ($doc['VerificationStatus'] === 'Verified') {
                    $_SESSION['error'] = "Action Denied: You cannot delete a document after it has been verified by the university.";
                } else {
                    if (file_exists($doc['FilePath'])) {
                        unlink($doc['FilePath']);
                    }
                    $del_stmt = $pdo->prepare("DELETE FROM submitted_document WHERE SubmittedDocID = ?");
                    $del_stmt->execute([$doc_id]);
                    $_SESSION['success'] = "Document was successfully deleted.";
                }
            } else {
                $_SESSION['error'] = "Document not found.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database Error: " . $e->getMessage();
        }
        header("Location: ../student/requirements.php");
        exit();
    }
}

// ==========================================
// MODULE: EXTERNAL ADMIN SCORING
// ==========================================
if ($module === 'applications' && $action === 'score') {
    $application_id = (int) $_POST['id'];
    $total_score = $_POST['score'] ?? '';

    // A merit score is 0-100, and only applications still waiting for one can be scored
    // (re-scoring an approved or rejected applicant would quietly move them back to Shortlisted)
    $current = $pdo->prepare("SELECT Status FROM application WHERE ApplicationID = ?");
    $current->execute([$application_id]);
    $current_status = $current->fetchColumn();
    $score_error = !is_numeric($total_score) || $total_score < 0 || $total_score > 100 ? "The score must be a number from 0 to 100."
        : (!in_array($current_status, ['Pending', 'Submitted', 'Under Review', 'Shortlisted'], true) ? "This application is already $current_status, so it can no longer be scored." : null);
    if ($score_error) {
        $_SESSION['error'] = $score_error;
        header("Location: " . ($_SESSION['role'] === 'External_Admin' ? '../external_admin/score.php' : '../super_admin/manage_applications.php'));
        exit();
    }
    $total_score = round((float) $total_score, 2);

    try {
        $stmt = $pdo->prepare("
            UPDATE application 
            SET TotalScore = :score, 
                Status = 'Shortlisted' 
            WHERE ApplicationID = :id
        ");
        $stmt->execute(['score' => $total_score, 'id' => $application_id]);
        
        // ✨ NEW: NOTIFY THE STUDENT!
        $info_stmt = $pdo->prepare("SELECT a.UserID, sch.Name FROM application a JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID WHERE a.ApplicationID = ?");
        $info_stmt->execute([$application_id]);
        $info = $info_stmt->fetch();
        if ($info) {
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, ?, ?, ?)");
            $notif_stmt->execute([
                $info['UserID'],
                "Application Evaluated! ✍️",
                "Your application for the " . $info['Name'] . " has been officially scored and Shortlisted by an evaluator.",
                "info"
            ]);
        }

        $_SESSION['success'] = "Application successfully scored ($total_score/100) and Shortlisted!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database Error: " . $e->getMessage();
    }
    header("Location: ../external_admin/score.php");
    exit();
}

// ==========================================
// MODULE: STATUS UPDATES (INTERNAL ADMIN)
// ==========================================
if ($module === 'applications' && $action === 'update_status') {
    $app_id = $_POST['id'] ?? null;
    $status = $_POST['status'] ?? '';
    $allowed_statuses = ['Submitted', 'Under Review', 'For Resubmission', 'Shortlisted', 'Approved', 'Rejected', 'Disqualified'];
    if (!in_array($status, $allowed_statuses, true)) {
        $_SESSION['error'] = 'Invalid application status.';
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '../admin_login.php'));
        exit();
    }
    
    // Slots and single-grant rules (includes/approval_rules.php)
    if ($app_id && $status === 'Approved') {
        require_once '../includes/approval_rules.php';
        if ($blocked = approvalBlocker($pdo, (int) $app_id)) {
            $_SESSION['error'] = "Not approved: $blocked";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? ($_SESSION['role'] === 'Super_Admin' ? '../super_admin/scholarships.php' : '../external_admin/scholarships.php')));
            exit();
        }
    }

    if ($app_id && !empty($status)) {
        try {
            $appeal_deadline = null;
            if (in_array($status, ['Rejected', 'Disqualified'], true)) {
                $appeal_deadline = setApplicationDecision($pdo, (int) $app_id, $status);
                $pdo->prepare("UPDATE application SET ResubmissionAllowed = 0, LastSavedAt = NOW() WHERE ApplicationID = ?")->execute([$app_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE application SET Status = :status, ResubmissionAllowed = :allowed, LastSavedAt = NOW() WHERE ApplicationID = :id");
                $stmt->execute(['status' => $status, 'allowed' => $status === 'For Resubmission' ? 1 : 0, 'id' => $app_id]);
            }
            snapshotApplicationVersion($pdo, (int) $app_id, $status === 'For Resubmission' ? 'Correction Requested' : 'Status Changed', (int) $_SESSION['user_id'], "Status changed to $status");

            $info_stmt = $pdo->prepare("
                SELECT u.Email, u.FirstName, sch.Name AS scholarship_name, sch.ScholarshipID, sch.NumberOfSlots, sch.CreatedBy, a.UserID 
                FROM application a
                JOIN users u ON a.UserID = u.UserID
                JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
                WHERE a.ApplicationID = :aid
            ");
            $info_stmt->execute(['aid' => $app_id]);
            $info = $info_stmt->fetch();

            if ($info && $status === 'Approved') {
                // ✨ Approved: the student gets their Approval Notice (email with PDF + portal notification)
                require_once '../includes/award_notice.php';
                notifyAwardee($pdo, (int) $app_id);
            } elseif ($info) {
                // ✨ LIGTAS NA EMAIL SENDER: Hindi magka-crash ang system kahit walang internet o SMTP!
                try {
                    if (!empty($info['Email']) && function_exists('sendScholarshipEmail')) {
                        sendScholarshipEmail($info['Email'], $info['FirstName'], $info['scholarship_name'], $status);
                    }
                } catch (Exception $mail_e) {
                    error_log("Email sending failed: " . $mail_e->getMessage());
                }

                // In-App Notification
                try {
                    $notif_title = "Status Update: " . $status;
                    $notif_msg = $status === 'For Resubmission'
                        ? "Corrections are required for your " . $info['scholarship_name'] . " application. Open My Applications, edit the requested documents, then resubmit."
                        : (in_array($status, ['Rejected', 'Disqualified'], true)
                            ? "Your application for the {$info['scholarship_name']} was $status. You may file a formal appeal from My Applications until $appeal_deadline."
                            : "Your application for the " . $info['scholarship_name'] . " has been updated to: " . $status . ".");
                    $notif_type = in_array($status, ['Rejected', 'Disqualified'], true) ? 'danger' : (($status === 'Approved') ? 'success' : 'info');
                    
                    $notif_stmt = $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (:uid, :title, :msg, :type)");
                    $notif_stmt->execute(['uid' => $info['UserID'], 'title' => $notif_title, 'msg' => $notif_msg, 'type' => $notif_type]);
                } catch (Exception $e) {
                    error_log("Notification Error: " . $e->getMessage());
                }
            }

            if ($info) {
                // AUTO-DEACTIVATION CHECK
                if ($status === 'Approved' && $info['NumberOfSlots'] > 0) {
                    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM application WHERE ScholarshipID = ? AND Status = 'Approved'");
                    $count_stmt->execute([$info['ScholarshipID']]);
                    $approved_count = $count_stmt->fetchColumn();

                    if ($approved_count >= $info['NumberOfSlots']) {
                        $pdo->prepare("UPDATE scholarship SET Status = 'Inactive' WHERE ScholarshipID = ?")->execute([$info['ScholarshipID']]);

                        $ext_msg = "Your scholarship program '{$info['scholarship_name']}' is now FULL ({$approved_count}/{$info['NumberOfSlots']} slots) and has been automatically deactivated.";
                        $notif = $pdo->prepare("INSERT INTO system_notifications (RecipientID, Title, Message) VALUES (?, 'Scholarship Full', ?)");
                        $notif->execute([$info['CreatedBy'], $ext_msg]);

                        $int_msg = "The scholarship program '{$info['scholarship_name']}' has reached its maximum quota ({$approved_count}/{$info['NumberOfSlots']} slots) and was auto-deactivated.";
                        $internal_admins = $pdo->query("SELECT UserID FROM users WHERE Role = 'Internal_Admin'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach($internal_admins as $admin_id) { 
                            $notif->execute([$admin_id, $int_msg]); 
                        }
                    }
                }
            }
            $_SESSION['success'] = "Status updated to $status and student notified via email & portal.";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    }
    
    $redirect = $_SERVER['HTTP_REFERER'] ?? ($_SESSION['role'] === 'Super_Admin' ? '../super_admin/scholarships.php' : '../external_admin/scholarships.php');
    header("Location: " . $redirect);
    exit();
}

// ==========================================
// MODULE: EXTERNAL ADMIN & SUPER ADMIN SCHOLARSHIPS (100% UNIFIED BUILDER)
// ==========================================
if ($module === 'scholarships') {
    require_once '../includes/scholarship_categories.php';

    // Only categories from the shared list are accepted
    function scholarshipCategoryFromPost(): string {
        $category = $_POST['category'] ?? '';
        return array_key_exists($category, SCHOLARSHIP_CATEGORIES) ? $category : DEFAULT_SCHOLARSHIP_CATEGORY;
    }

    if ($action === 'create') {
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        $amount = $_POST['award_amount'];
        $release = $_POST['release_frequency'] ?? 'Per Semester';
        $slots = (isset($_POST['slots']) && $_POST['slots'] !== '') ? $_POST['slots'] : null; 
        $min_gpa = $_POST['min_gpa'];
        $deadline = $_POST['deadline'];
        
        $year_level = null;
        if (isset($_POST['year_levels']) && is_array($_POST['year_levels'])) {
            if (!in_array('all', $_POST['year_levels']) && !empty($_POST['year_levels'])) {
                $year_level = implode(', ', array_map('trim', $_POST['year_levels']));
            }
        }
        $scholarship_type = $_POST['scholarship_type'] ?? 'Private';
        $category = scholarshipCategoryFromPost();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO scholarship (Name, Description, AwardAmount, ReleaseFrequency, NumberOfSlots, MinimumGWA, Deadline, YearLevel, Status, CreatedBy, ScholarshipType, Category)
                VALUES (:name, :desc, :amount, :release, :slots, :gpa, :deadline, :yl, 'Active', :created_by, :type, :category)
            ");
            $stmt->execute([
                'name' => $name, 'desc' => $desc, 'amount' => $amount, 'release' => $release,
                'slots' => $slots, 'gpa' => $min_gpa, 'deadline' => $deadline, 'yl' => $year_level,
                'created_by' => $_SESSION['user_id'], 'type' => $scholarship_type, 'category' => $category
            ]);

            $new_scholarship_id = $pdo->lastInsertId();

            if (!empty($_POST['new_dynamic_names']) && is_array($_POST['new_dynamic_names'])) {
                $req_stmt   = $pdo->prepare("INSERT INTO document_requirement (ScholarshipID, DocumentName) VALUES (?, ?)");
                $crit_stmt  = $pdo->prepare("INSERT INTO scholarship_criteria (ScholarshipID, CriteriaName) VALUES (?, ?)");
                $field_stmt = $pdo->prepare("INSERT INTO scholarship_custom_fields (ScholarshipID, FieldName, FieldType, PDFFieldName) VALUES (?, ?, ?, ?)");

                for($i = 0; $i < count($_POST['new_dynamic_names']); $i++) {
                    $row_name = trim($_POST['new_dynamic_names'][$i]);
                    $row_type = $_POST['new_dynamic_types'][$i] ?? 'Text';
                    $pdf_field_name = !empty($_POST['new_pdf_field_names'][$i]) ? trim($_POST['new_pdf_field_names'][$i]) : null;

                    if ($row_name !== '') {
                        if ($row_type === 'Document') {
                            $req_stmt->execute([$new_scholarship_id, $row_name]);
                        } elseif ($row_type === 'Criteria') {
                            $crit_stmt->execute([$new_scholarship_id, $row_name]);
                        } else {
                            // ✨ DETERMINE EXACT DB FIELD TYPE
                            $db_type = 'Text';
                            if ($row_type === 'Paragraph') {
                                $db_type = 'Textarea';
                            } elseif ($row_type === 'Number' || $row_type === 'Date') {
                                $db_type = $row_type;
                            } elseif (strpos($row_type, 'Auto_') === 0) {
                                $db_type = $row_type; // Capture Auto_ values (Auto_Religion, Auto_Gender, etc.)
                            }
                            
                            // Execute strictly ONCE with 4 correct arguments
                            $field_stmt->execute([$new_scholarship_id, $row_name, $db_type, $pdf_field_name]);
                        }
                    }
                }
            }

            if (isset($_POST['program_ids']) && is_array($_POST['program_ids'])) {
                if (!in_array('all', $_POST['program_ids'])) {
                    $prog_bridge = $pdo->prepare("INSERT INTO scholarship_programs (ScholarshipID, ProgramID) VALUES (?, ?)");
                    foreach ($_POST['program_ids'] as $pid) {
                        $prog_bridge->execute([$new_scholarship_id, $pid]);
                    }
                }
            }

            $pdo->commit();
            $_SESSION['success'] = "Scholarship successfully launched with custom dynamic fields and multi-course rules!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Database Error: " . $e->getMessage();
        }
        $redirect = $_SERVER['HTTP_REFERER'] ?? ($_SESSION['role'] === 'Super_Admin' ? '../super_admin/scholarships.php' : '../external_admin/scholarships.php');
        header("Location: " . $redirect);
        exit();
    }

    if ($action === 'edit') {
        $id = $_POST['scholarship_id'];
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        $amount = $_POST['award_amount'];
        $release = $_POST['release_frequency'] ?? 'Per Semester';
        $slots = (isset($_POST['slots']) && $_POST['slots'] !== '') ? $_POST['slots'] : null; 
        $min_gpa = $_POST['min_gpa'];
        $deadline = $_POST['deadline'];
        $status = $_POST['status'];
        
        $gender = $_POST['gender_req'] ?? 'Any';
        $allows_dual = $_POST['allows_dual'] ?? 'No';

        $year_level = null;
        if (isset($_POST['year_levels']) && is_array($_POST['year_levels'])) {
            if (!in_array('all', $_POST['year_levels']) && !empty($_POST['year_levels'])) {
                $year_level = implode(', ', array_map('trim', $_POST['year_levels']));
            }
        }
        $scholarship_type = $_POST['scholarship_type'] ?? 'Private';
        $category = scholarshipCategoryFromPost();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE scholarship SET Name = :name, Description = :desc, AwardAmount = :amount, ReleaseFrequency = :release, NumberOfSlots = :slots, MinimumGWA = :gpa, Deadline = :deadline, YearLevel = :yl, Status = :status, ScholarshipType = :type, Category = :category, GenderRequirement = :gender, AllowsDual = :dual WHERE ScholarshipID = :id");
            $stmt->execute(['name' => $name, 'desc' => $desc, 'amount' => $amount, 'release' => $release, 'slots' => $slots, 'gpa' => $min_gpa, 'deadline' => $deadline, 'yl' => $year_level, 'status' => $status, 'type' => $scholarship_type, 'category' => $category, 'gender' => $gender, 'dual' => $allows_dual, 'id' => $id]);

            // Application questions (scholarship_custom_fields) are owned by the Form Builder
            // (actions/save_application_form.php), so this edit only touches documents and criteria
            $field_categories = ['Req' => 'Document', 'Crit' => 'Criteria'];

            foreach ($field_categories as $cat_prefix => $original_js_type) {
                if (isset($_POST['existing_' . $cat_prefix . '_ids'])) {
                    for ($i = 0; $i < count($_POST['existing_' . $cat_prefix . '_ids']); $i++) {
                        $field_id = $_POST['existing_' . $cat_prefix . '_ids'][$i];
                        $new_name = trim($_POST['existing_' . $cat_prefix . '_names'][$i]);
                        $new_type = $_POST['existing_' . $cat_prefix . '_types'][$i];

                        if ($new_name === '') continue;

                        $current_js_type = $original_js_type;
                        if ($cat_prefix === 'Custom') {
                            $type_stmt = $pdo->prepare("SELECT FieldType FROM scholarship_custom_fields WHERE FieldID = ?");
                            $type_stmt->execute([$field_id]);
                            $db_type = $type_stmt->fetchColumn();
                            if ($db_type === 'Textarea') $current_js_type = 'Paragraph';
                            else $current_js_type = $db_type; 
                        }

                        if ($new_type === $current_js_type) {
                            if ($cat_prefix === 'Req') {
                                $pdo->prepare("UPDATE document_requirement SET DocumentName = ? WHERE RequirementID = ?")->execute([$new_name, $field_id]);
                            } elseif ($cat_prefix === 'Crit') {
                                $pdo->prepare("UPDATE scholarship_criteria SET CriteriaName = ? WHERE CriteriaID = ?")->execute([$new_name, $field_id]);
                            } elseif ($cat_prefix === 'Custom') {
                                $db_type = 'Text';
                                if ($new_type === 'Paragraph') $db_type = 'Textarea';
                                elseif ($new_type === 'Number') $db_type = 'Number';
                                elseif ($new_type === 'Date') $db_type = 'Date';
                                elseif (strpos($new_type, 'Auto_') === 0) $db_type = $new_type;
                                
                                // ✨ SALUHIN ANG PDF FIELD NAME
                                $new_pdf = !empty($_POST['existing_Custom_pdfnames'][$i]) ? trim($_POST['existing_Custom_pdfnames'][$i]) : null;

                                $pdo->prepare("UPDATE scholarship_custom_fields SET FieldName = ?, FieldType = ?, PDFFieldName = ? WHERE FieldID = ?")->execute([$new_name, $db_type, $new_pdf, $field_id]);
                            }
                        } else {
                            if ($cat_prefix === 'Req') $pdo->prepare("DELETE FROM document_requirement WHERE RequirementID = ?")->execute([$field_id]);
                            if ($cat_prefix === 'Crit') $pdo->prepare("DELETE FROM scholarship_criteria WHERE CriteriaID = ?")->execute([$field_id]);
                            if ($cat_prefix === 'Custom') $pdo->prepare("DELETE FROM scholarship_custom_fields WHERE FieldID = ?")->execute([$field_id]);

                            if ($new_type === 'Document') {
                                $pdo->prepare("INSERT INTO document_requirement (ScholarshipID, DocumentName) VALUES (?, ?)")->execute([$id, $new_name]);
                            } elseif ($new_type === 'Criteria') {
                                $pdo->prepare("INSERT INTO scholarship_criteria (ScholarshipID, CriteriaName) VALUES (?, ?)")->execute([$id, $new_name]);
                            } else { 
                                $db_type = 'Text';
                                if ($new_type === 'Paragraph') $db_type = 'Textarea';
                                elseif ($new_type === 'Number') $db_type = 'Number';
                                elseif ($new_type === 'Date') $db_type = 'Date';
                                elseif (strpos($new_type, 'Auto_') === 0) $db_type = $new_type;
                                
                                // ✨ SALUHIN ANG PDF FIELD NAME
                                $new_pdf = !empty($_POST['existing_Custom_pdfnames'][$i]) ? trim($_POST['existing_Custom_pdfnames'][$i]) : null;

                                $pdo->prepare("INSERT INTO scholarship_custom_fields (ScholarshipID, FieldName, FieldType, PDFFieldName) VALUES (?, ?, ?, ?)")->execute([$id, $new_name, $db_type, $new_pdf]);
                            }
                        }
                    }
                }
            }

            if (!empty($_POST['delete_Req_ids'])) {
                $del = $pdo->prepare("DELETE FROM document_requirement WHERE RequirementID = ? AND ScholarshipID = ?");
                foreach ($_POST['delete_Req_ids'] as $del_id) { $del->execute([$del_id, $id]); }
            }
            if (!empty($_POST['delete_Crit_ids'])) {
                $del = $pdo->prepare("DELETE FROM scholarship_criteria WHERE CriteriaID = ? AND ScholarshipID = ?");
                foreach ($_POST['delete_Crit_ids'] as $del_id) { $del->execute([$del_id, $id]); }
            }

            if (!empty($_POST['new_dynamic_names']) && is_array($_POST['new_dynamic_names'])) {
                $req_stmt   = $pdo->prepare("INSERT INTO document_requirement (ScholarshipID, DocumentName) VALUES (?, ?)");
                $crit_stmt  = $pdo->prepare("INSERT INTO scholarship_criteria (ScholarshipID, CriteriaName) VALUES (?, ?)");
                $field_stmt = $pdo->prepare("INSERT INTO scholarship_custom_fields (ScholarshipID, FieldName, FieldType, PDFFieldName) VALUES (?, ?, ?, ?)");

                for($i = 0; $i < count($_POST['new_dynamic_names']); $i++) {
                    $row_name = trim($_POST['new_dynamic_names'][$i]);
                    $row_type = $_POST['new_dynamic_types'][$i] ?? 'Text';
                    $pdf_field_name = !empty($_POST['new_pdf_field_names'][$i]) ? trim($_POST['new_pdf_field_names'][$i]) : null;

                    if ($row_name !== '') {
                        if ($row_type === 'Document') { 
                            $req_stmt->execute([$id, $row_name]); 
                        } elseif ($row_type === 'Criteria') { 
                            $crit_stmt->execute([$id, $row_name]); 
                        } else {
                            $db_type = 'Text';
                            if ($row_type === 'Paragraph') $db_type = 'Textarea';
                            elseif ($row_type === 'Number') $db_type = 'Number';
                            elseif ($row_type === 'Date') $db_type = 'Date';
                            elseif (strpos($row_type, 'Auto_') === 0) $db_type = $row_type; // ✨ SALUHIN ANG AUTO-FILL!
                            
                            $field_stmt->execute([$id, $row_name, $db_type, $pdf_field_name]);
                        }
                    }
                }
            }

            $del_prog = $pdo->prepare("DELETE FROM scholarship_programs WHERE ScholarshipID = ?");
            $del_prog->execute([$id]);

            if (isset($_POST['program_ids']) && is_array($_POST['program_ids'])) {
                if (!in_array('all', $_POST['program_ids'])) {
                    $prog_bridge = $pdo->prepare("INSERT INTO scholarship_programs (ScholarshipID, ProgramID) VALUES (?, ?)");
                    foreach ($_POST['program_ids'] as $pid) {
                        $prog_bridge->execute([$id, $pid]);
                    }
                }
            }

            $pdo->commit();
            $_SESSION['success'] = "Scholarship successfully updated with multi-course rules!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Database Error: " . $e->getMessage();
        }
        $redirect = $_SERVER['HTTP_REFERER'] ?? ($_SESSION['role'] === 'Super_Admin' ? '../super_admin/scholarships.php' : '../external_admin/scholarships.php');
        header("Location: " . $redirect);
        exit();
    }
    
    if ($action === 'delete_single') {
        $id = $_POST['scholarship_id'] ?? null;
        $current_admin_id = $_SESSION['user_id'] ?? $_SESSION['UserID'] ?? null;

        if ($id) {
            try {
                $pdo->beginTransaction();

                // 1. Delete uploaded physical files & records tied to applications of this scholarship
                $app_stmt = $pdo->prepare("SELECT ApplicationID FROM application WHERE ScholarshipID = ?");
                $app_stmt->execute([$id]);
                $app_ids = $app_stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($app_ids)) {
                    $in_query = implode(',', array_fill(0, count($app_ids), '?'));

                    // Remove physical uploaded documents from server
                    $doc_stmt = $pdo->prepare("SELECT FilePath FROM submitted_document WHERE ApplicationID IN ($in_query)");
                    $doc_stmt->execute($app_ids);
                    while ($doc = $doc_stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($doc['FilePath']) && file_exists($doc['FilePath'])) {
                            unlink($doc['FilePath']);
                        }
                    }

                    $pdo->prepare("DELETE FROM submitted_document WHERE ApplicationID IN ($in_query)")->execute($app_ids);
                    $pdo->prepare("DELETE FROM application_custom_answers WHERE ApplicationID IN ($in_query)")->execute($app_ids);
                    $pdo->prepare("DELETE FROM scholar_renewals WHERE ApplicationID IN ($in_query)")->execute($app_ids);
                    $pdo->prepare("DELETE FROM application WHERE ScholarshipID = ?")->execute([$id]);
                }

                // 2. Delete dynamic requirements, criteria, custom fields, and program mappings
                $pdo->prepare("DELETE FROM document_requirement WHERE ScholarshipID = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM scholarship_criteria WHERE ScholarshipID = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM scholarship_custom_fields WHERE ScholarshipID = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM scholarship_programs WHERE ScholarshipID = ?")->execute([$id]);

                // 3. ✨ Delete the scholarship (Super Admin can delete any; External Admin only their own)
                if ($_SESSION['role'] === 'Super_Admin') {
                    $del_stmt = $pdo->prepare("DELETE FROM scholarship WHERE ScholarshipID = ?");
                    $del_stmt->execute([$id]);
                } else {
                    $del_stmt = $pdo->prepare("DELETE FROM scholarship WHERE ScholarshipID = ? AND CreatedBy = ?");
                    $del_stmt->execute([$id, $current_admin_id]);
                }

                $pdo->commit();
                $_SESSION['success'] = "Scholarship and all associated records were permanently deleted.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['error'] = "Delete Error: " . $e->getMessage();
            }
        }
        $redirect = $_SERVER['HTTP_REFERER'] ?? ($_SESSION['role'] === 'Super_Admin' ? '../super_admin/scholarships.php' : '../external_admin/scholarships.php');
        header("Location: " . $redirect);
        exit();
    }
}

// ==========================================
// MODULE: DOCUMENT VERIFICATION (EXTERNAL ADMIN & SUPER ADMIN)
// ==========================================
if ($module === 'verification') {
    $document_id = $_POST['id'] ?? null;
    $status = $_POST['status'] ?? '';

    // Providers may only verify documents of their own scholarships' applicants
    if ($document_id && ($_SESSION['role'] ?? '') === 'External_Admin') {
        require_once '../includes/application_authority.php';
        $owner = $pdo->prepare("SELECT ApplicationID FROM submitted_document WHERE SubmittedDocID = ?");
        $owner->execute([$document_id]);
        $authority = getApplicationAuthority($pdo, (int) $owner->fetchColumn());
        if (!$authority || !canDecideApplication($authority, 'External_Admin', $_SESSION['user_id'])) {
            $_SESSION['error'] = "You can only verify documents for your own scholarships.";
            header("Location: ../external_admin/verify.php");
            exit();
        }
    }

    if ($document_id && in_array($status, ['Verified', 'Rejected'])) {
        try {
            $pdo->prepare("UPDATE submitted_document SET VerificationStatus = :status WHERE SubmittedDocID = :did")->execute(['status' => $status, 'did' => $document_id]);
            $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, ?, NOW(), ?, ?)")->execute([$_SESSION['user_id'], "Document $status", "Evaluator marked Document #$document_id as $status", $_SERVER['REMOTE_ADDR']]);

            $info_stmt = $pdo->prepare("SELECT a.ApplicationID, a.UserID, dr.DocumentName, sch.Name AS ScholarshipName FROM submitted_document sd JOIN application a ON sd.ApplicationID = a.ApplicationID JOIN document_requirement dr ON sd.RequirementID = dr.RequirementID JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID WHERE sd.SubmittedDocID = ?");
            $info_stmt->execute([$document_id]);
            $info = $info_stmt->fetch();
            
            if ($info) {
                if ($status === 'Rejected') {
                    $pdo->prepare("UPDATE application SET Status = 'For Resubmission', ResubmissionAllowed = 1, LastSavedAt = NOW() WHERE ApplicationID = ? AND Status NOT IN ('Approved', 'Rejected', 'Withdrawn')")
                        ->execute([$info['ApplicationID']]);
                    snapshotApplicationVersion($pdo, (int) $info['ApplicationID'], 'Correction Requested', (int) $_SESSION['user_id'], "Rejected document: {$info['DocumentName']}");
                }
                $type = ($status === 'Verified') ? 'success' : 'danger';
                $title = ($status === 'Verified') ? 'Document Verified ✅' : 'Document Rejected 🛑';
                $msg = $status === 'Rejected'
                    ? "Your document '" . $info['DocumentName'] . "' for the " . $info['ScholarshipName'] . " needs correction. Replace it in My Applications and resubmit."
                    : "Your document '" . $info['DocumentName'] . "' for the " . $info['ScholarshipName'] . " has been officially verified.";
                $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, ?, ?, ?)")->execute([$info['UserID'], $title, $msg, $type]);
            }
            $_SESSION['success'] = "Document #$document_id successfully marked as $status.";
        } catch (PDOException $e) { $_SESSION['error'] = "Verification Error: " . $e->getMessage(); }
    }
    $redirect = $_SERVER['HTTP_REFERER'] ?? '../external_admin/verify.php';
    header("Location: " . $redirect);
    exit();
}

// ==========================================
// MODULE: INTERNAL ADMIN FINAL DECISIONS
// ==========================================
if ($module === 'applications' && $action === 'final_decision') {
    $application_id = $_POST['application_id'];
    $status = $_POST['status'];

    // Slots and single-grant rules (includes/approval_rules.php)
    if ($status === 'Approved') {
        require_once '../includes/approval_rules.php';
        if ($blocked = approvalBlocker($pdo, (int) $application_id)) {
            $_SESSION['error'] = "Not approved: $blocked";
            $decision_pages = ['Internal_Admin' => '../internal_admin/shortlist.php', 'External_Admin' => '../external_admin/manage_applications.php', 'Super_Admin' => '../super_admin/manage_applications.php'];
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? $decision_pages[$_SESSION['role']]));
            exit();
        }
    }

    if (in_array($status, ['Approved', 'Rejected', 'Disqualified'], true)) {
        try {
            $appeal_deadline = setApplicationDecision($pdo, (int) $application_id, $status);
            snapshotApplicationVersion($pdo, (int) $application_id, 'Final Decision', (int) $_SESSION['user_id'], "Application marked as $status");

            $log = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, ?, NOW(), ?, ?)");
            $log->execute([$_SESSION['user_id'], "Application $status", str_replace('_', ' ', $_SESSION['role']) . " marked Application #$application_id as $status", $_SERVER['REMOTE_ADDR']]);

            $info_stmt = $pdo->prepare("
                SELECT a.UserID, u.Email, u.FirstName, sch.Name, sch.ScholarshipID, sch.NumberOfSlots, sch.CreatedBy 
                FROM application a 
                JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID 
                JOIN users u ON a.UserID = u.UserID
                WHERE a.ApplicationID = ?
            ");
            $info_stmt->execute([$application_id]);
            $info = $info_stmt->fetch();
            
            if ($info) {
                if ($status === 'Approved') {
                    // ✨ The student gets their Approval Notice (email with PDF + portal notification)
                    require_once '../includes/award_notice.php';
                    notifyAwardee($pdo, (int) $application_id);
                } else {
                    $notif_stmt = $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, ?, ?, 'danger')");
                    $notif_stmt->execute([$info['UserID'], 'Application Update', "Your application for {$info['Name']} has been reviewed and marked as $status. You may file a formal appeal from My Applications until $appeal_deadline."]);

                    if (!empty($info['Email'])) {
                        sendScholarshipEmail($info['Email'], $info['FirstName'], $info['Name'], $status);
                    }
                }

                // ✨ AUTO-DEACTIVATION CHECK
                if ($status === 'Approved' && $info['NumberOfSlots'] > 0) {
                    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM application WHERE ScholarshipID = ? AND Status = 'Approved'");
                    $count_stmt->execute([$info['ScholarshipID']]);
                    $approved_count = $count_stmt->fetchColumn();

                    if ($approved_count >= $info['NumberOfSlots']) {
                        $pdo->prepare("UPDATE scholarship SET Status = 'Inactive' WHERE ScholarshipID = ?")->execute([$info['ScholarshipID']]);

                        $ext_msg = "Your scholarship program '{$info['Name']}' is now FULL ({$approved_count}/{$info['NumberOfSlots']} slots) and has been automatically deactivated.";
                        $notif = $pdo->prepare("INSERT INTO system_notifications (RecipientID, Title, Message) VALUES (?, 'Scholarship Full', ?)");
                        $notif->execute([$info['CreatedBy'], $ext_msg]);

                        $int_msg = "The scholarship program '{$info['Name']}' has reached its maximum quota ({$approved_count}/{$info['NumberOfSlots']} slots) and was auto-deactivated.";
                        $internal_admins = $pdo->query("SELECT UserID FROM users WHERE Role = 'Internal_Admin'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach($internal_admins as $admin_id) { 
                            $notif->execute([$admin_id, $int_msg]); 
                        }
                    }
                }
            }

            if($status === 'Approved') {
                $_SESSION['success'] = "Application officially Approved! The student is now a Scholar and has been notified via email.";
            } else {
                $_SESSION['success'] = "Application has been $status. The student was notified of the appeal deadline ($appeal_deadline).";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database Error: " . $e->getMessage();
        }
    }
    $decision_pages = ['Internal_Admin' => '../internal_admin/shortlist.php', 'External_Admin' => '../external_admin/manage_applications.php', 'Super_Admin' => '../super_admin/manage_applications.php'];
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? $decision_pages[$_SESSION['role']]));
    exit();
}

// ==========================================
// MODULE: ADMIN ACTIONS (MOA DEACTIVATION NOTICE)
// ==========================================
if ($module === 'super_admin' && $action === 'notify_moa') {
    $scholarship_id = $_POST['scholarship_id'];
    
    try {
        $sch_stmt = $pdo->prepare("SELECT Name, CreatedBy FROM scholarship WHERE ScholarshipID = ?");
        $sch_stmt->execute([$scholarship_id]);
        $sch = $sch_stmt->fetch();

        if ($sch) {
            // Warn External Admin
            $ext_msg = "System Notice: Your scholarship program '{$sch['Name']}' is flagged for deactivation based on the Memorandum of Agreement (MOA). Please review your terms or contact the University Admin.";
            $notif = $pdo->prepare("INSERT INTO system_notifications (RecipientID, Title, Message) VALUES (?, 'MOA Deactivation Notice', ?)");
            $notif->execute([$sch['CreatedBy'], $ext_msg]);
            
            // Notify all Internal Admins
            $int_msg = "The scholarship program '{$sch['Name']}' was flagged for MOA Deactivation. The External Provider has been officially notified.";
            $internal_admins = $pdo->query("SELECT UserID FROM users WHERE Role = 'Internal_Admin'")->fetchAll(PDO::FETCH_COLUMN);
            foreach($internal_admins as $admin_id) { $notif->execute([$admin_id, $int_msg]); }

            $_SESSION['success'] = "MOA Deactivation Notice successfully sent to the External Provider and Internal Admins.";
        }
    } catch (PDOException $e) { $_SESSION['error'] = "Error: " . $e->getMessage(); }
    header("Location: ../super_admin/programs.php");
    exit();
}

if ($module === 'admin_actions' && $action === 'notify_moa') {
    $scholarship_id = $_POST['scholarship_id'];
    
    try {
        $sch_stmt = $pdo->prepare("SELECT Name, CreatedBy FROM scholarship WHERE ScholarshipID = ?");
        $sch_stmt->execute([$scholarship_id]);
        $sch = $sch_stmt->fetch();

        if ($sch) {
            $ext_msg = "System Notice: Your scholarship program '{$sch['Name']}' is flagged for deactivation based on the Memorandum of Agreement (MOA). Please review your terms or contact the University Admin.";
            $notif = $pdo->prepare("INSERT INTO system_notifications (RecipientID, Title, Message) VALUES (?, 'MOA Deactivation Notice', ?)");
            $notif->execute([$sch['CreatedBy'], $ext_msg]);

            $_SESSION['success'] = "MOA Deactivation Notice successfully sent to the External Provider.";
        }
    } catch (PDOException $e) { $_SESSION['error'] = "Error: " . $e->getMessage(); }
    header("Location: ../internal_admin/programs.php");
    exit();
}

// ==========================================
// MODULE: DISMISS SYSTEM NOTIFICATIONS
// ==========================================
if ($module === 'notifications' && $action === 'dismiss') {
    // Only the recipient can dismiss their own notice
    $stmt = $pdo->prepare("UPDATE system_notifications SET IsRead = 1 WHERE NotifID = ? AND RecipientID = ?");
    $stmt->execute([(int) ($_POST['notif_id'] ?? 0), $_SESSION['user_id']]);
    // Back to the page the notice was on (same site only); otherwise the admin's dashboard
    $back = $_SERVER['HTTP_REFERER'] ?? '';
    if (parse_url($back, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        $back = appBaseUrl() . '/' . (dashboardFor($_SESSION['role'] ?? '') ?? 'admin_login.php');
    }
    header("Location: " . $back);
    exit();
}

// ==========================================
// MODULE: REQUIREMENTS (STUDENT)
// ==========================================
if ($module === 'requirements' && $action === 'delete') {
    $document_id = $_POST['document_id'] ?? null;
    $user_id = $_SESSION['user_id'];

    if ($document_id) {
        try {
            $stmt = $pdo->prepare("
                SELECT d.file_path 
                FROM document d
                JOIN application a ON d.application_id = a.application_id
                JOIN student s ON a.student_id = s.student_id
                WHERE d.document_id = :did AND s.user_id = :uid
            ");
            $stmt->execute(['did' => $document_id, 'uid' => $user_id]);
            $doc = $stmt->fetch();

            if ($doc) {
                if (file_exists($doc['file_path'])) { unlink($doc['file_path']); }
                $delete_stmt = $pdo->prepare("DELETE FROM document WHERE document_id = :did");
                $delete_stmt->execute(['did' => $document_id]);
                $_SESSION['success'] = "Document deleted successfully.";
            } else {
                $_SESSION['error'] = "Unauthorized action or document not found.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
    header("Location: ../student/requirements.php");
    exit();
}

// ==========================================
// MODULE: LOGS (SUPER ADMIN)
// ==========================================
if ($module === 'logs') {
    require_once '../includes/audit_gate.php';
    if (!isAuditUnlocked()) {
        $_SESSION['error'] = "Audit logs are locked. Re-enter your password first.";
        header("Location: ../super_admin/audit_unlock.php");
        exit();
    }
    if ($action === 'delete_single') {
        $log_id = $_POST['log_id'] ?? null;
        if ($log_id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM audit_log WHERE AuditID = :id");
                $stmt->execute(['id' => $log_id]);
                $_SESSION['success'] = "Log entry successfully deleted.";
            } catch (PDOException $e) { $_SESSION['error'] = "Failed to delete log: " . $e->getMessage(); }
        }
    } elseif ($action === 'delete_batch') {
        $log_ids = $_POST['log_ids'] ?? [];
        if (!empty($log_ids)) {
            try {
                $placeholders = implode(',', array_fill(0, count($log_ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM audit_log WHERE AuditID IN ($placeholders)");
                $stmt->execute($log_ids);
                $_SESSION['success'] = count($log_ids) . " log entries successfully deleted.";
            } catch (PDOException $e) { $_SESSION['error'] = "Failed to delete logs: " . $e->getMessage(); }
        } else {
            $_SESSION['error'] = "No logs were selected for deletion.";
        }
    }
    header("Location: ../super_admin/logs.php");
    exit();
}

// ==========================================
// MODULE: SUPER ADMIN USERS & SECURITY
// ==========================================
if ($module === 'users' && $action === 'create') {
    $first_name = trim((string) ($_POST['first_name'] ?? ''));
    $last_name = trim((string) ($_POST['last_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $role = (string) ($_POST['role'] ?? '');
    $username = trim((string) ($_POST['username'] ?? ''));
    $student_number = preg_replace('/\D/', '', (string) ($_POST['student_number'] ?? ''));
    $organization = trim((string) ($_POST['organization'] ?? ''));
    // On a mistake: back to the form with what was typed (never the password)
    $back = function (string $error) {
        $_SESSION['error'] = $error;
        $_SESSION['old_user_form'] = array_diff_key($_POST, ['password' => true, 'module' => true, 'action' => true]);
        header("Location: ../super_admin/users.php");
        exit();
    };

    // Who and what
    if ($role === 'Super_Admin') $back("There can only be one Super Admin.");
    if (!in_array($role, ['Student', 'Internal_Admin', 'External_Admin'], true)) $back("Choose a role for the account.");
    if ($first_name === '' || $last_name === '') $back("First and last name are required.");
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $back("\"" . htmlspecialchars($email) . "\" is not a valid email address.");
    if ($password_error = passwordPolicyError($pdo, (string) ($_POST['password'] ?? ''))) $back("Security Policy: " . $password_error);

    // Username (what they type to sign in): the student number for students; for admins as given, or made from the email
    if ($role === 'Student') {
        if (strlen($student_number) !== 10) $back("A student account needs the 10-digit student number (it is also their username).");
        $username = $student_number;
    } else {
        if ($role === 'External_Admin' && $organization === '') $back("Enter the provider's organization (e.g. CHED Region III): it is how the provider is shown across ScholarLink.");
        $username = preg_replace('/[^a-z0-9._-]/', '', strtolower($username !== '' ? $username : strstr($email, '@', true)));
        if ($username === '') $username = 'user';
    }

    try {
        $exists = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(Email) = ?");
        $exists->execute([$email]);
        if ($exists->fetchColumn()) $back("An account with the email " . htmlspecialchars($email) . " already exists.");
        if ($role === 'Student') {
            $taken = $pdo->prepare("SELECT 1 FROM users WHERE Username = ? OR StudentID_Num = ?");
            $taken->execute([$username, $student_number]);
            if ($taken->fetchColumn()) $back("Student number $student_number is already registered.");
        } else {
            // Typed or not, make the username unique: ched.region3, ched.region32, ...
            $base_name = mb_substr($username, 0, 45);
            $taken = $pdo->prepare("SELECT 1 FROM users WHERE Username = ?");
            for ($n = 2; $taken->execute([$username]) && $taken->fetchColumn(); $n++) $username = $base_name . $n;
        }

        $pdo->prepare("INSERT INTO users (Username, FirstName, LastName, Email, Role, PasswordHash, AccountStatus, StudentID_Num, Organization)
                       VALUES (?, ?, ?, ?, ?, ?, 'Active', ?, ?)")
            ->execute([$username, $first_name, $last_name, $email, $role, password_hash($_POST['password'], PASSWORD_DEFAULT),
                       $role === 'Student' ? $student_number : null, $role === 'External_Admin' ? $organization : null]);
        $new_id = (int) $pdo->lastInsertId();
        unset($_SESSION['old_user_form']);
        logAudit($pdo, $_SESSION['user_id'], 'User Account Created', (AUDIT_ROLE_LABELS[$role] ?? $role) . " account for $first_name $last_name ($email), username $username"
            . ($role === 'External_Admin' ? ", organization $organization" : ''));

        // Welcome email: username, sign-in page and a 24-hour "set your password" link
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE users SET ResetToken = ?, ResetTokenExpire = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE UserID = ?")->execute([$token, $new_id]);
        $base = appPublicUrl();   // works from the recipient's device, not just "localhost"
        $role_label = ['Student' => 'Student', 'Internal_Admin' => 'OSSD (Internal Admin)', 'External_Admin' => 'Scholarship Provider'][$role];
        $emailed = function_exists('sendWelcomeEmail') && sendWelcomeEmail($email, $first_name, $role_label, $username,
            $base . ($role === 'Student' ? '/student_login.php' : '/admin_login.php'), $base . '/reset_password.php?token=' . $token);
        if ($emailed) {
            logAudit($pdo, $_SESSION['user_id'], 'Welcome Email Sent', "Sign-in details emailed to $first_name $last_name (" . maskEmail($email) . ")");
        } else {
            $pdo->prepare("UPDATE users SET ResetToken = NULL, ResetTokenExpire = NULL WHERE UserID = ?")->execute([$new_id]);
            logAudit($pdo, $_SESSION['user_id'], 'Welcome Email Failed', "The welcome email to $first_name $last_name could not be sent");
        }

        $_SESSION['success'] = "Account created for " . htmlspecialchars("$first_name $last_name") . ". They sign in with the username <b>" . htmlspecialchars($username) . "</b> or their email. "
            . ($emailed ? "A welcome email with a link to set their password was sent to " . htmlspecialchars(maskEmail($email)) . "."
                        : "<b>The welcome email couldn't be sent</b>, so share the temporary password with them yourself, or use Reset link later.");
    } catch (PDOException $e) { $_SESSION['error'] = "Database Error: " . $e->getMessage(); }
    header("Location: ../super_admin/users.php");
    exit();
}

if ($module === 'users' && $action === 'toggle_archive') {
    $user_id = (int) $_POST['user_id'];
    // The new status comes from the account itself, not from the form
    $target = $pdo->prepare("SELECT Role, AccountStatus, CONCAT(FirstName, ' ', LastName) AS Name FROM users WHERE UserID = ?");
    $target->execute([$user_id]);
    $target = $target->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        $_SESSION['error'] = "User not found.";
        header("Location: ../super_admin/users.php");
        exit();
    }
    $new_status = ($target['AccountStatus'] ?? 'Active') === 'Archived' ? 'Active' : 'Archived';
    // Safeguards: never archive yourself or the last active Super Admin (no one could manage the system)
    if ($new_status === 'Archived') {
        $active_supers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE Role = 'Super_Admin' AND COALESCE(AccountStatus, 'Active') <> 'Archived'")->fetchColumn();
        $why = $user_id === (int) $_SESSION['user_id'] ? "You can't archive your own account."
            : ($target['Role'] === 'Super_Admin' && $active_supers <= 1 ? "{$target['Name']} is the only active Super Admin and can't be archived." : null);
        if ($why) {
            $_SESSION['error'] = $why;
            header("Location: ../super_admin/users.php");
            exit();
        }
    }
    try {
        $pdo->prepare("UPDATE users SET AccountStatus = :status WHERE UserID = :uid")->execute(['status' => $new_status, 'uid' => $user_id]);
        $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, ?, NOW(), ?, ?)")->execute([$_SESSION['user_id'], "User $new_status", "Super Admin changed UserID $user_id status to $new_status", $_SERVER['REMOTE_ADDR']]);
        $_SESSION['success'] = "User account successfully marked as $new_status!";
    } catch (PDOException $e) { $_SESSION['error'] = "Database Error: " . $e->getMessage(); }
    header("Location: ../super_admin/users.php");
    exit();
}

// ✨ BULK ARCHIVE / RESTORE (e.g. graduated students at the end of the year), with the same safeguards as one at a time
if ($module === 'users' && $action === 'bulk_status') {
    $new_status = ($_POST['status'] ?? '') === 'Active' ? 'Active' : 'Archived';
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['user_ids'] ?? [])))));
    if (!$ids) {
        $_SESSION['error'] = "Select at least one account first.";
        header("Location: ../super_admin/users.php");
        exit();
    }
    $done = [];
    $skipped = [];
    try {
        $pdo->beginTransaction();
        $find = $pdo->prepare("SELECT UserID, Role, COALESCE(NULLIF(AccountStatus, ''), 'Active') AS Status, CONCAT(FirstName, ' ', LastName) AS Name FROM users WHERE UserID = ?");
        $set = $pdo->prepare("UPDATE users SET AccountStatus = ? WHERE UserID = ?");
        foreach ($ids as $id) {
            $find->execute([$id]);
            $u = $find->fetch(PDO::FETCH_ASSOC);
            if (!$u || $u['Status'] === $new_status) continue;   // gone, or already in that state
            if ($new_status === 'Archived') {
                if ($id === (int) $_SESSION['user_id']) { $skipped[] = "your own account"; continue; }
                if ($u['Role'] === 'Super_Admin') {
                    $active_supers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE Role = 'Super_Admin' AND COALESCE(AccountStatus, 'Active') <> 'Archived'")->fetchColumn();
                    if ($active_supers <= 1) { $skipped[] = "{$u['Name']} (the only active Super Admin)"; continue; }
                }
            }
            $set->execute([$new_status, $id]);
            $done[] = $u['Name'];
        }
        if ($done) {
            $verb = $new_status === 'Archived' ? 'archived' : 'restored';
            logAudit($pdo, $_SESSION['user_id'], "User $new_status", "Super Admin $verb " . count($done) . " account(s) at once: " . implode(', ', $done));
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = "Database Error: " . $e->getMessage();
        header("Location: ../super_admin/users.php");
        exit();
    }
    $verb = $new_status === 'Archived' ? 'Archived' : 'Restored';
    $msg = $done ? "$verb " . count($done) . " account" . (count($done) === 1 ? '' : 's') . "." : "No accounts were changed (they were already " . strtolower($new_status) . ").";
    if ($skipped) $msg .= " Skipped " . implode(', ', $skipped) . ".";
    $_SESSION[$done ? 'success' : 'error'] = $msg;
    header("Location: ../super_admin/users.php");
    exit();
}

// ✨ PASSWORD RESET LINK for a locked-out user: same email and 1-hour link as "Forgot password", so the admin never picks or sees the password
if ($module === 'users' && $action === 'send_reset') {
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT UserID, Email, FirstName, LastName, AccountStatus FROM users WHERE UserID = ?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    $name = $u ? $u['FirstName'] . ' ' . $u['LastName'] : '';
    $why = !$u ? "User not found."
         : (($u['AccountStatus'] ?? '') === 'Archived' ? "$name's account is archived. Restore it first, then send the reset link."
         : (!filter_var($u['Email'] ?? '', FILTER_VALIDATE_EMAIL) ? "$name has no valid email address on file, so a reset link can't be sent."
         : (str_ends_with(strtolower($u['Email']), '@demo.tau.edu.ph') ? "$name is a demo account; its email address doesn't exist." : null)));
    if ($why) {
        $_SESSION['error'] = $why;
        header("Location: ../super_admin/users.php");
        exit();
    }
    $token = bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE users SET ResetToken = ?, ResetTokenExpire = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE UserID = ?")->execute([$token, $user_id]);
    $reset_link = appPublicUrl() . '/reset_password.php?token=' . $token;

    if (function_exists('sendPasswordResetEmail') && sendPasswordResetEmail($u['Email'], $u['FirstName'], $reset_link)) {
        logAudit($pdo, $_SESSION['user_id'], 'Password Reset Link Sent', "Super Admin emailed a password reset link to $name (" . maskEmail($u['Email']) . ")");
        $_SESSION['success'] = "A password reset link was emailed to $name at " . maskEmail($u['Email']) . ". It works for 1 hour.";
    } else {
        // Don't leave a usable link behind when the email never went out
        $pdo->prepare("UPDATE users SET ResetToken = NULL, ResetTokenExpire = NULL WHERE UserID = ?")->execute([$user_id]);
        logAudit($pdo, $_SESSION['user_id'], 'Password Reset Link Failed', "The reset email to $name could not be sent");
        $_SESSION['error'] = "The reset email to $name couldn't be sent. Check the email settings (SMTP) in .env.";
    }
    header("Location: ../super_admin/users.php");
    exit();
}

if ($module === 'security' && $action === 'toggle_setting') {
    $setting_key = (string) ($_POST['setting_key'] ?? '');
    $new_value = (string) ($_POST['new_value'] ?? '');

    // Only the known switches, with values they can actually take
    $valid = ($setting_key === 'session_timeout') ? isset(SESSION_TIMEOUT_OPTIONS[(int) $new_value]) && ctype_digit($new_value)
           : (in_array($setting_key, ['require_2fa', 'strict_password'], true) && in_array($new_value, ['0', '1'], true));
    if (!$valid) {
        $_SESSION['error'] = "Invalid security setting.";
        header("Location: ../super_admin/security.php");
        exit();
    }

    // 2FA emails a code at login: every admin needs an email address first, or they'd be locked out
    if ($setting_key === 'require_2fa' && $new_value === '1') {
        $no_email = $pdo->query("SELECT CONCAT(FirstName, ' ', LastName) FROM users WHERE Role IN ('Internal_Admin', 'External_Admin', 'Super_Admin') AND AccountStatus <> 'Archived' AND (Email IS NULL OR Email NOT LIKE '%_@_%._%')")->fetchAll(PDO::FETCH_COLUMN);
        if ($no_email) {
            $_SESSION['error'] = "2FA was not turned on: these admin accounts have no valid email address to receive login codes: " . htmlspecialchars(implode(', ', $no_email)) . ".";
            header("Location: ../super_admin/security.php");
            exit();
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['val' => $new_value, 'key' => $setting_key]);

        $formatted_name = ['require_2fa' => 'Two-Factor Authentication', 'strict_password' => 'Strict Password Requirements', 'session_timeout' => 'Session Timeout'][$setting_key];
        $log_value = ($setting_key === 'session_timeout') ? SESSION_TIMEOUT_OPTIONS[(int) $new_value] : ($new_value === '1' ? 'ON' : 'OFF');

        $log = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'Security Update', NOW(), ?, ?)");
        $log->execute([$_SESSION['user_id'], "Super Admin changed $formatted_name to $log_value", $_SERVER['REMOTE_ADDR']]);

        $_SESSION['success'] = "$formatted_name updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database Error: " . $e->getMessage();
    }
    header("Location: ../super_admin/security.php");
    exit();

    
}

// ✨ ACADEMIC TERMS & MASS RENEWAL MODULE
if (isset($_POST['module']) && $_POST['module'] === 'academic_terms') {
    if (isset($_POST['action']) && $_POST['action'] === 'start_new_term') {
        $term_name = trim($_POST['term_name']);

        try {
            $pdo->beginTransaction();

            // 1. End current active term
            $pdo->query("UPDATE academic_terms SET Status = 'Ended', EndDate = CURRENT_DATE() WHERE Status = 'Active'");

            // 2. Insert new term
            $stmt = $pdo->prepare("INSERT INTO academic_terms (TermName, Status, StartDate) VALUES (?, 'Active', CURRENT_DATE())");
            $stmt->execute([$term_name]);
            $new_term_id = $pdo->lastInsertId();

            // 3. ✨ REVERT SCHOLARS TO PENDING & GENERATE RENEWAL FORMS
            $scholars_stmt = $pdo->query("SELECT ApplicationID, UserID FROM application WHERE Status = 'Approved'");
            $scholars = $scholars_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($scholars)) {
                $insert_renewal = $pdo->prepare("INSERT INTO scholar_renewals (ApplicationID, UserID, TermID, Status) VALUES (?, ?, ?, 'Pending_Submission')");
                $update_app = $pdo->prepare("UPDATE application SET Status = 'Pending' WHERE ApplicationID = ?");

                foreach ($scholars as $s) {
                    // Create the wall block
                    $insert_renewal->execute([$s['ApplicationID'], $s['UserID'], $new_term_id]);
                    // Revert the main application status back to Pending
                    $update_app->execute([$s['ApplicationID']]);
                }
            }

            $pdo->commit();
            $_SESSION['success'] = "New semester started! " . count($scholars) . " scholars have been reverted to 'Pending' for verification.";
            header("Location: ../internal_admin/dashboard.php");
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Mass Renewal Error: " . $e->getMessage());
        }
    }
}

// ✨ RENEWAL DECISION MODULE WITH EMAIL NOTIFICATION
if (isset($_POST['module']) && $_POST['module'] === 'renewals') {
    if (isset($_POST['action']) && $_POST['action'] === 'admin_decision') {
        $renewal_id = $_POST['renewal_id'];
        $decision = $_POST['decision']; // 'Renewed', 'Appeal_Requested', or 'Dropped'
        $admin_notes = $_POST['admin_notes'] ?? '';

        if (!in_array($decision, ['Renewed', 'Appeal_Requested', 'Dropped'], true)) {
            $_SESSION['error'] = "Invalid renewal decision.";
            header("Location: ../internal_admin/manage_renewals.php");
            exit();
        }

        try {
            // 1. I-update ang database
            $stmt = $pdo->prepare("
                UPDATE scholar_renewals 
                SET Status = ?, AdminNotes = ?, AdminDecisionDate = CURRENT_TIMESTAMP 
                WHERE RenewalID = ?
            ");
            $stmt->execute([$decision, $admin_notes, $renewal_id]);

            // ✨ Starting a new term reverts scholars to 'Pending', so restore them once renewed
            if ($decision === 'Renewed') {
                $pdo->prepare("
                    UPDATE application SET Status = 'Approved'
                    WHERE ApplicationID = (SELECT ApplicationID FROM scholar_renewals WHERE RenewalID = ?)
                ")->execute([$renewal_id]);
            }

            // 2. Kunin ang detalye ng estudyante para sa Email
            $user_stmt = $pdo->prepare("
                SELECT u.Email, u.FirstName, u.LastName, s.Name as ScholarshipName 
                FROM scholar_renewals sr
                JOIN users u ON sr.UserID = u.UserID
                JOIN application a ON sr.ApplicationID = a.ApplicationID
                JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID
                WHERE sr.RenewalID = ?
            ");
            $user_stmt->execute([$renewal_id]);
            $student = $user_stmt->fetch(PDO::FETCH_ASSOC);

            // 3. I-setup at I-send ang Email kung may nakuhang data
            if ($student && !empty($student['Email'])) {
                $to = $student['Email'];
                $scholarship_name = $student['ScholarshipName'];
                $first_name = $student['FirstName'];
                
                // Dynamic content base sa admin decision
                if ($decision === 'Renewed') {
                    $subject = "🎉 Congratulations! Your Scholarship is Renewed";
                    $message_body = "We are pleased to inform you that your <strong>{$scholarship_name}</strong> has been successfully renewed for this academic term. Keep up the excellent work!";
                } elseif ($decision === 'Appeal_Requested') {
                    $subject = "⚠️ Action Required: Letter of Appeal Needed";
                    $message_body = "The OSSD requires further explanation regarding your academic performance for the <strong>{$scholarship_name}</strong>. <br><br><strong>Admin Note:</strong> {$admin_notes}<br><br>Please log in to your ScholarLink portal to submit your formal Letter of Appeal.";
                } else { 
                    $subject = "❌ Notice: Scholarship Dropped";
                    $message_body = "We regret to inform you that your <strong>{$scholarship_name}</strong> has been dropped for this academic term. <br><br><strong>Reason:</strong> {$admin_notes}<br><br>We wish you the best in your future endeavors.";
                }

                // Clean HTML Email Template
                $html_message = "
                <html>
                <body style='font-family: Arial, sans-serif; color: #333; line-height: 1.6; background-color: #f8fafc; padding: 20px;'>
                    <div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0;'>
                        <h2 style='color: #0f172a; margin-top: 0;'>Hello {$first_name},</h2>
                        <p style='font-size: 15px; color: #475569;'>{$message_body}</p>
                        <br>
                        <hr style='border: none; border-top: 1px solid #f1f5f9; margin: 20px 0;'>
                        <p style='font-size: 11px; color: #94a3b8; margin-bottom: 0;'>This is an automated message from the ScholarLink System. Please do not reply directly to this email.</p>
                    </div>
                </body>
                </html>
                ";

                // Standard PHP Mail Headers
                $headers = "MIME-Version: 1.0" . "\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                $headers .= "From: ScholarLink OSSD <noreply@scholarlink-tau.edu.ph>" . "\r\n";

                // I-send ang email (ginamitan ng @ para hindi mag-throw ng error kung walang SMTP ang localhost mo)
                @mail($to, $subject, $html_message, $headers);
            }

            $_SESSION['success'] = "Decision saved and email notification sent to {$student['FirstName']}!";
            header("Location: ../internal_admin/manage_renewals.php");
            exit();

        } catch (PDOException $e) {
            die("Error updating renewal: " . $e->getMessage());
        }
    }
}

// ✨ UPDATE OFFICIAL STUDENT GWA
if (isset($_POST['module']) && $_POST['module'] === 'users' && isset($_POST['action']) && $_POST['action'] === 'update_gwa') {
    $user_id = $_POST['user_id'];
    $new_gwa = $_POST['gwa'];

    try {
        $stmt = $pdo->prepare("UPDATE users SET GPA = ? WHERE UserID = ?");
        $stmt->execute([$new_gwa, $user_id]);
        exit(); // Tapusin agad para makabalik sa dashboard nang walang error
    } catch (PDOException $e) {
        die("Error updating GWA: " . $e->getMessage());
    }
}
?>
