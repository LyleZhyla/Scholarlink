-- System Reports
-- 1) Each application belongs to the academic term it was submitted in (for reports by year / semester).
ALTER TABLE `application`
  ADD COLUMN `TermID` int(11) DEFAULT NULL AFTER `YearLevel`,
  ADD KEY `TermID` (`TermID`);

-- Existing applications: the latest term that had started on the submission date, otherwise the earliest term
UPDATE `application` a
SET a.`TermID` = COALESCE(
    (SELECT t.`TermID` FROM `academic_terms` t WHERE t.`StartDate` <= DATE(a.`DateSubmitted`) ORDER BY t.`StartDate` DESC, t.`TermID` DESC LIMIT 1),
    (SELECT t.`TermID` FROM `academic_terms` t ORDER BY t.`StartDate` ASC, t.`TermID` ASC LIMIT 1)
)
WHERE a.`TermID` IS NULL;

-- 2) Each sponsor's own report format for its scholarship (title, columns, headings).
CREATE TABLE IF NOT EXISTS `scholarship_report_formats` (
  `ScholarshipID` int(11) NOT NULL,
  `Title` varchar(200) DEFAULT NULL,
  `Subtitle` varchar(255) DEFAULT NULL,
  `Columns` text NOT NULL COMMENT 'JSON: [{"key": "...", "label": "..."}, ...] in report order',
  `PreparedBy` varchar(150) DEFAULT NULL,
  `UpdatedBy` int(11) DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ScholarshipID`),
  CONSTRAINT `fk_srf_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
