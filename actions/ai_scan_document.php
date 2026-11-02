<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php'; // records the activity in the audit trail
require_once __DIR__ . '/../includes/application_authority.php';
require_once __DIR__ . '/../includes/document_security.php';
require_once __DIR__ . '/../includes/evaluation_conflicts.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// Admins only (it reads students' uploaded documents and spends the AI quota)
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Unauthorized."]);
    exit;
}

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
$application_id = (int)($input['application_id'] ?? 0);

if (empty($file_url) || empty($document_type) || !$application_id) {
    echo json_encode(["success" => false, "error" => "Missing file or document type."]);
    exit;
}

$authority = getApplicationAuthority($pdo, $application_id);
if (!$authority || (!canDecideApplication($authority, $_SESSION['role'], (int)$_SESSION['user_id']) && !canEvaluateApplication($pdo,$application_id,$_SESSION['role'],(int)$_SESSION['user_id']))) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'You are not authorized to analyze this application.']); exit;
}
$secure_id = 0;
parse_str((string)parse_url($file_url, PHP_URL_QUERY), $file_query);
if (($file_query['type'] ?? '') === 'submitted') $secure_id = (int)($file_query['id'] ?? 0);
$doc_stmt=$pdo->prepare('SELECT sd.SubmittedDocID,sd.FilePath,sd.FileChecksum,dr.DocumentName FROM submitted_document sd JOIN document_requirement dr ON dr.RequirementID=sd.RequirementID WHERE sd.ApplicationID=? AND (? > 0 AND sd.SubmittedDocID=? OR ? = 0 AND (sd.FilePath=? OR sd.FilePath LIKE ?)) LIMIT 1');
$legacy_basename=basename((string)parse_url($file_url,PHP_URL_PATH));
$doc_stmt->execute([$application_id,$secure_id,$secure_id,$secure_id,$file_url,'%'.$legacy_basename]);$document=$doc_stmt->fetch(PDO::FETCH_ASSOC);
if(!$document){echo json_encode(['success'=>false,'error'=>'The submitted document record could not be matched.']);exit;}
$full_path=resolveProtectedDocumentPath($document['FilePath']);
if(!empty($document['FileChecksum'])&&!hash_equals($document['FileChecksum'],hash_file('sha256',$full_path))){echo json_encode(['success'=>false,'error'=>'Document integrity check failed.']);exit;}
$document_type=$document['DocumentName'];

