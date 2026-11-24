-- New semester: every student uploads their COR (checked by AI against the declared semester), scholars submit the
-- semester's renewal requirements, and an approved renewal adds that semester's benefit to the scholar's balance

-- The semester the admin declared, and the date its renewal requirements are due
ALTER TABLE `academic_terms`
  ADD COLUMN `Semester` varchar(30) DEFAULT NULL AFTER `TermName`,
  ADD COLUMN `SchoolYear` varchar(20) DEFAULT NULL AFTER `Semester`,
  ADD COLUMN `RenewalDeadline` date DEFAULT NULL AFTER `EndDate`,
  ADD COLUMN `StartedBy` int(11) DEFAULT NULL AFTER `RenewalDeadline`;

-- Each student's Certificate of Registration for a semester (the wall opens once there is one that is not rejected)
CREATE TABLE IF NOT EXISTS `student_term_cor` (
  `CorID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `TermID` int(11) NOT NULL,
  `FilePath` varchar(255) NOT NULL,
  `OriginalFileName` varchar(255) DEFAULT NULL,
  `MimeType` varchar(100) DEFAULT NULL,
  `FileSize` int(11) DEFAULT NULL,
  `FileChecksum` char(64) DEFAULT NULL,
  `AIStatus` enum('Verified','Needs Review','Rejected') NOT NULL DEFAULT 'Needs Review',
  `AIRemarks` text DEFAULT NULL,
  `SemesterFound` varchar(120) DEFAULT NULL,
  `UploadedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ReviewedBy` int(11) DEFAULT NULL,
  `ReviewedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`CorID`),
  UNIQUE KEY `uniq_student_term` (`UserID`, `TermID`),
  KEY `idx_term_status` (`TermID`, `AIStatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- What scholars submit to renew: for one semester (all scholars) or for one scholarship (every semester)
CREATE TABLE IF NOT EXISTS `renewal_requirements` (
  `RequirementID` int(11) NOT NULL AUTO_INCREMENT,
  `TermID` int(11) DEFAULT NULL,
  `ScholarshipID` int(11) DEFAULT NULL,
  `Name` varchar(150) NOT NULL,
  `Description` varchar(500) DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`RequirementID`),
  KEY `idx_term` (`TermID`),
  KEY `idx_scholarship` (`ScholarshipID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `renewal_submissions` (
  `SubmissionID` int(11) NOT NULL AUTO_INCREMENT,
  `RenewalID` int(11) NOT NULL,
  `RequirementID` int(11) NOT NULL,
  `FilePath` varchar(255) NOT NULL,
  `OriginalFileName` varchar(255) DEFAULT NULL,
  `MimeType` varchar(100) DEFAULT NULL,
  `FileSize` int(11) DEFAULT NULL,
  `FileChecksum` char(64) DEFAULT NULL,
  `UploadedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`SubmissionID`),
  UNIQUE KEY `uniq_renewal_requirement` (`RenewalID`, `RequirementID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One semester's benefit, added to the scholar's balance when their renewal for that semester is approved (once)
CREATE TABLE IF NOT EXISTS `scholar_term_benefits` (
  `TermBenefitID` int(11) NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `TermID` int(11) NOT NULL,
  `Amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `AddedBy` int(11) DEFAULT NULL,
  `AddedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`TermBenefitID`),
  UNIQUE KEY `uniq_application_term` (`ApplicationID`, `TermID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The new-semester emails to scholars (sent a few at a time, so none is sent twice)
CREATE TABLE IF NOT EXISTS `semester_emails` (
  `EmailID` int(11) NOT NULL AUTO_INCREMENT,
  `TermID` int(11) NOT NULL,
  `RenewalID` int(11) NOT NULL,
  `Status` enum('Queued','Sent','Failed','No Email') NOT NULL DEFAULT 'Queued',
  `SentAt` datetime DEFAULT NULL,
  PRIMARY KEY (`EmailID`),
  UNIQUE KEY `uniq_term_renewal` (`TermID`, `RenewalID`),
  KEY `idx_term_status` (`TermID`, `Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
