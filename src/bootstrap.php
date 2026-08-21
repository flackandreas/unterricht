<?php
/**
 * src/bootstrap.php
 * Gemeinsamer Einstiegspunkt: Autoloader und Umgebungsvariablen.
 *
 * Vorher lud jede Datei den Autoloader und die .env fuer sich, teils mehrfach
 * pro Request. Hier passiert das genau einmal.
 *
 * Die Hilfsfunktion env() kommt ueber den Composer-Autoloader
 * (app/Support/helpers.php) und steht damit auch in Tests zur Verfuegung.
 */

require_once __DIR__ . '/vendor/autoload.php';

(static function (): void {
    static $geladen = false;
    if ($geladen) {
        return;
    }
    $geladen = true;

    if (!is_file(__DIR__ . '/.env')) {
        return;
    }

    try {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    } catch (\Throwable $e) {
        // In Produktion kommen die Werte aus der Container-Umgebung.
        error_log('.env konnte nicht geladen werden: ' . $e->getMessage());
    }
})();
