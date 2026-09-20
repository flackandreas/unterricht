<?php
/**
 * src/logout.php
 * Abmeldung.
 *
 * Kam die Sitzung vom Portal, wird auch dort abgemeldet - und das Portal
 * meldet anschliessend die uebrigen Module ab. Sonst bliebe man auf einem
 * geteilten Rechner im Lehrerzimmer in den anderen Modulen angemeldet.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sso.php';

/*
 * Abgemeldet wird nur auf Wunsch dieser Seite.
 *
 * Die Abmeldung haengt an einem gewoehnlichen Verweis - ein GET also, und
 * jede fremde Seite kann eines ausloesen: <img src=".../logout.php">. Das
 * Sitzungscookie traegt zwar SameSite=Strict und geht bei einem solchen
 * Aufruf nicht mit; das ist aber eine Einstellung, die sich irgendwann
 * aendern kann, und der Schutz verschwaende dann stillschweigend.
 *
 * Sec-Fetch-Site sagt, woher der Aufruf kommt. Jeder aktuelle Browser
 * schickt den Kopf mit; fehlt er, wird wie bisher verfahren. Ein POST mit
 * gueltigem Token gilt immer.
 */
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$herkunft = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';

$erlaubt = $methode === 'POST'
    ? verify_csrf_token($_POST['csrf_token'] ?? '')
    : ($herkunft === '' || in_array($herkunft, ['same-origin', 'same-site', 'none'], true));

if (!$erlaubt) {
    // Nicht abmelden, sondern fragen. Ein Fehler waere hier unhoeflich: die
    // wahrscheinlichste Ursache ist kein Angriff, sondern ein Verweis aus
    // einer Lesezeichensammlung oder einer Mail.
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Abmelden</title></head><body style="font-family: sans-serif; padding: 2rem;">'
        . '<h1>Wirklich abmelden?</h1>'
        . '<p>Dieser Aufruf kam von einer anderen Seite.</p>'
        . '<form method="post" action="/logout.php">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(get_csrf_token(), ENT_QUOTES) . '">'
        . '<button type="submit">Abmelden</button>'
        . '</form>'
        . '<p><a href="/index.php">Angemeldet bleiben</a></p>'
        . '</body></html>';
    exit;
}

$abmeldeAdresse = null;

if (sso_aktiv() && sso_sitzung_vom_portal()) {
    $idToken = $_SESSION['sso_id_token'] ?? null;

    try {
        $abmeldeAdresse = sso_anmeldung()->abmeldeAdresse(
            is_string($idToken) ? $idToken : null,
            sso_portal_adresse() . '/anmelden'
        );
    } catch (Throwable $e) {
        // Das Portal ist nicht erreichbar. Oertlich abmelden ist dann alles,
        // was geht - und immer noch besser als eine Fehlerseite.
        error_log('Unterricht: Abmeldeadresse des Portals nicht ermittelbar: ' . $e->getMessage());
    }
}

session_unset();
session_destroy();

header('Location: ' . ($abmeldeAdresse ?? '/login.php'));
exit;
