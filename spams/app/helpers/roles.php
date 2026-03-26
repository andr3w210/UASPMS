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
