<?php

function setApplicationDecision(PDO $pdo, int $application_id, string $status): ?string {
    if (in_array($status, ['Rejected', 'Disqualified'], true)) {
        $stmt = $pdo->prepare("SELECT COALESCE(s.AppealWindowDays, 7) FROM application a JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID WHERE a.ApplicationID = ?");
        $stmt->execute([$application_id]);
        $days = max(1, (int) ($stmt->fetchColumn() ?: 7));
        $pdo->prepare("UPDATE application SET Status = ?, DecisionAt = NOW(), AppealDeadline = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE ApplicationID = ?")
            ->execute([$status, $days, $application_id]);
        $deadline = $pdo->prepare("SELECT DATE_FORMAT(AppealDeadline, '%M %e, %Y at %l:%i %p') FROM application WHERE ApplicationID = ?");
        $deadline->execute([$application_id]);
        return $deadline->fetchColumn() ?: null;
    }
    $pdo->prepare("UPDATE application SET Status = ?, DecisionAt = NOW(), AppealDeadline = NULL WHERE ApplicationID = ?")
        ->execute([$status, $application_id]);
    return null;
}

function appealStatusClass(string $status): string {
    return match ($status) {
        'Approved' => 'bg-green-50 text-green-700 border-green-200',
        'Denied' => 'bg-red-50 text-red-700 border-red-200',
        'Under Review' => 'bg-amber-50 text-amber-700 border-amber-200',
        default => 'bg-blue-50 text-blue-700 border-blue-200',
    };
}

function appealDocumentUrl(string $path): string {
    $relative = preg_replace('#^(?:\.\./)?uploads/#', '', str_replace('\\', '/', $path));
    return '../uploads/serve.php?f=' . rawurlencode($relative);
}
