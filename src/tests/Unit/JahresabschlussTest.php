<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Jahresabschluss;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Das Archiv ist eine Liste von siebzehn Abfragen. So etwas sieht man nicht
 * an: ein Tippfehler in einem Spaltennamen laesst genau einen Auszug
 * ausfallen, der Rest laeuft weiter, und im Archiv fehlt still ein Stueck
 * Schuljahr. Deshalb laufen hier alle Abfragen wirklich - gegen ein
 * Schema, das nur die Spalten hat, die sie anfassen.
 */
final class JahresabschlussTest extends TestCase
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE classes (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT);
        CREATE TABLE teachers (id INTEGER PRIMARY KEY, kuerzel TEXT, name TEXT, passwort_hash TEXT);
        CREATE TABLE students (id INTEGER PRIMARY KEY, class_id INTEGER, display_name TEXT,
            student_key TEXT, sort_order INTEGER, archived_at TEXT, created_at TEXT);
        CREATE TABLE teacher_classes (teacher_id INTEGER, class_id INTEGER);
        CREATE TABLE homework_assignments (id INTEGER PRIMARY KEY, teacher_id INTEGER, klasse TEXT,
            fach TEXT, title TEXT, description TEXT, token TEXT, expected_submissions INTEGER,
            quest_reward TEXT, feedback_level TEXT, release_mode TEXT, max_submissions_per_student INTEGER,
            allow_resubmission INTEGER, retention_days INTEGER, due_date TEXT,
            summary_common_errors TEXT, summary_solution_approach TEXT, created_at TEXT);
        CREATE TABLE homework_criteria (id INTEGER PRIMARY KEY, assignment_id INTEGER, label TEXT,
            description TEXT, max_points INTEGER);
        CREATE TABLE homework_submissions (id INTEGER PRIMARY KEY, assignment_id INTEGER,
            student_name TEXT, student_key TEXT, student_pseudonym TEXT, token TEXT, attempt_no INTEGER,
            status TEXT, image_path TEXT, image_deleted_at TEXT, created_at TEXT);
        CREATE TABLE homework_evaluations (submission_id INTEGER, score INTEGER, student_feedback TEXT,
            teacher_notes TEXT, criteria_scores TEXT, review_status TEXT, released_at TEXT,
            released_by INTEGER, edited_by_teacher INTEGER);
        CREATE TABLE submission_feedback_reports (id INTEGER PRIMARY KEY, submission_id INTEGER,
            reason TEXT, message TEXT, resolved_at TEXT, created_at TEXT);
        CREATE TABLE student_progress (klasse TEXT, student_key TEXT, display_name TEXT,
            xp INTEGER, level INTEGER, streak INTEGER, updated_at TEXT);
        CREATE TABLE lesson_sessions (id INTEGER PRIMARY KEY, teacher_id INTEGER, class_id INTEGER,
            fach TEXT, lesson_date TEXT, period INTEGER, topic TEXT, closed_at TEXT, created_at TEXT);
        CREATE TABLE participation_events (id INTEGER PRIMARY KEY, session_id INTEGER, student_id INTEGER,
            weight INTEGER, kind TEXT, note TEXT, client_uid TEXT, created_at TEXT);
        CREATE TABLE substitute_plans (id INTEGER PRIMARY KEY, teacher_id INTEGER, class_id INTEGER,
            fach TEXT, lesson_date TEXT, period INTEGER, dauer INTEGER, hinweis TEXT, status TEXT,
            plan_json TEXT, access_token TEXT, released_by INTEGER, released_at TEXT, created_at TEXT);
        CREATE TABLE feedback_sessions (id INTEGER PRIMARY KEY, teacher_id INTEGER, klasse TEXT,
            fach TEXT, token TEXT, is_active INTEGER, expires_at TEXT, created_at TEXT);
        CREATE TABLE feedback_questions (id INTEGER PRIMARY KEY, session_id INTEGER, question_text TEXT,
            question_type TEXT, options TEXT, sort_order INTEGER);
        CREATE TABLE feedback_responses (id INTEGER PRIMARY KEY, session_id INTEGER, question_id INTEGER,
            score INTEGER, response_text TEXT, created_at TEXT);
        CREATE TABLE audit_log (id INTEGER PRIMARY KEY, teacher_id INTEGER, action TEXT, entity TEXT,
            entity_id INTEGER, details TEXT, created_at TEXT);
        CREATE TABLE ai_usage (id INTEGER PRIMARY KEY, feature TEXT, model TEXT, teacher_id INTEGER,
            assignment_id INTEGER, prompt_tokens INTEGER, completion_tokens INTEGER,
            total_tokens INTEGER, created_at TEXT);
        SQL;

    private string $verzeichnis = '';

    private function datenbank(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (explode(';', self::SCHEMA) as $anweisung) {
            if (trim($anweisung) !== '') {
                $db->exec($anweisung);
            }
        }

        return $db;
    }

    protected function setUp(): void
    {
        $this->verzeichnis = sys_get_temp_dir() . '/jahresabschluss-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->verzeichnis . '/*') ?: [] as $datei) {
            @unlink($datei);
        }
        @rmdir($this->verzeichnis);
    }

    public function testSchuljahrLaeuftVonAugustBisJuli(): void
    {
        self::assertSame(['2025-08-01', '2026-07-31'], Jahresabschluss::schuljahr(2025));
    }

    public function testJedeAbfrageLaeuftUndErzeugtEineDatei(): void
    {
        $abschluss = new Jahresabschluss($this->datenbank());
        $umfang = $abschluss->exportiere($this->verzeichnis, '2025-08-01', '2026-07-31');

        // Faellt eine Abfrage aus, fehlt ihr Eintrag - das ist der Punkt.
        self::assertCount(17, $umfang, 'Es müssen alle Auszüge laufen: ' . implode(', ', array_keys($umfang)));

        foreach (array_keys($umfang) as $name) {
            self::assertFileExists($this->verzeichnis . '/' . $name . '.csv');
        }
    }

    public function testDatenAusDemZeitraumLandenImArchiv(): void
    {
        $db = $this->datenbank();
        $db->exec("INSERT INTO classes (id, name) VALUES (1, '7a')");
        $db->exec("INSERT INTO students (id, class_id, display_name, student_key) VALUES (1, 1, 'Anna Müller', 'anna mueller')");
        $db->exec("INSERT INTO lesson_sessions (id, teacher_id, class_id, fach, lesson_date, period, topic)
                   VALUES (1, 9, 1, 'Mathematik', '2025-09-15', 3, 'Bruchrechnen')");
        $db->exec("INSERT INTO participation_events (id, session_id, student_id, weight, kind, note)
                   VALUES (1, 1, 1, 2, 'freiwillig', 'gute Erklärung')");

        $umfang = (new Jahresabschluss($db))->exportiere($this->verzeichnis, '2025-08-01', '2026-07-31');

        self::assertSame(1, $umfang['stunden']);
        self::assertSame(1, $umfang['beteiligung']);

        $csv = (string) file_get_contents($this->verzeichnis . '/beteiligung.csv');
        self::assertStringContainsString('Anna Müller', $csv, 'Der Name gehört ins Archiv – so entschieden.');
        self::assertStringContainsString('gute Erklärung', $csv);
    }

    public function testStundenAusserhalbDesZeitraumsBleibenDraussen(): void
    {
        $db = $this->datenbank();
        $db->exec("INSERT INTO classes (id, name) VALUES (1, '7a')");
        $db->exec("INSERT INTO lesson_sessions (id, teacher_id, class_id, fach, lesson_date, period)
                   VALUES (1, 9, 1, 'Mathematik', '2025-07-31', 3)");

        $umfang = (new Jahresabschluss($db))->exportiere($this->verzeichnis, '2025-08-01', '2026-07-31');

        self::assertSame(0, $umfang['stunden']);
    }

    public function testZugangstokenUndPasswoerterStehenNichtImArchiv(): void
    {
        $db = $this->datenbank();
        $db->exec("INSERT INTO classes (id, name) VALUES (1, '7a')");
        $db->exec("INSERT INTO teachers (id, kuerzel, name, passwort_hash) VALUES (9, 'MUE', 'A. Müller', 'HASH-GEHEIM')");
        $db->exec("INSERT INTO teacher_classes (teacher_id, class_id) VALUES (9, 1)");
        $db->exec("INSERT INTO homework_assignments (id, teacher_id, klasse, fach, title, token, created_at)
                   VALUES (1, 9, '7a', 'Mathe', 'Brüche', 'TOKEN-AUFGABE', '2025-09-01')");
        $db->exec("INSERT INTO homework_submissions (id, assignment_id, student_name, token, created_at)
                   VALUES (1, 1, 'Anna Müller', 'TOKEN-ABGABE', '2025-09-02')");
        $db->exec("INSERT INTO feedback_sessions (id, teacher_id, klasse, fach, token, created_at)
                   VALUES (1, 9, '7a', 'Mathe', 'TOKEN-UMFRAGE', '2025-09-03')");
        $db->exec("INSERT INTO substitute_plans (id, teacher_id, class_id, fach, lesson_date, access_token)
                   VALUES (1, 9, 1, 'Mathe', '2025-09-04', 'TOKEN-MAPPE')");

        (new Jahresabschluss($db))->exportiere($this->verzeichnis, '2025-08-01', '2026-07-31');

        $alles = '';
        foreach (glob($this->verzeichnis . '/*.csv') ?: [] as $datei) {
            $alles .= (string) file_get_contents($datei);
        }

        // Token sind Schluessel, keine Daten: wer das Archiv haette, koennte
        // sonst jede Vertretungsmappe oeffnen und jede Umfrage ausfuellen.
        foreach (['TOKEN-AUFGABE', 'TOKEN-ABGABE', 'TOKEN-UMFRAGE', 'TOKEN-MAPPE', 'HASH-GEHEIM'] as $geheim) {
            self::assertStringNotContainsString($geheim, $alles, $geheim . ' gehört nicht ins Archiv');
        }

        // Gegenprobe: die Zeilen selbst sind sehr wohl drin.
        self::assertStringContainsString('Brüche', $alles);
        self::assertStringContainsString('Anna Müller', $alles);
    }

    public function testCsvTraegtEinBomFuerExcel(): void
    {
        $db = $this->datenbank();
        $db->exec("INSERT INTO classes (id, name) VALUES (1, '7a')");

        (new Jahresabschluss($db))->exportiere($this->verzeichnis, '2025-08-01', '2026-07-31');

        self::assertStringStartsWith("\xEF\xBB\xBF", (string) file_get_contents($this->verzeichnis . '/klassen.csv'));
    }
}
