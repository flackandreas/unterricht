<?php
/**
 * src/index.php
 * Portal Choice Page
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/sso.php';
require_once __DIR__ . '/includes/migrations.php';
run_all_migrations();

require_login();

// Am SchulOS-Portal ist die Modulauswahl dort zuhause. Ohne Portal bleibt die
// alte Auswahlseite bestehen, damit eine Schule dieses Modul auch allein
// betreiben kann.
// Am Portal ist die alte Modulauswahl gegenstandslos - der Weg fuehrt auf das
// eigene Dashboard.
//
// NICHT zurueck zum Portal: die Kacheln dort zeigen auf die Wurzel des Moduls,
// und die landet hier. Eine Weiterleitung ans Portal schickt damit jeden, der
// eine Kachel antippt, sofort wieder dorthin zurueck. Der Weg zum Portal
// steht in der Navigation.
if (sso_aktiv()) {
    header('Location: /dashboard');
    exit;
}

$user_name = get_current_user_name();

require_once __DIR__ . '/includes/twig_setup.php';

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Verweis auf das Antragsmodul.
//
// Hier stand bis zuletzt ein Anmeldetoken in der Adresse:
//
//     /login.php?autologin=1&kuerzel=ABC&token=<sha256>
//
// Wer die Adresse hatte, war angemeldet - zehn Minuten lang, als die
// betreffende Lehrkraft. Und eine Adresse hat viele Mitleser: sie steht im
// Zugriffsprotokoll des Webservers, im Verlauf des Browsers und, sobald die
// Zielseite nach aussen verweist, im Referrer. Ein Anmeldeausweis gehoert
// nicht in eine Adresszeile.
//
// Der Verweis zeigt jetzt schlicht auf das andere Modul. Am Portal ist das
// kein Verlust: es leitet von dort auf die gemeinsame Anmeldung weiter, und
// wer angemeldet ist, ist es auch dort. Ohne Portal erscheint die
// Anmeldemaske des Antragsmoduls - ein Schritt mehr als vorher.
$ziel = rtrim((string) env('ANTRAG_URL', ''), '/');

if ($ziel === '') {
    // Rueckfall auf den Nachbarport am selben Rechner, wie bisher.
    $ziel = '//' . explode(':', request_host())[0] . ':8888';
}

$url_antraege = $ziel . '/';
$url_unterricht = '/dashboard';

echo $twig->render('portal_choice.twig', [
    'current_user_name' => $user_name,
    'is_logged_in' => false,
    'url_antraege' => $url_antraege,
    'url_unterricht' => $url_unterricht,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error
]);
