-- Text written on the PDF can be italic too (with its font, size, bold and colour)
ALTER TABLE `scholarship_pdf_edits` ADD COLUMN `IsItalic` tinyint(1) NOT NULL DEFAULT 0 AFTER `IsBold`;
