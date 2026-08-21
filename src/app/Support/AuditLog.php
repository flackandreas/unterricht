<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Nachvollziehbarkeit: wer hat wann welche Bewertung geaendert oder
 * freigegeben. Bei benoteten Arbeiten ist das kein Luxus.
 */
final class AuditLog
{
    public function __construct(private PDO $conn) {}

    public function record(?int $teacherId, string $action, string $entity, ?int $entityId, string $details = ''): void
    {
        try {
            $this->conn->prepare('
                INSERT INTO audit_log (teacher_id, action, entity, entity_id, details)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([
                $teacherId,
                mb_substr($action, 0, 60),
                mb_substr($entity, 0, 40),
                $entityId,
                mb_substr($details, 0, 1000),
            ]);
        } catch (\Throwable $e) {
            // Das Protokoll darf den eigentlichen Vorgang nie blockieren.
            error_log('Audit-Eintrag fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entity, int $entityId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $stmt = $this->conn->prepare("
            SELECT l.*, t.kuerzel, t.name AS teacher_name
            FROM audit_log l
            LEFT JOIN teachers t ON t.id = l.teacher_id
            WHERE l.entity = ? AND l.entity_id = ?
            ORDER BY l.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$entity, $entityId]);

        return $stmt->fetchAll();
    }
}
