<?php
/**
 * src/student_homework.php
 * Endpoint for students to submit homework and get AI evaluation.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/twig_setup.php';
require_once __DIR__ . '/includes/AIService.php';

$conn = db_connect();

// Handle secure submission viewing
$view_token = $_GET['view'] ?? '';
if (!empty($view_token)) {
    $stmt = $conn->prepare("
        SELECT s.*, a.title as assignment_title, a.klasse, a.fach, a.description, a.expected_submissions,
               e.student_feedback, e.score, e.error_markers
        FROM homework_submissions s
        JOIN homework_assignments a ON s.assignment_id = a.id
        LEFT JOIN homework_evaluations e ON s.id = e.submission_id
        WHERE s.token = ?
    ");
    $stmt->execute([$view_token]);
    $submission = $stmt->fetch();
    
    if (!$submission) {
        die("Einreichung nicht gefunden oder ungültiger Link.");
    }
    
    // Fetch actual submissions count for the class quest in view mode
    $stmt_count = $conn->prepare("SELECT COUNT(DISTINCT student_pseudonym) FROM homework_submissions WHERE assignment_id = ?");
    $stmt_count->execute([$submission['assignment_id']]);
    $actual_submissions = (int)$stmt_count->fetchColumn();
    
    require_once __DIR__ . '/includes/twig_setup.php';
    echo $twig->render('student_homework_view.twig', [
        'sub' => $submission,
        'actual_submissions' => $actual_submissions,
        'expected_submissions' => (int)$submission['expected_submissions'],
        'host_url' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]"
    ]);
    exit;
}

$token = $_GET['t'] ?? '';

if (empty($token)) {
    die("Ungültiger Link.");
}

$stmt = $conn->prepare("SELECT * FROM homework_assignments WHERE token = ?");
$stmt->execute([$token]);
$assignment = $stmt->fetch();

if (!$assignment) {
    die("Hausaufgabe nicht gefunden oder Link abgelaufen.");
}

$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if the POST request exceeded post_max_size (which clears $_POST and $_FILES)
    if (empty($_POST) && empty($_FILES) && (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0)) {
        $error = "Die hochgeladenen Daten überschreiten das Limit. Bitte lade ein kleineres Bild hoch.";
    } else {
        $student_name = trim($_POST['student_name'] ?? '');
        
        if (empty($student_name)) {
            $error = "Bitte gib deinen Namen ein.";
        } elseif (!isset($_FILES['homework_image'])) {
            $error = "Bitte lade ein Bild deiner Hausaufgabe hoch.";
        } elseif ($_FILES['homework_image']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['homework_image']['error'];
            switch ($errorCode) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error = "Das Bild ist zu groß. Bitte verkleinere das Bild oder lade ein kleineres Foto hoch (max. 40 MB).";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error = "Das Bild wurde nur teilweise hochgeladen. Bitte versuche es erneut.";
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error = "Bitte lade ein Bild deiner Hausaufgabe hoch.";
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $error = "Fehler: Temporäres Upload-Verzeichnis fehlt auf dem Server.";
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $error = "Fehler: Bild konnte nicht auf dem Server gespeichert werden.";
                    break;
                default:
                    $error = "Fehler beim Hochladen (Code: $errorCode).";
                    break;
            }
        } else {
            $file = $_FILES['homework_image'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            
            if (!in_array($mimeType, $allowedTypes)) {
                $error = "Nur JPG, PNG oder WEBP Bilder sind erlaubt. Erkannt: " . htmlspecialchars($mimeType);
            } else {
                // Pseudonym generieren
                $pseudonym = 'Student_' . bin2hex(random_bytes(4));
                
                // Upload Verzeichnis sichern
                $uploadDir = __DIR__ . '/public/uploads/homework/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = uniqid('hw_') . '.' . $extension;
                $destination = $uploadDir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    // Auto-Rotate Image based on EXIF orientation if needed
                    autoRotateImage($destination);
                    
                    $relativePath = 'uploads/homework/' . $filename;
                    
                    // Submission speichern
                    $sub_token = bin2hex(random_bytes(16));
                    $stmt_sub = $conn->prepare("INSERT INTO homework_submissions (assignment_id, student_name, student_pseudonym, image_path, token) VALUES (?, ?, ?, ?, ?)");
                    $stmt_sub->execute([$assignment['id'], $student_name, $pseudonym, $relativePath, $sub_token]);
                    $submission_id = $conn->lastInsertId();
                    
                    // KI Auswertung
                    try {
                        $aiService = new \App\Includes\AIService();
                        $contextPath = !empty($assignment['context_image_path']) ? __DIR__ . '/public/' . $assignment['context_image_path'] : null;
                        $eval = $aiService->evaluateHomeworkImage($assignment['description'], $destination, $pseudonym, $contextPath);
                        
                        // Normalisiere Feedback und Lehrernotizen (da die KI diese manchmal als Array zurückgibt)
                        $studentFeedback = $eval['student_feedback'] ?? 'Kein Feedback generiert.';
                        if (is_array($studentFeedback)) {
                            $studentFeedback = implode("\n", array_map(function($item) {
                                return is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : (string)$item;
                            }, $studentFeedback));
                        }
                        
                        $teacherNotes = $eval['teacher_notes'] ?? 'Keine Notizen.';
                        if (is_array($teacherNotes)) {
                            $teacherNotes = implode("\n", array_map(function($item) {
                                return is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : (string)$item;
                            }, $teacherNotes));
                        }

                        // Evaluation speichern
                        $error_markers_json = isset($eval['errors']) ? json_encode($eval['errors']) : null;
                        $stmt_eval = $conn->prepare("INSERT INTO homework_evaluations (submission_id, student_feedback, teacher_notes, score, error_markers) VALUES (?, ?, ?, ?, ?)");
                        $stmt_eval->execute([
                            $submission_id, 
                            $studentFeedback, 
                            $teacherNotes, 
                            $eval['score'] ?? null,
                            $error_markers_json
                        ]);
                        
                        // Status updaten
                        $conn->prepare("UPDATE homework_submissions SET status = 'evaluated' WHERE id = ?")->execute([$submission_id]);
                        
                        $result = [
                            'student_feedback' => $studentFeedback,
                            'score' => $eval['score'] ?? null,
                            'errors' => $eval['errors'] ?? [],
                            'image_path' => $relativePath,
                            'token' => $sub_token,
                            'student_name' => $student_name
                        ];
                    } catch (\Exception $e) {
                        $error = "Fehler bei der KI-Auswertung: " . $e->getMessage();
                    }
                } else {
                    $error = "Fehler beim Hochladen der Datei.";
                }
            }
        }
    }
}

$actual_submissions = 0;
if ($assignment) {
    $stmt_count = $conn->prepare("SELECT COUNT(DISTINCT student_pseudonym) FROM homework_submissions WHERE assignment_id = ?");
    $stmt_count->execute([$assignment['id']]);
    $actual_submissions = (int)$stmt_count->fetchColumn();
}

echo $twig->render('student_homework.twig', [
    'assignment' => $assignment,
    'error' => $error,
    'result' => $result,
    'actual_submissions' => $actual_submissions,
    'expected_submissions' => (int)($assignment['expected_submissions'] ?? 0)
]);

function autoRotateImage($imagePath) {
    if (!function_exists('exif_read_data')) {
        return;
    }
    $exif = @exif_read_data($imagePath);
    if (empty($exif['Orientation'])) {
        return;
    }
    
    $ort = $exif['Orientation'];
    if (!in_array($ort, [3, 6, 8])) {
        return;
    }
    
    $image = null;
    $mime = mime_content_type($imagePath);
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        $image = imagecreatefromjpeg($imagePath);
    } elseif ($mime === 'image/png') {
        $image = imagecreatefrompng($imagePath);
    } elseif ($mime === 'image/webp') {
        $image = imagecreatefromwebp($imagePath);
    }
    
    if (!$image) {
        return;
    }
    
    switch ($ort) {
        case 3: // 180 degrees
            $rotated = imagerotate($image, 180, 0);
            break;
        case 6: // 90 degrees clockwise
            $rotated = imagerotate($image, -90, 0);
            break;
        case 8: // 90 degrees counter-clockwise
            $rotated = imagerotate($image, 90, 0);
            break;
        default:
            $rotated = $image;
    }
    
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        imagejpeg($rotated, $imagePath, 90);
    } elseif ($mime === 'image/png') {
        imagepng($rotated, $imagePath);
    } elseif ($mime === 'image/webp') {
        imagewebp($rotated, $imagePath, 90);
    }
    
    imagedestroy($image);
    if ($rotated !== $image) {
        imagedestroy($rotated);
    }
}
