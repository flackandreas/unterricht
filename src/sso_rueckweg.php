<?php
/**
 * src/sso_rueckweg.php
 * Rueckweg vom SchulOS-Portal: Code einloesen, Konto zuordnen, anmelden.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/error_page.php';
require_once __DIR__ . '/includes/sso.php';
require_once __DIR__ . '/includes/migrations.php';

use SchulOS\Sso\SsoFehler;

if (!sso_aktiv()) {
    header('Location: /login.php');
    exit;
}

$anmeldung = sso_anmeldung();

try {
    $identitaet = $anmeldung->abschliessen($_GET);
} catch (SsoFehler $e) {
    error_log('Unterricht: Anmeldung ueber das Portal gescheitert: ' . $e->getMessage());
    error_page(
        'Anmeldung nicht abgeschlossen',
        'Die Anmeldung über das Portal konnte nicht abgeschlossen werden. Bitte erneut versuchen.',
        400,
        sso_portal_adresse() ?: '/login.php'
    );
}

// Die Migrationen muessen durch sein, bevor auf sso_sub zugegriffen wird.
run_all_migrations();

$conn = db_connect();

if (!sso_zuordnung_bereit($conn)) {
    error_log('Unterricht: Tabelle portal_konten fehlt - die Migration ist nicht gelaufen.');
    error_page(
        'Modul noch nicht bereit',
        'Die Datenbank dieses Moduls ist noch nicht auf dem Stand für die Portal-Anmeldung. Bitte an die Administration wenden.',
        503,
        sso_portal_adresse() ?: '/login.php'
    );
}

$konto = sso_konto($conn, $identitaet);

if ($konto === null) {
    error_page(
        'Kein Zugang',
        'Für Ihr Konto ist dieses Modul nicht freigegeben. Die Schulleitung kann das im Portal ändern.',
        403,
        sso_portal_adresse() ?: '/login.php'
    );
}

sso_sitzung_starten($konto, $identitaet, $anmeldung->letztesIdToken());

header('Location: ' . $anmeldung->zielNachAnmeldung());
exit;
