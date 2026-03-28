<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

function consolidated_receiving_type_label(string $type): string
{
    return $type === 'equipment' ? 'Equipment' : ($type === 'semi_expendable' ? 'Semi-Expendable' : 'Supplies');
}

$db = db();
$purchaseOrderId = (int) ($_GET['po_id'] ?? 0);
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
$purchaseOrders = [];
$po = null;
$items = [];
$receivingRefs = [];

if (!$db) {
    http_response_code(500);
    echo 'Unable to connect to the database.';
    exit;
}

$poListStmt = $db->prepare(
    "SELECT po.id, po.po_number, po.po_date, s.supplier_name
     FROM purchase_orders po
     INNER JOIN suppliers s ON s.id = po.supplier_id
     WHERE EXISTS (
         SELECT 1
         FROM receivings r
         WHERE r.purchase_order_id = po.id
           AND r.status IN ('completed', 'partial')
     )
     ORDER BY po.po_date DESC, po.id DESC"
);
if ($poListStmt) {
    $poListStmt->execute();
    $poRes = $poListStmt->get_result();
    $purchaseOrders = $poRes ? $poRes->fetch_all(MYSQLI_ASSOC) : [];
    $poListStmt->close();
}

if ($purchaseOrderId > 0) {
    $headerStmt = $db->prepare(
        "SELECT po.id, po.po_number, po.po_date, po.supplier_address,
                s.supplier_name, f.fund_code
         FROM purchase_orders po
         INNER JOIN suppliers s ON s.id = po.supplier_id
         INNER JOIN funds f ON f.id = po.fund_id
         WHERE po.id = ?
         LIMIT 1"
    );
    if ($headerStmt) {
        $headerStmt->bind_param('i', $purchaseOrderId);
        $headerStmt->execute();
        $po = $headerStmt->get_result()->fetch_assoc() ?: null;
        $headerStmt->close();
    }

    if ($po) {
        $refStmt = $db->prepare(
            "SELECT r.system_reference, r.ris_no, r.received_date
             FROM receivings r
             WHERE r.purchase_order_id = ?
               AND r.status IN ('completed', 'partial')
             ORDER BY r.received_date ASC, r.id ASC"
        );
        if ($refStmt) {
            $refStmt->bind_param('i', $purchaseOrderId);
            $refStmt->execute();
            $refRes = $refStmt->get_result();
            while ($refRes && ($refRow = $refRes->fetch_assoc())) {
                $receivingRefs[] = $refRow;
            }
            $refStmt->close();
        }

        $itemStmt = $db->prepare(
            "SELECT
                poi.id,
                poi.line_no,
                poi.item_type,
                poi.item_description,
                poi.quantity AS qty_ordered,
                poi.unit_cost,
                sc.stock_no,
                ac.account_code,
                c.classification_name,
                u.uom_name,
                u.abbreviation,
                COALESCE(SUM(CASE WHEN r.status != 'cancelled' THEN ri.quantity_delivered ELSE 0 END), 0) AS quantity_delivered,
                COALESCE(SUM(CASE WHEN r.status != 'cancelled' THEN ri.quantity_accepted ELSE 0 END), 0) AS quantity_accepted,
                COALESCE(SUM(CASE WHEN r.status != 'cancelled' THEN ri.quantity_rejected ELSE 0 END), 0) AS quantity_rejected,
                MAX(CASE WHEN r.status != 'cancelled' THEN r.received_date END) AS latest_received_date
             FROM purchase_order_items poi
             LEFT JOIN stock_catalog sc ON sc.id = poi.stock_catalog_id
             LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
             LEFT JOIN classifications c ON c.id = poi.classification_id
             LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
             LEFT JOIN receiving_items ri ON ri.purchase_order_item_id = poi.id
             LEFT JOIN receivings r ON r.id = ri.receiving_id AND r.purchase_order_id = poi.purchase_order_id
             WHERE poi.purchase_order_id = ?
             GROUP BY
                poi.id, poi.line_no, poi.item_type, poi.item_description, poi.quantity, poi.unit_cost,
                sc.stock_no, ac.account_code, c.classification_name, u.uom_name, u.abbreviation
             HAVING COALESCE(SUM(CASE WHEN r.status != 'cancelled' THEN ri.quantity_delivered ELSE 0 END), 0) > 0
             ORDER BY poi.line_no ASC, poi.id ASC"
        );
        if ($itemStmt) {
            $itemStmt->bind_param('i', $purchaseOrderId);
            $itemStmt->execute();
            $itemRes = $itemStmt->get_result();
            while ($itemRes && ($itemRow = $itemRes->fetch_assoc())) {
                $items[] = $itemRow;
            }
            $itemStmt->close();
        }
    }
}

