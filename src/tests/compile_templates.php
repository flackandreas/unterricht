<?php
/**
 * src/tests/compile_templates.php
 * Übersetzt alle Twig-Templates, ohne sie auszuführen.
 *
 * Fängt Syntaxfehler in Templates ab, die sonst erst im Browser auffallen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
$twig = new \Twig\Environment($loader, ['cache' => false]);

// Die zur Laufzeit registrierten Erweiterungen als Attrappen.
$twig->addFunction(new \Twig\TwigFunction('asset', static fn(string $p): string => $p));
$twig->addFunction(new \Twig\TwigFunction('is_current_page', static fn(string $p): bool => false));
$twig->addFunction(new \Twig\TwigFunction('qr_data_uri', static fn(string $t, int $s = 200): string => ''));
$twig->addFunction(new \Twig\TwigFunction('pending_reviews', static fn(): int => 0));
$twig->addFilter(new \Twig\TwigFilter('json_decode', static fn(string $s): array => []));

$fehler = 0;
$geprueft = 0;

$dateien = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator(__DIR__ . '/../templates', \FilesystemIterator::SKIP_DOTS)
);

foreach ($dateien as $datei) {
    if ($datei->getExtension() !== 'twig') {
        continue;
    }

    $name = ltrim(str_replace(realpath(__DIR__ . '/../templates') ?: '', '', (string)$datei->getRealPath()), '/');
    $geprueft++;

    try {
        $twig->parse($twig->tokenize(new \Twig\Source((string)file_get_contents((string)$datei), $name)));
    } catch (\Throwable $e) {
        fwrite(STDERR, "  FEHLER $name: {$e->getMessage()}\n");
        $fehler++;
    }
}

echo $fehler === 0
    ? "$geprueft Templates übersetzt, keine Fehler.\n"
    : "$geprueft Templates geprüft, $fehler mit Fehlern.\n";

exit($fehler === 0 ? 0 : 1);
