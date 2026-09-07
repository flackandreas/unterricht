<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feedback\FeedbackRepository;
use PHPUnit\Framework\TestCase;

/**
 * Gruppierung der Sitzungen fuer die Verlaufsdiagramme.
 */
final class FeedbackRepositoryTest extends TestCase
{
    /**
     * @return list<array<string,mixed>>
     */
    private function sitzungen(): array
    {
        return [
            ['id' => 1, 'klasse' => '9a', 'fach' => 'Mathe', 'created_at' => '2026-01-10 08:00:00', 'questions' => []],
            ['id' => 2, 'klasse' => '9a', 'fach' => 'Deutsch', 'created_at' => '2026-01-11 08:00:00', 'questions' => []],
            ['id' => 3, 'klasse' => '9a', 'fach' => 'Mathe', 'created_at' => '2026-01-17 08:00:00', 'questions' => []],
            ['id' => 4, 'klasse' => '10b', 'fach' => 'Mathe', 'created_at' => '2026-01-18 08:00:00', 'questions' => []],
        ];
    }

    public function testGruppiertNachKlasseUndFach(): void
    {
        $gruppen = FeedbackRepository::groupByClassAndSubject($this->sitzungen());

        self::assertCount(3, $gruppen);
        self::assertArrayHasKey('9a - Mathe', $gruppen);
        self::assertArrayHasKey('9a - Deutsch', $gruppen);
        self::assertArrayHasKey('10b - Mathe', $gruppen);
    }

    /**
     * Dieselbe Klasse in verschiedenen Fächern darf nicht in einer Kurve landen.
     */
    public function testTrenntFaecherDerselbenKlasse(): void
    {
        $gruppen = FeedbackRepository::groupByClassAndSubject($this->sitzungen());

        self::assertCount(2, $gruppen['9a - Mathe']['sessions']);
        self::assertCount(1, $gruppen['9a - Deutsch']['sessions']);
    }

    public function testBehaeltChronologischeReihenfolge(): void
    {
        $gruppen = FeedbackRepository::groupByClassAndSubject($this->sitzungen());
        $mathe = $gruppen['9a - Mathe']['sessions'];

        self::assertSame(1, $mathe[0]['id']);
        self::assertSame(3, $mathe[1]['id']);
    }

    public function testUebernimmtKlasseUndFachInDieGruppe(): void
    {
        $gruppen = FeedbackRepository::groupByClassAndSubject($this->sitzungen());

        self::assertSame('10b', $gruppen['10b - Mathe']['klasse']);
        self::assertSame('Mathe', $gruppen['10b - Mathe']['fach']);
    }

    public function testLeereEingabeErgibtLeeresErgebnis(): void
    {
        self::assertSame([], FeedbackRepository::groupByClassAndSubject([]));
    }
}
