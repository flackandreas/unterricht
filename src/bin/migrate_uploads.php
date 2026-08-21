<?php
/**
 * src/bin/migrate_uploads.php
 * Verschiebt Altbestaende aus public/uploads/ in die geschuetzte Ablage.
 *
 * Aufruf (einmalig, im Container):
 *   php bin/migrate_uploads.php          # zeigt nur an, was passieren wuerde
 *   php bin/migrate_uploads.php --apply  # verschiebt tatsaechlich
 *
 * Die Pfade in der Datenbank bleiben unveraendert ("uploads/<typ>/<datei>");
 * storage_resolve() findet die Dateien am neuen Ort.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript ist nur über die Kommandozeile aufrufbar.\n");
}

require_once __DIR__ . '/../includes/storage.php';

$apply = in_array('--apply', $argv, true);
$legacyRoot = __DIR__ . '/../public/uploads';

if (!is_dir($legacyRoot)) {
    exit("Kein Altbestand gefunden - nichts zu tun.\n");
}

$verschoben = 0;
$uebersprungen = 0;

foreach (['homework', 'context'] as $subdir) {
    $quelle = $legacyRoot . '/' . $subdir;
    if (!is_dir($quelle)) {
        continue;
    }

    $ziel = storage_dir($subdir);

    foreach (scandir($quelle) ?: [] as $datei) {
        if ($datei === '.' || $datei === '..' || $datei === '.htaccess') {
            continue;
        }

        $von = $quelle . '/' . $datei;
        $nach = $ziel . '/' . $datei;

        if (!is_file($von)) {
            continue;
        }

        if (file_exists($nach)) {
            echo "  übersprungen (Ziel existiert): $subdir/$datei\n";
            $uebersprungen++;
            continue;
        }

        if ($apply) {
            if (rename($von, $nach)) {
                chmod($nach, 0640);
                $verschoben++;
            } else {
                echo "  FEHLER beim Verschieben: $subdir/$datei\n";
            }
        } else {
            echo "  würde verschieben: $subdir/$datei\n";
            $verschoben++;
        }
    }
}

echo $apply
    ? "\n$verschoben Datei(en) verschoben, $uebersprungen übersprungen.\n"
    : "\n$verschoben Datei(en) wären betroffen, $uebersprungen übersprungen. Mit --apply ausführen.\n";
