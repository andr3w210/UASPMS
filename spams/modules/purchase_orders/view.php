<?php
require_once __DIR__ . '/../../app/config/init.php';

require_role('Administrator', 'Supply Officer');

$db = db();
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
           po.delivery_term_days, po.expected_delivery_date, po.total_amount, po.status, po.is_partial_entry, po.created_at,
           s.supplier_name, s.tin_no, f.fund_name, f.fund_code, f.fund_source, mop.mode_name AS mode_of_procurement_name
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
           sc.stock_no, c.classification_name, ac.account_code, ac.account_name, u.uom_name, u.abbreviation
    FROM purchase_order_items poi
    LEFT JOIN stock_catalog sc ON sc.id = poi.stock_catalog_id
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

$poItems = [];
$poItemStmt = $db->prepare("SELECT item_type FROM purchase_order_items WHERE purchase_order_id = ?");
if ($poItemStmt) {
    $poItemStmt->bind_param('i', $purchaseOrderId);
    $poItemStmt->execute();
    $res = $poItemStmt->get_result();
    if ($res) {
        $poItems = $res->fetch_all(MYSQLI_ASSOC);
    }
    $poItemStmt->close();
}

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
    $result = $risOfficesStmt->get_result();
    if ($result) {
        $risOffices = $result->fetch_all(MYSQLI_ASSOC);
    }
    $risOfficesStmt->close();
}

$receivingsForPo = [];
$receivingsStmt = $db->prepare("SELECT id, system_reference, ris_no, received_date, status, total_received_amount FROM receivings WHERE purchase_order_id = ? ORDER BY received_date DESC, id DESC");
if ($receivingsStmt) {
    $receivingsStmt->bind_param('i', $purchaseOrderId);
    $receivingsStmt->execute();
    $rv = $receivingsStmt->get_result();
    if ($rv) {
        $receivingsForPo = $rv->fetch_all(MYSQLI_ASSOC);
    }
    $receivingsStmt->close();
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

function receiving_status_badge(string $status): string
{
    if ($status === 'completed') {
        return '<span class="badge text-bg-success">Completed</span>';
    }
    if ($status === 'partial') {
        return '<span class="badge text-bg-warning text-dark">Partial</span>';
    }
    if ($status === 'cancelled') {
        return '<span class="badge text-bg-danger">Cancelled</span>';
    }
    return '<span class="badge text-bg-secondary">' . h(ucfirst($status)) . '</span>';
}

function po_print_unit_label(array $item): string
{
    $uomLabel = trim((string) ($item['uom_name'] ?? ''));
    if ($uomLabel === '' && !empty($item['abbreviation'])) {
        return (string) $item['abbreviation'];
    }
    if ($uomLabel !== '' && !empty($item['abbreviation'])) {
        return $uomLabel . ' (' . $item['abbreviation'] . ')';
    }
    return $uomLabel !== '' ? $uomLabel : '-';
}

function po_number_to_words_under_1000(int $number): string
{
    $ones = [
        0 => '',
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
        8 => 'Eight',
        9 => 'Nine',
        10 => 'Ten',
        11 => 'Eleven',
        12 => 'Twelve',
        13 => 'Thirteen',
        14 => 'Fourteen',
        15 => 'Fifteen',
        16 => 'Sixteen',
        17 => 'Seventeen',
        18 => 'Eighteen',
        19 => 'Nineteen',
    ];
    $tens = [
        2 => 'Twenty',
        3 => 'Thirty',
        4 => 'Forty',
        5 => 'Fifty',
        6 => 'Sixty',
        7 => 'Seventy',
        8 => 'Eighty',
        9 => 'Ninety',
    ];

    if ($number === 0) {
        return '';
    }
    if ($number < 20) {
        return $ones[$number];
    }
    if ($number < 100) {
        $tenPart = intdiv($number, 10);
        $remainder = $number % 10;
        return $tens[$tenPart] . ($remainder ? ' ' . $ones[$remainder] : '');
    }

    $hundreds = intdiv($number, 100);
    $remainder = $number % 100;
    return $ones[$hundreds] . ' Hundred' . ($remainder ? ' ' . po_number_to_words_under_1000($remainder) : '');
}

function po_amount_in_words(float $amount): string
{
    $whole = (int) floor($amount);
    $fraction = (int) round(($amount - $whole) * 100);

    if ($whole === 0) {
        $words = 'Zero';
    } else {
        $parts = [];
        $scales = ['', 'Thousand', 'Million', 'Billion'];
        $scaleIndex = 0;

        while ($whole > 0) {
            $chunk = $whole % 1000;
            if ($chunk > 0) {
                $parts[] = trim(po_number_to_words_under_1000($chunk) . ' ' . $scales[$scaleIndex]);
            }
            $whole = intdiv($whole, 1000);
            $scaleIndex++;
        }

        $words = implode(' ', array_reverse($parts));
    }

    return trim($words . ' Pesos') . ' and ' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT) . '/100';
}

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

