<?php
/**
 * src/includes/auth.php
 * Session management and authentication checks.
 */

require_once __DIR__ . '/request.php';
require_once __DIR__ . '/../config/database.php';

session_name('unterricht_session');
session_set_cookie_params([
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    // request_is_https() beruecksichtigt X-Forwarded-Proto. Hinter dem
    // Reverse Proxy war $_SERVER['HTTPS'] nicht gesetzt, das Session-Cookie
    // wurde deshalb ohne Secure-Flag ausgeliefert.
    'secure'   => request_is_https(),
]);
session_start();

// Sessions ohne Aktivitaet verfallen nach vier Stunden.
$session_lifetime = 4 * 3600;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $session_lifetime) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Holt Rolle und Kontostand aus der Datenbank nach.
 *
 * is_admin und force_password_change standen bisher nur in der Sitzung, seit
 * dem Anmelden. Wer einer Lehrkraft die Verwaltungsrechte entzog, entzog sie
 * damit erst beim naechsten Anmelden - bis dahin, bis zu vier Stunden lang,
 * kam sie weiter in jeden Verwaltungsbereich. Dasselbe galt fuer ein
 * geloeschtes Konto: die Sitzung lief weiter, als gaebe es es noch. Am Portal
 * ist "teachers" eine Sicht auf die Kontenverwaltung, das Loeschen passiert
 * also anderswo und bleibt hier unbemerkt.
 *
 * Nachgeschlagen wird hoechstens einmal je Minute. Das ist eine Abfrage ueber
 * den Primaerschluessel; die Luecke schrumpft damit von vier Stunden auf
 * eine Minute.
 */
function auth_refresh() {
    if (!is_logged_in()) {
        return;
    }

    $abstand = 60;
    if (isset($_SESSION['auth_geprueft']) && (time() - $_SESSION['auth_geprueft']) < $abstand) {
        return;
    }

    try {
        $stmt = db_connect()->prepare(
            'SELECT is_admin, force_password_change FROM teachers WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int)$_SESSION['user_id']]);
        $konto = $stmt->fetch();
    } catch (\Throwable $e) {
        // Ist die Datenbank kurz nicht erreichbar, bleibt die Sitzung wie sie
        // ist. Sie deswegen wegzuwerfen wuerde bei jedem Neustart der
        // Datenbank das ganze Kollegium abmelden.
        error_log('Rollenabgleich fehlgeschlagen: ' . $e->getMessage());
        return;
    }

    $_SESSION['auth_geprueft'] = time();

    if ($konto === false) {
        // Das Konto gibt es nicht mehr.
        $_SESSION = [];
        session_destroy();
        header('Location: /login.php');
        exit;
    }

    $_SESSION['is_admin'] = (int)$konto['is_admin'];
    $_SESSION['force_password_change'] = (int)$konto['force_password_change'];
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: /login.php");
        exit;
    }

    auth_refresh();
    // Check if password change is forced
    if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] == 1) {
        $current_script = basename($_SERVER['SCRIPT_NAME']);
        if ($current_script !== 'change_password.php' && $current_script !== 'logout.php') {
            header("Location: /change_password.php");
            exit;
        }
    }
}

function get_current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function get_current_user_name() {
    return $_SESSION['user_name'] ?? null;
}

function get_current_user_kuerzel() {
    return $_SESSION['user_kuerzel'] ?? null;
}

function is_current_user_admin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function require_admin() {
    require_login();
    if (!is_current_user_admin()) {
        header("Location: /index.php");
        exit;
    }
}

/**
 * Validates a Kürzel and Password against the database.
 * Returns user data on success, false on failure.
 */
function authenticate_user($conn, $kuerzel, $password) {
    if (empty($kuerzel) || empty($password)) {
        return false;
    }

    $stmt = $conn->prepare("SELECT id, kuerzel, is_admin, passwort_hash, name, force_password_change FROM teachers WHERE kuerzel = :kuerzel LIMIT 1");
    $stmt->execute([':kuerzel' => $kuerzel]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['passwort_hash'])) {
        return $user;
    }

    return false;
}

/**
 * Simple CSRF token generation
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Simple CSRF token validation
 */
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token) || !is_string($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
?>
