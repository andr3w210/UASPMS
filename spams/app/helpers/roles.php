<?php

function schema_has_column(mysqli $db, string $table, string $column): bool
{
    static $cache = [];

    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        $cache[$cacheKey] = false;
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $cache[$cacheKey] = (bool) ($result && $result->fetch_assoc());
    $stmt->close();

    return $cache[$cacheKey];
}

function roles_name_column(mysqli $db): string
{
    if (schema_has_column($db, 'roles', 'name')) {
        return 'name';
    }

    if (schema_has_column($db, 'roles', 'role_name')) {
        return 'role_name';
    }

    return 'name';
}

function roles_name_expression(mysqli $db, string $alias = 'r'): string
{
    return $alias . '.' . roles_name_column($db);
}

function roles_active_clause(mysqli $db, string $alias = 'roles'): string
{
    if (schema_has_column($db, 'roles', 'is_active')) {
        return $alias . '.is_active = 1';
    }

    return '1 = 1';
}

function ensure_distribution_item_runtime_columns(mysqli $db): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $columns = [
        'current_office_id' => "ALTER TABLE distribution_item_details ADD COLUMN current_office_id BIGINT UNSIGNED NULL AFTER is_distributed",
        'current_employee_id' => "ALTER TABLE distribution_item_details ADD COLUMN current_employee_id BIGINT UNSIGNED NULL AFTER current_office_id",
        'current_responsibility_code_id' => "ALTER TABLE distribution_item_details ADD COLUMN current_responsibility_code_id BIGINT UNSIGNED NULL AFTER current_employee_id",
        'is_disposed' => "ALTER TABLE distribution_item_details ADD COLUMN is_disposed TINYINT(1) NOT NULL DEFAULT 0 AFTER current_responsibility_code_id",
    ];

    foreach ($columns as $column => $sql) {
        if (!schema_has_column($db, 'distribution_item_details', $column)) {
            $db->query($sql);
        }
    }

    $done = true;
}

function ensure_returns_runtime_schema(mysqli $db): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $db->query("
        CREATE TABLE IF NOT EXISTS returns (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            system_reference VARCHAR(50) NOT NULL UNIQUE,
            return_date DATE NOT NULL,
            distribution_item_detail_id BIGINT UNSIGNED NULL,
            office_id BIGINT UNSIGNED NULL,
            employee_id BIGINT UNSIGNED NULL,
            reason TEXT NULL,
            remarks TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'posted',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'distribution_item_detail_id' => "ALTER TABLE returns ADD COLUMN distribution_item_detail_id BIGINT UNSIGNED NULL AFTER return_date",
        'office_id' => "ALTER TABLE returns ADD COLUMN office_id BIGINT UNSIGNED NULL AFTER distribution_item_detail_id",
        'employee_id' => "ALTER TABLE returns ADD COLUMN employee_id BIGINT UNSIGNED NULL AFTER office_id",
        'status' => "ALTER TABLE returns ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'posted' AFTER remarks",
        'created_by' => "ALTER TABLE returns ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER status",
        'created_at' => "ALTER TABLE returns ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by",
    ];

    foreach ($columns as $column => $sql) {
        if (!schema_has_column($db, 'returns', $column)) {
            $db->query($sql);
        }
    }

    $done = true;
}

function ensure_disposals_runtime_schema(mysqli $db): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $db->query("
        CREATE TABLE IF NOT EXISTS disposals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            system_reference VARCHAR(50) NOT NULL UNIQUE,
            disposal_date DATE NOT NULL,
            distribution_item_detail_id BIGINT UNSIGNED NULL,
            disposal_type VARCHAR(100) NULL,
            reason TEXT NULL,
            approved_by BIGINT UNSIGNED NULL,
            remarks TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'posted',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'distribution_item_detail_id' => "ALTER TABLE disposals ADD COLUMN distribution_item_detail_id BIGINT UNSIGNED NULL AFTER disposal_date",
        'approved_by' => "ALTER TABLE disposals ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER reason",
        'status' => "ALTER TABLE disposals ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'posted' AFTER remarks",
        'created_by' => "ALTER TABLE disposals ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER status",
        'created_at' => "ALTER TABLE disposals ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by",
    ];

    foreach ($columns as $column => $sql) {
        if (!schema_has_column($db, 'disposals', $column)) {
            $db->query($sql);
        }
    }

    ensure_distribution_item_runtime_columns($db);
    $done = true;
}
