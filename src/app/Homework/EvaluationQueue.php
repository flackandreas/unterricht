<?php

declare(strict_types=1);

namespace App\Homework;

use PDO;

/**
 * Warteschlange fuer die KI-Auswertung.
 *
 * Die Auswertung lief bisher synchron im Request der Schuelerin oder des
 * Schuelers: 10 bis 60 Sekunden Spinner mit "Bitte schliesse diese Seite
 * nicht". Brach die Verbindung ab, blieb die Abgabe dauerhaft unausgewertet,
 * weil es keinen zweiten Versuch gab. Jetzt wird die Abgabe sofort
 * quittiert und ein Arbeitsprozess erledigt die Auswertung.
 */
final class EvaluationQueue
{
    /** Nach so vielen Fehlversuchen wird nicht mehr weiterprobiert. */
    public const MAX_ATTEMPTS = 3;

    /** Ein Auftrag, der so lange laeuft, gilt als abgestuerzt. */
    private const STALE_AFTER_SECONDS = 600;

    public function __construct(private PDO $conn) {}

    /**
     * Stellt eine Abgabe zur Auswertung ein.
     */
    public function push(int $submissionId): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO evaluation_jobs (submission_id, status, available_at)
            VALUES (?, 'queued', NOW())
            ON DUPLICATE KEY UPDATE
                status = 'queued', attempts = 0, last_error = NULL,
                available_at = NOW(), started_at = NULL, finished_at = NULL
        ");
        $stmt->execute([$submissionId]);

        $this->conn->prepare("UPDATE homework_submissions SET status = 'queued' WHERE id = ?")
            ->execute([$submissionId]);
    }

    /**
     * Holt den naechsten faelligen Auftrag und markiert ihn als laufend.
     *
     * Die Sperre verhindert, dass zwei Arbeitsprozesse denselben Auftrag
     * greifen: nur wer die Zeile tatsaechlich veraendert hat, bekommt sie.
     *
     * @return array<string,mixed>|null
     */
    public function reserve(): ?array
    {
        $this->requeueStale();

        $kandidat = $this->conn->query("
            SELECT id FROM evaluation_jobs
            WHERE status = 'queued' AND available_at <= NOW()
            ORDER BY available_at ASC, id ASC
            LIMIT 1
        ")->fetchColumn();

        if ($kandidat === false) {
            return null;
        }

        $claim = $this->conn->prepare("
            UPDATE evaluation_jobs
            SET status = 'running', started_at = NOW(), attempts = attempts + 1
            WHERE id = ? AND status = 'queued'
        ");
        $claim->execute([(int)$kandidat]);

        if ($claim->rowCount() === 0) {
            return null; // Ein anderer Prozess war schneller.
        }

        $stmt = $this->conn->prepare('
            SELECT j.*, s.assignment_id, s.image_path, s.student_pseudonym, s.student_name
            FROM evaluation_jobs j
            JOIN homework_submissions s ON s.id = j.submission_id
            WHERE j.id = ?
        ');
        $stmt->execute([(int)$kandidat]);

        return $stmt->fetch() ?: null;
    }

    public function markDone(int $jobId): void
    {
        $this->conn->prepare("
            UPDATE evaluation_jobs SET status = 'done', finished_at = NOW(), last_error = NULL WHERE id = ?
        ")->execute([$jobId]);
    }

    /**
     * Vermerkt einen Fehlschlag und plant je nach Versuchszahl neu ein.
     */
    public function markFailed(int $jobId, int $submissionId, string $error, int $attempts): void
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->conn->prepare("
                UPDATE evaluation_jobs
                SET status = 'failed', finished_at = NOW(), last_error = ?
                WHERE id = ?
            ")->execute([mb_substr($error, 0, 500), $jobId]);

            $this->conn->prepare("UPDATE homework_submissions SET status = 'failed' WHERE id = ?")
                ->execute([$submissionId]);

            return;
        }

        // Wachsender Abstand: 1, 4, 9 Minuten.
        $verzoegerung = $attempts ** 2 * 60;

        $this->conn->prepare("
            UPDATE evaluation_jobs
            SET status = 'queued', last_error = ?, available_at = NOW() + INTERVAL ? SECOND, started_at = NULL
            WHERE id = ?
        ")->execute([mb_substr($error, 0, 500), $verzoegerung, $jobId]);
    }

    /**
     * Setzt haengengebliebene Auftraege zurueck - etwa nach einem Absturz
     * des Arbeitsprozesses.
     */
    public function requeueStale(): int
    {
        $stmt = $this->conn->prepare("
            UPDATE evaluation_jobs
            SET status = 'queued', started_at = NULL
            WHERE status = 'running' AND started_at < NOW() - INTERVAL ? SECOND
        ");
        $stmt->execute([self::STALE_AFTER_SECONDS]);

        return $stmt->rowCount();
    }

    /**
     * Zustand einer Abgabe fuer die Statusabfrage aus dem Browser.
     *
     * @return array{status:string,attempts:int}|null
     */
    public function statusForSubmission(int $submissionId): ?array
    {
        $stmt = $this->conn->prepare('SELECT status, attempts FROM evaluation_jobs WHERE submission_id = ?');
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch();

        return $row ? ['status' => (string)$row['status'], 'attempts' => (int)$row['attempts']] : null;
    }

    /**
     * @return array<string,int>
     */
    public function counts(): array
    {
        $rows = $this->conn->query('SELECT status, COUNT(*) AS n FROM evaluation_jobs GROUP BY status')->fetchAll();
        $counts = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $counts[(string)$row['status']] = (int)$row['n'];
        }

        return $counts;
    }
}
