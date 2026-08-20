-- =====================================================================
-- Stufe 3e: Termine und Kontakte
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE contacts (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  kind           ENUM('doctor','clinic','therapist','pharmacy','insurance','other')
                 NOT NULL DEFAULT 'doctor',
  is_active      TINYINT(1)      NOT NULL DEFAULT 1,

  -- Verschlüsselt: der Name der Fachrichtung verrät die Erkrankung
  name_enc       VARBINARY(512)  NOT NULL,
  specialty_enc  VARBINARY(512)  NULL,
  institution_enc VARBINARY(512) NULL,
  phone_enc      VARBINARY(255)  NULL,
  email_enc      VARBINARY(512)  NULL,
  address_enc    BLOB            NULL,
  note_enc       BLOB            NULL,

  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_contact_user (user_id, is_active),
  CONSTRAINT fk_contact_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  contact_id     BIGINT UNSIGNED NULL,

  uid            CHAR(36)        NOT NULL,   -- stabile Kennung für den Kalender
  starts_at      DATETIME        NOT NULL,
  ends_at        DATETIME        NULL,
  all_day        TINYINT(1)      NOT NULL DEFAULT 0,
  status         ENUM('planned','done','cancelled') NOT NULL DEFAULT 'planned',
  reminder_min   INT             NULL,       -- Vorlaufzeit in Minuten

  title_enc      VARBINARY(768)  NOT NULL,
  location_enc   VARBINARY(768)  NULL,
  purpose_enc    BLOB            NULL,       -- Anlass
  prep_enc       BLOB            NULL,       -- Vorbereitung, Fragen
  result_enc     BLOB            NULL,       -- Ergebnis nach dem Termin

  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appt_uid (uid),
  KEY ix_appt_user_start (user_id, starts_at),
  KEY ix_appt_contact (contact_id),
  CONSTRAINT fk_appt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('007_appointments');
