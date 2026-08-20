SET NAMES utf8mb4;
CREATE TABLE costs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  category     ENUM('medication','doctor','hospital','therapy','aids','dental','other')
               NOT NULL DEFAULT 'other',
  cost_date    DATE            NOT NULL,
  amount       DECIMAL(10,2)   NOT NULL,
  currency     CHAR(3)         NOT NULL DEFAULT 'EUR',

  reimbursement_status ENUM('none','submitted','partial','full') NOT NULL DEFAULT 'none',
  reimbursed_amount    DECIMAL(10,2) NULL,
  submitted_date        DATE NULL,

  provider_enc     VARBINARY(255) NULL,
  description_enc  VARBINARY(512) NOT NULL,
  note_enc         BLOB NULL,

  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_costs_user_date (user_id, cost_date),
  KEY ix_costs_status (user_id, reimbursement_status),
  CONSTRAINT fk_costs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('011_costs');
