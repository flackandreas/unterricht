#!/bin/sh
#
# docker-entrypoint.sh
# Macht die Ablage fuer den Webserver beschreibbar, bevor Apache startet.
#
# Ein bind-mount verdeckt das Verzeichnis aus dem Abbild samt der dort per
# chown gesetzten Rechte - was im Dockerfile vorbereitet wurde, gilt fuer den
# eingehaengten Inhalt nicht. Der Webserver laeuft als www-data (uid 33), das
# eingehaengte src/ gehoert dem Benutzer des Hosts. Ohne diesen Schritt
# scheitert jeder Upload still: storage_store_upload() kann das Verzeichnis
# weder anlegen noch beschreiben.
#
# In buju blieb genau das vom 26.08. bis 07.09.2026 unbemerkt, weil die
# Kontrolle nach dem Deploy nur lesende Aufrufe abgedeckt hatte.

set -e

ABLAGE=/var/www/html/storage

for verzeichnis in \
    "$ABLAGE" \
    "$ABLAGE/uploads" \
    "$ABLAGE/uploads/homework" \
    "$ABLAGE/uploads/context" \
    "$ABLAGE/uploads/tmp" \
    "$ABLAGE/cache" \
    "$ABLAGE/cache/twig"
do
    mkdir -p "$verzeichnis" 2>/dev/null || true
done

# setgid, damit neu angelegte Dateien die Gruppe erben.
if chgrp -R www-data "$ABLAGE" 2>/dev/null \
    && chmod -R g+rwX "$ABLAGE" 2>/dev/null \
    && find "$ABLAGE" -type d -exec chmod g+s {} + 2>/dev/null
then
    :
else
    echo "WARNUNG: $ABLAGE ist fuer www-data nicht beschreibbar." >&2
    echo "         Hausaufgaben-Uploads und der Migrationsvermerk werden scheitern." >&2
fi

# An den Einstiegspunkt des PHP-Abbilds weiterreichen.
exec docker-php-entrypoint "$@"
