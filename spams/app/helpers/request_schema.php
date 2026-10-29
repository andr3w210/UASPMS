<?php

/**
 * Strict request-body schemas.
 *
 * A schema maps field name => rule. Unknown fields are rejected, so a client can
 * never smuggle in a column the route did not declare (role, owner, status, totals...).
 *
 * Rule keys:
 *   type      string|int|float|bool|date|enum|list|rows   (default string)
 *   required  bool
 *   max/min   string length, or numeric bound for int/float
 *   enum      allowed values for type=enum
 *   nullable  bool, empty string becomes null
 *   items     for type=list: scalar rule; for type=rows: field schema of each row
 *   max_items cap for list/rows
 */

const REQUEST_SCHEMA_ALLOWED_META = ['_csrf'];

// Fields a client may never supply, on any route. They are derived server-side.
function request_schema_forbidden_fields(): array
{
    return [
        'user_id', 'created_by', 'updated_by', 'deleted_by', 'sender_user_id', 'actor_id',
        'role', 'role_name', 'is_admin', 'permissions',
        'password_hash', 'force_password_change', 'failed_attempts', 'locked_until',
        'created_at', 'updated_at', 'deleted_at', 'last_login_at',
        'status', 'is_distributed', 'total_amount', 'grand_total', 'qr_token',
    ];
}

function request_schema_validate(array $schema, ?array $input = null): array
{
    $input = $input ?? $_POST;
    $errors = [];
    $data = [];

    // A route schema may narrow a global rule, but it must never make a
    // server-owned field writable. Keep this check independent of the schema.
    $forbidden = array_intersect(array_keys($input), request_schema_forbidden_fields());
    foreach ($forbidden as $name) {
        $errors[] = 'Forbidden field: ' . $name;
    }

    $unknown = array_diff(array_keys($input), array_keys($schema), REQUEST_SCHEMA_ALLOWED_META, $forbidden);
    foreach ($unknown as $name) {
        $errors[] = 'Unexpected field: ' . (is_string($name) ? substr($name, 0, 60) : (string) $name);
    }

    foreach ($schema as $name => $rule) {
        $present = array_key_exists($name, $input);
        if (!$present) {
            if (!empty($rule['required'])) {
                $errors[] = 'Missing field: ' . $name;
            } elseif (($rule['type'] ?? 'string') === 'bool') {
                $data[$name] = false;
            } elseif (array_key_exists('default', $rule)) {
                $data[$name] = $rule['default'];
            }
            continue;
        }

        $result = request_schema_cast($name, $input[$name], $rule);
        if ($result['error'] !== null) {
            $errors[] = $result['error'];
            continue;
        }
        $data[$name] = $result['value'];
    }

    return ['ok' => $errors === [], 'data' => $data, 'errors' => $errors];
}

function request_schema_cast(string $name, $value, array $rule): array
{
    $type = $rule['type'] ?? 'string';
    $fail = static fn(string $why): array => ['value' => null, 'error' => $name . ': ' . $why];
    $ok = static fn($v): array => ['value' => $v, 'error' => null];

    if ($type === 'list' || $type === 'rows') {
        if (!is_array($value)) {
            return $fail('must be a list');
        }
        $maxItems = (int) ($rule['max_items'] ?? 500);
        if (count($value) > $maxItems) {
            return $fail('too many entries');
        }
        $out = [];
        foreach ($value as $key => $item) {
            if ($type === 'list') {
                $sub = request_schema_cast($name . '[]', $item, $rule['items'] ?? ['type' => 'string']);
                if ($sub['error'] !== null) {
                    return $sub;
                }
                $out[$key] = $sub['value'];
                continue;
            }
            if (!is_array($item)) {
                return $fail('row must be an object');
            }
            $rowCheck = request_schema_validate($rule['items'] ?? [], $item);
            if (!$rowCheck['ok']) {
                return $fail(implode('; ', $rowCheck['errors']));
            }
            $out[$key] = $rowCheck['data'];
        }
        return $ok($out);
    }

    if (is_array($value)) {
        return $fail('must be a single value');
    }
    $value = is_string($value) ? trim($value) : (string) $value;

    if ($value === '') {
        if (!empty($rule['required'])) {
            return $fail('is required');
        }
        if ($type === 'bool') {
            return $ok(false);
        }
        return $ok(!empty($rule['nullable']) ? null : '');
    }

    switch ($type) {
        case 'int':
            if (!preg_match('/^-?\d{1,18}$/', $value)) {
                return $fail('must be an integer');
            }
            $num = (int) $value;
            if (isset($rule['min']) && $num < $rule['min']) {
                return $fail('below minimum');
            }
            if (isset($rule['max']) && $num > $rule['max']) {
                return $fail('above maximum');
            }
            return $ok($num);

        case 'float':
            if (!preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', str_replace(',', '', $value))) {
                return $fail('must be a number');
            }
            $num = (float) str_replace(',', '', $value);
            if (isset($rule['min']) && $num < $rule['min']) {
                return $fail('below minimum');
            }
            if (isset($rule['max']) && $num > $rule['max']) {
                return $fail('above maximum');
            }
            return $ok($num);

        case 'bool':
            if (!in_array($value, ['0', '1', 'on', 'true', 'false', 'off'], true)) {
                return $fail('must be a boolean');
            }
            return $ok(in_array($value, ['1', 'on', 'true'], true));

        case 'date':
            $date = DateTime::createFromFormat('Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                return $fail('must be a YYYY-MM-DD date');
            }
            return $ok($value);

        case 'enum':
            if (!in_array($value, array_map('strval', $rule['enum'] ?? []), true)) {
                return $fail('value not allowed');
            }
            return $ok($value);

        default:
            if (isset($rule['max']) && mb_strlen($value) > (int) $rule['max']) {
                return $fail('too long');
            }
            if (isset($rule['min']) && mb_strlen($value) < (int) $rule['min']) {
                return $fail('too short');
            }
            if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                return $fail('invalid format');
            }
            return $ok($value);
    }
}

/**
 * Validate the POST body or stop the request. $schemas is either one schema, or a
 * map of action => schema when $selector names the discriminating field.
 * Passwords are never copied into the log; only field names are.
 */
function request_schema_enforce(array $schemas, ?string $selector = null, bool $json = false, ?string $defaultAction = null): array
{
    $schema = $schemas;
    if ($selector !== null) {
        $action = $_POST[$selector] ?? $defaultAction;
        if (!is_string($action) || !isset($schemas[$action]) || !is_array($schemas[$action])) {
            request_schema_reject(['Unknown ' . $selector], $json);
        }
        $schema = $schemas[$action];
        $schema[$selector] = ['type' => 'enum', 'enum' => [$action], 'required' => $defaultAction === null];
    }

    $result = request_schema_validate($schema);
    if (!$result['ok']) {
        request_schema_reject($result['errors'], $json);
    }

    return $result['data'];
}

function request_schema_reject(array $errors, bool $json): void
{
    error_log('[SPAMS] Rejected request body on ' . ($_SERVER['SCRIPT_NAME'] ?? '?') . ': ' . implode(' | ', array_slice($errors, 0, 10)));
    http_response_code(422);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid request. Please go back, reload the page, and try again.';
    }
    exit;
}
