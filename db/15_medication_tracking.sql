-- Einnahme-Protokoll, Bestand, Zukäufe
SET NAMES utf8mb4;

ALTER TABLE medications
  ADD COLUMN stock_unit VARCHAR(24) NULL AFTER note_enc,
  ADD COLUMN stock_quantity DECIMAL(10,2) NULL AFTER stock_unit,
  ADD COLUMN stock_warn_at DECIMAL(10,2) NULL AFTER stock_quantity;

-- Wie viele Einheiten ein Plan-Slot verbraucht (z.B. 1 Tablette = 1),
-- getrennt vom Anzeigetext "dose_enc" ("1 Tablette"), damit der Bestand
-- rechnerisch fortgeschrieben werden kann.
ALTER TABLE medication_schedule
  ADD COLUMN dose_qty DECIMAL(10,2) NULL AFTER dose_enc;

CREATE TABLE medication_intakes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  medication_id BIGINT UNSIGNED NOT NULL,
  schedule_id   BIGINT UNSIGNED NULL,     -- NULL bei Bedarf/nachträglich ohne Planbezug
  user_id       BIGINT UNSIGNED NOT NULL,
  taken_at      DATETIME        NOT NULL,
  quantity      DECIMAL(10,2)   NULL,     -- verbrauchte Menge in stock_unit
  dose_enc      VARBINARY(120)  NULL,     -- Anzeigetext, z.B. "1 Tablette"
  note_enc      VARBINARY(255)  NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_intake_user_med (user_id, medication_id, taken_at),
  KEY ix_intake_schedule (schedule_id, taken_at),
  CONSTRAINT fk_intake_med  FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE,
  CONSTRAINT fk_intake_sched FOREIGN KEY (schedule_id) REFERENCES medication_schedule(id) ON DELETE SET NULL,
  CONSTRAINT fk_intake_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medication_restocks (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  medication_id BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  restock_date  DATE            NOT NULL,
  quantity      DECIMAL(10,2)   NOT NULL,
  note_enc      VARBINARY(255)  NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_restock_med (medication_id, restock_date),
  CONSTRAINT fk_restock_med  FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE,
  CONSTRAINT fk_restock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('015_medication_tracking');
