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
 * Vermittler, deren X-Forwarded-For geglaubt wird.
 *
 * TRUSTED_PROXIES nimmt einzelne Adressen und Netze in CIDR-Schreibweise,
 * kommagetrennt. Das Wort "private" steht fuer die privaten Netze und die
 * Rueckschleife - im Containerverbund, wo der Reverse Proxy im selben
 * Bridge-Netz haengt, ist das der Regelfall.
 *
 * @return list<string>
 */
function request_trusted_proxies(): array {
    $roh = trim((string)($_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: ''));
    if ($roh === '') {
        return [];
    }

    $bereiche = [];
    foreach (explode(',', $roh) as $eintrag) {
        $eintrag = trim($eintrag);
        if ($eintrag === '') {
            continue;
        }

        if (strcasecmp($eintrag, 'private') === 0) {
            array_push(
                $bereiche,
                '127.0.0.0/8',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '::1/128',
                'fc00::/7'
            );
            continue;
        }

        $bereiche[] = $eintrag;
    }

    return $bereiche;
}

/**
 * Liegt eine Adresse in einem Netz? Einzeladresse oder CIDR, v4 und v6.
 */
function request_ip_in_range(string $ip, string $bereich): bool {
    $binIp = @inet_pton($ip);
    if ($binIp === false) {
        return false;
    }

    if (!str_contains($bereich, '/')) {
        return @inet_pton($bereich) === $binIp;
    }

    [$netz, $praefix] = explode('/', $bereich, 2);
    $binNetz = @inet_pton(trim($netz));

    if ($binNetz === false || strlen($binNetz) !== strlen($binIp)) {
        return false;
    }

    $bits = (int)$praefix;
    if ($bits < 0 || $bits > strlen($binIp) * 8) {
        return false;
    }

    $ganzeBytes = intdiv($bits, 8);
    $restBits = $bits % 8;

    if ($ganzeBytes > 0 && strncmp($binIp, $binNetz, $ganzeBytes) !== 0) {
        return false;
    }

    if ($restBits === 0) {
        return true;
    }

    $maske = chr((0xFF << (8 - $restBits)) & 0xFF);

    return ($binIp[$ganzeBytes] & $maske) === ($binNetz[$ganzeBytes] & $maske);
}

/**
 * @param list<string> $bereiche
 */
function request_is_trusted_proxy(string $ip, array $bereiche): bool {
    foreach ($bereiche as $bereich) {
        if (request_ip_in_range($ip, $bereich)) {
            return true;
        }
    }

    return false;
}

/**
 * IP-Adresse des Clients, fuer Rate-Limiting.
 *
 * X-Forwarded-For wird nur ausgewertet, wenn die Anfrage von einem in
 * TRUSTED_PROXIES eingetragenen Vermittler kommt. Vorher wurde der Header
 * ungeprueft uebernommen - damit bestimmte jeder Aufrufer seine eigene
 * Kennung und konnte saemtliche IP-Grenzen umgehen: Anmeldeversuche,
 * Abgaben, Feedback und die kostenpflichtigen KI-Aufrufe.
 *
 * Aus der Kette wird von rechts nach links der erste Eintrag genommen, der
 * nicht selbst ein bekannter Vermittler ist. Die linken Eintraege kann der
 * Client frei erfinden; sie zaehlen deshalb nicht.
 */
function request_client_ip(): string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '') {
        return 'unknown';
    }

    $vertrauenswuerdig = request_trusted_proxies();

    if ($vertrauenswuerdig === []) {
        // Ohne Konfiguration zaehlt allein die Adresse der Verbindung. Steht
        // die Anwendung hinter einem Proxy, teilen sich sonst alle Zugriffe
        // dessen Adresse - der Hinweis spart die Suche danach.
        if (($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') !== '') {
            error_log('Hinweis: X-Forwarded-For wird ignoriert, weil TRUSTED_PROXIES nicht gesetzt ist.');
        }

        return $remote;
    }

    if (!request_is_trusted_proxy($remote, $vertrauenswuerdig)) {
        return $remote;
    }

    $kette = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));

    for ($i = count($kette) - 1; $i >= 0; $i--) {
        if (!filter_var($kette[$i], FILTER_VALIDATE_IP)) {
            continue;
        }

        if (!request_is_trusted_proxy($kette[$i], $vertrauenswuerdig)) {
            return $kette[$i];
        }
    }

    return $remote;
}
