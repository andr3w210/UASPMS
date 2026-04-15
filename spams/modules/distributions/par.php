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

// HEADER QUERY (same as ICS header)
$headerStmt = $db->prepare(
    "SELECT d.id, d.office_id, d.system_reference, d.document_no, d.distribution_date,\n" .
    "       d.document_type, d.purpose, d.remarks, d.total_amount,\n" .
    "       o.office_name, o.office_code,\n" .
    "       dep.name AS department_name,\n" .
    "       rc.code AS responsibility_center_code,\n" .
    "       e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name,\n" .
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
    if ($officeId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT e.id, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title, o.office_name
         FROM offices o
         LEFT JOIN employees e ON e.id = o.office_head_employee_id
         WHERE o.id = ?
         LIMIT 1"
    );

    $head = [];
    if ($stmt) {
        $stmt->bind_param('i', $officeId);
        $stmt->execute();
        $head = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }

    if (!empty($head['id'])) {
        return $head;
    }

    $stmt = $db->prepare(
        "SELECT e.id, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title, o.office_name
         FROM employees e
         INNER JOIN offices o ON o.id = e.office_id
         WHERE e.office_id = ? AND e.is_active = 1 AND e.is_unit_head = 1
         ORDER BY e.last_name ASC, e.first_name ASC
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $officeId);
        $stmt->execute();
        $head = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }

    return $head;
};

$resolveSupplyOfficeHead = static function (mysqli $db) use ($resolveOfficeHead): array {
    $stmt = $db->query(
        "SELECT o.id
         FROM offices o
         WHERE o.is_active = 1
           AND (
                o.office_name LIKE '%Supply%'
                OR o.office_code LIKE '%SUPPLY%'
                OR o.office_code IN ('SO', 'SPO')
           )
         ORDER BY
            CASE
                WHEN o.office_name LIKE '%Supply Office%' THEN 0
                WHEN o.office_name LIKE '%Supply%' THEN 1
                ELSE 2
            END,
            o.office_name ASC
         LIMIT 1"
    );

    $office = $stmt ? ($stmt->fetch_assoc() ?: []) : [];
    if (empty($office['id'])) {
        return [];
    }

    return $resolveOfficeHead($db, (int) $office['id']);
};

// Redirect to ICS if needed
if (!empty($header['document_type']) && $header['document_type'] === 'ics') {
    header('Location: ics.php?id=' . $distributionId);
    exit;
}

// ITEMS QUERY (may return multiple rows per di.id due to receiving_item_details)
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
    "LEFT JOIN distribution_item_details did ON did.distribution_item_id = di.id\n" .
     "WHERE di.distribution_id = ?\n" .
     "ORDER BY di.id ASC, did.id ASC"
);

$rows = [];
if ($itemStmt) {
    $itemStmt->bind_param('i', $distributionId);
    $itemStmt->execute();
    $res = $itemStmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $itemStmt->close();
}

