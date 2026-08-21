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
use App\Support\Database;

$einmal = in_array('--once', $argv, true);
$maximum = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $maximum = (int)substr($arg, 6);
    }
}

$conn = Database::connection();
$queue = new EvaluationQueue($conn);
$repository = new HomeworkRepository($conn);
$service = new SubmissionService($conn, $repository, $queue, new Gamification($conn));

$laufend = true;
$verarbeitet = 0;

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
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Warteschlange nicht lesbar: ' . $e->getMessage() . "\n");
        if ($einmal) {
            exit(1);
        }
        sleep(10);
        continue;
    }

    if ($job === null) {
        if ($einmal) {
            break;
        }
        sleep(3);
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
