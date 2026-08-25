<?php
/**
 * src/tests/pruefstand_live.php
 * Erzeugt eine bedienbare Fassung des Erfassungsschirms ohne Anmeldung.
 *
 * Der Schirm laesst sich sonst nur angemeldet und mit einer echten
 * Klassenliste pruefen - also gerade dann nicht, wenn man eine Aenderung an
 * live.js schnell gegenpruefen will. Hier kommen echtes Template, echtes
 * live.js und echtes Stylesheet zusammen, nur mit erfundenen Namen und einer
 * Attrappe des Sync-Endpunkts. Ueber window.PRUEFSTAND laesst sich das Netz
 * abschalten.
 *
 *   docker compose exec web php tests/pruefstand_live.php > src/public/_pruefstand.html
 *   # http://localhost:8889/_pruefstand.html oeffnen, danach die Datei loeschen
 *
 * Damit gefunden: ein langer Druck liess den naechsten ganz normalen Tipp
 * still verschwinden, weil ein Schalter haengenblieb.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use App\Live\ParticipationRepository;
use App\Live\Roster;

$namen = Roster::parseNames(
    "Ahrens, Lennart\nBauer, Emilia\nBerger, Lena\nCetin, Deniz\nDemir, Mert\n"
    . "Ferreira, Tomás\nGrabowski, Nele\nHartmann, Jonas\nIlic, Marija\nJansen, Fiete\n"
    . "Kowalski, Alina\nLehmann, Ben\nMbeki, Ayo\nNeumann, Charlotte\nOkonkwo, Chidi\n"
    . "Petrova, Sofia\nQuandt, Theo\nRichter, Mika\nSchäfer, Greta\nTran, Bao\n"
    . "Ulbrich, Paul\nVogel, Ida\nWeber, Anton\nYilmaz, Aylin\nZimmermann, Frieda"
);

$liste = [];
foreach ($namen as $i => $name) {
    $liste[] = ['id' => $i + 1, 'display_name' => $name];
}

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
$twig = new \Twig\Environment($loader, ['cache' => false]);

$twig->addFunction(new \Twig\TwigFunction('asset', static function (string $pfad): string {
    $datei = __DIR__ . '/../public/' . ltrim($pfad, '/');
    return $pfad . '?v=' . (is_readable($datei) ? filemtime($datei) : time());
}));
$twig->addFunction(new \Twig\TwigFunction('is_current_page', static fn(string $p): bool => $p === 'live.php'));
$twig->addFunction(new \Twig\TwigFunction('qr_data_uri', static fn(string $t, int $s = 200): string => ''));
$twig->addFunction(new \Twig\TwigFunction('pending_reviews', static fn(): int => 0));
$twig->addFilter(new \Twig\TwigFilter('json_decode', static fn(string $s): array => []));

$html = $twig->render('live.twig', [
    'csrf_token'        => 'pruefstand',
    'flash_success'     => null,
    'flash_error'       => null,
    'meine_klassen'     => [['id' => 1, 'name' => '7b']],
    'klasse'            => ['id' => 1, 'name' => '7b'],
    'class_id'          => 1,
    'fach'              => 'Mathematik',
    'faecher'           => ['Mathematik'],
    'period'            => 3,
    'datum'             => date('Y-m-d'),
    'liste'             => $liste,
    'session'           => ['id' => 1, 'topic' => ''],
    'stand'             => [],
    'letztes_thema'     => 'Addition ungleichnamiger Brüche',
    'vermutung'         => ['class_id' => 1, 'fach' => 'Mathematik', 'period' => 3, 'sicher' => true],
    'gewichte'          => ParticipationRepository::GEWICHTE,
    'current_user_name' => 'Prüfstand',
    'is_admin'          => false,
    'is_logged_in'      => true,
]);

// Attrappe des Servers: dieselbe Rechnung wie live_action.php, nur im
// Browser - und mit einem Schalter fuer "kein Netz".
$attrappe = <<<'JS'
<script>
window.PRUEFSTAND = { offline: false, aufrufe: 0, gespeichert: {} };
window.fetch = function (adresse, optionen) {
    var s = window.PRUEFSTAND;
    s.aufrufe++;
    var koerper = JSON.parse(optionen.body);

    if (s.offline) {
        return Promise.reject(new TypeError('Failed to fetch'));
    }

    (koerper.removals || []).forEach(function (uid) { delete s.gespeichert[uid]; });
    (koerper.events || []).forEach(function (e) { s.gespeichert[e.uid] = e; });

    var stand = {};
    Object.keys(s.gespeichert).forEach(function (uid) {
        var e = s.gespeichert[uid];
        if (!stand[e.student_id]) { stand[e.student_id] = { anzahl: 0, summe: 0 }; }
        stand[e.student_id].anzahl += 1;
        stand[e.student_id].summe += e.weight;
    });

    return Promise.resolve({
        status: 200,
        json: function () {
            return Promise.resolve({
                ok: true, session_id: 1, stand: stand,
                gespeichert: (koerper.events || []).length,
                doppelt: 0, verworfen: 0,
                entfernt: (koerper.removals || []).length
            });
        }
    });
};
</script>
JS;

echo str_replace('</body>', $attrappe . "\n</body>", $html);
