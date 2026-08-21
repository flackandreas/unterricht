<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Ai\AIService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Prueft die Normalisierung der Modellantwort.
 *
 * Vorher gingen unbrauchbare Koordinaten stillschweigend durch und wurden zu
 * einer Box an Position 0/0 mit Groesse 0.
 */
final class EvaluationSanitizerTest extends TestCase
{
    private function sanitize(array $antwort, array $criteria = []): array
    {
        $methode = new ReflectionMethod(AIService::class, 'sanitizeEvaluation');
        $methode->setAccessible(true);

        return $methode->invoke(new AIService(), $antwort, $criteria);
    }

    public function testGueltigerMarkerBleibtErhalten(): void
    {
        $ergebnis = $this->sanitize([
            'student_feedback' => 'Gut gemacht!',
            'score' => 80,
            'errors' => [
                ['step_text' => 'Zeile 3', 'description' => 'Vorzeichen', 'box_2d' => [100, 200, 300, 400]],
            ],
        ]);

        self::assertCount(1, $ergebnis['errors']);
        self::assertSame([100, 200, 300, 400], $ergebnis['errors'][0]['box_2d']);
        self::assertSame(80, $ergebnis['score']);
    }

    /**
     * @return list<array{0:string,1:mixed}>
     */
    public static function unbrauchbareBoxen(): array
    {
        return [
            'zu wenige Werte'   => ['drei Werte', [10, 20, 30]],
            'zu viele Werte'    => ['fünf Werte', [10, 20, 30, 40, 50]],
            'nicht numerisch'   => ['Text statt Zahl', [10, 'oben', 30, 40]],
            'keine Höhe'        => ['ymax == ymin', [100, 200, 100, 400]],
            'keine Breite'      => ['xmax == xmin', [100, 200, 300, 200]],
            'verdrehte Achsen'  => ['ymax < ymin', [300, 200, 100, 400]],
            'gar kein Array'    => ['String statt Array', 'irgendwas'],
        ];
    }

    /**
     * @dataProvider unbrauchbareBoxen
     */
    public function testUnbrauchbareBoxenWerdenVerworfen(string $fall, mixed $box): void
    {
        $ergebnis = $this->sanitize([
            'errors' => [['description' => $fall, 'box_2d' => $box]],
        ]);

        self::assertSame([], $ergebnis['errors'], $fall . ' hätte verworfen werden müssen');
    }

    public function testKoordinatenWerdenAufWertebereichBegrenzt(): void
    {
        $ergebnis = $this->sanitize([
            'errors' => [['description' => 'x', 'box_2d' => [-50, -10, 5000, 3000]]],
        ]);

        self::assertSame([0, 0, 1000, 1000], $ergebnis['errors'][0]['box_2d']);
    }

    public function testPunktzahlWirdBegrenzt(): void
    {
        self::assertSame(100, $this->sanitize(['score' => 250])['score']);
        self::assertSame(0, $this->sanitize(['score' => -10])['score']);
        self::assertNull($this->sanitize(['score' => 'sehr gut'])['score']);
    }

    /**
     * Das Modell lieferte Texte gelegentlich als verschachteltes Array.
     */
    public function testArrayTexteWerdenZuStrings(): void
    {
        $ergebnis = $this->sanitize([
            'student_feedback' => ['Erster Punkt', ['Zweiter', 'Dritter']],
            'teacher_notes' => 42,
        ]);

        self::assertSame("Erster Punkt\nZweiter\nDritter", $ergebnis['student_feedback']);
        self::assertSame('42', $ergebnis['teacher_notes']);
    }

    public function testFehlendeFelderErgebenLeereStrings(): void
    {
        $ergebnis = $this->sanitize([]);

        self::assertSame('', $ergebnis['student_feedback']);
        self::assertSame('', $ergebnis['teacher_notes']);
        self::assertSame([], $ergebnis['errors']);
        self::assertNull($ergebnis['score']);
    }

    // --- Bewertungsraster ------------------------------------------------

    /**
     * @return list<array{id:int,label:string,max_points:int}>
     */
    private function raster(): array
    {
        return [
            ['id' => 1, 'label' => 'Rechenweg', 'max_points' => 10],
            ['id' => 2, 'label' => 'Ergebnis', 'max_points' => 5],
            ['id' => 3, 'label' => 'Darstellung', 'max_points' => 5],
        ];
    }

    public function testScoreWirdAusRasterBerechnet(): void
    {
        $ergebnis = $this->sanitize([
            'score' => 99, // wird durch das Raster überschrieben
            'criteria' => [
                ['id' => 1, 'points' => 10, 'comment' => 'sauber'],
                ['id' => 2, 'points' => 5, 'comment' => 'richtig'],
                ['id' => 3, 'points' => 0, 'comment' => 'unleserlich'],
            ],
        ], $this->raster());

        // 15 von 20 Punkten = 75
        self::assertSame(75, $ergebnis['score']);
        self::assertCount(3, $ergebnis['criteria']);
    }

    public function testPunkteWerdenAufMaximumBegrenzt(): void
    {
        $ergebnis = $this->sanitize([
            'criteria' => [['id' => 2, 'points' => 999, 'comment' => 'x']],
        ], $this->raster());

        self::assertSame(5.0, $ergebnis['criteria'][0]['points']);
    }

    public function testNegativePunkteWerdenAufNullGesetzt(): void
    {
        $ergebnis = $this->sanitize([
            'criteria' => [['id' => 1, 'points' => -5, 'comment' => 'x']],
        ], $this->raster());

        self::assertSame(0.0, $ergebnis['criteria'][0]['points']);
    }

    public function testUnbekannteUndDoppelteKriterienWerdenIgnoriert(): void
    {
        $ergebnis = $this->sanitize([
            'criteria' => [
                ['id' => 1, 'points' => 8, 'comment' => 'erste Bewertung'],
                ['id' => 1, 'points' => 2, 'comment' => 'doppelt'],
                ['id' => 99, 'points' => 10, 'comment' => 'gibt es nicht'],
            ],
        ], $this->raster());

        self::assertCount(1, $ergebnis['criteria']);
        self::assertSame(8.0, $ergebnis['criteria'][0]['points']);
    }
}
