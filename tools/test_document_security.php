<?php
require_once __DIR__ . '/../includes/document_security.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$sample = null;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../uploads/documents'));
foreach ($iterator as $file) {
    if ($file->isFile() && in_array(strtolower($file->getExtension()), ['pdf','png','jpg','jpeg'], true)) { $sample = $file->getPathname(); break; }
}
$assert($sample !== null, 'A sample document is required for the security test.');

if ($sample !== null) {
    try {
        $metadata = inspectDocumentFile($sample, basename($sample));
        $assert(strlen($metadata['checksum']) === 64, 'SHA-256 checksum was not generated.');
        $assert($metadata['malware_status'] === 'Clean', 'Clean sample did not pass malware scanning.');
        $assert($metadata['file_size'] === filesize($sample), 'Recorded file size is incorrect.');
    } catch (Throwable $e) { $failures[] = 'Valid content failed inspection: ' . $e->getMessage(); }

    try { inspectDocumentFile($sample, 'spoofed.exe'); $failures[] = 'Spoofed extension was accepted.'; }
    catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'extension'), 'Spoofed extension failed for the wrong reason.'); }

    try { inspectDocumentFile($sample, basename($sample), 1); $failures[] = 'Oversized content was accepted.'; }
    catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), '10 MB'), 'Size limit failed for the wrong reason.'); }
}

$eicar = scanDocumentForMalware('unused', 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
$assert($eicar['status'] === 'Rejected', 'EICAR malware test signature was not rejected.');

if ($failures) {
    fwrite(STDERR, "Document security test FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Document security test passed: content signature, format spoofing, size, malware, and checksum controls.\n";
