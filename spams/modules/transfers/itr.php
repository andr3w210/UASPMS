<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/employee_assignments.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$transferId = (int) ($_GET['id'] ?? 0);
$batchId = (int) ($_GET['batch_id'] ?? 0);
$printFormat = (($_GET['print_format'] ?? 'long') === 'short') ? 'short' : 'long';
$isShort = $printFormat === 'short';
$viewMode = (($_GET['view_mode'] ?? 'grouped') === 'detailed') ? 'detailed' : 'grouped';
$isGrouped = $viewMode === 'grouped';
$extraRows = max(0, min(50, (int) ($_GET['extra_rows'] ?? 0)));

if (!$db || ($transferId <= 0 && $batchId <= 0)) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

function itr_name(array $row, string $prefix): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row[$prefix . 'first_name'] ?? '')),
        trim((string) ($row[$prefix . 'middle_name'] ?? '')),
        trim((string) ($row[$prefix . 'last_name'] ?? '')),
        trim((string) ($row[$prefix . 'suffix_name'] ?? '')),
    ])));
}

function itr_signatory_name(array $row, string $prefix = ''): string
{
    $namePrefix = trim((string) ($row[$prefix . 'name_prefix'] ?? ''));
    $firstName = trim((string) ($row[$prefix . 'first_name'] ?? ''));
    $middleName = trim((string) ($row[$prefix . 'middle_name'] ?? ''));
    $lastName = trim((string) ($row[$prefix . 'last_name'] ?? ''));
    $suffixName = trim((string) ($row[$prefix . 'suffix_name'] ?? ''));
    $middleInitial = $middleName !== '' ? strtoupper(substr($middleName, 0, 1)) . '.' : '';

    $name = trim(implode(' ', array_filter([
        $namePrefix,
        $firstName,
        $middleInitial,
        $lastName,
    ])));

    if ($suffixName !== '') {
        $name .= ', ' . $suffixName;
    }

    return $name;
}

function itr_format_signatory_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return '';
    }

    if (str_contains($name, ',')) {
        [$lastName, $givenNames] = array_pad(array_map('trim', explode(',', $name, 2)), 2, '');
        $name = trim($givenNames . ' ' . $lastName);
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    if (count($parts) < 3) {
        return $name;
    }

    $suffixName = '';
    $lastPart = (string) end($parts);
    if (preg_match('/^(jr\.?|sr\.?|ii|iii|iv|v)$/i', $lastPart)) {
        $suffixName = (string) array_pop($parts);
    }

    $firstName = (string) array_shift($parts);
    $lastName = (string) array_pop($parts);
    $middleName = trim(implode(' ', $parts));
    $middleInitial = $middleName !== '' ? strtoupper(substr($middleName, 0, 1)) . '.' : '';
    $formattedName = trim(implode(' ', array_filter([$firstName, $middleInitial, $lastName])));

    if ($suffixName !== '') {
        $formattedName .= ', ' . $suffixName;
    }

    return $formattedName;
}

function itr_transfer_type_options(): array
{
    return [
        'donation' => 'Donation',
        'relocate' => 'Relocate',
        'reassignment' => 'Reassignment',
        'others' => 'Others',
    ];
}

function itr_normalize_transfer_type(string $transferType): string
{
    $transferType = strtolower(trim($transferType));
    return array_key_exists($transferType, itr_transfer_type_options()) ? $transferType : '';
}

function itr_reason_flags(string $reasonText, string $transferType = ''): array
{
    $transferType = itr_normalize_transfer_type($transferType);
    if ($transferType !== '') {
        return [
            'donation' => $transferType === 'donation',
            'relocate' => $transferType === 'relocate',
            'reassignment' => $transferType === 'reassignment',
            'others' => $transferType === 'others',
        ];
    }

    $reasonNormalized = strtolower(trim($reasonText));
    $isDonation = str_contains($reasonNormalized, 'donation');
    $isRelocate = str_contains($reasonNormalized, 'relocate');
    $isReassignment = str_contains($reasonNormalized, 'reassignment');

    return [
        'donation' => $isDonation,
        'relocate' => $isRelocate,
        'reassignment' => $isReassignment,
        'others' => !$isDonation && !$isRelocate && !$isReassignment,
    ];
}

