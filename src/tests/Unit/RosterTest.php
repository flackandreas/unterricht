<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Homework\Gamification;
use App\Live\Roster;
use PHPUnit\Framework\TestCase;

final class RosterTest extends TestCase
{
    /**
     * Der eigentliche Zweck der Umstellung: eine aus IServ kopierte Zeile
     * muss denselben Schluessel ergeben wie der Name, den die Schuelerin
     * bisher selbst in die Abgabe getippt hat. Sonst greift die
     * Verknuepfung mit dem vorhandenen Lernfortschritt nicht.
     */
    public function testNachnameZuerstErgibtDenselbenSchluessel(): void
    {
        $ausExport = Roster::parseNames('Mustermann, Max');

        self::assertSame(['Max Mustermann'], $ausExport);
        self::assertSame(
            Gamification::studentKey('Max Mustermann'),
            Gamification::studentKey($ausExport[0])
        );
    }

    public function testTrennerSemikolonUndTabulator(): void
    {
        self::assertSame(['Erika Musterfrau'], Roster::parseNames('Musterfrau; Erika'));
        self::assertSame(['Erika Musterfrau'], Roster::parseNames("Musterfrau\tErika"));
    }

    /**
     * Bei drei Feldern wird nicht geraten - eine verdrehte Zeile faellt in
     * der Vorschau sonst kaum auf.
     */
    public function testDreiFelderWerdenNichtUmgedreht(): void
    {
        self::assertSame(['Mustermann Max 7b'], Roster::parseNames("Mustermann\tMax\t7b"));
    }

    public function testNummerierungUndAufzaehlungszeichenFallenWeg(): void
    {
        $namen = Roster::parseNames("1. Max Mustermann\n2) Erika Musterfrau\n- Jonas Klein\n• Aylin Yilmaz");

        self::assertSame(['Max Mustermann', 'Erika Musterfrau', 'Jonas Klein', 'Aylin Yilmaz'], $namen);
    }

    public function testLeerzeilenUndUeberfluessigeLeerzeichen(): void
    {
        self::assertSame(['Max Mustermann'], Roster::parseNames("\n\n   Max    Mustermann   \n\n"));
    }

    /**
     * Zweimal dieselbe Person in einer eingefuegten Liste ergibt einen
     * Eintrag - die Tabelle haette den zweiten ohnehin abgewiesen.
     */
    public function testDoppelteNamenWerdenZusammengefuehrt(): void
    {
        self::assertSame(['Max Mustermann'], Roster::parseNames("Max Mustermann\nMAX MUSTERMANN\nMustermann, Max"));
    }

    public function testNamenOhneVerwertbareZeichenFallenWeg(): void
    {
        self::assertSame([], Roster::parseNames("---\n???\n   "));
    }

    public function testUeberlangerNameWirdGekuerzt(): void
    {
        $namen = Roster::parseNames(str_repeat('a', 400));

        self::assertCount(1, $namen);
        self::assertSame(150, mb_strlen($namen[0]));
    }
}
