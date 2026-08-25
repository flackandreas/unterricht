<?php
/**
 * src/live.php
 * Beteiligung erfassen - ein Tipp je Wortbeitrag.
 *
 * Der Bildschirm ist fuer das Handy in der linken Hand gebaut. Er raet die
 * laufende Stunde, statt sie erfragen zu lassen: wer erst Klasse, Fach und
 * Stunde auswaehlt, hat die dreissig Sekunden schon verbraucht.
 *
 * Angelegt wird die Stunde erst beim ersten Beitrag (live_action.php) -
 * ein versehentlicher Aufruf soll die Vermutung nicht verfaelschen.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

use App\Live\LessonRepository;
use App\Live\ParticipationRepository;
use App\Live\Roster;

require_login();

$conn = db_connect();
$teacher_id = (int)get_current_user_id();

$lessons = new LessonRepository($conn);
$roster = new Roster($conn);
$participation = new ParticipationRepository($conn);

// Nur eigene Klassen - der Zugriffsschutz sitzt in der Abfrage, nicht in
// der Oberflaeche.
$stmt = $conn->prepare('
    SELECT c.id, c.name
    FROM classes c
    JOIN teacher_classes tc ON tc.class_id = c.id
    WHERE tc.teacher_id = ?
    ORDER BY c.name ASC
');
$stmt->execute([$teacher_id]);
$meine_klassen = $stmt->fetchAll();
$erlaubte_klassen = array_map(static fn(array $k): int => (int)$k['id'], $meine_klassen);

$vermutung = $lessons->guessCurrent($teacher_id);

$class_id = (int)($_GET['class_id'] ?? $vermutung['class_id'] ?? 0);
$fach     = trim((string)($_GET['fach'] ?? $vermutung['fach'] ?? ''));
$period   = (int)($_GET['period'] ?? $vermutung['period'] ?? LessonRepository::currentPeriod());
$datum    = date('Y-m-d');

if (!in_array($class_id, $erlaubte_klassen, true)) {
    $class_id = 0;
    $fach = '';
}

$period = max(1, min(10, $period));

$klasse = null;
foreach ($meine_klassen as $k) {
    if ((int)$k['id'] === $class_id) {
        $klasse = $k;
        break;
    }
}

$liste = [];
$faecher = [];
$session = null;
$stand = [];
$letztes_thema = null;

if ($klasse !== null) {
    $liste = $roster->forClass($class_id);
    $faecher = $lessons->subjectsFor($teacher_id, $class_id);

    if ($fach !== '') {
        $session = $lessons->findFor($teacher_id, $class_id, $datum, $period);
        $letztes_thema = $lessons->lastTopic($class_id, $fach);

        if ($session !== null) {
            $stand = $participation->tallyForSession((int)$session['id']);
        }
    }
}

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('live.twig', [
    'csrf_token'        => get_csrf_token(),
    'flash_success'     => $flash_success,
    'flash_error'       => $flash_error,
    'meine_klassen'     => $meine_klassen,
    'klasse'            => $klasse,
    'class_id'          => $class_id,
    'fach'              => $fach,
    'faecher'           => $faecher,
    'period'            => $period,
    'datum'             => $datum,
    'liste'             => $liste,
    'session'           => $session,
    'stand'             => $stand,
    'letztes_thema'     => $letztes_thema,
    'vermutung'         => $vermutung,
    'gewichte'          => ParticipationRepository::GEWICHTE,
    'current_user_name' => get_current_user_name(),
    'is_admin'          => is_current_user_admin(),
    'is_logged_in'      => true,
]);
