<?php
/**
 * src/includes/sso.php
 * Anbindung an das SchulOS-Portal.
 *
 * Solange PORTAL_ISSUER, PORTAL_CLIENT_ID und PORTAL_CLIENT_SECRET nicht
 * gesetzt sind, ist hier alles aus und das Modul verhaelt sich wie bisher:
 * eigene Anmeldemaske, eigene Konten. Das ist Absicht - eine Schule soll
 * dieses Modul auch ohne Portal betreiben koennen.
 *
 * Der eigentliche Anmeldeablauf steckt im Paket schulos/sso, damit er nicht
 * in jedem Modul einzeln und unterschiedlich vollstaendig dasteht.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

use App\Support\AuditLog;
use SchulOS\Sso\Anmeldung;
use SchulOS\Sso\Identitaet;
use SchulOS\Sso\Konfiguration;

function sso_konfiguration(): Konfiguration
{
    static $konfiguration = null;

    if ($konfiguration === null) {
        $ablage = __DIR__ . '/../storage/cache/sso';
        if (!is_dir($ablage)) {
            @mkdir($ablage, 0750, true);
        }

        $konfiguration = Konfiguration::ausUmgebung('PORTAL_', is_writable($ablage) ? $ablage : '');
    }

    return $konfiguration;
}

/**
 * Die registrierbare Domain eines Hostnamens - naeherungsweise.
 *
 * Genau ginge das nur mit der Public Suffix List. Fuer die Frage hier
 * genuegt die Naeherung "die letzten beiden Bestandteile": sie kann eine
 * Warnung uebersehen (zwei Schulen unter derselben Landesdomain), aber keine
 * falsche ausloesen - und eine uebersehene Warnung ist der Zustand von
 * heute.
 */
function sso_domain(string $host): string
{
    $host = strtolower(trim($host));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    $teile = array_values(array_filter(explode('.', $host), static fn(string $t): bool => $t !== ''));

    if (count($teile) < 2) {
        return $host;
    }

    return implode('.', array_slice($teile, -2));
}

/**
 * Liegen Portal und Modul unter derselben Domain?
 *
 * Das Sitzungscookie traegt SameSite=Strict. Der Browser schickt es dann bei
 * jedem Wechsel von einer anderen Site nicht mit - auch nicht beim Rueckweg
 * vom Portal, obwohl das eine gewoehnliche Weiterleitung ist. Die Anmeldung
 * kommt dann ohne den vorher gemerkten state zurueck und scheitert mit
 * "state stimmt nicht": eine Meldung, die nach Angriff klingt und in
 * Wahrheit eine falsch eingetragene Adresse bedeutet.
 *
 * Unterdomains derselben Domain sind dabei kein Wechsel. portal.schule.de
 * und unterricht.schule.de gehoeren zusammen, portal.schule.de und
 * unterricht.example.org nicht.
 *
 * Nur ein Hinweis, keine Sperre: fuer einen anderen Aufbau - etwa ein Portal
 * beim Schultraeger - waere SameSite=Lax der Weg, und das ist eine
 * Entscheidung, keine Fehlkonfiguration.
 */
function sso_gleiche_domain(): ?bool
{
    $portal = (string) parse_url((string) env('PORTAL_ISSUER', ''), PHP_URL_HOST);
    $eigen = function_exists('request_host') ? request_host() : '';

    if ($portal === '' || $eigen === '') {
        return null; // Auf der Kommandozeile gibt es keinen eigenen Host.
    }

    return sso_domain($portal) === sso_domain($eigen);
}

/** Laeuft dieses Modul am Portal? */
function sso_aktiv(): bool
{
    $konfiguration = sso_konfiguration();

    // Eine halb ausgefuellte Konfiguration ist der gefaehrlichere Fall: das
    // Modul meint, es sei am Portal, und scheitert erst beim Anmelden.
    $fehlt = $konfiguration->fehlendeAngaben();
    if ($fehlt !== []) {
        error_log('Unterricht: Portal-Anbindung unvollstaendig, es fehlt: PORTAL_' . implode(', PORTAL_', $fehlt));
    }

    $aktiv = $konfiguration->aktiv();

    static $gewarnt = false;
    if ($aktiv && !$gewarnt && sso_gleiche_domain() === false) {
        $gewarnt = true;
        error_log(sprintf(
            'Unterricht: PORTAL_ISSUER (%s) und dieses Modul (%s) liegen unter verschiedenen Domains. '
            . 'Das Sitzungscookie traegt SameSite=Strict und wird auf dem Rueckweg vom Portal nicht '
            . 'mitgeschickt - die Anmeldung scheitert dann mit "state stimmt nicht".',
            (string) env('PORTAL_ISSUER', ''),
            request_host()
        ));
    }

    return $aktiv;
}

