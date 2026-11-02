<?php
session_start();
require '../includes/db_connect.php';
require_once '../includes/benefit_releases.php';
require_once '../includes/application_authority.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) {
    header('Location: ../admin_login.php'); exit();
}
$role = $_SESSION['role']; $admin_id = (int) $_SESSION['user_id'];
$application_id = (int) ($_POST['application_id'] ?? 0);
$action = $_POST['action'] ?? '';
$back = match ($role) { 'Internal_Admin' => '../internal_admin/benefits.php', 'External_Admin' => '../external_admin/benefits.php', default => '../super_admin/benefits.php' };
$saved_file = null;

try {
    $authority = getApplicationAuthority($pdo, $application_id);
    if (!$authority || !canDecideApplication($authority, $role, $admin_id)) throw new RuntimeException($authority ? "Only {$authority['DecidedBy']} can record benefits for this scholarship." : 'Application not found.');
    $pdo->beginTransaction();
    $application = ensureBenefitAccount($pdo, $application_id, $admin_id);
    if ($application['Status'] !== 'Approved') throw new RuntimeException('New benefit releases may only be recorded for an approved scholar.');
    $account = benefitAccountSummary($pdo, $application_id);
    if (!$account) throw new RuntimeException('Benefit account could not be initialized.');

    if ($action === 'save_entitlement') {
        $total = round((float) ($_POST['total_entitlement'] ?? 0), 2);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($total <= 0) throw new RuntimeException('Total entitlement must be greater than zero.');
        if ($total < (float) $account['ReleasedAmount']) throw new RuntimeException('Total entitlement cannot be lower than the amount already released.');
        $pdo->prepare("UPDATE scholar_benefit_accounts SET TotalEntitlement = ?, Notes = ?, SetBy = ? WHERE BenefitAccountID = ?")
            ->execute([$total, $notes !== '' ? $notes : null, $admin_id, $account['BenefitAccountID']]);
        logAudit($pdo, $admin_id, 'Benefit Entitlement Updated', "Set total entitlement for Application #$application_id to PHP " . number_format($total, 2, '.', ''));
        $_SESSION['success'] = 'Benefit entitlement and remaining balance were updated.';
    } elseif ($action === 'add_release') {
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $period = trim((string) ($_POST['release_period'] ?? ''));
        $payment_date = $_POST['payment_date'] ?? '';
        $mode = trim((string) ($_POST['release_mode'] ?? ''));
        $reference = trim((string) ($_POST['reference_number'] ?? ''));
        $allowed_modes = ['Cash', 'Bank Transfer', 'Check', 'E-Wallet', 'Other'];
        if ($amount <= 0) throw new RuntimeException('Release amount must be greater than zero.');
        if ($amount > (float) $account['RemainingBalance']) throw new RuntimeException('Release amount exceeds the remaining unpaid balance.');
        if ($period === '' || mb_strlen($period) > 100) throw new RuntimeException('Enter a valid release period.');
        $date = DateTime::createFromFormat('!Y-m-d', $payment_date, new DateTimeZone('Asia/Manila'));
        $today = new DateTime('today', new DateTimeZone('Asia/Manila'));
        if (!$date || $date->format('Y-m-d') !== $payment_date || $date > $today) throw new RuntimeException('Payment date must be a valid date that is not in the future.');
        if (!in_array($mode, $allowed_modes, true)) throw new RuntimeException('Select a valid mode of release.');
        $file = $_FILES['acknowledgement_receipt'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload the acknowledgement receipt.');
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Acknowledgement receipt must be 5 MB or smaller.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
        if (!isset($allowed[$mime])) throw new RuntimeException('Acknowledgement receipt must be a PDF, JPG, or PNG file.');

        $pdo->prepare("INSERT INTO benefit_releases (BenefitAccountID, ApplicationID, Amount, ReleasePeriod, PaymentDate, ReleaseMode, ReferenceNumber, AcknowledgementReceipt, OriginalReceiptName, RecordedBy) VALUES (?, ?, ?, ?, ?, ?, ?, '', ?, ?)")
            ->execute([$account['BenefitAccountID'], $application_id, $amount, $period, $payment_date, $mode, $reference !== '' ? $reference : null, basename((string) $file['name']), $admin_id]);
        $release_id = (int) $pdo->lastInsertId();
        $student = $pdo->prepare("SELECT a.UserID, s.Name FROM application a JOIN scholarship s ON s.ScholarshipID=a.ScholarshipID WHERE a.ApplicationID=?");
        $student->execute([$application_id]); $info = $student->fetch(PDO::FETCH_ASSOC);
        $dir = dirname(__DIR__) . '/uploads/benefit_receipts/'; if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = "STU-{$info['UserID']}-BENEFIT-$release_id-" . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        $saved_file = $dir . $name;
        if (!move_uploaded_file($file['tmp_name'], $saved_file)) throw new RuntimeException('Acknowledgement receipt could not be saved.');
        $stored = '../uploads/benefit_receipts/' . $name;
        $pdo->prepare("UPDATE benefit_releases SET AcknowledgementReceipt=? WHERE BenefitReleaseID=?")->execute([$stored, $release_id]);
        $remaining = (float) $account['RemainingBalance'] - $amount;
        $pdo->prepare("INSERT INTO notifications (UserID, Title, Message, Type) VALUES (?, 'Scholarship Benefit Released', ?, 'success')")
            ->execute([$info['UserID'], "A benefit of PHP " . number_format($amount, 2) . " for $period was recorded for {$info['Name']}. Remaining unpaid balance: PHP " . number_format($remaining, 2) . '.']);
        logAudit($pdo, $admin_id, 'Scholarship Benefit Released', "Recorded PHP " . number_format($amount, 2, '.', '') . " for Application #$application_id ($period, $mode)");
        $_SESSION['success'] = 'Benefit release recorded and the remaining balance was recalculated.';
    } else throw new RuntimeException('Invalid benefit release action.');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($saved_file && is_file($saved_file)) @unlink($saved_file);
    $_SESSION['error'] = $e->getMessage();
}
header("Location: $back"); exit();
