<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Ai\AIService;
use App\Ai\LessonPlanSchema;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Normalisierung der Vertretungsstunde.
 *
 * Das Template hat um 7:45 Uhr im Lehrerzimmer keine Gelegenheit, sich ueber
 * eine fehlende Schluesselstelle zu beschweren - es muss sich darauf
 * verlassen koennen, dass jedes Feld existiert und den erwarteten Typ hat.
 */
final class SubstitutePlanSanitizerTest extends TestCase
{
    /**
     * @param array<string,mixed> $antwort
     * @return array<string,mixed>
     */
    private function sanitize(array $antwort, int $dauer = 45): array
    {
        $methode = new ReflectionMethod(AIService::class, 'sanitizePlan');
        $methode->setAccessible(true);

        return $methode->invoke(new AIService(), $antwort, $dauer);
    }

    public function testVollstaendigeAntwortBleibtErhalten(): void
    {
        $plan = $this->sanitize([
            'titel'          => 'Brüche addieren',
            'einstieg'       => 'Drei Schüler erklären lassen.',
            'arbeitsauftrag' => 'Bearbeitet die Aufgaben 1 bis 4.',
            'aufgaben'       => [['nummer' => 1, 'aufgabe' => '1/3 + 1/2', 'loesung' => 'Hauptnenner 6, also 5/6.']],
            'material'       => 'Tafel, Heft',
            'differenzierung' => ['leichter' => 'Nur Aufgabe 1.', 'schwerer' => 'Eigenes Beispiel.'],
            'sicherung'      => 'Zwei Ergebnisse vorlesen lassen.',
            'zeitplan'       => [['minuten' => 5, 'abschnitt' => 'Einstieg', 'sozialform' => 'Gespräch']],
            'hinweis_fachlehrer' => 'Hefte einsammeln.',
        ]);

        self::assertSame('Brüche addieren', $plan['titel']);
        self::assertSame('Hauptnenner 6, also 5/6.', $plan['aufgaben'][0]['loesung']);
        self::assertSame('Eigenes Beispiel.', $plan['differenzierung']['schwerer']);
        self::assertSame(5, $plan['zeitplan_summe']);
    }

    /**
     * Eine halbe Antwort darf keine leere Seite ergeben.
     */
    public function testFehlendeFelderWerdenZuLeerenZeichenketten(): void
    {
        $plan = $this->sanitize([]);

        foreach (['einstieg', 'arbeitsauftrag', 'material', 'sicherung', 'hinweis_fachlehrer'] as $feld) {
            self::assertSame('', $plan[$feld], "Feld $feld fehlt");
        }

        self::assertSame([], $plan['aufgaben']);
        self::assertSame([], $plan['zeitplan']);
        self::assertSame('', $plan['differenzierung']['leichter']);
        self::assertSame(0, $plan['zeitplan_summe']);
    }

    /**
     * Ein Abschnitt von 500 Minuten in einer 45-Minuten-Stunde ist ein
     * Ausreisser, kein Plan.
     */
    public function testUeberlangeAbschnitteWerdenAufDieStundeGedeckelt(): void
    {
        $plan = $this->sanitize([
            'zeitplan' => [
                ['minuten' => 500, 'abschnitt' => 'Arbeitsphase', 'sozialform' => 'Einzelarbeit'],
                ['minuten' => -3, 'abschnitt' => 'Sicherung', 'sozialform' => 'Gespräch'],
            ],
        ]);

        self::assertSame(45, $plan['zeitplan'][0]['minuten']);
        self::assertSame(0, $plan['zeitplan'][1]['minuten']);
        self::assertSame(45, $plan['zeitplan_summe']);
    }

    public function testUnbrauchbareEintraegeWerdenUebersprungen(): void
    {
        $plan = $this->sanitize([
            'aufgaben' => ['nur ein String', ['aufgabe' => 'Berechne 2+2', 'loesung' => '4']],
            'zeitplan' => ['kaputt', ['minuten' => 10, 'abschnitt' => 'Einstieg', 'sozialform' => 'Gespräch']],
        ]);

        self::assertCount(1, $plan['aufgaben']);
        self::assertSame('Berechne 2+2', $plan['aufgaben'][0]['aufgabe']);
        self::assertCount(1, $plan['zeitplan']);
    }

    /**
     * Fehlt die Nummer, ergibt sie sich aus der Reihenfolge - im PDF steht
     * sonst zweimal "Aufgabe 0".
     */
    public function testFehlendeAufgabennummerWirdErgaenzt(): void
    {
        $plan = $this->sanitize([
            'aufgaben' => [
                ['aufgabe' => 'Erste', 'loesung' => 'A'],
                ['aufgabe' => 'Zweite', 'loesung' => 'B'],
            ],
        ]);

        self::assertSame(1, $plan['aufgaben'][0]['nummer']);
        self::assertSame(2, $plan['aufgaben'][1]['nummer']);
    }

    public function testUeberlangerTitelWirdGekuerzt(): void
    {
        $plan = $this->sanitize(['titel' => str_repeat('a', 300)]);

        self::assertSame(120, mb_strlen($plan['titel']));
    }

    /**
     * Schema und Auswertung duerfen nicht auseinanderlaufen: was das
     * Template liest, muss das Modell auch liefern sollen.
     */
    public function testSchemaVerlangtAlleFelderDieDerPlanTraegt(): void
    {
        $schema = LessonPlanSchema::forPlan();
        $plan = $this->sanitize([]);

        foreach (array_keys($plan) as $feld) {
            if ($feld === 'zeitplan_summe') {
                continue; // wird berechnet, nicht geliefert
            }

            self::assertArrayHasKey($feld, $schema['properties'], "Schema kennt $feld nicht");
            self::assertContains($feld, $schema['required'], "Schema verlangt $feld nicht");
        }
    }
}
