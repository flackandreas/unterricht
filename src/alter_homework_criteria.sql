-- Bewertungsraster je Hausaufgabe. Ersetzt die frei erfundene Gesamtpunktzahl
-- durch nachvollziehbare Teilkriterien.
CREATE TABLE IF NOT EXISTS homework_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    label VARCHAR(190) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    max_points INT NOT NULL DEFAULT 10,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_criteria_assignment (assignment_id, sort_order),
    FOREIGN KEY (assignment_id) REFERENCES homework_assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
