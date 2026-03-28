<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$distributionId = (int) ($_GET['id'] ?? 0);

if (!$db || $distributionId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

// (item query will be prepared after header check)
// HEADER QUERY
    $headerStmt = $db->prepare(
        "SELECT d.id, d.system_reference, d.document_no, d.distribution_date,\n" .
        "       d.document_type, d.semi_expendable_type, d.purpose, d.remarks, d.total_amount,\n" .
        "       o.office_name, o.office_code,\n" .
        "       dep.name AS department_name,\n" .
        "       rc.code AS responsibility_center_code,\n" .
        "       e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name,\n" .
        "       e.position_title,\n" .
        "       f.fund_code\n" .
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
    // ensure header exists
    if (!$header) {
        http_response_code(404);
        echo 'Record not found.';
        exit;
    }

    // If this distribution is actually a PAR (or document_no indicates PAR), redirect to par.php for the canonical document
    $docNo = $header['document_no'] ?? '';
    if ((!empty($header['document_type']) && $header['document_type'] === 'par') || (is_string($docNo) && strpos($docNo, 'PAR-') === 0)) {
        header('Location: par.php?id=' . (int) $distributionId);
        exit;
    }

    // Prepare and run item query after header validation
    $itemStmt = $db->prepare(
            "SELECT di.id AS di_id, di.quantity_distributed, di.unit_cost, di.line_total,
             poi.item_description,
             u.uom_name, u.abbreviation,
                 c.classification_name, c.classification_family, c.useful_life_years AS useful_life_years,
             did.brand, did.model, did.serial_no, did.property_number, did.id AS did_id
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
   $items = [];
   if ($itemStmt) {
       $itemStmt->bind_param('i', $distributionId);
       $itemStmt->execute();
       $res = $itemStmt->get_result();
       while ($res && ($r = $res->fetch_assoc())) {
           $rows[] = $r;
       }
       $itemStmt->close();
   }

// Group rows by distribution item id (one di may have multiple did rows)
foreach ($rows as $r) {
    $di = (int) $r['di_id'];
    if (!isset($items[$di])) {
        $items[$di] = [
            'quantity_distributed' => $r['quantity_distributed'],
            'unit_cost'            => $r['unit_cost'],
            'line_total'           => $r['line_total'],
            'item_description'     => $r['item_description'],
            'uom_name'             => $r['uom_name'],
            'abbreviation'         => $r['abbreviation'],
            'classification_name'  => $r['classification_name'] ?? '',
            'classification_family'=> $r['classification_family'] ?? '',
            'inventory_item_no'    => $r['inventory_item_no'] ?? '',
            'useful_life_years'    => $r['useful_life_years'] ?? null,
            'details'              => [],
        ];
    }
    if (!empty($r['did_id'])) {
        $items[$di]['details'][] = [
            'brand'           => $r['brand'],
            'model'           => $r['model'],
            'serial_no'       => $r['serial_no'],
            'property_number' => $r['property_number'] ?? '',
        ];
    }
}

// Received by name
$receivedByName = '';
if (function_exists('employee_display_name')) {
    $receivedByName = employee_display_name($header);
} else {
    $receivedByName = trim(($header['first_name'] ?? '') . ' ' . ($header['middle_name'] ?? '') . ' ' . ($header['last_name'] ?? '') . ' ' . ($header['suffix_name'] ?? ''));
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ICS <?php echo h($header['document_no'] ?? $header['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-size:12px; }
        table { font-size:11px; }
        .no-print { display:block; }
        @media print { .no-print { display:none; } }
        .appendix { position:absolute; right:24px; top:18px; font-size:12px; }
        .table-bordered td, .table-bordered th { border:1px solid #000 !important; }
    </style>
</head>
<body>
    <div class="container" style="max-width:1000px;">
        <?php if (isset($_GET['created']) && $_GET['created'] == '1'): ?>
            <div class="alert alert-info no-print">Distribution was just posted — ideal time to print this ICS now.</div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-start mt-3 mb-2">
                <div>
                <a href="<?php echo base_url('modules/distributions/index.php?document_type=ics'); ?>" class="btn btn-sm btn-outline-secondary no-print">Back</a>
                <button onclick="window.print()" class="btn btn-sm btn-primary no-print">Print</button>
                <a href="<?php echo base_url('modules/property/tags.php?distribution_id=' . (int)$distributionId); ?>" class="btn btn-outline-secondary btn-sm no-print" target="_blank">Print QR Tags</a>
            </div>
            <div class="appendix">Appendix 59</div>
        </div>

        <div style="text-align:center; margin-bottom:12px; border-bottom:1px solid #000; padding-bottom:8px;">
            <img src="<?php echo h(LOGO_PATH); ?>" style="width:60px; height:60px; object-fit:contain;" alt="UA Logo">
            <div style="font-size:11pt; font-weight:bold; margin-top:4px;">University of Antique</div>
            <div style="font-size:9pt;">Sibalom, Antique</div>
            <div style="font-size:9pt;">Supply and Property Management System</div>
        </div>

        <div class="row mb-2" style="font-size:12px;">
            <div class="col-6">
                <div><strong>Entity Name:</strong> University of Antique</div>
                <div><strong>Fund Cluster:</strong> <?php echo h($header['fund_code'] ?? ''); ?></div>
            </div>
            <div class="col-6 text-end">
                <div><strong>ICS No.:</strong> <?php echo h($header['document_no'] ?? $header['system_reference'] ?? ''); ?></div>
                <?php
                $semiSubtype = $header['semi_expendable_type'] ?? null;
                if ($semiSubtype === 'low_value') {
                    $subtypeLabel = 'Low Value Semi-Expendable (₱5,000 and below)';
                } else {
                    $subtypeLabel = 'High Value Semi-Expendable (above ₱5,000 to below ₱50,000)';
                }
                ?>
                <div style="font-size:10pt; color:#555;">
                    <strong>Type:</strong> <?php echo h($subtypeLabel); ?>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th style="width:8%">Quantity</th>
                        <th style="width:6%">Unit</th>
                        <th style="width:10%" class="text-end">Unit Cost</th>
                        <th style="width:10%" class="text-end">Total Cost</th>
                        <th>Description</th>
                        <th style="width:12%">Inventory Item No.</th>
                        <th style="width:12%">Estimated Useful Life</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it):
                        $qty = (float) ($it['quantity_distributed'] ?? 0);
                        $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                        $unitCost = (float) ($it['unit_cost'] ?? 0);
                        $totalCost = (float) ($it['line_total'] ?? ($unitCost * $qty));
                        $useful = '—';
                        if (!empty($it['useful_life_years'])) {
                            $useful = h($it['useful_life_years']) . ' yr' . ((int)$it['useful_life_years'] > 1 ? 's' : '');
                        }
                    ?>
                    <tr>
                        <td class="text-end"><?php echo h(number_format($qty,2)); ?></td>
                        <td><?php echo h($unitLabel); ?></td>
                        <td class="text-end"><?php echo h(number_format($unitCost,2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($totalCost,2)); ?></td>
                        <td>
                            <?php
                                $icsLabel = trim((!empty($it['classification_family']) ? $it['classification_family'] . ' / ' : '') . ($it['classification_name'] ?? ''));
                                $icsDescription = trim(($icsLabel !== '' ? $icsLabel . ' - ' : '') . ($it['item_description'] ?? ''));
                            ?>
                            <?php echo nl2br(h($icsDescription)); ?>
                            <?php if (!empty($it['details'])): ?>
                                <div class="mt-1">
                                    <?php foreach ($it['details'] as $d): ?>
                                        <div>
                                            Brand: <?php echo h($d['brand'] ?? ''); ?> | Model: <?php echo h($d['model'] ?? ''); ?> | Serial No.: <?php echo h($d['serial_no'] ?? ''); ?>
                                            <?php if (!empty($d['property_number'])): ?>
                                                | Property No.: <?php echo h($d['property_number']); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($it['details'])): ?>
                                <?php foreach ($it['details'] as $d): ?>
                                    <?php echo h($d['property_number'] ?? ''); ?><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                &nbsp;
                            <?php endif; ?>
                        </td>
                        <td><?php echo h($useful); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="row mt-3">
            <div class="col-12"><strong>Purpose:</strong> <?php echo h($header['purpose'] ?? ''); ?></div>
        </div>

        <div class="row mt-4">
            <div class="col-md-6 text-center">
                <div><strong>Received from:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;margin:12px 40px;"></div>
                <div>Signature Over Printed Name</div>
                <div>Position/Office</div>
                <div>Date</div>
            </div>
            <div class="col-md-6 text-center">
                <div><strong>Received by:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;margin:12px 40px;"></div>
                <div><?php echo h($receivedByName); ?></div>
                <div>Signature Over Printed Name</div>
                <div>Position/Office: <?php echo h($header['position_title'] ?? ''); ?> / <?php echo h($header['office_name'] ?? ''); ?></div>
                <div>Date: <?php echo h(date('M d, Y', strtotime($header['distribution_date'] ?? ''))); ?></div>
            </div>
        </div>

    </div>
</body>
</html>
