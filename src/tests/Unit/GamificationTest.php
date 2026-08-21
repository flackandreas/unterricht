<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Homework\Gamification;
use PHPUnit\Framework\TestCase;

final class GamificationTest extends TestCase
{
    /**
     * Der Schluessel muss Schreibweisen zusammenfuehren, sonst zerfaellt der
     * Fortschritt einer Person in mehrere Datensaetze.
     */
    public function testStudentKeyNormalisiertSchreibweisen(): void
    {
        $erwartet = Gamification::studentKey('Max Mustermann');

        self::assertSame($erwartet, Gamification::studentKey('  max   mustermann '));
        self::assertSame($erwartet, Gamification::studentKey('MAX MUSTERMANN'));
        self::assertSame($erwartet, Gamification::studentKey('Max-Mustermann'));
    }

    public function testStudentKeyErsetztUmlaute(): void
    {
        self::assertSame('joerg mueller', Gamification::studentKey('Jörg Müller'));
        self::assertSame('strasser', Gamification::studentKey('Straßer'));
    }

    public function testStudentKeyUnterscheidetVerschiedeneNamen(): void
    {
        self::assertNotSame(
            Gamification::studentKey('Max Mustermann'),
            Gamification::studentKey('Erika Mustermann')
        );
    }

    public function testLeererNameErgibtLeerenSchluessel(): void
    {
        self::assertSame('', Gamification::studentKey('   '));
        self::assertSame('', Gamification::studentKey('!!!'));
    }

    public function testLevelSchwellenSindKonsistent(): void
    {
        self::assertSame(1, Gamification::levelForXp(0));
        self::assertSame(0, Gamification::xpForLevel(1));

        // Wer genau die Schwelle erreicht, steht auf dem neuen Level.
        for ($level = 2; $level <= 12; $level++) {
            $schwelle = Gamification::xpForLevel($level);

            self::assertSame($level, Gamification::levelForXp($schwelle), "Schwelle Level $level");
            self::assertSame($level - 1, Gamification::levelForXp($schwelle - 1), "Kurz vor Level $level");
        }
    }

    public function testLevelWaechstMonoton(): void
    {
        $vorher = 1;
        for ($xp = 0; $xp <= 20000; $xp += 137) {
            $level = Gamification::levelForXp($xp);
            self::assertGreaterThanOrEqual($vorher, $level);
            $vorher = $level;
        }
    }

    public function testNegativeXpErgibtLevelEins(): void
    {
        self::assertSame(1, Gamification::levelForXp(-500));
    }
}
