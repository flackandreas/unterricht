-- Erste zentrale Klassenliste.
--
-- Bisher gab es kein Schuelerverzeichnis: Namen tippten die Schuelerinnen und
-- Schueler bei jeder Abgabe selbst ein. student_key ist derselbe normalisierte
-- Schluessel, den Gamification::studentKey() erzeugt - dadurch findet die neue
-- Liste den vorhandenen Lernfortschritt wieder, ohne dass eine einzige Zeile
-- im Bestand angefasst werden muss.
CREATE TABLE IF NOT EXISTS students (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    class_id     INT NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    student_key  VARCHAR(190) NOT NULL,
    sort_order   INT NOT NULL DEFAULT 0,
    archived_at  DATE DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_class_key (class_id, student_key),
    INDEX idx_student_class (class_id, archived_at),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
