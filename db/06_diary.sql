-- =====================================================================
-- Stufe 3d: Tagebuch-Framework
-- Ein Modul für Stuhl, Ernährung, Psyche, Schlaf und Schmerz.
-- Neue Tagebücher sind Konfiguration, kein Schemaeingriff.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE diary_types (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,            -- NULL = mitgeliefert
  tkey        VARCHAR(48)     NOT NULL,
  label       VARCHAR(96)     NOT NULL,
  description VARCHAR(255)    NULL,
  sort_order  SMALLINT        NOT NULL DEFAULT 100,
  is_active   TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dtype (user_id, tkey),
  CONSTRAINT fk_dtype_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feldbeschreibungen. Klartext: es sind Formulardefinitionen, keine
-- Messwerte. "Es gibt ein Feld Schmerzstärke" verrät nichts – der
-- eingetragene Wert schon, und der liegt in diary_values.
CREATE TABLE diary_fields (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  type_id     INT UNSIGNED    NOT NULL,
  fkey        VARCHAR(48)     NOT NULL,
  label       VARCHAR(96)     NOT NULL,
  ftype       ENUM('scale','number','choice','bool','text','longtext','time','duration') NOT NULL,
  unit        VARCHAR(24)     NOT NULL DEFAULT '',
  options     TEXT            NULL,            -- JSON: [{"k":"1","l":"..."}]
  min_val     DECIMAL(10,3)   NULL,
  max_val     DECIMAL(10,3)   NULL,
  step_val    DECIMAL(10,3)   NULL,
  is_required TINYINT(1)      NOT NULL DEFAULT 0,
  is_primary  TINYINT(1)      NOT NULL DEFAULT 0,  -- Leitwert für Kurve/Timeline
  hint        VARCHAR(255)    NULL,
  sort_order  SMALLINT        NOT NULL DEFAULT 100,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dfield (type_id, fkey),
  KEY ix_dfield_sort (type_id, sort_order),
  CONSTRAINT fk_dfield_type FOREIGN KEY (type_id) REFERENCES diary_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE diary_entries (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  type_id     INT UNSIGNED    NOT NULL,
  occurred_at DATETIME        NOT NULL,
  note_enc    BLOB            NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_dentry_user_type (user_id, type_id, occurred_at),
  KEY ix_dentry_user_time (user_id, occurred_at),
  CONSTRAINT fk_dentry_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dentry_type FOREIGN KEY (type_id) REFERENCES diary_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Datensatz je ausgefülltem Feld.
-- Zahlen und Auswahlschlüssel im Klartext, damit Verläufe, Mittelwerte
-- und die Korrelationsansicht überhaupt möglich sind. Freitext verschlüsselt.
CREATE TABLE diary_values (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id    BIGINT UNSIGNED NOT NULL,
  field_id    INT UNSIGNED    NOT NULL,
  value_num   DECIMAL(10,3)   NULL,
  value_key   VARCHAR(48)     NULL,
  value_enc   BLOB            NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dvalue (entry_id, field_id),
  KEY ix_dvalue_field (field_id, value_num),
  CONSTRAINT fk_dvalue_entry FOREIGN KEY (entry_id) REFERENCES diary_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_dvalue_field FOREIGN KEY (field_id) REFERENCES diary_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Mitgelieferte Tagebücher
-- =====================================================================
INSERT INTO diary_types (user_id, tkey, label, description, sort_order) VALUES
(NULL,'stool','Stuhltagebuch','Konsistenz nach Bristol-Skala, Begleiterscheinungen',10),
(NULL,'nutrition','Ernährungstagebuch','Mahlzeiten und Verträglichkeit',20),
(NULL,'mood','Psychische Gesundheit','Stimmung, Energie, Anspannung',30),
(NULL,'sleep','Schlaftagebuch','Dauer, Qualität, Unterbrechungen',40),
(NULL,'pain','Schmerztagebuch','Ort, Stärke, Charakter',50);

-- --- Stuhl -----------------------------------------------------------
INSERT INTO diary_fields (type_id, fkey, label, ftype, unit, options, min_val, max_val, is_required, is_primary, hint, sort_order)
SELECT id,'bristol','Konsistenz (Bristol)','choice','',
 '[{"k":"1","l":"1 – harte Klümpchen"},{"k":"2","l":"2 – wurstförmig, klumpig"},{"k":"3","l":"3 – wurstförmig mit Rissen"},{"k":"4","l":"4 – glatt und weich"},{"k":"5","l":"5 – weiche Klumpen"},{"k":"6","l":"6 – breiig"},{"k":"7","l":"7 – flüssig"}]',
 1,7,1,1,'Typ 3 bis 4 gilt als unauffällig.',10 FROM diary_types WHERE tkey='stool' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, options, sort_order)
SELECT id,'amount','Menge','choice','[{"k":"small","l":"wenig"},{"k":"normal","l":"normal"},{"k":"large","l":"viel"}]',20
 FROM diary_types WHERE tkey='stool' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, min_val, max_val, sort_order)
SELECT id,'pain','Schmerzen','scale',0,10,30 FROM diary_types WHERE tkey='stool' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, sort_order)
SELECT id,'blood','Blut sichtbar','bool',40 FROM diary_types WHERE tkey='stool' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, sort_order)
SELECT id,'mucus','Schleim','bool',50 FROM diary_types WHERE tkey='stool' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, sort_order)
SELECT id,'urgency','Dringender Drang','bool',60 FROM diary_types WHERE tkey='stool' AND user_id IS NULL;

