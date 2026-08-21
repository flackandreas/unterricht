<?php
/**
 * src/includes/storage.php
 * Ablage fuer hochgeladene Dateien ausserhalb des DocumentRoot.
 *
 * Hausaufgabenfotos und Kontextdokumente (Musterloesungen!) lagen bisher unter
 * public/uploads/ und waren damit ohne jede Berechtigungspruefung abrufbar.
 * Die Dateinamen stammten aus uniqid(), also aus einem Zeitstempel, und waren
 * dadurch vorhersagbar. Beides ist hier behoben: Dateien liegen in
 * src/storage/uploads/ und werden ausschliesslich ueber media.php ausgeliefert.
 */

const STORAGE_SUBDIRS = ['homework', 'context', 'tmp'];

/**
 * Absoluter Pfad des Ablageverzeichnisses.
 */
function storage_root(): string {
    return __DIR__ . '/../storage';
}

/**
 * Legt ein Unterverzeichnis an (falls noetig) und gibt seinen Pfad zurueck.
 */
function storage_dir(string $subdir): string {
    if (!in_array($subdir, STORAGE_SUBDIRS, true)) {
        throw new InvalidArgumentException('Unbekanntes Ablageverzeichnis: ' . $subdir);
    }

    $dir = storage_root() . '/uploads/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    return $dir;
}

/** Endungen, die in der Ablage vergeben werden duerfen. */
const STORAGE_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

/**
 * Unvorhersagbarer Dateiname mit der uebergebenen Endung.
 *
 * Die Endung wird gegen eine feste Liste geprueft, nicht nur bereinigt:
 * ein blosses Entfernen von Sonderzeichen haette aus "../php" ein "php"
 * gemacht. Heute liefern alle Aufrufer Werte aus einer Allowlist, aber die
 * Funktion darf sich darauf nicht verlassen.
 */
function storage_random_filename(string $extension): string {
    $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: '';

    if (!in_array($extension, STORAGE_ALLOWED_EXTENSIONS, true)) {
        $extension = 'bin';
    }

    return bin2hex(random_bytes(16)) . '.' . $extension;
}

/**
 * Verschiebt eine hochgeladene Datei in die Ablage.
 *
 * Rueckgabe ist der in der Datenbank zu speichernde relative Pfad
 * ("uploads/homework/<name>") oder null, wenn das Verschieben scheitert.
 */
function storage_store_upload(string $tmpName, string $subdir, string $extension): ?string {
    $dir = storage_dir($subdir);
    $filename = storage_random_filename($extension);

    if (!move_uploaded_file($tmpName, $dir . '/' . $filename)) {
        return null;
    }

    chmod($dir . '/' . $filename, 0640);

    return 'uploads/' . $subdir . '/' . $filename;
}

/**
 * Loest einen in der Datenbank gespeicherten Pfad zu einem absoluten Pfad auf.
 *
 * Aeltere Datensaetze verweisen noch auf public/uploads/. Damit bestehende
 * Abgaben weiter angezeigt werden, wird dieser Ort als Fallback geprueft.
 * bin/migrate_uploads.php verschiebt die Altbestaende.
 */
function storage_resolve(?string $storedPath): ?string {
    if ($storedPath === null || $storedPath === '') {
        return null;
    }

    // Nur "uploads/<subdir>/<dateiname>" ist zulaessig - keine Traversierung.
    if (!preg_match('#^uploads/([a-z]+)/([A-Za-z0-9._-]+)$#', $storedPath, $m)) {
        return null;
    }
    if (!in_array($m[1], STORAGE_SUBDIRS, true)) {
        return null;
    }

    $candidates = [
        storage_root() . '/' . $storedPath,
        __DIR__ . '/../public/' . $storedPath,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Loescht eine abgelegte Datei an beiden moeglichen Orten.
 */
function storage_delete(?string $storedPath): void {
    $path = storage_resolve($storedPath);
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

/**
 * MIME-Typ einer abgelegten Datei.
 */
function storage_mime_type(string $absolutePath): string {
    $mime = @mime_content_type($absolutePath);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    return in_array($mime, $allowed, true) ? $mime : 'application/octet-stream';
}
