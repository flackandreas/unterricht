<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Antwortschema fuer die Vertretungsstunde.
 *
 * Nach demselben Grundsatz wie EvaluationSchema: das Format wird erzwungen,
 * nicht in Prosa erbeten. Eine Vertretungsmappe, bei der die Loesungen mal
 * ein String und mal eine Liste sind, ist im Lehrerzimmer um 7:45 Uhr
 * wertlos.
 */
final class LessonPlanSchema
{
    /**
     * @return array<string,mixed>
     */
    public static function forPlan(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'titel' => [
                    'type' => 'STRING',
                    'description' => 'Kurzer Titel der Stunde, höchstens acht Wörter.',
                ],
                'einstieg' => [
                    'type' => 'STRING',
                    'description' => 'Fünf Minuten zum Beginnen, ohne Vorbereitung und ohne Material '
                        . 'durchführbar. Anweisung an die Vertretungskraft, nicht an die Klasse.',
                ],
                'arbeitsauftrag' => [
                    'type' => 'STRING',
                    'description' => 'Der Kern der Stunde, in Schülersprache formuliert und so, dass '
                        . 'er an der Tafel stehen oder vorgelesen werden kann.',
                ],
                'aufgaben' => [
                    'type' => 'ARRAY',
                    'description' => 'Die konkreten Aufgaben, die bearbeitet werden.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'nummer'   => ['type' => 'INTEGER'],
                            'aufgabe'  => ['type' => 'STRING', 'description' => 'Die Aufgabe im Wortlaut.'],
                            'loesung'  => [
                                'type' => 'STRING',
                                'description' => 'Vollständige Lösung mit Rechen- oder Denkweg. Muss '
                                    . 'auch von einer fachfremden Vertretungskraft nachvollziehbar sein.',
                            ],
                        ],
                        'required' => ['nummer', 'aufgabe', 'loesung'],
                    ],
                ],
                'material' => [
                    'type' => 'STRING',
                    'description' => 'Nur was ohne Vorbereitung im Raum ist: Tafel, Heft, Buch. '
                        . 'Nichts, was kopiert oder mitgebracht werden müsste.',
                ],
                'differenzierung' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'leichter' => ['type' => 'STRING', 'description' => 'Für die, die nicht weiterkommen.'],
                        'schwerer' => ['type' => 'STRING', 'description' => 'Für die, die früh fertig sind.'],
                    ],
                    'required' => ['leichter', 'schwerer'],
                ],
                'sicherung' => [
                    'type' => 'STRING',
                    'description' => 'Wie die Stunde endet und was eingesammelt oder festgehalten wird.',
                ],
                'zeitplan' => [
                    'type' => 'ARRAY',
                    'description' => 'Ablauf in Abschnitten, zusammen so lang wie die Stunde.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'minuten'    => ['type' => 'INTEGER'],
                            'abschnitt'  => ['type' => 'STRING'],
                            'sozialform' => [
                                'type' => 'STRING',
                                'description' => 'Etwa Einzelarbeit, Partnerarbeit, Unterrichtsgespräch.',
                            ],
                        ],
                        'required' => ['minuten', 'abschnitt', 'sozialform'],
                    ],
                ],
                'hinweis_fachlehrer' => [
                    'type' => 'STRING',
                    'description' => 'Was die Fachlehrkraft nach ihrer Rückkehr nacharbeiten oder '
                        . 'einsammeln sollte.',
                ],
            ],
            'required' => [
                'titel', 'einstieg', 'arbeitsauftrag', 'aufgaben', 'material',
                'differenzierung', 'sicherung', 'zeitplan', 'hinweis_fachlehrer',
            ],
        ];
    }
}
