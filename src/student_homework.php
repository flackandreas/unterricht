<?php
/**
 * src/student_homework.php
 * Abgabe von Hausaufgaben und Anzeige der Auswertung.
 *
 * Der Ablauf ist zweistufig: die Abgabe wird sofort quittiert und in die
 * Warteschlange gestellt, die Auswertung erledigt bin/worker.php. Die
 * Ergebnisseite fragt den Stand nach. Vorher hing der Request bis zu 60
 * Sekunden im KI-Aufruf fest; brach die Verbindung ab, blieb die Abgabe
 * dauerhaft unausgewertet.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/error_page.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/twig_setup.php';

use App\Ai\AIService;
use App\Homework\EvaluationQueue;
use App\Homework\Gamification;
use App\Homework\HomeworkRepository;
use App\Homework\SubmissionService;

$conn = db_connect();
$repository = new HomeworkRepository($conn);
$queue = new EvaluationQueue($conn);
$gamification = new Gamification($conn);
$service = new SubmissionService($conn, $repository, $queue, $gamification);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * Antwortet mit JSON und beendet den Request.
 */
function json_out(array $daten, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Laedt eine Einreichung anhand ihres Tokens.
 */
function load_submission(PDO $conn, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT s.*, a.title AS assignment_title, a.klasse, a.fach, a.description,
               a.expected_submissions, a.quest_reward, a.token AS assignment_token,
               a.release_mode, a.show_score, a.max_submissions_per_student, a.allow_resubmission,
               e.student_feedback, e.score, e.error_markers, e.criteria_scores,
               e.review_status, e.released_at,
               j.status AS job_status, j.attempts AS job_attempts
        FROM homework_submissions s
        JOIN homework_assignments a ON a.id = s.assignment_id
        LEFT JOIN homework_evaluations e ON e.submission_id = s.id
        LEFT JOIN evaluation_jobs j ON j.submission_id = s.id
        WHERE s.token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);

    return $stmt->fetch() ?: null;
}

/**
 * Fasst den Bearbeitungsstand fuer die Anzeige zusammen.
 */
function submission_state(array $sub): string {
    if (($sub['status'] ?? '') === 'failed') {
        return 'failed';
    }
    if (empty($sub['student_feedback'])) {
        return 'processing';
    }
    if (($sub['review_status'] ?? 'released') === 'draft') {
        return 'awaiting_review';
    }

    return 'ready';
}

// ---------------------------------------------------------------------
// Statusabfrage der Ergebnisseite
// ---------------------------------------------------------------------
if ($action === 'status') {
    $token = (string)($_GET['t'] ?? '');

    if (!rate_limit_allow($conn, 'submission_status', request_client_ip(), 600, 3600)) {
        json_out(['state' => 'processing'], 429);
    }

    $sub = load_submission($conn, $token);
    if ($sub === null) {
        json_out(['state' => 'unknown'], 404);
    }

    json_out([
        'state'    => submission_state($sub),
        'attempts' => (int)($sub['job_attempts'] ?? 0),
    ]);
}

// ---------------------------------------------------------------------
// Sprachniveau des Feedbacks umformulieren
// ---------------------------------------------------------------------
if ($action === 'rephrase_level') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
        json_out(['success' => false, 'error' => 'Ungültige Anfrage. Bitte lade die Seite neu.'], 400);
    }

    $sub_token = (string)($_POST['submission_token'] ?? '');
    $target_level = (string)($_POST['target_level'] ?? 'appropriate');

    if (!in_array($target_level, ['simple', 'appropriate', 'complex'], true)) {
        $target_level = 'appropriate';
    }

    if (!preg_match('/^[a-f0-9]{32}$/', $sub_token)) {
        json_out(['success' => false, 'error' => 'Einreichung nicht gefunden.'], 400);
    }

    // Jeder Aufruf kostet einen Gemini-Request.
    if (!rate_limit_allow($conn, 'rephrase_level', $sub_token, 12, 3600)
        || !rate_limit_allow($conn, 'rephrase_level_ip', request_client_ip(), 60, 3600)) {
        json_out(['success' => false, 'error' => 'Zu viele Anfragen. Bitte versuche es später erneut.'], 429);
    }

    $sub = load_submission($conn, $sub_token);

    if ($sub === null || empty($sub['student_feedback']) || submission_state($sub) !== 'ready') {
        json_out(['success' => false, 'error' => 'Einreichung nicht gefunden.'], 404);
    }

    try {
        $ai = new AIService();
        json_out([
            'success' => true,
            'rephrased_feedback' => $ai->rephraseStudentFeedback(
                (string)$sub['student_feedback'],
                $target_level,
                (string)($sub['description'] ?? '')
            ),
        ]);
    } catch (\Throwable $e) {
        error_log('Umformulierung fehlgeschlagen: ' . $e->getMessage());
        json_out(['success' => false, 'error' => 'Das Feedback kann gerade nicht umformuliert werden.'], 503);
    }
}

