-- Approvals made before application.DecisionAt existed have no date. Take it from the audit log
-- ("... marked Application #123 as Approved"). Scholars with no such entry stay NULL ("Not recorded").
UPDATE application a
JOIN (
    SELECT CAST(REPLACE(REGEXP_SUBSTR(Description, '#[0-9]+'), '#', '') AS UNSIGNED) AS ApplicationID, MAX(ActionDate) AS ApprovedAt
    FROM audit_log
    WHERE ActionPerformed = 'Application Approved' AND Description REGEXP '#[0-9]+'
    GROUP BY 1
) approved ON approved.ApplicationID = a.ApplicationID
SET a.DecisionAt = approved.ApprovedAt
WHERE a.Status = 'Approved' AND a.DecisionAt IS NULL;
