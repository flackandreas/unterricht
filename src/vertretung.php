<?php
/**
 * src/vertretung.php
 * Vertretungsstunden anfordern und freigeben.
 *
 * Untis sagt, wer vertritt, nie was zu tun ist. Hier entsteht aus dem
 * Themenverlauf der Klasse eine anschlussfaehige Stunde - als Entwurf. Erst
 * die Freigabe durch einen Menschen erzeugt den Zugang fuers Lehrerzimmer.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/twig_setup.php';

use App\Homework\HomeworkRepository;
use App\Live\LessonRepository;
use App\Substitute\PlanQueue;
use App\Substitute\PlanService;
use App\Support\AuditLog;

require_login();

$conn = db_connect();
$teacher_id = (int)get_current_user_id();

$lessons = new LessonRepository($conn);
$service = new PlanService($conn, $lessons, new PlanQueue($conn), new AuditLog($conn), new HomeworkRepository($conn));

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Sicherheitsfehler: Ungültiger Token.';
        header('Location: /vertretung.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'request') {
        $class_id = (int)($_POST['class_id'] ?? 0);
        $fach = trim((string)($_POST['fach'] ?? ''));
        $datum = (string)($_POST['lesson_date'] ?? '');
        $period = (int)($_POST['period'] ?? 0);

        if (!in_array($class_id, $erlaubte_klassen, true)) {
            $_SESSION['flash_error'] = 'Diese Klasse gehört nicht zu deinen.';
        } elseif ($fach === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) !== 1) {
            $_SESSION['flash_error'] = 'Fach und Datum werden gebraucht.';
        } else {
            $plan_id = $service->request(
                $teacher_id,
                $class_id,
                $fach,
                $datum,
                $period > 0 ? $period : null,
                (int)($_POST['dauer'] ?? 45),
                (string)($_POST['hinweis'] ?? '')
            );

            // Was tatsaechlich in den Kontext ging - festgehalten beim
            // Anfordern, also genau das, was der Entwurf gesehen hat.
            $angefordert = $service->find($plan_id, $teacher_id);
            $kontext_neu = PlanService::contextFromJson(
                $angefordert !== null && $angefordert['context_json'] !== null
                    ? (string)$angefordert['context_json']
                    : null
            );

            $_SESSION['flash_success'] = $kontext_neu['themen'] === []
                ? 'Angefordert – aber für diese Klasse ist noch kein Themenverlauf hinterlegt. '
                    . 'Ohne ihn entsteht eine allgemeine Stunde.'
                : sprintf(
                    'Angefordert. Grundlage: %d Stundenthemen%s.',
                    count($kontext_neu['themen']),
                    $kontext_neu['hausaufgaben'] !== []
                        ? sprintf(' und %d ausgewertete Hausaufgaben', count($kontext_neu['hausaufgaben']))
                        : ''
                );

            header('Location: /vertretung.php?id=' . $plan_id);
            exit;
        }
    } elseif ($action === 'release') {
        $token = $service->release((int)($_POST['id'] ?? 0), $teacher_id);
        $_SESSION[$token !== null ? 'flash_success' : 'flash_error'] = $token !== null
            ? 'Freigegeben. Der Zugang steht jetzt bereit.'
            : 'Nur ein fertiger Entwurf lässt sich freigeben.';
        header('Location: /vertretung.php?id=' . (int)($_POST['id'] ?? 0));
        exit;
    } elseif ($action === 'delete') {
        $service->delete((int)($_POST['id'] ?? 0), $teacher_id);
        $_SESSION['flash_success'] = 'Gelöscht.';
        header('Location: /vertretung.php');
        exit;
    }

    header('Location: /vertretung.php');
    exit;
}

$plan = null;
$plan_daten = null;
$kontext = ['themen' => [], 'hausaufgaben' => []];

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $plan = $service->find($id, $teacher_id);

    if ($plan !== null) {
        $plan_daten = $plan['plan_json'] !== null ? json_decode((string)$plan['plan_json'], true) : null;
        $kontext = PlanService::contextFromJson(
            $plan['context_json'] !== null ? (string)$plan['context_json'] : null
        );
    }
}

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('vertretung.twig', [
    'csrf_token'        => get_csrf_token(),
    'flash_success'     => $flash_success,
    'flash_error'       => $flash_error,
    'meine_klassen'     => $meine_klassen,
    'plaene'            => $service->forTeacher($teacher_id),
    'plan'              => $plan,
    'plan_daten'        => is_array($plan_daten) ? $plan_daten : null,
    'themen'            => $kontext['themen'],
    'hausaufgaben'      => $kontext['hausaufgaben'],
    'heute'             => date('Y-m-d'),
    'host_url'          => request_base_url(),
    'current_user_name' => get_current_user_name(),
    'is_admin'          => is_current_user_admin(),
    'is_logged_in'      => true,
]);
