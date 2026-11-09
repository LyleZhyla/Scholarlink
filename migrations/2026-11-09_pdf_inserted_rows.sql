-- PDF designer: inserted rows and columns are stored with the other content edits (see includes/pdf_templates.php)
ALTER TABLE `scholarship_pdf_edits` MODIFY `Kind` enum('erase','text','row','column') NOT NULL;
