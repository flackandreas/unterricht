<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Bereitet Bilder fuer den Versand an das Modell vor.
 *
 * Ein Handyfoto mit 12 Megapixeln ergibt base64-kodiert einen Request von
 * rund 16 MB. Das kostet Uebertragungszeit und Tokens, ohne die Erkennung zu
 * verbessern - jenseits von etwa 1600 Pixeln Kantenlaenge gewinnt das Modell
 * keine Information mehr hinzu. GD ist im Image ohnehin installiert.
 */
final class ImagePreparer
{
    /** Laengste Kante, auf die verkleinert wird. */
    public const MAX_EDGE = 1568;

    /** JPEG-Qualitaet der verkleinerten Fassung. */
    private const QUALITY = 85;

    /**
     * Liefert Bilddaten und MIME-Typ fuer den API-Aufruf.
     *
     * Faellt bei jedem Problem auf die Originaldatei zurueck: eine
     * fehlgeschlagene Optimierung darf die Auswertung nicht verhindern.
     *
     * @return array{mime:string,data:string}
     */
    public static function prepare(string $path): array
    {
        $original = [
            'mime' => (string)(@mime_content_type($path) ?: 'application/octet-stream'),
            'data' => base64_encode((string)file_get_contents($path)),
        ];

        // PDFs und alles, was nicht als Bild vorliegt, unveraendert senden.
        if (!str_starts_with($original['mime'], 'image/') || !function_exists('imagecreatefromjpeg')) {
            return $original;
        }

        $verkleinert = self::downscale($path, $original['mime']);

        return $verkleinert ?? $original;
    }

    /**
     * @return array{mime:string,data:string}|null null, wenn nichts zu tun ist
     */
    private static function downscale(string $path, string $mime): ?array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        [$breite, $hoehe] = $info;
        $groessteKante = max($breite, $hoehe);

        if ($groessteKante <= self::MAX_EDGE) {
            return null;
        }

        $quelle = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };

        if ($quelle === false) {
            return null;
        }

        $faktor = self::MAX_EDGE / $groessteKante;
        $neueBreite = max(1, (int)round($breite * $faktor));
        $neueHoehe = max(1, (int)round($hoehe * $faktor));

        $ziel = imagescale($quelle, $neueBreite, $neueHoehe, IMG_BICUBIC);
        imagedestroy($quelle);

        if ($ziel === false) {
            return null;
        }

        // Handschrift auf Papier komprimiert als JPEG deutlich besser als PNG.
        ob_start();
        $ok = imagejpeg($ziel, null, self::QUALITY);
        $daten = (string)ob_get_clean();
        imagedestroy($ziel);

        if (!$ok || $daten === '') {
            return null;
        }

        return ['mime' => 'image/jpeg', 'data' => base64_encode($daten)];
    }
}
