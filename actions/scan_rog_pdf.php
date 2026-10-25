<?php
session_start();
// Suppress HTML error output so JSON never breaks
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// Catch fatal errors (like memory limits or missing extensions) that even Throwable might miss
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'error' => 'PHP Fatal Error: ' . $error['message'] . ' on line ' . $error['line']
        ]);
    }
});

try {
    // 1. Check if cURL is installed on the Debian VM
    if (!function_exists('curl_init')) {
        throw new Exception('PHP cURL extension is not installed or enabled on this server (run: sudo apt install php-curl && sudo systemctl restart apache2).');
    }

    // 2. Load API Key safely (supports both .env and includes/api_config.php)
    $apiKey = '';
    $env_path = __DIR__ . '/../.env';
    if (file_exists($env_path)) {
        $env = @parse_ini_file($env_path);
        if (is_array($env)) {
            $apiKey = $env['GEMINI_API_KEY'] ?? ($env['GOOGLE_VISION_API_KEY'] ?? '');
        }
    }
    if (empty($apiKey) && file_exists(__DIR__ . '/../includes/api_config.php')) {
        require_once __DIR__ . '/../includes/api_config.php';
        if (defined('GEMINI_API_KEY')) {
            $apiKey = GEMINI_API_KEY;
        }
    }

    if (empty($apiKey)) {
        throw new Exception('API Key is missing in .env or api_config.php.');
    }

    // 3. Validate File Upload & Catch PHP INI Size Limits
    if (!isset($_FILES['rog_pdf'])) {
        throw new Exception('No file received by server. The PDF might exceed your server post_max_size limit.');
    }

    if ($_FILES['rog_pdf']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded PDF exceeds the server upload_max_filesize limit in php.ini (default is 2MB).',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded PDF exceeds the MAX_FILE_SIZE directive.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        $errCode = $_FILES['rog_pdf']['error'];
        throw new Exception($uploadErrors[$errCode] ?? 'Unknown upload error (Code: ' . $errCode . ').');
    }

    $fileTmpPath = $_FILES['rog_pdf']['tmp_name'];
    $rawBinary = file_get_contents($fileTmpPath);

    if ($rawBinary === false || empty($rawBinary)) {
        throw new Exception('Could not read uploaded PDF file.');
    }

    // 4. SERVER-SIDE BINARY METADATA & TAMPERING CHECK
    if (preg_match('/(photoshop|illustrator|canva|photopea|gimp|corel|ilovepdf|sejda|smallpdf|jspdf|pdfkit|foxit|nitro)/i', $rawBinary, $matches)) {
        echo json_encode([
            'success' => false,
            'error' => 'SECURITY ALERT: Digital tampering or editing software signature (' . strtoupper($matches[1]) . ') detected in the PDF metadata. Please upload an original, unedited Report of Grades.'
        ]);
        exit;
    }

    // Check if the PDF was flattened into an image (no font streams) by an image editor like Photopea
    if (!preg_match('/\/Font\b/', $rawBinary)) {
        echo json_encode([
            'success' => false,
            'error' => 'SECURITY ALERT: This PDF appears to be a flattened image or rasterized export with no original text font streams. Please upload the original machine-generated PDF from the TAU portal.'
        ]);
        exit;
    }

    $base64Pdf = base64_encode($rawBinary);

    // 5. STRICT FORENSIC AI PROMPT
    $prompt = "You are a strict university registrar and forensic document evaluator for Tarlac Agricultural University (TAU). Analyze this uploaded PDF.

    STEP 1 - DOCUMENT VALIDITY:
    If the document is NOT a valid Report of Grades, return ONLY:
    { \"success\": false, \"error\": \"This document does not appear to be a valid Report of Grades.\" }

    STEP 2 - FORENSIC TAMPERING & TYPOGRAPHY CHECK (CRITICAL):
    Inspect the text, fonts, and layout carefully for signs of digital editing (such as Photopea, Photoshop, or OCR manipulation):
    - Check for garbled, misspelled, or nonsensical words/course titles (e.g., 'In formation', 'ITE ranced', 'Systerns', 'Principiles ain repa Etrotesction').
    - Check if multiple grades or credit units are stacked inside a single subject row (e.g., two grades like '1.43' and '1.28' under one course).
    - Check if the sum of the individual 'CREDIT UNITS' column does NOT equal the stated 'Total Credit Units Enrolled' at the bottom.
    - Check for misaligned text, inconsistent font styles/sizes, or edited numbers.
    If ANY of these tampering signs or anomalies exist, you MUST reject the document immediately and return ONLY:
    { \"success\": false, \"error\": \"TAMPERING DETECTED: <Explain the exact garbled text, stacked grade, or unit discrepancy found>\" }

    STEP 3 - GWA COMPUTATION (Only if 100% authentic and untampered):
    - Task 1: Find the explicitly stated 'General Weighted Average' on the document.
    - Task 2: For each subject, multiply the 'FINAL' grade by its 'CREDIT UNITS'.
    - Task 3: Divide the total weighted grade points by the sum of the credit units.
    - Task 4: Check if your computed GWA matches the stated GWA (allow a 0.01 rounding margin).
    - Task 5: Extract the exact 'Total Credit Units Enrolled'.

    Return ONLY a valid JSON object in this exact format:
    {
      \"success\": true,
      \"stated_gwa\": 1.42,
      \"computed_gwa\": 1.42,
      \"is_valid\": true,
      \"total_units\": 21
    }";

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt],
                    [
                        "inline_data" => [
                            "mime_type" => "application/pdf",
                            "data" => $base64Pdf
                        ]
                    ]
                ]
            ]
        ],
        "generationConfig" => [
            "responseMimeType" => "application/json"
        ]
    ];

    $ch = curl_init();
    // Use gemini-3.5-flash (valid production vision model)
    curl_setopt($ch, CURLOPT_URL, 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("cURL Connection Error: " . $curlError);
    }

    if ($httpCode !== 200) {
        throw new Exception("Google API Error (HTTP $httpCode): " . $response);
    }

    $responseData = json_decode($response, true);

    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $aiText = trim($responseData['candidates'][0]['content']['parts'][0]['text']);
        $aiText = preg_replace('/^```(?:json)?\s*/i', '', $aiText);$aiText = preg_replace('/\s*```$/', '', $aiText);
        
        $jsonResult = json_decode($aiText, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            echo json_encode($jsonResult);
        } else {
            throw new Exception('AI returned invalid JSON format. Output: ' . $aiText);
        }
    } else {
        throw new Exception('AI failed to analyze. API response: ' . $response);
    }

} catch (Throwable $e) {
    // Throwable catches BOTH Exceptions AND PHP 7/8 Fatal Errors!
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>