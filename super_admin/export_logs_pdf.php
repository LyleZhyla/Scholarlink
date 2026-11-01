<?php
session_start();

// 🔒 SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') {
    die("Unauthorized access.");
}

// 🔐 AUDIT LOG GATE (password re-check before exporting)
require_once '../includes/audit_gate.php';
requireAuditUnlock();

include '../includes/db_connect.php'; 

// --- REBUILD FILTER PARAMETERS (the same filters as the Audit Logs screen) ---
$filter_role       = isset(AUDIT_ROLE_LABELS[$_GET['role'] ?? '']) ? $_GET['role'] : '';
$filter_user_name  = trim((string) ($_GET['user'] ?? ''));     // part of the name, as typed on screen
$filter_action_txt = trim((string) ($_GET['action'] ?? ''));   // part of the action, as typed on screen
$filter_user       = $_GET['user_id'] ?? '';
$filter_action     = $_GET['action_type'] ?? '';
$filter_month      = $_GET['month'] ?? '';
$filter_date       = $_GET['date'] ?? '';
$filter_time_start = $_GET['time_start'] ?? '';
$filter_time_end   = $_GET['time_end'] ?? '';

// Base Query (NO LIMIT/OFFSET para makuha ang lahat ng records sa report)
$query = "SELECT al.AuditID, al.ActionDate, al.ActionPerformed, al.Description, al.IPAddress, 
                 u.FirstName, u.LastName, u.Role
          FROM audit_log al
          LEFT JOIN users u ON al.UserID = u.UserID
          WHERE 1=1 ";

$params = [];

// Apply Filters
if ($filter_role === 'System') {
    $query .= " AND u.UserID IS NULL";
} elseif ($filter_role !== '') {
    $query .= " AND u.Role = :role";
    $params['role'] = $filter_role;
}
if ($filter_user_name !== '') {
    // "System / Unknown" is how the screen names entries with no user
    if (stripos('system / unknown', $filter_user_name) !== false) {
        $query .= " AND (u.UserID IS NULL OR CONCAT(u.FirstName, ' ', u.LastName) LIKE :user_name)";
    } else {
        $query .= " AND CONCAT(u.FirstName, ' ', u.LastName) LIKE :user_name";
    }
    $params['user_name'] = '%' . $filter_user_name . '%';
}
if ($filter_action_txt !== '') {
    $query .= " AND al.ActionPerformed LIKE :action_txt";
    $params['action_txt'] = '%' . $filter_action_txt . '%';
}
if (!empty($filter_user)) {
    $query .= " AND al.UserID = :user_id";
    $params['user_id'] = $filter_user;
}
if (!empty($filter_action)) {
    $query .= " AND al.ActionPerformed = :action_type";
    $params['action_type'] = $filter_action;
}
if (!empty($filter_month)) {
    $query .= " AND DATE_FORMAT(al.ActionDate, '%Y-%m') = :month";
    $params['month'] = $filter_month;
}
if (!empty($filter_date)) {
    $query .= " AND DATE(al.ActionDate) = :date";
    $params['date'] = $filter_date;
}
// Start and end time work on their own too (the screen allows either)
if (!empty($filter_time_start)) {
    $query .= " AND TIME_FORMAT(al.ActionDate, '%H:%i') >= :time_start";
    $params['time_start'] = $filter_time_start;
}
if (!empty($filter_time_end)) {
    $query .= " AND TIME_FORMAT(al.ActionDate, '%H:%i') <= :time_end";
    $params['time_end'] = $filter_time_end;
}

// Shown on the report so it is clear what it covers
$filter_summary = array_filter([
    $filter_role !== '' ? 'User type: ' . AUDIT_ROLE_LABELS[$filter_role] : '',
    $filter_user_name !== '' ? 'User: "' . $filter_user_name . '"' : '',
    $filter_action_txt !== '' ? 'Action: "' . $filter_action_txt . '"' : '',
    $filter_month !== '' ? 'Month: ' . $filter_month : '',
    $filter_date !== '' ? 'Date: ' . $filter_date : '',
    $filter_time_start !== '' ? 'From ' . $filter_time_start : '',
    $filter_time_end !== '' ? 'Until ' . $filter_time_end : '',
]);

