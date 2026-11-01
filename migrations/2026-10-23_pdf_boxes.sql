-- Boxes that admins draw on the official PDF in the Form Builder.
-- A question either fills an existing PDF field (PDFFieldName) or a drawn box (PdfPage + position).
-- Positions are fractions (0-1) of the page width/height, measured from the top-left corner.
ALTER TABLE `scholarship_custom_fields`
  ADD COLUMN `PdfPage` smallint(6) DEFAULT NULL AFTER `PDFFieldName`,
  ADD COLUMN `PdfX` decimal(8,6) DEFAULT NULL AFTER `PdfPage`,
  ADD COLUMN `PdfY` decimal(8,6) DEFAULT NULL AFTER `PdfX`,
  ADD COLUMN `PdfW` decimal(8,6) DEFAULT NULL AFTER `PdfY`,
  ADD COLUMN `PdfH` decimal(8,6) DEFAULT NULL AFTER `PdfW`,
  ADD COLUMN `PdfFontSize` decimal(4,1) DEFAULT NULL COMMENT 'NULL = shrink to fit the box' AFTER `PdfH`;
