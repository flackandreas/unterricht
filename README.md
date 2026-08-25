# Unterrichts- & Hausaufgabenverwaltung (School Efficiency Tool)

Ein modernes, webbasiertes System für Schulen zur Verwaltung von Hausaufgaben, KI-unterstützten Korrekturen, strukturierter Unterrichtsevaluierung und Schüler-Gamification.

---

## 🌟 Hauptfunktionen

### 🔑 Authentifizierung & SSO
- **IServ OIDC Integration**: Nahtloses Single Sign-On (SSO) für Lehrkräfte und Schüler/innen über die schuleigene IServ-Instanz (OpenID Connect).
- **Klassische Anmeldung**: Anmeldefunktion mit Benutzername/E-Mail und Passwort inklusive Passwort-Zusendung und Passwort-Änderung.

### 📚 Hausaufgaben-Management
- **Lehrkräfte-Verwaltung**: Erstellen, Bearbeiten und Archivieren von Hausaufgaben pro Klasse und Fach. Zuordnung von Erwartungshorizonten, Dateianhängen und Abgabefristen.
- **Schüler-Abgaben**: Schülerinnen und Schüler können Textantworten eingeben oder Dateien (Bilder, Dokumente) hochladen.
- **Abgabe-Tracking**: Übersicht über rechtzeitige, verspätete oder ausstehende Abgaben.

### 🤖 KI-unterstützte Korrektur (Google Gemini)
- **Automated Feedback Engine** auf Basis der **Google Gemini API** (`AIService.php`).
- Automatische Vorkorrektur und detaillierte Feedback-Vorschläge für eingereichte Hausaufgaben zur Entlastung von Lehrkräften.

### 👀 Freigabe-Workflow (Human in the Loop)
- **Zwei Modi je Hausaufgabe**: *Übungsmodus* zeigt das Feedback sofort (formativ), *Freigabemodus* legt jede Auswertung zunächst der Lehrkraft vor.
- **Review-Warteschlange** mit Zähler in der Seitenleiste; Freigabe einzeln oder gesammelt.
- **Bewertungsraster**: Kriterien mit Maximalpunkten je Aufgabe. Die KI füllt sie aus, statt eine Gesamtpunktzahl zu schätzen – reproduzierbarer und pro Kompetenz auswertbar.
- **Rückkanal**: Schülerinnen und Schüler können melden, dass ein Feedback nicht stimmt.
- **Wiedervorlage**: begrenzte Anzahl verbesserter Abgaben je Person.
- **Änderungsprotokoll**: wer hat wann welche Bewertung geändert oder freigegeben.

### 🎮 Schüler-Gamification & Dashboard
- **Klassen-Quests & Belohnungen**: Gemeinsamer Fortschrittsbalken für Klassen-Herausforderungen. Lehrkräfte können individuelle Klassen-Belohnungen definieren (z. B. *"5 Min. Musik am Stundenende"*), die bei 100% Abgabe-Quote freigeschaltet werden.
- **Meilenstein-Badges & Effekte**: Dynamische Status-Badges (🛡️ Quest gestartet, ⚔️ Auf dem Vormarsch, 🔥 Endspurt, 🏆 Meisterhaft Vollendet) und feierlicher Konfetti-Effekt beim Erreichen von 100%.
- **Gamification Widgets**: Motivierende Anzeige von **Erfahrungspunkten (XP)** und **Streak-Tagen** (kontinuierliche Abgaben).
- **Personalisierung**: Avatare werden lokal im Browser erzeugt (`public/js/avatar.js`), es verlässt kein Name das Gerät.
- **Serverseitiger Fortschritt**: XP, Level und Streak liegen in der Datenbank statt im localStorage – geräteunabhängig und nicht manipulierbar.

### 📊 Unterrichts-Feedback & Trends
- Anonymes und strukturiertes Feedback von Schüler/innen an Lehrkräfte.
- Grafische Auswertung von Feedback-Trends und Entwicklungen über Zeiträume hinweg.

### 🎓 Unterricht live
Zwei Funktionen im selben blinden Fleck: dem Geschehen in der Stunde selbst.

