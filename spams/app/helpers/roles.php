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

function rbac_employee_account_role_names(): array
{
    return ['Employee', 'User'];
}

function rbac_full_registry_roles(): array
{
    return ['Administrator', 'Supply Officer', 'Property Officer', 'Property Custodian'];
}

function rbac_full_accountability_roles(): array
{
    return rbac_full_registry_roles();
}

function rbac_employee_account_role_id(mysqli $db): int
{
    $roleNameColumn = roles_name_column($db);
    $roleActiveClause = roles_active_clause($db);

    foreach (rbac_employee_account_role_names() as $roleName) {
        $stmt = $db->prepare("SELECT id FROM roles WHERE {$roleNameColumn} = ? AND {$roleActiveClause} LIMIT 1");
        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('s', $roleName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        if (!empty($row['id'])) {
            return (int) $row['id'];
        }
    }

    return 0;
}

function rbac_ensure_employee_account_role(mysqli $db, int $userId = 0): int
{
    $existingRoleId = rbac_employee_account_role_id($db);
    if ($existingRoleId > 0) {
        return $existingRoleId;
    }

    $roleNameColumn = roles_name_column($db);
    $columns = [$roleNameColumn];
    $placeholders = ['?'];
    $types = 's';
    $values = ['Employee'];

    if (schema_has_column($db, 'roles', 'is_active')) {
        $columns[] = 'is_active';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = 1;
    }

    if ($userId > 0 && schema_has_column($db, 'roles', 'created_by')) {
        $columns[] = 'created_by';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $userId;
    }

    $sql = 'INSERT INTO roles (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param($types, ...$values);
    $saved = $stmt->execute();
    $newRoleId = (int) $stmt->insert_id;
    $stmt->close();

    return $saved ? $newRoleId : 0;
}

function rbac_has_full_registry_access(): bool
{
    return function_exists('user_has_any_role') && user_has_any_role(...rbac_full_registry_roles());
}

function rbac_has_full_accountability_access(): bool
{
    return function_exists('user_has_any_role') && user_has_any_role(...rbac_full_accountability_roles());
}
