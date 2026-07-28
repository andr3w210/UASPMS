<?php

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function base_url(string $path = ''): string
{
    $base = rtrim(BASE_URL, '/');
    $path = ltrim($path, '/');

    return $path === '' ? $base : $base . '/' . $path;
}

function app_url(string $path = ''): string
{
    $relative = base_url($path);

    static $configuredBase = null;
    if ($configuredBase === null) {
        $configuredBase = APP_URL;

        if (function_exists('db')) {
            $db = db();
            if ($db) {
                $savedUrls = [
                    get_system_setting($db, 'local_access_url', ''),
                    get_system_setting($db, 'tailscale_serve_url', ''),
                    get_system_setting($db, 'tailscale_ip_url', ''),
                    get_system_setting($db, 'app_url', APP_URL),
                ];

                foreach ($savedUrls as $savedUrl) {
                    $savedUrl = trim((string) $savedUrl);
                    if ($savedUrl !== '') {
                        $configuredBase = $savedUrl;
                        break;
                    }
                }
            }
        }
    }

    if ($configuredBase !== '') {
        return rtrim($configuredBase, '/') . $relative;
    }

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    );
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));

    return $scheme . '://' . $host . $relative;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function redirect(string $path): void
{
    header('Location: ' . base_url($path));
    exit;
}

function request_expects_json(): bool
{
    $xhr = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($xhr === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($accept, 'application/json') !== false;
}

function request_reject_invalid_input(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);

    if (request_expects_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function request_is_valid_utf8(string $value): bool
{
    return preg_match('//u', $value) === 1;
}

function request_input_limits(): array
{
    return [
        'max_depth' => 8,
        'max_items' => 2000,
        'max_key_bytes' => 128,
        'max_scalar_bytes' => 20000,
        'max_file_scalar_bytes' => 2048,
    ];
}

function request_validate_key($key, string $path, array $limits): string
{
    if (is_int($key)) {
        return (string) $key;
    }

    if (!is_string($key)) {
        request_reject_invalid_input('Malformed request payload.', 400);
    }

    if ($key === '' || strlen($key) > $limits['max_key_bytes']) {
        request_reject_invalid_input('Request payload is too large.', 413);
    }

    if (!request_is_valid_utf8($key) || preg_match('/[\x00-\x1F\x7F]/', $key)) {
        request_reject_invalid_input('Malformed request payload.', 400);
    }

    return $key;
}

function request_validate_scalar($value, string $path, int $maxBytes): string
{
    if ($value === null) {
        return '';
    }

    if (!is_scalar($value)) {
        request_reject_invalid_input('Malformed request payload.', 400);
    }

    $stringValue = (string) $value;

    if (strlen($stringValue) > $maxBytes) {
        request_reject_invalid_input('Request payload is too large.', 413);
    }

    if (!request_is_valid_utf8($stringValue)) {
        request_reject_invalid_input('Malformed request payload.', 400);
    }

    if (preg_match('/[\x00\x08\x0B\x0C\x0E-\x1F\x7F]/', $stringValue)) {
        request_reject_invalid_input('Malformed request payload.', 400);
    }

    return $stringValue;
}

function request_sanitize_array(array $input, string $path, array &$state, array $limits, int $depth = 0): array
{
    if ($depth > $limits['max_depth']) {
        request_reject_invalid_input('Malformed request payload.', 400);
    }

    $sanitized = [];

    foreach ($input as $key => $value) {
        $state['items']++;
        if ($state['items'] > $limits['max_items']) {
            request_reject_invalid_input('Request payload is too large.', 413);
        }

        $validatedKey = request_validate_key($key, $path, $limits);
        $itemPath = $path . '[' . $validatedKey . ']';

        if (is_array($value)) {
            $sanitized[$key] = request_sanitize_array($value, $itemPath, $state, $limits, $depth + 1);
            continue;
        }

        $sanitized[$key] = request_validate_scalar($value, $itemPath, $limits['max_scalar_bytes']);
    }

    return $sanitized;
}

function request_sanitize_files_array(array $input, string $path, array &$state, array $limits, int $depth = 0): array
{
    if ($depth > $limits['max_depth']) {
        request_reject_invalid_input('Malformed request payload.', 400);
    }

    $sanitized = [];

    foreach ($input as $key => $value) {
        $state['items']++;
        if ($state['items'] > $limits['max_items']) {
            request_reject_invalid_input('Request payload is too large.', 413);
        }

        $validatedKey = request_validate_key($key, $path, $limits);
        $itemPath = $path . '[' . $validatedKey . ']';

        if (is_array($value)) {
            $sanitized[$key] = request_sanitize_files_array($value, $itemPath, $state, $limits, $depth + 1);
            continue;
        }

        $maxBytes = is_string($key) && in_array($key, ['name', 'full_path', 'type', 'tmp_name'], true)
            ? $limits['max_file_scalar_bytes']
            : $limits['max_scalar_bytes'];

        $sanitized[$key] = request_validate_scalar($value, $itemPath, $maxBytes);
    }

    return $sanitized;
}

function request_guard_superglobals(): void
{
    static $hasRun = false;
    if ($hasRun) {
        return;
    }

    $hasRun = true;
    $limits = request_input_limits();

    $inputState = ['items' => 0];
    $_GET = request_sanitize_array($_GET, 'GET', $inputState, $limits);
    $_POST = request_sanitize_array($_POST, 'POST', $inputState, $limits);
    $_COOKIE = request_sanitize_array($_COOKIE, 'COOKIE', $inputState, $limits);

    $fileState = ['items' => 0];
    $_FILES = request_sanitize_files_array($_FILES, 'FILES', $fileState, $limits);
}

function old(array $source, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $source) || is_array($source[$key])) {
        return $default;
    }

    return trim((string) $source[$key]);
}

function add_validation_error(array &$errors, string $message): void
{
    if (!in_array($message, $errors, true)) {
        $errors[] = $message;
    }
}

function schema_migration_required(string $message): void
{
    $fullMessage = 'Database schema is outdated: ' . $message . ' Apply latest migrations before continuing.';

    if (PHP_SAPI === 'cli') {
        throw new RuntimeException($fullMessage);
    }

    if (function_exists('set_flash')) {
        set_flash('error', $fullMessage);
    }

    if (!headers_sent() && function_exists('redirect')) {
        redirect('dashboard/index.php');
    }

    http_response_code(500);
    echo '<div class="alert alert-danger">' . h($fullMessage) . '</div>';
    exit;
}

function schema_require_table(mysqli $db, string $table): void
{
    if (!function_exists('schema_has_table') || !schema_has_table($db, $table)) {
        schema_migration_required($table . ' table is missing.');
    }
}

function schema_require_columns(mysqli $db, string $table, array $columns): void
{
    if (!function_exists('schema_has_column')) {
        schema_migration_required('schema inspection helpers are unavailable.');
    }

    $missing = [];
    foreach ($columns as $column) {
        if (!schema_has_column($db, $table, (string) $column)) {
            $missing[] = $table . '.' . $column;
        }
    }

    if ($missing) {
        schema_migration_required('missing required column(s): ' . implode(', ', $missing) . '.');
    }
}

function is_valid_date_string(string $value, string $format = 'Y-m-d'): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    $date = DateTime::createFromFormat($format, $value);
    $errors = DateTime::getLastErrors();
    if (!$date instanceof DateTime || $date->format($format) !== $value) {
        return false;
    }
    if (is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0)) {
        return false;
    }

    $year = (int) $date->format('Y');
    return $year >= 1900 && $year <= 2100;
}

