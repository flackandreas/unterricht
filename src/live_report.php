<?php
/**
 * src/live_report.php
 * Belegansicht der Beteiligung.
 *
 * Beantwortet die Frage, die im Elterngespraech tatsaechlich gestellt wird -
 * "woran machen Sie das fest?" - und beantwortet sie mit Belegen, nicht mit
 * einer errechneten Note.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

use App\Live\LessonRepository;
use App\Live\ParticipationReport;
use App\Live\Roster;

require_login();

$conn = db_connect();
$teacher_id = (int)get_current_user_id();

$lessons = new LessonRepository($conn);
$report = new ParticipationReport($conn);
$roster = new Roster($conn);

$stmt = $conn->prepare('
    SELECT c.id, c.name
    FROM classes c
    JOIN teacher_classes tc ON tc.class_id = c.id
    WHERE tc.teacher_id = ?
    ORDER BY c.name ASC
');
$stmt->execute([$teacher_id]);
$meine_klassen = $stmt->fetchAll();

$class_id = (int)($_GET['class_id'] ?? ($meine_klassen[0]['id'] ?? 0));

$klasse = null;
foreach ($meine_klassen as $k) {
    if ((int)$k['id'] === $class_id) {
        $klasse = $k;
        break;
    }
}

if ($klasse === null) {
    $class_id = 0;
}

$fach = trim((string)($_GET['fach'] ?? ''));
$bis = (string)($_GET['bis'] ?? date('Y-m-d'));
$von = (string)($_GET['von'] ?? date('Y-m-d', strtotime('-90 days')));

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis) !== 1) {
    $bis = date('Y-m-d');
}

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $von) !== 1) {
    $von = date('Y-m-d', strtotime('-90 days'));
}

if ($von > $bis) {
    [$von, $bis] = [$bis, $von];
}

$faecher = [];
$zeilen = [];
$verlauf = ['wochen' => [], 'werte' => []];
$belege = [];
$person = null;

if ($klasse !== null) {
    $faecher = $lessons->subjectsFor($teacher_id, $class_id);

    if ($fach !== '' && !in_array($fach, $faecher, true)) {
        $fach = '';
    }

    $zeilen = $report->overview($teacher_id, $class_id, $fach, $von, $bis);
    $verlauf = $report->weeklyCourse($teacher_id, $class_id, $fach, $von, $bis);

    $student_id = (int)($_GET['student'] ?? 0);
    if ($student_id > 0 && $roster->belongsToTeacher($student_id, $teacher_id)) {
        foreach ($zeilen as $zeile) {
            if ($zeile['student_id'] === $student_id) {
                $person = $zeile;
                break;
            }
        }

        if ($person !== null) {
            $belege = $report->evidence($student_id, $teacher_id, $fach, $von, $bis);
        }
    }
}

echo $twig->render('live_report.twig', [
    'meine_klassen'     => $meine_klassen,
    'klasse'            => $klasse,
    'class_id'          => $class_id,
    'fach'              => $fach,
    'faecher'           => $faecher,
    'von'               => $von,
    'bis'               => $bis,
    'zeilen'            => $zeilen,
    'verlauf'           => $verlauf,
    'person'            => $person,
    'belege'            => $belege,
    'still_ab'          => ParticipationReport::STILL_AB_TAGEN,
    'current_user_name' => get_current_user_name(),
    'is_admin'          => is_current_user_admin(),
    'is_logged_in'      => true,
]);
