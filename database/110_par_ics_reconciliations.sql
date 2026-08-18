USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `par_ics_reconciliations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `distribution_id` BIGINT UNSIGNED NULL,
    `hardcopy_office_id` BIGINT UNSIGNED NULL,
    `hardcopy_employee_id` BIGINT UNSIGNED NULL,
    `hardcopy_responsibility_code_id` BIGINT UNSIGNED NULL,
    `evidence_path` VARCHAR(500) NOT NULL,
    `evidence_original_name` VARCHAR(255) NOT NULL,
    `import_original_name` VARCHAR(255) NOT NULL,
    `status` ENUM('open', 'completed') NOT NULL DEFAULT 'open',
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_par_ics_reconciliations_distribution` (`distribution_id`),
    KEY `idx_par_ics_reconciliations_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `par_ics_reconciliation_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reconciliation_id` BIGINT UNSIGNED NOT NULL,
    `distribution_item_id` BIGINT UNSIGNED NULL,
    `description` TEXT NOT NULL,
    `unit` VARCHAR(80) NULL,
    `quantity` DECIMAL(14,2) NULL,
    `unit_cost` DECIMAL(14,2) NULL,
    `total_cost` DECIMAL(14,2) NULL,
    `comparison_status` ENUM('matched', 'different', 'not_found') NOT NULL DEFAULT 'not_found',
    `resolution_status` ENUM('pending', 'updated', 'no_action') NOT NULL DEFAULT 'pending',
    `resolution_notes` TEXT NULL,
    `resolved_by` BIGINT UNSIGNED NULL,
    `resolved_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_par_ics_reconciliation_items_reconciliation` (`reconciliation_id`),
    KEY `idx_par_ics_reconciliation_items_distribution_item` (`distribution_item_id`),
    KEY `idx_par_ics_reconciliation_items_resolved_by` (`resolved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `distribution_items`
    ADD COLUMN IF NOT EXISTS `reconciled_item_description` TEXT NULL AFTER `remarks`;

-- Safe for databases where the reconciliation tables were created before this
-- workflow was expanded from one selected document to the whole PAR/ICS registry.
ALTER TABLE `par_ics_reconciliations`
    MODIFY COLUMN `distribution_id` BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS `hardcopy_office_id` BIGINT UNSIGNED NULL AFTER `distribution_id`,
    ADD COLUMN IF NOT EXISTS `hardcopy_employee_id` BIGINT UNSIGNED NULL AFTER `hardcopy_office_id`,
    ADD COLUMN IF NOT EXISTS `hardcopy_responsibility_code_id` BIGINT UNSIGNED NULL AFTER `hardcopy_employee_id`;

ALTER TABLE `par_ics_reconciliation_items`
    ADD COLUMN IF NOT EXISTS `distribution_item_detail_id` BIGINT UNSIGNED NULL AFTER `distribution_item_id`;
