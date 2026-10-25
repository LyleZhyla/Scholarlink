-- Per-scholarship edits to the official PDF's printed content, made in the PDF designer.
-- 'erase' = cover an area in white (removes printed text, lines, images there)
-- 'text'  = print new text (Cover = 1 puts a white background behind it, used to replace existing text)
-- Positions are fractions (0-1) of the page, from the top-left corner. The PDF file in /forms is never modified.
CREATE TABLE IF NOT EXISTS `scholarship_pdf_edits` (
  `EditID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `Kind` enum('erase','text') NOT NULL,
  `PdfPage` smallint(6) NOT NULL,
  `PdfX` decimal(8,6) NOT NULL,
  `PdfY` decimal(8,6) NOT NULL,
  `PdfW` decimal(8,6) NOT NULL,
  `PdfH` decimal(8,6) NOT NULL,
  `Content` text DEFAULT NULL,
  `FontFamily` varchar(20) NOT NULL DEFAULT 'Helvetica',
  `FontSize` decimal(4,1) DEFAULT NULL,
  `IsBold` tinyint(1) NOT NULL DEFAULT 0,
  `Cover` tinyint(1) NOT NULL DEFAULT 0,
  `SortOrder` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`EditID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  CONSTRAINT `fk_spe_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
