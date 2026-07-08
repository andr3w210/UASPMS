<?php
require_once __DIR__ . '/../spams/app/config/init.php';

function cli_out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
    if (function_exists('flush')) {
        flush();
    }
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
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

function cli_pick_acquisition_date(array $src, array $col): string
{
    return cli_clean_optional_value(cli_pick_csv_value($src, $col, [
        'acquisition_date',
        'date_acquired',
        'date_acquire',
        'date_of_acquisition',
        'acquired_date',
        'acquired',
    ]));
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

function cli_store_supplier_map(array &$maps, array $supplier): void
{
    if (!isset($supplier['id'])) {
        return;
    }

    $supplier['id'] = (int) $supplier['id'];
    $maps['supplier']['__id_' . $supplier['id']] = $supplier;

    foreach (['supplier_name', 'supplier_code'] as $field) {
        $value = trim((string) ($supplier[$field] ?? ''));
        if ($value !== '') {
            $maps['supplier'][cli_norm($value)] = $supplier;
        }
    }
}

function cli_store_office_map(array &$maps, array $office): void
{
    if (!isset($office['id'])) {
        return;
    }

    $office['id'] = (int) $office['id'];
    $maps['office']['__id_' . $office['id']] = $office;

    foreach (['office_name', 'office_code'] as $field) {
        $value = trim((string) ($office[$field] ?? ''));
        if ($value !== '') {
            $maps['office'][cli_norm($value)] = $office;
        }
    }
}

function cli_generate_office_code(mysqli $db, string $seed): string
{
    $seed = trim($seed);
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $seed) ?: 'OFFICE');
    $base = substr($base, 0, 20);

    $candidate = $base;
    $suffix = 1;
    while (true) {
        $stmt = $db->prepare('SELECT id FROM offices WHERE office_code = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to validate generated office code.');
        }

        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $candidate;
        }

        $candidate = substr($base, 0, max(1, 50 - strlen((string) $suffix) - 1)) . '-' . $suffix;
        $suffix++;
    }
}

function cli_find_or_create_office(mysqli $db, array &$maps, string $officeName, int $userId): ?array
{
    $officeName = cli_clean_optional_value($officeName);
    if ($officeName === '' || cli_is_unknown_value($officeName)) {
        return null;
    }

    $key = cli_norm($officeName);
    if (isset($maps['office'][$key])) {
        $existing = $maps['office'][$key];
        if ((int) ($existing['is_active'] ?? 1) !== 1) {
            $update = $db->prepare('UPDATE offices SET is_active = 1, updated_by = ? WHERE id = ?');
            if ($update) {
                $id = (int) $existing['id'];
                $update->bind_param('ii', $userId, $id);
                $update->execute();
                $update->close();
                $existing['is_active'] = 1;
                cli_store_office_map($maps, $existing);
            }
        }

        return $existing;
    }

    $select = $db->prepare('SELECT id, office_name, office_code, is_active FROM offices WHERE LOWER(TRIM(office_name)) = LOWER(TRIM(?)) OR LOWER(TRIM(COALESCE(office_code, ""))) = LOWER(TRIM(?)) LIMIT 1');
    if ($select) {
        $select->bind_param('ss', $officeName, $officeName);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            if ((int) ($existing['is_active'] ?? 1) !== 1) {
                $update = $db->prepare('UPDATE offices SET is_active = 1, updated_by = ? WHERE id = ?');
                if ($update) {
                    $id = (int) $existing['id'];
                    $update->bind_param('ii', $userId, $id);
                    $update->execute();
                    $update->close();
                }
                $existing['is_active'] = 1;
            }

            cli_store_office_map($maps, $existing);
            return $existing;
        }
    }

    $officeCode = cli_generate_office_code($db, $officeName);
    $description = 'Auto-created from legacy asset import.';
    $insert = $db->prepare('INSERT INTO offices (office_name, office_code, description, is_active, created_by) VALUES (?, ?, ?, 1, ?)');
    if (!$insert) {
        throw new RuntimeException('Unable to create missing office.');
    }

    $insert->bind_param('sssi', $officeName, $officeCode, $description, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing office.');
    }

    $created = [
        'id' => $newId,
        'office_name' => $officeName,
        'office_code' => $officeCode,
        'is_active' => 1,
    ];
    cli_store_office_map($maps, $created);
    return $created;
}

