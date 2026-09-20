<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Bildgrenzen;
use PHPUnit\Framework\TestCase;

final class BildgrenzenTest extends TestCase
{
    /** @var list<string> */
    private array $dateien = [];

    protected function tearDown(): void
    {
        foreach ($this->dateien as $datei) {
            @unlink($datei);
        }
        $this->dateien = [];
    }

    /**
     * Ein PNG, das nur aus seinem Kopf besteht.
     *
     * getimagesize() liest die Masse aus dem IHDR-Block und ruehrt die
     * Bilddaten nicht an - genau deshalb ist die Pruefung guenstig. Fuer den
     * Test heisst das: ein Bild mit 30.000 x 30.000 Pixeln passt in 70 Byte.
     * Genau so sieht auch der Angriff aus.
     */
    private function pngKopf(int $breite, int $hoehe): string
    {
        $ihdr = 'IHDR'
            . pack('N', $breite)
            . pack('N', $hoehe)
            . chr(8)   // Bittiefe
            . chr(2)   // Farbtyp: Truecolor
            . chr(0) . chr(0) . chr(0);

        $daten = "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));

        $pfad = tempnam(sys_get_temp_dir(), 'bildgrenze') ?: '';
        file_put_contents($pfad, $daten);
        $this->dateien[] = $pfad;

        return $pfad;
    }

    public function testKleinesBildIstErlaubt(): void
    {
        self::assertFalse(Bildgrenzen::zuGross($this->pngKopf(4032, 3024)), 'Handyfoto mit 12 Megapixeln');
        self::assertFalse(Bildgrenzen::zuGross($this->pngKopf(5472, 3648)), 'Spiegelreflex mit 20 Megapixeln');
    }

    public function testZuVielePixelWerdenAbgewiesen(): void
    {
        // 30.000 x 30.000 = 900 Megapixel; GD belegte dafuer 3,6 GB.
        self::assertTrue(Bildgrenzen::zuGross($this->pngKopf(30000, 30000)));
    }

    public function testZuLangeKanteWirdAbgewiesen(): void
    {
        // Unter der Pixelgrenze, aber ein Streifen von 20.000 Pixeln Laenge.
        $pfad = $this->pngKopf(20000, 500);

        self::assertLessThan(Bildgrenzen::MAX_PIXEL, 20000 * 500, 'Voraussetzung des Tests');
        self::assertTrue(Bildgrenzen::zuGross($pfad));
    }

    public function testGrenzwertGenauErreichtIstErlaubt(): void
    {
        $kante = (int)sqrt(Bildgrenzen::MAX_PIXEL);

        self::assertFalse(Bildgrenzen::zuGross($this->pngKopf($kante, $kante)));
    }

    /**
     * Was kein lesbares Bild ist, gilt nicht als zu gross: GD fasst es
     * ohnehin nicht an, und eine Abweisung waere nur verwirrend.
     */
    public function testDateiOhneBildmasseGiltNichtAlsZuGross(): void
    {
        $pfad = tempnam(sys_get_temp_dir(), 'bildgrenze') ?: '';
        file_put_contents($pfad, 'kein Bild, nur Text');
        $this->dateien[] = $pfad;

        self::assertFalse(Bildgrenzen::zuGross($pfad));
        self::assertNull(Bildgrenzen::masse($pfad));
    }

    public function testFehlendeDateiGiltNichtAlsZuGross(): void
    {
        self::assertFalse(Bildgrenzen::zuGross(sys_get_temp_dir() . '/gibt-es-nicht-' . bin2hex(random_bytes(4))));
    }
}
