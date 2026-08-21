-- Add expected_submissions to homework_assignments for Class Quests
ALTER TABLE homework_assignments ADD COLUMN IF NOT EXISTS expected_submissions INT DEFAULT 0;
