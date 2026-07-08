<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$receivingId = (int) ($_GET['receiving_id'] ?? 0);
$poId = (int) ($_GET['po_id'] ?? 0);
$officeId = (int) ($_GET['office_id'] ?? 0);

if (!$db) {
    http_response_code(404);
    echo 'Invalid parameters.';
    exit;
}

$mode = 'po_office';
if ($receivingId > 0) {
    $mode = 'receiving';
}

$po = null;
$office = null;
if ($mode === 'receiving') {
    $rStmt = $db->prepare(
        "SELECT r.id, r.system_reference AS iar_ref, r.ris_no, r.received_date, r.purchase_order_id, po.po_number, po.po_date, po.purpose, s.supplier_name, f.fund_code
         FROM receivings r
         INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
         INNER JOIN suppliers s ON s.id = po.supplier_id
         LEFT JOIN funds f ON f.id = po.fund_id
         WHERE r.id = ? LIMIT 1"
    );
    if ($rStmt) {
        $rStmt->bind_param('i', $receivingId);
        $rStmt->execute();
        $po = $rStmt->get_result()->fetch_assoc() ?: null;
        $rStmt->close();
    }
    if (!$po) {
        http_response_code(404);
        echo 'Receiving not found.';
        exit;
    }
} else {
    $poStmt = $db->prepare(
        "SELECT po.id, po.po_number, po.po_date, po.purpose,
               s.supplier_name, f.fund_code,
               rc.code AS responsibility_center_code,
               dep.name AS department_name
        FROM purchase_orders po
        INNER JOIN suppliers s ON s.id = po.supplier_id
        INNER JOIN funds f ON f.id = po.fund_id
        LEFT JOIN responsibility_codes rc ON rc.office_id = ?
        LEFT JOIN offices o ON o.id = ?
        LEFT JOIN departments dep ON dep.id = o.department_id
        WHERE po.id = ?
        LIMIT 1"
    );
    if ($poStmt) {
        $poStmt->bind_param('iii', $officeId, $officeId, $poId);
        $poStmt->execute();
        $po = $poStmt->get_result()->fetch_assoc() ?: null;
        $poStmt->close();
    }
    if (!$po) {
        http_response_code(404);
        echo 'PO not found.';
        exit;
    }

    $officeStmt = $db->prepare("SELECT office_name, office_code FROM offices WHERE id = ? LIMIT 1");
    if ($officeStmt) {
        $officeStmt->bind_param('i', $officeId);
        $officeStmt->execute();
        $office = $officeStmt->get_result()->fetch_assoc() ?: null;
        $officeStmt->close();
    }
    if (!$office) {
        http_response_code(404);
        echo 'Office not found.';
        exit;
    }
}

if ($mode === 'receiving') {
    $suppliesStmt = $db->prepare(
        "SELECT poi.line_no, poi.item_description, u.uom_name, u.abbreviation, '' AS stock_no,
                ri.quantity_received AS quantity, r.system_reference AS doc_ref
         FROM receiving_items ri
         INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
         LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
         INNER JOIN receivings r ON r.id = ri.receiving_id AND r.id = ?
         WHERE poi.item_type IN ('supply', 'semi_expendable')
         ORDER BY poi.line_no ASC"
    );
    $supplies = [];
    if ($suppliesStmt) {
        $suppliesStmt->bind_param('i', $receivingId);
        $suppliesStmt->execute();
        $supplies = $suppliesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $suppliesStmt->close();
    }
} else {
    $suppliesStmt = $db->prepare(
        "SELECT poi.line_no, poi.item_description, u.uom_name, u.abbreviation, si.system_reference AS stock_no,
                ii.quantity_issued AS quantity, iss.system_reference AS doc_ref
         FROM issuance_items ii
         INNER JOIN issuances iss ON iss.id = ii.issuance_id AND iss.office_id = ?
         INNER JOIN stock_items si ON si.id = ii.stock_item_id
         INNER JOIN receiving_items ri ON ri.id = si.receiving_item_id
         INNER JOIN receivings r ON r.id = ri.receiving_id
         INNER JOIN purchase_orders po ON po.id = r.purchase_order_id AND po.id = ?
         INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
         LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
         ORDER BY poi.line_no ASC, ii.id ASC"
    );
    $supplies = [];
    if ($suppliesStmt) {
        $suppliesStmt->bind_param('ii', $officeId, $poId);
        $suppliesStmt->execute();
        $supplies = $suppliesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $suppliesStmt->close();
    }
}

