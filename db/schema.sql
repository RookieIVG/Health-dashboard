-- =====================================================================
-- Gesundheitsdashboard – konsolidiertes Datenbankschema
--
-- Ersetzt die 29 einzelnen, nacheinander gewachsenen Migrationsdateien
-- (db/01_core_schema.sql bis db/29_stool_bulky_fatty.sql) durch den
-- tatsächlichen Endzustand in einer Datei – für Neuinstallationen.
-- Ermittelt, indem alle 29 Migrationen der Reihe nach gegen eine echte
-- MySQL/MariaDB-Instanz gefahren und das reale Ergebnis ausgelesen
-- wurde (SHOW CREATE TABLE je Tabelle), nicht von Hand aus den
-- Migrationen zusammengetragen – das wäre bei 29 aufeinander
-- aufbauenden Schritten (Spalten, die später umbenannt, verschoben
-- oder wieder entfernt wurden) zu fehleranfällig gewesen.
--
-- Für ein bestehendes, bereits laufendes System NICHT verwenden – dort
-- weiterhin die einzelnen Migrationen aus db/ Schritt für Schritt
-- einspielen. Diese Datei ist ausschließlich für eine komplett neue,
-- leere Datenbank gedacht.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- --- Benutzerkonten & Sicherheit ---------------------------------------
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `email_enc` varbinary(512) DEFAULT NULL,
  `email_bidx` varbinary(16) DEFAULT NULL,
  `display_name_enc` varbinary(512) DEFAULT NULL,
  `first_name_enc` varbinary(255) DEFAULT NULL,
  `last_name_enc` varbinary(255) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `sex` enum('m','w','d','unknown') NOT NULL DEFAULT 'unknown',
  `dek_wrapped` varbinary(255) NOT NULL,
  `dek_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `status` enum('active','disabled','pending') NOT NULL DEFAULT 'pending',
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_secret_enc` varbinary(255) DEFAULT NULL,
  `totp_confirmed_at` datetime DEFAULT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'Europe/Vienna',
  `locale` varchar(10) NOT NULL DEFAULT 'de-AT',
  `failed_logins` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varbinary(16) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `notify_appt_mail` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `notify_appt_push` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `med_reminder_offset1` smallint(5) unsigned DEFAULT 30,
  `med_reminder_offset2` smallint(5) unsigned DEFAULT 60,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_uuid` (`uuid`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email_bidx` (`email_bidx`),
  KEY `ix_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `sid_hash` char(64) NOT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `mfa_passed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_sid` (`sid_hash`),
  KEY `ix_sessions_user` (`user_id`,`revoked_at`,`expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_settings` (
  `user_id` bigint(20) unsigned NOT NULL,
  `skey` varchar(96) NOT NULL,
  `value_enc` blob DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`skey`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_recovery_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_recovery_user` (`user_id`,`used_at`),
  CONSTRAINT `fk_recovery_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `purpose` enum('password_reset','email_verify','invite') NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tokens_hash` (`token_hash`),
  KEY `ix_tokens_user` (`user_id`,`purpose`),
  CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `totp_used_codes` (
  `user_id` bigint(20) unsigned NOT NULL,
  `time_step` bigint(20) unsigned NOT NULL,
  `used_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`time_step`),
  CONSTRAINT `fk_totpused_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `identifier` varchar(190) NOT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `successful` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_attempts_ident` (`identifier`,`attempted_at`),
  KEY `ix_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_grants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_user_id` bigint(20) unsigned NOT NULL,
  `grantee_user_id` bigint(20) unsigned NOT NULL,
  `scope` varchar(64) NOT NULL DEFAULT '*',
  `permission` enum('read','write','admin') NOT NULL DEFAULT 'read',
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grant` (`owner_user_id`,`grantee_user_id`,`scope`),
  KEY `ix_grant_grantee` (`grantee_user_id`),
  CONSTRAINT `fk_grant_grantee` FOREIGN KEY (`grantee_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grant_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trusted_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_trusted_devices_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_trusted_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `module` varchar(48) DEFAULT NULL,
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `detail_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_audit_user` (`user_id`,`created_at`),
  KEY `ix_audit_action` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Modulübergreifend (Anhänge, Tags, Zeitleiste) ---------------------
CREATE TABLE `attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `module` varchar(48) NOT NULL,
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `filename_enc` varbinary(512) NOT NULL,
  `mime_type` varchar(128) DEFAULT NULL,
  `size_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `storage_key` char(64) NOT NULL,
  `sha256` char(64) DEFAULT NULL,
  `is_encrypted` tinyint(1) NOT NULL DEFAULT 1,
  `enc_format` varchar(16) NOT NULL DEFAULT 'HDF1',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attach_storage` (`storage_key`),
  KEY `ix_attach_ref` (`user_id`,`module`,`ref_id`),
  KEY `ix_attach_unassigned` (`user_id`,`ref_id`,`created_at`),
  CONSTRAINT `fk_attach_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name_enc` varbinary(255) NOT NULL,
  `name_bidx` varbinary(16) NOT NULL,
  `color` char(7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_user_name` (`user_id`,`name_bidx`),
  CONSTRAINT `fk_tags_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `taggables` (
  `tag_id` bigint(20) unsigned NOT NULL,
  `module` varchar(48) NOT NULL,
  `ref_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`tag_id`,`module`,`ref_id`),
  KEY `ix_taggables_ref` (`module`,`ref_id`),
  CONSTRAINT `fk_taggables_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `timeline_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `occurred_at` datetime NOT NULL,
  `occurred_end` datetime DEFAULT NULL,
  `module` varchar(48) NOT NULL,
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(48) NOT NULL DEFAULT 'entry',
  `title_enc` varbinary(512) NOT NULL,
  `summary_enc` blob DEFAULT NULL,
  `severity` tinyint(4) NOT NULL DEFAULT 0,
  `icon` varchar(48) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_timeline_ref` (`module`,`ref_id`,`event_type`),
  KEY `ix_timeline_user_time` (`user_id`,`occurred_at`),
  KEY `ix_timeline_module` (`user_id`,`module`,`occurred_at`),
  KEY `ix_timeline_severity` (`user_id`,`severity`,`occurred_at`),
  CONSTRAINT `fk_timeline_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schema_migrations` (
  `version` varchar(32) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Kontakte (Ärzte, Einrichtungen) -----------------------------------
CREATE TABLE `contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `kind` enum('doctor','clinic','therapist','pharmacy','insurance','radiology','other') NOT NULL DEFAULT 'doctor',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `name_enc` varbinary(512) NOT NULL,
  `specialty_enc` varbinary(512) DEFAULT NULL,
  `parent_contact_id` bigint(20) unsigned DEFAULT NULL,
  `phone_enc` varbinary(255) DEFAULT NULL,
  `email_enc` varbinary(512) DEFAULT NULL,
  `address_enc` blob DEFAULT NULL,
  `note_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_contact_user` (`user_id`,`is_active`),
  KEY `fk_contacts_parent` (`parent_contact_id`),
  CONSTRAINT `fk_contact_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_contacts_parent` FOREIGN KEY (`parent_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Vitalwerte --------------------------------------------------------
CREATE TABLE `vital_metrics` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `mkey` varchar(48) NOT NULL,
  `label` varchar(96) NOT NULL,
  `unit` varchar(24) NOT NULL DEFAULT '',
  `decimals` tinyint(4) NOT NULL DEFAULT 0,
  `has_second` tinyint(1) NOT NULL DEFAULT 0,
  `label_first` varchar(48) DEFAULT NULL,
  `label_second` varchar(48) DEFAULT NULL,
  `ref_low` decimal(10,3) DEFAULT NULL,
  `ref_high` decimal(10,3) DEFAULT NULL,
  `ref_low2` decimal(10,3) DEFAULT NULL,
  `ref_high2` decimal(10,3) DEFAULT NULL,
  `plaus_min` decimal(10,3) DEFAULT NULL,
  `plaus_max` decimal(10,3) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_metric` (`user_id`,`mkey`),
  KEY `ix_metric_active` (`is_active`,`sort_order`),
  CONSTRAINT `fk_metric_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vital_measurements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `metric_id` int(10) unsigned NOT NULL,
  `measured_at` datetime NOT NULL,
  `value` decimal(10,3) NOT NULL,
  `value2` decimal(10,3) DEFAULT NULL,
  `context` varchar(32) NOT NULL DEFAULT '',
  `source` varchar(32) NOT NULL DEFAULT 'manual',
  `note_enc` varbinary(1024) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_vm_user_metric` (`user_id`,`metric_id`,`measured_at`),
  KEY `ix_vm_user_time` (`user_id`,`measured_at`),
  KEY `fk_vm_metric` (`metric_id`),
  CONSTRAINT `fk_vm_metric` FOREIGN KEY (`metric_id`) REFERENCES `vital_metrics` (`id`),
  CONSTRAINT `fk_vm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Tagebücher --------------------------------------------------------
CREATE TABLE `diary_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `tkey` varchar(48) NOT NULL,
  `label` varchar(96) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `info_text` longtext DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dtype` (`user_id`,`tkey`),
  CONSTRAINT `fk_dtype_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `diary_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type_id` int(10) unsigned NOT NULL,
  `fkey` varchar(48) NOT NULL,
  `label` varchar(96) NOT NULL,
  `ftype` enum('scale','number','choice','bool','text','longtext','time','duration') NOT NULL,
  `unit` varchar(24) NOT NULL DEFAULT '',
  `options` text DEFAULT NULL,
  `min_val` decimal(10,3) DEFAULT NULL,
  `max_val` decimal(10,3) DEFAULT NULL,
  `step_val` decimal(10,3) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `hint` varchar(255) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dfield` (`type_id`,`fkey`),
  KEY `ix_dfield_sort` (`type_id`,`sort_order`),
  CONSTRAINT `fk_dfield_type` FOREIGN KEY (`type_id`) REFERENCES `diary_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `diary_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type_id` int(10) unsigned NOT NULL,
  `occurred_at` datetime NOT NULL,
  `note_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_dentry_user_type` (`user_id`,`type_id`,`occurred_at`),
  KEY `ix_dentry_user_time` (`user_id`,`occurred_at`),
  KEY `fk_dentry_type` (`type_id`),
  CONSTRAINT `fk_dentry_type` FOREIGN KEY (`type_id`) REFERENCES `diary_types` (`id`),
  CONSTRAINT `fk_dentry_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `diary_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint(20) unsigned NOT NULL,
  `field_id` int(10) unsigned NOT NULL,
  `value_num` decimal(10,3) DEFAULT NULL,
  `value_key` varchar(48) DEFAULT NULL,
  `value_enc` blob DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dvalue` (`entry_id`,`field_id`),
  KEY `ix_dvalue_field` (`field_id`,`value_num`),
  CONSTRAINT `fk_dvalue_entry` FOREIGN KEY (`entry_id`) REFERENCES `diary_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dvalue_field` FOREIGN KEY (`field_id`) REFERENCES `diary_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Diagnosen ---------------------------------------------------------
CREATE TABLE `diagnoses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `onset_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('suspected','active','chronic','remission','resolved') NOT NULL DEFAULT 'active',
  `severity` tinyint(4) NOT NULL DEFAULT 0,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `title_enc` varbinary(768) NOT NULL,
  `icd_enc` varbinary(255) DEFAULT NULL,
  `icd_bidx` varbinary(16) DEFAULT NULL,
  `doctor_enc` varbinary(512) DEFAULT NULL,
  `note_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_diag_user_status` (`user_id`,`status`,`onset_date`),
  KEY `ix_diag_user_onset` (`user_id`,`onset_date`),
  KEY `ix_diag_icd` (`user_id`,`icd_bidx`),
  CONSTRAINT `fk_diag_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Allergien und Unverträglichkeiten ---------------------------------
CREATE TABLE `allergies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `category` enum('drug','food','environment','insect','contact','other') NOT NULL DEFAULT 'other',
  `kind` enum('allergy','intolerance','suspected') NOT NULL DEFAULT 'allergy',
  `severity` tinyint(4) NOT NULL DEFAULT 1,
  `status` enum('active','resolved') NOT NULL DEFAULT 'active',
  `onset_date` date DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `substance_enc` varbinary(512) NOT NULL,
  `reaction_enc` blob DEFAULT NULL,
  `note_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_allergy_user` (`user_id`,`status`,`severity`),
  CONSTRAINT `fk_allergy_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Befunde -----------------------------------------------------------
CREATE TABLE `findings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `occurred_at` datetime NOT NULL,
  `received_at` date DEFAULT NULL,
  `category` varchar(32) NOT NULL DEFAULT 'other',
  `follow_up_at` date DEFAULT NULL,
  `is_important` tinyint(1) NOT NULL DEFAULT 0,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `title_enc` varbinary(768) NOT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `doctor_enc` varbinary(512) DEFAULT NULL,
  `summary_enc` blob DEFAULT NULL,
  `text_enc` longblob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_find_user_time` (`user_id`,`occurred_at`),
  KEY `ix_find_category` (`user_id`,`category`,`occurred_at`),
  KEY `ix_find_followup` (`user_id`,`follow_up_at`),
  KEY `fk_findings_contact` (`contact_id`),
  CONSTRAINT `fk_find_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_findings_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Labor -------------------------------------------------------------
CREATE TABLE `lab_tests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `tkey` varchar(48) NOT NULL,
  `label` varchar(96) NOT NULL,
  `unit` varchar(24) NOT NULL DEFAULT '',
  `decimals` tinyint(4) NOT NULL DEFAULT 1,
  `ref_low` decimal(12,4) DEFAULT NULL,
  `ref_high` decimal(12,4) DEFAULT NULL,
  `category` varchar(64) NOT NULL DEFAULT '',
  `sort_order` smallint(6) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_labtest` (`user_id`,`tkey`),
  KEY `ix_labtest_sort` (`category`,`sort_order`),
  CONSTRAINT `fk_labtest_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lab_visits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `visit_date` date NOT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `note_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_labvisit_user` (`user_id`,`visit_date`),
  KEY `fk_labvisit_contact` (`contact_id`),
  CONSTRAINT `fk_labvisit_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_labvisit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lab_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `visit_id` bigint(20) unsigned NOT NULL,
  `test_id` int(10) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `value_num` decimal(12,4) DEFAULT NULL,
  `value_text_enc` varbinary(255) DEFAULT NULL,
  `flag` char(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_labresult` (`visit_id`,`test_id`),
  KEY `ix_labresult_test` (`user_id`,`test_id`,`visit_id`),
  KEY `fk_labresult_test` (`test_id`),
  CONSTRAINT `fk_labresult_test` FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`id`),
  CONSTRAINT `fk_labresult_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_labresult_visit` FOREIGN KEY (`visit_id`) REFERENCES `lab_visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Impfpass ----------------------------------------------------------
CREATE TABLE `vaccinations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `given_date` date NOT NULL,
  `next_due_date` date DEFAULT NULL,
  `dose_number` smallint(5) unsigned DEFAULT NULL,
  `vaccine_enc` varbinary(255) NOT NULL,
  `disease_enc` varbinary(255) DEFAULT NULL,
  `lot_enc` varbinary(120) DEFAULT NULL,
  `location_enc` varbinary(255) DEFAULT NULL,
  `doctor_enc` varbinary(255) DEFAULT NULL,
  `note_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_vacc_user` (`user_id`,`given_date`),
  KEY `ix_vacc_due` (`user_id`,`next_due_date`),
  CONSTRAINT `fk_vacc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Termine -----------------------------------------------------------
CREATE TABLE `appointments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `uid` char(36) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime DEFAULT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('planned','done','cancelled') NOT NULL DEFAULT 'planned',
  `reminder_min` int(11) DEFAULT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `title_enc` varbinary(768) NOT NULL,
  `location_enc` varbinary(768) DEFAULT NULL,
  `purpose_enc` blob DEFAULT NULL,
  `prep_enc` blob DEFAULT NULL,
  `result_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appt_uid` (`uid`),
  KEY `ix_appt_user_start` (`user_id`,`starts_at`),
  KEY `ix_appt_contact` (`contact_id`),
  CONSTRAINT `fk_appt_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Kosten und Erstattungen -------------------------------------------
CREATE TABLE `costs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `category` enum('medication','doctor','hospital','therapy','aids','dental','other') NOT NULL DEFAULT 'other',
  `cost_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'EUR',
  `reimbursement_status` enum('none','submitted','partial','full') NOT NULL DEFAULT 'none',
  `reimbursed_amount` decimal(10,2) DEFAULT NULL,
  `submitted_date` date DEFAULT NULL,
  `provider_enc` varbinary(255) DEFAULT NULL,
  `description_enc` varbinary(512) NOT NULL,
  `note_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_costs_user_date` (`user_id`,`cost_date`),
  KEY `ix_costs_status` (`user_id`,`reimbursement_status`),
  CONSTRAINT `fk_costs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Medikation --------------------------------------------------------
CREATE TABLE `medications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `form` enum('tablet','drops','capsule','injection','spray','cream','patch','inhaler','other') NOT NULL DEFAULT 'tablet',
  `status` enum('active','paused','stopped') NOT NULL DEFAULT 'active',
  `is_prn` tinyint(1) NOT NULL DEFAULT 0,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `name_enc` varbinary(512) NOT NULL,
  `strength_enc` varbinary(255) DEFAULT NULL,
  `purpose_enc` varbinary(512) DEFAULT NULL,
  `doctor_enc` varbinary(255) DEFAULT NULL,
  `note_enc` blob DEFAULT NULL,
  `stock_unit` varchar(24) DEFAULT NULL,
  `stock_quantity` decimal(10,2) DEFAULT NULL,
  `stock_warn_at` decimal(10,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_med_user_status` (`user_id`,`status`),
  KEY `ix_med_end` (`user_id`,`end_date`),
  CONSTRAINT `fk_med_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `medication_schedule` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `medication_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `intake_time` time NOT NULL DEFAULT '08:00:00',
  `cycle_type` enum('daily','weekly','interval') NOT NULL DEFAULT 'weekly',
  `weekdays` varchar(7) NOT NULL DEFAULT '1234567',
  `interval_days` smallint(5) unsigned DEFAULT NULL,
  `anchor_date` date DEFAULT NULL,
  `dose_enc` varbinary(120) NOT NULL,
  `dose_qty` decimal(10,2) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  KEY `ix_medsched_med` (`medication_id`),
  KEY `ix_medsched_user` (`user_id`),
  CONSTRAINT `fk_medsched_med` FOREIGN KEY (`medication_id`) REFERENCES `medications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_medsched_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `medication_intakes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `medication_id` bigint(20) unsigned NOT NULL,
  `schedule_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `taken_at` datetime NOT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `dose_enc` varbinary(120) DEFAULT NULL,
  `note_enc` varbinary(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_intake_user_med` (`user_id`,`medication_id`,`taken_at`),
  KEY `ix_intake_schedule` (`schedule_id`,`taken_at`),
  KEY `fk_intake_med` (`medication_id`),
  CONSTRAINT `fk_intake_med` FOREIGN KEY (`medication_id`) REFERENCES `medications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_intake_sched` FOREIGN KEY (`schedule_id`) REFERENCES `medication_schedule` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_intake_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `medication_restocks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `medication_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `restock_date` date NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `note_enc` varbinary(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_restock_med` (`medication_id`,`restock_date`),
  KEY `fk_restock_user` (`user_id`),
  CONSTRAINT `fk_restock_med` FOREIGN KEY (`medication_id`) REFERENCES `medications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_restock_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `medication_push_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint(20) unsigned NOT NULL,
  `minutes_before` smallint(6) NOT NULL DEFAULT 0,
  `user_id` bigint(20) unsigned NOT NULL,
  `sent_date` date NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pushlog_slot_day_offset` (`schedule_id`,`sent_date`,`minutes_before`),
  KEY `fk_pushlog_user` (`user_id`),
  CONSTRAINT `fk_pushlog_sched` FOREIGN KEY (`schedule_id`) REFERENCES `medication_schedule` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pushlog_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Web-Push-Benachrichtigungen ---------------------------------------
CREATE TABLE `push_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `endpoint_enc` blob NOT NULL,
  `p256dh_enc` varbinary(255) NOT NULL,
  `auth_enc` varbinary(255) NOT NULL,
  `device_label` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_user_endpoint` (`user_id`,`endpoint_hash`),
  CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Mitgelieferte Vorlagedaten (user_id IS NULL) – dieselben Werte, die
-- über die 29 Einzelmigrationen hinweg entstanden sind: neun
-- Vitalwert-Messgrößen, fünf Tagebücher samt ihrer 25 Felder
-- (inklusive der ausführlichen Infotexte für Stuhl- und
-- Stimmungstagebuch), 23 Laborparameter.
-- =====================================================================

INSERT INTO `vital_metrics` (`id`, `user_id`, `mkey`, `label`, `unit`, `decimals`, `has_second`, `label_first`, `label_second`, `ref_low`, `ref_high`, `ref_low2`, `ref_high2`, `plaus_min`, `plaus_max`, `sort_order`, `is_active`) VALUES (1,NULL,'blood_pressure','Blutdruck','mmHg',0,1,'systolisch','diastolisch',100.000,135.000,60.000,85.000,50.000,300.000,10,1),
(6,NULL,'glucose','Blutzucker','mg/dL',0,0,NULL,NULL,70.000,140.000,NULL,NULL,20.000,600.000,60,1),
(8,NULL,'peak_flow','Peak Flow','l/min',0,0,NULL,NULL,NULL,NULL,NULL,NULL,50.000,900.000,80,1),
(2,NULL,'pulse','Puls','/min',0,0,NULL,NULL,50.000,100.000,NULL,NULL,20.000,250.000,20,1),
(7,NULL,'resp_rate','Atemfrequenz','/min',0,0,NULL,NULL,12.000,20.000,NULL,NULL,4.000,60.000,70,1),
(5,NULL,'spo2','Sauerstoffsättigung','%',0,0,NULL,NULL,94.000,100.000,NULL,NULL,50.000,100.000,50,1),
(4,NULL,'temperature','Körpertemperatur','°C',1,0,NULL,NULL,36.000,37.500,NULL,NULL,30.000,45.000,40,1),
(9,NULL,'waist','Bauchumfang','cm',1,0,NULL,NULL,NULL,NULL,NULL,NULL,40.000,200.000,90,1),
(3,NULL,'weight','Gewicht','kg',1,0,NULL,NULL,NULL,NULL,NULL,NULL,20.000,300.000,30,1);

INSERT INTO `diary_types` (`id`, `user_id`, `tkey`, `label`, `description`, `info_text`, `sort_order`, `is_active`) VALUES (1,NULL,'stool','Stuhltagebuch','Konsistenz nach Bristol-Skala, Begleiterscheinungen','Wozu das gut ist\n\nÄrztinnen und Ärzte fragen bei Erkrankungen des Verdauungstrakts wie Morbus Crohn, Colitis ulcerosa oder Reizdarm fast immer nach dem Stuhlgang der letzten Zeit – aus dem Gedächtnis lässt sich das über Wochen kaum verlässlich rekonstruieren. Dieses Tagebuch nimmt dir die Erinnerungsarbeit ab.\n\nIn Kombination mit dem Ernährungs- oder Stresstagebuch kann die Auswertung unter \"Tagebücher -> Muster erkennen\" zeigen, ob bestimmte Lebensmittel oder anspannungsreiche Phasen bei dir persönlich mit mehr Beschwerden einhergehen.\n\nKonsistenz (Bristol-Stuhlformen-Skala)\n\nDer Leitwert des Tagebuchs, eine international gebräuchliche, siebenstufige Einteilung:\n– Typ 1, harte Klümpchen: einzelne, harte Klümpchen, schwer auszuscheiden. Deutet auf Verstopfung hin.\n– Typ 2, wurstförmig, klumpig: fest, mit deutlichen Klumpen.\n– Typ 3, wurstförmig mit Rissen: gilt als normal, am festeren Ende.\n– Typ 4, glatt und weich: der \"Idealtyp\".\n– Typ 5, weiche Klumpen: leicht auszuscheiden, tendiert Richtung weich, noch normal.\n– Typ 6, breiig: unregelmäßig, ausgefranst, bereits Richtung Durchfall.\n– Typ 7, flüssig: kompletter Durchfall.\n\nTyp 3 bis 4 gilt als unauffällig. Ein einzelner Ausreißer ist normalerweise kein Grund zur Sorge – interessant wird es bei wiederkehrenden Mustern, etwa gehäuften Typ 6-7 nach bestimmten Mahlzeiten oder in stressigen Phasen.\n\nMenge (wenig/normal/viel)\n\nEine grobe Einschätzung im Vergleich zu deinem eigenen Normalzustand. Ungewöhnliche Mengen, besonders zusammen mit veränderter Konsistenz, können ein zusätzlicher Hinweis sein.\n\nVoluminös / fettig (Fettstuhl)\n\nAnkreuzen, wenn der Stuhl auffallend groß und massig war – weich bis breiig, oft schmierig glänzend, schwer wegzuspülen oder ungewöhnlich übelriechend. Das ist etwas anderes als reine Menge (\"viel\"): hier geht es um die Beschaffenheit, nicht nur um die Quantität. Ein solcher \"Fettstuhl\" deutet auf eine unvollständige Verdauung oder Aufnahme von Nährstoffen, insbesondere Fetten, im Darm hin. Bei Morbus Crohn kann das zum Beispiel bei Befall des unteren Dünndarms (Ileum) vorkommen, wo Gallensäuren und Fette normalerweise aufgenommen werden. Gehäuftes oder neu auftretendes Vorkommen ist ein guter Grund, das ärztlich ansprechen zu lassen.\n\nSchmerzen (0-10)\n\nSchmerzen, die mit dem Stuhlgang selbst zusammenhängen, nicht allgemeine Bauchschmerzen (dafür gibt es das Schmerztagebuch). 0: keine Schmerzen. 1-3: leichtes Ziehen. 4-6: deutlich spürbar. 7-10: starke Schmerzen.\n\nBlut sichtbar\n\nAnkreuzen bei Blut im oder am Stuhl, auf dem Toilettenpapier oder in der Toilette, unabhängig von der Menge. Das ist der wichtigste Einzelwert in diesem Tagebuch: sichtbares Blut ist bei entzündlichen Darmerkrankungen häufig ein Zeichen für Krankheitsaktivität. Neu auftretendes Blut oder eine spürbare Zunahme gehört zeitnah ärztlich besprochen.\n\nSchleim\n\nAnkreuzen bei sichtbarem Schleim im Stuhl. Etwas Schleim kann harmlos sein, vermehrter oder neu auftretender Schleim kann auf eine Entzündung der Darmschleimhaut hindeuten.\n\nDringender Drang\n\nAnkreuzen, wenn du den Stuhlgang kaum aufschieben konntest. Das ist ein anerkanntes Symptom, das auch in ärztlichen Fragebögen zur Krankheitsschwere erhoben wird.\n\nWie oft eintragen?\n\nAm aussagekräftigsten ist ein Eintrag pro Stuhlgang, nicht nur einer pro Tag – gerade die Häufigkeit selbst ist ein wichtiger Wert. Falls das zu viel wird: lieber ein vereinfachter, aber regelmäßiger Eintrag als gar keiner. Blut, ungewöhnlicher Schmerz oder starker Drang sollten aber zuverlässig festgehalten werden.\n\nWichtiger Hinweis\n\nDieses Tagebuch und seine Auswertung ersetzen keine ärztliche Einschätzung. Neu auftretendes Blut, anhaltend starke Schmerzen oder eine deutliche, andauernde Verschlechterung gehören unabhängig vom Tagebuch zeitnah ärztlich abgeklärt.',10,1),
(2,NULL,'nutrition','Ernährungstagebuch','Mahlzeiten und Verträglichkeit',NULL,20,1),
(3,NULL,'mood','Psychische Gesundheit','Stimmung, Energie, Anspannung','Wozu das gut ist\n\nDieses Tagebuch hält fest, wie es dir psychisch geht – Tag für Tag, in ein paar Sekunden ausgefüllt. Allein genommen zeigt es dir über Wochen und Monate, ob es dir insgesamt besser oder schlechter geht und ob es Muster gibt.\n\nDer eigentliche Mehrwert entsteht in Kombination mit deinen anderen Tagebüchern, vor allem dem Stuhltagebuch. Psychischer Stress kann bei vielen Erkrankungen des Verdauungstrakts, etwa Morbus Crohn oder Colitis ulcerosa, Schübe mit auslösen oder verstärken. Unter \"Tagebücher -> Muster erkennen\" lässt sich prüfen, ob bei dir persönlich ein Zusammenhang erkennbar ist.\n\nStimmung (1-10)\n\nDer Leitwert des Tagebuchs. 1-3: es geht dir schlecht. 4-6: ein durchwachsener, normaler Tag. 7-10: es geht dir gut. Wichtig ist nicht die \"richtige\" Kalibrierung, sondern dass du die Skala über die Zeit hinweg ähnlich verwendest.\n\nEnergie (1-10)\n\nWie viel körperliche und geistige Energie dir zur Verfügung steht, unabhängig von der Stimmung. Man kann gut gelaunt, aber erschöpft sein, oder angespannt, aber voller Tatendrang. 1-3: erschöpft, ausgelaugt. 4-6: normales Energielevel. 7-10: voller Energie, tatkräftig.\n\nStress/Anspannung (1-10)\n\nWie angespannt oder unter Druck du dich fühlst, unabhängig von Stimmung und Energie. 1-3: entspannt, ruhig. 4-6: normaler Alltagsstress. 7-10: stark angespannt, überfordert.\n\nWoher Stress kommen kann, ein paar Beispiele:\n– Arbeit: Termindruck, ein schwieriges Gespräch, zu viel gleichzeitig, ein Konflikt mit Kolleginnen oder Kollegen.\n– Trauer und Verlust: der Tod eines nahestehenden Menschen, aber auch kleinere Verluste wie eine Freundschaft, die auseinandergeht.\n– Beziehungen und Familie: Streit mit dem Partner, Sorgen um Kinder oder Eltern, Pflegeverantwortung, Einsamkeit.\n– Gesundheit: eine eigene Diagnose oder die eines nahestehenden Menschen, ein bevorstehender Eingriff, Schlafmangel.\n– Finanzen: Geldsorgen, unerwartete Ausgaben, berufliche Unsicherheit.\n– Alltägliche Reibung: Streit im Straßenverkehr, Zeitdruck, technische Probleme.\n– Auch freudige, aufregende Ereignisse wie eine Hochzeit oder ein neuer Job können Stress auslösen.\n\nDas Feld \"Was war los\"\n\nTrage hier in ein paar Worten ein, was Stimmung, Energie oder Stress geprägt hat. Das hilft dir später beim Rückblick, und wenn du wiederkehrende Auslöser als einheitliche Stichworte einträgst (z.B. immer \"Arbeit stressig\" statt wechselnder Formulierungen), lassen sich diese auch in der Musteranalyse als Tags nutzen.\n\nWie oft eintragen?\n\nEin Eintrag pro Tag reicht meist, am besten zur ähnlichen Tageszeit. Wichtiger als Perfektion ist Regelmäßigkeit.\n\nWichtiger Hinweis\n\nDie Musteranalyse zeigt Auffälligkeiten in deinen eigenen Aufzeichnungen – sie stellt keine Diagnose und ersetzt kein ärztliches Gespräch. Bei wenigen Einträgen kann ein scheinbarer Zusammenhang auch Zufall sein. Ein auffälliges Muster ist ein guter Anlass, es mit deiner Ärztin oder deinem Arzt zu besprechen.',30,1),
(4,NULL,'sleep','Schlaftagebuch','Dauer, Qualität, Unterbrechungen',NULL,40,1),
(5,NULL,'pain','Schmerztagebuch','Ort, Stärke, Charakter',NULL,50,1);

INSERT INTO `lab_tests` (`id`, `user_id`, `tkey`, `label`, `unit`, `decimals`, `ref_low`, `ref_high`, `category`, `sort_order`, `is_active`) VALUES (1,NULL,'hb','Hämoglobin','g/dL',1,13.5000,17.5000,'Blutbild',10,1),
(2,NULL,'hct','Hämatokrit','%',1,40.0000,52.0000,'Blutbild',20,1),
(3,NULL,'leuko','Leukozyten','G/l',1,4.0000,10.0000,'Blutbild',30,1),
(4,NULL,'thrombo','Thrombozyten','G/l',0,150.0000,400.0000,'Blutbild',40,1),
(5,NULL,'krea','Kreatinin','mg/dL',2,0.7000,1.2000,'Niere',50,1),
(6,NULL,'egfr','eGFR','ml/min',0,90.0000,999.0000,'Niere',60,1),
(7,NULL,'harnstoff','Harnstoff','mg/dL',0,17.0000,43.0000,'Niere',70,1),
(8,NULL,'na','Natrium','mmol/l',0,136.0000,145.0000,'Elektrolyte',80,1),
(9,NULL,'k','Kalium','mmol/l',2,3.5000,5.1000,'Elektrolyte',90,1),
(10,NULL,'crp','CRP','mg/dL',2,0.0000,0.5000,'Entzündung',100,1),
(11,NULL,'alt','ALT (GPT)','U/l',0,0.0000,45.0000,'Leber',110,1),
(12,NULL,'ast','AST (GOT)','U/l',0,0.0000,35.0000,'Leber',120,1),
(13,NULL,'ggt','Gamma-GT','U/l',0,0.0000,55.0000,'Leber',130,1),
(14,NULL,'bili','Bilirubin gesamt','mg/dL',2,0.2000,1.2000,'Leber',140,1),
(15,NULL,'chol','Cholesterin gesamt','mg/dL',0,0.0000,200.0000,'Fette',150,1),
(16,NULL,'ldl','LDL-Cholesterin','mg/dL',0,0.0000,130.0000,'Fette',160,1),
(17,NULL,'hdl','HDL-Cholesterin','mg/dL',0,40.0000,999.0000,'Fette',170,1),
(18,NULL,'tg','Triglyceride','mg/dL',0,0.0000,150.0000,'Fette',180,1),
(19,NULL,'hba1c','HbA1c','%',1,4.0000,5.6000,'Zucker',190,1),
(20,NULL,'glukose','Blutzucker nüchtern','mg/dL',0,70.0000,100.0000,'Zucker',200,1),
(21,NULL,'tsh','TSH','mU/l',2,0.4000,4.0000,'Schilddrüse',210,1),
(22,NULL,'vitd','Vitamin D (25-OH)','ng/ml',0,30.0000,100.0000,'Vitamine',220,1),
(23,NULL,'ferritin','Ferritin','ng/ml',0,30.0000,300.0000,'Eisenhaushalt',230,1);

INSERT INTO `diary_fields` (`id`, `type_id`, `fkey`, `label`, `ftype`, `unit`, `options`, `min_val`, `max_val`, `step_val`, `is_required`, `is_primary`, `hint`, `sort_order`, `is_active`) VALUES (1,1,'bristol','Konsistenz (Bristol)','choice','','[{\"k\":\"1\",\"l\":\"1 – harte Klümpchen\"},{\"k\":\"2\",\"l\":\"2 – wurstförmig, klumpig\"},{\"k\":\"3\",\"l\":\"3 – wurstförmig mit Rissen\"},{\"k\":\"4\",\"l\":\"4 – glatt und weich\"},{\"k\":\"5\",\"l\":\"5 – weiche Klumpen\"},{\"k\":\"6\",\"l\":\"6 – breiig\"},{\"k\":\"7\",\"l\":\"7 – flüssig\"}]',1.000,7.000,NULL,1,1,'Typ 3 bis 4 gilt als unauffällig.',10,1),
(2,1,'amount','Menge','choice','','[{\"k\":\"small\",\"l\":\"wenig\"},{\"k\":\"normal\",\"l\":\"normal\"},{\"k\":\"large\",\"l\":\"viel\"}]',NULL,NULL,NULL,0,0,NULL,20,1),
(3,1,'pain','Schmerzen','scale','',NULL,0.000,10.000,NULL,0,0,NULL,30,1),
(4,1,'blood','Blut sichtbar','bool','',NULL,NULL,NULL,NULL,0,0,NULL,40,1),
(5,1,'mucus','Schleim','bool','',NULL,NULL,NULL,NULL,0,0,NULL,50,1),
(6,1,'urgency','Dringender Drang','bool','',NULL,NULL,NULL,NULL,0,0,NULL,60,1),
(7,2,'meal','Mahlzeit','choice','','[{\"k\":\"breakfast\",\"l\":\"Frühstück\"},{\"k\":\"lunch\",\"l\":\"Mittagessen\"},{\"k\":\"dinner\",\"l\":\"Abendessen\"},{\"k\":\"snack\",\"l\":\"Zwischenmahlzeit\"},{\"k\":\"drink\",\"l\":\"Getränk\"}]',NULL,NULL,NULL,1,0,NULL,10,1),
(8,2,'food','Was gegessen','longtext','',NULL,NULL,NULL,NULL,1,0,'Je genauer, desto brauchbarer die spätere Korrelation.',20,1),
(9,2,'tolerance','Verträglichkeit','scale','',NULL,1.000,5.000,NULL,0,1,NULL,30,1),
(10,2,'symptoms','Beschwerden danach','text','',NULL,NULL,NULL,NULL,0,0,NULL,40,1),
(11,3,'mood','Stimmung','scale','',NULL,1.000,10.000,NULL,1,1,'1 sehr schlecht, 10 sehr gut',10,1),
(12,3,'energy','Energie','scale','',NULL,1.000,10.000,NULL,0,0,NULL,20,1),
(13,3,'tension','Stress/Anspannung','scale','',NULL,1.000,10.000,NULL,0,0,NULL,30,1),
(14,3,'context','Was war los','longtext','',NULL,NULL,NULL,NULL,0,0,NULL,40,1),
(15,4,'bedtime','Zu Bett','time','',NULL,NULL,NULL,NULL,0,0,NULL,10,1),
(16,4,'wake','Aufgestanden','time','',NULL,NULL,NULL,NULL,0,0,NULL,20,1),
(17,4,'duration','Schlafdauer','number','h',NULL,0.000,24.000,0.250,0,1,NULL,30,1),
(18,4,'quality','Schlafqualität','scale','',NULL,1.000,5.000,NULL,0,0,NULL,40,1),
(19,4,'awakenings','Aufwachen','number','',NULL,0.000,30.000,NULL,0,0,NULL,50,1),
(20,5,'location','Ort','text','',NULL,NULL,NULL,NULL,1,0,NULL,10,1),
(21,5,'intensity','Stärke','scale','',NULL,0.000,10.000,NULL,1,1,'0 kein Schmerz, 10 stärkster vorstellbarer',20,1),
(22,5,'character','Charakter','choice','','[{\"k\":\"dull\",\"l\":\"dumpf\"},{\"k\":\"sharp\",\"l\":\"stechend\"},{\"k\":\"burning\",\"l\":\"brennend\"},{\"k\":\"pulsing\",\"l\":\"pochend\"},{\"k\":\"cramping\",\"l\":\"krampfartig\"},{\"k\":\"radiating\",\"l\":\"ausstrahlend\"}]',NULL,NULL,NULL,0,0,NULL,30,1),
(23,5,'duration','Dauer','duration','min',NULL,0.000,1440.000,NULL,0,0,NULL,40,1),
(24,5,'medication','Medikament genommen','bool','',NULL,NULL,NULL,NULL,0,0,NULL,50,1),
(25,1,'bulky_fatty','Voluminös / fettig (Fettstuhl)','bool','',NULL,NULL,NULL,NULL,0,0,'Auffällig groß, schmierig-glänzend oder übelriechend - kann auf eine gestörte Fettverdauung hindeuten.',25,1);

-- Markiert diese Installation als auf dem konsolidierten Stand,
-- unabhängig von der alten, granularen 001…029-Nummerierung.
INSERT INTO schema_migrations (version) VALUES ('000_consolidated_baseline');