function cli_find_or_create_supplier(mysqli $db, array &$maps, string $supplierName, int $userId): ?array
{
    $supplierName = cli_clean_optional_value($supplierName);
    if ($supplierName === '' || cli_is_unknown_value($supplierName)) {
        return null;
    }

    $key = cli_norm($supplierName);
    if (isset($maps['supplier'][$key])) {
        $existing = $maps['supplier'][$key];
        if ((int) ($existing['is_active'] ?? 1) !== 1) {
            $update = $db->prepare('UPDATE suppliers SET is_active = 1, updated_by = ? WHERE id = ?');
            if ($update) {
                $id = (int) $existing['id'];
                $update->bind_param('ii', $userId, $id);
                $update->execute();
                $update->close();
                $existing['is_active'] = 1;
                cli_store_supplier_map($maps, $existing);
            }
        }

        return $existing;
    }

    $select = $db->prepare('SELECT id, supplier_name, supplier_code, is_active FROM suppliers WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?)) LIMIT 1');
    if ($select) {
        $select->bind_param('s', $supplierName);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            if ((int) ($existing['is_active'] ?? 1) !== 1) {
                $update = $db->prepare('UPDATE suppliers SET is_active = 1, updated_by = ? WHERE id = ?');
                if ($update) {
                    $id = (int) $existing['id'];
                    $update->bind_param('ii', $userId, $id);
                    $update->execute();
                    $update->close();
                }
                $existing['is_active'] = 1;
            }

            cli_store_supplier_map($maps, $existing);
            return $existing;
        }
    }

    $supplierCode = 'SUP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $supplierName) ?: 'NEW', 0, 20));
    $suffix = 1;
    while (true) {
        $stmt = $db->prepare('SELECT id FROM suppliers WHERE supplier_code = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to validate generated supplier code.');
        }

        $stmt->bind_param('s', $supplierCode);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            break;
        }

        $supplierCode = 'SUP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $supplierName) ?: 'NEW', 0, 16)) . '-' . $suffix;
        $suffix++;
    }

    $insert = $db->prepare('INSERT INTO suppliers (supplier_name, supplier_code, is_active, created_by) VALUES (?, ?, 1, ?)');
    if (!$insert) {
        throw new RuntimeException('Unable to create missing supplier.');
    }

    $insert->bind_param('ssi', $supplierName, $supplierCode, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing supplier.');
    }

    $created = [
        'id' => $newId,
        'supplier_name' => $supplierName,
        'supplier_code' => $supplierCode,
        'is_active' => 1,
    ];
    cli_store_supplier_map($maps, $created);
    return $created;
}

function cli_store_fund_map(array &$maps, array $fund): void
{
    if (!isset($fund['id'])) {
        return;
    }

    $fund['id'] = (int) $fund['id'];
    $maps['fund']['__id_' . $fund['id']] = $fund;

    foreach (['fund_code', 'fund_name', 'fund_source'] as $field) {
        $value = trim((string) ($fund[$field] ?? ''));
        if ($value !== '') {
            $maps['fund'][cli_norm($value)] = $fund;
        }
    }
}

function cli_generate_fund_code(mysqli $db, string $seed): string
{
    $seed = trim($seed);
    $digits = preg_replace('/\D+/', '', $seed);

    if ($digits !== '') {
        $base = str_pad(substr($digits, -2), 2, '0', STR_PAD_LEFT);
    } else {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $seed) ?? '');
        $base = substr($base, 0, 20);
        if ($base === '') {
            $base = 'FUND';
        }
    }

    $candidate = $base;
    $suffix = 1;

    while (true) {
        $stmt = $db->prepare('SELECT id FROM funds WHERE fund_code = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to validate generated fund code.');
        }

        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $candidate;
        }

        $suffixText = (string) $suffix;
        $trimLength = max(1, 50 - strlen($suffixText) - 1);
        $candidate = substr($base, 0, $trimLength) . '-' . $suffixText;
        $suffix++;
    }
}

