<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der Nonce traegt die ganze Richtlinie: er steht im Header und in jedem
 * eigenen Skriptblock, und ein eingeschleustes Skript kommt nur dann nicht
 * zum Zug, wenn er sich nicht erraten laesst.
 *
 * Zwei Eigenschaften muessen dafuer gleichzeitig gelten, und sie ziehen in
 * entgegengesetzte Richtungen: innerhalb eines Requests derselbe Wert - sonst
 * passt der Skriptblock nicht zum Header -, von Request zu Request ein
 * anderer.
 */
final class CspNonceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once self::datei();
    }

    public function testInnerhalbEinesRequestsDerselbeWert(): void
    {
        // Der Header entsteht im Front Controller, die Skriptbloecke erst
        // beim Rendern der Vorlage. Waere der Wert dazwischen ein anderer,
        // wuerde die Seite ihre eigenen Skripte aussperren.
        self::assertSame(csp_nonce(), csp_nonce());
    }

    public function testBase64UndSechzehnByteLang(): void
    {
        $roh = base64_decode(csp_nonce(), true);

        self::assertIsString($roh, 'Der Nonce muss Base64 sein, so verlangt es die Richtlinie.');
        self::assertSame(16, strlen($roh), '16 Byte sind die im CSP-Standard empfohlene Untergrenze.');
    }

    public function testVonRequestZuRequestEinAnderer(): void
    {
        if (!function_exists('shell_exec')) {
            self::markTestSkipped('shell_exec ist abgeschaltet; zwei Requests lassen sich so nicht nachstellen.');
        }

        // Im selben Prozess ist der Wert absichtlich derselbe. Die Frage
        // stellt sich also erst ueber die Prozessgrenze hinweg - genau da,
        // wo im Betrieb der naechste Request anfaengt.
        $befehl = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
            'require ' . var_export(self::datei(), true) . '; echo csp_nonce();'
        );

        $erster = trim((string)shell_exec($befehl));
        $zweiter = trim((string)shell_exec($befehl));

        self::assertNotSame('', $erster, 'Der Unterprozess hat nichts geliefert.');
        self::assertNotSame($erster, $zweiter, 'Zwei Requests bekamen denselben Nonce.');
    }

    private static function datei(): string
    {
        return dirname(__DIR__, 2) . '/includes/csp.php';
    }
}
