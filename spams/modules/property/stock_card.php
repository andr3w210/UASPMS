<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$receivingId = (int) ($_GET['receiving_id'] ?? 0);

if (!$db || $receivingId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$stmt = $db->prepare(
    "SELECT ri.id, ri.quantity_received, ri.unit_cost,
       poi.item_description, poi.item_type,
       u.uom_name, ac.account_code,
       si.system_reference AS stk_ref,
       r.system_reference AS iar_ref, r.received_date,
       f.fund_code
    FROM receiving_items ri
    INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
    INNER JOIN receivings r ON r.id = ri.receiving_id
    INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
    LEFT JOIN funds f ON f.id = po.fund_id
    LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
    LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
    LEFT JOIN stock_items si ON si.receiving_item_id = ri.id
    WHERE ri.receiving_id = ?
      AND poi.item_type IN ('supply', 'semi_expendable')"
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

// For each receiving_item row, fetch related distribution_items (issues)
$cards = [];
foreach ($rows as $r) {
    $rid = (int) ($r['id'] ?? 0);
    if ($rid <= 0) continue;
    $card = [
        'ri_id' => $rid,
        'quantity_received' => $r['quantity_received'],
        'unit_cost' => $r['unit_cost'],
        'item_description' => $r['item_description'],
        'item_type' => $r['item_type'],
        'uom_name' => $r['uom_name'],
        'account_code' => $r['account_code'],
        'stk_ref' => $r['stk_ref'],
        'iar_ref' => $r['iar_ref'],
        'received_date' => $r['received_date'],
        'fund_code' => $r['fund_code'],
        'ledger' => [],
    ];

    // Receipt row
    $card['ledger'][] = [
        'date' => $r['received_date'],
        'reference' => $r['iar_ref'],
        'receipt_qty' => (float) ($r['quantity_received'] ?? 0),
        'issue_qty' => 0,
        'remarks' => '',
    ];

    // Fetch distribution_items that used this receiving_item
    $issueStmt = $db->prepare(
        "SELECT d.distribution_date AS date, d.document_no AS reference, di.quantity_distributed
         FROM distribution_items di
         INNER JOIN distributions d ON d.id = di.distribution_id
         WHERE di.receiving_item_id = ?");
    if ($issueStmt) {
        $issueStmt->bind_param('i', $rid);
        $issueStmt->execute();
        $ires = $issueStmt->get_result();
        while ($ir = $ires->fetch_assoc()) {
            $card['ledger'][] = [
                'date' => $ir['date'],
                'reference' => $ir['reference'],
                'receipt_qty' => 0,
                'issue_qty' => (float) ($ir['quantity_distributed'] ?? 0),
                'remarks' => 'Issued',
            ];
        }
        $issueStmt->close();
    }

    $cards[] = $card;
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Stock Card</title>
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
                        <h5 class="mt-2">University of Antique</h5>
                        <div>Stock Card (COA GAM Appendix 64)</div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-6">
                            <div><strong>Fund Cluster:</strong> <?php echo h($card['fund_code']); ?></div>
                            <div><strong>Stock Card No.:</strong> <?php echo h($card['stk_ref']); ?></div>
                            <div><strong>Item description:</strong> <?php echo h($card['item_description']); ?></div>
                        </div>
                        <div class="col-6">
                            <div><strong>Unit of Measure:</strong> <?php echo h($card['uom_name']); ?></div>
                            <div><strong>Account Code:</strong> <?php echo h($card['account_code']); ?></div>
                            <div><strong>Reorder Point:</strong> </div>
                        </div>
                    </div>

                    <div class="table-responsive mt-3">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Receipt Qty</th>
                                    <th>Issue Qty</th>
                                    <th>Balance Qty</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $bal = 0.0; foreach ($card['ledger'] as $row): $bal += ($row['receipt_qty'] ?? 0) - ($row['issue_qty'] ?? 0); ?>
                                    <tr>
                                        <td><?php echo h(!empty($row['date']) ? date('M d, Y', strtotime($row['date'])) : ''); ?></td>
                                        <td><?php echo h($row['reference'] ?? ''); ?></td>
                                        <td class="text-end"><?php echo h(number_format($row['receipt_qty'] ?? 0,2)); ?></td>
                                        <td class="text-end"><?php echo h(number_format($row['issue_qty'] ?? 0,2)); ?></td>
                                        <td class="text-end"><?php echo h(number_format($bal,2)); ?></td>
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
