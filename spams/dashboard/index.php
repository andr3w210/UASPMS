<?php
require_once __DIR__ . '/../app/config/init.php';
require_login();

$page_title = 'Dashboard';
$db = db_connect();
$summary = [
    'active_pos' => 0,
    'pending_receivings' => 0,
    'distributed_items' => 0,
    'disposed_this_year' => 0,
    'returned_this_year' => 0,
];
$recentPurchaseOrders = [];
$recentDistributions = [];

if ($db) {
    $currentYear = (int) date('Y');

    $queries = [
        'active_pos' => "
            SELECT COUNT(*) AS total
            FROM purchase_orders
            WHERE status != 'cancelled'
        ",
        'pending_receivings' => "
            SELECT COUNT(*) AS total
            FROM (
                SELECT po.id
                FROM purchase_orders po
                LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
                LEFT JOIN receiving_items ri ON ri.purchase_order_item_id = poi.id
                LEFT JOIN receivings r ON r.id = ri.receiving_id AND r.status != 'cancelled'
                WHERE po.status != 'cancelled'
                GROUP BY po.id
                HAVING COALESCE(SUM(poi.quantity), 0) > COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN ri.quantity_delivered ELSE 0 END), 0)
                    OR SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) > 0
            ) pending_receiving_rows
        ",
        'distributed_items' => "
            SELECT COUNT(*) AS total
            FROM distribution_item_details
            WHERE is_distributed = 1
              AND is_disposed = 0
        ",
        'disposed_this_year' => "
            SELECT COUNT(*) AS total
            FROM disposals
            WHERE status = 'posted'
              AND YEAR(disposal_date) = ?
        ",
        'returned_this_year' => "
            SELECT COUNT(*) AS total
            FROM returns
            WHERE status = 'posted'
              AND YEAR(return_date) = ?
        ",
    ];

    foreach ($queries as $key => $sql) {
        $stmt = $db->prepare($sql);
        if ($stmt) {
            if (in_array($key, ['disposed_this_year', 'returned_this_year'], true)) {
                $stmt->bind_param('i', $currentYear);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $summary[$key] = (int) ($row['total'] ?? 0);
            $stmt->close();
        }
    }

    $poStmt = $db->prepare("
        SELECT
            po.po_number,
            po.po_date,
            po.status,
            s.supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        ORDER BY po.po_date DESC, po.id DESC
        LIMIT 5
    ");
    if ($poStmt) {
        $poStmt->execute();
        $recentPurchaseOrders = $poStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $poStmt->close();
    }

    $distributionStmt = $db->prepare("
        SELECT
            d.document_no,
            d.document_type,
            d.distribution_date,
            o.office_name,
            e.employee_no,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name
        FROM distributions d
        LEFT JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        ORDER BY d.distribution_date DESC, d.id DESC
        LIMIT 5
    ");
    if ($distributionStmt) {
        $distributionStmt->execute();
        $recentDistributions = $distributionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $distributionStmt->close();
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="row g-3">
            <div class="col-md-6 col-xl-2-4 col-xxl">
                <div class="card info-card h-100">
                    <div class="card-body">
                        <h5 class="card-title">Active POs</h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle text-primary">
                                <i class="bi bi-journal-text"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?php echo h((string) $summary['active_pos']); ?></h6>
                                <span class="text-muted small">Status not cancelled</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-2-4 col-xxl">
                <div class="card info-card h-100">
                    <div class="card-body">
                        <h5 class="card-title">Pending Receivings</h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-warning-subtle text-warning">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?php echo h((string) $summary['pending_receivings']); ?></h6>
                                <span class="text-muted small">Not fully received</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-2-4 col-xxl">
                <div class="card info-card h-100">
                    <div class="card-body">
                        <h5 class="card-title">Distributed Items</h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-success-subtle text-success">
                                <i class="bi bi-diagram-3"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?php echo h((string) $summary['distributed_items']); ?></h6>
                                <span class="text-muted small">Active distributed units</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-2-4 col-xxl">
                <div class="card info-card h-100">
                    <div class="card-body">
                        <h5 class="card-title">Disposed This Year</h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-danger-subtle text-danger">
                                <i class="bi bi-trash3"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?php echo h((string) $summary['disposed_this_year']); ?></h6>
                                <span class="text-muted small"><?php echo h((string) date('Y')); ?> posted disposals</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-2-4 col-xxl">
                <div class="card info-card h-100">
                    <div class="card-body">
                        <h5 class="card-title">Returned This Year</h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-info-subtle text-info">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?php echo h((string) $summary['returned_this_year']); ?></h6>
                                <span class="text-muted small"><?php echo h((string) date('Y')); ?> posted returns</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card recent-sales overflow-auto">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Recent Purchase Orders</h5>
                    <span class="badge text-bg-light"><?php echo count($recentPurchaseOrders); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-borderless datatable align-middle">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentPurchaseOrders): ?>
                                <?php foreach ($recentPurchaseOrders as $po): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($po['po_number'] ?? ''); ?></td>
                                        <td><?php echo h($po['supplier_name'] ?? ''); ?></td>
                                        <td><?php echo !empty($po['po_date']) ? h(date('M d, Y', strtotime($po['po_date']))) : ''; ?></td>
                                        <td>
                                            <span class="badge <?php echo ($po['status'] ?? '') === 'cancelled' ? 'text-bg-secondary' : (($po['status'] ?? '') === 'completed' ? 'text-bg-success' : 'text-bg-warning'); ?>">
                                                <?php echo h(ucfirst((string) ($po['status'] ?? ''))); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No purchase orders found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card recent-sales overflow-auto">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Recent Distributions</h5>
                    <span class="badge text-bg-light"><?php echo count($recentDistributions); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-borderless datatable align-middle">
                        <thead>
                            <tr>
                                <th>Doc No</th>
                                <th>Type</th>
                                <th>Office</th>
                                <th>Employee</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentDistributions): ?>
                                <?php foreach ($recentDistributions as $distribution): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($distribution['document_no'] ?? ''); ?></td>
                                        <td><span class="badge text-bg-light text-uppercase"><?php echo h((string) ($distribution['document_type'] ?? '')); ?></span></td>
                                        <td><?php echo h($distribution['office_name'] ?? ''); ?></td>
                                        <td>
                                            <?php echo !empty($distribution['employee_no']) ? h(employee_display_name($distribution) . ' - ' . $distribution['employee_no']) : '<span class="text-muted">Not specified</span>'; ?>
                                        </td>
                                        <td><?php echo !empty($distribution['distribution_date']) ? h(date('M d, Y', strtotime($distribution['distribution_date']))) : ''; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No distributions found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
