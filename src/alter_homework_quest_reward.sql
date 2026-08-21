-- Add quest_reward to homework_assignments for custom Class Quest rewards
ALTER TABLE homework_assignments ADD COLUMN IF NOT EXISTS quest_reward VARCHAR(255) DEFAULT NULL;
