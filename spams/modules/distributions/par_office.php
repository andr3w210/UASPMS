<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$officeId = (int) ($_GET['office_id'] ?? 0);
$legacyAssetId = (int) ($_GET['legacy_asset_id'] ?? 0);
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
$printFormat = 'long';
$isShort = false;
$viewMode = (($_GET['view_mode'] ?? 'grouped') === 'detailed') ? 'detailed' : 'grouped';
$isGrouped = $viewMode === 'grouped';
$extraRows = max(0, min(25, (int) ($_GET['extra_rows'] ?? 0)));
$offices = [];
$header = null;
$rows = [];
$validationError = '';
$selectedOfficeName = '';

$resolveOfficeHead = static function (mysqli $db, int $officeId): array {
    return employee_resolve_office_head($db, $officeId);
};

$resolveSupplyOfficeHead = static function (mysqli $db): array {
    return employee_resolve_supply_office_head($db);
};

$signatoryDisplayName = static function (array $person): string {
    $suffix = trim((string) ($person['suffix_name'] ?? ''));
    $nameParts = array_filter([
        trim((string) ($person['name_prefix'] ?? '')),
        trim((string) ($person['first_name'] ?? '')),
        trim((string) ($person['middle_name'] ?? '')),
        trim((string) ($person['last_name'] ?? '')),
    ]);
    $name = strtoupper(trim(implode(' ', $nameParts)));
    if ($suffix !== '') {
        $name .= ' ' . $suffix;
    }
    return $name;
};

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

