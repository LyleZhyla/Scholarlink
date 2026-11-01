-- Per-scholarship changes to the official PDF's own fields, made in the PDF designer.
-- 'hide' = leave the field blank on this scholarship's PDF; 'move' = print its value in a new box instead.
-- The PDF file in /forms is never modified, so other scholarships using it are unaffected.
CREATE TABLE IF NOT EXISTS `scholarship_pdf_overrides` (
  `ScholarshipID` int(11) NOT NULL,
  `FieldName` varchar(150) NOT NULL,
  `Action` enum('hide','move') NOT NULL,
  `PdfPage` smallint(6) DEFAULT NULL,
  `PdfX` decimal(8,6) DEFAULT NULL,
  `PdfY` decimal(8,6) DEFAULT NULL,
  `PdfW` decimal(8,6) DEFAULT NULL,
  `PdfH` decimal(8,6) DEFAULT NULL,
  `PdfFontSize` decimal(4,1) DEFAULT NULL,
  PRIMARY KEY (`ScholarshipID`, `FieldName`),
  CONSTRAINT `fk_spo_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