// Group rows by distribution item id
$items = [];
foreach ($rows as $r) {
    $di = (int) $r['di_id'];
    if (!isset($items[$di])) {
        $items[$di] = [
            'quantity_distributed' => $r['quantity_distributed'],
            'unit_cost' => $r['unit_cost'],
            'line_total' => $r['line_total'],
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

$groupedItems = [];
if ($isGrouped) {
    foreach ($items as $item) {
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
        $groupedItems[$groupKey]['line_total'] += (float) ($item['line_total'] ?? 0);

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
$receivedByName = '';
if (function_exists('employee_display_name')) {
    $receivedByName = employee_display_name($header);
} else {
    $receivedByName = trim(($header['first_name'] ?? '') . ' ' . ($header['middle_name'] ?? '') . ' ' . ($header['last_name'] ?? '') . ' ' . ($header['suffix_name'] ?? ''));
}

$recipientHead = $resolveOfficeHead($db, $officeId);
$supplyHead = $resolveSupplyOfficeHead($db);

$recipientHeadName = !empty($recipientHead) ? employee_display_name($recipientHead) : '';
$recipientHeadTitle = trim((string) ($recipientHead['position_title'] ?? ''));
$recipientOfficeName = trim((string) ($header['office_name'] ?? ''));

$supplyHeadName = !empty($supplyHead) ? employee_display_name($supplyHead) : '';
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
    <title>PAR <?php echo h($header['document_no'] ?? $header['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page { size: 8.5in 13in; margin: 0.5in; }
        body { font-size:12px; color:#000; }
        table { font-size:11px; }
        .no-print { display:block; }
        .print-shell.short { font-size: 10.5px; }
        .print-shell.short table { font-size: 10px; }
        .duplicate-host { display: none; }
        .print-shell.short {
            display: flex;
            flex-direction: column;
            gap: 0.2in;
        }
        .print-shell.short .print-copy,
        .print-shell.short .duplicate-host {
            flex: 0 0 calc((13in - 1in - 0.2in) / 2);
            min-height: calc((13in - 1in - 0.2in) / 2);
        }
        .print-shell.short .print-copy {
            margin-bottom: 0;
        }
        .print-shell.short .duplicate-host { display: block; }
        .print-shell.short .duplicate-host .no-print { display: none !important; }
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
        .par-sign-table .sign-box { height:74px; text-align:center; vertical-align:middle; font-size:10px; }
        .par-sign-table .sign-line { display:block; width:82%; margin:2px auto 4px; border-bottom:1px solid #000; height:12px; }
        .par-sign-table .sign-name { font-weight:700; text-transform:uppercase; font-size:12px; letter-spacing:0.2px; }
        .par-sign-table .meta-box { height:52px; text-align:center; vertical-align:middle; }
        .par-sign-table .meta-line { display:block; width:68%; margin:2px auto 2px; border-bottom:1px solid #000; height:12px; }
        .par-sign-table .meta-value { margin-bottom:2px; font-size:10px; line-height:1.15; }
        .par-sign-table .meta-caption { text-align:center; font-size:10px; }
        .print-shell.short .par-sign-table .sign-box { height:60px; font-size:9px; }
        .print-shell.short .par-sign-table .sign-line { height:12px; }
        .print-shell.short .par-sign-table .sign-name { font-size:10px; }
        .print-shell.short .par-sign-table .meta-box { height:42px; }
        .print-shell.short .par-sign-table .meta-value { font-size:9px; }
        .print-shell.short .par-sign-table .meta-line { height:10px; }
        @media print { .no-print { display:none; } thead { display: table-header-group; } .print-shell.short .print-copy, .print-shell.short .duplicate-host { break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="container print-shell <?php echo $isShort ? 'short' : 'long'; ?>" style="max-width:1000px;">
        <?php if (isset($_GET['created']) && $_GET['created'] == '1'): ?>
            <div class="alert alert-info no-print">Distribution was just posted — ideal time to print this PAR now.</div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-start mt-3 mb-2">
                <div class="d-flex gap-2 flex-wrap">
                <a href="<?php echo base_url('modules/distributions/index.php?document_type=par'); ?>" class="btn btn-sm btn-outline-secondary no-print">Back</a>
                <button onclick="window.print()" class="btn btn-sm btn-primary no-print">Print</button>
                <a href="<?php echo h(base_url('modules/distributions/par.php?id=' . (int) $distributionId . '&print_format=short')); ?>" class="btn btn-sm <?php echo $isShort ? 'btn-primary' : 'btn-outline-primary'; ?> no-print">Short</a>
                <a href="<?php echo h(base_url('modules/distributions/par.php?id=' . (int) $distributionId . '&print_format=long')); ?>" class="btn btn-sm <?php echo !$isShort ? 'btn-primary' : 'btn-outline-primary'; ?> no-print">Long</a>
                <a href="<?php echo h(base_url('modules/distributions/par.php?id=' . (int) $distributionId . '&print_format=' . $printFormat . '&view_mode=grouped')); ?>" class="btn btn-sm <?php echo $isGrouped ? 'btn-primary' : 'btn-outline-primary'; ?> no-print">Grouped</a>
                <a href="<?php echo h(base_url('modules/distributions/par.php?id=' . (int) $distributionId . '&print_format=' . $printFormat . '&view_mode=detailed')); ?>" class="btn btn-sm <?php echo !$isGrouped ? 'btn-primary' : 'btn-outline-primary'; ?> no-print">Detailed</a>
                <a href="<?php echo base_url('modules/property/tags.php?distribution_id=' . (int)$distributionId); ?>" class="btn btn-outline-secondary btn-sm no-print" target="_blank">Print QR Tags</a>
            </div>
            <div class="appendix">Appendix 71</div>
        </div>
        <div class="print-copy" id="printCopy">
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
                                $parDescription = trim(($itemClass !== '' ? $itemClass : '') . ($itemClass !== '' && $itemDescription !== '' ? ' - ' : '') . $itemDescription);
                            ?>
                            <?php echo nl2br(h($parDescription)); ?>
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
                        <td><?php echo h(!empty($it['date_acquired']) ? date('m/d/Y', strtotime($it['date_acquired'])) : ''); ?></td>
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
                        <div class="sign-name"><?php echo h($recipientHeadName); ?></div>
                    <?php endif; ?>
                    <span class="sign-line"></span>
                    <div>Signature over Printed Name of End User</div>
                </td>
                <td class="sign-box">
                    <?php if ($supplyHeadName !== ''): ?>
                        <div class="sign-name"><?php echo h($supplyHeadName); ?></div>
                    <?php endif; ?>
                    <span class="sign-line"></span>
                    <div>Signature over Printed Name of Supply and/or Property Custodian</div>
                </td>
            </tr>
            <tr>
                <td class="meta-box">
                    <div class="meta-value"><?php echo h(trim($recipientHeadTitle . ($recipientOfficeName !== '' ? ' / ' . $recipientOfficeName : ''))); ?></div>
                    <span class="meta-line"></span>
                    <div class="meta-caption">Position/Office</div>
                </td>
                <td class="meta-box">
                    <div class="meta-value"><?php echo h(trim($supplyHeadTitle . ($supplyOfficeName !== '' ? ' / ' . $supplyOfficeName : ''))); ?></div>
                    <span class="meta-line"></span>
                    <div class="meta-caption">Position/Office</div>
                </td>
            </tr>
            <tr>
                <td class="meta-box">
                    <div class="meta-value"><?php echo h(date('m/d/Y', strtotime($header['distribution_date'] ?? 'now'))); ?></div>
                    <span class="meta-line"></span>
                    <div class="meta-caption">Date</div>
                </td>
                <td class="meta-box">
                    <div class="meta-value"><?php echo h(date('m/d/Y', strtotime($header['distribution_date'] ?? 'now'))); ?></div>
                    <span class="meta-line"></span>
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