if ($db) {
    $officeRes = $db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeRes) {
        $offices = $officeRes->fetch_all(MYSQLI_ASSOC);
    }

    foreach ($offices as $office) {
        if ((int) ($office['id'] ?? 0) === $officeId) {
            $selectedOfficeName = (string) ($office['office_name'] ?? '');
            break;
        }
    }

    if ($legacyAssetId > 0 && $officeId <= 0) {
        $legacyOfficeStmt = $db->prepare("SELECT office_id FROM legacy_assets WHERE id = ? LIMIT 1");
        if ($legacyOfficeStmt) {
            $legacyOfficeStmt->bind_param('i', $legacyAssetId);
            $legacyOfficeStmt->execute();
            $legacyOffice = $legacyOfficeStmt->get_result()->fetch_assoc();
            $legacyOfficeStmt->close();
            $officeId = (int) ($legacyOffice['office_id'] ?? 0);
        }
    }

    if ($legacyAssetId > 0) {
        $legacyValidationStmt = $db->prepare(
            "SELECT property_number, item_description, office_id, employee_id, item_type
             FROM legacy_assets
             WHERE id = ?
             LIMIT 1"
        );
        if ($legacyValidationStmt) {
            $legacyValidationStmt->bind_param('i', $legacyAssetId);
            $legacyValidationStmt->execute();
            $legacyRow = $legacyValidationStmt->get_result()->fetch_assoc() ?: null;
            $legacyValidationStmt->close();

            if (!$legacyRow) {
                $validationError = 'Legacy asset record not found for printing.';
            } else {
                $missing = [];
                if (trim((string) ($legacyRow['property_number'] ?? '')) === '') {
                    $missing[] = 'Property Number';
                }
                if (trim((string) ($legacyRow['item_description'] ?? '')) === '') {
                    $missing[] = 'Description';
                }
                if ((int) ($legacyRow['office_id'] ?? 0) <= 0) {
                    $missing[] = 'Office Assignment';
                }
                if ((int) ($legacyRow['employee_id'] ?? 0) <= 0) {
                    $missing[] = 'Accountable Employee';
                }
                if ($missing) {
                    $validationError = 'Printing is blocked. Complete this legacy asset first: ' . implode(', ', $missing) . '.';
                } elseif (($legacyRow['item_type'] ?? '') !== 'equipment') {
                    $validationError = 'PAR printing is allowed for legacy equipment assets only.';
                }
            }
        }
    }

    if (($officeId > 0 || $legacyAssetId > 0) && $validationError === '') {
        $headStmt = $db->prepare(
            "SELECT o.id, o.office_name, o.office_code, rc.code AS rc_code
             FROM offices o
             LEFT JOIN responsibility_codes rc ON rc.office_id = o.id
             WHERE o.id = ?
             LIMIT 1"
        );
        if ($headStmt && $officeId > 0) {
            $headStmt->bind_param('i', $officeId);
            $headStmt->execute();
            $header = $headStmt->get_result()->fetch_assoc() ?: null;
            $headStmt->close();
        }

        if (!$header && $legacyAssetId > 0) {
            $fallbackStmt = $db->prepare(
                "SELECT o.id, COALESCE(o.office_name, 'Unassigned Office') AS office_name, o.office_code
                 FROM legacy_assets la
                 LEFT JOIN offices o ON o.id = la.office_id
                 WHERE la.id = ?
                 LIMIT 1"
            );
            if ($fallbackStmt) {
                $fallbackStmt->bind_param('i', $legacyAssetId);
                $fallbackStmt->execute();
                $fallback = $fallbackStmt->get_result()->fetch_assoc() ?: [];
                $fallbackStmt->close();
                $header = [
                    'id' => (int) ($fallback['id'] ?? 0),
                    'office_name' => (string) ($fallback['office_name'] ?? 'Unassigned Office'),
                    'office_code' => (string) ($fallback['office_code'] ?? ''),
                    'rc_code' => '',
                ];
            }
        }

        if ($officeId > 0) {
            $systemStmt = $db->prepare(
                "SELECT
                    'system' AS source_type,
                    did.property_number,
                    did.brand,
                    did.model,
                    did.serial_no,
                    poi.item_description,
                    c.classification_name,
                    c.classification_family,
                    u.abbreviation,
                    ri.unit_cost,
                    r.received_date AS date_acquired
                 FROM distribution_item_details did
                 INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                 INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'par'
                 INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
                 INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
                 INNER JOIN receivings r ON r.id = ri.receiving_id
                 LEFT JOIN classifications c ON c.id = poi.classification_id
                 LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
                 WHERE d.office_id = ?
                   AND did.is_distributed = 1
                   AND (did.is_disposed IS NULL OR did.is_disposed = 0)
                 ORDER BY did.property_number ASC, did.id ASC"
            );
            if ($systemStmt) {
                $systemStmt->bind_param('i', $officeId);
                $systemStmt->execute();
                $res = $systemStmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $rows[] = $row;
                }
                $systemStmt->close();
            }
        }

        if ($officeId > 0) {
            $legacyStmt = $db->prepare(
                "SELECT
                    'legacy' AS source_type,
                    la.property_number,
                    la.brand,
                    la.model,
                    la.serial_no,
                    la.item_description,
                    c.classification_name,
                    c.classification_family,
                    '' AS abbreviation,
                    la.unit_cost,
                    la.acquisition_date AS date_acquired
                 FROM legacy_assets la
                 LEFT JOIN classifications c ON c.id = la.classification_id
                 WHERE la.is_active = 1
                   AND la.item_type = 'equipment'
                   AND la.office_id = ?
                 ORDER BY la.property_number ASC, la.id ASC"
            );
            if ($legacyStmt) {
                $legacyStmt->bind_param('i', $officeId);
                $legacyStmt->execute();
                $res = $legacyStmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $rows[] = $row;
                }
                $legacyStmt->close();
            }
        } elseif ($legacyAssetId > 0) {
            $legacyStmt = $db->prepare(
                "SELECT
                    'legacy' AS source_type,
                    la.property_number,
                    la.brand,
                    la.model,
                    la.serial_no,
                    la.item_description,
                    c.classification_name,
                    c.classification_family,
                    '' AS abbreviation,
                    la.unit_cost,
                    la.acquisition_date AS date_acquired
                 FROM legacy_assets la
                 LEFT JOIN classifications c ON c.id = la.classification_id
                 WHERE la.id = ?
                 LIMIT 1"
            );
            if ($legacyStmt) {
                $legacyStmt->bind_param('i', $legacyAssetId);
                $legacyStmt->execute();
                $res = $legacyStmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $rows[] = $row;
                }
                $legacyStmt->close();
            }
        }
    }
}

