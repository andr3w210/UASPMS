<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$errors = [];
$offices = [];
$employees = [];
$employeeAssignments = [];
$responsibilityCodes = [];
$classifications = [];
$accountCodes = [];
$brands = [];
$models = [];
$suppliers = [];
$funds = [];
$unitOfMeasures = [];
$csrfToken = csrf_token();
$duplicateSource = null;
$form = [
    'property_number' => '',
    'po_number' => '',
    'item_type' => 'equipment',
    'item_description' => '',
    'classification_id' => '',
    'account_code_id' => '',
    'fund_id' => '',
    'supplier_id' => '',
    'brand_id' => '',
    'model_id' => '',
    'serial_no' => '',
    'acquisition_date' => '',
    'quantity' => '1',
    'unit_of_measure_id' => '',
    'unit_cost' => '',
    'office_id' => '',
    'employee_id' => '',
    'responsibility_code_id' => '',
    'condition_status' => 'good',
    'remarks' => '',
];

function legacy_asset_temp_property_number(mysqli $db, string $officeCode = ''): string
{
    $officeCode = strtoupper(trim($officeCode));
    $officeCode = preg_replace('/[^A-Z0-9]/', '', $officeCode) ?? '';
    if ($officeCode === '') {
        $officeCode = 'GEN';
    }

    $prefix = 'TEMP-' . $officeCode . '-' . date('Y') . '-';
    $nextSeq = 1;

    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(property_number, '-', -1) AS UNSIGNED)), 0) AS current_value
         FROM legacy_assets
         WHERE property_number LIKE ?"
    );
    if ($stmt) {
        $pattern = $prefix . '%';
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $nextSeq = ((int) ($row['current_value'] ?? 0)) + 1;
    }

    do {
        $propertyNumber = $prefix . str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        $conflict = asset_identifier_conflict($db, 'property_number', $propertyNumber);
        $nextSeq++;
    } while ($conflict);

    return $propertyNumber;
}

function legacy_asset_parse_bulk_rows(string $rawText): array
{
    $rows = [];
    $lines = preg_split('/\R/', $rawText) ?: [];

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^(?:s\/?n|serial(?:\s+no\.?)?)\s*[:#-]?\s*(.+)$/i', $line, $serialMatch) && $rows) {
            $rows[count($rows) - 1]['serial_no'] = trim((string) $serialMatch[1]);
            continue;
        }

        $parts = preg_split('/\t+/', $line);
        $quantity = '1';
        $unit = '';
        $unitCost = '';
        $totalCost = '';
        $description = $line;

        if (is_array($parts) && count($parts) >= 3 && ctype_digit(trim((string) $parts[0]))) {
            $quantity = trim((string) $parts[0]);
            $unit = trim((string) $parts[1]);
            $remainingParts = array_values(array_filter(array_map(static function ($part): string {
                return trim((string) $part);
            }, array_slice($parts, 2)), static function (string $part): bool {
                return $part !== '';
            }));
            $descriptionParts = [];
            foreach ($remainingParts as $part) {
                $normalizedAmount = legacy_asset_normalize_amount($part);
                if ($normalizedAmount !== '' && count($descriptionParts) === 0) {
                    if ($unitCost === '') {
                        $unitCost = $normalizedAmount;
                        continue;
                    }
                    if ($totalCost === '') {
                        $totalCost = $normalizedAmount;
                        continue;
                    }
                }
                $descriptionParts[] = $part;
            }
            $description = trim(implode(' ', $descriptionParts));
        } elseif (preg_match('/^(\d+)\s+([A-Za-z.\/-]+)\s+(.+)$/', $line, $match)) {
            $quantity = $match[1];
            $unit = $match[2];
            $description = trim($match[3]);
            $tokens = preg_split('/\s+/', $description) ?: [];
            if ($tokens) {
                $firstAmount = legacy_asset_normalize_amount((string) ($tokens[0] ?? ''));
                if ($firstAmount !== '' && count($tokens) > 1) {
                    $unitCost = $firstAmount;
                    array_shift($tokens);
                    $secondAmount = legacy_asset_normalize_amount((string) ($tokens[0] ?? ''));
                    if ($secondAmount !== '' && count($tokens) > 1) {
                        $totalCost = $secondAmount;
                        array_shift($tokens);
                    }
                    $description = trim(implode(' ', $tokens));
                }
            }
        }

        $description = preg_replace('/\s+/', ' ', $description) ?? $description;
        if ($description === '') {
            continue;
        }

        $rows[] = [
            'quantity' => $quantity,
            'unit_text' => $unit,
            'item_description' => $description,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'serial_no' => '',
        ];
    }

    return $rows;
}

function legacy_asset_normalize_amount(string $value): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/[A-Za-z]/', $value)) {
        return '';
    }

    $value = str_replace([',', 'PHP', 'Php', 'php', 'P', '₱'], '', $value);
    $value = trim($value);
    if ($value === '' || !is_numeric($value) || (float) $value < 0) {
        return '';
    }

    return number_format((float) $value, 2, '.', '');
}

function legacy_asset_unit_id_from_text(array $unitOfMeasures, string $unitText): string
{
    $needle = strtolower(trim($unitText));
    $needle = rtrim($needle, '.');
    if ($needle === '') {
        return '';
    }

    $aliases = [
        'pc' => 'pcs',
        'piece' => 'pcs',
        'pieces' => 'pcs',
        'unit' => 'unit',
        'units' => 'unit',
        'set' => 'sets',
        'bottle' => 'bottles',
    ];
    $needle = $aliases[$needle] ?? $needle;

    foreach ($unitOfMeasures as $unitRow) {
        $name = strtolower(trim((string) ($unitRow['uom_name'] ?? '')));
        $abbr = strtolower(trim((string) ($unitRow['abbreviation'] ?? '')));
        $name = rtrim($name, '.');
        $abbr = rtrim($abbr, '.');
        $candidates = array_unique(array_filter([
            $name,
            $abbr,
            $aliases[$name] ?? '',
            $aliases[$abbr] ?? '',
        ]));
        if (in_array($needle, $candidates, true)) {
            return (string) ((int) ($unitRow['id'] ?? 0));
        }
    }

    return '';
}

function legacy_asset_lookup_label(array $rows, string $idValue, string $idKey, string $labelKey): string
{
    foreach ($rows as $row) {
        if ((int) ($row[$idKey] ?? 0) === (int) $idValue) {
            return trim((string) ($row[$labelKey] ?? ''));
        }
    }

    return '';
}

