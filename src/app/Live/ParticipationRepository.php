<?php

declare(strict_types=1);

namespace App\Live;

use PDO;

/**
 * Wortbeitraege einer Stunde.
 *
 * Geschrieben wird buendelweise, nicht einzeln: das Geraet legt jeden Tipp
 * zuerst in seinen eigenen Zwischenspeicher und schickt ihn, sobald es
 * wieder Netz hat. Im Klassenzimmer eines Altbaus ist das kein Randfall.
 * Die client_uid entscheidet ueber die Wiederholbarkeit - dieselbe Kennung
 * zweimal geschickt ergibt einen Datensatz.
 *
 * Keine Abfrage vertraut der student_id aus dem Browser: was nicht zur
 * Klasse dieser Stunde gehoert, wird verworfen.
 */
final class ParticipationRepository
{
    /** 1 kurz, 2 solide, 3 weiterfuehrend. */
    public const GEWICHTE = [1, 2, 3];

    private const ARTEN = ['freiwillig', 'aufgerufen'];

    public function __construct(private PDO $conn) {}

    /**
     * Traegt ein Buendel Beitraege ein.
     *
     * @param list<array<string,mixed>> $events
     * @return array{gespeichert:int,doppelt:int,verworfen:int}
     */
    public function record(int $sessionId, array $events): array
    {
        if ($events === []) {
            return ['gespeichert' => 0, 'doppelt' => 0, 'verworfen' => 0];
        }

        $erlaubt = array_flip($this->studentIdsForSession($sessionId));

        $einfuegen = $this->conn->prepare('
            INSERT IGNORE INTO participation_events
                (session_id, student_id, weight, kind, note, client_uid, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');

        $gespeichert = 0;
        $doppelt = 0;
        $verworfen = 0;

        $this->conn->beginTransaction();

        try {
            foreach ($events as $event) {
                $uid = self::normalizeUid($event['uid'] ?? null);
                $studentId = (int)($event['student_id'] ?? 0);

                if ($uid === null || !isset($erlaubt[$studentId])) {
                    $verworfen++;
                    continue;
                }

                $gewicht = (int)($event['weight'] ?? 2);
                if (!in_array($gewicht, self::GEWICHTE, true)) {
                    $gewicht = 2;
                }

                $art = (string)($event['kind'] ?? 'freiwillig');
                if (!in_array($art, self::ARTEN, true)) {
                    $art = 'freiwillig';
                }

                $notiz = trim((string)($event['note'] ?? ''));
                $notiz = $notiz === '' ? null : mb_substr($notiz, 0, 200);

                $einfuegen->execute([
                    $sessionId,
                    $studentId,
                    $gewicht,
                    $art,
                    $notiz,
                    $uid,
                    self::normalizeTime($event['at'] ?? null),
                ]);

                $einfuegen->rowCount() === 1 ? $gespeichert++ : $doppelt++;
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        return ['gespeichert' => $gespeichert, 'doppelt' => $doppelt, 'verworfen' => $verworfen];
    }

    /**
     * Nimmt Beitraege zurueck, die das Geraet schon abgeschickt hatte.
     *
     * @param list<string> $uids
     */
    public function remove(int $sessionId, array $uids): int
    {
        $gueltig = [];
        foreach ($uids as $uid) {
            $normalisiert = self::normalizeUid($uid);
            if ($normalisiert !== null) {
                $gueltig[] = $normalisiert;
            }
        }

        if ($gueltig === []) {
            return 0;
        }

        $platzhalter = implode(',', array_fill(0, count($gueltig), '?'));
        $stmt = $this->conn->prepare("
            DELETE FROM participation_events
            WHERE session_id = ? AND client_uid IN ($platzhalter)
        ");
        $stmt->execute(array_merge([$sessionId], $gueltig));

        return $stmt->rowCount();
    }

    /**
     * Der Stand einer Stunde, nach Person.
     *
     * @return array<int,array{anzahl:int,summe:int}>
     */
    public function tallyForSession(int $sessionId): array
    {
        $stmt = $this->conn->prepare('
            SELECT student_id, COUNT(*) AS anzahl, SUM(weight) AS summe
            FROM participation_events
            WHERE session_id = ?
            GROUP BY student_id
        ');
        $stmt->execute([$sessionId]);

        $stand = [];
        foreach ($stmt->fetchAll() as $zeile) {
            $stand[(int)$zeile['student_id']] = [
                'anzahl' => (int)$zeile['anzahl'],
                'summe'  => (int)$zeile['summe'],
            ];
        }

        return $stand;
    }

    /**
     * Die einzelnen Beitraege einer Stunde - fuer das Zuruecknehmen und die
     * Belegansicht.
     *
     * @return list<array<string,mixed>>
     */
    public function forSession(int $sessionId): array
    {
        $stmt = $this->conn->prepare('
            SELECT e.*, s.display_name
            FROM participation_events e
            JOIN students s ON s.id = e.student_id
            WHERE e.session_id = ?
            ORDER BY e.created_at ASC, e.id ASC
        ');
        $stmt->execute([$sessionId]);

        return $stmt->fetchAll();
    }

    /**
     * Wer zur Klasse dieser Stunde gehoert.
     *
     * @return list<int>
     */
    public function studentIdsForSession(int $sessionId): array
    {
        $stmt = $this->conn->prepare('
            SELECT st.id
            FROM students st
            JOIN lesson_sessions ls ON ls.class_id = st.class_id
            WHERE ls.id = ? AND st.archived_at IS NULL
        ');
        $stmt->execute([$sessionId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Kennungen wie 3f2504e0-4f89-11d3-9a0c-0305e82c3301. Alles andere
     * koennte die Wiederholbarkeit aushebeln und wird abgewiesen.
     */
    private static function normalizeUid(mixed $uid): ?string
    {
        if (!is_string($uid)) {
            return null;
        }

        $uid = strtolower(trim($uid));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uid) === 1
            ? $uid
            : null;
    }

    /**
     * Der Zeitpunkt kommt vom Geraet, damit nachgelieferte Beitraege in der
     * Stunde stehen, in der sie entstanden sind - aber niemals in der
     * Zukunft und nicht aelter als einen Tag.
     */
    private static function normalizeTime(mixed $wert): string
    {
        $jetzt = time();

        if (!is_string($wert) || $wert === '') {
            return date('Y-m-d H:i:s', $jetzt);
        }

        $zeit = strtotime($wert);
        if ($zeit === false || $zeit > $jetzt || $zeit < $jetzt - 86400) {
            return date('Y-m-d H:i:s', $jetzt);
        }

        return date('Y-m-d H:i:s', $zeit);
    }
}
