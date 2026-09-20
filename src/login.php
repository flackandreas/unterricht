<?php
/**
 * src/login.php
 * Login Controller
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/error_page.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/sso.php';
require_once __DIR__ . '/includes/twig_setup.php';

// Redirect to dashboard if already logged in
if (is_logged_in()) {
    header("Location: /index.php");
    exit;
}

$portalAktiv = sso_aktiv();

/**
 * Ausschliesslich ueber das Portal.
 *
 * Dann gibt es die oertliche Maske gar nicht - auch nicht ueber ?lokal=1 und
 * auch nicht als abgeschicktes Formular. Gedacht fuer oeffentlich erreichbare
 * Instanzen: dort ist ein mitgeliefertes Konto eine offene Tuer, und wer es
 * uebernimmt, behaelt es.
 *
 * Vorgabe ist aus. Eine Schule soll im Zweifel noch hereinkommen, wenn das
 * Portal steht.
 */
$nurUeberPortal = $portalAktiv && (string) env('PORTAL_NUR_SSO', '0') === '1';

if ($nurUeberPortal) {
    header('Location: /sso_start.php');
    exit;
}

// Sonst ist die oertliche Maske am Portal nur nicht mehr der Regelweg; als
// Rueckfall bleibt sie unter /login.php?lokal=1 erreichbar.
if ($portalAktiv && !isset($_GET['lokal']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /sso_start.php');
    exit;
}

// Hier wurde bis zuletzt eine Anmeldung ueber einen Token in der Adresszeile
// entgegengenommen (?autologin=1&kuerzel=...&token=...). Sie stammte aus der
// Zeit, als sich zwei Module gegenseitig verlinkt haben.
//
// Ein Anmeldeausweis in einer Adresse steht im Zugriffsprotokoll des
// Webservers, im Verlauf des Browsers und im Referrer. Er war zehn Minuten
// gueltig und ersetzte das Passwort vollstaendig. Am Portal wurde er ohnehin
// nicht mehr ausgewertet; jetzt nirgends mehr. Die Kachel im anderen Modul
// verweist auf dessen Startseite, und die Anmeldung laeuft ueber das Portal
// oder ueber das Formular unten.

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $kuerzel = trim($_POST['kuerzel'] ?? '');
    $password = $_POST['passwort'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
    } elseif (empty($kuerzel) || empty($password)) {
        $_SESSION['flash_error'] = "Bitte Kürzel und Passwort eingeben.";
    } else {
        $conn = db_connect();

        // Bisher bremste nur ein sleep(1) - das haelt automatisiertes
        // Durchprobieren nicht auf.
        $ip = request_client_ip();
        // Hier ohne Netz: laesst sich die Zaehltabelle nicht lesen, wird
        // abgewiesen statt durchgelassen. Sonst genuegte es, die Tabelle
        // unbrauchbar zu machen, um unbegrenzt Passwoerter durchprobieren zu
        // koennen - der Schutz fiele genau dann aus, wenn er gebraucht wird.
        if (!rate_limit_allow($conn, 'login_ip', $ip, 15, 900, false)
            || !rate_limit_allow($conn, 'login_user', mb_strtolower($kuerzel), 8, 900, false)) {
            http_response_code(429);
            $_SESSION['flash_error'] = "Zu viele Anmeldeversuche. Bitte warten Sie einige Minuten.";
            $user = false;
        } else {
            $user = authenticate_user($conn, $kuerzel, $password);
        }

        if ($user) {
            rate_limit_reset($conn, 'login_ip', $ip);
            rate_limit_reset($conn, 'login_user', mb_strtolower($kuerzel));

            // Login successful
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_kuerzel'] = $user['kuerzel'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['force_password_change'] = $user['force_password_change'];
            
            header("Location: /index.php");
            exit;
        } elseif (empty($_SESSION['flash_error'])) {
            // Kleine Verzoegerung gegen schnelles Durchprobieren einzelner Versuche
            usleep(300000);
            $_SESSION['flash_error'] = "Falsches Kürzel oder Passwort.";
        }
    }
}

// Generate new CSRF token for the form
$csrf_token = get_csrf_token();

$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

echo $twig->render('login.twig', [
    'csrf_token' => $csrf_token,
    'flash_error' => $flash_error,
    'is_logged_in' => false,
    'portal_aktiv' => $portalAktiv,
    'portal_adresse' => sso_portal_adresse(),
    'iserv_aktiv' => (string) env('ISERV_HOST', '') !== '' && (string) env('ISERV_CLIENT_ID', '') !== '',
]);
