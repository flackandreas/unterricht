<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Migrator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MigratorTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/migration-state-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
    }

    /**
     * Solange der Satz an Migrationsdateien unveraendert ist, darf kein
     * Datenbankzugriff noetig sein - genau das war vorher der Fall.
     */
    public function testIstAktuellNurBeiPassendemVermerk(): void
    {
        $migrator = new Migrator(null, $this->stateFile);

        self::assertFalse($migrator->isUpToDate(), 'ohne Vermerk nicht aktuell');

        file_put_contents($this->stateFile, $migrator->fingerprint());
        self::assertTrue($migrator->isUpToDate(), 'mit passendem Vermerk aktuell');

        file_put_contents($this->stateFile, 'veralteter-stand');
        self::assertFalse($migrator->isUpToDate(), 'bei abweichendem Vermerk erneut laufen');
    }

    public function testFingerprintIstStabil(): void
    {
        $a = (new Migrator(null, $this->stateFile))->fingerprint();
        $b = (new Migrator(null, $this->stateFile))->fingerprint();

        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $a);
    }

    /**
     * Ein Semikolon in einer Kommentarzeile darf die Zerlegung nicht
     * durcheinanderbringen.
     */
    public function testStatementsIgnoriertKommentare(): void
    {
        $methode = new ReflectionMethod(Migrator::class, 'statements');
        $methode->setAccessible(true);

        $sql = "-- Hinweis: hier steht ein; Semikolon\n"
            . "CREATE TABLE a (id INT);\n"
            . "-- noch ein Kommentar\n"
            . "ALTER TABLE a ADD COLUMN b INT;\n";

        $statements = $methode->invoke(new Migrator(null, $this->stateFile), $sql);

        self::assertCount(2, $statements);
        self::assertStringStartsWith('CREATE TABLE', $statements[0]);
        self::assertStringStartsWith('ALTER TABLE', $statements[1]);
    }

    public function testAlleMigrationsdateienExistieren(): void
    {
        $reflection = new \ReflectionClass(Migrator::class);
        $dateien = $reflection->getConstant('FILES');
        $verzeichnis = dirname(__DIR__, 2);

        foreach ($dateien as $datei) {
            self::assertFileExists($verzeichnis . '/' . $datei, "Migration $datei fehlt");
        }
    }

    public function testKeineDoppeltenMigrationen(): void
    {
        $reflection = new \ReflectionClass(Migrator::class);
        $dateien = $reflection->getConstant('FILES');

        self::assertSame(array_values(array_unique($dateien)), array_values($dateien));
    }
}
