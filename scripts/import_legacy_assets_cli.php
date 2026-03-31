<?php
require_once __DIR__ . '/../spams/app/config/init.php';

function cli_out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function cli_norm(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function cli_name(array $row): string
{
    return employee_display_name([
        'first_name' => $row['first_name'] ?? '',
        'middle_name' => $row['middle_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'suffix_name' => $row['suffix_name'] ?? '',
    ]);
}

function cli_is_unknown_value(string $value): bool
{
    $normalized = cli_norm($value);
    return in_array($normalized, ['unknown', 'n_a', 'na', 'none', 'not_applicable', '-'], true);
}

function cli_is_nullish_value(string $value): bool
{
    $normalized = cli_norm($value);
    return $normalized === '' || in_array($normalized, ['null', 'nil', 'none', 'n_a', 'na', 'not_applicable', '-'], true);
}

function cli_clean_optional_value(string $value): string
{
    return cli_is_nullish_value($value) ? '' : trim($value);
}

function cli_resolve_po_number(string $poNumber, string $propertyNumber): string
{
    $poNumber = cli_clean_optional_value($poNumber);
    if ($poNumber !== '') {
        return $poNumber;
    }
    return trim($propertyNumber);
}

function cli_normalize_decimal_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace([',', ' '], '', $value);
    return trim($value);
}

function cli_classification_group_from_item_type(string $itemType): string
{
    return $itemType === 'semi_expendable' ? 'semi_expendable' : ($itemType === 'equipment' ? 'asset' : 'supply');
}

function cli_pick_csv_value(array $src, array $col, array $names, string $default = ''): string
{
    foreach ($names as $name) {
        if (isset($col[$name])) {
            return trim((string) ($src[$col[$name]] ?? ''));
        }
    }
    return $default;
}

function cli_build_description(string $description, string $specifications): string
{
    $description = trim($description);
    $specifications = trim($specifications);
    if ($description === '') {
        return $specifications;
    }
    if ($specifications === '') {
        return $description;
    }
    return $description . ' - ' . $specifications;
}

function cli_derive_acquisition_date(string $acquisitionDate, string $propertyNumber): string
{
    $acquisitionDate = cli_clean_optional_value($acquisitionDate);
    if ($acquisitionDate !== '') {
        return $acquisitionDate;
    }

    if (preg_match('/^\s*(\d{4})[-.]/', $propertyNumber, $matches)) {
        $year = (int) $matches[1];
        if ($year >= 1900 && $year <= 2100) {
            return sprintf('%04d-01-01', $year);
        }
    }

    return '';
}

function cli_find_or_create_classification(mysqli $db, array &$maps, string $classificationName, string $itemType, ?int $accountCodeId, int $userId): ?array
{
    $classificationName = cli_clean_optional_value($classificationName);
    if ($classificationName === '' || cli_is_unknown_value($classificationName)) {
        return null;
    }

    $key = cli_norm($classificationName);
    if (isset($maps['classification'][$key])) {
        return $maps['classification'][$key];
    }

    $select = $db->prepare("SELECT id, classification_name, classification_group, account_code_id FROM classifications WHERE LOWER(TRIM(classification_name)) = LOWER(TRIM(?)) LIMIT 1");
    if ($select) {
        $select->bind_param('s', $classificationName);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            $maps['classification'][$key] = $existing;
            return $existing;
        }
    }

    $classificationCode = next_module_code($db, 'classifications');
    $classificationGroup = cli_classification_group_from_item_type($itemType);
    $description = 'Auto-created from legacy asset import.';
    $insert = $db->prepare("INSERT INTO classifications (classification_code, classification_name, classification_group, account_code_id, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)");
    if (!$insert) {
        throw new RuntimeException('Unable to create missing classification.');
    }
    $insert->bind_param('sssisi', $classificationCode, $classificationName, $classificationGroup, $accountCodeId, $description, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing classification.');
    }

    $created = ['id' => $newId, 'classification_name' => $classificationName, 'classification_group' => $classificationGroup, 'account_code_id' => $accountCodeId];
    $maps['classification'][$key] = $created;
    return $created;
}

function cli_parse_csv_file(string $filePath): array
{
    $rows = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new RuntimeException('Unable to open CSV file: ' . $filePath);
    }
    while (($csvRow = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static fn($v) => trim((string) $v), $csvRow);
    }
    fclose($handle);
    return $rows;
}

