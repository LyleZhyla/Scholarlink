<?php

function scholarshipEligibilityList(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $parts = preg_split('/\s*(?:,|\||\/|;|\band\b)\s*/i', $value) ?: [];
    return array_values(array_filter(array_map(
        static fn($part) => strtolower(trim((string)$part)),
        $parts
    )));
}

function scholarshipEligibilityCheck(
    array $student,
    array $scholarship,
    array $eligibleProgramIds = [],
    ?DateTimeImmutable $today = null
): array {
    $today ??= new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $checks = [];
    $score = 0;
    $blockers = [];
    $missing = [];

    $addCheck = static function (
        string $code,
        string $label,
        string $state,
        string $detail,
        int $weight
    ) use (&$checks, &$score, &$blockers, &$missing): void {
        $checks[] = compact('code', 'label', 'state', 'detail', 'weight');
        if ($state === 'pass') {
            $score += $weight;
        } elseif ($state === 'missing') {
            $missing[] = $detail;
        } else {
            $blockers[] = $detail;
        }
    };

    $status = strtolower(trim((string)($scholarship['Status'] ?? '')));
    if ($status !== 'active') {
        $blockers[] = 'This scholarship is not currently active.';
    }

    $programIds = array_values(array_unique(array_filter(array_map('intval', $eligibleProgramIds))));
    $legacyProgramId = (int)($scholarship['ProgramID'] ?? 0);
    if ($legacyProgramId > 0 && !in_array($legacyProgramId, $programIds, true)) {
        $programIds[] = $legacyProgramId;
    }
    $studentProgramId = (int)($student['ProgramID'] ?? 0);
    if (!$programIds) {
        $addCheck('program', 'Program', 'pass', 'Open to students from all programs.', 25);
    } elseif ($studentProgramId <= 0) {
        $addCheck('program', 'Program', 'missing', 'Add your academic program to your profile.', 25);
    } elseif (in_array($studentProgramId, $programIds, true)) {
        $addCheck('program', 'Program', 'pass', 'Your academic program is accepted.', 25);
    } else {
        $addCheck('program', 'Program', 'fail', 'Your academic program is not included.', 25);
    }

    $allowedYears = scholarshipEligibilityList($scholarship['YearLevel'] ?? null);
    $studentYear = strtolower(trim((string)($student['YearLevel'] ?? '')));
    if (!$allowedYears || in_array('all levels', $allowedYears, true)) {
        $addCheck('year_level', 'Year level', 'pass', 'Open to all year levels.', 20);
    } elseif ($studentYear === '') {
        $addCheck('year_level', 'Year level', 'missing', 'Add your year level to your profile.', 20);
    } elseif (in_array($studentYear, $allowedYears, true)) {
        $addCheck('year_level', 'Year level', 'pass', 'Your year level is accepted.', 20);
    } else {
        $addCheck('year_level', 'Year level', 'fail', 'Required year level: ' . implode(', ', array_map('ucwords', $allowedYears)) . '.', 20);
    }

    $maximumGwa = isset($scholarship['MinimumGWA']) ? (float)$scholarship['MinimumGWA'] : 0.0;
    $studentGwa = isset($student['GPA']) ? (float)$student['GPA'] : 0.0;
    if ($maximumGwa <= 0) {
        $addCheck('gwa', 'GWA', 'pass', 'No minimum GWA is configured.', 30);
    } elseif ($studentGwa <= 0) {
        $addCheck('gwa', 'GWA', 'missing', 'Add a valid GWA to your profile.', 30);
    } elseif ($studentGwa <= $maximumGwa) {
        $addCheck('gwa', 'GWA', 'pass', 'Your GWA of ' . number_format($studentGwa, 2) . ' meets the ' . number_format($maximumGwa, 2) . ' requirement.', 30);
    } else {
        $gap = $studentGwa - $maximumGwa;
        $addCheck('gwa', 'GWA', 'fail', 'Required GWA is ' . number_format($maximumGwa, 2) . ' or better; your profile is ' . number_format($studentGwa, 2) . ' (' . number_format($gap, 2) . ' away).', 30);
    }

    $requiredGender = strtolower(trim((string)($scholarship['GenderRequirement'] ?? 'any')));
    $studentGender = strtolower(trim((string)($student['Gender'] ?? '')));
    if ($requiredGender === '' || $requiredGender === 'any') {
        $addCheck('gender', 'Gender', 'pass', 'No gender restriction.', 10);
    } elseif ($studentGender === '' || $studentGender === 'not specified') {
        $addCheck('gender', 'Gender', 'missing', 'Complete the gender field in your profile.', 10);
    } elseif ($studentGender === $requiredGender) {
        $addCheck('gender', 'Gender', 'pass', 'You meet the gender requirement.', 10);
    } else {
        $addCheck('gender', 'Gender', 'fail', 'This scholarship is limited to ' . ucfirst($requiredGender) . ' applicants.', 10);
    }

    $deadlineValue = trim((string)($scholarship['Deadline'] ?? ''));
    $deadline = $deadlineValue !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $deadlineValue, new DateTimeZone('Asia/Manila')) : false;
    $deadlinePassed = false;
    $daysLeft = null;
    if (!$deadline) {
        $addCheck('deadline', 'Deadline', 'pass', 'No closing date is configured.', 15);
    } else {
        $daysLeft = (int)$today->diff($deadline)->format('%r%a');
        if ($deadline < $today) {
            $deadlinePassed = true;
            $addCheck('deadline', 'Deadline', 'fail', 'Applications closed on ' . $deadline->format('M j, Y') . '.', 15);
        } else {
            $addCheck('deadline', 'Deadline', 'pass', $daysLeft === 0 ? 'Applications close today.' : "{$daysLeft} day(s) remaining before the deadline.", 15);
        }
    }

    $statusKey = 'eligible';
    if ($deadlinePassed || $status !== 'active') {
        $statusKey = 'closed';
    } elseif ($blockers) {
        $statusKey = 'ineligible';
    } elseif ($missing) {
        $statusKey = 'needs_profile';
    }

    $labels = [
        'eligible' => 'Eligible',
        'needs_profile' => 'Complete Profile',
        'ineligible' => 'Not Eligible',
        'closed' => 'Closed',
    ];

    return [
        'status' => $statusKey,
        'label' => $labels[$statusKey],
        'score' => max(0, min(100, $score)),
        'eligible' => $statusKey === 'eligible',
        'checks' => $checks,
        'blockers' => array_values(array_unique($blockers)),
        'missing' => array_values(array_unique($missing)),
        'days_left' => $daysLeft,
        'student_gwa' => $studentGwa,
    ];
}

