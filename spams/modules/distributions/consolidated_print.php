<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$errors = [];
$printDate = trim((string) ($_GET['print_date'] ?? date('Y-m-d')));
$extraRows = max(0, min(35, (int) ($_GET['extra_rows'] ?? 0)));
$documentType = strtolower(trim((string) ($_GET['document_type'] ?? '')));
if (!in_array($documentType, ['ics', 'par'], true)) {
    $documentType = '';
}
$poId = (int) ($_GET['po_id'] ?? 0);
$officeId = (int) ($_GET['office_id'] ?? 0);
$employeeId = (int) ($_GET['employee_id'] ?? 0);

$rawDetailIds = $_GET['detail_ids'] ?? [];
if (!is_array($rawDetailIds)) {
    $rawDetailIds = explode(',', (string) $rawDetailIds);
}
$detailIds = array_values(array_unique(array_filter(array_map('intval', $rawDetailIds), static function ($id) {
    return $id > 0;
})));

$rawDistributionIds = $_GET['distribution_ids'] ?? ($_GET['ids'] ?? []);
if (!is_array($rawDistributionIds)) {
    $rawDistributionIds = explode(',', (string) $rawDistributionIds);
}
$distributionIds = array_values(array_unique(array_filter(array_map('intval', $rawDistributionIds), static function ($id) {
    return $id > 0;
})));

