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
    │   ├── Ai/                 # AIService, Antwortschema, Bildvorbereitung
    │   ├── Homework/           # Repository, SubmissionService, Warteschlange, Gamification
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
    ├── student_homework.php    # Hausaufgaben- & Gamification-Dashboard für Schüler
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

---

## ⚙️ Hintergrundprozesse

### Auswertungs-Worker (erforderlich)

Die KI-Auswertung läuft **asynchron**: Die Abgabe wird sofort quittiert und in
die Warteschlange gestellt, ein Arbeitsprozess erledigt die Auswertung. Ohne
laufenden Worker bleiben Abgaben in der Warteschlange stehen – sie gehen nicht
verloren, werden aber auch nicht ausgewertet.

`docker-compose.yml` startet den Dienst mit. Manuell:

```bash
docker compose exec web php bin/worker.php --once
```

### Löschkonzept (empfohlen als täglicher cron-Aufruf)

Entfernt Hausaufgabenfotos nach Ablauf der je Aufgabe eingestellten Frist
(Vorgabe 90 Tage). Das Feedback bleibt erhalten.

```bash
docker compose exec web php bin/retention.php --apply
```

---

## 🧪 Qualitätssicherung

```bash
docker compose exec web composer test      # PHPUnit
docker compose exec web composer analyse   # PHPStan Level 6
docker compose exec web php tests/compile_templates.php
```

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
