<?php
/**
 * src/admin_archive.php
 * Jahresabschluss: archivieren, dann das Loeschkonzept anstossen.
 *
 * Vorher exportierte diese Datei genau eine von 23 Tabellen und loeschte
 * anschliessend Feedback-Sitzungen nach Kalenderjahr - eine zweite Loeschlogik
 * neben bin/retention.php, die von den eingestellten Fristen nichts wusste.
 *
 * Jetzt ist die Aufgabe geteilt: App\Support\Jahresabschluss archiviert
 * vollstaendig, App\Support\Retention loescht nach den Fristen, die die Schule
 * gesetzt hat. Der Abschluss erfindet keine eigenen.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/error_page.php';
require_once __DIR__ . '/includes/storage.php';

use App\Support\AuditLog;
use App\Support\Jahresabschluss;
use App\Support\Retention;

require_admin();

$conn = db_connect();
$audit = new AuditLog($conn);
$user_id = (int) get_current_user_id();

/**
 * Das Beginnjahr des Schuljahres aus der Anfrage - oder das laufende.
 *
 * Bis Juli laeuft das Schuljahr, das im Vorjahr begonnen hat.
 */
function gewaehltes_schuljahr(): int {
    $wunsch = (int) ($_POST['schuljahr'] ?? $_GET['schuljahr'] ?? 0);

    if ($wunsch >= 2000 && $wunsch <= 2100) {
        return $wunsch;
    }

    return (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;
}

// =====================================================================
// Archiv erzeugen und ausliefern
// =====================================================================
if (($_POST['action'] ?? '') === 'export') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        error_page('Sicherheitsprüfung fehlgeschlagen', 'Die Seite war zu lange geöffnet. Bitte laden Sie sie neu.', 400, '/admin/system');
    }

    $beginn = gewaehltes_schuljahr();
    [$von, $bis] = Jahresabschluss::schuljahr($beginn);

    $bezeichnung = sprintf('Unterricht_%d-%02d', $beginn, ($beginn + 1) % 100);
    $arbeitsverzeichnis = storage_dir('tmp') . '/' . $bezeichnung . '_' . bin2hex(random_bytes(6));
    $archiv = $arbeitsverzeichnis . '.tar.gz';

    try {
        $umfang = (new Jahresabschluss($conn))->exportiere($arbeitsverzeichnis, $von, $bis);

        $befehl = 'tar -czf ' . escapeshellarg($archiv) . ' -C ' . escapeshellarg($arbeitsverzeichnis) . ' .';
        exec($befehl, $ausgabe, $rueckgabe);

        if ($rueckgabe !== 0 || !is_file($archiv)) {
            throw new RuntimeException('tar meldete Rückgabewert ' . $rueckgabe);
        }
    } catch (\Throwable $e) {
        error_log('Jahresabschluss fehlgeschlagen: ' . $e->getMessage());
        aufraeumen($arbeitsverzeichnis, $archiv);
        error_page('Archiv konnte nicht erstellt werden', 'Der Fehler steht im Protokoll des Servers.', 500, '/admin/system');
    }

    // Das Archiv traegt Namen Minderjaehriger, ihre Hausaufgaben samt
    // Korrektur und die Beteiligungsbelege. Wer es wann gezogen hat, gehoert
    // deshalb festgehalten - das ist der Preis dafuer, dass es vollstaendig ist.
    $audit->record(
        $user_id,
        'jahresabschluss.exportiert',
        'schuljahr',
        $beginn,
        sprintf('%s bis %s, %d Auszüge, %d Datensätze', $von, $bis, count($umfang), array_sum($umfang))
    );

    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $bezeichnung . '.tar.gz"');
    header('Content-Length: ' . filesize($archiv));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    readfile($archiv);
    aufraeumen($arbeitsverzeichnis, $archiv);
    exit;
}

// =====================================================================
// Loeschkonzept anstossen
// =====================================================================
if (($_POST['action'] ?? '') === 'loeschen') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        error_page('Sicherheitsprüfung fehlgeschlagen', 'Die Seite war zu lange geöffnet. Bitte laden Sie sie neu.', 400, '/admin/system');
    }

    $retention = new Retention($conn);

    try {
        $bilder = $retention->purgeExpiredImages(true);
        $beteiligung = $retention->purgeParticipation(true);
        $retention->purgeTransientRows();
    } catch (\Throwable $e) {
        error_log('Löschlauf fehlgeschlagen: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Der Löschlauf ist nicht durchgelaufen. Der Fehler steht im Protokoll des Servers.';
        header('Location: /admin/system', true, 303);
        exit;
    }

    $audit->record(
        $user_id,
        'loeschlauf.ausgefuehrt',
        'schuljahr',
        gewaehltes_schuljahr(),
        sprintf(
            '%d Bilder gelöscht, %d Beiträge und %d Stunden nach %d Tagen entfernt',
            $bilder['geloescht'],
            $beteiligung['beitraege'],
            $beteiligung['stunden'],
            $beteiligung['frist']
        )
    );

    $_SESSION['flash_success'] = sprintf(
        'Löschlauf abgeschlossen: %d Hausaufgabenfotos entfernt, %d Beteiligungsbeiträge und %d leere Stunden nach %d Tagen.',
        $bilder['geloescht'],
        $beteiligung['beitraege'],
        $beteiligung['stunden'],
        $beteiligung['frist']
    );

    header('Location: /admin/system', true, 303);
    exit;
}

header('Location: /admin/system', true, 303);
exit;

/**
 * Raeumt Arbeitsverzeichnis und Archiv weg - auch wenn unterwegs etwas
 * schiefgegangen ist. Ein liegengebliebenes Archiv waere eine Kopie des
 * ganzen Schuljahres auf der Platte.
 */
function aufraeumen(string $verzeichnis, string $archiv): void {
    if (is_file($archiv)) {
        @unlink($archiv);
    }

    if (!is_dir($verzeichnis)) {
        return;
    }

    foreach (glob($verzeichnis . '/*') ?: [] as $datei) {
        @unlink($datei);
    }

    @rmdir($verzeichnis);
}
