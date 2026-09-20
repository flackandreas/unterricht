<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feedback\Auswahloptionen;
use PHPUnit\Framework\TestCase;

final class AuswahloptionenTest extends TestCase
{
    public function testZerlegtUndTrimmt(): void
    {
        self::assertSame(
            ['zu schnell', 'genau richtig', 'zu langsam'],
            Auswahloptionen::aus('zu schnell, genau richtig ,zu langsam')
        );
    }

    public function testLeereEintraegeFallenWeg(): void
    {
        self::assertSame(['a', 'b'], Auswahloptionen::aus('a,,b,  ,'));
        self::assertSame([], Auswahloptionen::aus(''));
        self::assertSame([], Auswahloptionen::aus(null));
    }

    /**
     * empty('0') ist wahr - die frueheren Zerlegungen haben eine
     * Antwortmoeglichkeit "0" deshalb still verschluckt.
     */
    public function testNullBleibtEineAntwortmoeglichkeit(): void
    {
        self::assertSame(['0', '1', '2'], Auswahloptionen::aus('0,1,2'));
    }

    public function testNurVorgeseheneAntwortenGelten(): void
    {
        $optionen = 'ja,nein,weiss nicht';

        self::assertTrue(Auswahloptionen::gueltig($optionen, 'ja'));
        self::assertTrue(Auswahloptionen::gueltig($optionen, '  weiss nicht  '), 'Leerraum wird abgeschnitten');

        self::assertFalse(Auswahloptionen::gueltig($optionen, 'vielleicht'));
        self::assertFalse(Auswahloptionen::gueltig($optionen, ''));
        self::assertFalse(Auswahloptionen::gueltig($optionen, 'JA'), 'Gross- und Kleinschreibung zaehlt');
        self::assertFalse(
            Auswahloptionen::gueltig($optionen, 'Die Lehrkraft ist unfair'),
            'genau das ging vorher ungeprueft in die Auswertung'
        );
    }

    public function testOhneOptionenIstNichtsGueltig(): void
    {
        self::assertFalse(Auswahloptionen::gueltig(null, 'irgendwas'));
        self::assertFalse(Auswahloptionen::gueltig('', ''));
    }
}
