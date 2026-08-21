<?php
/**
 * src/includes/rate_limit.php
 * Einfaches, datenbankgestuetztes Rate-Limiting.
 *
 * Bisher gab es an keiner Stelle eine Begrenzung: weder fuer Login-Versuche
 * noch fuer Abgaben oder die KI-Endpunkte. Letztere verursachen pro Aufruf
 * Kosten bei der Gemini-API und waren ohne Anmeldung erreichbar.
 */

/**
 * Zaehlt einen Zugriff und meldet, ob das Limit noch eingehalten wird.
 *
 * @param string $action  Bezeichner des Vorgangs, z.B. "homework_submit"
 * @param string $subject Wer/was begrenzt wird, z.B. eine IP oder ein Token
 * @param int    $limit   Erlaubte Zugriffe im Zeitfenster
 * @param int    $window  Laenge des Zeitfensters in Sekunden
 *
 * @return bool true = erlaubt, false = Limit ueberschritten
 */
function rate_limit_allow(PDO $conn, string $action, string $subject, int $limit, int $window): bool {
    $bucket = $action . ':' . hash('sha256', $subject);
    $window = max(1, $window);

    try {
        $stmt = $conn->prepare("
            INSERT INTO rate_limits (bucket, window_start, hits)
            VALUES (:bucket, NOW(), 1)
            ON DUPLICATE KEY UPDATE
                hits = IF(window_start < NOW() - INTERVAL {$window} SECOND, 1, hits + 1),
                window_start = IF(window_start < NOW() - INTERVAL {$window} SECOND, NOW(), window_start)
        ");
        $stmt->execute([':bucket' => $bucket]);

        $stmt = $conn->prepare("SELECT hits FROM rate_limits WHERE bucket = ?");
        $stmt->execute([$bucket]);
        $hits = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        // Ein Fehler in der Zaehltabelle darf die Anwendung nicht blockieren.
        error_log('Rate-Limit-Pruefung fehlgeschlagen: ' . $e->getMessage());
        return true;
    }

    if ($hits > $limit) {
        error_log(sprintf('Rate limit erreicht: action=%s hits=%d limit=%d', $action, $hits, $limit));
        return false;
    }

    return true;
}

/**
 * Setzt den Zaehler zurueck, z.B. nach einem erfolgreichen Login.
 */
function rate_limit_reset(PDO $conn, string $action, string $subject): void {
    $bucket = $action . ':' . hash('sha256', $subject);

    try {
        $conn->prepare("DELETE FROM rate_limits WHERE bucket = ?")->execute([$bucket]);
    } catch (PDOException $e) {
        error_log('Rate-Limit-Reset fehlgeschlagen: ' . $e->getMessage());
    }
}

/**
 * Entfernt abgelaufene Eintraege. Wird gelegentlich aus dem Migrationslauf
 * heraus aufgerufen, damit die Tabelle nicht unbegrenzt waechst.
 */
function rate_limit_gc(PDO $conn): void {
    try {
        $conn->exec("DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY");
    } catch (PDOException $e) {
        error_log('Rate-Limit-Aufraeumen fehlgeschlagen: ' . $e->getMessage());
    }
}
