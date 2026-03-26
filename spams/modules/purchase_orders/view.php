<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db_connect();
$purchaseOrder = null;
$items = [];

if (!$db) {
    http_response_code(500);
    echo 'Unable to connect to the database.';
    exit;
}

$purchaseOrderId = (int) ($_GET['id'] ?? 0);
if ($purchaseOrderId <= 0) {
    http_response_code(404);
    echo 'Purchase order not found.';
    exit;
}

$stmt = $db->prepare("
    SELECT po.id, po.system_reference, po.po_number, po.po_date, po.supplier_address, po.place_of_delivery,
           po.delivery_term_days, po.expected_delivery_date, po.total_amount, po.status, po.created_at,
           s.supplier_name, f.fund_name, mop.mode_name AS mode_of_procurement_name
    FROM purchase_orders po
    INNER JOIN suppliers s ON s.id = po.supplier_id
    INNER JOIN funds f ON f.id = po.fund_id
    LEFT JOIN mode_of_procurements mop ON mop.id = po.mode_of_procurement_id
    WHERE po.id = ?
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param('i', $purchaseOrderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $purchaseOrder = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$purchaseOrder) {
    http_response_code(404);
    echo 'Purchase order not found.';
    exit;
}

$itemStmt = $db->prepare("
    SELECT poi.line_no, poi.item_type, poi.item_description, poi.quantity, poi.unit_cost, poi.line_total,
           c.classification_name, ac.account_code, ac.account_name, u.uom_name, u.abbreviation
    FROM purchase_order_items poi
    LEFT JOIN classifications c ON c.id = poi.classification_id
    LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
    LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
    WHERE poi.purchase_order_id = ?
    ORDER BY poi.line_no ASC, poi.id ASC
");

if ($itemStmt) {
    $itemStmt->bind_param('i', $purchaseOrderId);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    if ($itemResult) {
        $items = $itemResult->fetch_all(MYSQLI_ASSOC);
    }
    $itemStmt->close();
}

// Load purchase order item types for printing decisions
$poItems = [];
$poItemStmt = $db->prepare(
    "SELECT item_type FROM purchase_order_items WHERE purchase_order_id = ?"
);
if ($poItemStmt) {
    $poItemStmt->bind_param('i', $purchaseOrderId);
    $poItemStmt->execute();
    $res = $poItemStmt->get_result();
    if ($res) $poItems = $res->fetch_all(MYSQLI_ASSOC);
    $poItemStmt->close();
}

function po_item_type_label(string $itemType): string
{
    if ($itemType === 'equipment') {
        return 'Equipment';
    }

    if ($itemType === 'semi_expendable') {
        return 'Semi-Expendable';
    }

    return 'Supplies';
}
// Find offices that received items from this PO (for printing RIS per office)
$risOffices = [];
$risOfficesStmt = $db->prepare(
    "SELECT DISTINCT o.id AS office_id, o.office_name FROM offices o WHERE o.id IN (\n" .
    "  SELECT iss.office_id FROM issuances iss INNER JOIN issuance_items ii ON ii.issuance_id = iss.id INNER JOIN stock_items si ON si.id = ii.stock_item_id INNER JOIN receiving_items ri ON ri.id = si.receiving_item_id INNER JOIN receivings r ON r.id = ri.receiving_id WHERE r.purchase_order_id = ?\n" .
    "  UNION\n" .
    "  SELECT d.office_id FROM distributions d INNER JOIN distribution_items di ON di.distribution_id = d.id AND d.status != 'cancelled' INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id INNER JOIN receivings r ON r.id = ri.receiving_id WHERE r.purchase_order_id = ?\n" .
    ") ORDER BY o.office_name ASC"
);
if ($risOfficesStmt) {
    $risOfficesStmt->bind_param('ii', $purchaseOrderId, $purchaseOrderId);
    $risOfficesStmt->execute();
    $risOffices = $risOfficesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $risOfficesStmt->close();
}

// Fetch previous receivings for this PO
$receivingsForPo = [];
$receivingsStmt = $db->prepare("SELECT id, system_reference, ris_no, received_date, status, total_received_amount FROM receivings WHERE purchase_order_id = ? ORDER BY received_date DESC, id DESC");
if ($receivingsStmt) {
    $receivingsStmt->bind_param('i', $purchaseOrderId);
    $receivingsStmt->execute();
    $rv = $receivingsStmt->get_result();
    if ($rv) $receivingsForPo = $rv->fetch_all(MYSQLI_ASSOC);
    $receivingsStmt->close();
}

// Helper: render receiving status badge (defined here because not present globally)
function receiving_status_badge(string $status): string {
    if ($status === 'completed') {
        return '<span class="badge text-bg-success">Completed</span>';
    } elseif ($status === 'partial') {
        return '<span class="badge text-bg-warning text-dark">Partial</span>';
    } elseif ($status === 'cancelled') {
        return '<span class="badge text-bg-danger">Cancelled</span>';
    } else {
        return '<span class="badge text-bg-secondary">' . h(ucfirst($status)) . '</span>';
    }
}

?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title><?php echo h('PO ' . $purchaseOrder['po_number']); ?> | SPAMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/app.css'); ?>" rel="stylesheet">
</head>
<body class="po-print-page">
    <div class="po-print-toolbar d-print-none">
        <div class="container-fluid d-flex justify-content-between align-items-center gap-3">
            <div>
                <div class="fw-semibold">Purchase Order Preview</div>
                <small class="text-muted"><?php echo h($purchaseOrder['po_number']); ?> | <?php echo h($purchaseOrder['system_reference']); ?></small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo base_url('modules/purchase_orders/index.php'); ?>" class="btn btn-outline-secondary">Back</a>
                <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
                <?php if ($purchaseOrder['status'] !== 'completed' && $purchaseOrder['status'] !== 'cancelled'): ?>
                    <a href="<?php echo base_url('modules/receivings/index.php?po_id=' . (int)$purchaseOrder['id']); ?>" class="btn btn-success">Receive Delivery</a>
                <?php endif; ?>
                <?php if (!empty($risOffices)): ?>
                <div class="dropdown d-inline-block">
                    <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">Print RIS</button>
                    <ul class="dropdown-menu">
                        <?php foreach ($risOffices as $ro): ?>
                        <li>
                            <a class="dropdown-item" target="_blank" href="<?php echo base_url('modules/receivings/ris.php' . '?po_id=' . $purchaseOrderId . '&office_id=' . (int)$ro['office_id']); ?>">
                                <?php echo h($ro['office_name']); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <main class="po-document-wrapper">
        <section class="po-document">
            <header class="po-document-header">
                <div class="po-document-entity">Republic of the Philippines</div>
                <div class="po-document-entity">UNIVERSITY OF ANTIQUE</div>
                <div class="po-document-subtitle">Sibalom, Antique</div>
                <h1>Purchase Order</h1>
            </header>

            <section class="po-document-grid po-document-grid-top">
                <div class="po-grid-cell po-grid-cell-wide">
                    <div class="po-field-label">Supplier</div>
                    <div class="po-field-value"><?php echo h($purchaseOrder['supplier_name']); ?></div>
                </div>
                <div class="po-grid-cell">
                    <div class="po-field-label">PO No.</div>
                    <div class="po-field-value"><?php echo h($purchaseOrder['po_number']); ?></div>
                </div>
                <div class="po-grid-cell po-grid-cell-wide">
                    <div class="po-field-label">Address</div>
                    <div class="po-field-value"><?php echo h($purchaseOrder['supplier_address'] ?: '-'); ?></div>
                </div>
                <div class="po-grid-cell">
                    <div class="po-field-label">Date</div>
                    <div class="po-field-value"><?php echo h(date('F d, Y', strtotime($purchaseOrder['po_date']))); ?></div>
                </div>
                <div class="po-grid-cell">
                    <div class="po-field-label">Fund</div>
                    <div class="po-field-value"><?php echo h($purchaseOrder['fund_name']); ?></div>
                </div>
                <div class="po-grid-cell">
                    <div class="po-field-label">Mode of Procurement</div>
                    <div class="po-field-value"><?php echo h($purchaseOrder['mode_of_procurement_name'] ?: '-'); ?></div>
                </div>
                <div class="po-grid-cell po-grid-cell-wide">
                    <div class="po-field-label">Place of Delivery</div>
                    <div class="po-field-value"><?php echo h($purchaseOrder['place_of_delivery']); ?></div>
                </div>
                <div class="po-grid-cell">
                    <div class="po-field-label">Delivery Term</div>
                    <div class="po-field-value"><?php echo $purchaseOrder['delivery_term_days'] !== null ? h((string) $purchaseOrder['delivery_term_days'] . ' day(s)') : '-'; ?></div>
                </div>
                <div class="po-grid-cell">
                    <div class="po-field-label">End Date</div>
                    <div class="po-field-value"><?php echo $purchaseOrder['expected_delivery_date'] ? h(date('F d, Y', strtotime($purchaseOrder['expected_delivery_date']))) : '-'; ?></div>
                </div>
            </section>

            <section class="po-document-body">
                <div class="table-responsive">
                    <table class="table po-items-table mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 6%;">No.</th>
                                <th style="width: 12%;">Unit</th>
                                <th>Description</th>
                                <th class="text-end" style="width: 10%;">Quantity</th>
                                <th class="text-end" style="width: 14%;">Unit Cost</th>
                                <th class="text-end" style="width: 14%;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($items): ?>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                    $uomLabel = trim((string) ($item['uom_name'] ?? ''));
                                    if ($uomLabel === '' && !empty($item['abbreviation'])) {
                                        $uomLabel = $item['abbreviation'];
                                    } elseif (!empty($item['abbreviation'])) {
                                        $uomLabel .= ' (' . $item['abbreviation'] . ')';
                                    }
                                    $descriptionParts = array_filter([
                                        !empty($item['account_code']) ? $item['account_code'] : '',
                                        $item['classification_name'] ?? '',
                                        $item['item_description'] ?? '',
                                    ]);
                                    ?>
                                    <tr>
                                        <td class="text-center"><?php echo (int) $item['line_no']; ?></td>
                                        <td><?php echo h($uomLabel ?: '-'); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo h(implode(' - ', $descriptionParts)); ?></div>
                                            <div class="text-muted small">
                                                <?php echo h(trim(po_item_type_label((string) $item['item_type']) . (!empty($item['account_name']) ? ' | ' . $item['account_name'] : ''))); ?>
                                            </div>
                                        </td>
                                        <td class="text-end"><?php echo h(number_format((float) $item['quantity'], 2)); ?></td>
                                        <td class="text-end"><?php echo h(number_format((float) $item['unit_cost'], 2)); ?></td>
                                        <td class="text-end"><?php echo h(number_format((float) $item['line_total'], 2)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No line items found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="5" class="text-end">Total</th>
                                <th class="text-end"><?php echo h(number_format((float) $purchaseOrder['total_amount'], 2)); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php if (!empty($receivingsForPo)): ?>
                    <div class="mt-4">
                        <h6>Previous Receivings</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>IAR No.</th>
                                        <th>Date</th>
                                        <th class="text-end">Amount</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $hasSupplyOrSemi = false;
                                        $hasEquipment = false;
                                        foreach ($poItems as $poi) {
                                            if (($poi['item_type'] ?? '') === 'equipment') {
                                                $hasEquipment = true;
                                            }
                                            if (in_array($poi['item_type'] ?? '', ['supply', 'semi_expendable'], true)) {
                                                $hasSupplyOrSemi = true;
                                            }
                                        }
                                    ?>
                                    <?php foreach ($receivingsForPo as $r): ?>
                                        <tr>
                                            <td><?php echo h($r['ris_no'] ?: $r['system_reference']); ?></td>
                                            <td><?php echo h(date('M d, Y', strtotime($r['received_date']))); ?></td>
                                            <td class="text-end"><?php echo h(number_format((float)$r['total_received_amount'],2)); ?></td>
                                            <td><?php echo receiving_status_badge($r['status'] ?? 'encoded'); ?></td>
                                            <td>
                                                <?php if (in_array($r['status'] ?? '', ['partial', 'completed'], true)): ?>
                                                    <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?php echo base_url('modules/receivings/iar.php?id=' . (int)$r['id']); ?>">Print IAR</a>
                                                <?php endif; ?>

                                                <?php if (($r['status'] ?? '') === 'completed' && $hasSupplyOrSemi): ?>
                                                    <a class="btn btn-sm btn-outline-success" target="_blank" href="<?php echo base_url('modules/receivings/ris.php?receiving_id=' . (int)$r['id']); ?>">Print RIS</a>
                                                <?php endif; ?>

                                                <?php if (($r['status'] ?? '') === 'completed' && $hasEquipment): ?>
                                                    <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?php echo base_url('modules/property/property_card.php?receiving_id=' . (int)$r['id']); ?>">Property Card</a>
                                                <?php endif; ?>

                                                <?php if (($r['status'] ?? '') === 'completed' && $hasSupplyOrSemi): ?>
                                                    <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?php echo base_url('modules/property/stock_card.php?receiving_id=' . (int)$r['id']); ?>">Stock Card</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="po-document-notes">
                <div class="po-note-box">
                    <div class="po-field-label">Important Note</div>
                    <div class="po-note-text">
                        This system-generated view is based on the encoded Purchase Order record and is intended for printing and document review.
                    </div>
                </div>
            </section>

            <section class="po-document-signatures">
                <div class="po-signature-box">
                    <div class="po-signature-label">Prepared By</div>
                    <div class="po-signature-line"></div>
                    <div class="po-signature-meta">Supply Office</div>
                </div>
                <div class="po-signature-box">
                    <div class="po-signature-label">Approved By</div>
                    <div class="po-signature-line"></div>
                    <div class="po-signature-meta">Head of Procuring Entity</div>
                </div>
                <div class="po-signature-box">
                    <div class="po-signature-label">Conforme</div>
                    <div class="po-signature-line"></div>
                    <div class="po-signature-meta">Supplier</div>
                </div>
            </section>
        </section>
    </main>
</body>
</html>
