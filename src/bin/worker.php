<?php
/**
 * src/bin/worker.php
 * Arbeitet die Warteschlange der KI-Auswertungen ab.
 *
 *   php bin/worker.php              # laeuft dauerhaft
 *   php bin/worker.php --once       # nur ein Durchlauf (fuer cron)
 *   php bin/worker.php --max=20     # nach 20 Auftraegen beenden
 *
 * Mehrere Prozesse koennen parallel laufen; die Auftragsvergabe ist gesperrt.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';

use App\Ai\AIServiceException;
use App\Homework\EvaluationQueue;
use App\Homework\Gamification;
use App\Homework\HomeworkRepository;
use App\Homework\SubmissionService;
use App\Live\LessonRepository;
use App\Substitute\PlanQueue;
use App\Substitute\PlanService;
use App\Support\AuditLog;
use App\Support\Database;

$einmal = in_array('--once', $argv, true);
$maximum = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $maximum = (int)substr($arg, 6);
    }
}

$conn = null;
$queue = null;
$repository = null;
$service = null;
// Zweite Warteschlange: Vertretungsstunden. Auswertungen haben Vorrang -
// eine Schuelerin wartet auf ihr Feedback, eine Vertretungsmappe wird erst
// am naechsten Morgen gebraucht.
$plaene = null;
$planService = null;

/**
 * Baut Verbindung und alle daran haengenden Objekte neu auf.
 *
 * Als Funktion, weil jedes dieser Objekte seine PDO-Instanz im Konstruktor
 * bekommt: eine neue Verbindung allein genuegt nicht, die Repositories halten
 * sonst weiter die alte.
 */
$aufbauen = static function () use (&$conn, &$queue, &$repository, &$service, &$plaene, &$planService): void {
    $conn = Database::connection();
    $queue = new EvaluationQueue($conn);
    $repository = new HomeworkRepository($conn);
    $service = new SubmissionService($conn, $repository, $queue, new Gamification($conn));
    $plaene = new PlanQueue($conn);
    $planService = new PlanService($conn, new LessonRepository($conn), $plaene, new AuditLog($conn), $repository);
};

$aufbauen();

$laufend = true;
$verarbeitet = 0;
$fehlversuche = 0;

// Nach so vielen erfolglosen Neuverbindungen beendet sich der Prozess und
// laesst den Container neu starten.
$maxFehlversuche = 10;

// Sauber beenden, wenn der Container gestoppt wird.
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stopp = static function (int $signal) use (&$laufend): void {
        echo "\nSignal $signal empfangen - beende nach dem laufenden Auftrag.\n";
        $laufend = false;
    };
    pcntl_signal(SIGTERM, $stopp);
    pcntl_signal(SIGINT, $stopp);
}

echo "Worker gestartet" . ($einmal ? ' (ein Durchlauf)' : '') . ".\n";

while ($laufend) {
    try {
        $job = $queue->reserve();
        $planAuftrag = $job === null ? $plaene->reserve() : null;
        $fehlversuche = 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Warteschlange nicht lesbar: ' . $e->getMessage() . "\n");
        if ($einmal) {
            exit(1);
        }

        // Frueher wurde hier zehn Sekunden gewartet und danach dieselbe
        // Verbindung erneut benutzt. Ist die Datenbank zwischendurch neu
        // gestartet, ist sie aber tot und bleibt es - der Worker drehte sich
        // dann bis zum naechsten Neustart im Kreis, waehrend die
        // Auswertungen in der Warteschlange liegen blieben.
        $fehlversuche++;

        if ($fehlversuche >= $maxFehlversuche) {
            fwrite(STDERR, sprintf(
                "Nach %d Versuchen keine Verbindung - beende. Der Container startet neu.\n",
                $fehlversuche
            ));
            exit(1);
        }

        // Wartezeit waechst mit den Versuchen, damit ein laengerer Ausfall
        // nicht im Sekundentakt protokolliert wird.
        sleep(min(60, 5 * $fehlversuche));

        try {
            Database::reconnect();
            $aufbauen();
            fwrite(STDERR, "Verbindung wiederhergestellt.\n");
        } catch (\Throwable $neu) {
            fwrite(STDERR, 'Neuverbindung fehlgeschlagen: ' . $neu->getMessage() . "\n");
        }

        continue;
    }

    if ($job === null && $planAuftrag === null) {
        if ($einmal) {
            break;
        }
        sleep(3);
        continue;
    }

    if ($job === null) {
        $planId = (int)$planAuftrag['id'];
        $start = microtime(true);

        try {
            $planService->generate($planAuftrag);
            printf("  Vertretungsstunde %d entworfen (%.1fs)\n", $planId, microtime(true) - $start);
        } catch (\Throwable $e) {
            $plaene->markFailed($planId, $e->getMessage(), (int)$planAuftrag['attempts']);
            fwrite(STDERR, sprintf(
                "  Vertretungsstunde %d fehlgeschlagen (Versuch %d): %s\n",
                $planId,
                $planAuftrag['attempts'],
                $e->getMessage()
            ));
        }

        $verarbeitet++;
        if ($maximum > 0 && $verarbeitet >= $maximum) {
            break;
        }

        continue;
    }

    $jobId = (int)$job['id'];
    $submissionId = (int)$job['submission_id'];
    $start = microtime(true);

    try {
        $service->evaluate($submissionId);
        $queue->markDone($jobId);
        printf("  Einreichung %d ausgewertet (%.1fs)\n", $submissionId, microtime(true) - $start);
    } catch (AIServiceException $e) {
        $queue->markFailed($jobId, $submissionId, $e->getMessage(), (int)$job['attempts']);
        fwrite(STDERR, sprintf("  Einreichung %d fehlgeschlagen (Versuch %d): %s\n", $submissionId, $job['attempts'], $e->getMessage()));
    } catch (\Throwable $e) {
        $queue->markFailed($jobId, $submissionId, $e->getMessage(), (int)$job['attempts']);
        fwrite(STDERR, sprintf("  Einreichung %d abgebrochen: %s\n", $submissionId, $e->getMessage()));
    }

    $verarbeitet++;
    if ($maximum > 0 && $verarbeitet >= $maximum) {
        break;
    }
}

echo "Worker beendet. $verarbeitet Auftrag/Aufträge verarbeitet.\n";
