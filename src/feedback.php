<?php
/**
 * src/feedback.php
 * Schüler-Feedback und Trend-Analyse unter einem Navigationspunkt.
 *
 * Vorher waren das zwei getrennte Seiten (teacher_feedback.php und
 * feedback_trends.php), obwohl es ein Arbeitsablauf ist: Feedback einholen,
 * Ergebnisse der Stunde ansehen, gelegentlich die Entwicklung prüfen. Die
 * Liste vergangener Sitzungen lag dabei ausgerechnet in der Trend-Analyse.
 *
 * Die Tabs laufen serverseitig über ?tab=. Damit bleiben sie verlinkbar, und
 * die aufwendige Verlaufsabfrage läuft nur, wenn sie auch angezeigt wird.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/twig_setup.php';

use App\Feedback\FeedbackRepository;

require_login();

$user_id = (int)get_current_user_id();
$conn = db_connect();
$repository = new FeedbackRepository($conn);

/** Wie viele vergangene Sitzungen die Liste zeigt. */
const FEEDBACK_SESSION_LIMIT = 25;

// Tab aus dem Parameter oder aus der sauberen Route /feedback/trends.
$pfad = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
$tab = (($_GET['tab'] ?? '') === 'trends' || $pfad === 'feedback/trends') ? 'trends' : 'sitzungen';

$daten = [
    'tab'               => $tab,
    'active_session'    => $repository->activeSession($user_id),
    'host_url'          => request_base_url(),
    'csrf_token'        => get_csrf_token(),
    'flash_success'     => flash('flash_success'),
    'flash_error'       => flash('flash_error'),
    'current_user_name' => get_current_user_name(),
    'is_admin'          => is_current_user_admin(),
    'is_logged_in'      => true,
];

if ($tab === 'trends') {
    $klasse = trim((string)($_GET['klasse'] ?? ''));
    $fach = trim((string)($_GET['fach'] ?? ''));

    $sessions = $repository->sessionsWithAverages($user_id, $klasse, $fach);
    $optionen = $repository->filterOptions($user_id);

    $daten += [
        'grouped_history' => FeedbackRepository::groupByClassAndSubject($sessions),
        'unique_classes'  => $optionen['klassen'],
        'unique_subjects' => $optionen['faecher'],
        'current_klasse'  => $klasse,
        'current_fach'    => $fach,
        'session_count'   => count($sessions),
    ];
} else {
    $klassen = $repository->classesForTeacher($user_id);

    $daten += [
        'history'            => $repository->sessionsWithAverages($user_id, '', '', FEEDBACK_SESSION_LIMIT),
        'all_classes'        => $klassen['all'],
        'selected_class_ids' => $klassen['selected'],
        'templates'          => $repository->templates($user_id),
    ];
}

echo $twig->render('feedback/index.twig', $daten);

/**
 * Liest eine Flash-Nachricht und entfernt sie.
 */
function flash(string $key): ?string {
    $wert = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);

    return $wert;
}