function normalize_date_string(?string $value, int $minYear = 1900, int $maxYear = 2100): string
{
    $clean = trim((string) $value);
    if ($clean === '' || $clean === '0000-00-00') {
        return '';
    }

    $datePart = $clean;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $clean, $matches)) {
        $datePart = $matches[0];
    }

    $formats = ['!Y-m-d', '!m/d/Y', '!n/j/Y', '!m-d-Y', '!n-j-Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $datePart);
        $errors = DateTime::getLastErrors();
        if (!$date instanceof DateTime) {
            continue;
        }
        if (is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }

        $year = (int) $date->format('Y');
        if ($year < $minYear || $year > $maxYear) {
            continue;
        }

        return $date->format('Y-m-d');
    }

    if (is_numeric($clean)) {
        $serial = (int) floor((float) $clean);
        if ($serial > 0) {
            $date = (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days');
            $year = (int) $date->format('Y');
            if ($year >= $minYear && $year <= $maxYear) {
                return $date->format('Y-m-d');
            }
        }
    }

    return '';
}

function is_allowed_value(string $value, array $allowed): bool
{
    return in_array($value, $allowed, true);
}

function asset_identifier_conflict(
    mysqli $db,
    string $field,
    string $value,
    string $currentSource = '',
    int $currentId = 0,
    bool $includeReceivingDetails = false
): ?array {
    $field = $field === 'serial_no' ? 'serial_no' : 'property_number';
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $checks = [
        [
            'source' => 'legacy',
            'label' => 'beginning balance asset',
            'sql' => "SELECT id FROM legacy_assets WHERE is_active = 1 AND TRIM({$field}) = ?",
        ],
        [
            'source' => 'system',
            'label' => 'system asset',
            'sql' => "SELECT id FROM distribution_item_details WHERE TRIM({$field}) = ?",
        ],
    ];

    if ($field === 'serial_no' && $includeReceivingDetails) {
        $checks[] = [
            'source' => 'receiving',
            'label' => 'receiving detail',
            'sql' => 'SELECT id FROM receiving_item_details WHERE TRIM(serial_no) = ?',
        ];
    }

    foreach ($checks as $check) {
        $sql = $check['sql'];
        $params = [$value];
        $types = 's';
        if ($currentSource === $check['source'] && $currentId > 0) {
            $sql .= ' AND id <> ?';
            $types .= 'i';
            $params[] = $currentId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return [
                'source' => $check['source'],
                'label' => $check['label'],
                'id' => (int) ($row['id'] ?? 0),
            ];
        }
    }

    return null;
}

function has_foreign_key_reference(mysqli $db, string $referencedTable, int $recordId, array $fallbackChecks = []): bool
{
    $targets = [];
    $fkStmt = $db->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = ?"
    );
    if ($fkStmt) {
        $fkStmt->bind_param('s', $referencedTable);
        $fkStmt->execute();
        $fkResult = $fkStmt->get_result();
        if ($fkResult) {
            $targets = $fkResult->fetch_all(MYSQLI_ASSOC);
        }
        $fkStmt->close();
    }

    $checks = [];
    foreach ($targets as $target) {
        $table = (string) ($target['TABLE_NAME'] ?? '');
        $column = (string) ($target['COLUMN_NAME'] ?? '');
        if ($table === '' || $column === '') {
            continue;
        }
        $safeTable = str_replace('`', '``', $table);
        $safeColumn = str_replace('`', '``', $column);
        $checks[] = "SELECT 1 FROM `{$safeTable}` WHERE `{$safeColumn}` = ? LIMIT 1";
    }

    if (!$checks) {
        $checks = $fallbackChecks;
    }

    foreach ($checks as $sql) {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $hasRow = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($hasRow) {
            return true;
        }
    }

    return false;
}

function format_date(?string $value, string $format = 'M d, Y'): string
{
    $clean = trim((string) $value);
    if ($clean === '') {
        return '';
    }

    if (normalize_date_string($clean) === '') {
        return '';
    }

    $timestamp = strtotime($clean);
    if ($timestamp === false) {
        return '';
    }

    return date($format, $timestamp);
}

function format_quantity($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return number_format((float) $value, 0);
}