function scholarshipEligibilityForStudent(PDO $pdo, int $userId, int $scholarshipId): array
{
    $studentStatement = $pdo->prepare('SELECT * FROM users WHERE UserID = ? AND Role = \'Student\'');
    $studentStatement->execute([$userId]);
    $student = $studentStatement->fetch(PDO::FETCH_ASSOC);

    $scholarshipStatement = $pdo->prepare('SELECT * FROM scholarship WHERE ScholarshipID = ?');
    $scholarshipStatement->execute([$scholarshipId]);
    $scholarship = $scholarshipStatement->fetch(PDO::FETCH_ASSOC);

    if (!$student || !$scholarship) {
        throw new RuntimeException('Student or scholarship record was not found.');
    }

    $programStatement = $pdo->prepare('SELECT ProgramID FROM scholarship_programs WHERE ScholarshipID = ?');
    $programStatement->execute([$scholarshipId]);
    $programIds = array_map('intval', $programStatement->fetchAll(PDO::FETCH_COLUMN));

    return scholarshipEligibilityCheck($student, $scholarship, $programIds);
}

function scholarshipEligibilityError(array $result): string
{
    $reasons = array_merge($result['blockers'] ?? [], $result['missing'] ?? []);
    return $reasons
        ? 'You cannot apply yet: ' . implode(' ', $reasons)
        : 'You are not currently eligible for this scholarship.';
}