$totalAmountInWords = po_amount_in_words((float) $purchaseOrder['total_amount']);
$fundClusterLabel = trim((string) ($purchaseOrder['fund_source'] ?? ''));
if ($fundClusterLabel === '') {
    $fundClusterLabel = trim((string) ($purchaseOrder['fund_code'] ?? ''));
}
if ($fundClusterLabel === '') {
    $fundClusterLabel = trim((string) ($purchaseOrder['fund_name'] ?? ''));
}
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
                <?php if (!empty($purchaseOrder['is_partial_entry'])): ?>
                    <span class="badge text-bg-info ms-1">Partial Entry</span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <a href="<?php echo base_url('modules/purchase_orders/index.php'); ?>" class="btn btn-outline-secondary">Back</a>
                <?php if (!empty($purchaseOrder['is_partial_entry']) && ($purchaseOrder['status'] ?? '') !== 'cancelled'): ?>
                    <a href="<?php echo base_url('modules/purchase_orders/edit.php?id=' . (int) $purchaseOrder['id']); ?>" class="btn btn-outline-secondary">Edit / Add Items</a>
                <?php endif; ?>
                <a href="<?php echo base_url('modules/messages/index.php?related_table=purchase_orders&related_id=' . (int) $purchaseOrder['id']); ?>" class="btn btn-outline-info">
                    Discussion
                </a>
                <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
                <?php if ($purchaseOrder['status'] !== 'completed' && $purchaseOrder['status'] !== 'cancelled'): ?>
                    <a href="<?php echo base_url('modules/receivings/index.php?po_id=' . (int) $purchaseOrder['id']); ?>" class="btn btn-success">Receive Delivery</a>
                <?php endif; ?>
                <?php if (!empty($risOffices)): ?>
                    <div class="dropdown d-inline-block">
                        <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">Print RIS</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php foreach ($risOffices as $ro): ?>
                                <li>
                                    <a class="dropdown-item" target="_blank" href="<?php echo base_url('modules/receivings/ris.php?po_id=' . $purchaseOrderId . '&office_id=' . (int) $ro['office_id']); ?>">
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
        <section class="po-document coa-po-document">
            <header class="po-document-header">
                <div class="po-document-form-meta">
                    <span>Fund Cluster: <?php echo h($fundClusterLabel ?: '________________'); ?></span>
                    <span>Appendix 61</span>
                </div>
                <h1>Purchase Order</h1>
                <div class="po-document-entity">Entity Name</div>
                <div class="po-document-subtitle po-entity-name">University of Antique</div>
            </header>

            <table class="table table-bordered po-detail-table po-sample-header-table">
                <tbody>
                    <tr>
                        <td class="po-label-cell">Supplier</td>
                        <td class="po-value-cell" colspan="3"><?php echo h($purchaseOrder['supplier_name']); ?></td>
                        <td class="po-label-cell">P.O. No.</td>
                        <td class="po-value-cell"><?php echo h($purchaseOrder['po_number']); ?></td>
                    </tr>
                    <tr>
                        <td class="po-label-cell">Address</td>
                        <td class="po-value-cell" colspan="3"><?php echo h($purchaseOrder['supplier_address'] ?: '-'); ?></td>
                        <td class="po-label-cell">Date</td>
                        <td class="po-value-cell"><?php echo h(date('F d, Y', strtotime($purchaseOrder['po_date']))); ?></td>
                    </tr>
                    <tr>
                        <td class="po-label-cell">TIN</td>
                        <td class="po-value-cell" colspan="3"><?php echo h($purchaseOrder['tin_no'] ?: '________________'); ?></td>
                        <td class="po-label-cell">Mode of Procurement</td>
                        <td class="po-value-cell"><?php echo h($purchaseOrder['mode_of_procurement_name'] ?: '-'); ?></td>
                    </tr>
                </tbody>
            </table>

            <section class="po-document-intro po-gentlemen-row">
                <div class="po-gentlemen-label">Gentlemen:</div>
                <p>Please furnish this Office the following articles subject to the terms and conditions contained herein:</p>
            </section>

            <table class="table table-bordered po-detail-table">
                <tbody>
                    <tr>
                        <td class="po-label-cell">Place of Delivery</td>
                        <td class="po-value-cell"><?php echo h($purchaseOrder['place_of_delivery'] ?: '-'); ?></td>
                        <td class="po-label-cell">Delivery Term</td>
                        <td class="po-value-cell"><?php echo $purchaseOrder['delivery_term_days'] !== null ? h((string) $purchaseOrder['delivery_term_days'] . ' day(s)') : '-'; ?></td>
                    </tr>
                    <tr>
                        <td class="po-label-cell">Date of Delivery</td>
                        <td class="po-value-cell"><?php echo $purchaseOrder['expected_delivery_date'] ? h(date('F d, Y', strtotime($purchaseOrder['expected_delivery_date']))) : '-'; ?></td>
                        <td class="po-label-cell">Payment Term</td>
                        <td class="po-value-cell">Charge to Available Funds</td>
                    </tr>
                </tbody>
            </table>

            <section class="po-document-body">
                <div class="table-responsive">
                    <table class="table po-items-table mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 12%;">Stock / Property No.</th>
                                <th class="text-center" style="width: 10%;">Unit</th>
                                <th>Description</th>
                                <th class="text-end" style="width: 10%;">Quantity</th>
                                <th class="text-end" style="width: 14%;">Unit Cost</th>
                                <th class="text-end" style="width: 14%;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($items): ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="text-center"><?php echo h($item['stock_no'] ?: ''); ?></td>
                                        <td class="text-center"><?php echo h(po_print_unit_label($item)); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo h($item['item_description'] ?: '-'); ?></div>
                                            <div class="small po-item-meta"><?php echo h(trim(($item['classification_name'] ?: 'Unclassified') . (!empty($item['account_code']) ? ' | ' . $item['account_code'] : ''))); ?></div>
                                            <div class="small text-muted"><?php echo h(trim(po_item_type_label((string) $item['item_type']) . (!empty($item['account_name']) ? ' | ' . $item['account_name'] : ''))); ?></div>
                                        </td>
                                        <td class="text-end"><?php echo h(format_quantity($item['quantity'])); ?></td>
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
            </section>

            <table class="table table-bordered po-detail-table po-total-words-table">
                <tbody>
                    <tr>
                        <td class="po-label-cell po-total-words-label">(Total Amount in Words)</td>
                        <td class="po-value-cell po-total-words-value"><?php echo h($totalAmountInWords); ?></td>
                    </tr>
                </tbody>
            </table>

            <section class="po-document-notes po-penalty-box">
                <div class="po-note-box">
                    In case of failure to make the full delivery within the time specified above, a penalty of one-tenth (1/10) of one percent for every day of delay shall be imposed on the undelivered item/s.
                </div>
            </section>

            <section class="po-document-signatures po-document-signatures-sample">
                <div class="po-signature-box">
                    <div class="po-signature-label">Conforme:</div>
                    <div class="po-signature-line"></div>
                    <div class="po-signature-meta">Signature over Printed Name of Supplier</div>
                </div>
                <div class="po-signature-box">
                    <div class="po-signature-label">Very truly yours,</div>
                    <div class="po-signature-line"></div>
                    <div class="po-signature-meta">Signature over Printed Name of Authorized Official</div>
                </div>
                <div class="po-signature-box">
                    <div class="po-signature-label">Date</div>
                    <div class="po-signature-line"></div>
                    <div class="po-signature-meta">Designation</div>
                </div>
            </section>

            <table class="table table-bordered po-detail-table po-fund-footer-table">
                <tbody>
                    <tr>
                        <td class="po-label-cell">Fund Cluster :</td>
                        <td class="po-value-cell"><?php echo h($fundClusterLabel ?: '________________'); ?></td>
                        <td class="po-label-cell">ORS/BURS No. :</td>
                        <td class="po-value-cell">&nbsp;</td>
                    </tr>
                    <tr>
                        <td class="po-label-cell">Funds Available :</td>
                        <td class="po-value-cell"><?php echo h($purchaseOrder['fund_name'] ?: '________________'); ?></td>
                        <td class="po-label-cell">Date of the ORS/BURS:</td>
                        <td class="po-value-cell">&nbsp;</td>
                    </tr>
                    <tr>
                        <td class="po-signoff-cell" colspan="2">
                            <div class="po-signature-line"></div>
                            <div class="po-signature-meta">Signature over Printed Name of Chief Accountant/Head of Accounting Division/Unit</div>
                        </td>
                        <td class="po-label-cell">Amount :</td>
                        <td class="po-value-cell"><?php echo h(number_format((float) $purchaseOrder['total_amount'], 2)); ?></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <?php if (!empty($receivingsForPo)): ?>
            <section class="card mt-3 d-print-none">
                <div class="card-body">
                    <h6 class="mb-3">Previous Receivings</h6>
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
                                <?php foreach ($receivingsForPo as $r): ?>
                                    <tr>
                                        <td><?php echo h($r['ris_no'] ?: $r['system_reference']); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($r['received_date']))); ?></td>
                                        <td class="text-end"><?php echo h(number_format((float) $r['total_received_amount'], 2)); ?></td>
                                        <td><?php echo receiving_status_badge($r['status'] ?? 'encoded'); ?></td>
                                        <td class="text-end">
                                            <?php if (in_array($r['status'] ?? '', ['partial', 'completed'], true)): ?>
                                                <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?php echo base_url('modules/receivings/iar.php?id=' . (int) $r['id']); ?>">Print IAR</a>
                                            <?php endif; ?>
                                            <?php if (($r['status'] ?? '') === 'completed' && $hasSupplyOrSemi): ?>
                                                <a class="btn btn-sm btn-outline-success" target="_blank" href="<?php echo base_url('modules/receivings/ris.php?receiving_id=' . (int) $r['id']); ?>">Print RIS</a>
                                            <?php endif; ?>
                                            <?php if (($r['status'] ?? '') === 'completed' && $hasEquipment): ?>
                                                <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?php echo base_url('modules/property/property_card.php?receiving_id=' . (int) $r['id']); ?>">Property Card</a>
                                            <?php endif; ?>
                                            <?php if (($r['status'] ?? '') === 'completed' && $hasSupplyOrSemi): ?>
                                                <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?php echo base_url('modules/property/stock_card.php?receiving_id=' . (int) $r['id']); ?>">Stock Card</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
