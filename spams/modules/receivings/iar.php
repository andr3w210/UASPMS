<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

function receiving_type_label(string $type): string
{
    return $type === 'equipment' ? 'Equipment' : ($type === 'semi_expendable' ? 'Semi-Expendable' : 'Supplies');
}

$db = db_connect();
$receivingId = (int) ($_GET['id'] ?? 0);

if (!$db || $receivingId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

// Header query
$headerStmt = $db->prepare(
    "SELECT r.id, r.system_reference, r.ris_no, r.received_date, r.delivery_receipt_no, r.invoice_no, r.remarks, r.status,\n" .
    "       po.po_number, po.po_date, po.supplier_address,\n" .
    "       s.supplier_name, f.fund_code,\n" .
    "       o.office_name, o.office_code,\n" .
    "       d.name AS department_name, rc.code AS responsibility_center_code\n" .
    "FROM receivings r\n" .
    "INNER JOIN purchase_orders po ON po.id = r.purchase_order_id\n" .
    "INNER JOIN suppliers s ON s.id = po.supplier_id\n" .
    "INNER JOIN funds f ON f.id = po.fund_id\n" .
    "LEFT JOIN users u ON u.id = r.created_by\n" .
    "LEFT JOIN offices o ON o.id = u.office_id\n" .
    "LEFT JOIN departments d ON d.id = o.department_id\n" .
    "LEFT JOIN responsibility_codes rc ON rc.office_id = o.id\n" .
    "WHERE r.id = ?\n" .
    "LIMIT 1"
);

$receiving = null;
if ($headerStmt) {
    $headerStmt->bind_param('i', $receivingId);
    $headerStmt->execute();
    $receiving = $headerStmt->get_result()->fetch_assoc() ?: null;
    $headerStmt->close();
}

if (!$receiving) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

// Block printing if no accepted items (not yet receivable)
if (!in_array($receiving['status'], ['completed','partial'], true)) {
    http_response_code(403);
    echo '<p>IAR cannot be printed yet — no items have been accepted.</p>';
    echo '<a href="' . base_url('modules/receivings/index.php') . '">Back</a>';
    exit;
}

// Items query
$itemStmt = $db->prepare(
    "SELECT ri.id, ri.quantity_delivered, ri.quantity_accepted, ri.quantity_rejected, ri.unit_cost, ri.item_condition, ri.remarks,\n" .
    "       poi.line_no, poi.item_type, poi.item_description, poi.quantity AS qty_ordered,\n" .
    "       u.uom_name, u.abbreviation,\n" .
    "       si.system_reference AS stock_property_no\n" .
    "FROM receiving_items ri\n" .
    "INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id\n" .
    "LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id\n" .
    "LEFT JOIN stock_items si ON si.receiving_item_id = ri.id\n" .
    "WHERE ri.receiving_id = ?\n" .
    "ORDER BY poi.line_no ASC"
);

$items = [];
if ($itemStmt) {
    $itemStmt->bind_param('i', $receivingId);
    $itemStmt->execute();
    $res = $itemStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $itemStmt->close();
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>IAR <?php echo h($receiving['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-size:12px; }
        table { font-size:11px; }
        .no-print { display: block; }
        @media print { .no-print { display: none; } body { font-size:11px; } }
        .appendix { position: absolute; right: 24px; top: 18px; font-size:12px; }
        .table-bordered td, .table-bordered th { border:1px solid #000 !important; }
    </style>
</head>
<body>
    <div class="container" style="max-width:1000px;">
        <div class="d-flex justify-content-between align-items-start mt-3 mb-2">
            <div>
                <a href="<?php echo base_url('modules/receivings/index.php'); ?>" class="btn btn-sm btn-outline-secondary no-print">Back</a>
                <button onclick="window.print()" class="btn btn-sm btn-primary no-print">Print</button>
            </div>
            <div class="appendix">Appendix 62</div>
        </div>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px; border-bottom:1px solid #000; padding-bottom:8px;">
            <img src="<?php echo h(LOGO_PATH); ?>" style="width:60px; height:60px; object-fit:contain;" alt="UA Logo">
            <div style="text-align:center; flex:1;">
                <div style="font-size:11pt; font-weight:bold;">University of Antique</div>
                <div style="font-size:9pt;">Sibalom, Antique</div>
                <div style="font-size:9pt;">Supply and Property Management System</div>
            </div>
            <div style="width:60px;"></div>
        </div>

        <div class="row mb-2" style="font-size:12px;">
            <div class="col-6">
                <div><strong>Entity Name:</strong> University of Antique</div>
                <div><strong>Supplier:</strong> <?php echo h($receiving['supplier_name']); ?></div>
                <div><strong>PO No./Date:</strong> <?php echo h($receiving['po_number']); ?> / <?php echo h($receiving['po_date']); ?></div>
                <div><strong>Requisitioning Office/Dept.:</strong> <?php echo h($receiving['office_name'] ?: ''); ?> / <?php echo h($receiving['department_name'] ?: ''); ?></div>
            </div>
            <div class="col-6">
                <div><strong>Fund Cluster:</strong> <?php echo h($receiving['fund_code']); ?></div>
                <div><strong>IAR No.:</strong> <?php echo h($receiving['ris_no']); ?></div>
                <div><strong>Date:</strong> <?php echo h(date('M d, Y', strtotime($receiving['received_date']))); ?></div>
                <div><strong>Delivery Receipt No.:</strong> <?php echo h($receiving['delivery_receipt_no'] ?? ''); ?></div>
                <div><strong>Invoice No.:</strong> <?php echo h($receiving['invoice_no']); ?></div>
                <div><strong>Responsibility Center Code:</strong> <?php echo h($receiving['responsibility_center_code'] ?: ''); ?></div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Stock/Property No.</th>
                        <th>Description</th>
                        <th>Unit</th>
                        <th class="text-end">Qty Ordered</th>
                        <th class="text-end">Qty Delivered</th>
                        <th class="text-end">Qty Accepted</th>
                        <th class="text-end">Qty Rejected</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $totalDelivered = 0.0;
                    $totalAccepted = 0.0;
                    $totalRejected = 0.0;
                    $grandTotal = 0.0;
                    foreach ($items as $itm) {
                        $totalDelivered += (float)($itm['quantity_delivered'] ?? 0);
                        $totalAccepted += (float)($itm['quantity_accepted'] ?? 0);
                        $totalRejected += (float)($itm['quantity_rejected'] ?? 0);
                        $grandTotal += (float)($itm['unit_cost'] ?? 0) * (float)($itm['quantity_accepted'] ?? 0);
                    }
                    foreach ($items as $it):
                        $qtyOrdered = (float)($it['qty_ordered'] ?? 0);
                        $qtyDelivered = (float) ($it['quantity_delivered'] ?? 0);
                        $qtyAccepted = (float) ($it['quantity_accepted'] ?? 0);
                        $qtyRejected = (float) ($it['quantity_rejected'] ?? 0);
                        $unitCost = (float) ($it['unit_cost'] ?? 0);
                        $lineTotal = round($unitCost * $qtyAccepted, 2);
                        $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                    ?>
                    <tr>
                        <td style="vertical-align:top"><?php echo h($it['stock_property_no'] ?? ''); ?></td>
                        <td style="vertical-align:top"><?php echo nl2br(h($it['item_description'])); ?></td>
                        <td style="vertical-align:top"><?php echo h($unitLabel); ?></td>
                        <td class="text-end"><?php echo h(number_format((float)$qtyOrdered,2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($qtyDelivered,2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($qtyAccepted,2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($qtyRejected,2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($unitCost,2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($lineTotal,2)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-end fw-bold">Total Delivered:</td>
                        <td class="text-end fw-bold">
                            <?php echo h(number_format($totalDelivered, 2)); ?>
                        </td>
                        <td class="text-end fw-bold"><?php echo h(number_format($totalAccepted, 2)); ?></td>
                        <td class="text-end fw-bold"><?php echo h(number_format($totalRejected, 2)); ?></td>
                        <td></td>
                        <td class="text-end fw-bold"><?php echo h(number_format($grandTotal, 2)); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="row mt-4">
            <div class="col-md-6">
                <div><strong>Date Inspected:</strong> <?php echo h(date('M d, Y', strtotime($receiving['received_date']))); ?></div>
                <p class="mt-2">Inspected, verified and found in order as to quantity and specifications</p>
                <div style="height:60px;border-bottom:1px solid #000;width:80%;"></div>
                <div class="small">Inspection Officer / Inspection Committee</div>
            </div>
            <div class="col-md-6">
                <div><strong>Date Received:</strong> <?php echo h(date('M d, Y', strtotime($receiving['received_date']))); ?></div>
                <div class="mt-2">
                    <?php $isComplete = ($totalDelivered >= array_sum(array_column($items, 'qty_ordered'))); ?>
                    <div><input type="checkbox" <?php echo $isComplete ? 'checked' : ''; ?> disabled> Complete</div>
                    <div><input type="checkbox" <?php echo !$isComplete ? 'checked' : ''; ?> disabled> Partial (pls. specify quantity)</div>
                </div>
                <div style="height:60px;border-bottom:1px solid #000;width:80%;margin-top:12px;"></div>
                <div class="small">Supply and/or Property Custodian</div>
            </div>
        </div>

    </div>
</body>
</html>
