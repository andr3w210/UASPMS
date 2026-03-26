-- Migration: add useful_life_years to classifications
ALTER TABLE `classifications`
  ADD COLUMN `useful_life_years` TINYINT UNSIGNED NULL DEFAULT NULL
  AFTER `classification_name`;
