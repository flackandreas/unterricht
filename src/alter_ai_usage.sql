-- Tokenverbrauch je KI-Aufruf. Ohne diese Zahlen gibt es keine Kostenkontrolle.
CREATE TABLE IF NOT EXISTS ai_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feature VARCHAR(40) NOT NULL,
    model VARCHAR(80) NOT NULL,
    teacher_id INT DEFAULT NULL,
    assignment_id INT DEFAULT NULL,
    prompt_tokens INT NOT NULL DEFAULT 0,
    completion_tokens INT NOT NULL DEFAULT 0,
    total_tokens INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_usage_created (created_at),
    INDEX idx_ai_usage_assignment (assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
