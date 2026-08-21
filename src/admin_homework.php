<?php
/**
 * src/admin_homework.php
 * Hausaufgabenverwaltung für Lehrkräfte.
 *
 * Die Datenbankzugriffe liegen in App\Homework\HomeworkRepository, der Ablauf
 * einer Abgabe in App\Homework\SubmissionService. Vorher steckte beides samt
 * Routing und Rendering in dieser Datei.
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
use App\Support\AuditLog;
use App\Support\UsageRecorder;

require_login();

$user_id = (int)get_current_user_id();
$conn = db_connect();
$repository = new HomeworkRepository($conn);
$queue = new EvaluationQueue($conn);
$gamification = new Gamification($conn);
$service = new SubmissionService($conn, $repository, $queue, $gamification);
$audit = new AuditLog($conn);

$action = $_GET['action'] ?? 'list';

/**
 * Leitet mit Meldung zurück und beendet den Request.
 */
function redirect_back(string $ziel, ?string $erfolg = null, ?string $fehler = null): never {
    if ($erfolg !== null) {
        $_SESSION['flash_success'] = $erfolg;
    }
    if ($fehler !== null) {
        $_SESSION['flash_error'] = $fehler;
    }

    header('Location: ' . $ziel, true, 303);
    exit;
}

/**
 * Liest das Bewertungsraster aus dem Formular.
 *
 * @return list<array{label:string,description:string,max_points:int}>
 */
function criteria_from_request(): array {
    $labels = (array)($_POST['criterion_label'] ?? []);
    $punkte = (array)($_POST['criterion_points'] ?? []);
    $texte  = (array)($_POST['criterion_description'] ?? []);

    $raster = [];
    foreach ($labels as $i => $label) {
        $label = trim((string)$label);
        if ($label === '') {
            continue;
        }

        $raster[] = [
            'label'       => $label,
            'description' => trim((string)($texte[$i] ?? '')),
            'max_points'  => max(1, (int)($punkte[$i] ?? 10)),
        ];
    }

    return $raster;
}

