-- Indizes fuer die Uebersichts- und Auswertungsabfragen.
ALTER TABLE homework_assignments ADD INDEX IF NOT EXISTS idx_assignment_teacher (teacher_id, created_at);
ALTER TABLE homework_submissions ADD INDEX IF NOT EXISTS idx_submission_assignment (assignment_id, created_at);
ALTER TABLE homework_submissions ADD INDEX IF NOT EXISTS idx_submission_status (status);
