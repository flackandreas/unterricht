<?php
/**
 * src/includes/csp.php
 * Einmaliger Zufallswert je Request fuer die Content-Security-Policy.
 *
 * Solange script-src 'unsafe-inline' erlaubt, ist die Richtlinie gegen
 * eingeschleuste Skripte wirkungslos: der Browser kann nicht unterscheiden,
 * ob ein <script>-Block aus der Vorlage stammt oder aus einem Feld, das
 * jemand mit Skriptcode gefuellt hat. Ein Nonce macht genau diesen
 * Unterschied - er steht im Header und in den eigenen Skriptbloecken, und er
 * ist bei jedem Aufruf ein anderer. Wer Inhalt in die Seite bekommt, kennt
 * ihn nicht und kommt nicht zum Zug.
 *
 * Der Wert wird beim ersten Aufruf gezogen und dann festgehalten: der Header
 * entsteht im Front Controller, die Skriptbloecke entstehen spaeter beim
 * Rendern der Vorlage. Beide muessen denselben Wert nennen.
 */

/**
 * Nonce dieses Requests, Base64 wie in der Richtlinie vorgesehen.
 *
 * 16 Byte aus random_bytes(): das ist die vom CSP-Standard empfohlene
 * Untergrenze und reicht, weil der Wert nur fuer die Dauer eines Requests
 * geheim bleiben muss.
 */
function csp_nonce(): string {
    static $nonce = null;

    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}
