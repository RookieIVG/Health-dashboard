-- =====================================================================
-- Stufe 2: Querschnittsdienste
-- Voraussetzung: 01_core_schema.sql
-- =====================================================================

SET NAMES utf8mb4;

-- Format der Dateiverschlüsselung (aktuell 'HDF1'), damit ein späterer
-- Formatwechsel bestehende Dateien nicht unlesbar macht.
ALTER TABLE attachments
  ADD COLUMN enc_format VARCHAR(16) NOT NULL DEFAULT 'HDF1' AFTER is_encrypted;

-- Anhänge ohne Zuordnung (frisch hochgeladen, noch keinem Datensatz
-- zugewiesen) müssen schnell auffindbar sein.
ALTER TABLE attachments
  ADD KEY ix_attach_unassigned (user_id, ref_id, created_at);

-- Die Timeline wird häufig nach Schweregrad gefiltert ("nur Auffälliges").
ALTER TABLE timeline_events
  ADD KEY ix_timeline_severity (user_id, severity, occurred_at);

INSERT INTO schema_migrations (version) VALUES ('002_stufe2');
