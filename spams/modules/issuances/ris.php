<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$issuanceId = (int) ($_GET['id'] ?? 0);
$printFormat = ($_GET['print_format'] ?? 'long') === 'short' ? 'short' : 'long';
$isShort = $printFormat === 'short';

if (!$db || $issuanceId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

// HEADER QUERY
$headerStmt = $db->prepare(
    "SELECT i.id, i.system_reference, i.issuance_date, i.purpose, i.remarks,\n" .
    "       i.office_id,\n" .
    "       o.office_name, o.office_code,\n" .
    "       dep.name AS division_name,\n" .
    "       rc.code AS responsibility_center_code,\n" .
    "       f.fund_code, f.fund_source,\n" .
    "       e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name,\n" .
    "       e.position_title\n" .
    "FROM issuances i\n" .
    "LEFT JOIN offices o ON o.id = i.office_id\n" .
    "LEFT JOIN departments dep ON dep.id = o.department_id\n" .
    "LEFT JOIN responsibility_codes rc ON rc.office_id = o.id\n" .
    "LEFT JOIN employees e ON e.id = i.employee_id\n" .
    "LEFT JOIN funds f ON f.id = (\n" .
    "    SELECT po.fund_id\n" .
    "    FROM issuance_items ii\n" .
    "    INNER JOIN stock_items si ON si.id = ii.stock_item_id\n" .
    "    LEFT JOIN receivings r ON r.id = si.receiving_id\n" .
    "    LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id\n" .
    "    WHERE ii.issuance_id = i.id\n" .
    "    LIMIT 1\n" .
    ")\n" .
    "WHERE i.id = ?\n" .
    "LIMIT 1"
);

$header = null;
if ($headerStmt) {
    $headerStmt->bind_param('i', $issuanceId);
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

// ITEMS QUERY
$itemStmt = $db->prepare(
    "SELECT ii.id, ii.quantity_issued, ii.remarks,\n" .
    "       si.system_reference AS stock_no,\n" .
    "       si.item_description, si.unit_cost, si.item_type, si.quantity_on_hand,\n" .
    "       sm.balance_after,\n" .
    "       u.uom_name, u.abbreviation\n" .
    "FROM issuance_items ii\n" .
    "INNER JOIN stock_items si ON si.id = ii.stock_item_id\n" .
    "LEFT JOIN stock_movements sm ON sm.stock_item_id = ii.stock_item_id AND sm.reference_type = 'issuance' AND sm.reference_id = ii.issuance_id AND sm.quantity_out = ii.quantity_issued\n" .
    "LEFT JOIN unit_of_measures u ON u.id = si.unit_of_measure_id\n" .
    "WHERE ii.issuance_id = ?\n" .
    "ORDER BY ii.id ASC"
);

$items = [];
if ($itemStmt) {
    $itemStmt->bind_param('i', $issuanceId);
    $itemStmt->execute();
    $res = $itemStmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $items[] = $r;
    }
    $itemStmt->close();
}

// Helper: employee display name (use existing helper if available)
$receivedByName = '';
if (function_exists('employee_display_name')) {
    $receivedByName = employee_display_name($header);
} else {
    $receivedByName = trim(($header['first_name'] ?? '') . ' ' . ($header['middle_name'] ?? '') . ' ' . ($header['last_name'] ?? '') . ' ' . ($header['suffix_name'] ?? ''));
}

$approvedByName = get_system_setting($db, 'ris_approved_by', '');
$requestedByName = $receivedByName;
$requestedByPosition = trim((string) ($header['position_title'] ?? ''));
$supplyHead = $resolveSupplyOfficeHead($db);
$issuedByName = !empty($supplyHead) ? employee_display_name($supplyHead) : '';
$issuedByPosition = trim((string) ($supplyHead['position_title'] ?? ''));
$fundCluster = trim((string) ($header['fund_source'] ?? ''));
if ($fundCluster === '') {
    $fundCluster = trim((string) ($header['fund_code'] ?? ''));
}
if (preg_match('/(?:^|[^0-9])(0[1567])(?:[^0-9]|$)/', $fundCluster, $matches)) {
    $fundCluster = $matches[1];
} elseif (preg_match('/([0-9]{2})/', $fundCluster, $matches)) {
    $fundCluster = $matches[1];
}
$risTargetRows = $isShort ? 9 : 18;
$blankRows = max(0, $risTargetRows - count($items));
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>RIS <?php echo h($header['system_reference']); ?></title>
    <style>
        @page { size: 8.5in 13in; margin: 0.5in; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #000;
            font-family: "Times New Roman", serif;
            font-size: 12px;
            line-height: 1.2;
        }
        .no-print {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
            font-family: Arial, sans-serif;
        }
        .btn {
            display: inline-block;
            padding: 6px 10px;
            border: 1px solid #666;
            border-radius: 4px;
            color: #111;
            text-decoration: none;
            background: #fff;
            font-size: 12px;
        }
        .btn-primary {
            background: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }
        .ris-wrap {
            width: 100%;
        }
        .ris-wrap.short {
            font-size: 10.5px;
        }
        .ris-wrap.short table {
            font-size: 10px;
        }
        .duplicate-host { display: none; }
        .ris-wrap.short {
            display: flex;
            flex-direction: column;
            gap: 0.2in;
        }
        .ris-wrap.short .print-copy,
        .ris-wrap.short .duplicate-host {
            flex: 0 0 calc((13in - 1in - 0.2in) / 2);
            min-height: calc((13in - 1in - 0.2in) / 2);
        }
        .ris-wrap.short .print-copy {
            margin-bottom: 0;
        }
        .ris-wrap.short .duplicate-host { display: block; }
        .ris-wrap.short .duplicate-host .no-print { display: none !important; }
        .ris-appendix {
            text-align: right;
            font-style: italic;
            font-size: 12px;
            margin-bottom: 10px;
        }
        .ris-title {
            text-align: center;
            font-weight: bold;
            font-size: 17px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .ris-meta-table td,
        .ris-items-table th,
        .ris-items-table td,
        .ris-purpose-table td,
        .ris-sign-table td,
        .ris-sign-table th {
            border: 1px solid #000;
            padding: 3px 5px;
            vertical-align: top;
        }
        .ris-meta-table td {
            height: 22px;
        }
        .ris-label {
            white-space: nowrap;
            font-weight: bold;
        }
        .ris-fill {
            display: inline-block;
            min-width: 120px;
            border-bottom: 1px solid #000;
            padding: 0 2px;
            line-height: 1.1;
        }
        .ris-items-table thead th {
            text-align: center;
            font-weight: bold;
            font-size: 12px;
        }
        .ris-items-table thead tr:first-child th {
            font-style: italic;
            font-weight: bold;
        }
        .ris-items-table tbody td {
            height: 22px;
        }
        .ris-wrap.short .ris-items-table tbody td {
            height: 15px;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .ris-purpose-table td {
            height: 66px;
        }
        .ris-wrap.short .ris-purpose-table td {
            height: 42px;
        }
        .ris-purpose-lines {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 6px;
        }
        .ris-purpose-line {
            width: 100%;
            border-bottom: 1px solid #000;
            height: 13px;
        }
        .ris-sign-table th {
            text-align: left;
            font-weight: bold;
        }
        .ris-sign-table .row-label {
            width: 68px;
            font-weight: normal;
            white-space: nowrap;
        }
        .ris-sign-table td,
        .ris-sign-table th {
            height: 24px;
        }
        .ris-wrap.short .ris-sign-table td,
        .ris-wrap.short .ris-sign-table th {
            height: 18px;
        }
        @media print {
            .no-print { display: none; }
            thead { display: table-header-group; }
            .ris-wrap.short .print-copy,
            .ris-wrap.short .duplicate-host { break-inside: avoid; }
        }
    
            <?php echo print_page_number_css(); ?></style>
</head>
<body>
    <div class="ris-wrap <?php echo $isShort ? 'short' : 'long'; ?>">
        <div class="no-print">
            <a href="<?php echo base_url('modules/issuances/index.php'); ?>" class="btn">Back</a>
            <button onclick="window.print()" class="btn btn-primary" type="button">Print</button>
            <a href="<?php echo h(base_url('modules/issuances/ris.php?id=' . (int) $issuanceId . '&print_format=short')); ?>" class="btn <?php echo $isShort ? 'btn-primary' : ''; ?>" type="button">Short</a>
            <a href="<?php echo h(base_url('modules/issuances/ris.php?id=' . (int) $issuanceId . '&print_format=long')); ?>" class="btn <?php echo !$isShort ? 'btn-primary' : ''; ?>" type="button">Long</a>
        </div>
        <div class="print-copy" id="printCopy">
        <div class="ris-appendix">Appendix 63</div>
        <div class="ris-title">Requisition and Issue Slip</div>

        <table class="ris-meta-table">
            <tr>
                <td style="width:55%;"><span class="ris-label">Entity Name :</span> <span class="ris-fill"><?php echo h(APP_NAME); ?></span></td>
                <td style="width:45%;"><span class="ris-label">Fund Cluster :</span> <span class="ris-fill"><?php echo h($fundCluster); ?></span></td>
            </tr>
            <tr>
                <td><span class="ris-label">Division :</span> <span class="ris-fill"><?php echo h($header['division_name'] ?? ''); ?></span></td>
                <td><span class="ris-label">Responsibility Center Code :</span> <span class="ris-fill"><?php echo h($header['responsibility_center_code'] ?? ''); ?></span></td>
            </tr>
            <tr>
                <td><span class="ris-label">Office :</span> <span class="ris-fill"><?php echo h($header['office_name'] ?? ''); ?></span></td>
                <td><span class="ris-label">RIS No. :</span> <span class="ris-fill"><?php echo h($header['system_reference'] ?? ''); ?></span></td>
            </tr>
        </table>

        <table class="ris-items-table">
            <thead>
                <tr>
                    <th colspan="4">Requisition</th>
                    <th colspan="2">Stock Available ?</th>
                    <th colspan="2">Issue</th>
                </tr>
                <tr>
                    <th style="width:11%;">Stock No.</th>
                    <th style="width:6%;">Unit</th>
                    <th>Description</th>
                    <th style="width:10%;">Quantity</th>
                    <th style="width:7%;">Yes</th>
                    <th style="width:7%;">No</th>
                    <th style="width:10%;">Quantity</th>
                    <th style="width:18%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it):
                    $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                    $qty = (float) ($it['quantity_issued'] ?? 0);
                    $balanceAfter = (float) ($it['balance_after'] ?? 0);
                    $availableAtIssuance = $balanceAfter + $qty;
                    if ($availableAtIssuance <= 0) {
                        $availableAtIssuance = (float) ($it['quantity_on_hand'] ?? 0);
                    }
                ?>
                <tr>
                    <td><?php echo h($it['stock_no'] ?? ''); ?></td>
                    <td class="center"><?php echo h($unitLabel); ?></td>
                    <td><?php echo h($it['item_description'] ?? ''); ?></td>
                    <td class="right"><?php echo h(format_quantity($qty)); ?></td>
                    <td class="center"><?php echo $availableAtIssuance > 0 ? '&#10003;' : ''; ?></td>
                    <td class="center"><?php echo $availableAtIssuance > 0 ? '' : '&#10003;'; ?></td>
                    <td class="right"><?php echo h(format_quantity($qty)); ?></td>
                    <td><?php echo h($it['remarks'] ?? ''); ?></td>
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
                    <td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <table class="ris-purpose-table">
            <tr>
                <td>
                    <span class="ris-label">Purpose</span>
                    <?php if (!empty($header['purpose'])): ?>
                        <div style="margin-top:6px;"><?php echo h($header['purpose']); ?></div>
                    <?php else: ?>
                        <div class="ris-purpose-lines">
                            <div class="ris-purpose-line"></div>
                            <div class="ris-purpose-line"></div>
                            <div class="ris-purpose-line"></div>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <table class="ris-sign-table">
            <tr>
                <td class="row-label"></td>
                <th>Requested by:</th>
                <th>Approved by:</th>
                <th>Issued by:</th>
                <th>Received by:</th>
            </tr>
            <tr>
                <td class="row-label">Signature :</td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td class="row-label">Printed Name :</td>
                <td><?php echo h($requestedByName); ?></td>
                <td><?php echo h($approvedByName); ?></td>
                <td><?php echo h($issuedByName); ?></td>
                <td><?php echo h($receivedByName); ?></td>
            </tr>
            <tr>
                <td class="row-label">Designation :</td>
                <td><?php echo h($requestedByPosition); ?></td>
                <td></td>
                <td><?php echo h($issuedByPosition); ?></td>
                <td><?php echo h($header['position_title'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="row-label">Date :</td>
                <td></td>
                <td></td>
                <td><?php echo h(format_date((string) ($header['issuance_date'] ?? ''))); ?></td>
                <td><?php echo h(format_date((string) ($header['issuance_date'] ?? ''))); ?></td>
            </tr>
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
