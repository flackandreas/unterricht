<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Loeschkonzept.
 *
 * Hausaufgabenfotos und Klarnamen blieben bisher unbegrenzt liegen. Fuer ein
 * schulisches System gehoert eine Aufbewahrungsfrist dazu: das Foto ist nach
 * Ablauf entbehrlich, das Feedback darf bleiben.
 */
final class Retention
{
    public function __construct(private PDO $conn) {}

    /**
     * Loescht abgelaufene Bilddateien.
     *
     * Die Frist steht je Hausaufgabe in retention_days; 0 schaltet die
     * automatische Loeschung fuer diese Aufgabe ab.
     *
     * @return array{geprueft:int,geloescht:int}
     */
    public function purgeExpiredImages(bool $apply = true): array
    {
        $stmt = $this->conn->query("
            SELECT s.id, s.image_path, s.created_at, a.retention_days, a.title
            FROM homework_submissions s
            JOIN homework_assignments a ON a.id = s.assignment_id
            WHERE s.image_deleted_at IS NULL
              AND a.retention_days > 0
              AND s.created_at < NOW() - INTERVAL a.retention_days DAY
        ");

        $geprueft = 0;
        $geloescht = 0;
        $markieren = $this->conn->prepare('UPDATE homework_submissions SET image_deleted_at = NOW() WHERE id = ?');

        foreach ($stmt->fetchAll() as $row) {
            $geprueft++;

            if (!$apply) {
                continue;
            }

            storage_delete($row['image_path']);
            $markieren->execute([(int)$row['id']]);
            $geloescht++;
        }

        return ['geprueft' => $geprueft, 'geloescht' => $geloescht];
    }

    /**
     * Raeumt abgearbeitete Warteschlangen- und Zaehlereintraege ab.
     */
    public function purgeTransientRows(): void
    {
        try {
            $this->conn->exec("DELETE FROM evaluation_jobs WHERE status = 'done' AND finished_at < NOW() - INTERVAL 7 DAY");
            $this->conn->exec('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY');
            $this->conn->exec('DELETE FROM ai_usage WHERE created_at < NOW() - INTERVAL 400 DAY');
        } catch (\Throwable $e) {
            error_log('Aufräumen fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
