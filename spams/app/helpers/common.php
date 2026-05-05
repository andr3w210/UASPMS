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
                    get_system_setting($db, 'tailscale_serve_url', ''),
                    get_system_setting($db, 'tailscale_ip_url', ''),
                    get_system_setting($db, 'local_access_url', ''),
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

function old(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) ? trim((string) $source[$key]) : $default;
}

function add_validation_error(array &$errors, string $message): void
{
    if (!in_array($message, $errors, true)) {
        $errors[] = $message;
    }
}

function is_valid_date_string(string $value, string $format = 'Y-m-d'): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    $date = DateTime::createFromFormat($format, $value);
    return $date instanceof DateTime && $date->format($format) === $value;
}

function is_allowed_value(string $value, array $allowed): bool
{
    return in_array($value, $allowed, true);
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
    if (function_exists('schema_has_column') && !schema_has_column($db, 'legacy_assets', 'fund_id')) {
        $db->query("ALTER TABLE legacy_assets ADD COLUMN fund_id INT NULL AFTER account_code_id");
    }
}

function ensure_legacy_assets_rpcppe_tracking_columns(mysqli $db): void
{
    $db->query("ALTER TABLE legacy_assets
        ADD COLUMN IF NOT EXISTS is_rpcppe_candidate TINYINT(1) NOT NULL DEFAULT 0 AFTER fund_id,
        ADD COLUMN IF NOT EXISTS rpcppe_status VARCHAR(30) NOT NULL DEFAULT 'excluded' AFTER is_rpcppe_candidate,
        ADD COLUMN IF NOT EXISTS rpcppe_batch_id BIGINT UNSIGNED NULL AFTER rpcppe_status,
        ADD COLUMN IF NOT EXISTS rpcppe_submitted_at DATETIME NULL AFTER rpcppe_batch_id,
        ADD COLUMN IF NOT EXISTS rpcppe_reconciled_at DATETIME NULL AFTER rpcppe_submitted_at");

    $db->query("UPDATE legacy_assets
        SET rpcppe_status = CASE
            WHEN COALESCE(is_rpcppe_candidate, 0) = 1 THEN 'included_draft'
            ELSE 'excluded'
        END
        WHERE rpcppe_status IS NULL OR TRIM(rpcppe_status) = ''");
}

function ensure_distribution_item_rpcppe_tracking_columns(mysqli $db): void
{
    $db->query("ALTER TABLE distribution_item_details
        ADD COLUMN IF NOT EXISTS is_rpcppe_candidate TINYINT(1) NOT NULL DEFAULT 0 AFTER is_disposed,
        ADD COLUMN IF NOT EXISTS rpcppe_status VARCHAR(30) NOT NULL DEFAULT 'excluded' AFTER is_rpcppe_candidate,
        ADD COLUMN IF NOT EXISTS rpcppe_batch_id BIGINT UNSIGNED NULL AFTER rpcppe_status,
        ADD COLUMN IF NOT EXISTS rpcppe_submitted_at DATETIME NULL AFTER rpcppe_batch_id,
        ADD COLUMN IF NOT EXISTS rpcppe_reconciled_at DATETIME NULL AFTER rpcppe_submitted_at");

    $db->query("UPDATE distribution_item_details
        SET rpcppe_status = CASE
            WHEN COALESCE(is_rpcppe_candidate, 0) = 1 THEN 'included_draft'
            ELSE 'excluded'
        END
        WHERE rpcppe_status IS NULL OR TRIM(rpcppe_status) = ''");
}

function ensure_rpcppe_batch_tracking_columns(mysqli $db): void
{
    $db->query("ALTER TABLE rpcppe_batch_items
        ADD COLUMN IF NOT EXISTS reconciliation_status VARCHAR(30) NOT NULL DEFAULT 'included_draft' AFTER is_included,
        ADD COLUMN IF NOT EXISTS submitted_to_accounting_at DATETIME NULL AFTER reconciliation_status,
        ADD COLUMN IF NOT EXISTS reconciled_at DATETIME NULL AFTER submitted_to_accounting_at");

    $db->query("UPDATE rpcppe_batch_items
        SET reconciliation_status = CASE
            WHEN COALESCE(is_included, 0) = 1 THEN 'included_draft'
            ELSE 'excluded'
        END
        WHERE reconciliation_status IS NULL OR TRIM(reconciliation_status) = ''");
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
    if (function_exists('schema_has_column') && !schema_has_column($db, 'legacy_assets', 'po_number')) {
        $db->query("ALTER TABLE legacy_assets ADD COLUMN po_number VARCHAR(100) NULL AFTER system_reference");
    }
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

    // Extract 3rd and 4th decimal segments from account code
    $acctParts = explode('.', $accountCode);
    if (isset($acctParts[2]) && isset($acctParts[3])) {
        $acctShort = $acctParts[2] . '.' . $acctParts[3];
    } else {
        $acctShort = $accountCode;
    }
    $acctShort = trim((string) $acctShort);
    if ($acctShort === '') {
        $acctShort = 'GEN';
    }

    // Use office code as the last property number segment.
    $officeShort = trim($officeCode);
    if (stripos($officeShort, 'RC-') === 0) {
        $officeShort = substr($officeShort, 3);
    }
    $officeShort = strtoupper((string) $officeShort);
    if ($officeShort === '') {
        $officeShort = 'GEN';
    }

    $prefix = $year . '-' . $fundSegment . '-' . $acctShort;
    $seriesModuleKey = 'property_number|' . $year;
    $legacySeriesModuleKey = 'property_number|' . $year . '|' . $officeShort;
    $legacyPrefixSeriesModuleKey = 'property_number|' . $prefix . '|' . $officeShort;
    $legacyRcSeriesModuleKey = 'property_number|' . $prefix . '|RC-' . $officeShort;
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
            $legacyCurrentValue = null;
            $legacyStmt = $db->prepare("SELECT current_value FROM series_numbers WHERE module_key = ? LIMIT 1");
            if ($legacyStmt) {
                $legacyStmt->bind_param('s', $legacySeriesModuleKey);
                $legacyStmt->execute();
                $legacyRow = $legacyStmt->get_result()->fetch_assoc();
                $legacyStmt->close();
                if ($legacyRow) {
                    $legacyCurrentValue = (int) ($legacyRow['current_value'] ?? 0);
                }
            }
            $legacyPrefixStmt = $db->prepare("SELECT current_value FROM series_numbers WHERE module_key = ? LIMIT 1");
            if ($legacyPrefixStmt) {
                $legacyPrefixStmt->bind_param('s', $legacyPrefixSeriesModuleKey);
                $legacyPrefixStmt->execute();
                $legacyPrefixRow = $legacyPrefixStmt->get_result()->fetch_assoc();
                $legacyPrefixStmt->close();
                if ($legacyPrefixRow) {
                    $legacyPrefixValue = (int) ($legacyPrefixRow['current_value'] ?? 0);
                    if ($legacyCurrentValue === null || $legacyPrefixValue > $legacyCurrentValue) {
                        $legacyCurrentValue = $legacyPrefixValue;
                    }
                }
            }
            $legacyRcStmt = $db->prepare("SELECT current_value FROM series_numbers WHERE module_key = ? LIMIT 1");
            if ($legacyRcStmt) {
                $legacyRcStmt->bind_param('s', $legacyRcSeriesModuleKey);
                $legacyRcStmt->execute();
                $legacyRcRow = $legacyRcStmt->get_result()->fetch_assoc();
                $legacyRcStmt->close();
                if ($legacyRcRow) {
                    $legacyRcValue = (int) ($legacyRcRow['current_value'] ?? 0);
                    if ($legacyCurrentValue === null || $legacyRcValue > $legacyCurrentValue) {
                        $legacyCurrentValue = $legacyRcValue;
                    }
                }
            }

            $yearPrefix = (string) $year;
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
                                 WHERE SUBSTRING_INDEX(property_number, '-', 1) = ?"
            );
            $currentValue = 0;
            if ($seedStmt) {
                                $seedStmt->bind_param('s', $yearPrefix);
                $seedStmt->execute();
                $seedRow = $seedStmt->get_result()->fetch_assoc();
                $currentValue = (int) ($seedRow['current_value'] ?? 0);
                $seedStmt->close();
            }
            if ($legacyCurrentValue !== null && $legacyCurrentValue > $currentValue) {
                $currentValue = $legacyCurrentValue;
            }

            $insertStmt = $db->prepare(
                "INSERT INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
                 VALUES (?, ?, NULL, ?, ?)
                 ON DUPLICATE KEY UPDATE module_key = module_key"
            );
            if ($insertStmt) {
                $seriesPrefix = $year;
                $insertStmt->bind_param('ssii', $seriesModuleKey, $seriesPrefix, $currentValue, $padding);
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
         . '-' . $officeShort;
}
