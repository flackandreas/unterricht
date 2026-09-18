<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Jahresabschluss: archivieren, dann das Loeschkonzept anstossen.
 *
 * Vorher tat admin_archive.php beides halb. Der Export umfasste genau eine
 * von 23 Tabellen - feedback_sessions, und davon nicht einmal die Fragen und
 * Antworten, die er anschliessend per Cascade mitloeschte. Gleichzeitig lief
 * mit bin/retention.php ein zweiter Loeschweg nach ganz anderer Logik, der
 * vom ersten nichts wusste.
 *
 * Deshalb hier die Trennung:
 *
 *   Archivieren  - vollstaendig, in dieser Klasse.
 *   Loeschen     - gar nicht. Das entscheiden die eingestellten Fristen, und
 *                  die setzt Retention um. Der Jahresabschluss stoesst sie an,
 *                  statt eine zweite Frist zu erfinden. PARTICIPATION_-
 *                  RETENTION_DAYS umspannt bewusst zwei Schuljahre, weil die
 *                  muendliche Note innerhalb der Widerspruchsfrist begruendbar
 *                  bleiben muss - ein "alles aus Schuljahr X weg" liefe dem
 *                  zuwider.
 *
 * Das Archiv enthaelt Namen von Minderjaehrigen, ihre Hausaufgaben samt
 * Korrektur und die Beteiligungsbelege. Das ist so gewollt und gehoert ins
 * Verzeichnis der Verarbeitungstaetigkeiten. Zwei Dinge sind trotzdem
 * ausgenommen:
 *
 *   - Zugangstoken. Sie sind keine Daten, sondern Schluessel: wer das Archiv
 *     haette, koennte sonst jede Vertretungsmappe oeffnen und jede Umfrage
 *     ausfuellen.
 *   - Passwort-Hashes. Die Kontenliste fuehrt das Portal.
 *
 * Beides ist der Grund, warum unten jede Spalte einzeln steht statt eines
 * SELECT *: eine neue Spalte landet dann nicht versehentlich im Archiv.
 */
final class Jahresabschluss
{
    /**
     * Was ins Archiv geht.
     *
     * Je Eintrag: Dateiname => [SQL, Zeitspalte fuer den Zeitraum].
     * Ist die Zeitspalte null, geht die Tabelle vollstaendig mit - eine
     * Klassenliste ohne Jahresbezug taugt sonst nicht als Beleg.
     *
     * Nicht dabei: rate_limits, evaluation_jobs und migration_log. Das ist
     * Betriebszustand, kein Beleg.
     *
     * @var array<string,array{0:string,1:?string}>
     */
    private const AUSZUEGE = [
        'klassen' => ['SELECT id, name, created_at FROM classes', null],

        'klassenlisten' => [
            'SELECT s.id, c.name AS klasse, s.display_name, s.sort_order, s.archived_at, s.created_at
               FROM students s JOIN classes c ON c.id = s.class_id',
            null,
        ],

        'klassenzuordnung' => [
            'SELECT tc.teacher_id, t.kuerzel, t.name, c.name AS klasse
               FROM teacher_classes tc
               JOIN classes c ON c.id = tc.class_id
               LEFT JOIN teachers t ON t.id = tc.teacher_id',
            null,
        ],

        'hausaufgaben' => [
            'SELECT a.id, a.teacher_id, a.klasse, a.fach, a.title, a.description,
                    a.expected_submissions, a.quest_reward, a.feedback_level, a.release_mode,
                    a.max_submissions_per_student, a.allow_resubmission, a.retention_days,
                    a.due_date, a.summary_common_errors, a.summary_solution_approach, a.created_at
               FROM homework_assignments a',
            'a.created_at',
        ],

        'hausaufgaben_kriterien' => [
            'SELECT k.id, k.assignment_id, k.label, k.description, k.max_points
               FROM homework_criteria k JOIN homework_assignments a ON a.id = k.assignment_id',
            'a.created_at',
        ],

        'abgaben' => [
            'SELECT s.id, s.assignment_id, s.student_name, s.student_key, s.student_pseudonym,
                    s.attempt_no, s.status, s.image_path, s.image_deleted_at, s.created_at
               FROM homework_submissions s',
            's.created_at',
        ],

        'auswertungen' => [
            'SELECT e.submission_id, e.score, e.student_feedback, e.teacher_notes, e.criteria_scores,
                    e.review_status, e.released_at, e.released_by, e.edited_by_teacher
               FROM homework_evaluations e JOIN homework_submissions s ON s.id = e.submission_id',
            's.created_at',
        ],

        'rueckmeldungen_zum_feedback' => [
            'SELECT r.id, r.submission_id, r.reason, r.message, r.resolved_at, r.created_at
               FROM submission_feedback_reports r',
            'r.created_at',
        ],

        'lernfortschritt' => [
            'SELECT klasse, student_key, display_name, xp, level, streak, updated_at FROM student_progress',
            null,
        ],

        'stunden' => [
            'SELECT s.id, s.teacher_id, c.name AS klasse, s.fach, s.lesson_date, s.period,
                    s.topic, s.closed_at, s.created_at
               FROM lesson_sessions s JOIN classes c ON c.id = s.class_id',
            's.lesson_date',
        ],

        'beteiligung' => [
            'SELECT e.id, e.session_id, e.student_id, st.display_name, e.weight, e.kind, e.note, e.created_at
               FROM participation_events e
               JOIN lesson_sessions ls ON ls.id = e.session_id
               JOIN students st ON st.id = e.student_id',
            'ls.lesson_date',
        ],

        'vertretungsstunden' => [
            'SELECT p.id, p.teacher_id, c.name AS klasse, p.fach, p.lesson_date, p.period, p.dauer,
                    p.hinweis, p.status, p.plan_json, p.released_by, p.released_at, p.created_at
               FROM substitute_plans p JOIN classes c ON c.id = p.class_id',
            'p.lesson_date',
        ],

        'feedback_sitzungen' => [
            'SELECT id, teacher_id, klasse, fach, is_active, expires_at, created_at FROM feedback_sessions',
            'created_at',
        ],

        'feedback_fragen' => [
            'SELECT q.id, q.session_id, q.question_text, q.question_type, q.options, q.sort_order
               FROM feedback_questions q JOIN feedback_sessions s ON s.id = q.session_id',
            's.created_at',
        ],

        'feedback_antworten' => [
            'SELECT r.id, r.session_id, r.question_id, r.score, r.response_text, r.created_at
               FROM feedback_responses r JOIN feedback_sessions s ON s.id = r.session_id',
            's.created_at',
        ],

        'protokoll' => [
            'SELECT id, teacher_id, action, entity, entity_id, details, created_at FROM audit_log',
            'created_at',
        ],

        'ki_verbrauch' => [
            'SELECT id, feature, model, teacher_id, assignment_id, prompt_tokens, completion_tokens,
                    total_tokens, created_at
               FROM ai_usage',
            'created_at',
        ],
    ];

