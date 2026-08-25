<?php

declare(strict_types=1);

namespace App\Live;

use PDO;

/**
 * Die gehaltene Stunde.
 *
 * Der Erfassungsschirm muss ohne Auswahldialog auskommen: wer erst Klasse,
 * Fach und Stunde anklickt, hat die versprochenen dreissig Sekunden schon
 * verbraucht, bevor der erste Name getippt ist. Solange kein Stundenplan
 * angebunden ist, raet guessCurrent() aus der eigenen Gewohnheit - welche
 * Klasse diese Lehrkraft an diesem Wochentag zu dieser Stunde zuletzt
 * erfasst hat. Nach zwei Wochen Benutzung trifft das fast immer.
 */
final class LessonRepository
{
    /**
     * Zeitraster in Minuten nach Mitternacht: Beginn und Ende je Stunde.
     *
     * Ein uebliches 45-Minuten-Raster mit zwei grossen Pausen. Weicht die
     * Schule davon ab, ist das hier die einzige Stelle, die sich aendert -
     * die Vermutung darf danebenliegen, die Lehrkraft korrigiert sie mit
     * einem Griff.
     *
     * @var array<int,array{0:int,1:int}>
     */
    public const PERIODS = [
        1  => [465, 510],   // 07:45 - 08:30
        2  => [510, 555],   // 08:30 - 09:15
        3  => [575, 620],   // 09:35 - 10:20
        4  => [620, 665],   // 10:20 - 11:05
        5  => [685, 730],   // 11:25 - 12:10
        6  => [730, 775],   // 12:10 - 12:55
        7  => [820, 865],   // 13:40 - 14:25
        8  => [865, 910],   // 14:25 - 15:10
        9  => [915, 960],   // 15:15 - 16:00
        10 => [960, 1005],  // 16:00 - 16:45
    ];

    /** So weit zurueck schaut die Vermutung. */
    private const GEWOHNHEIT_WOCHEN = 10;

    public function __construct(private PDO $conn) {}

    /**
     * Welche Schulstunde gerade laeuft.
     *
     * Zwischen zwei Stunden - also in der Pause - gilt die kommende: wer in
     * der Pause das Handy zueckt, bereitet die naechste Stunde vor, nicht
     * die vergangene.
     *
     * Der Beginn gehoert zur Stunde, das Ende schon zur naechsten. Die
     * Stunden stossen aneinander (08:30 ist Ende der ersten und Beginn der
     * zweiten); mit einem einschliessenden Ende lieferte die Vermutung in
     * genau diesem Moment noch die abgelaufene Stunde.
     */
    public static function periodForMinute(int $minuten): int
    {
        $letzte = 1;

        foreach (self::PERIODS as $nummer => [$von, $bis]) {
            if ($minuten < $bis) {
                return $nummer;
            }
            $letzte = $nummer;
        }

        return $letzte;
    }

    public static function currentPeriod(?int $zeitstempel = null): int
    {
        $zeit = $zeitstempel ?? time();

        return self::periodForMinute((int)date('G', $zeit) * 60 + (int)date('i', $zeit));
    }

