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

function format_date(?string $value, string $format = 'M d, Y'): string
{
    $clean = trim((string) $value);
    if ($clean === '') {
        return '';
    }

    $timestamp = strtotime($clean);
    if ($timestamp === false) {
        return $clean;
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

    // Extract 3rd and 4th decimal segments from account code
    $acctParts = explode('.', $accountCode);
    if (isset($acctParts[2]) && isset($acctParts[3])) {
        $acctShort = $acctParts[2] . '.' . $acctParts[3];
    } else {
        $acctShort = $accountCode;
    }

    // Extract RC short code — remove leading RC- prefix
    $rcShort = preg_replace('/^RC-/i', '', trim($rcCode));
    if ($rcShort === '') {
        $rcShort = trim($rcCode);
    }
    if ($rcShort === '') {
        $rcShort = 'GEN';
    }

    $prefix = $year . '-' . $fundSegment . '-' . $acctShort;
    $seriesModuleKey = 'property_number|' . $prefix . '|' . $rcShort;
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
            $likePrefix = $prefix . '-%';
            $likeSuffix = '%-' . $rcShort;
            $seedStmt = $db->prepare(
                "SELECT COALESCE(MAX(
                    CAST(SUBSTRING_INDEX(
                        SUBSTRING_INDEX(property_number, '-', -2),
                        '-', 1
                    ) AS UNSIGNED)
                 ), 0) AS current_value
                 FROM distribution_item_details
                 WHERE property_number LIKE ?
                   AND property_number LIKE ?"
            );
            $currentValue = 0;
            if ($seedStmt) {
                $seedStmt->bind_param('ss', $likePrefix, $likeSuffix);
                $seedStmt->execute();
                $seedRow = $seedStmt->get_result()->fetch_assoc();
                $currentValue = (int) ($seedRow['current_value'] ?? 0);
                $seedStmt->close();
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
         . '-' . $rcShort;
}
