CREATE DATABASE IF NOT EXISTS `spamsdb`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `role_id` BIGINT UNSIGNED DEFAULT NULL,
  `employee_id` BIGINT UNSIGNED DEFAULT NULL,
  `office_id` BIGINT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role_id` (`role_id`),
  KEY `idx_users_employee_id` (`employee_id`),
  KEY `idx_users_office_id` (`office_id`),
  CONSTRAINT `fk_users_role_id`
    FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `departments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_departments_code` (`code`),
  UNIQUE KEY `uk_departments_name` (`name`),
  KEY `idx_departments_created_by` (`created_by`),
  KEY `idx_departments_updated_by` (`updated_by`),
  CONSTRAINT `fk_departments_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_departments_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `employees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_no` VARCHAR(50) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `suffix_name` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `department_id` BIGINT UNSIGNED DEFAULT NULL,
  `office_id` BIGINT UNSIGNED DEFAULT NULL,
  `responsibility_code_id` BIGINT UNSIGNED DEFAULT NULL,
  `position_title` VARCHAR(150) DEFAULT NULL,
  `employment_status` VARCHAR(50) DEFAULT NULL,
  `is_unit_head` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employees_employee_no` (`employee_no`),
  UNIQUE KEY `uk_employees_email` (`email`),
  KEY `idx_employees_department_id` (`department_id`),
  KEY `idx_employees_office_id` (`office_id`),
  KEY `idx_employees_responsibility_code_id` (`responsibility_code_id`),
  KEY `idx_employees_created_by` (`created_by`),
  KEY `idx_employees_updated_by` (`updated_by`),
  CONSTRAINT `fk_employees_department_id`
    FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_employees_office_id`
    FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_employees_responsibility_code_id`
    FOREIGN KEY (`responsibility_code_id`) REFERENCES `responsibility_codes` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_employees_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_employees_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `offices`
  ADD CONSTRAINT `fk_offices_office_head_employee_id`
    FOREIGN KEY (`office_head_employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_employee_id`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_office_id`
    FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_code` VARCHAR(50) NOT NULL,
  `supplier_name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(150) DEFAULT NULL,
  `contact_no` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `tin_no` VARCHAR(50) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_suppliers_supplier_code` (`supplier_code`),
  UNIQUE KEY `uk_suppliers_supplier_name` (`supplier_name`),
  KEY `idx_suppliers_created_by` (`created_by`),
  KEY `idx_suppliers_updated_by` (`updated_by`),
  CONSTRAINT `fk_suppliers_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_suppliers_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `funds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_code` VARCHAR(50) NOT NULL,
  `fund_name` VARCHAR(150) NOT NULL,
  `fund_source` VARCHAR(150) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_funds_fund_code` (`fund_code`),
  UNIQUE KEY `uk_funds_fund_name` (`fund_name`),
  KEY `idx_funds_created_by` (`created_by`),
  KEY `idx_funds_updated_by` (`updated_by`),
  CONSTRAINT `fk_funds_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_funds_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_code` VARCHAR(50) NOT NULL,
  `account_name` VARCHAR(150) NOT NULL,
  `account_group` ENUM('supply', 'asset', 'semi_expendable') NOT NULL DEFAULT 'asset',
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_codes_account_code` (`account_code`),
  UNIQUE KEY `uk_account_codes_account_name` (`account_name`),
  KEY `idx_account_codes_created_by` (`created_by`),
  KEY `idx_account_codes_updated_by` (`updated_by`),
  CONSTRAINT `fk_account_codes_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_account_codes_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_code` VARCHAR(50) NOT NULL,
  `category_name` VARCHAR(150) NOT NULL,
  `category_type` ENUM('supply', 'asset', 'semi_expendable') NOT NULL DEFAULT 'supply',
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_categories_category_code` (`category_code`),
  UNIQUE KEY `uk_categories_category_name` (`category_name`),
  KEY `idx_categories_created_by` (`created_by`),
  KEY `idx_categories_updated_by` (`updated_by`),
  CONSTRAINT `fk_categories_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_categories_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `classifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `classification_code` VARCHAR(50) NOT NULL,
  `classification_name` VARCHAR(150) NOT NULL,
  `classification_group` ENUM('supply', 'asset', 'semi_expendable') NOT NULL DEFAULT 'asset',
  `account_code_id` BIGINT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_classifications_classification_code` (`classification_code`),
  UNIQUE KEY `uk_classifications_classification_name` (`classification_name`),
  KEY `idx_classifications_account_code_id` (`account_code_id`),
  KEY `idx_classifications_created_by` (`created_by`),
  KEY `idx_classifications_updated_by` (`updated_by`),
  CONSTRAINT `fk_classifications_account_code_id`
    FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_classifications_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_classifications_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `unit_of_measures` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uom_code` VARCHAR(50) NOT NULL,
  `uom_name` VARCHAR(100) NOT NULL,
  `abbreviation` VARCHAR(20) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_unit_of_measures_uom_code` (`uom_code`),
  UNIQUE KEY `uk_unit_of_measures_uom_name` (`uom_name`),
  UNIQUE KEY `uk_unit_of_measures_abbreviation` (`abbreviation`),
  KEY `idx_unit_of_measures_created_by` (`created_by`),
  KEY `idx_unit_of_measures_updated_by` (`updated_by`),
  CONSTRAINT `fk_unit_of_measures_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_unit_of_measures_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `brands` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_code` VARCHAR(30) NOT NULL,
  `brand_name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_brands_brand_code` (`brand_code`),
  UNIQUE KEY `uk_brands_brand_name` (`brand_name`),
  KEY `idx_brands_created_by` (`created_by`),
  KEY `idx_brands_updated_by` (`updated_by`),
  CONSTRAINT `fk_brands_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_brands_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `models` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` BIGINT UNSIGNED NOT NULL,
  `model_code` VARCHAR(30) NOT NULL,
  `model_name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_models_model_code` (`model_code`),
  UNIQUE KEY `uk_models_brand_model_name` (`brand_id`, `model_name`),
  KEY `idx_models_brand_id` (`brand_id`),
  KEY `idx_models_created_by` (`created_by`),
  KEY `idx_models_updated_by` (`updated_by`),
  CONSTRAINT `fk_models_brand_id`
    FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_models_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_models_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mode_of_procurements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mode_code` VARCHAR(50) NOT NULL,
  `mode_name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mode_of_procurements_mode_code` (`mode_code`),
  UNIQUE KEY `uk_mode_of_procurements_mode_name` (`mode_name`),
  KEY `idx_mode_of_procurements_created_by` (`created_by`),
  KEY `idx_mode_of_procurements_updated_by` (`updated_by`),
  CONSTRAINT `fk_mode_of_procurements_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_mode_of_procurements_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `po_number` VARCHAR(100) NOT NULL,
  `po_date` DATE NOT NULL,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `fund_id` BIGINT UNSIGNED NOT NULL,
  `supplier_address` VARCHAR(255) DEFAULT NULL,
  `mode_of_procurement_id` BIGINT UNSIGNED NOT NULL,
  `place_of_delivery` VARCHAR(255) NOT NULL DEFAULT 'University of Antique',
  `delivery_term_days` INT UNSIGNED DEFAULT NULL,
  `expected_delivery_date` DATE DEFAULT NULL,
  `status` ENUM('encoded', 'verified', 'cancelled') NOT NULL DEFAULT 'encoded',
  `purpose` TEXT DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_orders_system_reference` (`system_reference`),
  UNIQUE KEY `uk_purchase_orders_po_number` (`po_number`),
  KEY `idx_purchase_orders_supplier_id` (`supplier_id`),
  KEY `idx_purchase_orders_fund_id` (`fund_id`),
  KEY `idx_purchase_orders_mode_of_procurement_id` (`mode_of_procurement_id`),
  KEY `idx_purchase_orders_created_by` (`created_by`),
  KEY `idx_purchase_orders_updated_by` (`updated_by`),
  CONSTRAINT `fk_purchase_orders_supplier_id`
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_purchase_orders_fund_id`
    FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_purchase_orders_mode_of_procurement_id`
    FOREIGN KEY (`mode_of_procurement_id`) REFERENCES `mode_of_procurements` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_purchase_orders_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_orders_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_order_id` BIGINT UNSIGNED NOT NULL,
  `line_no` INT UNSIGNED NOT NULL,
  `item_type` ENUM('supply', 'semi_expendable', 'equipment') NOT NULL DEFAULT 'supply',
  `account_code_id` BIGINT UNSIGNED DEFAULT NULL,
  `classification_id` BIGINT UNSIGNED DEFAULT NULL,
  `item_description` TEXT NOT NULL,
  `quantity` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unit_of_measure_id` BIGINT UNSIGNED DEFAULT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_order_items_purchase_order_id` (`purchase_order_id`),
  KEY `idx_purchase_order_items_account_code_id` (`account_code_id`),
  KEY `idx_purchase_order_items_classification_id` (`classification_id`),
  KEY `idx_purchase_order_items_unit_of_measure_id` (`unit_of_measure_id`),
  CONSTRAINT `fk_purchase_order_items_purchase_order_id`
    FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_order_items_account_code_id`
    FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_order_items_classification_id`
    FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_order_items_unit_of_measure_id`
    FOREIGN KEY (`unit_of_measure_id`) REFERENCES `unit_of_measures` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receivings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `purchase_order_id` BIGINT UNSIGNED NOT NULL,
  `ris_no` VARCHAR(100) DEFAULT NULL,
  `received_date` DATE NOT NULL,
  `delivery_receipt_no` VARCHAR(100) DEFAULT NULL,
  `invoice_no` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('draft', 'partial', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
  `remarks` TEXT DEFAULT NULL,
  `total_received_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_receivings_system_reference` (`system_reference`),
  KEY `idx_receivings_purchase_order_id` (`purchase_order_id`),
  KEY `idx_receivings_created_by` (`created_by`),
  KEY `idx_receivings_updated_by` (`updated_by`),
  CONSTRAINT `fk_receivings_purchase_order_id`
    FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_receivings_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_receivings_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receiving_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receiving_id` BIGINT UNSIGNED NOT NULL,
  `purchase_order_item_id` BIGINT UNSIGNED NOT NULL,
  `quantity_delivered` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_accepted` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_rejected` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `item_condition` VARCHAR(100) DEFAULT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receiving_items_receiving_id` (`receiving_id`),
  KEY `idx_receiving_items_purchase_order_item_id` (`purchase_order_item_id`),
  CONSTRAINT `fk_receiving_items_receiving_id`
    FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_receiving_items_purchase_order_item_id`
    FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receiving_item_details` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receiving_item_id` BIGINT UNSIGNED NOT NULL,
  `brand_id` BIGINT UNSIGNED DEFAULT NULL,
  `model_id` BIGINT UNSIGNED DEFAULT NULL,
  `brand` VARCHAR(150) DEFAULT NULL,
  `model` VARCHAR(150) DEFAULT NULL,
  `serial_no` VARCHAR(150) DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receiving_item_details_receiving_item_id` (`receiving_item_id`),
  KEY `idx_receiving_item_details_brand_id` (`brand_id`),
  KEY `idx_receiving_item_details_model_id` (`model_id`),
  CONSTRAINT `fk_receiving_item_details_receiving_item_id`
    FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_receiving_item_details_brand_id`
    FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_receiving_item_details_model_id`
    FOREIGN KEY (`model_id`) REFERENCES `models` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `receiving_id` BIGINT UNSIGNED DEFAULT NULL,
  `receiving_item_id` BIGINT UNSIGNED DEFAULT NULL,
  `purchase_order_item_id` BIGINT UNSIGNED DEFAULT NULL,
  `item_type` ENUM('supply', 'semi_expendable', 'equipment') NOT NULL DEFAULT 'supply',
  `account_code_id` BIGINT UNSIGNED DEFAULT NULL,
  `classification_id` BIGINT UNSIGNED DEFAULT NULL,
  `unit_of_measure_id` BIGINT UNSIGNED DEFAULT NULL,
  `item_description` TEXT NOT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_received` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_issued` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_on_hand` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_items_system_reference` (`system_reference`),
  UNIQUE KEY `uk_stock_items_receiving_item_id` (`receiving_item_id`),
  KEY `idx_stock_items_receiving_id` (`receiving_id`),
  KEY `idx_stock_items_purchase_order_item_id` (`purchase_order_item_id`),
  KEY `idx_stock_items_account_code_id` (`account_code_id`),
  KEY `idx_stock_items_classification_id` (`classification_id`),
  KEY `idx_stock_items_unit_of_measure_id` (`unit_of_measure_id`),
  KEY `idx_stock_items_created_by` (`created_by`),
  KEY `idx_stock_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_stock_items_receiving_id`
    FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_receiving_item_id`
    FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_purchase_order_item_id`
    FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_account_code_id`
    FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_classification_id`
    FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_unit_of_measure_id`
    FOREIGN KEY (`unit_of_measure_id`) REFERENCES `unit_of_measures` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_item_id` BIGINT UNSIGNED NOT NULL,
  `movement_type` ENUM('receipt', 'issue', 'return', 'adjustment') NOT NULL,
  `movement_date` DATE NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `reference_id` BIGINT UNSIGNED DEFAULT NULL,
  `quantity_in` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_out` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `balance_after` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stock_movements_stock_item_id` (`stock_item_id`),
  KEY `idx_stock_movements_created_by` (`created_by`),
  CONSTRAINT `fk_stock_movements_stock_item_id`
    FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_stock_movements_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `issuances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `issuance_date` DATE NOT NULL,
  `office_id` BIGINT UNSIGNED NOT NULL,
  `employee_id` BIGINT UNSIGNED DEFAULT NULL,
  `purpose` TEXT DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `status` ENUM('draft', 'posted', 'cancelled') NOT NULL DEFAULT 'posted',
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_issuances_system_reference` (`system_reference`),
  KEY `idx_issuances_office_id` (`office_id`),
  KEY `idx_issuances_employee_id` (`employee_id`),
  KEY `idx_issuances_created_by` (`created_by`),
  KEY `idx_issuances_updated_by` (`updated_by`),
  CONSTRAINT `fk_issuances_office_id`
    FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_issuances_employee_id`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_issuances_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_issuances_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `issuance_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuance_id` BIGINT UNSIGNED NOT NULL,
  `stock_item_id` BIGINT UNSIGNED NOT NULL,
  `quantity_issued` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_issuance_items_issuance_id` (`issuance_id`),
  KEY `idx_issuance_items_stock_item_id` (`stock_item_id`),
  CONSTRAINT `fk_issuance_items_issuance_id`
    FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_issuance_items_stock_item_id`
    FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `distributions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `document_type` ENUM('ics', 'par') NOT NULL,
  `document_no` VARCHAR(50) NOT NULL,
  `distribution_date` DATE NOT NULL,
  `office_id` BIGINT UNSIGNED NOT NULL,
  `employee_id` BIGINT UNSIGNED DEFAULT NULL,
  `purpose` TEXT DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `status` ENUM('posted', 'cancelled') NOT NULL DEFAULT 'posted',
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_distributions_system_reference` (`system_reference`),
  UNIQUE KEY `uk_distributions_document_no` (`document_no`),
  KEY `idx_distributions_office_id` (`office_id`),
  KEY `idx_distributions_employee_id` (`employee_id`),
  KEY `idx_distributions_created_by` (`created_by`),
  KEY `idx_distributions_updated_by` (`updated_by`),
  CONSTRAINT `fk_distributions_office_id`
    FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_distributions_employee_id`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_distributions_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_distributions_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `distribution_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `distribution_id` BIGINT UNSIGNED NOT NULL,
  `receiving_item_id` BIGINT UNSIGNED NOT NULL,
  `quantity_distributed` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_distribution_items_distribution_id` (`distribution_id`),
  KEY `idx_distribution_items_receiving_item_id` (`receiving_item_id`),
  CONSTRAINT `fk_distribution_items_distribution_id`
    FOREIGN KEY (`distribution_id`) REFERENCES `distributions` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_distribution_items_receiving_item_id`
    FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `distribution_item_details` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `distribution_item_id` BIGINT UNSIGNED NOT NULL,
  `receiving_item_detail_id` BIGINT UNSIGNED DEFAULT NULL,
  `brand` VARCHAR(150) DEFAULT NULL,
  `model` VARCHAR(150) DEFAULT NULL,
  `serial_no` VARCHAR(150) DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_distribution_item_details_distribution_item_id` (`distribution_item_id`),
  KEY `idx_distribution_item_details_receiving_item_detail_id` (`receiving_item_detail_id`),
  CONSTRAINT `fk_distribution_item_details_distribution_item_id`
    FOREIGN KEY (`distribution_item_id`) REFERENCES `distribution_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_distribution_item_details_receiving_item_detail_id`
    FOREIGN KEY (`receiving_item_detail_id`) REFERENCES `receiving_item_details` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `module_name` VARCHAR(100) NOT NULL,
  `record_type` VARCHAR(100) NOT NULL,
  `record_id` BIGINT UNSIGNED DEFAULT NULL,
  `action_name` VARCHAR(50) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_user_id` (`user_id`),
  KEY `idx_audit_logs_module_name` (`module_name`),
  CONSTRAINT `fk_audit_logs_user_id`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `series_numbers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(100) NOT NULL,
  `prefix` VARCHAR(30) NOT NULL,
  `year_value` SMALLINT UNSIGNED DEFAULT NULL,
  `current_value` INT UNSIGNED NOT NULL DEFAULT 0,
  `padding_length` TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_series_numbers_module_key` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`name`, `description`)
