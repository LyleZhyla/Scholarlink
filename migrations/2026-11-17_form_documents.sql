-- A scholarship's printed form: the official PDF (PDF designer) or a Word-style document written in the form builder
ALTER TABLE `scholarship` ADD COLUMN `FormMode` enum('pdf','document') NOT NULL DEFAULT 'pdf' AFTER `FormTemplate`;
CREATE TABLE IF NOT EXISTS `application_form_documents` (
  `ScholarshipID` int(11) NOT NULL,
  `BodyHtml` longtext NOT NULL,
  `HeaderHtml` mediumtext DEFAULT NULL,
  `FooterHtml` mediumtext DEFAULT NULL,
  `PaperSize` enum('A4','Letter','Legal') NOT NULL DEFAULT 'A4',
  `HeaderHeightMm` decimal(5,1) NOT NULL DEFAULT 20.0,
  `FooterHeightMm` decimal(5,1) NOT NULL DEFAULT 12.0,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ScholarshipID`),
  CONSTRAINT `fk_form_document_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
