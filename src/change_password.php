<?php
/**
 * src/change_password.php
 * Controller for changing forced password
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

// require_login() kennt jetzt die Ausnahme fuer diese Seite - vorher haette
// der Aufruf hier eine Endlosschleife ergeben, deshalb stand hier eine
// eigene Pruefung. Der Weg ueber require_login() gleicht nebenbei die Rolle
// mit der Datenbank ab.
require_login();

$user_id = get_current_user_id();
$conn = db_connect();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $new_password_confirm = $_POST['new_password_confirm'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token.";
    } elseif (empty($current_password) || empty($new_password) || empty($new_password_confirm)) {
        $_SESSION['flash_error'] = "Bitte alle Felder ausfüllen.";
    } elseif ($new_password !== $new_password_confirm) {
        $_SESSION['flash_error'] = "Die Passwörter stimmen nicht überein.";
    } elseif (strlen($new_password) < 8) {
        $_SESSION['flash_error'] = "Das neue Passwort muss mindestens 8 Zeichen lang sein.";
    } else {
        // Fetch current password hash
        $stmt = $conn->prepare("SELECT passwort_hash FROM teachers WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if ($user && password_verify($current_password, $user['passwort_hash'])) {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            // Nach einem Passwortwechsel eine neue Session-ID vergeben, damit
            // eine eventuell mitgelesene alte ID wertlos wird.
            session_regenerate_id(true);
            $update = $conn->prepare("UPDATE teachers SET passwort_hash = ?, force_password_change = 0 WHERE id = ?");
            if ($update->execute([$new_hash, $user_id])) {
                $_SESSION['force_password_change'] = 0;
                $_SESSION['flash_success'] = "Ihr Passwort wurde erfolgreich geändert.";
                header("Location: /index.php");
                exit;
            } else {
                $_SESSION['flash_error'] = "Fehler beim Aktualisieren des Passworts.";
            }
        } else {
            $_SESSION['flash_error'] = "Das aktuelle Passwort ist nicht korrekt.";
        }
    }
    header("Location: /change_password.php");
    exit;
}

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('change_password.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