function cli_find_or_create_fund(mysqli $db, array &$maps, string $fundValue, int $userId): ?array
{
    $fundValue = cli_clean_optional_value($fundValue);
    if ($fundValue === '' || cli_is_unknown_value($fundValue)) {
        return null;
    }

    $key = cli_norm($fundValue);
    if (isset($maps['fund'][$key])) {
        $existing = $maps['fund'][$key];
        if ((int) ($existing['is_active'] ?? 1) !== 1) {
            $update = $db->prepare('UPDATE funds SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?');
            if ($update) {
                $id = (int) $existing['id'];
                $update->bind_param('ii', $userId, $id);
                $update->execute();
                $update->close();
                $existing['is_active'] = 1;
                cli_store_fund_map($maps, $existing);
            }
        }

        return $existing;
    }

    $select = $db->prepare('SELECT id, fund_code, fund_name, fund_source, is_active FROM funds WHERE LOWER(TRIM(fund_code)) = LOWER(TRIM(?)) OR LOWER(TRIM(COALESCE(fund_name, ""))) = LOWER(TRIM(?)) OR LOWER(TRIM(COALESCE(fund_source, ""))) = LOWER(TRIM(?)) LIMIT 1');
    if ($select) {
        $select->bind_param('sss', $fundValue, $fundValue, $fundValue);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            if ((int) ($existing['is_active'] ?? 1) !== 1) {
                $update = $db->prepare('UPDATE funds SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?');
                if ($update) {
                    $id = (int) $existing['id'];
                    $update->bind_param('ii', $userId, $id);
                    $update->execute();
                    $update->close();
                }
                $existing['is_active'] = 1;
            }
            cli_store_fund_map($maps, $existing);
            return $existing;
        }
    }

    $fundCode = cli_generate_fund_code($db, $fundValue);
    $fundName = $fundValue;
    $insert = $db->prepare('INSERT INTO funds (fund_code, fund_name, is_active, created_by) VALUES (?, ?, 1, ?)');
    if (!$insert) {
        throw new RuntimeException('Unable to create missing fund.');
    }

    $insert->bind_param('ssi', $fundCode, $fundName, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing fund.');
    }

    $created = [
        'id' => $newId,
        'fund_code' => $fundCode,
        'fund_name' => $fundName,
        'fund_source' => null,
        'is_active' => 1,
    ];
    cli_store_fund_map($maps, $created);
    return $created;
}

function cli_store_rc_map(array &$maps, array $rc): void
{
    if (!isset($rc['id'])) {
        return;
    }

    $rc['id'] = (int) $rc['id'];
    $maps['rc']['__id_' . $rc['id']] = $rc;

    $code = trim((string) ($rc['code'] ?? ''));
    if ($code !== '') {
        $maps['rc'][cli_norm($code)] = $rc;
    }
}

function cli_find_or_create_rc(mysqli $db, array &$maps, string $rcCode, ?int $officeId, int $userId): ?array
{
    $rcCode = cli_clean_optional_value($rcCode);
    if ($rcCode === '' || cli_is_unknown_value($rcCode)) {
        return null;
    }

    $key = cli_norm($rcCode);
    $existing = $maps['rc'][$key] ?? null;

    if (!$existing) {
        $select = $db->prepare('SELECT id, code, office_id, is_active FROM responsibility_codes WHERE LOWER(TRIM(code)) = LOWER(TRIM(?)) LIMIT 1');
        if ($select) {
            $select->bind_param('s', $rcCode);
            $select->execute();
            $existing = $select->get_result()->fetch_assoc() ?: null;
            $select->close();
        }
    }

    if ($existing) {
        $targetOffice = ($officeId ?? 0) > 0 ? (int) $officeId : (int) ($existing['office_id'] ?? 0);
        $needsUpdate = (int) ($existing['is_active'] ?? 1) !== 1 || ($targetOffice > 0 && (int) ($existing['office_id'] ?? 0) !== $targetOffice);
        if ($needsUpdate) {
            $update = $db->prepare('UPDATE responsibility_codes SET office_id = NULLIF(?, 0), is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?');
            if ($update) {
                $id = (int) $existing['id'];
                $update->bind_param('iii', $targetOffice, $userId, $id);
                $update->execute();
                $update->close();
                $existing['office_id'] = $targetOffice > 0 ? $targetOffice : null;
                $existing['is_active'] = 1;
            }
        }

        cli_store_rc_map($maps, $existing);
        return $existing;
    }

    $description = 'Auto-created from legacy asset import.';
    $officeValue = ($officeId ?? 0) > 0 ? (int) $officeId : 0;
    $insert = $db->prepare('INSERT INTO responsibility_codes (code, office_id, description, is_active, created_by) VALUES (?, NULLIF(?, 0), ?, 1, ?)');
    if (!$insert) {
        throw new RuntimeException('Unable to create missing responsibility code.');
    }

    $insert->bind_param('sisi', $rcCode, $officeValue, $description, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing responsibility code.');
    }

    $created = [
        'id' => $newId,
        'code' => $rcCode,
        'office_id' => $officeValue > 0 ? $officeValue : null,
        'is_active' => 1,
    ];
    cli_store_rc_map($maps, $created);
    return $created;
}