function operational_status_label(string $domain, string $status): string
{
    $status = trim($status);
    if ($status === '') {
        $status = 'pending';
    }

    $labels = [
        'purchase_order' => ['encoded' => 'Encoded', 'partial' => 'Partial', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
        'receiving' => ['draft' => 'Draft', 'partial' => 'Partial', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'rejected' => 'With Rejected Items'],
        'posted_transaction' => ['posted' => 'Posted', 'cancelled' => 'Cancelled'],
        'stock_adjustment' => ['pending' => 'Pending', 'approved' => 'Approved', 'cancelled' => 'Cancelled'],
        'count_session' => ['open' => 'Open', 'closed' => 'Closed'],
        'inventory_count' => ['pending' => 'Pending', 'found' => 'Found', 'missing' => 'Missing', 'for_repair' => 'For Repair', 'for_disposal' => 'For Disposal', 'wrong_office' => 'Wrong Office', 'wrong_accountable' => 'Wrong Accountable'],
        'supply_count' => ['pending' => 'Pending', 'match' => 'Match', 'shortage' => 'Shortage', 'overage' => 'Overage', 'not_counted' => 'Not Counted'],
    ];

    return $labels[$domain][$status] ?? ucwords(str_replace('_', ' ', $status));
}

function operational_status_badge_class(string $domain, string $status): string
{
    $status = trim($status);
    if ($status === '') {
        $status = 'pending';
    }

    $classes = [
        'purchase_order' => ['encoded' => 'text-bg-secondary', 'partial' => 'text-bg-warning', 'completed' => 'text-bg-success', 'cancelled' => 'text-bg-danger'],
        'receiving' => ['draft' => 'text-bg-secondary', 'partial' => 'text-bg-warning', 'completed' => 'text-bg-success', 'cancelled' => 'text-bg-danger', 'rejected' => 'text-bg-danger'],
        'posted_transaction' => ['posted' => 'text-bg-success', 'cancelled' => 'text-bg-danger'],
        'stock_adjustment' => ['pending' => 'text-bg-warning', 'approved' => 'text-bg-success', 'cancelled' => 'text-bg-danger'],
        'count_session' => ['open' => 'text-bg-success', 'closed' => 'text-bg-secondary'],
        'inventory_count' => ['pending' => 'text-bg-secondary', 'found' => 'text-bg-success', 'missing' => 'text-bg-danger', 'for_repair' => 'text-bg-warning', 'for_disposal' => 'text-bg-danger', 'wrong_office' => 'text-bg-info', 'wrong_accountable' => 'text-bg-info'],
        'supply_count' => ['pending' => 'text-bg-secondary', 'match' => 'text-bg-success', 'shortage' => 'text-bg-warning', 'overage' => 'text-bg-danger', 'not_counted' => 'text-bg-dark'],
    ];

    return $classes[$domain][$status] ?? 'text-bg-secondary';
}

function operational_status_badge(string $domain, string $status, string $extraClass = ''): string
{
    $class = trim('badge ' . operational_status_badge_class($domain, $status) . ' ' . $extraClass);
    return '<span class="' . h($class) . '">' . h(operational_status_label($domain, $status)) . '</span>';
}

function employee_display_name(array $employee): string
{
    $prefix = trim((string) ($employee['name_prefix'] ?? ''));
    $parts = [
        trim((string) ($employee['last_name'] ?? '')),
        trim((string) ($employee['first_name'] ?? '')),
    ];

    $name = implode(', ', array_filter($parts));
    $middle = trim((string) ($employee['middle_name'] ?? ''));
    $suffix = trim((string) ($employee['suffix_name'] ?? ''));

    if ($middle !== '') {
        $name .= ' ' . strtoupper(substr($middle, 0, 1)) . '.';
    }

    if ($suffix !== '') {
        $name .= ' ' . $suffix;
    }

    if ($prefix !== '') {
        $name = $prefix . ' ' . $name;
    }

    return trim($name);
}

function stock_catalog_item_code(string $label): string
{
    $normalized = strtoupper(trim(preg_replace('/[^A-Za-z0-9& ]+/', ' ', $label)));
    if ($normalized === '') {
        return 'SC';
    }

    $rawWords = preg_split('/\s+/', $normalized) ?: [];
    $words = [];
    foreach ($rawWords as $word) {
        if ($word === '' || in_array($word, ['AND', 'OF', 'THE', '&'], true)) {
            continue;
        }
        $words[] = $word;
    }

    if (count($words) >= 2) {
        $first = preg_replace('/[^A-Z]/', '', $words[0]);
        $second = preg_match('/^[A-Z]/', $words[1]) ? preg_replace('/[^A-Z]/', '', $words[1]) : '';
        $code = substr($first, 0, 1) . substr($second, 0, 1);
        if (strlen($code) === 2) {
            return $code;
        }
        if (strlen($first) >= 2) {
            return substr($first, 0, 2);
        }
    }

    $single = $words ? $words[0] : $normalized;
    $single = preg_replace('/[^A-Z]/', '', $single);
    $single = substr($single, 0, 2);

    return str_pad($single, 2, 'X');
}

function stock_catalog_number_basis(mysqli $db, ?int $classificationId, string $itemName = '', string $itemDescription = ''): string
{
    if ($classificationId && $classificationId > 0) {
        $stmt = $db->prepare("SELECT classification_family, classification_name FROM classifications WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $classificationId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $family = trim((string) ($row['classification_family'] ?? ''));
            if ($family !== '') {
                return $family;
            }
            $name = trim((string) ($row['classification_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }

    return trim($itemName) !== '' ? $itemName : $itemDescription;
}

function stock_catalog_next_number(mysqli $db, ?int $classificationId, string $itemName = '', string $itemDescription = ''): string
{
    $prefix = stock_catalog_item_code(stock_catalog_number_basis($db, $classificationId, $itemName, $itemDescription));
    if ($prefix === '') {
        return '';
    }

    $seriesStmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING_INDEX(stock_no, '-', -1) AS UNSIGNED)) AS max_series
        FROM stock_catalog
        WHERE stock_no LIKE CONCAT(?, '-%')
    ");
    if (!$seriesStmt) {
        return '';
    }

    $seriesStmt->bind_param('s', $prefix);
    $seriesStmt->execute();
    $seriesRow = $seriesStmt->get_result()->fetch_assoc();
    $seriesStmt->close();

    $nextSeries = ((int) ($seriesRow['max_series'] ?? 0)) + 1;

    return $prefix . '-' . str_pad((string) $nextSeries, 3, '0', STR_PAD_LEFT);
}

function account_code_default_useful_life_years(string $accountCode, string $accountGroup): ?int
{
    $accountCode = trim($accountCode);
    $accountGroup = trim($accountGroup);

    if ($accountGroup === 'supply' || $accountCode === '1.06.01.010.00') {
        return null;
    }
    if ($accountGroup === 'semi_expendable') {
        return 3;
    }

    $defaultsByAccount = [
        '1.06.02.010.00' => 20,
        '1.06.02.020.00' => 20,
        '1.06.02.990.00' => 20,
        '1.06.03.010.00' => 20,
        '1.06.03.020.00' => 20,
        '1.06.03.030.00' => 20,
        '1.06.03.040.00' => 20,
        '1.06.03.050.00' => 20,
        '1.06.03.060.00' => 20,
        '1.06.03.070.00' => 20,
        '1.06.03.090.00' => 20,
        '1.06.03.100.00' => 20,
        '1.06.04.010.00' => 30,
        '1.06.04.020.00' => 30,
        '1.06.04.030.00' => 30,
        '1.06.04.040.00' => 30,
        '1.06.04.050.00' => 30,
        '1.06.04.060.00' => 30,
        '1.06.04.990.00' => 20,
        '1.06.05.010.00' => 10,
        '1.06.05.020.00' => 5,
        '1.06.05.030.00' => 5,
        '1.06.05.040.00' => 10,
        '1.06.05.050.00' => 10,
        '1.06.05.060.00' => 10,
        '1.06.05.070.00' => 10,
        '1.06.05.080.00' => 10,
        '1.06.05.090.00' => 10,
        '1.06.05.100.00' => 10,
        '1.06.05.110.00' => 10,
        '1.06.05.120.00' => 5,
        '1.06.05.130.00' => 5,
        '1.06.05.140.00' => 10,
        '1.06.05.990.00' => 10,
        '1.06.06.010.00' => 7,
        '1.06.06.990.00' => 7,
        '1.06.07.010.00' => 10,
        '1.06.07.020.00' => 5,
        '1.06.08.010.00' => 10,
        '1.06.99.990.00' => 10,
        '1.08.01.020.00' => 10,
    ];

    return $defaultsByAccount[$accountCode] ?? null;
}

function classification_default_useful_life_years(mysqli $db, ?int $accountCodeId, string $classificationGroup = ''): ?int
{
    $accountCodeId = (int) ($accountCodeId ?? 0);
    if ($accountCodeId <= 0) {
        return null;
    }

    if (function_exists('schema_has_column') && schema_has_column($db, 'account_codes', 'default_useful_life_years')) {
        $accountDefaultStmt = $db->prepare("
            SELECT default_useful_life_years
            FROM account_codes
            WHERE id = ?
            LIMIT 1
        ");
        if ($accountDefaultStmt) {
            $accountDefaultStmt->bind_param('i', $accountCodeId);
            $accountDefaultStmt->execute();
            $accountDefault = $accountDefaultStmt->get_result()->fetch_assoc();
            $accountDefaultStmt->close();
            $defaultLife = (int) ($accountDefault['default_useful_life_years'] ?? 0);
            if ($defaultLife > 0) {
                return $defaultLife;
            }
        }
    }

    $accountStmt = $db->prepare("SELECT account_code, account_group FROM account_codes WHERE id = ? LIMIT 1");
    if (!$accountStmt) {
        return null;
    }

    $accountStmt->bind_param('i', $accountCodeId);
    $accountStmt->execute();
    $account = $accountStmt->get_result()->fetch_assoc();
    $accountStmt->close();

    $accountCode = trim((string) ($account['account_code'] ?? ''));
    $accountGroup = trim((string) ($account['account_group'] ?? $classificationGroup));

    return account_code_default_useful_life_years($accountCode, $accountGroup);
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        if (isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '') {
            $_SESSION['csrf_token_prev'] = $_SESSION['csrf_token'];
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool
{
    if (empty($_SESSION['csrf_token']) && empty($_SESSION['csrf_token_prev'])) {
        return false;
    }

    $posted = '';
    if (!empty($_POST['_csrf'])) {
        $posted = is_string($_POST['_csrf']) ? $_POST['_csrf'] : (string) $_POST['_csrf'];
    } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $posted = is_string($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    $posted = trim($posted);
    if ($posted === '') {
        return false;
    }

    $current = isset($_SESSION['csrf_token']) ? (string) $_SESSION['csrf_token'] : '';
    $previous = isset($_SESSION['csrf_token_prev']) ? (string) $_SESSION['csrf_token_prev'] : '';

    if ($current !== '' && hash_equals($current, $posted)) {
        return true;
    }

    return $previous !== '' && hash_equals($previous, $posted);
}

function reconcile_stock_item(mysqli $db, int $stockItemId): bool
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity_in), 0) AS total_in, COALESCE(SUM(quantity_out), 0) AS total_out FROM stock_movements WHERE stock_item_id = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $stockItemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $totalIn = isset($row['total_in']) ? (int) $row['total_in'] : 0;
    $totalOut = isset($row['total_out']) ? (int) $row['total_out'] : 0;

    $calculated = $totalIn - $totalOut;

    $update = $db->prepare("UPDATE stock_items SET quantity_on_hand = ? WHERE id = ?");
    if (!$update) {
        return false;
    }

    $update->bind_param('ii', $calculated, $stockItemId);
    $ok = $update->execute();
    $update->close();

    return (bool) $ok;
}

function recalculate_purchase_order_status(mysqli $db, int $purchaseOrderId): string
{
    if ($purchaseOrderId <= 0) {
        return 'encoded';
    }

    $orderedDeliveredStmt = $db->prepare(
        "SELECT
            COALESCE(SUM(poi.quantity), 0) AS total_ordered,
            COALESCE((
                SELECT SUM(ri2.quantity_delivered)
                FROM receiving_items ri2
                INNER JOIN receivings r2 ON r2.id = ri2.receiving_id
                WHERE r2.purchase_order_id = po.id
                  AND r2.status != 'cancelled'
            ), 0) AS total_delivered
         FROM purchase_order_items poi
         INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
         WHERE poi.purchase_order_id = ?"
    );

    if (!$orderedDeliveredStmt) {
        return 'encoded';
    }

    $orderedDeliveredStmt->bind_param('i', $purchaseOrderId);
    $orderedDeliveredStmt->execute();
    $orderedDelivered = $orderedDeliveredStmt->get_result()->fetch_assoc() ?: [
        'total_ordered' => 0,
        'total_delivered' => 0,
    ];
    $orderedDeliveredStmt->close();

    $totalOrdered = (float) ($orderedDelivered['total_ordered'] ?? 0);
    $totalDelivered = (float) ($orderedDelivered['total_delivered'] ?? 0);

    $hasFullDelivery = $totalOrdered > 0 && $totalDelivered >= $totalOrdered;

    $disposedCondition = '1=1';
    if (function_exists('schema_has_column') && schema_has_column($db, 'receiving_item_details', 'is_disposed')) {
        $disposedCondition = '(rid.is_disposed IS NULL OR rid.is_disposed = 0)';
    }

    $pendingDistributionSql =
        "SELECT COUNT(*) AS pending_count
         FROM receiving_item_details rid
         INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
         INNER JOIN receivings r ON r.id = ri.receiving_id
         INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
         WHERE r.purchase_order_id = ?
           AND r.status != 'cancelled'
           AND poi.item_type IN ('semi_expendable', 'equipment')
           AND rid.is_distributed = 0
           AND " . $disposedCondition;

    $pendingDistributionStmt = $db->prepare($pendingDistributionSql);
    if (!$pendingDistributionStmt) {
        return $totalDelivered > 0 ? 'partial' : 'encoded';
    }

    $pendingDistributionStmt->bind_param('i', $purchaseOrderId);
    $pendingDistributionStmt->execute();
    $pendingDistributionRow = $pendingDistributionStmt->get_result()->fetch_assoc() ?: ['pending_count' => 0];
    $pendingDistributionStmt->close();

    $pendingDistributionCount = (int) ($pendingDistributionRow['pending_count'] ?? 0);
    $hasCompleteDistribution = $pendingDistributionCount === 0;

    if ($hasFullDelivery && $hasCompleteDistribution) {
        return 'completed';
    }

    if ($totalDelivered > 0) {
        return 'partial';
    }

    return 'encoded';
}

function get_active_threshold(mysqli $db): array
{
    $defaults = ['equipment_min' => 50000.00, 'semi_hv_min' => 5000.01];
    $stmt = $db->prepare(
        "SELECT equipment_min, semi_hv_min FROM property_thresholds WHERE effective_date <= CURDATE() ORDER BY effective_date DESC, id DESC LIMIT 1"
    );
    if (!$stmt) {
        return $defaults;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $defaults;
    return [
        'equipment_min' => isset($row['equipment_min']) ? (float) $row['equipment_min'] : $defaults['equipment_min'],
        'semi_hv_min' => isset($row['semi_hv_min']) ? (float) $row['semi_hv_min'] : $defaults['semi_hv_min'],
    ];
}

function get_system_setting(mysqli $db, string $key, string $default = ''): string
{
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !array_key_exists('setting_value', $row) || $row['setting_value'] === null) {
        return $default;
    }

    return (string) $row['setting_value'];
}

function person_full_name(array $row): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row['name_prefix'] ?? '')),
        trim((string) ($row['first_name'] ?? '')),
        trim((string) ($row['middle_name'] ?? '')),
        trim((string) ($row['last_name'] ?? '')),
        trim((string) ($row['suffix_name'] ?? '')),
    ])));
}

