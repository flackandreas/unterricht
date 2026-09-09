-- Zuordnung Portalkonto -> Lehrkraft, in der eigenen Datenbank.
--
-- Warum eine eigene Tabelle und keine Spalte in teachers?
--
-- teachers ist hier nur eine Sicht auf db_feedback.teachers. Eine mit
-- "SELECT *" angelegte Sicht uebernimmt spaeter hinzugefuegte Spalten NICHT -
-- eine Spalte im Antragssystem waere hier also unsichtbar geblieben, und die
-- Anmeldung waere daran gescheitert. Jedes Modul fuehrt seine Zuordnung
-- deshalb selbst.
--
-- Warum nicht ueber das Kuerzel verknuepfen? Weil Kuerzel an Schulen nach
-- Jahren neu vergeben werden. Wer Konten am Kuerzel festmacht, vererbt den
-- Lernfortschritt der Vorgaengerin an die Nachfolgerin.
CREATE TABLE IF NOT EXISTS portal_konten (
    sso_sub VARCHAR(64) NOT NULL PRIMARY KEY,
    teacher_id INT NOT NULL,
    verknuepft_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ein_portalkonto_je_lehrkraft (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
