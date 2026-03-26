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

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . base_url('auth/login.php'));
        exit;
    }
}

function require_role(string ...$allowedRoles): void
{
    require_login();

    $role = isset($_SESSION['user_role']) ? (string) $_SESSION['user_role'] : '';

    if (!in_array($role, $allowedRoles, true)) {
        set_flash('error', 'Access denied.');
        redirect('dashboard/index.php');
    }
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
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

function old(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) ? trim((string) $source[$key]) : $default;
}

function employee_display_name(array $employee): string
{
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

    return trim($name);
}

function module_series_defaults(): array
{
    return [
        'departments' => ['prefix' => 'DEP', 'use_year' => true, 'padding' => 4],
        'offices' => ['prefix' => 'OFF', 'use_year' => true, 'padding' => 4],
        'employees' => ['prefix' => 'EMP', 'use_year' => true, 'padding' => 4],
        'suppliers' => ['prefix' => 'SUP', 'use_year' => true, 'padding' => 4],
        'funds' => ['prefix' => 'FND', 'use_year' => true, 'padding' => 4],
        'classifications' => ['prefix' => 'CLS', 'use_year' => true, 'padding' => 4],
        'mode_of_procurements' => ['prefix' => 'MOP', 'use_year' => true, 'padding' => 4],
        'unit_of_measures' => ['prefix' => 'UOM', 'use_year' => true, 'padding' => 4],
        'brands' => ['prefix' => 'BRD', 'use_year' => true, 'padding' => 4],
        'models' => ['prefix' => 'MDL', 'use_year' => true, 'padding' => 4],
        'stock_items' => ['prefix' => 'STK', 'use_year' => true, 'padding' => 4],
        'issuances' => ['prefix' => 'ISS', 'use_year' => true, 'padding' => 4],
        'distributions' => ['prefix' => 'DST', 'use_year' => true, 'padding' => 4],
        'purchase_orders' => ['prefix' => 'POREC', 'use_year' => true, 'padding' => 4],
        'receivings' => ['prefix' => 'RCV', 'use_year' => true, 'padding' => 4],
    ];
}

function ensure_series_row(mysqli $db, string $moduleKey): void
{
    $defaults = module_series_defaults()[$moduleKey] ?? null;
    if (!$defaults) {
        return;
    }

    $yearValue = !empty($defaults['use_year']) ? (int) date('Y') : null;
    $stmt = $db->prepare("
        INSERT INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
        VALUES (?, ?, ?, 0, ?)
        ON DUPLICATE KEY UPDATE module_key = module_key
    ");

    if ($stmt) {
        $stmt->bind_param('ssii', $moduleKey, $defaults['prefix'], $yearValue, $defaults['padding']);
        $stmt->execute();
        $stmt->close();
    }
}

function preview_module_code(mysqli $db, string $moduleKey, ?string $customPrefix = null, ?int $customYear = null): string
{
    ensure_series_row($db, $moduleKey);

    $stmt = $db->prepare("SELECT prefix, year_value, current_value, padding_length FROM series_numbers WHERE module_key = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('s', $moduleKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return '';
    }

    $prefix = $customPrefix ?: $row['prefix'];
    $yearValue = $customYear ?? ($row['year_value'] !== null ? (int) $row['year_value'] : null);
    $nextValue = ((int) $row['current_value']) + 1;
    $padding = (int) $row['padding_length'];

    return build_series_code($prefix, $yearValue, $nextValue, $padding);
}

function next_module_code(mysqli $db, string $moduleKey): string
{
    ensure_series_row($db, $moduleKey);

    $stmt = $db->prepare("SELECT prefix, year_value, current_value, padding_length FROM series_numbers WHERE module_key = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('s', $moduleKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return '';
    }

    $prefix = $row['prefix'];
    $yearValue = $row['year_value'] !== null ? (int) $row['year_value'] : null;
    $currentYear = (int) date('Y');
    $padding = (int) $row['padding_length'];

    if ($row['year_value'] !== null && (int) $row['year_value'] !== $currentYear) {
        $resetStmt = $db->prepare("UPDATE series_numbers SET year_value = ?, current_value = 0 WHERE module_key = ?");
        if ($resetStmt) {
            $resetStmt->bind_param('is', $currentYear, $moduleKey);
            $resetStmt->execute();
            $resetStmt->close();
        }

        $yearValue = $currentYear;
    }

    $updateStmt = $db->prepare("UPDATE series_numbers SET current_value = LAST_INSERT_ID(current_value + 1) WHERE module_key = ?");
    if (!$updateStmt) {
        return '';
    }

    $updateStmt->bind_param('s', $moduleKey);
    $updateStmt->execute();
    $updateStmt->close();

    $lastStmt = $db->prepare("SELECT LAST_INSERT_ID() AS last_id");
    if (!$lastStmt) {
        return '';
    }

    $lastStmt->execute();
    $lastResult = $lastStmt->get_result();
    $lastRow = $lastResult ? $lastResult->fetch_assoc() : null;
    $lastStmt->close();

    if (!$lastRow || !isset($lastRow['last_id'])) {
        return '';
    }

    $assigned = (int) $lastRow['last_id'];

    return build_series_code($prefix, $yearValue, $assigned, $padding);
}

function build_series_code(string $prefix, ?int $yearValue, int $number, int $padding): string
{
    $parts = [$prefix];

    if ($yearValue !== null) {
        $parts[] = (string) $yearValue;
    }

    $parts[] = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);

    return implode('-', $parts);
}

