<?php
/**
 * src/bin/retention.php
 * Setzt das Loeschkonzept um: entfernt abgelaufene Hausaufgabenfotos und
 * raeumt Warteschlangen- sowie Zaehlereintraege ab.
 *
 *   php bin/retention.php           # zeigt an, was passieren wuerde
 *   php bin/retention.php --apply   # loescht tatsaechlich
 *
 * Sinnvoll als taeglicher cron-Aufruf.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';

use App\Support\Database;
use App\Support\Retention;

$apply = in_array('--apply', $argv, true);
$retention = new Retention(Database::connection());

$ergebnis = $retention->purgeExpiredImages($apply);
$beteiligung = $retention->purgeParticipation($apply);

if ($apply) {
    $retention->purgeTransientRows();
    echo "{$ergebnis['geloescht']} Bilddatei(en) gelöscht, Hilfstabellen aufgeräumt.\n";
    echo "{$beteiligung['beitraege']} Beitrag/Beiträge und {$beteiligung['stunden']} leere Stunde(n) "
        . "älter als {$beteiligung['frist']} Tage gelöscht.\n";
} else {
    echo "{$ergebnis['geprueft']} Bilddatei(en) wären zu löschen. Mit --apply ausführen.\n";
    echo "{$beteiligung['beitraege']} Beitrag/Beiträge wären zu löschen "
        . "(Frist: {$beteiligung['frist']} Tage).\n";
}
