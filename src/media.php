<?php
/**
 * src/media.php
 * Liefert hochgeladene Dateien aus - mit Berechtigungspruefung.
 *
 * Ersetzt den direkten Zugriff auf public/uploads/. Schuelerinnen und Schueler
 * erreichen ausschliesslich ihr eigenes Foto ueber ihren Abgabe-Token,
 * Lehrkraefte nur Dateien der eigenen Hausaufgaben. Kontextdokumente
 * (Musterloesungen) werden ausschliesslich an die eigene Lehrkraft ausgeliefert.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/storage.php';

$conn = db_connect();

/**
 * Bricht mit einem schlanken Fehler ab - ohne Hinweis darauf, ob die
 * angefragte Datei ueberhaupt existiert.
 */
function media_deny(int $status = 404): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $status === 403 ? 'Kein Zugriff.' : 'Datei nicht gefunden.';
    exit;
}

function media_send(?string $storedPath, string $downloadName): void {
    $absolute = storage_resolve($storedPath);
    if ($absolute === null) {
        media_deny();
    }

    $mime = storage_mime_type($absolute);
    // Nur eingebettet anzeigen, was gefahrlos anzeigbar ist.
    $inline = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
    $extension = pathinfo($absolute, PATHINFO_EXTENSION);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($absolute));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
        . '; filename="' . $downloadName . '.' . $extension . '"');
    // Personenbezogene Inhalte gehoeren nicht in geteilte Caches.
    header('Cache-Control: private, max-age=300, no-transform');
    header('Referrer-Policy: no-referrer');

    readfile($absolute);
    exit;
}

// --- Variante 1: Schueleransicht ueber den Abgabe-Token ---------------------
$submissionToken = $_GET['s'] ?? '';
if ($submissionToken !== '') {
    if (!preg_match('/^[a-f0-9]{32}$/', $submissionToken)) {
        media_deny();
    }

    $stmt = $conn->prepare("SELECT image_path FROM homework_submissions WHERE token = ? LIMIT 1");
    $stmt->execute([$submissionToken]);
    $submission = $stmt->fetch();

    if (!$submission) {
        media_deny();
    }

    media_send($submission['image_path'], 'hausaufgabe');
}

// --- Variante 2 und 3 setzen eine angemeldete Lehrkraft voraus --------------
if (!is_logged_in()) {
    media_deny(403);
}
$user_id = get_current_user_id();

// Einreichung einer eigenen Hausaufgabe
$submissionId = (int)($_GET['sub'] ?? 0);
if ($submissionId > 0) {
    $stmt = $conn->prepare("
        SELECT s.image_path
        FROM homework_submissions s
        JOIN homework_assignments a ON s.assignment_id = a.id
        WHERE s.id = ? AND a.teacher_id = ?
        LIMIT 1
    ");
    $stmt->execute([$submissionId, $user_id]);
    $submission = $stmt->fetch();

    if (!$submission) {
        media_deny();
    }

    media_send($submission['image_path'], 'einreichung-' . $submissionId);
}

// Kontextdokument (Aufgabenblatt / Musterloesung) einer eigenen Hausaufgabe
$assignmentId = (int)($_GET['ctx'] ?? 0);
if ($assignmentId > 0) {
    $stmt = $conn->prepare("
        SELECT context_image_path
        FROM homework_assignments
        WHERE id = ? AND teacher_id = ?
        LIMIT 1
    ");
    $stmt->execute([$assignmentId, $user_id]);
    $assignment = $stmt->fetch();

    if (!$assignment) {
        media_deny();
    }

    media_send($assignment['context_image_path'], 'kontext-' . $assignmentId);
}

media_deny();