function cli_split_employee_name(string $fullName): array
{
    $fullName = trim($fullName);
    if ($fullName === '') {
        return ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'suffix_name' => ''];
    }

    if (strpos($fullName, ',') !== false) {
        [$last, $rest] = array_pad(array_map('trim', explode(',', $fullName, 2)), 2, '');
        $parts = preg_split('/\s+/', $rest) ?: [];
        $first = $parts[0] ?? '';
        $middle = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        return ['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'suffix_name' => ''];
    }

    $parts = preg_split('/\s+/', $fullName) ?: [];
    if (count($parts) === 1) {
        return ['first_name' => $parts[0], 'middle_name' => '', 'last_name' => 'Unknown', 'suffix_name' => ''];
    }

    $first = array_shift($parts);
    $last = array_pop($parts);
    $middle = implode(' ', $parts);
    return ['first_name' => (string) $first, 'middle_name' => $middle, 'last_name' => (string) $last, 'suffix_name' => ''];
}

function cli_generate_employee_no(mysqli $db): string
{
    for ($i = 0; $i < 8; $i++) {
        $candidate = 'IMP-' . date('YmdHis') . '-' . strtoupper(substr(md5((string) microtime(true) . (string) random_int(1000, 999999)), 0, 6));
        $stmt = $db->prepare('SELECT id FROM employees WHERE employee_no = ? LIMIT 1');
        if (!$stmt) {
            break;
        }
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $candidate;
        }
    }

    return 'IMP-' . date('YmdHis') . '-' . random_int(100000, 999999);
}

function cli_store_employee_map(array &$maps, array $employee): void
{
    if (!isset($employee['id'])) {
        return;
    }

    $employee['id'] = (int) $employee['id'];
    $maps['employee']['__id_' . $employee['id']] = $employee;
    $display = cli_name($employee);
    if ($display !== '') {
        $maps['employee'][cli_norm($display)] = $employee;
    }
}

