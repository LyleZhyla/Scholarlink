<?php
require '../vendor/autoload.php';
require '../includes/db_connect.php';
session_start();

use Dompdf\Dompdf;
use Dompdf\Options;

// Security Check
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Internal_Admin') {
    exit("Unauthorized access.");
}

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);

try {
    // Fetch Shortlisted candidates ordered by Highest Score first
    $stmt = $pdo->query("
        SELECT a.ApplicationID, a.TotalScore, a.GPA, 
               u.StudentID_Num, u.FirstName, u.LastName, u.Major, 
               sch.Name AS ScholarshipName, sch.AwardAmount
        FROM application a
        JOIN users u ON a.UserID = u.UserID
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        WHERE a.Status = 'Shortlisted'
        ORDER BY a.TotalScore DESC, a.GPA ASC
    ");
    $shortlisted = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$html = '
<style>
    body { font-family: "Helvetica", sans-serif; color: #333; }
    .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th { background-color: #f8fafc; color: #0f172a; font-size: 11px; text-transform: uppercase; padding: 12px; border: 1px solid #cbd5e1; text-align: left; }
    td { padding: 12px; border: 1px solid #cbd5e1; font-size: 11px; }
    .score-high { color: #16a34a; font-weight: bold; font-size: 14px; }
    .footer { margin-top: 30px; text-align: right; font-size: 9px; color: #94a3b8; }
</style>

<div class="header">
    <h1 style="margin:0; color: #0f172a;">Official Shortlist Report</h1>
    <p style="margin:5px 0; color:#2563eb; font-weight:bold;">Tarlac Agricultural University - ScholarLink</p>
    <p style="font-size:12px; color:#64748b; margin-top:10px;">Candidates awaiting final university approval.</p>
    <p style="font-size:11px; color:#64748b;">Generated on: ' . date('F d, Y') . '</p>
</div>

<table>
    <thead>
        <tr>
            <th>Student Info</th>
            <th>Program</th>
            <th>Scholarship</th>
            <th style="text-align:center;">Evaluator Score</th>
        </tr>
    </thead>
    <tbody>';

if (empty($shortlisted)) {
    $html .= '<tr><td colspan="4" style="text-align:center; padding: 20px;">No candidates are currently shortlisted.</td></tr>';
} else {
    foreach ($shortlisted as $app) {
        $html .= '
            <tr>
                <td>
                    <strong>' . htmlspecialchars($app['FirstName'] . ' ' . $app['LastName']) . '</strong><br>
                    <span style="color: #64748b; font-size: 10px;">ID: ' . htmlspecialchars($app['StudentID_Num']) . '</span>
                </td>
                <td>' . htmlspecialchars($app['Major']) . '</td>
                <td>
                    <strong>' . htmlspecialchars($app['ScholarshipName']) . '</strong><br>
                    <span style="color: #64748b; font-size: 10px;">Award: ₱' . number_format($app['AwardAmount'], 2) . '</span>
                </td>
                <td style="text-align:center;">
                    <span class="score-high">' . $app['TotalScore'] . '</span> / 100
                </td>
            </tr>';
    }
}

$html .= '
    </tbody>
</table>
<div class="footer">
    Internal Admin Portal &bull; ' . date('Y-m-d H:i:s') . '
</div>';

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Stream the PDF to the browser
$dompdf->stream("ScholarLink_Shortlist_" . date('Y-m-d') . ".pdf", ["Attachment" => false]); 
exit();
?>