if ($db) {
    ensure_legacy_assets_fund_column($db);
    ensure_legacy_assets_unit_of_measure_column($db);

    $res = $db->query("SELECT id, office_code, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($res instanceof mysqli_result) { $offices = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, office_id, responsibility_code_id, is_unit_head, position_title, first_name, middle_name, last_name, suffix_name FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC");
    if ($res instanceof mysqli_result) { $employees = $res->fetch_all(MYSQLI_ASSOC); }
    if (schema_has_table($db, 'employee_assignments')) {
        $res = $db->query("
            SELECT ea.employee_id, ea.office_id, ea.responsibility_code_id, ea.is_unit_head, ea.is_primary
            FROM employee_assignments ea
            INNER JOIN employees e ON e.id = ea.employee_id
            WHERE ea.is_active = 1 AND e.is_active = 1
            ORDER BY ea.is_primary DESC, ea.is_unit_head DESC, e.last_name ASC, e.first_name ASC, ea.id ASC
        ");
        if ($res instanceof mysqli_result) { $employeeAssignments = $res->fetch_all(MYSQLI_ASSOC); }
    }
    if (!$employeeAssignments) {
        foreach ($employees as $employeeRow) {
            $employeeAssignments[] = [
                'employee_id' => (int) ($employeeRow['id'] ?? 0),
                'office_id' => (int) ($employeeRow['office_id'] ?? 0),
                'responsibility_code_id' => (int) ($employeeRow['responsibility_code_id'] ?? 0),
                'is_unit_head' => (int) ($employeeRow['is_unit_head'] ?? 0),
                'is_primary' => 1,
            ];
        }
    } else {
        $assignedEmployeeIds = [];
        foreach ($employeeAssignments as $assignmentRow) {
            $assignedEmployeeIds[(int) ($assignmentRow['employee_id'] ?? 0)] = true;
        }
        foreach ($employees as $employeeRow) {
            $employeeId = (int) ($employeeRow['id'] ?? 0);
            if ($employeeId <= 0 || isset($assignedEmployeeIds[$employeeId])) {
                continue;
            }
            $employeeAssignments[] = [
                'employee_id' => $employeeId,
                'office_id' => (int) ($employeeRow['office_id'] ?? 0),
                'responsibility_code_id' => (int) ($employeeRow['responsibility_code_id'] ?? 0),
                'is_unit_head' => (int) ($employeeRow['is_unit_head'] ?? 0),
                'is_primary' => 1,
            ];
        }
    }
    $res = $db->query("SELECT id, office_id, code, description FROM responsibility_codes WHERE is_active = 1 ORDER BY code ASC");
    if ($res instanceof mysqli_result) { $responsibilityCodes = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, classification_name, classification_family, classification_group, account_code_id FROM classifications WHERE is_active = 1 ORDER BY classification_family ASC, classification_name ASC");
    if ($res instanceof mysqli_result) { $classifications = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, account_code, account_name, account_group FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
    if ($res instanceof mysqli_result) { $accountCodes = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC");
    if ($res instanceof mysqli_result) { $funds = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, uom_name, abbreviation FROM unit_of_measures WHERE is_active = 1 ORDER BY uom_name ASC");
    if ($res instanceof mysqli_result) { $unitOfMeasures = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
    if ($res instanceof mysqli_result) { $suppliers = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC");
    if ($res instanceof mysqli_result) { $brands = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, model_name, brand_id FROM models WHERE is_active = 1 ORDER BY model_name ASC");
    if ($res instanceof mysqli_result) { $models = $res->fetch_all(MYSQLI_ASSOC); }

    $duplicateId = (int) ($_GET['duplicate_id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $duplicateId > 0) {
        $duplicateStmt = $db->prepare("
            SELECT id, property_number, po_number, item_type, item_description, classification_id, account_code_id,
                   fund_id, supplier_id, brand_id, model_id, acquisition_date, quantity, unit_of_measure_id,
                   unit_cost, office_id, employee_id, responsibility_code_id, condition_status, remarks
            FROM legacy_assets
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        if ($duplicateStmt) {
            $duplicateStmt->bind_param('i', $duplicateId);
            $duplicateStmt->execute();
            $duplicateSource = $duplicateStmt->get_result()->fetch_assoc() ?: null;
            $duplicateStmt->close();

            if ($duplicateSource) {
                foreach ($form as $key => $value) {
                    if (array_key_exists($key, $duplicateSource)) {
                        $form[$key] = (string) ($duplicateSource[$key] ?? '');
                    }
                }
                $form['property_number'] = '';
                $form['serial_no'] = '';
            } else {
                add_validation_error($errors, 'The asset selected for duplication was not found.');
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = trim((string) ($_POST['action'] ?? 'single'));

        if ($postAction === 'bulk_import') {
            $bulkDefaults = [
                'po_number' => trim((string) ($_POST['bulk_po_number'] ?? '')),
                'item_type' => trim((string) ($_POST['bulk_item_type'] ?? 'equipment')),
                'classification_id' => trim((string) ($_POST['bulk_classification_id'] ?? '')),
                'account_code_id' => trim((string) ($_POST['bulk_account_code_id'] ?? '')),
                'fund_id' => trim((string) ($_POST['bulk_fund_id'] ?? '')),
                'supplier_id' => trim((string) ($_POST['bulk_supplier_id'] ?? '')),
                'acquisition_date' => trim((string) ($_POST['bulk_acquisition_date'] ?? '')),
                'unit_cost' => trim((string) ($_POST['bulk_unit_cost'] ?? '')),
                'office_id' => trim((string) ($_POST['bulk_office_id'] ?? '')),
                'employee_id' => trim((string) ($_POST['bulk_employee_id'] ?? '')),
                'responsibility_code_id' => trim((string) ($_POST['bulk_responsibility_code_id'] ?? '')),
                'condition_status' => trim((string) ($_POST['bulk_condition_status'] ?? 'good')),
                'remarks' => trim((string) ($_POST['bulk_remarks'] ?? 'Bulk import from beginning balance PAR/ICS.')),
            ];
            $bulkRows = legacy_asset_parse_bulk_rows((string) ($_POST['bulk_items'] ?? ''));

            if (!csrf_verify()) {
                add_validation_error($errors, 'Invalid CSRF token.');
            }
            if (!$bulkRows) {
                add_validation_error($errors, 'Paste at least one item row to import.');
            }
            if (!is_allowed_value($bulkDefaults['item_type'], ['semi_expendable', 'equipment'])) {
                add_validation_error($errors, 'Inventory type must be semi-expendable or equipment.');
            }
            if ($bulkDefaults['account_code_id'] === '') {
                add_validation_error($errors, 'Account code is required to generate property numbers.');
            }
            if ($bulkDefaults['acquisition_date'] !== '' && !is_valid_date_string($bulkDefaults['acquisition_date'])) {
                add_validation_error($errors, 'Acquisition date format is invalid.');
            }
            if ($bulkDefaults['unit_cost'] !== '' && (!is_numeric($bulkDefaults['unit_cost']) || (float) $bulkDefaults['unit_cost'] < 0)) {
                add_validation_error($errors, 'Unit cost must be a valid amount or left blank if unknown.');
            }
            if (!is_allowed_value($bulkDefaults['condition_status'], ['good', 'serviceable', 'repair_needed', 'unserviceable'])) {
                add_validation_error($errors, 'Condition status is invalid.');
            }

            $editableRowFields = [
                'quantity' => 'bulk_row_quantity',
                'unit_text' => 'bulk_row_unit_text',
                'item_description' => 'bulk_row_item_description',
                'unit_cost' => 'bulk_row_unit_cost',
                'total_cost' => 'bulk_row_total_cost',
                'serial_no' => 'bulk_row_serial_no',
            ];
            foreach ($bulkRows as $idx => $bulkRow) {
                foreach ($editableRowFields as $rowKey => $postKey) {
                    $postedValues = $_POST[$postKey] ?? [];
                    if (!is_array($postedValues) || !array_key_exists($idx, $postedValues)) {
                        continue;
                    }
                    $postedValue = trim((string) $postedValues[$idx]);
                    if (in_array($rowKey, ['unit_cost', 'total_cost'], true)) {
                        $postedValue = legacy_asset_normalize_amount($postedValue);
                    }
                    $bulkRows[$idx][$rowKey] = $postedValue;
                }
                $bulkRows[$idx]['item_description'] = preg_replace('/\s+/', ' ', (string) ($bulkRows[$idx]['item_description'] ?? '')) ?? (string) ($bulkRows[$idx]['item_description'] ?? '');
                if (
                    legacy_asset_should_split_per_unit($bulkDefaults['item_type'], (int) ($bulkRows[$idx]['quantity'] ?? 0))
                    && trim((string) ($bulkRows[$idx]['serial_no'] ?? '')) !== ''
                ) {
                    add_validation_error($errors, 'Bulk row ' . ($idx + 1) . ' has quantity greater than 1 with a serial number. Encode serialized items as separate rows.');
                }
            }

            $bulkOfficeId = $bulkDefaults['office_id'] !== '' ? (int) $bulkDefaults['office_id'] : 0;
            $bulkEmployeeId = $bulkDefaults['employee_id'] !== '' ? (int) $bulkDefaults['employee_id'] : 0;
            $bulkRcId = $bulkDefaults['responsibility_code_id'] !== '' ? (int) $bulkDefaults['responsibility_code_id'] : 0;
            if ($bulkOfficeId <= 0) {
                add_validation_error($errors, 'Office assignment is required for PAR/ICS printing.');
            }
            if ($bulkEmployeeId <= 0) {
                add_validation_error($errors, 'Accountable employee is required for PAR/ICS printing.');
            }

            $bulkOfficeCode = '';
            $officeExists = false;
            foreach ($offices as $officeRow) {
                if ((int) ($officeRow['id'] ?? 0) === $bulkOfficeId) {
                    $officeExists = true;
                    $bulkOfficeCode = trim((string) ($officeRow['office_code'] ?? ''));
                    break;
                }
            }
            if ($bulkOfficeId > 0 && !$officeExists) {
                add_validation_error($errors, 'Selected office is invalid.');
            }

            $employeeOfficeId = 0;
            $employeeExists = false;
            $hasSelectedOfficeAssignment = false;
            foreach ($employees as $employeeRow) {
                if ((int) ($employeeRow['id'] ?? 0) === $bulkEmployeeId) {
                    $employeeExists = true;
                    $employeeOfficeId = (int) ($employeeRow['office_id'] ?? 0);
                    break;
                }
            }
            foreach ($employeeAssignments as $assignmentRow) {
                if ((int) ($assignmentRow['employee_id'] ?? 0) === $bulkEmployeeId && (int) ($assignmentRow['office_id'] ?? 0) === $bulkOfficeId) {
                    $hasSelectedOfficeAssignment = true;
                    break;
                }
            }
            if ($bulkEmployeeId > 0 && !$employeeExists) {
                add_validation_error($errors, 'Selected employee is invalid.');
            } elseif ($bulkOfficeId > 0 && $employeeOfficeId !== $bulkOfficeId && !$hasSelectedOfficeAssignment) {
                add_validation_error($errors, 'Selected employee does not belong to the selected office.');
            }

            if ($bulkRcId > 0) {
                $rcOfficeId = 0;
                foreach ($responsibilityCodes as $rcRow) {
                    if ((int) ($rcRow['id'] ?? 0) === $bulkRcId) {
                        $rcOfficeId = (int) ($rcRow['office_id'] ?? 0);
                        break;
                    }
                }
                if ($rcOfficeId <= 0) {
                    add_validation_error($errors, 'Selected responsibility code is invalid.');
                } elseif ($bulkOfficeId > 0 && $rcOfficeId !== $bulkOfficeId) {
                    add_validation_error($errors, 'Selected responsibility code does not belong to the selected office.');
                }
            }

            $bulkFundCode = '';
            foreach ($funds as $fundRow) {
                if ($bulkDefaults['fund_id'] !== '' && (int) ($fundRow['id'] ?? 0) === (int) $bulkDefaults['fund_id']) {
                    $bulkFundCode = fund_number_from_source((string) ($fundRow['fund_code'] ?? ''), (string) ($fundRow['fund_source'] ?? ''));
                    if ($bulkFundCode === '') {
                        $bulkFundCode = trim((string) ($fundRow['fund_code'] ?? ''));
                    }
                    break;
                }
            }
            if ($bulkDefaults['fund_id'] !== '' && $bulkFundCode === '') {
                add_validation_error($errors, 'Selected fund is invalid.');
            }

            $bulkAccountCode = '';
            $bulkAccountGroup = '';
            foreach ($accountCodes as $accountCodeRow) {
                if ($bulkDefaults['account_code_id'] !== '' && (int) ($accountCodeRow['id'] ?? 0) === (int) $bulkDefaults['account_code_id']) {
                    $bulkAccountCode = trim((string) ($accountCodeRow['account_code'] ?? ''));
                    $bulkAccountGroup = trim((string) ($accountCodeRow['account_group'] ?? ''));
                    break;
                }
            }
            $expectedBulkGroups = $bulkDefaults['item_type'] === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
            if ($bulkDefaults['account_code_id'] !== '' && $bulkAccountCode === '') {
                add_validation_error($errors, 'Selected account code is invalid.');
            } elseif ($bulkAccountCode !== '' && !in_array($bulkAccountGroup, $expectedBulkGroups, true)) {
                add_validation_error($errors, 'Selected account code does not match the inventory type.');
            }

            foreach ($bulkRows as $idx => $bulkRow) {
                if (!ctype_digit((string) $bulkRow['quantity']) || (int) $bulkRow['quantity'] <= 0) {
                    add_validation_error($errors, 'Row ' . ($idx + 1) . ' has an invalid quantity.');
                }
                if (trim((string) ($bulkRow['item_description'] ?? '')) === '') {
                    add_validation_error($errors, 'Row ' . ($idx + 1) . ' description is required.');
                }
                if ((string) ($bulkRow['unit_cost'] ?? '') !== '' && (!is_numeric((string) $bulkRow['unit_cost']) || (float) $bulkRow['unit_cost'] < 0)) {
                    add_validation_error($errors, 'Row ' . ($idx + 1) . ' has an invalid unit cost.');
                }
                if ((string) ($bulkRow['total_cost'] ?? '') !== '' && (!is_numeric((string) $bulkRow['total_cost']) || (float) $bulkRow['total_cost'] < 0)) {
                    add_validation_error($errors, 'Row ' . ($idx + 1) . ' has an invalid total cost.');
                }
            }

            $bulkRowClassificationIds = $_POST['bulk_row_classification_id'] ?? [];
            if (!is_array($bulkRowClassificationIds)) {
                $bulkRowClassificationIds = [];
            }
            foreach ($bulkRows as $idx => $bulkRow) {
                $rowClassificationId = trim((string) ($bulkRowClassificationIds[$idx] ?? ''));
                if ($rowClassificationId === '') {
                    $rowClassificationId = $bulkDefaults['classification_id'];
                }
                if ($rowClassificationId === '') {
                    $bulkRows[$idx]['classification_id'] = '';
                    continue;
                }

                $classificationFound = false;
                $classificationGroup = '';
                $classificationAccountCodeId = 0;
                foreach ($classifications as $classificationRow) {
                    if ((int) ($classificationRow['id'] ?? 0) === (int) $rowClassificationId) {
                        $classificationFound = true;
                        $classificationGroup = trim((string) ($classificationRow['classification_group'] ?? ''));
                        $classificationAccountCodeId = (int) ($classificationRow['account_code_id'] ?? 0);
                        break;
                    }
                }
                if ($classificationFound && $classificationGroup === '' && $classificationAccountCodeId > 0) {
                    foreach ($accountCodes as $accountCodeRow) {
                        if ((int) ($accountCodeRow['id'] ?? 0) === $classificationAccountCodeId) {
                            $classificationGroup = trim((string) ($accountCodeRow['account_group'] ?? ''));
                            break;
                        }
                    }
                }
                if (!$classificationFound || $classificationGroup === '') {
                    add_validation_error($errors, 'Row ' . ($idx + 1) . ' has an invalid classification.');
                } elseif (!in_array($classificationGroup, $expectedBulkGroups, true)) {
                    add_validation_error($errors, 'Row ' . ($idx + 1) . ' classification does not match the inventory type.');
                }
                $bulkRows[$idx]['classification_id'] = $rowClassificationId;
            }

            if (!$errors) {
                $bulkYear = date('Y');
                if ($bulkDefaults['acquisition_date'] !== '') {
                    $timestamp = strtotime($bulkDefaults['acquisition_date']);
                    if ($timestamp !== false) {
                        $bulkYear = date('Y', $timestamp);
                    }
                }
                $hasOfficialNumberInputs = $bulkDefaults['acquisition_date'] !== ''
                    && $bulkDefaults['fund_id'] !== ''
                    && $bulkFundCode !== ''
                    && $bulkAccountCode !== '';
                $accountCodeId = $bulkDefaults['account_code_id'] !== '' ? (int) $bulkDefaults['account_code_id'] : null;
                $fundId = $bulkDefaults['fund_id'] !== '' ? (int) $bulkDefaults['fund_id'] : null;
                $supplierId = $bulkDefaults['supplier_id'] !== '' ? (int) $bulkDefaults['supplier_id'] : null;
                $defaultUnitCost = $bulkDefaults['unit_cost'] !== '' ? (float) $bulkDefaults['unit_cost'] : 0.0;
                $userId = current_user_id();
                if (!$errors) {
                    $db->begin_transaction();
                    $insertedCount = 0;

                    try {
                        foreach ($bulkRows as $bulkRow) {
                            $quantity = (int) $bulkRow['quantity'];
                            $splitRecords = legacy_asset_should_split_per_unit($bulkDefaults['item_type'], $quantity);
                            $propertyNumber = $hasOfficialNumberInputs
                                ? generate_property_number($db, $bulkYear, $bulkFundCode, $bulkAccountCode, $bulkOfficeCode)
                                : legacy_asset_temp_property_number($db, $bulkOfficeCode);
                            $unitOfMeasureId = legacy_asset_unit_id_from_text($unitOfMeasures, (string) $bulkRow['unit_text']);
                            $unitOfMeasureIdValue = $unitOfMeasureId !== '' ? (int) $unitOfMeasureId : null;
                            $rowUnitCost = (string) ($bulkRow['unit_cost'] ?? '') !== '' ? (float) $bulkRow['unit_cost'] : $defaultUnitCost;
                            $rowTotalCost = (string) ($bulkRow['total_cost'] ?? '') !== '' ? (float) $bulkRow['total_cost'] : null;
                            if ($rowUnitCost <= 0.0 && $rowTotalCost !== null && $quantity > 0) {
                                $rowUnitCost = round($rowTotalCost / $quantity, 2);
                            }
                            $acquisitionCost = $rowTotalCost !== null ? round($rowTotalCost, 2) : round($quantity * $rowUnitCost, 2);
                            $description = (string) $bulkRow['item_description'];
                            $serialNo = (string) $bulkRow['serial_no'];
                            $classificationId = ((string) ($bulkRow['classification_id'] ?? '')) !== '' ? (int) $bulkRow['classification_id'] : null;
                            $itemName = legacy_asset_lookup_label($classifications, (string) $classificationId, 'id', 'classification_name');
                            if ($itemName === '') {
                                $itemName = legacy_asset_lookup_label($accountCodes, (string) $accountCodeId, 'id', 'account_name');
                            }

                            $bulkRcIdValue = $bulkRcId > 0 ? $bulkRcId : null;
                            $recordsToCreate = $splitRecords ? $quantity : 1;
                            for ($unitIndex = 0; $unitIndex < $recordsToCreate; $unitIndex++) {
                                $rowPropertyNumber = $unitIndex === 0
                                    ? $propertyNumber
                                    : ($hasOfficialNumberInputs
                                        ? generate_property_number($db, $bulkYear, $bulkFundCode, $bulkAccountCode, $bulkOfficeCode)
                                        : legacy_asset_temp_property_number($db, $bulkOfficeCode));
                                $rowQuantityValue = $splitRecords ? 1 : $quantity;
                                $rowAcquisitionCostValue = $splitRecords
                                    ? round($rowUnitCost * $rowQuantityValue, 2)
                                    : $acquisitionCost;
                                $rowSerialNo = $splitRecords && $unitIndex > 0 ? '' : $serialNo;
                                $systemReference = next_module_code($db, 'stock_items');

                                $legacyAssetId = legacy_asset_insert_record($db, [
                                    'system_reference' => $systemReference,
                                    'po_number' => $bulkDefaults['po_number'],
                                    'property_number' => $rowPropertyNumber,
                                    'item_type' => $bulkDefaults['item_type'],
                                    'item_description' => $description,
                                    'classification_id' => $classificationId,
                                    'account_code_id' => $accountCodeId,
                                    'fund_id' => $fundId,
                                    'supplier_id' => $supplierId,
                                    'brand_id' => null,
                                    'model_id' => null,
                                    'brand' => '',
                                    'model' => '',
                                    'serial_no' => $rowSerialNo,
                                    'acquisition_date' => $bulkDefaults['acquisition_date'],
                                    'quantity' => $rowQuantityValue,
                                    'unit_of_measure_id' => $unitOfMeasureIdValue,
                                    'unit_cost' => $rowUnitCost,
                                    'acquisition_cost' => $rowAcquisitionCostValue,
                                    'office_id' => $bulkOfficeId,
                                    'employee_id' => $bulkEmployeeId,
                                    'responsibility_code_id' => $bulkRcIdValue,
                                    'condition_status' => $bulkDefaults['condition_status'],
                                    'remarks' => $bulkDefaults['remarks'],
                                    'created_by' => $userId,
                                    'item_name' => $itemName,
                                ]);
                                if ($legacyAssetId <= 0) {
                                    throw new RuntimeException('Unable to insert bulk legacy asset row.');
                                }
                                $insertedCount++;

                                write_audit_log($db, [
                                    'action' => 'insert',
                                    'table_name' => 'legacy_assets',
                                    'record_id' => $legacyAssetId,
                                    'module_name' => 'property',
                                    'record_type' => 'legacy_asset',
                                    'action_name' => 'bulk_create_legacy_asset',
                                    'new_values' => [
                                        'system_reference' => $systemReference,
                                        'property_number' => $rowPropertyNumber,
                                        'item_type' => $bulkDefaults['item_type'],
                                        'item_name' => $itemName,
                                        'item_description' => $description,
                                        'fund_id' => $fundId,
                                        'acquisition_date' => $bulkDefaults['acquisition_date'],
                                        'quantity' => $rowQuantityValue,
                                        'unit_of_measure_id' => $unitOfMeasureIdValue,
                                        'unit_cost' => $rowUnitCost,
                                        'acquisition_cost' => $rowAcquisitionCostValue,
                                        'office_id' => $bulkOfficeId,
                                        'employee_id' => $bulkEmployeeId,
                                        'responsibility_code_id' => $bulkRcId,
                                    ],
                                    'description' => 'Bulk imported beginning balance asset.',
                                ]);
                            }
                        }

                        $db->commit();
                        set_flash('success', number_format($insertedCount) . ' beginning balance assets imported successfully.');
                        redirect('modules/property/legacy_assets.php');
                    } catch (Throwable $e) {
                        $db->rollback();
                        error_log('Bulk legacy asset import failed: ' . $e->getMessage());
                        $errors[] = 'Bulk import failed. No rows were saved.';
                    }
                }
            }
        } else {
        foreach ($form as $key => $value) {
            $form[$key] = trim((string) ($_POST[$key] ?? ''));
        }

        if (!csrf_verify()) {
            add_validation_error($errors, 'Invalid CSRF token.');
        }
        if ($form['item_description'] === '') { add_validation_error($errors, 'Description is required.'); }
        if (!is_allowed_value($form['item_type'], ['semi_expendable', 'equipment'])) { add_validation_error($errors, 'Inventory type must be semi-expendable or equipment.'); }
        if ($form['quantity'] === '' || !ctype_digit($form['quantity']) || (int) $form['quantity'] <= 0) { add_validation_error($errors, 'Quantity is required.'); }
        if ($form['unit_of_measure_id'] !== '') {
            $unitExists = false;
            foreach ($unitOfMeasures as $unitRow) {
                if ((int) ($unitRow['id'] ?? 0) === (int) $form['unit_of_measure_id']) {
                    $unitExists = true;
                    break;
                }
            }
            if (!$unitExists) {
                add_validation_error($errors, 'Selected unit type is invalid.');
            }
        }
        if ($form['unit_cost'] !== '' && (!is_numeric($form['unit_cost']) || (float) $form['unit_cost'] < 0)) {
            add_validation_error($errors, 'Unit cost must be a valid amount or left blank if unknown.');
        }
        if ($form['acquisition_date'] !== '' && !is_valid_date_string($form['acquisition_date'])) {
            add_validation_error($errors, 'Acquisition date format is invalid.');
        }
        if ($form['account_code_id'] === '') { add_validation_error($errors, 'Account code is required to generate the property number.'); }
        if (!is_allowed_value($form['condition_status'], ['good', 'serviceable', 'repair_needed', 'unserviceable'])) {
            add_validation_error($errors, 'Condition status is invalid.');
        }

        $fundCodeValue = '';
        foreach ($funds as $fundRow) {
            if ($form['fund_id'] !== '' && (int) $fundRow['id'] === (int) $form['fund_id']) {
                $fundCodeValue = fund_number_from_source((string) ($fundRow['fund_code'] ?? ''), (string) ($fundRow['fund_source'] ?? ''));
                if ($fundCodeValue === '') {
                    $fundCodeValue = trim((string) ($fundRow['fund_code'] ?? ''));
                }
                break;
            }
        }
        if ($form['fund_id'] !== '' && $fundCodeValue === '') {
            add_validation_error($errors, 'Selected fund is invalid.');
        }

        $accountCodeValue = '';
        $expectedAccountGroups = $form['item_type'] === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
        $accountCodeGroup = '';
        foreach ($accountCodes as $accountCodeRow) {
            if ($form['account_code_id'] !== '' && (int) $accountCodeRow['id'] === (int) $form['account_code_id']) {
                $accountCodeValue = trim((string) ($accountCodeRow['account_code'] ?? ''));
                $accountCodeGroup = trim((string) ($accountCodeRow['account_group'] ?? ''));
                break;
            }
        }
        if ($form['account_code_id'] !== '' && $accountCodeValue === '') {
            add_validation_error($errors, 'Selected account code is invalid.');
        }
        if ($form['account_code_id'] !== '' && $accountCodeValue !== '' && !in_array($accountCodeGroup, $expectedAccountGroups, true)) {
            add_validation_error($errors, 'Selected account code does not match the inventory type.');
        }
        if ($form['classification_id'] !== '') {
            $classificationGroup = '';
            $classificationAccountCodeId = 0;
            foreach ($classifications as $classificationRow) {
                if ((int) ($classificationRow['id'] ?? 0) === (int) $form['classification_id']) {
                    $classificationGroup = trim((string) ($classificationRow['classification_group'] ?? ''));
                    $classificationAccountCodeId = (int) ($classificationRow['account_code_id'] ?? 0);
                    break;
                }
            }
            if ($classificationGroup === '' && $classificationAccountCodeId > 0) {
                foreach ($accountCodes as $accountCodeRow) {
                    if ((int) ($accountCodeRow['id'] ?? 0) === $classificationAccountCodeId) {
                        $classificationGroup = trim((string) ($accountCodeRow['account_group'] ?? ''));
                        break;
                    }
                }
            }
            $expectedClassificationGroups = $form['item_type'] === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
            if ($classificationGroup === '') {
                add_validation_error($errors, 'Selected item classification is invalid.');
            } elseif (!in_array($classificationGroup, $expectedClassificationGroups, true)) {
                add_validation_error($errors, 'Selected item classification does not match the inventory type.');
            }
        }

        $officeIdValue = $form['office_id'] !== '' ? (int) $form['office_id'] : 0;
        $employeeIdValue = $form['employee_id'] !== '' ? (int) $form['employee_id'] : 0;
        $responsibilityCodeIdValue = $form['responsibility_code_id'] !== '' ? (int) $form['responsibility_code_id'] : 0;

        if ($officeIdValue <= 0) {
            add_validation_error($errors, 'Office assignment is required for PAR/ICS printing.');
        }
        if ($employeeIdValue <= 0) {
            add_validation_error($errors, 'Accountable employee is required for PAR/ICS printing.');
        }

        if ($officeIdValue > 0) {
            $officeExists = false;
            foreach ($offices as $officeRow) {
                if ((int) ($officeRow['id'] ?? 0) === $officeIdValue) {
                    $officeExists = true;
                    break;
                }
            }
            if (!$officeExists) {
                add_validation_error($errors, 'Selected office is invalid.');
            }
        }

        if ($employeeIdValue > 0) {
            $employeeOfficeId = 0;
            $employeeExists = false;
            $hasSelectedOfficeAssignment = false;
            foreach ($employees as $employeeRow) {
                if ((int) ($employeeRow['id'] ?? 0) === $employeeIdValue) {
                    $employeeExists = true;
                    $employeeOfficeId = (int) ($employeeRow['office_id'] ?? 0);
                    break;
                }
            }
            foreach ($employeeAssignments as $assignmentRow) {
                if ((int) ($assignmentRow['employee_id'] ?? 0) === $employeeIdValue && (int) ($assignmentRow['office_id'] ?? 0) === $officeIdValue) {
                    $hasSelectedOfficeAssignment = true;
                    break;
                }
            }
            if (!$employeeExists) {
                add_validation_error($errors, 'Selected employee is invalid.');
            } elseif ($officeIdValue > 0 && $employeeOfficeId !== $officeIdValue && !$hasSelectedOfficeAssignment) {
                add_validation_error($errors, 'Selected employee does not belong to the selected office.');
            }
        }

        if ($responsibilityCodeIdValue > 0) {
            $rcOfficeId = 0;
            foreach ($responsibilityCodes as $rcRow) {
                if ((int) ($rcRow['id'] ?? 0) === $responsibilityCodeIdValue) {
                    $rcOfficeId = (int) ($rcRow['office_id'] ?? 0);
                    break;
                }
            }
            if ($rcOfficeId <= 0) {
                add_validation_error($errors, 'Selected responsibility code is invalid.');
            } elseif ($officeIdValue > 0 && $rcOfficeId !== $officeIdValue) {
                add_validation_error($errors, 'Selected responsibility code does not belong to the selected office.');
            }
        }

        $officeCodeValue = '';
        foreach ($offices as $officeRow) {
            if ($form['office_id'] !== '' && (int) $officeRow['id'] === (int) $form['office_id']) {
                $officeCodeValue = trim((string) ($officeRow['office_code'] ?? ''));
                break;
            }
        }

        $yearValue = date('Y');
        if ($form['acquisition_date'] !== '') {
            $timestamp = strtotime($form['acquisition_date']);
            if ($timestamp !== false) {
                $yearValue = date('Y', $timestamp);
            }
        }

        $hasOfficialNumberInputs = false;
        if (!$errors) {
            $hasOfficialNumberInputs = $form['acquisition_date'] !== ''
                && $form['fund_id'] !== ''
                && $fundCodeValue !== ''
                && $accountCodeValue !== '';

            $form['property_number'] = $hasOfficialNumberInputs
                ? generate_property_number($db, $yearValue, $fundCodeValue, $accountCodeValue, $officeCodeValue)
                : legacy_asset_temp_property_number($db, $officeCodeValue);
        }

        if (!$errors) {
            $propertyConflict = asset_identifier_conflict($db, 'property_number', $form['property_number']);
            if ($propertyConflict) {
                add_validation_error($errors, 'Property number already exists in ' . $propertyConflict['label'] . ' #' . $propertyConflict['id'] . '.');
            }
        }

        if (!$errors && $form['serial_no'] !== '') {
            $serialConflict = asset_identifier_conflict($db, 'serial_no', $form['serial_no'], '', 0, true);
            if ($serialConflict) {
                add_validation_error($errors, 'Serial number already exists in ' . $serialConflict['label'] . ' #' . $serialConflict['id'] . '.');
            }
        }

        if (
            !$errors
            && legacy_asset_should_split_per_unit($form['item_type'], (int) $form['quantity'])
            && trim((string) $form['serial_no']) !== ''
        ) {
            add_validation_error($errors, 'Items with quantity greater than 1 and a serial number must be encoded as separate rows.');
        }

        if (!$errors) {
            $userId = current_user_id();
            $classificationId = $form['classification_id'] !== '' ? (int) $form['classification_id'] : null;
            $accountCodeId = $form['account_code_id'] !== '' ? (int) $form['account_code_id'] : null;
            $fundId = $form['fund_id'] !== '' ? (int) $form['fund_id'] : null;
            $supplierId = $form['supplier_id'] !== '' ? (int) $form['supplier_id'] : null;
            $brandId = $form['brand_id'] !== '' ? (int) $form['brand_id'] : null;
            $modelId = $form['model_id'] !== '' ? (int) $form['model_id'] : null;
            $officeId = $form['office_id'] !== '' ? (int) $form['office_id'] : null;
            $employeeId = $form['employee_id'] !== '' ? (int) $form['employee_id'] : null;
            $rcId = $form['responsibility_code_id'] !== '' ? (int) $form['responsibility_code_id'] : null;
            $quantity = (int) $form['quantity'];
            $splitRecords = legacy_asset_should_split_per_unit($form['item_type'], $quantity);
            $unitOfMeasureId = $form['unit_of_measure_id'] !== '' ? (int) $form['unit_of_measure_id'] : null;
            $unitCost = $form['unit_cost'] !== '' ? (float) $form['unit_cost'] : 0.0;
            $acquisitionCost = round($quantity * $unitCost, 2);
            $itemName = '';
            $brandName = '';
            $modelName = '';
            foreach ($classifications as $classificationRow) {
                if ((int) ($classificationRow['id'] ?? 0) === (int) $classificationId) {
                    $itemName = trim((string) ($classificationRow['classification_name'] ?? ''));
                    break;
                }
            }
            if ($itemName === '') {
                foreach ($accountCodes as $accountCodeRow) {
                    if ((int) ($accountCodeRow['id'] ?? 0) === (int) $accountCodeId) {
                        $itemName = trim((string) ($accountCodeRow['account_name'] ?? ''));
                        break;
                    }
                }
            }
            foreach ($brands as $brandRow) {
                if ((int) $brandRow['id'] === (int) $brandId) { $brandName = (string) $brandRow['brand_name']; break; }
            }
            foreach ($models as $modelRow) {
                if ((int) $modelRow['id'] === (int) $modelId) { $modelName = (string) $modelRow['model_name']; break; }
            }

            $db->begin_transaction();
            try {
                $createdCount = 0;
                $recordsToCreate = $splitRecords ? $quantity : 1;
                for ($unitIndex = 0; $unitIndex < $recordsToCreate; $unitIndex++) {
                    $rowPropertyNumber = $unitIndex === 0
                        ? $form['property_number']
                        : ($hasOfficialNumberInputs
                            ? generate_property_number($db, $yearValue, $fundCodeValue, $accountCodeValue, $officeCodeValue)
                            : legacy_asset_temp_property_number($db, $officeCodeValue));
                    $rowQuantity = $splitRecords ? 1 : $quantity;
                    $rowAcquisitionCost = $splitRecords ? round($unitCost * $rowQuantity, 2) : $acquisitionCost;
                    $rowSerialNo = $splitRecords && $unitIndex > 0 ? '' : $form['serial_no'];
                    $systemReference = next_module_code($db, 'stock_items');

                    $legacyAssetId = legacy_asset_insert_record($db, [
                        'system_reference' => $systemReference,
                        'po_number' => $form['po_number'],
                        'property_number' => $rowPropertyNumber,
                        'item_type' => $form['item_type'],
                        'item_description' => $form['item_description'],
                        'classification_id' => $classificationId,
                        'account_code_id' => $accountCodeId,
                        'fund_id' => $fundId,
                        'supplier_id' => $supplierId,
                        'brand_id' => $brandId,
                        'model_id' => $modelId,
                        'brand' => $brandName,
                        'model' => $modelName,
                        'serial_no' => $rowSerialNo,
                        'acquisition_date' => $form['acquisition_date'],
                        'quantity' => $rowQuantity,
                        'unit_of_measure_id' => $unitOfMeasureId,
                        'unit_cost' => $unitCost,
                        'acquisition_cost' => $rowAcquisitionCost,
                        'office_id' => $officeId,
                        'employee_id' => $employeeId,
                        'responsibility_code_id' => $rcId,
                        'condition_status' => $form['condition_status'],
                        'remarks' => $form['remarks'],
                        'created_by' => $userId,
                        'item_name' => $itemName,
                    ]);
                    if ($legacyAssetId <= 0) {
                        throw new RuntimeException('Unable to save the beginning balance asset.');
                    }
                    $createdCount++;

                    write_audit_log($db, [
                        'action' => 'insert',
                        'table_name' => 'legacy_assets',
                        'record_id' => $legacyAssetId,
                        'module_name' => 'property',
                        'record_type' => 'legacy_asset',
                        'action_name' => 'create_legacy_asset',
                        'new_values' => [
                            'system_reference' => $systemReference,
                            'property_number' => $rowPropertyNumber,
                            'item_type' => $form['item_type'],
                            'item_name' => $itemName,
                            'item_description' => $form['item_description'],
                            'fund_id' => $fundId,
                            'acquisition_date' => $form['acquisition_date'],
                            'quantity' => $rowQuantity,
                            'unit_of_measure_id' => $unitOfMeasureId,
                            'unit_cost' => $unitCost,
                            'acquisition_cost' => $rowAcquisitionCost,
                            'office_id' => $officeId,
                            'employee_id' => $employeeId,
                            'responsibility_code_id' => $rcId,
                        ],
                        'description' => 'Created beginning balance asset.',
                    ]);
                }

                $db->commit();
                set_flash('success', $createdCount > 1
                    ? number_format($createdCount) . ' beginning balance assets recorded successfully.'
                    : 'Beginning balance asset recorded successfully.');
                redirect('modules/property/legacy_assets.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to save the beginning balance asset.';
            }
        }
        }
    }
}

$page_title = 'Encode Beginning Balance Asset';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="page-section">
<div class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header border-0 pb-0 bg-transparent">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <div class="text-uppercase small text-muted fw-semibold">Beginning Balance Encoding</div>
                        <h4 class="mb-1">Encode Legacy Asset</h4>
                        <div class="small text-muted">Record an existing asset already owned by the university. One physical unit corresponds to one property number. If quantity is greater than 1, the system creates separate records and separate property numbers per unit. If date or fund is still unknown, the system saves a temporary property number first.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="#bulkImportPanel" class="btn btn-success">
                            <i class="bi bi-upload me-1"></i>Bulk Import
                        </a>
                        <a href="<?php echo h(base_url('modules/property/legacy_assets.php')); ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Back to List
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($duplicateSource): ?>
                    <div class="alert alert-info">
                        Duplicating <?php echo h((string) ($duplicateSource['property_number'] ?? 'selected beginning balance asset')); ?>. Enter the new serial number, then save to generate a new property number.
                    </div>
                <?php endif; ?>

                <form method="post" class="workspace-form-section">
                    <input type="hidden" name="_csrf" id="legacy_asset_csrf_token" value="<?php echo h($csrfToken); ?>">
                    <div class="row g-3">

                        <!-- ── Asset Details ── -->
                        <div class="col-12">
                            <div class="small text-uppercase text-muted fw-semibold">Asset Details</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Property Number</label>
                            <input type="text" class="form-control" name="property_number" value="<?php echo h($form['property_number']); ?>" readonly>
                            <div class="form-text">Official number is generated when date and fund are complete. Otherwise a TEMP number is assigned.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Inventory Type</label>
                            <select name="item_type" class="form-select" id="item_type">
                                <option value="equipment" <?php echo $form['item_type'] === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                <option value="semi_expendable" <?php echo $form['item_type'] === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">PO Number</label>
                            <input type="text" class="form-control" name="po_number" value="<?php echo h($form['po_number']); ?>" placeholder="Enter PO number if available">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Acquisition Date</label>
                            <input type="date" class="form-control" name="acquisition_date" value="<?php echo h($form['acquisition_date']); ?>">
                            <div class="form-text">Leave blank if still for verification.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity</label>
                            <input type="number" min="1" step="1" class="form-control" name="quantity" value="<?php echo h($form['quantity']); ?>" required>
                            <div class="form-text">Rule: 1 unit quantity = 1 property number. Quantity above 1 creates separate records with separate property numbers.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit of Measure</label>
                            <select name="unit_of_measure_id" class="form-select">
                                <option value="">Select unit</option>
                                <?php foreach ($unitOfMeasures as $unit): ?>
                                    <?php
                                    $unitId = (int) ($unit['id'] ?? 0);
                                    $unitLabel = trim(($unit['uom_name'] ?? '') . (($unit['abbreviation'] ?? '') !== '' ? ' (' . $unit['abbreviation'] . ')' : ''));
                                    ?>
                                    <option value="<?php echo $unitId; ?>" <?php echo $form['unit_of_measure_id'] === (string) $unitId ? 'selected' : ''; ?>>
                                        <?php echo h($unitLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit Cost</label>
                            <input type="number" min="0" step="0.01" class="form-control" name="unit_cost" value="<?php echo h($form['unit_cost']); ?>" placeholder="Leave blank if unknown">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Fund</label>
                            <select name="fund_id" class="form-select">
                                <option value="">Select fund</option>
                                <?php foreach ($funds as $fund): ?>
                                    <option value="<?php echo (int) $fund['id']; ?>" <?php echo $form['fund_id'] === (string) $fund['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(($fund['fund_code'] ?? '') . ' - ' . ($fund['fund_name'] ?? '') . (($fund['fund_source'] ?? '') !== '' ? ' - ' . ($fund['fund_source'] ?? '') : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Leave blank if still for verification.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>><?php echo h($supplier['supplier_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Equipment Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="item_description" rows="3" required><?php echo h($form['item_description']); ?></textarea>
                        </div>

                        <!-- ── Classification and Catalog ── -->
                        <div class="col-12 mt-2">
                            <div class="small text-uppercase text-muted fw-semibold">Classification and Catalog</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Item Classification</label>
                            <div class="input-group qa-field-group">
                                <select name="classification_id" class="form-select" id="classification_id">
                                    <option value="">Select classification</option>
                                    <?php foreach ($classifications as $classification): ?>
                                        <option value="<?php echo (int) $classification['id']; ?>" <?php echo $form['classification_id'] === (string) $classification['id'] ? 'selected' : ''; ?> data-account-code-id="<?php echo (int) ($classification['account_code_id'] ?? 0); ?>" data-classification-group="<?php echo h((string) ($classification['classification_group'] ?? '')); ?>">
                                            <?php echo h(trim(($classification['classification_family'] ?? '') . ' / ' . ($classification['classification_name'] ?? ''))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new classification" aria-label="Add new classification" data-qa-modal="qaClassificationModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                            <div class="form-text">Select the actual item classification, such as Airconditioner, Chair, or Printer.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account Code <span class="text-danger">*</span></label>
                            <div class="input-group qa-field-group">
                                <select name="account_code_id" class="form-select" id="account_code_id">
                                    <option value="">Select account code</option>
                                    <?php foreach ($accountCodes as $accountCode): ?>
                                        <option value="<?php echo (int) $accountCode['id']; ?>" <?php echo $form['account_code_id'] === (string) $accountCode['id'] ? 'selected' : ''; ?> data-account-group="<?php echo h((string) ($accountCode['account_group'] ?? '')); ?>">
                                            <?php echo h(($accountCode['account_code'] ?? '') . ' - ' . ($accountCode['account_name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new account code" aria-label="Add new account code" data-qa-modal="qaAccountCodeModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Brand</label>
                            <div class="input-group qa-field-group">
                                <select name="brand_id" class="form-select" id="brand_id" data-placeholder="Select brand">
                                    <option value="">Select brand</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?php echo (int) $brand['id']; ?>" <?php echo $form['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>><?php echo h($brand['brand_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new brand" aria-label="Add new brand" data-qa-modal="qaBrandModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Model</label>
                            <div class="input-group qa-field-group">
                                <select name="model_id" class="form-select" id="model_id" data-placeholder="Select model">
                                    <option value="">Select model</option>
                                    <?php foreach ($models as $model): ?>
                                        <option value="<?php echo (int) $model['id']; ?>" <?php echo $form['model_id'] === (string) $model['id'] ? 'selected' : ''; ?> data-brand-id="<?php echo (int) ($model['brand_id'] ?? 0); ?>"><?php echo h($model['model_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new model" aria-label="Add new model" data-qa-modal="qaModelModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Serial No.</label>
                            <input type="text" class="form-control" name="serial_no" value="<?php echo h($form['serial_no']); ?>">
                        </div>

                        <!-- ── Assignment ── -->
                        <div class="col-12 mt-2">
                            <div class="small text-uppercase text-muted fw-semibold">Assignment</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Office <span class="text-danger">*</span></label>
                            <div class="input-group qa-field-group">
                                <select name="office_id" class="form-select" id="office_id" data-placeholder="Select office" required>
                                    <option value="">Select office</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new office" aria-label="Add new office" data-qa-modal="qaOfficeModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Accountable Employee <span class="text-danger">*</span></label>
                            <div class="input-group qa-field-group">
                                <select name="employee_id" class="form-select" id="employee_id" data-placeholder="Select employee" required>
                                    <option value="">Select employee</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?php echo (int) $employee['id']; ?>" <?php echo $form['employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?> data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" data-responsibility-code-id="<?php echo (int) ($employee['responsibility_code_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>">
                                            <?php echo h(employee_display_name($employee)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new employee" aria-label="Add new employee" data-qa-modal="qaEmployeeModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Responsibility Code</label>
                            <select name="responsibility_code_id" class="form-select" data-placeholder="Select RC">
                                <option value="">Select RC</option>
                                <?php foreach ($responsibilityCodes as $rc): ?>
                                    <option value="<?php echo (int) $rc['id']; ?>" <?php echo $form['responsibility_code_id'] === (string) $rc['id'] ? 'selected' : ''; ?> data-office-id="<?php echo (int) ($rc['office_id'] ?? 0); ?>">
                                        <?php echo h(($rc['code'] ?? '') . ' - ' . ($rc['description'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Condition</label>
                            <select name="condition_status" class="form-select">
                                <?php foreach (['good' => 'Good', 'serviceable' => 'Serviceable', 'repair_needed' => 'Needs Repair', 'unserviceable' => 'Unserviceable'] as $value => $label): ?>
                                    <option value="<?php echo h($value); ?>" <?php echo $form['condition_status'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Remarks</label>
                            <input type="text" class="form-control" name="remarks" value="<?php echo h($form['remarks']); ?>">
                        </div>

                        <div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2 border-top mt-2">
                            <a href="<?php echo h(base_url('modules/property/legacy_assets.php')); ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-floppy me-1"></i>Save Encoded Asset
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12" id="bulkImportPanel">
        <div class="card">
            <div class="card-header border-0 pb-0 bg-transparent">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <div class="text-uppercase small text-muted fw-semibold">Bulk Beginning Balance</div>
                        <h4 class="mb-1">Import PAR / ICS Items</h4>
                        <div class="small text-muted">Paste typed or OCR rows from a hard-copy PAR/ICS, set the shared assignment once, then save all rows together.</div>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <form method="post" class="workspace-form-section" id="legacyBulkImportForm">
                    <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="action" value="bulk_import">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Item Rows <span class="text-danger">*</span></label>
                            <textarea class="form-control font-monospace" name="bulk_items" id="bulk_items" rows="10" required placeholder="11 pcs Fingerprint Brushes&#10;6 pcs Fingerprint Rollers&#10;1 unit I.D. Laminator / Laminating Machine&#10;SN-002062"></textarea>
                            <div class="form-text">Accepted format: quantity, unit, optional unit cost, optional total cost, description. Rule: 1 unit quantity = 1 property number, so quantity above 1 is split into separate records. A serial number line starting with SN is attached to the previous item.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Inventory Type</label>
                            <select name="bulk_item_type" class="form-select" id="bulk_item_type">
                                <option value="equipment">Equipment</option>
                                <option value="semi_expendable">Semi-Expendable</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">PO Number</label>
                            <input type="text" class="form-control" name="bulk_po_number" placeholder="Enter PO number if available">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Acquisition Date</label>
                            <input type="date" class="form-control" name="bulk_acquisition_date">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Account Code <span class="text-danger">*</span></label>
                            <select name="bulk_account_code_id" class="form-select" id="bulk_account_code_id" data-placeholder="Select account code" required>
                                <option value="">Select account code</option>
                                <?php foreach ($accountCodes as $accountCode): ?>
                                    <option value="<?php echo (int) $accountCode['id']; ?>" data-account-group="<?php echo h((string) ($accountCode['account_group'] ?? '')); ?>">
                                        <?php echo h(($accountCode['account_code'] ?? '') . ' - ' . ($accountCode['account_name'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Default Item Classification</label>
                            <select name="bulk_classification_id" class="form-select" id="bulk_classification_id" data-placeholder="Select classification">
                                <option value="">Select classification</option>
                                <?php foreach ($classifications as $classification): ?>
                                    <option value="<?php echo (int) $classification['id']; ?>" data-account-code-id="<?php echo (int) ($classification['account_code_id'] ?? 0); ?>" data-classification-group="<?php echo h((string) ($classification['classification_group'] ?? '')); ?>">
                                        <?php echo h(trim(($classification['classification_family'] ?? '') . ' / ' . ($classification['classification_name'] ?? ''))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Used only for rows where you do not choose a specific classification in the preview.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Fund</label>
                            <select name="bulk_fund_id" class="form-select" id="bulk_fund_id" data-placeholder="Select fund">
                                <option value="">Select fund</option>
                                <?php foreach ($funds as $fund): ?>
                                    <option value="<?php echo (int) $fund['id']; ?>">
                                        <?php echo h(($fund['fund_code'] ?? '') . ' - ' . ($fund['fund_name'] ?? '') . (($fund['fund_source'] ?? '') !== '' ? ' - ' . ($fund['fund_source'] ?? '') : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Supplier</label>
                            <select name="bulk_supplier_id" class="form-select" id="bulk_supplier_id" data-placeholder="Select supplier">
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo (int) $supplier['id']; ?>"><?php echo h($supplier['supplier_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit Cost</label>
                            <input type="number" min="0" step="0.01" class="form-control" name="bulk_unit_cost" placeholder="Leave blank if unknown">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Office <span class="text-danger">*</span></label>
                            <select name="bulk_office_id" class="form-select" id="bulk_office_id" data-placeholder="Select office" required>
                                <option value="">Select office</option>
                                <?php foreach ($offices as $office): ?>
                                    <option value="<?php echo (int) $office['id']; ?>"><?php echo h($office['office_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Choose CCJE-Laboratory for this batch.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Accountable Employee <span class="text-danger">*</span></label>
                            <select name="bulk_employee_id" class="form-select" id="bulk_employee_id" data-placeholder="Select employee" required>
                                <option value="">Select employee</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo (int) $employee['id']; ?>" data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" data-responsibility-code-id="<?php echo (int) ($employee['responsibility_code_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>">
                                        <?php echo h(employee_display_name($employee)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Responsibility Code</label>
                            <select name="bulk_responsibility_code_id" class="form-select" id="bulk_responsibility_code_id" data-placeholder="Select RC">
                                <option value="">Select RC</option>
                                <?php foreach ($responsibilityCodes as $rc): ?>
                                    <option value="<?php echo (int) $rc['id']; ?>" data-office-id="<?php echo (int) ($rc['office_id'] ?? 0); ?>">
                                        <?php echo h(($rc['code'] ?? '') . ' - ' . ($rc['description'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Condition</label>
                            <select name="bulk_condition_status" class="form-select">
                                <?php foreach (['good' => 'Good', 'serviceable' => 'Serviceable', 'repair_needed' => 'Needs Repair', 'unserviceable' => 'Unserviceable'] as $value => $label): ?>
                                    <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Remarks</label>
                            <input type="text" class="form-control" name="bulk_remarks" value="Bulk import from beginning balance PAR/ICS.">
                        </div>

                        <div class="col-12">
                            <div class="table-responsive border rounded">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 90px;">Qty</th>
                                            <th style="width: 110px;">Unit</th>
                                            <th style="width: 130px;">Unit Cost</th>
                                            <th style="width: 130px;">Total Cost</th>
                                            <th>Description</th>
                                            <th style="min-width: 300px;">
                                                <div class="d-flex align-items-center justify-content-between gap-2">
                                                    <span>Classification</span>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Add new classification" aria-label="Add new classification" data-qa-modal="qaClassificationModal" data-qa-context="bulk">
                                                        <i class="bi bi-plus-lg"></i>
                                                    </button>
                                                </div>
                                            </th>
                                            <th style="width: 160px;">Serial No.</th>
                                        </tr>
                                    </thead>
                                    <tbody id="bulkImportPreview">
                                        <tr><td colspan="7" class="text-muted text-center py-3">Paste rows above to preview the import.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2 border-top mt-2">
                            <button type="submit" class="btn btn-success px-4" id="bulkImportSubmit">
                                <i class="bi bi-upload me-1"></i>Import Beginning Balance Items
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</section>

<script>
(function() {
    function select2Ready() {
        return !!(window.jQuery && jQuery.fn && jQuery.fn.select2);
    }

    function initPageSelect2(select) {
        if (!select || select.hasAttribute('data-no-select2') || !select2Ready()) {
            return;
        }
        var $select = jQuery(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
        }
        var allowClear = Array.from(select.options || []).some(function (option) {
            return option.value === '';
        });
        $select.select2({
            width: '100%',
            placeholder: select.getAttribute('data-placeholder') || 'Select an option',
            allowClear: allowClear,
            minimumResultsForSearch: 0,
            dropdownParent: jQuery(document.body)
        });
        select.setAttribute('data-select2-initialized', 'true');
    }

    function refreshEnhancedSelect(select) {
        if (!select) { return; }
        if (!select2Ready()) {
            window.setTimeout(function () { refreshEnhancedSelect(select); }, 150);
            return;
        }
        initPageSelect2(select);
    }

    var modelOptions = <?php
        $modelDataset = array_map(static function ($model) {
            return [
                'value' => (string) ($model['id'] ?? ''),
                'text' => (string) ($model['model_name'] ?? ''),
                'brandId' => (string) ($model['brand_id'] ?? ''),
            ];
        }, $models);
        echo json_encode($modelDataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    var accountCodeOptions = window.legacyAssetAccountCodeOptions = <?php
        $accountCodeDataset = array_map(static function ($accountCode) {
            return [
                'value' => (string) ($accountCode['id'] ?? ''),
                'text' => trim((string) ($accountCode['account_code'] ?? '') . ' - ' . (string) ($accountCode['account_name'] ?? '')),
                'accountGroup' => (string) ($accountCode['account_group'] ?? ''),
            ];
        }, $accountCodes);
        echo json_encode($accountCodeDataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    var classificationOptions = window.legacyAssetClassificationOptions = <?php
        $classificationDataset = array_map(static function ($classification) {
            return [
                'value' => (string) ($classification['id'] ?? ''),
                'text' => trim((string) ($classification['classification_family'] ?? '') . ' / ' . (string) ($classification['classification_name'] ?? '')),
                'accountCodeId' => (string) ($classification['account_code_id'] ?? ''),
                'classificationGroup' => (string) ($classification['classification_group'] ?? ''),
            ];
        }, $classifications);
        echo json_encode($classificationDataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    var employeeAssignmentOptions = <?php
        $employeeAssignmentDataset = array_values(array_filter(array_map(static function ($assignment) {
            $employeeId = (int) ($assignment['employee_id'] ?? 0);
            $officeId = (int) ($assignment['office_id'] ?? 0);
            if ($employeeId <= 0 || $officeId <= 0) {
                return null;
            }
            return [
                'employeeId' => (string) $employeeId,
                'officeId' => (string) $officeId,
                'responsibilityCodeId' => (string) ((int) ($assignment['responsibility_code_id'] ?? 0)),
                'isUnitHead' => (int) ($assignment['is_unit_head'] ?? 0),
                'isPrimary' => (int) ($assignment['is_primary'] ?? 0),
            ];
        }, $employeeAssignments)));
        echo json_encode($employeeAssignmentDataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;

    function expectedAssetGroups(itemType) {
        return itemType === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
    }

    function classificationGroupMatches(optionData, expectedGroups) {
        if (expectedGroups.indexOf(optionData.classificationGroup) !== -1) {
            return true;
        }
        if (!optionData.classificationGroup && optionData.accountCodeId) {
            var accountCode = accountCodeOptions.find(function(accountData) {
                return accountData.value === optionData.accountCodeId;
            });
            return !!accountCode && expectedGroups.indexOf(accountCode.accountGroup) !== -1;
        }
        return false;
    }

    function filterClassificationsForType() {
        var itemTypeSelect = document.getElementById('item_type');
        var classificationSelect = document.getElementById('classification_id');
        if (!itemTypeSelect || !classificationSelect) { return; }
        var expectedGroups = expectedAssetGroups(itemTypeSelect.value);
        var previousValue = classificationSelect.value || '';
        classificationSelect.innerHTML = '';
        classificationSelect.add(new Option('Select classification', '', false, false));
        classificationOptions.forEach(function(optionData) {
            if (!classificationGroupMatches(optionData, expectedGroups)) { return; }
            var option = new Option(optionData.text, optionData.value, false, optionData.value === previousValue);
            option.setAttribute('data-account-code-id', optionData.accountCodeId || '0');
            option.setAttribute('data-classification-group', optionData.classificationGroup || '');
            classificationSelect.add(option);
        });
        if (previousValue !== '' && !Array.from(classificationSelect.options).some(function(option) { return option.value === previousValue; })) {
            classificationSelect.value = '';
        }
        refreshEnhancedSelect(classificationSelect);
    }
    window.legacyAssetFilterClassificationsForType = filterClassificationsForType;

    function setupAccountCodeTypeFilter() {
        var itemTypeSelect = document.getElementById('item_type');
        var accountCodeSelect = document.getElementById('account_code_id');
        if (!itemTypeSelect || !accountCodeSelect) { return; }
        var initialValue = accountCodeSelect.value || '';

        function filterAccountCodes() {
            var expectedGroups = itemTypeSelect.value === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
            var previousValue = accountCodeSelect.value || initialValue;
            accountCodeSelect.innerHTML = '';
            accountCodeSelect.add(new Option('Select account code', '', false, false));
            accountCodeOptions.forEach(function(optionData) {
                if (expectedGroups.indexOf(optionData.accountGroup) === -1) { return; }
                var option = new Option(optionData.text, optionData.value, false, optionData.value === previousValue);
                option.setAttribute('data-account-group', optionData.accountGroup);
                accountCodeSelect.add(option);
            });
            if (previousValue !== '' && !Array.from(accountCodeSelect.options).some(function(option) { return option.value === previousValue; })) {
                accountCodeSelect.value = '';
            }
            initialValue = '';
            refreshEnhancedSelect(accountCodeSelect);
            filterClassificationsForType();
        }

        filterAccountCodes();
        window.setTimeout(filterAccountCodes, 0);
        window.setTimeout(filterAccountCodes, 250);
        if (itemTypeSelect.getAttribute('data-account-filter-wired') !== '1') {
            itemTypeSelect.setAttribute('data-account-filter-wired', '1');
            itemTypeSelect.addEventListener('change', filterAccountCodes);
            if (window.jQuery) {
                jQuery(itemTypeSelect)
                    .off('select2:select.legacyAccountFilter select2:clear.legacyAccountFilter change.legacyAccountFilter')
                    .on('select2:select.legacyAccountFilter select2:clear.legacyAccountFilter change.legacyAccountFilter', filterAccountCodes);
            }
        }
    }
    window.legacyAssetSetupAccountCodeTypeFilter = setupAccountCodeTypeFilter;

    function setupBrandModelFilter() {
        var brandSelect = document.querySelector('select[name="brand_id"]');
        var modelSelect = document.querySelector('select[name="model_id"]');
        if (!brandSelect || !modelSelect) { return; }
        if (brandSelect.getAttribute('data-brand-model-wired') === '1') { return; }
        brandSelect.setAttribute('data-brand-model-wired', '1');

        function filterModels() {
            var brandId = brandSelect.value || '';
            var previousValue = modelSelect.value || '';
            modelSelect.innerHTML = '';
            modelSelect.add(new Option('Select model', '', false, false));
            modelOptions.forEach(function(optionData) {
                // When a brand is selected, only show models tied to that brand.
                if (brandId !== '' && optionData.brandId !== brandId) { return; }
                var option = new Option(optionData.text, optionData.value, false, optionData.value === previousValue);
                option.setAttribute('data-brand-id', optionData.brandId);
                modelSelect.add(option);
            });
            if (previousValue !== '' && !Array.from(modelSelect.options).some(function(o) { return o.value === previousValue; })) {
                modelSelect.value = '';
            }
            refreshEnhancedSelect(modelSelect);
        }

        refreshEnhancedSelect(brandSelect);
        filterModels();
        brandSelect.addEventListener('change', filterModels);
        jQuery(brandSelect).off('change.legacyBrandFilter').on('change.legacyBrandFilter', filterModels);
    }

    function setupOfficeEmployeeFilter() {
        var officeSelect = document.querySelector('select[name="office_id"]');
        var employeeSelect = document.querySelector('select[name="employee_id"]');
        var rcSelect = document.querySelector('select[name="responsibility_code_id"]');
        if (!officeSelect || !employeeSelect || !rcSelect) { return; }
        if (!select2Ready()) {
            window.setTimeout(setupOfficeEmployeeFilter, 150);
            return;
        }
        if (officeSelect.getAttribute('data-office-employee-wired') === '1') { return; }
        officeSelect.setAttribute('data-office-employee-wired', '1');
        var assignmentSyncing = false;

        function refreshSharedSelect(select) {
            refreshEnhancedSelect(select);
        }

        function selectedOption(select) {
            return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        }

        function setSelectValue(select, value) {
            if (!select) { return; }
            select.value = String(value || '');
            refreshSharedSelect(select);
        }

        function enableAssignmentOptions(select) {
            if (!select) { return; }
            Array.prototype.forEach.call(select.options, function(option) {
                option.hidden = false;
                option.disabled = false;
            });
        }

        function employeeAssignments(employeeId) {
            return employeeAssignmentOptions.filter(function(assignment) {
                return assignment.employeeId === String(employeeId || '');
            });
        }

        function bestAssignmentForEmployee(employeeId, officeId, rcId) {
            var assignments = employeeAssignments(employeeId);
            if (!assignments.length) { return null; }
            var matches = assignments;
            if (officeId) {
                var officeMatches = matches.filter(function(assignment) {
                    return assignment.officeId === officeId;
                });
                if (officeMatches.length) {
                    matches = officeMatches;
                }
            }
            if (rcId) {
                var rcMatches = matches.filter(function(assignment) {
                    return assignment.responsibilityCodeId === rcId;
                });
                if (rcMatches.length) {
                    matches = rcMatches;
                }
            }
            return matches.find(function(assignment) { return assignment.isPrimary === 1; })
                || matches.find(function(assignment) { return assignment.isUnitHead === 1; })
                || matches[0];
        }

        function preferredEmployeeForOffice(officeId, preferredRcId) {
            var firstAssignment = null;
            var unitHeadAssignment = null;
            var rcAssignment = null;
            if (!officeId) { return ''; }
            employeeAssignmentOptions.forEach(function(assignment) {
                if (assignment.officeId !== officeId) { return; }
                if (!firstAssignment) {
                    firstAssignment = assignment;
                }
                if (assignment.isUnitHead === 1 && !unitHeadAssignment) {
                    unitHeadAssignment = assignment;
                }
                if (preferredRcId !== '' && assignment.responsibilityCodeId === preferredRcId && !rcAssignment) {
                    rcAssignment = assignment;
                }
            });
            var selectedAssignment = rcAssignment || unitHeadAssignment || firstAssignment;
            return selectedAssignment ? selectedAssignment.employeeId : '';
        }

        function preferredRcForOffice(officeId, preferredEmployeeId) {
            var firstRcId = '';
            if (!officeId) { return ''; }
            if (preferredEmployeeId) {
                var assignment = bestAssignmentForEmployee(preferredEmployeeId, officeId, '');
                if (assignment && assignment.responsibilityCodeId !== '0') {
                    return assignment.responsibilityCodeId;
                }
            }
            Array.prototype.forEach.call(rcSelect.options, function(option) {
                if (!option.value || (option.getAttribute('data-office-id') || '') !== officeId) { return; }
                if (firstRcId === '') {
                    firstRcId = option.value;
                }
            });
            return firstRcId;
        }

        function syncFromOffice(forceFill) {
            var officeId = officeSelect.value || '';
            enableAssignmentOptions(employeeSelect);
            enableAssignmentOptions(rcSelect);
            if (officeId !== '') {
                var nextEmployeeId = preferredEmployeeForOffice(officeId, '');
                if (forceFill || !employeeSelect.value) {
                    setSelectValue(employeeSelect, nextEmployeeId);
                }
                var nextRcId = preferredRcForOffice(officeId, employeeSelect.value || '');
                if (forceFill || !rcSelect.value) {
                    setSelectValue(rcSelect, nextRcId);
                }
            }
            refreshSharedSelect(employeeSelect);
            refreshSharedSelect(rcSelect);
        }

        function syncFromEmployee() {
            var option = selectedOption(employeeSelect);
            var employeeId = option ? (option.value || '') : '';
            if (!employeeId) {
                syncFromOffice(false);
                return;
            }
            var currentOfficeId = officeSelect.value || '';
            var assignment = bestAssignmentForEmployee(employeeId, currentOfficeId, '');
            if (assignment && assignment.officeId) {
                setSelectValue(officeSelect, assignment.officeId);
                enableAssignmentOptions(employeeSelect);
                enableAssignmentOptions(rcSelect);
                setSelectValue(employeeSelect, employeeId);
                setSelectValue(rcSelect, assignment.responsibilityCodeId !== '0' ? assignment.responsibilityCodeId : preferredRcForOffice(assignment.officeId, employeeId));
            }
        }

        function syncFromResponsibilityCode() {
            var option = selectedOption(rcSelect);
            var rcId = option ? (option.value || '') : '';
            if (!rcId) {
                syncFromOffice(false);
                return;
            }
            var officeId = option.getAttribute('data-office-id') || '';
            if (officeId) {
                setSelectValue(officeSelect, officeId);
                enableAssignmentOptions(employeeSelect);
                enableAssignmentOptions(rcSelect);
                setSelectValue(rcSelect, rcId);
                setSelectValue(employeeSelect, preferredEmployeeForOffice(officeId, rcId));
            }
        }

        function guarded(handler) {
            return function() {
                if (assignmentSyncing) { return; }
                assignmentSyncing = true;
                try {
                    handler();
                } finally {
                    assignmentSyncing = false;
                }
            };
        }

        refreshSharedSelect(officeSelect);
        refreshSharedSelect(employeeSelect);
        refreshSharedSelect(rcSelect);
        var handleOfficeChange = guarded(function() { syncFromOffice(true); });
        var handleEmployeeChange = guarded(syncFromEmployee);
        var handleRcChange = guarded(syncFromResponsibilityCode);
        officeSelect.addEventListener('change', handleOfficeChange);
        employeeSelect.addEventListener('change', handleEmployeeChange);
        rcSelect.addEventListener('change', handleRcChange);
        if (window.jQuery) {
            jQuery(officeSelect).off('select2:select.legacyOfficeFilter select2:clear.legacyOfficeFilter change.legacyOfficeFilter').on('select2:select.legacyOfficeFilter select2:clear.legacyOfficeFilter change.legacyOfficeFilter', handleOfficeChange);
            jQuery(employeeSelect).off('select2:select.legacyEmployeeFilter select2:clear.legacyEmployeeFilter change.legacyEmployeeFilter').on('select2:select.legacyEmployeeFilter select2:clear.legacyEmployeeFilter change.legacyEmployeeFilter', handleEmployeeChange);
            jQuery(rcSelect).off('select2:select.legacyRcFilter select2:clear.legacyRcFilter change.legacyRcFilter').on('select2:select.legacyRcFilter select2:clear.legacyRcFilter change.legacyRcFilter', handleRcChange);
        }
        syncFromOffice(false);
    }

    function setupBulkImportPreview() {
        var textarea = document.getElementById('bulk_items');
        var preview = document.getElementById('bulkImportPreview');
        if (!textarea || !preview) { return; }

        function currentRowSelections() {
            var values = {};
            Array.prototype.forEach.call(preview.querySelectorAll('select[name="bulk_row_classification_id[]"]'), function(select) {
                var key = select.getAttribute('data-row-key') || '';
                if (key) {
                    values[key] = values[key] || {};
                    values[key].classificationId = select.value || '';
                }
            });
            Array.prototype.forEach.call(preview.querySelectorAll('[data-edit-field]'), function(input) {
                var key = input.getAttribute('data-row-key') || '';
                var field = input.getAttribute('data-edit-field') || '';
                if (key && field) {
                    values[key] = values[key] || {};
                    values[key][field] = input.value || '';
                }
            });
            return values;
        }

        function buildInput(name, value, rowKey, field, type, classes) {
            var input = document.createElement('input');
            input.type = type || 'text';
            input.name = name;
            input.value = value || '';
            input.className = classes || 'form-control form-control-sm';
            input.setAttribute('data-row-key', rowKey);
            input.setAttribute('data-edit-field', field);
            return input;
        }

        function buildTextarea(name, value, rowKey, field) {
            var textareaEl = document.createElement('textarea');
            textareaEl.name = name;
            textareaEl.value = value || '';
            textareaEl.rows = 2;
            textareaEl.className = 'form-control form-control-sm';
            textareaEl.setAttribute('data-row-key', rowKey);
            textareaEl.setAttribute('data-edit-field', field);
            return textareaEl;
        }

        function classificationChoices() {
            var itemTypeSelect = document.getElementById('bulk_item_type');
            var expectedGroups = expectedAssetGroups(itemTypeSelect ? itemTypeSelect.value : 'equipment');
            return classificationOptions.filter(function(optionData) {
                return classificationGroupMatches(optionData, expectedGroups);
            });
        }

        function normalizeAmount(value) {
            value = String(value || '').trim();
            if (!value || /[A-Za-z]/.test(value)) { return ''; }
            value = value.replace(/[,₱]/g, '').replace(/^PHP\s*/i, '').replace(/^P\s*/i, '').trim();
            var numberValue = Number(value);
            if (!Number.isFinite(numberValue) || numberValue < 0) { return ''; }
            return numberValue.toFixed(2);
        }

        function parseRows(text) {
            var rows = [];
            text.split(/\r?\n/).forEach(function(line) {
                line = line.trim();
                if (!line) { return; }
                var serialMatch = line.match(/^(?:s\/?n|serial(?:\s+no\.?)?)\s*[:#-]?\s*(.+)$/i);
                if (serialMatch && rows.length) {
                    rows[rows.length - 1].serialNo = serialMatch[1].trim();
                    return;
                }
                var qty = '1';
                var unit = '';
                var unitCost = '';
                var totalCost = '';
                var description = line;
                var tabParts = line.split(/\t+/);
                var textMatch = line.match(/^(\d+)\s+([A-Za-z.\/-]+)\s+(.+)$/);
                if (tabParts.length >= 3 && /^\d+$/.test(tabParts[0].trim())) {
                    qty = tabParts[0].trim();
                    unit = tabParts[1].trim();
                    var descriptionParts = [];
                    tabParts.slice(2).map(function(part) { return part.trim(); }).filter(Boolean).forEach(function(part) {
                        var amount = normalizeAmount(part);
                        if (amount && descriptionParts.length === 0) {
                            if (!unitCost) {
                                unitCost = amount;
                                return;
                            }
                            if (!totalCost) {
                                totalCost = amount;
                                return;
                            }
                        }
                        descriptionParts.push(part);
                    });
                    description = descriptionParts.join(' ').trim();
                } else if (textMatch) {
                    qty = textMatch[1];
                    unit = textMatch[2];
                    description = textMatch[3].trim();
                }
                if (description) {
                    rows.push({ qty: qty, unit: unit, unitCost: unitCost, totalCost: totalCost, description: description, serialNo: '', key: [qty, unit, unitCost, totalCost, description, rows.length].join('|') });
                }
            });
            return rows;
        }

        function renderPreview() {
            var rows = parseRows(textarea.value);
            var savedSelections = currentRowSelections();
            var choices = classificationChoices();
            preview.innerHTML = '';
            if (!rows.length) {
                preview.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-3">Paste rows above to preview the import.</td></tr>';
                return;
            }
            rows.forEach(function(row) {
                var tr = document.createElement('tr');
                var saved = savedSelections[row.key] || {};

                var qtyTd = document.createElement('td');
                qtyTd.appendChild(buildInput('bulk_row_quantity[]', saved.quantity || row.qty, row.key, 'quantity', 'number'));
                tr.appendChild(qtyTd);

                var unitTd = document.createElement('td');
                unitTd.appendChild(buildInput('bulk_row_unit_text[]', saved.unitText || row.unit || '', row.key, 'unitText'));
                tr.appendChild(unitTd);

                var unitCostTd = document.createElement('td');
                unitCostTd.appendChild(buildInput('bulk_row_unit_cost[]', saved.unitCost || row.unitCost || '', row.key, 'unitCost', 'number'));
                tr.appendChild(unitCostTd);

                var totalCostTd = document.createElement('td');
                totalCostTd.appendChild(buildInput('bulk_row_total_cost[]', saved.totalCost || row.totalCost || '', row.key, 'totalCost', 'number'));
                tr.appendChild(totalCostTd);

                var descTd = document.createElement('td');
                descTd.appendChild(buildTextarea('bulk_row_item_description[]', saved.description || row.description, row.key, 'description'));
                tr.appendChild(descTd);

                var classificationTd = document.createElement('td');
                var select = document.createElement('select');
                select.className = 'form-select form-select-sm';
                select.name = 'bulk_row_classification_id[]';
                select.setAttribute('data-row-key', row.key);
                select.appendChild(new Option('Use default classification', '', false, false));
                choices.forEach(function(optionData) {
                    select.appendChild(new Option(optionData.text, optionData.value, false, optionData.value === (saved.classificationId || '')));
                });
                classificationTd.appendChild(select);
                tr.appendChild(classificationTd);

                var serialTd = document.createElement('td');
                serialTd.appendChild(buildInput('bulk_row_serial_no[]', saved.serialNo || row.serialNo || '', row.key, 'serialNo'));
                tr.appendChild(serialTd);
                preview.appendChild(tr);
            });
        }

        textarea.addEventListener('input', renderPreview);
        document.addEventListener('change', function(e) {
            if (e.target && (e.target.id === 'bulk_item_type' || e.target.id === 'bulk_classification_id')) {
                renderPreview();
            }
        });
        renderPreview();
        window.legacyAssetRenderBulkPreview = renderPreview;
    }

    function setupBulkAccountFilters() {
        var itemTypeSelect = document.getElementById('bulk_item_type');
        var accountCodeSelect = document.getElementById('bulk_account_code_id');
        var classificationSelect = document.getElementById('bulk_classification_id');
        if (!itemTypeSelect || !accountCodeSelect || !classificationSelect) { return; }

        function filterBulkOptions() {
            var expectedGroups = expectedAssetGroups(itemTypeSelect.value);
            var previousAccount = accountCodeSelect.value || '';
            var previousClassification = classificationSelect.value || '';
            accountCodeSelect.innerHTML = '';
            accountCodeSelect.add(new Option('Select account code', '', false, false));
            accountCodeOptions.forEach(function(optionData) {
                if (expectedGroups.indexOf(optionData.accountGroup) === -1) { return; }
                var option = new Option(optionData.text, optionData.value, false, optionData.value === previousAccount);
                option.setAttribute('data-account-group', optionData.accountGroup);
                accountCodeSelect.add(option);
            });
            if (previousAccount && !Array.from(accountCodeSelect.options).some(function(option) { return option.value === previousAccount; })) {
                accountCodeSelect.value = '';
            }

            classificationSelect.innerHTML = '';
            classificationSelect.add(new Option('Select classification', '', false, false));
            classificationOptions.forEach(function(optionData) {
                if (!classificationGroupMatches(optionData, expectedGroups)) { return; }
                var option = new Option(optionData.text, optionData.value, false, optionData.value === previousClassification);
                option.setAttribute('data-account-code-id', optionData.accountCodeId || '0');
                option.setAttribute('data-classification-group', optionData.classificationGroup || '');
                classificationSelect.add(option);
            });
            if (previousClassification && !Array.from(classificationSelect.options).some(function(option) { return option.value === previousClassification; })) {
                classificationSelect.value = '';
            }
            refreshEnhancedSelect(accountCodeSelect);
            refreshEnhancedSelect(classificationSelect);
            if (typeof window.legacyAssetRenderBulkPreview === 'function') {
                window.legacyAssetRenderBulkPreview();
            }
        }

        itemTypeSelect.addEventListener('change', filterBulkOptions);
        if (window.jQuery) {
            jQuery(itemTypeSelect).off('change.bulkImportType').on('change.bulkImportType', filterBulkOptions);
        }
        filterBulkOptions();
    }

    function setupBulkOfficeEmployeeFilter() {
        var officeSelect = document.getElementById('bulk_office_id');
        var employeeSelect = document.getElementById('bulk_employee_id');
        var rcSelect = document.getElementById('bulk_responsibility_code_id');
        if (!officeSelect || !employeeSelect || !rcSelect) { return; }

        function setValue(select, value) {
            select.value = String(value || '');
            refreshEnhancedSelect(select);
        }

        function employeeAssignments(employeeId) {
            return employeeAssignmentOptions.filter(function(assignment) {
                return assignment.employeeId === String(employeeId || '');
            });
        }

        function bestAssignmentForEmployee(employeeId, officeId) {
            var assignments = employeeAssignments(employeeId);
            if (!assignments.length) { return null; }
            if (officeId) {
                var officeMatches = assignments.filter(function(assignment) { return assignment.officeId === officeId; });
                if (officeMatches.length) { assignments = officeMatches; }
            }
            return assignments.find(function(assignment) { return assignment.isPrimary === 1; })
                || assignments.find(function(assignment) { return assignment.isUnitHead === 1; })
                || assignments[0];
        }

        function firstEmployeeForOffice(officeId, rcId) {
            var firstAssignment = null;
            var unitHeadAssignment = null;
            var rcAssignment = null;
            employeeAssignmentOptions.forEach(function(assignment) {
                if (assignment.officeId !== officeId) { return; }
                if (!firstAssignment) { firstAssignment = assignment; }
                if (assignment.isUnitHead === 1 && !unitHeadAssignment) { unitHeadAssignment = assignment; }
                if (rcId && assignment.responsibilityCodeId === rcId && !rcAssignment) { rcAssignment = assignment; }
            });
            var selectedAssignment = rcAssignment || unitHeadAssignment || firstAssignment;
            return selectedAssignment ? selectedAssignment.employeeId : '';
        }

        function firstRcForOffice(officeId, employeeId) {
            var assignment = bestAssignmentForEmployee(employeeId, officeId);
            if (assignment && assignment.responsibilityCodeId !== '0') {
                return assignment.responsibilityCodeId;
            }
            var firstId = '';
            Array.prototype.forEach.call(rcSelect.options, function(option) {
                if (!option.value || (option.getAttribute('data-office-id') || '') !== officeId) { return; }
                if (!firstId) { firstId = option.value; }
            });
            return firstId;
        }

        function syncBulkFromOffice() {
            var officeId = officeSelect.value || '';
            if (!officeId) { return; }
            var employeeId = employeeSelect.value || firstEmployeeForOffice(officeId, '');
            setValue(employeeSelect, employeeId);
            setValue(rcSelect, firstRcForOffice(officeId, employeeId));
        }

        function syncBulkFromEmployee() {
            var assignment = bestAssignmentForEmployee(employeeSelect.value || '', officeSelect.value || '');
            if (!assignment) { return; }
            setValue(officeSelect, assignment.officeId);
            setValue(employeeSelect, assignment.employeeId);
            setValue(rcSelect, assignment.responsibilityCodeId !== '0' ? assignment.responsibilityCodeId : firstRcForOffice(assignment.officeId, assignment.employeeId));
        }

        function syncBulkFromRc() {
            var option = rcSelect.options[rcSelect.selectedIndex];
            var rcId = option ? (option.value || '') : '';
            var officeId = option ? (option.getAttribute('data-office-id') || '') : '';
            if (!officeId) { return; }
            setValue(officeSelect, officeId);
            setValue(rcSelect, rcId);
            setValue(employeeSelect, firstEmployeeForOffice(officeId, rcId));
        }

        officeSelect.addEventListener('change', syncBulkFromOffice);
        employeeSelect.addEventListener('change', syncBulkFromEmployee);
        rcSelect.addEventListener('change', syncBulkFromRc);
        if (window.jQuery) {
            jQuery(officeSelect).off('change.bulkOffice').on('change.bulkOffice', syncBulkFromOffice);
            jQuery(employeeSelect).off('change.bulkEmployee').on('change.bulkEmployee', syncBulkFromEmployee);
            jQuery(rcSelect).off('change.bulkRc').on('change.bulkRc', syncBulkFromRc);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            [
                document.getElementById('classification_id'),
                document.getElementById('account_code_id'),
                document.getElementById('fund_id'),
                document.getElementById('supplier_id'),
                document.getElementById('brand_id'),
                document.getElementById('model_id'),
                document.getElementById('office_id'),
                document.getElementById('employee_id'),
                document.getElementById('bulk_account_code_id'),
                document.getElementById('bulk_classification_id'),
                document.getElementById('bulk_fund_id'),
                document.getElementById('bulk_supplier_id'),
                document.getElementById('bulk_office_id'),
                document.getElementById('bulk_employee_id'),
                document.getElementById('bulk_responsibility_code_id')
            ].forEach(refreshEnhancedSelect);
            setupAccountCodeTypeFilter();
            setupBrandModelFilter();
            setupOfficeEmployeeFilter();
            setupBulkImportPreview();
            setupBulkAccountFilters();
            setupBulkOfficeEmployeeFilter();
        });
    } else {
        [
            document.getElementById('classification_id'),
            document.getElementById('account_code_id'),
            document.getElementById('fund_id'),
            document.getElementById('supplier_id'),
            document.getElementById('brand_id'),
            document.getElementById('model_id'),
            document.getElementById('office_id'),
            document.getElementById('employee_id'),
            document.getElementById('bulk_account_code_id'),
            document.getElementById('bulk_classification_id'),
            document.getElementById('bulk_fund_id'),
            document.getElementById('bulk_supplier_id'),
            document.getElementById('bulk_office_id'),
            document.getElementById('bulk_employee_id'),
            document.getElementById('bulk_responsibility_code_id')
        ].forEach(refreshEnhancedSelect);
        setupAccountCodeTypeFilter();
        setupBrandModelFilter();
        setupOfficeEmployeeFilter();
        setupBulkImportPreview();
        setupBulkAccountFilters();
        setupBulkOfficeEmployeeFilter();
    }
})();
</script>

<!-- Quick-Add Modals -->

<!-- Classification Modal -->
<div class="modal fade" id="qaClassificationModal" tabindex="-1" aria-labelledby="qaClassificationModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaClassificationModalLabel">Add Classification</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaClassificationError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <label class="form-label">Classification Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_classification_name" placeholder="e.g. Office Chair">
          <div class="form-text">Family will automatically copy the selected account code name.</div>
        </div>
      </div>
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaClassificationSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Account Code Modal -->
<div class="modal fade" id="qaAccountCodeModal" tabindex="-1" aria-labelledby="qaAccountCodeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaAccountCodeModalLabel">Add Account Code</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaAccountCodeError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <label class="form-label">Account Code <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_account_code" placeholder="e.g. 10605010-00">
        </div>
        <div class="mb-3">
          <label class="form-label">Account Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_account_name" placeholder="e.g. Office Equipment">
        </div>
      </div>
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaAccountCodeSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Brand Modal -->
<div class="modal fade" id="qaBrandModal" tabindex="-1" aria-labelledby="qaBrandModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaBrandModalLabel">Add Brand</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaBrandError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <label class="form-label">Brand Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_brand_name" placeholder="e.g. Samsung">
        </div>
      </div>
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaBrandSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Model Modal -->
<div class="modal fade" id="qaModelModal" tabindex="-1" aria-labelledby="qaModelModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaModelModalLabel">Add Model</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaModelError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <p class="mb-1 text-muted small">Brand: <strong id="qaModelBrandLabel">—</strong></p>
          <input type="hidden" id="qa_model_brand_id">
        </div>
        <div class="mb-3">
          <label class="form-label">Model Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_model_name" placeholder="e.g. Galaxy S24">
        </div>
      </div>
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaModelSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Office Modal -->
<div class="modal fade" id="qaOfficeModal" tabindex="-1" aria-labelledby="qaOfficeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaOfficeModalLabel">Add Office</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaOfficeError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <label class="form-label">Office Code <span class="text-danger">*</span></label>
          <input type="text" class="form-control text-uppercase" id="qa_office_code" placeholder="e.g. HRMO">
        </div>
        <div class="mb-3">
          <label class="form-label">Office Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_office_name" placeholder="e.g. Human Resource Management Office">
        </div>
      </div>
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaOfficeSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Employee Modal -->
<div class="modal fade" id="qaEmployeeModal" tabindex="-1" aria-labelledby="qaEmployeeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaEmployeeModalLabel">Add Employee</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaEmployeeError" class="alert alert-danger d-none"></div>
        <div class="row g-2 mb-3">
          <div class="col-5">
            <label class="form-label">First Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="qa_emp_first_name">
          </div>
          <div class="col-3">
            <label class="form-label">Middle Name</label>
            <input type="text" class="form-control" id="qa_emp_middle_name">
          </div>
          <div class="col-4">
            <label class="form-label">Last Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="qa_emp_last_name">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Position Title <small class="text-muted">(optional)</small></label>
          <input type="text" class="form-control" id="qa_emp_position_title">
        </div>
        <div class="mb-3">
          <label class="form-label">Office <small class="text-muted">(optional)</small></label>
          <select class="form-select" id="qa_emp_office_id">
            <option value="">— Select Office —</option>
          </select>
        </div>
      </div>
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaEmployeeSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';

    var qaEndpoint = <?php echo json_encode(base_url('modules/property/legacy_assets_quickadd.php')); ?>;
    var qaContext = 'single';

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-qa-modal]');
        if (!btn) return;
        e.preventDefault();
        qaContext = btn.getAttribute('data-qa-context') || 'single';
        var modalId = btn.getAttribute('data-qa-modal');
        var modalEl = document.getElementById(modalId);
        if (!modalEl) return;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    function getCsrf() {
        var el = document.getElementById('legacy_asset_csrf_token');
        if (!el) {
            el = document.querySelector('form.workspace-form-section input[name="_csrf"]');
        }
        if (!el) {
            el = document.querySelector('input[name="_csrf"]');
        }
        return el ? el.value : '';
    }

    function showError(elId, msg) {
        var el = document.getElementById(elId);
        if (!el) return;
        el.textContent = msg;
        el.classList.remove('d-none');
    }

    function clearError(elId) {
        var el = document.getElementById(elId);
        if (!el) return;
        el.textContent = '';
        el.classList.add('d-none');
    }

    function appendOption(selectId, id, label, extraData) {
        var sel = document.getElementById(selectId);
        if (!sel) return;
        var stringId = String(id);
        var existing = Array.prototype.find.call(sel.options, function (option) {
            return String(option.value) === stringId;
        });
        if (existing) {
            existing.textContent = label;
            if (extraData) {
                Object.keys(extraData).forEach(function (k) {
                    existing.setAttribute('data-' + k.replace(/_/g, '-'), extraData[k]);
                });
            }
            sel.value = stringId;
            if (window.SPAMS && window.SPAMS.refreshSelect2) {
                window.SPAMS.refreshSelect2(sel);
            }
            return;
        }
        var opt = document.createElement('option');
        opt.value = stringId;
        opt.textContent = label;
        if (extraData) {
            Object.keys(extraData).forEach(function (k) {
                opt.setAttribute('data-' + k.replace(/_/g, '-'), extraData[k]);
            });
        }
        sel.appendChild(opt);
        sel.value = id;
        if (window.SPAMS && window.SPAMS.refreshSelect2) {
            window.SPAMS.refreshSelect2(sel);
        }
    }

    function postQA(payload, onSuccess, onError) {
        payload['_csrf'] = getCsrf();
        fetch(qaEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': getCsrf()
            },
            body: new URLSearchParams(payload).toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { onSuccess(data); }
            else { onError(data.error || 'An error occurred.'); }
        })
        .catch(function () { onError('Network error. Please try again.'); });
    }

    function hideQAModal(modalId) {
        var modalEl = document.getElementById(modalId);
        if (!modalEl) return;
        var modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    }

    function bindQAModal(config) {
        var modalEl = document.getElementById(config.modalId);
        var saveBtn = document.getElementById(config.saveBtnId);
        if (!modalEl || !saveBtn) return;

        modalEl.addEventListener('show.bs.modal', function () {
            clearError(config.errorId);
            if (typeof config.onShow === 'function') {
                config.onShow();
            }
        });

        saveBtn.addEventListener('click', function () {
            clearError(config.errorId);
            var payload = config.buildPayload();
            if (!payload || payload.error) {
                showError(config.errorId, (payload && payload.error) || 'Please complete required fields.');
                return;
            }

            postQA(payload, function (data) {
                config.onSuccess(data);
                hideQAModal(config.modalId);
            }, function (err) {
                showError(config.errorId, err);
            });
        });
    }

    bindQAModal({
        modalId: 'qaClassificationModal',
        saveBtnId: 'qaClassificationSaveBtn',
        errorId: 'qaClassificationError',
        onShow: function () {
            document.getElementById('qa_classification_name').value = '';
        },
        buildPayload: function () {
            var name = document.getElementById('qa_classification_name').value.trim();
            var accountCodeSelect = document.getElementById(qaContext === 'bulk' ? 'bulk_account_code_id' : 'account_code_id');
            var accountCodeId = accountCodeSelect ? accountCodeSelect.value : '';
            if (!name) return { error: 'Classification Name is required.' };
            if (!accountCodeId) return { error: 'Select Account Code first before adding classification.' };
            return {
                action: 'add_classification',
                classification_name: name,
                account_code_id: accountCodeId
            };
        },
        onSuccess: function (data) {
            appendOption('classification_id', data.id, data.label, { account_code_id: data.account_code_id || 0, classification_group: data.classification_group || '' });
            appendOption('bulk_classification_id', data.id, data.label, { account_code_id: data.account_code_id || 0, classification_group: data.classification_group || '' });
            if (window.legacyAssetClassificationOptions && !window.legacyAssetClassificationOptions.some(function (option) { return String(option.value) === String(data.id); })) {
                window.legacyAssetClassificationOptions.push({
                    value: String(data.id),
                    text: data.label,
                    accountCodeId: String(data.account_code_id || ''),
                    classificationGroup: data.classification_group || ''
                });
            }
            if (typeof window.legacyAssetFilterClassificationsForType === 'function') {
                window.legacyAssetFilterClassificationsForType();
                var classificationSelect = document.getElementById('classification_id');
                if (classificationSelect) {
                    classificationSelect.value = String(data.id);
                    if (window.SPAMS && window.SPAMS.refreshSelect2) {
                        window.SPAMS.refreshSelect2(classificationSelect);
                    }
                }
            }
            if (typeof window.legacyAssetRenderBulkPreview === 'function') {
                var bulkClassificationSelect = document.getElementById('bulk_classification_id');
                if (bulkClassificationSelect) {
                    bulkClassificationSelect.value = String(data.id);
                    if (window.SPAMS && window.SPAMS.refreshSelect2) {
                        window.SPAMS.refreshSelect2(bulkClassificationSelect);
                    }
                }
                window.legacyAssetRenderBulkPreview();
            }
        }
    });

    bindQAModal({
        modalId: 'qaAccountCodeModal',
        saveBtnId: 'qaAccountCodeSaveBtn',
        errorId: 'qaAccountCodeError',
        onShow: function () {
            document.getElementById('qa_account_code').value = '';
            document.getElementById('qa_account_name').value = '';
        },
        buildPayload: function () {
            var code = document.getElementById('qa_account_code').value.trim();
            var name = document.getElementById('qa_account_name').value.trim();
            if (!code) return { error: 'Account Code is required.' };
            if (!name) return { error: 'Account Name is required.' };
            var itemTypeSelect = document.getElementById('item_type');
            var accountGroup = itemTypeSelect && itemTypeSelect.value === 'equipment' ? 'asset' : 'semi_expendable';
            return { action: 'add_account_code', account_code: code, account_name: name, account_group: accountGroup };
        },
        onSuccess: function (data) {
            appendOption('account_code_id', data.id, data.label, { account_group: data.account_group || '' });
            if (window.legacyAssetAccountCodeOptions) {
                window.legacyAssetAccountCodeOptions.push({
                    value: String(data.id),
                    text: data.label,
                    accountGroup: data.account_group || ''
                });
            }
            if (typeof window.legacyAssetSetupAccountCodeTypeFilter === 'function') {
                window.legacyAssetSetupAccountCodeTypeFilter();
            }
        }
    });

    bindQAModal({
        modalId: 'qaBrandModal',
        saveBtnId: 'qaBrandSaveBtn',
        errorId: 'qaBrandError',
        onShow: function () {
            document.getElementById('qa_brand_name').value = '';
        },
        buildPayload: function () {
            var name = document.getElementById('qa_brand_name').value.trim();
            if (!name) return { error: 'Brand Name is required.' };
            return { action: 'add_brand', brand_name: name };
        },
        onSuccess: function (data) {
            appendOption('brand_id', data.id, data.label);
        }
    });

    bindQAModal({
        modalId: 'qaModelModal',
        saveBtnId: 'qaModelSaveBtn',
        errorId: 'qaModelError',
        onShow: function () {
            document.getElementById('qa_model_name').value = '';
            var brandSel = document.getElementById('brand_id');
            var brandId = brandSel ? brandSel.value : '';
            var brandLabel = brandSel && brandSel.selectedIndex >= 0 ? brandSel.options[brandSel.selectedIndex].text : '—';
            document.getElementById('qa_model_brand_id').value = brandId;
            document.getElementById('qaModelBrandLabel').textContent = brandId ? brandLabel : '— (no brand selected)';
        },
        buildPayload: function () {
            var name = document.getElementById('qa_model_name').value.trim();
            var brandId = document.getElementById('qa_model_brand_id').value;
            if (!name) return { error: 'Model Name is required.' };
            if (!brandId) return { error: 'Please select a Brand first, then open this dialog.' };
            return { action: 'add_model', model_name: name, brand_id: brandId };
        },
        onSuccess: function (data) {
            appendOption('model_id', data.id, data.label, { 'brand-id': data.brand_id });
        }
    });

    bindQAModal({
        modalId: 'qaOfficeModal',
        saveBtnId: 'qaOfficeSaveBtn',
        errorId: 'qaOfficeError',
        onShow: function () {
            document.getElementById('qa_office_code').value = '';
            document.getElementById('qa_office_name').value = '';
        },
        buildPayload: function () {
            var code = document.getElementById('qa_office_code').value.trim().toUpperCase();
            var name = document.getElementById('qa_office_name').value.trim();
            if (!code) return { error: 'Office Code is required.' };
            if (!name) return { error: 'Office Name is required.' };
            return { action: 'add_office', office_code: code, office_name: name };
        },
        onSuccess: function (data) {
            appendOption('office_id', data.id, data.label);
            var empOfficeSel = document.getElementById('qa_emp_office_id');
            if (empOfficeSel) {
                var opt = document.createElement('option');
                opt.value = data.id;
                opt.textContent = data.label;
                empOfficeSel.appendChild(opt);
            }
        }
    });

    bindQAModal({
        modalId: 'qaEmployeeModal',
        saveBtnId: 'qaEmployeeSaveBtn',
        errorId: 'qaEmployeeError',
        onShow: function () {
            document.getElementById('qa_emp_first_name').value = '';
            document.getElementById('qa_emp_middle_name').value = '';
            document.getElementById('qa_emp_last_name').value = '';
            document.getElementById('qa_emp_position_title').value = '';

            var empOfficeSel = document.getElementById('qa_emp_office_id');
            var mainOfficeSel = document.getElementById('office_id');
            if (!empOfficeSel) return;

            empOfficeSel.innerHTML = '<option value="">— Select Office —</option>';
            if (mainOfficeSel) {
                Array.from(mainOfficeSel.options).forEach(function (opt) {
                    if (!opt.value) return;
                    var o = document.createElement('option');
                    o.value = opt.value;
                    o.textContent = opt.textContent;
                    empOfficeSel.appendChild(o);
                });
                empOfficeSel.value = mainOfficeSel.value || '';
            }
        },
        buildPayload: function () {
            var first = document.getElementById('qa_emp_first_name').value.trim();
            var last = document.getElementById('qa_emp_last_name').value.trim();
            if (!first) return { error: 'First Name is required.' };
            if (!last) return { error: 'Last Name is required.' };

            return {
                action: 'add_employee',
                first_name: first,
                middle_name: document.getElementById('qa_emp_middle_name').value.trim(),
                last_name: last,
                position_title: document.getElementById('qa_emp_position_title').value.trim(),
                office_id: document.getElementById('qa_emp_office_id').value
            };
        },
        onSuccess: function (data) {
            appendOption('employee_id', data.id, data.label, { 'office-id': data.office_id });
        }
    });

})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
