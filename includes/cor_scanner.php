<?php
// ✨ COR CHECK FOR A NEW SEMESTER
// The AI reads the uploaded Certificate of Registration and says whether it is a genuine COR, for the semester and
// school year the admin declared, in the student's own name.
//   scanCorForTerm($binary, $mime, $semester, $school_year, $student_name)
//   → ['verdict' => 'pass' | 'fail' | 'unavailable', 'reason' => '...', 'semester_found' => '...']
// 'unavailable' (no key, no internet, an unreadable answer) never blocks a student: the upload is kept for an admin.
require_once __DIR__ . '/api_config.php';

function scanCorForTerm(string $binary, string $mime, string $semester, string $school_year, string $student_name): array {
    $unavailable = fn(string $why) => ['verdict' => 'unavailable', 'reason' => $why, 'semester_found' => null];
    if (GEMINI_API_KEY === '') return $unavailable('The AI check is not set up (no API key), so an admin will check this COR.');
    if ($binary === '') return ['verdict' => 'fail', 'reason' => 'The file is empty.', 'semester_found' => null];

    // A PDF made with an image or PDF editor is not an original COR
    if ($mime === 'application/pdf' && preg_match('/(photoshop|illustrator|canva|photopea|gimp|corel|ilovepdf|sejda|smallpdf|pdfescape|nitro)/i', $binary, $m)) {
        return ['verdict' => 'fail', 'reason' => 'This PDF was changed with editing software (' . strtoupper($m[1]) . '). Upload the COR exactly as you got it from the registrar or the student portal.', 'semester_found' => null];
    }

    $prompt = "You check documents for the Office of Student Services of Tarlac Agricultural University (TAU), Philippines.\n"
        . "The uploaded file should be the student's Certificate of Registration (COR, also called Certificate of Enrollment or Registration Form) "
        . "for the semester the school declared: SEMESTER = \"$semester\", SCHOOL YEAR = \"$school_year\". The student's name is \"$student_name\".\n\n"
        . "Check, in this order:\n"
        . "1. Is it really a COR / registration form (lists enrolled subjects or units for one term, issued by a school)? A report of grades, an ID, a receipt, a screenshot of something else, or a blank form is NOT a COR.\n"
        . "2. Does it show signs of editing (mismatched fonts in the semester, year or name, text pasted over, numbers that do not line up)?\n"
        . "3. Which semester and school year does it state? Treat '1st Sem', 'First Semester', '1st Semester' as the same; likewise 2nd / Second, and Midyear / Summer. "
        . "Treat 'AY 2026-2027', 'SY 2026-2027', '2026-2027' as the same.\n"
        . "4. Is the student's name on it the same person as \"$student_name\" (ignore order, middle names and letter case)?\n\n"
        . "Answer ONLY with JSON: {\"is_cor\": true|false, \"tampered\": true|false, \"semester_found\": \"<as written, or empty>\", "
        . "\"school_year_found\": \"<as written, or empty>\", \"matches_declared_term\": true|false, \"name_matches\": true|false, "
        . "\"reason\": \"<one short sentence a student can understand>\"}";

    $payload = [
        'contents' => [['parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($binary)]]]]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
    ];
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . GEMINI_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) { error_log('COR check: connection failed: ' . $error); return $unavailable('The AI check could not be reached, so an admin will check this COR.'); }
    if ($code !== 200) { error_log("COR check: HTTP $code"); return $unavailable('The AI check is not available right now, so an admin will check this COR.'); }

    $text = (string) (json_decode($response, true)['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $text = preg_replace(['/^```(?:json)?\s*/i', '/\s*```$/'], '', trim($text));
    $r = json_decode($text, true);
    if (!is_array($r) || !isset($r['is_cor'])) return $unavailable('The AI answer could not be read, so an admin will check this COR.');

    $found = trim(trim((string) ($r['semester_found'] ?? '')) . ' ' . trim((string) ($r['school_year_found'] ?? '')));
    $reason = trim((string) ($r['reason'] ?? ''));
    if (empty($r['is_cor'])) return ['verdict' => 'fail', 'reason' => 'This does not look like a Certificate of Registration. ' . $reason, 'semester_found' => $found ?: null];
    if (!empty($r['tampered'])) return ['verdict' => 'fail', 'reason' => 'This COR looks edited. Upload it exactly as you got it. ' . $reason, 'semester_found' => $found ?: null];
    if (empty($r['matches_declared_term'])) {
        return ['verdict' => 'fail', 'reason' => "This COR is for " . ($found ?: 'a different semester') . ", not $semester AY $school_year. Upload your COR for this semester.", 'semester_found' => $found ?: null];
    }
    if (array_key_exists('name_matches', $r) && !$r['name_matches']) {
        return ['verdict' => 'fail', 'reason' => 'The name on this COR is not yours. Upload your own COR. ' . $reason, 'semester_found' => $found ?: null];
    }
    return ['verdict' => 'pass', 'reason' => $reason ?: 'Genuine COR for the declared semester.', 'semester_found' => $found ?: null];
}
