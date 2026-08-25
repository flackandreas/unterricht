<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Substitute\PlanService;
use PHPUnit\Framework\TestCase;

/**
 * Der beim Anfordern festgehaltene Kontext.
 *
 * Er wird gespeichert, damit spaeter nachvollziehbar bleibt, worauf sich die
 * KI gestuetzt hat. Beim Lesen darf nichts scheitern - weder ein Auftrag aus
 * der Zeit vor den Hausaufgaben im Kontext noch eine leere Spalte.
 */
final class PlanContextTest extends TestCase
{
    public function testThemenUndHausaufgabenWerdenGelesen(): void
    {
        $kontext = PlanService::contextFromJson(json_encode([
            'themen' => [
                ['lesson_date' => '2026-08-18', 'period' => 3, 'topic' => 'Brüche addieren'],
            ],
            'hausaufgaben' => [
                ['titel' => 'Übungsblatt 4', 'aufgabe' => 'Rechne aus', 'fehler' => 'Hauptnenner vergessen'],
            ],
        ]) ?: null);

        self::assertCount(1, $kontext['themen']);
        self::assertCount(1, $kontext['hausaufgaben']);
        self::assertSame('Hauptnenner vergessen', $kontext['hausaufgaben'][0]['fehler']);
    }

    /**
     * Die aeltere Form war eine reine Themenliste. Ein Auftrag, der damit in
     * der Warteschlange steht, muss weiterlaufen.
     */
    public function testAeltereReineThemenlisteWirdVerstanden(): void
    {
        $kontext = PlanService::contextFromJson(json_encode([
            ['lesson_date' => '2026-08-18', 'period' => 3, 'topic' => 'Brüche addieren'],
            ['lesson_date' => '2026-08-11', 'period' => 3, 'topic' => 'Brüche kürzen'],
        ]) ?: null);

        self::assertCount(2, $kontext['themen']);
        self::assertSame([], $kontext['hausaufgaben']);
    }

    /**
     * @return array<string,array{0:string|null}>
     */
    public static function unbrauchbareEingaben(): array
    {
        return [
            'null'          => [null],
            'leer'          => [''],
            'kaputtes JSON' => ['{nicht wirklich json'],
            'Zeichenkette'  => ['"nur ein String"'],
            'Zahl'          => ['42'],
        ];
    }

    /**
     * @dataProvider unbrauchbareEingaben
     */
    public function testUnbrauchbareEingabenErgebenLeerenKontext(?string $json): void
    {
        $kontext = PlanService::contextFromJson($json);

        self::assertSame([], $kontext['themen']);
        self::assertSame([], $kontext['hausaufgaben']);
    }

    public function testEintraegeDieKeineListenSindFallenWeg(): void
    {
        $kontext = PlanService::contextFromJson(json_encode([
            'themen'       => ['nur ein String', ['topic' => 'Brüche']],
            'hausaufgaben' => 'gar keine Liste',
        ]) ?: null);

        self::assertCount(1, $kontext['themen']);
        self::assertSame([], $kontext['hausaufgaben']);
    }
}
