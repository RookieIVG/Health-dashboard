-- =====================================================================
-- Stufe 3c: Diagnosen
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE diagnoses (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,

  -- Klartext: Status und Zeitraum steuern Sortierung und Filter
  onset_date   DATE            NOT NULL,
  end_date     DATE            NULL,
  status       ENUM('suspected','active','chronic','remission','resolved') NOT NULL DEFAULT 'active',
  severity     TINYINT         NOT NULL DEFAULT 0,   -- 0 unbestimmt … 3 schwer
  is_pinned    TINYINT(1)      NOT NULL DEFAULT 0,   -- im Notfallblatt zeigen

  -- Verschlüsselt: die Diagnose selbst ist der schützenswerte Teil.
  -- Der ICD-Code ebenso – er benennt die Erkrankung eindeutig.
  title_enc    VARBINARY(768)  NOT NULL,
  icd_enc      VARBINARY(255)  NULL,
  icd_bidx     VARBINARY(16)   NULL,   -- exakte Suche trotz Verschlüsselung
  doctor_enc   VARBINARY(512)  NULL,
  note_enc     BLOB            NULL,

  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_diag_user_status (user_id, status, onset_date),
  KEY ix_diag_user_onset (user_id, onset_date),
  KEY ix_diag_icd (user_id, icd_bidx),
  CONSTRAINT fk_diag_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('005_diagnoses');
