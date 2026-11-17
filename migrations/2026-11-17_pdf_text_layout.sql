-- Text written on the PDF: underline, alignment (left / center / right), and paragraphs (Content keeps their line breaks)
ALTER TABLE `scholarship_pdf_edits` ADD COLUMN `IsUnderline` tinyint(1) NOT NULL DEFAULT 0 AFTER `IsItalic`, ADD COLUMN `TextAlign` enum('left','center','right') NOT NULL DEFAULT 'left' AFTER `IsUnderline`;
