<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die Ablage loest Pfade aus der Datenbank auf. Ein Fehler hier wuerde
 * Pfadtraversierung ermoeglichen.
 */
final class StorageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/storage.php';
    }

    /**
     * @return list<array{0:string}>
     */
    public static function boesePfade(): array
    {
        return [
            ['uploads/homework/../../../etc/passwd'],
            ['../../etc/passwd'],
            ['/etc/passwd'],
            ['uploads/../config/database.php'],
            ['uploads/homework/'],
            ['uploads//homework/x.jpg'],
            ['uploads/unbekannt/x.jpg'],
            ['homework/x.jpg'],
            [''],
        ];
    }

    /**
     * @dataProvider boesePfade
     */
    public function testUnzulaessigePfadeWerdenAbgewiesen(string $pfad): void
    {
        self::assertNull(storage_resolve($pfad), "Pfad hätte abgewiesen werden müssen: $pfad");
    }

    public function testNullPfadIstZulaessig(): void
    {
        self::assertNull(storage_resolve(null));
    }

    public function testNichtVorhandeneDateiErgibtNull(): void
    {
        self::assertNull(storage_resolve('uploads/homework/gibtesnicht.jpg'));
    }

    public function testZufallsdateinameHatKorrektesFormat(): void
    {
        $name = storage_random_filename('JPG');

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $name);
        self::assertNotSame($name, storage_random_filename('jpg'), 'Namen müssen unvorhersagbar sein');
    }

    /**
     * @return list<array{0:string}>
     */
    public static function unzulaessigeEndungen(): array
    {
        return [['../php'], ['p.h.p'], ['phtml'], ['php8'], ['html'], ['svg'], [''], ['exe']];
    }

    /**
     * @dataProvider unzulaessigeEndungen
     */
    public function testUnzulaessigeEndungenWerdenZuBin(string $endung): void
    {
        self::assertStringEndsWith('.bin', storage_random_filename($endung), "Endung '$endung'");
    }

    public function testZulaessigeEndungenBleiben(): void
    {
        foreach (['jpg', 'jpeg', 'png', 'webp', 'pdf'] as $endung) {
            self::assertStringEndsWith('.' . $endung, storage_random_filename($endung));
        }
    }
}
