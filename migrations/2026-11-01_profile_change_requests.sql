-- Profile changes that need the Super Admin's approval (includes/profile_fields.php).
-- Students edit their Personal Details directly; changes to everything else (student number, email, year level,
-- IDs, address, family, special status) wait here until a Super Admin approves or rejects them.
CREATE TABLE IF NOT EXISTS profile_change_requests (
    RequestID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    Changes TEXT NOT NULL,                       -- JSON: {"field": {"from": "...", "to": "..."}}
    Status VARCHAR(20) NOT NULL DEFAULT 'Pending', -- Pending, Approved, Rejected, Cancelled
    ReviewNote VARCHAR(500) NULL,                -- the Super Admin's reason (required when rejecting)
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ReviewedBy INT NULL,
    ReviewedAt DATETIME NULL,
    KEY idx_profile_req_status (Status, CreatedAt),
    KEY idx_profile_req_user (UserID, Status),
    CONSTRAINT fk_profile_req_user FOREIGN KEY (UserID) REFERENCES users (UserID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
