<?php

declare(strict_types=1);

namespace App\Homework;

use App\Ai\AIService;
use App\Ai\AIServiceException;
use App\Support\AuditLog;
use App\Support\UsageRecorder;
use PDO;

/**
 * Ablauf einer Hausaufgaben-Abgabe.
 *
 * Vorher steckte all das in student_homework.php zwischen Formularpruefung,
 * Datei-I/O, SQL, KI-Aufruf und Templaterendering - rund 200 Zeilen in einer
 * einzigen verschachtelten if-Kaskade.
 */
final class SubmissionService
{
    public function __construct(
        private PDO $conn,
        private HomeworkRepository $repository,
        private EvaluationQueue $queue,
        private Gamification $gamification
    ) {}

    /**
     * Nimmt eine Abgabe entgegen und stellt sie zur Auswertung ein.
     *
     * @param array<string,mixed> $assignment
     * @return array{ok:bool,error?:string,submission_id?:int,token?:string,attempt_no?:int}
     */
    public function submit(array $assignment, string $studentName, string $storedPath): array
    {
        $studentKey = Gamification::studentKey($studentName);

        if ($studentKey === '') {
            return ['ok' => false, 'error' => 'Bitte gib deinen Vor- und Nachnamen ein.'];
        }

        $assignmentId = (int)$assignment['id'];
        $bisher = $this->repository->attemptsByStudent($assignmentId, $studentKey);
        $maximum = max(1, (int)($assignment['max_submissions_per_student'] ?? 3));

        if ($bisher >= $maximum) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Du hast für diese Hausaufgabe bereits %d Mal abgegeben. Mehr Versuche sind nicht vorgesehen.',
                    $maximum
                ),
            ];
        }

        if ($bisher > 0 && (int)($assignment['allow_resubmission'] ?? 1) !== 1) {
            return ['ok' => false, 'error' => 'Für diese Hausaufgabe ist nur eine Abgabe vorgesehen.'];
        }

        $token = bin2hex(random_bytes(16));
        $pseudonym = 'Student_' . bin2hex(random_bytes(4));
        $versuch = $bisher + 1;

        $stmt = $this->conn->prepare('
            INSERT INTO homework_submissions
                (assignment_id, student_name, student_key, student_pseudonym, image_path, token, attempt_no, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $assignmentId,
            mb_substr($studentName, 0, 150),
            $studentKey,
            $pseudonym,
            $storedPath,
            $token,
            $versuch,
            'queued',
        ]);

        $submissionId = (int)$this->conn->lastInsertId();
        $this->queue->push($submissionId);

        return [
            'ok'            => true,
            'submission_id' => $submissionId,
            'token'         => $token,
            'attempt_no'    => $versuch,
        ];
    }

    /**
     * Wertet eine Abgabe aus und schreibt das Ergebnis fort.
     *
     * Wird vom Arbeitsprozess aufgerufen, nicht im Request der Schuelerin
     * oder des Schuelers.
     *
     * @throws AIServiceException
     */
    public function evaluate(int $submissionId): void
    {
        $stmt = $this->conn->prepare('
            SELECT s.*, a.id AS assignment_id, a.description, a.klasse, a.feedback_level, a.title,
                   a.context_image_path, a.context_file_uri, a.context_file_expires_at,
                   a.release_mode, a.teacher_id
            FROM homework_submissions s
            JOIN homework_assignments a ON a.id = s.assignment_id
            WHERE s.id = ?
        ');
        $stmt->execute([$submissionId]);
        $sub = $stmt->fetch();

        if (!$sub) {
            throw new AIServiceException('Einreichung nicht gefunden.');
        }

        $bildpfad = storage_resolve($sub['image_path']);
        if ($bildpfad === null) {
            throw new AIServiceException('Die Bilddatei der Einreichung fehlt.');
        }

        $ai = new AIService(new UsageRecorder($this->conn, (int)$sub['teacher_id'], (int)$sub['assignment_id']));
        $criteria = $this->repository->criteriaFor((int)$sub['assignment_id']);

        $eval = $ai->evaluateHomeworkImage(
            (string)$sub['description'],
            $bildpfad,
            (string)$sub['student_pseudonym'],
            storage_resolve($sub['context_image_path'] ?? null),
            (string)($sub['klasse'] ?? ''),
            (string)($sub['feedback_level'] ?? 'appropriate'),
            $criteria,
            $this->contextFileFor($sub, $ai)
        );

        // Im Review-Modus sieht die Klasse das Ergebnis erst nach Freigabe.
        $freigegeben = ($sub['release_mode'] ?? 'immediate') !== 'review';

        $this->conn->prepare('DELETE FROM homework_evaluations WHERE submission_id = ?')->execute([$submissionId]);
        $this->conn->prepare('
            INSERT INTO homework_evaluations
                (submission_id, student_feedback, teacher_notes, score, error_markers, criteria_scores, review_status, released_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $submissionId,
            $eval['student_feedback'] !== '' ? $eval['student_feedback'] : 'Kein Feedback generiert.',
            $eval['teacher_notes'] !== '' ? $eval['teacher_notes'] : 'Keine Notizen.',
            $eval['score'],
            $eval['errors'] !== [] ? json_encode($eval['errors'], JSON_UNESCAPED_UNICODE) : null,
            $eval['criteria'] !== [] ? json_encode($eval['criteria'], JSON_UNESCAPED_UNICODE) : null,
            $freigegeben ? 'released' : 'draft',
            $freigegeben ? date('Y-m-d H:i:s') : null,
        ]);

        $this->conn->prepare("UPDATE homework_submissions SET status = 'evaluated' WHERE id = ?")
            ->execute([$submissionId]);

        if ($freigegeben) {
            $this->gamification->award(
                $submissionId,
                (string)($sub['klasse'] ?? ''),
                (string)$sub['student_name'],
                $eval['score']
            );
        }
    }

    /**
     * Gibt eine Auswertung frei.
     */
    public function release(int $submissionId, int $teacherId): bool
    {
        $stmt = $this->conn->prepare('
            SELECT s.id, s.student_name, a.klasse, e.score, e.review_status
            FROM homework_submissions s
            JOIN homework_assignments a ON a.id = s.assignment_id
            JOIN homework_evaluations e ON e.submission_id = s.id
            WHERE s.id = ? AND a.teacher_id = ?
        ');
        $stmt->execute([$submissionId, $teacherId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        $this->conn->prepare("
            UPDATE homework_evaluations
            SET review_status = 'released', released_at = NOW(), released_by = ?
            WHERE submission_id = ?
        ")->execute([$teacherId, $submissionId]);

        $this->gamification->award(
            $submissionId,
            (string)($row['klasse'] ?? ''),
            (string)$row['student_name'],
            $row['score'] !== null ? (int)$row['score'] : null
        );

        (new AuditLog($this->conn))->record(
            $teacherId,
            'evaluation.released',
            'submission',
            $submissionId,
            'Auswertung freigegeben'
        );

        return true;
    }

    /**
     * Gibt alle offenen Auswertungen einer Hausaufgabe frei.
     */
    public function releaseAll(int $assignmentId, int $teacherId): int
    {
        $stmt = $this->conn->prepare("
            SELECT s.id
            FROM homework_submissions s
            JOIN homework_assignments a ON a.id = s.assignment_id
            JOIN homework_evaluations e ON e.submission_id = s.id
            WHERE a.id = ? AND a.teacher_id = ? AND e.review_status = 'draft'
        ");
        $stmt->execute([$assignmentId, $teacherId]);

        $anzahl = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $anzahl += $this->release((int)$id, $teacherId) ? 1 : 0;
        }

        return $anzahl;
    }

    /**
     * Kontextdokument moeglichst nur einmal hochladen statt bei jeder Abgabe.
     *
     * @param array<string,mixed> $sub
     * @return array{uri:string,mime:string}|null
     */
    private function contextFileFor(array $sub, AIService $ai): ?array
    {
        $pfad = storage_resolve($sub['context_image_path'] ?? null);
        if ($pfad === null) {
            return null;
        }

        $uri = $sub['context_file_uri'] ?? null;
        $laeuftAb = $sub['context_file_expires_at'] ?? null;

        if (is_string($uri) && $uri !== '' && is_string($laeuftAb) && strtotime($laeuftAb) > time()) {
            return ['uri' => $uri, 'mime' => (string)(@mime_content_type($pfad) ?: 'application/pdf')];
        }

        $hochgeladen = $ai->uploadContextFile($pfad);
        if ($hochgeladen === null) {
            return null; // Faellt auf inline_data zurueck.
        }

        $this->conn->prepare('
            UPDATE homework_assignments SET context_file_uri = ?, context_file_expires_at = ? WHERE id = ?
        ')->execute([
            $hochgeladen['uri'],
            date('Y-m-d H:i:s', strtotime($hochgeladen['expires_at']) ?: time() + 47 * 3600),
            (int)$sub['assignment_id'],
        ]);

        return ['uri' => $hochgeladen['uri'], 'mime' => $hochgeladen['mime']];
    }
}