function itr_item_description(array $row): string
{
    $classificationPrefix = trim((string) ($row['classification_name'] ?: $row['classification_family'] ?: ''));
    return trim(($classificationPrefix !== '' ? $classificationPrefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

function itr_item_meta(array $row): string
{
    return trim(implode(' | ', array_filter([
        trim(trim((string) ($row['brand'] ?? '')) . ' ' . trim((string) ($row['model'] ?? ''))),
        !empty($row['serial_no']) ? 'SN ' . $row['serial_no'] : null,
    ])));
}

function itr_build_reference_range(array $values): string
{
    $values = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $values), static function (string $value): bool {
        return $value !== '';
    })));

    if (!$values) {
        return '';
    }

    sort($values);

    if (count($values) === 1) {
        return $values[0];
    }

    $first = $values[0];
    $last = $values[count($values) - 1];
    if ($first === $last) {
        return $first;
    }

    if (preg_match('/^(.*?)(\d+)$/', $first, $firstMatches) && preg_match('/^(.*?)(\d+)$/', $last, $lastMatches) && $firstMatches[1] === $lastMatches[1]) {
        return $first . ' to ' . $last;
    }

    return $first . ' to ' . $last;
}

function itr_apply_batch_from_fallback(mysqli $db, int $batchId, array &$header): void
{
    if ($batchId <= 0 || itr_name($header, 'from_') !== '') {
        return;
    }

    $stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT at.from_employee_id) AS employee_count,
            MAX(e.name_prefix) AS name_prefix,
            MAX(e.first_name) AS first_name,
            MAX(e.middle_name) AS middle_name,
            MAX(e.last_name) AS last_name,
            MAX(e.suffix_name) AS suffix_name,
            MAX(e.position_title) AS position_title
        FROM transfer_batch_items tbi
        INNER JOIN asset_transfers at ON at.id = tbi.asset_transfer_id
        LEFT JOIN employees e ON e.id = at.from_employee_id
        WHERE tbi.batch_id = ?
          AND at.from_employee_id IS NOT NULL
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if ((int) ($row['employee_count'] ?? 0) !== 1) {
        return;
    }

    $header['from_first_name'] = (string) ($row['first_name'] ?? '');
    $header['from_name_prefix'] = (string) ($row['name_prefix'] ?? '');
    $header['from_middle_name'] = (string) ($row['middle_name'] ?? '');
    $header['from_last_name'] = (string) ($row['last_name'] ?? '');
    $header['from_suffix_name'] = (string) ($row['suffix_name'] ?? '');
    $header['from_position_title'] = (string) ($row['position_title'] ?? '');
}

function itr_is_external_government_office(array $header): bool
{
    $officeText = strtolower(trim(implode(' ', array_filter([
        (string) ($header['to_office_code'] ?? ''),
        (string) ($header['to_office_name'] ?? ''),
        (string) ($header['to_office_description'] ?? ''),
    ]))));

    if ($officeText === '') {
        return false;
    }

    if (preg_match('/\b(external|outside|non[- ]?ua|government|agency|province|provincial|municipal|municipality|city|barangay|department of|bureau of|commission on|office of the governor|deped|ched|dilg|dost|doh|dpwh|denr|dti|coa|dbm|dof|pnp|bfp|bjmp)\b/i', $officeText)) {
        return true;
    }

    return false;
}

function itr_approval_profile(mysqli $db, array $header): array
{
    if (itr_is_external_government_office($header)) {
        $president = get_university_president_profile($db);
        return [
            'name' => itr_format_signatory_name((string) ($president['name'] ?? '')),
            'title' => trim((string) ($president['title'] ?? 'University President')),
        ];
    }

    $supplyHead = employee_resolve_supply_office_head($db);

    return [
        'name' => !empty($supplyHead) ? itr_signatory_name($supplyHead) : '',
        'title' => trim((string) ($supplyHead['position_title'] ?? 'Supply Officer')),
    ];
}

