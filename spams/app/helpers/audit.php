<?php

function write_audit_log(mysqli $db, array $entry): bool
{
    $userId = isset($entry['user_id']) ? (int) $entry['user_id'] : (function_exists('current_user_id') ? (int) current_user_id() : 0);
    $action = trim((string) ($entry['action'] ?? 'update'));
    $tableName = trim((string) ($entry['table_name'] ?? ''));
    $recordId = isset($entry['record_id']) ? (string) $entry['record_id'] : null;
    $oldValues = $entry['old_values'] ?? null;
    $newValues = $entry['new_values'] ?? null;
    $moduleName = trim((string) ($entry['module_name'] ?? ''));
    $recordType = trim((string) ($entry['record_type'] ?? ''));
    $actionName = trim((string) ($entry['action_name'] ?? ''));
    $description = trim((string) ($entry['description'] ?? ''));
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if ($tableName === '') {
        return false;
    }

    if (is_array($oldValues) || is_object($oldValues)) {
        $oldValues = json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_array($newValues) || is_object($newValues)) {
        $newValues = json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $oldValues = $oldValues !== null ? (string) $oldValues : null;
    $newValues = $newValues !== null ? (string) $newValues : null;
    $moduleName = $moduleName !== '' ? $moduleName : null;
    $recordType = $recordType !== '' ? $recordType : null;
    $actionName = $actionName !== '' ? $actionName : null;
    $description = $description !== '' ? $description : null;
    $recordId = ($recordId !== null && $recordId !== '') ? $recordId : null;

    $stmt = $db->prepare("
        INSERT INTO audit_logs
            (user_id, action, table_name, record_id, old_values, new_values, module_name, record_type, action_name, description, ip_address, created_at)
        VALUES
            (NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'issssssssss',
        $userId,
        $action,
        $tableName,
        $recordId,
        $oldValues,
        $newValues,
        $moduleName,
        $recordType,
        $actionName,
        $description,
        $ipAddress
    );

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}
