<?php
/**
 * src/live_action.php
 * Nimmt Beitraege des Erfassungsschirms entgegen - buendelweise.
 *
 * Einzelne Tipps zu schicken waere im Klassenzimmer aussichtslos: das Geraet
 * sammelt sie und liefert nach, sobald es Netz hat. Die client_uid je
 * Beitrag macht das Nachliefern wiederholbar, ohne doppelt zu zaehlen.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

use App\Live\LessonRepository;
use App\Live\ParticipationRepository;

header('Content-Type: application/json; charset=utf-8');

/**
 * @param array<string,mixed> $daten
 */
function live_antwort(array $daten, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    live_antwort(['ok' => false, 'fehler' => 'Nicht angemeldet.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    live_antwort(['ok' => false, 'fehler' => 'Nur POST.'], 405);
}

$rohdaten = (string)file_get_contents('php://input');
$eingang = json_decode($rohdaten, true);

if (!is_array($eingang)) {
    live_antwort(['ok' => false, 'fehler' => 'Unlesbare Anfrage.'], 400);
}

if (!verify_csrf_token((string)($eingang['csrf_token'] ?? ''))) {
    live_antwort(['ok' => false, 'fehler' => 'Sitzung abgelaufen. Bitte die Seite neu laden.'], 419);
}

$conn = db_connect();
$teacher_id = (int)get_current_user_id();

$lessons = new LessonRepository($conn);
$participation = new ParticipationRepository($conn);

$class_id = (int)($eingang['class_id'] ?? 0);
$fach     = trim((string)($eingang['fach'] ?? ''));
$period   = max(1, min(10, (int)($eingang['period'] ?? 0)));
$datum    = (string)($eingang['date'] ?? date('Y-m-d'));

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) !== 1) {
    $datum = date('Y-m-d');
}

// Gehoert die Klasse dieser Lehrkraft?
$pruefung = $conn->prepare('SELECT 1 FROM teacher_classes WHERE teacher_id = ? AND class_id = ? LIMIT 1');
$pruefung->execute([$teacher_id, $class_id]);

if ($pruefung->fetchColumn() === false || $fach === '' || $period === 0) {
    live_antwort(['ok' => false, 'fehler' => 'Klasse, Fach oder Stunde fehlt.'], 400);
}

try {
    $session = $lessons->openOrCreate($teacher_id, $class_id, $fach, $datum, $period);
    $session_id = (int)$session['id'];

    if (array_key_exists('topic', $eingang)) {
        $lessons->updateTopic($session_id, $teacher_id, (string)$eingang['topic']);
    }

    $ereignisse = is_array($eingang['events'] ?? null) ? array_values($eingang['events']) : [];
    $ruecknahmen = is_array($eingang['removals'] ?? null) ? array_values($eingang['removals']) : [];

    $entfernt = $participation->remove($session_id, array_map('strval', $ruecknahmen));

    /** @var list<array<string,mixed>> $gefiltert */
    $gefiltert = array_values(array_filter($ereignisse, 'is_array'));
    $ergebnis = $participation->record($session_id, $gefiltert);

    if (($eingang['close'] ?? false) === true) {
        $lessons->close($session_id, $teacher_id);
    }

    live_antwort([
        'ok'         => true,
        'session_id' => $session_id,
        'entfernt'   => $entfernt,
        'stand'      => $participation->tallyForSession($session_id),
    ] + $ergebnis);
} catch (\Throwable $e) {
    error_log('Beteiligung speichern fehlgeschlagen: ' . $e->getMessage());
    live_antwort(['ok' => false, 'fehler' => 'Die Beiträge konnten nicht gespeichert werden.'], 500);
}