// =====================================================================
// Schreibende Aktionen (nur POST, CSRF geprüft)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        error_page("Sicherheitsprüfung fehlgeschlagen", "Die Seite war zu lange geöffnet. Bitte lade sie neu und versuche es erneut.", 400, "/admin_homework.php");
    }

    if ($action === 'create') {
        $feedback_level = in_array($_POST['feedback_level'] ?? '', ['simple', 'appropriate', 'complex'], true)
            ? $_POST['feedback_level'] : 'appropriate';
        $release_mode = ($_POST['release_mode'] ?? '') === 'review' ? 'review' : 'immediate';

        $context_image_path = null;
        if (isset($_FILES['context_image']) && $_FILES['context_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['context_image'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = (string)finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $erlaubt = [
                'image/jpeg' => 'jpg', 'image/png' => 'png',
                'image/webp' => 'webp', 'application/pdf' => 'pdf',
            ];

            if (!isset($erlaubt[$mimeType])) {
                redirect_back('/admin_homework.php', null, 'Ungültiges Dateiformat für das Kontextdokument. Nur JPG, PNG, WEBP oder PDF.');
            }

            // Musterlösungen liegen außerhalb des DocumentRoot.
            $context_image_path = storage_store_upload($file['tmp_name'], 'context', $erlaubt[$mimeType]);
        }

        $due_date = trim((string)($_POST['due_date'] ?? ''));

        $stmt = $conn->prepare('
            INSERT INTO homework_assignments
                (teacher_id, klasse, fach, title, description, token, context_image_path,
                 expected_submissions, quest_reward, feedback_level, release_mode, show_score,
                 max_submissions_per_student, allow_resubmission, retention_days, due_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([
            $user_id,
            (string)($_POST['klasse'] ?? ''),
            (string)($_POST['fach'] ?? ''),
            (string)($_POST['title'] ?? ''),
            (string)($_POST['description'] ?? ''),
            bin2hex(random_bytes(16)),
            $context_image_path,
            max(0, (int)($_POST['expected_submissions'] ?? 0)),
            trim((string)($_POST['quest_reward'] ?? '')),
            $feedback_level,
            $release_mode,
            isset($_POST['show_score']) ? 1 : 0,
            max(1, min(10, (int)($_POST['max_submissions_per_student'] ?? 3))),
            isset($_POST['allow_resubmission']) ? 1 : 0,
            max(0, min(3650, (int)($_POST['retention_days'] ?? 90))),
            $due_date !== '' ? $due_date : null,
        ]);

        $assignmentId = (int)$conn->lastInsertId();
        $repository->replaceCriteria($assignmentId, criteria_from_request());
        $audit->record($user_id, 'assignment.created', 'assignment', $assignmentId, (string)($_POST['title'] ?? ''));

        redirect_back('/admin_homework.php', 'Hausaufgabe erfolgreich erstellt.');
    }

    if ($action === 'update_settings') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);

        if ($repository->assignmentForTeacher($assignmentId, $user_id) === null) {
            redirect_back('/admin_homework.php', null, 'Keine Berechtigung.');
        }

        $release_mode = ($_POST['release_mode'] ?? '') === 'review' ? 'review' : 'immediate';

        $conn->prepare('
            UPDATE homework_assignments
            SET release_mode = ?, show_score = ?, max_submissions_per_student = ?,
                allow_resubmission = ?, retention_days = ?, due_date = ?
            WHERE id = ? AND teacher_id = ?
        ')->execute([
            $release_mode,
            isset($_POST['show_score']) ? 1 : 0,
            max(1, min(10, (int)($_POST['max_submissions_per_student'] ?? 3))),
            isset($_POST['allow_resubmission']) ? 1 : 0,
            max(0, min(3650, (int)($_POST['retention_days'] ?? 90))),
            trim((string)($_POST['due_date'] ?? '')) !== '' ? $_POST['due_date'] : null,
            $assignmentId,
            $user_id,
        ]);

        $repository->replaceCriteria($assignmentId, criteria_from_request());
        $audit->record($user_id, 'assignment.updated', 'assignment', $assignmentId);

        redirect_back('/admin_homework.php?action=view&id=' . $assignmentId, 'Einstellungen gespeichert.');
    }

    if ($action === 'delete_assignment') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $assignment = $repository->assignmentForTeacher($assignmentId, $user_id);

        if ($assignment === null) {
            redirect_back('/admin_homework.php', null, 'Keine Berechtigung oder Hausaufgabe nicht gefunden.');
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare('SELECT image_path FROM homework_submissions WHERE assignment_id = ?');
            $stmt->execute([$assignmentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $bild) {
                storage_delete($bild);
            }
            storage_delete($assignment['context_image_path'] ?? null);

            $conn->prepare('DELETE FROM homework_assignments WHERE id = ?')->execute([$assignmentId]);
            $conn->commit();

            $audit->record($user_id, 'assignment.deleted', 'assignment', $assignmentId, (string)$assignment['title']);
        } catch (\Throwable $e) {
            $conn->rollBack();
            error_log('Löschen der Hausaufgabe fehlgeschlagen: ' . $e->getMessage());
            redirect_back('/admin_homework.php', null, 'Die Hausaufgabe konnte nicht gelöscht werden.');
        }

        redirect_back('/admin_homework.php', 'Hausaufgabe und alle zugehörigen Daten wurden gelöscht.');
    }

    if ($action === 'delete_submissions_bulk') {
        $ids = array_map('intval', (array)($_POST['submission_ids'] ?? []));
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $ziel = $assignmentId > 0 ? '/admin_homework.php?action=view&id=' . $assignmentId : '/admin_homework.php';

        if ($ids === []) {
            redirect_back($ziel, null, 'Keine Einreichungen zum Löschen ausgewählt.');
        }

        $platzhalter = implode(',', array_fill(0, count($ids), '?'));
        $parameter = array_merge($ids, [$user_id]);

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT s.image_path FROM homework_submissions s
                JOIN homework_assignments a ON a.id = s.assignment_id
                WHERE s.id IN ($platzhalter) AND a.teacher_id = ?
            ");
            $stmt->execute($parameter);
            $dateien = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($dateien as $datei) {
                storage_delete($datei);
            }

            $conn->prepare("
                DELETE s FROM homework_submissions s
                JOIN homework_assignments a ON a.id = s.assignment_id
                WHERE s.id IN ($platzhalter) AND a.teacher_id = ?
            ")->execute($parameter);

            $conn->commit();
            $audit->record($user_id, 'submissions.deleted', 'assignment', $assignmentId, count($dateien) . ' Einreichung(en)');
        } catch (\Throwable $e) {
            $conn->rollBack();
            error_log('Sammellöschung fehlgeschlagen: ' . $e->getMessage());
            redirect_back($ziel, null, 'Die Einreichungen konnten nicht gelöscht werden.');
        }

        redirect_back($ziel, count($dateien) . ' Einreichung(en) gelöscht.');
    }

    if ($action === 'edit_evaluation') {
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $score = ($_POST['score'] ?? '') !== '' ? max(0, min(100, (int)$_POST['score'])) : null;

        $stmt = $conn->prepare('
            SELECT s.id, e.id AS evaluation_id, e.score AS alter_score
            FROM homework_submissions s
            JOIN homework_assignments a ON a.id = s.assignment_id
            LEFT JOIN homework_evaluations e ON e.submission_id = s.id
            WHERE s.id = ? AND a.teacher_id = ?
        ');
        $stmt->execute([$submissionId, $user_id]);
        $info = $stmt->fetch();

        if (!$info) {
            redirect_back('/admin_homework.php', null, 'Keine Berechtigung oder Einreichung nicht gefunden.');
        }

        $notizen = trim((string)($_POST['teacher_notes'] ?? ''));
        $feedback = trim((string)($_POST['student_feedback'] ?? ''));

        if ($info['evaluation_id']) {
            $conn->prepare('
                UPDATE homework_evaluations
                SET score = ?, teacher_notes = ?, student_feedback = ?, edited_by_teacher = 1
                WHERE submission_id = ?
            ')->execute([$score, $notizen, $feedback, $submissionId]);
        } else {
            $conn->prepare("
                INSERT INTO homework_evaluations
                    (submission_id, score, teacher_notes, student_feedback, edited_by_teacher, review_status, released_at)
                VALUES (?, ?, ?, ?, 1, 'released', NOW())
            ")->execute([$submissionId, $score, $notizen, $feedback]);

            $conn->prepare("UPDATE homework_submissions SET status = 'evaluated' WHERE id = ?")->execute([$submissionId]);
        }

        $audit->record(
            $user_id,
            'evaluation.edited',
            'submission',
            $submissionId,
            sprintf('Punkte: %s → %s', $info['alter_score'] ?? '–', $score ?? '–')
        );

        redirect_back('/admin_homework.php?action=view&id=' . $assignmentId, 'Bewertung gespeichert.');
    }

    if ($action === 'release') {
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $ziel = (int)($_POST['assignment_id'] ?? 0) > 0
            ? '/admin_homework.php?action=view&id=' . (int)$_POST['assignment_id']
            : '/admin_homework.php?action=review';

        $service->release($submissionId, $user_id)
            ? redirect_back($ziel, 'Auswertung freigegeben.')
            : redirect_back($ziel, null, 'Freigabe nicht möglich.');
    }

    if ($action === 'release_all') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $anzahl = $service->releaseAll($assignmentId, $user_id);

        redirect_back(
            '/admin_homework.php?action=view&id=' . $assignmentId,
            $anzahl . ' Auswertung(en) freigegeben.'
        );
    }

    if ($action === 'requeue') {
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);

        $stmt = $conn->prepare('
            SELECT s.id FROM homework_submissions s
            JOIN homework_assignments a ON a.id = s.assignment_id
            WHERE s.id = ? AND a.teacher_id = ?
        ');
        $stmt->execute([$submissionId, $user_id]);

        if ($stmt->fetch() === false) {
            redirect_back('/admin_homework.php', null, 'Keine Berechtigung.');
        }

        $queue->push($submissionId);
        $audit->record($user_id, 'evaluation.requeued', 'submission', $submissionId);

        redirect_back('/admin_homework.php?action=view&id=' . $assignmentId, 'Auswertung erneut eingeplant.');
    }

    if ($action === 'resolve_report') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);

        $conn->prepare('
            UPDATE submission_feedback_reports r
            JOIN homework_submissions s ON s.id = r.submission_id
            JOIN homework_assignments a ON a.id = s.assignment_id
            SET r.resolved_at = NOW()
            WHERE r.id = ? AND a.teacher_id = ?
        ')->execute([$reportId, $user_id]);

        redirect_back('/admin_homework.php?action=view&id=' . $assignmentId, 'Rückmeldung als erledigt markiert.');
    }

    if ($action === 'refresh_summary') {
        $assignmentId = (int)($_POST['assignment_id'] ?? $_GET['id'] ?? 0);
        $ziel = '/admin_homework.php?action=view&id=' . $assignmentId;

        if (!rate_limit_allow($conn, 'ai_summary', (string)$user_id, 30, 3600)) {
            redirect_back($ziel, null, 'Zu viele Analysen in kurzer Zeit. Bitte in einer Stunde erneut versuchen.');
        }

        $assignment = $repository->assignmentForTeacher($assignmentId, $user_id);
        if ($assignment === null) {
            redirect_back('/admin_homework.php', null, 'Hausaufgabe nicht gefunden oder keine Berechtigung.');
        }

        generate_summary($conn, $repository, $assignment, $user_id);
        redirect_back($ziel, 'Analyse der häufigsten Fehler wurde aktualisiert.');
    }

    redirect_back('/admin_homework.php', null, 'Unbekannte Aktion.');
}