function consolidated_bind(mysqli_stmt $stmt, string $types, array $params): bool
{
    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

function consolidated_signatory_name(array $person): string
{
    $suffix = trim((string) ($person['suffix_name'] ?? ''));
    $middle = trim((string) ($person['middle_name'] ?? ''));
    $middleInitial = $middle !== '' ? strtoupper(substr(rtrim($middle, '.'), 0, 1)) . '.' : '';
    $parts = array_filter([
        trim((string) ($person['name_prefix'] ?? '')),
        trim((string) ($person['first_name'] ?? '')),
        $middleInitial,
        trim((string) ($person['last_name'] ?? '')),
    ]);
    $name = strtoupper(trim(implode(' ', $parts)));
    return $suffix !== '' ? $name . ', ' . $suffix : $name;
}

function consolidated_property_range(array $propertyNumbers): string
{
    $values = array_values(array_filter(array_unique(array_map('trim', $propertyNumbers)), static fn($value) => $value !== ''));
    sort($values);
    if (count($values) <= 1) {
        return $values[0] ?? '';
    }
    $first = $values[0];
    $last = $values[count($values) - 1];
    if (preg_match('/^(.*?)(\d+)$/', $first, $firstMatches) && preg_match('/^(.*?)(\d+)$/', $last, $lastMatches) && $firstMatches[1] === $lastMatches[1]) {
        return $first . ' to ' . $last;
    }
    return implode(', ', $values);
}

function consolidated_fetch_rows(mysqli $db, array $detailIds, array $distributionIds): array
{
    $where = ["d.status = 'posted'", "did.is_distributed = 1"];
    $params = [];
    $types = '';

    if ($detailIds) {
        $where[] = 'did.id IN (' . implode(',', array_fill(0, count($detailIds), '?')) . ')';
        $params = array_merge($params, $detailIds);
        $types .= str_repeat('i', count($detailIds));
    } elseif ($distributionIds) {
        $where[] = 'd.id IN (' . implode(',', array_fill(0, count($distributionIds), '?')) . ')';
        $params = array_merge($params, $distributionIds);
        $types .= str_repeat('i', count($distributionIds));
    } else {
        return [];
    }

    $sql = "
        SELECT d.id AS distribution_id, d.system_reference, d.document_type, d.document_no,
               d.distribution_date, d.office_id, d.employee_id, d.status,
               o.office_name, o.office_code,
               dep.name AS department_name,
               rc.code AS responsibility_center_code,
               e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title,
               po.id AS purchase_order_id, po.po_number, po.supplier_id, po.fund_id,
               s.supplier_name,
               f.fund_code, f.fund_source,
               poi.id AS purchase_order_item_id, poi.line_no, poi.item_description, poi.account_code_id, poi.classification_id, poi.unit_of_measure_id,
               ac.account_code, ac.account_name,
               c.classification_name, c.classification_family, c.useful_life_years,
               u.uom_name, u.abbreviation,
               ri.id AS receiving_item_id, r.received_date,
               di.id AS distribution_item_id, di.quantity_distributed, di.unit_cost,
               did.id AS detail_id, did.property_number, did.brand, did.model, did.serial_no
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN receivings r ON r.id = ri.receiving_id
        INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
        INNER JOIN suppliers s ON s.id = po.supplier_id
        INNER JOIN funds f ON f.id = po.fund_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        INNER JOIN offices o ON o.id = d.office_id
        LEFT JOIN departments dep ON dep.id = o.department_id
        LEFT JOIN responsibility_codes rc ON rc.id = (
            SELECT rc2.id FROM responsibility_codes rc2
            WHERE rc2.office_id = o.id AND rc2.is_active = 1
            ORDER BY rc2.id ASC LIMIT 1
        )
        LEFT JOIN employees e ON e.id = d.employee_id
        LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY d.document_no ASC, poi.line_no ASC, did.property_number ASC, did.id ASC
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($params) {
        consolidated_bind($stmt, $types, $params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

$poOptions = [];
$officeOptions = [];
$employeeOptions = [];
$candidateRows = [];

if ($db) {
    $poResult = $db->query("
        SELECT DISTINCT po.id, po.po_number, s.supplier_name, f.fund_code
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN receivings r ON r.id = ri.receiving_id
        INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
        INNER JOIN suppliers s ON s.id = po.supplier_id
        INNER JOIN funds f ON f.id = po.fund_id
        WHERE did.is_distributed = 1
        ORDER BY po.po_date DESC, po.id DESC
    ");
    if ($poResult) {
        $poOptions = $poResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($poId > 0 || $documentType !== '') {
        $filterWhere = ["d.status = 'posted'", "did.is_distributed = 1"];
        $filterParams = [];
        $filterTypes = '';
        if ($poId > 0) {
            $filterWhere[] = 'po.id = ?';
            $filterParams[] = $poId;
            $filterTypes .= 'i';
        }
        if ($documentType !== '') {
            $filterWhere[] = 'd.document_type = ?';
            $filterParams[] = $documentType;
            $filterTypes .= 's';
        }
        if ($officeId > 0) {
            $filterWhere[] = 'd.office_id = ?';
            $filterParams[] = $officeId;
            $filterTypes .= 'i';
        }
        if ($employeeId > 0) {
            $filterWhere[] = 'd.employee_id = ?';
            $filterParams[] = $employeeId;
            $filterTypes .= 'i';
        }

        $candidateSql = "
            SELECT did.id AS detail_id, did.property_number, did.brand, did.model, did.serial_no,
                   d.document_no, d.document_type, d.distribution_date, d.office_id, d.employee_id,
                   o.office_name,
                   e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name,
                   po.id AS purchase_order_id, po.po_number,
                   poi.line_no, poi.item_description,
                   c.classification_name, ac.account_code,
                   u.abbreviation, u.uom_name,
                   di.unit_cost,
                   r.received_date
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN receivings r ON r.id = ri.receiving_id
            INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            INNER JOIN offices o ON o.id = d.office_id
            LEFT JOIN employees e ON e.id = d.employee_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
            LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
            WHERE " . implode(' AND ', $filterWhere) . "
            ORDER BY poi.line_no ASC, d.distribution_date ASC, did.property_number ASC, did.id ASC
        ";
        $candidateStmt = $db->prepare($candidateSql);
        if ($candidateStmt) {
            if ($filterParams) {
                consolidated_bind($candidateStmt, $filterTypes, $filterParams);
            }
            $candidateStmt->execute();
            $candidateResult = $candidateStmt->get_result();
            while ($candidateResult && ($row = $candidateResult->fetch_assoc())) {
                $candidateRows[] = $row;
                $officeOptions[(int) $row['office_id']] = $row['office_name'];
                $employeeName = trim(employee_display_name($row));
                $employeeOptions[(int) ($row['employee_id'] ?? 0)] = $employeeName !== '' ? $employeeName : 'No employee';
            }
            $candidateStmt->close();
        }
    }
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
}
if ($printDate === '' || !is_valid_date_string($printDate)) {
    $errors[] = 'Choose a valid acceptance/print date.';
}

$selectionMode = !$detailIds && !$distributionIds;
$rows = [];
$header = [];
$printItems = [];
$documentNumbers = [];
$sourceReferences = [];

if (!$selectionMode && !$errors) {
    $rows = consolidated_fetch_rows($db, $detailIds, $distributionIds);
    if (!$rows) {
        $errors[] = 'No posted distributed asset/item rows were found.';
    }
}

if (!$selectionMode && !$errors) {
    $distinct = static function (array $rows, string $key): array {
        return array_values(array_unique(array_map(static fn($row) => (string) ($row[$key] ?? ''), $rows)));
    };
    foreach ([
        'document_type' => 'same document type',
        'office_id' => 'same office',
        'employee_id' => 'same employee/accountable person',
        'purchase_order_id' => 'same PO',
        'supplier_id' => 'same supplier',
        'fund_id' => 'same fund',
    ] as $key => $label) {
        if (count($distinct($rows, $key)) > 1) {
            $errors[] = 'Selected rows must have the ' . $label . '.';
        }
    }
}

if (!$selectionMode && !$errors) {
    $header = $rows[0];
    foreach ($rows as $row) {
        $documentNumbers[(int) $row['distribution_id']] = (string) $row['document_no'];
        $sourceReferences[(int) $row['distribution_id']] = (string) $row['system_reference'];
        $unitLabel = trim((string) ($row['abbreviation'] ?? $row['uom_name'] ?? ''));
        $groupKey = implode('|', [
            (string) ($row['purchase_order_item_id'] ?? ''),
            (string) ($row['classification_id'] ?? ''),
            (string) ($row['account_code_id'] ?? ''),
            $unitLabel,
            trim((string) ($row['item_description'] ?? '')),
            number_format((float) ($row['unit_cost'] ?? 0), 2, '.', ''),
        ]);
        if (!isset($printItems[$groupKey])) {
            $printItems[$groupKey] = [
                'quantity_distributed' => 0.0,
                'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                'line_total' => 0.0,
                'item_description' => (string) ($row['item_description'] ?? ''),
                'classification_name' => (string) ($row['classification_name'] ?? ''),
                'classification_family' => (string) ($row['classification_family'] ?? ''),
                'uom_name' => (string) ($row['uom_name'] ?? ''),
                'abbreviation' => (string) ($row['abbreviation'] ?? ''),
                'useful_life_years' => $row['useful_life_years'] ?? null,
                'received_dates' => [],
                'property_numbers' => [],
                'details' => [],
            ];
        }
        $printItems[$groupKey]['quantity_distributed'] += 1;
        $printItems[$groupKey]['line_total'] += (float) ($row['unit_cost'] ?? 0);
        if (!empty($row['received_date'])) {
            $printItems[$groupKey]['received_dates'][] = (string) $row['received_date'];
        }
        if (!empty($row['property_number'])) {
            $printItems[$groupKey]['property_numbers'][] = (string) $row['property_number'];
        }
        $printItems[$groupKey]['details'][] = [
            'brand' => (string) ($row['brand'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'serial_no' => (string) ($row['serial_no'] ?? ''),
            'property_number' => (string) ($row['property_number'] ?? ''),
        ];
    }
    foreach ($printItems as &$item) {
        $item['property_numbers'] = array_values(array_unique($item['property_numbers']));
        sort($item['property_numbers']);
        $item['property_number_range'] = consolidated_property_range($item['property_numbers']);
        $item['received_dates'] = array_values(array_unique($item['received_dates']));
        sort($item['received_dates']);
        $item['date_acquired_label'] = count($item['received_dates']) === 1 ? format_date($item['received_dates'][0], 'm/d/Y') : 'Various';
    }
    unset($item);
    $printItems = array_values($printItems);
}

$docType = strtolower((string) ($header['document_type'] ?? ($documentType ?: 'ics')));
$isPar = $docType === 'par';
$docLabel = $isPar ? 'PAR' : 'ICS';
$docTitle = $isPar ? 'Property Acknowledgment Receipt' : 'Inventory Custodian Slip';
$documentNoDisplay = implode(', ', array_values(array_unique($documentNumbers)));
$sourceRefDisplay = implode(', ', array_values(array_unique($sourceReferences)));
$fundCluster = trim((string) ($header['fund_source'] ?? ''));
if ($fundCluster === '') {
    $fundCluster = trim((string) ($header['fund_code'] ?? ''));
}
if (preg_match('/(?:^|[^0-9])(0[1567])(?:[^0-9]|$)/', $fundCluster, $matches)) {
    $fundCluster = $matches[1];
} elseif (preg_match('/([0-9]{2})/', $fundCluster, $matches)) {
    $fundCluster = $matches[1];
}

$recipientHead = (!$selectionMode && !$errors) ? employee_resolve_office_head($db, (int) ($header['office_id'] ?? 0)) : [];
$supplyHead = (!$selectionMode && !$errors) ? employee_resolve_supply_office_head($db) : [];
$recipientName = $recipientHead ? consolidated_signatory_name($recipientHead) : consolidated_signatory_name($header);
$recipientTitle = trim((string) (($recipientHead['position_title'] ?? '') ?: ($header['position_title'] ?? '')));
$recipientOfficeName = trim((string) ($header['office_name'] ?? ''));
$supplyName = $supplyHead ? consolidated_signatory_name($supplyHead) : '';
$supplyTitle = trim((string) ($supplyHead['position_title'] ?? ''));
$supplyOfficeName = trim((string) ($supplyHead['office_name'] ?? 'Supply Office'));

$identityLines = static function (array $item): array {
    $lines = [];
    foreach ((array) ($item['details'] ?? []) as $detail) {
        $parts = [];
        foreach (['brand' => 'Brand', 'model' => 'Model', 'serial_no' => 'Serial'] as $key => $label) {
            $value = trim((string) ($detail[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }
        if ($parts) {
            $lines[] = implode(' | ', $parts);
        }
    }
    return array_values(array_unique($lines));
};
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo $selectionMode ? 'Consolidated Acceptance' : 'Consolidated ' . h($docLabel); ?></title>
    <style>
        @page { size: 8.5in 13in; margin: 0.5in; }
        body { margin:0; color:#000; font-family:"Times New Roman", serif; font-size:12px; }
        table { font-size:12px; }
        .screen { font-family:Arial, sans-serif; max-width:1200px; margin:18px auto; padding:0 16px; }
        .toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:end; margin:12px 0; font-family:Arial, sans-serif; }
        .toolbar a,.toolbar button,.btn { border:1px solid #0d6efd; background:#fff; color:#0d6efd; padding:7px 11px; text-decoration:none; border-radius:4px; cursor:pointer; }
        .toolbar button,.btn-primary { background:#0d6efd; color:#fff; }
        .field { display:flex; flex-direction:column; gap:4px; }
        .field label { font-size:11px; color:#555; }
        input,select { border:1px solid #bbb; border-radius:4px; padding:7px 9px; }
        .notice { border:1px solid #ddd; padding:10px; margin:12px 0; font-family:Arial, sans-serif; color:#444; }
        .table { width:100%; border-collapse:collapse; font-family:Arial, sans-serif; }
        .table th,.table td { border-bottom:1px solid #e5e5e5; padding:8px; text-align:left; vertical-align:top; }
        .table th { background:#f8f9fa; font-size:12px; }
        .muted { color:#666; }
        .form-wrap { position:relative; }
        .appendix { position:absolute; right:0; top:0; font-style:italic; font-size:12px; }
        .title { text-align:center; font-weight:bold; font-size:16px; text-transform:uppercase; margin:18px 0 22px; }
        .line-value { display:inline-block; border-bottom:1px solid #000; min-width:150px; padding:0 2px; line-height:1.1; }
        .line-value.long { min-width:220px; }
        .meta,.items,.sign { width:100%; border-collapse:collapse; }
        .meta td { padding:2px 4px; vertical-align:bottom; }
        .meta .label { font-weight:bold; white-space:nowrap; }
        .items th,.items td,.sign th,.sign td { border:1px solid #000; padding:3px 4px; vertical-align:top; }
        .items th { text-align:center; font-weight:bold; }
        .items tbody td { height:24px; }
        .sign .sign-head { text-align:left; font-weight:bold; }
        .sign .sign-box { height:74px; text-align:center; vertical-align:top; font-size:10px; padding-top:8px; }
        .sign .sign-name { font-weight:700; font-size:14px; letter-spacing:0.2px; line-height:1.1; margin:24px 0 0; }
        .sign .meta-box { height:52px; text-align:center; vertical-align:top; padding-top:6px; }
        .sign .meta-value { margin:10px 0 0; font-size:12px; line-height:1.15; }
        .sign .meta-caption { text-align:center; font-size:11px; }
        .underlined-value { display:inline-block; border-bottom:1px solid #000; padding:0 8px 1px; min-width:82%; }
        .meta-box .underlined-value { min-width:68%; }
        .text-end { text-align:right; }
        .small { font-size:10px; }
        @media print {
            .no-print,.no-print *,.screen { display:none !important; }
            thead { display:table-header-group; }
        }
    
            <?php echo print_page_number_css(); ?></style>
</head>
<body>
<?php if ($selectionMode): ?>
    <div class="screen">
        <h2>Consolidated Acceptance Print</h2>
        <p class="muted">Choose a PO and select the already distributed asset/item rows to include in one combined ICS/PAR print.</p>
        <?php if ($errors): ?><div class="notice"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
        <form method="get" class="toolbar">
            <div class="field">
                <label for="po_id">PO</label>
                <select id="po_id" name="po_id" required>
                    <option value="">Select PO</option>
                    <?php foreach ($poOptions as $po): ?>
                        <option value="<?php echo (int) $po['id']; ?>" <?php echo $poId === (int) $po['id'] ? 'selected' : ''; ?>>
                            <?php echo h(trim(($po['po_number'] ?? '') . ' - ' . ($po['supplier_name'] ?? '') . ' ' . ($po['fund_code'] ?? ''))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="document_type">Document type</label>
                <select id="document_type" name="document_type">
                    <option value="">All</option>
                    <option value="ics" <?php echo $documentType === 'ics' ? 'selected' : ''; ?>>ICS</option>
                    <option value="par" <?php echo $documentType === 'par' ? 'selected' : ''; ?>>PAR</option>
                </select>
            </div>
            <div class="field">
                <label for="office_id">Office</label>
                <select id="office_id" name="office_id">
                    <option value="">All offices</option>
                    <?php foreach ($officeOptions as $id => $name): ?>
                        <option value="<?php echo (int) $id; ?>" <?php echo $officeId === (int) $id ? 'selected' : ''; ?>><?php echo h($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="employee_id">Employee</label>
                <select id="employee_id" name="employee_id">
                    <option value="">All employees</option>
                    <?php foreach ($employeeOptions as $id => $name): ?>
                        <?php if ($id <= 0) continue; ?>
                        <option value="<?php echo (int) $id; ?>" <?php echo $employeeId === (int) $id ? 'selected' : ''; ?>><?php echo h($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="print_date">Acceptance / print date</label>
                <input type="date" id="print_date" name="print_date" value="<?php echo h($printDate); ?>" required>
            </div>
            <button type="submit">Load Distributed Items</button>
        </form>

        <?php if ($candidateRows): ?>
            <form method="get" target="_blank">
                <input type="hidden" name="print_date" value="<?php echo h($printDate); ?>">
                <div class="toolbar">
                    <div class="field">
                        <label for="extra_rows">Extra blank rows</label>
                        <input type="number" id="extra_rows" name="extra_rows" value="<?php echo (int) $extraRows; ?>" min="0" max="35" step="1" style="width:110px;">
                    </div>
                    <button type="submit" class="btn-primary">Print Selected Items</button>
                    <span class="muted">Selections must still have the same document type, office, employee, PO, supplier, and fund.</span>
                </div>
                <table class="table">
                    <thead>
                    <tr>
                        <th><input type="checkbox" onclick="document.querySelectorAll('.detail-check').forEach(function(c){c.checked = event.target.checked;});"></th>
                        <th>PO Item</th>
                        <th>Property No.</th>
                        <th>Document</th>
                        <th>Office / Employee</th>
                        <th>Received</th>
                        <th>Cost</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($candidateRows as $row): ?>
                        <tr>
                            <td><input class="detail-check" type="checkbox" name="detail_ids[]" value="<?php echo (int) $row['detail_id']; ?>"></td>
                            <td>
                                <strong><?php echo h(($row['classification_name'] ? $row['classification_name'] . ' - ' : '') . $row['item_description']); ?></strong>
                                <div class="muted"><?php echo h(trim('Line ' . ($row['line_no'] ?? '') . ' ' . ($row['account_code'] ?? ''))); ?></div>
                            </td>
                            <td>
                                <?php echo h((string) ($row['property_number'] ?? '')); ?>
                                <div class="muted"><?php echo h(trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? '') . ' ' . ($row['serial_no'] ?? ''))); ?></div>
                            </td>
                            <td><?php echo h(strtoupper((string) $row['document_type']) . ' ' . $row['document_no']); ?><div class="muted"><?php echo h(format_date($row['distribution_date'] ?? null)); ?></div></td>
                            <td><?php echo h((string) $row['office_name']); ?><div class="muted"><?php echo h(employee_display_name($row)); ?></div></td>
                            <td><?php echo h(format_date($row['received_date'] ?? null)); ?></td>
                            <td><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php elseif ($poId > 0 || $documentType !== ''): ?>
            <div class="notice">No already distributed items matched the selected filters.</div>
        <?php endif; ?>
    </div>
<?php elseif ($errors): ?>
    <div class="screen">
        <div class="notice"><strong>Cannot create consolidated print.</strong><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
        <a class="btn" href="<?php echo h(base_url('modules/distributions/consolidated_print.php')); ?>">Back to selection</a>
    </div>
<?php else: ?>
    <div class="toolbar no-print">
        <a href="<?php echo h(base_url('modules/distributions/consolidated_print.php?po_id=' . (int) ($header['purchase_order_id'] ?? 0) . '&document_type=' . urlencode($docType) . '&office_id=' . (int) ($header['office_id'] ?? 0) . '&employee_id=' . (int) ($header['employee_id'] ?? 0) . '&print_date=' . urlencode($printDate))); ?>">Back</a>
        <button type="button" onclick="window.print()">Print</button>
        <form method="get" class="toolbar" style="margin:0;">
            <?php foreach ($detailIds as $detailId): ?><input type="hidden" name="detail_ids[]" value="<?php echo (int) $detailId; ?>"><?php endforeach; ?>
            <?php foreach ($distributionIds as $distributionId): ?><input type="hidden" name="distribution_ids[]" value="<?php echo (int) $distributionId; ?>"><?php endforeach; ?>
            <input type="hidden" name="print_date" value="<?php echo h($printDate); ?>">
            <div class="field">
                <label for="extra_rows_print">Add blank rows</label>
                <input type="number" id="extra_rows_print" name="extra_rows" value="<?php echo (int) $extraRows; ?>" min="0" max="35" step="1" style="width:100px;">
            </div>
            <button type="submit">Apply Rows</button>
        </form>
    </div>
    <div class="notice no-print">Consolidated acceptance print only. Original receiving and distribution records remain unchanged. Source documents: <?php echo h($documentNoDisplay); ?></div>
    <div class="form-wrap">
        <div class="appendix"><?php echo $isPar ? 'Appendix 71' : 'Annex A.3'; ?></div>
        <div class="title"><?php echo h($docTitle); ?></div>
        <table class="meta">
            <tr>
                <td style="width:14%;" class="label">Entity Name :</td>
                <td style="width:39%;"><span class="line-value long"><?php echo h(APP_NAME); ?></span></td>
                <td style="width:14%;" class="label"><?php echo h($docLabel); ?> No. :</td>
                <td style="width:33%;"><span class="line-value"><?php echo h($documentNoDisplay); ?></span></td>
            </tr>
            <tr>
                <td class="label">Fund Cluster :</td>
                <td><span class="line-value long"><?php echo h($fundCluster); ?></span></td>
                <td class="label">PO No. :</td>
                <td><span class="line-value"><?php echo h((string) ($header['po_number'] ?? '')); ?></span></td>
            </tr>
        </table>
        <?php if ($isPar): ?>
            <table class="items">
                <thead><tr><th style="width:9%">Quantity</th><th style="width:9%">Unit</th><th style="width:34%">Description</th><th style="width:18%">Property Number</th><th style="width:14%">Date Acquired</th><th style="width:16%">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($printItems as $item):
                    $qty = (float) ($item['quantity_distributed'] ?? 0);
                    $amount = (float) ($item['line_total'] ?? 0);
                    $description = trim(trim((string) ($item['classification_name'] ?? '')) . ' - ' . trim((string) ($item['item_description'] ?? '')), ' -');
                ?>
                    <tr><td class="text-end"><?php echo h(format_quantity($qty)); ?></td><td><?php echo h(trim((string) ($item['abbreviation'] ?: $item['uom_name']))); ?></td><td><?php echo nl2br(h($description)); ?><?php foreach ($identityLines($item) as $line): ?><br><span class="small"><?php echo h($line); ?></span><?php endforeach; ?></td><td><?php echo h((string) ($item['property_number_range'] ?? '')); ?></td><td><?php echo h((string) ($item['date_acquired_label'] ?? '')); ?></td><td class="text-end"><?php echo h(number_format($amount, 2)); ?></td></tr>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < $extraRows; $i++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table class="items">
                <thead><tr><th rowspan="2" style="width:8%">Quanti<br>ty</th><th rowspan="2" style="width:8%">Unit</th><th colspan="2" style="width:16%">Amount</th><th rowspan="2" style="width:34%">Description</th><th rowspan="2" style="width:18%">Inventory Item No.</th><th rowspan="2" style="width:16%">Estimated Useful Life</th></tr><tr><th>Unit Cost</th><th>Total Cost</th></tr></thead>
                <tbody>
                <?php foreach ($printItems as $item):
                    $qty = (float) ($item['quantity_distributed'] ?? 0);
                    $unitCost = (float) ($item['unit_cost'] ?? 0);
                    $amount = (float) ($item['line_total'] ?? 0);
                    $description = trim(trim((string) ($item['classification_name'] ?? '')) . ' - ' . trim((string) ($item['item_description'] ?? '')), ' -');
                    $useful = !empty($item['useful_life_years']) ? ((int) $item['useful_life_years']) . ' yr' . ((int) $item['useful_life_years'] > 1 ? 's' : '') : '';
                ?>
                    <tr><td class="text-end"><?php echo h(format_quantity($qty)); ?></td><td><?php echo h(trim((string) ($item['abbreviation'] ?: $item['uom_name']))); ?></td><td class="text-end"><?php echo h(number_format($unitCost, 2)); ?></td><td class="text-end"><?php echo h(number_format($amount, 2)); ?></td><td><?php echo nl2br(h($description)); ?><?php foreach ($identityLines($item) as $line): ?><br><span class="small"><?php echo h($line); ?></span><?php endforeach; ?></td><td><?php echo h((string) ($item['property_number_range'] ?? '')); ?></td><td><?php echo h($useful); ?></td></tr>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < $extraRows; $i++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <table class="sign">
            <tr><?php if ($isPar): ?><th class="sign-head" style="width:50%;">Received by:</th><th class="sign-head" style="width:50%;">Issued by:</th><?php else: ?><th class="sign-head" style="width:50%;">Received from:</th><th class="sign-head" style="width:50%;">Received by:</th><?php endif; ?></tr>
            <tr><?php if ($isPar): ?><td class="sign-box"><div class="sign-name"><span class="underlined-value"><?php echo h($recipientName); ?></span></div><div>Signature over Printed Name of End User</div></td><td class="sign-box"><div class="sign-name"><span class="underlined-value"><?php echo h($supplyName); ?></span></div><div>Signature over Printed Name of Supply and/or Property Custodian</div></td><?php else: ?><td class="sign-box"><div class="sign-name"><span class="underlined-value"><?php echo h($supplyName); ?></span></div><div>Signature Over Printed Name</div></td><td class="sign-box"><div class="sign-name"><span class="underlined-value"><?php echo h($recipientName); ?></span></div><div>Signature Over Printed Name</div></td><?php endif; ?></tr>
            <tr><?php if ($isPar): ?><td class="meta-box"><div class="meta-value"><span class="underlined-value"><?php echo h(trim($recipientTitle . ($recipientOfficeName !== '' ? ' / ' . $recipientOfficeName : ''))); ?></span></div><div class="meta-caption">Designation/Office</div></td><td class="meta-box"><div class="meta-value"><span class="underlined-value"><?php echo h(trim($supplyTitle . ($supplyOfficeName !== '' ? ' / ' . $supplyOfficeName : ''))); ?></span></div><div class="meta-caption">Designation/Office</div></td><?php else: ?><td class="meta-box"><div class="meta-value"><span class="underlined-value"><?php echo h(trim($supplyTitle . ($supplyOfficeName !== '' ? ' / ' . $supplyOfficeName : ''))); ?></span></div><div class="meta-caption">Designation/Office</div></td><td class="meta-box"><div class="meta-value"><span class="underlined-value"><?php echo h(trim($recipientTitle . ($recipientOfficeName !== '' ? ' / ' . $recipientOfficeName : ''))); ?></span></div><div class="meta-caption">Designation/Office</div></td><?php endif; ?></tr>
            <tr><td class="meta-box"><div class="meta-value"><span class="underlined-value"><?php echo h(format_date($printDate, 'm/d/Y')); ?></span></div><div class="meta-caption">Date</div></td><td class="meta-box"><div class="meta-value"><span class="underlined-value"><?php echo h(format_date($printDate, 'm/d/Y')); ?></span></div><div class="meta-caption">Date</div></td></tr>
        </table>
    </div>
<?php endif; ?>

<?php render_print_page_number(); ?></body>
</html>
