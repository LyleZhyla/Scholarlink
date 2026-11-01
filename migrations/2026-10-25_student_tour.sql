-- First-login guided tour for students (includes/student_tour.php): when the student finished or skipped it.
-- NULL = the tour starts by itself on the student's next visit to the dashboard.
ALTER TABLE users ADD COLUMN IF NOT EXISTS TourCompletedAt DATETIME NULL DEFAULT NULL;

-- Students who have already signed in before this feature know their way around: don't start it for them
-- (they can still open it with "Take the tour" on the dashboard)
UPDATE users u SET u.TourCompletedAt = NOW()
WHERE u.Role = 'Student' AND u.TourCompletedAt IS NULL
  AND EXISTS (SELECT 1 FROM audit_log a WHERE a.UserID = u.UserID AND a.ActionPerformed = 'Login');
