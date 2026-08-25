-- Vertretungsstunden.
--
-- Plan und Auftrag in einer Tabelle: der Plan *ist* der Auftrag. Die Spalten
-- fuer Versuche und Wiedervorlage entsprechen evaluation_jobs, damit
-- bin/worker.php dieselbe Vergabelogik wiederverwenden kann - Kandidat lesen,
-- per UPDATE beanspruchen, nur wer die Zeile veraendert hat, bekommt sie.
--
-- status draft vor released ist derselbe Gedanke wie im Freigabemodus der
-- Hausaufgaben: nichts, was die KI erzeugt, erreicht eine Klasse, bevor ein
-- Mensch daraufgeschaut hat.
--
-- Kein Fremdschluessel auf teacher_id / released_by: `teachers` ist in dieser
-- Installation eine View auf db_feedback.teachers, und auf eine View laesst
-- MariaDB keinen Fremdschluessel zu. Siehe alter_lesson_sessions.sql.
CREATE TABLE IF NOT EXISTS substitute_plans (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id   INT NOT NULL,
    class_id     INT NOT NULL,
    fach         VARCHAR(100) NOT NULL,
    lesson_date  DATE NOT NULL,
    period       TINYINT UNSIGNED DEFAULT NULL,
    dauer        TINYINT UNSIGNED NOT NULL DEFAULT 45,
    hinweis      VARCHAR(500) DEFAULT NULL,
    context_json TEXT NULL,
    plan_json    TEXT NULL,
    status       ENUM('queued','running','draft','released','failed') NOT NULL DEFAULT 'queued',
    attempts     INT NOT NULL DEFAULT 0,
    last_error   VARCHAR(500) DEFAULT NULL,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at   TIMESTAMP NULL DEFAULT NULL,
    released_by  INT DEFAULT NULL,
    released_at  TIMESTAMP NULL DEFAULT NULL,
    access_token VARCHAR(64) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_plan_token (access_token),
    INDEX idx_plan_pickup (status, available_at),
    INDEX idx_plan_teacher (teacher_id, created_at),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
