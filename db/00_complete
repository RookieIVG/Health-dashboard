-- =====================================================================
-- Gesundheitsdashboard – Core-Schema (Stufe 1: Basis)
-- MySQL 8.0 / MariaDB 10.5+  |  utf8mb4 / InnoDB
-- ---------------------------------------------------------------------
-- Konventionen:
--   *_enc   = VARBINARY/BLOB, AES-256-GCM verschlüsselt (siehe Crypto.php)
--   *_bidx  = VARBINARY(16), Blind Index (HMAC-SHA256, gekürzt)
--             -> nur Gleichheitssuche möglich, kein LIKE, kein ORDER BY
--   Klartext bleibt alles, worauf sortiert/gefiltert/aggregiert wird:
--   Zeitstempel, numerische Messwerte, FKs, Kategorien, Skalenwerte.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';   -- alles in UTC speichern, Anzeige in Europe/Vienna

-- ---------------------------------------------------------------------
-- 1. Benutzer
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)        NOT NULL,
  username          VARCHAR(64)     NOT NULL,           -- Login-Handle, Klartext
  password_hash     VARCHAR(255)    NOT NULL,           -- Argon2id
  password_changed_at DATETIME      NULL,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,

  email_enc         VARBINARY(512)  NULL,
  email_bidx        VARBINARY(16)   NULL,               -- eindeutige Suche
  display_name_enc  VARBINARY(512)  NULL,
  birthdate         DATE            NULL,               -- Klartext: für Altersbezug bei Laborwerten
  sex               ENUM('m','w','d','unknown') NOT NULL DEFAULT 'unknown',

  -- Envelope Encryption: der Data Encryption Key des Users,
  -- gewrapped mit dem Master-Key aus app/keys/master.key
  dek_wrapped       VARBINARY(255)  NOT NULL,
  dek_version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,

  role              ENUM('user','admin') NOT NULL DEFAULT 'user',
  status            ENUM('active','disabled','pending') NOT NULL DEFAULT 'pending',

  totp_enabled      TINYINT(1)      NOT NULL DEFAULT 0,
  totp_secret_enc   VARBINARY(255)  NULL,               -- mit User-DEK verschlüsselt
  totp_confirmed_at DATETIME        NULL,

  timezone          VARCHAR(64)     NOT NULL DEFAULT 'Europe/Vienna',
  locale            VARCHAR(10)     NOT NULL DEFAULT 'de-AT',

  failed_logins     INT UNSIGNED    NOT NULL DEFAULT 0,
  locked_until      DATETIME        NULL,
  last_login_at     DATETIME        NULL,
  last_login_ip     VARBINARY(16)   NULL,

  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME        NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_users_uuid (uuid),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email_bidx (email_bidx),
  KEY ix_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wiederherstellungscodes für 2FA (Einmalcodes, gehasht)
CREATE TABLE user_recovery_codes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  code_hash     VARCHAR(255)    NOT NULL,
  used_at       DATETIME        NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_recovery_user (user_id, used_at),
  CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verhindert TOTP-Replay innerhalb des Zeitfensters
CREATE TABLE totp_used_codes (
  user_id     BIGINT UNSIGNED NOT NULL,
  time_step   BIGINT UNSIGNED NOT NULL,
  used_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, time_step),
  CONSTRAINT fk_totpused_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Sessions & Login-Schutz
