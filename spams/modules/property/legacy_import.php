<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

if (isset($_GET['download_template'])) {
    $templatePath = dirname(__DIR__, 3) . '/database/templates/legacy_assets_import_template.csv';
    if (!is_file($templatePath)) {
        http_response_code(404);
        exit('Legacy import template not found.');
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="legacy_assets_import_template.csv"');
    header('Content-Length: ' . (string) filesize($templatePath));
    readfile($templatePath);
    exit;
}


function li_norm(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function li_name(array $row): string
{
    return employee_display_name([
        'first_name' => $row['first_name'] ?? '',
        'middle_name' => $row['middle_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'suffix_name' => $row['suffix_name'] ?? '',
    ]);
}

function li_is_unknown_value(string $value): bool
{
    $normalized = li_norm($value);
    return in_array($normalized, ['unknown', 'n_a', 'na', 'none', 'not_applicable', '-'], true);
}

function li_is_nullish_value(string $value): bool
{
    $normalized = li_norm($value);
    return $normalized === '' || in_array($normalized, ['null', 'nil', 'none', 'n_a', 'na', 'not_applicable', '-'], true);
}

function li_clean_optional_value(string $value): string
{
    return li_is_nullish_value($value) ? '' : trim($value);
}

function li_resolve_po_number(string $poNumber, string $propertyNumber): string
{
    $poNumber = li_clean_optional_value($poNumber);
    if ($poNumber !== '') {
        return $poNumber;
    }
    return trim($propertyNumber);
}

function li_normalize_decimal_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace([',', ' '], '', $value);
    return trim($value);
}

function li_classification_group_from_item_type(string $itemType): string
{
    return $itemType === 'semi_expendable' ? 'semi_expendable' : ($itemType === 'equipment' ? 'asset' : 'supply');
}

function li_pick_csv_value(array $src, array $col, array $names, string $default = ''): string
{
    foreach ($names as $name) {
        if (isset($col[$name])) {
            return trim((string) ($src[$col[$name]] ?? ''));
        }
    }
    return $default;
}

function li_pick_acquisition_date(array $src, array $col): string
{
    return li_clean_optional_value(li_pick_csv_value($src, $col, [
        'acquisition_date',
        'date_acquired',
        'date_acquire',
        'date_of_acquisition',
        'acquired_date',
        'acquired',
    ]));
}

function li_build_description(string $description, string $specifications): string
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

function li_derive_acquisition_date(string $acquisitionDate, string $propertyNumber): string
{
    $acquisitionDate = li_clean_optional_value($acquisitionDate);
    if ($acquisitionDate !== '') {
        return normalize_date_string($acquisitionDate);
    }

    if (preg_match('/^\s*(\d{4})[-.]/', $propertyNumber, $matches)) {
        $year = (int) $matches[1];
        $latestSafeLegacyYear = ((int) date('Y')) - 2;
        if ($year >= 1900 && $year <= $latestSafeLegacyYear) {
            return sprintf('%04d-01-01', $year);
        }
    }

    return '';
}

function li_find_or_create_classification(mysqli $db, array &$maps, string $classificationName, string $itemType, ?int $accountCodeId, int $userId): ?array
{
    $classificationName = li_clean_optional_value($classificationName);
    if ($classificationName === '' || li_is_unknown_value($classificationName)) {
        return null;
    }

    $key = li_norm($classificationName);
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
    $classificationGroup = li_classification_group_from_item_type($itemType);
    $description = 'Auto-created from legacy asset import.';
    $insert = $db->prepare("INSERT INTO classifications (classification_code, classification_name, classification_group, account_code_id, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)");
    if (!$insert) {
        throw new RuntimeException('Unable to create missing classification during import.');
    }
    $insert->bind_param('sssisi', $classificationCode, $classificationName, $classificationGroup, $accountCodeId, $description, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing classification during import.');
    }

    $created = ['id' => $newId, 'classification_name' => $classificationName, 'classification_group' => $classificationGroup, 'account_code_id' => $accountCodeId];
    $maps['classification'][$key] = $created;
    return $created;
}

function li_find_or_create_brand(mysqli $db, array &$maps, string $brandName, int $userId): ?array
{
    $brandName = li_clean_optional_value($brandName);
    if ($brandName === '' || li_is_unknown_value($brandName)) {
        return null;
    }

    $key = li_norm($brandName);
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
        throw new RuntimeException('Unable to create missing brand during import.');
    }
    $insert->bind_param('ssi', $brandCode, $brandName, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing brand during import.');
    }

    $created = ['id' => $newId, 'brand_name' => $brandName];
    $maps['brand'][$key] = $created;
    return $created;
}

function li_find_or_create_model(mysqli $db, array &$maps, string $modelName, ?int $brandId, int $userId): ?array
{
    $modelName = li_clean_optional_value($modelName);
    if ($modelName === '' || li_is_unknown_value($modelName)) {
        return null;
    }

    $key = li_norm($modelName);
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
        throw new RuntimeException('Unable to create missing model during import.');
    }
    $brandIdValue = $brandId ?? 0;
    $insert->bind_param('issi', $brandIdValue, $modelCode, $modelName, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing model during import.');
    }

    $created = ['id' => $newId, 'model_name' => $modelName, 'brand_id' => $brandId];
    $maps['model'][$key][] = $created;
    return $created;
}

