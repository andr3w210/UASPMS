USE `spamsdb`;

ALTER TABLE `audit_logs`
    ADD COLUMN IF NOT EXISTS `route_path` VARCHAR(255) NULL AFTER `ip_address`,
    ADD INDEX IF NOT EXISTS `idx_audit_logs_route_path_created_at` (`route_path`, `created_at`);

UPDATE `audit_logs`
SET `route_path` = JSON_UNQUOTE(JSON_EXTRACT(`new_values`, '$.route'))
WHERE `route_path` IS NULL
  AND `table_name` = 'request_activity'
  AND JSON_VALID(`new_values`)
  AND JSON_EXTRACT(`new_values`, '$.route') IS NOT NULL;
