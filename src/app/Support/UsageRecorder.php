<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Haelt den Tokenverbrauch der KI-Aufrufe fest.
 */
final class UsageRecorder
{
    public function __construct(
        private PDO $conn,
        private ?int $teacherId = null,
        private ?int $assignmentId = null
    ) {}

    /**
     * Als Callback an den AIService uebergebbar.
     */
    /**
     * @param array<string,mixed> $usage
     */
    public function __invoke(string $feature, array $usage): void
    {
        try {
            $this->conn->prepare('
                INSERT INTO ai_usage (feature, model, teacher_id, assignment_id, prompt_tokens, completion_tokens, total_tokens)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                mb_substr($feature, 0, 40),
                mb_substr((string)($usage['model'] ?? 'unbekannt'), 0, 80),
                $this->teacherId,
                $this->assignmentId,
                (int)($usage['prompt_tokens'] ?? 0),
                (int)($usage['completion_tokens'] ?? 0),
                (int)($usage['total_tokens'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            error_log('Verbrauch konnte nicht erfasst werden: ' . $e->getMessage());
        }
    }

    /**
     * Verbrauch der letzten Tage, fuer die Systemuebersicht.
     *
     * @return array<string,mixed>
     */
    public static function summary(PDO $conn, int $days = 30): array
    {
        $days = max(1, min(400, $days));

        $gesamt = $conn->query("
            SELECT COUNT(*) AS calls, COALESCE(SUM(total_tokens),0) AS tokens
            FROM ai_usage WHERE created_at >= NOW() - INTERVAL {$days} DAY
        ")->fetch() ?: ['calls' => 0, 'tokens' => 0];

        $proFeature = $conn->query("
            SELECT feature, COUNT(*) AS calls, COALESCE(SUM(total_tokens),0) AS tokens
            FROM ai_usage WHERE created_at >= NOW() - INTERVAL {$days} DAY
            GROUP BY feature ORDER BY tokens DESC
        ")->fetchAll();

        return [
            'days'     => $days,
            'calls'    => (int)$gesamt['calls'],
            'tokens'   => (int)$gesamt['tokens'],
            'features' => $proFeature,
        ];
    }
}
