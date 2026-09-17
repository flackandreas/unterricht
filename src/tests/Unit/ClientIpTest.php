<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die Client-Adresse ist die Kennung fuer saemtliche Mengenbegrenzungen -
 * Anmeldeversuche, Abgaben, Feedback und die kostenpflichtigen KI-Aufrufe.
 * Vorher wurde X-Forwarded-For ungeprueft uebernommen: damit bestimmte jeder
 * Aufrufer seinen eigenen Zaehler und war praktisch unbegrenzt.
 */
final class ClientIpTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/request.php';
    }

    protected function setUp(): void
    {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
    }

    public function testOhneKonfigurationZaehltAlleinDieVerbindung(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        self::assertSame('203.0.113.7', request_client_ip());
    }

    public function testFremderHeaderVonUnbekannterAdresseZaehltNicht(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';

        self::assertSame('203.0.113.7', request_client_ip());
    }

    public function testHinterEingetragenemVermittlerZaehltDerHeader(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

        self::assertSame('203.0.113.7', request_client_ip());
    }

    public function testErfundeneEintraegeLinksInDerKetteZaehlenNicht(): void
    {
        // Der Client haengt "1.2.3.4" selbst an; der Proxy ergaenzt die echte
        // Adresse rechts davon. Gezaehlt wird die echte.
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.7';

        self::assertSame('203.0.113.7', request_client_ip());
    }

    public function testMehrereVermittlerWerdenUebersprungen(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 10.1.2.3, 10.0.0.1';

        self::assertSame('203.0.113.7', request_client_ip());
    }

    public function testNetzInCidrSchreibweise(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '172.16.0.0/12';
        $_SERVER['REMOTE_ADDR'] = '172.18.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';

        self::assertSame('198.51.100.9', request_client_ip());
    }

    public function testPrivateDeckenDenContainerverbundAb(): void
    {
        $_ENV['TRUSTED_PROXIES'] = 'private';
        $_SERVER['REMOTE_ADDR'] = '172.18.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';

        self::assertSame('198.51.100.9', request_client_ip());
    }

    public function testUnsinnImHeaderFaelltAufDieVerbindungZurueck(): void
    {
        $_ENV['TRUSTED_PROXIES'] = 'private';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'nicht-einmal-eine-adresse';

        self::assertSame('10.0.0.1', request_client_ip());
    }

    public function testOhneVerbindungsadresseUnbekannt(): void
    {
        self::assertSame('unknown', request_client_ip());
    }

    /**
     * @return list<array{0:string,1:string,2:bool}>
     */
    public static function bereiche(): array
    {
        return [
            ['10.0.0.1', '10.0.0.1', true],
            ['10.0.0.2', '10.0.0.1', false],
            ['10.255.255.254', '10.0.0.0/8', true],
            ['11.0.0.1', '10.0.0.0/8', false],
            ['172.31.255.1', '172.16.0.0/12', true],
            ['172.32.0.1', '172.16.0.0/12', false],
            ['192.168.1.9', '192.168.0.0/16', true],
            ['203.0.113.1', '0.0.0.0/0', true],
            ['::1', '::1/128', true],
            ['fd00::1', 'fc00::/7', true],
            ['2001:db8::1', 'fc00::/7', false],
            // Gemischte Familien duerfen nie zusammenpassen.
            ['10.0.0.1', '::1/128', false],
            ['::1', '10.0.0.0/8', false],
            // Unsinn wird abgewiesen statt geraten.
            ['10.0.0.1', '10.0.0.0/99', false],
            ['10.0.0.1', 'kein-netz/8', false],
            ['keine-adresse', '10.0.0.0/8', false],
        ];
    }

    /**
     * @dataProvider bereiche
     */
    public function testNetzpruefung(string $ip, string $bereich, bool $erwartet): void
    {
        self::assertSame($erwartet, request_ip_in_range($ip, $bereich), "$ip in $bereich");
    }
}
