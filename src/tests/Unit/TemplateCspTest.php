<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die Content-Security-Policy erlaubt Skripte nur noch mit dem Nonce des
 * laufenden Requests - siehe includes/csp.php und public/index.php. Dass das
 * haelt, haengt an zwei Bedingungen, und beide fallen im Browser still aus:
 * ein Skriptblock ohne Nonce wird nicht ausgefuehrt, ein wieder eingefuegtes
 * Ereignisattribut ebenso wenig. Zu sehen ist davon nur eine Zeile in der
 * Entwicklerkonsole - und eine Schaltflaeche, die nichts tut.
 *
 * Deshalb hier, wo es beim Uebersetzen auffaellt statt im Unterricht.
 */
final class TemplateCspTest extends TestCase
{
    public function testKeineVorlageTraegtEinenEreignishandlerAlsAttribut(): void
    {
        $funde = [];

        foreach (self::vorlagen() as $name => $inhalt) {
            $markup = self::ohneSkripteUndKommentare($inhalt);

            if (preg_match_all('/\s(on[a-z]+)\s*=\s*["\']/i', $markup, $treffer, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($treffer[1] as $treffer1) {
                [$attribut, $stelle] = $treffer1;
                $zeile = substr_count(substr($markup, 0, (int)$stelle), "\n") + 1;
                $funde[] = $name . ':' . $zeile . ' (' . $attribut . '=)';
            }
        }

        self::assertSame([], $funde, "Ereignishandler als Attribut gefunden. Ein Attribut kann keinen "
            . "Nonce tragen, die Richtlinie fuehrt es also nicht aus. Stattdessen ein data-Attribut "
            . "setzen und im Skriptblock der Vorlage oder in public/js/app.js per addEventListener "
            . "verdrahten:\n\n" . implode("\n", $funde));
    }

    public function testJederEigeneSkriptblockNenntDenNonce(): void
    {
        $funde = [];

        foreach (self::vorlagen() as $name => $inhalt) {
            preg_match_all('/<script\b[^>]*>/i', $inhalt, $treffer, PREG_OFFSET_CAPTURE);

            foreach ($treffer[0] as $treffer0) {
                [$tag, $stelle] = $treffer0;

                // Eine eigene Datei vom eigenen Server; die deckt 'self' ab.
                if (stripos((string)$tag, ' src=') !== false) {
                    continue;
                }

                if (str_contains((string)$tag, 'nonce="{{ csp_nonce() }}"')) {
                    continue;
                }

                $zeile = substr_count(substr($inhalt, 0, (int)$stelle), "\n") + 1;
                $funde[] = $name . ':' . $zeile . '  ' . $tag;
            }
        }

        self::assertSame([], $funde, "Skriptblock ohne Nonce gefunden, der Browser fuehrt ihn nicht "
            . "aus. Erwartet wird woertlich nonce=\"{{ csp_nonce() }}\" - ein fest eingetragener Wert "
            . "waere fuer jeden Request derselbe und damit keiner:\n\n" . implode("\n", $funde));
    }

    /**
     * Alle Vorlagen, Dateiname (relativ) => Inhalt.
     *
     * @return array<string, string>
     */
    private static function vorlagen(): array
    {
        $wurzel = (string)realpath(dirname(__DIR__, 2) . '/templates');

        $dateien = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS)
        );

        $vorlagen = [];
        foreach ($dateien as $datei) {
            if (!$datei instanceof \SplFileInfo || $datei->getExtension() !== 'twig') {
                continue;
            }

            $name = ltrim(str_replace($wurzel, '', (string)$datei->getRealPath()), '/');
            $vorlagen[$name] = (string)file_get_contents((string)$datei->getRealPath());
        }

        ksort($vorlagen);

        self::assertNotSame([], $vorlagen, 'Keine Vorlage gefunden - stimmt der Pfad noch?');

        return $vorlagen;
    }

    /**
     * Ersetzt Skriptbloecke und Twig-Kommentare durch ebenso viele Leerzeilen.
     *
     * In beiden steht JavaScript oder Prosa, und beides darf vom abgeloesten
     * onclick-Attribut erzaehlen, ohne diesen Test auszuloesen. Die
     * Zeilenumbrueche bleiben stehen, damit die gemeldete Fundstelle die
     * richtige ist.
     */
    private static function ohneSkripteUndKommentare(string $inhalt): string
    {
        return (string)preg_replace_callback(
            '#<script\b.*?</script>|\{\#.*?\#\}#is',
            static fn(array $treffer): string => str_repeat("\n", substr_count($treffer[0], "\n")),
            $inhalt
        );
    }
}