$header = null;
$items = [];
$batchTransferTypeSelect = schema_has_column($db, 'transfer_batches', 'transfer_type') ? 'tb.transfer_type' : "''";
$assetTransferTypeSelect = schema_has_column($db, 'asset_transfers', 'transfer_type') ? 'at.transfer_type' : "''";

if ($batchId > 0) {
    $stmt = $db->prepare("
        SELECT
            tb.id,
            tb.system_reference,
            tb.transfer_date,
            {$batchTransferTypeSelect} AS transfer_type,
            tb.reason,
            tb.remarks,
            from_o.office_name AS from_office_name,
            to_o.office_name AS to_office_name,
            to_o.office_code AS to_office_code,
            to_o.description AS to_office_description,
            from_e.first_name AS from_first_name,
            from_e.name_prefix AS from_name_prefix,
            from_e.middle_name AS from_middle_name,
            from_e.last_name AS from_last_name,
            from_e.suffix_name AS from_suffix_name,
            from_e.position_title AS from_position_title,
            to_e.first_name AS to_first_name,
            to_e.name_prefix AS to_name_prefix,
            to_e.middle_name AS to_middle_name,
            to_e.last_name AS to_last_name,
            to_e.suffix_name AS to_suffix_name,
            to_e.position_title AS to_position_title
        FROM transfer_batches tb
        LEFT JOIN offices from_o ON from_o.id = tb.source_office_id
        LEFT JOIN offices to_o ON to_o.id = tb.to_office_id
        LEFT JOIN employees from_e ON from_e.id = tb.source_employee_id
        LEFT JOIN employees to_e ON to_e.id = tb.to_employee_id
        WHERE tb.id = ?
          AND tb.status = 'posted'
          AND tb.document_type = 'itr'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    if ($header) {
        itr_apply_batch_from_fallback($db, $batchId, $header);
        $stmt = $db->prepare("
            SELECT
                at.property_number,
                CASE WHEN at.source_type = 'system' THEN poi.item_description ELSE la.item_description END AS item_description,
                CASE WHEN at.source_type = 'system' THEN poi.item_type ELSE la.item_type END AS item_type,
                CASE WHEN at.source_type = 'system' THEN did.brand ELSE la.brand END AS brand,
                CASE WHEN at.source_type = 'system' THEN did.model ELSE la.model END AS model,
                CASE WHEN at.source_type = 'system' THEN did.serial_no ELSE la.serial_no END AS serial_no,
                CASE WHEN at.source_type = 'system' THEN ri.unit_cost ELSE la.unit_cost END AS amount,
                CASE WHEN at.source_type = 'system' THEN r.received_date ELSE la.acquisition_date END AS date_acquired,
                CASE WHEN at.source_type = 'system' THEN f.fund_code ELSE '' END AS fund_code,
                CASE WHEN at.source_type = 'system' THEN d.system_reference ELSE '' END AS ics_no,
                CASE WHEN at.source_type = 'system' THEN d.distribution_date ELSE la.acquisition_date END AS ics_date,
                CASE WHEN at.source_type = 'system' THEN c.classification_name ELSE lc.classification_name END AS classification_name,
                CASE WHEN at.source_type = 'system' THEN c.classification_family ELSE lc.classification_family END AS classification_family
            FROM transfer_batch_items tbi
            INNER JOIN asset_transfers at ON at.id = tbi.asset_transfer_id
            LEFT JOIN distribution_item_details did ON did.id = at.distribution_item_detail_id
            LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
            LEFT JOIN distributions d ON d.id = di.distribution_id
            LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
            LEFT JOIN receivings r ON r.id = ri.receiving_id
            LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
            LEFT JOIN funds f ON f.id = po.fund_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN legacy_assets la ON la.id = at.legacy_asset_id
            LEFT JOIN classifications lc ON lc.id = la.classification_id
            WHERE tbi.batch_id = ?
            ORDER BY at.property_number ASC, at.id ASC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $batchId);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
} else {
    $stmt = $db->prepare("
        SELECT
            at.id,
            at.system_reference,
            at.transfer_date,
            at.source_type,
            at.property_number,
            {$assetTransferTypeSelect} AS transfer_type,
            at.reason,
            at.remarks,
            from_o.office_name AS from_office_name,
            to_o.office_name AS to_office_name,
            to_o.office_code AS to_office_code,
            to_o.description AS to_office_description,
            from_e.first_name AS from_first_name,
            from_e.name_prefix AS from_name_prefix,
            from_e.middle_name AS from_middle_name,
            from_e.last_name AS from_last_name,
            from_e.suffix_name AS from_suffix_name,
            from_e.position_title AS from_position_title,
            to_e.first_name AS to_first_name,
            to_e.name_prefix AS to_name_prefix,
            to_e.middle_name AS to_middle_name,
            to_e.last_name AS to_last_name,
            to_e.suffix_name AS to_suffix_name,
            to_e.position_title AS to_position_title,
            CASE WHEN at.source_type = 'system' THEN poi.item_description ELSE la.item_description END AS item_description,
            CASE WHEN at.source_type = 'system' THEN poi.item_type ELSE la.item_type END AS item_type,
            CASE WHEN at.source_type = 'system' THEN did.brand ELSE la.brand END AS brand,
            CASE WHEN at.source_type = 'system' THEN did.model ELSE la.model END AS model,
            CASE WHEN at.source_type = 'system' THEN did.serial_no ELSE la.serial_no END AS serial_no,
            CASE WHEN at.source_type = 'system' THEN ri.unit_cost ELSE la.unit_cost END AS amount,
            CASE WHEN at.source_type = 'system' THEN r.received_date ELSE la.acquisition_date END AS date_acquired,
            CASE WHEN at.source_type = 'system' THEN d.system_reference ELSE '' END AS ics_no,
            CASE WHEN at.source_type = 'system' THEN d.distribution_date ELSE la.acquisition_date END AS ics_date,
            CASE WHEN at.source_type = 'system' THEN f.fund_code ELSE '' END AS fund_code,
            CASE WHEN at.source_type = 'system' THEN c.classification_name ELSE lc.classification_name END AS classification_name,
            CASE WHEN at.source_type = 'system' THEN c.classification_family ELSE lc.classification_family END AS classification_family
        FROM asset_transfers at
        LEFT JOIN offices from_o ON from_o.id = at.from_office_id
        LEFT JOIN offices to_o ON to_o.id = at.to_office_id
        LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
        LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
        LEFT JOIN distribution_item_details did ON did.id = at.distribution_item_detail_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN distributions d ON d.id = di.distribution_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN receivings r ON r.id = ri.receiving_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN legacy_assets la ON la.id = at.legacy_asset_id
        LEFT JOIN classifications lc ON lc.id = la.classification_id
        WHERE at.id = ?
          AND at.status = 'posted'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    if ($header && ($header['item_type'] ?? '') === 'semi_expendable') {
        $items[] = $header;
    }
}

if (!$header || !$items) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$groupedItems = [];
if ($isGrouped) {
    foreach ($items as $item) {
        $groupKey = implode('|', [
            trim((string) ($item['classification_name'] ?? '')),
            trim((string) ($item['classification_family'] ?? '')),
            trim((string) ($item['item_description'] ?? '')),
            trim((string) ($item['brand'] ?? '')),
            trim((string) ($item['model'] ?? '')),
            trim((string) ($item['date_acquired'] ?? '')),
            trim((string) ($item['ics_no'] ?? '')),
            trim((string) ($item['ics_date'] ?? '')),
            number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
        ]);

        if (!isset($groupedItems[$groupKey])) {
            $groupedItems[$groupKey] = $item;
            $groupedItems[$groupKey]['quantity'] = 0;
            $groupedItems[$groupKey]['total_amount'] = 0.0;
            $groupedItems[$groupKey]['property_numbers'] = [];
            $groupedItems[$groupKey]['serial_numbers'] = [];
        }

        $groupedItems[$groupKey]['quantity']++;
        $groupedItems[$groupKey]['total_amount'] += (float) ($item['amount'] ?? 0);
        if (!empty($item['property_number'])) {
            $groupedItems[$groupKey]['property_numbers'][] = (string) $item['property_number'];
        }
        if (!empty($item['serial_no'])) {
            $groupedItems[$groupKey]['serial_numbers'][] = (string) $item['serial_no'];
        }
    }

    foreach ($groupedItems as &$groupedItem) {
        $groupedItem['property_numbers'] = array_values(array_unique($groupedItem['property_numbers']));
        $groupedItem['serial_numbers'] = array_values(array_unique($groupedItem['serial_numbers']));
        sort($groupedItem['serial_numbers']);
        $groupedItem['property_number'] = itr_build_reference_range($groupedItem['property_numbers']);
        $groupedItem['amount'] = (float) ($groupedItem['total_amount'] ?? 0);
        if (count($groupedItem['serial_numbers']) > 1) {
            $groupedItem['serial_no'] = implode(', ', $groupedItem['serial_numbers']);
        }
    }
    unset($groupedItem);
}

$printItems = $isGrouped ? array_values($groupedItems) : array_values($items);
$baseParams = $batchId > 0 ? ['batch_id' => $batchId] : ['id' => $transferId];
$baseParams['print_format'] = $printFormat;
$baseParams['view_mode'] = $viewMode;
$baseParams['extra_rows'] = $extraRows;

$fromOfficer = trim(itr_name($header, 'from_') . (!empty($header['from_office_name']) ? ' / ' . $header['from_office_name'] : ''));
$toOfficer = trim(itr_name($header, 'to_') . (!empty($header['to_office_name']) ? ' / ' . $header['to_office_name'] : ''));
$approvedBy = itr_approval_profile($db, $header);
$reasonText = trim((string) ($header['reason'] ?? ''));
$flags = itr_reason_flags($reasonText, (string) ($header['transfer_type'] ?? ''));
$fundCode = '';
foreach ($items as $item) {
    if (!empty($item['fund_code'])) {
        $fundCode = (string) $item['fund_code'];
        break;
    }
}
$totalAmount = array_sum(array_map(static fn(array $item): float => (float) ($item['amount'] ?? 0), $items));
$blankRows = $extraRows;
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ITR <?php echo h($header['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page { size: 8.5in 13in; margin: 0.5in 0.07in 0.07in 0.07in; }
        body { margin:0; color:#000; font-family:"Times New Roman", Times, serif; font-size:12px; }
        .itr-wrap { margin:0 auto; max-width:1000px; }
        .itr-appendix { font-size:11px; font-style:italic; text-align:right; }
        .itr-title { font-size:18px; font-weight:700; text-align:center; text-transform:uppercase; }
        .itr-table, .itr-table td, .itr-table th { border:1px solid #000 !important; border-collapse:collapse; }
        .itr-table { width:100%; }
        .itr-table td, .itr-table th { padding:.3rem .35rem; vertical-align:top; }
        .itr-body td { height:25px; }
        .itr-line { border-bottom:1px solid #000; min-height:20px; }
        .no-print { display:block; }
        .duplicate-host { display:none; }
        .print-shell.short { font-size:10.5px; display:flex; flex-direction:column; gap:0.2in; }
        .print-shell.short .itr-table { font-size:10px; }
        .print-shell.short .print-copy,
        .print-shell.short .duplicate-host {
            flex: 0 0 calc((13in - 1in - 0.2in) / 2);
            min-height: calc((13in - 1in - 0.2in) / 2);
        }
        .print-shell.short .duplicate-host { display:block; }
        .print-shell.short .duplicate-host .no-print { display:none !important; }
        .print-shell.short .itr-body td { height:14px; }
        @media print {
            .no-print { display:none !important; }
            thead { display: table-header-group; }
            .print-shell.short .print-copy,
            .print-shell.short .duplicate-host { break-inside: avoid; }
        }
    
            <?php echo print_page_number_css(); ?></style>
</head>
<body>
<div class="container itr-wrap print-shell <?php echo $isShort ? 'short' : 'long'; ?>">
    <div class="d-flex justify-content-between align-items-center my-3 no-print">
        <div>
            <a href="<?php echo base_url('modules/transfers/index.php'); ?>" class="btn btn-sm btn-outline-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-sm btn-primary">Print</button>
            <a href="<?php echo h(base_url('modules/transfers/itr.php?' . http_build_query(array_merge($baseParams, ['print_format' => 'short'])))); ?>" class="btn btn-sm <?php echo $isShort ? 'btn-primary' : 'btn-outline-primary'; ?>">Short</a>
            <a href="<?php echo h(base_url('modules/transfers/itr.php?' . http_build_query(array_merge($baseParams, ['print_format' => 'long'])))); ?>" class="btn btn-sm <?php echo !$isShort ? 'btn-primary' : 'btn-outline-primary'; ?>">Long</a>
            <a href="<?php echo h(base_url('modules/transfers/itr.php?' . http_build_query(array_merge($baseParams, ['view_mode' => 'grouped'])))); ?>" class="btn btn-sm <?php echo $isGrouped ? 'btn-primary' : 'btn-outline-primary'; ?>">Grouped</a>
            <a href="<?php echo h(base_url('modules/transfers/itr.php?' . http_build_query(array_merge($baseParams, ['view_mode' => 'detailed'])))); ?>" class="btn btn-sm <?php echo !$isGrouped ? 'btn-primary' : 'btn-outline-primary'; ?>">Detailed</a>
            <form method="get" class="d-inline-flex align-items-center gap-2 ms-2">
                <?php foreach ($baseParams as $paramName => $paramValue): ?>
                    <?php if ($paramName !== 'extra_rows'): ?><input type="hidden" name="<?php echo h((string) $paramName); ?>" value="<?php echo h((string) $paramValue); ?>"><?php endif; ?>
                <?php endforeach; ?>
                <label for="extra_rows_itr" class="small text-muted mb-0">Extra rows</label>
                <input type="number" min="0" max="50" step="1" id="extra_rows_itr" name="extra_rows" value="<?php echo (int) $extraRows; ?>" style="width:80px;" class="form-control form-control-sm">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            </form>
        </div>
    </div>
    <div class="print-copy" id="printCopy">
    <div class="itr-appendix">Annex A.5</div>
    <div class="itr-title">Inventory Transfer Report</div>

    <table class="itr-table mt-3 mb-3">
        <tr>
            <td colspan="4"><strong>Entity Name:</strong> University of Antique</td>
            <td colspan="2"><strong>Fund Cluster:</strong> <?php echo h($fundCode); ?></td>
        </tr>
        <tr>
            <td colspan="4"><strong>From Accountable Officer/Agency/Fund Cluster</strong> <?php echo h($fromOfficer); ?></td>
            <td><strong>ITR :</strong></td>
            <td><?php echo h($header['system_reference']); ?></td>
        </tr>
        <tr>
            <td colspan="4"><strong>To Accountable Officer/Agency/Fund Cluster</strong> <?php echo h($toOfficer); ?></td>
            <td><strong>Date :</strong></td>
            <td><?php echo h(!empty($header['transfer_date']) ? date('M d, Y', strtotime((string) $header['transfer_date'])) : ''); ?></td>
        </tr>
        <tr>
            <td colspan="6">
                <strong>Transfer Type:</strong> (check only one)
                <span class="ms-3">[<?php echo $flags['donation'] ? '/' : ' '; ?>] Donation</span>
                <span class="ms-3">[<?php echo $flags['relocate'] ? '/' : ' '; ?>] Relocate</span>
                <span class="ms-3">[<?php echo $flags['reassignment'] ? '/' : ' '; ?>] Reassignment</span>
                <span class="ms-3">[<?php echo $flags['others'] ? '/' : ' '; ?>] Others (Specify) <?php echo h($flags['others'] ? $reasonText : ''); ?></span>
            </td>
        </tr>
    </table>

    <table class="itr-table mb-3">
        <thead>
            <tr>
                <th style="width:14%;">Date Acquired</th>
                <th style="width:12%;">Item No.</th>
                <th style="width:14%;">ICS No./Date</th>
                <th>Description</th>
                <th style="width:12%;" class="text-end">Amount</th>
                <th style="width:16%;">Condition of Inventory</th>
            </tr>
        </thead>
        <tbody class="itr-body">
            <?php foreach ($printItems as $item): ?>
                <?php $meta = itr_item_meta($item); ?>
                <tr>
                    <td><?php echo h(format_date($item['date_acquired'] ?? null)); ?></td>
                    <td>
                        <?php echo h($item['property_number'] ?? ''); ?>
                        <?php if ($isGrouped && !empty($item['quantity']) && (int) $item['quantity'] > 1): ?>
                            <div class="small text-muted"><?php echo h((string) $item['quantity']); ?> item(s)</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo h($item['ics_no'] ?? ''); ?>
                        <?php if (!empty($item['ics_date'])): ?>
                            <div class="small text-muted"><?php echo h(date('M d, Y', strtotime((string) $item['ics_date']))); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?php echo nl2br(h(itr_item_description($item))); ?></div>
                        <?php if ($meta !== ''): ?>
                            <div class="small text-muted mt-2"><?php echo h($meta); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo h(number_format((float) ($item['amount'] ?? 0), 2)); ?></td>
                    <td>Good</td>
                </tr>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < $blankRows; $i++): ?>
                <tr>
                    <td>&nbsp;</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            <?php endfor; ?>
            <tr>
                <td colspan="4" class="text-end fw-bold">TOTAL</td>
                <td class="text-end fw-bold"><?php echo h(number_format($totalAmount, 2)); ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="mb-3">
        <strong>Reason/s for Transfer</strong>
        <div class="itr-line mt-1"><?php echo h($reasonText); ?></div>
        <div class="itr-line"><?php echo h($header['remarks'] ?? ''); ?></div>
        <div class="itr-line">&nbsp;</div>
        <div class="itr-line">&nbsp;</div>
    </div>

    <table class="itr-table">
        <tr>
            <td style="width:33%;"><strong>Approved by:</strong></td>
            <td style="width:33%;"><strong>Released/Issued by:</strong></td>
            <td style="width:34%;"><strong>Received by:</strong></td>
        </tr>
        <tr><td>Signature :</td><td>Signature :</td><td>Signature :</td></tr>
        <tr><td>Printed Name : <strong><?php echo h(strtoupper($approvedBy['name'])); ?></strong></td><td>Printed Name : <strong><?php echo h(strtoupper(itr_signatory_name($header, 'from_'))); ?></strong></td><td>Printed Name : <strong><?php echo h(strtoupper(itr_signatory_name($header, 'to_'))); ?></strong></td></tr>
        <tr><td>Designation : <?php echo h($approvedBy['title']); ?></td><td>Designation : <?php echo h($header['from_position_title'] ?? ''); ?></td><td>Designation : <?php echo h($header['to_position_title'] ?? ''); ?></td></tr>
        <tr><td>Date :</td><td>Date :</td><td>Date :</td></tr>
    </table>
    </div>
    <div class="duplicate-host" id="duplicateHost"></div>
</div>
<?php if ($isShort): ?>
<script>
(function () {
    var source = document.getElementById('printCopy');
    var host = document.getElementById('duplicateHost');
    if (!source || !host || host.children.length) return;
    var clone = source.cloneNode(true);
    clone.removeAttribute('id');
    host.appendChild(clone);
})();
</script>
<?php endif; ?>

<?php render_print_page_number(); ?></body>
</html>
