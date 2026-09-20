<?php

declare(strict_types=1);

namespace App\Feedback;

/**
 * Antwortmoeglichkeiten einer Auswahlfrage.
 *
 * Sie stehen als eine mit Komma getrennte Zeile in feedback_questions.options.
 * Die Zeile wurde bisher an drei Stellen einzeln zerlegt - im Formular, in
 * der Auswertung und nirgends beim Entgegennehmen der Antwort. Genau die
 * fehlende dritte Stelle war das Problem: Was als mc_responses[] ankam, ging
 * ungeprueft in die Datenbank.
 *
 * Die Radio-Schaltflaechen im Formular schraenken nichts ein - sie sind eine
 * Bequemlichkeit des Browsers, keine Pruefung. Ein POST laesst sich von Hand
 * schicken, und in der Auswertung legt jeder unbekannte Wert eine eigene
 * Saeule im Diagramm an. In einer anonymen Rueckmeldung, die die Klasse
 * gemeinsam sieht, steht dann ein frei gewaehlter Text da, wo die Lehrkraft
 * eine von drei Antwortmoeglichkeiten erwartet.
 */
final class Auswahloptionen
{
    /**
     * Zerlegt die gespeicherte Zeile in einzelne Antwortmoeglichkeiten.
     *
     * Leere Eintraege fallen weg, "0" bleibt: empty('0') ist wahr, und die
     * frueheren Zerlegungen haben eine Antwortmoeglichkeit "0" deshalb still
     * verschluckt.
     *
     * @return list<string>
     */
    public static function aus(?string $roh): array
    {
        $teile = array_map('trim', explode(',', (string)$roh));

        return array_values(array_filter($teile, static fn(string $o): bool => $o !== ''));
    }

    /**
     * Ist die Antwort eine der vorgesehenen Moeglichkeiten?
     */
    public static function gueltig(?string $roh, string $antwort): bool
    {
        return in_array(trim($antwort), self::aus($roh), true);
    }
}
