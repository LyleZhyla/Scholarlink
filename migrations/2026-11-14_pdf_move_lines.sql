-- Lines moved on the PDF form tab: the strip PdfY..PdfY+PdfH is put back at PdfTo
ALTER TABLE `scholarship_pdf_edits` MODIFY `Kind` enum('erase','text','row','column','break','move') NOT NULL, ADD COLUMN `PdfTo` decimal(8,6) DEFAULT NULL AFTER `PdfH`;
