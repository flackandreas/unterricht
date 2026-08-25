<?php

declare(strict_types=1);

namespace App\Substitute;

use PDO;

/**
 * Warteschlange der Vertretungsstunden.
 *
 * Dieselbe Vergabelogik wie EvaluationQueue - Kandidat lesen, per UPDATE
 * beanspruchen, nur wer die Zeile tatsaechlich veraendert hat, bekommt sie -
 * nur ohne eigene Auftragstabelle: der Plan ist der Auftrag.
 *
 * Der Aufruf dauert je nach Umfang zwanzig Sekunden bis zwei Minuten. Im
 * Request einer Lehrkraft, die um Viertel vor acht krank anruft, hat er
 * nichts verloren.
 */
final class PlanQueue
{
    /** Nach so vielen Fehlversuchen wird nicht mehr weiterprobiert. */
    public const MAX_ATTEMPTS = 3;

    /** Ein Auftrag, der so lange laeuft, gilt als abgestuerzt. */
    private const STALE_AFTER_SECONDS = 600;

    public function __construct(private PDO $conn) {}

    /**
     * Holt den naechsten faelligen Auftrag und markiert ihn als laufend.
     *
     * @return array<string,mixed>|null
     */
    public function reserve(): ?array
    {
        $this->requeueStale();

        $kandidat = $this->conn->query("
            SELECT id FROM substitute_plans
            WHERE status = 'queued' AND available_at <= NOW()
            ORDER BY available_at ASC, id ASC
            LIMIT 1
        ")->fetchColumn();

        if ($kandidat === false) {
            return null;
        }

        $claim = $this->conn->prepare("
            UPDATE substitute_plans
            SET status = 'running', started_at = NOW(), attempts = attempts + 1
            WHERE id = ? AND status = 'queued'
        ");
        $claim->execute([(int)$kandidat]);

        if ($claim->rowCount() === 0) {
            return null; // Ein anderer Prozess war schneller.
        }

        $stmt = $this->conn->prepare('
            SELECT p.*, c.name AS klasse
            FROM substitute_plans p
            JOIN classes c ON c.id = p.class_id
            WHERE p.id = ?
        ');
        $stmt->execute([(int)$kandidat]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array<string,mixed> $plan
     */
    public function markDraft(int $planId, array $plan): void
    {
        $this->conn->prepare("
            UPDATE substitute_plans
            SET status = 'draft', plan_json = ?, last_error = NULL
            WHERE id = ?
        ")->execute([json_encode($plan, JSON_UNESCAPED_UNICODE), $planId]);
    }

    /**
     * Vermerkt einen Fehlschlag und plant je nach Versuchszahl neu ein.
     */
    public function markFailed(int $planId, string $error, int $attempts): void
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->conn->prepare("
                UPDATE substitute_plans SET status = 'failed', last_error = ? WHERE id = ?
            ")->execute([mb_substr($error, 0, 500), $planId]);

            return;
        }

        // Wachsender Abstand: 1, 4, 9 Minuten.
        $verzoegerung = $attempts ** 2 * 60;

        $this->conn->prepare("
            UPDATE substitute_plans
            SET status = 'queued', last_error = ?, available_at = NOW() + INTERVAL ? SECOND, started_at = NULL
            WHERE id = ?
        ")->execute([mb_substr($error, 0, 500), $verzoegerung, $planId]);
    }

    public function requeueStale(): int
    {
        $stmt = $this->conn->prepare("
            UPDATE substitute_plans
            SET status = 'queued', started_at = NULL
            WHERE status = 'running' AND started_at < NOW() - INTERVAL ? SECOND
        ");
        $stmt->execute([self::STALE_AFTER_SECONDS]);

        return $stmt->rowCount();
    }
}
