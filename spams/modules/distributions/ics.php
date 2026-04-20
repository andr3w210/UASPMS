<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$distributionId = (int) ($_GET['id'] ?? 0);
$printFormat = ($_GET['print_format'] ?? 'long') === 'short' ? 'short' : 'long';
$isShort = $printFormat === 'short';
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
    header('Location: par.php?id=' . (int) $distributionId);
    exit;
}

$officeId = (int) ($header['office_id'] ?? 0);

$resolveOfficeHead = static function (mysqli $db, int $officeId): array {
    return employee_resolve_office_head($db, $officeId);
};

$resolveSupplyOfficeHead = static function (mysqli $db): array {
    return employee_resolve_supply_office_head($db);
};

$itemStmt = $db->prepare(
    "SELECT di.id AS di_id, di.quantity_distributed, di.unit_cost, di.line_total,
            poi.item_description,
            u.uom_name, u.abbreviation,
            c.classification_name, c.useful_life_years,
            did.property_number, did.id AS did_id
     FROM distribution_items di
     INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
     INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
     LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
     LEFT JOIN classifications c ON c.id = poi.classification_id
     LEFT JOIN distribution_item_details did ON did.distribution_item_id = di.id
     WHERE di.distribution_id = ?
     ORDER BY di.id ASC, did.id ASC"
);

$rows = [];
if ($itemStmt) {
    $itemStmt->bind_param('i', $distributionId);
    $itemStmt->execute();
    $res = $itemStmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $itemStmt->close();
}