// =====================================================================
// Lesende Ansichten
// =====================================================================
if ($action === 'export_summary') {
    $assignmentId = (int)($_GET['id'] ?? 0);
    $assignment = $repository->assignmentForTeacher($assignmentId, $user_id);

    if ($assignment === null) {
        error_page("Hausaufgabe nicht gefunden", "Sie existiert nicht oder gehört einer anderen Lehrkraft.", 404, "/admin_homework.php");
    }

    export_summary_pdf($twig, $repository, $assignment);
}

if ($action === 'review') {
    echo $twig->render('admin_homework_review.twig', [
        'queue'             => $repository->reviewQueue($user_id),
        'csrf_token'        => get_csrf_token(),
        'flash_success'     => flash('flash_success'),
        'flash_error'       => flash('flash_error'),
        'is_logged_in'      => true,
        'is_admin'          => is_current_user_admin(),
        'current_user_name' => get_current_user_name(),
    ]);
    exit;
}

if ($action === 'view') {
    $assignmentId = (int)($_GET['id'] ?? 0);
    $assignment = $repository->assignmentForTeacher($assignmentId, $user_id);

    if ($assignment === null) {
        error_page("Hausaufgabe nicht gefunden", "Sie existiert nicht oder gehört einer anderen Lehrkraft.", 404, "/admin_homework.php");
    }

    $submissions = $repository->submissionsForAssignment($assignmentId);

    // Zusammenfassung nur erzeugen, wenn sie fehlt und das Limit es zulässt.
    if (empty($assignment['summary_common_errors'])
        && has_evaluated_submission($submissions)
        && rate_limit_allow($conn, 'ai_summary', (string)$user_id, 30, 3600)) {
        $summary = generate_summary($conn, $repository, $assignment, $user_id);
        $assignment['summary_common_errors'] = $summary['common_errors'];
        $assignment['summary_solution_approach'] = $summary['solution_approach'];
        $assignment['summary_updated_at'] = date('Y-m-d H:i:s');
    }

    echo $twig->render('admin_homework_details.twig', [
        'assignment'           => $assignment,
        'submissions'          => $submissions,
        'criteria'             => $repository->criteriaFor($assignmentId),
        'reports'              => open_reports($conn, $assignmentId, $user_id),
        'audit'                => $audit->forEntity('assignment', $assignmentId, 20),
        'queue_counts'         => $queue->counts(),
        'draft_count'          => count(array_filter($submissions, static fn(array $s): bool => ($s['review_status'] ?? '') === 'draft')),
        'actual_submissions'   => $repository->distinctStudentCount($assignmentId),
        'expected_submissions' => (int)($assignment['expected_submissions'] ?? 0),
        'quest_reward'         => $assignment['quest_reward'] ?? null,
        'csrf_token'           => get_csrf_token(),
        'flash_success'        => flash('flash_success'),
        'flash_error'          => flash('flash_error'),
        'is_logged_in'         => true,
        'is_admin'             => is_current_user_admin(),
        'current_user_name'    => get_current_user_name(),
        'host_url'             => request_base_url(),
    ]);
    exit;
}