$groupedRows = [];
if ($isGrouped) {
    foreach ($rows as $row) {
        $unitLabel = trim((string) ($row['abbreviation'] ?? 'unit'));
        $groupKey = implode('|', [
            trim((string) ($row['classification_name'] ?? '')),
            trim((string) ($row['classification_family'] ?? '')),
            trim((string) ($row['item_description'] ?? '')),
            $unitLabel,
            trim((string) ($row['date_acquired'] ?? '')),
            number_format((float) ($row['unit_cost'] ?? 0), 2, '.', ''),
        ]);

        if (!isset($groupedRows[$groupKey])) {
            $groupedRows[$groupKey] = [
                'abbreviation' => (string) ($row['abbreviation'] ?? 'unit'),
                'classification_name' => (string) ($row['classification_name'] ?? ''),
                'classification_family' => (string) ($row['classification_family'] ?? ''),
                'item_description' => (string) ($row['item_description'] ?? ''),
                'date_acquired' => (string) ($row['date_acquired'] ?? ''),
                'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                'line_total' => 0.0,
                'quantity' => 0,
                'property_number' => '',
                'property_numbers' => [],
                'details' => [],
            ];
        }

        $groupedRows[$groupKey]['quantity']++;
        $groupedRows[$groupKey]['line_total'] += (float) ($row['unit_cost'] ?? 0);
        if (!empty($row['property_number'])) {
            $groupedRows[$groupKey]['property_numbers'][] = (string) $row['property_number'];
        }
        $groupedRows[$groupKey]['details'][] = [
            'brand' => (string) ($row['brand'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'serial_no' => (string) ($row['serial_no'] ?? ''),
        ];
    }

    foreach ($groupedRows as &$groupedRow) {
        $groupedRow['property_numbers'] = array_values(array_unique($groupedRow['property_numbers']));
        sort($groupedRow['property_numbers']);
        $groupedRow['property_number'] = $buildPropertyRange($groupedRow['property_numbers']);
    }
    unset($groupedRow);
}

$printRows = $isGrouped ? array_values($groupedRows) : array_map(static function (array $row): array {
    $row['quantity'] = 1;
    $row['line_total'] = (float) ($row['unit_cost'] ?? 0);
    $row['details'] = [[
        'brand' => (string) ($row['brand'] ?? ''),
        'model' => (string) ($row['model'] ?? ''),
        'serial_no' => (string) ($row['serial_no'] ?? ''),
    ]];
    return $row;
}, $rows);

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

$itemIdentityLines = static function (array $row) use ($detailIdentityLine): array {
    $lines = [];
    $details = array_key_exists('details', $row) ? (array) $row['details'] : [$row];
    foreach ($details as $detail) {
        $line = $detailIdentityLine((array) $detail);
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return array_values(array_unique($lines));
};

$recipientHead = ($db && $officeId > 0) ? $resolveOfficeHead($db, $officeId) : [];
$supplyHead = $db ? $resolveSupplyOfficeHead($db) : [];

$recipientHeadName = !empty($recipientHead) ? $signatoryDisplayName($recipientHead) : '';
$recipientHeadTitle = trim((string) ($recipientHead['position_title'] ?? ''));
$recipientOfficeName = trim((string) ($header['office_name'] ?? ''));

$supplyHeadName = !empty($supplyHead) ? $signatoryDisplayName($supplyHead) : '';
$supplyHeadTitle = trim((string) ($supplyHead['position_title'] ?? ''));
$supplyOfficeName = trim((string) ($supplyHead['office_name'] ?? 'Supply Office'));

$fundCluster = '';
if ($db && $officeId > 0 && $legacyAssetId <= 0) {
    $funds = [];
    $fundStmt = $db->prepare(
        "SELECT DISTINCT COALESCE(NULLIF(TRIM(f.fund_source), ''), NULLIF(TRIM(f.fund_code), '')) AS fund_label
         FROM distributions d
         INNER JOIN distribution_items di ON di.distribution_id = d.id
         INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
         INNER JOIN receivings r ON r.id = ri.receiving_id
         INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
         LEFT JOIN funds f ON f.id = po.fund_id
         WHERE d.office_id = ?
           AND d.status = 'posted'
           AND d.document_type = 'par'"
    );
    if ($fundStmt) {
        $fundStmt->bind_param('i', $officeId);
        $fundStmt->execute();
        $fundRes = $fundStmt->get_result();
        while ($fundRes && ($fundRow = $fundRes->fetch_assoc())) {
            $label = trim((string) ($fundRow['fund_label'] ?? ''));
            if ($label !== '') {
                $funds[] = $label;
            }
        }
        $fundStmt->close();
    }
    $funds = array_values(array_unique($funds));
    if (count($funds) === 1) {
        $fundCluster = $funds[0];
    } elseif (count($funds) > 1) {
        $fundCluster = 'Various';
    }
}

$officePrintNo = 'PAR-OFFICE-' . str_pad((string) max(1, $officeId), 4, '0', STR_PAD_LEFT);
$blankRows = $extraRows;
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PAR by Office</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page { size: 8.5in 13in; margin: 0.5in; }
        body { margin: 0; font-size:12px; color:#000; font-family: "Times New Roman", serif; }
        table { font-size:11px; }
        .no-print { display:block; font-family: Arial, sans-serif; }
        .print-shell.short { font-size: 10.5px; }
        .print-shell.short table { font-size: 10px; }
        .duplicate-host { display: none; }
        .print-shell.short { display: flex; flex-direction: column; gap: 0.2in; }
        .print-shell.short .print-copy,
        .print-shell.short .duplicate-host {
            flex: 0 0 calc((13in - 1in - 0.2in) / 2);
            min-height: calc((13in - 1in - 0.2in) / 2);
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
        .par-sign-table .sign-head { font-weight:bold; text-align:left; }
        .par-sign-table .sign-box { height:74px; text-align:center; vertical-align:top; font-size:10px; padding-top:8px; }
        .par-sign-table .sign-name { font-weight:700; font-size:12px; letter-spacing:0.2px; line-height:1.1; margin:26px 0 0; }
        .par-sign-table .meta-box { height:52px; text-align:center; vertical-align:top; padding-top:6px; }
        .par-sign-table .meta-value { margin: 10px 0 0; font-size:10px; line-height:1.15; }
        .par-sign-table .meta-caption { text-align:center; font-size:10px; }
        .par-sign-table .underlined-value { display:inline-block; border-bottom:1px solid #000; padding:0 8px 1px; min-width:82%; }
        .par-sign-table .meta-box .underlined-value { min-width:68%; }
        .print-shell.short .par-sign-table .sign-box { height:60px; font-size:9px; padding-top:6px; }
        .print-shell.short .par-sign-table .sign-name { font-size:10px; margin-top:16px; margin-bottom:0; }
        .print-shell.short .par-sign-table .meta-box { height:42px; padding-top:4px; }
        .print-shell.short .par-sign-table .meta-value { font-size:9px; margin-top:8px; }
        @media print { .no-print { display:none !important; } thead { display: table-header-group; } .print-shell.short .print-copy, .print-shell.short .duplicate-host { break-inside: avoid; } }
    </style>
</head>
<body>
<div class="container print-shell <?php echo $isShort ? 'short' : 'long'; ?>" style="max-width:1000px;">
    <div class="no-print mt-3 mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h4 class="mb-0">PAR Print by Office</h4>
                <div class="small text-muted">Bulk print equipment currently accountable to one office.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo base_url('modules/distributions/index.php?document_type=par'); ?>" class="btn btn-outline-secondary">Back to Distribution</a>
                <?php if (($officeId > 0 || $legacyAssetId > 0) && $rows): ?>
                    <a href="<?php echo h(base_url('modules/distributions/par_office.php?office_id=' . $officeId . '&view_mode=' . $viewMode . '&extra_rows=' . $extraRows . ($legacyAssetId > 0 ? '&legacy_asset_id=' . $legacyAssetId : '') . '&print=1')); ?>" class="btn btn-primary">Print Current Result</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($validationError !== ''): ?>
            <div class="alert alert-warning"><?php echo h($validationError); ?></div>
        <?php endif; ?>
        <form method="get" class="row g-3 align-items-end">
            <?php if ($legacyAssetId > 0): ?>
                <input type="hidden" name="legacy_asset_id" value="<?php echo (int) $legacyAssetId; ?>">
            <?php endif; ?>
            <div class="col-lg-7 col-md-12">
                <label class="form-label">Office</label>
                <input type="hidden" name="office_id" id="office_id" value="<?php echo (int) $officeId; ?>">
                <input type="text" class="form-control" id="office_name" list="office_options" value="<?php echo h($selectedOfficeName); ?>" placeholder="Search office" required>
                <datalist id="office_options">
                    <?php foreach ($offices as $office): ?>
                        <option data-office-id="<?php echo (int) $office['id']; ?>" value="<?php echo h($office['office_name']); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="form-label">View</label>
                <div class="btn-group w-100" role="group" aria-label="PAR view mode">
                    <a href="<?php echo h(base_url('modules/distributions/par_office.php?office_id=' . $officeId . '&view_mode=grouped' . ($legacyAssetId > 0 ? '&legacy_asset_id=' . $legacyAssetId : ''))); ?>" class="btn btn-outline-primary <?php echo $isGrouped ? 'active' : ''; ?>">Grouped</a>
                    <a href="<?php echo h(base_url('modules/distributions/par_office.php?office_id=' . $officeId . '&view_mode=detailed' . ($legacyAssetId > 0 ? '&legacy_asset_id=' . $legacyAssetId : ''))); ?>" class="btn btn-outline-primary <?php echo !$isGrouped ? 'active' : ''; ?>">Detailed</a>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-primary flex-fill">Load PAR</button>
                    <a href="<?php echo base_url('modules/distributions/par_office.php'); ?>" class="btn btn-outline-secondary flex-fill">Clear</a>
                </div>
            </div>
        </form>
        <?php if (($officeId > 0 || $legacyAssetId > 0) && $rows): ?>
        <div class="d-flex align-items-center gap-2 mt-2 no-print">
            <form method="get" class="d-flex align-items-center gap-2">
                <input type="hidden" name="office_id" value="<?php echo (int) $officeId; ?>">
                <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
                <?php if ($legacyAssetId > 0): ?><input type="hidden" name="legacy_asset_id" value="<?php echo (int) $legacyAssetId; ?>"><?php endif; ?>
                <label for="extra_rows_par" style="font-size:12px;color:#666;white-space:nowrap;">Extra rows</label>
                <input type="number" min="0" max="25" step="1" id="extra_rows_par" name="extra_rows" value="<?php echo (int) $extraRows; ?>" style="width:80px;" class="form-control form-control-sm">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php if (($officeId > 0 || $legacyAssetId > 0) && $header): ?>
        <div class="print-copy" id="printCopy">
            <div class="par-form">
                <div class="appendix">Appendix 71</div>
                <div class="par-title">Property Acknowledgment Receipt</div>

                <table class="par-meta">
                    <tr>
                        <td style="width:14%;" class="label">Entity Name :</td>
                        <td style="width:39%;"><span class="line-value long"><?php echo h(APP_NAME); ?></span></td>
                        <td style="width:14%;" class="label">PAR No. :</td>
                        <td style="width:33%;"><span class="line-value"><?php echo h($officePrintNo); ?></span></td>
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
                        <?php $total = 0.0; foreach ($printRows as $row): $total += (float) ($row['line_total'] ?? 0); ?>
                            <tr>
                                <td class="text-end"><?php echo h(format_quantity((float) ($row['quantity'] ?? 1))); ?></td>
                                <td><?php echo h($row['abbreviation'] ?: 'unit'); ?></td>
                                <td>
                                    <?php
                                        $itemClass = trim((string) ($row['classification_name'] ?? ''));
                                        $itemDescription = trim((string) ($row['item_description'] ?? ''));
                                        $parDescription = trim(($itemClass !== '' ? $itemClass : '') . ($itemClass !== '' && $itemDescription !== '' ? ' - ' : '') . $itemDescription);
                                        $identityLines = $itemIdentityLines((array) $row);
                                    ?>
                                    <?php echo nl2br(h($parDescription)); ?>
                                    <?php if (!empty($identityLines)): ?>
                                        <div class="small text-muted">
                                            <?php foreach ($identityLines as $identityLine): ?>
                                                <?php echo h($identityLine); ?><br>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo h($row['property_number'] ?? ''); ?>
                                    <?php if ($isGrouped && !empty($row['property_numbers'])): ?>
                                        <div class="small text-muted"><?php echo h(count($row['property_numbers'])); ?> property no.(s)</div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h(!empty($row['date_acquired']) ? date('m/d/Y', strtotime($row['date_acquired'])) : ''); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['line_total'] ?? 0), 2)); ?></td>
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
                            <div class="meta-value"><span class="underlined-value"><?php echo h(date('m/d/Y')); ?></span></div>
                            <div class="meta-caption">Date</div>
                        </td>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"><?php echo h(date('m/d/Y')); ?></span></div>
                            <div class="meta-caption">Date</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <div class="duplicate-host" id="duplicateHost"></div>
    <?php elseif ($officeId > 0 || $legacyAssetId > 0): ?>
        <div class="alert alert-info">No PAR items found for the selected office.</div>
    <?php endif; ?>
</div>
<?php if ($autoPrint && $rows): ?><script>window.addEventListener('load', function(){ window.print(); });</script><?php endif; ?>
<?php if ($isShort && (($officeId > 0 || $legacyAssetId > 0) && $header)): ?>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    const officeInput = document.getElementById('office_name');
    const officeIdInput = document.getElementById('office_id');
    const officeOptions = Array.from(document.querySelectorAll('#office_options option'));

    if (!officeInput || !officeIdInput) {
        return;
    }

    const syncOfficeId = function () {
        const match = officeOptions.find(function (option) {
            return option.value === officeInput.value;
        });
        officeIdInput.value = match ? (match.dataset.officeId || '') : '';
    };

    officeInput.addEventListener('input', syncOfficeId);
    officeInput.form && officeInput.form.addEventListener('submit', function (event) {
        syncOfficeId();
        if (!officeIdInput.value) {
            event.preventDefault();
            officeInput.setCustomValidity('Please select a valid office from the list.');
            officeInput.reportValidity();
        } else {
            officeInput.setCustomValidity('');
        }
    });
});
</script>
</body>
</html>
