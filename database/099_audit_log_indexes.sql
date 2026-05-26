USE `spamsdb`;

ALTER TABLE `audit_logs`
    ADD INDEX IF NOT EXISTS `idx_audit_logs_created_at` (`created_at`),
    ADD INDEX IF NOT EXISTS `idx_audit_logs_action_created_at` (`action`, `created_at`),
    ADD INDEX IF NOT EXISTS `idx_audit_logs_user_created_at` (`user_id`, `created_at`),
    ADD INDEX IF NOT EXISTS `idx_audit_logs_module_created_at` (`module_name`, `created_at`),
    ADD INDEX IF NOT EXISTS `idx_audit_logs_table_record` (`table_name`, `record_id`);