function li_col_to_index(string $letters): int
{
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function li_parse_csv_file(string $filePath): array
{
    $rows = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new RuntimeException('Unable to open the uploaded CSV file.');
    }
    while (($csvRow = fgetcsv($handle)) !== false) {
        $rows[] = array_map('trim', $csvRow);
    }
    fclose($handle);
    return $rows;
}

function li_parse_xlsx_file(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('XLSX import requires the PHP zip extension (ZipArchive). Use CSV for now or enable extension=zip in php.ini, then restart Apache.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Unable to open the uploaded XLSX file.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedDoc = simplexml_load_string($sharedXml);
        if ($sharedDoc) {
            foreach ($sharedDoc->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = trim((string) $si->t);
                } else {
                    $parts = [];
                    foreach ($si->r as $run) {
                        $parts[] = (string) $run->t;
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Unable to read the first worksheet from the XLSX file.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) {
        throw new RuntimeException('Unable to parse the XLSX worksheet.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            preg_match('/([A-Z]+)/', $ref, $matches);
            $colIndex = isset($matches[1]) ? li_col_to_index($matches[1]) : count($values);
            $type = (string) $cell['t'];
            $value = '';

            if ($type === 's') {
                $value = $sharedStrings[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = trim((string) $cell->is->t);
            } else {
                $value = isset($cell->v) ? trim((string) $cell->v) : '';
            }

            $values[$colIndex] = $value;
        }

        if ($values) {
            ksort($values);
            $max = max(array_keys($values));
            $normalized = [];
            for ($i = 0; $i <= $max; $i++) {
                $normalized[] = trim((string) ($values[$i] ?? ''));
            }
            $rows[] = $normalized;
        }
    }

    return $rows;
}

function li_parse_upload(array $file): array
{
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return li_parse_csv_file($file['tmp_name']);
    }
    if ($ext === 'xlsx') {
        return li_parse_xlsx_file($file['tmp_name']);
    }
    throw new RuntimeException('Only CSV and XLSX files are supported.');
}

$db = db();
$page_title = 'Import Legacy Assets';
$errors = [];
$flash = get_flash();
$preview = $_SESSION['legacy_import_preview'] ?? [];
$summary = ['valid' => 0, 'invalid' => 0];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $classifications = ($db->query("SELECT id, classification_name FROM classifications WHERE is_active = 1 ORDER BY classification_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
    $accountCodes = ($db->query("SELECT id, account_code, account_name FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
    $funds = ($db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
    $suppliers = ($db->query("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
    $brands = ($db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
    $models = ($db->query("SELECT id, model_name, brand_id FROM models WHERE is_active = 1 ORDER BY model_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
    $offices = ($db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
    $employees = ($db->query("SELECT id, office_id, responsibility_code_id, is_unit_head, first_name, middle_name, last_name, suffix_name FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
    $responsibilityCodes = ($db->query("SELECT id, office_id, code FROM responsibility_codes WHERE is_active = 1 ORDER BY code ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];

    ensure_legacy_assets_fund_column($db);
    ensure_legacy_assets_po_number_column($db);

    $maps = ['classification'=>[],'account'=>[],'fund'=>[],'supplier'=>[],'brand'=>[],'model'=>[],'office'=>[],'employee'=>[],'rc'=>[]];
    foreach ($classifications as $r) $maps['classification'][li_norm($r['classification_name'])] = $r;
    foreach ($accountCodes as $r) { $maps['account'][li_norm($r['account_code'])] = $r; $maps['account'][li_norm($r['account_name'])] = $r; }
    foreach ($funds as $r) {
        $maps['fund'][li_norm($r['fund_code'])] = $r;
        $maps['fund'][li_norm($r['fund_name'])] = $r;
        if (!empty($r['fund_source'])) $maps['fund'][li_norm((string) $r['fund_source'])] = $r;
    }
    foreach ($suppliers as $r) $maps['supplier'][li_norm($r['supplier_name'])] = $r;
    foreach ($brands as $r) $maps['brand'][li_norm($r['brand_name'])] = $r;
    foreach ($models as $r) $maps['model'][li_norm($r['model_name'])][] = $r;
    foreach ($offices as $r) { $maps['office'][li_norm($r['office_name'])] = $r; $maps['office'][li_norm($r['office_code'])] = $r; }
    foreach ($employees as $r) $maps['employee'][li_norm(li_name($r))] = $r;
    foreach ($responsibilityCodes as $r) $maps['rc'][li_norm($r['code'])] = $r;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'preview';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'clear_preview') {
            unset($_SESSION['legacy_import_preview']);
            redirect('modules/property/legacy_import.php');
        } elseif ($action === 'import') {
            if (!$preview) {
                $errors[] = 'No preview data to import.';
            } else {
                $stmt = $db->prepare("INSERT INTO legacy_assets (system_reference, po_number, property_number, item_type, item_description, classification_id, account_code_id, fund_id, supplier_id, brand_id, model_id, brand, model, serial_no, acquisition_date, quantity, unit_cost, acquisition_cost, office_id, employee_id, responsibility_code_id, condition_status, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    $errors[] = 'Unable to prepare import statement.';
                } else {
                    $userId = current_user_id();
                    foreach ($preview as $row) {
                        if (!empty($row['errors'])) continue;
                        $systemReference = next_module_code($db, 'stock_items');
                        $qty = (int) $row['quantity'];
                        $unitCost = (float) $row['unit_cost'];
                        $totalCost = round($qty * $unitCost, 2);
                        $classificationId = $row['classification_id'] ? (int) $row['classification_id'] : null;
                        $accountCodeId = $row['account_code_id'] ? (int) $row['account_code_id'] : null;
                        $fundId = $row['fund_id'] ? (int) $row['fund_id'] : null;
                        $supplierId = $row['supplier_id'] ? (int) $row['supplier_id'] : null;
                        $brandId = $row['brand_id'] ? (int) $row['brand_id'] : null;
                        $modelId = $row['model_id'] ? (int) $row['model_id'] : null;
                        $officeId = $row['office_id'] ? (int) $row['office_id'] : null;
                        $employeeId = $row['employee_id'] ? (int) $row['employee_id'] : null;
                        $rcId = $row['responsibility_code_id'] ? (int) $row['responsibility_code_id'] : null;
                        $stmt->bind_param('sssssiiiiissssidddiiissi', $systemReference, $row['po_number'], $row['property_number'], $row['item_type'], $row['item_description'], $classificationId, $accountCodeId, $fundId, $supplierId, $brandId, $modelId, $row['brand_name'], $row['model_name'], $row['serial_no'], $row['acquisition_date'], $qty, $unitCost, $totalCost, $officeId, $employeeId, $rcId, $row['condition_status'], $row['remarks'], $userId);
                        $stmt->execute();
                    }
                    $stmt->close();
                    unset($_SESSION['legacy_import_preview']);
                    set_flash('success', 'Valid legacy rows imported successfully.');
                    redirect('modules/property/legacy_import.php');
                }
            }
        } else {
            if (empty($_FILES['legacy_file']['name'])) {
                $errors[] = 'Please choose a CSV or XLSX file.';
            } else {
                try {
                    $rows = li_parse_upload($_FILES['legacy_file']);
                    if (count($rows) < 2) {
                        $errors[] = 'The file must contain a header row and at least one data row.';
                    } else {
                        $header = array_map('li_norm', $rows[0]);
                        $col = array_flip($header);
                        if (isset($col['propno'])) {
                            foreach (['propno','itemdesc','invname','accountcode','unitcost'] as $required) {
                                if (!isset($col[$required])) {
                                    $errors[] = 'Missing required column: ' . $required;
                                }
                            }
                        } else {
                            foreach (['property_number','inventory_type','description'] as $required) if (!isset($col[$required])) $errors[] = 'Missing required column: ' . $required;
                        }
                        if (!$errors) {
                            $parsed = [];
                            $seenPropertyNumbers = [];
                            $seenSerialNumbers = [];
                            $userId = current_user_id();
                            for ($i = 1; $i < count($rows); $i++) {
                                $src = $rows[$i];
                                if (!array_filter($src, fn($v) => trim((string) $v) !== '')) continue;
                                $isSemiTemplate = isset($col['propno']);
                                $itemDescription = $isSemiTemplate
                                    ? li_build_description(
                                        li_pick_csv_value($src, $col, ['itemdesc']),
                                        li_pick_csv_value($src, $col, ['specifications'])
                                    )
                                    : trim((string) ($src[$col['description'] ?? null] ?? ''));
                                $r = [
                                    'source_row' => $i + 1,
                                    'po_number' => '',
                                    'property_number' => $isSemiTemplate ? li_pick_csv_value($src, $col, ['propno']) : trim((string) ($src[$col['property_number'] ?? null] ?? '')),
                                    'item_type' => $isSemiTemplate ? 'semi_expendable' : strtolower(str_replace([' ', '-'], '_', (string) ($src[$col['inventory_type']] ?? ''))),
                                    'item_description' => $itemDescription,
                                    'classification' => $isSemiTemplate ? li_clean_optional_value(li_pick_csv_value($src, $col, ['invname'])) : li_clean_optional_value((string) ($src[$col['classification'] ?? null] ?? '')),
                                    'fund' => $isSemiTemplate ? li_clean_optional_value(li_pick_csv_value($src, $col, ['fund'])) : li_clean_optional_value((string) ($src[$col['fund'] ?? null] ?? ($src[$col['fund_number'] ?? null] ?? ''))),
                                    'account_code' => $isSemiTemplate ? li_clean_optional_value(li_pick_csv_value($src, $col, ['accountcode'])) : li_clean_optional_value((string) ($src[$col['account_code'] ?? null] ?? '')),
                                    'supplier' => li_clean_optional_value((string) ($src[$col['supplier'] ?? null] ?? '')),
                                    'brand' => $isSemiTemplate ? li_clean_optional_value(li_pick_csv_value($src, $col, ['brand'])) : li_clean_optional_value((string) ($src[$col['brand'] ?? null] ?? '')),
                                    'model' => $isSemiTemplate ? li_clean_optional_value(li_pick_csv_value($src, $col, ['model'])) : li_clean_optional_value((string) ($src[$col['model'] ?? null] ?? '')),
                                    'serial_no' => $isSemiTemplate ? li_clean_optional_value(li_pick_csv_value($src, $col, ['serialno'])) : li_clean_optional_value((string) ($src[$col['serial_no'] ?? null] ?? '')),
                                    'acquisition_date' => li_derive_acquisition_date(
                                        li_pick_acquisition_date($src, $col),
                                        $isSemiTemplate ? li_pick_csv_value($src, $col, ['propno']) : trim((string) ($src[$col['property_number'] ?? null] ?? ''))
                                    ),
                                    'quantity' => trim((string) ($src[$col['quantity'] ?? null] ?? '1')),
                                    'unit_cost' => $isSemiTemplate ? li_normalize_decimal_value(li_pick_csv_value($src, $col, ['unitcost'])) : li_normalize_decimal_value((string) ($src[$col['unit_cost'] ?? null] ?? '')),
                                    'office' => li_clean_optional_value((string) ($src[$col['office'] ?? null] ?? '')),
                                    'employee' => li_clean_optional_value((string) ($src[$col['employee'] ?? null] ?? '')),
                                    'responsibility_code' => li_clean_optional_value((string) ($src[$col['responsibility_code'] ?? null] ?? '')),
                                    'condition_status' => li_clean_optional_value((string) ($src[$col['condition_status'] ?? null] ?? 'good')),
                                    'remarks' => li_clean_optional_value((string) ($src[$col['remarks'] ?? null] ?? '')),
                                    'errors' => [],
                                ];
                                $r['po_number'] = li_resolve_po_number((string) ($src[$col['po_number'] ?? null] ?? ''), $r['property_number']);
                                if (!in_array($r['item_type'], ['equipment', 'semi_expendable'], true)) $r['errors'][] = 'Type must be equipment or semi_expendable.';
                                if (!ctype_digit($r['quantity']) || (int) $r['quantity'] <= 0) $r['errors'][] = 'Quantity must be a whole number.';
                                if ($r['unit_cost'] === '' || !is_numeric($r['unit_cost'])) $r['errors'][] = 'Unit cost is required.';

                                $account = $maps['account'][li_norm($r['account_code'])] ?? null;
                                $classification = null;
                                $fund = $maps['fund'][li_norm($r['fund'])] ?? null;
                                $supplier = $maps['supplier'][li_norm($r['supplier'])] ?? null;
                                $brand = null;
                                $model = null;
                                $office = $maps['office'][li_norm($r['office'])] ?? null;
                                $employee = $maps['employee'][li_norm($r['employee'])] ?? null;
                                $rc = $maps['rc'][li_norm($r['responsibility_code'])] ?? null;
                                if ($r['supplier'] !== '' && !$supplier) $r['errors'][] = 'Unknown supplier.';
                                if ($r['account_code'] !== '' && !$account) $r['errors'][] = 'Unknown account code.';
                                if ($r['fund'] !== '' && !$fund) $r['errors'][] = 'Unknown fund.';
                                if ($r['office'] !== '' && !$office) $r['errors'][] = 'Unknown office.';
                                if ($r['employee'] !== '' && !$employee) $r['errors'][] = 'Unknown employee.';
                                if ($r['responsibility_code'] !== '' && !$rc) $r['errors'][] = 'Unknown RC.';

                                try {
                                    $classification = li_find_or_create_classification($db, $maps, $r['classification'], $r['item_type'], isset($account['id']) ? (int) $account['id'] : null, $userId);
                                    $brand = li_find_or_create_brand($db, $maps, $r['brand'], $userId);
                                    $brandId = isset($brand['id']) ? (int) $brand['id'] : null;
                                    $model = li_find_or_create_model($db, $maps, $r['model'], $brandId, $userId);
                                    if ($brand && $model && (int) ($model['brand_id'] ?? 0) > 0 && (int) $model['brand_id'] !== (int) $brand['id']) {
                                        $r['errors'][] = 'Model does not belong to brand.';
                                    }
                                } catch (Throwable $e) {
                                    $r['errors'][] = $e->getMessage();
                                }

                                if (!$office && $employee && !empty($employee['office_id'])) foreach ($offices as $off) if ((int) $off['id'] === (int) $employee['office_id']) { $office = $off; break; }
                                if ($office && !$employee) foreach ($employees as $emp) if ((int) ($emp['office_id'] ?? 0) === (int) $office['id'] && (int) ($emp['is_unit_head'] ?? 0) === 1) { $employee = $emp; break; }

                                if ($employee && $office && (int) ($employee['office_id'] ?? 0) !== (int) $office['id']) $r['errors'][] = 'Employee does not belong to office.';
                                if ($rc && $office && (int) ($rc['office_id'] ?? 0) !== (int) $office['id']) $r['errors'][] = 'RC does not belong to office.';

                                if ($r['property_number'] !== '') {
                                    $propertyKey = strtoupper($r['property_number']);
                                    if (isset($seenPropertyNumbers[$propertyKey])) {
                                        $r['errors'][] = 'Property number duplicates row ' . $seenPropertyNumbers[$propertyKey] . ' in this file.';
                                    } else {
                                        $seenPropertyNumbers[$propertyKey] = $r['source_row'];
                                    }
                                    $propertyConflict = asset_identifier_conflict($db, 'property_number', $r['property_number']);
                                    if ($propertyConflict) {
                                        $r['errors'][] = 'Property number already exists in ' . $propertyConflict['label'] . ' #' . $propertyConflict['id'] . '.';
                                    }
                                }
                                if ($r['serial_no'] !== '') {
                                    $serialKey = strtoupper($r['serial_no']);
                                    if (isset($seenSerialNumbers[$serialKey])) {
                                        $r['errors'][] = 'Serial number duplicates row ' . $seenSerialNumbers[$serialKey] . ' in this file.';
                                    } else {
                                        $seenSerialNumbers[$serialKey] = $r['source_row'];
                                    }
                                    $serialConflict = asset_identifier_conflict($db, 'serial_no', $r['serial_no'], '', 0, true);
                                    if ($serialConflict) {
                                        $r['errors'][] = 'Serial number already exists in ' . $serialConflict['label'] . ' #' . $serialConflict['id'] . '.';
                                    }
                                }

                                $r['classification_id'] = $classification['id'] ?? null;
                                $r['account_code_id'] = $account['id'] ?? null;
                                $r['fund_id'] = $fund['id'] ?? null;
                                $r['supplier_id'] = $supplier['id'] ?? null;
                                $r['brand_id'] = $brand['id'] ?? null;
                                $r['model_id'] = $model['id'] ?? null;
                                $r['office_id'] = $office['id'] ?? null;
                                $r['employee_id'] = $employee['id'] ?? null;
                                $r['responsibility_code_id'] = $rc['id'] ?? null;
                                $r['brand_name'] = $brand['brand_name'] ?? $r['brand'];
                                $r['model_name'] = $model['model_name'] ?? $r['model'];
                                $r['resolved_fund'] = $fund ? trim(implode(' - ', array_filter([(string) ($fund['fund_code'] ?? ''), (string) ($fund['fund_name'] ?? '')]))) : '';
                                $r['resolved_office'] = $office['office_name'] ?? '';
                                $r['resolved_employee'] = $employee ? li_name($employee) : '';
                                $r['resolved_rc'] = $rc['code'] ?? '';
                                $parsed[] = $r;
                            }
                            $_SESSION['legacy_import_preview'] = $parsed;
                            redirect('modules/property/legacy_import.php');
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }

    $preview = $_SESSION['legacy_import_preview'] ?? [];
    foreach ($preview as $row) empty($row['errors']) ? $summary['valid']++ : $summary['invalid']++;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Import Legacy Assets</h5>
                <div class="small text-muted">Upload a CSV or XLSX file from Excel, review the parsed rows, then import the valid ones.</div>
            </div>
            <div class="card-body">
                <?php if ($flash): ?><div class="alert alert-success"><?php echo h($flash['message']); ?></div><?php endif; ?>
                <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                <?php if (!class_exists('ZipArchive')): ?>
                    <div class="alert alert-warning">XLSX import is unavailable on this PHP setup. You can still import a <strong>CSV</strong> file, or enable <code>extension=zip</code> in <code>php.ini</code> and restart Apache to restore XLSX support.</div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="preview">
                    <div class="col-md-8">
                        <label class="form-label">Legacy File</label>
                        <input type="file" name="legacy_file" class="form-control" accept=".csv,.xlsx" required>
                        <div class="form-text">Required headers: `property_number`, `inventory_type`, `description`. Optional: `po_number`, `fund` or `fund_number`, `classification`, `account_code`, `supplier`, `brand`, `model`, `serial_no`, `acquisition_date` or `date_acquired`, `quantity`, `unit_cost`, `office`, `employee`, `responsibility_code`, `condition_status`, `remarks`.</div>
                    </div>
                    <div class="col-md-4 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Preview Import</button>
                        <a href="<?php echo base_url('modules/property/legacy_import.php?download_template=1'); ?>" class="btn btn-outline-primary"><i class="bi bi-download me-1"></i>Download Template</a>
                        <a href="<?php echo base_url('modules/property/legacy_assets.php'); ?>" class="btn btn-outline-secondary">Back</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ($preview): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div><h5 class="card-title mb-0">Preview</h5><div class="small text-muted"><?php echo (int) $summary['valid']; ?> valid, <?php echo (int) $summary['invalid']; ?> invalid</div></div>
                    <div class="d-flex gap-2">
                        <form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="clear_preview"><button type="submit" class="btn btn-outline-secondary">Clear</button></form>
                        <form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="import"><button type="submit" class="btn btn-success" <?php echo $summary['valid'] ? '' : 'disabled'; ?>>Import Valid Rows</button></form>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Row</th><th>Property No.</th><th>Type</th><th>Description</th><th>Fund</th><th>Office</th><th>Unit Head</th><th>RC</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($preview as $row): ?>
                                <tr>
                                    <td><?php echo (int) $row['source_row']; ?></td>
                                    <td><?php echo h($row['property_number']); ?></td>
                                    <td><?php echo h($row['item_type']); ?></td>
                                    <td><div class="fw-semibold"><?php echo h($row['item_description']); ?></div><small class="text-muted"><?php echo h($row['brand_name'] . ($row['model_name'] ? ' | ' . $row['model_name'] : '')); ?></small></td>
                                    <td><?php echo h($row['resolved_fund'] ?: $row['fund']); ?></td>
                                    <td><?php echo h($row['resolved_office'] ?: $row['office']); ?></td>
                                    <td><?php echo h($row['resolved_employee'] ?: $row['employee']); ?></td>
                                    <td><?php echo h($row['resolved_rc'] ?: $row['responsibility_code']); ?></td>
                                    <td><?php if (empty($row['errors'])): ?><span class="badge text-bg-success">Ready</span><?php else: ?><span class="badge text-bg-danger">Issue</span><div class="small text-danger mt-1"><?php echo h(implode(' ', $row['errors'])); ?></div><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


