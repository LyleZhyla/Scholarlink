-- Deadline reminders already sent (tools/send_deadline_reminders.php), so each one goes out only once
CREATE TABLE IF NOT EXISTS deadline_reminders (
    ApplicationID INT NOT NULL,
    DaysBefore TINYINT NOT NULL,           -- 7, 3 or 1 day(s) before the scholarship's deadline
    SentAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Emailed TINYINT(1) NOT NULL DEFAULT 0, -- 0 when only the portal notification was sent
    PRIMARY KEY (ApplicationID, DaysBefore),
    CONSTRAINT fk_reminder_application FOREIGN KEY (ApplicationID) REFERENCES application (ApplicationID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
