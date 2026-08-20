-- Labor-Kumulativbefund
SET NAMES utf8mb4;

CREATE TABLE lab_visits (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  visit_date     DATE            NOT NULL,
  institution_enc VARBINARY(512) NULL,
  note_enc       BLOB            NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_labvisit_user (user_id, visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE lab_visits ADD CONSTRAINT fk_labvisit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

CREATE TABLE lab_tests (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,           -- NULL = mitgeliefert
  tkey        VARCHAR(48)     NOT NULL,
  label       VARCHAR(96)     NOT NULL,
  unit        VARCHAR(24)     NOT NULL DEFAULT '',
  decimals    TINYINT         NOT NULL DEFAULT 1,
  ref_low     DECIMAL(12,4)   NULL,
  ref_high    DECIMAL(12,4)   NULL,
  category    VARCHAR(64)     NOT NULL DEFAULT '',
  sort_order  SMALLINT        NOT NULL DEFAULT 100,
  is_active   TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_labtest (user_id, tkey),
  KEY ix_labtest_sort (category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE lab_tests ADD CONSTRAINT fk_labtest_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Zahlenwerte im Klartext (Kurve, Referenzvergleich, Kumulativtabelle
-- unmöglich sonst); qualitative Ergebnisse ("negativ") verschlüsselt.
CREATE TABLE lab_results (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visit_id    BIGINT UNSIGNED NOT NULL,
  test_id     INT UNSIGNED    NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  value_num   DECIMAL(12,4)   NULL,
  value_text_enc VARBINARY(255) NULL,
  flag        CHAR(1)         NULL,           -- 'L','H' oder NULL
  PRIMARY KEY (id),
  UNIQUE KEY uq_labresult (visit_id, test_id),
  KEY ix_labresult_test (user_id, test_id, visit_id),
  CONSTRAINT fk_labresult_visit FOREIGN KEY (visit_id) REFERENCES lab_visits(id) ON DELETE CASCADE,
  CONSTRAINT fk_labresult_test  FOREIGN KEY (test_id)  REFERENCES lab_tests(id),
  CONSTRAINT fk_labresult_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lab_tests (user_id, tkey, label, unit, decimals, ref_low, ref_high, category, sort_order) VALUES
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

INSERT INTO schema_migrations (version) VALUES ('009_lab');
