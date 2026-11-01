-- Official PDF form (a file in /forms) used to print each scholarship's applications.
-- NULL = automatic: Annex 1 for Tulong Dunong / TES, TAU-OSSD-QF-19B for everything else.
ALTER TABLE `scholarship`
  ADD COLUMN `FormTemplate` varchar(150) DEFAULT NULL AFTER `FormUpdatedAt`;
