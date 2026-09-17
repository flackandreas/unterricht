<?php
/**
 * src/admin_system.php
 * System management: CSV Import, Archiving, and Cleanup.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

use App\Homework\EvaluationQueue;
use App\Support\UsageRecorder;

require_admin();

$conn = db_connect();

// Handle CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['teacher_csv'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: CSRF Token ungültig.";
        header("Location: /admin_system.php");
        exit;
    }
    
    $file = $_FILES['teacher_csv'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle !== false) {
                // Determine delimiter by reading first line
                $first_line = fgets($handle);
                $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
                rewind($handle);
                
                // Skip header row
                fgetcsv($handle, 1000, $delimiter);
                
                $success_count = 0;
                $skip_count = 0;
                $zugaenge = [];

                // Je Konto ein eigenes Zufallspasswort, das beim ersten
                // Anmelden gewechselt werden muss. Vorher bekam jede
                // importierte Lehrkraft dasselbe Passwort ("lehrer"), das im
                // Quelltext und in der Oberflaeche stand.
                $stmt = $conn->prepare("
                    INSERT IGNORE INTO teachers (kuerzel, name, email, passwort_hash, force_password_change)
                    VALUES (?, ?, ?, ?, 1)
                ");

                while (($data = fgetcsv($handle, 1000, $delimiter)) !== false) {
                    if (count($data) >= 2) {
                        $kuerzel = trim($data[0]);
                        $name = trim($data[1]);
                        $email = isset($data[2]) ? trim($data[2]) : null;
                        
                        if (!empty($kuerzel) && !empty($name)) {
                            if ($email === '') $email = null;

                            $passwort = erstes_passwort();
                            $stmt->execute([$kuerzel, $name, $email, password_hash($passwort, PASSWORD_DEFAULT)]);

                            if ($stmt->rowCount() > 0) {
                                $success_count++;
                                $zugaenge[] = ['kuerzel' => $kuerzel, 'name' => $name, 'passwort' => $passwort];
                            } else {
                                $skip_count++;
                            }
                        }
                    }
                }
                fclose($handle);

                // Genau einmal anzeigen: ohne diese Liste kommt niemand in
                // sein neues Konto. Sie steht in der Sitzung, nicht in der
                // Datenbank - das Passwort selbst wird nirgends gespeichert.
                $_SESSION['neue_zugaenge'] = $zugaenge;
                $_SESSION['flash_success'] = "Import abgeschlossen: $success_count hinzugefügt, $skip_count übersprungen.";
            } else {
                $_SESSION['flash_error'] = "Fehler beim Lesen der Datei.";
            }
        } else {
            $_SESSION['flash_error'] = "Nur .csv Dateien erlaubt.";
        }
    } else {
        $_SESSION['flash_error'] = "Upload-Fehler.";
    }
    header("Location: /admin_system.php");
    exit;
}

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
$neue_zugaenge = $_SESSION['neue_zugaenge'] ?? [];
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['neue_zugaenge']);

echo $twig->render('admin_system.twig', [
    'neue_zugaenge' => $neue_zugaenge,
    'ai_usage' => UsageRecorder::summary($conn, 30),
    'queue_counts' => (new EvaluationQueue($conn))->counts(),
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
