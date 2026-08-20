-- =====================================================================
-- Stufe 3b: Befunde / Dokumentenarchiv
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE findings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,

  -- Klartext: hierüber wird sortiert, gefiltert und gruppiert
  occurred_at    DATETIME        NOT NULL,          -- Datum des Befunds
  received_at    DATE            NULL,              -- wann erhalten
  category       VARCHAR(32)     NOT NULL DEFAULT 'other',
  follow_up_at   DATE            NULL,              -- Wiedervorlage
  is_important   TINYINT(1)      NOT NULL DEFAULT 0,
  is_archived    TINYINT(1)      NOT NULL DEFAULT 0,

  -- Verschlüsselt: alles, was inhaltlich etwas verrät
  title_enc      VARBINARY(768)  NOT NULL,
  institution_enc VARBINARY(512) NULL,
  doctor_enc     VARBINARY(512)  NULL,
  summary_enc    BLOB            NULL,
  text_enc       LONGBLOB        NULL,

  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_find_user_time (user_id, occurred_at),
  KEY ix_find_category (user_id, category, occurred_at),
  KEY ix_find_followup (user_id, follow_up_at),
  CONSTRAINT fk_find_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('004_findings');
