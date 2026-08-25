<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Live\LessonRepository;
use PHPUnit\Framework\TestCase;

/**
 * Das Zeitraster hinter der Stundenvermutung.
 *
 * Die Vermutung darf danebenliegen - sie ist mit einem Griff korrigierbar.
 * Sie darf aber nicht unsinnig sein: wer in der grossen Pause das Handy
 * zueckt, bereitet die kommende Stunde vor, nicht die vergangene.
 */
final class LessonScheduleTest extends TestCase
{
    public function testVorSchulbeginnGiltDieErsteStunde(): void
    {
        self::assertSame(1, LessonRepository::periodForMinute(0));      // 00:00
        self::assertSame(1, LessonRepository::periodForMinute(7 * 60)); // 07:00
    }

    public function testInnerhalbEinerStunde(): void
    {
        self::assertSame(1, LessonRepository::periodForMinute(8 * 60));       // 08:00
        self::assertSame(4, LessonRepository::periodForMinute(10 * 60 + 45)); // 10:45
    }

    /**
     * Der Kern: in der Pause zaehlt die naechste Stunde.
     */
    public function testInDerPauseGiltDieKommendeStunde(): void
    {
        self::assertSame(3, LessonRepository::periodForMinute(9 * 60 + 20));  // 09:20, grosse Pause
        self::assertSame(5, LessonRepository::periodForMinute(11 * 60 + 15)); // 11:15
        self::assertSame(7, LessonRepository::periodForMinute(13 * 60));      // 13:00, Mittagspause
    }

    /**
     * Die Stunden stossen aneinander: 08:30 ist Ende der ersten und Beginn
     * der zweiten. In diesem Moment beginnt die zweite - nicht die erste
     * laeuft noch.
     */
    public function testDerBeginnGehoertZurStundeDasEndeZurNaechsten(): void
    {
        [$von, $bis] = LessonRepository::PERIODS[2];

        self::assertSame(2, LessonRepository::periodForMinute($von));
        self::assertSame(2, LessonRepository::periodForMinute($bis - 1));
        self::assertNotSame(2, LessonRepository::periodForMinute($bis));
    }

    public function testStundenwechselOhneVersatz(): void
    {
        // 08:30 - die zweite Stunde beginnt, die erste ist vorbei.
        self::assertSame(2, LessonRepository::periodForMinute(8 * 60 + 30));
        // 10:20 - dritte endet, vierte beginnt ohne Pause dazwischen.
        self::assertSame(4, LessonRepository::periodForMinute(10 * 60 + 20));
    }

    public function testNachSchulschlussBleibtDieLetzteStunde(): void
    {
        self::assertSame(10, LessonRepository::periodForMinute(23 * 60));
    }

    public function testCurrentPeriodRechnetAusDerUhrzeit(): void
    {
        self::assertSame(2, LessonRepository::currentPeriod(mktime(9, 0, 0, 8, 25, 2026) ?: null));
        self::assertSame(6, LessonRepository::currentPeriod(mktime(12, 30, 0, 8, 25, 2026) ?: null));
    }

    /**
     * Luecken zwischen den Stunden darf es nicht geben - sonst faellt eine
     * Uhrzeit durch das Raster und die Vermutung stuerzt ab.
     */
    public function testJedeMinuteDesTagesErgibtEineStunde(): void
    {
        for ($minute = 0; $minute < 1440; $minute++) {
            $stunde = LessonRepository::periodForMinute($minute);

            self::assertArrayHasKey($stunde, LessonRepository::PERIODS, "Minute $minute ohne Stunde");
        }
    }
}
