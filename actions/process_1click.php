<?php
session_start();
// ✨ ILALABAS NATIN ANG LAHAT NG PHP ERRORS SA SCREEN
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../includes/db_connect.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    die("ERROR: Hindi ka naka-login bilang estudyante o nag-expire ang session.");
}

$user_id = $_SESSION['user_id'];
$scholarship_id = isset($_POST['scholarship_id']) ? (int)$_POST['scholarship_id'] : 0;

echo "<h3>DEBUG MODE: Sinusubukang mag-apply para sa Scholarship ID: $scholarship_id ng User ID: $user_id</h3>";

if (!$scholarship_id) {
    die("ERROR: Walang natanggap na Scholarship ID!");
}

try {
    // 1. I-check kung may active db connection
    if (!$pdo) {
        die("ERROR: Walang koneksyon sa Database!");
    }

    // 2. I-insert ang application
    $sql = "INSERT INTO application (UserID, ScholarshipID, Status, DateSubmitted, GPA) VALUES (?, ?, 'Submitted', NOW(), 1.00)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $scholarship_id]);
    $new_app_id = $pdo->lastInsertId();
    echo "<p>✅ Natagumpayan ang paggawa ng Application ID: $new_app_id</p>";

    // 3. Kunin ang requirements
    $req_stmt = $pdo->prepare("SELECT RequirementID, DocumentName FROM document_requirement WHERE ScholarshipID = ?");
    $req_stmt->execute([$scholarship_id]);
    $requirements = $req_stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>📋 Bilang ng requirements para sa scholarship na ito: " . count($requirements) . "</p>";

    // 4. Kunin ang vault ng estudyante
    $vault_stmt = $pdo->prepare("SELECT DocumentType, FilePath FROM user_vault WHERE UserID = ?");
    $vault_stmt->execute([$user_id]);
    $vault_docs = $vault_stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>📂 Bilang ng files sa vault ng estudyante: " . count($vault_docs) . "</p>";

    // 5. Pwersahang i-insert sa submitted_document kahit ano ang laman ng vault
    $doc_insert_stmt = $pdo->prepare("INSERT INTO submitted_document (ApplicationID, RequirementID, FilePath, VerificationStatus) VALUES (?, ?, ?, 'Pending')");

    if (count($requirements) > 0) {
        foreach ($requirements as $req) {
            $filePath = !empty($vault_docs) ? $vault_docs[0]['FilePath'] : '../uploads/default.pdf';
            $doc_insert_stmt->execute([$new_app_id, $req['RequirementID'], $filePath]);
            echo "<p style='color:green;'>✔️ Nai-save ang requirement: " . htmlspecialchars($req['DocumentName']) . "</p>";
        }
    } else {
        echo "<p style='color:orange;'>⚠️ Walang requirements ang scholarship na ito, kaya walang dokumentong isinumite.</p>";
    }

    echo "<h2 style='color:blue;'>🎉 TAPOS NA ANG DEBUG! I-check na ang phpMyAdmin at ang submitted_document table.</h2>";
    echo "<br><a href='../student/applications.php'>Pumunta sa Applications Page</a>";
    exit();

} catch (Exception $e) {
    echo "<h2 style='color:red;'>🚨 MAY LUMITAW NA FATAL ERROR:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    exit();
}
?>