function cli_find_or_create_brand(mysqli $db, array &$maps, string $brandName, int $userId): ?array
{
    $brandName = cli_clean_optional_value($brandName);
    if ($brandName === '' || cli_is_unknown_value($brandName)) {
        return null;
    }

    $key = cli_norm($brandName);
    if (isset($maps['brand'][$key])) {
        return $maps['brand'][$key];
    }

    $select = $db->prepare("SELECT id, brand_name FROM brands WHERE LOWER(TRIM(brand_name)) = LOWER(TRIM(?)) LIMIT 1");
    if ($select) {
        $select->bind_param('s', $brandName);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            $maps['brand'][$key] = $existing;
            return $existing;
        }
    }

    $brandCode = next_module_code($db, 'brands');
    $insert = $db->prepare("INSERT INTO brands (brand_code, brand_name, is_active, created_by) VALUES (?, ?, 1, ?)");
    if (!$insert) {
        throw new RuntimeException('Unable to create missing brand.');
    }
    $insert->bind_param('ssi', $brandCode, $brandName, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing brand.');
    }

    $created = ['id' => $newId, 'brand_name' => $brandName];
    $maps['brand'][$key] = $created;
    return $created;
}

function cli_find_or_create_model(mysqli $db, array &$maps, string $modelName, ?int $brandId, int $userId): ?array
{
    $modelName = cli_clean_optional_value($modelName);
    if ($modelName === '' || cli_is_unknown_value($modelName)) {
        return null;
    }

    $key = cli_norm($modelName);
    $candidates = isset($maps['model'][$key]) ? (array) $maps['model'][$key] : [];
    foreach ($candidates as $candidate) {
        if ((int) ($candidate['brand_id'] ?? 0) === (int) ($brandId ?? 0)) {
            return $candidate;
        }
    }

    if ($brandId !== null) {
        $select = $db->prepare("SELECT id, model_name, brand_id FROM models WHERE LOWER(TRIM(model_name)) = LOWER(TRIM(?)) AND brand_id = ? LIMIT 1");
        if ($select) {
            $select->bind_param('si', $modelName, $brandId);
            $select->execute();
            $existing = $select->get_result()->fetch_assoc() ?: null;
            $select->close();
            if ($existing) {
                $maps['model'][$key][] = $existing;
                return $existing;
            }
        }
    } else {
        $select = $db->prepare("SELECT id, model_name, brand_id FROM models WHERE LOWER(TRIM(model_name)) = LOWER(TRIM(?)) AND (brand_id IS NULL OR brand_id = 0) LIMIT 1");
        if ($select) {
            $select->bind_param('s', $modelName);
            $select->execute();
            $existing = $select->get_result()->fetch_assoc() ?: null;
            $select->close();
            if ($existing) {
                $maps['model'][$key][] = $existing;
                return $existing;
            }
        }
    }

    $modelCode = next_module_code($db, 'models');
    $insert = $db->prepare("INSERT INTO models (brand_id, model_code, model_name, is_active, created_by) VALUES (NULLIF(?, 0), ?, ?, 1, ?)");
    if (!$insert) {
        throw new RuntimeException('Unable to create missing model.');
    }
    $brandIdValue = $brandId ?? 0;
    $insert->bind_param('issi', $brandIdValue, $modelCode, $modelName, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing model.');
    }

    $created = ['id' => $newId, 'model_name' => $modelName, 'brand_id' => $brandId];
    $maps['model'][$key][] = $created;
    return $created;
}

$filePath = $argv[1] ?? '';
if ($filePath === '') {
    fwrite(STDERR, "Usage: php scripts/import_legacy_assets_cli.php <csv-path>" . PHP_EOL);
    exit(1);
}

if (!is_file($filePath)) {
    fwrite(STDERR, "File not found: {$filePath}" . PHP_EOL);
    exit(1);
}

$db = db();
if (!$db) {
    fwrite(STDERR, "Unable to connect to database." . PHP_EOL);
    exit(1);
}

ensure_legacy_assets_fund_column($db);
ensure_legacy_assets_po_number_column($db);

$userId = 1;
$userRes = $db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
if ($userRes && ($userRow = $userRes->fetch_assoc())) {
    $userId = (int) $userRow['id'];
}

