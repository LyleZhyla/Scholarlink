-- Approval notice: the heading parts the admin can now edit (blank = automatic)
--   DisplayName   name shown in "LIST OF NEW ... GRANTEES" (default: the scholarship's name)
--   Semester      e.g. "1st Semester" (default: the active academic term)
--   AcademicYear  e.g. "2026-2027"    (default: the active academic term)
--   NoticeDate    date printed on the notice (default: the day it is generated)
ALTER TABLE scholarship_award_notice
    ADD COLUMN DisplayName VARCHAR(150) NULL AFTER ScholarshipID,
    ADD COLUMN Semester VARCHAR(40) NULL AFTER DisplayName,
    ADD COLUMN AcademicYear VARCHAR(20) NULL AFTER Semester,
    ADD COLUMN NoticeDate DATE NULL AFTER AcademicYear;