function cli_find_or_create_employee(mysqli $db, array &$maps, string $employeeName, ?int $officeId, ?int $rcId, int $userId): ?array
{
    $employeeName = cli_clean_optional_value($employeeName);
    if ($employeeName === '' || cli_is_unknown_value($employeeName)) {
        return null;
    }

    $key = cli_norm($employeeName);
    $existing = $maps['employee'][$key] ?? null;

    if (!$existing) {
        $all = $db->query('SELECT id, employee_no, first_name, middle_name, last_name, suffix_name, office_id, responsibility_code_id, is_active FROM employees');
        if ($all instanceof mysqli_result) {
            while ($candidate = $all->fetch_assoc()) {
                if (cli_norm(cli_name($candidate)) === $key) {
                    $existing = $candidate;
                    break;
                }
            }
        }
    }

    if ($existing) {
        $targetOffice = ($officeId ?? 0) > 0 ? (int) $officeId : (int) ($existing['office_id'] ?? 0);
        $targetRc = ($rcId ?? 0) > 0 ? (int) $rcId : (int) ($existing['responsibility_code_id'] ?? 0);
        $needsUpdate = (int) ($existing['is_active'] ?? 1) !== 1
            || ($targetOffice > 0 && (int) ($existing['office_id'] ?? 0) !== $targetOffice)
            || ($targetRc > 0 && (int) ($existing['responsibility_code_id'] ?? 0) !== $targetRc);

        if ($needsUpdate) {
            $update = $db->prepare('UPDATE employees SET office_id = NULLIF(?, 0), responsibility_code_id = NULLIF(?, 0), is_active = 1, updated_by = ? WHERE id = ?');
            if ($update) {
                $id = (int) $existing['id'];
                $update->bind_param('iiii', $targetOffice, $targetRc, $userId, $id);
                $update->execute();
                $update->close();
                $existing['office_id'] = $targetOffice > 0 ? $targetOffice : null;
                $existing['responsibility_code_id'] = $targetRc > 0 ? $targetRc : null;
                $existing['is_active'] = 1;
            }
        }

        cli_store_employee_map($maps, $existing);
        return $existing;
    }

    $nameParts = cli_split_employee_name($employeeName);
    $firstName = trim((string) ($nameParts['first_name'] ?? ''));
    $lastName = trim((string) ($nameParts['last_name'] ?? ''));
    if ($firstName === '') {
        $firstName = 'Unknown';
    }
    if ($lastName === '') {
        $lastName = 'Unknown';
    }

    $employeeNo = cli_generate_employee_no($db);
    $middleName = trim((string) ($nameParts['middle_name'] ?? ''));
    $suffixName = trim((string) ($nameParts['suffix_name'] ?? ''));
    $officeValue = ($officeId ?? 0) > 0 ? (int) $officeId : 0;
    $rcValue = ($rcId ?? 0) > 0 ? (int) $rcId : 0;

    $insert = $db->prepare('INSERT INTO employees (employee_no, first_name, middle_name, last_name, suffix_name, office_id, responsibility_code_id, is_active, created_by) VALUES (?, ?, NULLIF(?, ""), ?, NULLIF(?, ""), NULLIF(?, 0), NULLIF(?, 0), 1, ?)');
    if (!$insert) {
        throw new RuntimeException('Unable to create missing employee.');
    }

    $insert->bind_param('sssssiii', $employeeNo, $firstName, $middleName, $lastName, $suffixName, $officeValue, $rcValue, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing employee.');
    }

    $created = [
        'id' => $newId,
        'employee_no' => $employeeNo,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'suffix_name' => $suffixName,
        'office_id' => $officeValue > 0 ? $officeValue : null,
        'responsibility_code_id' => $rcValue > 0 ? $rcValue : null,
        'is_active' => 1,
    ];
    cli_store_employee_map($maps, $created);
    return $created;
}

function cli_find_existing_legacy_asset_id(mysqli $db, int $legacyId, string $systemReference, string $sourcePropertyNumber): int
{
    if ($legacyId > 0) {
        $stmt = $db->prepare('SELECT id FROM legacy_assets WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $legacyId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (int) $row['id'];
            }
        }
    }

    if ($systemReference !== '') {
        $stmt = $db->prepare('SELECT id FROM legacy_assets WHERE system_reference = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $systemReference);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (int) $row['id'];
            }
        }
    }

    if ($sourcePropertyNumber !== '') {
        $stmt = $db->prepare('SELECT id FROM legacy_assets WHERE property_number = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $sourcePropertyNumber);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (int) $row['id'];
            }
        }
    }

    return 0;
}

