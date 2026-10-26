<?php

require dirname(__DIR__) . '/includes/scholarship_eligibility.php';

$today = new DateTimeImmutable('2026-10-26', new DateTimeZone('Asia/Manila'));
$student = [
    'ProgramID' => 18,
    'YearLevel' => '3rd Year',
    'GPA' => 1.45,
    'Gender' => 'Male',
];
$scholarship = [
    'Status' => 'Active',
    'ProgramID' => 18,
    'YearLevel' => '3rd Year',
    'MinimumGWA' => 2.00,
    'GenderRequirement' => 'Any',
    'Deadline' => '2026-12-31',
];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$eligible = scholarshipEligibilityCheck($student, $scholarship, [], $today);
$assert($eligible['status'] === 'eligible', 'Expected an eligible result.');
$assert($eligible['score'] === 100, 'Expected a perfect match score.');

$multiYear = $scholarship;
$multiYear['YearLevel'] = '2nd Year, 3rd Year';
$assert(scholarshipEligibilityCheck($student, $multiYear, [], $today)['eligible'], 'Comma-separated year levels should match.');

$gwaFailure = $student;
$gwaFailure['GPA'] = 2.50;
$failed = scholarshipEligibilityCheck($gwaFailure, $scholarship, [], $today);
$assert($failed['status'] === 'ineligible', 'A GWA outside the threshold should fail.');
$assert($failed['score'] === 70, 'A failed GWA should remove its score weight.');

$missingProgram = $student;
$missingProgram['ProgramID'] = null;
$incomplete = scholarshipEligibilityCheck($missingProgram, $scholarship, [], $today);
$assert($incomplete['status'] === 'needs_profile', 'Missing required profile data should request profile completion.');

$expired = $scholarship;
$expired['Deadline'] = '2026-10-25';
$closed = scholarshipEligibilityCheck($student, $expired, [], $today);
$assert($closed['status'] === 'closed', 'Past deadlines should close applications.');

echo "Scholarship eligibility tests passed.\n";
