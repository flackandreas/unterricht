/**
 * public/js/live.js
 * Beteiligungserfassung: tippen, zwischenspeichern, nachliefern.
 *
 * Der Zwischenspeicher ist der Kern. Im Klassenzimmer bricht das Netz weg,
 * und ein Beitrag, der dabei verloren geht, ist nicht nachtragbar - niemand
 * erinnert sich abends, wer in der dritten Stunde etwas gesagt hat. Jeder
 * Tipp landet deshalb sofort in localStorage und wird von dort aus so lange
 * geschickt, bis der Server ihn bestaetigt.
 *
 * localStorage ist hier bewusst in Ordnung, anders als beim Lernfortschritt:
 * das ist das Geraet der Lehrkraft, und der Speicher ist ein Ausgangskorb,
 * keine Quelle der Wahrheit. Die liegt in der Datenbank.
 */
(function () {
    'use strict';

    var cfg = window.LIVE_CONFIG;
    if (!cfg) {
        return;
    }

    var SCHLUESSEL = 'live-ausgang:' + cfg.classId + ':' + cfg.fach + ':' + cfg.date + ':' + cfg.period;
    var WARTEZEIT = 1200;
    var NACHFASSEN = 20000;

    var ausgang = laden('events');
    var ruecknahmen = laden('removals');
    var serverStand = cfg.stand || {};
    var thema = cfg.topic || '';
    var themaGeaendert = false;
    var sendeZeitgeber = null;
    var laeuft = false;

    // ---------------------------------------------------------------
    // Speicher
    // ---------------------------------------------------------------

    function laden(feld) {
        try {
            var roh = window.localStorage.getItem(SCHLUESSEL);
            var daten = roh ? JSON.parse(roh) : {};
            return Array.isArray(daten[feld]) ? daten[feld] : [];
        } catch (e) {
            return [];
        }
    }

    function sichern() {
        try {
            window.localStorage.setItem(SCHLUESSEL, JSON.stringify({
                events: ausgang,
                removals: ruecknahmen
            }));
        } catch (e) {
            /* Voller Speicher darf das Erfassen nicht blockieren. */
        }
    }

    function kennung() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        // Ohne sicheren Kontext (http im Schulnetz) gibt es randomUUID nicht.
        var zufall = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(zufall);
        } else {
            for (var i = 0; i < 16; i++) {
                zufall[i] = Math.floor(Math.random() * 256);
            }
        }
        zufall[6] = (zufall[6] & 0x0f) | 0x40;
        zufall[8] = (zufall[8] & 0x3f) | 0x80;

        var hex = [];
        for (var j = 0; j < 16; j++) {
            hex.push((zufall[j] + 0x100).toString(16).substr(1));
        }
        return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-' +
               hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-' +
               hex.slice(10, 16).join('');
    }

    // ---------------------------------------------------------------
    // Stand berechnen: Server plus noch nicht bestaetigte Tipps
    // ---------------------------------------------------------------

    // Was zurueckgenommen wurde, aber schon beim Server lag - damit die
    // Anzeige nicht auf die Bestaetigung warten muss.
    var zurueckgenommen = {};

    function standFuer(studentId) {
        var anzahl = 0;
        var summe = 0;

        if (serverStand[studentId]) {
            anzahl = serverStand[studentId].anzahl;
            summe = serverStand[studentId].summe;
        }

        ruecknahmen.forEach(function (uid) {
            var treffer = zurueckgenommen[uid];
            if (treffer && treffer.student_id === studentId) {
                anzahl -= 1;
                summe -= treffer.weight;
            }
        });

        ausgang.forEach(function (e) {
            if (e.student_id === studentId) {
                anzahl += 1;
                summe += e.weight;
            }
        });

        return { anzahl: Math.max(0, anzahl), summe: Math.max(0, summe) };
    }

    // ---------------------------------------------------------------
    // Anzeige
    // ---------------------------------------------------------------

    function zeichne() {
        var stilleAnzahl = 0;

        document.querySelectorAll('.live-kachel').forEach(function (kachel) {
            var id = parseInt(kachel.dataset.studentId, 10);
            var stand = standFuer(id);
            var zaehler = kachel.querySelector('.live-zaehler');

            zaehler.textContent = stand.anzahl > 0 ? String(stand.anzahl) : '';
            kachel.classList.toggle('live-kachel--still', stand.anzahl === 0);
            kachel.setAttribute('aria-label',
                kachel.dataset.name + ': ' + stand.anzahl + ' Beiträge in dieser Stunde');

            if (stand.anzahl === 0) {
                stilleAnzahl++;
            }
        });

        var still = document.getElementById('live-still');
        if (still) {
            still.textContent = stilleAnzahl === 0
                ? 'Alle waren dran.'
                : stilleAnzahl + (stilleAnzahl === 1 ? ' war noch nicht dran.' : ' waren noch nicht dran.');
        }

        var offen = ausgang.length + ruecknahmen.length;
        var hinweis = document.getElementById('live-ausstehend');
        if (hinweis) {
            if (offen === 0) {
                hinweis.textContent = navigator.onLine ? 'Gespeichert' : 'Offline';
                hinweis.className = 'live-status' + (navigator.onLine ? '' : ' live-status--offline');
            } else {
                hinweis.textContent = offen + ' offen';
                hinweis.className = 'live-status live-status--offen';
            }
        }

        var zuruecknehmen = document.getElementById('live-undo');
        if (zuruecknehmen) {
            zuruecknehmen.disabled = letzte.length === 0;
        }
    }

    // ---------------------------------------------------------------
    // Erfassen
    // ---------------------------------------------------------------

    var letzte = [];

    function erfasse(studentId, gewicht, art, notiz) {
        var eintrag = {
            uid: kennung(),
            student_id: studentId,
            weight: gewicht || 2,
            kind: art || 'freiwillig',
            note: notiz || null,
            at: new Date().toISOString().slice(0, 19).replace('T', ' ')
        };

        ausgang.push(eintrag);
        letzte.push(eintrag);
        sichern();
        zeichne();
        planeSenden();
    }

    function nimmZurueck(studentId) {
        // Zuerst aus dem Ausgang - was noch nicht weg ist, muss nicht
        // geloescht werden.
        for (var i = ausgang.length - 1; i >= 0; i--) {
            if (studentId === null || ausgang[i].student_id === studentId) {
                var raus = ausgang.splice(i, 1)[0];
                letzte = letzte.filter(function (e) { return e.uid !== raus.uid; });
                sichern();
                zeichne();
                return true;
            }
        }

        // Sonst den letzten bestaetigten Beitrag dieser Person.
        for (var j = letzte.length - 1; j >= 0; j--) {
            if (studentId === null || letzte[j].student_id === studentId) {
                var eintrag = letzte.splice(j, 1)[0];
                zurueckgenommen[eintrag.uid] = eintrag;
                ruecknahmen.push(eintrag.uid);
                sichern();
                zeichne();
                planeSenden();
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------
    // Senden
    // ---------------------------------------------------------------

    function planeSenden() {
        window.clearTimeout(sendeZeitgeber);
        sendeZeitgeber = window.setTimeout(sende, WARTEZEIT);
    }

    function sende() {
        if (laeuft) {
            planeSenden();
            return;
        }

        if (ausgang.length === 0 && ruecknahmen.length === 0 && !themaGeaendert) {
            return;
        }

        laeuft = true;

        var paket = {
            csrf_token: cfg.csrfToken,
            class_id: cfg.classId,
            fach: cfg.fach,
            period: cfg.period,
            date: cfg.date,
            topic: thema,
            events: ausgang.slice(),
            removals: ruecknahmen.slice()
        };

        fetch('/live_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(paket)
        }).then(function (antwort) {
            return antwort.json().then(function (daten) {
                return { status: antwort.status, daten: daten };
            });
        }).then(function (ergebnis) {
            laeuft = false;

            if (!ergebnis.daten.ok) {
                if (ergebnis.status === 419) {
                    melde('Die Sitzung ist abgelaufen. Bitte die Seite neu laden – die Beiträge bleiben gespeichert.');
                }
                return;
            }

            // Nur das entfernen, was tatsaechlich mitgeschickt wurde.
            var gesendet = {};
            paket.events.forEach(function (e) { gesendet[e.uid] = true; });
            ausgang = ausgang.filter(function (e) { return !gesendet[e.uid]; });

            var erledigt = {};
            paket.removals.forEach(function (uid) { erledigt[uid] = true; });
            ruecknahmen = ruecknahmen.filter(function (uid) { return !erledigt[uid]; });

            serverStand = ergebnis.daten.stand || {};
            zurueckgenommen = {};
            themaGeaendert = false;
            sichern();
            zeichne();
        }).catch(function () {
            // Kein Netz: der Ausgang bleibt, der naechste Versuch kommt.
            laeuft = false;
            zeichne();
        });
    }

    function melde(text) {
        if (window.Swal) {
            window.Swal.fire({ icon: 'warning', title: 'Hinweis', text: text });
        } else {
            window.alert(text);
        }
    }

    // ---------------------------------------------------------------
    // Bedienung
    // ---------------------------------------------------------------

    var haltezeitgeber = null;
    var langGedrueckt = false;
    var startX = 0;

    function binde(kachel) {
        var id = parseInt(kachel.dataset.studentId, 10);

        kachel.addEventListener('click', function () {
            if (langGedrueckt) {
                langGedrueckt = false;
                return;
            }
            erfasse(id, 2, 'freiwillig', null);
        });

        kachel.addEventListener('contextmenu', function (e) {
            e.preventDefault();
            oeffneAuswahl(id, kachel.dataset.name);
        });

        function halten() {
            haltezeitgeber = window.setTimeout(function () {
                langGedrueckt = true;
                oeffneAuswahl(id, kachel.dataset.name);
            }, 500);
        }

        function loslassen() {
            window.clearTimeout(haltezeitgeber);
        }

        kachel.addEventListener('touchstart', function (e) {
            startX = e.touches[0].clientX;
            halten();
        }, { passive: true });

        kachel.addEventListener('touchmove', loslassen, { passive: true });
        kachel.addEventListener('touchcancel', loslassen);
        kachel.addEventListener('mousedown', halten);
        kachel.addEventListener('mouseup', loslassen);
        kachel.addEventListener('mouseleave', loslassen);

        kachel.addEventListener('touchend', function (e) {
            loslassen();
            var ende = e.changedTouches[0].clientX;
            if (startX - ende > 50) {
                // Wischen nach links nimmt den letzten Beitrag zurueck.
                e.preventDefault();
                langGedrueckt = true;
                if (!nimmZurueck(id)) {
                    langGedrueckt = false;
                }
            }
        });
    }

    function oeffneAuswahl(studentId, name) {
        var blende = document.getElementById('live-auswahl');
        if (!blende) {
            return;
        }

        blende.querySelector('.live-auswahl-name').textContent = name;
        blende.dataset.studentId = String(studentId);
        blende.querySelector('#live-notiz').value = '';
        blende.querySelector('#live-aufgerufen').checked = false;
        blende.style.display = 'flex';
    }

    function schliesseAuswahl() {
        var blende = document.getElementById('live-auswahl');
        if (blende) {
            blende.style.display = 'none';
        }
    }

    // ---------------------------------------------------------------
    // Start
    // ---------------------------------------------------------------

    document.querySelectorAll('.live-kachel').forEach(binde);

    var blende = document.getElementById('live-auswahl');
    if (blende) {
        blende.addEventListener('click', function (e) {
            if (e.target === blende) {
                schliesseAuswahl();
            }
        });

        blende.querySelectorAll('[data-gewicht]').forEach(function (knopf) {
            knopf.addEventListener('click', function () {
                var id = parseInt(blende.dataset.studentId, 10);
                var art = blende.querySelector('#live-aufgerufen').checked ? 'aufgerufen' : 'freiwillig';
                var notiz = blende.querySelector('#live-notiz').value.trim();
                erfasse(id, parseInt(knopf.dataset.gewicht, 10), art, notiz || null);
                schliesseAuswahl();
            });
        });

        var zurueckKnopf = blende.querySelector('#live-auswahl-undo');
        if (zurueckKnopf) {
            zurueckKnopf.addEventListener('click', function () {
                nimmZurueck(parseInt(blende.dataset.studentId, 10));
                schliesseAuswahl();
            });
        }

        var abbrechen = blende.querySelector('#live-auswahl-abbrechen');
        if (abbrechen) {
            abbrechen.addEventListener('click', schliesseAuswahl);
        }
    }

    var undo = document.getElementById('live-undo');
    if (undo) {
        undo.addEventListener('click', function () {
            nimmZurueck(null);
        });
    }

    var themenfeld = document.getElementById('live-thema');
    if (themenfeld) {
        thema = themenfeld.value;
        themenfeld.addEventListener('input', function () {
            thema = themenfeld.value;
            themaGeaendert = true;
            planeSenden();
        });
    }

    var abschluss = document.getElementById('live-abschluss');
    if (abschluss) {
        abschluss.addEventListener('click', function (e) {
            e.preventDefault();
            thema = themenfeld ? themenfeld.value : thema;
            themaGeaendert = true;
            sende();
            window.setTimeout(function () {
                window.location.href = '/live_report.php?class_id=' + cfg.classId +
                    '&fach=' + encodeURIComponent(cfg.fach);
            }, 600);
        });
    }

    window.addEventListener('online', sende);
    window.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            sende();
        }
    });
    window.setInterval(function () {
        if (ausgang.length > 0 || ruecknahmen.length > 0) {
            sende();
        }
    }, NACHFASSEN);

    // Was aus einer frueheren Sitzung liegen geblieben ist, sofort abliefern.
    if (ausgang.length > 0 || ruecknahmen.length > 0) {
        sende();
    }

    zeichne();
})();
