<?php

declare(strict_types=1);

namespace App\Homework;

use PDO;

/**
 * Datenbankzugriffe rund um Hausaufgaben.
 *
 * Die Uebersichtsseite lud vorher pro Hausaufgabe alle Einreichungen samt
 * Feedbacktexten - nur um sie zu zaehlen. Bei 40 Aufgaben waren das 41
 * Abfragen und mehrere Megabyte im Speicher. Die Kennzahlen kommen jetzt aus
 * einer einzigen Abfrage mit GROUP BY.
 */
final class HomeworkRepository
{
    public function __construct(private PDO $conn) {}

    /**
     * Hausaufgaben einer Lehrkraft inklusive Kennzahlen.
     *
     * @return list<array<string,mixed>>
     */
    public function assignmentsForTeacher(int $teacherId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $stmt = $this->conn->prepare("
            SELECT a.*,
                   COALESCE(k.submission_count, 0)   AS submission_count,
                   COALESCE(k.student_count, 0)      AS student_count,
                   COALESCE(k.evaluated_count, 0)    AS evaluated_count,
                   COALESCE(k.pending_count, 0)      AS pending_count,
                   COALESCE(k.draft_count, 0)        AS draft_count,
                   k.avg_score,
                   k.last_submission_at
            FROM homework_assignments a
            LEFT JOIN (
                SELECT s.assignment_id,
                       COUNT(*)                                        AS submission_count,
                       COUNT(DISTINCT s.student_pseudonym)             AS student_count,
                       SUM(s.status = 'evaluated')                     AS evaluated_count,
                       SUM(s.status IN ('pending','queued'))           AS pending_count,
                       SUM(e.review_status = 'draft')                  AS draft_count,
                       ROUND(AVG(e.score), 1)                          AS avg_score,
                       MAX(s.created_at)                               AS last_submission_at
                FROM homework_submissions s
                LEFT JOIN homework_evaluations e ON e.submission_id = s.id
                GROUP BY s.assignment_id
            ) k ON k.assignment_id = a.id
            WHERE a.teacher_id = ?
            ORDER BY a.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute([$teacherId]);

        return $stmt->fetchAll();
    }

    public function countAssignmentsForTeacher(int $teacherId): int
    {
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM homework_assignments WHERE teacher_id = ?');
        $stmt->execute([$teacherId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function assignmentForTeacher(int $assignmentId, int $teacherId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM homework_assignments WHERE id = ? AND teacher_id = ? LIMIT 1');
        $stmt->execute([$assignmentId, $teacherId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function assignmentByToken(string $token): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM homework_assignments WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function submissionsForAssignment(int $assignmentId): array
    {
        $stmt = $this->conn->prepare("
            SELECT s.*,
                   e.teacher_notes, e.student_feedback, e.score, e.error_markers,
                   e.review_status, e.released_at, e.edited_by_teacher, e.criteria_scores,
                   j.status AS job_status, j.last_error,
                   (SELECT COUNT(*) FROM submission_feedback_reports r
                     WHERE r.submission_id = s.id AND r.resolved_at IS NULL) AS open_reports
            FROM homework_submissions s
            LEFT JOIN homework_evaluations e ON e.submission_id = s.id
            LEFT JOIN evaluation_jobs j ON j.submission_id = s.id
            WHERE s.assignment_id = ?
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$assignmentId]);

        return $stmt->fetchAll();
    }

    /**
     * Wie viele Auswertungen warten auf Freigabe durch diese Lehrkraft?
     */
    public function pendingReviewCount(int $teacherId): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM homework_evaluations e
            JOIN homework_submissions s ON s.id = e.submission_id
            JOIN homework_assignments a ON a.id = s.assignment_id
            WHERE a.teacher_id = ? AND e.review_status = 'draft'
        ");
        $stmt->execute([$teacherId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Auswertungen, die auf Freigabe warten - die Review-Warteschlange.
     *
     * @return list<array<string,mixed>>
     */
    public function reviewQueue(int $teacherId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $stmt = $this->conn->prepare("
            SELECT s.id AS submission_id, s.student_name, s.created_at, s.attempt_no,
                   e.score, e.student_feedback, e.teacher_notes,
                   a.id AS assignment_id, a.title, a.klasse, a.fach
            FROM homework_evaluations e
            JOIN homework_submissions s ON s.id = e.submission_id
            JOIN homework_assignments a ON a.id = s.assignment_id
            WHERE a.teacher_id = ? AND e.review_status = 'draft'
            ORDER BY s.created_at ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$teacherId]);

        return $stmt->fetchAll();
    }

    /**
     * Bewertungsraster einer Hausaufgabe.
     *
     * @return list<array{id:int,label:string,description:string,max_points:int}>
     */
    public function criteriaFor(int $assignmentId): array
    {
        $stmt = $this->conn->prepare('
            SELECT id, label, description, max_points
            FROM homework_criteria
            WHERE assignment_id = ?
            ORDER BY sort_order ASC, id ASC
        ');
        $stmt->execute([$assignmentId]);

        return array_map(
            static fn(array $r): array => [
                'id'          => (int)$r['id'],
                'label'       => (string)$r['label'],
                'description' => (string)($r['description'] ?? ''),
                'max_points'  => (int)$r['max_points'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * @param list<array{label:string,description:string,max_points:int}> $criteria
     */
    public function replaceCriteria(int $assignmentId, array $criteria): void
    {
        $this->conn->prepare('DELETE FROM homework_criteria WHERE assignment_id = ?')->execute([$assignmentId]);

        if ($criteria === []) {
            return;
        }

        $stmt = $this->conn->prepare('
            INSERT INTO homework_criteria (assignment_id, label, description, max_points, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ');

        foreach (array_values($criteria) as $i => $k) {
            $stmt->execute([
                $assignmentId,
                mb_substr($k['label'], 0, 190),
                mb_substr($k['description'], 0, 500),
                max(1, min(100, $k['max_points'])),
                $i,
            ]);
        }
    }

    /**
     * Anzahl abgebender Schuelerinnen und Schueler - fuer die Klassen-Quest.
     */
    /**
     * Ausgewertete Hausaufgaben einer Klasse in einem Fach.
     *
     * Grundlage fuer die Vertretungsstunde. Der Themenverlauf sagt nur, *was*
     * behandelt wurde; hier steht, *woran die Klasse tatsaechlich gescheitert
     * ist* - summary_common_errors entsteht aus den Korrekturen der ganzen
     * Klasse. Damit zielt die Vertretungsstunde auf die belegten Luecken
     * statt auf das Thema im Allgemeinen.
     *
     * homework_assignments fuehrt die Klasse als Namen, nicht als
     * Fremdschluessel - die Verbindung laeuft deshalb ueber classes.name.
     *
     * Es verlaesst nichts Personenbezogenes die Anwendung: die
     * Zusammenfassung ist ueber die ganze Klasse aggregiert, die
     * Aufgabenstellung stammt von der Lehrkraft.
     *
     * @return list<array{titel:string,aufgabe:string,fehler:string}>
     */
    public function summariesForClassSubject(int $classId, string $fach, int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));

        $stmt = $this->conn->prepare("
            SELECT a.title, a.description, a.summary_common_errors
            FROM homework_assignments a
            JOIN classes c ON c.name = a.klasse
            WHERE c.id = ?
              AND a.fach = ?
              AND a.summary_common_errors IS NOT NULL
              AND a.summary_common_errors <> ''
            ORDER BY a.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$classId, $fach]);

        $zeilen = [];
        foreach ($stmt->fetchAll() as $r) {
            $zeilen[] = [
                'titel'   => mb_substr((string)$r['title'], 0, 150),
                'aufgabe' => mb_substr(trim((string)$r['description']), 0, 400),
                'fehler'  => mb_substr(trim((string)$r['summary_common_errors']), 0, 400),
            ];
        }

        return array_reverse($zeilen);
    }

    public function distinctStudentCount(int $assignmentId): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(DISTINCT student_pseudonym) FROM homework_submissions WHERE assignment_id = ?'
        );
        $stmt->execute([$assignmentId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Wie oft hat diese Person zu dieser Aufgabe schon abgegeben?
     */
    public function attemptsByStudent(int $assignmentId, string $studentKey): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM homework_submissions WHERE assignment_id = ? AND student_key = ?'
        );
        $stmt->execute([$assignmentId, $studentKey]);

        return (int)$stmt->fetchColumn();
    }
}
