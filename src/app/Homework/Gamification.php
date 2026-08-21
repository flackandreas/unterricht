<?php

declare(strict_types=1);

namespace App\Homework;

use PDO;

/**
 * Lernfortschritt: XP, Level und Streak.
 *
 * Der Fortschritt lag bisher vollstaendig im localStorage des Browsers. Er war
 * damit geraeteabhaengig, beim Loeschen der Browserdaten verloren, auf einem
 * geteilten Tablet vermischt und ueber die Entwicklerkonsole in Sekunden
 * manipulierbar. Jetzt haelt ihn der Server, gebunden an Klasse und Name.
 */
final class Gamification
{
    /** XP fuer eine Abgabe, unabhaengig vom Ergebnis. */
    private const XP_SUBMISSION = 50;

    /** Zusaetzliche XP aus der Punktzahl (score/100 * dieser Wert). */
    private const XP_SCORE_BONUS = 100;

    /** XP je Tag laufender Serie, gedeckelt. */
    private const XP_STREAK_BONUS = 10;
    private const XP_STREAK_MAX = 100;

    public function __construct(private PDO $conn) {}

    /**
     * Normalisiert einen Namen zu einem stabilen Schluessel.
     *
     * "Max Mustermann", "max  mustermann" und "MAX MUSTERMANN" ergeben
     * denselben Schluessel, damit der Fortschritt zusammenbleibt.
     */
    public static function studentKey(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $key);
        $key = (string)preg_replace('/[^a-z0-9]+/', ' ', $key);

