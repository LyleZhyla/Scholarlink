<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// ✨ 1. KUNIN ANG API KEY MULA SA .ENV O API_CONFIG.PHP
$GEMINI_API_KEY = '';$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $envVars = @parse_ini_file($envPath);
    if (is_array($envVars)) {$GEMINI_API_KEY = $envVars['GEMINI_API_KEY'] ?? ($envVars['GOOGLE_VISION_API_KEY'] ?? '');
    }
}
if (empty($GEMINI_API_KEY) && file_exists(__DIR__ . '/../includes/api_config.php')) {
    require_once __DIR__ . '/../includes/api_config.php';
    if (defined('GEMINI_API_KEY')) {
        $GEMINI_API_KEY = GEMINI_API_KEY;
    }
}

if (empty($GEMINI_API_KEY)) {
    echo json_encode(["success" => false, "error" => "GEMINI_API_KEY not found in configuration."]);
    exit;
}

// Basahin ang pinasok na JSON data mula sa Javascript
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

$file_url =$input['file_url'] ?? '';
$document_type =$input['document_type'] ?? '';
$required_gwa =$input['required_gwa'] ?? 0;

if (empty($file_url) || empty($document_type)) {
    echo json_encode(["success" => false, "error" => "Missing file or document type."]);
    exit;
}

// Hanapin ang totoong file sa server
$physical_path = str_replace('../', '',$file_url);
$full_path = __DIR__ . '/../' .$physical_path;

if (!file_exists($full_path)) {
    echo json_encode(["success" => false, "error" => "File not found on server."]);
    exit;
}

$raw_binary = file_get_contents($full_path);
$mime_type = mime_content_type($full_path);

// ✨ 2. LAYER 1 FORENSICS: BINARY METADATA & EDITING SOFTWARE FOOTPRINT CHECK
if (preg_match('/(photoshop|illustrator|canva|photopea|gimp|corel|ilovepdf|sejda|smallpdf|jspdf|pdfkit|foxit|nitro|picsart|lightroom|snapseed)/i', $raw_binary,$matches)) {
    echo json_encode([
        "success" => true,
        "authenticity" => [
            "is_authentic" => false,
            "confidence" => 99,
            "flag_reason" => "Tampering Detected: File metadata contains an editing software signature (" . strtoupper($matches[1]) . ")."
        ],
        "qualification" => [
            "is_applicable" => ($document_type === 'Report of Grades'),
            "is_qualified" => false,
            "extracted_gwa" => "Tampered"
        ]
    ]);
    exit;
}

// ✨ 3. LAYER 2 FORENSICS: FLATTENED RASTER PDF CHECK (For Report of Grades / COR)
// Official TAU Portal PDFs have embedded font streams (/Font). Photopea image-flattened PDFs do not.
if ($mime_type === 'application/pdf' && in_array($document_type, ['Report of Grades', 'Certificate of Registration (COR)', 'Certificate of Registration'])) {
    if (!preg_match('/\/Font\b/', $raw_binary)) {
        echo json_encode([
            "success" => true,
            "authenticity" => [
                "is_authentic" => false,
                "confidence" => 95,
                "flag_reason" => "Tampering Detected: PDF has been flattened into a raster image (no original text/font layers found), indicating export from an image editor."
            ],
            "qualification" => [
                "is_applicable" => ($document_type === 'Report of Grades'),
                "is_qualified" => false,
                "extracted_gwa" => "Tampered"
            ]
        ]);
        exit;
    }
}

$file_data = base64_encode($raw_binary);

// ✨ 4. LAYER 3 FORENSICS: STRICT AI VISUAL & MATHEMATICAL TAMPERING PROMPT
$prompt = "
You are a strict Forensic Document Verifier for Tarlac Agricultural University (TAU) ScholarLink in the Philippines.
I am giving you an uploaded document. The applicant claims this is a: '$document_type'.

Perform all 3 forensic checks below:

TASK 1: DOCUMENT TYPE & MISMATCH CHECK
Check the layout, structure, seals, and headers. Does it match standard Philippine/TAU formats for a '$document_type'? 
If it is a completely different document (e.g., an exam paper, selfie, blank page, or wrong certificate), mark `is_authentic` as `false`.

TASK 2: VISUAL & TEXT TAMPERING / FORGERY DETECTION (CRITICAL)
Inspect the document closely for signs of digital manipulation (such as Photopea, Photoshop, Canva, or OCR edits):
- Look for garbled, misspelled, or nonsensical course titles/words (e.g., 'In formation', 'ITE ranced', 'Systerns', 'Principiles ain repa Etrotesction').
- Look for misaligned text rows, inconsistent font types/sizes/weights, or pasted text boxes with different background shading.
- Look for stacked or floating numbers (e.g., two grades like '1.43' and '1.28' crammed into a single subject row).
If ANY of these tampering signs appear, you MUST set `is_authentic` to `false` and state the exact visual/text anomaly in `flag_reason`.

TASK 3: MATHEMATICAL GWA & UNITS AUDIT (Only if '$document_type' is 'Report of Grades')
- Sum up the individual subject credit units and verify if they match the stated 'Total Credit Units Enrolled'.
- Compute the actual GWA manually: Sum of (Subject Grade * Credit Units) / Total Credit Units.
- Compare your computed GWA with the 'General Weighted Average' printed on the document (allow a 0.02 rounding margin).
- If the unit sum or computed GWA does NOT match the printed numbers, set `is_authentic` to `false` and explain the math discrepancy in `flag_reason`.

Respond ONLY with a valid JSON object in this exact format, with no markdown tags or backticks:
{
    \"is_authentic\": true/false,
    \"confidence\": <number from 0 to 100>,
    \"flag_reason\": \"<If authentic: 'Verified authentic layout and math.' If false: 'Tampering/Mismatch Detected: [exact reason]'>\",
    \"is_applicable\": true/false,
    \"extracted_gwa\": <extracted number or null>
}
";

$payload = [
    "contents" => [
        [
            "parts" => [
                ["text" => $prompt],
                ["inline_data" => ["mime_type" => $mime_type, "data" => $file_data]]
            ]
        ]
    ],
    "generationConfig" => ["response_mime_type" => "application/json"]
];

// ✨ FIXED MODEL NAME: Changed from gemini-3.6-flash to gemini-3.5-flash
$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=" . $GEMINI_API_KEY);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    error_log("Gemini API Error: " . $response);
    echo json_encode(["success" => false, "error" => "AI Service Error (HTTP $http_code). Please try again."]);
    exit;
}

$resultData = json_decode($response, true);$ai_text = $resultData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';$ai_TextClean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($ai_text));
$ai_json = json_decode($ai_TextClean, true);

$is_authentic = $ai_json['is_authentic'] ?? false;
$extracted = $ai_json['extracted_gwa'] ?? null;

// Only qualify the GWA if the document is ALSO verified as 100% authentic!
$is_qualified = false;
if ($is_authentic && $extracted !== null && is_numeric($extracted) && $required_gwa > 0) {
    $is_qualified = ($extracted <= $required_gwa);
}

echo json_encode([
    "success" => true,
    "authenticity" => [
        "is_authentic" => $is_authentic,
        "confidence" => $ai_json['confidence'] ?? 0,
        "flag_reason" => $ai_json['flag_reason'] ?? 'Could not verify document structure.'
    ],
    "qualification" => [
        "is_applicable" => $ai_json['is_applicable'] ?? false,
        "is_qualified" => $is_qualified,
        "extracted_gwa" => $extracted ?? 'N/A'
    ]
]);
?>