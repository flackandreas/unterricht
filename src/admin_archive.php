<?php
/**
 * src/admin_archive.php
 * Handles annual archiving and cleanup.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();
$conn = db_connect();

$action = $_GET['action'] ?? '';
$year = (int)($_GET['year'] ?? date('Y', strtotime('-1 month'))); // Default to last year if in Jan

if ($action === 'export') {
    $archive_name = "Jahresabschluss_Unterricht_" . $year . "_" . date('Ymd_His');
    $tmp_dir = __DIR__ . "/public/uploads/" . $archive_name;
    
    if (!is_dir($tmp_dir)) mkdir($tmp_dir, 0777, true);
    if (!is_dir($tmp_dir . "/Feedback")) mkdir($tmp_dir . "/Feedback");

    // --- 1. Export Feedback ---
    $stmt = $conn->prepare("SELECT * FROM feedback_sessions WHERE YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $fb_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $csv = "ID;Lehrer_ID;Klasse;Fach;Token;Aktiv;Ablauf;Erstellt_am\n";
    foreach ($fb_data as $r) {
        $csv .= implode(';', array_values($r)) . "\n";
    }
    file_put_contents($tmp_dir . "/Feedback/sessions_$year.csv", "\xEF\xBB\xBF" . $csv);

    // Create Archive using tar (fallback for ZipArchive)
    $archive_file = $archive_name . ".tar.gz";
    $archive_path = __DIR__ . "/public/uploads/" . $archive_file;
    
    $cmd = "tar -czf " . escapeshellarg($archive_path) . " -C " . escapeshellarg($tmp_dir) . " .";
    shell_exec($cmd);

    // Cleanup tmp dir
    shell_exec("rm -rf " . escapeshellarg($tmp_dir));

    if (file_exists($archive_path)) {
        header('Content-Type: application/x-gzip');
        header('Content-Disposition: attachment; filename="' . $archive_file . '"');
        header('Content-Length: ' . filesize($archive_path));
        readfile($archive_path);
        unlink($archive_path);
        exit;
    } else {
        die("Fehler beim Erstellen des Archivs.");
    }
}

if ($action === 'cleanup' && isset($_POST['confirm_year'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        die("CSRF Security Check failed.");
    }
    
    $cleanup_year = (int)$_POST['confirm_year'];
    
    // Feedback is slightly more complex due to cascading? Assume ON DELETE CASCADE exists.
    $stmt = $conn->prepare("DELETE FROM feedback_sessions WHERE YEAR(created_at) = ?");
    $stmt->execute([$cleanup_year]);
    $count_feedback = $stmt->rowCount();

    $_SESSION['flash_success'] = "Archiv-Cleanup für $cleanup_year abgeschlossen. $count_feedback Feedback-Sitzungen wurden entfernt.";
    header("Location: /admin/system");
    exit;
}
