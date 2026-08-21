-- Marker der KI-Auswertung (Bounding Boxes) je Einreichung.
-- Wurde bisher bei jedem Verbindungsaufbau per ALTER TABLE nachgezogen.
ALTER TABLE homework_evaluations ADD COLUMN IF NOT EXISTS error_markers TEXT DEFAULT NULL;
