<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;

/**
 * Zentrale Datenbankverbindung.
 *
 * db_connect() legte bei jedem Aufruf eine neue PDO-Verbindung an und wurde
 * pro Request mehrfach aufgerufen - vom Router, vom Migrationslauf und noch
 * einmal vom Controller. Hier gibt es genau eine Verbindung je Request.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', 'db'),
            env('DB_NAME', 'db_unterricht')
        );

        try {
            self::$connection = new PDO($dsn, (string)env('DB_USER', 'root'), (string)env('DB_PASS', ''), [
                // Echte Prepared Statements statt der Emulation im Treiber.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            error_log('Datenbankfehler: ' . $e->getMessage());
            throw new \RuntimeException('Die Datenbank ist nicht erreichbar.', 0, $e);
        }

        return self::$connection;
    }

    /**
     * Nur fuer Tests: setzt die gemerkte Verbindung zurueck.
     */
    public static function reset(): void
    {
        self::$connection = null;
    }
}