SELECT 'Administrator', 'Full system access'
WHERE NOT EXISTS (
  SELECT 1 FROM `roles` WHERE `name` = 'Administrator'
);

INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role_id`)
SELECT
  'admin',
  'admin@spams.local',
  '$2y$10$9WlVwC5X0KV/kZgZ82WWbuwHMKN6wGSTfFjfOOHm2gzn2a6FIINFW',
  'System Administrator',
  `id`
FROM `roles`
WHERE `name` = 'Administrator'
  AND NOT EXISTS (
    SELECT 1 FROM `users` WHERE `username` = 'admin'
  );

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'departments', 'DEP', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'departments');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'offices', 'OFF', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'offices');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'employees', 'EMP', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'employees');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'suppliers', 'SUP', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'suppliers');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'funds', 'FND', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'funds');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'classifications', 'CLS', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'classifications');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'mode_of_procurements', 'MOP', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'mode_of_procurements');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'unit_of_measures', 'UOM', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'unit_of_measures');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'brands', 'BRD', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'brands');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'models', 'MDL', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'models');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'stock_items', 'STK', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'stock_items');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'issuances', 'ISS', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'issuances');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'distributions', 'DST', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'distributions');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'purchase_orders', 'POREC', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'purchase_orders');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'receivings', 'RCV', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'receivings');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0001', 'Public Bidding', 'Competitive public bidding process'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Public Bidding');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0002', 'Shopping', 'Procurement through shopping under approved thresholds'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Shopping');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0003', 'Small Value Procurement', 'Procurement for small value requirements'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Small Value Procurement');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0004', 'Negotiated Procurement', 'Negotiated procurement mode'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Negotiated Procurement');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0005', 'Direct Contracting', 'Direct contracting procurement mode'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Direct Contracting');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'FND-2026-0001', 'General Fund', 'Government Appropriations', 'Default general fund for regular procurement'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_name` = 'General Fund');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1-07-05-030', 'Information and Communication Technology Equipment', 'asset', 'Starter account code for ICT equipment'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1-07-05-030');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5-02-03-010', 'Office Supplies Expenses', 'supply', 'Starter account code for office supplies'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5-02-03-010');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1-06-07-010', 'Semi-Expendable Office Equipment', 'semi_expendable', 'Starter account code for semi-expendable equipment'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1-06-07-010');

UPDATE `classifications` c
INNER JOIN `account_codes` ac
  ON ac.`account_group` = c.`classification_group`
SET c.`account_code_id` = ac.`id`
WHERE c.`account_code_id` IS NULL;
