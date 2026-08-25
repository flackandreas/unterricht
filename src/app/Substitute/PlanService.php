<?php

declare(strict_types=1);

namespace App\Substitute;

use App\Ai\AIService;
use App\Homework\HomeworkRepository;
use App\Live\LessonRepository;
use App\Support\AuditLog;
use App\Support\UsageRecorder;
use PDO;

/**
 * Vertretungsstunden anfordern, erzeugen, freigeben.
 *
 * Der Kontext wird beim Anfordern festgehalten, nicht beim Erzeugen: so
 * steht spaeter nachvollziehbar in der Zeile, aus welchen Stundenthemen der
 * Plan entstanden ist. Ohne diesen Schnappschuss laesst sich hinterher nicht
 * mehr sagen, worauf sich die KI gestuetzt hat.
 */
final class PlanService
{
    /** So viele zurueckliegende Stundenthemen gehen in den Kontext. */
    private const THEMEN = 8;

    /** So viele ausgewertete Hausaufgaben gehen in den Kontext. */
    private const HAUSAUFGABEN = 3;

    public function __construct(
        private PDO $conn,
        private LessonRepository $lessons,
        private PlanQueue $queue,
        private AuditLog $audit,
        private HomeworkRepository $homework
    ) {}

    /**
     * Liest den festgehaltenen Kontext.
     *
     * Vertraegt beide Formen: die aeltere, in der nur die Themenliste
     * gespeichert wurde, und die heutige mit Themen und Hausaufgaben. Ein
     * Auftrag, der vor der Umstellung in die Warteschlange ging, soll nicht
     * daran scheitern.
     *
     * @return array{themen:list<array<string,mixed>>,hausaufgaben:list<array<string,mixed>>}
     */
    public static function contextFromJson(?string $json): array
    {
        $daten = $json !== null && $json !== '' ? json_decode($json, true) : null;

        if (!is_array($daten)) {
            return ['themen' => [], 'hausaufgaben' => []];
        }

        // Aeltere Form: eine reine Liste von Themen.
        if (!isset($daten['themen']) && !isset($daten['hausaufgaben'])) {
            return [
                'themen'       => array_values(array_filter($daten, 'is_array')),
                'hausaufgaben' => [],
            ];
        }

        return [
            'themen'       => array_values(array_filter(
                is_array($daten['themen'] ?? null) ? $daten['themen'] : [],
                'is_array'
            )),
            'hausaufgaben' => array_values(array_filter(
                is_array($daten['hausaufgaben'] ?? null) ? $daten['hausaufgaben'] : [],
                'is_array'
            )),
        ];
    }

    /**
     * Stellt eine Vertretungsstunde in die Warteschlange.
     */
    public function request(
        int $teacherId,
        int $classId,
        string $fach,
        string $datum,
        ?int $period,
        int $dauer = 45,
        string $hinweis = ''
    ): int {
        $themen = $this->lessons->recentTopics($classId, $fach, self::THEMEN);
        $hausaufgaben = $this->homework->summariesForClassSubject($classId, $fach, self::HAUSAUFGABEN);

        $this->conn->prepare('
            INSERT INTO substitute_plans
                (teacher_id, class_id, fach, lesson_date, period, dauer, hinweis, context_json, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'queued\')
        ')->execute([
            $teacherId,
            $classId,
            mb_substr($fach, 0, 100),
            $datum,
            $period,
            max(20, min(120, $dauer)),
            mb_substr(trim($hinweis), 0, 500) ?: null,
            json_encode(['themen' => $themen, 'hausaufgaben' => $hausaufgaben], JSON_UNESCAPED_UNICODE),
        ]);

        $planId = (int)$this->conn->lastInsertId();

        $this->audit->record(
            $teacherId,
            'vertretung_angefordert',
            'substitute_plan',
            $planId,
            sprintf(
                '%s, %s, Klasse %d (%d Themen, %d Hausaufgaben im Kontext)',
                $fach,
                $datum,
                $classId,
                count($themen),
                count($hausaufgaben)
            )
        );

        return $planId;
    }

    /**
     * Erzeugt den Entwurf. Wird vom Arbeitsprozess aufgerufen.
     *
     * @param array<string,mixed> $auftrag
     */
    public function generate(array $auftrag): void
    {
        $kontext = self::contextFromJson(
            $auftrag['context_json'] !== null ? (string)$auftrag['context_json'] : null
        );

        // Wie in SubmissionService: der Dienst entsteht je Auftrag, damit der
        // Tokenverbrauch der richtigen Lehrkraft zugeordnet wird.
        $ai = new AIService(new UsageRecorder($this->conn, (int)$auftrag['teacher_id']));

        $plan = $ai->generateSubstitutePlan(
            (string)$auftrag['klasse'],
            (string)$auftrag['fach'],
            $kontext['themen'],
            $kontext['hausaufgaben'],
            (int)$auftrag['dauer'],
            (string)($auftrag['hinweis'] ?? '')
        );

        $this->queue->markDraft((int)$auftrag['id'], $plan);
    }

    /**
     * Gibt den Entwurf frei und erzeugt den Zugang fuer die
     * Vertretungskraft.
     *
     * Erst hier entsteht der Token: ein Entwurf, den noch niemand gelesen
     * hat, soll keine Adresse haben, die im Lehrerzimmer haengen kann.
     */
    public function release(int $planId, int $teacherId): ?string
    {
        $plan = $this->find($planId, $teacherId);

        if ($plan === null || $plan['status'] !== 'draft') {
            return null;
        }

        $token = bin2hex(random_bytes(24));

        $this->conn->prepare("
            UPDATE substitute_plans
            SET status = 'released', released_by = ?, released_at = NOW(), access_token = ?
            WHERE id = ? AND status = 'draft'
        ")->execute([$teacherId, $token, $planId]);

        $this->audit->record($teacherId, 'vertretung_freigegeben', 'substitute_plan', $planId, '');

        return $token;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $planId, int $teacherId): ?array
    {
        $stmt = $this->conn->prepare('
            SELECT p.*, c.name AS klasse
            FROM substitute_plans p
            JOIN classes c ON c.id = p.class_id
            WHERE p.id = ? AND p.teacher_id = ?
        ');
        $stmt->execute([$planId, $teacherId]);
        $zeile = $stmt->fetch();

        return $zeile === false ? null : $zeile;
    }

    /**
     * Zugang der Vertretungskraft - ueber den Token, ohne Anmeldung.
     *
     * @return array<string,mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
            return null;
        }

        $stmt = $this->conn->prepare("
            SELECT p.*, c.name AS klasse
            FROM substitute_plans p
            JOIN classes c ON c.id = p.class_id
            WHERE p.access_token = ? AND p.status = 'released'
        ");
        $stmt->execute([$token]);
        $zeile = $stmt->fetch();

        return $zeile === false ? null : $zeile;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forTeacher(int $teacherId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $stmt = $this->conn->prepare("
            SELECT p.*, c.name AS klasse
            FROM substitute_plans p
            JOIN classes c ON c.id = p.class_id
            WHERE p.teacher_id = ?
            ORDER BY p.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$teacherId]);

        return $stmt->fetchAll();
    }

    public function delete(int $planId, int $teacherId): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM substitute_plans WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$planId, $teacherId]);

        return $stmt->rowCount() > 0;
    }
}