$distribStmt = $db->prepare(
    "SELECT poi.line_no, poi.item_description, u.uom_name, u.abbreviation,
            si.system_reference AS stock_no, di.quantity_distributed AS quantity,
            d.document_no AS doc_ref
     FROM distribution_items di
     INNER JOIN distributions d ON d.id = di.distribution_id AND d.status != 'cancelled' AND d.office_id = ?
     INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
     INNER JOIN receivings r ON r.id = ri.receiving_id
     INNER JOIN purchase_orders po ON po.id = r.purchase_order_id AND po.id = ?
     INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
     LEFT JOIN stock_items si ON si.receiving_item_id = ri.id
     LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
     ORDER BY poi.line_no ASC, di.id ASC"
);

$distributed = [];
if ($distribStmt) {
    $distribStmt->bind_param('ii', $officeId, $poId);
    $distribStmt->execute();
    $distributed = $distribStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $distribStmt->close();
}

$allItems = array_merge($supplies, $distributed);
usort($allItems, function ($a, $b) {
    return (int) ($a['line_no'] ?? 0) - (int) ($b['line_no'] ?? 0);
});

if (empty($allItems)) {
    echo '<div style="font-family:sans-serif;padding:40px;">';
    echo '<h3>No items found</h3>';
    echo '<p>No supplies were issued or semi/equipment distributed to this office from this PO.</p>';
    echo '<a href="' . base_url('modules/purchase_orders/view.php?id=' . $poId) . '">Back</a></div>';
    exit;
}

$displayDivisionName = $po['department_name'] ?? '';
$displayOfficeName = $mode === 'receiving' ? '' : ($office['office_name'] ?? '');
$displayRisNo = $mode === 'receiving'
    ? ($po['ris_no'] ?? $po['iar_ref'] ?? '')
    : ($po['po_number'] . '-' . ($office['office_code'] ?? ''));
$displayDate = $mode === 'receiving'
    ? format_date((string) ($po['received_date'] ?? ''))
    : format_date(date('Y-m-d'));
$risTargetRows = 18;
$blankRows = max(0, $risTargetRows - count($allItems));
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>RIS <?php echo h($displayRisNo); ?></title>
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
        .center { text-align: center; }
        .right { text-align: right; }
        .ris-purpose-table td {
            height: 66px;
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
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <a href="<?php echo base_url('modules/purchase_orders/view.php?id=' . $poId); ?>" class="btn">Back</a>
        <button onclick="window.print()" class="btn btn-primary" type="button">Print</button>
    </div>
    <div class="ris-appendix">Appendix 63</div>
    <div class="ris-title">Requisition and Issue Slip</div>

    <table class="ris-meta-table">
        <tr>
            <td style="width:55%;"><span class="ris-label">Entity Name :</span> <span class="ris-fill"><?php echo h(APP_NAME); ?></span></td>
            <td style="width:45%;"><span class="ris-label">Fund Cluster :</span> <span class="ris-fill"><?php echo h($po['fund_code'] ?? ''); ?></span></td>
        </tr>
        <tr>
            <td><span class="ris-label">Division :</span> <span class="ris-fill"><?php echo h($displayDivisionName); ?></span></td>
            <td><span class="ris-label">Responsibility Center Code :</span> <span class="ris-fill"><?php echo h($po['responsibility_center_code'] ?? ''); ?></span></td>
        </tr>
        <tr>
            <td><span class="ris-label">Office :</span> <span class="ris-fill"><?php echo h($displayOfficeName); ?></span></td>
            <td><span class="ris-label">RIS No. :</span> <span class="ris-fill"><?php echo h($displayRisNo); ?></span></td>
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
            <?php foreach ($allItems as $it):
                $stockNo = $it['stock_no'] ?? '';
                $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                $description = $it['item_description'] ?? '';
                $qty = (float) ($it['quantity'] ?? 0);
                $docRef = $it['doc_ref'] ?? '';
            ?>
            <tr>
                <td><?php echo h($stockNo); ?></td>
                <td class="center"><?php echo h($unitLabel); ?></td>
                <td><?php echo h($description); ?></td>
                <td class="right"><?php echo h(format_quantity($qty)); ?></td>
                <td class="center"><?php echo $qty > 0 ? '&#10003;' : ''; ?></td>
                <td></td>
                <td class="right"><?php echo h(format_quantity($qty)); ?></td>
                <td><?php echo h($docRef); ?></td>
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
                <?php if (!empty($po['purpose'])): ?>
                    <div style="margin-top:6px;"><?php echo h($po['purpose']); ?></div>
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
            <td></td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <tr>
            <td class="row-label">Designation :</td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <tr>
            <td class="row-label">Date :</td>
            <td></td>
            <td></td>
            <td><?php echo h($displayDate); ?></td>
            <td><?php echo h($displayDate); ?></td>
        </tr>
    </table>
</body>
</html>
