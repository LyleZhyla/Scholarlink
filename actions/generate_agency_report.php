<?php
session_start();

// 1. SESSION MANAGER & SECURITY
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'Super_Admin'])) { 
    die("Unauthorized access."); 
}

require '../includes/db_connect.php';

// 2. CAPTURE PARAMETERS
$report_type = $_GET['report_type'] ?? 'scholarships_available';
$period = $_GET['period'] ?? 'all';
$format = $_GET['format'] ?? 'pdf';

// 3. TITLE & DESCRIPTION MAPPING
$report_titles = [
    'scholars_per_scholarship' => 'Scholars per Scholarship Report',
    'scholarships_available' => 'Scholarships Available Report',
    'scholarship_applicants' => 'Scholarship Applicants / Application Report'
];
$title = $report_titles[$report_type] ?? 'OSSD Scholarship Report';
$period_label = ucfirst($period) . ' Period';
if ($period == 'all') $period_label = 'Overall (All Time)';

// 4. FETCH DATA BASED ON REPORT TYPE
$data = [];
$headers = [];

try {
    if ($report_type === 'scholars_per_scholarship') {
        $headers = ['Scholarship Name', 'Sponsor', 'Total Active Scholars', 'Total Grant Value Allocated (PHP)'];
        $stmt = $pdo->query("
            SELECT s.Name, 
                   IFNULL(u.Organization, 'TAU') as Sponsor,
                   COUNT(a.ApplicationID) as TotalScholars,
                   SUM(s.AwardAmount) as TotalAllocated
            FROM scholarship s
            LEFT JOIN application a ON s.ScholarshipID = a.ScholarshipID AND a.Status = 'Approved'
            LEFT JOIN users u ON s.CreatedBy = u.UserID
            GROUP BY s.ScholarshipID
            ORDER BY TotalScholars DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data[] = [
                $row['Name'], 
                $row['Sponsor'], 
                $row['TotalScholars'], 
                number_format((float)$row['TotalAllocated'], 2)
            ];
        }

    } elseif ($report_type === 'scholarships_available') {
        $headers = ['Scholarship Name', 'Status', 'Minimum GWA', 'Award Amount (PHP)', 'Deadline'];
        $stmt = $pdo->query("
            SELECT Name, Status, MinimumGWA, AwardAmount, Deadline 
            FROM scholarship 
            ORDER BY Status ASC, Deadline ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data[] = [
                $row['Name'], 
                $row['Status'], 
                $row['MinimumGWA'], 
                number_format((float)$row['AwardAmount'], 2), 
                $row['Deadline'] ? date('M d, Y', strtotime($row['Deadline'])) : 'No Deadline'
            ];
        }

    } elseif ($report_type === 'scholarship_applicants') {
        $headers = ['Scholarship Name', 'Total Applicants', 'Pending/Draft', 'Shortlisted', 'Approved (Scholars)', 'Rejected'];
        $stmt = $pdo->query("
            SELECT s.Name,
                   COUNT(a.ApplicationID) as TotalApps,
                   SUM(CASE WHEN a.Status IN ('Pending', 'Draft') THEN 1 ELSE 0 END) as PendingApps,
                   SUM(CASE WHEN a.Status = 'Shortlisted' THEN 1 ELSE 0 END) as ShortlistedApps,
                   SUM(CASE WHEN a.Status = 'Approved' THEN 1 ELSE 0 END) as ApprovedApps,
                   SUM(CASE WHEN a.Status = 'Rejected' THEN 1 ELSE 0 END) as RejectedApps
            FROM scholarship s
            LEFT JOIN application a ON s.ScholarshipID = a.ScholarshipID
            GROUP BY s.ScholarshipID
            ORDER BY TotalApps DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data[] = [
                $row['Name'], 
                $row['TotalApps'], 
                $row['PendingApps'], 
                $row['ShortlistedApps'], 
                $row['ApprovedApps'], 
                $row['RejectedApps']
            ];
        }
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// ==========================================
// 5. EXPORT AS CSV
// ==========================================
if ($format === 'csv') {
    $filename = $report_type . "_" . date('Ymd') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');
    
    // Add Report Meta
    fputcsv($output, ['TARLAC AGRICULTURAL UNIVERSITY']);
    fputcsv($output, ['Office of Student Affairs and Services - OSSD']);
    fputcsv($output, [$title]);
    fputcsv($output, ['Period: ' . $period_label]);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []); // Empty line

    fputcsv($output, $headers);
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// ==========================================
// 6. EXPORT AS PDF (Printable HTML Format)
// ==========================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - PDF</title>
    <style>
        body { font-family: 'Arial', sans-serif; color: #333; margin: 0; padding: 40px; background: #525659; }
        .document-container { max-width: 800px; margin: 0 auto; background: #fff; padding: 50px; box-shadow: 0 0 10px rgba(0,0,0,0.5); }
        .header { text-align: center; border-bottom: 2px solid #198754; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { margin: 0; font-size: 20px; color: #123524; text-transform: uppercase; }
        .header h2 { margin: 5px 0 0; font-size: 14px; color: #5f7469; font-weight: normal; }
        .report-title { text-align: center; margin-bottom: 30px; }
        .report-title h3 { margin: 0 0 5px; font-size: 18px; color: #198754; text-transform: uppercase; }
        .report-title p { margin: 0; font-size: 12px; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 12px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f3faf5; color: #123524; font-weight: bold; }
        .footer { text-align: center; font-size: 10px; color: #999; margin-top: 50px; border-top: 1px solid #eee; padding-top: 20px; }
        
        /* Automatically trigger print and hide background in print mode */
        @media print {
            body { background: #fff; padding: 0; }
            .document-container { box-shadow: none; padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="document-container">
        <div class="header">
            <h1>Tarlac Agricultural University</h1>
            <h2>Office of Student Affairs and Services - OSSD</h2>
        </div>

        <div class="report-title">
            <h3><?= htmlspecialchars($title) ?></h3>
            <p>Reporting Period: <strong><?= htmlspecialchars($period_label) ?></strong> | Generated: <?= date('F j, Y, g:i A') ?></p>
        </div>

        <?php if (empty($data)): ?>
            <p style="text-align: center; font-style: italic; color: #999;">No data available for the selected period.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <?php foreach ($headers as $th): ?>
                            <th><?= htmlspecialchars($th) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <?php foreach ($row as $td): ?>
                                <td><?= htmlspecialchars($td) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="footer">
            <p>This is a system-generated report from ScholarLink. Do not reply.</p>
            <p>&copy; <?= date('Y') ?> Tarlac Agricultural University.</p>
        </div>
    </div>

</body>
</html>