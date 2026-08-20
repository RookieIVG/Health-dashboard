-- =====================================================================
-- Stufe 3f: Allergien und Unverträglichkeiten
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE allergies (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,

  category      ENUM('drug','food','environment','insect','contact','other')
                NOT NULL DEFAULT 'other',
  kind          ENUM('allergy','intolerance','suspected') NOT NULL DEFAULT 'allergy',
  severity      TINYINT         NOT NULL DEFAULT 1,   -- 1 leicht … 3 schwer
  status        ENUM('active','resolved') NOT NULL DEFAULT 'active',
  onset_date    DATE            NULL,
  is_pinned     TINYINT(1)      NOT NULL DEFAULT 0,   -- Notfallblatt

  -- Verschlüsselt: Auslöser und Reaktion sind medizinische Angaben
  substance_enc VARBINARY(512)  NOT NULL,
  reaction_enc  BLOB            NULL,
  note_enc      BLOB            NULL,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_allergy_user (user_id, status, severity),
  CONSTRAINT fk_allergy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('008_allergies');
