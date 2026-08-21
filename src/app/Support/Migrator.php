<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;

/**
 * Fuehrt die SQL-Migrationen aus.
 *
 * Bisher lief der komplette Durchlauf bei JEDEM Request: 16 Dateien, je eine
 * Abfrage gegen migration_log, dazu Dateizugriffe. Jetzt merkt sich ein
 * Zustandsvermerk in storage/, welcher Satz an Migrationsdateien zuletzt
 * vollstaendig durchgelaufen ist. Solange der Satz unveraendert ist, kostet
 * der Aufruf eine Dateipruefung und keine einzige Datenbankabfrage.
 */
final class Migrator
{
    /** @var list<string> Reihenfolge ist bedeutsam. */
    private const FILES = [
        'alter_homework.sql',
        'alter_feedback.sql',
        'db_update_klassen.sql',
        'alter_teacher_classes.sql',
        'alter_homework_context.sql',
        'alter_feedback_templates.sql',
        'alter_feedback_templates_klasse_fach.sql',
        'alter_homework_submission_token.sql',
        'alter_homework_expected_submissions.sql',
        'alter_homework_quest_reward.sql',
        'alter_feedback_types.sql',
        'alter_homework_summary.sql',
        'alter_homework_feedback_level.sql',
        'alter_homework_error_markers.sql',
        'alter_homework_submission_token_index.sql',
        'alter_rate_limits.sql',
        'alter_ai_usage.sql',
        'alter_evaluation_jobs.sql',
        'alter_homework_review_workflow.sql',
        'alter_homework_criteria.sql',
        'alter_student_progress.sql',
        'alter_feedback_reports.sql',
        'alter_audit_log.sql',
        'alter_homework_indexes.sql',
    ];

    private string $migrationDir;
    private string $stateFile;

    public function __construct(?string $migrationDir = null, ?string $stateFile = null)
    {
        $this->migrationDir = $migrationDir ?? dirname(__DIR__, 2);
        $this->stateFile = $stateFile ?? dirname(__DIR__, 2) . '/storage/migration-state';
    }

    /**
     * Kennung des aktuellen Migrationssatzes.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', implode('|', self::FILES)), 0, 32);
    }

    /**
     * Ist der aktuelle Satz bereits vollstaendig eingespielt?
     */
    public function isUpToDate(): bool
    {
        return is_file($this->stateFile)
            && trim((string)@file_get_contents($this->stateFile)) === $this->fingerprint();
    }

    /**
     * Spielt alle noch offenen Migrationen ein.
     *
     * @return array{ausgefuehrt:list<string>,uebersprungen:int,fehler:list<string>}
     */
    public function migrate(PDO $conn): array
    {
        $ausgefuehrt = [];
        $uebersprungen = 0;
        $fehler = [];

        $conn->exec('CREATE TABLE IF NOT EXISTS migration_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) UNIQUE NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->selfHeal($conn);

        $erledigt = $conn->query('SELECT filename FROM migration_log')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $erledigt = array_flip($erledigt);

        $log = $conn->prepare('INSERT INTO migration_log (filename) VALUES (?)');

        foreach (self::FILES as $file) {
            if (isset($erledigt[$file])) {
                $uebersprungen++;
                continue;
            }

            $pfad = $this->migrationDir . '/' . $file;
            if (!is_file($pfad)) {
                $fehler[] = $file . ': Datei fehlt';
                continue;
            }

            $erfolgreich = true;
            foreach ($this->statements((string)file_get_contents($pfad)) as $statement) {
                try {
                    $conn->exec($statement);
                } catch (PDOException $e) {
                    $erfolgreich = false;
                    $fehler[] = $file . ': ' . $e->getMessage();
                    error_log("Migration $file fehlgeschlagen: " . $e->getMessage());
                }
            }

            if ($erfolgreich) {
                $log->execute([$file]);
                $ausgefuehrt[] = $file;
            }
        }

        if ($fehler === []) {
            $this->markComplete();
        }

        return ['ausgefuehrt' => $ausgefuehrt, 'uebersprungen' => $uebersprungen, 'fehler' => $fehler];
    }

    /**
     * Zerlegt eine SQL-Datei in einzelne Anweisungen.
     *
     * Kommentarzeilen fliegen raus, damit ein "-- Hinweis; mit Semikolon"
     * die Zerlegung nicht durcheinanderbringt.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $ohneKommentare = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        return array_values(array_filter(
            array_map('trim', explode(';', $ohneKommentare)),
            static fn(string $s): bool => $s !== ''
        ));
    }

    /**
     * Fehlt eine Tabelle, obwohl ihre Migration als erledigt vermerkt ist,
     * wird der Vermerk entfernt, damit sie erneut laeuft.
     */
    private function selfHeal(PDO $conn): void
    {
        try {
            if ($conn->query("SHOW TABLES LIKE 'teacher_classes'")->fetch() === false) {
                $conn->exec("DELETE FROM migration_log WHERE filename = 'alter_teacher_classes.sql'");
            }
        } catch (PDOException $e) {
            error_log('Selbstheilung der Migrationen fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function markComplete(): void
    {
        $verzeichnis = dirname($this->stateFile);
        if (!is_dir($verzeichnis)) {
            mkdir($verzeichnis, 0750, true);
        }

        @file_put_contents($this->stateFile, $this->fingerprint());
    }
}
