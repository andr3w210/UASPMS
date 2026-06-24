ALTER TABLE legacy_assets
  ADD COLUMN IF NOT EXISTS unit_of_measure_id INT UNSIGNED NULL AFTER quantity,
  ADD INDEX IF NOT EXISTS idx_legacy_assets_unit_of_measure_id (unit_of_measure_id);
