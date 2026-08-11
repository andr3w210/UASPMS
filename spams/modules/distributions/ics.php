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
$extraRows = max(0, min(25, (int) ($_GET['extra_rows'] ?? 0)));
$viewMode = (($_GET['view_mode'] ?? 'grouped') === 'detailed') ? 'detailed' : 'grouped';
$isGrouped = $viewMode === 'grouped';

if (!$db || $distributionId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$headerStmt = $db->prepare(
    "SELECT d.id, d.office_id, d.system_reference, d.document_no, d.distribution_date,
            d.document_type, d.semi_expendable_type, d.purpose, d.remarks, d.total_amount,
            o.office_name, o.office_code,
            dep.name AS department_name,
            rc.code AS responsibility_center_code,
            e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name,
            e.position_title,
            f.fund_code, f.fund_source
     FROM distributions d
     INNER JOIN offices o ON o.id = d.office_id
     LEFT JOIN departments dep ON dep.id = o.department_id
     LEFT JOIN responsibility_codes rc ON rc.office_id = o.id
     LEFT JOIN employees e ON e.id = d.employee_id
     LEFT JOIN funds f ON f.id = (
         SELECT po.fund_id
         FROM distribution_items di
         INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
         INNER JOIN receivings r ON r.id = ri.receiving_id
         INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
         WHERE di.distribution_id = d.id
         LIMIT 1
     )
     WHERE d.id = ?
     LIMIT 1"
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

$docNo = $header['document_no'] ?? '';
if ((!empty($header['document_type']) && $header['document_type'] === 'par') || (is_string($docNo) && strpos($docNo, 'PAR-') === 0)) {
    header('Location: par.php?id=' . (int) $distributionId . ($detailId > 0 ? '&detail_id=' . $detailId : ''));
    exit;
}

$officeId = (int) ($header['office_id'] ?? 0);

$resolveOfficeHead = static function (mysqli $db, int $officeId): array {
    return employee_resolve_office_head($db, $officeId);
};

$resolveSupplyOfficeHead = static function (mysqli $db): array {
    return employee_resolve_supply_office_head($db);
};

$detailFilterSql = $detailId > 0 ? ' AND did.id = ?' : '';
$itemStmt = $db->prepare(
    "SELECT di.id AS di_id, di.quantity_distributed, di.unit_cost, di.line_total,
            poi.item_description,
            u.uom_name, u.abbreviation,
            c.classification_name, c.useful_life_years,
            did.property_number, did.brand, did.model, did.serial_no, did.id AS did_id
     FROM distribution_items di
     INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
     INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
     LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
     LEFT JOIN classifications c ON c.id = poi.classification_id
     LEFT JOIN distribution_item_details did ON did.distribution_item_id = di.id
         AND did.is_distributed = 1
     WHERE di.distribution_id = ?{$detailFilterSql}
     ORDER BY di.id ASC, did.id ASC"
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
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $itemStmt->close();
}

if ($detailId > 0 && !$rows) {
    http_response_code(404);
    echo 'Asset detail not found in this ICS.';
    exit;
}

$items = [];
foreach ($rows as $row) {
    $di = (int) $row['di_id'];
    if (!isset($items[$di])) {
        $qty_di = $detailId > 0 ? 1.0 : (float) $row['quantity_distributed'];
        $uc_di  = (float) $row['unit_cost'];
        $items[$di] = [
            'quantity_distributed' => $qty_di,
            'unit_cost' => $uc_di,
            'line_total' => $qty_di * $uc_di,
            'item_description' => $row['item_description'],
            'classification_name' => $row['classification_name'] ?? '',
            'uom_name' => $row['uom_name'] ?? '',
            'abbreviation' => $row['abbreviation'] ?? '',
            'useful_life_years' => $row['useful_life_years'] ?? null,
            'inventory_item_no' => '',
            'details' => [],
        ];
    }

    if (!empty($row['did_id'])) {
        $items[$di]['details'][] = [
            'property_number' => $row['property_number'] ?? '',
            'brand' => $row['brand'] ?? '',
            'model' => $row['model'] ?? '',
            'serial_no' => $row['serial_no'] ?? '',
        ];
        if ($items[$di]['inventory_item_no'] === '' && !empty($row['property_number'])) {
            $items[$di]['inventory_item_no'] = (string) $row['property_number'];
        }
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

$groupedItems = [];
if ($isGrouped) {
    foreach ($items as $item) {
        $unitLabel = trim((string) ($item['abbreviation'] ?? $item['uom_name'] ?? ''));
        $groupKey = implode('|', [
            trim((string) ($item['classification_name'] ?? '')),
            trim((string) ($item['item_description'] ?? '')),
            $unitLabel,
            trim((string) ($item['useful_life_years'] ?? '')),
            number_format((float) ($item['unit_cost'] ?? 0), 2, '.', ''),
        ]);

        if (!isset($groupedItems[$groupKey])) {
            $groupedItems[$groupKey] = [
                'quantity_distributed' => 0.0,
                'unit_cost' => (float) ($item['unit_cost'] ?? 0),
                'line_total' => 0.0,
                'item_description' => (string) ($item['item_description'] ?? ''),
                'classification_name' => (string) ($item['classification_name'] ?? ''),
                'uom_name' => (string) ($item['uom_name'] ?? ''),
                'abbreviation' => (string) ($item['abbreviation'] ?? ''),
                'useful_life_years' => $item['useful_life_years'] ?? null,
                'inventory_item_no' => '',
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

        if (empty($groupedItems[$groupKey]['property_numbers']) && !empty($item['inventory_item_no'])) {
            $groupedItems[$groupKey]['property_numbers'][] = (string) $item['inventory_item_no'];
        }
    }

    foreach ($groupedItems as &$groupedItem) {
        $groupedItem['property_numbers'] = array_values(array_unique($groupedItem['property_numbers']));
        sort($groupedItem['property_numbers']);
        $groupedItem['inventory_item_no'] = $buildPropertyRange($groupedItem['property_numbers']);
    }
    unset($groupedItem);
}

$printItems = $isGrouped ? array_values($groupedItems) : array_values($items);

$detailIdentityLine = static function (array $detail): string {
    $parts = [];
    $brand = trim((string) ($detail['brand'] ?? ''));
    $model = trim((string) ($detail['model'] ?? ''));
    $serial = trim((string) ($detail['serial_no'] ?? ''));

    if ($brand !== '') {
        $parts[] = 'Brand: ' . $brand;
    }
    if ($model !== '') {
        $parts[] = 'Model: ' . $model;
    }
    if ($serial !== '') {
        $parts[] = 'Serial: ' . $serial;
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
    <title>ICS <?php echo h($header['document_no'] ?? $header['system_reference']); ?></title>
    <style>
        @page { size: 8.5in 13in; margin: <?php echo $isShort ? '0.5in 0.07in 0.07in 0.07in' : '0.5in 0.07in 0.07in 0.07in'; ?>; }
        body { margin: 0; font-size: 12px; color: #000; font-family: "Times New Roman", serif; }
        table { font-size: 12px; }
        .print-shell { width: 100%; max-width: none !important; margin: 0 auto; padding: 0; }
        .no-print { display: block; font-family: Arial, sans-serif; }
        .print-shell.short { font-size: 12px; }
        .print-shell.short table { font-size: 12px; }
        .print-shell.short { width: 7.5in; max-width: 7.5in !important; margin: 0 auto; padding: 0; }
        .short-copies { width: 7.5in; margin: 0 auto; }
        .short-sheet { width: 7.5in; height: 12.5in; box-sizing: border-box; display: block; overflow: hidden; }
        .short-sheet + .short-sheet { margin-top: 0; }
        .short-slot { height: 6.125in; box-sizing: border-box; display: block; overflow: hidden; }
        .short-slot + .short-slot { padding-top: 0.25in; }
        .short-slot + .short-slot { border-top: 1px dashed #bbb; }
        .short-copy { height: 6.125in; min-height: 6.125in; padding: 0; box-sizing: border-box; overflow: hidden; break-inside: avoid; page-break-inside: avoid; flex: 1 1 auto; }
        .ics-form { position: relative; break-inside: avoid; page-break-inside: avoid; }
        .appendix { position: absolute; right: 0; top: 0; font-style: italic; font-size: 12px; }
        .ics-title { text-align: center; font-weight: bold; font-size: 16px; text-transform: uppercase; margin: 18px 0 22px; }
        .line-value { display: inline-block; border-bottom: 1px solid #000; min-width: 150px; padding: 0 2px; line-height: 1.1; }
        .line-value.long { min-width: 220px; }
        .ics-meta, .ics-table, .ics-sign-table { width: 100%; border-collapse: collapse; }
        .ics-meta td { padding: 2px 4px; vertical-align: bottom; }
        .ics-meta .label { font-weight: bold; white-space: nowrap; }
        .ics-table th, .ics-table td, .ics-sign-table td, .ics-sign-table th { border: 1px solid #000; padding: 3px 4px; vertical-align: top; }
        .ics-table thead th { text-align: center; font-weight: bold; }
        .ics-body td { height: 25px; }
        .print-shell.short .ics-body td { height: 14px; }
        .ics-sign-table .sign-head { text-align: left; font-weight: bold; }
        .ics-sign-table .sign-box { height: 74px; text-align: center; vertical-align: top; font-size: 10px; padding-top: 8px; }
        .ics-sign-table .sign-line { display: none; }
        .ics-sign-table .sign-name { font-weight: 700; font-size: 14px; letter-spacing: 0.2px; line-height: 1.1; margin: 24px 0 0; }
        .ics-sign-table .meta-box { height: 52px; text-align: center; vertical-align: top; padding-top: 6px; }
        .ics-sign-table .meta-line { display: none; }
        .ics-sign-table .meta-value { margin: 10px 0 0; font-size: 12px; line-height: 1.15; }
        .ics-sign-table .meta-caption { text-align: center; font-size: 11px; }
        .ics-sign-table .underlined-value { display:inline-block; border-bottom:1px solid #000; padding:0 8px 1px; min-width:82%; }
        .ics-sign-table .meta-box .underlined-value { min-width:68%; }
        .print-shell.short .ics-sign-table .sign-box { height: 60px; font-size: 9px; padding-top: 6px; }
        .print-shell.short .ics-sign-table .sign-name { font-size: 12px; margin-top: 14px; margin-bottom: 0; }
        .print-shell.short .ics-sign-table .meta-box { height: 42px; padding-top: 4px; }
        .print-shell.short .ics-sign-table .meta-value { font-size: 10px; margin-top: 8px; }
        .print-shell.long .ics-body td { height: 24px; }
        @media print {
            .no-print, .no-print * { display: none !important; }
            thead { display: table-header-group; }
            .print-shell.long .print-copy { break-inside: avoid; page-break-inside: avoid; }
            .print-shell.short .short-copies { width: 7.5in !important; height: auto !important; overflow: visible !important; }
            .print-shell.short .short-sheet { width: 7.5in !important; height: 12.5in !important; display: block !important; overflow: hidden !important; }
                .print-shell.short .short-slot { height: 6.125in !important; display: block !important; overflow: hidden !important; }
            .print-shell.short .short-slot + .short-slot { padding-top: 0.25in !important; }
                .print-shell.short .short-copy { height: 6.125in !important; min-height: 6.125in !important; }
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
            <div class="no-print" style="margin-bottom:10px;">Distribution was just posted - ideal time to print this ICS now.</div>
        <?php endif; ?>
        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;">
            <a href="<?php echo base_url('modules/distributions/index.php?document_type=ics'); ?>" style="border:1px solid #666;padding:6px 10px;text-decoration:none;color:#111;">Back</a>
            <button onclick="window.print()" style="border:1px solid #0d6efd;background:#0d6efd;color:#fff;padding:6px 10px;">Print</button>
            <a href="<?php echo h(base_url('modules/distributions/ics.php?id=' . (int) $distributionId . $detailParam . '&print_format=short&view_mode=' . $viewMode . '&extra_rows=' . $extraRows . '&copies=' . $copyCount)); ?>" style="border:1px solid #0d6efd;padding:6px 10px;text-decoration:none;color:<?php echo $isShort ? '#fff' : '#0d6efd'; ?>;background:<?php echo $isShort ? '#0d6efd' : '#fff'; ?>;">Short</a>
            <a href="<?php echo h(base_url('modules/distributions/ics.php?id=' . (int) $distributionId . $detailParam . '&print_format=long&view_mode=' . $viewMode . '&extra_rows=' . $extraRows)); ?>" style="border:1px solid #0d6efd;padding:6px 10px;text-decoration:none;color:<?php echo !$isShort ? '#fff' : '#0d6efd'; ?>;background:<?php echo !$isShort ? '#0d6efd' : '#fff'; ?>;">Long</a>
            <a href="<?php echo h(base_url('modules/distributions/ics.php?id=' . (int) $distributionId . $detailParam . '&print_format=' . $printFormat . '&view_mode=grouped&extra_rows=' . $extraRows . ($isShort ? '&copies=' . $copyCount : ''))); ?>" style="border:1px solid #0d6efd;padding:6px 10px;text-decoration:none;color:<?php echo $isGrouped ? '#fff' : '#0d6efd'; ?>;background:<?php echo $isGrouped ? '#0d6efd' : '#fff'; ?>;">Grouped</a>
            <a href="<?php echo h(base_url('modules/distributions/ics.php?id=' . (int) $distributionId . $detailParam . '&print_format=' . $printFormat . '&view_mode=detailed&extra_rows=' . $extraRows . ($isShort ? '&copies=' . $copyCount : ''))); ?>" style="border:1px solid #0d6efd;padding:6px 10px;text-decoration:none;color:<?php echo !$isGrouped ? '#fff' : '#0d6efd'; ?>;background:<?php echo !$isGrouped ? '#0d6efd' : '#fff'; ?>;">Detailed</a>
            <a href="<?php echo h($tagPrintUrl); ?>" target="_blank" style="border:1px solid #666;padding:6px 10px;text-decoration:none;color:#111;">Print QR Tags</a>
            <form method="get" style="display:flex;align-items:center;gap:8px;" class="no-print">
                <input type="hidden" name="id" value="<?php echo (int) $distributionId; ?>">
                <?php if ($detailId > 0): ?><input type="hidden" name="detail_id" value="<?php echo (int) $detailId; ?>"><?php endif; ?>
                <input type="hidden" name="print_format" value="<?php echo h($printFormat); ?>">
                <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
                <?php if ($isShort): ?>
                <input type="hidden" name="copies" value="<?php echo (int) $copyCount; ?>">
                <?php endif; ?>
                <label for="extra_rows" style="font-size:12px;color:#666;">Extra rows</label>
                <input type="number" min="0" max="25" step="1" id="extra_rows" name="extra_rows" value="<?php echo (int) $extraRows; ?>" style="width:88px;padding:6px 8px;border:1px solid #bbb;border-radius:6px;">
                <button type="submit" style="border:1px solid #111;background:#fff;color:#111;padding:6px 10px;border-radius:6px;">Apply</button>
            </form>
            <?php if ($isShort): ?>
            <form method="get" style="display:flex;align-items:center;gap:8px;" class="no-print">
                <input type="hidden" name="id" value="<?php echo (int) $distributionId; ?>">
                <?php if ($detailId > 0): ?><input type="hidden" name="detail_id" value="<?php echo (int) $detailId; ?>"><?php endif; ?>
                <input type="hidden" name="print_format" value="short">
                <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
                <input type="hidden" name="extra_rows" value="<?php echo (int) $extraRows; ?>">
                <label for="copies" style="font-size:12px;color:#666;">Copies on sheet</label>
                <input type="number" min="1" max="20" step="1" id="copies" name="copies" value="<?php echo (int) $copyCount; ?>" style="width:88px;padding:6px 8px;border:1px solid #bbb;border-radius:6px;">
                <button type="submit" style="border:1px solid #111;background:#fff;color:#111;padding:6px 10px;border-radius:6px;">Apply</button>
            </form>
            <?php endif; ?>
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
            <div class="ics-form">
                <div class="appendix">Annex A.3</div>
                <div class="ics-title">Inventory Custodian Slip</div>

                <table class="ics-meta">
                    <tr>
                        <td style="width:14%;" class="label">Entity Name:</td>
                        <td style="width:46%;"><span class="line-value long"><?php echo h(APP_NAME); ?></span></td>
                        <td style="width:12%;" class="label">ICS No :</td>
                        <td style="width:28%;"><span class="line-value"><?php echo h($header['document_no'] ?? $header['system_reference'] ?? ''); ?></span></td>
                    </tr>
                    <tr>
                        <td class="label">Fund Cluster :</td>
                        <td><span class="line-value long"><?php echo h($fundCluster); ?></span></td>
                        <td></td>
                        <td></td>
                    </tr>
                </table>

                <table class="ics-table">
                    <thead>
                        <tr>
                            <th style="width:8%;" rowspan="2">Quanti<br>ty</th>
                            <th style="width:8%;" rowspan="2">Unit</th>
                            <th style="width:16%;" colspan="2">Amount</th>
                            <th style="width:32%;" rowspan="2">Description</th>
                            <th style="width:15%;" rowspan="2">Inventory Item No.</th>
                            <th style="width:15%;" rowspan="2">Estimated Useful Life</th>
                        </tr>
                        <tr>
                            <th style="width:8%;">Unit Cost</th>
                            <th style="width:8%;">Total Cost</th>
                        </tr>
                    </thead>
                    <tbody class="ics-body">
                        <?php foreach ($printItems as $it):
                            $qty = (float) ($it['quantity_distributed'] ?? 0);

                            if ($qty <= 0) continue;

                            $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                            $unitCost = (float) ($it['unit_cost'] ?? 0);
                            $totalCost = $qty * $unitCost;
                            $itemClass = trim((string) ($it['classification_name'] ?? ''));
                            $itemDescription = trim((string) ($it['item_description'] ?? ''));
                            $icsDescription = trim(($itemClass !== '' ? $itemClass : '') . ($itemClass !== '' && $itemDescription !== '' ? ' - ' : '') . $itemDescription);
                            $identityLines = $itemIdentityLines((array) $it);
                            $inventoryItemNo = trim((string) ($it['inventory_item_no'] ?? ''));
                            $useful = '';
                            if (!empty($it['useful_life_years'])) {
                                $useful = (string) ((int) $it['useful_life_years']) . ' yr' . ((int) $it['useful_life_years'] > 1 ? 's' : '');
                            }
                        ?>
                        <tr>
                            <td class="text-end"><?php echo h(format_quantity($qty)); ?></td>
                            <td><?php echo h($unitLabel); ?></td>
                            <td class="text-end"><?php echo h(number_format($unitCost, 2)); ?></td>
                            <td class="text-end"><?php echo h(number_format($totalCost, 2)); ?></td>
                            <td>
                                <?php echo nl2br(h($icsDescription)); ?>
                                <?php if (!empty($identityLines)): ?>
                                    <div class="small">
                                        <?php foreach ($identityLines as $identityLine): ?>
                                            <?php echo h($identityLine); ?><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($inventoryItemNo); ?></td>
                            <td><?php echo h($useful); ?></td>
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
                            <td></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <table class="ics-sign-table">
                    <tr>
                        <th class="sign-head" style="width:50%;">Received from:</th>
                        <th class="sign-head" style="width:50%;">Received by:</th>
                    </tr>
                    <tr>
                        <td class="sign-box">
                            <?php if ($supplyHeadName !== ''): ?>
                                <div class="sign-name"><span class="underlined-value"><?php echo h($supplyHeadName); ?></span></div>
                            <?php endif; ?>
                            <div>Signature Over Printed Name</div>
                        </td>
                        <td class="sign-box">
                            <?php if ($recipientHeadName !== ''): ?>
                                <div class="sign-name"><span class="underlined-value"><?php echo h($recipientHeadName); ?></span></div>
                            <?php endif; ?>
                            <div>Signature Over Printed Name</div>
                        </td>
                    </tr>
                    <tr>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"><?php echo h(trim($supplyHeadTitle . ($supplyOfficeName !== '' ? ' / ' . $supplyOfficeName : ''))); ?></span></div>
                            <div class="meta-caption">Designation/Office</div>
                        </td>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"><?php echo h(trim($recipientHeadTitle . ($recipientOfficeName !== '' ? ' / ' . $recipientOfficeName : ''))); ?></span></div>
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
            <div class="ics-form">
                <div class="appendix">Annex A.3</div>
                <div class="ics-title">Inventory Custodian Slip</div>

                <table class="ics-meta">
                    <tr>
                        <td style="width:14%;" class="label">Entity Name:</td>
                        <td style="width:46%;"><span class="line-value long"><?php echo h(APP_NAME); ?></span></td>
                        <td style="width:12%;" class="label">ICS No :</td>
                        <td style="width:28%;"><span class="line-value"><?php echo h($header['document_no'] ?? $header['system_reference'] ?? ''); ?></span></td>
                    </tr>
                    <tr>
                        <td class="label">Fund Cluster :</td>
                        <td><span class="line-value long"><?php echo h($fundCluster); ?></span></td>
                        <td></td>
                        <td></td>
                    </tr>
                </table>

                <table class="ics-table">
                    <thead>
                        <tr>
                            <th style="width:8%;" rowspan="2">Quanti<br>ty</th>
                            <th style="width:8%;" rowspan="2">Unit</th>
                            <th style="width:16%;" colspan="2">Amount</th>
                            <th style="width:32%;" rowspan="2">Description</th>
                            <th style="width:15%;" rowspan="2">Inventory Item No.</th>
                            <th style="width:15%;" rowspan="2">Estimated Useful Life</th>
                        </tr>
                        <tr>
                            <th style="width:8%;">Unit Cost</th>
                            <th style="width:8%;">Total Cost</th>
                        </tr>
                    </thead>
                    <tbody class="ics-body">
                        <?php foreach ($printItems as $it):
                            $qty = (float) ($it['quantity_distributed'] ?? 0);

                            if ($qty <= 0) continue;

                            $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                            $unitCost = (float) ($it['unit_cost'] ?? 0);
                            $totalCost = $qty * $unitCost;
                            $itemClass = trim((string) ($it['classification_name'] ?? ''));
                            $itemDescription = trim((string) ($it['item_description'] ?? ''));
                            $icsDescription = trim(($itemClass !== '' ? $itemClass : '') . ($itemClass !== '' && $itemDescription !== '' ? ' - ' : '') . $itemDescription);
                            $identityLines = $itemIdentityLines((array) $it);
                            $inventoryItemNo = trim((string) ($it['inventory_item_no'] ?? ''));
                            $useful = '';
                            if (!empty($it['useful_life_years'])) {
                                $useful = (string) ((int) $it['useful_life_years']) . ' yr' . ((int) $it['useful_life_years'] > 1 ? 's' : '');
                            }
                        ?>
                        <tr>
                            <td class="text-end"><?php echo h(format_quantity($qty)); ?></td>
                            <td><?php echo h($unitLabel); ?></td>
                            <td class="text-end"><?php echo h(number_format($unitCost, 2)); ?></td>
                            <td class="text-end"><?php echo h(number_format($totalCost, 2)); ?></td>
                            <td>
                                <?php echo nl2br(h($icsDescription)); ?>
                                <?php if (!empty($identityLines)): ?>
                                    <div class="small">
                                        <?php foreach ($identityLines as $identityLine): ?>
                                            <?php echo h($identityLine); ?><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($inventoryItemNo); ?></td>
                            <td><?php echo h($useful); ?></td>
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
                            <td></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <table class="ics-sign-table">
                    <tr>
                        <th class="sign-head" style="width:50%;">Received from:</th>
                        <th class="sign-head" style="width:50%;">Received by:</th>
                    </tr>
                    <tr>
                        <td class="sign-box">
                            <?php if ($supplyHeadName !== ''): ?>
                                <div class="sign-name"><span class="underlined-value"><?php echo h($supplyHeadName); ?></span></div>
                            <?php endif; ?>
                            <div>Signature Over Printed Name</div>
                        </td>
                        <td class="sign-box">
                            <?php if ($recipientHeadName !== ''): ?>
                                <div class="sign-name"><span class="underlined-value"><?php echo h($recipientHeadName); ?></span></div>
                            <?php endif; ?>
                            <div>Signature Over Printed Name</div>
                        </td>
                    </tr>
                    <tr>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"><?php echo h(trim($supplyHeadTitle . ($supplyOfficeName !== '' ? ' / ' . $supplyOfficeName : ''))); ?></span></div>
                            <div class="meta-caption">Designation/Office</div>
                        </td>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"><?php echo h(trim($recipientHeadTitle . ($recipientOfficeName !== '' ? ' / ' . $recipientOfficeName : ''))); ?></span></div>
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