function get_university_president_profile(mysqli $db): array
{
    $profile = [
        'name' => get_system_setting($db, 'university_president_name', ''),
        'title' => get_system_setting($db, 'university_president_title', ''),
        'appointment_date' => get_system_setting($db, 'university_president_appointment_date', ''),
    ];

    if ($profile['name'] !== '' && $profile['title'] !== '') {
        return $profile;
    }

    $presidentSql = "
        SELECT e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title
        FROM offices o
        LEFT JOIN employees e ON e.id = o.office_head_employee_id
        WHERE o.is_active = 1
          AND (
                o.office_name LIKE '%President%'
                OR o.office_code LIKE '%PRES%'
              )
        ORDER BY
            CASE
                WHEN o.office_name LIKE '%Office of the President%' THEN 0
                WHEN o.office_name LIKE '%President%' THEN 1
                ELSE 2
            END,
            o.office_name ASC
        LIMIT 1
    ";

    $presidentRes = $db->query($presidentSql);
    $president = $presidentRes ? ($presidentRes->fetch_assoc() ?: []) : [];

    if ($profile['name'] === '') {
        $profile['name'] = person_full_name($president);
    }
    if ($profile['title'] === '') {
        $profile['title'] = trim((string) ($president['position_title'] ?? ''));
    }
    if ($profile['title'] === '') {
        $profile['title'] = 'University President';
    }

    return $profile;
}

function classify_item_by_cost(float $unitCost, array $threshold): string
{
    if ($unitCost >= (float) $threshold['equipment_min']) {
        return 'equipment';
    }
    if ($unitCost >= (float) $threshold['semi_hv_min']) {
        return 'semi_expendable_hv';
    }
    return 'semi_expendable_lv';
}

