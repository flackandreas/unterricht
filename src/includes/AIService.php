<?php

namespace App\Includes;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;

class AIService {
    private $client;
    private $apiKey;

    public function __construct() {
        // Lade .env falls vorhanden (für lokale Entwicklung)
        $envPath = realpath(__DIR__ . '/../');
        if (file_exists($envPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->load();
        }

        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY');

        $this->client = new Client([
            'base_uri' => 'https://generativelanguage.googleapis.com/',
            'timeout'  => 60.0,
        ]);
    }

    public function evaluateHomeworkImage(string $taskDescription, string $studentImagePath, string $studentPseudonym, ?string $contextImagePath = null): array {
        if (empty($this->apiKey)) {
            // Mock fallback when API key is not configured
            sleep(2); // Simulate network latency
            return [
                'student_feedback' => "Hallo " . $studentPseudonym . "!\n\nDas ist ein simuliertes Feedback der KI (da kein GEMINI_API_KEY konfiguriert ist).\n\nDeine Abgabe zur Aufgabe '" . $taskDescription . "' sieht ordentlich aus. Achte in Zukunft besonders auf die Vorzeichenregeln bei der Bruchrechnung und stelle sicher, dass du alle Zwischenschritte nachvollziehbar dokumentierst. Das spart dir in Klassenarbeiten wertvolle Punkte!",
                'teacher_notes' => "- Test-Modus aktiv (kein GEMINI_API_KEY konfiguriert)\n- Die Formatierung und Struktur der Einreichung sind in Ordnung\n- Geringfügige Mängel bei der Dokumentation der Rechenschritte.",
                'score' => 88,
                'errors' => [
                    [
                        'description' => "Achte hier auf das richtige Vorzeichen beim Zusammenfassen.",
                        'box_2d' => [250, 180, 380, 480]
                    ],
                    [
                        'description' => "Hier solltest du den Hauptnenner nochmals überprüfen.",
                        'box_2d' => [550, 280, 680, 620]
                    ]
                ]
            ];
        }

        // Read student image
        if (!file_exists($studentImagePath)) {
            throw new \Exception("Student image not found: " . $studentImagePath);
        }

        $studentMimeType = mime_content_type($studentImagePath);
        $studentImageData = base64_encode(file_get_contents($studentImagePath));

        $contextParts = [];
        if ($contextImagePath && file_exists($contextImagePath)) {
            $contextMimeType = mime_content_type($contextImagePath);
            $contextImageData = base64_encode(file_get_contents($contextImagePath));
            $contextParts[] = ['text' => "Zusätzlicher Kontext (z.B. Musterlösung oder Aufgabenblatt):"];
            $contextParts[] = [
                'inline_data' => [
                    'mime_type' => $contextMimeType,
                    'data' => $contextImageData
                ]
            ];
        }

        $prompt = "Du bist ein erfahrener und ermutigender Lehrer. \n" .
                  "Die Aufgabe lautet: " . $taskDescription . "\n\n" .
                  ($contextImagePath ? "Ich habe dir oben auch eine Datei/Dokument als Kontext (Musterlösung/Aufgabe) beigefügt.\n" : "") .
                  "Hier ist die eingereichte Hausaufgabe von Schüler " . $studentPseudonym . ". \n" .
                  "Werte diese Hausaufgabe aus und antworte AUSSCHLIESSLICH im JSON-Format. \n\n" .
                  "WICHTIGE REGELN FÜR BEWERTUNG UND PUNKTZAHL (`score`):\n" .
                  "1. VOLLSTÄNDIGKEITSPRÜFUNG: Überprüfe genau, welche Teilaufgaben (z. B. 1a, 1b, 1c, 1d) in der Aufgabenstellung oder im Kontextdokument gefordert wurden und welche davon der Schüler tatsächlich bearbeitet hat.\n" .
                  "2. PUNKTABZUG BEI FEHLENDEN TEILAUFGABEN: Wenn Teilaufgaben fehlen oder gar nicht bearbeitet wurden (z. B. nur 1a und 1b statt 1a bis 1d), ziehe DAFÜR PROPORTIONAL PUNKTE AB! Ein Schüler, der nur die Hälfte der geforderten Aufgaben eingereicht hat, darf MAXIMAL 50 von 100 Punkten erhalten, selbst wenn seine eingereichten Teile fehlerfrei sind.\n" .
                  "3. HINWEIS IN DEN FEEDBACKS: Erwähne fehlende Teilaufgaben explizit im `student_feedback` (z. B. 'Hinweis: Aufgaben 1c und 1d fehlen noch') sowie in den `teacher_notes` (z. B. 'Unvollständig: 2 von 4 Teilaufgaben fehlen').\n\n" .
                  "WICHTIG FÜR MATHEMATISCHE FORMATIERUNG:\n" .
                  "- Verwende für mathematische Ausdrücke saubere Typografie und Unicode-Zeichen statt Roh-Code!\n" .
                  "- Hochzahlen: Verwende immer echte Hochzahlen wie `²`, `³`, `⁴`, `ⁿ` (z.B. `(x - 2)²` statt `(x-2)^2`, `x² + y²` statt `x^2+y^2`, `a³` statt `a^3`).\n" .
                  "- Operatoren & Indizes: Verwende `·` (Mal-Punkt statt `*`), `±`, `≠`, `≤`, `≥`, `√` sowie Indizes wie `x₁`, `x₂`.\n\n" .
                  "WICHTIG FÜR DIE KOORDINATEN (`box_2d`):\n" .
                  "- Lokalisierte Fehler dürfen NICHT verschoben oder ungenau sein. Die roten Boxen werden direkt über das Bild gelegt, sie müssen exakt den fehlerhaften Rechenschritt oder die fehlerhafte Zahl umschließen.\n" .
                  "- Die Koordinaten `[ymin, xmin, ymax, xmax]` sind normalisiert von 0 bis 1000 bezogen auf die gesamte Bildhöhe und -breite.\n" .
                  "- 0 ist der ganz obere/linke Rand, 1000 ist der ganz untere/rechte Rand. Beachte bei Hochformat-Bildern, dass y=1000 das untere Ende ist.\n" .
                  "- Benutze das Feld `image_analysis` als Denk-Schritt (Chain of Thought), um die visuelle Anordnung der mathematischen Zeilen von oben nach unten (Y-Achse) und links nach rechts (X-Achse) zu beschreiben, bevor du die exakten Koordinaten-Werte setzt.\n\n" .
                  "Antworte mit folgendem JSON-Format:\n" .
                  "{\n" .
                  "  \"image_analysis\": \"Beschreibe hier strukturiert, welche Teilaufgaben gefordert wurden, welche vorhanden sind und in welchen Zeilen/Bereichen sich welche Rechenschritte und Fehler befinden.\",\n" .
                  "  \"student_feedback\": \"Dein konstruktives, motivierendes Feedback für den Schüler in der Du-Form (inklusive Hinweis auf eventuell fehlende Teilaufgaben).\",\n" .
                  "  \"teacher_notes\": \"Kurze, stichpunktartige Liste der fachlichen/konzeptionellen Fehler und der Vollständigkeit (z.B. ob Teilaufgaben fehlen) für die Lehrkraft.\",\n" .
                  "  \"score\": 85,\n" .
                  "  \"errors\": [\n" .
                  "    {\n" .
                  "      \"step_text\": \"Genaue Angabe der Aufgabe/Zeile im Bild (z.B. 'Aufgabe 1b, Zeile 3: 4x + 12 = 36').\",\n" .
                  "      \"description\": \"Kurze, ermutigende Erklärung, was hier falsch berechnet/geschrieben wurde.\",\n" .
                  "      \"box_2d\": [ymin, xmin, ymax, xmax]\n" .
                  "    }\n" .
                  "  ]\n" .
                  "}";

        $parts = [];
        foreach ($contextParts as $cp) $parts[] = $cp;
        $parts[] = ['text' => $prompt];
        $parts[] = [
            'inline_data' => [
                'mime_type' => $studentMimeType,
                'data' => $studentImageData
            ]
        ];

        $payload = [
            'contents' => [
                [
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'responseMimeType' => 'application/json'
            ]
        ];

        try {
            $response = $this->client->post('v1beta/models/gemini-2.5-flash:generateContent?key=' . $this->apiKey, [
                'json' => $payload
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                $responseText = $body['candidates'][0]['content']['parts'][0]['text'];
                // Clean markdown JSON wrapper if present
                $responseText = trim(preg_replace('/^```json|```$/m', '', $responseText));
                $result = json_decode($responseText, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $result;
                } else {
                    throw new \Exception("Invalid JSON response from Gemini API: " . json_last_error_msg());
                }
            } else {
                throw new \Exception("Unexpected response format from Gemini API.");
            }

        } catch (RequestException $e) {
            throw new \Exception("API Request failed: " . $e->getMessage());
        }
    }

    public function generateAssignmentSummary(string $taskTitle, string $taskDescription, array $submissions): array {
        $notesList = [];
        foreach ($submissions as $sub) {
            if (!empty($sub['teacher_notes'])) {
                $notesList[] = "• Schüler-Einreichung (Pseudonym: " . ($sub['student_pseudonym'] ?? 'Schüler') . ", Punkte: " . ($sub['score'] ?? 'N/A') . "/100):\n  " . $sub['teacher_notes'];
            }
        }

        if (empty($notesList)) {
            return [
                'common_errors' => 'Noch keine qualifizierten Auswertungen vorhanden.',
                'solution_approach' => 'Sobald Schüler ihre Hausarbeit abgeben, wird hier automatisch die Zusammenfassung der Fehler und ein didaktischer Lösungsansatz generiert.'
            ];
        }

        if (empty($this->apiKey)) {
            return [
                'common_errors' => "• Typische Unklarheiten bei der Anwendung der mathematischen Grundregeln.\n• Teils unvollständige Bearbeitung der Teilaufgaben.\n• Vorzeichenfehler bei Äquivalenzumformungen.",
                'solution_approach' => "Empfehlung für die nächste Stunde:\n1. Reche den 1. Teilschritt gemeinsam an der Tafel vor.\n2. Weise explizit auf die typische Stolperfalle bei den Vorzeichen hin.\n3. Lass die Schüler eine ähnliche Aufgabe in Partnerarbeit vertiefen."
            ];
        }

        $notesCombined = implode("\n\n", $notesList);

        $prompt = "Du bist ein erfahrener Didaktiker und Mathematiklehrer.\n" .
                  "Hausaufgabe: " . $taskTitle . "\n" .
                  "Aufgabenstellung: " . $taskDescription . "\n\n" .
                  "Hier sind die bisherigen Auswertungsausgaben aller eingereichten Schüler-Arbeiten:\n" .
                  $notesCombined . "\n\n" .
                  "Erstelle für die Lehrkraft eine prägnante Klassen-Zusammenfassung im JSON-Format:\n" .
                  "1. `common_errors`: Kurze, prägnante Zusammenfassung (stichpunktartig oder Absätze) der HÄUFIGSTEN Fehler, Fehlkonzepte und Lücken in dieser Klasse.\n" .
                  "2. `solution_approach`: Ein konkreter didaktischer Lösungsansatz / Vorschlag für die Besprechung in der nächsten Unterrichtsstunde (wie der Lehrer die Fehler aufgreifen und die Lösung erklären kann).\n\n" .
                  "Verwende für mathematische Ausdrücke saubere Typografie (wie ², ³, ·, √).\n" .
                  "Antworte AUSSCHLIESSLICH im folgenden JSON-Format:\n" .
                  "{\n" .
                  "  \"common_errors\": \"...\",\n" .
                  "  \"solution_approach\": \"...\"\n" .
                  "}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'responseMimeType' => 'application/json'
            ]
        ];

        try {
            $response = $this->client->post('v1beta/models/gemini-2.5-flash:generateContent?key=' . $this->apiKey, [
                'json' => $payload
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                $responseText = trim(preg_replace('/^```json|```$/m', '', $body['candidates'][0]['content']['parts'][0]['text']));
                $json = json_decode($responseText, true);
                if ($json && isset($json['common_errors'], $json['solution_approach'])) {
                    return $json;
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to generate assignment summary: " . $e->getMessage());
        }

        return [
            'common_errors' => implode("\n", array_slice($notesList, 0, 5)),
            'solution_approach' => 'Empfehlung: Gehe die wesentlichen Rechenschritte an der Tafel mit der Klasse durch.'
        ];
    }
}
