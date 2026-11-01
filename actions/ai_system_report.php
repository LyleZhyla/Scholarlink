<?php
session_start();
require '../includes/db_connect.php';

// ✨ Same key file the chatbot (process_chat.php) uses - no more hardcoded key in this file
require_once '../includes/api_config.php';

header('Content-Type: application/json');

// 🔒 Security: Only the Super Admin can run this
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

// Release the session lock - nothing below writes to the session
session_write_close();

try {
    // 📊 STEP 1: Gather Live System Metrics
    $stats = [];
    $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['total_students'] = $pdo->query("SELECT COUNT(*) FROM users WHERE Role = 'Student'")->fetchColumn();
    $stats['total_applications'] = $pdo->query("SELECT COUNT(*) FROM application")->fetchColumn();
    // The chatbot treats 'Submitted' and 'Under Review' as in-progress, so count those as pending too
    $stats['pending_apps'] = $pdo->query("SELECT COUNT(*) FROM application WHERE Status IN ('Pending', 'Submitted', 'Under Review')")->fetchColumn();
    $stats['approved_apps'] = $pdo->query("SELECT COUNT(*) FROM application WHERE Status = 'Approved'")->fetchColumn();
    $stats['active_scholarships'] = $pdo->query("SELECT COUNT(*) FROM scholarship WHERE Status = 'Active'")->fetchColumn();

    // 🧠 STEP 2: Setup Gemini API (same key and model as the chatbot)
    $api_key = trim(GEMINI_API_KEY);
    $model = "gemini-3.5-flash";

    if ($api_key === '') {
        echo json_encode(['success' => false, 'error' => 'GEMINI_API_KEY is empty. Check includes/api_config.php.']);
        exit;
    }

    $prompt = "You are the Executive AI Analyst for the ScholarLink Scholarship System.
    Analyze this live database data:
    - Total Users: {$stats['total_users']} ({$stats['total_students']} are Students)
    - Active Scholarships: {$stats['active_scholarships']}
    - Total Applications: {$stats['total_applications']}
    - Pending Review: {$stats['pending_apps']}
    - Approved: {$stats['approved_apps']}

    Write a short, highly professional 2-paragraph executive summary for the Super Admin.
    Paragraph 1: 'System Health & Engagement' - Summarize the current user load and application volume.
    Paragraph 2: 'Strategic Recommendations' - Give 1 actionable tip based on the numbers.
    Do NOT use markdown bolding (**). Keep it strictly under 5 sentences total.";

    $payload = [
        "contents" => [ [ "parts" => [ [ "text" => $prompt ] ] ] ]
    ];

    // 🚀 STEP 3: Send to Google (same cURL method as process_chat.php)
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-goog-api-key: ' . $api_key]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $curl_error = curl_error($ch);
        curl_close($ch);
        echo json_encode(['success' => false, 'error' => 'Network error: ' . $curl_error]);
        exit;
    }
    curl_close($ch);

    $result = json_decode($response, true);

    // If Google returns an error (bad key, quota exceeded, etc.), show it on the dashboard
    if (isset($result['error'])) {
        echo json_encode(['success' => false, 'error' => "Google API Error: " . $result['error']['message']]);
        exit;
    }

    $ai_reply = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if ($ai_reply) {
        $ai_reply = trim(str_replace(["\r", "*", "\""], '', $ai_reply));
        echo json_encode(['success' => true, 'report' => $ai_reply]);
    } else {
        // Failed without a standard error: print the raw response so it can be debugged
        echo json_encode(['success' => false, 'error' => "Unexpected API Response. Raw Data: " . print_r($result, true)]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>