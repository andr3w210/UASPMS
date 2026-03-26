USE spamsdb;

CREATE TABLE IF NOT EXISTS property_thresholds (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipment_min   DECIMAL(14,2) NOT NULL DEFAULT 50000.00
    COMMENT 'Items at or above this cost = Equipment (PPE)',
  semi_hv_min     DECIMAL(14,2) NOT NULL DEFAULT 5000.01
    COMMENT 'Items above this cost but below equipment_min = Semi HV',
  -- Items at or below (semi_hv_min - 0.01) = Semi LV
  effective_date  DATE NOT NULL
    COMMENT 'Date this threshold set became effective',
  basis           VARCHAR(255) DEFAULT NULL
    COMMENT 'Legal basis e.g. COA Circular 2022-004',
  created_by      BIGINT UNSIGNED DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_pt_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed current COA 2022-004 thresholds
INSERT INTO property_thresholds
  (equipment_min, semi_hv_min, effective_date, basis)
VALUES
  (50000.00, 5000.01, '2022-05-31',
   'COA Circular No. 2022-004 dated May 31, 2022');