$classifications = ($db->query("SELECT id, classification_name FROM classifications WHERE is_active = 1 ORDER BY classification_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$accountCodes = ($db->query("SELECT id, account_code, account_name FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$funds = ($db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$suppliers = ($db->query("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$brands = ($db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$models = ($db->query("SELECT id, model_name, brand_id FROM models WHERE is_active = 1 ORDER BY model_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$offices = ($db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$employees = ($db->query("SELECT id, office_id, responsibility_code_id, is_unit_head, first_name, middle_name, last_name, suffix_name FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$responsibilityCodes = ($db->query("SELECT id, office_id, code FROM responsibility_codes WHERE is_active = 1 ORDER BY code ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];

$maps = ['classification' => [], 'account' => [], 'fund' => [], 'supplier' => [], 'brand' => [], 'model' => [], 'office' => [], 'employee' => [], 'rc' => []];
foreach ($classifications as $r) {
    $maps['classification'][cli_norm($r['classification_name'])] = $r;
}
foreach ($accountCodes as $r) {
    $maps['account'][cli_norm($r['account_code'])] = $r;
    $maps['account'][cli_norm($r['account_name'])] = $r;
}
foreach ($funds as $r) {
    $maps['fund'][cli_norm($r['fund_code'])] = $r;
    $maps['fund'][cli_norm($r['fund_name'])] = $r;
    if (!empty($r['fund_source'])) {
        $maps['fund'][cli_norm((string) $r['fund_source'])] = $r;
    }
}
foreach ($suppliers as $r) {
    $maps['supplier'][cli_norm($r['supplier_name'])] = $r;
}
foreach ($brands as $r) {
    $maps['brand'][cli_norm($r['brand_name'])] = $r;
}
foreach ($models as $r) {
    $maps['model'][cli_norm($r['model_name'])][] = $r;
}
foreach ($offices as $r) {
    $maps['office'][cli_norm($r['office_name'])] = $r;
    $maps['office'][cli_norm($r['office_code'])] = $r;
}
foreach ($employees as $r) {
    $maps['employee'][cli_norm(cli_name($r))] = $r;
}
foreach ($responsibilityCodes as $r) {
    $maps['rc'][cli_norm($r['code'])] = $r;
}

$rows = cli_parse_csv_file($filePath);
if (count($rows) < 2) {
    fwrite(STDERR, "The file must contain a header row and at least one data row." . PHP_EOL);
    exit(1);
}

$header = array_map('cli_norm', $rows[0]);
$col = array_flip($header);
if (isset($col['propno'])) {
    $semiFormatRequired = ['propno', 'itemdesc', 'invname', 'accountcode', 'unitcost'];
    foreach ($semiFormatRequired as $required) {
        if (!isset($col[$required])) {
            fwrite(STDERR, "Missing required column: {$required}" . PHP_EOL);
            exit(1);
        }
    }
} else {
foreach (['property_number', 'inventory_type', 'description'] as $required) {
    if (!isset($col[$required])) {
        fwrite(STDERR, "Missing required column: {$required}" . PHP_EOL);
        exit(1);
    }
}
}

$insert = $db->prepare("INSERT INTO legacy_assets (system_reference, po_number, property_number, item_type, item_description, classification_id, account_code_id, fund_id, supplier_id, brand_id, model_id, brand, model, serial_no, acquisition_date, quantity, unit_cost, acquisition_cost, office_id, employee_id, responsibility_code_id, condition_status, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$insert) {
    fwrite(STDERR, "Unable to prepare insert statement." . PHP_EOL);
    exit(1);
}

$db->begin_transaction();
$inserted = 0;
$skipped = 0;
$failures = [];

try {
    for ($i = 1; $i < count($rows); $i++) {
        $src = $rows[$i];
        if (!array_filter($src, static fn($v) => trim((string) $v) !== '')) {
            continue;
        }

        $isSemiTemplate = isset($col['propno']);
        $itemDescription = $isSemiTemplate
            ? cli_build_description(
                cli_pick_csv_value($src, $col, ['itemdesc']),
                cli_pick_csv_value($src, $col, ['specifications'])
            )
            : trim((string) ($src[$col['description'] ?? null] ?? ''));

        $row = [
            'source_row' => $i + 1,
            'po_number' => '',
            'property_number' => $isSemiTemplate ? cli_pick_csv_value($src, $col, ['propno']) : trim((string) ($src[$col['property_number'] ?? null] ?? '')),
            'item_type' => $isSemiTemplate ? 'semi_expendable' : strtolower(str_replace([' ', '-'], '_', (string) ($src[$col['inventory_type']] ?? ''))),
            'item_description' => $itemDescription,
            'classification' => $isSemiTemplate ? cli_clean_optional_value(cli_pick_csv_value($src, $col, ['invname'])) : cli_clean_optional_value((string) ($src[$col['classification'] ?? null] ?? '')),
            'fund' => $isSemiTemplate ? cli_clean_optional_value(cli_pick_csv_value($src, $col, ['fund'])) : cli_clean_optional_value((string) ($src[$col['fund'] ?? null] ?? ($src[$col['fund_number'] ?? null] ?? ''))),
            'account_code' => $isSemiTemplate ? cli_clean_optional_value(cli_pick_csv_value($src, $col, ['accountcode'])) : cli_clean_optional_value((string) ($src[$col['account_code'] ?? null] ?? '')),
            'supplier' => cli_clean_optional_value((string) ($src[$col['supplier'] ?? null] ?? '')),
            'brand' => $isSemiTemplate ? cli_clean_optional_value(cli_pick_csv_value($src, $col, ['brand'])) : cli_clean_optional_value((string) ($src[$col['brand'] ?? null] ?? '')),
            'model' => $isSemiTemplate ? cli_clean_optional_value(cli_pick_csv_value($src, $col, ['model'])) : cli_clean_optional_value((string) ($src[$col['model'] ?? null] ?? '')),
            'serial_no' => $isSemiTemplate ? cli_clean_optional_value(cli_pick_csv_value($src, $col, ['serialno'])) : cli_clean_optional_value((string) ($src[$col['serial_no'] ?? null] ?? '')),
            'acquisition_date' => cli_derive_acquisition_date(
                cli_clean_optional_value((string) ($src[$col['acquisition_date'] ?? null] ?? '')),
                $isSemiTemplate ? cli_pick_csv_value($src, $col, ['propno']) : trim((string) ($src[$col['property_number'] ?? null] ?? ''))
            ),
            'quantity' => trim((string) ($src[$col['quantity'] ?? null] ?? '1')),
            'unit_cost' => $isSemiTemplate ? cli_normalize_decimal_value(cli_pick_csv_value($src, $col, ['unitcost'])) : cli_normalize_decimal_value((string) ($src[$col['unit_cost'] ?? null] ?? '')),
            'office' => cli_clean_optional_value((string) ($src[$col['office'] ?? null] ?? '')),
            'employee' => cli_clean_optional_value((string) ($src[$col['employee'] ?? null] ?? '')),
            'responsibility_code' => cli_clean_optional_value((string) ($src[$col['responsibility_code'] ?? null] ?? '')),
            'condition_status' => cli_clean_optional_value((string) ($src[$col['condition_status'] ?? null] ?? 'good')),
            'remarks' => cli_clean_optional_value((string) ($src[$col['remarks'] ?? null] ?? '')),
            'errors' => [],
        ];

        $row['po_number'] = cli_resolve_po_number((string) ($src[$col['po_number'] ?? null] ?? ''), $row['property_number']);
        if (!in_array($row['item_type'], ['equipment', 'semi_expendable'], true)) {
            $row['errors'][] = 'Type must be equipment or semi_expendable.';
        }
        if (!ctype_digit($row['quantity']) || (int) $row['quantity'] <= 0) {
            $row['errors'][] = 'Quantity must be a whole number.';
        }
        if ($row['unit_cost'] === '' || !is_numeric($row['unit_cost'])) {
            $row['errors'][] = 'Unit cost is required.';
        }

        $account = $maps['account'][cli_norm($row['account_code'])] ?? null;
        $classification = null;
        $fund = $maps['fund'][cli_norm($row['fund'])] ?? null;
        $supplier = $maps['supplier'][cli_norm($row['supplier'])] ?? null;
        $office = $maps['office'][cli_norm($row['office'])] ?? null;
        $employee = $maps['employee'][cli_norm($row['employee'])] ?? null;
        $rc = $maps['rc'][cli_norm($row['responsibility_code'])] ?? null;

        if ($row['supplier'] !== '' && !$supplier) {
            $row['errors'][] = 'Unknown supplier.';
        }
        if ($row['account_code'] !== '' && !$account) {
            $row['errors'][] = 'Unknown account code.';
        }
        if ($row['fund'] !== '' && !$fund) {
            $row['errors'][] = 'Unknown fund.';
        }
        if ($row['office'] !== '' && !$office) {
            $row['errors'][] = 'Unknown office.';
        }
        if ($row['employee'] !== '' && !$employee) {
            $row['errors'][] = 'Unknown employee.';
        }
        if ($row['responsibility_code'] !== '' && !$rc) {
            $row['errors'][] = 'Unknown RC.';
        }

        try {
            $classification = cli_find_or_create_classification($db, $maps, $row['classification'], $row['item_type'], isset($account['id']) ? (int) $account['id'] : null, $userId);
            $brand = cli_find_or_create_brand($db, $maps, $row['brand'], $userId);
            $brandId = isset($brand['id']) ? (int) $brand['id'] : null;
            $model = cli_find_or_create_model($db, $maps, $row['model'], $brandId, $userId);
            if ($brand && $model && (int) ($model['brand_id'] ?? 0) > 0 && (int) $model['brand_id'] !== (int) $brand['id']) {
                $row['errors'][] = 'Model does not belong to brand.';
            }
        } catch (Throwable $e) {
            $row['errors'][] = $e->getMessage();
        }

        if (!$office && $employee && !empty($employee['office_id'])) {
            foreach ($offices as $off) {
                if ((int) $off['id'] === (int) $employee['office_id']) {
                    $office = $off;
                    break;
                }
            }
        }
        if ($office && !$employee) {
            foreach ($employees as $emp) {
                if ((int) ($emp['office_id'] ?? 0) === (int) $office['id'] && (int) ($emp['is_unit_head'] ?? 0) === 1) {
                    $employee = $emp;
                    break;
                }
            }
        }

        if ($employee && $office && (int) ($employee['office_id'] ?? 0) !== (int) $office['id']) {
            $row['errors'][] = 'Employee does not belong to office.';
        }
        if ($rc && $office && (int) ($rc['office_id'] ?? 0) !== (int) $office['id']) {
            $row['errors'][] = 'RC does not belong to office.';
        }

        $dup = $row['property_number'] !== '' ? $db->prepare("SELECT id FROM legacy_assets WHERE property_number = ? LIMIT 1") : null;
        if ($dup) {
            $dup->bind_param('s', $row['property_number']);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                $row['errors'][] = 'Property number already exists.';
            }
            $dup->close();
        }

        if ($row['errors']) {
            $skipped++;
            $failures[] = 'Row ' . $row['source_row'] . ': ' . implode(' ', $row['errors']);
            continue;
        }

        $systemReference = next_module_code($db, 'stock_items');
        $qty = (int) $row['quantity'];
        $unitCost = (float) $row['unit_cost'];
        $totalCost = round($qty * $unitCost, 2);
        $classificationId = $classification['id'] ?? null;
        $accountCodeId = $account['id'] ?? null;
        $fundId = $fund['id'] ?? null;
        $supplierId = $supplier['id'] ?? null;
        $brandId = $brand['id'] ?? null;
        $modelId = $model['id'] ?? null;
        $officeId = $office['id'] ?? null;
        $employeeId = $employee['id'] ?? null;
        $rcId = $rc['id'] ?? null;
        $brandName = $brand['brand_name'] ?? $row['brand'];
        $modelName = $model['model_name'] ?? $row['model'];

        $insert->bind_param(
            'sssssiiiiissssidddiiissi',
            $systemReference,
            $row['po_number'],
            $row['property_number'],
            $row['item_type'],
            $row['item_description'],
            $classificationId,
            $accountCodeId,
            $fundId,
            $supplierId,
            $brandId,
            $modelId,
            $brandName,
            $modelName,
            $row['serial_no'],
            $row['acquisition_date'],
            $qty,
            $unitCost,
            $totalCost,
            $officeId,
            $employeeId,
            $rcId,
            $row['condition_status'],
            $row['remarks'],
            $userId
        );

        if (!$insert->execute()) {
            throw new RuntimeException('Failed to insert row ' . $row['source_row'] . ': ' . $insert->error);
        }

        $inserted++;
    }

    $db->commit();
    cli_out('Inserted: ' . $inserted);
    cli_out('Skipped: ' . $skipped);
    if ($failures) {
        cli_out('Failures:');
        foreach ($failures as $failure) {
            cli_out($failure);
        }
    }
} catch (Throwable $e) {
    $db->rollback();
    $insert->close();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$insert->close();
exit(0);
