USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `offices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `office_code` VARCHAR(50) NOT NULL,
  `office_name` VARCHAR(150) NOT NULL,
  `department_id` BIGINT UNSIGNED DEFAULT NULL,
  `office_head_employee_id` BIGINT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_offices_office_code` (`office_code`),
  UNIQUE KEY `uk_offices_office_name` (`office_name`),
  KEY `idx_offices_department_id` (`department_id`),
  KEY `idx_offices_created_by` (`created_by`),
  KEY `idx_offices_updated_by` (`updated_by`),
  KEY `idx_offices_office_head_employee_id` (`office_head_employee_id`),
  CONSTRAINT `fk_offices_department_id`
    FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_offices_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_offices_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `responsibility_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `office_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_responsibility_codes_office_code` (`office_id`, `code`),
  KEY `idx_responsibility_codes_created_by` (`created_by`),
  KEY `idx_responsibility_codes_updated_by` (`updated_by`),
  CONSTRAINT `fk_responsibility_codes_office_id`
    FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_responsibility_codes_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_responsibility_codes_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `office_id` BIGINT UNSIGNED DEFAULT NULL AFTER `department_id`,
  ADD COLUMN IF NOT EXISTS `responsibility_code_id` BIGINT UNSIGNED DEFAULT NULL AFTER `office_id`,
  ADD COLUMN IF NOT EXISTS `is_unit_head` TINYINT(1) NOT NULL DEFAULT 0 AFTER `employment_status`,
  ADD INDEX IF NOT EXISTS `idx_employees_office_id` (`office_id`),
  ADD INDEX IF NOT EXISTS `idx_employees_responsibility_code_id` (`responsibility_code_id`),
  ADD CONSTRAINT `fk_employees_office_id`
    FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  ADD CONSTRAINT `fk_employees_responsibility_code_id`
    FOREIGN KEY (`responsibility_code_id`) REFERENCES `responsibility_codes` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `employee_id` BIGINT UNSIGNED DEFAULT NULL AFTER `role_id`,
  ADD COLUMN IF NOT EXISTS `office_id` BIGINT UNSIGNED DEFAULT NULL AFTER `employee_id`,
  ADD INDEX IF NOT EXISTS `idx_users_employee_id` (`employee_id`),
  ADD INDEX IF NOT EXISTS `idx_users_office_id` (`office_id`),
  ADD CONSTRAINT `fk_users_employee_id`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_office_id`
    FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

ALTER TABLE `offices`
  ADD CONSTRAINT `fk_offices_office_head_employee_id`
    FOREIGN KEY (`office_head_employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;
