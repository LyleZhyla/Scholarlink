-- Demo students only (@demo.tau.edu.ph): their applications were given a status directly, so their workflow
-- still sat at the first stage. Line the stages up with the status. Safe to run again: an application with any
-- completed stage is left alone, and real students are never touched.

-- Approved: every stage is done, so nothing is left in Workflow Tasks
UPDATE application_workflow_progress p
JOIN application a ON a.ApplicationID = p.ApplicationID
JOIN users u ON u.UserID = a.UserID
JOIN (SELECT ApplicationID FROM application_workflow_progress GROUP BY ApplicationID HAVING SUM(Status = 'Completed') = 0) fresh ON fresh.ApplicationID = p.ApplicationID
SET p.Status = 'Completed',
    p.StartedAt = COALESCE(p.StartedAt, a.DecisionAt, a.DateSubmitted),
    p.CompletedAt = COALESCE(a.DecisionAt, a.DateSubmitted)
WHERE u.Role = 'Student' AND u.Email LIKE '%@demo.tau.edu.ph' AND a.Status = 'Approved';

-- Shortlisted: waiting at the stage that approves them
UPDATE application_workflow_progress p
JOIN application a ON a.ApplicationID = p.ApplicationID
JOIN users u ON u.UserID = a.UserID
JOIN (SELECT ApplicationID FROM application_workflow_progress GROUP BY ApplicationID HAVING SUM(Status = 'Completed') = 0) fresh ON fresh.ApplicationID = p.ApplicationID
JOIN (SELECT ApplicationID, MIN(StageOrder) AS FinalOrder FROM application_workflow_progress WHERE CompletionAction = 'approve' GROUP BY ApplicationID) final ON final.ApplicationID = p.ApplicationID
SET p.CompletedAt = CASE WHEN p.StageOrder < final.FinalOrder THEN a.DateSubmitted ELSE NULL END,
    p.StartedAt = CASE WHEN p.StageOrder <= final.FinalOrder THEN COALESCE(p.StartedAt, a.DateSubmitted) ELSE NULL END,
    p.Status = CASE WHEN p.StageOrder < final.FinalOrder THEN 'Completed' WHEN p.StageOrder = final.FinalOrder THEN 'In Progress' ELSE 'Pending' END
WHERE u.Role = 'Student' AND u.Email LIKE '%@demo.tau.edu.ph' AND a.Status = 'Shortlisted';