-- ---------------------------------------------------------------------
CREATE TABLE user_sessions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  sid_hash      CHAR(64)        NOT NULL,       -- sha256(PHP session id)
  ip            VARBINARY(16)   NULL,
  user_agent    VARCHAR(255)    NULL,
  mfa_passed    TINYINT(1)      NOT NULL DEFAULT 0,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME        NOT NULL,
  revoked_at    DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_sid (sid_hash),
  KEY ix_sessions_user (user_id, revoked_at, expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier    VARCHAR(190)    NOT NULL,       -- Username oder 'ip:1.2.3.4'
  ip            VARBINARY(16)   NULL,
  successful    TINYINT(1)      NOT NULL DEFAULT 0,
  attempted_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_attempts_ident (identifier, attempted_at),
  KEY ix_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Passwort-Reset / E-Mail-Verifikation
CREATE TABLE user_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  purpose       ENUM('password_reset','email_verify','invite') NOT NULL,
  token_hash    CHAR(64)        NOT NULL,
  expires_at    DATETIME        NOT NULL,
  used_at       DATETIME        NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY ix_tokens_user (user_id, purpose),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Berechtigungen zwischen Accounts (Kinder, Angehörige)
--    Funktioniert, weil der Server den Master-Key hat und damit
--    den DEK des Owners für einen berechtigten Grantee entpacken kann.
-- ---------------------------------------------------------------------
CREATE TABLE account_grants (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id   BIGINT UNSIGNED NOT NULL,
  grantee_user_id BIGINT UNSIGNED NOT NULL,
  scope           VARCHAR(64)     NOT NULL DEFAULT '*',  -- '*' oder Modulname
  permission      ENUM('read','write','admin') NOT NULL DEFAULT 'read',
  expires_at      DATETIME        NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grant (owner_user_id, grantee_user_id, scope),
  KEY ix_grant_grantee (grantee_user_id),
  CONSTRAINT fk_grant_owner   FOREIGN KEY (owner_user_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_grant_grantee FOREIGN KEY (grantee_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Audit-Log
-- ---------------------------------------------------------------------
CREATE TABLE audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NULL,     -- betroffener Datenbesitzer
  actor_id      BIGINT UNSIGNED NULL,     -- wer hat gehandelt
  action        VARCHAR(64)     NOT NULL, -- login.success, record.update, ...
  module        VARCHAR(48)     NULL,
  ref_id        BIGINT UNSIGNED NULL,
  ip            VARBINARY(16)   NULL,
  user_agent    VARCHAR(255)    NULL,
  detail_enc    BLOB            NULL,     -- JSON, mit Master-Key verschlüsselt
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_user (user_id, created_at),
  KEY ix_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Querschnitt: Timeline
--    Jedes Modul schreibt hier einen Eintrag. Das ist die Achse,
--    an der später alles ausgerichtet wird.
-- ---------------------------------------------------------------------
CREATE TABLE timeline_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  occurred_at   DATETIME        NOT NULL,
  occurred_end  DATETIME        NULL,      -- für Zeiträume (Medikation, Aufenthalt)
  module        VARCHAR(48)     NOT NULL,  -- 'medication','finding','lab','diary',...
  ref_id        BIGINT UNSIGNED NULL,      -- PK im Quellmodul
  event_type    VARCHAR(48)     NOT NULL DEFAULT 'entry',
  title_enc     VARBINARY(512)  NOT NULL,
  summary_enc   BLOB            NULL,
  severity      TINYINT         NOT NULL DEFAULT 0,  -- 0 info … 3 kritisch
  icon          VARCHAR(48)     NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_timeline_user_time (user_id, occurred_at),
  KEY ix_timeline_module (user_id, module, occurred_at),
  UNIQUE KEY uq_timeline_ref (module, ref_id, event_type),
  CONSTRAINT fk_timeline_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Querschnitt: Tags (polymorph)
-- ---------------------------------------------------------------------
CREATE TABLE tags (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  name_enc    VARBINARY(255)  NOT NULL,
  name_bidx   VARBINARY(16)   NOT NULL,
  color       CHAR(7)         NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tag_user_name (user_id, name_bidx),
  CONSTRAINT fk_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE taggables (
  tag_id    BIGINT UNSIGNED NOT NULL,
  module    VARCHAR(48)     NOT NULL,
  ref_id    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (tag_id, module, ref_id),
  KEY ix_taggables_ref (module, ref_id),
  CONSTRAINT fk_taggables_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Querschnitt: Anhänge (polymorph, Dateien liegen verschlüsselt
--    außerhalb des Webroots unter app/storage/)
-- ---------------------------------------------------------------------
CREATE TABLE attachments (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  module        VARCHAR(48)     NOT NULL,
  ref_id        BIGINT UNSIGNED NULL,
  filename_enc  VARBINARY(512)  NOT NULL,
  mime_type     VARCHAR(128)    NULL,
  size_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  storage_key   CHAR(64)        NOT NULL,  -- Zufallsname auf Platte
  sha256        CHAR(64)        NULL,      -- Hash des Klartexts (Dedupe/Integrität)
  is_encrypted  TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attach_storage (storage_key),
  KEY ix_attach_ref (user_id, module, ref_id),
  CONSTRAINT fk_attach_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. Querschnitt: Benutzereinstellungen (Key/Value)
-- ---------------------------------------------------------------------
CREATE TABLE user_settings (
  user_id     BIGINT UNSIGNED NOT NULL,
  skey        VARCHAR(96)     NOT NULL,
  value_enc   BLOB            NULL,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, skey),
  CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. Schema-Version (für spätere Migrationen)
-- ---------------------------------------------------------------------
CREATE TABLE schema_migrations (
  version     VARCHAR(32) NOT NULL,
  applied_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('001_core');
