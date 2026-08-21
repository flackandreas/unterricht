<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Antwortschema fuer die Hausaufgaben-Auswertung.
 *
 * Bisher wurde das Format in Prosa im Prompt beschrieben und die Antwort
 * anschliessend mit preg_replace von Markdown-Zaeunen befreit. Gemini
 * erzwingt mit responseSchema den Typ - die Nachbearbeitung entfaellt und
 * "die KI liefert manchmal ein Array statt eines Strings" kann nicht mehr
 * passieren.
 */
final class EvaluationSchema
{
    /**
     * Schema der Einzelauswertung.
     *
     * @param list<array{id:int,label:string,max_points:int}> $criteria
     * @return array<string,mixed>
     */
    public static function forEvaluation(array $criteria = []): array
    {
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'image_analysis' => [
                    'type' => 'STRING',
                    'description' => 'Welche Teilaufgaben gefordert und welche bearbeitet wurden, '
                        . 'sowie die Lage der Rechenschritte im Bild von oben nach unten.',
                ],
                'student_feedback' => [
                    'type' => 'STRING',
                    'description' => 'Konstruktives, motivierendes Feedback in der Du-Form.',
                ],
                'teacher_notes' => [
                    'type' => 'STRING',
                    'description' => 'Stichpunktartige fachliche Notizen für die Lehrkraft.',
                ],
                'score' => [
                    'type' => 'INTEGER',
                    'description' => 'Gesamtpunktzahl von 0 bis 100.',
                ],
                'errors' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'step_text' => [
                                'type' => 'STRING',
                                'description' => 'Aufgabe und Zeile im Bild, z. B. "Aufgabe 1b, Zeile 3".',
                            ],
                            'description' => [
                                'type' => 'STRING',
                                'description' => 'Kurze, ermutigende Erklärung des Fehlers.',
                            ],
                            'box_2d' => [
                                'type' => 'ARRAY',
                                'description' => '[ymin, xmin, ymax, xmax], normalisiert auf 0 bis 1000.',
                                'items' => ['type' => 'INTEGER'],
                                'minItems' => 4,
                                'maxItems' => 4,
                            ],
                        ],
                        'required' => ['description', 'box_2d'],
                    ],
                ],
            ],
            'required' => ['image_analysis', 'student_feedback', 'teacher_notes', 'score', 'errors'],
        ];

        if ($criteria !== []) {
            $schema['properties']['criteria'] = [
                'type' => 'ARRAY',
                'description' => 'Bewertung je Kriterium des Rasters. Jedes Kriterium genau einmal.',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'id' => [
                            'type' => 'INTEGER',
                            'description' => 'Die vorgegebene ID des Kriteriums.',
                        ],
                        'points' => [
                            'type' => 'NUMBER',
                            'description' => 'Erreichte Punkte, höchstens die angegebene Maximalpunktzahl.',
                        ],
                        'comment' => [
                            'type' => 'STRING',
                            'description' => 'Ein Satz zur Begründung.',
                        ],
                    ],
                    'required' => ['id', 'points', 'comment'],
                ],
            ];
            $schema['required'][] = 'criteria';
        }

        return $schema;
    }

    /**
     * Schema der Klassen-Zusammenfassung.
     *
     * @return array<string,mixed>
     */
    public static function forSummary(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'common_errors' => [
                    'type' => 'STRING',
                    'description' => 'Häufigste Fehler, Fehlkonzepte und Lücken der Klasse.',
                ],
                'solution_approach' => [
                    'type' => 'STRING',
                    'description' => 'Konkreter didaktischer Vorschlag für die Besprechung.',
                ],
            ],
            'required' => ['common_errors', 'solution_approach'],
        ];
    }
}