$totalDelivered = 0.0;
$totalAccepted = 0.0;
$totalRejected = 0.0;
$grandTotal = 0.0;
$totalOrdered = 0.0;
foreach ($items as $item) {
    $totalOrdered += (float) ($item['qty_ordered'] ?? 0);
    $totalDelivered += (float) ($item['quantity_delivered'] ?? 0);
    $totalAccepted += (float) ($item['quantity_accepted'] ?? 0);
    $totalRejected += (float) ($item['quantity_rejected'] ?? 0);
    $grandTotal += (float) ($item['unit_cost'] ?? 0) * (float) ($item['quantity_accepted'] ?? 0);
}
$isComplete = $totalOrdered > 0 && round($totalAccepted, 2) >= round($totalOrdered, 2);
$consolidatedAsOf = '';
if ($receivingRefs) {
    $lastRef = end($receivingRefs);
    $consolidatedAsOf = !empty($lastRef['received_date']) ? date('M d, Y', strtotime((string) $lastRef['received_date'])) : '';
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Final IAR by PO</title>
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
        <div class="iar-toolbar no-print">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <a href="<?php echo base_url('modules/receivings/index.php'); ?>" class="btn btn-sm btn-outline-secondary">Back</a>
                    <?php if ($po): ?>
                        <button onclick="window.print()" class="btn btn-sm btn-primary">Print</button>
                    <?php endif; ?>
                </div>
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label for="po_id" class="form-label mb-0">Purchase Order</label>
                        <select id="po_id" name="po_id" class="form-select form-select-sm" required>
                            <option value="">Select PO</option>
                            <?php foreach ($purchaseOrders as $purchaseOrder): ?>
                                <option value="<?php echo (int) $purchaseOrder['id']; ?>" <?php echo $purchaseOrderId === (int) $purchaseOrder['id'] ? 'selected' : ''; ?>>
                                    <?php echo h(($purchaseOrder['po_number'] ?: ('PO #' . (int) $purchaseOrder['id'])) . ' - ' . ($purchaseOrder['supplier_name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Load Final IAR</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($po): ?>
            <div class="iar-appendix">Appendix 62</div>
            <div class="iar-title">Inspection and Acceptance Report</div>
            <div class="iar-subtitle">Entity Name</div>
            <div class="text-center mb-3"><span class="iar-entity">University of Antique</span></div>

            <table class="iar-meta-table mb-2">
                <tr>
                    <td class="iar-label">Supplier</td>
                    <td colspan="3"><?php echo h($po['supplier_name'] ?? ''); ?></td>
                    <td class="iar-label">Fund Cluster</td>
                    <td><?php echo h($po['fund_code'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td class="iar-label">Address</td>
                    <td colspan="3"><?php echo h($po['supplier_address'] ?? ''); ?></td>
                    <td class="iar-label">IAR No.</td>
                    <td><?php echo h('Consolidated - ' . ($po['po_number'] ?? '')); ?></td>
                </tr>
                <tr>
                    <td class="iar-label">P.O. No. / Date</td>
                    <td colspan="3"><?php echo h($po['po_number'] ?? ''); ?> / <?php echo h(!empty($po['po_date']) ? date('M d, Y', strtotime((string) $po['po_date'])) : ''); ?></td>
                    <td class="iar-label">As Of</td>
                    <td><?php echo h($consolidatedAsOf); ?></td>
                </tr>
                <tr>
                    <td class="iar-label">Coverage</td>
                    <td colspan="5">
                        <?php if ($receivingRefs): ?>
                            <?php foreach ($receivingRefs as $index => $ref): ?>
                                <?php echo $index > 0 ? '; ' : ''; ?>
                                <?php
                                $refLabel = $ref['ris_no'] ?: $ref['system_reference'];
                                $refDate = !empty($ref['received_date']) ? date('M d, Y', strtotime((string) $ref['received_date'])) : '';
                                ?>
                                <?php echo h($refLabel . ($refDate !== '' ? ' (' . $refDate . ')' : '')); ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            No receiving records found.
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="iar-label">Consolidated Status</td>
                    <td colspan="5"><?php echo h($isComplete ? 'Complete final IAR' : 'Partial consolidated IAR'); ?></td>
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
                    <?php foreach ($items as $item): ?>
                        <?php
                        $qtyOrdered = (float) ($item['qty_ordered'] ?? 0);
                        $qtyDelivered = (float) ($item['quantity_delivered'] ?? 0);
                        $qtyAccepted = (float) ($item['quantity_accepted'] ?? 0);
                        $qtyRejected = (float) ($item['quantity_rejected'] ?? 0);
                        $unitCost = (float) ($item['unit_cost'] ?? 0);
                        $lineTotal = round($unitCost * $qtyAccepted, 2);
                        $unitLabel = trim((string) ($item['abbreviation'] ?? $item['uom_name'] ?? ''));
                        $description = trim((string) ($item['classification_name'] ?? ''));
                        if (!empty($item['item_description'])) {
                            $description = trim($description . ' - ' . (string) $item['item_description'], ' -');
                        }
                        ?>
                        <tr>
                            <td>
                                <?php if ((string) ($item['item_type'] ?? '') === 'supply' && !empty($item['stock_no'])): ?>
                                    <?php echo h($item['stock_no']); ?>
                                <?php elseif (in_array((string) ($item['item_type'] ?? ''), ['semi_expendable', 'equipment'], true)): ?>
                                    <span class="text-muted">To be assigned upon distribution</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo nl2br(h($description)); ?></div>
                                <div class="iar-description-meta"><?php echo h(consolidated_receiving_type_label((string) ($item['item_type'] ?? ''))); ?><?php echo !empty($item['account_code']) ? ' | ' . h($item['account_code']) : ''; ?></div>
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
                        <tr><td colspan="9" class="text-center">No received items found for this PO.</td></tr>
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
                        <div><strong>Date Inspected:</strong> <?php echo h($consolidatedAsOf); ?></div>
                        <div class="mt-2 iar-note">Inspected, verified, and found in order as to quantity and specifications.</div>
                        <div class="iar-line"></div>
                        <div class="text-center">Inspection Officer / Inspection Committee</div>
                    </td>
                    <td style="width:50%;">
                        <div class="iar-cell-title">Acceptance</div>
                        <div><strong>Date Received:</strong> <?php echo h($consolidatedAsOf); ?></div>
                        <div class="mt-2">
                            <div>[ <?php echo $isComplete ? '/' : '&nbsp;'; ?> ] Complete</div>
                            <div>[ <?php echo !$isComplete ? '/' : '&nbsp;'; ?> ] Partial (pls. specify quantity)</div>
                        </div>
                        <div class="iar-line"></div>
                        <div class="text-center">Supply and/or Property Custodian</div>
                    </td>
                </tr>
            </table>
        <?php else: ?>
            <div class="alert alert-info">Select a purchase order with receiving transactions to print a consolidated final IAR.</div>
        <?php endif; ?>
    </div>
<?php if ($autoPrint && $po): ?><script>window.addEventListener('load', function(){ window.print(); });</script><?php endif; ?>
</body>
</html>
