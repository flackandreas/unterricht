-- Wer hat wann welche Bewertung geaendert oder freigegeben.
CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT DEFAULT NULL,
    action VARCHAR(60) NOT NULL,
    entity VARCHAR(40) NOT NULL,
    entity_id INT DEFAULT NULL,
    details VARCHAR(1000) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_entity (entity, entity_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
