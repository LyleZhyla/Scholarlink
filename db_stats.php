<?php
// Force PHP to show errors if any occur
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('../config.php');
require_login();

// SECURITY LOCK: Only allow Site Administrators to view this page
if (!is_siteadmin()) {
    print_error('nopermissions', 'error', '', 'view this custom statistics dashboard');
}

global $DB;

// ==========================================
// IMPORTANT: Set this to your RAM Quiz ID!
// ==========================================
$quizid = 1;

// 1. Get Individual Scores
$scores_sql = "
    SELECT qa.id, u.firstname, u.lastname, qa.attempt, qa.sumgrades
    FROM {quiz_attempts} qa
    JOIN {user} u ON u.id = qa.userid
    WHERE qa.quiz = :quizid AND qa.state = 'finished'
    ORDER BY u.lastname, u.firstname, qa.attempt
";
$scores = $DB->get_records_sql($scores_sql, ['quizid' => $quizid]);

// 2. Get Question Statistics
$stats_sql = "
    SELECT
        q.id,
        q.name AS questionname,
        SUM(CASE WHEN qas.fraction = 1 THEN 1 ELSE 0 END) AS correct_count,
        SUM(CASE WHEN qas.fraction < 1 OR qas.fraction IS NULL THEN 1 ELSE 0 END) AS wrong_count,
        ( SUM(CASE WHEN qas.fraction = 1 THEN 1 ELSE 0 END) / COUNT(qat.id) ) * 100 AS success_rate
    FROM {quiz_attempts} qa
    JOIN {question_usages} qu ON qu.id = qa.uniqueid
    JOIN {question_attempts} qat ON qat.questionusageid = qu.id
    JOIN {question} q ON q.id = qat.questionid
    JOIN (
        SELECT questionattemptid, MAX(sequencenumber) AS maxseq
        FROM {question_attempt_steps}
        GROUP BY questionattemptid
    ) max_step ON max_step.questionattemptid = qat.id
    JOIN {question_attempt_steps} qas ON qas.questionattemptid = qat.id AND qas.sequencenumber = max_step.maxseq
    WHERE qa.quiz = :quizid AND qa.state = 'finished'
    GROUP BY q.id, q.name
    ORDER BY success_rate ASC
";
$question_stats = $DB->get_records_sql($stats_sql, ['quizid' => $quizid]);

$lowest_success_rate = 100;
$hardest_questions = [];