// Übersicht
$seite = max(1, (int)($_GET['page'] ?? 1));
$proSeite = 20;

try {
    $stmt = $conn->prepare('SELECT id, name FROM classes ORDER BY name ASC');
    $stmt->execute();
    $all_classes = $stmt->fetchAll();

    $stmt = $conn->prepare('SELECT class_id FROM teacher_classes WHERE teacher_id = ?');
    $stmt->execute([$user_id]);
    $selected_class_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $all_classes = [];
    $selected_class_ids = [];
}

$gesamt = $repository->countAssignmentsForTeacher($user_id);

echo $twig->render('admin_homework.twig', [
    'assignments'        => $repository->assignmentsForTeacher($user_id, $proSeite, ($seite - 1) * $proSeite),
    'all_classes'        => $all_classes,
    'selected_class_ids' => $selected_class_ids,
    'review_count'       => $repository->pendingReviewCount($user_id),
    'queue_counts'       => $queue->counts(),
    'page'               => $seite,
    'total_pages'        => max(1, (int)ceil($gesamt / $proSeite)),
    'total_assignments'  => $gesamt,
    'csrf_token'         => get_csrf_token(),
    'flash_success'      => flash('flash_success'),
    'flash_error'        => flash('flash_error'),
    'host_url'           => request_base_url(),
    'is_logged_in'       => true,
    'is_admin'           => is_current_user_admin(),
    'current_user_name'  => get_current_user_name(),
]);

