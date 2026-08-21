-- Warteschlange fuer die KI-Auswertung.
-- Der Aufruf lief bisher synchron im Request: 10-60 Sekunden Wartezeit, und
-- ein Verbindungsabbruch liess die Abgabe dauerhaft unausgewertet zurueck.
CREATE TABLE IF NOT EXISTS evaluation_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(500) DEFAULT NULL,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL DEFAULT NULL,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_job_submission (submission_id),
    INDEX idx_job_pickup (status, available_at),
    FOREIGN KEY (submission_id) REFERENCES homework_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