function fund_number_from_source(?string $fundCode, ?string $fundSource = null): string
{
    $haystack = trim((string) $fundCode . ' ' . (string) $fundSource);
    if ($haystack !== '' && preg_match('/(?:^|[^0-9])(0[1567])(?:[^0-9]|$)/', $haystack, $matches)) {
        return $matches[1];
    }

    return '';
}

function coa_disposal_reason_options(): array
{
    return [
        'unserviceable' => 'Unserviceable',
        'damaged' => 'Damaged',
        'beyond_repair' => 'Beyond Repair',
        'destroyed' => 'Destroyed',
        'obsolete' => 'Obsolete',
        'lost' => 'Lost',
        'stolen' => 'Stolen',
    ];
}

function normalize_disposal_reason(?string $reason): string
{
    $raw = strtolower(trim((string) $reason));
    if ($raw === '') {
        return 'unserviceable';
    }

    $normalized = str_replace(['-', ' '], '_', $raw);
    $aliases = [
        'for_disposal' => 'unserviceable',
        'for_condemnation' => 'destroyed',
        'condemned' => 'destroyed',
        'broken' => 'damaged',
    ];
    if (isset($aliases[$normalized])) {
        $normalized = $aliases[$normalized];
    }

    return array_key_exists($normalized, coa_disposal_reason_options()) ? $normalized : 'unserviceable';
}

function disposal_reason_label(?string $reason): string
{
    $normalized = normalize_disposal_reason($reason);
    $options = coa_disposal_reason_options();

    return $options[$normalized] ?? 'Unserviceable';
}

function disposal_rlsddp_status_flags(?string $reason): array
{
    $normalized = normalize_disposal_reason($reason);

    return [
        'lost' => $normalized === 'lost',
        'damaged' => in_array($normalized, ['damaged', 'unserviceable', 'beyond_repair'], true),
        'stolen' => $normalized === 'stolen',
        'destroyed' => in_array($normalized, ['destroyed', 'obsolete'], true),
    ];
}

function disposal_unserviceable_reason_filters(): array
{
    return ['unserviceable', 'damaged', 'beyond_repair', 'obsolete', 'destroyed'];
}

function collect_non_empty_column_values(array $rows, string $key): array
{
    $values = [];
    foreach ($rows as $row) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            $values[$value] = true;
        }
    }

    return array_keys($values);
}

function report_fund_cluster(array $rows, string $selectedFundNumber = ''): string
{
    $selectedFundNumber = trim($selectedFundNumber);
    if ($selectedFundNumber !== '') {
        return $selectedFundNumber;
    }

    return implode(', ', collect_non_empty_column_values($rows, 'fund_number'));
}

function report_account_name(array $rows, ?array $selectedAccountCode, string $default = ''): string
{
    if ($selectedAccountCode) {
        $selectedName = trim((string) ($selectedAccountCode['account_name'] ?? ''));
        if ($selectedName !== '') {
            return $selectedName;
        }
    }

    $accountNames = collect_non_empty_column_values($rows, 'account_name');
    if ($accountNames) {
        return implode(', ', $accountNames);
    }

    return $default;
}

function render_print_action_bar(): void
{
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button>
        <button class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
    </div>
    <?php
}

function stream_csv_download(string $filename, array $headers, array $rows, callable $rowMapper): void
{
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    if ($safeFilename === '' || $safeFilename === null) {
        $safeFilename = 'export.csv';
    }
    if (!str_ends_with(strtolower($safeFilename), '.csv')) {
        $safeFilename .= '.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $rowMapper($row));
    }
    fclose($output);
    exit;
}

function render_simple_report_header(string $appendix, string $title, string $asOf, string $fundCluster, string $entityName = APP_NAME): void
{
    ?>
    <div class="text-center mb-3">
        <div class="small fst-italic"><?php echo h($appendix); ?></div>
        <h4 class="mb-1"><?php echo h($title); ?></h4>
        <div>As at <?php echo h($asOf); ?></div>
        <div>Entity Name: <?php echo h($entityName); ?> | Fund Cluster: <?php echo h($fundCluster); ?></div>
    </div>
    <?php
}

function render_inventory_committee_signature_grid(string $tableClass): void
{
    ?>
    <table class="<?php echo h($tableClass); ?>">
        <tr>
            <td style="width:33.33%;">
                <div class="sign-label">Certified Correct by:</div>
                <div class="sign-line"></div>
                <div class="sign-caption">Signature over Printed Name of<br>Inventory Committee Chair and<br>Members</div>
            </td>
            <td style="width:33.33%;">
                <div class="sign-label">Approved by:</div>
                <div class="sign-line"></div>
                <div class="sign-caption">Signature over Printed Name of Head of<br>Agency/Entity or Authorized Representative</div>
            </td>
            <td style="width:33.33%;">
                <div class="sign-label">Verified by:</div>
                <div class="sign-line"></div>
                <div class="sign-caption">Signature over Printed Name of COA<br>Representative</div>
            </td>
        </tr>
    </table>
    <?php
}

function ensure_legacy_assets_fund_column(mysqli $db): void
{
    // Migration-owned schema: runtime requests must not alter table structure.
}

function ensure_legacy_assets_unit_of_measure_column(mysqli $db): void
{
    schema_require_columns($db, 'legacy_assets', ['unit_of_measure_id']);
}

function ensure_legacy_assets_rpcppe_tracking_columns(mysqli $db): void
{
    schema_require_columns($db, 'legacy_assets', [
        'is_rpcppe_candidate',
        'rpcppe_status',
        'rpcppe_submitted_at',
        'rpcppe_reconciled_at',
    ]);
}

function ensure_distribution_item_rpcppe_tracking_columns(mysqli $db): void
{
    schema_require_columns($db, 'distribution_item_details', [
        'is_rpcppe_candidate',
        'rpcppe_status',
        'rpcppe_submitted_at',
        'rpcppe_reconciled_at',
    ]);
}

function ensure_receiving_item_variance_columns(mysqli $db): void
{
    schema_require_columns($db, 'receiving_items', [
        'actual_item_description',
        'variance_type',
        'variance_note',
        'accepted_no_additional_cost',
    ]);
}

function ensure_rpcppe_batch_tracking_columns(mysqli $db): void
{
    schema_require_columns($db, 'rpcppe_batch_items', [
        'reconciliation_status',
        'is_included',
    ]);
}

function rpcppe_status_options(): array
{
    return [
        'excluded' => 'Excluded',
        'included_draft' => 'Included Draft',
        'submitted_to_accounting' => 'Submitted',
        'reconciled' => 'Reconciled',
    ];
}

function rpcppe_normalize_status(string $status, bool $isIncluded): string
{
    $status = trim($status);
    $options = rpcppe_status_options();
    if (!isset($options[$status])) {
        $status = $isIncluded ? 'included_draft' : 'excluded';
    }

    if (!$isIncluded) {
        return 'excluded';
    }

    if ($status === 'excluded') {
        return 'included_draft';
    }

    return $status;
}

function rpcppe_status_badge_class(string $status): string
{
    switch ($status) {
        case 'reconciled':
            return 'text-bg-success';
        case 'submitted_to_accounting':
            return 'text-bg-primary';
        case 'included_draft':
            return 'text-bg-warning';
        default:
            return 'text-bg-secondary';
    }
}

function rpcppe_status_label(string $status): string
{
    $options = rpcppe_status_options();
    return $options[$status] ?? 'Excluded';
}

function ensure_legacy_assets_po_number_column(mysqli $db): void
{
    schema_require_columns($db, 'legacy_assets', ['po_number']);
}

