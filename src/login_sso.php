<?php
/**
 * src/login_sso.php
 * Real Single-Sign-On-Schnittstelle für IServ via OpenID Connect (OIDC)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/error_page.php';
require_once __DIR__ . '/includes/sso.php';

// Laeuft dieses Modul ausschliesslich ueber das SchulOS-Portal, soll es genau
// einen Weg hinein geben. IServ wird dann dort eingerichtet, nicht hier.
if (sso_aktiv() && (string) env('PORTAL_NUR_SSO', '0') === '1') {
    header('Location: /sso_start.php');
    exit;
}

// Konfiguration aus .env auslesen
$iserv_host = $_ENV['ISERV_HOST'] ?? getenv('ISERV_HOST') ?: '';
$client_id = $_ENV['ISERV_CLIENT_ID'] ?? getenv('ISERV_CLIENT_ID') ?: '';
$client_secret = $_ENV['ISERV_CLIENT_SECRET'] ?? getenv('ISERV_CLIENT_SECRET') ?: '';
$iserv_scopes = trim((string)($_ENV['ISERV_SCOPES'] ?? getenv('ISERV_SCOPES') ?: 'openid profile email'));

/**
 * Gruppen, die zur Nutzung des Lehrkraft-Portals berechtigen.
 *
 * Ohne diese Angabe wird niemand mehr automatisch angelegt. Vorher wurde
 * jeder IServ-Account beim ersten Aufruf ungeprueft als Lehrkraft
 * registriert - auch Schuelerinnen und Schueler.
 */
$teacher_groups = array_values(array_filter(array_map(
    'trim',
    explode(',', (string)($_ENV['ISERV_TEACHER_GROUPS'] ?? getenv('ISERV_TEACHER_GROUPS') ?: ''))
)));

/**
 * Sammelt alle Gruppen-/Rollenangaben aus den Claims ein.
 */
function sso_collect_groups(array $claims): array {
    $groups = [];
    foreach (['groups', 'roles', 'group', 'memberOf'] as $claim) {
        $value = $claims[$claim] ?? null;
        if (is_string($value)) {
            $groups[] = $value;
        } elseif (is_array($value)) {
            foreach ($value as $entry) {
                if (is_string($entry)) {
                    $groups[] = $entry;
                }
            }
        }
    }

    return array_map(static fn($g) => mb_strtolower(trim($g)), $groups);
}

/**
 * Liest die Nutzdaten eines ID-Tokens aus.
 *
 * Das Token stammt direkt vom Token-Endpunkt ueber eine TLS-Verbindung mit
 * Client-Authentifizierung (OIDC Core 3.1.3.7), die Signatur wird deshalb
 * nicht zusaetzlich geprueft. iss, aud, exp und nonce werden ausgewertet.
 */
function sso_decode_id_token(string $idToken): ?array {
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        return null;
    }

    $payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
    if ($payload === false) {
        return null;
    }

    $claims = json_decode($payload, true);

    return is_array($claims) ? $claims : null;
}

if (empty($iserv_host) || empty($client_id) || empty($client_secret)) {
    error_page("SSO nicht eingerichtet", "Die IServ-Anmeldung ist auf diesem Server nicht konfiguriert. Bitte wenden Sie sich an die Administration.", 503, "/login.php");
}

// Dynamische Erstellung der Redirect-URI (muss bei IServ registriert sein).
// request_base_url() bevorzugt APP_URL und faellt sonst auf einen gegen
// Host-Header-Injection geprueften Host zurueck.
$redirect_uri = request_base_url() . '/login_sso.php';

$client = new \GuzzleHttp\Client();

