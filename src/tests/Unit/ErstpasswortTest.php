<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Erstpasswoerter aus dem CSV-Import.
 *
 * Vorher bekam jede importierte Lehrkraft dasselbe, im Quelltext stehende
 * Passwort. Dass hier wirklich je Konto ein eigener Zufallswert entsteht, ist
 * der ganze Punkt der Aenderung - und nichts, was man einer Funktion ansieht.
 */
final class ErstpasswortTest extends TestCase
{
    public function testFormatIstLesbarGruppiert(): void
    {
        self::assertMatchesRegularExpression(
            '/^[a-z2-9]{4}(-[a-z2-9]{4})+$/',
            erstes_passwort()
        );
    }

    public function testJederAufrufErgibtEtwasAnderes(): void
    {
        $gesehen = [];
        for ($i = 0; $i < 200; $i++) {
            $gesehen[erstes_passwort()] = true;
        }

        self::assertCount(200, $gesehen, 'Erstpasswörter dürfen sich nicht wiederholen');
    }

    public function testVerwechselbareZeichenKommenNichtVor(): void
    {
        $alle = '';
        for ($i = 0; $i < 200; $i++) {
            $alle .= erstes_passwort();
        }

        // Die Ziffern 0 und 1 fallen weg, dazu das kleine l. Damit bleiben o
        // und i eindeutig: es gibt keine Ziffer mehr, mit der man sie
        // verwechseln koennte. Das Passwort wird auf Papier weitergegeben.
        foreach (['0', '1', 'l'] as $zeichen) {
            self::assertStringNotContainsString($zeichen, $alle, "Zeichen '$zeichen' ist verwechselbar");
        }
    }

    public function testKuerzeAlsAchtZeichenIstNichtMoeglich(): void
    {
        foreach ([-5, 0, 1, 8] as $wunsch) {
            $roh = str_replace('-', '', erstes_passwort($wunsch));
            self::assertGreaterThanOrEqual(8, strlen($roh), "Wunschlänge $wunsch");
        }
    }

    public function testLaengereWuenscheWerdenBeachtet(): void
    {
        $roh = str_replace('-', '', erstes_passwort(20));

        self::assertSame(20, strlen($roh));
    }
}
