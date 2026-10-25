<?php
session_start();
require 'includes/db_connect.php';

// Only site admins may edit homepage content
$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$allowed_roles = ['Internal_Admin', 'Super_Admin'];

if (!$is_logged_in || !in_array($_SESSION['role'] ?? '', $allowed_roles, true)) {
    header('Location: student_login.php');
    exit();
}

$role_redirect = $_SESSION['role'] === 'Super_Admin' ? 'super_admin/dashboard.php' : 'internal_admin/dashboard.php';

// Defaults mirror the fallbacks used on index.php
$defaults = [
    'hero' => [
        'label' => 'Hero Banner',
        'title' => 'Unlock your future with ScholarLink.',
        'body'  => 'Discover financial assistance programs, track your applications, and focus on your education. Browse every TAU grant below to get started.',
    ],
    'grants_header' => [
        'label' => 'Grants Section Header',
        'title' => 'Scholarships & Grants',
        'body'  => 'Every scholarship on ScholarLink — active, upcoming, and closed.',
    ],
    'no_grants' => [
        'label' => 'Empty State Message',
        'title' => 'No scholarships available',
        'body'  => 'Please check back later.',
    ],
];

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $sectionKey = $_POST['section_key'] ?? '';

    if (!array_key_exists($sectionKey, $defaults)) {
        $message = 'Unknown section.';
        $messageType = 'error';
    } elseif ($action === 'reset') {
        try {
            $stmt = $pdo->prepare("DELETE FROM landing_content WHERE section_key = :key");
            $stmt->execute([':key' => $sectionKey]);
            $message = 'Section reset to its default text.';
        } catch (PDOException $e) {
            $message = 'Could not reset this section. Please try again.';
            $messageType = 'error';
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');

        if ($title === '' || $body === '') {
            $message = 'Title and body cannot be empty.';
            $messageType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO landing_content (section_key, title, body)
                    VALUES (:section_key, :title, :body)
                    ON DUPLICATE KEY UPDATE title = :title2, body = :body2
                ");
                $stmt->execute([
                    ':section_key' => $sectionKey,
                    ':title' => $title,
                    ':body' => $body,
                    ':title2' => $title,
                    ':body2' => $body,
                ]);
                $message = 'Section updated.';
            } catch (PDOException $e) {
                $message = 'Could not save changes. Please try again.';
                $messageType = 'error';
            }
        }
    }
}

// Fetch current CMS content
$cms = [];
try {
    $stmt = $pdo->query("SELECT * FROM landing_content");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cms[$row['section_key']] = $row;
    }
} catch (PDOException $e) {}

