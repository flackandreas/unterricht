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

    if (is_file(__DIR__ . '/.env')) {
        try {
            Dotenv\Dotenv::createImmutable(__DIR__)->load();
        } catch (\Throwable $e) {
            // In Produktion kommen die Werte aus der Container-Umgebung.
            error_log('.env konnte nicht geladen werden: ' . $e->getMessage());
        }
    }

    // APP_ENV war in der .env.example beschrieben ("steuert Fehlerausgaben
    // und Caching"), wurde aber nirgends gelesen. Damit galten die
    // PHP-Vorgaben: das Abbild bringt keine php.ini mit, und ohne sie ist
    // display_errors eingeschaltet. Jede Warnung - samt absolutem Pfad -
    // landete im Browser der Lehrkraft oder der Schuelerin.
    //
    // Die erste Verteidigung ist die php.ini-production im Abbild. Hier steht
    // die zweite, damit es auch ausserhalb des Containers stimmt.
    $entwicklung = env('APP_ENV', 'production') === 'development';

    ini_set('display_errors', $entwicklung ? '1' : '0');
    ini_set('display_startup_errors', $entwicklung ? '1' : '0');
    ini_set('log_errors', '1');

    if ($entwicklung) {
        error_reporting(E_ALL);
    }
})();
