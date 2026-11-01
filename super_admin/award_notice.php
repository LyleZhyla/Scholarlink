<?php
session_start();
include '../includes/session_manager.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') {
    header("Location: ../admin_login.php");
    exit();
}

include '../includes/db_connect.php';
require_once '../includes/application_authority.php';

// ✨ APPROVAL NOTICE: only the scholarship's owner sets what approved scholars must still submit
$authority = getScholarshipAuthority($pdo, (int) ($_GET['id'] ?? 0));
if (!$authority || !canDecideApplication($authority, $_SESSION['role'], $_SESSION['user_id'])) {
    $_SESSION['error'] = $authority
        ? "Only {$authority['DecidedBy']} can set the approval requirements of {$authority['ScholarshipName']}."
        : "Scholarship not found.";
    header("Location: scholarships.php");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM scholarship WHERE ScholarshipID = ?");
$stmt->execute([$authority['ScholarshipID']]);
$scholarship = $stmt->fetch(PDO::FETCH_ASSOC);
$back_url = 'scholarships.php';

include '../includes/header.php';
include '../includes/super_sidebar.php';
include '../includes/award_notice_view.php';
include '../includes/footer.php';
