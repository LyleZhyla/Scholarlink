-- Approval notice ("List of New Grantees") of each scholarship: what approved scholars must still submit.
-- Edited by whoever decides the scholarship's applications (OSSD for internally funded, the provider otherwise).
CREATE TABLE IF NOT EXISTS scholarship_award_notice (
    ScholarshipID INT NOT NULL PRIMARY KEY,
    BatchLabel VARCHAR(60) NULL,                 -- e.g. "Batch 2.1"
    Intro VARCHAR(255) NOT NULL DEFAULT 'Visit the OSSD and submit the following:',
    Requirements TEXT NOT NULL,                  -- JSON: [{"text": "...", "sub": ["...", "..."]}]
    SubmissionDeadline DATE NULL,
    ClosingNote TEXT NULL,                       -- e.g. orientation schedule
    UpdatedBy INT NULL,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_award_notice_scholarship FOREIGN KEY (ScholarshipID) REFERENCES scholarship (ScholarshipID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
