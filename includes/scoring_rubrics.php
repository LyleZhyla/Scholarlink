<?php

function scholarshipRubric(PDO $pdo, int $scholarshipId): array {
    $settings = $pdo->prepare('SELECT * FROM scholarship_scoring_rubrics WHERE ScholarshipID = ?');
    $settings->execute([$scholarshipId]);
    $rubric = $settings->fetch(PDO::FETCH_ASSOC) ?: [
        'ScholarshipID' => $scholarshipId, 'PassingScore' => 75, 'TotalMaximumScore' => 100,
        'TieBreakingRules' => 'Highest weighted score, then lowest GWA, then earliest submission.',
        'GeneralCommentsRequired' => 1,
        'OverrideProcedure' => 'Only a Super Administrator may override a completed score after documenting the reason.'
    ];
    $criteria = $pdo->prepare('SELECT CriteriaID, CriteriaName, WeightPercent, MaximumScore, PassingScore, CommentRequired, TieBreakPriority, SortOrder FROM scholarship_criteria WHERE ScholarshipID = ? AND IsActive = 1 ORDER BY SortOrder, CriteriaID');
    $criteria->execute([$scholarshipId]);
    $rubric['criteria'] = $criteria->fetchAll(PDO::FETCH_ASSOC);
    return $rubric;
}

function applicationRubric(PDO $pdo, int $applicationId): array {
    require_once __DIR__ . '/scholarship_config_versions.php';
    $snapshot = applicationConfigurationSnapshot($pdo, $applicationId);
    if (!empty($snapshot['scoring_rubric_settings'])) {
        $rubric = $snapshot['scoring_rubric_settings'];
        $rubric['criteria'] = $snapshot['scoring_rubric'] ?? [];
        return $rubric;
    }
    if (!empty($snapshot['_version']) && !empty($snapshot['scoring_rubric'])) {
        $criteria = $snapshot['scoring_rubric']; $count = count($criteria); $base = round(100 / $count, 2);
        foreach ($criteria as $i => &$criterion) {
            $criterion += ['WeightPercent' => $i === $count - 1 ? round(100 - $base * ($count - 1), 2) : $base, 'MaximumScore' => 100, 'PassingScore' => null, 'CommentRequired' => 0, 'TieBreakPriority' => null, 'SortOrder' => $i + 1];
        } unset($criterion);
        return ['PassingScore'=>75,'TotalMaximumScore'=>100,'TieBreakingRules'=>'Highest weighted score, then lowest GWA, then earliest submission.','GeneralCommentsRequired'=>1,'OverrideProcedure'=>'Only a Super Administrator may override a completed score after documenting the reason.','criteria'=>$criteria];
    }
    $stmt = $pdo->prepare('SELECT ScholarshipID FROM application WHERE ApplicationID = ?');
    $stmt->execute([$applicationId]);
    return scholarshipRubric($pdo, (int) $stmt->fetchColumn());
}

function calculateRubricScore(array $rubric, array $scores, array $comments, string $generalComment): array {
    $criteria = $rubric['criteria'] ?? [];
    if (!$criteria) throw new RuntimeException('This scholarship has no active scoring criteria.');
    $weightTotal = array_sum(array_map(fn($c) => (float) ($c['WeightPercent'] ?? 0), $criteria));
    if (abs($weightTotal - 100) > 0.01) throw new RuntimeException('Rubric weights must total exactly 100%.');
    if (!empty($rubric['GeneralCommentsRequired']) && mb_strlen(trim($generalComment)) < 10) throw new RuntimeException('Evaluator comments of at least 10 characters are required.');
    $items = []; $weighted = 0; $raw = 0;
    foreach ($criteria as $criterion) {
        $id = (int) $criterion['CriteriaID'];
        if (!isset($scores[$id]) || !is_numeric($scores[$id])) throw new RuntimeException('Every rubric criterion must be scored.');
        $value = round((float) $scores[$id], 2); $max = (float) $criterion['MaximumScore'];
        if ($value < 0 || $value > $max || $max <= 0) throw new RuntimeException("{$criterion['CriteriaName']} must be between 0 and {$max}.");
        $comment = trim((string) ($comments[$id] ?? ''));
        if (!empty($criterion['CommentRequired']) && mb_strlen($comment) < 5) throw new RuntimeException("A comment is required for {$criterion['CriteriaName']}.");
        $part = round(($value / $max) * (float) $criterion['WeightPercent'], 2);
        $raw += $value; $weighted += $part;
        $items[] = ['criterion' => $criterion, 'raw' => $value, 'weighted' => $part, 'comment' => $comment];
    }
    $weighted = round($weighted, 2); $passing = (float) ($rubric['PassingScore'] ?? 75);
    return ['raw_total' => round($raw, 2), 'weighted_score' => $weighted, 'passing_score' => $passing, 'result' => $weighted >= $passing ? 'Passed' : 'Failed', 'items' => $items];
}

function saveApplicationScorecard(PDO $pdo, int $applicationId, int $evaluatorId, array $rubric, array $calculation, string $generalComment): int {
    $pdo->prepare('UPDATE application_scorecards SET IsCurrent = 0 WHERE ApplicationID = ?')->execute([$applicationId]);
    $stmt = $pdo->prepare('INSERT INTO application_scorecards (ApplicationID, EvaluatorID, RawTotal, WeightedScore, PassingScore, Result, EvaluatorComments, RubricSnapshotJson) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$applicationId, $evaluatorId, $calculation['raw_total'], $calculation['weighted_score'], $calculation['passing_score'], $calculation['result'], trim($generalComment) ?: null, json_encode($rubric, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $scorecardId = (int) $pdo->lastInsertId();
    $item = $pdo->prepare('INSERT INTO application_score_items (ScorecardID, CriteriaID, CriteriaName, RawScore, MaximumScore, WeightPercent, WeightedScore, EvaluatorComment) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($calculation['items'] as $row) {
        $c = $row['criterion'];
        $item->execute([$scorecardId, $c['CriteriaID'], $c['CriteriaName'], $row['raw'], $c['MaximumScore'], $c['WeightPercent'], $row['weighted'], $row['comment'] ?: null]);
    }
    return $scorecardId;
}
