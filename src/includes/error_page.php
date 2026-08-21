<?php
/**
 * src/includes/error_page.php
 * Einheitliche Fehlerseite.
 *
 * Bisher endeten Fehler in rohen die()-Ausgaben: unformatierter Text ohne
 * Layout, ohne Statuscode, ohne Weg zurueck.
 */

require_once __DIR__ . '/request.php';

/**
 * Zeigt eine Fehlerseite und beendet den Request.
 *
 * @param string      $titel   Kurze Überschrift
 * @param string      $text    Erklärung für die Nutzerin oder den Nutzer
 * @param int         $status  HTTP-Statuscode
 * @param string|null $zurueck Ziel des Zurück-Links, null blendet ihn aus
 */
function error_page(string $titel, string $text, int $status = 400, ?string $zurueck = '/index.php'): never {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }

    $titelHtml = htmlspecialchars($titel, ENT_QUOTES, 'UTF-8');
    $textHtml = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $zurueckHtml = $zurueck !== null ? htmlspecialchars($zurueck, ENT_QUOTES, 'UTF-8') : null;

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$titelHtml}</title>
        <link rel="stylesheet" href="/css/fonts.css">
        <style>
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                background: #f7fafc; color: #2c3e50; margin: 0;
                display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px;
            }
            .karte {
                background: #fff; border-radius: 12px; border-top: 4px solid #e74c3c;
                box-shadow: 0 4px 20px rgba(0,0,0,0.07); padding: 36px; max-width: 460px; width: 100%; text-align: center;
            }
            h1 { color: #e74c3c; font-size: 1.5rem; margin: 0 0 12px; }
            p { color: #475569; line-height: 1.55; margin: 0 0 22px; }
            a {
                display: inline-block; background: #2ecc71; color: #fff; text-decoration: none;
                padding: 11px 22px; border-radius: 6px; font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class="karte">
            <h1>{$titelHtml}</h1>
            <p>{$textHtml}</p>
    HTML;

    if ($zurueckHtml !== null) {
        echo '        <a href="' . $zurueckHtml . '">Zurück</a>' . "\n";
    }

    echo "    </div>\n</body>\n</html>\n";
    exit;
}
