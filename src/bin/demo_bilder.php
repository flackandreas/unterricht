<?php
/**
 * bin/demo_bilder.php
 * Legt die Beispielfotos der Demo-Hausaufgaben in die Ablage.
 *
 * Die Demodaten (schulos/deploy/demo-seed/unterricht.sql) verweisen auf
 * uploads/homework/demo-*.jpg. Die Bilder liegen in src/demo/hausaufgaben/ -
 * erfundene Hefteinträge, keine echten Schülerarbeiten. Laeuft nur mit
 * SCHULOS_DEMO=1; der Container ruft es beim Start auf, der naechtliche
 * Reset ebenfalls. Vorhandene Dateien werden ueberschrieben.
 */

require_once __DIR__ . '/../includes/demo.php';
require_once __DIR__ . '/../includes/storage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (getenv('SCHULOS_DEMO') !== '1') {
    fwrite(STDERR, "demo_bilder: SCHULOS_DEMO ist nicht 1 - nichts zu tun.\n");
    exit(0);
}

$quelle = __DIR__ . '/../demo/hausaufgaben';
$ziel = storage_dir('homework');
$anzahl = 0;

foreach (glob($quelle . '/demo-*.jpg') ?: [] as $datei) {
    $neu = $ziel . '/' . basename($datei);
    if (!copy($datei, $neu)) {
        fwrite(STDERR, 'demo_bilder: ' . basename($datei) . " liess sich nicht kopieren.\n");
        exit(1);
    }
    chmod($neu, 0640);
    $anzahl++;
}

echo "demo_bilder: {$anzahl} Beispielbilder abgelegt.\n";
