<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

 $db = db();
 $receivingId = (int)($_GET['receiving_id'] ?? 0);
 $poId     = (int)($_GET['po_id'] ?? 0);
 $officeId = (int)($_GET['office_id'] ?? 0);

 if (!$db) {
     http_response_code(404);
     echo 'Invalid parameters.';
     exit;
 }

 // If receiving_id provided, show RIS for that receiving record
 $mode = 'po_office';
 if ($receivingId > 0) {
     $mode = 'receiving';
 }

 // Load header info depending on mode
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
     // po + office mode
     $poStmt = $db->prepare(
         "SELECT po.id, po.po_number, po.po_date, po.purpose,\n" .
         "       s.supplier_name, f.fund_code,\n" .
         "       rc.code AS responsibility_center_code,\n" .
         "       dep.name AS department_name\n" .
         "FROM purchase_orders po\n" .
         "INNER JOIN suppliers s ON s.id = po.supplier_id\n" .
         "INNER JOIN funds f ON f.id = po.fund_id\n" .
         "LEFT JOIN responsibility_codes rc ON rc.office_id = ?\n" .
         "LEFT JOIN offices o ON o.id = ?\n" .
         "LEFT JOIN departments dep ON dep.id = o.department_id\n" .
         "WHERE po.id = ?\n" .
         "LIMIT 1"
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

     // Load office info
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

// PART A — Supplies issued to this office from this PO, or supplies received for a specific receiving
if ($mode === 'receiving') {
    $suppliesStmt = $db->prepare(
        "SELECT poi.line_no, poi.item_description, u.uom_name, u.abbreviation, '' AS stock_no, ri.quantity_received AS quantity, ri.unit_cost, ROUND(ri.quantity_received * ri.unit_cost,2) AS line_total, r.system_reference AS doc_ref, r.received_date AS doc_date, '' AS first_name, '' AS middle_name, '' AS last_name, '' AS suffix_name, '' AS position_title, 'IAR' AS doc_type FROM receiving_items ri INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id INNER JOIN receivings r ON r.id = ri.receiving_id AND r.id = ? WHERE poi.item_type IN ('supply', 'semi_expendable') ORDER BY poi.line_no ASC"
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
        "SELECT poi.line_no, poi.item_description, u.uom_name, u.abbreviation, si.system_reference AS stock_no, si.unit_cost, ii.quantity_issued AS quantity, ROUND(ii.quantity_issued * si.unit_cost, 2) AS line_total, iss.system_reference AS doc_ref, iss.issuance_date AS doc_date, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title, 'RIS' AS doc_type FROM issuance_items ii INNER JOIN issuances iss ON iss.id = ii.issuance_id AND iss.office_id = ? INNER JOIN stock_items si ON si.id = ii.stock_item_id INNER JOIN receiving_items ri ON ri.id = si.receiving_item_id INNER JOIN receivings r ON r.id = ri.receiving_id INNER JOIN purchase_orders po ON po.id = r.purchase_order_id AND po.id = ? INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id LEFT JOIN employees e ON e.id = iss.employee_id ORDER BY poi.line_no ASC, ii.id ASC"
    );
    $supplies = [];
    if ($suppliesStmt) {
        $suppliesStmt->bind_param('ii', $officeId, $poId);
        $suppliesStmt->execute();
        $supplies = $suppliesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $suppliesStmt->close();
    }
}

// PART B — Semi/Equipment distributed to this office from this PO
$distribStmt = $db->prepare(
    "SELECT\n" .
    "  poi.line_no,\n" .
    "  poi.item_description,\n" .
    "  poi.item_type,\n" .
    "  u.uom_name, u.abbreviation,\n" .
    "  si.system_reference AS stock_no,\n" .
    "  di.unit_cost,\n" .
    "  di.quantity_distributed AS quantity,\n" .
    "  di.line_total,\n" .
    "  d.document_no AS doc_ref,\n" .
    "  d.distribution_date AS doc_date,\n" .
    "  e.first_name, e.middle_name, e.last_name,\n" .
    "  e.suffix_name, e.position_title,\n" .
    "  UPPER(d.document_type) AS doc_type\n" .
    "FROM distribution_items di\n" .
    "INNER JOIN distributions d ON d.id = di.distribution_id AND d.status != 'cancelled' AND d.office_id = ?\n" .
    "INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id\n" .
    "INNER JOIN receivings r ON r.id = ri.receiving_id\n" .
    "INNER JOIN purchase_orders po ON po.id = r.purchase_order_id AND po.id = ?\n" .
    "INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id\n" .
    "LEFT JOIN stock_items si ON si.receiving_item_id = ri.id\n" .
    "LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id\n" .
    "LEFT JOIN employees e ON e.id = d.employee_id\n" .
    "ORDER BY poi.line_no ASC, di.id ASC"
);

$distributed = [];
if ($distribStmt) {
    $distribStmt->bind_param('ii', $officeId, $poId);
    $distribStmt->execute();
    $distributed = $distribStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $distribStmt->close();
}

// Merge all items ordered by line_no
$allItems = array_merge($supplies, $distributed);
usort($allItems, function($a, $b) {
    return (int)($a['line_no'] ?? 0) - (int)($b['line_no'] ?? 0);
});

if (empty($allItems)) {
    echo '<div style="font-family:sans-serif;padding:40px;">';
    echo '<h3>No items found</h3>';
    echo '<p>No supplies were issued or semi/equipment distributed to this office from this PO.</p>';
    echo '<a href="' . base_url('modules/purchase_orders/view.php?id=' . $poId) . '">';
    echo '← Back</a></div>';
    exit;
}

// HTML output (RIS Appendix 63)
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>RIS <?php if ($mode === 'receiving') { echo h($po['ris_no'] ?? $po['iar_ref'] ?? ''); } else { echo h($po['po_number'] . '-' . ($office['office_code'] ?? '')); } ?></title>
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
        <div class="d-flex justify-content-between align-items-start mt-3 mb-2">
            <div>
                <a href="<?php echo base_url('modules/purchase_orders/view.php?id=' . $poId); ?>" class="btn btn-sm btn-outline-secondary no-print">Back</a>
                <button onclick="window.print()" class="btn btn-sm btn-primary no-print">Print</button>
            </div>
            <div class="appendix">Appendix 63</div>
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
                <div><strong>Fund Cluster:</strong> <?php echo h($po['fund_code']); ?></div>
                <div><strong>Division/Office:</strong> <?php echo h($po['department_name'] . ' / ' . ($office['office_name'] ?? '')); ?></div>
                <div><strong>Responsibility Center Code:</strong> <?php echo h($po['responsibility_center_code'] ?? ''); ?></div>
                <div><strong>Supplier:</strong> <?php echo h($po['supplier_name']); ?></div>
                <div><strong>PO No./Date:</strong> <?php echo h($po['po_number']); ?> / <?php echo h($po['po_date']); ?></div>
            </div>
            <div class="col-6 text-end">
                <div><strong>RIS No.:</strong>
                    <?php if ($mode === 'receiving'): ?>
                        <?php echo h($po['ris_no'] ?? $po['iar_ref'] ?? ''); ?>
                    <?php else: ?>
                        <?php echo h($po['po_number'] . '-' . ($office['office_code'] ?? '')); ?>
                    <?php endif; ?>
                </div>
                <div><strong>Date:</strong> <?php echo h(date('M d, Y')); ?></div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Stock No.</th>
                        <th>Unit</th>
                        <th>Description</th>
                        <th class="text-end">Qty Requested</th>
                        <th class="text-end">Qty Issued</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Amount</th>
                        <th>Issued via</th>
                        <th>Date</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $grandTotal = 0.0; foreach ($allItems as $it):
                        $stockNo = $it['stock_no'] ?? '';
                        $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                        $description = $it['item_description'] ?? '';
                        $qty = (float) ($it['quantity'] ?? 0);
                        $unitCost = (float) ($it['unit_cost'] ?? 0);
                        $lineTotal = (float) ($it['line_total'] ?? round($qty * $unitCost, 2));
                        $grandTotal += $lineTotal;
                        $docRef = $it['doc_ref'] ?? '';
                        $docDate = $it['doc_date'] ?? '';
                        $empName = trim(($it['first_name'] ?? '') . ' ' . ($it['middle_name'] ?? '') . ' ' . ($it['last_name'] ?? ''));
                        $position = $it['position_title'] ?? '';
                    ?>
                    <tr>
                        <td><?php echo h($stockNo); ?></td>
                        <td><?php echo h($unitLabel); ?></td>
                        <td>
                            <?php echo nl2br(h($description)); ?>
                            <div class="small text-muted mt-1">via <?php echo h($it['doc_type'] ?? ''); ?>: <?php echo h($docRef); ?></div>
                            <?php if ($empName): ?><div class="small text-muted"><?php echo h($empName); ?><?php echo $position ? ' — ' . h($position) : ''; ?></div><?php endif; ?>
                        </td>
                        <td class="text-end"><?php echo h(number_format($qty,2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($qty,2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($unitCost,2)); ?></td>
                        <td class="text-end"><?php echo h(number_format($lineTotal,2)); ?></td>
                        <td><?php echo h($docRef); ?></td>
                        <td><?php echo h($docDate ? date('M d, Y', strtotime($docDate)) : ''); ?></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="text-end fw-semibold">Total</td>
                        <td class="text-end fw-semibold"><?php echo h(number_format($grandTotal,2)); ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="row mt-4">
            <div class="col-md-6">
                <div><strong>Requested by:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;width:80%;"></div>
                <div class="small">Requisitioner / Date</div>
            </div>
            <div class="col-md-6">
                <div><strong>Approved by:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;width:80%;"></div>
                <div class="small">Authorized Official / Date</div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-6">
                <div><strong>Issued by:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;width:80%;"></div>
                <div class="small">Supply/Property Custodian / Date</div>
            </div>
            <div class="col-md-6">
                <div><strong>Received by:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;width:80%;"></div>
                <div class="small">Name / Position / Date</div>
            </div>
        </div>

    </div>
</body>
</html>
