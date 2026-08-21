<?php
/**
 * src/includes/request.php
 * Ermittelt Schema und Host der Anfrage.
 *
 * Beides wurde bisher an vielen Stellen direkt aus $_SERVER['HTTPS'] bzw.
 * $_SERVER['HTTP_HOST'] gelesen. Hinter einem Reverse Proxy ist HTTPS dort
 * nicht gesetzt (das Secure-Flag der Session fiel deshalb weg) und der
 * Host-Header ist grundsaetzlich vom Client kontrollierbar.
 */

/**
 * Laeuft die Anfrage ueber TLS?
 *
 * X-Forwarded-Proto kann nur zu "https" hochstufen, niemals herabstufen.
 * Ein gefaelschter Header kann einer echten TLS-Verbindung also nicht das
 * Secure-Flag entziehen.
 */
function request_is_https(): bool {
    $https = $_SERVER['HTTPS'] ?? '';
    if ($https !== '' && strcasecmp((string)$https, 'off') !== 0) {
        return true;
    }

    $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($forwarded !== '') {
        // Proxy-Ketten haengen die Werte kommasepariert aneinander.
        $first = trim(explode(',', $forwarded)[0]);
        if (strcasecmp($first, 'https') === 0) {
            return true;
        }
    }

    if (strcasecmp((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''), 'on') === 0) {
        return true;
    }

    return false;
}

/**
 * Host der Anwendung. APP_URL hat Vorrang; sonst wird der Host-Header
 * verwendet, aber gegen Host-Header-Injection auf ein striktes Format geprueft.
 */
function request_host(): string {
    $configured = trim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
    if ($configured !== '') {
        $host = parse_url($configured, PHP_URL_HOST);
        if ($host) {
            $port = parse_url($configured, PHP_URL_PORT);
            return $port ? $host . ':' . $port : $host;
        }
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && preg_match('/^[A-Za-z0-9.\-]{1,253}(:[0-9]{1,5})?$/', $host)) {
        return $host;
    }

    return 'localhost';
}

/**
 * Basis-URL ohne abschliessenden Schraegstrich, z.B. "https://schule.example".
 */
function request_base_url(): string {
    $configured = trim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    return (request_is_https() ? 'https' : 'http') . '://' . request_host();
}

/**
 * IP-Adresse des Clients, fuer Rate-Limiting.
 */
function request_client_ip(): string {
    $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }

    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $remote !== '' ? $remote : 'unknown';
}
