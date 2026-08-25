<?php

declare(strict_types=1);

namespace App\Live;

use App\Homework\Gamification;
use PDO;

/**
 * Die Klassenliste.
 *
 * Bis hierher gab es kein Schuelerverzeichnis. Namen tippten die Schuelerinnen
 * und Schueler bei jeder Abgabe selbst ein; student_progress fand sie ueber
 * einen normalisierten Schluessel wieder. Genau dieser Schluessel verbindet die
 * neue Liste mit dem Bestand: wer schon abgegeben hat, bringt Erfahrungspunkte
 * und Serie mit, ohne dass eine Zeile migriert wird.
 *
 * Ausscheiden heisst archivieren, nicht loeschen - sonst reisst die
 * Beteiligungshistorie ab, sobald jemand die Liste neu einfuegt.
 */
final class Roster
{
    public function __construct(private PDO $conn) {}

    /**
     * Zerlegt eine eingefuegte Namensliste.
     *
     * Aus IServ und Untis kommt "Mustermann, Max", von Hand getippt wird
     * "Max Mustermann". Beide muessen denselben Schluessel ergeben, sonst
     * greift die Verknuepfung mit dem Bestand nicht. Umgedreht wird nur bei
     * genau zwei Feldern - eine dreispaltige Zeile bliebe sonst rateweise
     * verdreht, und das faellt in der Vorschau schwerer auf.
     *
     * @return list<string>
     */
    public static function parseNames(string $eingabe): array
    {
        $namen = [];
        $gesehen = [];

        foreach (preg_split('/\r\n|\r|\n/', $eingabe) ?: [] as $zeile) {
            // Fuehrende Nummerierung aus kopierten Listen: "1.", "12)", "- ".
            $zeile = (string)preg_replace('/^\s*(\d+\s*[.)]|[-\x{2022}*])\s*/u', '', trim($zeile));
            $zeile = trim($zeile);

            if ($zeile === '') {
                continue;
            }

            if (preg_match('/^([^,;\t]+)[,;\t]\s*([^,;\t]+)$/u', $zeile, $treffer) === 1) {
                $zeile = trim($treffer[2]) . ' ' . trim($treffer[1]);
            }

            $zeile = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $zeile)), 0, 150);
            $key = Gamification::studentKey($zeile);

            if ($key === '' || isset($gesehen[$key])) {
                continue;
            }

            $gesehen[$key] = true;
            $namen[] = $zeile;
        }

        return $namen;
    }

    /**
     * Die Liste einer Klasse.
     *
     * @return list<array<string,mixed>>
     */
    public function forClass(int $classId, bool $mitArchivierten = false): array
    {
        $stmt = $this->conn->prepare('
            SELECT id, class_id, display_name, student_key, sort_order, archived_at
            FROM students
            WHERE class_id = ?' . ($mitArchivierten ? '' : ' AND archived_at IS NULL') . '
            ORDER BY archived_at IS NOT NULL, sort_order ASC, display_name ASC
        ');
        $stmt->execute([$classId]);

        return $stmt->fetchAll();
    }

    public function countActive(int $classId): int
    {
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM students WHERE class_id = ? AND archived_at IS NULL');
        $stmt->execute([$classId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Was ein Speichern bewirken wuerde - ohne es zu tun.
     *
     * Die Vorschau ist kein Beiwerk: eingefuegt wird eine ganze Liste, und
     * wer eine unvollstaendige einfuegt, archiviert versehentlich den Rest
     * der Klasse. Das muss vorher sichtbar sein.
     *
     * @return array{namen:list<array{name:string,key:string,bekannt:bool,neu:bool}>,archiviert:list<string>,anzahl_neu:int,anzahl_bleibt:int,anzahl_bekannt:int}
     */
    public function plan(int $classId, string $eingabe): array
    {
        $namen = self::parseNames($eingabe);
        $keys = array_map(static fn(string $n): string => Gamification::studentKey($n), $namen);

        $vorhanden = [];
        foreach ($this->forClass($classId, true) as $zeile) {
            $vorhanden[(string)$zeile['student_key']] = $zeile;
        }

        $bekannt = array_flip($this->keysWithProgress($classId, $keys));

        $eintraege = [];
        $anzahlNeu = 0;
        $anzahlBleibt = 0;

        foreach ($namen as $i => $name) {
            $key = $keys[$i];
            $istNeu = !isset($vorhanden[$key]) || $vorhanden[$key]['archived_at'] !== null;

            $eintraege[] = [
                'name'    => $name,
                'key'     => $key,
                'bekannt' => isset($bekannt[$key]),
                'neu'     => $istNeu,
            ];

            $istNeu ? $anzahlNeu++ : $anzahlBleibt++;
        }

        $behalten = array_flip($keys);
        $archiviert = [];
        foreach ($vorhanden as $key => $zeile) {
            if (!isset($behalten[$key]) && $zeile['archived_at'] === null) {
                $archiviert[] = (string)$zeile['display_name'];
            }
        }

        return [
            'namen'          => $eintraege,
            'archiviert'     => $archiviert,
            'anzahl_neu'     => $anzahlNeu,
            'anzahl_bleibt'  => $anzahlBleibt,
            'anzahl_bekannt' => count(array_filter($eintraege, static fn(array $e): bool => $e['bekannt'])),
        ];
    }

    /**
     * Uebernimmt die eingefuegte Liste als neuen Stand der Klasse.
     *
     * @return array{angelegt:int,reaktiviert:int,archiviert:int}
     */
    public function apply(int $classId, string $eingabe): array
    {
        $namen = self::parseNames($eingabe);
        if ($namen === []) {
            return ['angelegt' => 0, 'reaktiviert' => 0, 'archiviert' => 0];
        }

        $vorhanden = [];
        foreach ($this->forClass($classId, true) as $zeile) {
            $vorhanden[(string)$zeile['student_key']] = $zeile;
        }

        $einfuegen = $this->conn->prepare('
            INSERT INTO students (class_id, display_name, student_key, sort_order)
            VALUES (?, ?, ?, ?)
        ');
        $aktualisieren = $this->conn->prepare('
            UPDATE students SET display_name = ?, sort_order = ?, archived_at = NULL WHERE id = ?
        ');

        $angelegt = 0;
        $reaktiviert = 0;
        $behalten = [];

        $this->conn->beginTransaction();

        try {
            foreach ($namen as $position => $name) {
                $key = Gamification::studentKey($name);
                $behalten[] = $key;

                if (!isset($vorhanden[$key])) {
                    $einfuegen->execute([$classId, $name, $key, $position]);
                    $angelegt++;
                    continue;
                }

                if ($vorhanden[$key]['archived_at'] !== null) {
                    $reaktiviert++;
                }

                $aktualisieren->execute([$name, $position, (int)$vorhanden[$key]['id']]);
            }

            $platzhalter = implode(',', array_fill(0, count($behalten), '?'));
            $archivieren = $this->conn->prepare("
                UPDATE students SET archived_at = CURDATE()
                WHERE class_id = ? AND archived_at IS NULL AND student_key NOT IN ($platzhalter)
            ");
            $archivieren->execute(array_merge([$classId], $behalten));
            $archiviert = $archivieren->rowCount();

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        return ['angelegt' => $angelegt, 'reaktiviert' => $reaktiviert, 'archiviert' => $archiviert];
    }

    /**
     * Einzelne Person archivieren oder zurueckholen - fuer den Zugang oder
     * Abgang mitten im Schuljahr, der keine ganze neue Liste rechtfertigt.
     */
    public function setArchived(int $studentId, bool $archiviert): void
    {
        $this->conn->prepare('UPDATE students SET archived_at = ? WHERE id = ?')
            ->execute([$archiviert ? date('Y-m-d') : null, $studentId]);
    }

    /**
     * Prueft, ob eine Person zu einer Klasse gehoert, die diese Lehrkraft
     * unterrichtet. Zugriffsschutz gehoert in die Abfrage, nicht in die
     * Oberflaeche.
     */
    public function belongsToTeacher(int $studentId, int $teacherId): bool
    {
        $stmt = $this->conn->prepare('
            SELECT 1 FROM students s
            JOIN teacher_classes tc ON tc.class_id = s.class_id
            WHERE s.id = ? AND tc.teacher_id = ?
            LIMIT 1
        ');
        $stmt->execute([$studentId, $teacherId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Welche der Schluessel schon Lernfortschritt mitbringen.
     *
     * student_progress.klasse ist ein Klassenname als Text, kein
     * Fremdschluessel - die Verbindung laeuft deshalb ueber classes.name.
     * Unschoen, aber sie kommt ohne Aenderung am Bestand aus.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public function keysWithProgress(int $classId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $platzhalter = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->conn->prepare("
            SELECT p.student_key
            FROM student_progress p
            JOIN classes c ON c.name = p.klasse
            WHERE c.id = ? AND p.student_key IN ($platzhalter)
        ");
        $stmt->execute(array_merge([$classId], $keys));

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
