<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

function receiving_type_label(string $type): string
{
    return $type === 'equipment' ? 'Equipment' : ($type === 'semi_expendable' ? 'Semi-Expendable' : 'Supplies');
}

function iar_fund_cluster_label(array $row): string
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
$receivingId = (int) ($_GET['id'] ?? 0);

if (!$db || $receivingId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}
if (function_exists('ensure_receiving_item_variance_columns')) {
    ensure_receiving_item_variance_columns($db);
}

$headerStmt = $db->prepare(
    "SELECT r.id, r.system_reference, r.ris_no, r.received_date, r.delivery_receipt_no, r.invoice_no, r.inspected_by, r.remarks, r.status,
            po.po_number, po.po_date, po.supplier_address,
            s.supplier_name, s.tin_no, f.fund_code, f.fund_source, f.fund_name,
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
    "SELECT ri.id, ri.actual_item_description, ri.variance_type, ri.variance_note, ri.accepted_no_additional_cost,
            ri.quantity_delivered, ri.quantity_accepted, ri.quantity_rejected, ri.unit_cost, ri.item_condition, ri.remarks,
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
$fundClusterLabel = iar_fund_cluster_label($receiving);
$blankRows = max(0, 10 - count($items));
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>IAR <?php echo h($receiving['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { color:#000; font-family:"Times New Roman", Times, serif; font-size:12px; }
        .iar-wrap { margin:0 auto; max-width:900px; }
        .iar-toolbar { margin:1rem 0; }
        .iar-appendix { font-size:11px; font-style:italic; text-align:right; }
        .iar-title { font-size:22px; font-weight:700; text-align:center; text-transform:uppercase; }
        .iar-header-line { border-bottom:1px solid #000; display:inline-block; min-height:16px; min-width:120px; padding:0 .25rem; }
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
        .iar-items-table th { font-size:11px; padding:.18rem .28rem; font-style:italic; text-align:center; }
        .iar-signatures td { height:118px; vertical-align:top; }
        .iar-note { font-size:10.5px; line-height:1.25; }
        .iar-cell-title { font-size:18px; font-style:italic; font-weight:700; text-align:center; }
        .iar-min-rows td { height:27px; }
        .iar-sign-line { border-bottom:1px solid #000; height:20px; margin:.7rem auto .2rem; width:88%; }
        .iar-checkbox { border:1px solid #000; display:inline-block; height:20px; margin-right:.35rem; vertical-align:middle; width:20px; }
        .iar-check-row { align-items:center; display:flex; gap:.35rem; margin-top:.45rem; }
        .iar-blank { border-bottom:1px solid #000; display:inline-block; min-width:120px; min-height:14px; }
        .no-print { display:block; }
        @media print {
            .no-print { display:none !important; }
            @page { size: 8.5in 13in; margin: 0.5in 0.45in; }
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
                <td colspan="3"><?php echo h($receiving['supplier_name']); ?></td>
                <td class="iar-label">IAR No. :</td>
                <td><?php echo h($receiving['ris_no'] ?: $receiving['system_reference']); ?></td>
            </tr>
            <tr>
                <td class="iar-label">PO No./Date :</td>
                <td colspan="3"><?php echo h($receiving['po_number']); ?> / <?php echo h(format_date((string) $receiving['po_date'])); ?></td>
                <td class="iar-label">Date :</td>
                <td><?php echo h(format_date((string) $receiving['received_date'])); ?></td>
            </tr>
            <tr>
                <td class="iar-label">Requisitioning Office/Dept. :</td>
                <td colspan="3"><?php echo h(trim((string) ($receiving['office_name'] ?? '')) . (!empty($receiving['department_name']) ? ' / ' . (string) $receiving['department_name'] : '')); ?></td>
                <td class="iar-label">Invoice No. :</td>
                <td><?php echo h($receiving['invoice_no'] ?: ''); ?></td>
            </tr>
            <tr>
                <td class="iar-label">Responsibility Center Code :</td>
                <td colspan="3"><?php echo h($receiving['responsibility_center_code'] ?: ''); ?></td>
                <td class="iar-label">Date :</td>
                <td><?php echo h($receiving['delivery_receipt_no'] ?: ''); ?></td>
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
                <?php foreach ($items as $it): ?>
                    <?php
                        $qtyAccepted = (float) ($it['quantity_accepted'] ?? 0);
                        $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                        $description = trim((string) ($it['classification_name'] ?? ''));
                        $actualDescription = trim((string) ($it['actual_item_description'] ?? ''));
                        $orderedDescription = trim((string) ($it['item_description'] ?? ''));
                        if ($actualDescription === '') {
                            $actualDescription = $orderedDescription;
                        }
                        if ($actualDescription !== '') {
                            $description = trim($description . ' - ' . $actualDescription, ' -');
                        }
                        $varianceNote = trim((string) ($it['variance_note'] ?? ''));
                        if (($it['variance_type'] ?? 'none') !== 'none' && $varianceNote !== '') {
                            $description .= "\nInspection note: " . $varianceNote;
                        }
                        if ($orderedDescription !== '' && $actualDescription !== $orderedDescription) {
                            $description .= "\nOrdered per PO: " . $orderedDescription;
                        }
                    ?>
                    <tr>
                        <td>
                            <?php if ((string) ($it['item_type'] ?? '') === 'supply' && !empty($it['stock_no'])): ?>
                                <?php echo h($it['stock_no']); ?>
                            <?php elseif (in_array((string) ($it['item_type'] ?? ''), ['semi_expendable', 'equipment'], true)): ?>
                                &nbsp;
                            <?php endif; ?>
                        </td>
                        <td><?php echo nl2br(h($description)); ?></td>
                        <td><?php echo h($unitLabel); ?></td>
                        <td class="text-end"><?php echo h(format_quantity($qtyAccepted)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="4" class="text-center">No accepted items found.</td></tr>
                <?php endif; ?>
                <?php for ($i = 0; $i < $blankRows; $i++): ?>
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
                    <div><strong>Date Inspected :</strong> <span class="iar-blank"><?php echo h(format_date((string) $receiving['received_date'])); ?></span></div>
                    <div class="iar-check-row mt-3">
                        <span class="iar-checkbox"></span>
                        <span class="iar-note">Inspected, verified and found in order as to quantity and specifications</span>
                    </div>
                    <div class="iar-sign-line"></div>
                    <div class="text-center fw-semibold"><?php echo h($receiving['inspected_by'] ?: ''); ?></div>
                    <div class="text-center">Inspection Officer / Inspection Committee</div>
                </td>
                <td style="width:50%;">
                    <div class="iar-cell-title">Acceptance</div>
                    <div><strong>Date Received :</strong> <span class="iar-blank"><?php echo h(format_date((string) $receiving['received_date'])); ?></span></div>
                    <?php $isComplete = ($totalDelivered >= array_sum(array_map(function ($row) { return (float) ($row['qty_ordered'] ?? 0); }, $items))); ?>
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
    </div>
</body>
</html>