$filePath = $argv[1] ?? '';
if ($filePath === '') {
    fwrite(STDERR, "Usage: php scripts/import_legacy_assets_cli.php <csv-path> [--apply]" . PHP_EOL);
    exit(1);
}
$apply = in_array('--apply', $argv, true);
if (!$apply) {
    cli_out('Dry-run only. Re-run with --apply to persist legacy asset import changes.');
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
$funds = ($db->query("SELECT id, fund_code, fund_name, fund_source, is_active FROM funds ORDER BY fund_code ASC, fund_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$suppliers = ($db->query("SELECT id, supplier_name, supplier_code, is_active FROM suppliers ORDER BY supplier_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$brands = ($db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$models = ($db->query("SELECT id, model_name, brand_id FROM models WHERE is_active = 1 ORDER BY model_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$offices = ($db->query("SELECT id, office_name, office_code, is_active FROM offices ORDER BY office_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$employees = ($db->query("SELECT id, employee_no, office_id, responsibility_code_id, is_unit_head, is_active, first_name, middle_name, last_name, suffix_name FROM employees ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];
$responsibilityCodes = ($db->query("SELECT id, office_id, code, is_active FROM responsibility_codes ORDER BY code ASC") ?: false)?->fetch_all(MYSQLI_ASSOC) ?? [];

$maps = ['classification' => [], 'account' => [], 'fund' => [], 'supplier' => [], 'brand' => [], 'model' => [], 'office' => [], 'employee' => [], 'rc' => []];
foreach ($classifications as $r) {
    $maps['classification'][cli_norm($r['classification_name'])] = $r;
}
foreach ($accountCodes as $r) {
    $maps['account'][cli_norm($r['account_code'])] = $r;
    $maps['account'][cli_norm($r['account_name'])] = $r;
}
foreach ($funds as $r) {
    cli_store_fund_map($maps, $r);
}
foreach ($suppliers as $r) {
    cli_store_supplier_map($maps, $r);
}
foreach ($brands as $r) {
    $maps['brand'][cli_norm($r['brand_name'])] = $r;
}
foreach ($models as $r) {
    $maps['model'][cli_norm($r['model_name'])][] = $r;
}
foreach ($offices as $r) {
    cli_store_office_map($maps, $r);
}
foreach ($employees as $r) {
    cli_store_employee_map($maps, $r);
}
foreach ($responsibilityCodes as $r) {
    cli_store_rc_map($maps, $r);
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

$update = $db->prepare("UPDATE legacy_assets SET po_number = ?, property_number = ?, item_type = ?, item_description = ?, classification_id = ?, account_code_id = ?, fund_id = ?, supplier_id = ?, brand_id = ?, model_id = ?, brand = ?, model = ?, serial_no = ?, acquisition_date = NULLIF(?, ''), quantity = ?, unit_cost = ?, acquisition_cost = ?, office_id = ?, employee_id = ?, responsibility_code_id = ?, condition_status = ?, remarks = ?, is_active = 1 WHERE id = ?");
if (!$update) {
    fwrite(STDERR, "Unable to prepare update statement." . PHP_EOL);
    $insert->close();
    exit(1);
}

$db->begin_transaction();
$inserted = 0;
$updated = 0;
$skipped = 0;
$failures = [];
$seenPropertyNumbers = [];
$seenSerialNumbers = [];

try {
    for ($i = 1; $i < count($rows); $i++) {
        $src = $rows[$i];
        if (!array_filter($src, static fn($v) => trim((string) $v) !== '')) {
            continue;
        }

        if ($i === 1 || $i % 100 === 0) {
            cli_out('Processing row ' . $i . ' of ' . (count($rows) - 1) . '...');
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
            'legacy_id' => (int) ($src[$col['legacy_id'] ?? $col['id'] ?? null] ?? 0),
            'system_reference_input' => trim((string) ($src[$col['system_reference'] ?? null] ?? '')),
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
                cli_pick_acquisition_date($src, $col),
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

        $sourcePropertyNumber = $row['property_number'];
        if ($sourcePropertyNumber !== '') {
            $propertyKey = strtoupper($sourcePropertyNumber);
            if (isset($seenPropertyNumbers[$propertyKey])) {
                $row['errors'][] = 'Property number duplicates row ' . $seenPropertyNumbers[$propertyKey] . ' in this file.';
            } else {
                $seenPropertyNumbers[$propertyKey] = $row['source_row'];
            }
        }
        if ($row['serial_no'] !== '') {
            $serialKey = strtoupper($row['serial_no']);
            if (isset($seenSerialNumbers[$serialKey])) {
                $row['errors'][] = 'Serial number duplicates row ' . $seenSerialNumbers[$serialKey] . ' in this file.';
            } else {
                $seenSerialNumbers[$serialKey] = $row['source_row'];
            }
        }

        $account = $maps['account'][cli_norm($row['account_code'])] ?? null;
        $classification = null;
        $fund = null;
        $supplier = null;
        $office = null;
        $employee = null;
        $rc = null;

        if ($row['account_code'] !== '' && !$account) {
            $row['errors'][] = 'Unknown account code.';
        }

        try {
            $office = cli_find_or_create_office($db, $maps, $row['office'], $userId);
            $supplier = cli_find_or_create_supplier($db, $maps, $row['supplier'], $userId);
            $fund = cli_find_or_create_fund($db, $maps, $row['fund'], $userId);
            $officeIdSeed = $office ? (int) ($office['id'] ?? 0) : 0;
            $rc = cli_find_or_create_rc($db, $maps, $row['responsibility_code'], $officeIdSeed > 0 ? $officeIdSeed : null, $userId);
            $rcIdSeed = $rc ? (int) ($rc['id'] ?? 0) : 0;
            $employee = cli_find_or_create_employee($db, $maps, $row['employee'], $officeIdSeed > 0 ? $officeIdSeed : null, $rcIdSeed > 0 ? $rcIdSeed : null, $userId);
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

        if ($row['office'] !== '' && !$office) {
            $row['errors'][] = 'Unknown office.';
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

        if ($row['errors']) {
            $skipped++;
            $failures[] = 'Row ' . $row['source_row'] . ': ' . implode(' ', $row['errors']);
            continue;
        }

        $yearValue = date('Y');
        if ($row['acquisition_date'] !== '') {
            $timestamp = strtotime($row['acquisition_date']);
            if ($timestamp !== false) {
                $yearValue = date('Y', $timestamp);
            }
        }
        $fundCode = trim((string) ($fund['fund_code'] ?? ''));
        $fundSource = trim((string) ($fund['fund_source'] ?? ''));
        $fundNumber = fund_number_from_source($fundCode, $fundSource);
        if ($fundNumber === '') {
            $fundNumber = $fundCode;
        }
        $accountCode = trim((string) ($account['account_code'] ?? $row['account_code']));
        $officeCode = trim((string) ($office['office_code'] ?? ''));

        $row['property_number'] = generate_property_number($db, $yearValue, $fundNumber, $accountCode, $officeCode);
        $row['po_number'] = cli_resolve_po_number($row['po_number'], $row['property_number']);

        $systemReference = $row['system_reference_input'] !== '' ? $row['system_reference_input'] : next_module_code($db, 'stock_items');
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

        $existingId = cli_find_existing_legacy_asset_id($db, (int) $row['legacy_id'], $row['system_reference_input'], $sourcePropertyNumber);
        $propertyConflict = asset_identifier_conflict($db, 'property_number', $row['property_number'], 'legacy', $existingId);
        if ($propertyConflict) {
            $skipped++;
            $failures[] = 'Row ' . $row['source_row'] . ': Property number already exists in ' . $propertyConflict['label'] . ' #' . $propertyConflict['id'] . '.';
            continue;
        }
        if ($row['serial_no'] !== '') {
            $serialConflict = asset_identifier_conflict($db, 'serial_no', $row['serial_no'], 'legacy', $existingId, true);
            if ($serialConflict) {
                $skipped++;
                $failures[] = 'Row ' . $row['source_row'] . ': Serial number already exists in ' . $serialConflict['label'] . ' #' . $serialConflict['id'] . '.';
                continue;
            }
        }
        if ($existingId > 0) {
            $update->bind_param(
                'ssssiiiiiissssiddiiissi',
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
                $existingId
            );

            if (!$update->execute()) {
                throw new RuntimeException('Failed to update row ' . $row['source_row'] . ': ' . $update->error);
            }

            $updated++;
            continue;
        }

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

    if ($apply) {
        $db->commit();
    } else {
        $db->rollback();
    }

    cli_out(($apply ? 'Inserted: ' : 'Rows that would insert: ') . $inserted);
    cli_out(($apply ? 'Updated: ' : 'Rows that would update: ') . $updated);
    cli_out('Skipped: ' . $skipped);
    if ($failures) {
        cli_out('Failures:');
        foreach ($failures as $failure) {
            cli_out($failure);
        }
    }
} catch (Throwable $e) {
    $db->rollback();
    $update->close();
    $insert->close();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$update->close();
$insert->close();
exit(0);
