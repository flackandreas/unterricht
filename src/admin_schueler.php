<?php
/**
 * src/admin_schueler.php
 * Klassenlisten pflegen.
 *
 * Erste Stelle, an der Namen Minderjaehriger dauerhaft und zentral in dieser
 * Anwendung liegen. Deshalb ausschliesslich fuer die Verwaltung, jede
 * Aenderung im Protokoll, und gespeichert wird erst nach einer Vorschau:
 * eingefuegt wird eine ganze Liste, und eine unvollstaendige Liste
 * archiviert stillschweigend den Rest der Klasse.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

use App\Live\Roster;
use App\Support\AuditLog;

require_admin();

$conn = db_connect();
$roster = new Roster($conn);
$audit = new AuditLog($conn);

$klassen = $conn->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll();

$class_id = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
if ($class_id === 0 && $klassen !== []) {
    $class_id = (int)$klassen[0]['id'];
}

$klasse = null;
foreach ($klassen as $k) {
    if ((int)$k['id'] === $class_id) {
        $klasse = $k;
        break;
    }
}

$vorschau = null;
$eingabe = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Sicherheitsfehler: Ungültiger Token.';
        header('Location: /admin_schueler.php?class_id=' . $class_id);
        exit;
    }

    if ($klasse === null) {
        $_SESSION['flash_error'] = 'Unbekannte Klasse.';
        header('Location: /admin_schueler.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $eingabe = (string)($_POST['namen'] ?? '');

    if ($action === 'preview') {
        $vorschau = $roster->plan($class_id, $eingabe);

        if ($vorschau['namen'] === []) {
            $_SESSION['flash_error'] = 'In der Eingabe war kein verwertbarer Name.';
            $vorschau = null;
        }
    } elseif ($action === 'save') {
        try {
            $ergebnis = $roster->apply($class_id, $eingabe);

            if (array_sum($ergebnis) === 0) {
                $_SESSION['flash_error'] = 'Es gab nichts zu speichern.';
            } else {
                $audit->record(
                    get_current_user_id(),
                    'klassenliste_gespeichert',
                    'class',
                    $class_id,
                    sprintf(
                        '%d angelegt, %d zurückgeholt, %d archiviert (%s)',
                        $ergebnis['angelegt'],
                        $ergebnis['reaktiviert'],
                        $ergebnis['archiviert'],
                        (string)$klasse['name']
                    )
                );

                $_SESSION['flash_success'] = sprintf(
                    'Liste gespeichert: %d neu, %d zurückgeholt, %d archiviert.',
                    $ergebnis['angelegt'],
                    $ergebnis['reaktiviert'],
                    $ergebnis['archiviert']
                );
            }
        } catch (\Throwable $e) {
            error_log('Klassenliste speichern fehlgeschlagen: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Die Liste konnte nicht gespeichert werden.';
        }

        header('Location: /admin_schueler.php?class_id=' . $class_id);
        exit;
    } elseif ($action === 'archive' || $action === 'restore') {
        $student_id = (int)($_POST['student_id'] ?? 0);

        if ($student_id > 0) {
            $roster->setArchived($student_id, $action === 'archive');
            $audit->record(
                get_current_user_id(),
                $action === 'archive' ? 'schueler_archiviert' : 'schueler_zurueckgeholt',
                'student',
                $student_id,
                (string)$klasse['name']
            );
            $_SESSION['flash_success'] = $action === 'archive'
                ? 'Eintrag archiviert.'
                : 'Eintrag zurückgeholt.';
        }

        header('Location: /admin_schueler.php?class_id=' . $class_id);
        exit;
    }
}

$liste = $klasse !== null ? $roster->forClass($class_id, true) : [];

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('admin_schueler.twig', [
    'csrf_token'        => get_csrf_token(),
    'flash_success'     => $flash_success,
    'flash_error'       => $flash_error,
    'klassen'           => $klassen,
    'klasse'            => $klasse,
    'class_id'          => $class_id,
    'liste'             => $liste,
    'vorschau'          => $vorschau,
    'eingabe'           => $eingabe,
    'current_user_name' => get_current_user_name(),
    'is_admin'          => is_current_user_admin(),
    'is_logged_in'      => true,
]);
