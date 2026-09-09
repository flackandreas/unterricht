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

    /**
     * Der Vermerk gilt fuer eine bestimmte Datenbank, nicht nur fuer den Code.
     *
     * Ohne diese Trennung reichte es, dass ein zweiter Container mit
     * demselben eingehaengten src/ auf eine andere Datenbank zeigte: der eine
     * spielte die Migrationen ein und schrieb den Vermerk, der andere hielt
     * sich daraufhin fuer fertig und lief gegen ein Schema ohne die neue
     * Tabelle.
     */
    public function testVermerkUnterscheidetDieDatenbank(): void
    {
        $lies = static function (): string {
            $eigenschaft = new \ReflectionProperty(Migrator::class, 'stateFile');

            return (string) $eigenschaft->getValue(new Migrator());
        };

        $vorher = [$_ENV['DB_HOST'] ?? null, $_ENV['DB_NAME'] ?? null];

        $_ENV['DB_HOST'] = 'db';
        $_ENV['DB_NAME'] = 'db_unterricht';
        $eine = $lies();

        $_ENV['DB_NAME'] = 'db_unterricht_zweite_schule';
        $andere = $lies();

        $_ENV['DB_HOST'] = 'anderer-host';
        $_ENV['DB_NAME'] = 'db_unterricht';
        $dritte = $lies();

        [$_ENV['DB_HOST'], $_ENV['DB_NAME']] = $vorher;
        foreach (['DB_HOST', 'DB_NAME'] as $name) {
            if ($_ENV[$name] === null) {
                unset($_ENV[$name]);
            }
        }

        self::assertNotSame($eine, $andere, 'Zwei Datenbanken brauchen zwei Vermerke.');
        self::assertNotSame($eine, $dritte, 'Zwei Hosts brauchen zwei Vermerke.');

        // Nicht in storage/: das Verzeichnis wird vom Host eingehaengt und
        // von jedem Container geteilt, der dieselbe Quelle benutzt.
        self::assertStringNotContainsString('/storage/', $eine);
        self::assertStringStartsWith(sys_get_temp_dir(), $eine);
    }
}
