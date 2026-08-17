<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$distributionId = (int) ($_GET['id'] ?? 0);
$detailId = (int) ($_GET['detail_id'] ?? 0);
$printFormat = ($_GET['print_format'] ?? 'long') === 'short' ? 'short' : 'long';
$isShort = $printFormat === 'short';
$copyCount = max(1, min(20, (int) ($_GET['copies'] ?? 1)));
$extraRows = max(0, min(35, (int) ($_GET['extra_rows'] ?? 0)));
$viewMode = (($_GET['view_mode'] ?? 'grouped') === 'detailed') ? 'detailed' : 'grouped';
$isGrouped = $viewMode === 'grouped';

if (!$db || $distributionId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

// HEADER QUERY (same as ICS header)
$headerStmt = $db->prepare(
    "SELECT d.id, d.office_id, d.system_reference, d.document_no, d.distribution_date,\n" .
    "       d.document_type, d.purpose, d.remarks, d.total_amount,\n" .
    "       o.office_name, o.office_code,\n" .
    "       dep.name AS department_name,\n" .
    "       rc.code AS responsibility_center_code,\n" .
    "       e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name,\n" .
    "       e.position_title,\n" .
    "       f.fund_code, f.fund_source\n" .
    "FROM distributions d\n" .
    "INNER JOIN offices o ON o.id = d.office_id\n" .
    "LEFT JOIN departments dep ON dep.id = o.department_id\n" .
    "LEFT JOIN responsibility_codes rc ON rc.office_id = o.id\n" .
    "LEFT JOIN employees e ON e.id = d.employee_id\n" .
    "LEFT JOIN funds f ON f.id = (\n" .
    "    SELECT po.fund_id\n" .
    "    FROM distribution_items di\n" .
    "    INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id\n" .
    "    INNER JOIN receivings r ON r.id = ri.receiving_id\n" .
    "    INNER JOIN purchase_orders po ON po.id = r.purchase_order_id\n" .
    "    WHERE di.distribution_id = d.id\n" .
    "    LIMIT 1\n" .
    ")\n" .
    "WHERE d.id = ?\n" .
    "LIMIT 1"
);

$header = null;
if ($headerStmt) {
    $headerStmt->bind_param('i', $distributionId);
    $headerStmt->execute();
    $header = $headerStmt->get_result()->fetch_assoc() ?: null;
    $headerStmt->close();
}

if (!$header) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$officeId = (int) ($header['office_id'] ?? 0);

$resolveOfficeHead = static function (mysqli $db, int $officeId): array {
    return employee_resolve_office_head($db, $officeId);
};

$resolveSupplyOfficeHead = static function (mysqli $db): array {
    return employee_resolve_supply_office_head($db);
};

// Redirect to ICS if needed
if (!empty($header['document_type']) && $header['document_type'] === 'ics') {
    header('Location: ics.php?id=' . $distributionId . ($detailId > 0 ? '&detail_id=' . $detailId : ''));
    exit;
}

// ITEMS QUERY (may return multiple rows per di.id due to receiving_item_details)
$detailFilterSql = $detailId > 0 ? ' AND did.id = ?' : '';
    $itemStmt = $db->prepare(
     "SELECT di.id AS di_id, di.quantity_distributed, di.unit_cost, di.line_total,\n" .
         "       poi.item_description,\n" .
         "       c.classification_name, c.classification_family,\n" .
         "       u.uom_name, u.abbreviation,\n" .
         "       r.received_date AS date_acquired,\n" .
        "       did.brand, did.model, did.serial_no, did.property_number, did.id AS did_id\n" .
     "FROM distribution_items di\n" .
     "INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id\n" .
     "INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id\n" .
     "INNER JOIN receivings r ON r.id = ri.receiving_id\n" .
     "LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id\n" .
    "LEFT JOIN classifications c ON c.id = poi.classification_id\n" .
        "LEFT JOIN distribution_item_details did ON did.distribution_item_id = di.id AND did.is_distributed = 1\n" .
     "WHERE di.distribution_id = ?{$detailFilterSql}\n" .
     "ORDER BY di.id ASC, did.id ASC"
);

$rows = [];
if ($itemStmt) {
    if ($detailId > 0) {
        $itemStmt->bind_param('ii', $distributionId, $detailId);
    } else {
        $itemStmt->bind_param('i', $distributionId);
    }
    $itemStmt->execute();
    $res = $itemStmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $itemStmt->close();
}

if ($detailId > 0 && !$rows) {
    http_response_code(404);
    echo 'Asset detail not found in this PAR.';
    exit;
}

// Group rows by distribution item id
$items = [];
foreach ($rows as $r) {
    $di = (int) $r['di_id'];
    if (!isset($items[$di])) {
        $qty_di = $detailId > 0 ? 1.0 : (float) $r['quantity_distributed'];
        $uc_di  = (float) $r['unit_cost'];
        $items[$di] = [
            'quantity_distributed' => $qty_di,
            'unit_cost' => $uc_di,
            'line_total' => $qty_di * $uc_di,
            'item_description' => $r['item_description'],
            'classification_name' => $r['classification_name'] ?? '',
            'classification_family' => $r['classification_family'] ?? '',
            'uom_name' => $r['uom_name'],
            'abbreviation' => $r['abbreviation'],
            'date_acquired' => $r['date_acquired'],
            'property_number' => $r['property_number'],
            'details' => [],
        ];
    }
    // If there is a receiving_item_detail row, add to details
    if (!empty($r['did_id'])) {
        $items[$di]['details'][] = [
            'brand' => $r['brand'],
            'model' => $r['model'],
            'serial_no' => $r['serial_no'],
            'property_number' => $r['property_number'] ?? '',
        ];
    }
}

$buildPropertyRange = static function (array $propertyNumbers): string {
    $values = array_values(array_filter(array_map('trim', $propertyNumbers), static function ($value) {
        return $value !== '';
    }));
    if (!$values) {
        return '';
    }
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
};

$detailIdentityLine = static function (array $detail): string {
    $parts = [];
    $brand = trim((string) ($detail['brand'] ?? ''));
    $model = trim((string) ($detail['model'] ?? ''));
    $serial = trim((string) ($detail['serial_no'] ?? ''));

    if ($brand !== '') {
        $parts[] = '<strong>Brand: ' . h($brand) . '</strong>';
    }
    if ($model !== '') {
        $parts[] = '<strong>Model: ' . h($model) . '</strong>';
    }
    if ($serial !== '') {
        $parts[] = '<strong>Serial: ' . h($serial) . '</strong>';
    }

    return implode(' | ', $parts);
};

$itemIdentityLines = static function (array $item) use ($detailIdentityLine): array {
    $lines = [];
    $details = array_key_exists('details', $item) ? (array) $item['details'] : [$item];
    foreach ($details as $detail) {
        $line = $detailIdentityLine((array) $detail);
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return array_values(array_unique($lines));
};

$groupedItems = [];
if ($isGrouped) {
    foreach ($items as $item) {
        // Skip items with zero quantity (removed/corrected items)
        $qty = (float) ($item['quantity_distributed'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $unitLabel = trim((string) ($item['abbreviation'] ?? $item['uom_name'] ?? ''));
        $groupKey = implode('|', [
            trim((string) ($item['classification_name'] ?? '')),
            trim((string) ($item['classification_family'] ?? '')),
            trim((string) ($item['item_description'] ?? '')),
            $unitLabel,
            trim((string) ($item['date_acquired'] ?? '')),
            number_format((float) ($item['unit_cost'] ?? 0), 2, '.', ''),
        ]);

        if (!isset($groupedItems[$groupKey])) {
            $groupedItems[$groupKey] = [
                'quantity_distributed' => 0.0,
                'unit_cost' => (float) ($item['unit_cost'] ?? 0),
                'line_total' => 0.0,
                'item_description' => (string) ($item['item_description'] ?? ''),
                'classification_name' => (string) ($item['classification_name'] ?? ''),
                'classification_family' => (string) ($item['classification_family'] ?? ''),
                'uom_name' => (string) ($item['uom_name'] ?? ''),
                'abbreviation' => (string) ($item['abbreviation'] ?? ''),
                'date_acquired' => (string) ($item['date_acquired'] ?? ''),
                'details' => [],
                'property_numbers' => [],
            ];
        }

        $groupedItems[$groupKey]['quantity_distributed'] += (float) ($item['quantity_distributed'] ?? 0);
        $groupedItems[$groupKey]['line_total'] = $groupedItems[$groupKey]['quantity_distributed'] * $groupedItems[$groupKey]['unit_cost'];

        foreach ((array) ($item['details'] ?? []) as $detail) {
            $groupedItems[$groupKey]['details'][] = $detail;
            if (!empty($detail['property_number'])) {
                $groupedItems[$groupKey]['property_numbers'][] = (string) $detail['property_number'];
            }
        }

        if (empty($groupedItems[$groupKey]['property_numbers']) && !empty($item['property_number'])) {
            $groupedItems[$groupKey]['property_numbers'][] = (string) $item['property_number'];
        }
    }

    foreach ($groupedItems as &$groupedItem) {
        $groupedItem['property_numbers'] = array_values(array_unique($groupedItem['property_numbers']));
        sort($groupedItem['property_numbers']);
        $groupedItem['property_number_range'] = $buildPropertyRange($groupedItem['property_numbers']);
    }
    unset($groupedItem);
}

$printItems = $isGrouped ? array_values($groupedItems) : array_values($items);

// Received by name
$signatoryDisplayName = static function (array $person): string {
    $suffix = trim((string) ($person['suffix_name'] ?? ''));
    $middle = trim((string) ($person['middle_name'] ?? ''));
    $middleInitial = $middle !== '' ? strtoupper(substr(rtrim($middle, '.'), 0, 1)) . '.' : '';
    $nameParts = array_filter([
        trim((string) ($person['name_prefix'] ?? '')),
        trim((string) ($person['first_name'] ?? '')),
        $middleInitial,
        trim((string) ($person['last_name'] ?? '')),
    ]);
    $name = strtoupper(trim(implode(' ', $nameParts)));
    if ($suffix !== '') {
        $name .= ', ' . $suffix;
    }
    return $name;
};

$singleAssetRecipient = [];
if ($detailId > 0) {
    $recipientStmt = $db->prepare(
        "SELECT e.id, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title, o.office_name
         FROM distribution_item_details did
         INNER JOIN distribution_items di ON di.id = did.distribution_item_id
         INNER JOIN distributions d ON d.id = di.distribution_id
         INNER JOIN employees e ON e.id = COALESCE(NULLIF(did.current_employee_id, 0), d.employee_id)
         LEFT JOIN offices o ON o.id = COALESCE(NULLIF(did.current_office_id, 0), d.office_id)
         WHERE did.id = ? AND d.id = ? AND e.is_active = 1
         LIMIT 1"
    );
    if ($recipientStmt) {
        $recipientStmt->bind_param('ii', $detailId, $distributionId);
        $recipientStmt->execute();
        $singleAssetRecipient = $recipientStmt->get_result()->fetch_assoc() ?: [];
        $recipientStmt->close();
    }
}

$recipientHead = $singleAssetRecipient ?: $resolveOfficeHead($db, $officeId);
$supplyHead = $resolveSupplyOfficeHead($db);

$recipientHeadName = !empty($recipientHead) ? $signatoryDisplayName($recipientHead) : '';
$recipientHeadTitle = trim((string) ($recipientHead['position_title'] ?? ''));
$recipientOfficeName = trim((string) ($header['office_name'] ?? ''));

$supplyHeadName = !empty($supplyHead) ? $signatoryDisplayName($supplyHead) : '';
$supplyHeadTitle = trim((string) ($supplyHead['position_title'] ?? ''));
$supplyOfficeName = trim((string) ($supplyHead['office_name'] ?? 'Supply Office'));

$fundCluster = trim((string) ($header['fund_source'] ?? ''));
if ($fundCluster === '') {
    $fundCluster = trim((string) ($header['fund_code'] ?? ''));
}
if (preg_match('/(?:^|[^0-9])(0[1567])(?:[^0-9]|$)/', $fundCluster, $matches)) {
    $fundCluster = $matches[1];
} elseif (preg_match('/([0-9]{2})/', $fundCluster, $matches)) {
    $fundCluster = $matches[1];
}

$blankRows = $extraRows;
$shortSheetCount = (int) ceil($copyCount / 2);
$detailParam = $detailId > 0 ? '&detail_id=' . $detailId : '';
$tagPrintUrl = $detailId > 0
    ? base_url('modules/property/tags.php?detail_id=' . $detailId)
    : base_url('modules/property/tags.php?distribution_id=' . (int) $distributionId);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PAR <?php echo h($header['document_no'] ?? $header['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page { size: 8.5in 13in; margin: <?php echo $isShort ? '0.5in 0.07in 0.07in 0.07in' : '0.5in 0.07in 0.07in 0.07in'; ?>; }
        body { margin: 0; font-size:12px; color:#000; font-family: "Times New Roman", serif; }
        table { font-size:12px; }
        .print-shell { width: 100%; max-width: none !important; margin: 0 auto; padding: 0; }
        .no-print { display:block; font-family: Arial, sans-serif; }
        .print-shell.short { font-size: 12px; }
        .print-shell.short table { font-size: 12px; }
        .print-shell.short { width: 7.5in; max-width: 7.5in !important; margin: 0 auto; padding: 0; }
        .short-copies { width: 7.5in; margin: 0 auto; }
        .short-sheet { width: 7.5in; height: 12.4in; box-sizing: border-box; display: block; overflow: hidden; }
        .short-sheet + .short-sheet { margin-top: 0; }
        .short-slot { height: 6.1in; box-sizing: border-box; display: block; overflow: hidden; }
        .short-slot + .short-slot { padding-top: 0.2in; }
        .short-slot + .short-slot { border-top: 1px dashed #bbb; }
        .short-copy { height: 6.1in; min-height: 6.1in; padding: 0; box-sizing: border-box; overflow: hidden; break-inside: avoid; page-break-inside: avoid; flex: 1 1 auto; }
        .par-form { position: relative; }
        .par-title { text-align:center; font-weight:bold; font-size:16px; text-transform:uppercase; margin:18px 0 22px; }
        .appendix { position:absolute; right:0; top:0; font-size:12px; font-style:italic; }
        .line-value { display:inline-block; border-bottom:1px solid #000; min-width:150px; padding:0 2px; line-height:1.1; }
        .line-value.long { min-width:220px; }
        .par-meta { width:100%; border-collapse:collapse; margin-bottom:8px; }
        .par-meta td { padding:2px 4px; vertical-align:bottom; }
        .par-meta .label { white-space:nowrap; font-weight:bold; }
        .par-table, .par-sign-table { width:100%; border-collapse:collapse; }
        .par-table th, .par-table td, .par-sign-table td, .par-sign-table th { border:1px solid #000; padding:3px 4px; vertical-align:top; }
        .par-table thead th { text-align:center; font-weight:bold; }
        .par-body td { height: 25px; }
        .print-shell.short .par-body td { height: 14px; }
        .par-sign-table { margin-top:0; }
        .par-sign-table .sign-head { font-weight:bold; text-align:left; }
        .par-sign-table .sign-box { height:74px; text-align:center; vertical-align:top; font-size:10px; padding-top:8px; }
        .par-sign-table .sign-line { display:none; }
        .par-sign-table .sign-name { font-weight:700; font-size:14px; letter-spacing:0.2px; line-height:1.1; margin:24px 0 0; }
        .par-sign-table .meta-box { height:52px; text-align:center; vertical-align:top; padding-top:6px; }
        .par-sign-table .meta-line { display:none; }
        .par-sign-table .meta-value { margin: 10px 0 0; font-size:12px; line-height:1.15; }
        .par-sign-table .meta-caption { text-align:center; font-size:11px; }
        .par-sign-table .underlined-value { display:inline-block; border-bottom:1px solid #000; padding:0 8px 1px; min-width:82%; }
        .par-sign-table .meta-box .underlined-value { min-width:68%; }
        .print-shell.short .par-sign-table .sign-box { height:60px; font-size:9px; padding-top:6px; }
        .print-shell.short .par-sign-table .sign-name { font-size:12px; margin-top:14px; margin-bottom:0; }
        .print-shell.short .par-sign-table .meta-box { height:42px; padding-top:4px; }
        .print-shell.short .par-sign-table .meta-value { font-size:10px; margin-top:8px; }
        .print-shell.long .par-body td { height: 22px; }
        @media print {
            .no-print, .no-print * { display:none !important; }
            thead { display: table-header-group; }
            .par-print-toolbar { height: 0 !important; margin: 0 !important; overflow: visible !important; position: relative; }
            .print-shell.short .short-copies { width: 7.5in !important; height: auto !important; overflow: visible !important; }
            .print-shell.short .short-sheet { width: 7.5in !important; height: 12.4in !important; display: block !important; overflow: hidden !important; }
            .print-shell.short .short-slot { height: 6.1in !important; display: block !important; overflow: hidden !important; }
            .print-shell.short .short-slot + .short-slot { padding-top: 0.2in !important; }
            .print-shell.short .short-copy { height: 6.1in !important; min-height: 6.1in !important; }
            .print-shell.short .short-slot + .short-slot { border-top: none; }
            .print-shell.short .short-sheet { break-after: page; page-break-after: always; }
            .print-shell.short .short-sheet:last-child { break-after: auto; page-break-after: auto; }
        }
        <?php if (!$isShort): ?>
            <?php echo print_page_number_css(); ?>
        <?php endif; ?></style>
</head>
<body>
    <div class="container print-shell <?php echo $isShort ? 'short' : 'long'; ?>">
        <?php if (isset($_GET['created']) && $_GET['created'] == '1'): ?>
            <div class="alert alert-info no-print">Distribution was just posted — ideal time to print this PAR now.</div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-start mt-3 mb-2 par-print-toolbar">
                <div class="d-flex gap-2 flex-wrap">
                <a href="<?php echo base_url('modules/distributions/index.php?document_type=par'); ?>" class="btn btn-sm btn-outline-secondary no-print">Back</a>
                <button onclick="window.print()" class="btn btn-sm btn-primary no-print">Print</button>
                <a href="<?php echo h(base_url('modules/distributions/par.php?id=' . (int) $distributionId . $detailParam . '&print_format=short&view_mode=' . $viewMode . '&extra_rows=' . $extraRows . '&copies=' . $copyCount)); ?>" class="btn btn-sm <?php echo $isShort ? 'btn-primary' : 'btn-outline-primary'; ?> no-print">Short</a>
                <a href="<?php echo h(base_url('modules/distributions/par.php?id=' . (int) $distributionId . $detailParam . '&print_format=long&view_mode=' . $viewMode . '&extra_rows=' . $extraRows)); ?>" class="btn btn-sm <?php echo !$isShort ? 'btn-primary' : 'btn-outline-primary'; ?> no-print">Long</a>
                <a href="<?php echo h(base_url('modules/distributions/par.php?id=' . (int) $distributionId . $detailParam . '&print_format=' . $printFormat . '&view_mode=grouped&extra_rows=' . $extraRows . ($isShort ? '&copies=' . $copyCount : ''))); ?>" class="btn btn-sm <?php echo $isGrouped ? 'btn-primary' : 'btn-outline-primary'; ?> no-print">Grouped</a>
                <a href="<?php echo h(base_url('modules/distributions/par.php?id=' . (int) $distributionId . $detailParam . '&print_format=' . $printFormat . '&view_mode=detailed&extra_rows=' . $extraRows . ($isShort ? '&copies=' . $copyCount : ''))); ?>" class="btn btn-sm <?php echo !$isGrouped ? 'btn-primary' : 'btn-outline-primary'; ?> no-print">Detailed</a>
                <a href="<?php echo h($tagPrintUrl); ?>" class="btn btn-outline-secondary btn-sm no-print" target="_blank">Print QR Tags</a>
                <form method="get" class="d-flex align-items-center gap-2 no-print ms-2">
                    <input type="hidden" name="id" value="<?php echo (int) $distributionId; ?>">
                    <?php if ($detailId > 0): ?><input type="hidden" name="detail_id" value="<?php echo (int) $detailId; ?>"><?php endif; ?>
                    <input type="hidden" name="print_format" value="<?php echo h($printFormat); ?>">
                    <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
                    <?php if ($isShort): ?>
                    <input type="hidden" name="copies" value="<?php echo (int) $copyCount; ?>">
                    <?php endif; ?>
                    <label for="extra_rows" class="small text-muted mb-0">Extra rows</label>
                    <input type="number" min="0" max="35" step="1" id="extra_rows" name="extra_rows" value="<?php echo (int) $extraRows; ?>" class="form-control form-control-sm" style="width:88px;">
                    <button type="submit" class="btn btn-sm btn-outline-dark">Apply</button>
                </form>
                <?php if ($isShort): ?>
                <form method="get" class="d-flex align-items-center gap-2 no-print ms-2">
                    <input type="hidden" name="id" value="<?php echo (int) $distributionId; ?>">
                    <?php if ($detailId > 0): ?><input type="hidden" name="detail_id" value="<?php echo (int) $detailId; ?>"><?php endif; ?>
                    <input type="hidden" name="print_format" value="short">
                    <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
                    <input type="hidden" name="extra_rows" value="<?php echo (int) $extraRows; ?>">
                    <label for="copies" class="small text-muted mb-0">Copies on sheet</label>
                    <input type="number" min="1" max="20" step="1" id="copies" name="copies" value="<?php echo (int) $copyCount; ?>" class="form-control form-control-sm" style="width:88px;">
                    <button type="submit" class="btn btn-sm btn-outline-dark">Apply</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="appendix">Appendix 71</div>
        </div>
        <?php if ($isShort): ?><div class="short-copies"><?php endif; ?>
        <?php if ($isShort): ?>
        <?php for ($sheetIndex = 0; $sheetIndex < $shortSheetCount; $sheetIndex++): ?>
        <div class="short-sheet">
            <?php for ($slotIndex = 0; $slotIndex < 2; $slotIndex++): ?>
            <?php $copyIndex = ($sheetIndex * 2) + $slotIndex; ?>
            <div class="short-slot">
                <div class="print-copy short-copy">
            <?php if ($copyIndex < $copyCount): ?>
        <div class="par-form">
        <div class="par-title">Property Acknowledgment Receipt</div>

        <table class="par-meta">
            <tr>
                <td style="width:14%;" class="label">Entity Name :</td>
                <td style="width:39%;"><span class="line-value long"><?php echo h(APP_NAME); ?></span></td>
                <td style="width:14%;" class="label">PAR No. :</td>
                <td style="width:33%;"><span class="line-value"><?php echo h($header['document_no'] ?? $header['system_reference'] ?? ''); ?></span></td>
            </tr>
            <tr>
                <td class="label">Fund Cluster :</td>
                <td><span class="line-value long"><?php echo h($fundCluster); ?></span></td>
                <td></td>
                <td></td>
            </tr>
        </table>

        <table class="par-table">
            <thead>
                <tr>
                    <th style="width:11%">Quantity</th>
                    <th style="width:10%">Unit</th>
                    <th style="width:33%">Description</th>
                    <th style="width:14%">Property Number</th>
                    <th style="width:14%">Date Acquired</th>
                    <th style="width:18%">Amount</th>
                </tr>
            </thead>
            <tbody class="par-body">
                    <?php $total = 0.0; foreach ($printItems as $it):
                        $qty = (float) ($it['quantity_distributed'] ?? 0);

                        if ($qty <= 0) continue;

                        $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                        $amount = (float) ($it['line_total'] ?? 0);
                        $total += $amount;
                    ?>
                    <tr>
                        <td class="text-end"><?php echo h(format_quantity($qty)); ?></td>
                        <td><?php echo h($unitLabel); ?></td>
                        <td>
                            <?php
                                $itemClass = trim((string) ($it['classification_name'] ?? ''));
                                $itemDescription = trim((string) ($it['item_description'] ?? ''));
                                $parDescription = report_short_text(trim(($itemClass !== '' ? $itemClass : '') . ($itemClass !== '' && $itemDescription !== '' ? ' - ' : '') . $itemDescription));
                                $identityLines = $itemIdentityLines((array) $it);
                            ?>
                            <?php echo nl2br(h($parDescription)); ?>
                            <?php if (!empty($identityLines)): ?>
                                <div class="small">
                                    <?php foreach ($identityLines as $identityLine): ?>
                                        <?php echo $identityLine; ?><br>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isGrouped): ?>
                                <?php echo h((string) ($it['property_number_range'] ?? '')); ?>
                                <?php if (!empty($it['property_numbers'])): ?>
                                    <div class="small text-muted">
                                        <?php echo h(count($it['property_numbers'])); ?> property no.(s)
                                    </div>
                                <?php endif; ?>
                            <?php elseif (!empty($it['details'])): ?>
                                <?php foreach ($it['details'] as $d): ?>
                                    <?php echo h($d['property_number'] ?? ''); ?><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php echo h($it['property_number'] ?? ''); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h(format_date($it['date_acquired'] ?? null, 'm/d/Y')); ?></td>
                        <td class="text-end"><?php echo h(number_format($amount,2)); ?></td>
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
                </tbody>
            </table>

        <table class="par-sign-table">
            <tr>
                <th class="sign-head" style="width:50%;">Received by:</th>
                <th class="sign-head" style="width:50%;">Issued by:</th>
            </tr>
            <tr>
                <td class="sign-box">
                    <?php if ($recipientHeadName !== ''): ?>
                        <div class="sign-name"><span class="underlined-value"><?php echo h($recipientHeadName); ?></span></div>
                    <?php endif; ?>
                    <div>Signature over Printed Name of End User</div>
                </td>
                <td class="sign-box">
                    <?php if ($supplyHeadName !== ''): ?>
                        <div class="sign-name"><span class="underlined-value"><?php echo h($supplyHeadName); ?></span></div>
                    <?php endif; ?>
                    <div>Signature over Printed Name of Supply and/or Property Custodian</div>
                </td>
            </tr>
            <tr>
                <td class="meta-box">
                    <div class="meta-value"><span class="underlined-value"><?php echo h(trim($recipientHeadTitle . ($recipientOfficeName !== '' ? ' / ' . $recipientOfficeName : ''))); ?></span></div>
                    <div class="meta-caption">Designation/Office</div>
                </td>
                <td class="meta-box">
                    <div class="meta-value"><span class="underlined-value"><?php echo h(trim($supplyHeadTitle . ($supplyOfficeName !== '' ? ' / ' . $supplyOfficeName : ''))); ?></span></div>
                    <div class="meta-caption">Designation/Office</div>
                </td>
            </tr>
            <tr>
                <td class="meta-box">
                    <div class="meta-value"><span class="underlined-value">&nbsp;</span></div>
                    <div class="meta-caption">Date</div>
                </td>
                <td class="meta-box">
                    <div class="meta-value"><span class="underlined-value">&nbsp;</span></div>
                    <div class="meta-caption">Date</div>
                </td>
            </tr>
        </table>
        </div>
            <?php endif; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        <?php endfor; ?>
        <?php else: ?>
        <div class="print-copy">
        <div class="par-form">
        <div class="par-title">Property Acknowledgment Receipt</div>

        <table class="par-meta">
            <tr>
                <td style="width:14%;" class="label">Entity Name :</td>
                <td style="width:39%;"><span class="line-value long"><?php echo h(APP_NAME); ?></span></td>
                <td style="width:14%;" class="label">PAR No. :</td>
                <td style="width:33%;"><span class="line-value"><?php echo h($header['document_no'] ?? $header['system_reference'] ?? ''); ?></span></td>
            </tr>
            <tr>
                <td class="label">Fund Cluster :</td>
                <td><span class="line-value long"><?php echo h($fundCluster); ?></span></td>
                <td></td>
                <td></td>
            </tr>
        </table>

        <table class="par-table">
            <thead>
                <tr>
                    <th style="width:11%">Quantity</th>
                    <th style="width:10%">Unit</th>
                    <th style="width:33%">Description</th>
                    <th style="width:14%">Property Number</th>
                    <th style="width:14%">Date Acquired</th>
                    <th style="width:18%">Amount</th>
                </tr>
            </thead>
            <tbody class="par-body">
                    <?php $total = 0.0; foreach ($printItems as $it):
                        $qty = (float) ($it['quantity_distributed'] ?? 0);

                        if ($qty <= 0) continue;

                        $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                        $amount = (float) ($it['line_total'] ?? 0);
                        $total += $amount;
                    ?>
                    <tr>
                        <td class="text-end"><?php echo h(format_quantity($qty)); ?></td>
                        <td><?php echo h($unitLabel); ?></td>
                        <td>
                            <?php
                                $itemClass = trim((string) ($it['classification_name'] ?? ''));
                                $itemDescription = trim((string) ($it['item_description'] ?? ''));
                                $parDescription = report_short_text(trim(($itemClass !== '' ? $itemClass : '') . ($itemClass !== '' && $itemDescription !== '' ? ' - ' : '') . $itemDescription));
                                $identityLines = $itemIdentityLines((array) $it);
                            ?>
                            <?php echo nl2br(h($parDescription)); ?>
                            <?php if (!empty($identityLines)): ?>
                                <div class="small">
                                    <?php foreach ($identityLines as $identityLine): ?>
                                        <?php echo $identityLine; ?><br>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isGrouped): ?>
                                <?php echo h((string) ($it['property_number_range'] ?? '')); ?>
                                <?php if (!empty($it['property_numbers'])): ?>
                                    <div class="small text-muted">
                                        <?php echo h(count($it['property_numbers'])); ?> property no.(s)
                                    </div>
                                <?php endif; ?>
                            <?php elseif (!empty($it['details'])): ?>
                                <?php foreach ($it['details'] as $d): ?>
                                    <?php echo h($d['property_number'] ?? ''); ?><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php echo h($it['property_number'] ?? ''); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h(format_date($it['date_acquired'] ?? null, 'm/d/Y')); ?></td>
                        <td class="text-end"><?php echo h(number_format($amount,2)); ?></td>
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
                </tbody>
            </table>

        <table class="par-sign-table">
            <tr>
                <th class="sign-head" style="width:50%;">Received by:</th>
                <th class="sign-head" style="width:50%;">Issued by:</th>
            </tr>
            <tr>
                <td class="sign-box">
                    <?php if ($recipientHeadName !== ''): ?>
                        <div class="sign-name"><span class="underlined-value"><?php echo h($recipientHeadName); ?></span></div>
                    <?php endif; ?>
                    <div>Signature over Printed Name of End User</div>
                </td>
                <td class="sign-box">
                    <?php if ($supplyHeadName !== ''): ?>
                        <div class="sign-name"><span class="underlined-value"><?php echo h($supplyHeadName); ?></span></div>
                    <?php endif; ?>
                    <div>Signature over Printed Name of Supply and/or Property Custodian</div>
                </td>
            </tr>
            <tr>
                <td class="meta-box">
                    <div class="meta-value"><span class="underlined-value"><?php echo h(trim($recipientHeadTitle . ($recipientOfficeName !== '' ? ' / ' . $recipientOfficeName : ''))); ?></span></div>
                    <div class="meta-caption">Designation/Office</div>
                </td>
                <td class="meta-box">
                    <div class="meta-value"><span class="underlined-value"><?php echo h(trim($supplyHeadTitle . ($supplyOfficeName !== '' ? ' / ' . $supplyOfficeName : ''))); ?></span></div>
                    <div class="meta-caption">Designation/Office</div>
                </td>
            </tr>
            <tr>
                <td class="meta-box">
                    <div class="meta-value"><span class="underlined-value">&nbsp;</span></div>
                    <div class="meta-caption">Date</div>
                </td>
                <td class="meta-box">
                    <div class="meta-value"><span class="underlined-value">&nbsp;</span></div>
                    <div class="meta-caption">Date</div>
                </td>
            </tr>
        </table>
        </div>
        </div>
        <?php endif; ?>
        <?php if ($isShort): ?></div><?php endif; ?>
    </div>

<?php if (!$isShort) { render_print_page_number(); } ?></body>
</html>
