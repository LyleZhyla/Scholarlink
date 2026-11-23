<?php
// A message shown after an action ($_SESSION['success'] / ['error']), made safe to print: it can carry names people typed
// (a student's name, a scholarship's), so everything is escaped; only bold and line breaks stay as formatting.
if (!function_exists('flashHtml')) {
    function flashHtml($message): string {
        $safe = htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8');
        return preg_replace_callback('#&lt;(/?)(b|strong)&gt;|&lt;br\s*/?&gt;#i',
            fn($m) => isset($m[2]) && $m[2] !== '' ? "<{$m[1]}{$m[2]}>" : '<br>', $safe);
    }
}
