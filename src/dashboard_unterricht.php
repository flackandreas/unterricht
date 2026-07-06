<?php
/**
 * src/index.php
 * Main Dashboard
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/migrations.php';
run_all_migrations();

require_login();

$user_id = get_current_user_id();
$user_name = get_current_user_name();

$conn = db_connect();

if (is_current_user_admin()) {
    header("Location: /admin/klassen");
    exit;
}

try {
    $stmt_classes = $conn->prepare("SELECT id, name FROM classes ORDER BY name ASC");
    $stmt_classes->execute();
    $all_classes = $stmt_classes->fetchAll();

    // Fetch selected classes for this teacher
    $stmt_selected = $conn->prepare("SELECT class_id FROM teacher_classes WHERE teacher_id = ?");
    $stmt_selected->execute([$user_id]);
    $selected_class_ids = $stmt_selected->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $all_classes = [];
    $selected_class_ids = [];
}

require_once __DIR__ . '/includes/twig_setup.php';

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('dashboard.twig', [
    'current_user_name' => $user_name,
    'current_date' => date('d.m.Y'),
    'all_classes' => $all_classes,
    'selected_class_ids' => $selected_class_ids,
    'host_url' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]",
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true,
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error
]);
