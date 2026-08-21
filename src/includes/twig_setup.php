<?php
/**
 * src/includes/twig_setup.php
 * Bootstrapper for the Twig template engine.
 */

require_once __DIR__ . '/../bootstrap.php';

// Prepare Twig Environment
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');

// Der Cache war dauerhaft abgeschaltet, sodass jedes Template bei jedem
// Request neu uebersetzt wurde. Mit auto_reload wird eine geaenderte Vorlage
// weiterhin sofort neu gebaut - in der Entwicklung aendert sich also nichts.
$cacheVerzeichnis = __DIR__ . '/../storage/cache/twig';
if (!is_dir($cacheVerzeichnis)) {
    @mkdir($cacheVerzeichnis, 0750, true);
}

$twig = new \Twig\Environment($loader, [
    'cache'            => is_writable($cacheVerzeichnis) ? $cacheVerzeichnis : false,
    // Bewusst immer aktiv: die Anwendung wird per Bind-Mount aktualisiert,
    // ohne Cache-Leerung. Ein stat() je Template ist billiger als eine
    // veraltete Vorlage nach dem Deployment.
    'auto_reload'      => true,
    'strict_variables' => false,
]);

// Helper extension: Expose a function to fetch active navigation states
$twig->addFunction(new \Twig\TwigFunction('is_current_page', function ($page) {
    return basename($_SERVER['PHP_SELF']) === $page;
}));

// Add json_decode filter
$twig->addFilter(new \Twig\TwigFilter('json_decode', function ($string) {
    return json_decode($string, true) ?: [];
}));

/**
 * Erzeugt einen QR-Code als Data-URI.
 *
 * Vorher wurden die Links inklusive ihrer geheimen Tokens an
 * api.qrserver.com geschickt. Die Erzeugung laeuft jetzt lokal, es verlaesst
 * kein Token mehr den Server.
 */
/**
 * Anzahl der Auswertungen, die auf Freigabe warten.
 *
 * Als Funktion statt als Global: so ist es gleichgueltig, in welcher
 * Reihenfolge ein Controller Datenbank und Templating einbindet. Der frueher
 * hier stehende Block setzte eine bereits geoeffnete Verbindung voraus und
 * band eine Datei ein, die es gar nicht gab.
 */
$twig->addFunction(new \Twig\TwigFunction('pending_reviews', function (): int {
    static $anzahl = null;
    if ($anzahl !== null) {
        return $anzahl;
    }

    $anzahl = 0;
    if (empty($_SESSION['user_id'])) {
        return $anzahl;
    }

    try {
        $repository = new \App\Homework\HomeworkRepository(\App\Support\Database::connection());
        $anzahl = $repository->pendingReviewCount((int)$_SESSION['user_id']);
    } catch (\Throwable $e) {
        error_log('Anzahl offener Freigaben nicht ermittelbar: ' . $e->getMessage());
    }

    return $anzahl;
}));

$twig->addFunction(new \Twig\TwigFunction('qr_data_uri', function (string $text, int $size = 200) {
    try {
        $qrCode = \Endroid\QrCode\QrCode::create($text)
            ->setSize(max(64, min(1000, $size)))
            ->setMargin(8);

        return (new \Endroid\QrCode\Writer\SvgWriter())->write($qrCode)->getDataUri();
    } catch (\Throwable $e) {
        error_log('QR-Code konnte nicht erzeugt werden: ' . $e->getMessage());
        return '';
    }
}));
