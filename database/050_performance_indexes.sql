USE `spamsdb`;

ALTER TABLE `distributions`
    ADD INDEX IF NOT EXISTS `idx_distributions_status` (`status`);

ALTER TABLE `distribution_item_details`
    ADD INDEX IF NOT EXISTS `idx_distribution_item_details_is_disposed` (`is_disposed`),
    ADD INDEX IF NOT EXISTS `idx_distribution_item_details_is_distributed` (`is_distributed`),
    ADD INDEX IF NOT EXISTS `idx_distribution_item_details_current_office_id` (`current_office_id`),
    ADD INDEX IF NOT EXISTS `idx_distribution_item_details_current_employee_id` (`current_employee_id`);

ALTER TABLE `purchase_order_items`
    ADD INDEX IF NOT EXISTS `idx_purchase_order_items_item_type` (`item_type`);

ALTER TABLE `offices`
    ADD INDEX IF NOT EXISTS `idx_offices_is_active` (`is_active`);

ALTER TABLE `employees`
    ADD INDEX IF NOT EXISTS `idx_employees_is_active` (`is_active`);

ALTER TABLE `suppliers`
    ADD INDEX IF NOT EXISTS `idx_suppliers_is_active` (`is_active`);

ALTER TABLE `funds`
    ADD INDEX IF NOT EXISTS `idx_funds_is_active` (`is_active`);

ALTER TABLE `classifications`
    ADD INDEX IF NOT EXISTS `idx_classifications_is_active` (`is_active`);

ALTER TABLE `stock_catalog`
    ADD INDEX IF NOT EXISTS `idx_stock_catalog_is_active` (`is_active`);

ALTER TABLE `legacy_assets`
    ADD INDEX IF NOT EXISTS `idx_legacy_assets_classification_id` (`classification_id`),
    ADD INDEX IF NOT EXISTS `idx_legacy_assets_is_active` (`is_active`);

ALTER TABLE `returns`
    ADD INDEX IF NOT EXISTS `idx_returns_office_id` (`office_id`),
    ADD INDEX IF NOT EXISTS `idx_returns_employee_id` (`employee_id`),
    ADD INDEX IF NOT EXISTS `idx_returns_distribution_item_detail_id` (`distribution_item_detail_id`);

ALTER TABLE `disposals`
    ADD INDEX IF NOT EXISTS `idx_disposals_distribution_item_detail_id` (`distribution_item_detail_id`);