// ---------------------------------------------------------------------
// Rueckmeldung zum Feedback ("das stimmt nicht")
// ---------------------------------------------------------------------
if ($action === 'report_feedback') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
        json_out(['success' => false, 'error' => 'Ungültige Anfrage. Bitte lade die Seite neu.'], 400);
    }

    $sub_token = (string)($_POST['submission_token'] ?? '');
    $reason = (string)($_POST['reason'] ?? 'other');
    $message = trim((string)($_POST['message'] ?? ''));

    if (!in_array($reason, ['wrong', 'unclear', 'unfair', 'other'], true)) {
        $reason = 'other';
    }

    $sub = load_submission($conn, $sub_token);
    if ($sub === null) {
        json_out(['success' => false, 'error' => 'Einreichung nicht gefunden.'], 404);
    }

    if (!rate_limit_allow($conn, 'feedback_report', $sub_token, 3, 86400)) {
        json_out(['success' => false, 'error' => 'Du hast dazu bereits eine Rückmeldung gesendet.'], 429);
    }

    $conn->prepare('INSERT INTO submission_feedback_reports (submission_id, reason, message) VALUES (?, ?, ?)')
        ->execute([(int)$sub['id'], $reason, mb_substr($message, 0, 1000)]);

    json_out(['success' => true]);
}

// ---------------------------------------------------------------------
// Ergebnisseite
// ---------------------------------------------------------------------
$view_token = (string)($_GET['view'] ?? '');
if ($view_token !== '') {
    $sub = load_submission($conn, $view_token);

    if ($sub === null) {
        error_page("Einreichung nicht gefunden", "Dieser Link ist ungültig oder die Abgabe wurde gelöscht.", 404, null);
    }

    $markers = [];
    if (!empty($sub['error_markers'])) {
        $markers = json_decode((string)$sub['error_markers'], true) ?: [];
    }

    $criteriaScores = [];
    if (!empty($sub['criteria_scores'])) {
        $criteriaScores = json_decode((string)$sub['criteria_scores'], true) ?: [];
    }

    echo $twig->render('student_homework_view.twig', [
        'sub'                  => $sub,
        'state'                => submission_state($sub),
        'markers'              => $markers,
        'criteria'             => build_criteria_view($repository->criteriaFor((int)$sub['assignment_id']), $criteriaScores),
        'progress'             => $gamification->progressFor((string)$sub['klasse'], (string)$sub['student_name']),
        'leaderboard'          => $gamification->classLeaderboard((string)$sub['klasse']),
        'actual_submissions'   => $repository->distinctStudentCount((int)$sub['assignment_id']),
        'expected_submissions' => (int)$sub['expected_submissions'],
        'quest_reward'         => $sub['quest_reward'] ?? null,
        'csrf_token'           => get_csrf_token(),
        'host_url'             => request_base_url(),
    ]);
    exit;
}

// ---------------------------------------------------------------------
// Abgabeformular
// ---------------------------------------------------------------------
$token = (string)($_GET['t'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    error_page("Ungültiger Link", "Bitte prüfe den Link oder frage deine Lehrkraft nach einem neuen.", 404, null);
}

$assignment = $repository->assignmentByToken($token);

if ($assignment === null) {
    error_page("Hausaufgabe nicht gefunden", "Der Link ist abgelaufen oder die Hausaufgabe wurde entfernt.", 404, null);
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = handle_submission($assignment, $conn, $service, $token);
}

echo $twig->render('student_homework.twig', [
    'assignment'           => $assignment,
    'error'                => $error,
    'due_date'             => $assignment['due_date'] ?? null,
    'is_overdue'           => !empty($assignment['due_date']) && strtotime((string)$assignment['due_date']) < time(),
    'actual_submissions'   => $repository->distinctStudentCount((int)$assignment['id']),
    'expected_submissions' => (int)($assignment['expected_submissions'] ?? 0),
    'quest_reward'         => $assignment['quest_reward'] ?? null,
    'criteria'             => $repository->criteriaFor((int)$assignment['id']),
    'csrf_token'           => get_csrf_token(),
]);

/**
 * Nimmt das Abgabeformular entgegen.
 *
 * Bei Erfolg wird auf die Ergebnisseite weitergeleitet (Post/Redirect/Get),
 * damit ein Neuladen keine zweite Abgabe ausloest.
 *
 * @return string|null Fehlermeldung oder null
 */
function handle_submission(array $assignment, PDO $conn, SubmissionService $service, string $token): ?string {
    // Ueberschreitet der Upload post_max_size, sind $_POST und $_FILES leer.
    if ($_POST === [] && $_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        return "Die hochgeladenen Daten überschreiten das Limit. Bitte lade ein kleineres Bild hoch.";
    }

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        return "Sicherheitsfehler: Ungültiger Token. Bitte lade die Seite neu.";
    }

    if (!rate_limit_allow($conn, 'homework_submit_ip', request_client_ip(), 20, 3600)
        || !rate_limit_allow($conn, 'homework_submit_task', $token, 200, 3600)) {
        http_response_code(429);
        return "Es wurden zu viele Abgaben in kurzer Zeit gesendet. Bitte versuche es in einer Stunde erneut.";
    }

    $student_name = trim((string)($_POST['student_name'] ?? ''));
    if ($student_name === '') {
        return "Bitte gib deinen Namen ein.";
    }

    $fehler = upload_error_message($_FILES['homework_image'] ?? null);
    if ($fehler !== null) {
        return $fehler;
    }

    $file = $_FILES['homework_image'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = (string)finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $erlaubt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($erlaubt[$mimeType])) {
        return "Nur JPG, PNG oder WEBP Bilder sind erlaubt. Erkannt: " . htmlspecialchars($mimeType);
    }

    // Ablage ausserhalb des DocumentRoot, Dateiname aus dem Zufallsgenerator.
    $relativePath = storage_store_upload($file['tmp_name'], 'homework', $erlaubt[$mimeType]);
    $destination = $relativePath !== null ? storage_resolve($relativePath) : null;

    if ($destination === null) {
        return "Die Datei konnte nicht gespeichert werden. Bitte versuche es erneut.";
    }

    autoRotateImage($destination);

    $ergebnis = $service->submit($assignment, $student_name, $relativePath);

    if (!$ergebnis['ok']) {
        storage_delete($relativePath);
        return $ergebnis['error'];
    }

    header('Location: /student_homework.php?view=' . $ergebnis['token'], true, 303);
    exit;
}