function stock_catalog_category_code(string $accountName): string
{
    $normalized = strtoupper(trim(preg_replace('/[^A-Za-z0-9& ]+/', ' ', $accountName)));
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
        return substr($words[0], 0, 1) . substr($words[1], 0, 1);
    }

    $single = $words ? $words[0] : $normalized;
    $single = preg_replace('/[^A-Z0-9]/', '', $single);
    $single = substr($single, 0, 2);

    return str_pad($single, 2, 'X');
}

function stock_catalog_next_number(mysqli $db, int $accountCodeId): string
{
    if ($accountCodeId <= 0) {
        return '';
    }

    $accountStmt = $db->prepare("SELECT account_name FROM account_codes WHERE id = ? LIMIT 1");
    if (!$accountStmt) {
        return '';
    }

    $accountStmt->bind_param('i', $accountCodeId);
    $accountStmt->execute();
    $accountRow = $accountStmt->get_result()->fetch_assoc();
    $accountStmt->close();

    $accountName = (string) ($accountRow['account_name'] ?? '');
    if ($accountName === '') {
        return '';
    }

    $prefix = stock_catalog_category_code($accountName);

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

    return $prefix . '-' . str_pad((string) $nextSeries, 4, '0', STR_PAD_LEFT);
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool
{
    if (empty($_POST['_csrf']) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    $posted = is_string($_POST['_csrf']) ? $_POST['_csrf'] : (string) $_POST['_csrf'];
    return hash_equals((string) $_SESSION['csrf_token'], $posted);
}

function paginate(mysqli $db, string $countSql, string $dataSql, array $params, string $types, int $page, int $perPage = 20): array
{
    $page = max(1, $page);

    $bindToStmt = function (mysqli_stmt $stmt, string $types, array $values): void {
        if ($types === '') {
            return;
        }

        $bindValues = $values;
        $refs = [];
        foreach ($bindValues as $key => $val) {
            $refs[$key] = &$bindValues[$key];
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    };

    // Count total
    $total = 0;
    $countStmt = $db->prepare($countSql);
    if ($countStmt) {
        if ($types !== '') {
            $bindToStmt($countStmt, $types, $params);
        }
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult ? $countResult->fetch_assoc() : null;
        $countStmt->close();

        if ($countRow && isset($countRow['total'])) {
            $total = (int) $countRow['total'];
        } elseif ($countRow && isset($countRow['COUNT(*)'])) {
            $total = (int) $countRow['COUNT(*)'];
        }
    }

    $total_pages = $total > 0 ? (int) ceil($total / $perPage) : 0;
    if ($total_pages > 0) {
        $page = min($page, $total_pages);
    }

    $offset = ($page - 1) * $perPage;

    // Fetch data with LIMIT/OFFSET
    $data = [];
    $finalSql = $dataSql . " LIMIT ? OFFSET ?";
    $dataStmt = $db->prepare($finalSql);
    if ($dataStmt) {
        $finalTypes = $types . 'ii';
        $finalParams = $params;
        $finalParams[] = $perPage;
        $finalParams[] = $offset;

        if ($finalTypes !== '') {
            $bindToStmt($dataStmt, $finalTypes, $finalParams);
        }

        $dataStmt->execute();
        $result = $dataStmt->get_result();
        if ($result) {
            $data = $result->fetch_all(MYSQLI_ASSOC);
        }
        $dataStmt->close();
    }

    return [
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $total_pages,
    ];
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

function generate_property_number(
    $db,
    string $year,
    string $fundCode,
    string $accountCode,
    string $rcCode
): string {
    // Extract fund number (last numeric segment)
    $fundParts = explode('-', $fundCode);
    $fundNumber = '';
    foreach (array_reverse($fundParts) as $part) {
        $part = trim($part);
        if (is_numeric($part)) {
            $fundNumber = str_pad($part, 2, '0', STR_PAD_LEFT);
            break;
        }
    }
    if ($fundNumber === '') {
        $fundNumber = preg_replace('/[^0-9]/', '', $fundCode);
    }

    // Extract 3rd and 4th decimal segments from account code
    $acctParts = explode('.', $accountCode);
    if (isset($acctParts[2]) && isset($acctParts[3])) {
        $acctShort = $acctParts[2] . '.' . $acctParts[3];
    } else {
        $acctShort = $accountCode;
    }

    // Extract RC short code — remove leading RC- prefix
    $rcShort = preg_replace('/^RC-/i', '', trim($rcCode));
    if ($rcShort === '') $rcShort = $rcCode;

    // Build the LIKE pattern for series lookup
    $prefix = $year . '-' . $fundNumber . '-' . $acctShort . '-';
    $like   = $prefix . '%';

    // Get next series number for this prefix
    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(
            CAST(SUBSTRING_INDEX(
                SUBSTRING_INDEX(property_number, '-RC', 1),
                '-', -1
            ) AS UNSIGNED)
         ), 0) + 1 AS next_seq
         FROM distribution_item_details
         WHERE property_number LIKE ?"
    );
    $nextSeq = 1;
    if ($stmt) {
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $nextSeq = (int)($row['next_seq'] ?? 1);
        $stmt->close();
    }

    return $prefix
         . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT)
         . '-' . $rcShort;
}