function sso_anmeldung(): Anmeldung
{
    return new Anmeldung(sso_konfiguration());
}

/** Adresse des Portals, fuer den Rueckweg aus der Navigation. */
function sso_portal_adresse(): string
{
    return rtrim((string) env('PORTAL_ISSUER', ''), '/');
}

/**
 * Steht die Zuordnungstabelle schon?
 *
 * Sie entsteht ueber die Migrationen. Fehlt sie, ist etwas beim Update
 * schiefgelaufen - dann soll die Anmeldung mit einer klaren Meldung
 * stehenbleiben statt mit einem SQL-Fehler.
 */
function sso_zuordnung_bereit(PDO $conn): bool
{
    static $bereit = null;

    if ($bereit === null) {
        try {
            $bereit = $conn->query("SHOW TABLES LIKE 'portal_konten'")->fetch() !== false;
        } catch (PDOException $e) {
            error_log('Unterricht: Pruefung der Zuordnungstabelle fehlgeschlagen: ' . $e->getMessage());
            $bereit = false;
        }
    }

    return $bereit;
}

/**
 * Sucht das Konto zur Portal-Identitaet, legt es bei Bedarf an und bringt
 * Name, E-Mail und Verwaltungsrecht auf den Stand des Portals.
 *
 * Reihenfolge der Suche:
 *   1. ueber sso_sub - die einzige dauerhaft verlaessliche Kennung
 *   2. ueber das Kuerzel, dann wird sso_sub nachgetragen; so wandert ein
 *      bestehendes Konto beim ersten Portal-Login mit, samt seiner Klassen
 *      und Hausaufgaben
 *   3. neu anlegen - aber nur, wenn PORTAL_ZUGANG_GRUPPEN gesetzt ist
 *
 * Ohne PORTAL_ZUGANG_GRUPPEN wird niemand mehr automatisch angelegt; dann
 * koennen sich nur bereits eingetragene Lehrkraefte anmelden. Vorher war es
 * umgekehrt: ein leerer Wert hiess "jede im Portal angemeldete Person bekommt
 * hier ein Lehrkraftkonto" - inklusive Zugriff auf Hausaufgaben und
 * Klassenauswertungen. Der IServ-Weg (login_sso.php) haelt es seit laengerem
 * so herum; beide Wege sollen sich nicht widersprechen.
 *
 * @return array<string,mixed>|null null heisst: kein Zugang
 */