function emitAiSuggestion(PDO $pdo,array $document,int $applicationId,int $userId,string $sourceHash,float $requiredGwa,bool $authentic,float $confidence,string $reason,bool $applicable,$extracted,string $model='rules+gemini-3.5-flash'): void {
    $confidence=max(0,min(100,$confidence));$fields=[];$failed=[];
    if($applicable){$fields[]=['label'=>'Extracted GWA','value'=>is_numeric($extracted)?(float)$extracted:$extracted,'confidence'=>$confidence,'source_document'=>$document['DocumentName'],'source_field'=>'General Weighted Average / computed subject grades'];}
    if(!$authentic)$failed[]=['criterion'=>'Document authenticity and consistency','explanation'=>$reason];
    $qualified=null;
    if($applicable&&is_numeric($extracted)&&$requiredGwa>0){$qualified=(float)$extracted<=$requiredGwa;if(!$qualified)$failed[]=['criterion'=>'Required GWA','explanation'=>'Extracted GWA '.number_format((float)$extracted,2).' is above the required maximum '.number_format($requiredGwa,2).'.'];}
    if($confidence<80)$failed[]=['criterion'=>'Minimum AI confidence for automated assistance','explanation'=>'Confidence is '.number_format($confidence,1).'%, below the 80% manual-verification threshold.'];
    $authSuggestion=$authentic?'Likely Authentic':'Potential Issue';
    $qualSuggestion=!$applicable?'Not Applicable':($qualified===null?'Needs Review':($qualified?'Meets Criterion':'Fails Criterion'));
    $manual=$confidence<80||!$authentic||count($failed)>0;
    $explanation=$reason.($manual?' Human verification is required before any adverse action.':' This remains an AI suggestion until an authorized reviewer confirms it.');
    $insert=$pdo->prepare('INSERT INTO ai_document_analyses (ApplicationID,SubmittedDocID,SourceDocument,SourceFileHash,ModelName,OverallConfidence,AuthenticitySuggestion,QualificationSuggestion,ExtractedFieldsJson,FailedCriteriaJson,Explanation,RequiresManualReview,CreatedBy) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute([$applicationId,$document['SubmittedDocID'],$document['DocumentName'],$sourceHash,$model,$confidence,$authSuggestion,$qualSuggestion,json_encode($fields,JSON_UNESCAPED_UNICODE),json_encode($failed,JSON_UNESCAPED_UNICODE),$explanation,$manual?1:0,$userId]);
    echo json_encode(['success'=>true,'analysis_id'=>(int)$pdo->lastInsertId(),'policy'=>'AI suggestion only — no applicant is automatically rejected based on this output.','manual_verification_required'=>$manual,'authenticity'=>['is_authentic'=>$authentic,'suggestion'=>$authSuggestion,'confidence'=>$confidence,'flag_reason'=>$reason,'source_document'=>$document['DocumentName'],'source_field'=>'Document layout, metadata, text consistency and mathematical checks'],'qualification'=>['is_applicable'=>$applicable,'is_qualified'=>$qualified,'suggestion'=>$qualSuggestion,'extracted_gwa'=>$extracted??'N/A','confidence'=>$confidence,'source_document'=>$document['DocumentName'],'source_field'=>'General Weighted Average / computed subject grades','required_gwa'=>$requiredGwa],'extracted_fields'=>$fields,'failed_criteria'=>$failed]);exit;
}

$raw_binary = file_get_contents($full_path);
$mime_type = mime_content_type($full_path);
$source_hash=hash('sha256',$raw_binary);

// ✨ 2. LAYER 1 FORENSICS: BINARY METADATA & EDITING SOFTWARE FOOTPRINT CHECK
if (preg_match('/(photoshop|illustrator|canva|photopea|gimp|corel|ilovepdf|sejda|smallpdf|jspdf|pdfkit|foxit|nitro|picsart|lightroom|snapseed)/i', $raw_binary,$matches)) {
    emitAiSuggestion($pdo,$document,$application_id,(int)$_SESSION['user_id'],$source_hash,(float)$required_gwa,false,99,"File metadata contains an editing-software signature (".strtoupper($matches[1]).").",$document_type==='Report of Grades','Unreadable due to flagged metadata','metadata-rules');
}

// ✨ 3. LAYER 2 FORENSICS: FLATTENED RASTER PDF CHECK (For Report of Grades / COR)
// Official TAU Portal PDFs have embedded font streams (/Font). Photopea image-flattened PDFs do not.
if ($mime_type === 'application/pdf' && in_array($document_type, ['Report of Grades', 'Certificate of Registration (COR)', 'Certificate of Registration'])) {
    if (!preg_match('/\/Font\b/', $raw_binary)) {
        emitAiSuggestion($pdo,$document,$application_id,(int)$_SESSION['user_id'],$source_hash,(float)$required_gwa,false,95,'PDF is flattened and has no original text/font layer; manual inspection is required.',$document_type==='Report of Grades','Unreadable due to flattened PDF','metadata-rules');
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

emitAiSuggestion($pdo,$document,$application_id,(int)$_SESSION['user_id'],$source_hash,(float)$required_gwa,(bool)$is_authentic,(float)($ai_json['confidence']??0),(string)($ai_json['flag_reason']??'Could not verify document structure.'),(bool)($ai_json['is_applicable']??false),$extracted);
?>