- **Klassenlisten** (`admin_schueler.php`): Namen aus IServ oder Untis einfügen, eine Zeile je Person. „Mustermann, Max" und „Max Mustermann" ergeben denselben Schlüssel – wer schon abgegeben hat, wird automatisch mit seinem vorhandenen Lernfortschritt verknüpft. Ausscheiden heißt archivieren, nicht löschen. Gespeichert wird erst nach einer Vorschau.
- **Beteiligung erfassen** (`live.php`): ein Tipp je Wortbeitrag, gebaut fürs Handy in der linken Hand. Der Bildschirm rät die laufende Stunde aus der eigenen Gewohnheit, statt sie erfragen zu lassen. Langes Drücken öffnet Gewichtung (kurz / solide / weiterführend) und Notiz, Wischen nach links nimmt zurück. **Funktioniert ohne Netz**: jeder Tipp landet sofort in einem Ausgangskorb und wird nachgeliefert, eine Kennung je Beitrag verhindert doppelte Zählung.
- **Belege statt Note** (`live_report.php`): Anzahl, Mischung der Gewichte, Wochenverlauf und die einzelnen Beiträge mit Notizen. Bewusst **ohne Notenberechnung** – die mündliche Note ist eine pädagogische Ermessensentscheidung; ein Mittelwert aus Strichlisten wäre angreifbarer als ein begründetes Urteil.
- **Wochenhinweis** auf dem Dashboard: wer seit über drei Wochen nicht drangekommen ist. Technisch der billigste Teil, pädagogisch der wertvollste.
- **Vertretungsstunde** (`vertretung.php`): erzeugt aus den zuletzt behandelten Themen der Klasse eine anschlussfähige Stunde – Einstieg, Arbeitsauftrag, Aufgaben **mit ausgeschriebenen Lösungen** (Vertretung ist fast immer fachfremd), Differenzierung, Ablauf, Sicherung. Erst die Freigabe durch einen Menschen erzeugt Zugang und QR-Code fürs Lehrerzimmer; die Mappe öffnet sich ohne Anmeldung und lässt sich als PDF ausdrucken.

**Bewusst nicht vorgesehen:** keine Spalte für Störungen (das machte aus einer Leistungsdokumentation eine Verhaltensakte), keine errechnete Note, keine Schüleransicht der Beteiligungswerte (sichtbare Zähler verwandeln das Unterrichtsgespräch in Punktesammeln).

### ⚙️ Administration & System
- Verwaltung von Lehrkräften, Klassen und Fachzuordnungen.
- Automatisiertes Datenbank-Migrationssystem für reibungslose Updates.

---

## 🛠️ Technologie-Stack

- **Backend**: PHP 8.2
- **Templating Engine**: Twig 3 (`twig/twig`)
- **Datenbank**: MySQL / MariaDB
- **Libraries**:
  - `vlucas/phpdotenv` (Umgebungsvariablen-Management)
  - `guzzlehttp/guzzle` (HTTP Client u.a. für IServ OIDC & Gemini API)
  - `endroid/qr-code` (QR-Codes, lokal erzeugt)
  - `dompdf/dompdf` (PDF-Export der Unterrichtsvorbereitung)
  - `phpmailer/phpmailer` (E-Mail-Versand)
- **Frontend**: Responsive HTML5, Vanilla CSS3, JavaScript – alle Assets lokal gehostet
- **Qualitätssicherung**: PHPUnit, PHPStan (Level 6), GitHub Actions
- **Containerisierung**: Docker & Docker Compose (Apache Webserver mit `mod_rewrite` und DocumentRoot auf `src/public`)

---

## 🚀 Installation & Inbetriebnahme

### Vorbereitung
Stelle sicher, dass **Docker** und **Docker Compose** auf deinem System installiert sind.

### 1. Repository klonen
```bash
git clone <repository-url>
cd unterricht
```

### 2. Umgebungsvariablen konfigurieren
Erstelle die `.env`-Datei im Ordner `src/` (oder nutze `.env.example` als Vorlage):

```bash
cp .env.example src/.env
```

Passe die Werte in `src/.env` an:

```ini
# Datenbank-Konfiguration
DB_HOST=db
DB_USER=root
DB_PASS=dein_passwort
DB_NAME=db_unterricht

# Gemini API-Schlüssel (für KI-Hausaufgabenkorrektur)
GEMINI_API_KEY=dein_gemini_api_key

# IServ OIDC-Konfiguration (für Single Sign-On)
ISERV_HOST=schule.iserv.de
ISERV_CLIENT_ID=deine_client_id
ISERV_CLIENT_SECRET=dein_client_secret
```

### 3. Container starten
Starte die Anwendung mit Docker Compose:

```bash
docker-compose up -d --build
```

Die Anwendung ist anschließend unter **`http://localhost:8889`** erreichbar.

---

## 📁 Projektstruktur

