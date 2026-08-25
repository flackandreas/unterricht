<?php
/**
 * src/vertretung_view.php
 * Die Vertretungsmappe, wie sie die einspringende Kraft sieht.
 *
 * Zugang ueber einen Token statt ueber eine Anmeldung: Vertretung wird um
 * 7:40 Uhr im Lehrerzimmer verteilt, oft an jemanden ohne Zugang zu diesem
 * Modul. Der Plan enthaelt keine Schuelerdaten - Klasse, Fach, Thema,
 * Aufgaben - deshalb ist der Token hier vertretbar. Ohne Freigabe existiert
 * er nicht.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

use App\Homework\HomeworkRepository;
use App\Live\LessonRepository;
use App\Substitute\PlanQueue;
use App\Substitute\PlanService;
use App\Support\AuditLog;

$conn = db_connect();
$service = new PlanService($conn, new LessonRepository($conn), new PlanQueue($conn), new AuditLog($conn), new HomeworkRepository($conn));

$plan = $service->findByToken((string)($_GET['token'] ?? ''));

if ($plan === null) {
    http_response_code(404);
    echo $twig->render('vertretung_view.twig', [
        'plan'         => null,
        'is_logged_in' => false,
    ]);
    exit;
}

$daten = json_decode((string)$plan['plan_json'], true);
$daten = is_array($daten) ? $daten : [];

if (isset($_GET['pdf'])) {
    $html = $twig->render('pdf_substitute_plan.twig', [
        'plan'        => $plan,
        'daten'       => $daten,
        'erstellt_am' => date('d.m.Y, H:i'),
    ]);

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dateiname = preg_replace('/[^A-Za-z0-9_-]+/', '_', $plan['klasse'] . '_' . $plan['fach']) ?: 'Vertretung';
    $dompdf->stream('Vertretung_' . $dateiname . '.pdf', ['Attachment' => true]);
    exit;
}

echo $twig->render('vertretung_view.twig', [
    'plan'         => $plan,
    'daten'        => $daten,
    'token'        => (string)$_GET['token'],
    'is_logged_in' => false,
]);
