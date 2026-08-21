-- Rueckkanal: Schuelerinnen und Schueler koennen melden, dass ein Feedback
-- nicht stimmt. Das wichtigste Qualitaetssignal fuer den Prompt.
CREATE TABLE IF NOT EXISTS submission_feedback_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    reason ENUM('wrong','unclear','unfair','other') NOT NULL DEFAULT 'other',
    message VARCHAR(1000) DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_submission (submission_id),
    FOREIGN KEY (submission_id) REFERENCES homework_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
