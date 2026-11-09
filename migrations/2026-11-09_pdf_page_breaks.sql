-- PDF designer: where a page that grew past its bottom continues on a new printed page
ALTER TABLE `scholarship_pdf_edits` MODIFY `Kind` enum('erase','text','row','column','break') NOT NULL;
