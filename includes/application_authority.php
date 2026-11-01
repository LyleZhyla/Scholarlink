<?php
// ✨ APPLICATION DECISION AUTHORITY
// Who may shortlist, score, approve or reject an application depends on who funds the scholarship:
//   - Internally funded (created by an Internal Admin / Super Admin) -> Internal Admins (OSSD)
//   - Externally funded (created by an External provider account)   -> only that provider account
//   - Super Admin may decide on any application (oversight)

if (!function_exists('getApplicationAuthority')) {

    // SQL condition (needs `scholarship s` joined with `users cu ON cu.UserID = s.CreatedBy`)
    // that is true for internally funded scholarships
    define('INTERNALLY_FUNDED_SQL', "(cu.Role IS NULL OR cu.Role IN ('Internal_Admin', 'Super_Admin'))");

    // Funding owner of the application's scholarship, or null if the application doesn't exist
    function getApplicationAuthority(PDO $pdo, $application_id): ?array {
        $stmt = $pdo->prepare("
            SELECT s.ScholarshipID, s.Name AS ScholarshipName, s.CreatedBy, cu.Role AS CreatorRole,
                   COALESCE(NULLIF(cu.Organization, ''), CONCAT(cu.FirstName, ' ', cu.LastName)) AS ProviderName
            FROM application a
            JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
            LEFT JOIN users cu ON cu.UserID = s.CreatedBy
            WHERE a.ApplicationID = ?
        ");
        $stmt->execute([$application_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['IsInternal'] = $row['CreatorRole'] === null || in_array($row['CreatorRole'], ['Internal_Admin', 'Super_Admin'], true);
        $row['DecidedBy'] = $row['IsInternal'] ? 'the OSSD (Internal Admin)' : $row['ProviderName'];
        return $row;
    }

    // Same ownership info for a scholarship (used to decide who may design its application form)
    function getScholarshipAuthority(PDO $pdo, $scholarship_id): ?array {
        $stmt = $pdo->prepare("
            SELECT s.ScholarshipID, s.Name AS ScholarshipName, s.CreatedBy, cu.Role AS CreatorRole,
                   COALESCE(NULLIF(cu.Organization, ''), CONCAT(cu.FirstName, ' ', cu.LastName)) AS ProviderName
            FROM scholarship s
            LEFT JOIN users cu ON cu.UserID = s.CreatedBy
            WHERE s.ScholarshipID = ?
        ");
        $stmt->execute([$scholarship_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['IsInternal'] = $row['CreatorRole'] === null || in_array($row['CreatorRole'], ['Internal_Admin', 'Super_Admin'], true);
        $row['DecidedBy'] = $row['IsInternal'] ? 'the OSSD (Internal Admin)' : $row['ProviderName'];
        return $row;
    }

    function canDecideApplication(array $authority, string $role, $user_id): bool {
        if ($role === 'Super_Admin') return true;
        if ($role === 'Internal_Admin') return $authority['IsInternal'];
        if ($role === 'External_Admin') return !$authority['IsInternal'] && (int) $authority['CreatedBy'] === (int) $user_id;
        return false;
    }

    // Stops the request with an error unless the current admin may decide on this application
    function requireApplicationAuthority(PDO $pdo, $application_id, string $fallback_redirect): array {
        $authority = getApplicationAuthority($pdo, $application_id);
        if (!$authority || !canDecideApplication($authority, $_SESSION['role'] ?? '', $_SESSION['user_id'] ?? 0)) {
            $_SESSION['error'] = $authority
                ? "Only {$authority['DecidedBy']} can make decisions on applications for {$authority['ScholarshipName']}."
                : "Application not found.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? $fallback_redirect));
            exit();
        }
        return $authority;
    }
}
