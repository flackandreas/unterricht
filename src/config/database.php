<?php
/**
 * src/config/database.php
 * Stellt die PDO-Verbindung zur MariaDB her.
 *
 * Die eigentliche Logik liegt in App\Support\Database. Diese Datei bleibt als
 * Einstiegspunkt bestehen, weil sie von allen Controllern eingebunden wird.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

// Rueckwaertskompatible Konstanten.
define('DB_SERVER', env('DB_HOST', 'db'));
define('DB_USERNAME', env('DB_USER', 'root'));
define('DB_PASSWORD', env('DB_PASS', ''));
define('DB_NAME', env('DB_NAME', 'db_unterricht'));
define('CHARSET', 'utf8mb4');

/**
 * Liefert die gemeinsame Datenbankverbindung des Requests.
 */
function db_connect(): PDO {
    try {
        return Database::connection();
    } catch (\RuntimeException $e) {
        http_response_code(503);
        die("Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.");
    }
}
