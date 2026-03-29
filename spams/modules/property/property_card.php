<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$receivingId = (int) ($_GET['receiving_id'] ?? 0);

if (!$db || $receivingId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$stmt = $db->prepare(
    "SELECT si.id, si.system_reference AS stk_ref,
           rid.brand, rid.model, rid.serial_no,
           poi.item_description,
           ri.unit_cost, r.received_date, r.system_reference AS iar_ref,
           c.useful_life_years,
           d.document_no AS par_no, d.distribution_date,
           o.office_name, e.first_name, e.last_name, e.position_title,
           rc.code AS rc_code,
           did.property_number,
           f.fund_code
    FROM receiving_items ri
    INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
    INNER JOIN receivings r ON r.id = ri.receiving_id
    INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
    LEFT JOIN funds f ON f.id = po.fund_id
    LEFT JOIN classifications c ON c.id = poi.classification_id
    LEFT JOIN receiving_item_details rid ON rid.receiving_item_id = ri.id
    LEFT JOIN stock_items si ON si.id = rid.stock_item_id
    LEFT JOIN distribution_item_details did ON did.receiving_item_detail_id = rid.id
    LEFT JOIN distribution_items ditem ON ditem.id = did.distribution_item_id
    LEFT JOIN distributions d ON d.id = ditem.distribution_id AND d.document_type = 'par'
    LEFT JOIN offices o ON o.id = d.office_id
    LEFT JOIN employees e ON e.id = d.employee_id
    LEFT JOIN responsibility_codes rc ON rc.office_id = o.id
    WHERE ri.receiving_id = ?
      AND poi.item_type = 'equipment'"
);

$rows = [];
if ($stmt) {
    $stmt->bind_param('i', $receivingId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = $r;
    }
    $stmt->close();
}

// Group by stock item (one physical unit)
$cards = [];
foreach ($rows as $r) {
    $sid = (int) ($r['id'] ?? 0);
    if ($sid <= 0) continue;
    if (!isset($cards[$sid])) {
        $cards[$sid] = [
            'stk_ref' => $r['stk_ref'] ?? '',
            'brand' => $r['brand'] ?? '',
            'model' => $r['model'] ?? '',
            'serial_no' => $r['serial_no'] ?? '',
            'item_description' => $r['item_description'] ?? '',
            'unit_cost' => $r['unit_cost'] ?? 0,
            'received_date' => $r['received_date'] ?? null,
            'iar_ref' => $r['iar_ref'] ?? '',
            'useful_life_years' => $r['useful_life_years'] ?? null,
            'par_no' => $r['par_no'] ?? null,
            'par_date' => $r['distribution_date'] ?? null,
            'office_name' => $r['office_name'] ?? '',
            'accountable_person' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'position_title' => $r['position_title'] ?? '',
            'rc_code' => $r['rc_code'] ?? '',
            'property_number' => $r['property_number'] ?? '',
            'fund_code' => $r['fund_code'] ?? '',
            'ledger' => [],
        ];
    }
    // Receipt row (only once)
    if (empty($cards[$sid]['ledger'])) {
        $cards[$sid]['ledger'][] = [
            'date' => $r['received_date'] ?? null,
            'reference' => $r['iar_ref'] ?? '',
            'receipt_qty' => 1,
            'receipt_cost' => (float) ($r['unit_cost'] ?? 0),
            'issue_qty' => 0,
            'remarks' => '',
        ];
    }
    // If there is a PAR linked, add issue row
    if (!empty($r['par_no'])) {
        $cards[$sid]['ledger'][] = [
            'date' => $r['distribution_date'] ?? null,
            'reference' => $r['par_no'],
            'receipt_qty' => 0,
            'receipt_cost' => 0,
            'issue_qty' => 1,
            'remarks' => 'Issued (PAR)',
        ];
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Property Card</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style> @media print { .no-print{ display:none; } } </style>
</head>
<body>
    <div class="container mt-3">
        <div class="d-flex justify-content-between mb-2">
            <div>
                <a href="<?php echo base_url('modules/purchase_orders/view.php?receiving_id=' . $receivingId); ?>" class="btn btn-sm btn-outline-secondary no-print">Back</a>
                <button onclick="window.print()" class="btn btn-sm btn-primary no-print">Print</button>
            </div>
        </div>

        <?php foreach ($cards as $card): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="text-center">
                        <img src="<?php echo h(LOGO_PATH); ?>" style="width:60px;height:60px;object-fit:contain;" alt="logo">
                        <div class="small fst-italic">Appendix 69</div>
                        <h5 class="mt-2">University of Antique</h5>
                        <div>Property Card</div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-6">
                            <div><strong>Fund Cluster:</strong> <?php echo h($card['fund_code']); ?></div>
                            <div><strong>Property, Plant and Equipment:</strong> <?php echo h($card['item_description']); ?></div>
                            <div><strong>Property Number:</strong> <?php echo h($card['property_number'] ?? ''); ?></div>
                        </div>
                        <div class="col-6">
                            <div><strong>Reference / PAR No.:</strong> <?php echo h($card['par_no'] ?? ''); ?></div>
                            <div><strong>Brand:</strong> <?php echo h($card['brand']); ?></div>
                            <div><strong>Model:</strong> <?php echo h($card['model']); ?></div>
                            <div><strong>Serial No.:</strong> <?php echo h($card['serial_no']); ?></div>
                            <div><strong>Estimated Useful Life:</strong> <?php echo h($card['useful_life_years'] ?? ''); ?></div>
                            <div><strong>Responsibility Center / Office:</strong> <?php echo h($card['office_name']); ?></div>
                            <div><strong>Accountable Person:</strong> <?php echo h($card['accountable_person']); ?> <?php echo h($card['position_title']); ?></div>
                        </div>
                    </div>

                    <div class="table-responsive mt-3">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Receipt Qty</th>
                                    <th>Receipt Cost</th>
                                    <th>Issue Qty</th>
                                    <th>Balance Qty</th>
                                    <th>Balance Cost</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $balQty = 0;
                                $balCost = 0.0;
                                foreach ($card['ledger'] as $row):
                                    $balQty += ($row['receipt_qty'] ?? 0) - ($row['issue_qty'] ?? 0);
                                    $balCost += ($row['receipt_cost'] ?? 0) - (($row['issue_qty'] ?? 0) * ($card['unit_cost'] ?? 0));
                                ?>
                                <tr>
                                    <td><?php echo h(!empty($row['date']) ? date('M d, Y', strtotime($row['date'])) : ''); ?></td>
                                    <td><?php echo h($row['reference'] ?? ''); ?></td>
                                    <td class="text-end"><?php echo h(format_quantity($row['receipt_qty'] ?? 0)); ?></td>
                                    <td class="text-end"><?php echo h(number_format($row['receipt_cost'] ?? 0, 2)); ?></td>
                                    <td class="text-end"><?php echo h(format_quantity($row['issue_qty'] ?? 0)); ?></td>
                                    <td class="text-end"><?php echo h(format_quantity($balQty)); ?></td>
                                    <td class="text-end"><?php echo h(number_format($balCost, 2)); ?></td>
                                    <td><?php echo h($row['remarks'] ?? ''); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>
</body>
</html>
