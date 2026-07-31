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

### 🎮 Schüler-Gamification & Dashboard
- **Gamification Widgets**: Motivierende Anzeige von **Erfahrungspunkten (XP)**, **Streak-Tagen** (kontinuierliche Abgaben) und **Klassen-Quests**.
- **Klassenfortschritt**: Gemeinsamer Fortschrittsbalken für Klassen-Herausforderungen.
- **Personalisierung**: Avatar-Generierung über Dicebear API.

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
  - `dompdf/dompdf` (PDF-Generierung)
  - `phpmailer/phpmailer` (E-Mail-Versand)
- **Frontend**: Responsive HTML5, Vanilla CSS3, JavaScript, FontAwesome, Dicebear Avatars
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
    ├── config/                 # Konfigurationsdateien (DB, Mail, Untis)
    ├── includes/               # Logik & Services (AIService, Auth, Migrations)
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

Datenbankänderungen werden beim Starten der Anwendung automatisch über den Router (`src/public/index.php` -> `src/includes/migrations.php`) ausgeführt. Neue Tabellen oder Spaltenanpassungen werden dadurch automatisch eingespielt.

---

## 📄 Lizenz & Hinweise

Entwickelt für den Schul- und Unterrichtseinsatz.