$items = [];
foreach ($rows as $row) {
    $di = (int) $row['di_id'];
    if (!isset($items[$di])) {
        $items[$di] = [
            'quantity_distributed' => $row['quantity_distributed'],
            'unit_cost' => $row['unit_cost'],
            'line_total' => $row['line_total'],
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
                'property_numbers' => [],
            ];
        }

        $groupedItems[$groupKey]['quantity_distributed'] += (float) ($item['quantity_distributed'] ?? 0);
        $groupedItems[$groupKey]['line_total'] += (float) ($item['line_total'] ?? 0);

        foreach ((array) ($item['details'] ?? []) as $detail) {
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

$recipientHead = $resolveOfficeHead($db, $officeId);
$supplyHead = $resolveSupplyOfficeHead($db);

$signatoryDisplayName = static function (array $person): string {
    if (function_exists('person_full_name')) {
        return person_full_name($person);
    }

    return trim(implode(' ', array_filter([
        trim((string) ($person['name_prefix'] ?? '')),
        trim((string) ($person['first_name'] ?? '')),
        trim((string) ($person['middle_name'] ?? '')),
        trim((string) ($person['last_name'] ?? '')),
        trim((string) ($person['suffix_name'] ?? '')),
    ])));
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

$targetRows = $isShort ? 10 : 22;
$blankRows = max(0, $targetRows - count($printItems));
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ICS <?php echo h($header['document_no'] ?? $header['system_reference']); ?></title>
    <style>
        @page { size: 8.5in 13in; margin: 0.5in; }
        body { margin: 0; font-size: 12px; color: #000; font-family: "Times New Roman", serif; }
        table { font-size: 11px; }
        .no-print { display: block; font-family: Arial, sans-serif; }
        .duplicate-host { display: none; }
        .print-shell.short { font-size: 10.5px; }
        .print-shell.short table { font-size: 10px; }
        .print-shell.short { display: flex; flex-direction: column; gap: 0.2in; }
        .print-shell.short .print-copy,
        .print-shell.short .duplicate-host {
            flex: 0 0 calc((13in - 1in - 0.2in) / 2);
            min-height: calc((13in - 1in - 0.2in) / 2);
        }
        .print-shell.short .duplicate-host { display: block; }
        .print-shell.short .duplicate-host .no-print { display: none !important; }
        .ics-form { position: relative; }
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
        .ics-sign-table .sign-name { font-weight: 700; text-transform: uppercase; font-size: 12px; letter-spacing: 0.2px; line-height: 1.1; margin: 26px 0 0; }
        .ics-sign-table .meta-box { height: 52px; text-align: center; vertical-align: top; padding-top: 6px; }
        .ics-sign-table .meta-line { display: none; }
        .ics-sign-table .meta-value { margin: 10px 0 0; font-size: 10px; line-height: 1.15; }
        .ics-sign-table .meta-caption { text-align: center; font-size: 10px; }
        .ics-sign-table .underlined-value { display:inline-block; border-bottom:1px solid #000; padding:0 8px 1px; min-width:82%; }
        .ics-sign-table .meta-box .underlined-value { min-width:68%; }
        .print-shell.short .ics-sign-table .sign-box { height: 60px; font-size: 9px; padding-top: 6px; }
        .print-shell.short .ics-sign-table .sign-name { font-size: 10px; margin-top: 16px; margin-bottom: 0; }
        .print-shell.short .ics-sign-table .meta-box { height: 42px; padding-top: 4px; }
        .print-shell.short .ics-sign-table .meta-value { font-size: 9px; margin-top: 8px; }
        @media print {
            .no-print { display: none !important; }
            thead { display: table-header-group; }
            .print-shell.short .print-copy,
            .print-shell.short .duplicate-host { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container print-shell <?php echo $isShort ? 'short' : 'long'; ?>" style="max-width:1000px;">
        <?php if (isset($_GET['created']) && $_GET['created'] == '1'): ?>
            <div class="no-print" style="margin-bottom:10px;">Distribution was just posted - ideal time to print this ICS now.</div>
        <?php endif; ?>
        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;">
            <a href="<?php echo base_url('modules/distributions/index.php?document_type=ics'); ?>" style="border:1px solid #666;padding:6px 10px;text-decoration:none;color:#111;">Back</a>
            <button onclick="window.print()" style="border:1px solid #0d6efd;background:#0d6efd;color:#fff;padding:6px 10px;">Print</button>
            <a href="<?php echo h(base_url('modules/distributions/ics.php?id=' . (int) $distributionId . '&print_format=short')); ?>" style="border:1px solid #0d6efd;padding:6px 10px;text-decoration:none;color:<?php echo $isShort ? '#fff' : '#0d6efd'; ?>;background:<?php echo $isShort ? '#0d6efd' : '#fff'; ?>;">Short</a>
            <a href="<?php echo h(base_url('modules/distributions/ics.php?id=' . (int) $distributionId . '&print_format=long')); ?>" style="border:1px solid #0d6efd;padding:6px 10px;text-decoration:none;color:<?php echo !$isShort ? '#fff' : '#0d6efd'; ?>;background:<?php echo !$isShort ? '#0d6efd' : '#fff'; ?>;">Long</a>
            <a href="<?php echo h(base_url('modules/distributions/ics.php?id=' . (int) $distributionId . '&print_format=' . $printFormat . '&view_mode=grouped')); ?>" style="border:1px solid #0d6efd;padding:6px 10px;text-decoration:none;color:<?php echo $isGrouped ? '#fff' : '#0d6efd'; ?>;background:<?php echo $isGrouped ? '#0d6efd' : '#fff'; ?>;">Grouped</a>
            <a href="<?php echo h(base_url('modules/distributions/ics.php?id=' . (int) $distributionId . '&print_format=' . $printFormat . '&view_mode=detailed')); ?>" style="border:1px solid #0d6efd;padding:6px 10px;text-decoration:none;color:<?php echo !$isGrouped ? '#fff' : '#0d6efd'; ?>;background:<?php echo !$isGrouped ? '#0d6efd' : '#fff'; ?>;">Detailed</a>
            <a href="<?php echo base_url('modules/property/tags.php?distribution_id=' . (int) $distributionId); ?>" target="_blank" style="border:1px solid #666;padding:6px 10px;text-decoration:none;color:#111;">Print QR Tags</a>
        </div>
        <div class="print-copy" id="printCopy">
            <div class="ics-form">
                <div class="appendix">Appendix 59</div>
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
                            $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                            $unitCost = (float) ($it['unit_cost'] ?? 0);
                            $totalCost = (float) ($it['line_total'] ?? ($unitCost * $qty));
                            $itemClass = trim((string) ($it['classification_name'] ?? ''));
                            $itemDescription = trim((string) ($it['item_description'] ?? ''));
                            $icsDescription = trim(($itemClass !== '' ? $itemClass : '') . ($itemClass !== '' && $itemDescription !== '' ? ' - ' : '') . $itemDescription);
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
                            <td><?php echo nl2br(h($icsDescription)); ?></td>
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
                            <div class="meta-value"><span class="underlined-value"><?php echo h(date('m/d/Y', strtotime($header['distribution_date'] ?? 'now'))); ?></span></div>
                            <div class="meta-caption">Date</div>
                        </td>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"><?php echo h(date('m/d/Y', strtotime($header['distribution_date'] ?? 'now'))); ?></span></div>
                            <div class="meta-caption">Date</div>
                        </td>
                    </tr>
                </table>
            </div>
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
</body>
</html>