```
unterricht/
├── Dockerfile                  # Apache PHP 8.2 Docker Image Konfiguration
├── docker-compose.yml          # Service Definition (Port 8889)
├── .env.example                # Beispiel-Umgebungsvariablen
├── README.md                   # Projektdokumentation
└── src/                        # Quellcode der Anwendung
    ├── app/                    # Domänenschicht (PSR-4, Namespace App\)
    │   ├── Ai/                 # AIService, Antwortschemata, Bildvorbereitung
    │   ├── Homework/           # Repository, SubmissionService, Warteschlange, Gamification
    │   ├── Live/               # Klassenliste, Stunden, Beteiligung, Auswertung
    │   ├── Substitute/         # Vertretungsstunden: Warteschlange und Ablauf
    │   └── Support/            # Datenbank, Migrationen, Audit, Aufbewahrung
    ├── bin/                    # CLI: migrate, worker, retention, migrate_uploads
    ├── config/                 # Konfigurationsdateien (DB, Mail, Untis)
    ├── includes/               # Prozedurale Helfer (Auth, Storage, Rate-Limit, Request)
    ├── storage/                # Uploads & Cache, außerhalb des DocumentRoot
    ├── tests/                  # PHPUnit-Tests
    ├── public/                 # Document Root (Front Controller index.php, Assets)
    │   ├── css/                # Stylesheets
    │   ├── js/                 # Client-seitige Skripte
    │   └── index.php           # Router & Front Controller
    ├── templates/              # Twig-Templates für Frontend & Admin
    ├── vendor/                 # Composer-Abhängigkeiten
    ├── login_sso.php           # IServ OIDC OAuth2 Handler
    ├── admin_homework.php      # Hausaufgabenverwaltung für Lehrkräfte
    ├── admin_schueler.php      # Klassenlisten pflegen (nur Verwaltung)
    ├── student_homework.php    # Hausaufgaben- & Gamification-Dashboard für Schüler
    ├── live.php                # Beteiligung erfassen (+ live_action.php als Endpunkt)
    ├── live_report.php         # Belegansicht der Beteiligung
    ├── vertretung.php          # Vertretungsstunden anfordern und freigeben
    ├── vertretung_view.php     # Vertretungsmappe per Token, ohne Anmeldung
    └── feedback_trends.php     # Feedback- & Trend-Analysen
```

---

## 🔄 Datenbank-Migrationen

Vorgesehener Weg beim Deployment:

```bash
docker compose exec web php bin/migrate.php
```

Alle Migrationen sind wiederholbar (`IF NOT EXISTS`). Ein Vermerk in
`src/storage/migration-state` hält fest, welcher Satz zuletzt vollständig
durchgelaufen ist – solange er unverändert ist, kostet die Prüfung im Request
keine einzige Datenbankabfrage.

Als Netz läuft ein fehlender Satz weiterhin automatisch beim ersten Request an.
Mit `AUTO_MIGRATE=0` lässt sich das abschalten.

