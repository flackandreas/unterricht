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