function ensure_legacy_assets_accountability_no_column(mysqli $db): void
{
    schema_require_columns($db, 'legacy_assets', ['accountability_no']);
}

function ensure_legacy_assets_accountability_tracking_columns(mysqli $db): void
{
    schema_require_columns($db, 'legacy_assets', [
        'accountability_status',
        'last_office_id',
        'last_employee_id',
        'last_responsibility_code_id',
        'accountability_cleared_at',
        'accountability_cleared_by',
    ]);
}

function legacy_asset_accountability_status_label(string $status): string
{
    return $status === 'for_reconciliation' ? 'For Reconciliation' : 'Active';
}

function legacy_asset_normalize_accountability_status(string $status): string
{
    return $status === 'for_reconciliation' ? 'for_reconciliation' : 'active';
}

function legacy_asset_distribution_prefix(mysqli $db, string $itemType, float $unitCost): string
{
    if ($itemType === 'equipment') {
        return 'PAR';
    }

    $threshold = get_active_threshold($db);
    $semiHighValueMin = (float) ($threshold['semi_hv_min'] ?? 5000.01);

    return $unitCost >= $semiHighValueMin ? 'SPHV' : 'SPLV';
}

function next_distribution_style_document_no(mysqli $db, string $prefix, int $year, ?int $month = null): string
{
    $prefix = strtoupper(trim($prefix));
    $seriesPrefix = $prefix . '-' . $year;
    $docPrefix = $month !== null
        ? sprintf('%s-%04d-%02d', $prefix, $year, $month)
        : $seriesPrefix;
    $moduleKey = 'distribution_doc_no|' . $seriesPrefix;
    $padding = 4;
    $nextSeq = 1;
    $currentValue = 0;
    $counterExists = false;

    $stmt = $db->prepare("SELECT current_value FROM series_numbers WHERE module_key = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $moduleKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $counterExists = true;
            $currentValue = max($currentValue, (int) ($row['current_value'] ?? 0));
        }
    }

    $like = $seriesPrefix . '-%';

    $distributionStmt = $db->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(document_no, '-', -1) AS UNSIGNED)), 0) AS current_value
         FROM distributions
         WHERE document_no LIKE ?"
    );
    if ($distributionStmt) {
        $distributionStmt->bind_param('s', $like);
        $distributionStmt->execute();
        $distributionRow = $distributionStmt->get_result()->fetch_assoc();
        $currentValue = max($currentValue, (int) ($distributionRow['current_value'] ?? 0));
        $distributionStmt->close();
    }

    if (function_exists('schema_has_column') && schema_has_column($db, 'legacy_assets', 'accountability_no')) {
        $legacyStmt = $db->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(accountability_no, '-', -1) AS UNSIGNED)), 0) AS current_value
             FROM legacy_assets
             WHERE accountability_no LIKE ?"
        );
        if ($legacyStmt) {
            $legacyStmt->bind_param('s', $like);
            $legacyStmt->execute();
            $legacyRow = $legacyStmt->get_result()->fetch_assoc();
            $currentValue = max($currentValue, (int) ($legacyRow['current_value'] ?? 0));
            $legacyStmt->close();
        }
    }

    if (!$counterExists) {
        $insertStmt = $db->prepare(
            "INSERT INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE module_key = module_key"
        );
        if ($insertStmt) {
            $insertStmt->bind_param('ssiii', $moduleKey, $seriesPrefix, $year, $currentValue, $padding);
            $insertStmt->execute();
            $insertStmt->close();
        }
    }

    $nextSeq = $currentValue + 1;

    $updateStmt = $db->prepare("UPDATE series_numbers SET current_value = ? WHERE module_key = ?");
    if ($updateStmt) {
        $currentValue = $nextSeq;
        $updateStmt->bind_param('is', $currentValue, $moduleKey);
        $updateStmt->execute();
        $updateStmt->close();
    }

    return $docPrefix . '-' . str_pad((string) $nextSeq, $padding, '0', STR_PAD_LEFT);
}

function ensure_legacy_asset_accountability_no(
    mysqli $db,
    int $legacyAssetId,
    string $itemType,
    float $unitCost,
    string $currentValue = '',
    ?string $documentDate = null
): string {
    ensure_legacy_assets_accountability_no_column($db);

    $currentValue = trim($currentValue);
    $isEquipment = $itemType === 'equipment';
    $isCurrentFormatValid = $currentValue !== '' && (
        ($isEquipment && preg_match('/^PAR-\d{4}-\d{2}-\d{4}$/', $currentValue))
        || (!$isEquipment && preg_match('/^(SPHV|SPLV)-\d{4}-\d{2}-\d{4}$/', $currentValue))
    );
    if ($isCurrentFormatValid) {
        return $currentValue;
    }

    $prefix = legacy_asset_distribution_prefix($db, $itemType, $unitCost);
    $timestamp = strtotime((string) $documentDate);
    if ($timestamp === false) {
        $timestamp = time();
    }
    $year = (int) date('Y', $timestamp);
    $month = (int) date('m', $timestamp);
    $documentNo = next_distribution_style_document_no($db, $prefix, $year, $month);

    $stmt = $db->prepare("UPDATE legacy_assets SET accountability_no = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('si', $documentNo, $legacyAssetId);
        $stmt->execute();
        $stmt->close();
    }

    return $documentNo;
}

function ensure_asset_location_tracking_schema(mysqli $db): void
{
    ensure_locations_schema($db);

    schema_require_columns($db, 'distribution_item_details', [
        'manual_location',
        'location_id',
        'location_lat',
        'location_lng',
        'location_updated_at',
        'location_updated_by',
    ]);
    schema_require_columns($db, 'legacy_assets', [
        'manual_location',
        'location_id',
        'location_lat',
        'location_lng',
        'location_updated_at',
        'location_updated_by',
    ]);
    schema_require_table($db, 'asset_location_history');
    schema_require_columns($db, 'asset_location_history', [
        'asset_source',
        'asset_id',
        'inventory_session_id',
        'inventory_count_item_id',
        'changed_by',
        'change_reason',
        'old_manual_location',
        'old_location_id',
        'old_latitude',
        'old_longitude',
        'new_manual_location',
        'new_location_id',
        'new_latitude',
        'new_longitude',
        'created_at',
    ]);
}

function ensure_locations_schema(mysqli $db): void
{
    schema_require_table($db, 'locations');
    schema_require_columns($db, 'locations', [
        'location_code',
        'location_name',
        'description',
        'is_active',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ]);
}

