<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

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
        throw new RuntimeException('XLSX import is not available because ZipArchive is missing on this PHP setup.');
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
    foreach ($models as $r) $maps['model'][li_norm($r['model_name'])] = $r;
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
                $stmt = $db->prepare("INSERT INTO legacy_assets (system_reference, property_number, item_type, item_description, classification_id, account_code_id, fund_id, supplier_id, brand_id, model_id, brand, model, serial_no, acquisition_date, quantity, unit_cost, acquisition_cost, office_id, employee_id, responsibility_code_id, condition_status, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
                        $stmt->bind_param('ssssiiiiissssiddiiissi', $systemReference, $row['property_number'], $row['item_type'], $row['item_description'], $classificationId, $accountCodeId, $fundId, $supplierId, $brandId, $modelId, $row['brand_name'], $row['model_name'], $row['serial_no'], $row['acquisition_date'], $qty, $unitCost, $totalCost, $officeId, $employeeId, $rcId, $row['condition_status'], $row['remarks'], $userId);
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
                        foreach (['property_number','inventory_type','description'] as $required) if (!isset($col[$required])) $errors[] = 'Missing required column: ' . $required;
                        if (!$errors) {
                            $parsed = [];
                            for ($i = 1; $i < count($rows); $i++) {
                                $src = $rows[$i];
                                if (!array_filter($src, fn($v) => trim((string) $v) !== '')) continue;
                                $r = [
                                    'source_row' => $i + 1,
                                    'property_number' => trim((string) ($src[$col['property_number']] ?? '')),
                                    'item_type' => strtolower(str_replace([' ', '-'], '_', (string) ($src[$col['inventory_type']] ?? ''))),
                                    'item_description' => trim((string) ($src[$col['description']] ?? '')),
                                    'classification' => trim((string) ($src[$col['classification']] ?? '')),
                                    'fund' => trim((string) ($src[$col['fund']] ?? ($src[$col['fund_number']] ?? ''))),
                                    'account_code' => trim((string) ($src[$col['account_code']] ?? '')),
                                    'supplier' => trim((string) ($src[$col['supplier']] ?? '')),
                                    'brand' => trim((string) ($src[$col['brand']] ?? '')),
                                    'model' => trim((string) ($src[$col['model']] ?? '')),
                                    'serial_no' => trim((string) ($src[$col['serial_no']] ?? '')),
                                    'acquisition_date' => trim((string) ($src[$col['acquisition_date']] ?? '')),
                                    'quantity' => trim((string) ($src[$col['quantity']] ?? '1')),
                                    'unit_cost' => trim((string) ($src[$col['unit_cost']] ?? '')),
                                    'office' => trim((string) ($src[$col['office']] ?? '')),
                                    'employee' => trim((string) ($src[$col['employee']] ?? '')),
                                    'responsibility_code' => trim((string) ($src[$col['responsibility_code']] ?? '')),
                                    'condition_status' => trim((string) ($src[$col['condition_status']] ?? 'good')),
                                    'remarks' => trim((string) ($src[$col['remarks']] ?? '')),
                                    'errors' => [],
                                ];
                                if ($r['property_number'] === '') $r['errors'][] = 'Property number is required.';
                                if ($r['item_description'] === '') $r['errors'][] = 'Description is required.';
                                if (!in_array($r['item_type'], ['equipment', 'semi_expendable'], true)) $r['errors'][] = 'Type must be equipment or semi_expendable.';
                                if (!ctype_digit($r['quantity']) || (int) $r['quantity'] <= 0) $r['errors'][] = 'Quantity must be a whole number.';
                                if ($r['unit_cost'] === '' || !is_numeric($r['unit_cost'])) $r['errors'][] = 'Unit cost is required.';

                                $classification = $maps['classification'][li_norm($r['classification'])] ?? null;
                                $account = $maps['account'][li_norm($r['account_code'])] ?? null;
                                $fund = $maps['fund'][li_norm($r['fund'])] ?? null;
                                $supplier = $maps['supplier'][li_norm($r['supplier'])] ?? null;
                                $brand = $maps['brand'][li_norm($r['brand'])] ?? null;
                                $model = $maps['model'][li_norm($r['model'])] ?? null;
                                $office = $maps['office'][li_norm($r['office'])] ?? null;
                                $employee = $maps['employee'][li_norm($r['employee'])] ?? null;
                                $rc = $maps['rc'][li_norm($r['responsibility_code'])] ?? null;

                                if ($r['classification'] !== '' && !$classification) $r['errors'][] = 'Unknown classification.';
                                if ($r['account_code'] !== '' && !$account) $r['errors'][] = 'Unknown account code.';
                                if ($r['fund'] !== '' && !$fund) $r['errors'][] = 'Unknown fund.';
                                if ($r['supplier'] !== '' && !$supplier) $r['errors'][] = 'Unknown supplier.';
                                if ($r['brand'] !== '' && !$brand) $r['errors'][] = 'Unknown brand.';
                                if ($r['model'] !== '' && !$model) $r['errors'][] = 'Unknown model.';
                                if ($brand && $model && (int) ($model['brand_id'] ?? 0) > 0 && (int) $model['brand_id'] !== (int) $brand['id']) $r['errors'][] = 'Model does not belong to brand.';
                                if ($r['office'] !== '' && !$office) $r['errors'][] = 'Unknown office.';
                                if ($r['employee'] !== '' && !$employee) $r['errors'][] = 'Unknown employee.';
                                if ($r['responsibility_code'] !== '' && !$rc) $r['errors'][] = 'Unknown RC.';

                                if (!$office && $employee && !empty($employee['office_id'])) foreach ($offices as $off) if ((int) $off['id'] === (int) $employee['office_id']) { $office = $off; break; }
                                if ($office && !$employee) foreach ($employees as $emp) if ((int) ($emp['office_id'] ?? 0) === (int) $office['id'] && (int) ($emp['is_unit_head'] ?? 0) === 1) { $employee = $emp; break; }
                                if ($office && !$rc) foreach ($responsibilityCodes as $rcRow) if ((int) ($rcRow['office_id'] ?? 0) === (int) $office['id']) { $rc = $rcRow; break; }

                                if ($employee && $office && (int) ($employee['office_id'] ?? 0) !== (int) $office['id']) $r['errors'][] = 'Employee does not belong to office.';
                                if ($rc && $office && (int) ($rc['office_id'] ?? 0) !== (int) $office['id']) $r['errors'][] = 'RC does not belong to office.';

                                $dup = $db->prepare("SELECT id FROM legacy_assets WHERE property_number = ? LIMIT 1");
                                if ($dup) {
                                    $dup->bind_param('s', $r['property_number']);
                                    $dup->execute();
                                    if ($dup->get_result()->fetch_assoc()) $r['errors'][] = 'Property number already exists.';
                                    $dup->close();
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
                <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="preview">
                    <div class="col-md-8">
                        <label class="form-label">Legacy File</label>
                        <input type="file" name="legacy_file" class="form-control" accept=".csv,.xlsx" required>
                        <div class="form-text">Required headers: `property_number`, `inventory_type`, `description`. Optional: `fund` or `fund_number`, `classification`, `account_code`, `supplier`, `brand`, `model`, `serial_no`, `acquisition_date`, `quantity`, `unit_cost`, `office`, `employee`, `responsibility_code`, `condition_status`, `remarks`.</div>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Preview Import</button>
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
                                    <td><div class="fw-semibold"><?php echo h($row['item_description']); ?></div><small class="text-muted"><?php echo h($row['brand_name'] . ($row['model_name'] ? ' • ' . $row['model_name'] : '')); ?></small></td>
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
