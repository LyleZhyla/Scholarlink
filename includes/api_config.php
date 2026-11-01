<?php
// AI key for the chatbot (process_chat.php) and the AI system report (ai_system_report.php).
// The key itself lives only in .env (GEMINI_API_KEY), like every other secret, so changing it there is enough.
if (!defined('GEMINI_API_KEY')) {
    $api_env = is_file(__DIR__ . '/../.env') ? (@parse_ini_file(__DIR__ . '/../.env') ?: []) : [];
    define('GEMINI_API_KEY', trim((string) ($api_env['GEMINI_API_KEY'] ?? '')));
}
