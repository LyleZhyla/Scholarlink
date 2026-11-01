<?php
require '../vendor/autoload.php';
require '../includes/db_connect.php';
session_start();

use Dompdf\Dompdf;
use Dompdf\Options;

// 🔒 Security Check
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Internal_Admin') {
    exit("Unauthorized access.");
}

// --- 1. CAPTURE DYNAMIC FILTERS ---
$search         = $_GET['search'] ?? '';
$scholarship_id = $_GET['scholarship_id'] ?? '';
$course         = $_GET['course'] ?? '';

// Build dynamic subtitle for the PDF header to show what filters are active
$active_filters = [];
if (!empty($search)) $active_filters[] = "Search: '" . htmlspecialchars($search) . "'";
if (!empty($course)) $active_filters[] = "Program: " . htmlspecialchars($course);
if (!empty($scholarship_id)) $active_filters[] = "Specific Scholarship Filter Applied";

$subtitle = empty($active_filters) ? "Complete Roster of Approved Scholars" : "Filters: " . implode(' | ', $active_filters);

// --- 2. CONFIGURE DOMPDF ---
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);

try {
    // --- 3. BUILD THE DYNAMIC SQL QUERY (Matches scholars.php logic) ---
    $query = "
        SELECT 
            u.StudentID_Num, 
            u.FirstName, 
            u.LastName, 
            u.Major, 
            sch.Name AS ScholarshipName, 
            sch.AwardAmount
        FROM application a
        JOIN users u ON a.UserID = u.UserID
        JOIN scholarship sch ON a.ScholarshipID = sch.ScholarshipID
        WHERE a.Status = 'Approved'
    ";
    
    $params = [];

    if (!empty($search)) {
        $query .= " AND (u.FirstName LIKE :search OR u.LastName LIKE :search OR u.StudentID_Num LIKE :search)";
        $params['search'] = "%$search%";
    }
    if (!empty($scholarship_id)) {
        $query .= " AND a.ScholarshipID = :sid";
        $params['sid'] = $scholarship_id;
    }
    if (!empty($course)) {
        $query .= " AND u.Major = :course";
        $params['course'] = $course;
    }

    $query .= " ORDER BY u.LastName ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $scholars = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// --- 4. GENERATE THE HTML CONTENT ---
$html = '
<style>
    body { 
        font-family: "DejaVu Sans", sans-serif; 
        color: #333; 
        line-height: 1.4; 
    }
    .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0f172a; padding-bottom: 10px; }
    .title { font-size: 22px; font-weight: bold; color: #0f172a; margin: 0; text-transform: uppercase; }
    .university { font-size: 14px; font-weight: bold; color: #2563eb; margin: 5px 0; }
    .subtitle { font-size: 10px; color: #64748b; margin-top: 5px; font-style: italic; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th { background-color: #f8fafc; color: #0f172a; font-size: 9px; text-transform: uppercase; padding: 12px; border: 1px solid #cbd5e1; text-align: left; }
    td { padding: 10px 12px; border: 1px solid #cbd5e1; font-size: 10px; }
    .amount { text-align: right; font-weight: bold; color: #16a34a; }
    .footer { margin-top: 40px; text-align: right; font-size: 9px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; }
</style>

<div class="header">
    <h1 class="title">Active Scholars Roster</h1>
    <p class="university">Tarlac Agricultural University &bull; ScholarLink</p>
    <p class="subtitle">' . $subtitle . '</p>
    <p style="font-size:10px; color:#64748b; margin-top:8px;">Generated: ' . date('F d, Y') . '</p>
</div>

<table>
    <thead>
        <tr>
            <th>Student Number</th>
            <th>Full Name</th>
            <th>Program / Major</th>
            <th>Scholarship Grant</th>
            <th style="text-align:right;">Award Amount</th>
        </tr>
    </thead>
    <tbody>';

if (empty($scholars)) {
    $html .= '<tr><td colspan="5" style="text-align:center; padding: 30px; color: #64748b;">No active scholars found matching the selected filters.</td></tr>';
} else {
    foreach ($scholars as $s) {
        $html .= '
            <tr>
                <td>' . htmlspecialchars($s['StudentID_Num']) . '</td>
                <td>' . htmlspecialchars($s['FirstName'] . ' ' . $s['LastName']) . '</td>
                <td>' . htmlspecialchars($s['Major']) . '</td>
                <td>' . htmlspecialchars($s['ScholarshipName']) . '</td>
                <td class="amount">₱' . number_format($s['AwardAmount'], 2) . '</td>
            </tr>';
    }
}

$html .= '
    </tbody>
</table>

<div class="footer">
    This is an official university document generated via the Internal Admin Portal &bull; ' . date('Y-m-d H:i:s') . '
</div>';

// --- 5. RENDER AND STREAM ---
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Stream directly to browser
$dompdf->stream("ScholarLink_Active_Roster_" . date('Y-m-d') . ".pdf", ["Attachment" => false]);
exit();