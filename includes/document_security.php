<?php

const DOCUMENT_MAX_BYTES = 10 * 1024 * 1024;
const DOCUMENT_ALLOWED_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

/** Validate the real bytes of an uploaded document and return auditable metadata. */
function inspectDocumentFile(string $path, string $original_name, int $max_bytes = DOCUMENT_MAX_BYTES): array {
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('The selected document cannot be read.');
    $size = filesize($path);
    if ($size === false || $size <= 0) throw new RuntimeException('Empty documents are not allowed.');
    if ($size > $max_bytes) throw new RuntimeException('The document exceeds the 10 MB limit.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($path);
    if (!isset(DOCUMENT_ALLOWED_MIME[$mime])) {
        throw new RuntimeException('The actual file content is not an allowed PDF, JPG, or PNG document.');
    }
    $extension = DOCUMENT_ALLOWED_MIME[$mime];
    $claimed = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $claimed = $claimed === 'jpeg' ? 'jpg' : $claimed;
    if ($claimed !== $extension) throw new RuntimeException('The filename extension does not match the actual file content.');

    $head = (string) file_get_contents($path, false, null, 0, min($size, 8192));
    if ($mime === 'application/pdf') {
        if (!str_starts_with($head, '%PDF-')) throw new RuntimeException('The uploaded file does not contain a valid PDF header.');
        $tail = (string) file_get_contents($path, false, null, max(0, $size - 4096));
        if (strpos($tail, '%%EOF') === false) throw new RuntimeException('The PDF is incomplete or corrupted.');
    } else {
        $image = @getimagesize($path);
        if (!$image || ($image['mime'] ?? '') !== $mime) throw new RuntimeException('The image content is invalid or corrupted.');
    }

    $scan = scanDocumentForMalware($path, $head);
    if ($scan['status'] !== 'Clean') throw new RuntimeException('The document failed malware scanning and was not saved.');

    return [
        'original_name' => basename($original_name),
        'mime_type' => $mime,
        'extension' => $extension,
        'file_size' => $size,
        'checksum' => hash_file('sha256', $path),
        'malware_status' => $scan['status'],
        'scanner_engine' => $scan['engine'],
        'scanned_at' => date('Y-m-d H:i:s'),
    ];
}

/** Use ClamAV when installed; always apply local signature and active-content checks. */
function scanDocumentForMalware(string $path, string $head = ''): array {
    $sample = $head !== '' ? $head : (string) file_get_contents($path, false, null, 0, 8192);
    if (str_contains($sample, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')) {
        return ['status' => 'Rejected', 'engine' => 'Built-in signatures (EICAR)'];
    }
    if (str_starts_with($sample, 'MZ') || str_starts_with($sample, "\x7FELF")) {
        return ['status' => 'Rejected', 'engine' => 'Built-in executable signature scanner'];
    }
    if (str_starts_with($sample, '%PDF-')) {
        $pdf = (string) file_get_contents($path);
        if (preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b/i', $pdf)) {
            return ['status' => 'Rejected', 'engine' => 'Built-in PDF active-content scanner'];
        }
    }

    $env = file_exists(dirname(__DIR__) . '/.env') ? @parse_ini_file(dirname(__DIR__) . '/.env') : [];
    $candidates = array_filter([
        is_array($env) ? ($env['CLAMSCAN_PATH'] ?? '') : '',
        'C:\\Program Files\\ClamAV\\clamscan.exe',
        '/usr/bin/clamscan',
        '/usr/local/bin/clamscan',
    ]);
    foreach ($candidates as $binary) {
        if (!is_file($binary) || !is_executable($binary)) continue;
        $output = [];
        $code = 2;
        exec(escapeshellarg($binary) . ' --no-summary ' . escapeshellarg($path) . ' 2>&1', $output, $code);
        if ($code === 0) return ['status' => 'Clean', 'engine' => 'ClamAV'];
        if ($code === 1) return ['status' => 'Rejected', 'engine' => 'ClamAV'];
        throw new RuntimeException('The malware scanner could not complete. Please try again later.');
    }
    return ['status' => 'Clean', 'engine' => 'Built-in signature and active-content scanner'];
}

function resolveProtectedDocumentPath(string $stored_path): string {
    $root = realpath(dirname(__DIR__) . '/uploads');
    $candidate = realpath(dirname(__DIR__) . '/' . ltrim(str_replace('..', '', str_replace('\\', '/', $stored_path)), '/'));
    if ($root === false || $candidate === false || !is_file($candidate) || strpos($candidate, $root . DIRECTORY_SEPARATOR) !== 0) {
        throw new RuntimeException('Document file not found.');
    }
    return $candidate;
}

function protectedDocumentUrl(int $submitted_doc_id, bool $download = false): string {
    return '../actions/serve_document.php?type=submitted&id=' . $submitted_doc_id . ($download ? '&download=1' : '');
}