/**
 * Liest eine Flash-Nachricht und entfernt sie.
 */
function flash(string $key): ?string {
    $wert = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);

    return $wert;
}

/**
 * @param list<array<string,mixed>> $submissions
 */
function has_evaluated_submission(array $submissions): bool {
    foreach ($submissions as $sub) {
        if (!empty($sub['teacher_notes'])) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{common_errors:string,solution_approach:string}
 */
function generate_summary(PDO $conn, HomeworkRepository $repository, array $assignment, int $userId): array {
    $ai = new AIService(new UsageRecorder($conn, $userId, (int)$assignment['id']));
    $summary = $ai->generateAssignmentSummary(
        (string)$assignment['title'],
        (string)$assignment['description'],
        $repository->submissionsForAssignment((int)$assignment['id'])
    );

    $conn->prepare('
        UPDATE homework_assignments
        SET summary_common_errors = ?, summary_solution_approach = ?, summary_updated_at = NOW()
        WHERE id = ?
    ')->execute([$summary['common_errors'], $summary['solution_approach'], (int)$assignment['id']]);

    return $summary;
}

/**
 * Erzeugt die Unterrichtsvorbereitung als PDF.
 *
 * Die Klassen-Zusammenfassung ist der didaktisch wertvollste Teil des
 * Systems - bisher liess sie sich nur am Bildschirm lesen. dompdf war schon
 * als Abhaengigkeit vorhanden, wurde aber nirgends genutzt.
 */
function export_summary_pdf(\Twig\Environment $twig, HomeworkRepository $repository, array $assignment): never {
    $submissions = $repository->submissionsForAssignment((int)$assignment['id']);
    $punkte = array_values(array_filter(
        array_map(static fn(array $s) => $s['score'] !== null ? (int)$s['score'] : null, $submissions),
        static fn(?int $p): bool => $p !== null
    ));

    $html = $twig->render('pdf_assignment_summary.twig', [
        'assignment'  => $assignment,
        'criteria'    => $repository->criteriaFor((int)$assignment['id']),
        'anzahl'      => count($submissions),
        'bewertet'    => count($punkte),
        'durchschnitt' => $punkte !== [] ? round(array_sum($punkte) / count($punkte), 1) : null,
        'bestes'      => $punkte !== [] ? max($punkte) : null,
        'schwaechstes' => $punkte !== [] ? min($punkte) : null,
        'erstellt_am' => date('d.m.Y, H:i'),
    ]);

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dateiname = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$assignment['title']) ?: 'Zusammenfassung';
    $dompdf->stream($dateiname . '.pdf', ['Attachment' => true]);
    exit;
}

/**
 * @return list<array<string,mixed>>
 */
function open_reports(PDO $conn, int $assignmentId, int $userId): array {
    $stmt = $conn->prepare('
        SELECT r.*, s.student_name
        FROM submission_feedback_reports r
        JOIN homework_submissions s ON s.id = r.submission_id
        JOIN homework_assignments a ON a.id = s.assignment_id
        WHERE a.id = ? AND a.teacher_id = ? AND r.resolved_at IS NULL
        ORDER BY r.created_at DESC
    ');
    $stmt->execute([$assignmentId, $userId]);

    return $stmt->fetchAll();
}
