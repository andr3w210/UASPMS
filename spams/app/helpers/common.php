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

function ensure_user_messages_table(mysqli $db): void
{
    $db->query("
        CREATE TABLE IF NOT EXISTS user_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_user_id INT UNSIGNED NOT NULL,
            recipient_user_id INT UNSIGNED NOT NULL,
            subject VARCHAR(200) NULL,
            message_body TEXT NOT NULL,
            related_table VARCHAR(50) NULL,
            related_id BIGINT UNSIGNED NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sender (sender_user_id),
            INDEX idx_recipient (recipient_user_id),
            INDEX idx_recipient_read (recipient_user_id, is_read),
            INDEX idx_related (related_table, related_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->query("ALTER TABLE user_messages ADD COLUMN IF NOT EXISTS hidden_for_sender TINYINT(1) NOT NULL DEFAULT 0");
    $db->query("ALTER TABLE user_messages ADD COLUMN IF NOT EXISTS hidden_for_recipient TINYINT(1) NOT NULL DEFAULT 0");
    $db->query("ALTER TABLE user_messages ADD COLUMN IF NOT EXISTS related_table VARCHAR(50) NULL");
    $db->query("ALTER TABLE user_messages ADD COLUMN IF NOT EXISTS related_id BIGINT UNSIGNED NULL");
}

function ensure_message_channels_table(mysqli $db): void
{
    $db->query("
        CREATE TABLE IF NOT EXISTS message_channels (
            channel_key VARCHAR(50) PRIMARY KEY,
            channel_name VARCHAR(100) NOT NULL,
            channel_description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS channel_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel_key VARCHAR(50) NOT NULL,
            sender_user_id INT UNSIGNED NOT NULL,
            subject VARCHAR(200) NULL,
            message_body TEXT NOT NULL,
            related_table VARCHAR(50) NULL,
            related_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_channel_created (channel_key, created_at),
            INDEX idx_sender (sender_user_id),
            INDEX idx_related (related_table, related_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS message_channel_reads (
            channel_key VARCHAR(50) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_read_at DATETIME NULL,
            PRIMARY KEY (channel_key, user_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS message_channel_hidden (
            channel_message_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (channel_message_id, user_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $insertStmt = $db->prepare("
        INSERT INTO message_channels (channel_key, channel_name, channel_description, is_active)
        VALUES ('general', 'General Group Chat', 'Shared coordination channel for all active users.', 1)
        ON DUPLICATE KEY UPDATE channel_name = VALUES(channel_name), channel_description = VALUES(channel_description), is_active = 1
    ");
    if ($insertStmt) {
        $insertStmt->execute();
        $insertStmt->close();
    }
}

function ensure_messaging_infrastructure(mysqli $db): void
{
    ensure_user_messages_table($db);
    ensure_user_presence_table($db);
    ensure_message_channels_table($db);
}

function message_mark_channel_read(mysqli $db, string $channelKey, int $userId): void
{
    if ($channelKey === '' || $userId <= 0) {
        return;
    }

    $maxStmt = $db->prepare("SELECT COALESCE(MAX(id), 0) AS last_id FROM channel_messages WHERE channel_key = ?");
    if (!$maxStmt) {
        return;
    }

    $maxStmt->bind_param('s', $channelKey);
    $maxStmt->execute();
    $lastId = (int) (($maxStmt->get_result()->fetch_assoc()['last_id'] ?? 0));
    $maxStmt->close();

    $upsertStmt = $db->prepare("
        INSERT INTO message_channel_reads (channel_key, user_id, last_read_message_id, last_read_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE last_read_message_id = VALUES(last_read_message_id), last_read_at = NOW()
    ");
    if ($upsertStmt) {
        $upsertStmt->bind_param('sii', $channelKey, $userId, $lastId);
        $upsertStmt->execute();
        $upsertStmt->close();
    }
}

function message_channel_unread_count(mysqli $db, string $channelKey, int $userId): int
{
    if ($channelKey === '' || $userId <= 0) {
        return 0;
    }

    $stmt = $db->prepare("
        SELECT COUNT(*) AS total
        FROM channel_messages cm
        LEFT JOIN message_channel_reads mcr
            ON mcr.channel_key = cm.channel_key
           AND mcr.user_id = ?
        LEFT JOIN message_channel_hidden mch
            ON mch.channel_message_id = cm.id
           AND mch.user_id = ?
        WHERE cm.channel_key = ?
          AND cm.sender_user_id != ?
          AND mch.channel_message_id IS NULL
          AND cm.id > COALESCE(mcr.last_read_message_id, 0)
    ");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('iisi', $userId, $userId, $channelKey, $userId);
    $stmt->execute();
    $total = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0));
    $stmt->close();

    return $total;
}

function message_hide_channel_message(mysqli $db, int $messageId, int $userId): bool
{
    if ($messageId <= 0 || $userId <= 0) {
        return false;
    }

    $stmt = $db->prepare("
        INSERT INTO message_channel_hidden (channel_message_id, user_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE hidden_at = CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $messageId, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function message_highlight_mentions_html(string $message): string
{
    $html = h($message);
    $patterns = [
        '/@everyone\b/i',
        '/@administrator\b/i',
        '/@supply officer\b/i',
        '/@property officer\b/i',
        '/@viewer\b/i',
    ];

    foreach ($patterns as $pattern) {
        $html = preg_replace($pattern, '<span class="badge text-bg-warning text-dark">$0</span>', $html);
    }

    return nl2br($html, false);
}

function ensure_user_presence_table(mysqli $db): void
{
    $db->query("
        CREATE TABLE IF NOT EXISTS user_presence (
            user_id INT UNSIGNED PRIMARY KEY,
            last_seen_at DATETIME NOT NULL,
            INDEX idx_last_seen_at (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
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
        'po_delivery_extensions' => ['prefix' => 'POEXT', 'use_year' => true, 'padding' => 4],
        'receivings' => ['prefix' => 'RCV', 'use_year' => true, 'padding' => 4],
        'maintenance' => ['prefix' => 'MNT', 'use_year' => true, 'padding' => 4],
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