function update_asset_location_snapshot(
    mysqli $db,
    string $assetSource,
    int $assetId,
    string $manualLocation,
    ?float $latitude,
    ?float $longitude,
    int $changedBy = 0,
    string $changeReason = 'manual_update',
    ?int $inventorySessionId = null,
    ?int $inventoryCountItemId = null,
    ?int $locationId = null
): bool {
    ensure_asset_location_tracking_schema($db);

    if (!in_array($assetSource, ['system', 'legacy'], true) || $assetId <= 0) {
        return false;
    }

    $table = $assetSource === 'legacy' ? 'legacy_assets' : 'distribution_item_details';
    $selectStmt = $db->prepare("SELECT manual_location, location_id, location_lat, location_lng FROM {$table} WHERE id = ? LIMIT 1");
    if (!$selectStmt) {
        return false;
    }

    $selectStmt->bind_param('i', $assetId);
    $selectStmt->execute();
    $current = $selectStmt->get_result()->fetch_assoc();
    $selectStmt->close();
    if (!$current) {
        return false;
    }

    $manualLocation = trim($manualLocation);
    $oldManualLocation = trim((string) ($current['manual_location'] ?? ''));
    $oldLocationId = (int) ($current['location_id'] ?? 0);
    $locationIdValue = $locationId === null ? $oldLocationId : max(0, (int) $locationId);
    $oldLatitude = isset($current['location_lat']) ? (float) $current['location_lat'] : null;
    $oldLongitude = isset($current['location_lng']) ? (float) $current['location_lng'] : null;

    $oldLatitudeStr = $oldLatitude === null ? '' : number_format($oldLatitude, 7, '.', '');
    $oldLongitudeStr = $oldLongitude === null ? '' : number_format($oldLongitude, 7, '.', '');
    $newLatitudeStr = $latitude === null ? '' : number_format($latitude, 7, '.', '');
    $newLongitudeStr = $longitude === null ? '' : number_format($longitude, 7, '.', '');

    if (
        $manualLocation === $oldManualLocation
        && $locationIdValue === $oldLocationId
        && $newLatitudeStr === $oldLatitudeStr
        && $newLongitudeStr === $oldLongitudeStr
    ) {
        return true;
    }

    $updateStmt = $db->prepare("UPDATE {$table}
        SET manual_location = NULLIF(?, ''),
            location_id = NULLIF(?, 0),
            location_lat = NULLIF(?, ''),
            location_lng = NULLIF(?, ''),
            location_updated_at = NOW(),
            location_updated_by = NULLIF(?, 0)
        WHERE id = ?");
    if (!$updateStmt) {
        return false;
    }

    $updateStmt->bind_param('sissii', $manualLocation, $locationIdValue, $newLatitudeStr, $newLongitudeStr, $changedBy, $assetId);
    $saved = $updateStmt->execute();
    $updateStmt->close();
    if (!$saved) {
        return false;
    }

    $historyStmt = $db->prepare("INSERT INTO asset_location_history
        (asset_source, asset_id, inventory_session_id, inventory_count_item_id, changed_by, change_reason,
         old_manual_location, old_location_id, old_latitude, old_longitude, new_manual_location, new_location_id, new_latitude, new_longitude)
        VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?,
                NULLIF(?, ''), NULLIF(?, 0), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, 0), NULLIF(?, ''), NULLIF(?, ''))");
    if ($historyStmt) {
        $sessionIdValue = max(0, (int) ($inventorySessionId ?? 0));
        $countItemIdValue = max(0, (int) ($inventoryCountItemId ?? 0));
        $historyStmt->bind_param(
            'siiiississsiss',
            $assetSource,
            $assetId,
            $sessionIdValue,
            $countItemIdValue,
            $changedBy,
            $changeReason,
            $oldManualLocation,
            $oldLocationId,
            $oldLatitudeStr,
            $oldLongitudeStr,
            $manualLocation,
            $locationIdValue,
            $newLatitudeStr,
            $newLongitudeStr
        );
        $historyStmt->execute();
        $historyStmt->close();
    }

    return true;
}

function ensure_office_location_pin_schema(mysqli $db): void
{
    schema_require_table($db, 'office_location_pins');
    schema_require_columns($db, 'office_location_pins', [
        'office_id',
        'office_name_snapshot',
        'manual_location',
        'location_lat',
        'location_lng',
        'updated_by',
        'updated_at',
        'created_at',
    ]);
}

function get_office_location_pin(mysqli $db, int $officeId): ?array
{
    ensure_office_location_pin_schema($db);
    if ($officeId <= 0) {
        return null;
    }

    $stmt = $db->prepare("SELECT office_id, office_name_snapshot, manual_location, location_lat, location_lng
        FROM office_location_pins
        WHERE office_id = ?
        LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $officeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row ?: null;
}

function upsert_office_location_pin(
    mysqli $db,
    int $officeId,
    string $officeNameSnapshot,
    string $manualLocation,
    float $latitude,
    float $longitude,
    int $updatedBy = 0
): bool {
    ensure_office_location_pin_schema($db);
    if ($officeId <= 0) {
        return false;
    }

    $officeNameSnapshot = trim($officeNameSnapshot);
    $manualLocation = trim($manualLocation);
    $latValue = number_format($latitude, 7, '.', '');
    $lngValue = number_format($longitude, 7, '.', '');

    $stmt = $db->prepare("INSERT INTO office_location_pins
        (office_id, office_name_snapshot, manual_location, location_lat, location_lng, updated_by)
        VALUES (?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, 0))
        ON DUPLICATE KEY UPDATE
            office_name_snapshot = VALUES(office_name_snapshot),
            manual_location = VALUES(manual_location),
            location_lat = VALUES(location_lat),
            location_lng = VALUES(location_lng),
            updated_by = VALUES(updated_by),
            updated_at = NOW()");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('issssi', $officeId, $officeNameSnapshot, $manualLocation, $latValue, $lngValue, $updatedBy);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function generate_property_number(
    $db,
    string $year,
    string $fundCode,
    string $accountCode,
    string $officeCode
): string {
    // Use the numeric fund segment (e.g. 05, 01, 06, 07) in the property number.
    $fundSegment = trim($fundCode);
    if ($fundSegment === '') {
        $fundSegment = 'GEN';
    }
    $fundSegment = preg_replace('/[^0-9]/', '', $fundSegment);
    if ($fundSegment !== '') {
        $fundSegment = str_pad(substr($fundSegment, -2), 2, '0', STR_PAD_LEFT);
    }
    if ($fundSegment === '') {
        $fundSegment = 'GEN';
    }

    // For Semi-Expendable ME (03.210.xx): use segments 3, 4, 5 (indices 2,3,4) → 03.210.01/02/03
    // For other Equipment (05.xxx.xx): use segments 3, 4 (indices 2,3) → 05.030/140/990
    $acctParts = explode('.', $accountCode);
    $acctShort = $accountCode; // fallback
    
    // Check if semi-expendable (contains 03.210)
    if (isset($acctParts[2]) && isset($acctParts[3]) && $acctParts[2] === '03' && $acctParts[3] === '210') {
        // Semi-expendable: use all 5 segments via indices 2,3,4
        if (isset($acctParts[4])) {
            $acctShort = $acctParts[2] . '.' . $acctParts[3] . '.' . $acctParts[4];
        }
    } elseif (isset($acctParts[2]) && isset($acctParts[3])) {
        // Other equipment: use segments 3,4 via indices 2,3
        $acctShort = $acctParts[2] . '.' . $acctParts[3];
    }
    $acctShort = trim((string) $acctShort);
    if ($acctShort === '') {
        $acctShort = 'GEN';
    }

    $prefix = $year . '-' . $fundSegment . '-' . $acctShort;

    // Office suffix is kept as a display/disambiguation suffix.
    // Series is still controlled by prefix only (year-fund-account bucket).
    $officeSuffix = strtoupper(trim($officeCode));
    $officeSuffix = preg_replace('/[^A-Z0-9]/', '', $officeSuffix);
    if ($officeSuffix === '') {
        $officeSuffix = 'GEN';
    }

    // One series per account-code bucket (prefix = year+fund+acctShort).
    $seriesModuleKey = 'property_number|' . $prefix;
    $padding = 4;
    $nextSeq = 1;

    $stmt = $db->prepare("SELECT current_value FROM series_numbers WHERE module_key = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $seriesModuleKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $nextSeq = ((int) $row['current_value']) + 1;
        } else {
            // Seed: find the highest existing sequence for this prefix across all offices.
            $seedPattern = $prefix . '-%';
            $currentValue = 0;

            // Check distribution_item_details
            $seedStmt = $db->prepare(
                "SELECT COALESCE(MAX(
                    CAST(
                        SUBSTRING_INDEX(
                            SUBSTRING_INDEX(property_number, '-', 4),
                            '-',
                            -1
                        ) AS UNSIGNED
                    )
                 ), 0) AS current_value
                                 FROM distribution_item_details
                                 WHERE property_number LIKE ?"
            );
            if ($seedStmt) {
                                $seedStmt->bind_param('s', $seedPattern);
                $seedStmt->execute();
                $seedRow = $seedStmt->get_result()->fetch_assoc();
                $currentValue = max($currentValue, (int) ($seedRow['current_value'] ?? 0));
                $seedStmt->close();
            }

            // Check legacy_assets (beginning balance entries)
            $legacySeedStmt = $db->prepare(
                "SELECT COALESCE(MAX(
                    CAST(
                        SUBSTRING_INDEX(
                            SUBSTRING_INDEX(property_number, '-', 4),
                            '-',
                            -1
                        ) AS UNSIGNED
                    )
                 ), 0) AS current_value
                                 FROM legacy_assets
                                 WHERE property_number LIKE ?"
            );
            if ($legacySeedStmt) {
                                $legacySeedStmt->bind_param('s', $seedPattern);
                $legacySeedStmt->execute();
                $legacySeedRow = $legacySeedStmt->get_result()->fetch_assoc();
                $currentValue = max($currentValue, (int) ($legacySeedRow['current_value'] ?? 0));
                $legacySeedStmt->close();
            }

            $insertStmt = $db->prepare(
                "INSERT INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
                 VALUES (?, ?, NULL, ?, ?)
                 ON DUPLICATE KEY UPDATE module_key = module_key"
            );
            if ($insertStmt) {
                $insertStmt->bind_param('ssii', $seriesModuleKey, $prefix, $currentValue, $padding);
                $insertStmt->execute();
                $insertStmt->close();
            }
            $nextSeq = $currentValue + 1;
        }
    }

    $updateStmt = $db->prepare("UPDATE series_numbers SET current_value = ? WHERE module_key = ?");
    if ($updateStmt) {
        $currentValue = $nextSeq;
        $updateStmt->bind_param('is', $currentValue, $seriesModuleKey);
        $updateStmt->execute();
        $updateStmt->close();
    }

        return $prefix
            . '-' . str_pad((string) $nextSeq, $padding, '0', STR_PAD_LEFT)
            . '-' . $officeSuffix;
}