    /**
     * Die wahrscheinlichste Stunde fuer diese Lehrkraft, jetzt.
     *
     * @return array{class_id:int,fach:string,period:int,sicher:bool}|null
     */
    public function guessCurrent(int $teacherId, ?int $zeitstempel = null): ?array
    {
        $zeit = $zeitstempel ?? time();
        $stunde = self::currentPeriod($zeit);

        // MySQL zaehlt Sonntag als 1, PHP als 0.
        $wochentag = (int)date('w', $zeit) + 1;

        $stmt = $this->conn->prepare('
            SELECT s.class_id, s.fach, COUNT(*) AS treffer
            FROM lesson_sessions s
            JOIN teacher_classes tc ON tc.class_id = s.class_id AND tc.teacher_id = s.teacher_id
            WHERE s.teacher_id = ?
              AND s.period = ?
              AND DAYOFWEEK(s.lesson_date) = ?
              AND s.lesson_date >= CURDATE() - INTERVAL ? WEEK
            GROUP BY s.class_id, s.fach
            ORDER BY treffer DESC, MAX(s.lesson_date) DESC
            LIMIT 1
        ');
        $stmt->execute([$teacherId, $stunde, $wochentag, self::GEWOHNHEIT_WOCHEN]);
        $treffer = $stmt->fetch();

        if ($treffer === false) {
            return null;
        }

        return [
            'class_id' => (int)$treffer['class_id'],
            'fach'     => (string)$treffer['fach'],
            'period'   => $stunde,
            // Ein einziger Vorgaenger ist noch keine Gewohnheit.
            'sicher'   => (int)$treffer['treffer'] >= 2,
        ];
    }

    /**
     * Holt die Stunde oder legt sie an.
     *
     * @return array<string,mixed>
     */
    public function openOrCreate(int $teacherId, int $classId, string $fach, string $datum, int $period): array
    {
        $this->conn->prepare('
            INSERT INTO lesson_sessions (teacher_id, class_id, fach, lesson_date, period)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE fach = VALUES(fach)
        ')->execute([$teacherId, $classId, mb_substr($fach, 0, 100), $datum, $period]);

        $stmt = $this->conn->prepare('
            SELECT * FROM lesson_sessions
            WHERE teacher_id = ? AND class_id = ? AND lesson_date = ? AND period = ?
        ');
        $stmt->execute([$teacherId, $classId, $datum, $period]);

        /** @var array<string,mixed> $zeile */
        $zeile = $stmt->fetch();

        return $zeile;
    }

    /**
     * Sucht die Stunde, ohne sie anzulegen.
     *
     * Der Erfassungsschirm darf beim blossen Aufrufen keine Stunde erzeugen:
     * leere Stunden verfaelschen sonst genau die Gewohnheit, aus der
     * guessCurrent() seine Vermutung zieht. Angelegt wird erst beim ersten
     * Beitrag.
     *
     * @return array<string,mixed>|null
     */
    public function findFor(int $teacherId, int $classId, string $datum, int $period): ?array
    {
        $stmt = $this->conn->prepare('
            SELECT * FROM lesson_sessions
            WHERE teacher_id = ? AND class_id = ? AND lesson_date = ? AND period = ?
        ');
        $stmt->execute([$teacherId, $classId, $datum, $period]);
        $zeile = $stmt->fetch();

        return $zeile === false ? null : $zeile;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $sessionId, int $teacherId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM lesson_sessions WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$sessionId, $teacherId]);
        $zeile = $stmt->fetch();

        return $zeile === false ? null : $zeile;
    }

    public function updateTopic(int $sessionId, int $teacherId, string $topic): void
    {
        $this->conn->prepare('UPDATE lesson_sessions SET topic = ? WHERE id = ? AND teacher_id = ?')
            ->execute([mb_substr(trim($topic), 0, 255) ?: null, $sessionId, $teacherId]);
    }

    public function close(int $sessionId, int $teacherId): void
    {
        $this->conn->prepare('UPDATE lesson_sessions SET closed_at = NOW() WHERE id = ? AND teacher_id = ?')
            ->execute([$sessionId, $teacherId]);
    }

    /**
     * Das zuletzt behandelte Thema - Vorbelegung fuer den Erfassungsschirm.
     *
     * Ohne diese Vorbelegung bleibt topic leer, weil das Tippen laestig ist,
     * und dann steht Stufe 4 ohne Kontext da.
     */
    public function lastTopic(int $classId, string $fach): ?string
    {
        $stmt = $this->conn->prepare('
            SELECT topic FROM lesson_sessions
            WHERE class_id = ? AND fach = ? AND topic IS NOT NULL AND topic <> \'\'
            ORDER BY lesson_date DESC, period DESC
            LIMIT 1
        ');
        $stmt->execute([$classId, $fach]);
        $topic = $stmt->fetchColumn();

        return $topic === false ? null : (string)$topic;
    }

    /**
     * Die zuletzt behandelten Themen einer Klasse in einem Fach.
     *
     * @return list<array{lesson_date:string,period:int,topic:string}>
     */
    public function recentTopics(int $classId, string $fach, int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));

        $stmt = $this->conn->prepare("
            SELECT lesson_date, period, topic
            FROM lesson_sessions
            WHERE class_id = ? AND fach = ? AND topic IS NOT NULL AND topic <> ''
            ORDER BY lesson_date DESC, period DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$classId, $fach]);

        $themen = [];
        foreach ($stmt->fetchAll() as $zeile) {
            $themen[] = [
                'lesson_date' => (string)$zeile['lesson_date'],
                'period'      => (int)$zeile['period'],
                'topic'       => (string)$zeile['topic'],
            ];
        }

        return array_reverse($themen);
    }

    /**
     * Faecher, die diese Lehrkraft in dieser Klasse schon gefuehrt hat -
     * aus den Stunden und aus den Hausaufgaben.
     *
     * Eine Auswahlliste statt eines Textfelds: "Mathe" und "Mathematik"
     * nebeneinander lassen den Themenverlauf zerfallen.
     *
     * @return list<string>
     */
    public function subjectsFor(int $teacherId, int $classId): array
    {
        $stmt = $this->conn->prepare('
            SELECT DISTINCT fach FROM lesson_sessions WHERE teacher_id = ? AND class_id = ?
            UNION
            SELECT DISTINCT a.fach FROM homework_assignments a
            JOIN classes c ON c.name = a.klasse
            WHERE a.teacher_id = ? AND c.id = ?
        ');
        $stmt->execute([$teacherId, $classId, $teacherId, $classId]);

        $faecher = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($faecher, SORT_NATURAL | SORT_FLAG_CASE);

        return $faecher;
    }

    /**
     * Die letzten Stunden dieser Lehrkraft, fuer die Uebersicht.
     *
     * @return list<array<string,mixed>>
     */
    public function recentForTeacher(int $teacherId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $stmt = $this->conn->prepare("
            SELECT s.*, c.name AS klasse, COUNT(e.id) AS beitraege
            FROM lesson_sessions s
            JOIN classes c ON c.id = s.class_id
            LEFT JOIN participation_events e ON e.session_id = s.id
            WHERE s.teacher_id = ?
            GROUP BY s.id
            ORDER BY s.lesson_date DESC, s.period DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$teacherId]);

        return $stmt->fetchAll();
    }
}