> **Kein Fremdschlüssel auf `teachers`.** In der SchulOS-Installation ist
> `teachers` keine Tabelle, sondern eine View auf `db_feedback.teachers` – die
> Suite teilt sich einen Benutzerbestand. MariaDB lehnt Fremdschlüssel auf
> Views ab (errno 150, „Foreign key constraint is incorrectly formed"). Die
> älteren Tabellen tragen ihre `teachers`-Verweise noch aus der Zeit davor;
> **neue Migrationen dürfen keinen mehr anlegen.** Ein Index leistet für die
> Abfragen dasselbe, die Zuordnung prüft die Anwendung.

---

## ⚙️ Hintergrundprozesse

### Worker (erforderlich)

Der Arbeitsprozess bedient **zwei** Warteschlangen:

1. **KI-Auswertungen** – die Abgabe wird sofort quittiert und eingereiht. Ohne
   laufenden Worker bleiben Abgaben stehen; sie gehen nicht verloren, werden
   aber auch nicht ausgewertet.
2. **Vertretungsstunden** – nachrangig. Eine Schülerin wartet auf ihr Feedback,
   eine Vertretungsmappe wird erst am nächsten Morgen gebraucht.

`docker-compose.yml` startet den Dienst mit. Manuell:

```bash
docker compose exec web php bin/worker.php --once
```

> Der Worker ist ein **Dauerprozess**: nach einer Änderung an `bin/worker.php`
> oder den davon genutzten Klassen muss er neu gestartet werden
> (`docker compose restart worker`), sonst läuft weiter der alte Code.

### Löschkonzept (empfohlen als täglicher cron-Aufruf)

Entfernt Hausaufgabenfotos nach Ablauf der je Aufgabe eingestellten Frist
(Vorgabe 90 Tage; das Feedback bleibt erhalten) und Beteiligungsdaten nach
`PARTICIPATION_RETENTION_DAYS` (Vorgabe 400 Tage).

```bash
docker compose exec web php bin/retention.php --apply
```

Ohne `--apply` wird nur angezeigt, was gelöscht würde.

---

## 🧪 Qualitätssicherung

```bash
docker compose exec web composer test      # PHPUnit
docker compose exec web composer analyse   # PHPStan Level 6
docker compose exec web php tests/compile_templates.php
```

Den Erfassungsschirm ohne Anmeldung bedienen - fuer Aenderungen an `live.js`:

```bash
docker compose exec web php tests/pruefstand_live.php > src/public/_pruefstand.html
```

Danach `http://localhost:8889/_pruefstand.html` oeffnen und die Datei wieder
loeschen. Ueber `window.PRUEFSTAND.offline` laesst sich das Netz abschalten.

GitHub Actions führt zusätzlich einen Migrationslauf gegen ein leeres Schema aus.

---

## 📄 Lizenz & Hinweise

Entwickelt für den Schul- und Unterrichtseinsatz.

---

## 🔐 Sicherheit & Datenschutz

### Ablage hochgeladener Dateien
Hausaufgabenfotos und Kontextdokumente liegen in `src/storage/uploads/` – **ausserhalb**
des DocumentRoot. Ausgeliefert werden sie ausschliesslich über `src/media.php`, das
prüft, wer eine Datei sehen darf:

| Aufruf | Berechtigung |
|---|---|
| `media.php?s=<abgabe-token>` | Schüler-Ansicht der eigenen Abgabe |
| `media.php?sub=<id>` | angemeldete Lehrkraft, nur eigene Hausaufgaben |
| `media.php?ctx=<id>` | angemeldete Lehrkraft, nur eigene Kontextdokumente |

Bestandsdateien aus `public/uploads/` werden einmalig verschoben mit:

```bash
docker compose exec web php bin/migrate_uploads.php --apply
```

### Klassenlisten und Beteiligungsdaten
Mit `students` liegen erstmals Namen Minderjähriger dauerhaft und zentral in
dieser Anwendung. Das gehört ins Verzeichnis von Verarbeitungstätigkeiten und
der Schulleitung vorgelegt, **bevor** die erste echte Liste eingefügt wird.

| Schutz | Umsetzung |
|---|---|
| Zugriff nur auf eigene Klassen | Jede Abfrage in `Roster`, `ParticipationRepository` und den Controllern filtert über `teacher_classes` – in der Abfrage, nicht in der Oberfläche |
| Keine fremden Personen erfassbar | `ParticipationRepository::record()` verwirft jede `student_id`, die nicht zur Klasse der Stunde gehört |
| Pflege nur durch die Verwaltung | `admin_schueler.php` läuft unter `require_admin()`, jede Änderung landet im `audit_log` |
| Nichts im Gerätespeicher | Der Service Worker nimmt `live.php` und `live_report.php` ausdrücklich vom Cache aus; der Offline-Ausgangskorb enthält Kennungen und Gewichte, keine Namen |
| Befristete Aufbewahrung | `PARTICIPATION_RETENTION_DAYS`, umgesetzt von `bin/retention.php` |

Vertretungsmappen sind über einen Token **ohne Anmeldung** erreichbar. Das ist
vertretbar, weil sie keine Schülerdaten tragen – Klasse, Fach, Thema, Aufgaben.
Der Token entsteht erst mit der Freigabe: ein Entwurf, den noch niemand gelesen
hat, bekommt keine Adresse.

Für den Demobetrieb gilt: **keine echte Klassenliste in die Demoinstanz.**

### Keine Drittanbieter im Browser der Schüler
Schriften, QR-Codes, Avatare und alle JavaScript-Bibliotheken werden lokal
ausgeliefert. Es gehen keine IP-Adressen, Namen oder Tokens an externe Dienste.

### Zugang für Lehrkräfte über SSO
`ISERV_TEACHER_GROUPS` legt fest, welche IServ-Gruppen beim ersten SSO-Login
automatisch als Lehrkraft angelegt werden. **Ohne diesen Wert wird niemand
automatisch angelegt** – dann können sich nur bereits eingetragene Lehrkräfte
anmelden. Das ist die sichere Vorgabe.

### Rate-Limiting
Login, Autologin, Hausaufgaben-Abgaben, Unterrichts-Feedback und alle
KI-Aufrufe sind mengenmässig begrenzt (Tabelle `rate_limits`).

### Datenbank-Migrationen
Alle Migrationen sind wiederholbar (`IF NOT EXISTS`) und laufen beim ersten
Request nach einem Update automatisch durch.
