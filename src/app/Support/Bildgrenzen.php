<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Obergrenze fuer die Masse eines Bildes, bevor GD es in den Speicher laedt.
 *
 * Bisher entschied allein die Dateigroesse: upload_max_filesize = 40M. Fuer
 * GD ist die aber kein Massstab. Ein PNG komprimiert gleichfoermige Flaechen
 * auf fast nichts, beim Dekodieren haelt GD dagegen jedes Pixel als
 * Truecolor-Wert - Breite mal Hoehe mal 4 Byte. Nachgemessen in diesem
 * Container, PHP 8.4:
 *
 *     4.000 x 4.000 Pixel  =  45,7 KB auf der Platte
 *     imagecreatefrompng() =  61,4 MB mehr im Arbeitsspeicher
 *
 * also genau Breite x Hoehe x 4. Hochgerechnet belegt ein Bild mit
 * 30.000 x 30.000 Pixeln - immer noch eine Datei von wenigen hundert
 * Kilobyte - rund 3,6 GB.
 *
 * Das memory_limit faengt das NICHT ab: GD legt seine Puffer seit PHP 8
 * ausserhalb der Speicherverwaltung von PHP an. In derselben Messung blieb
 * memory_get_usage() bei 0,4 MB, waehrend der Prozess um 61 MB wuchs. Statt
 * eines sauberen Abbruchs waechst der Prozess also, bis der Kernel ihn
 * abraeumt - im Apache-Prozess mitten in der Antwort, im Worker mitten im
 * Auftrag. Der Auftrag wird danach erneut versucht und trifft dieselbe Datei.
 *
 * Erreichbar war das ohne Anmeldung: eine Schuelerin braucht nur den Token
 * einer Hausaufgabe.
 *
 * 20 Megapixel sind 80 MB je Puffer; beim Drehen und beim Verkleinern liegen
 * kurzzeitig zwei gleichzeitig im Speicher, macht 160 MB fuer einen Upload.
 * Ein Foto aus dem Telefon hat 12 Megapixel, eine Spiegelreflexkamera 20 -
 * die Grenze schneidet nichts ab, was hier je abgegeben wird.
 */
final class Bildgrenzen
{
    /** Gesamtzahl der Pixel (Breite x Hoehe). */
    public const MAX_PIXEL = 20000000;

    /** Laengste Kante - faengt extreme Streifen ab, die unter MAX_PIXEL bleiben. */
    public const MAX_KANTE = 10000;

    /**
     * Masse eines Bildes, oder null wenn die Datei kein lesbares Bild ist.
     *
     * getimagesize() liest nur den Kopf der Datei, nicht ihren Inhalt - die
     * Pruefung kostet also nichts von dem Speicher, vor dem sie schuetzt.
     *
     * @return array{0:int,1:int}|null
     */
    public static function masse(string $pfad): ?array
    {
        $info = @getimagesize($pfad);

        if ($info === false) {
            return null;
        }

        return [(int)$info[0], (int)$info[1]];
    }

    /**
     * Ist das Bild zu gross, um es gefahrlos zu dekodieren?
     *
     * Eine Datei, aus der sich keine Masse lesen lassen, gilt nicht als zu
     * gross: sie ist entweder kein Bild - dann fasst GD sie ohnehin nicht an -
     * oder ein Format, das getimagesize() nicht kennt.
     */
    public static function zuGross(string $pfad): bool
    {
        $masse = self::masse($pfad);

        if ($masse === null) {
            return false;
        }

        [$breite, $hoehe] = $masse;

        return $breite * $hoehe > self::MAX_PIXEL
            || $breite > self::MAX_KANTE
            || $hoehe > self::MAX_KANTE;
    }

    /**
     * Was der abgewiesenen Person gesagt wird.
     */
    public static function meldung(): string
    {
        return sprintf(
            'Das Bild ist zu gross: erlaubt sind %d Megapixel und %d Pixel je Kante. '
            . 'Ein Foto aus dem Telefon liegt weit darunter.',
            (int)(self::MAX_PIXEL / 1000000),
            self::MAX_KANTE
        );
    }
}
