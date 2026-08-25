-- Ein Datensatz je Wortbeitrag.
--
-- client_uid kommt vom Geraet und macht das Nachliefern aus dem
-- Offline-Zwischenspeicher wiederholbar: zweimal geschickt heisst nicht
-- zweimal gezaehlt. Ohne diesen Schluessel waere jede abgebrochene
-- Verbindung im Klassenzimmer eine doppelte Zaehlung.
--
-- Bewusst nicht vorgesehen: eine Spalte fuer Stoerungen. Sie waere in zehn
-- Minuten ergaenzt und macht aus einer Leistungsdokumentation eine
-- Verhaltensakte ueber Minderjaehrige - mit anderem rechtlichen Gewicht.
CREATE TABLE IF NOT EXISTS participation_events (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id INT NOT NULL,
    weight     TINYINT NOT NULL DEFAULT 2,
    kind       ENUM('freiwillig','aufgerufen') NOT NULL DEFAULT 'freiwillig',
    note       VARCHAR(200) DEFAULT NULL,
    client_uid CHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_client (client_uid),
    INDEX idx_event_student (student_id, created_at),
    INDEX idx_event_session (session_id),
    FOREIGN KEY (session_id) REFERENCES lesson_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