if ($question_stats) {
    foreach ($question_stats as $stat) {
        $q_record = $DB->get_record('question', ['id' => $stat->id], 'questiontext');
        $stat->questiontext = $q_record ? $q_record->questiontext : 'Text not available';

        if ($stat->success_rate < $lowest_success_rate) {
            $lowest_success_rate = $stat->success_rate;
            $hardest_questions = [$stat];
        } elseif ($stat->success_rate == $lowest_success_rate) {
            $hardest_questions[] = $stat;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Performance Dashboard</title>
    <style>
        /* LIGHT MODE (DEFAULT) */
        :root {
            --bg-base: #f4f7f6;
            --bg-card: #ffffff;
            --text-main: #2c3e50;
            --text-muted: #7f8c8d;
            --accent-primary: #3498db;
            --accent-glow: rgba(52, 152, 219, 0.08);
            --color-success: #27ae60;
            --color-danger: #e74c3c;
            --border-line: #e1e8ed;
            --hardest-bg: #fff5f5;
        }

        /* SOFT DARK MODE (SLATE/PASTEL) */
        [data-theme="dark"] {
            --bg-base: #1a1b26; /* Soft deep slate */
            --bg-card: #24283b; /* Slightly lighter slate for cards */
            --text-main: #c0caf5; /* Soft lavender-blue text */
            --text-muted: #565f89; /* Muted purple-slate */
            --accent-primary: #7aa2f7; /* Pastel blue */
            --accent-glow: rgba(122, 162, 247, 0.1);
            --color-success: #9ece6a; /* Pastel mint green */
            --color-danger: #f7768e; /* Soft coral red */
            --border-line: #414868;
            --hardest-bg: rgba(247, 118, 142, 0.05);
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-main);
            margin: 0;
            padding: 40px 20px;
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .theme-toggle {
            position: absolute;
            top: 20px;
            right: 20px;
            background: var(--bg-card);
            color: var(--text-main);
            border: 1px solid var(--border-line);
            padding: 10px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9em;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }

        .theme-toggle:hover {
            background: var(--accent-glow);
            border-color: var(--accent-primary);
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            position: relative;
        }

        .dashboard-header {
            text-align: center;
            margin-bottom: 40px;
            margin-top: 10px;
        }

        h1 {
            color: var(--text-main);
            font-weight: 600;
            font-size: 2.2em;
            margin-bottom: 5px;
        }

        h2 {
            color: var(--text-main);
            font-size: 1.3em;
            font-weight: 600;
            border-left: 4px solid var(--accent-primary);
            padding-left: 14px;
            margin-top: 50px;
            margin-bottom: 20px;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-line);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            transition: all 0.3s ease;
        }

        .hardest-card {
            border: 1px solid rgba(247, 118, 142, 0.3);
            background: var(--hardest-bg);
        }

        .hardest-card h3 {
            margin-top: 0;
            color: var(--color-danger);
            font-size: 1.1em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background-color: var(--bg-card);
            border: 1px solid var(--border-line);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            transition: background-color 0.3s ease, border 0.3s ease;
        }

        th, td {
            padding: 18px 20px;
            text-align: left;
            border-bottom: 1px solid var(--border-line);
        }

        th {
            background-color: rgba(0, 0, 0, 0.02);
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        [data-theme="dark"] th {
            background-color: rgba(255, 255, 255, 0.02);
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: var(--accent-glow);
        }

        .text-center { text-align: center; }

        .stat-right { color: var(--color-success); font-weight: bold; font-size: 1.1em; }
        .stat-wrong { color: var(--color-danger); font-weight: bold; font-size: 1.1em; }

        .question-name {
            color: var(--text-main);
            font-weight: 600;
            display: block;
            margin-bottom: 5px;
            font-size: 1.05em;
        }

        .question-text {
            font-size: 0.95em;
            color: var(--text-muted);
            display: block;
        }

        .rate-badge {
            background: var(--accent-glow);
            color: var(--accent-primary);
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<button class="theme-toggle" id="themeBtn">🌙 Dark Mode</button>

<div class="container">
    <div class="dashboard-header">
        <h1>Quiz Performance Dashboard</h1>
        <div style="color: var(--text-muted);">Moodle Analytics Database</div>
    </div>

    <?php if (!$question_stats): ?>
        <div style="padding: 20px; text-align: center; border: 1px solid var(--color-danger); border-radius: 8px; color: var(--color-danger);">
            No data found for this quiz ID.
        </div>
    <?php else: ?>

        <!-- HARDEST QUESTION WIDGET -->
        <h2>Attention Required</h2>
        <div class="card hardest-card">
            <h3>Most Difficult Question</h3>
            <?php foreach ($hardest_questions as $hq): ?>
                <p>
                    <span class="question-name"><?php echo htmlspecialchars($hq->questionname); ?></span>
                    <span class="question-text"><?php echo strip_tags($hq->questiontext); ?></span>
                </p>
            <?php endforeach; ?>
            <p style="margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border-line);">
                <span style="color: var(--text-muted); font-size: 0.9em;">Success Rate:</span>
                <span style="color: var(--color-danger); font-weight: bold; font-size: 1.3em; margin-left: 10px;">
                    <?php echo round($lowest_success_rate, 2); ?>%
                </span>
            </p>
        </div>

        <!-- STATISTICS TABLE -->
        <h2>Question Breakdown</h2>
        <table>
            <thead>
                <tr>
                    <th>Question</th>
                    <th class="text-center">Right</th>
                    <th class="text-center">Wrong</th>
                    <th class="text-center">Success Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($question_stats as $stat): ?>
                    <tr>
                        <td>
                            <span class="question-name"><?php echo htmlspecialchars($stat->questionname); ?></span>
                            <span class="question-text"><?php echo strip_tags($stat->questiontext); ?></span>
                        </td>
                        <td class="text-center stat-right"><?php echo $stat->correct_count; ?></td>
                        <td class="text-center stat-wrong"><?php echo $stat->wrong_count; ?></td>
                        <td class="text-center">
                            <span class="rate-badge"><?php echo round($stat->success_rate, 2); ?>%</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- INDIVIDUAL SCORES -->
        <h2>Individual Student Scores</h2>
        <table>
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th class="text-center">Attempt</th>
                    <th class="text-center">Total Score</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($scores): ?>
                    <?php foreach ($scores as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student->firstname . ' ' . $student->lastname); ?></td>
                            <td class="text-center" style="color: var(--text-muted);"><?php echo $student->attempt; ?></td>
                            <td class="text-center" style="font-weight: 600;"><?php echo round($student->sumgrades, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" class="text-center" style="color: var(--text-muted);">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<script>
    const themeBtn = document.getElementById('themeBtn');

    const savedTheme = localStorage.getItem('dashboardTheme');
    if (savedTheme === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        themeBtn.innerText = '☀️ Light Mode';
    }

    themeBtn.addEventListener('click', () => {
        let currentTheme = document.documentElement.getAttribute('data-theme');

        if (currentTheme === 'dark') {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('dashboardTheme', 'light');
            themeBtn.innerText = '🌙 Dark Mode';
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('dashboardTheme', 'dark');
            themeBtn.innerText = '☀️ Light Mode';
        }
    });
</script>

</body>
</html>
