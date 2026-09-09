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
if (sso_aktiv()) {
    $portal = sso_portal_adresse();
    header('Location: ' . ($portal !== '' ? $portal . '/' : '/dashboard'));
    exit;
}

$user_name = get_current_user_name();

require_once __DIR__ . '/includes/twig_setup.php';

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$sso_secret = $_ENV['SSO_SECRET'] ?? getenv('SSO_SECRET') ?: '';
if (!empty($sso_secret)) {
    $time_bucket = floor(time() / 300);
    $token = hash('sha256', get_current_user_kuerzel() . $sso_secret . $time_bucket);
    $host_name = explode(':', request_host())[0];
    $url_antraege = '//' . $host_name . ':8888/login.php?autologin=1&kuerzel=' . urlencode(get_current_user_kuerzel()) . '&token=' . $token;
} else {
    $url_antraege = '#';
}
$url_unterricht = '/dashboard';

echo $twig->render('portal_choice.twig', [
    'current_user_name' => $user_name,
    'is_logged_in' => false,
    'url_antraege' => $url_antraege,
    'url_unterricht' => $url_unterricht,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error
]);
