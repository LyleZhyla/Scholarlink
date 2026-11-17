-- A Word-style document's page margins (and a letterhead running to the paper's edges), copied from the official PDF
ALTER TABLE `application_form_documents` ADD COLUMN `LayoutJson` varchar(255) DEFAULT NULL AFTER `FooterHeightMm`;
