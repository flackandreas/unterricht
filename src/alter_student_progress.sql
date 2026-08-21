-- Serverseitiger Lernfortschritt. XP, Level und Streak lagen bisher im
-- localStorage: geraeteabhaengig, beim Loeschen der Browserdaten weg und
-- in Sekunden manipulierbar.
CREATE TABLE IF NOT EXISTS student_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    klasse VARCHAR(100) NOT NULL,
    student_key VARCHAR(190) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    xp INT NOT NULL DEFAULT 0,
    level INT NOT NULL DEFAULT 1,
    streak INT NOT NULL DEFAULT 0,
    last_submission_date DATE DEFAULT NULL,
    avatar_seed VARCHAR(64) DEFAULT NULL,
    avatar_style VARCHAR(32) NOT NULL DEFAULT 'adventurer',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_progress_student (klasse, student_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Welche Abgabe bereits XP gegeben hat - verhindert Mehrfachgutschriften.
CREATE TABLE IF NOT EXISTS student_progress_awards (
    submission_id INT NOT NULL PRIMARY KEY,
    progress_id INT NOT NULL,
    xp_awarded INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES homework_submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (progress_id) REFERENCES student_progress(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
