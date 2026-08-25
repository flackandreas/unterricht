<?php

declare(strict_types=1);

namespace App\Live;

use PDO;

/**
 * Auswertung der Beteiligung.
 *
 * Bewusst ohne Notenberechnung. Die muendliche Note ist eine paedagogische
 * Ermessensentscheidung; ein arithmetisches Mittel aus Strichlisten waere
 * angreifbarer als das begruendete Urteil der Lehrkraft, weil es das
 * Ermessen ersetzt statt es zu stuetzen. Diese Klasse liefert Belege - wie
 * viele Beitraege, welcher Art, wann - und ueberlaesst den Schluss dem
 * Menschen.
 *
 * Der zweite Zweck ist der wertvollere: stille Schuelerinnen und Schueler
 * verschwinden systematisch aus der Wahrnehmung. silentStudents() holt sie
 * zurueck.
 */
final class ParticipationReport
{
    /** Ab so vielen Tagen ohne Beitrag gilt jemand als uebersehen. */
    public const STILL_AB_TAGEN = 21;

    public function __construct(private PDO $conn) {}

    /**
     * Belegansicht einer Klasse.
     *
     * @return list<array{student_id:int,display_name:string,anzahl:int,summe:int,kurz:int,solide:int,weiterfuehrend:int,aufgerufen:int,notizen:int,letzter:string|null}>
     */
    public function overview(int $teacherId, int $classId, string $fach, string $von, string $bis): array
    {
        [$fachBedingung, $fachWerte] = $this->fachFilter($fach);

        $stmt = $this->conn->prepare("
            SELECT st.id AS student_id,
                   st.display_name,
                   COUNT(e.id)                                        AS anzahl,
                   COALESCE(SUM(e.weight), 0)                         AS summe,
                   SUM(CASE WHEN e.weight = 1 THEN 1 ELSE 0 END)      AS kurz,
                   SUM(CASE WHEN e.weight = 2 THEN 1 ELSE 0 END)      AS solide,
                   SUM(CASE WHEN e.weight = 3 THEN 1 ELSE 0 END)      AS weiterfuehrend,
                   SUM(CASE WHEN e.kind = 'aufgerufen' THEN 1 ELSE 0 END) AS aufgerufen,
                   SUM(CASE WHEN e.note IS NOT NULL THEN 1 ELSE 0 END)    AS notizen,
                   -- Der Stundentag, nicht der Erfassungszeitpunkt: nachgelieferte
                   -- Beitraege sollen in der Stunde stehen, in der sie fielen.
                   MAX(CASE WHEN e.id IS NOT NULL THEN ls.lesson_date END) AS letzter
            FROM students st
            LEFT JOIN lesson_sessions ls
                   ON ls.class_id = st.class_id
                  AND ls.teacher_id = ?
                  AND ls.lesson_date BETWEEN ? AND ?
                  {$fachBedingung}
            LEFT JOIN participation_events e
                   ON e.session_id = ls.id AND e.student_id = st.id
            WHERE st.class_id = ? AND st.archived_at IS NULL
            GROUP BY st.id, st.display_name
            ORDER BY st.sort_order ASC, st.display_name ASC
        ");
        $stmt->execute(array_merge([$teacherId, $von, $bis], $fachWerte, [$classId]));

        $zeilen = [];
        foreach ($stmt->fetchAll() as $r) {
            $zeilen[] = [
                'student_id'     => (int)$r['student_id'],
                'display_name'   => (string)$r['display_name'],
                'anzahl'         => (int)$r['anzahl'],
                'summe'          => (int)$r['summe'],
                'kurz'           => (int)$r['kurz'],
                'solide'         => (int)$r['solide'],
                'weiterfuehrend' => (int)$r['weiterfuehrend'],
                'aufgerufen'     => (int)$r['aufgerufen'],
                'notizen'        => (int)$r['notizen'],
                'letzter'        => $r['letzter'] !== null ? (string)$r['letzter'] : null,
            ];
        }

        return $zeilen;
    }

    /**
     * Beitraege je Kalenderwoche - der Verlauf neben der Summe.
     *
     * Zwoelf Beitraege gleichmaessig verteilt sind etwas anderes als zwoelf
     * Beitraege in einer einzigen Woche.
     *
     * @return array{wochen:list<string>,werte:array<int,array<string,int>>}
     */
    public function weeklyCourse(int $teacherId, int $classId, string $fach, string $von, string $bis): array
    {
        [$fachBedingung, $fachWerte] = $this->fachFilter($fach);

        $stmt = $this->conn->prepare("
            SELECT e.student_id,
                   DATE_FORMAT(ls.lesson_date, '%x-%v') AS woche,
                   COUNT(*) AS anzahl
            FROM participation_events e
            JOIN lesson_sessions ls ON ls.id = e.session_id
            WHERE ls.teacher_id = ?
              AND ls.class_id = ?
              AND ls.lesson_date BETWEEN ? AND ?
              {$fachBedingung}
            GROUP BY e.student_id, woche
        ");
        $stmt->execute(array_merge([$teacherId, $classId, $von, $bis], $fachWerte));

        $werte = [];
        $wochen = [];

        foreach ($stmt->fetchAll() as $r) {
            $woche = (string)$r['woche'];
            $wochen[$woche] = true;
            $werte[(int)$r['student_id']][$woche] = (int)$r['anzahl'];
        }

        $liste = array_keys($wochen);
        sort($liste);

        return ['wochen' => $liste, 'werte' => $werte];
    }

    /**
     * Die einzelnen Beitraege einer Person - was im Elterngespraech auf dem
     * Tisch liegt, wenn die Frage kommt, woran man das festmacht.
     *
     * @return list<array<string,mixed>>
     */
    public function evidence(int $studentId, int $teacherId, string $fach, string $von, string $bis): array
    {
        [$fachBedingung, $fachWerte] = $this->fachFilter($fach);

        $stmt = $this->conn->prepare("
            SELECT e.weight, e.kind, e.note, e.created_at,
                   ls.lesson_date, ls.period, ls.fach, ls.topic
            FROM participation_events e
            JOIN lesson_sessions ls ON ls.id = e.session_id
            WHERE e.student_id = ?
              AND ls.teacher_id = ?
              AND ls.lesson_date BETWEEN ? AND ?
              {$fachBedingung}
            ORDER BY ls.lesson_date DESC, ls.period DESC
        ");
        $stmt->execute(array_merge([$studentId, $teacherId, $von, $bis], $fachWerte));

        return $stmt->fetchAll();
    }

    /**
     * Wer laenger nicht drangekommen ist.
     *
     * Die Einschraenkung auf Klassen mit juengster Erfassung ist wesentlich:
     * ohne sie meldet der Hinweis jede Klasse, in der noch nie erfasst wurde,
     * und wird damit sofort zu Rauschen, das man wegklickt.
     *
     * @return list<array{class_id:int,klasse:string,namen:list<string>}>
     */
    public function silentStudents(int $teacherId, int $tage = self::STILL_AB_TAGEN): array
    {
        $tage = max(7, min(120, $tage));

        $stmt = $this->conn->prepare('
            SELECT c.id AS class_id, c.name AS klasse, st.display_name,
                   MAX(CASE WHEN e.id IS NOT NULL THEN ls.lesson_date END) AS letzter
            FROM students st
            JOIN classes c ON c.id = st.class_id
            JOIN teacher_classes tc ON tc.class_id = st.class_id AND tc.teacher_id = ?
            LEFT JOIN lesson_sessions ls ON ls.class_id = st.class_id AND ls.teacher_id = ?
            LEFT JOIN participation_events e ON e.session_id = ls.id AND e.student_id = st.id
            WHERE st.archived_at IS NULL
              AND st.class_id IN (
                  SELECT class_id FROM lesson_sessions
                  WHERE teacher_id = ? AND lesson_date >= CURDATE() - INTERVAL ? DAY
              )
            GROUP BY st.id, c.id, c.name, st.display_name
            HAVING letzter IS NULL OR letzter < CURDATE() - INTERVAL ? DAY
            ORDER BY c.name ASC, st.display_name ASC
        ');
        $stmt->execute([$teacherId, $teacherId, $teacherId, $tage, $tage]);

        $klassen = [];
        foreach ($stmt->fetchAll() as $r) {
            $id = (int)$r['class_id'];

            if (!isset($klassen[$id])) {
                $klassen[$id] = ['class_id' => $id, 'klasse' => (string)$r['klasse'], 'namen' => []];
            }

            $klassen[$id]['namen'][] = (string)$r['display_name'];
        }

        return array_values($klassen);
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    private function fachFilter(string $fach): array
    {
        return $fach === '' ? ['', []] : ['AND ls.fach = ?', [$fach]];
    }
}