if (isset($_GET['code'])) {
    // 1. CSRF-Schutz: State validieren
    $state = $_GET['state'] ?? '';
    if (empty($state) || empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
        error_page("Anmeldung abgebrochen", "Die Sitzung ist abgelaufen. Bitte starten Sie den Anmeldevorgang erneut.", 400, "/login.php");
    }

    $expected_nonce = $_SESSION['oauth_nonce'] ?? '';
    $code_verifier = $_SESSION['oauth_code_verifier'] ?? '';
    // Einmalwerte sofort entwerten, damit der Code nicht erneut eingeloest wird.
    unset($_SESSION['oauth_state'], $_SESSION['oauth_nonce'], $_SESSION['oauth_code_verifier']);

    // 2. OIDC Discovery aufrufen
    try {
        $discovery_url = rtrim($iserv_host, '/') . '/.well-known/openid-configuration';
        $discovery_response = $client->get($discovery_url);
        $discovery_data = json_decode($discovery_response->getBody(), true);
        
        $token_endpoint = $discovery_data['token_endpoint'] ?? '';
        $userinfo_endpoint = $discovery_data['userinfo_endpoint'] ?? '';
        
        if (empty($token_endpoint) || empty($userinfo_endpoint)) {
            throw new \Exception("Token- oder UserInfo-Endpoint fehlt in der OIDC-Konfiguration von IServ.");
        }
    } catch (\Exception $e) {
        error_log("IServ OIDC Discovery fehlgeschlagen: " . $e->getMessage());
        error_page("IServ nicht erreichbar", "Die Anmeldedaten konnten nicht abgerufen werden. Bitte versuchen Sie es später erneut.", 502, "/login.php");
    }

    // 3. Autorisierungs-Code gegen Tokens tauschen
    $id_claims = [];
    try {
        $form_params = [
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $redirect_uri,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ];
        if ($code_verifier !== '') {
            $form_params['code_verifier'] = $code_verifier;
        }

        $token_response = $client->post($token_endpoint, ['form_params' => $form_params]);

        $token_data = json_decode($token_response->getBody(), true);
        $access_token = $token_data['access_token'] ?? '';
        if (empty($access_token)) {
            throw new \Exception("Kein Access-Token in Token-Antwort erhalten.");
        }

        // ID-Token gegen Wiedereinspielung und fremde Empfaenger pruefen
        if (!empty($token_data['id_token'])) {
            $id_claims = sso_decode_id_token($token_data['id_token']) ?? [];

            $aud = $id_claims['aud'] ?? '';
            $aud_list = is_array($aud) ? $aud : [$aud];
            if (!in_array($client_id, $aud_list, true)) {
                throw new \Exception("ID-Token ist für einen anderen Client ausgestellt.");
            }

            if (isset($id_claims['exp']) && (int)$id_claims['exp'] < time() - 60) {
                throw new \Exception("ID-Token ist abgelaufen.");
            }

            if ($expected_nonce !== '' && !hash_equals($expected_nonce, (string)($id_claims['nonce'] ?? ''))) {
                throw new \Exception("Nonce des ID-Tokens stimmt nicht überein.");
            }
        }
    } catch (\Exception $e) {
        error_log("IServ Token-Austausch fehlgeschlagen: " . $e->getMessage());
        error_page("Anmeldung fehlgeschlagen", "Der Anmeldevorgang konnte nicht abgeschlossen werden. Bitte versuchen Sie es erneut.", 400, "/login.php");
    }

    // 4. Benutzerinformationen abrufen
    try {
        $userinfo_response = $client->get($userinfo_endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
            ]
        ]);
        
        $userinfo_data = json_decode($userinfo_response->getBody(), true);
    } catch (\Exception $e) {
        error_log("IServ UserInfo-Abfrage fehlgeschlagen: " . $e->getMessage());
        error_page("IServ nicht erreichbar", "Die Benutzerdaten konnten nicht abgerufen werden. Bitte versuchen Sie es später erneut.", 502, "/login.php");
    }

    // 5. Benutzerdaten auswerten & anpassen
    $email = $userinfo_data['email'] ?? '';
    $preferred_username = $userinfo_data['preferred_username'] ?? '';
    
    if (empty($preferred_username)) {
        $preferred_username = $userinfo_data['username'] ?? '';
        if (empty($preferred_username) && !empty($email)) {
            $preferred_username = explode('@', $email)[0];
        }
    }
    
    if (empty($preferred_username)) {
        error_page("Anmeldung fehlgeschlagen", "Aus der IServ-Antwort ließ sich kein Benutzerkürzel ermitteln. Bitte wenden Sie sich an die Administration.", 502, "/login.php");
    }

    $full_name = $userinfo_data['name'] ?? '';
    if (empty($full_name)) {
        $given_name = $userinfo_data['given_name'] ?? '';
        $family_name = $userinfo_data['family_name'] ?? '';
        $full_name = trim($given_name . ' ' . $family_name);
    }
    if (empty($full_name)) {
        $full_name = $preferred_username;
    }

    // 6. Login / Registrierung in der lokalen DB durchführen
    try {
        $conn = db_connect();
        
        // Prüfen, ob der Lehrer bereits existiert
        $stmt = $conn->prepare("SELECT id, is_admin, name, force_password_change FROM teachers WHERE kuerzel = :kuerzel LIMIT 1");
        $stmt->execute([':kuerzel' => $preferred_username]);
        $user = $stmt->fetch();
        
        // Auto-Register nur fuer Konten aus einer konfigurierten Lehrer-Gruppe.
        //
        // Ohne diese Pruefung erhielt jeder IServ-Account beim ersten Aufruf
        // von /login_sso.php einen Lehrkraft-Datensatz - inklusive Zugriff auf
        // Hausaufgaben und Klassenauswertungen. Ist ISERV_TEACHER_GROUPS nicht
        // gesetzt, wird niemand mehr automatisch angelegt; bestehende
        // Lehrkraefte koennen sich weiterhin anmelden.
        if (!$user) {
            $user_groups = array_merge(sso_collect_groups($userinfo_data), sso_collect_groups($id_claims));
            $allowed_groups = array_map('mb_strtolower', $teacher_groups);
            $is_teacher = !empty($allowed_groups) && !empty(array_intersect($user_groups, $allowed_groups));

            if (!$is_teacher) {
                error_log(sprintf(
                    'SSO-Anmeldung abgelehnt: %s ist nicht angelegt und in keiner berechtigten Gruppe (Gruppen: %s)',
                    $preferred_username,
                    $user_groups ? implode(', ', $user_groups) : 'keine übermittelt'
                ));
                error_page("Kein Zugang", "Für dieses Konto ist kein Zugang zum Lehrkräfte-Portal eingerichtet. Bitte wenden Sie sich an die Administration.", 403, "/login.php");
            }

            // SSO-Nutzer brauchen kein lokales Passwort. bin2hex, weil
            // random_bytes Nullbytes enthalten kann und bcrypt dort abschneidet.
            $dummy_password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

            $insert = $conn->prepare("INSERT INTO teachers (kuerzel, email, passwort_hash, is_admin, name, force_password_change) VALUES (?, ?, ?, ?, ?, 0)");
            $insert->execute([$preferred_username, $email, $dummy_password, 0, $full_name]);

            $user_id = $conn->lastInsertId();
            $user = [
                'id' => $user_id,
                'is_admin' => 0,
                'name' => $full_name,
                'force_password_change' => 0
            ];
        }
        
        // Lokale Session starten
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_kuerzel'] = $preferred_username;
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['force_password_change'] = $user['force_password_change'];
        
        header("Location: /index.php");
        exit;

    } catch (\PDOException $e) {
        error_log("SSO-Datenbankfehler: " . $e->getMessage());
        error_page("Anmeldung fehlgeschlagen", "Beim Speichern der Anmeldung ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.", 500, "/login.php");
    }

} else {
    // 1. OIDC Discovery für Login-Redirect aufrufen
    try {
        $discovery_url = rtrim($iserv_host, '/') . '/.well-known/openid-configuration';
        $discovery_response = $client->get($discovery_url);
        $discovery_data = json_decode($discovery_response->getBody(), true);
        
        $auth_endpoint = $discovery_data['authorization_endpoint'] ?? '';
        
        if (empty($auth_endpoint)) {
            throw new \Exception("Authorization-Endpoint fehlt in der OIDC-Konfiguration von IServ.");
        }
    } catch (\Exception $e) {
        error_log("IServ OIDC Discovery fehlgeschlagen: " . $e->getMessage());
        error_page("IServ nicht erreichbar", "Die Anmeldung bei IServ ist derzeit nicht möglich. Bitte versuchen Sie es später erneut.", 502, "/login.php");
    }

    // 2. State, Nonce und PKCE-Verifier erzeugen und in der Session ablegen
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $code_verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');

    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_nonce'] = $nonce;
    $_SESSION['oauth_code_verifier'] = $code_verifier;

    // 3. Zum IServ-Login weiterleiten
    $params = [
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => $iserv_scopes,
        'state' => $state,
        'nonce' => $nonce,
        // PKCE schuetzt den Autorisierungscode auf dem Rueckweg.
        'code_challenge' => $code_challenge,
        'code_challenge_method' => 'S256'
    ];

    header('Location: ' . $auth_endpoint . '?' . http_build_query($params));
    exit;
}
