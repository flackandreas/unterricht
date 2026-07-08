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
                  ($contextImagePath ? "Ich habe dir oben auch ein Bild als Kontext (Musterlösung/Aufgabe) beigefügt.\n" : "") .
                  "Hier ist die eingereichte Hausaufgabe von Schüler " . $studentPseudonym . ". \n" .
                  "Werte diese Hausaufgabe aus und antworte AUSSCHLIESSLICH im JSON-Format. \n" .
                  "WICHTIG FÜR DIE KOORDINATEN (`box_2d`):\n" .
                  "- Lokalisierte Fehler dürfen NICHT verschoben oder ungenau sein. Die roten Boxen werden direkt über das Bild gelegt, sie müssen exakt den fehlerhaften Rechenschritt oder die fehlerhafte Zahl umschließen.\n" .
                  "- Die Koordinaten `[ymin, xmin, ymax, xmax]` sind normalisiert von 0 bis 1000 bezogen auf die gesamte Bildhöhe und -breite.\n" .
                  "- 0 ist der ganz obere/linke Rand, 1000 ist der ganz untere/rechte Rand. Beachte bei Hochformat-Bildern, dass y=1000 das untere Ende ist.\n" .
                  "- Benutze das Feld `image_analysis` als Denk-Schritt (Chain of Thought), um die visuelle Anordnung der mathematischen Zeilen von oben nach unten (Y-Achse) und links nach rechts (X-Achse) zu beschreiben, bevor du die exakten Koordinaten-Werte setzt.\n\n" .
                  "Antworte mit folgendem JSON-Format:\n" .
                  "{\n" .
                  "  \"image_analysis\": \"Beschreibe hier strukturiert, in welchen Zeilen/Bereichen sich welche Rechenschritte und Fehler befinden (z.B. 'Zeile 1 bei y=150 bis 220', 'Fehler 1 in Zeile 4 bei y=640 bis 680, x=450 bis 500').\",\n" .
                  "  \"student_feedback\": \"Dein konstruktives, motivierendes Feedback für den Schüler in der Du-Form.\",\n" .
                  "  \"teacher_notes\": \"Kurze, stichpunktartige Liste der fachlichen oder konzeptionellen Fehler für die Lehrkraft zur Auswertung.\",\n" .
                  "  \"score\": 85,\n" .
                  "  \"errors\": [\n" .
                  "    {\n" .
                  "      \"description\": \"Kurze, ermutigende Erklärung, was hier falsch berechnet/geschrieben wurde.\",\n" .
                  "      \"box_2d\": [ymin, xmin, ymax, xmax] // Bounding Box des Fehlers im Bild. Z.B. [640, 450, 680, 500]\n" .
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
}
