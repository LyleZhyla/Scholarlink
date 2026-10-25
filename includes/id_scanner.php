<?php
// ✨ STUDENT ID SCANNER (Google Vision OCR)
// Shared by registration (actions/scan_id_ocr.php) and program shifting (actions/process_program_shift.php).
// The student's program is always taken from the scanned ID, never typed in by the student.

if (!function_exists('readIdText')) {

    // Sends the image to Google Vision and returns ['text' => ..., 'error' => ...]
    function readIdText(string $image_path): array {
        $env_path = __DIR__ . '/../.env';
        if (!file_exists($env_path)) {
            return ['text' => '', 'error' => 'System Error: .env file is missing.'];
        }
        $env = parse_ini_file($env_path);
        $api_key = $env['GOOGLE_VISION_API_KEY'] ?? '';
        if (empty($api_key)) {
            return ['text' => '', 'error' => 'System Error: API Key is not set in .env file.'];
        }

        $request_body = [
            'requests' => [[
                'image' => ['content' => base64_encode(file_get_contents($image_path))],
                'features' => [['type' => 'TEXT_DETECTION', 'maxResults' => 1]]
            ]]
        ];

        $ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key=' . $api_key);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_body));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        $vision_data = json_decode($response, true);
        if (isset($vision_data['responses'][0]['textAnnotations'][0]['description'])) {
            return ['text' => $vision_data['responses'][0]['textAnnotations'][0]['description'], 'error' => ''];
        }

        // A real API problem (bad key, quota, etc.)
        $google_error = $vision_data['error']['message'] ?? $vision_data['responses'][0]['error']['message'] ?? null;
        if ($google_error !== null || !isset($vision_data['responses'])) {
            return ['text' => '', 'error' => 'Google API Alert: ' . ($google_error ?? 'No response from the scanner service.')];
        }

        // The request worked but Google found no text in the image (blurry, dark, glare, or no ID in frame)
        return ['text' => '', 'error' => 'No text could be read from the image. Make sure the whole ID is in frame, in focus, well-lit and without glare, then try again.'];
    }

    // 🧠 DATA EXTRACTION LOGIC (PERFECTED FOR TAU)
    function parseIdData(string $extracted_text): array {
        $data = [
            'student_id' => '',
            'first_name' => '',
            'last_name' => '',
            'course' => '',
            'date_of_birth' => ''
        ];

        // 1. STUDENT NUMBER
        if (preg_match('/\b(20[0-9]{8})\b/', $extracted_text, $matches)) {
            $data['student_id'] = $matches[1];
        }

        // 2. DATE OF BIRTH
        if (preg_match('/(?:Date of Birth|DOB)[\s\S]{0,10}?([A-Za-z]+\s+\d{1,2},?\s+\d{4}|\d{2}[\/\-]\d{2}[\/\-]\d{4})/i', $extracted_text, $matches)) {
            $data['date_of_birth'] = date('Y-m-d', strtotime(trim($matches[1])));
        }

        // 3. COURSE / PROGRAM (raw text, for display only — see matchProgramFromText)
        if (preg_match('/(?:Bachelor\s+of\s+[A-Za-z\s]+\s+in\s+|Bachelor\s+of\s+|B\.?S\.?\s+(?:in\s+)?)([^\n\r]+)/i', $extracted_text, $matches)) {
            $data['course'] = trim($matches[1]);
        } elseif (preg_match('/(BSIT|BS[A-Z]{2,5})/i', $extracted_text, $matches)) {
            $data['course'] = trim($matches[1]);
        }

        // 4. SMART NAME PARSING
        $lines = explode("\n", $extracted_text);
        $blacklist = ['REPUBLIC', 'PHILIPPINES', 'TARLAC', 'AGRICULTURAL', 'UNIVERSITY', 'CAMILING', 'EMERGENCY', 'NOTIFY', 'ADDRESS', 'SIGNATURE', 'VALIDITY', 'SEMESTER', 'ID NUMBER', 'DATE OF BIRTH', 'STUDENT'];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $skip = false;
            foreach ($blacklist as $bad_word) {
                if (stripos($line, $bad_word) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // Eksaktong hahanapin ang format na: SANIDAD, CHRIS JUNE B.
            if (empty($data['last_name']) && preg_match('/^([A-ZÑ\-\s]+),\s*([A-ZÑ\-\s\.]+)$/i', $line, $matches)) {
                $data['last_name'] = ucwords(strtolower(trim($matches[1])));
                $data['first_name'] = ucwords(strtolower(trim($matches[2])));
                break;
            }
        }

        return $data;
    }

    // Finds which program in the `program` table is printed on the ID.
    // Matches the program's core name (e.g. "Information Technology") or its acronym in
    // parentheses (e.g. "BSIT"); the longest core-name match wins. Returns null if none match.
    function matchProgramFromText(PDO $pdo, string $extracted_text): ?array {
        $text = ' ' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', ' ', $extracted_text)) . ' ';
        $programs = $pdo->query("SELECT ProgramID, ProgramName FROM program")->fetchAll(PDO::FETCH_ASSOC);

        $best = null;
        $best_len = 0;
        foreach ($programs as $prog) {
            $name = $prog['ProgramName'];

            // "BS Information Technology (BSIT)" -> core "INFORMATION TECHNOLOGY"
            $core = preg_replace('/\(.*?\)/', '', $name);
            $core = preg_replace('/^\s*(BS|AB|Bachelor\s+of|Doctor\s+of)\s+/i', '', $core);
            $core = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', ' ', $core)));

            if ($core !== '' && strpos($text, ' ' . $core . ' ') !== false && strlen($core) > $best_len) {
                $best = $prog;
                $best_len = strlen($core);
            }
        }
        if ($best) return $best;

        // Fallback: acronym such as BSIT, DVM, BEEd
        foreach ($programs as $prog) {
            if (preg_match('/\(([A-Za-z]{2,8})\)/', $prog['ProgramName'], $m)
                && strpos($text, ' ' . strtoupper($m[1]) . ' ') !== false) {
                return $prog;
            }
        }

        return null;
    }
}
