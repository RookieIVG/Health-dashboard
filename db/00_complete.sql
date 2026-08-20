# install.sql

```sql
-- =====================================================================
-- Gesundheitsdashboard – Vollständige Erstinstallation
-- MySQL 8.0 / MariaDB 10.5+
-- utf8mb4 / InnoDB
--
-- Diese Datei erstellt das gesamte Schema im aktuellen Endzustand.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =====================================================================
-- 1. Benutzer
-- =====================================================================

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  password_changed_at DATETIME NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,

  email_enc VARBINARY(512) NULL,
  email_bidx VARBINARY(16) NULL,
  display_name_enc VARBINARY(512) NULL,
  birthdate DATE NULL,
  sex ENUM('m','w','d','unknown') NOT NULL DEFAULT 'unknown',

  dek_wrapped VARBINARY(255) NOT NULL,
  dek_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,

  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  status ENUM('active','disabled','pending') NOT NULL DEFAULT 'pending',

  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  totp_secret_enc VARBINARY(255) NULL,
  totp_confirmed_at DATETIME NULL,

  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Vienna',
  locale VARCHAR(10) NOT NULL DEFAULT 'de-AT',

  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARBINARY(16) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_users_uuid (uuid),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email_bidx (email_bidx),
  KEY ix_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_recovery_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_recovery_user (user_id, used_at),
  CONSTRAINT fk_recovery_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE totp_used_codes (
  user_id BIGINT UNSIGNED NOT NULL,
  time_step BIGINT UNSIGNED NOT NULL,
  used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (user_id, time_step),
  CONSTRAINT fk_totpused_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 2. Sessions & Login-Schutz
-- =====================================================================

CREATE TABLE user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  sid_hash CHAR(64) NOT NULL,
  ip VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  mfa_passed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_sid (sid_hash),
  KEY ix_sessions_user (user_id, revoked_at, expires_at),
  CONSTRAINT fk_sessions_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier VARCHAR(190) NOT NULL,
  ip VARBINARY(16) NULL,
  successful TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_attempts_ident (identifier, attempted_at),
  KEY ix_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('password_reset','email_verify','invite') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY ix_tokens_user (user_id, purpose),
  CONSTRAINT fk_tokens_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 3. Berechtigungen
-- =====================================================================

CREATE TABLE account_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  grantee_user_id BIGINT UNSIGNED NOT NULL,
  scope VARCHAR(64) NOT NULL DEFAULT '*',
  permission ENUM('read','write','admin') NOT NULL DEFAULT 'read',
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_grant (owner_user_id, grantee_user_id, scope),
  KEY ix_grant_grantee (grantee_user_id),
  CONSTRAINT fk_grant_owner
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_grant_grantee
    FOREIGN KEY (grantee_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 4. Audit-Log
-- =====================================================================

CREATE TABLE audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  module VARCHAR(48) NULL,
  ref_id BIGINT UNSIGNED NULL,
  ip VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  detail_enc BLOB NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_audit_user (user_id, created_at),
  KEY ix_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 5. Timeline
-- =====================================================================

CREATE TABLE timeline_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  occurred_at DATETIME NOT NULL,
  occurred_end DATETIME NULL,
  module VARCHAR(48) NOT NULL,
  ref_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(48) NOT NULL DEFAULT 'entry',
  title_enc VARBINARY(512) NOT NULL,
  summary_enc BLOB NULL,
  severity TINYINT NOT NULL DEFAULT 0,
  icon VARCHAR(48) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_timeline_user_time (user_id, occurred_at),
  KEY ix_timeline_module (user_id, module, occurred_at),
  KEY ix_timeline_severity (user_id, severity, occurred_at),
  UNIQUE KEY uq_timeline_ref (module, ref_id, event_type),
  CONSTRAINT fk_timeline_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 6. Tags
-- =====================================================================

CREATE TABLE tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name_enc VARBINARY(255) NOT NULL,
  name_bidx VARBINARY(16) NOT NULL,
  color CHAR(7) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_tag_user_name (user_id, name_bidx),
  CONSTRAINT fk_tags_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE taggables (
  tag_id BIGINT UNSIGNED NOT NULL,
  module VARCHAR(48) NOT NULL,
  ref_id BIGINT UNSIGNED NOT NULL,

  PRIMARY KEY (tag_id, module, ref_id),
  KEY ix_taggables_ref (module, ref_id),
  CONSTRAINT fk_taggables_tag
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 7. Anhänge
-- =====================================================================

CREATE TABLE attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  module VARCHAR(48) NOT NULL,
  ref_id BIGINT UNSIGNED NULL,
  filename_enc VARBINARY(512) NOT NULL,
  mime_type VARCHAR(128) NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  storage_key CHAR(64) NOT NULL,
  sha256 CHAR(64) NULL,
  is_encrypted TINYINT(1) NOT NULL DEFAULT 1,
  enc_format VARCHAR(16) NOT NULL DEFAULT 'HDF1',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_attach_storage (storage_key),
  KEY ix_attach_ref (user_id, module, ref_id),
  KEY ix_attach_unassigned (user_id, ref_id, created_at),
  CONSTRAINT fk_attach_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 8. Benutzereinstellungen
-- =====================================================================

CREATE TABLE user_settings (
  user_id BIGINT UNSIGNED NOT NULL,
  skey VARCHAR(96) NOT NULL,
  value_enc BLOB NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (user_id, skey),
  CONSTRAINT fk_settings_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 9. Schema-Migrationen
-- =====================================================================

CREATE TABLE schema_migrations (
  version VARCHAR(32) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 10. Vitalwerte
-- =====================================================================

CREATE TABLE vital_metrics (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  mkey VARCHAR(48) NOT NULL,
  label VARCHAR(96) NOT NULL,
  unit VARCHAR(24) NOT NULL DEFAULT '',
  decimals TINYINT NOT NULL DEFAULT 0,

  has_second TINYINT(1) NOT NULL DEFAULT 0,
  label_first VARCHAR(48) NULL,
  label_second VARCHAR(48) NULL,

  ref_low DECIMAL(10,3) NULL,
  ref_high DECIMAL(10,3) NULL,
  ref_low2 DECIMAL(10,3) NULL,
  ref_high2 DECIMAL(10,3) NULL,

  plaus_min DECIMAL(10,3) NULL,
  plaus_max DECIMAL(10,3) NULL,

  sort_order SMALLINT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_metric (user_id, mkey),
  KEY ix_metric_active (is_active, sort_order),
  CONSTRAINT fk_metric_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vital_measurements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  metric_id INT UNSIGNED NOT NULL,
  measured_at DATETIME NOT NULL,
  value DECIMAL(10,3) NOT NULL,
  value2 DECIMAL(10,3) NULL,
  context VARCHAR(32) NOT NULL DEFAULT '',
  source VARCHAR(32) NOT NULL DEFAULT 'manual',
  note_enc VARBINARY(1024) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_vm_user_metric (user_id, metric_id, measured_at),
  KEY ix_vm_user_time (user_id, measured_at),
  CONSTRAINT fk_vm_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vm_metric
    FOREIGN KEY (metric_id) REFERENCES vital_metrics(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO vital_metrics
(user_id, mkey, label, unit, decimals, has_second, label_first, label_second,
 ref_low, ref_high, ref_low2, ref_high2, plaus_min, plaus_max, sort_order)
VALUES
(NULL,'blood_pressure','Blutdruck','mmHg',0,1,'systolisch','diastolisch',100,135,60,85,50,300,10),
(NULL,'pulse','Puls','/min',0,0,NULL,NULL,50,100,NULL,NULL,20,250,20),
(NULL,'weight','Gewicht','kg',1,0,NULL,NULL,NULL,NULL,NULL,NULL,20,300,30),
(NULL,'temperature','Körpertemperatur','°C',1,0,NULL,NULL,36.0,37.5,NULL,NULL,30,45,40),
(NULL,'spo2','Sauerstoffsättigung','%',0,0,NULL,NULL,94,100,NULL,NULL,50,100,50),
(NULL,'glucose','Blutzucker','mg/dL',0,0,NULL,NULL,70,140,NULL,NULL,20,600,60),
(NULL,'resp_rate','Atemfrequenz','/min',0,0,NULL,NULL,12,20,NULL,NULL,4,60,70),
(NULL,'peak_flow','Peak Flow','l/min',0,0,NULL,NULL,NULL,NULL,NULL,NULL,50,900,80),
(NULL,'waist','Bauchumfang','cm',1,0,NULL,NULL,NULL,NULL,NULL,NULL,40,200,90);

-- =====================================================================
-- 11. Befunde
-- =====================================================================

CREATE TABLE findings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  occurred_at DATETIME NOT NULL,
  received_at DATE NULL,
  category VARCHAR(32) NOT NULL DEFAULT 'other',
  follow_up_at DATE NULL,
  is_important TINYINT(1) NOT NULL DEFAULT 0,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,

  title_enc VARBINARY(768) NOT NULL,
  institution_enc VARBINARY(512) NULL,
  doctor_enc VARBINARY(512) NULL,
  summary_enc BLOB NULL,
  text_enc LONGBLOB NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_find_user_time (user_id, occurred_at),
  KEY ix_find_category (user_id, category, occurred_at),
  KEY ix_find_followup (user_id, follow_up_at),
  CONSTRAINT fk_find_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 12. Diagnosen
-- =====================================================================

CREATE TABLE diagnoses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  onset_date DATE NOT NULL,
  end_date DATE NULL,
  status ENUM('suspected','active','chronic','remission','resolved') NOT NULL DEFAULT 'active',
  severity TINYINT NOT NULL DEFAULT 0,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,

  title_enc VARBINARY(768) NOT NULL,
  icd_enc VARBINARY(255) NULL,
  icd_bidx VARBINARY(16) NULL,
  doctor_enc VARBINARY(512) NULL,
  note_enc BLOB NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_diag_user_status (user_id, status, onset_date),
  KEY ix_diag_user_onset (user_id, onset_date),
  KEY ix_diag_icd (user_id, icd_bidx),
  CONSTRAINT fk_diag_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 13. Tagebuch
-- =====================================================================

CREATE TABLE diary_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  tkey VARCHAR(48) NOT NULL,
  label VARCHAR(96) NOT NULL,
  description VARCHAR(255) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_dtype (user_id, tkey),
  CONSTRAINT fk_dtype_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE diary_fields (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_id INT UNSIGNED NOT NULL,
  fkey VARCHAR(48) NOT NULL,
  label VARCHAR(96) NOT NULL,
  ftype ENUM('scale','number','choice','bool','text','longtext','time','duration') NOT NULL,
  unit VARCHAR(24) NOT NULL DEFAULT '',
  options TEXT NULL,
  min_val DECIMAL(10,3) NULL,
  max_val DECIMAL(10,3) NULL,
  step_val DECIMAL(10,3) NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  hint VARCHAR(255) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,

  PRIMARY KEY (id),
  UNIQUE KEY uq_dfield (type_id, fkey),
  KEY ix_dfield_sort (type_id, sort_order),
  CONSTRAINT fk_dfield_type
    FOREIGN KEY (type_id) REFERENCES diary_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE diary_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type_id INT UNSIGNED NOT NULL,
  occurred_at DATETIME NOT NULL,
  note_enc BLOB NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_dentry_user_type (user_id, type_id, occurred_at),
  KEY ix_dentry_user_time (user_id, occurred_at),
  CONSTRAINT fk_dentry_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dentry_type
    FOREIGN KEY (type_id) REFERENCES diary_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE diary_values (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id BIGINT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  value_num DECIMAL(10,3) NULL,
  value_key VARCHAR(48) NULL,
  value_enc BLOB NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_dvalue (entry_id, field_id),
  KEY ix_dvalue_field (field_id, value_num),
  CONSTRAINT fk_dvalue_entry
    FOREIGN KEY (entry_id) REFERENCES diary_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_dvalue_field
    FOREIGN KEY (field_id) REFERENCES diary_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO diary_types
(user_id, tkey, label, description, sort_order)
VALUES
(NULL,'stool','Stuhltagebuch','Konsistenz nach Bristol-Skala, Begleiterscheinungen',10),
(NULL,'nutrition','Ernährungstagebuch','Mahlzeiten und Verträglichkeit',20),
(NULL,'mood','Psychische Gesundheit','Stimmung, Energie, Anspannung',30),
(NULL,'sleep','Schlaftagebuch','Dauer, Qualität, Unterbrechungen',40),
(NULL,'pain','Schmerztagebuch','Ort, Stärke, Charakter',50);

-- Stuhl
INSERT INTO diary_fields (type_id,fkey,label,ftype,unit,options,min_val,max_val,is_required,is_primary,hint,sort_order)
SELECT id,'bristol','Konsistenz (Bristol)','choice','',
'[{"k":"1","l":"1 – harte Klümpchen"},{"k":"2","l":"2 – wurstförmig, klumpig"},{"k":"3","l":"3 – wurstförmig mit Rissen"},{"k":"4","l":"4 – glatt und weich"},{"k":"5","l":"5 – weiche Klumpen"},{"k":"6","l":"6 – breiig"},{"k":"7","l":"7 – flüssig"}]',
1,7,1,1,'Typ 3 bis 4 gilt als unauffällig.',10
FROM diary_types WHERE tkey='stool' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,options,sort_order)
SELECT id,'amount','Menge','choice',
'[{"k":"small","l":"wenig"},{"k":"normal","l":"normal"},{"k":"large","l":"viel"}]',20
FROM diary_types WHERE tkey='stool' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,min_val,max_val,sort_order)
SELECT id,'pain','Schmerzen','scale',0,10,30
FROM diary_types WHERE tkey='stool' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,sort_order)
SELECT id,'blood','Blut sichtbar','bool',40
FROM diary_types WHERE tkey='stool' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,sort_order)
SELECT id,'mucus','Schleim','bool',50
FROM diary_types WHERE tkey='stool' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,sort_order)
SELECT id,'urgency','Dringender Drang','bool',60
FROM diary_types WHERE tkey='stool' AND user_id IS NULL;

-- Ernährung
INSERT INTO diary_fields (type_id,fkey,label,ftype,options,is_required,sort_order)
SELECT id,'meal','Mahlzeit','choice',
'[{"k":"breakfast","l":"Frühstück"},{"k":"lunch","l":"Mittagessen"},{"k":"dinner","l":"Abendessen"},{"k":"snack","l":"Zwischenmahlzeit"},{"k":"drink","l":"Getränk"}]',
1,10
FROM diary_types WHERE tkey='nutrition' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,is_required,hint,sort_order)
SELECT id,'food','Was gegessen','longtext',1,
'Je genauer, desto brauchbarer die spätere Korrelation.',20
FROM diary_types WHERE tkey='nutrition' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,min_val,max_val,is_primary,sort_order)
SELECT id,'tolerance','Verträglichkeit','scale',1,5,1,30
FROM diary_types WHERE tkey='nutrition' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,sort_order)
SELECT id,'symptoms','Beschwerden danach','text',40
FROM diary_types WHERE tkey='nutrition' AND user_id IS NULL;

-- Psyche
INSERT INTO diary_fields (type_id,fkey,label,ftype,min_val,max_val,is_required,is_primary,hint,sort_order)
SELECT id,'mood','Stimmung','scale',1,10,1,1,'1 sehr schlecht, 10 sehr gut',10
FROM diary_types WHERE tkey='mood' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,min_val,max_val,sort_order)
SELECT id,'energy','Energie','scale',1,10,20
FROM diary_types WHERE tkey='mood' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,min_val,max_val,sort_order)
SELECT id,'tension','Anspannung','scale',1,10,30
FROM diary_types WHERE tkey='mood' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,sort_order)
SELECT id,'context','Was war los','longtext',40
FROM diary_types WHERE tkey='mood' AND user_id IS NULL;

-- Schlaf
INSERT INTO diary_fields (type_id,fkey,label,ftype,sort_order)
SELECT id,'bedtime','Zu Bett','time',10
FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,sort_order)
SELECT id,'wake','Aufgestanden','time',20
FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,unit,min_val,max_val,step_val,is_primary,sort_order)
SELECT id,'duration','Schlafdauer','number','h',0,24,0.25,1,30
FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,min_val,max_val,sort_order)
SELECT id,'quality','Schlafqualität','scale',1,5,40
FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,min_val,max_val,sort_order)
SELECT id,'awakenings','Aufwachen','number',0,30,50
FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;

-- Schmerz
INSERT INTO diary_fields (type_id,fkey,label,ftype,is_required,sort_order)
SELECT id,'location','Ort','text',1,10
FROM diary_types WHERE tkey='pain' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,min_val,max_val,is_required,is_primary,hint,sort_order)
SELECT id,'intensity','Stärke','scale',0,10,1,1,
'0 kein Schmerz, 10 stärkster vorstellbarer',20
FROM diary_types WHERE tkey='pain' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,options,sort_order)
SELECT id,'character','Charakter','choice',
'[{"k":"dull","l":"dumpf"},{"k":"sharp","l":"stechend"},{"k":"burning","l":"brennend"},{"k":"pulsing","l":"pochend"},{"k":"cramping","l":"krampfartig"},{"k":"radiating","l":"ausstrahlend"}]',30
FROM diary_types WHERE tkey='pain' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,unit,min_val,max_val,sort_order)
SELECT id,'duration','Dauer','duration','min',0,1440,40
FROM diary_types WHERE tkey='pain' AND user_id IS NULL;

INSERT INTO diary_fields (type_id,fkey,label,ftype,sort_order)
SELECT id,'medication','Medikament genommen','bool',50
FROM diary_types WHERE tkey='pain' AND user_id IS NULL;

-- =====================================================================
-- 14. Kontakte & Termine
-- =====================================================================

CREATE TABLE contacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('doctor','clinic','therapist','pharmacy','insurance','other')
    NOT NULL DEFAULT 'doctor',
  is_active TINYINT(1) NOT NULL DEFAULT 1,

  name_enc VARBINARY(512) NOT NULL,
  specialty_enc VARBINARY(512) NULL,
  institution_enc VARBINARY(512) NULL,
  phone_enc VARBINARY(255) NULL,
  email_enc VARBINARY(512) NULL,
  address_enc BLOB NULL,
  note_enc BLOB NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_contact_user (user_id, is_active),
  CONSTRAINT fk_contact_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NULL,
  uid CHAR(36) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('planned','done','cancelled') NOT NULL DEFAULT 'planned',
  reminder_min INT NULL,

  title_enc VARBINARY(768) NOT NULL,
  location_enc VARBINARY(768) NULL,
  purpose_enc BLOB NULL,
  prep_enc BLOB NULL,
  result_enc BLOB NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_appt_uid (uid),
  KEY ix_appt_user_start (user_id, starts_at),
  KEY ix_appt_contact (contact_id),
  CONSTRAINT fk_appt_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_contact
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 15. Allergien
-- =====================================================================

CREATE TABLE allergies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  category ENUM('drug','food','environment','insect','contact','other')
    NOT NULL DEFAULT 'other',
  kind ENUM('allergy','intolerance','suspected')
    NOT NULL DEFAULT 'allergy',
  severity TINYINT NOT NULL DEFAULT 1,
  status ENUM('active','resolved') NOT NULL DEFAULT 'active',
  onset_date DATE NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,

  substance_enc VARBINARY(512) NOT NULL,
  reaction_enc BLOB NULL,
  note_enc BLOB NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_allergy_user (user_id, status, severity),
  CONSTRAINT fk_allergy_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 16. Laborwerte
-- =====================================================================

CREATE TABLE lab_visits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  visit_date DATE NOT NULL,
  institution_enc VARBINARY(512) NULL,
  note_enc BLOB NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_labvisit_user (user_id, visit_date),
  CONSTRAINT fk_labvisit_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lab_tests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  tkey VARCHAR(48) NOT NULL,
  label VARCHAR(96) NOT NULL,
  unit VARCHAR(24) NOT NULL DEFAULT '',
  decimals TINYINT NOT NULL DEFAULT 1,
  ref_low DECIMAL(12,4) NULL,
  ref_high DECIMAL(12,4) NULL,
  category VARCHAR(64) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,

  PRIMARY KEY (id),
  UNIQUE KEY uq_labtest (user_id, tkey),
  KEY ix_labtest_sort (category, sort_order),
  CONSTRAINT fk_labtest_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lab_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visit_id BIGINT UNSIGNED NOT NULL,
  test_id INT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  value_num DECIMAL(12,4) NULL,
  value_text_enc VARBINARY(255) NULL,
  flag CHAR(1) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_labresult (visit_id, test_id),
  KEY ix_labresult_test (user_id, test_id, visit_id),
  CONSTRAINT fk_labresult_visit
    FOREIGN KEY (visit_id) REFERENCES lab_visits(id) ON DELETE CASCADE,
  CONSTRAINT fk_labresult_test
    FOREIGN KEY (test_id) REFERENCES lab_tests(id),
  CONSTRAINT fk_labresult_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lab_tests
(user_id,tkey,label,unit,decimals,ref_low,ref_high,category,sort_order)
VALUES
(NULL,'hb','Hämoglobin','g/dL',1,13.5,17.5,'Blutbild',10),
(NULL,'hct','Hämatokrit','%',1,40,52,'Blutbild',20),
(NULL,'leuko','Leukozyten','G/l',1,4.0,10.0,'Blutbild',30),
(NULL,'thrombo','Thrombozyten','G/l',0,150,400,'Blutbild',40),
(NULL,'krea','Kreatinin','mg/dL',2,0.7,1.2,'Niere',50),
(NULL,'egfr','eGFR','ml/min',0,90,999,'Niere',60),
(NULL,'harnstoff','Harnstoff','mg/dL',0,17,43,'Niere',70),
(NULL,'na','Natrium','mmol/l',0,136,145,'Elektrolyte',80),
(NULL,'k','Kalium','mmol/l',2,3.5,5.1,'Elektrolyte',90),
(NULL,'crp','CRP','mg/dL',2,0,0.5,'Entzündung',100),
(NULL,'alt','ALT (GPT)','U/l',0,0,45,'Leber',110),
(NULL,'ast','AST (GOT)','U/l',0,0,35,'Leber',120),
(NULL,'ggt','Gamma-GT','U/l',0,0,55,'Leber',130),
(NULL,'bili','Bilirubin gesamt','mg/dL',2,0.2,1.2,'Leber',140),
(NULL,'chol','Cholesterin gesamt','mg/dL',0,0,200,'Fette',150),
(NULL,'ldl','LDL-Cholesterin','mg/dL',0,0,130,'Fette',160),
(NULL,'hdl','HDL-Cholesterin','mg/dL',0,40,999,'Fette',170),
(NULL,'tg','Triglyceride','mg/dL',0,0,150,'Fette',180),
(NULL,'hba1c','HbA1c','%',1,4.0,5.6,'Zucker',190),
(NULL,'glukose','Blutzucker nüchtern','mg/dL',0,70,100,'Zucker',200),
(NULL,'tsh','TSH','mU/l',2,0.4,4.0,'Schilddrüse',210),
(NULL,'vitd','Vitamin D (25-OH)','ng/ml',0,30,100,'Vitamine',220),
(NULL,'ferritin','Ferritin','ng/ml',0,30,300,'Eisenhaushalt',230);

-- =====================================================================
-- 17. Impfungen
-- =====================================================================

CREATE TABLE vaccinations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  given_date DATE NOT NULL,
  next_due_date DATE NULL,
  dose_number SMALLINT UNSIGNED NULL,

  vaccine_enc VARBINARY(255) NOT NULL,
  disease_enc VARBINARY(255) NULL,
  lot_enc VARBINARY(120) NULL,
  location_enc VARBINARY(255) NULL,
  doctor_enc VARBINARY(255) NULL,
  note_enc BLOB NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_vacc_user (user_id, given_date),
  KEY ix_vacc_due (user_id, next_due_date),
  CONSTRAINT fk_vacc_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 18. Kosten
-- =====================================================================

CREATE TABLE costs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  category ENUM('medication','doctor','hospital','therapy','aids','dental','other')
    NOT NULL DEFAULT 'other',
  cost_date DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',

  reimbursement_status ENUM('none','submitted','partial','full')
    NOT NULL DEFAULT 'none',
  reimbursed_amount DECIMAL(10,2) NULL,
  submitted_date DATE NULL,

  provider_enc VARBINARY(255) NULL,
  description_enc VARBINARY(512) NOT NULL,
  note_enc BLOB NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_costs_user_date (user_id, cost_date),
  KEY ix_costs_status (user_id, reimbursement_status),
  CONSTRAINT fk_costs_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 19. Medikationen
-- =====================================================================

CREATE TABLE medications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,

  form ENUM('tablet','drops','capsule','injection','spray','cream','patch','inhaler','other')
    NOT NULL DEFAULT 'tablet',
  status ENUM('active','paused','stopped') NOT NULL DEFAULT 'active',
  is_prn TINYINT(1) NOT NULL DEFAULT 0,
  start_date DATE NOT NULL,
  end_date DATE NULL,

  name_enc VARBINARY(512) NOT NULL,
  strength_enc VARBINARY(255) NULL,
  purpose_enc VARBINARY(512) NULL,
  doctor_enc VARBINARY(255) NULL,
  note_enc BLOB NULL,

  stock_unit VARCHAR(24) NULL,
  stock_quantity DECIMAL(10,2) NULL,
  stock_warn_at DECIMAL(10,2) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_med_user_status (user_id, status),
  KEY ix_med_end (user_id, end_date),
  CONSTRAINT fk_med_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medication_schedule (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  medication_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,

  period ENUM('morning','noon','evening','night') NOT NULL,
  cycle_type ENUM('daily','weekly','interval') NOT NULL DEFAULT 'weekly',
  weekdays VARCHAR(7) NOT NULL DEFAULT '1234567',
  interval_days SMALLINT UNSIGNED NULL,
  anchor_date DATE NULL,

  dose_enc VARBINARY(120) NOT NULL,
  dose_qty DECIMAL(10,2) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 100,

  PRIMARY KEY (id),
  KEY ix_medsched_med (medication_id, period),
  KEY ix_medsched_user (user_id),
  CONSTRAINT fk_medsched_med
    FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE,
  CONSTRAINT fk_medsched_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medication_intakes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  medication_id BIGINT UNSIGNED NOT NULL,
  schedule_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  taken_at DATETIME NOT NULL,
  quantity DECIMAL(10,2) NULL,
  dose_enc VARBINARY(120) NULL,
  note_enc VARBINARY(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_intake_user_med (user_id, medication_id, taken_at),
  KEY ix_intake_schedule (schedule_id, taken_at),
  CONSTRAINT fk_intake_med
    FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE,
  CONSTRAINT fk_intake_sched
    FOREIGN KEY (schedule_id) REFERENCES medication_schedule(id) ON DELETE SET NULL,
  CONSTRAINT fk_intake_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medication_restocks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  medication_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  restock_date DATE NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  note_enc VARBINARY(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_restock_med (medication_id, restock_date),
  CONSTRAINT fk_restock_med
    FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE,
  CONSTRAINT fk_restock_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Installation abgeschlossen
-- =====================================================================

INSERT INTO schema_migrations (version)
VALUES ('001_initial');
```
