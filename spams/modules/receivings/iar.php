<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

function receiving_type_label(string $type): string
{
    return $type === 'equipment' ? 'Equipment' : ($type === 'semi_expendable' ? 'Semi-Expendable' : 'Supplies');
}

$db = db();
$receivingId = (int) ($_GET['id'] ?? 0);

if (!$db || $receivingId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$headerStmt = $db->prepare(
    "SELECT r.id, r.system_reference, r.ris_no, r.received_date, r.delivery_receipt_no, r.invoice_no, r.remarks, r.status,
            po.po_number, po.po_date, po.supplier_address,
            s.supplier_name, f.fund_code,
            o.office_name, o.office_code,
            d.name AS department_name, rc.code AS responsibility_center_code
     FROM receivings r
     INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
     INNER JOIN suppliers s ON s.id = po.supplier_id
     INNER JOIN funds f ON f.id = po.fund_id
     LEFT JOIN users u ON u.id = r.created_by
     LEFT JOIN offices o ON o.id = u.office_id
     LEFT JOIN departments d ON d.id = o.department_id
     LEFT JOIN responsibility_codes rc ON rc.office_id = o.id
     WHERE r.id = ?
     LIMIT 1"
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

if (!in_array($receiving['status'], ['completed', 'partial'], true)) {
    http_response_code(403);
    echo '<p>IAR cannot be printed yet — no items have been accepted.</p>';
    echo '<a href="' . base_url('modules/receivings/index.php') . '">Back</a>';
    exit;
}

$itemStmt = $db->prepare(
    "SELECT ri.id, ri.quantity_delivered, ri.quantity_accepted, ri.quantity_rejected, ri.unit_cost, ri.item_condition, ri.remarks,
            poi.line_no, poi.item_type, poi.item_description, poi.quantity AS qty_ordered,
            sc.stock_no,
            ac.account_code, c.classification_name,
            u.uom_name, u.abbreviation
     FROM receiving_items ri
     INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
     LEFT JOIN stock_catalog sc ON sc.id = poi.stock_catalog_id
     LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
     LEFT JOIN classifications c ON c.id = poi.classification_id
     LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
     WHERE ri.receiving_id = ?
     ORDER BY poi.line_no ASC"
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

$totalDelivered = 0.0;
$totalAccepted = 0.0;
$totalRejected = 0.0;
$grandTotal = 0.0;
foreach ($items as $itm) {
    $totalDelivered += (float) ($itm['quantity_delivered'] ?? 0);
    $totalAccepted += (float) ($itm['quantity_accepted'] ?? 0);
    $totalRejected += (float) ($itm['quantity_rejected'] ?? 0);
    $grandTotal += (float) ($itm['unit_cost'] ?? 0) * (float) ($itm['quantity_accepted'] ?? 0);
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>IAR <?php echo h($receiving['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { color:#000; font-family:"Times New Roman", Times, serif; font-size:12px; }
        .iar-wrap { margin:0 auto; max-width:980px; }
        .iar-toolbar { margin:1rem 0; }
        .iar-appendix { font-size:11px; font-style:italic; text-align:right; }
        .iar-title { font-size:18px; font-weight:700; letter-spacing:.03em; text-align:center; text-transform:uppercase; }
        .iar-subtitle { font-size:11px; text-align:center; }
        .iar-entity { border-bottom:1px solid #000; display:inline-block; font-weight:700; min-width:260px; padding:0 1rem .1rem; }
        .iar-meta-table td,
        .iar-items-table td,
        .iar-items-table th,
        .iar-footer-table td { border:1px solid #000 !important; }
        .iar-meta-table,
        .iar-items-table,
        .iar-footer-table { width:100%; border-collapse:collapse; }
        .iar-meta-table td,
        .iar-footer-table td { font-size:11px; padding:.18rem .32rem; vertical-align:top; }
        .iar-label { font-weight:700; white-space:nowrap; width:16%; }
        .iar-items-table th,
        .iar-items-table td { font-size:10.5px; padding:.24rem .28rem; vertical-align:top; }
        .iar-items-table th { text-align:center; }
        .iar-description-meta { color:#333; font-size:10px; }
        .iar-signatures td { height:96px; vertical-align:bottom; }
        .iar-line { border-bottom:1px solid #000; height:34px; margin-bottom:4px; }
        .iar-note { font-size:10.5px; line-height:1.25; }
        .iar-cell-title { font-weight:700; }
        .iar-min-rows td { height:28px; }
        .no-print { display:block; }
        @media print {
            .no-print { display:none !important; }
            @page { size: A4 portrait; margin: 0.45in; }
            body { margin:0; }
        }
    </style>
</head>
<body>
    <div class="container iar-wrap">
        <div class="iar-toolbar d-flex justify-content-between align-items-center no-print">
            <div>
                <a href="<?php echo base_url('modules/receivings/index.php'); ?>" class="btn btn-sm btn-outline-secondary">Back</a>
                <button onclick="window.print()" class="btn btn-sm btn-primary">Print</button>
            </div>
        </div>

        <div class="iar-appendix">Appendix 62</div>
        <div class="iar-title">Inspection and Acceptance Report</div>
        <div class="iar-subtitle">Entity Name</div>
        <div class="text-center mb-3"><span class="iar-entity">University of Antique</span></div>

        <table class="iar-meta-table mb-2">
            <tr>
                <td class="iar-label">Supplier</td>
                <td colspan="3"><?php echo h($receiving['supplier_name']); ?></td>
                <td class="iar-label">Fund Cluster</td>
                <td><?php echo h($receiving['fund_code']); ?></td>
            </tr>
            <tr>
                <td class="iar-label">Address</td>
                <td colspan="3"><?php echo h($receiving['supplier_address'] ?: ''); ?></td>
                <td class="iar-label">IAR No.</td>
                <td><?php echo h($receiving['ris_no'] ?: $receiving['system_reference']); ?></td>
            </tr>
            <tr>
                <td class="iar-label">P.O. No. / Date</td>
                <td colspan="3"><?php echo h($receiving['po_number']); ?> / <?php echo h(date('M d, Y', strtotime((string) $receiving['po_date']))); ?></td>
                <td class="iar-label">Date</td>
                <td><?php echo h(date('M d, Y', strtotime((string) $receiving['received_date']))); ?></td>
            </tr>
            <tr>
                <td class="iar-label">Office / Department</td>
                <td colspan="3"><?php echo h(trim((string) ($receiving['office_name'] ?? '')) . (!empty($receiving['department_name']) ? ' / ' . (string) $receiving['department_name'] : '')); ?></td>
                <td class="iar-label">RC Code</td>
                <td><?php echo h($receiving['responsibility_center_code'] ?: ''); ?></td>
            </tr>
            <tr>
                <td class="iar-label">Delivery Receipt No.</td>
                <td><?php echo h($receiving['delivery_receipt_no'] ?: ''); ?></td>
                <td class="iar-label">Invoice No.</td>
                <td><?php echo h($receiving['invoice_no'] ?: ''); ?></td>
                <td class="iar-label">Remarks</td>
                <td><?php echo h($receiving['remarks'] ?: ''); ?></td>
            </tr>
        </table>

        <table class="iar-items-table mb-2">
            <thead>
                <tr>
                    <th style="width:16%;">Stock / Property No.</th>
                    <th>Description</th>
                    <th style="width:8%;">Unit</th>
                    <th style="width:8%;">Qty Ordered</th>
                    <th style="width:8%;">Qty Delivered</th>
                    <th style="width:8%;">Qty Accepted</th>
                    <th style="width:8%;">Qty Rejected</th>
                    <th style="width:10%;">Unit Cost</th>
                    <th style="width:12%;">Amount</th>
                </tr>
            </thead>
            <tbody class="iar-min-rows">
                <?php foreach ($items as $it): ?>
                    <?php
                        $qtyOrdered = (float) ($it['qty_ordered'] ?? 0);
                        $qtyDelivered = (float) ($it['quantity_delivered'] ?? 0);
                        $qtyAccepted = (float) ($it['quantity_accepted'] ?? 0);
                        $qtyRejected = (float) ($it['quantity_rejected'] ?? 0);
                        $unitCost = (float) ($it['unit_cost'] ?? 0);
                        $lineTotal = round($unitCost * $qtyAccepted, 2);
                        $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                        $description = trim((string) ($it['classification_name'] ?? ''));
                        if (!empty($it['item_description'])) {
                            $description = trim($description . ' - ' . (string) $it['item_description'], ' -');
                        }
                    ?>
                    <tr>
                        <td>
                            <?php if ((string) ($it['item_type'] ?? '') === 'supply' && !empty($it['stock_no'])): ?>
                                <?php echo h($it['stock_no']); ?>
                            <?php elseif (in_array((string) ($it['item_type'] ?? ''), ['semi_expendable', 'equipment'], true)): ?>
                                <span class="text-muted">To be assigned upon distribution</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?php echo nl2br(h($description)); ?></div>
                            <div class="iar-description-meta"><?php echo h(receiving_type_label((string) $it['item_type'])); ?><?php echo !empty($it['account_code']) ? ' | ' . h($it['account_code']) : ''; ?></div>
                        </td>
                        <td><?php echo h($unitLabel); ?></td>
                        <td class="text-end"><?php echo h(number_format($qtyOrdered, 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($qtyDelivered, 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($qtyAccepted, 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($qtyRejected, 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($unitCost, 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($lineTotal, 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="9" class="text-center">No accepted items found.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="text-end fw-bold">Total</td>
                    <td class="text-end fw-bold"><?php echo h(number_format($totalDelivered, 2)); ?></td>
                    <td class="text-end fw-bold"><?php echo h(number_format($totalAccepted, 2)); ?></td>
                    <td class="text-end fw-bold"><?php echo h(number_format($totalRejected, 2)); ?></td>
                    <td></td>
                    <td class="text-end fw-bold"><?php echo h(number_format($grandTotal, 2)); ?></td>
                </tr>
            </tfoot>
        </table>

        <table class="iar-footer-table iar-signatures">
            <tr>
                <td style="width:50%;">
                    <div class="iar-cell-title">Inspection</div>
                    <div><strong>Date Inspected:</strong> <?php echo h(date('M d, Y', strtotime((string) $receiving['received_date']))); ?></div>
                    <div class="mt-2 iar-note">Inspected, verified, and found in order as to quantity and specifications.</div>
                    <div class="iar-line"></div>
                    <div class="text-center">Inspection Officer / Inspection Committee</div>
                </td>
                <td style="width:50%;">
                    <div class="iar-cell-title">Acceptance</div>
                    <div><strong>Date Received:</strong> <?php echo h(date('M d, Y', strtotime((string) $receiving['received_date']))); ?></div>
                    <div class="mt-2">
                        <?php $isComplete = ($totalDelivered >= array_sum(array_map(function ($row) { return (float) ($row['qty_ordered'] ?? 0); }, $items))); ?>
                        <div>[ <?php echo $isComplete ? '/' : '&nbsp;'; ?> ] Complete</div>
                        <div>[ <?php echo !$isComplete ? '/' : '&nbsp;'; ?> ] Partial (pls. specify quantity)</div>
                    </div>
                    <div class="iar-line"></div>
                    <div class="text-center">Supply and/or Property Custodian</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
