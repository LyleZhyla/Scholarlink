<?php
require 'includes/db_connect.php';

try {
    // 1. SAFELY WIPE EXISTING DUMMY SCHOLARSHIPS AND REQUIREMENTS
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE document_requirement;");
    $pdo->exec("TRUNCATE TABLE scholarship_criteria;");
    $pdo->exec("TRUNCATE TABLE application_custom_answers;");
    $pdo->exec("TRUNCATE TABLE submitted_document;");
    $pdo->exec("TRUNCATE TABLE score;");
    $pdo->exec("TRUNCATE TABLE application;");
    $pdo->exec("TRUNCATE TABLE scholarship;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // 2. THE OFFICIAL TAU SCHOLARSHIPS & BENEFITS (From OSSD List)
    $tau_scholarships = [
        // ==========================================
        // I. INSTITUTIONAL SCHOLARSHIPS
        // ==========================================
        [
            'Name' => 'Academic Scholars - Full',
            'Type' => 'Government',
            'Desc' => 'GWA of 1.20-1.00 of an academic load for regular students with no dropped, conditional, or failed grades in any subjects.',
            'Amount' => 5000.00,
            'Year' => NULL,
            'GWA' => 1.20,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades']
        ],
        [
            'Name' => 'Academic Scholars - Partial',
            'Type' => 'Government',
            'Desc' => 'GWA of 1.75-1.21. Must be regularly enrolled with a minimum of 12 units and no dropped, conditional, or failed grades.',
            'Amount' => 1500.00,
            'Year' => NULL,
            'GWA' => 1.75,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades']
        ],
        [
            'Name' => 'Athletes Regular Training Allowance',
            'Type' => 'Government',
            'Desc' => 'Subject to monitoring, evaluation, and endorsement of the Director for Sports Development.',
            'Amount' => 5000.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Endorsement from Sports Development Director']
        ],
        [
            'Name' => 'Cultural Performers Allowance',
            'Type' => 'Government',
            'Desc' => 'Subject to monitoring, evaluation, and endorsement of the Socio-Cultural Development Director.',
            'Amount' => 5000.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Endorsement from Socio-Cultural Director']
        ],
        [
            'Name' => 'Golden Harvest Incentives',
            'Type' => 'Government',
            'Desc' => 'Incentives for the Official Student Publication editorial board. Editor-in-Chief receives P5,000; Associate gets P4,000; others P2,500.',
            'Amount' => 5000.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Endorsement from Publication Adviser']
        ],
        [
            'Name' => 'ROTC Cadet Allowance',
            'Type' => 'Government',
            'Desc' => 'Incentives for First Class (P5,000) and Second Class (P2,500) Cadets.',
            'Amount' => 5000.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'ROTC Commandant Endorsement']
        ],
        [
            'Name' => 'SSC/CSC Leadership Incentives',
            'Type' => 'Government',
            'Desc' => 'For students holding leadership positions indicated in the TAU Code 2021 (Elective or Appointive). Must have no dropped or failed grades.',
            'Amount' => 5000.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Endorsement from Adviser']
        ],
        [
            'Name' => 'Tulong Dunong Program',
            'Type' => 'Government',
            'Desc' => 'One-time SUC program for low-income earning families (net monthly income not exceeding Php12,000).',
            'Amount' => 7500.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Indigency']
        ],

        // ==========================================
        // II. GOVERNMENT-FUNDED SCHOLARSHIPS
        // ==========================================
        [
            'Name' => 'City of Tarlac Integrated Scholarship (Agriculture)',
            'Type' => 'Government',
            'Desc' => 'For indigent families enrolled in Agriculture/Agri-related courses. Must maintain a GWA of 2.00 or higher.',
            'Amount' => 3500.00,
            'Year' => NULL,
            'GWA' => 2.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Certificate of Indigency', 'Certificate of Good Moral Character']
        ],
        [
            'Name' => 'ACEF-GIAHEP Program',
            'Type' => 'Government',
            'Desc' => 'For agriculture, forestry, fisheries, and veterinary medicine students. Combined annual gross income must not exceed P400,000.',
            'Amount' => 12500.00, 
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'BIR Income Tax Return or Tax Exemption']
        ],
        [
            'Name' => 'DOST Scholarships (RA 7687 / MERIT)',
            'Type' => 'Government',
            'Desc' => 'For talented students in priority S&T courses. Must maintain a GWA of at least 83% (2.00).',
            'Amount' => 14000.00, 
            'Year' => NULL,
            'GWA' => 2.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Certificate of Good Moral Character']
        ],
        [
            'Name' => 'Tertiary Educational Subsidy (TES)',
            'Type' => 'Government',
            'Desc' => 'For low-income families with a net monthly income not exceeding Php12,000.00 as certified by the Barangay.',
            'Amount' => 20000.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Indigency']
        ],
        [
            'Name' => 'TES Tulong Dunong Program',
            'Type' => 'Government',
            'Desc' => 'For low-income families with a net monthly income not exceeding Php12,000.00 as certified by the Barangay.',
            'Amount' => 7500.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Indigency']
        ],
        [
            'Name' => 'Tulong Agri Program',
            'Type' => 'Government',
            'Desc' => 'For students in agriculture, fisheries, forestry, food tech, and veterinary medicine with a GWA of 75% or equivalent.',
            'Amount' => 13500.00, 
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Indigency']
        ],

        // ==========================================
        // III. PRIVATE-FUNDED SCHOLARSHIPS
        // ==========================================
        [
            'Name' => 'TAU Alumni and Friends Grant',
            'Type' => 'Private',
            'Desc' => 'For bonafide TAU students from low-income families with a net monthly income not exceeding Php12,000.00.',
            'Amount' => 4000.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Indigency']
        ],
        [
            'Name' => 'Lorna Fernando Dinos Scholarship Program',
            'Type' => 'Private',
            'Desc' => 'For 1st-year single students (max 25 yrs old) in Tarlac. HS Average of 85%. Income not exceeding P12k/month.',
            'Amount' => 9000.00,
            'Year' => '1st Year',
            'GWA' => 2.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Residency', 'DSWD Certificate of Indigency', 'Certificate of Good Moral Character', '2 Recommendation Letters']
        ],
        [
            'Name' => 'Philchema Inc. Scholarship Grant',
            'Type' => 'Private',
            'Desc' => 'For 3rd-year BS Animal Science, Agriculture, or VetMed students. Must maintain a GPA of not worse than 2.00.',
            'Amount' => 10000.00,
            'Year' => '3rd Year',
            'GWA' => 2.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'DSWD Certificate of Indigency', 'Certificate of Good Moral Character', '2 Recommendation Letters']
        ],
        [
            'Name' => 'Prado Builders Scholarship',
            'Type' => 'Private',
            'Desc' => 'For residents of Tarlac Province from low-income families (income not exceeding P12k/month).',
            'Amount' => 20000.00,
            'Year' => NULL,
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Residency', 'DSWD Certificate of Indigency']
        ],
        [
            'Name' => 'The Camileños Inc. Scholarship Program',
            'Type' => 'Private',
            'Desc' => 'For graduating students from low-income families (not exceeding P12k/month).',
            'Amount' => 3000.00,
            'Year' => '4th Year',
            'GWA' => 3.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Indigency']
        ],
        [
            'Name' => 'Tolentino - Dahlgren Scholarship Program',
            'Type' => 'Private',
            'Desc' => 'For 1st-year students from Tarlac. GPA of not worse than 2.00. Income not exceeding P12k/month.',
            'Amount' => 9000.00,
            'Year' => '1st Year',
            'GWA' => 2.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Residency', 'DSWD Certificate of Indigency', 'Certificate of Good Moral Character', '2 Recommendation Letters']
        ],
        [
            'Name' => 'Bounty Cares Foundation Inc.',
            'Type' => 'Private',
            'Desc' => 'For 2nd-year Agri, Biosystems, Food Tech, Biology, Chem, and 4th-year VetMed. Must maintain 2.50 GPA with no grades lower than 2.75.',
            'Amount' => 37740.00,
            'Year' => '2nd Year',
            'GWA' => 2.50,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Certificate of Good Moral Character', '2 Recommendation Letters', 'Medical Certificate (Fit, X-ray, Urinalysis, Fecalysis, Eye Exam)']
        ],
        [
            'Name' => 'Cristobal Partido Scholarship Inc.',
            'Type' => 'Private',
            'Desc' => 'For 1st-year students from Tarlac. GPA of not worse than 2.00. Income not exceeding P12k/month.',
            'Amount' => 9573.00,
            'Year' => '1st Year',
            'GWA' => 2.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Residency', 'DSWD Certificate of Indigency', 'Certificate of Good Moral Character', '2 Recommendation Letters']
        ],
        [
            'Name' => 'Scholarship Program for IP Student',
            'Type' => 'Private',
            'Desc' => 'For 1st-year Indigenous Peoples (IP) students from Tarlac. Income not exceeding P12k/month.',
            'Amount' => 12000.00,
            'Year' => '1st Year',
            'GWA' => 2.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'IP Certification/ID', 'Barangay Certificate of Residency', 'DSWD Certificate of Indigency', 'Certificate of Good Moral Character', '2 Recommendation Letters']
        ],
        [
            'Name' => 'CJ and LJ Scholarships',
            'Type' => 'Private',
            'Desc' => 'For 1st-year students from Tarlac. GPA of not worse than 2.00. Income not exceeding P12k/month.',
            'Amount' => 5000.00,
            'Year' => '1st Year',
            'GWA' => 2.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Residency', 'DSWD Certificate of Indigency', 'Certificate of Good Moral Character', '2 Recommendation Letters']
        ],
        [
            'Name' => 'Don Francisco Santos Scholarship',
            'Type' => 'Private',
            'Desc' => 'For regular 3rd-year students. Must have a GPA of 2.5 or higher and no incomplete grades.',
            'Amount' => 6000.00,
            'Year' => '3rd Year',
            'GWA' => 2.50,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Indigency (Mayantoc)', 'MSWDO Certificate of Indigency', 'BIR Certificate of Tax Exemption']
        ],
        [
            'Name' => 'Ninoy and Cory Aquino Foundation Inc.',
            'Type' => 'Private',
            'Desc' => 'For 1st-year students. Annual household gross income must not exceed P375,000.00. High school average of 85%.',
            'Amount' => 15000.00,
            'Year' => '1st Year',
            'GWA' => 2.00, 
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Income Tax Return (ITR)']
        ],
        [
            'Name' => 'Geodetic Engineers of the Philippines Inc. (Reg III)',
            'Type' => 'Private',
            'Desc' => 'For 1st-year BS Geodetic Engineering students from Tarlac. Income not exceeding P12k/month.',
            'Amount' => 2000.00,
            'Year' => '1st Year',
            'GWA' => 2.00,
            'Docs' => ['Certificate of Registration (COR)', 'Report of Grades', 'Barangay Certificate of Residency', 'DSWD Certificate of Indigency', 'Certificate of Good Moral Character', '2 Recommendation Letters']
        ]
    ];

    $count = 0;
    
    // 3. PREPARE THE INSERT STATEMENTS
    $stmt_sch = $pdo->prepare("
        INSERT INTO scholarship (Name, Description, ProgramID, YearLevel, MinimumGWA, AwardAmount, Deadline, Status, CreatedBy, GenderRequirement, ScholarshipType, FormConfig, ReleaseFrequency, AllowsDual) 
        VALUES (:name, :desc, NULL, :yl, :gwa, :amt, '2026-08-30', 'Active', 2, 'Any', :type, 'Academic,Family,Financial,Essay', 'Per Semester', 'No')
    ");

    $stmt_req = $pdo->prepare("INSERT INTO document_requirement (ScholarshipID, DocumentName) VALUES (:sch_id, :doc_name)");

    $stmt_crit = $pdo->prepare("INSERT INTO scholarship_criteria (ScholarshipID, CriteriaName) VALUES (:sch_id, 'Essay')");

    // 4. LOOP AND INJECT TO DATABASE
    foreach ($tau_scholarships as $sch) {
        // Insert the Scholarship
        $stmt_sch->execute([
            'name' => $sch['Name'],
            'desc' => $sch['Description'],
            'yl'   => $sch['YearLevel'],
            'gwa'  => $sch['MinimumGWA'],
            'amt'  => $sch['AwardAmount'],
            'type' => $sch['Type']
        ]);
        
        $new_scholarship_id = $pdo->lastInsertId();

        // Insert the required Documents specific to this scholarship
        foreach ($sch['Docs'] as $doc) {
            $stmt_req->execute([
                'sch_id' => $new_scholarship_id,
                'doc_name' => $doc
            ]);
        }

        // Add a default scoring criteria (e.g. Essay Evaluation)
        $stmt_crit->execute(['sch_id' => $new_scholarship_id]);

        $count++;
    }

    echo "<div style='font-family: Arial, sans-serif; padding: 40px; text-align: center;'>";
    echo "<h1 style='color: #16a34a; font-size: 32px;'>✅ Database Successfully Seeded!</h1>";
    echo "<p style='font-size: 18px; color: #475569;'>Successfully injected <strong>$count</strong> official TAU Scholarships and their strict documentary requirements into your system.</p>";
    echo "<p style='color: #ef4444; font-weight: bold;'>⚠️ Please delete this file (`seed_tau_database.php`) after running it to secure your database.</p>";
    echo "<a href='index.php' style='display: inline-block; background-color: #2563eb; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; margin-top: 20px;'>Go Back to Dashboard</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<h2 style='color:red;'>ERROR: " . $e->getMessage() . "</h2>";
}
?>