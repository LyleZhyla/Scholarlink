<?php
// ✨ AUDIT LOG GATE
// Audit logs need a fresh password check on top of the Super Admin session.
// A successful check unlocks them for AUDIT_UNLOCK_SECONDS, then they lock again.

if (!defined('AUDIT_UNLOCK_SECONDS')) {
    define('AUDIT_UNLOCK_SECONDS', 600); // 10 minutes
}

if (!function_exists('isAuditUnlocked')) {
    function isAuditUnlocked(): bool {
        return ($_SESSION['role'] ?? '') === 'Super_Admin'
            && ($_SESSION['audit_unlocked_until'] ?? 0) > time();
    }

    // Sends the user to the unlock page, then back to the current page (with its filters) after verifying
    function requireAuditUnlock(): void {
        if (isAuditUnlocked()) return;

        $return_to = basename($_SERVER['PHP_SELF']);
        if (!empty($_SERVER['QUERY_STRING'])) {
            $return_to .= '?' . $_SERVER['QUERY_STRING'];
        }
        header("Location: audit_unlock.php?next=" . urlencode($return_to));
        exit();
    }
}
