-- =====================================================================
-- Stufe 3a: Vitalwerte / Messwerte (generisch)
-- =====================================================================
SET NAMES utf8mb4;

-- Metrik-Definitionen. user_id NULL = mitgeliefert, sonst eigene Metrik.
-- Bewusst im Klartext: hierüber wird gruppiert, sortiert und gefiltert.
-- Die Messwerte selbst sind das Schützenswerte, nicht die Tatsache,
-- dass es eine Metrik "Blutdruck" gibt.
CREATE TABLE vital_metrics (
  id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NULL,
  mkey          VARCHAR(48)     NOT NULL,
  label         VARCHAR(96)     NOT NULL,
  unit          VARCHAR(24)     NOT NULL DEFAULT '',
  decimals      TINYINT         NOT NULL DEFAULT 0,

  -- Zweiter Wert, z. B. diastolischer Blutdruck
  has_second    TINYINT(1)      NOT NULL DEFAULT 0,
  label_first   VARCHAR(48)     NULL,
  label_second  VARCHAR(48)     NULL,

  -- Orientierungsbereich für die Darstellung (keine Diagnose)
  ref_low       DECIMAL(10,3)   NULL,
  ref_high      DECIMAL(10,3)   NULL,
  ref_low2      DECIMAL(10,3)   NULL,
  ref_high2     DECIMAL(10,3)   NULL,

  -- Plausibilitätsgrenzen gegen Tippfehler
  plaus_min     DECIMAL(10,3)   NULL,
  plaus_max     DECIMAL(10,3)   NULL,

  sort_order    SMALLINT        NOT NULL DEFAULT 100,
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_metric (user_id, mkey),
  KEY ix_metric_active (is_active, sort_order),
  CONSTRAINT fk_metric_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messwerte. Zahlen im Klartext – sonst wäre keine Kurve, kein Mittelwert
-- und keine Korrelationsansicht möglich. Notizen sind verschlüsselt.
CREATE TABLE vital_measurements (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  metric_id     INT UNSIGNED    NOT NULL,
  measured_at   DATETIME        NOT NULL,
  value         DECIMAL(10,3)   NOT NULL,
  value2        DECIMAL(10,3)   NULL,
  context       VARCHAR(32)     NOT NULL DEFAULT '',
  source        VARCHAR(32)     NOT NULL DEFAULT 'manual',
  note_enc      VARBINARY(1024) NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_vm_user_metric (user_id, metric_id, measured_at),
  KEY ix_vm_user_time (user_id, measured_at),
  CONSTRAINT fk_vm_user   FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vm_metric FOREIGN KEY (metric_id) REFERENCES vital_metrics(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Mitgelieferte Metriken -----------------------------------------
INSERT INTO vital_metrics
  (user_id, mkey, label, unit, decimals, has_second, label_first, label_second,
   ref_low, ref_high, ref_low2, ref_high2, plaus_min, plaus_max, sort_order) VALUES
(NULL,'blood_pressure','Blutdruck','mmHg',0,1,'systolisch','diastolisch',
   100,135,60,85,50,300,10),
(NULL,'pulse','Puls','/min',0,0,NULL,NULL,           50,100,NULL,NULL,20,250,20),
(NULL,'weight','Gewicht','kg',1,0,NULL,NULL,         NULL,NULL,NULL,NULL,20,300,30),
(NULL,'temperature','Körpertemperatur','°C',1,0,NULL,NULL, 36.0,37.5,NULL,NULL,30,45,40),
(NULL,'spo2','Sauerstoffsättigung','%',0,0,NULL,NULL, 94,100,NULL,NULL,50,100,50),
(NULL,'glucose','Blutzucker','mg/dL',0,0,NULL,NULL,  70,140,NULL,NULL,20,600,60),
(NULL,'resp_rate','Atemfrequenz','/min',0,0,NULL,NULL, 12,20,NULL,NULL,4,60,70),
(NULL,'peak_flow','Peak Flow','l/min',0,0,NULL,NULL, NULL,NULL,NULL,NULL,50,900,80),
(NULL,'waist','Bauchumfang','cm',1,0,NULL,NULL,      NULL,NULL,NULL,NULL,40,200,90);

INSERT INTO schema_migrations (version) VALUES ('003_vitals');
