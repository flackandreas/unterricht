-- 11. Add summary columns to homework_assignments
ALTER TABLE homework_assignments
ADD COLUMN IF NOT EXISTS summary_common_errors TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS summary_solution_approach TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS summary_updated_at TIMESTAMP DEFAULT NULL;
