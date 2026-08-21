ALTER TABLE feedback_questions ADD COLUMN IF NOT EXISTS question_type VARCHAR(20) NOT NULL DEFAULT 'emoji';
ALTER TABLE feedback_questions ADD COLUMN IF NOT EXISTS options TEXT DEFAULT NULL;

ALTER TABLE feedback_template_questions ADD COLUMN IF NOT EXISTS question_type VARCHAR(20) NOT NULL DEFAULT 'emoji';
ALTER TABLE feedback_template_questions ADD COLUMN IF NOT EXISTS options TEXT DEFAULT NULL;

ALTER TABLE feedback_responses MODIFY score INT NULL;
ALTER TABLE feedback_responses ADD COLUMN IF NOT EXISTS response_text TEXT DEFAULT NULL;
