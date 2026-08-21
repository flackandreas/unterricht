<?php
/**
 * src/bin/migrate.php
 * Spielt ausstehende Datenbank-Migrationen ein.
 *
 *   php bin/migrate.php            # einspielen
 *   php bin/migrate.php --status   # nur anzeigen
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\Migrator;

$migrator = new Migrator();

if (in_array('--status', $argv, true)) {
    echo $migrator->isUpToDate()
        ? "Schema ist aktuell (Stand {$migrator->fingerprint()}).\n"
        : "Es stehen Migrationen aus.\n";
    exit($migrator->isUpToDate() ? 0 : 1);
}

try {
    $ergebnis = $migrator->migrate(Database::connection());
} catch (\Throwable $e) {
    fwrite(STDERR, "Migration abgebrochen: {$e->getMessage()}\n");
    exit(1);
}

foreach ($ergebnis['ausgefuehrt'] as $datei) {
    echo "  eingespielt: $datei\n";
}

echo sprintf(
    "\n%d eingespielt, %d bereits vorhanden, %d Fehler.\n",
    count($ergebnis['ausgefuehrt']),
    $ergebnis['uebersprungen'],
    count($ergebnis['fehler'])
);

foreach ($ergebnis['fehler'] as $fehler) {
    fwrite(STDERR, "  FEHLER: $fehler\n");
}

exit($ergebnis['fehler'] === [] ? 0 : 1);
