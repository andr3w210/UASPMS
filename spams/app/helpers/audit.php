<?php

if (!defined('AUDIT_AUTO_LOG_DISABLED_ROUTES')) {
    define('AUDIT_AUTO_LOG_DISABLED_ROUTES', [
        'modules/audit_log/index.php',
    ]);
}

function write_audit_log(mysqli $db, array $entry): bool
{
    $GLOBALS['audit_log_written_in_request'] = true;
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

function audit_normalize_route_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $marker = '/spams/';
    $pos = stripos($scriptName, $marker);
    if ($pos !== false) {
        return ltrim(substr($scriptName, $pos + strlen($marker)), '/');
    }

    return ltrim(basename($scriptName), '/');
}

function audit_auto_log_request(mysqli $db): bool
{
    if (!empty($GLOBALS['audit_log_written_in_request'])) {
        return false;
    }

    $userId = function_exists('current_user_id') ? (int) current_user_id() : 0;
    if ($userId <= 0) {
        return false;
    }

    $routePath = audit_normalize_route_path();
    if ($routePath === '' || in_array($routePath, AUDIT_AUTO_LOG_DISABLED_ROUTES, true)) {
        return false;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $isGet = $method === 'GET';
    $isPost = $method === 'POST';
    if (!$isGet && !$isPost) {
        return false;
    }

    $query = $_GET ?? [];
    unset($query['_csrf']);
    $query = array_filter($query, static function ($value) {
        return !is_array($value) && (string) $value !== '';
    });

    $routeParts = explode('/', $routePath);
    $moduleName = count($routeParts) >= 2 ? $routeParts[count($routeParts) - 2] : 'system';
    $recordId = null;
    foreach (['id', 'po_id', 'office_id', 'session_id', 'receiving_id', 'distribution_id', 'return_id', 'transfer_id', 'disposal_id', 'adjustment_id'] as $idKey) {
        if (isset($_GET[$idKey]) && trim((string) $_GET[$idKey]) !== '') {
            $recordId = trim((string) $_GET[$idKey]);
            break;
        }
        if (isset($_POST[$idKey]) && trim((string) $_POST[$idKey]) !== '') {
            $recordId = trim((string) $_POST[$idKey]);
            break;
        }
    }

    $newValues = [
        'method' => $method,
        'route' => $routePath,
    ];
    if ($query) {
        $newValues['query'] = $query;
    }
    if ($isPost) {
        $postKeys = array_values(array_filter(array_keys($_POST ?? []), static function ($key) {
            return $key !== '_csrf';
        }));
        if ($postKeys) {
            $newValues['post_keys'] = $postKeys;
        }
    }

    return write_audit_log($db, [
        'user_id' => $userId,
        'action' => $isGet ? 'access' : 'request',
        'table_name' => 'request_activity',
        'record_id' => $recordId,
        'module_name' => $moduleName,
        'record_type' => 'route',
        'action_name' => $isGet ? 'view_page' : 'submit_request',
        'description' => ($isGet ? 'Viewed ' : 'Submitted request to ') . $routePath . '.',
        'new_values' => $newValues,
    ]);
}
