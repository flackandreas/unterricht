-- Eine gehaltene Stunde.
--
-- Traegt beide Haelften des Moduls: die Beteiligung haengt an der Stunde,
-- und die Themenfolge einer Klasse ist spaeter der Kontext, aus dem eine
-- anschlussfaehige Vertretungsstunde entsteht.
--
-- Kein Fremdschluessel auf teacher_id: In dieser Installation ist `teachers`
-- keine Tabelle, sondern eine View auf db_feedback.teachers - die Suite
-- teilt sich einen Benutzerbestand. Auf eine View laesst MariaDB keinen
-- Fremdschluessel zu (errno 150). Die aelteren Tabellen tragen ihre
-- teachers-Verweise noch aus der Zeit vor der Umstellung; neu anlegen laesst
-- sich so einer nicht mehr. Ein Index tut hier denselben Dienst fuer die
-- Abfragen, die Zuordnung prueft die Anwendung.
CREATE TABLE IF NOT EXISTS lesson_sessions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id  INT NOT NULL,
    class_id    INT NOT NULL,
    fach        VARCHAR(100) NOT NULL,
    lesson_date DATE NOT NULL,
    period      TINYINT UNSIGNED NOT NULL,
    topic       VARCHAR(255) DEFAULT NULL,
    note        TEXT NULL,
    closed_at   TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_lesson (teacher_id, class_id, lesson_date, period),
    INDEX idx_lesson_class (class_id, fach, lesson_date),
    INDEX idx_lesson_guess (teacher_id, period, lesson_date),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
