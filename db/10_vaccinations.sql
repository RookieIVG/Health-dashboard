SET NAMES utf8mb4;
CREATE TABLE vaccinations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  given_date    DATE            NOT NULL,
  next_due_date DATE            NULL,
  dose_number   SMALLINT UNSIGNED NULL,

  vaccine_enc   VARBINARY(255)  NOT NULL,
  disease_enc   VARBINARY(255)  NULL,     -- "wogegen", falls vom Impfstoffnamen abweichend
  lot_enc       VARBINARY(120)  NULL,
  location_enc  VARBINARY(255)  NULL,
  doctor_enc    VARBINARY(255)  NULL,
  note_enc      BLOB            NULL,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_vacc_user (user_id, given_date),
  KEY ix_vacc_due (user_id, next_due_date),
  CONSTRAINT fk_vacc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('010_vaccinations');
