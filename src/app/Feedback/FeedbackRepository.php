<?php

declare(strict_types=1);

namespace App\Feedback;

use PDO;

/**
 * Datenbankzugriffe rund um das Schueler-Feedback.
 *
 * Die Abfragen lagen vorher verteilt in teacher_feedback.php und
 * feedback_trends.php - die Klassen- und Fachlisten wurden in beiden
 * Controllern eigenstaendig geladen.
 */
final class FeedbackRepository
{
    public function __construct(private PDO $conn) {}

    /**
     * Laufende Sitzung dieser Lehrkraft, sofern vorhanden.
     *
     * @return array<string,mixed>|null
     */
    public function activeSession(int $teacherId): ?array
    {
        $stmt = $this->conn->prepare('
            SELECT * FROM feedback_sessions
            WHERE teacher_id = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ');
        $stmt->execute([$teacherId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Sitzungen samt Durchschnittswert je Frage.
     *
     * Grundlage sowohl fuer die Sitzungsliste als auch fuer die
     * Verlaufsdiagramme - vorher zwei getrennte Wege durch dieselben Daten.
     *
     * @return list<array{id:int,klasse:string,fach:string,created_at:string,questions:list<array{text:string,avg:float|null}>}>
     */
    public function sessionsWithAverages(int $teacherId, string $klasse = '', string $fach = '', int $limit = 0): array
    {
        $sql = '
            SELECT s.id, s.klasse, s.fach, s.created_at,
                   q.id AS question_id, q.question_text,
                   AVG(r.score) AS avg_score
            FROM feedback_sessions s
            JOIN feedback_questions q ON q.session_id = s.id
            LEFT JOIN feedback_responses r ON r.question_id = q.id
            WHERE s.teacher_id = ?
        ';
        $params = [$teacherId];

        if ($klasse !== '') {
            $sql .= ' AND s.klasse = ?';
            $params[] = $klasse;
        }
        if ($fach !== '') {
            $sql .= ' AND s.fach = ?';
            $params[] = $fach;
        }

        $sql .= ' GROUP BY s.id, q.id ORDER BY s.created_at ASC, q.sort_order ASC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $sessions = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int)$row['id'];

            if (!isset($sessions[$id])) {
                $sessions[$id] = [
                    'id'         => $id,
                    'klasse'     => (string)$row['klasse'],
                    'fach'       => (string)$row['fach'],
                    'created_at' => (string)$row['created_at'],
                    'questions'  => [],
                ];
            }

            $sessions[$id]['questions'][] = [
                'text' => (string)$row['question_text'],
                'avg'  => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
            ];
        }

        $sessions = array_values($sessions);

        // Die Liste zeigt die neuesten zuerst, das Diagramm braucht die
        // chronologische Reihenfolge - deshalb erst hier zuschneiden.
        if ($limit > 0 && count($sessions) > $limit) {
            $sessions = array_slice($sessions, -$limit);
        }

        return $sessions;
    }

    /**
     * Gruppiert Sitzungen nach Klasse und Fach - eine Kurve je Gruppe.
     *
     * @param list<array<string,mixed>> $sessions
     * @return array<string,array{klasse:string,fach:string,sessions:list<array<string,mixed>>}>
     */
    public static function groupByClassAndSubject(array $sessions): array
    {
        $gruppen = [];

        foreach ($sessions as $session) {
            $schluessel = $session['klasse'] . ' - ' . $session['fach'];

            if (!isset($gruppen[$schluessel])) {
                $gruppen[$schluessel] = [
                    'klasse'   => $session['klasse'],
                    'fach'     => $session['fach'],
                    'sessions' => [],
                ];
            }

            $gruppen[$schluessel]['sessions'][] = $session;
        }

        return $gruppen;
    }

    /**
     * Klassen und Faecher, zu denen diese Lehrkraft Sitzungen hat.
     *
     * @return array{klassen:list<string>,faecher:list<string>}
     */
    public function filterOptions(int $teacherId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT DISTINCT klasse, fach FROM feedback_sessions WHERE teacher_id = ?'
        );
        $stmt->execute([$teacherId]);

        $klassen = [];
        $faecher = [];

        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['klasse'])) {
                $klassen[] = (string)$row['klasse'];
            }
            if (!empty($row['fach'])) {
                $faecher[] = (string)$row['fach'];
            }
        }

        $klassen = array_values(array_unique($klassen));
        $faecher = array_values(array_unique($faecher));
        sort($klassen);
        sort($faecher);

        return ['klassen' => $klassen, 'faecher' => $faecher];
    }

    /**
     * Klassenliste der Schule und die Zuordnung dieser Lehrkraft.
     *
     * @return array{all:list<array<string,mixed>>,selected:list<int>}
     */
    public function classesForTeacher(int $teacherId): array
    {
        try {
            $stmt = $this->conn->prepare('SELECT id, name FROM classes ORDER BY name ASC');
            $stmt->execute();
            $alle = $stmt->fetchAll();

            $stmt = $this->conn->prepare('SELECT class_id FROM teacher_classes WHERE teacher_id = ?');
            $stmt->execute([$teacherId]);
            $eigene = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\PDOException $e) {
            error_log('Klassenliste nicht ladbar: ' . $e->getMessage());

            return ['all' => [], 'selected' => []];
        }

        return ['all' => $alle, 'selected' => $eigene];
    }

    /**
     * Gespeicherte Fragen-Vorlagen samt ihrer Fragen.
     *
     * @return list<array<string,mixed>>
     */
    public function templates(int $teacherId): array
    {
        try {
            $stmt = $this->conn->prepare('
                SELECT t.id, t.title, t.klasse, t.fach, COUNT(q.id) AS question_count
                FROM feedback_templates t
                LEFT JOIN feedback_template_questions q ON q.template_id = t.id
                WHERE t.teacher_id = ?
                GROUP BY t.id, t.title, t.klasse, t.fach
                ORDER BY t.title ASC
            ');
            $stmt->execute([$teacherId]);
            $vorlagen = $stmt->fetchAll();

            if ($vorlagen === []) {
                return [];
            }

            // Nur die Fragen der eigenen Vorlagen laden - vorher wurden alle
            // Vorlagenfragen der gesamten Datenbank geholt und danach gefiltert.
            $ids = array_column($vorlagen, 'id');
            $platzhalter = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $this->conn->prepare("
                SELECT template_id, question_text, question_type, options
                FROM feedback_template_questions
                WHERE template_id IN ($platzhalter)
                ORDER BY template_id, sort_order ASC
            ");
            $stmt->execute($ids);

            $fragen = [];
            foreach ($stmt->fetchAll() as $row) {
                $fragen[(int)$row['template_id']][] = [
                    'text'    => (string)$row['question_text'],
                    'type'    => (string)$row['question_type'],
                    'options' => (string)($row['options'] ?? ''),
                ];
            }

            foreach ($vorlagen as &$vorlage) {
                $vorlage['questions'] = $fragen[(int)$vorlage['id']] ?? [];
            }
            unset($vorlage);

            return $vorlagen;
        } catch (\PDOException $e) {
            error_log('Vorlagen nicht ladbar: ' . $e->getMessage());

            return [];
        }
    }
}