        return mb_substr(trim((string)preg_replace('/\s+/', ' ', $key)), 0, 190);
    }

    /**
     * Level aus der XP-Summe. Jede Stufe kostet 200 XP mehr als die vorige.
     */
    public static function levelForXp(int $xp): int
    {
        return max(1, (int)floor((-1 + sqrt(1 + 4 * max(0, $xp) / 50)) / 2) + 1);
    }

    /**
     * XP-Schwelle, ab der ein Level beginnt.
     */
    public static function xpForLevel(int $level): int
    {
        $level = max(1, $level);

        return 50 * ($level - 1) * $level;
    }

    /**
     * Aktueller Stand, ohne etwas zu veraendern.
     *
     * @return array<string,mixed>
     */
    public function progressFor(string $klasse, string $studentName): array
    {
        $key = self::studentKey($studentName);

        $stmt = $this->conn->prepare('SELECT * FROM student_progress WHERE klasse = ? AND student_key = ? LIMIT 1');
        $stmt->execute([$klasse, $key]);
        $row = $stmt->fetch();

        if (!$row) {
            return $this->decorate([
                'klasse' => $klasse, 'student_key' => $key, 'display_name' => $studentName,
                'xp' => 0, 'level' => 1, 'streak' => 0,
                'avatar_seed' => $key !== '' ? $key : 'anon', 'avatar_style' => 'adventurer',
            ]);
        }

        return $this->decorate($row);
    }

    /**
     * Schreibt die Gutschrift fuer eine Abgabe fort.
     *
     * Doppelte Gutschriften sind ausgeschlossen: student_progress_awards
     * haelt die submission_id als Primaerschluessel.
     *
     * @return array<string,mixed> Der neue Stand, ergaenzt um die Gutschrift
     */
    public function award(int $submissionId, string $klasse, string $studentName, ?int $score): array
    {
        $key = self::studentKey($studentName);
        $heute = date('Y-m-d');

        $this->conn->beginTransaction();

        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM student_progress WHERE klasse = ? AND student_key = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$klasse, $key]);
            $stand = $stmt->fetch();

            if (!$stand) {
                $insert = $this->conn->prepare('
                    INSERT INTO student_progress (klasse, student_key, display_name, xp, level, streak, avatar_seed)
                    VALUES (?, ?, ?, 0, 1, 0, ?)
                ');
                $insert->execute([$klasse, $key, mb_substr($studentName, 0, 150), $key !== '' ? $key : 'anon']);

                $stmt->execute([$klasse, $key]);
                $stand = $stmt->fetch();
            }

            $progressId = (int)$stand['id'];

            // Bereits gutgeschrieben? Dann nur den Stand zurueckgeben.
            $bereits = $this->conn->prepare('SELECT xp_awarded FROM student_progress_awards WHERE submission_id = ?');
            $bereits->execute([$submissionId]);

            if ($bereits->fetch() !== false) {
                $this->conn->commit();

                return $this->decorate($stand) + ['awarded' => 0, 'already_awarded' => true];
            }

            $streak = $this->nextStreak((string)($stand['last_submission_date'] ?? ''), $heute, (int)$stand['streak']);

            $xp = self::XP_SUBMISSION;
            if ($score !== null) {
                $xp += (int)round(max(0, min(100, $score)) / 100 * self::XP_SCORE_BONUS);
            }
            $xp += min(self::XP_STREAK_MAX, $streak * self::XP_STREAK_BONUS);

            $neueXp = (int)$stand['xp'] + $xp;
            $neuesLevel = self::levelForXp($neueXp);

            $this->conn->prepare('
                UPDATE student_progress
                SET xp = ?, level = ?, streak = ?, last_submission_date = ?, display_name = ?
                WHERE id = ?
            ')->execute([$neueXp, $neuesLevel, $streak, $heute, mb_substr($studentName, 0, 150), $progressId]);

            $this->conn->prepare('
                INSERT INTO student_progress_awards (submission_id, progress_id, xp_awarded) VALUES (?, ?, ?)
            ')->execute([$submissionId, $progressId, $xp]);

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            error_log('Fortschritt konnte nicht gebucht werden: ' . $e->getMessage());

            return $this->progressFor($klasse, $studentName) + ['awarded' => 0, 'already_awarded' => false];
        }

        $stand['xp'] = $neueXp;
        $stand['level'] = $neuesLevel;
        $stand['streak'] = $streak;

        return $this->decorate($stand) + [
            'awarded'        => $xp,
            'already_awarded' => false,
            'level_up'       => $neuesLevel > (int)self::levelForXp((int)$stand['xp'] - $xp),
        ];
    }

    /**
     * Serie fortschreiben: gestern abgegeben verlaengert, heute erneut
     * abgegeben aendert nichts, eine Luecke setzt zurueck.
     */
    private function nextStreak(string $letzteAbgabe, string $heute, int $aktuell): int
    {
        if ($letzteAbgabe === '' || $letzteAbgabe === '0000-00-00') {
            return 1;
        }

        if ($letzteAbgabe === $heute) {
            return max(1, $aktuell);
        }

        $gestern = date('Y-m-d', strtotime($heute . ' -1 day'));

        return $letzteAbgabe === $gestern ? $aktuell + 1 : 1;
    }

    /**
     * Ergaenzt abgeleitete Werte fuer die Anzeige.
     *
     * @param array<string,mixed> $stand
     * @return array<string,mixed>
     */
    private function decorate(array $stand): array
    {
        $xp = (int)($stand['xp'] ?? 0);
        $level = self::levelForXp($xp);
        $basis = self::xpForLevel($level);
        $naechste = self::xpForLevel($level + 1);
        $spanne = max(1, $naechste - $basis);

        $stand['level'] = $level;
        $stand['xp_in_level'] = $xp - $basis;
        $stand['xp_for_next_level'] = $spanne;
        $stand['level_percent'] = (int)round(($xp - $basis) / $spanne * 100);

        return $stand;
    }

    /**
     * Rangliste einer Klasse - ohne Klarnamen, nur Vorname und Anfangsbuchstabe.
     *
     * @return list<array<string,mixed>>
     */
    public function classLeaderboard(string $klasse, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $stmt = $this->conn->prepare("
            SELECT display_name, xp, level, streak
            FROM student_progress
            WHERE klasse = ?
            ORDER BY xp DESC, updated_at ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$klasse]);

        return array_map(static function (array $r): array {
            $teile = preg_split('/\s+/', trim((string)$r['display_name'])) ?: [];
            $r['display_name'] = $teile[0] . (count($teile) > 1 ? ' ' . mb_substr(end($teile), 0, 1) . '.' : '');

            return $r;
        }, $stmt->fetchAll());
    }
}
