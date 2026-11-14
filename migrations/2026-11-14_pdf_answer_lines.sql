-- A printed answer line under an answer blank added in the PDF designer (a thin filled bar in FillColor)
ALTER TABLE `scholarship_pdf_edits` MODIFY `Kind` enum('erase','text','row','column','break','move','line') NOT NULL;
