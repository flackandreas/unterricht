-- Migration: Add feedback_level column to homework_assignments
ALTER TABLE homework_assignments 
ADD COLUMN IF NOT EXISTS feedback_level VARCHAR(50) NOT NULL DEFAULT 'appropriate';
