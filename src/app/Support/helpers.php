<?php

declare(strict_types=1);

/**
 * src/app/Support/helpers.php
 * Globale Hilfsfunktionen, von Composer automatisch geladen.
 */

if (!function_exists('env')) {
    /**
     * Liest einen Konfigurationswert aus der Umgebung.
     *
     * Leere Werte gelten als nicht gesetzt, damit ein leeres Feld in der .env
     * den Standardwert nicht überschreibt.
     */
    function env(string $name, ?string $default = null): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (!is_scalar($value) || $value === false || $value === '') {
            return $default;
        }

        return (string)$value;
    }
}

if (!function_exists('erstes_passwort')) {
    /**
     * Erzeugt ein Erstpasswort fuer ein neu angelegtes Konto.
     *
     * Vorher vergaben beide CSV-Importe ein festes, im Quelltext lesbares
     * Passwort ("lehrer" bzw. "Start123!") - fuer jede importierte Lehrkraft
     * dasselbe, und ohne Wechselzwang. Wer die Kuerzel einer Schule kannte
     * (sie stehen im Vertretungsplan), kam damit herein.
     *
     * Das Alphabet laesst 0/O/1/l/I weg: das Passwort wird auf Papier
     * weitergegeben und abgetippt, und eine Verwechslung kostet einen Anruf.
     */
    function erstes_passwort(int $laenge = 12): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyz23456789';
        $grenze = strlen($alphabet) - 1;

        // Nicht kuerzer als acht Zeichen, und ein Vielfaches von vier, damit
        // die Gruppierung aufgeht.
        $laenge = (int) (ceil(max(8, $laenge) / 4) * 4);

        $zeichen = '';
        for ($i = 0; $i < $laenge; $i++) {
            $zeichen .= $alphabet[random_int(0, $grenze)];
        }

        // In Vierergruppen: "k7fp-r3mq-x9tz" liest sich vom Zettel besser.
        return implode('-', str_split($zeichen, 4));
    }
}