function content_value($cms, $defaults, $key, $field) {
    return $cms[$key][$field] ?? $defaults[$key][$field];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Homepage Content - ScholarLink</title>
    <link rel="icon" type="image/png" href="assets/img/tau_logo.png">

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --ink: #123524;
            --muted: #5f7469;
            --line: #d7e8dd;
            --wash: #f3faf5;
            --paper: #ffffff;
            --green: #198754;
            --gold: #b7791f;
            --danger: #b8323b;
            --page-max: 980px;
            --page-gutter: clamp(20px, 4vw, 48px);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: var(--ink);
            background: var(--wash);
            font-family: 'Plus Jakarta Sans', Arial, sans-serif;
            line-height: 1.6;
        }

        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 7px 20px rgba(0, 0, 0, 0.06);
            backdrop-filter: blur(14px);
        }

        .nav-shell {
            width: min(1180px, calc(100% - (var(--page-gutter) * 2)));
            margin: 0 auto;
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }

        .brand img { width: 40px; height: 40px; object-fit: contain; }
        .brand strong { display: block; font-size: 1rem; color: var(--ink); }
        .brand span { display: block; color: var(--gold); font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; }

        .nav-links { display: flex; align-items: center; gap: 16px; font-size: 0.88rem; font-weight: 800; }

        .nav-links a.pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 8px;
            border: 1px solid var(--line);
            color: var(--ink);
            transition: all 0.2s ease;
        }

        .nav-links a.pill:hover { border-color: var(--green); color: var(--green); }

        main {
            width: min(var(--page-max), calc(100% - (var(--page-gutter) * 2)));
            margin: 0 auto;
            padding: 48px 0 80px;
        }

        .page-head { margin-bottom: 32px; }
        .page-head .kicker { color: var(--green); font-size: 0.78rem; font-weight: 900; letter-spacing: 0.08em; text-transform: uppercase; }
        .page-head h1 { margin: 8px 0 8px; font-size: clamp(1.7rem, 3vw, 2.2rem); letter-spacing: -0.01em; }
        .page-head p { margin: 0; color: var(--muted); font-weight: 500; }

        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 10px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        .alert.success { background: #eaf7ee; color: var(--green); border: 1px solid #bfe6cc; }
        .alert.error { background: #fdecec; color: var(--danger); border: 1px solid #f3c6c6; }

        .section-card {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: 0 10px 28px rgba(25, 135, 84, 0.06);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .section-card-head {
            padding: 18px 24px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .section-card-head h2 {
            margin: 0;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-card-head h2 i { color: var(--green); }

        .section-key-tag {
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--muted);
            background: var(--wash);
            padding: 4px 10px;
            border-radius: 999px;
        }

        .section-card-body { padding: 24px; }

        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 800;
            color: var(--ink);
            margin-bottom: 6px;
        }

        .field { margin-bottom: 18px; }

        input[type="text"], textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
            color: var(--ink);
            background: #fff;
            outline: none;
            transition: border-color 0.2s ease;
        }

        input[type="text"]:focus, textarea:focus { border-color: var(--green); }

        textarea { min-height: 96px; resize: vertical; }

        .field-hint { margin-top: 6px; font-size: 0.78rem; color: var(--muted); font-weight: 600; }

        .card-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 20px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 0.88rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-save { background: var(--green); color: #fff; box-shadow: 0 8px 18px rgba(25, 135, 84, 0.2); }
        .btn-save:hover { transform: translateY(-1px); box-shadow: 0 12px 22px rgba(25, 135, 84, 0.28); }

        .btn-reset { background: transparent; color: var(--muted); border: 1px solid var(--line); }
        .btn-reset:hover { border-color: var(--danger); color: var(--danger); }
    </style>
</head>
<body>
    <header class="site-header">
        <nav class="nav-shell">
            <a class="brand" href="index.php">
                <img src="assets/img/tau_logo.png" alt="TAU Logo">
                <span>
                    <strong>ScholarLink</strong>
                    <span>Homepage Content</span>
                </span>
            </a>
            <div class="nav-links">
                <a class="pill" href="index.php" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i> Preview Homepage</a>
                <a class="pill" href="<?= htmlspecialchars($role_redirect) ?>"><i class="fas fa-arrow-left"></i> Dashboard</a>
            </div>
        </nav>
    </header>

    <main>
        <div class="page-head">
            <span class="kicker">Content Manager</span>
            <h1>Edit homepage text</h1>
            <p>Update the hero banner, the grants section header, and the empty-state message shown on <a href="index.php" style="color: var(--green); text-decoration: underline;">index.php</a>. Changes appear immediately after saving.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert <?= $messageType === 'error' ? 'error' : 'success' ?>">
                <i class="fas <?= $messageType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php foreach ($defaults as $key => $default): ?>
            <div class="section-card">
                <div class="section-card-head">
                    <h2><i class="fas fa-pen-to-square"></i> <?= htmlspecialchars($default['label']) ?></h2>
                    <span class="section-key-tag"><?= htmlspecialchars($key) ?></span>
                </div>
                <div class="section-card-body">
                    <form method="POST">
                        <input type="hidden" name="section_key" value="<?= htmlspecialchars($key) ?>">
                        <input type="hidden" name="action" value="save">

                        <div class="field">
                            <label for="title-<?= htmlspecialchars($key) ?>">Title</label>
                            <input type="text" id="title-<?= htmlspecialchars($key) ?>" name="title"
                                value="<?= htmlspecialchars(content_value($cms, $defaults, $key, 'title')) ?>" required>
                        </div>

                        <div class="field">
                            <label for="body-<?= htmlspecialchars($key) ?>">Body Text</label>
                            <textarea id="body-<?= htmlspecialchars($key) ?>" name="body" required><?= htmlspecialchars(content_value($cms, $defaults, $key, 'body')) ?></textarea>
                            <div class="field-hint">Default: "<?= htmlspecialchars($default['body']) ?>"</div>
                        </div>

                        <div class="card-actions">
                            <button type="submit" class="btn btn-save"><i class="fas fa-save"></i> Save Changes</button>
                            <button type="submit" name="action" value="reset" class="btn btn-reset"
                                onclick="return confirm('Reset this section back to its default text?');">
                                <i class="fas fa-rotate-left"></i> Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </main>
</body>
</html>