/**
 * Uebersetzt den Upload-Fehlercode in eine verstaendliche Meldung.
 */
function upload_error_message(?array $file): ?string {
    if ($file === null) {
        return "Bitte lade ein Bild deiner Hausaufgabe hoch.";
    }

    return match ($file['error']) {
        UPLOAD_ERR_OK        => null,
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE => "Das Bild ist zu groß. Bitte lade ein kleineres Foto hoch (max. 40 MB).",
        UPLOAD_ERR_PARTIAL   => "Das Bild wurde nur teilweise hochgeladen. Bitte versuche es erneut.",
        UPLOAD_ERR_NO_FILE   => "Bitte lade ein Bild deiner Hausaufgabe hoch.",
        UPLOAD_ERR_NO_TMP_DIR,
        UPLOAD_ERR_CANT_WRITE => "Das Bild konnte auf dem Server nicht gespeichert werden.",
        default              => "Beim Hochladen ist ein Fehler aufgetreten. Bitte versuche es erneut.",
    };
}

/**
 * Verbindet das Bewertungsraster mit den erreichten Punkten.
 *
 * @param list<array{id:int,label:string,description:string,max_points:int}> $criteria
 * @param list<array{id:int,points:float,comment:string}> $scores
 * @return list<array<string,mixed>>
 */
function build_criteria_view(array $criteria, array $scores): array {
    if ($criteria === []) {
        return [];
    }

    $nachId = [];
    foreach ($scores as $s) {
        if (isset($s['id'])) {
            $nachId[(int)$s['id']] = $s;
        }
    }

    $ergebnis = [];
    foreach ($criteria as $k) {
        $treffer = $nachId[$k['id']] ?? null;
        $ergebnis[] = $k + [
            'points'  => $treffer !== null ? (float)$treffer['points'] : null,
            'comment' => $treffer !== null ? (string)$treffer['comment'] : '',
            'percent' => $treffer !== null && $k['max_points'] > 0
                ? (int)round((float)$treffer['points'] / $k['max_points'] * 100)
                : 0,
        ];
    }

    return $ergebnis;
}

/**
 * Dreht ein Foto anhand seiner EXIF-Ausrichtung.
 */
function autoRotateImage(string $imagePath): void {
    if (!is_file($imagePath) || !function_exists('exif_read_data')) {
        return;
    }

    $exif = @exif_read_data($imagePath);
    $ort = (int)($exif['Orientation'] ?? $exif['IFD0']['Orientation'] ?? 0);

    if (!in_array($ort, [3, 6, 8], true)) {
        return;
    }

    $mime = (string)@mime_content_type($imagePath);
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($imagePath),
        'image/png'  => @imagecreatefrompng($imagePath),
        'image/webp' => @imagecreatefromwebp($imagePath),
        default      => false,
    };

    if ($image === false) {
        return;
    }

    $grad = match ($ort) {
        3 => 180,
        6 => 270,
        8 => 90,
    };

    $gedreht = @imagerotate($image, $grad, 0);
    if ($gedreht !== false) {
        imagedestroy($image);
        $image = $gedreht;
    }

    match ($mime) {
        'image/jpeg' => imagejpeg($image, $imagePath, 92),
        'image/png'  => imagepng($image, $imagePath),
        'image/webp' => imagewebp($image, $imagePath, 92),
        default      => null,
    };

    imagedestroy($image);
}
