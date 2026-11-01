<?php
// ✨ AUDIT TRAIL
// logAudit() writes one row. auditTrackRequest() (called from db_connect.php) records every activity
// automatically: each form submission / change, plus downloads, exports and logins that arrive by GET.
// When the script already wrote its own, more specific row, the automatic one is skipped.

if (!function_exists('logAudit')) {
    function logAudit(PDO $pdo, $userId, string $action, string $description = ''): void {
        try {
            $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, ?, NOW(), ?, ?)")
                ->execute([$userId ?: null, mb_substr($action, 0, 255), $description, $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
        } catch (PDOException $e) {
            // Never block the user's action because logging failed
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }
}

// User types, for filtering the audit trail ("System" = no signed-in user: automatic jobs, failed logins, registration)
const AUDIT_ROLE_LABELS = [
    'Student' => 'Student',
    'Internal_Admin' => 'OSSD (Internal Admin)',
    'External_Admin' => 'Scholarship Provider (External Admin)',
    'Super_Admin' => 'Super Admin',
    'System' => 'System / Not signed in',
];

// Activity names: "script" or "script:module/action" → what the user did
const AUDIT_ACTIVITIES = [
    // Accounts & sessions
    'process_register' => 'Account Registered',
    'google_callback' => 'Login (Google)',
    'process_logout' => 'Logout',
    'send_otp' => 'Verification Code Sent',
    'verify_otp' => 'Verification Code Checked',
    'process_profile' => 'Profile Updated',
    'scan_id_ocr' => 'ID Scanned',
    // Applications (student)
    'process_crud:student_apply/create' => 'Application Submitted',
    'process_crud:student_apply/update' => 'Application Updated',
    'process_crud:student_apply/one_click_apply' => 'Application Submitted (1-Click)',
    'process_1click' => 'Application Submitted (1-Click)',
    'process_application' => 'Application Submitted',
    'submit_application' => 'Application Submitted',
    'process_renewal' => 'Renewal Submitted',
    'process_crud:student_documents/upload' => 'Document Uploaded',
    'process_crud:student_documents/delete' => 'Document Deleted',
    'process_upload' => 'Document Uploaded',
    'upload_requirement' => 'Requirement Uploaded',
    'process_vault_link' => 'Vault Document Linked',
    'ai_scan_document' => 'Document AI Scan',
    'scan_rog_pdf' => 'Grades (ROG) Scanned',
    'vault' => 'Vault Updated',
    'dashboard' => 'Dashboard Form Submitted',
    // Evaluation & decisions (admins)
    'process_crud:applications/score' => 'Application Scored',
    'process_crud:applications/update_status' => 'Application Status Changed',
    'process_crud:applications/final_decision' => 'Application Decision',
    'process_app_status' => 'Application Status Changed',
    'process_admin_action' => 'Application Action',
    'process_crud:verification' => 'Document Verification',
    'process_crud:requirements/delete' => 'Requirement Deleted',
    // Scholarships & forms
    'process_crud:scholarships/create' => 'Scholarship Created',
    'process_crud:scholarships/edit' => 'Scholarship Updated',
    'process_crud:scholarships/delete_single' => 'Scholarship Deleted',
    'save_application_form' => 'Application Form Updated',
    'upload_form_template' => 'PDF Form Template Changed',
    'save_report_format' => 'Report Format Updated',
    'save_award_notice' => 'Approval Notice Updated',
    'landing_content_manager' => 'Landing Page Content Updated',
    // Messages & notifications
    'process_chat' => 'Message Sent',
    'process_crud:super_admin/notify_moa' => 'MOA Notification Sent',
    'process_crud:admin_actions/notify_moa' => 'MOA Notification Sent',
    'process_crud:notifications/dismiss' => 'Notification Dismissed',
    'clear_chat' => 'Chatbot History Cleared',
    // Super Admin
    'process_crud:users/create' => 'User Account Created',
    'process_crud:users/toggle_archive' => 'User Status Changed',
    'process_crud:users/bulk_status' => 'User Status Changed',
    'process_crud:users/send_reset' => 'Password Reset Link Sent',
    'complete_grades' => 'Report of Grades Uploaded',
    'reset_code' => 'Reset Code Entered',
    'process_profile_request' => 'Profile Change Reviewed',
    'process_crud:security/toggle_setting' => 'Security Update',
    'process_crud:logs/delete_single' => 'Audit Log Entry Deleted',
    'process_crud:logs/delete_batch' => 'Audit Log Entries Deleted',
    'process_database' => 'Database Maintenance',
    'audit_unlock' => 'Audit Log Unlock Attempt',
    // Exports, downloads & viewing student records (GET)
    'export_report' => 'Report Generated',
    'export_annex1_excel' => 'CHED Annex 1 Exported',
    'export_scholars_csv' => 'Scholar List Exported (CSV)',
    'export_scholars_pdf' => 'Scholar List Exported (PDF)',
    'export_evaluations_csv' => 'Evaluations Exported (CSV)',
    'export_evaluations_pdf' => 'Evaluations Exported (PDF)',
    'generate_agency_report' => 'Agency Report Generated',
    'generate_application_pdf' => 'Application Form PDF Generated',
    'live_edit_application_pdf' => 'Application Form PDF Edited',
    'export_logs_pdf' => 'Audit Logs Exported (PDF)',
    'get_application_docs' => 'Application Documents Viewed',
    'get_user_vault' => 'Student Vault Viewed',
    'award_notice_pdf' => 'Approval Notice Opened',
    'ai_system_report' => 'AI System Report Generated',
];

// What the entry says when the request carried no details worth recording
const AUDIT_PLAIN_DESCRIPTIONS = [
    'process_logout' => 'Signed out.',
    'scan_id_ocr' => 'Scanned their student ID.',
    'process_profile' => 'Updated their profile.',
    'export_logs_pdf' => 'Exported the audit logs as a PDF report.',
    'clear_chat' => 'Cleared their chatbot history.',
    'send_otp' => 'Requested an email verification code.',
    'verify_otp' => 'Entered an email verification code.',
    'process_vault_link' => 'Linked a vault document to an application.',
    'upload_requirement' => 'Uploaded a requirement.',
    'process_upload' => 'Uploaded a document.',
    'ai_system_report' => 'Generated an AI system report.',
];

// Lookups and polling that change nothing: not activities
const AUDIT_IGNORED_SCRIPTS = [
    'fetch_live_assigned', 'fetch_live_scholars', 'fetch_live_score', 'fetch_live_verify',
    'check_account_exists', 'report_columns', 'report_format_info', 'get_scholarships_by_program',
    'chat_handler', 'google_login', 'auto_backup', 'session_keepalive', 'session_timeout',
];

// Request fields worth recording (never passwords, codes or tokens)
function auditRequestDetails(PDO $pdo): string {
    $details = [];
    foreach ($_POST + $_GET as $k => $v) {
        if (!is_string($k) || in_array($k, ['module', 'action', 'format', 'download'], true)) continue;
        if (preg_match('/pass|otp|token|secret|csrf|code|answers?$|^q\d|content|message_body|image|base64/i', $k)) continue;
        if (!preg_match('/(^|_)id$|id$|name|status|decision|title|type|category|email|username|setting_key|new_value|semester|^ay$|gender|region|municipality|reason|remarks|score/i', $k)) continue;
        $text = is_array($v) ? count($v) . ' selected' : trim((string) $v);
        if ($text === '') continue;
        $details[$k] = mb_strimwidth($text, 0, 80, '…');
    }

    // Name the records the IDs point to
    $named = [];
    try {
        if (!empty($details['scholarship_id']) && ctype_digit($details['scholarship_id'])) {
            $s = $pdo->prepare("SELECT Name FROM scholarship WHERE ScholarshipID = ?");
            $s->execute([$details['scholarship_id']]);
            if ($n = $s->fetchColumn()) $named[] = "Scholarship: $n (#{$details['scholarship_id']})";
            unset($details['scholarship_id']);
        }
        foreach (['application_id', 'app_id'] as $k) {
            if (!empty($details[$k]) && ctype_digit($details[$k])) {
                $s = $pdo->prepare("SELECT CONCAT(u.FirstName, ' ', u.LastName, ' — ', s.Name) FROM application a JOIN users u ON u.UserID = a.UserID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.ApplicationID = ?");
                $s->execute([$details[$k]]);
                if ($n = $s->fetchColumn()) $named[] = "Application #{$details[$k]}: $n";
                unset($details[$k]);
            }
        }
        if (!empty($details['user_id']) && ctype_digit($details['user_id'])) {
            $s = $pdo->prepare("SELECT CONCAT(FirstName, ' ', LastName) FROM users WHERE UserID = ?");
            $s->execute([$details['user_id']]);
            if ($n = $s->fetchColumn()) $named[] = "User: $n (#{$details['user_id']})";
            unset($details['user_id']);
        }
    } catch (PDOException $e) { }

    foreach ($details as $k => $v) $named[] = ucwords(str_replace('_', ' ', $k)) . ": $v";
    return implode(' · ', $named);
}

function auditTrackRequest(PDO $pdo): void {
    static $started = false;
    if ($started || PHP_SAPI === 'cli') return;

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($script, AUDIT_IGNORED_SCRIPTS, true)) return;
    $module = (string) ($_POST['module'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $activity = AUDIT_ACTIVITIES["$script:$module/$action"] ?? AUDIT_ACTIVITIES["$script:$module"] ?? AUDIT_ACTIVITIES[$script] ?? null;

    // Every submission is an activity; a plain GET only when it is a download, export, login or logout
    if ($method !== 'POST' && $activity === null) return;
    if ($method !== 'POST' && !in_array($script, ['process_logout', 'google_callback', 'process_admin_action', 'audit_unlock'], true)
        && !preg_match('/^(export|generate|get_|live_edit|ai_system|award_notice_pdf)/', $script)) return;
    if ($script === 'audit_unlock' && $method !== 'POST' && !isset($_GET['lock'])) return;
    $started = true;

    $activity = $activity ?? ucwords(str_replace('_', ' ', $script)) . ($action !== '' ? ' (' . str_replace('_', ' ', $action) . ')' : '');
    try { $last_id = (int) $pdo->query("SELECT COALESCE(MAX(AuditID), 0) FROM audit_log")->fetchColumn(); } catch (PDOException $e) { return; }
    $user_at_start = $_SESSION['user_id'] ?? null;
    $had_error = isset($_SESSION['error']);
    $details = auditRequestDetails($pdo);

    register_shutdown_function(function () use ($pdo, $script, $activity, $last_id, $user_at_start, $had_error, $details) {
        $user_id = $_SESSION['user_id'] ?? $user_at_start;
        try {
            // The script wrote its own, more specific entry (for this user or this computer), or the
            // session had timed out so nothing happened (Auto Logout): keep just that one
            $own = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE AuditID > ? AND (UserID <=> ? OR IPAddress = ?)");
            $own->execute([$last_id, $user_id, $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
            if ((int) $own->fetchColumn() > 0) return;
        } catch (PDOException $e) { return; }

        // Outcome: an error flash message, an error redirect or an HTTP error means it didn't go through
        $failed = '';
        if (!$had_error && !empty($_SESSION['error']) && is_string($_SESSION['error'])) $failed = $_SESSION['error'];
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') === 0 && preg_match('/[?&](error|err)=([^&]*)/i', $h, $m)) $failed = $failed ?: urldecode($m[2]);
        }
        $code = http_response_code();
        if (is_int($code) && $code >= 400) $failed = $failed ?: "HTTP $code";

        $parts = array_filter([
            $user_id === null ? 'Not signed in' : '',
            $details,
            $failed !== '' ? 'Result: FAILED — ' . mb_strimwidth(strip_tags($failed), 0, 160, '…') : '',
        ]);
        // Nothing else to say: a plain sentence instead of an empty description
        if (!$parts) $parts = [AUDIT_PLAIN_DESCRIPTIONS[$script] ?? ''];
        logAudit($pdo, $user_id, $activity . ($failed !== '' ? ' (Failed)' : ''), implode(' · ', $parts));
    });
}
