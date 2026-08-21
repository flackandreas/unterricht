-- Der Abgabe-Token ist der Zugangsschluessel zur eigenen Auswertung und wurde
-- bisher ohne Index gesucht (voller Tabellenscan je Aufruf).
ALTER TABLE homework_submissions ADD UNIQUE INDEX IF NOT EXISTS idx_submission_token (token);
