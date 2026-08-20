-- Medikationsverwaltung
SET NAMES utf8mb4;

CREATE TABLE medications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,

  form         ENUM('tablet','drops','capsule','injection','spray','cream','patch','inhaler','other')
               NOT NULL DEFAULT 'tablet',
  status       ENUM('active','paused','stopped') NOT NULL DEFAULT 'active',
  is_prn       TINYINT(1)      NOT NULL DEFAULT 0,   -- "bei Bedarf", kein fester Plan
  start_date   DATE            NOT NULL,
  end_date     DATE            NULL,

  name_enc     VARBINARY(512)  NOT NULL,
  strength_enc VARBINARY(255)  NULL,
  purpose_enc  VARBINARY(512)  NULL,
  doctor_enc   VARBINARY(255)  NULL,
  note_enc     BLOB            NULL,

  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_med_user_status (user_id, status),
  KEY ix_med_end (user_id, end_date),
  CONSTRAINT fk_med_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Slot im klassischen Blisterschema (morgens/mittags/abends/nachts)
-- statt exakter Uhrzeit – so wird ein Einnahmeplan in der Praxis notiert.
CREATE TABLE medication_schedule (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  medication_id BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  period        ENUM('morning','noon','evening','night') NOT NULL,
  weekdays      VARCHAR(7)      NOT NULL DEFAULT '1234567',  -- ISO-Wochentage, die zutreffen
  dose_enc      VARBINARY(120)  NOT NULL,                    -- z.B. "1 Tablette", "10 Tropfen"
  sort_order    SMALLINT        NOT NULL DEFAULT 100,
  PRIMARY KEY (id),
  KEY ix_medsched_med (medication_id, period),
  KEY ix_medsched_user (user_id),
  CONSTRAINT fk_medsched_med FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE,
  CONSTRAINT fk_medsched_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('013_medication');
