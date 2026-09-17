#!/usr/bin/env bash
# Kein Schuelerlink darf mit host_url gebaut werden.
#
# host_url ist die Adresse des Lehrerzugangs. Steht sie vor einem Pfad, den
# Schuelerinnen und Schueler aufrufen, bekommen sie einen Link auf eine
# Adresse, die fuer sie nicht gedacht ist - und mit SCHUELER_URL waere die
# kurze Adresse wirkungslos. Faellt in keinem Test auf, nur im Alltag.
set -uo pipefail
cd "$(dirname "$0")"

TREFFER=$(grep -rn "host_url" src/templates/ 2>/dev/null \
  | grep -E "student_|vertretung_view" || true)

if [ -n "$TREFFER" ]; then
  echo "FEHL  Schuelerlink mit host_url statt schueler_url gebaut:"
  echo "$TREFFER" | sed 's/^/  /'
  exit 1
fi

ANZAHL=$(grep -rc "schueler_url" src/templates/ 2>/dev/null | awk -F: '{s+=$2} END {print s+0}')
echo "OK    $ANZAHL Schuelerlinks, alle ueber schueler_url"
