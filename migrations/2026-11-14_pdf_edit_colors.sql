-- Changed / erased text is covered with the colour that was behind it, and written in the colour it had
ALTER TABLE `scholarship_pdf_edits` ADD COLUMN `FillColor` char(7) DEFAULT NULL AFTER `Cover`, ADD COLUMN `TextColor` char(7) DEFAULT NULL AFTER `FillColor`;
