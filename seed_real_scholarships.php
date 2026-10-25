<?php
require 'includes/db_connect.php';

try {
    // 1. I-DISABLE ANG FOREIGN KEY PARA MABURA NANG LIGTAS ANG LUMANG DATA
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE document_requirement;");
    $pdo->exec("TRUNCATE TABLE scholarship;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // 2. ANG MGA TOTOONG TAU SCHOLARSHIPS (Institutional, Government, Private)
    $real_scholarships = [
        // === INSTITUTIONAL SCHOLARSHIPS ===
        [
            'Name' => 'Academic Scholars - Full',
            'Description' => 'Institutional scholarship for regular students with an academic load, no dropped/failed subjects, and maintaining the highest academic standards.',
            'YearLevel' => NULL,
            'MinimumGWA' => 1.20,
            'AwardAmount' => 5000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'Academic Scholars - Partial',
            'Description' => 'Institutional scholarship for regular students carrying a minimum of 12 units with no dropped, conditional, or failed grades.',
            'YearLevel' => NULL,
            'MinimumGWA' => 1.75,
            'AwardAmount' => 1500.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'TAU Athletes Training Allowance',
            'Description' => 'Regular training allowance for university athletes subject to monitoring and endorsement of the Director for Sports Development.',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 5000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'Cultural Performers Grant',
            'Description' => 'Allowance for student cultural performers endorsed by the Socio-Cultural Development Director.',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 5000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'Golden Harvest Publication Incentive',
            'Description' => 'Incentives for the Editorial Board (Editor-in-Chief, Associate, etc.) of the official student publication of TAU.',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 5000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'ROTC Cadet Allowance',
            'Description' => 'Semester allowance for First Class and Second Class ROTC Cadets.',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 5000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'SSC/CSC Leadership Incentives',
            'Description' => 'Incentives for student leaders holding elective or appointive positions indicated in the TAU Code.',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 5000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'Tulong Dunong Program (One-time SUC)',
            'Description' => 'Financial assistance for students coming from low-income earning families (monthly income not exceeding Php12,000).',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 7500.00,
            'Deadline' => '2026-09-15'
        ],

        // === GOVERNMENT-FUNDED SCHOLARSHIPS ===
        [
            'Name' => 'Tarlac City Agricultural Scholarship',
            'Description' => 'For indigent students enrolled in Agriculture and related courses. Must not be a beneficiary of other government scholarships.',
            'YearLevel' => NULL,
            'MinimumGWA' => 2.00,
            'AwardAmount' => 3500.00,
            'Deadline' => '2026-09-15'
        ],
        [
            'Name' => 'ACEF-GIAHEP Grant',
            'Description' => 'For students in Agriculture, Forestry, Fisheries, and VetMed. Preference given to dependents of registered farmers/fisherfolks.',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 15000.00, // Combined tuition & stipend estimate
            'Deadline' => '2026-09-30'
        ],
        [
            'Name' => 'DOST Scholarships (RA 7687 / MERIT)',
            'Description' => 'For talented students in priority S&T courses. Must maintain an 83% (2.00) GWA and carry a normal academic load.',
            'YearLevel' => NULL,
            'MinimumGWA' => 2.00,
            'AwardAmount' => 15000.00, // Stipend + Tuition estimate
            'Deadline' => '2026-09-30'
        ],
        [
            'Name' => 'Tertiary Educational Subsidy (TES)',
            'Description' => 'Major government subsidy for students from low-income earning families (net monthly income not exceeding Php12,000).',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 20000.00,
            'Deadline' => '2026-10-15'
        ],
        [
            'Name' => 'Tulong Agri Program',
            'Description' => 'For students in agriculture, fisheries, forestry, food tech, and veterinary medicine from low-income families.',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 13500.00, // Per semester computation
            'Deadline' => '2026-10-15'
        ],
        [
            'Name' => 'TAU Alumni and Friends Grant',
            'Description' => 'Support grant funded by TAU Alumni for bonafide students from low-income families.',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 4000.00,
            'Deadline' => '2026-09-15'
        ],

        // === PRIVATE-FUNDED SCHOLARSHIPS ===
        [
            'Name' => 'Lorna Fernando Dinos Scholarship',
            'Description' => 'For 1st-year students (max 25 yrs old) residing in Tarlac Province with high school average of 85%.',
            'YearLevel' => '1st Year',
            'MinimumGWA' => 2.00,
            'AwardAmount' => 9000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'Philchema Inc. Scholarship Grant',
            'Description' => 'For 3rd-year students taking BS Animal Science, Agriculture, or VetMed. Includes thesis grant.',
            'YearLevel' => '3rd Year',
            'MinimumGWA' => 2.50,
            'AwardAmount' => 10000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'Prado Builders Scholarship',
            'Description' => 'Financial assistance for residents of Tarlac Province belonging to low-income families.',
            'YearLevel' => NULL,
            'MinimumGWA' => 3.00,
            'AwardAmount' => 20000.00,
            'Deadline' => '2026-09-15'
        ],
        [
            'Name' => 'The Camileños Inc. Scholarship',
            'Description' => 'For graduating students of TAU residing in Camiling and coming from low-income families.',
            'YearLevel' => '4th Year',
            'MinimumGWA' => 3.00,
            'AwardAmount' => 3000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'Tolentino - Dahlgren Scholarship',
            'Description' => 'For 1st-year students residing in Tarlac Province with no grade worse than 2.50.',
            'YearLevel' => '1st Year',
            'MinimumGWA' => 2.00,
            'AwardAmount' => 9000.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'Bounty Cares Foundation Inc.',
            'Description' => 'Strictly for 2nd-year Agri, Biosystems, Food Tech, Biology, Chemistry, or 4th-year VetMed students.',
            'YearLevel' => '2nd Year',
            'MinimumGWA' => 2.50,
            'AwardAmount' => 37740.00,
            'Deadline' => '2026-08-25'
        ],
        [
            'Name' => 'Cristobal Partido Scholarship Inc.',
            'Description' => 'Assistance for 1st-year students from low-income families in Tarlac.',
            'YearLevel' => '1st Year',
            'MinimumGWA' => 2.50,
            'AwardAmount' => 9573.00,
            'Deadline' => '2026-08-30'
        ],
        [
            'Name' => 'IP Student Scholarship Program',
            'Description' => 'Dedicated scholarship for students belonging to any Indigenous Peoples group in the country.',
            'YearLevel' => NULL,
            'MinimumGWA' => 2.00,
            'AwardAmount' => 12000.00,
            'Deadline' => '2026-09-30'
        ],
        [
            'Name' => 'CJ and LJ Scholarships',
            'Description' => 'Private grant for 1st-year Filipino students residing in Tarlac.',
            'YearLevel' => '1st Year',
            'MinimumGWA' => 2.00,
            'AwardAmount' => 5000.00,
            'Deadline' => '2026-09-15'
        ],
        [
            'Name' => 'Don Francisco Santos Scholarship',
            'Description' => 'For regular 3rd-year students with no failing or incomplete grades. Requires a Certificate of Indigency from Mayantoc.',
            'YearLevel' => '3rd Year',
            'MinimumGWA' => 2.50,
            'AwardAmount' => 6000.00,
            'Deadline' => '2026-09-30'
        ],
        [
            'Name' => 'Ninoy and Cory Aquino Foundation',
            'Description' => 'For 1st-year students with an annual household income not exceeding P375,000.00 and high school average of 85%.',
            'YearLevel' => '1st Year',
            'MinimumGWA' => 2.00, // Equivalent to 80% maintaining grade
            'AwardAmount' => 15000.00,
            'Deadline' => '2026-09-15'
        ],
        [
            'Name' => 'Geodetic Engineers of the Phil. (Reg III)',
            'Description' => 'Exclusive for 1st-year Bachelor of Geodetic Engineering students residing in Tarlac.',
            'YearLevel' => '1st Year',
            'MinimumGWA' => 2.00,
            'AwardAmount' => 2000.00,
            'Deadline' => '2026-09-15'
        ]
    ];

    // 3. I-INSERT ANG MGA BAGONG DATA
    $stmt = $pdo->prepare("INSERT INTO scholarship (Name, Description, ProgramID, YearLevel, MinimumGWA, AwardAmount, Deadline, Status) VALUES (:name, :desc, NULL, :yl, :gwa, :amt, :deadline, 'Active')");

    $count = 0;
    foreach ($real_scholarships as $sch) {
        $stmt->execute([
            'name' => $sch['Name'],
            'desc' => $sch['Description'],
            'yl' => $sch['YearLevel'],
            'gwa' => $sch['MinimumGWA'],
            'amt' => $sch['AwardAmount'],
            'deadline' => $sch['Deadline']
        ]);
        $count++;
    }

    echo "<h2 style='color:green;'>SUCCESS: $count Real TAU Scholarships injected into the database!</h2>";
    echo "<p>You can now delete this file (seed_real_scholarships.php) for security.</p>";
    echo "<a href='index.php'>Go back to Home Page</a>";

} catch (PDOException $e) {
    echo "<h2 style='color:red;'>ERROR: " . $e->getMessage() . "</h2>";
}
?>