USE `spamsdb`;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `failed_login_attempts` INT NOT NULL DEFAULT 0 AFTER `last_login_at`,
    ADD COLUMN IF NOT EXISTS `locked_until` DATETIME NULL AFTER `failed_login_attempts`;
