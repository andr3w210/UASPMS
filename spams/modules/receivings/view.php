<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$receivingId = (int) ($_GET['id'] ?? 0);
$receiving = null;
$items = [];
$unitDetails = [];

if ($db && $receivingId > 0) {
    if (function_exists('ensure_receiving_item_variance_columns')) {
        ensure_receiving_item_variance_columns($db);
    }

    $stmt = $db->prepare(
        "SELECT r.id, r.system_reference, r.ris_no, r.received_date, r.delivery_receipt_no, r.invoice_no,
                r.inspected_by, r.remarks, r.status, r.total_received_amount,
                po.id AS purchase_order_id, po.po_number, s.supplier_name
         FROM receivings r
         INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
         INNER JOIN suppliers s ON s.id = po.supplier_id
         WHERE r.id = ?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $receivingId);
        $stmt->execute();
        $receiving = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

if (!$receiving) {
    set_flash('error', 'Receiving record not found.');
    redirect('modules/receivings/index.php');
}

$stmt = $db->prepare(
    "SELECT ri.id, ri.actual_item_description, ri.variance_type, ri.variance_note,
            ri.quantity_delivered, ri.quantity_accepted, ri.quantity_rejected, ri.unit_cost, ri.line_total,
            ri.item_condition, ri.remarks,
            poi.line_no, poi.item_type, poi.item_description, poi.quantity AS qty_ordered,
            u.abbreviation, u.uom_name
     FROM receiving_items ri
     INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
     LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
     WHERE ri.receiving_id = ?
     ORDER BY poi.line_no ASC, ri.id ASC"
);
if ($stmt) {
    $stmt->bind_param('i', $receivingId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$stmt = $db->prepare(
    "SELECT rid.receiving_item_id, rid.brand, rid.model, rid.serial_no, rid.remarks
     FROM receiving_item_details rid
     INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
     WHERE ri.receiving_id = ?
     ORDER BY rid.id ASC"
);
if ($stmt) {
    $stmt->bind_param('i', $receivingId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $detail) {
        $unitDetails[(int) $detail['receiving_item_id']][] = $detail;
    }
    $stmt->close();
}

$typeLabels = ['equipment' => 'Equipment', 'semi_expendable' => 'Semi-Expendable'];
$varianceLabels = [
    'none' => '', 'higher_specs' => 'Higher specs', 'substitution' => 'Substitution',
    'defective' => 'Defective', 'short_delivery' => 'Short delivery', 'other' => 'Other',
];
$statusClasses = ['completed' => 'bg-success-lt', 'partial' => 'bg-warning-lt', 'cancelled' => 'bg-secondary-lt'];
$qty = static function ($value): string {
    $number = (float) $value;
    return rtrim(rtrim(number_format($number, 2), '0'), '.');
};

$totalDelivered = array_sum(array_map(static fn($i) => (float) $i['quantity_delivered'], $items));
$totalAccepted = array_sum(array_map(static fn($i) => (float) $i['quantity_accepted'], $items));
$totalRejected = array_sum(array_map(static fn($i) => (float) $i['quantity_rejected'], $items));
$printable = in_array($receiving['status'], ['completed', 'partial'], true);

$page_title = 'Receiving: ' . $receiving['system_reference'];
$flash = get_flash();
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="mb-1"><?php echo h($receiving['system_reference']); ?></h4>
                <div class="text-muted">
                    PO <?php echo h($receiving['po_number']); ?> &middot; <?php echo h($receiving['supplier_name']); ?>
                    &nbsp;<span class="badge <?php echo h($statusClasses[$receiving['status']] ?? 'bg-secondary-lt'); ?>"><?php echo h(ucfirst((string) $receiving['status'])); ?></span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo h(base_url('modules/receivings/index.php')); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to list</a>
                <?php if ($printable): ?>
                    <a href="<?php echo h(base_url('modules/receivings/iar.php?id=' . $receivingId)); ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-1"></i>Print IAR</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6 col-lg-3"><div class="text-muted small">Received date</div><div class="fw-semibold"><?php echo h(date('M d, Y', strtotime((string) $receiving['received_date']))); ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="text-muted small">RIS No.</div><div class="fw-semibold"><?php echo h($receiving['ris_no'] ?: '—'); ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="text-muted small">Delivery receipt</div><div class="fw-semibold"><?php echo h($receiving['delivery_receipt_no'] ?: '—'); ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="text-muted small">Invoice</div><div class="fw-semibold"><?php echo h($receiving['invoice_no'] ?: '—'); ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="text-muted small">Inspected by</div><div class="fw-semibold"><?php echo h($receiving['inspected_by'] ?: '—'); ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="text-muted small">Total received amount</div><div class="fw-semibold"><?php echo h(number_format((float) $receiving['total_received_amount'], 2)); ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="text-muted small">Delivered / Accepted / Rejected</div><div class="fw-semibold"><?php echo h($qty($totalDelivered) . ' / ' . $qty($totalAccepted) . ' / ' . $qty($totalRejected)); ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="text-muted small">Purchase order</div><div class="fw-semibold"><a href="<?php echo h(base_url('modules/purchase_orders/view.php?id=' . (int) $receiving['purchase_order_id'])); ?>" target="_blank"><?php echo h($receiving['po_number']); ?></a></div></div>
                <?php if (trim((string) $receiving['remarks']) !== ''): ?>
                    <div class="col-12"><div class="text-muted small">Remarks</div><div><?php echo nl2br(h($receiving['remarks'])); ?></div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header"><h5 class="card-title mb-0">Items Delivered / Received</h5></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0" data-no-table-search="true">
                <thead>
                    <tr>
                        <th>#</th><th>Item</th><th>Type</th><th>Unit</th>
                        <th class="text-end">Ordered</th><th class="text-end">Delivered</th>
                        <th class="text-end">Accepted</th><th class="text-end">Rejected</th>
                        <th>Condition</th><th class="text-end">Unit cost</th><th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No items were recorded for this receiving.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $item):
                    $actual = trim((string) $item['actual_item_description']);
                    $ordered = trim((string) $item['item_description']);
                    $variance = $varianceLabels[(string) $item['variance_type']] ?? '';
                    $details = $unitDetails[(int) $item['id']] ?? [];
                    ?>
                    <tr>
                        <td><?php echo (int) $item['line_no']; ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo h($actual !== '' ? $actual : $ordered); ?></div>
                            <?php if ($actual !== '' && $actual !== $ordered): ?>
                                <div class="small text-muted">Ordered as: <?php echo h($ordered); ?></div>
                            <?php endif; ?>
                            <?php if ($variance !== ''): ?>
                                <span class="badge bg-warning-lt"><?php echo h($variance); ?></span>
                                <?php if (trim((string) $item['variance_note']) !== ''): ?><span class="small text-muted"><?php echo h($item['variance_note']); ?></span><?php endif; ?>
                            <?php endif; ?>
                            <?php if (trim((string) $item['remarks']) !== ''): ?>
                                <div class="small text-muted"><?php echo h($item['remarks']); ?></div>
                            <?php endif; ?>
                            <?php if ($details): ?>
                                <details class="mt-1">
                                    <summary class="small text-primary" style="cursor:pointer;"><?php echo count($details); ?> unit(s) &ndash; brand / model / serial</summary>
                                    <ul class="small mb-0 ps-3">
                                        <?php foreach ($details as $detail): ?>
                                            <li><?php echo h(trim(($detail['brand'] ?? '') . ' ' . ($detail['model'] ?? '')) ?: '—'); ?><?php echo trim((string) $detail['serial_no']) !== '' ? ' &middot; S/N ' . h($detail['serial_no']) : ''; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h($typeLabels[(string) $item['item_type']] ?? 'Supplies'); ?></td>
                        <td><?php echo h($item['abbreviation'] ?: $item['uom_name'] ?: ''); ?></td>
                        <td class="text-end"><?php echo h($qty($item['qty_ordered'])); ?></td>
                        <td class="text-end"><?php echo h($qty($item['quantity_delivered'])); ?></td>
                        <td class="text-end fw-semibold text-success"><?php echo h($qty($item['quantity_accepted'])); ?></td>
                        <td class="text-end <?php echo (float) $item['quantity_rejected'] > 0 ? 'fw-semibold text-danger' : ''; ?>"><?php echo h($qty($item['quantity_rejected'])); ?></td>
                        <td><?php echo h($item['item_condition'] ?: '—'); ?></td>
                        <td class="text-end"><?php echo h(number_format((float) $item['unit_cost'], 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format((float) $item['line_total'], 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
