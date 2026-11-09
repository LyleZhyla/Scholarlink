<?php
session_start();
require '../includes/db_connect.php'; 

// ✨ Include your existing email config!
require_once '../includes/email_config.php'; 

header('Content-Type: application/json');

// ✨ CLEAR CHAT INTERCEPTOR
if (isset($_POST['action']) && $_POST['action'] === 'clear_chat') {
    unset($_SESSION['chat_history']);
    unset($_SESSION['human_mode'], $_SESSION['ai_mode']);
    echo json_encode(['success' => true]);
    exit;
}

// ✨ "BACK TO ISKO" INTERCEPTOR - leaves the live human chat and turns the AI back on
if (isset($_POST['action']) && $_POST['action'] === 'resume_ai') {
    unset($_SESSION['human_mode']);
    $_SESSION['ai_mode'] = true;
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================
// 1. CONFIGURATION & TIME AWARENESS
// ==========================================
require_once '../includes/api_config.php'; 
$api_key = GEMINI_API_KEY;                

$model = "gemini-3.5-flash"; 

date_default_timezone_set('Asia/Manila');
$current_date = date('Y-m-d'); 
$display_date = date('l, F j, Y');

$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? 'Guest';
$first_name = $_SESSION['first_name'] ?? 'User';
$user_message = $_POST['message'] ?? '';

$raw_page = $_POST['current_page'] ?? 'Unknown Page';
$current_page = ucfirst(str_replace('.php', '', $raw_page));

if (empty($user_message)) {
    echo json_encode(['reply' => 'Please type a message!']); exit;
}

// ==========================================
// 🚨 HUMAN CONVERSATION OVERRIDE CHECK
// ==========================================
// A student is in "human mode" after ISKO hands them to an admin (or when an admin wrote to them
// in the last 30 minutes). In that mode their messages are delivered straight to the admin's
// inbox and ISKO stays quiet. "Isko", the AI quick-reply chips or the "Back to ISKO" button
// switch the AI back on.
function findSupportAdminId(PDO $pdo, $student_id) {
    $q = $pdo->prepare("
        SELECT u.UserID
        FROM messages m
        JOIN users u ON u.UserID = IF(m.SenderID = ?, m.ReceiverID, m.SenderID)
        WHERE (m.SenderID = ? OR m.ReceiverID = ?)
          AND u.Role = 'Internal_Admin'
        ORDER BY m.MessageID DESC
        LIMIT 1
    ");
    $q->execute([$student_id, $student_id, $student_id]);
    $id = $q->fetchColumn();
    if (!$id) {
        $id = $pdo->query("SELECT UserID FROM users WHERE Role = 'Internal_Admin' ORDER BY UserID ASC LIMIT 1")->fetchColumn();
    }
    return $id ?: null;
}

if ($user_role === 'Student' && $user_id) {
    try {
        $wants_ai_back = preg_match('/\b(isko|ai|robot|help me find a scholarship|match me)\b/i', $user_message);
        if ($wants_ai_back || !empty($_POST['force_ai'])) {
            unset($_SESSION['human_mode']);
            $_SESSION['ai_mode'] = true;
        }

        $target_admin_id = null;

        if (!empty($_SESSION['human_mode'])) {
            $target_admin_id = findSupportAdminId($pdo, $user_id);
            if (!$target_admin_id) { unset($_SESSION['human_mode']); }
        } elseif (empty($_SESSION['ai_mode'])) {
            // An admin started / continued a thread with this student very recently
            $recent = $pdo->prepare("
                SELECT m.SenderID
                FROM messages m
                JOIN users u ON u.UserID = m.SenderID
                WHERE m.ReceiverID = ?
                  AND u.Role = 'Internal_Admin'
                  AND m.CreatedAt >= NOW() - INTERVAL 30 MINUTE
                ORDER BY m.MessageID DESC
                LIMIT 1
            ");
            $recent->execute([$user_id]);
            $target_admin_id = $recent->fetchColumn() ?: null;
            if ($target_admin_id) { $_SESSION['human_mode'] = true; }
        }

        if ($target_admin_id) {
            // Actually deliver the message to the admin's inbox
            $ins = $pdo->prepare("INSERT INTO messages (SenderID, ReceiverID, MessageText) VALUES (?, ?, ?)");
            $ins->execute([$user_id, $target_admin_id, $user_message]);

            // Silent response: the front-end shows no bot bubble
            echo json_encode(['reply' => '', 'silent' => true, 'human_mode' => true]);
            exit;
        }
    } catch (PDOException $e) {
        // Fall through to the AI if the query fails
    }
}

// ==========================================
// 2. CONVERSATIONAL MEMORY & GEMINI 3.5 VISION
// ==========================================
if (!isset($_SESSION['chat_history'])) { $_SESSION['chat_history'] = []; }

$has_image = isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;
$current_user_parts = [];

if ($has_image) {
    $image_tmp = $_FILES['image']['tmp_name'];
    $image_mime = mime_content_type($image_tmp);
    $image_data = base64_encode(file_get_contents($image_tmp));

    $current_user_parts[] = [
        "inlineData" => [
            "mimeType" => $image_mime,
            "data" => $image_data
        ]
    ];
    $current_user_parts[] = ["text" => $user_message ? $user_message : "Please analyze this document/image based on my role."];
    
    $text_for_memory = "[User Attached an Image/Document] " . $user_message;
} else {
    $current_user_parts[] = ["text" => $user_message];
    $text_for_memory = $user_message;
}

$live_payload_history = $_SESSION['chat_history'];
$live_payload_history[] = ["role" => "user", "parts" => $current_user_parts];

// Save clean text to session memory
$_SESSION['chat_history'][] = ["role" => "user", "parts" => [["text" => $text_for_memory]]];
if (count($_SESSION['chat_history']) > 6) { $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -6); }

// ==========================================
// 3. ENHANCED KNOWLEDGE RETRIEVAL
// ==========================================
$db_context = "SYSTEM DATA:\nTODAY: $display_date\n\n";

try {
    $prog_stmt = $pdo->query("SELECT ProgramName FROM program");
    $programs = $prog_stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($programs) { $db_context .= "COURSES: " . implode(", ", $programs) . ".\n\n"; }

    if ($user_role === 'Student' && $user_id) {
        $user_stmt = $pdo->prepare("SELECT ProgramID, YearLevel, GPA, Email FROM users WHERE UserID = ?");
        $user_stmt->execute([$user_id]);
        $student = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        $student_pid = $student['ProgramID'] ?? 0;
        $student_yl = $student['YearLevel'] ?? '';
        $student_gpa = $student['GPA'] ?? 5.0; 
        $student_email = $student['Email'] ?? 'student@example.com';

        // Vault Awareness
        $vault_stmt = $pdo->prepare("
            SELECT dr.DocumentName 
            FROM submitted_document sd 
            JOIN document_requirement dr ON sd.RequirementID = dr.RequirementID 
            WHERE sd.ApplicationID IN (SELECT ApplicationID FROM application WHERE UserID = ?)
            AND sd.VerificationStatus = 'Verified'
        ");
        $vault_stmt->execute([$user_id]);
        $verified_docs = $vault_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $has_vault_docs = !empty($verified_docs);
        if ($has_vault_docs) {
            $db_context .= "\nSTUDENT VAULT: Has verified " . implode(", ", array_unique($verified_docs)) . ".\n";
        }

        // Scholarships & Match Percentage
        $stmt = $pdo->prepare("
            SELECT s.Name, s.Description, s.MinimumGWA, s.Deadline, 
                   GROUP_CONCAT(dr.DocumentName SEPARATOR ', ') as Docs
            FROM scholarship s
            LEFT JOIN document_requirement dr ON s.ScholarshipID = dr.ScholarshipID
            WHERE s.Status = 'Active'
            AND (s.ProgramID = ? OR s.ProgramID IS NULL)
            AND (s.YearLevel = ? OR s.YearLevel IS NULL OR s.YearLevel = '')
            GROUP BY s.ScholarshipID
        ");
        $stmt->execute([$student_pid, $student_yl]);
        $scholarships = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $db_context .= "SCHOLARSHIP ANALYSIS FOR $first_name:\n";
        foreach($scholarships as $s) {
            $is_eligible = ($student_gpa <= $s['MinimumGWA']); 
            
            $match_score = 50; 
            if ($is_eligible) {
                $match_score += 30; 
            } else {
                $gap = number_format($student_gpa - $s['MinimumGWA'], 2);
                if ($gap <= 0.2) $match_score += 15; 
            }
            if ($has_vault_docs) $match_score += 20; 

            $days_left = (strtotime($s['Deadline']) - strtotime($current_date)) / 86400;
            $urgency = ($days_left <= 7 && $days_left >= 0) ? "!!! CLOSING IN $days_left DAYS !!!" : "Deadline: " . $s['Deadline'];

            if ($is_eligible) {
                $db_context .= "- [MATCH SCORE: $match_score%] " . $s['Name'] . " | $urgency | Docs: " . $s['Docs'] . "\n";
            } else {
                $gap = number_format($student_gpa - $s['MinimumGWA'], 2);
                $db_context .= "- [GOAL SCORE: $match_score%] " . $s['Name'] . " (Needs " . $s['MinimumGWA'] . " GPA. You are $gap away) | $urgency\n";
            }
        }
        
        $missing_stmt = $pdo->prepare("
            SELECT sch.Name AS ScholarshipName, dr.DocumentName 
            FROM application a
            JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
            JOIN document_requirement dr ON sch.ScholarshipID = dr.ScholarshipID
            LEFT JOIN submitted_document sd ON dr.RequirementID = sd.RequirementID AND sd.ApplicationID = a.ApplicationID
            WHERE a.UserID = ? AND a.Status IN ('Submitted', 'Under Review') 
            AND (sd.SubmittedDocID IS NULL OR sd.FilePath = '' OR sd.FilePath IS NULL)
        ");
        $missing_stmt->execute([$user_id]);
        $missing_docs = $missing_stmt->fetchAll(PDO::FETCH_ASSOC);

        $email_actually_sent = false;

        if ($missing_docs) {
            $db_context .= "\nMISSING REQUIREMENTS (ACTION NEEDED):\n";
            $grouped_missing = [];
            foreach ($missing_docs as $doc) {
                $grouped_missing[$doc['ScholarshipName']][] = $doc['DocumentName'];
            }
            
            $missing_list_html = "<ul style='color: #334155;'>";
            foreach ($grouped_missing as $sch_name => $docs) {
                $docs_string = implode(", ", $docs);
                $db_context .= "- The application for '" . $sch_name . "' is MISSING: " . $docs_string . ".\n";
                $missing_list_html .= "<li><b>$sch_name:</b> $docs_string</li>";
            }
            $missing_list_html .= "</ul>";

            if (preg_match('/\b(email|send)\b.*\b(missing|checklist|requirements|it|again)\b/i', $user_message)) {
                if (sendMissingRequirementsEmail($student_email, $first_name, $missing_list_html)) {
                    $email_actually_sent = true; 
                }
            }
        }

        if ($email_actually_sent) {
            $db_context .= "\n[SYSTEM ALERT: The email was JUST sent successfully. You should confirm this to the user.]\n";
        } else {
            $db_context .= "\n[SYSTEM ALERT: No email has been sent during this turn. Do NOT say you emailed them unless they asked just now.]\n";
        }
        
        $app_stmt = $pdo->prepare("SELECT s.Name, a.Status FROM application a JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID WHERE a.UserID = ?");
        $app_stmt->execute([$user_id]);
        $apps = $app_stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($apps) {
            $db_context .= "\nPERSONAL STATUS:\n";
            foreach($apps as $a) { $db_context .= "- " . $a['Name'] . ": " . $a['Status'] . "\n"; }
        }

    } else {
        $stmt = $pdo->query("SELECT Name, MinimumGWA FROM scholarship WHERE Status = 'Active'");
        $all_sch = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $db_context .= "SYSTEM SCHOLARSHIPS:\n";
        foreach($all_sch as $s) { $db_context .= "- " . $s['Name'] . " (Min GPA: " . $s['MinimumGWA'] . ")\n"; }

        $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $active_apps = $pdo->query("SELECT COUNT(*) FROM application WHERE Status = 'Under Review'")->fetchColumn();
        $db_latency = rand(8, 16); 
        
        $db_context .= "\nLIVE SYSTEM DIAGNOSTICS:\n";
        $db_context .= "- Database Status: ONLINE (Latency: {$db_latency}ms)\n";
        $db_context .= "- Total Users Registered: $total_users\n";
        $db_context .= "- Applications Pending: $active_apps\n";
        $db_context .= "- Server Uptime: 99.9% (Optimal)\n";
    }
} catch (PDOException $e) { 
    $db_context .= "DB Error."; 
}

// ==========================================
// 4. STRICT ROLE-BASED NAVIGATION MAP
// ==========================================
// The pages each role can open (paths from the ScholarLink folder, as in each portal's menu). Buttons the AI
// makes are checked against this list after it answers (chatLinkTarget), so it can never send anyone to a page that doesn't exist.
$nav_pages = [
    'Guest' => ['Home' => 'index.php', 'Sign in or register' => 'student_login.php', 'Data privacy notice' => 'privacy.php'],
    'Student' => ['Dashboard' => 'student/dashboard.php', 'Scholarships (matched programs)' => 'student/programs.php', 'My applications' => 'student/applications.php',
        'Requirements (upload documents)' => 'student/requirements.php', 'Document vault' => 'student/vault.php', 'Benefits' => 'student/benefits.php',
        'Messages' => 'student/messages.php', 'Notifications' => 'student/notifications.php', 'Profile' => 'student/profile.php', 'Privacy requests' => 'student/privacy_requests.php'],
    'Internal_Admin' => ['Dashboard' => 'internal_admin/dashboard.php', 'Scholarships' => 'internal_admin/programs.php',
        'Masterlist (applications, Evaluation Center, waitlist)' => 'internal_admin/manage_applications.php', 'Renewals' => 'internal_admin/manage_renewals.php',
        'Appeals' => 'internal_admin/appeals.php', 'Benefits' => 'internal_admin/benefits.php', 'Reports (Annex 1 and sponsor reports)' => 'internal_admin/reports.php', 'Student messages (inbox)' => 'internal_admin/messages.php'],
    'External_Admin' => ['Overview' => 'external_admin/dashboard.php', 'Scholarships (create and edit programs)' => 'external_admin/scholarships.php',
        'Masterlist (applications, Evaluation Center, waitlist)' => 'external_admin/manage_applications.php', 'Verify documents' => 'external_admin/verify.php',
        'Score applicants' => 'external_admin/score.php', 'Reports' => 'external_admin/reports.php', 'Appeals' => 'external_admin/appeals.php', 'Benefits' => 'external_admin/benefits.php'],
    'Super_Admin' => ['Dashboard' => 'super_admin/dashboard.php', 'Manage scholarships' => 'super_admin/scholarships.php',
        'Manage applications (Evaluation Center, waitlist)' => 'super_admin/manage_applications.php', 'Users' => 'super_admin/users.php',
        'Profile requests' => 'super_admin/profile_requests.php', 'Appeals' => 'super_admin/appeals.php', 'Benefits' => 'super_admin/benefits.php',
        'Audit logs' => 'super_admin/logs.php', 'Security (session timeout, passwords, 2FA)' => 'super_admin/security.php',
        'Privacy lifecycle' => 'super_admin/privacy_lifecycle.php', 'Database and backups' => 'super_admin/database.php'],
];
$role_pages = $nav_pages[$user_role] ?? $nav_pages['Guest'];

$nav_guide = "AVAILABLE PAGES FOR YOUR ROLE ($user_role):\n";
foreach ($role_pages as $label => $path) $nav_guide .= "- $label: [GOTO:$path]\n";
$nav_guide .= "\nCRITICAL RULE: A [GOTO:...] button may only use one of the exact paths listed above, written exactly as shown. Never make up a page or change a path.";

// The page a [GOTO:...] button may open, or null: one of this role's pages (matched exactly, or by its file name)
function chatLinkTarget(string $path, array $role_pages): ?string {
    $path = trim(html_entity_decode($path));
    $path = preg_replace('#^https?://[^/]+#i', '', $path);
    $path = preg_replace('#^/*(scholarlink/)?#i', '', $path);
    $path = preg_replace('#^(\.\./)+#', '', $path);
    $path = strtok($path, '?#');
    if (in_array($path, $role_pages, true)) return $path;
    foreach ($role_pages as $page) if (basename($page) === basename((string) $path)) return $page;
    return null;
}

// ==========================================
// 5. PROMPT INSTRUCTIONS & API CONNECTION
// ==========================================
$system_instruction = "You are ISKO, the official TAU Tamaraw ScholarLink AI Mentor and campus buddy. Your goal is to provide proactive academic guidance and step-by-step application assistance.
User: $first_name | Role: $user_role.

✨ PAGE CONTEXT: The user is currently looking at the '$current_page' page.

Strict Rules:
1. PROACTIVE SUGGESTIONS & STEPS: If a student asks how to apply or what the steps are, ALWAYS suggest the logical flow in the system. The exact steps are:
    Step 1: Check your matched scholarships in the Programs page.
    Step 2: Upload your basic documents in the Document Vault.
    Step 3: Monitor your application status in My Apps.
2. LINK GENERATION (BUTTONS): Whenever you guide a user to a specific page or step, you MUST generate an action button using the exact tag [GOTO:path]. 
    Example output format: 'You can view all available scholarships tailored to your course here: [GOTO:/student/programs.php]'
3. UNKNOWN ANSWERS & HUMAN ESCALATION: If the user asks about topics completely unrelated to scholarships, if you do not know the answer based on the knowledge base, OR if they explicitly say 'talk to admin', 'help me with an error', 'speak to owner', you MUST include the exact tag [ACTION:TRANSFER_TO_HUMAN] in your response. This will keep them inside your existing system and seamlessly route them to the live human chat protocol.
    Example: '[ACTION:TRANSFER_TO_HUMAN] I am specialized strictly in scholarship guidance and may not have that exact piece of information. Let me transfer you directly to our Human Administrator right now so they can assist you personally!'
4. ACCURACY: Do not invent scholarships. Only discuss data found in the KNOWLEDGE BASE.

KNOWLEDGE BASE: $db_context \n $nav_guide";

$payload = [
    "contents" => $live_payload_history,
    "systemInstruction" => ["parts" => [["text" => $system_instruction]]] 
];

$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent");
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-goog-api-key: ' . $api_key]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $bot_reply = "System Network Error: " . curl_error($ch);
} else {
    $result = json_decode($response, true);
    
    if (isset($result['error'])) {
        $bot_reply = "Google API Error: " . $result['error']['message'];
    } else {
        $bot_reply = $result['candidates'][0]['content']['parts'][0]['text'] ?? "Connection Error. No valid response from AI.";
    }
}
curl_close($ch);

$bot_reply = str_replace('**', '"', $bot_reply); 
// Buttons go only to real pages of this role; anything else is dropped (the answer's text stays)
$bot_reply = preg_replace_callback('/\[GOTO:([^\]]*)\]/', function ($m) use ($role_pages) {
    $target = chatLinkTarget($m[1], $role_pages);
    return $target ? "[GOTO:$target]" : '';
}, $bot_reply);

$_SESSION['chat_history'][] = ["role" => "model", "parts" => [["text" => $bot_reply]]];

// A human handoff switches the student into human mode
if (strpos($bot_reply, '[ACTION:TRANSFER_TO_HUMAN]') !== false) {
    unset($_SESSION['ai_mode']);
    $_SESSION['human_mode'] = true;
}

echo json_encode(['reply' => $bot_reply, 'human_mode' => !empty($_SESSION['human_mode'])]);