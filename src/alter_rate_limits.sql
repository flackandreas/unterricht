-- Zaehler fuer Rate-Limiting (Login-Versuche, Abgaben, KI-Aufrufe).
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(191) NOT NULL PRIMARY KEY,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hits INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
