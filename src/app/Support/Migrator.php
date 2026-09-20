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
 *
 * Scheitert eine Migration, wird das ebenfalls vermerkt. Ohne diesen Vermerk
 * bleibt der Zustand "nicht aktuell" fuer immer bestehen, und der gesamte
 * Durchlauf - CREATE TABLE IF NOT EXISTS, Selbstheilung, SELECT auf
 * migration_log und der erneute Versuch der kaputten Datei - wiederholte
 * sich bei JEDEM Request. Eine einzige fehlerhafte Anweisung machte damit
 * genau die Last dauerhaft, die der Vermerk vermeiden soll, und schrieb
 * dabei bei jedem Aufruf ins Fehlerprotokoll.
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
        'alter_students.sql',
        'alter_lesson_sessions.sql',
        'alter_participation.sql',
        'alter_substitute_plans.sql',
        'alter_portal_konten.sql',
    ];

    /** Wartezeit nach einem gescheiterten Durchlauf, in Sekunden. */
    private const FEHLER_PAUSE = 300;

    private string $migrationDir;
    private string $stateFile;

    public function __construct(?string $migrationDir = null, ?string $stateFile = null)
    {
        $this->migrationDir = $migrationDir ?? dirname(__DIR__, 2);
        $this->stateFile = $stateFile ?? self::vorgabeVermerk();
    }

    /**
     * Wo der Vermerk liegt, dass ein Migrationssatz vollstaendig durchlief.
     *
     * Bewusst NICHT in storage/: dieses Verzeichnis wird vom Host eingehaengt
     * und dadurch von jedem Container geteilt, der dasselbe Arbeitsverzeichnis
     * benutzt. Genau daran ist es gescheitert - ein Container spielte die
     * Migrationen in seine Datenbank ein und schrieb den Vermerk, ein zweiter
     * mit derselben Quelle, aber einer anderen Datenbank, hielt sich daraufhin
     * fuer fertig und lief gegen ein Schema ohne die neue Tabelle.
     *
     * Im temporaeren Verzeichnis hat jeder Container seinen eigenen Vermerk.
     * Der Preis ist ein Migrationslauf je Containerstart statt einer je
     * Bereitstellung; die Datenbank fuehrt ohnehin Buch darueber, was schon
     * eingespielt ist, sodass nichts doppelt laeuft.
     */
    private static function vorgabeVermerk(): string
    {
        $kennung = substr(hash(
            'sha256',
            (string) env('DB_HOST', 'db') . '|' . (string) env('DB_NAME', 'db_unterricht')
        ), 0, 12);

        return sys_get_temp_dir() . '/unterricht-migrationen-' . $kennung;
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
        return self::vermerkLesbar($this->stateFile)
            && trim((string)@file_get_contents($this->stateFile)) === $this->fingerprint();
    }

    /**
     * Darf dieser Vermerk gelesen werden?
     *
     * Die Vermerke liegen im temporaeren Verzeichnis unter einem Namen, der
     * sich aus DB_HOST und DB_NAME berechnet - also vorhersagbar ist. In
     * einem Container ist /tmp privat und die Frage stellt sich nicht. Laeuft
     * PHP dagegen auf einem geteilten Rechner, kann dort jeder andere
     * Benutzer eine Datei anlegen:
     *
     *   - mit dem richtigen Fingerabdruck darin, dann haelt sich die
     *     Anwendung fuer migriert und laeuft gegen ein altes Schema;
     *   - als Symlink, dann schreibt @file_put_contents() an dessen Ziel.
     *
     * Ein Vermerk zaehlt deshalb nur, wenn er eine gewoehnliche Datei ist und
     * uns selbst gehoert. Im Zweifel wird er ignoriert - dann laeuft die
     * Migration eben, und die Datenbank fuehrt ohnehin Buch.
     */
    private static function vermerkLesbar(string $datei): bool
    {
        if (!is_file($datei) || is_link($datei)) {
            return false;
        }

        if (!function_exists('posix_geteuid')) {
            return true;
        }

        $besitzer = @fileowner($datei);

        return $besitzer !== false && $besitzer === posix_geteuid();
    }

    /**
     * Wo der Vermerk ueber einen gescheiterten Durchlauf liegt.
     */
    private function fehlerVermerk(): string
    {
        return $this->stateFile . '.fehler';
    }

    /**
     * Ist derselbe Satz gerade erst gescheitert?
     *
     * Nur derselbe: aendert sich der Satz an Migrationsdateien, ist der
     * Vermerk hinfaellig und der naechste Aufruf laeuft sofort wieder.
     *
     * Gilt nur fuer den automatischen Lauf aus dem Request heraus. Wer
     * bin/migrate.php von Hand aufruft, will migrieren und wartet nicht.
     */
    public function kuerzlichGescheitert(): bool
    {
        $inhalt = self::vermerkLesbar($this->fehlerVermerk())
            ? (string)@file_get_contents($this->fehlerVermerk())
            : '';

        $teile = explode("\n", trim($inhalt));
        if (count($teile) !== 2 || $teile[0] !== $this->fingerprint()) {
            return false;
        }

        return (time() - (int)$teile[1]) < self::FEHLER_PAUSE;
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
            @unlink($this->fehlerVermerk());
        } else {
            $this->markFailed();
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
        self::schreibeVermerk($this->stateFile, $this->fingerprint());
    }

    private function markFailed(): void
    {
        self::schreibeVermerk($this->fehlerVermerk(), $this->fingerprint() . "\n" . time());
    }

    /**
     * Schreibt einen Vermerk - nie durch einen Symlink hindurch.
     */
    private static function schreibeVermerk(string $datei, string $inhalt): void
    {
        $verzeichnis = dirname($datei);
        if (!is_dir($verzeichnis)) {
            mkdir($verzeichnis, 0750, true);
        }

        if (is_link($datei)) {
            error_log('Migrationsvermerk ' . $datei . ' ist ein Symlink - wird nicht beschrieben.');

            return;
        }

        if (@file_put_contents($datei, $inhalt, LOCK_EX) !== false) {
            @chmod($datei, 0600);
        }
    }

}