-- --- Ernährung -------------------------------------------------------
INSERT INTO diary_fields (type_id, fkey, label, ftype, options, is_required, sort_order)
SELECT id,'meal','Mahlzeit','choice',
 '[{"k":"breakfast","l":"Frühstück"},{"k":"lunch","l":"Mittagessen"},{"k":"dinner","l":"Abendessen"},{"k":"snack","l":"Zwischenmahlzeit"},{"k":"drink","l":"Getränk"}]',
 1,10 FROM diary_types WHERE tkey='nutrition' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, is_required, hint, sort_order)
SELECT id,'food','Was gegessen','longtext',1,'Je genauer, desto brauchbarer die spätere Korrelation.',20
 FROM diary_types WHERE tkey='nutrition' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, min_val, max_val, is_primary, sort_order)
SELECT id,'tolerance','Verträglichkeit','scale',1,5,1,30 FROM diary_types WHERE tkey='nutrition' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, sort_order)
SELECT id,'symptoms','Beschwerden danach','text',40 FROM diary_types WHERE tkey='nutrition' AND user_id IS NULL;

-- --- Psyche ----------------------------------------------------------
INSERT INTO diary_fields (type_id, fkey, label, ftype, min_val, max_val, is_required, is_primary, hint, sort_order)
SELECT id,'mood','Stimmung','scale',1,10,1,1,'1 sehr schlecht, 10 sehr gut',10
 FROM diary_types WHERE tkey='mood' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, min_val, max_val, sort_order)
SELECT id,'energy','Energie','scale',1,10,20 FROM diary_types WHERE tkey='mood' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, min_val, max_val, sort_order)
SELECT id,'tension','Anspannung','scale',1,10,30 FROM diary_types WHERE tkey='mood' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, sort_order)
SELECT id,'context','Was war los','longtext',40 FROM diary_types WHERE tkey='mood' AND user_id IS NULL;

-- --- Schlaf ----------------------------------------------------------
INSERT INTO diary_fields (type_id, fkey, label, ftype, sort_order)
SELECT id,'bedtime','Zu Bett','time',10 FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, sort_order)
SELECT id,'wake','Aufgestanden','time',20 FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, unit, min_val, max_val, step_val, is_primary, sort_order)
SELECT id,'duration','Schlafdauer','number','h',0,24,0.25,1,30 FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, min_val, max_val, sort_order)
SELECT id,'quality','Schlafqualität','scale',1,5,40 FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, min_val, max_val, sort_order)
SELECT id,'awakenings','Aufwachen','number',0,30,50 FROM diary_types WHERE tkey='sleep' AND user_id IS NULL;

-- --- Schmerz ---------------------------------------------------------
INSERT INTO diary_fields (type_id, fkey, label, ftype, is_required, sort_order)
SELECT id,'location','Ort','text',1,10 FROM diary_types WHERE tkey='pain' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, min_val, max_val, is_required, is_primary, hint, sort_order)
SELECT id,'intensity','Stärke','scale',0,10,1,1,'0 kein Schmerz, 10 stärkster vorstellbarer',20
 FROM diary_types WHERE tkey='pain' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, options, sort_order)
SELECT id,'character','Charakter','choice',
 '[{"k":"dull","l":"dumpf"},{"k":"sharp","l":"stechend"},{"k":"burning","l":"brennend"},{"k":"pulsing","l":"pochend"},{"k":"cramping","l":"krampfartig"},{"k":"radiating","l":"ausstrahlend"}]',30
 FROM diary_types WHERE tkey='pain' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, unit, min_val, max_val, sort_order)
SELECT id,'duration','Dauer','duration','min',0,1440,40 FROM diary_types WHERE tkey='pain' AND user_id IS NULL;
INSERT INTO diary_fields (type_id, fkey, label, ftype, sort_order)
SELECT id,'medication','Medikament genommen','bool',50 FROM diary_types WHERE tkey='pain' AND user_id IS NULL;

INSERT INTO schema_migrations (version) VALUES ('006_diary');
