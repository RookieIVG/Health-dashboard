-- Zyklus je Einnahmeplan-Slot: täglich, wöchentlich (bestimmte Tage)
-- oder ein Intervall in Tagen (z. B. 14-tägig) ab einem Bezugsdatum.
SET NAMES utf8mb4;

ALTER TABLE medication_schedule
  ADD COLUMN cycle_type ENUM('daily','weekly','interval') NOT NULL DEFAULT 'weekly' AFTER period,
  ADD COLUMN interval_days SMALLINT UNSIGNED NULL AFTER weekdays,
  ADD COLUMN anchor_date DATE NULL AFTER interval_days;

-- Bestehende Zeilen liefen bislang ausschließlich wochentagsbasiert -
-- 'weekly' mit den gespeicherten Wochentagen entspricht exakt dem
-- bisherigen Verhalten, auch wenn dort alle sieben Tage gesetzt sind.
UPDATE medication_schedule SET cycle_type = 'weekly' WHERE cycle_type = 'weekly';

INSERT INTO schema_migrations (version) VALUES ('014_medication_cycle');