function legacy_asset_should_split_per_unit(string $itemType, int $quantity): bool
{
    return in_array(trim($itemType), ['equipment', 'semi_expendable'], true) && $quantity > 1;
}

function legacy_asset_sync_item_name(mysqli $db, int $legacyAssetId, string $itemName, int $itemNameId = 0): void
{
    if ($legacyAssetId <= 0 || !schema_has_column($db, 'legacy_assets', 'item_name')) {
        return;
    }

    $itemNameSql = schema_has_column($db, 'legacy_assets', 'item_name_id')
        ? "UPDATE legacy_assets SET item_name = NULLIF(?, ''), item_name_id = NULLIF(?, 0) WHERE id = ?"
        : "UPDATE legacy_assets SET item_name = NULLIF(?, '') WHERE id = ?";
    $itemNameStmt = $db->prepare($itemNameSql);
    if (!$itemNameStmt) {
        return;
    }

    if (schema_has_column($db, 'legacy_assets', 'item_name_id')) {
        $itemNameStmt->bind_param('sii', $itemName, $itemNameId, $legacyAssetId);
    } else {
        $itemNameStmt->bind_param('si', $itemName, $legacyAssetId);
    }
    $itemNameStmt->execute();
    $itemNameStmt->close();
}

function legacy_asset_insert_record(mysqli $db, array $payload): int
{
    $stmt = $db->prepare(
        "INSERT INTO legacy_assets
            (system_reference, po_number, property_number, item_type, item_description, classification_id, account_code_id, fund_id, supplier_id, brand_id, model_id, brand, model, serial_no, acquisition_date, quantity, unit_of_measure_id, unit_cost, acquisition_cost, office_id, employee_id, responsibility_code_id, condition_status, remarks, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return 0;
    }

    $systemReference = (string) ($payload['system_reference'] ?? '');
    $poNumber = (string) ($payload['po_number'] ?? '');
    $propertyNumber = (string) ($payload['property_number'] ?? '');
    $itemType = (string) ($payload['item_type'] ?? 'equipment');
    $itemDescription = (string) ($payload['item_description'] ?? '');
    $classificationId = isset($payload['classification_id']) ? (int) $payload['classification_id'] : null;
    $accountCodeId = isset($payload['account_code_id']) ? (int) $payload['account_code_id'] : null;
    $fundId = isset($payload['fund_id']) ? (int) $payload['fund_id'] : null;
    $supplierId = isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : null;
    $brandId = isset($payload['brand_id']) ? (int) $payload['brand_id'] : null;
    $modelId = isset($payload['model_id']) ? (int) $payload['model_id'] : null;
    $brand = (string) ($payload['brand'] ?? '');
    $model = (string) ($payload['model'] ?? '');
    $serialNo = (string) ($payload['serial_no'] ?? '');
    $acquisitionDate = (string) ($payload['acquisition_date'] ?? '');
    $quantity = max(1, (int) ($payload['quantity'] ?? 1));
    $unitOfMeasureId = isset($payload['unit_of_measure_id']) ? (int) $payload['unit_of_measure_id'] : null;
    $unitCost = round((float) ($payload['unit_cost'] ?? 0), 2);
    $acquisitionCost = round((float) ($payload['acquisition_cost'] ?? 0), 2);
    $officeId = isset($payload['office_id']) ? (int) $payload['office_id'] : null;
    $employeeId = isset($payload['employee_id']) ? (int) $payload['employee_id'] : null;
    $responsibilityCodeId = isset($payload['responsibility_code_id']) ? (int) $payload['responsibility_code_id'] : null;
    $conditionStatus = (string) ($payload['condition_status'] ?? 'good');
    $remarks = (string) ($payload['remarks'] ?? '');
    $createdBy = isset($payload['created_by']) ? (int) $payload['created_by'] : null;

    $stmt->bind_param(
        'sssssiiiiiissssiiddiiissi',
        $systemReference,
        $poNumber,
        $propertyNumber,
        $itemType,
        $itemDescription,
        $classificationId,
        $accountCodeId,
        $fundId,
        $supplierId,
        $brandId,
        $modelId,
        $brand,
        $model,
        $serialNo,
        $acquisitionDate,
        $quantity,
        $unitOfMeasureId,
        $unitCost,
        $acquisitionCost,
        $officeId,
        $employeeId,
        $responsibilityCodeId,
        $conditionStatus,
        $remarks,
        $createdBy
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $legacyAssetId = (int) $stmt->insert_id;
    $stmt->close();

    legacy_asset_sync_item_name(
        $db,
        $legacyAssetId,
        (string) ($payload['item_name'] ?? ''),
        isset($payload['item_name_id']) ? (int) $payload['item_name_id'] : 0
    );

    return $legacyAssetId;
}
