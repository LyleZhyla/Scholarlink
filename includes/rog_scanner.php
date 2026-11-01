<?php
// ✨ REPORT OF GRADES SCANNER
// scanReportOfGrades($pdf_binary) checks a TAU Report of Grades PDF for tampering, then has Gemini read the stated GWA,
// recompute it from the subjects and units, and return:
//   ['success' => true, 'stated_gwa' => 1.42, 'computed_gwa' => 1.42, 'is_valid' => true, 'total_units' => 21]
//   ['success' => false, 'error' => '...']
// Used by actions/scan_rog_pdf.php (registration) and student/complete_grades.php (first sign-in wall).

if (!function_exists('scanReportOfGrades')) {

function scanReportOfGrades(string $rawBinary): array {
    if (!function_exists('curl_init')) {
        throw new Exception('PHP cURL extension is not installed or enabled on this server (run: sudo apt install php-curl && sudo systemctl restart apache2).');
    }

    // API key from .env (or includes/api_config.php)
    $apiKey = '';
    $env_path = __DIR__ . '/../.env';
    if (file_exists($env_path)) {
        $env = @parse_ini_file($env_path);
        if (is_array($env)) {
            $apiKey = $env['GEMINI_API_KEY'] ?? ($env['GOOGLE_VISION_API_KEY'] ?? '');
        }
    }
    if (empty($apiKey) && file_exists(__DIR__ . '/api_config.php')) {
        require_once __DIR__ . '/api_config.php';
        if (defined('GEMINI_API_KEY')) {
            $apiKey = GEMINI_API_KEY;
        }
    }
    if (empty($apiKey)) {
        throw new Exception('API Key is missing in .env or api_config.php.');
    }

    if ($rawBinary === '') {
        throw new Exception('Could not read uploaded PDF file.');
    }

    // SERVER-SIDE BINARY METADATA & TAMPERING CHECK
    if (preg_match('/(photoshop|illustrator|canva|photopea|gimp|corel|ilovepdf|sejda|smallpdf|jspdf|pdfkit|foxit|nitro)/i', $rawBinary, $matches)) {
        return ['success' => false, 'error' => 'SECURITY ALERT: Digital tampering or editing software signature (' . strtoupper($matches[1]) . ') detected in the PDF metadata. Please upload an original, unedited Report of Grades.'];
    }

    // Check if the PDF was flattened into an image (no font streams) by an image editor like Photopea
    if (!preg_match('/\/Font\b/', $rawBinary)) {
        return ['success' => false, 'error' => 'SECURITY ALERT: This PDF appears to be a flattened image or rasterized export with no original text font streams. Please upload the original machine-generated PDF from the TAU portal.'];
    }

    // STRICT FORENSIC AI PROMPT
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
                    ["inline_data" => ["mime_type" => "application/pdf", "data" => base64_encode($rawBinary)]]
                ]
            ]
        ],
        "generationConfig" => ["responseMimeType" => "application/json"]
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
    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('AI failed to analyze. API response: ' . $response);
    }
    $aiText = trim($responseData['candidates'][0]['content']['parts'][0]['text']);
    $aiText = preg_replace('/^```(?:json)?\s*/i', '', $aiText);
    $aiText = preg_replace('/\s*```$/', '', $aiText);

    $jsonResult = json_decode($aiText, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($jsonResult)) {
        throw new Exception('AI returned invalid JSON format. Output: ' . $aiText);
    }
    return $jsonResult;
}

}
