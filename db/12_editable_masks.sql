-- Bearbeitbarkeit: Sichtbarkeit einzeln je Feld/Metrik/Test.
-- diary_fields hatte bislang kein is_active - vital_metrics und
-- lab_tests haben die Spalte bereits seit ihrer Einführung.
SET NAMES utf8mb4;
ALTER TABLE diary_fields ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order;
INSERT INTO schema_migrations (version) VALUES ('012_editable_masks');
