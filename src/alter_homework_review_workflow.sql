-- Freigabe-Workflow: die Auswertung geht erst nach Sichtung durch die
-- Lehrkraft an die Schuelerin oder den Schueler.
ALTER TABLE homework_assignments
    ADD COLUMN IF NOT EXISTS release_mode ENUM('immediate','review') NOT NULL DEFAULT 'immediate',
    ADD COLUMN IF NOT EXISTS show_score TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS max_submissions_per_student INT NOT NULL DEFAULT 3,
    ADD COLUMN IF NOT EXISTS allow_resubmission TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS retention_days INT NOT NULL DEFAULT 90,
    ADD COLUMN IF NOT EXISTS context_file_uri VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS context_file_expires_at DATETIME DEFAULT NULL;

ALTER TABLE homework_evaluations
    ADD COLUMN IF NOT EXISTS review_status ENUM('draft','released') NOT NULL DEFAULT 'released',
    ADD COLUMN IF NOT EXISTS released_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS released_by INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS edited_by_teacher TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS criteria_scores TEXT DEFAULT NULL;

ALTER TABLE homework_submissions
    ADD COLUMN IF NOT EXISTS attempt_no INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS image_deleted_at DATETIME DEFAULT NULL,
    -- Normalisierter Name: erkennt Mehrfachabgaben derselben Person und
    -- verbindet den Lernfortschritt geraeteunabhaengig.
    ADD COLUMN IF NOT EXISTS student_key VARCHAR(190) NOT NULL DEFAULT '';

ALTER TABLE homework_submissions ADD INDEX IF NOT EXISTS idx_submission_student (assignment_id, student_key);

-- 'pending' bleibt erhalten; 'queued' und 'failed' kommen fuer die
-- asynchrone Verarbeitung hinzu.
ALTER TABLE homework_submissions
    MODIFY COLUMN status ENUM('pending','queued','evaluated','failed') NOT NULL DEFAULT 'pending';