$query .= " ORDER BY al.ActionDate DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log Report - ScholarLink</title>
    <style>
        body { font-family: 'Arial', sans-serif; margin: 0; padding: 20px; color: #333; }
        .report-header { text-align: center; border-bottom: 2px solid #1e293b; padding-bottom: 20px; margin-bottom: 30px; }
        .report-header h1 { margin: 0; font-size: 24px; color: #0f172a; text-transform: uppercase; letter-spacing: 2px; }
        .report-header p { margin: 5px 0 0 0; font-size: 14px; color: #64748b; }
        .meta-data { margin-bottom: 20px; font-size: 12px; display: flex; justify-content: space-between; color: #475569; }
        table { w-full; width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background-color: #f1f5f9; padding: 12px; text-align: left; font-weight: bold; border-bottom: 2px solid #cbd5e1; text-transform: uppercase; letter-spacing: 1px; }
        td { padding: 12px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .action-badge { font-weight: bold; color: #0f172a; }
        .timestamp { white-space: nowrap; color: #64748b; }
        .footer { margin-top: 40px; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; }
        
        /* Ensures the print preview hides unnecessary elements and looks like a PDF */
        @media print {
            body { padding: 0; }
            @page { margin: 1cm; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="text-align: right; margin-bottom: 20px;">
        <button onclick="window.print()" style="background: #0f172a; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold;">
            Save as PDF / Print
        </button>
    </div>

    <div class="report-header">
        <h1>System Audit Log Report</h1>
        <p>Tarlac Agricultural University - ScholarLink Management System</p>
    </div>

    <div class="meta-data">
        <div><strong>Generated By:</strong> Super Admin (<?= htmlspecialchars($_SESSION['first_name'] ?? 'Admin') ?>)</div>
        <div><strong>Date Generated:</strong> <?= date('F j, Y, g:i a') ?></div>
        <div><strong>Total Records:</strong> <?= count($logs) ?></div>
        <div><strong>Filters:</strong> <?= $filter_summary ? htmlspecialchars(implode(' · ', $filter_summary)) : 'None (all activity)' ?></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>User / Actor</th>
                <th>Action Performed</th>
                <th>Details</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($logs)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 40px;">No logs found for the selected filters.</td>
                </tr>
            <?php else: ?>
                <?php foreach($logs as $log): ?>
                    <tr>
                        <td class="timestamp">
                            <strong><?= date('M d, Y', strtotime($log['ActionDate'])) ?></strong><br>
                            <?= date('h:i:s A', strtotime($log['ActionDate'])) ?>
                        </td>
                        <td>
                            <?php if($log['FirstName']): ?>
                                <strong><?= htmlspecialchars($log['FirstName'].' '.$log['LastName']) ?></strong><br>
                                <span style="color: #64748b; font-size: 10px;"><?= htmlspecialchars(AUDIT_ROLE_LABELS[$log['Role'] ?? ''] ?? 'System / Not signed in') ?></span>
                            <?php else: ?>
                                <span style="color: #94a3b8; font-style: italic;">System / Unregistered</span>
                            <?php endif; ?>
                        </td>
                        <td class="action-badge"><?= htmlspecialchars($log['ActionPerformed']) ?></td>
                        <td><?= htmlspecialchars($log['Description']) ?></td>
                        <td style="color: #64748b; font-family: monospace;"><?= htmlspecialchars($log['IPAddress']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">
        CONFIDENTIAL REPORT • Generated by ScholarLink Database Engine • <?= date('Y') ?>
    </div>

    <script>
        // Awtomatikong pinapa-pop up ang Print/Save as PDF dialog pagka-load ng page!
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>