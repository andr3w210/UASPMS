USE `spamsdb`;

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `is_unit_head` TINYINT(1) NOT NULL DEFAULT 0 AFTER `employment_status`;
