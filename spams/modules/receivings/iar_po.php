<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

function consolidated_receiving_type_label(string $type): string
{
    return $type === 'equipment' ? 'Equipment' : ($type === 'semi_expendable' ? 'Semi-Expendable' : 'Supplies');
}

function consolidated_iar_fund_cluster_label(array $row): string
{
    $value = trim((string) ($row['fund_source'] ?? ''));
    if ($value !== '') {
        return $value;
    }

    $value = trim((string) ($row['fund_code'] ?? ''));
    if ($value !== '') {
        return $value;
    }

    return '';
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
if (function_exists('ensure_receiving_item_variance_columns')) {
    ensure_receiving_item_variance_columns($db);
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
                s.supplier_name, s.tin_no, f.fund_code, f.fund_source, f.fund_name
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
                COALESCE(MAX(NULLIF(ri.actual_item_description, '')), poi.item_description) AS actual_item_description,
                GROUP_CONCAT(DISTINCT NULLIF(ri.variance_note, '') SEPARATOR '; ') AS variance_notes,
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
$fundClusterLabel = $po ? consolidated_iar_fund_cluster_label($po) : '';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Final IAR by PO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { color:#000; font-family:"Times New Roman", Times, serif; font-size:12px; }
        .iar-wrap { margin:0 auto; max-width:900px; }
        .iar-toolbar { margin:1rem 0; }
        .iar-appendix { font-size:11px; font-style:italic; text-align:right; }
        .iar-title { font-size:22px; font-weight:700; text-align:center; text-transform:uppercase; }
        .iar-meta-table td,
        .iar-items-table td,
        .iar-items-table th,
        .iar-footer-table td { border:1px solid #000 !important; }
        .iar-meta-table,
        .iar-items-table,
        .iar-footer-table { width:100%; border-collapse:collapse; }
        .iar-meta-table td,
        .iar-footer-table td { font-size:11px; padding:.12rem .24rem; vertical-align:top; }
        .iar-label { font-weight:700; white-space:nowrap; width:18%; }
        .iar-items-table th,
        .iar-items-table td { font-size:10.5px; padding:.2rem .28rem; vertical-align:top; }
        .iar-items-table th { font-size:11px; font-style:italic; padding:.18rem .28rem; text-align:center; }
        .iar-signatures td { height:148px; vertical-align:top; }
        .iar-note { font-size:10.5px; line-height:1.25; }
        .iar-cell-title { font-size:18px; font-style:italic; font-weight:700; text-align:center; }
        .iar-min-rows td { height:27px; }
        .iar-sign-line { border-bottom:1px solid #000; height:26px; margin:1rem auto .25rem; width:88%; }
        .iar-checkbox { border:1px solid #000; display:inline-block; height:20px; margin-right:.35rem; vertical-align:middle; width:20px; }
        .iar-check-row { align-items:center; display:flex; gap:.35rem; margin-top:.45rem; }
        .iar-blank { border-bottom:1px solid #000; display:inline-block; min-width:120px; min-height:14px; }
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
            <div class="iar-title mb-3">Inspection and Acceptance Report</div>

            <table class="iar-meta-table mb-2">
                <tr>
                    <td class="iar-label">Entity Name :</td>
                    <td colspan="3"><?php echo h('University of Antique'); ?></td>
                    <td class="iar-label">Fund Cluster :</td>
                    <td><?php echo h($fundClusterLabel); ?></td>
                </tr>
                <tr>
                    <td class="iar-label">Supplier :</td>
                    <td colspan="3"><?php echo h($po['supplier_name'] ?? ''); ?></td>
                    <td class="iar-label">IAR No. :</td>
                    <td><?php echo h('Consolidated - ' . ($po['po_number'] ?? '')); ?></td>
                </tr>
                <tr>
                    <td class="iar-label">PO No./Date :</td>
                    <td colspan="3"><?php echo h($po['po_number'] ?? ''); ?> / <?php echo h(format_date((string) ($po['po_date'] ?? ''))); ?></td>
                    <td class="iar-label">Date :</td>
                    <td><?php echo h($consolidatedAsOf); ?></td>
                </tr>
                <tr>
                    <td class="iar-label">Requisitioning Office/Dept. :</td>
                    <td colspan="3"><?php echo h('Consolidated Receiving'); ?></td>
                    <td class="iar-label">Invoice No. :</td>
                    <td><?php echo h(''); ?></td>
                </tr>
                <tr>
                    <td class="iar-label">Responsibility Center Code :</td>
                    <td colspan="3"><?php echo h(''); ?></td>
                    <td class="iar-label">Date :</td>
                    <td>
                        <?php if ($receivingRefs): ?>
                            <?php echo h($consolidatedAsOf); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <table class="iar-items-table mb-2">
                <thead>
                    <tr>
                        <th style="width:17%;">Stock/<br>Property No.</th>
                        <th>Description</th>
                        <th style="width:14%;">Unit</th>
                        <th style="width:14%;">Quantity</th>
                    </tr>
                </thead>
                <tbody class="iar-min-rows">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $qtyAccepted = (float) ($item['quantity_accepted'] ?? 0);
                        $unitLabel = trim((string) ($item['abbreviation'] ?? $item['uom_name'] ?? ''));
                        $description = trim((string) ($item['classification_name'] ?? ''));
                        $actualDescription = trim((string) ($item['actual_item_description'] ?? ''));
                        $orderedDescription = trim((string) ($item['item_description'] ?? ''));
                        if ($actualDescription === '') {
                            $actualDescription = $orderedDescription;
                        }
                        if ($actualDescription !== '') {
                            $description = trim($description . ' - ' . $actualDescription, ' -');
                        }
                        $varianceNotes = trim((string) ($item['variance_notes'] ?? ''));
                        if ($varianceNotes !== '') {
                            $description .= "\nInspection note: " . $varianceNotes;
                        }
                        if ($orderedDescription !== '' && $actualDescription !== $orderedDescription) {
                            $description .= "\nOrdered per PO: " . $orderedDescription;
                        }
                        ?>
                        <tr>
                            <td>
                            <?php if ((string) ($item['item_type'] ?? '') === 'supply' && !empty($item['stock_no'])): ?>
                                <?php echo h($item['stock_no']); ?>
                            <?php elseif (in_array((string) ($item['item_type'] ?? ''), ['semi_expendable', 'equipment'], true)): ?>
                                &nbsp;
                            <?php endif; ?>
                        </td>
                        <td><?php echo nl2br(h($description)); ?></td>
                        <td><?php echo h($unitLabel); ?></td>
                        <td class="text-end"><?php echo h(format_quantity($qtyAccepted)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$items): ?>
                        <tr><td colspan="4" class="text-center">No received items found for this PO.</td></tr>
                    <?php endif; ?>
                    <?php for ($i = count($items); $i < 14; $i++): ?>
                        <tr>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <table class="iar-footer-table iar-signatures">
                <tr>
                    <td style="width:50%;">
                        <div class="iar-cell-title">Inspection</div>
                        <div><strong>Date Inspected :</strong> <span class="iar-blank"><?php echo h($consolidatedAsOf); ?></span></div>
                        <div class="iar-check-row mt-3">
                            <span class="iar-checkbox"></span>
                            <span class="iar-note">Inspected, verified and found in order as to quantity and specifications</span>
                        </div>
                        <div class="iar-sign-line"></div>
                        <div class="text-center">Inspection Officer / Inspection Committee</div>
                    </td>
                    <td style="width:50%;">
                        <div class="iar-cell-title">Acceptance</div>
                        <div><strong>Date Received :</strong> <span class="iar-blank"><?php echo h($consolidatedAsOf); ?></span></div>
                        <div class="iar-check-row mt-3">
                            <span class="iar-checkbox"><?php echo $isComplete ? '&#10003;' : ''; ?></span>
                            <span>Complete</span>
                        </div>
                        <div class="iar-check-row">
                            <span class="iar-checkbox"><?php echo !$isComplete ? '&#10003;' : ''; ?></span>
                            <span>Partial (pls. specify quantity)</span>
                        </div>
                        <div class="iar-sign-line"></div>
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