function sso_konto(PDO $conn, Identitaet $identitaet): ?array
{
    $zugangsGruppen = (string) env('PORTAL_ZUGANG_GRUPPEN', '');
    if ($zugangsGruppen !== '' && !$identitaet->inGruppenListe($zugangsGruppen)) {
        error_log('Unterricht: Zugang verweigert fuer ' . $identitaet->kuerzel . ' - nicht in ' . $zugangsGruppen);

        return null;
    }

    $istVerwaltung = $identitaet->inGruppenListe((string) env('PORTAL_ADMIN_GRUPPEN', 'Schulleitung'));

    $suche = $conn->prepare(
        'SELECT t.* FROM teachers t
           JOIN portal_konten p ON p.teacher_id = t.id
          WHERE p.sso_sub = ? LIMIT 1'
    );
    $suche->execute([$identitaet->sub]);
    $konto = $suche->fetch();

    if (!$konto && $identitaet->kuerzel !== '') {
        $suche = $conn->prepare('SELECT * FROM teachers WHERE kuerzel = ? LIMIT 1');
        $suche->execute([$identitaet->kuerzel]);
        $konto = $suche->fetch();

        if ($konto) {
            sso_verknuepfe($conn, $identitaet->sub, (int) $konto['id']);
            error_log('Unterricht: bestehendes Konto ' . $identitaet->kuerzel . ' mit dem Portal verknuepft.');

            // Beim ersten Mal entscheidet das Kuerzel darueber, wer ein
            // bestehendes Konto uebernimmt. Das gehoert nachlesbar
            // festgehalten, nicht nur ins Fehlerprotokoll.
            (new AuditLog($conn))->record(
                (int) $konto['id'],
                'portal.konto_verknuepft',
                'teacher',
                (int) $konto['id'],
                'Kuerzel ' . $identitaet->kuerzel . ' mit Portal-Kennung ' . substr($identitaet->sub, 0, 12) . '… verknuepft'
            );
        }
    }

    if (!$konto) {
        // Ohne festgelegte Zugangsgruppen wird niemand automatisch angelegt.
        // Sonst bekaeme jede im Portal angemeldete Person hier ein
        // Lehrkraftkonto - auch Schuelerinnen und Schueler, sobald das Portal
        // deren Konten fuehrt.
        if ($zugangsGruppen === '') {
            error_log(
                'Unterricht: Zugang verweigert fuer ' . $identitaet->kuerzel
                . ' - kein Konto vorhanden und PORTAL_ZUGANG_GRUPPEN ist nicht gesetzt.'
            );

            return null;
        }

        // SSO-Konten brauchen kein lokales Passwort. bin2hex, weil
        // random_bytes Nullbytes enthalten kann und bcrypt dort abschneidet.
        $platzhalter = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        $kuerzel = mb_substr(
            $identitaet->kuerzel !== '' ? $identitaet->kuerzel : 'p_' . substr(sha1($identitaet->sub), 0, 8),
            0,
            50
        );

        $conn->prepare(
            'INSERT INTO teachers (kuerzel, name, email, passwort_hash, is_admin, force_password_change)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([
            $kuerzel,
            mb_substr($identitaet->name, 0, 100),
            $identitaet->email !== '' ? mb_substr($identitaet->email, 0, 255) : null,
            $platzhalter,
            $istVerwaltung ? 1 : 0,
        ]);

        // Ueber das Kuerzel nachlesen: im Unterrichtsmodul ist teachers eine
        // Sicht, und lastInsertId liefert dort nichts Brauchbares.
        $suche = $conn->prepare('SELECT * FROM teachers WHERE kuerzel = ? LIMIT 1');
        $suche->execute([$kuerzel]);
        $konto = $suche->fetch();

        if (!is_array($konto)) {
            error_log('Unterricht: Das angelegte Konto liess sich nicht wieder lesen.');

            return null;
        }

        sso_verknuepfe($conn, $identitaet->sub, (int) $konto['id']);

        error_log('Unterricht: Konto aus dem Portal angelegt: ' . $identitaet->kuerzel);
    } else {
        // Das Portal ist die fuehrende Quelle. Wer dort umbenannt oder aus der
        // Schulleitung genommen wird, ist es hier ab der naechsten Anmeldung
        // auch - sonst blieben Rechte in den Modulen stehen.
        $conn->prepare(
            'UPDATE teachers SET name = ?, email = COALESCE(?, email), is_admin = ?, force_password_change = 0 WHERE id = ?'
        )->execute([
            mb_substr($identitaet->name !== '' ? $identitaet->name : (string) $konto['name'], 0, 100),
            $identitaet->email !== '' ? mb_substr($identitaet->email, 0, 255) : null,
            $istVerwaltung ? 1 : 0,
            $konto['id'],
        ]);

        $konto['name'] = $identitaet->name !== '' ? $identitaet->name : $konto['name'];
        $konto['is_admin'] = $istVerwaltung ? 1 : 0;
        $konto['force_password_change'] = 0;
    }

    return is_array($konto) ? $konto : null;
}

/**
 * Haelt fest, welches Portalkonto zu welcher Lehrkraft gehoert.
 *
 * Vorher wird eine eventuelle aeltere Zuordnung derselben Lehrkraft geloest:
 * sonst scheiterte das Verknuepfen, wenn jemand im Portal ein neues Konto
 * bekommen hat.
 */
function sso_verknuepfe(PDO $conn, string $sub, int $teacherId): void
{
    $conn->prepare('DELETE FROM portal_konten WHERE teacher_id = ? OR sso_sub = ?')
        ->execute([$teacherId, $sub]);
    $conn->prepare('INSERT INTO portal_konten (sso_sub, teacher_id) VALUES (?, ?)')
        ->execute([$sub, $teacherId]);
}

/**
 * Uebernimmt das Konto in die Sitzung dieses Moduls.
 *
 * @param array<string,mixed> $konto
 */
function sso_sitzung_starten(array $konto, Identitaet $identitaet, string $idToken): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = $konto['id'];
    $_SESSION['user_kuerzel'] = $konto['kuerzel'];
    $_SESSION['user_name'] = $konto['name'];
    $_SESSION['is_admin'] = $konto['is_admin'];
    $_SESSION['force_password_change'] = 0;

    // Die Sitzungskennung des Portals. Ohne sie liesse sich eine Abmeldung
    // ueber den Vorderkanal nicht der richtigen Sitzung zuordnen.
    $_SESSION['sso_sid'] = $identitaet->sitzung;
    $_SESSION['sso_id_token'] = $idToken;
    $_SESSION['sso_gruppen'] = $identitaet->gruppen;
}

/** Ist die laufende Sitzung ueber das Portal entstanden? */
function sso_sitzung_vom_portal(): bool
{
    return !empty($_SESSION['sso_sid']);
}