    public function __construct(private PDO $conn) {}

    /**
     * Der uebliche Zeitraum eines Schuljahres: 1. August bis 31. Juli.
     *
     * Ein Kalenderjahr waere hier falsch - es schneidet mitten durch das
     * Schuljahr, und ein Abschluss im Dezember archiviert eine halbe Klasse.
     *
     * @return array{0:string,1:string}
     */
    public static function schuljahr(int $beginnjahr): array
    {
        return [
            sprintf('%04d-08-01', $beginnjahr),
            sprintf('%04d-07-31', $beginnjahr + 1),
        ];
    }

    /**
     * Schreibt je Auszug eine CSV-Datei in das Verzeichnis.
     *
     * @return array<string,int> Dateiname => Anzahl Zeilen
     */
    public function exportiere(string $verzeichnis, string $von, string $bis): array
    {
        if (!is_dir($verzeichnis)) {
            mkdir($verzeichnis, 0750, true);
        }

        $ergebnis = [];

        foreach (self::AUSZUEGE as $name => [$sql, $zeitspalte]) {
            $werte = [];

            if ($zeitspalte !== null) {
                $sql .= (stripos($sql, ' WHERE ') === false ? ' WHERE ' : ' AND ')
                    . 'DATE(' . $zeitspalte . ') BETWEEN ? AND ?';
                $werte = [$von, $bis];
            }

            try {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($werte);
                $zeilen = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                // Eine Tabelle, die es in dieser Installation nicht gibt, darf
                // den ganzen Abschluss nicht verhindern.
                error_log('Jahresabschluss: ' . $name . ' nicht lesbar: ' . $e->getMessage());
                continue;
            }

            $ergebnis[$name] = count($zeilen);
            $this->schreibeCsv($verzeichnis . '/' . $name . '.csv', $zeilen);
        }

        return $ergebnis;
    }

    /**
     * @param list<array<string,mixed>> $zeilen
     */
    private function schreibeCsv(string $datei, array $zeilen): void
    {
        $kanal = fopen($datei, 'w');
        if ($kanal === false) {
            return;
        }

        // Byte Order Mark, damit Excel die Umlaute erkennt.
        fwrite($kanal, "\xEF\xBB\xBF");

        if ($zeilen === []) {
            fclose($kanal);

            return;
        }

        fputcsv($kanal, array_keys($zeilen[0]), ';', '"', '');

        foreach ($zeilen as $zeile) {
            fputcsv($kanal, array_map(
                static fn ($wert): string => $wert === null ? '' : (string) $wert,
                $zeile
            ), ';', '"', '');
        }

        fclose($kanal);
    }
}
