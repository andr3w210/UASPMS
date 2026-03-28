<?php
require_once __DIR__ . '/../app/config/init.php';
require_login();

$page_title = 'Dashboard';
$db = db_connect();
$summary = [
    'active_pos' => 0,
    'pending_receivings' => 0,
    'pending_distribution_units' => 0,
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
        'pending_distribution_units' => "
            SELECT COUNT(*) AS total
            FROM receiving_item_details rid
            INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
            INNER JOIN receivings r ON r.id = ri.receiving_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            WHERE r.status != 'cancelled'
              AND poi.item_type IN ('semi_expendable', 'equipment')
              AND rid.is_distributed = 0
              AND COALESCE(rid.is_disposed, 0) = 0
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

$dashboardStats = [
    [
        'label' => 'Active POs',
        'value' => $summary['active_pos'],
        'note' => 'Status not cancelled',
        'icon' => 'bi-journal-text',
        'tone' => 'primary',
    ],
    [
        'label' => 'Pending Receivings',
        'value' => $summary['pending_receivings'],
        'note' => 'Not fully received',
        'icon' => 'bi-box-seam',
        'tone' => 'warning',
    ],
    [
        'label' => 'Pending Distribution',
        'value' => $summary['pending_distribution_units'],
        'note' => 'Received units awaiting posting',
        'icon' => 'bi-hourglass-split',
        'tone' => 'warning',
    ],
    [
        'label' => 'Distributed Items',
        'value' => $summary['distributed_items'],
        'note' => 'Active distributed units',
        'icon' => 'bi-diagram-3',
        'tone' => 'success',
    ],
    [
        'label' => 'Disposed This Year',
        'value' => $summary['disposed_this_year'],
        'note' => date('Y') . ' posted disposals',
        'icon' => 'bi-trash3',
        'tone' => 'danger',
    ],
    [
        'label' => 'Returned This Year',
        'value' => $summary['returned_this_year'],
        'note' => date('Y') . ' posted returns',
        'icon' => 'bi-arrow-counterclockwise',
        'tone' => 'info',
    ],
];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="dashboard-hero card">
            <div class="card-body p-4 p-xl-5">
                <div class="row g-4 align-items-center">
                    <div class="col-xl-7">
                        <div class="dashboard-hero-eyebrow">Operations Overview</div>
                        <h2 class="dashboard-hero-title">Manage procurement, receiving, accountability, and asset flow from one cleaner workspace.</h2>
                        <p class="dashboard-hero-text">
                            Use the dashboard as a working overview for the queues that need action now, then jump directly into receiving and distribution without digging through modules.
                        </p>
                        <div class="dashboard-hero-actions d-flex flex-wrap gap-2">
                            <a class="btn btn-primary" href="<?php echo base_url('modules/distributions/index.php'); ?>">Open Distribution</a>
                            <a class="btn btn-outline-primary" href="<?php echo base_url('modules/receivings/index.php'); ?>">Open Receiving</a>
                            <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/property/index.php'); ?>">Open Asset Registry</a>
                        </div>
                        <div class="dashboard-hero-meta">
                            <div class="dashboard-hero-meta-item">
                                <span class="dashboard-hero-meta-label">Pending distribution</span>
                                <strong><?php echo h((string) $summary['pending_distribution_units']); ?></strong>
                            </div>
                            <div class="dashboard-hero-meta-item">
                                <span class="dashboard-hero-meta-label">Pending receivings</span>
                                <strong><?php echo h((string) $summary['pending_receivings']); ?></strong>
                            </div>
                            <div class="dashboard-hero-meta-item">
                                <span class="dashboard-hero-meta-label">Active assets</span>
                                <strong><?php echo h((string) $summary['distributed_items']); ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="dashboard-priority-card">
                            <div class="dashboard-priority-title">Priority Queue</div>
                            <div class="dashboard-priority-copy">Focus here first to keep receiving and accountability work moving.</div>
                            <div class="dashboard-priority-item">
                                <span>Pending distribution</span>
                                <strong><?php echo h((string) $summary['pending_distribution_units']); ?></strong>
                            </div>
                            <div class="dashboard-priority-item">
                                <span>Pending receivings</span>
                                <strong><?php echo h((string) $summary['pending_receivings']); ?></strong>
                            </div>
                            <div class="dashboard-priority-item">
                                <span>Distributed items</span>
                                <strong><?php echo h((string) $summary['distributed_items']); ?></strong>
                            </div>
                            <div class="dashboard-priority-item">
                                <span>Disposed this year</span>
                                <strong><?php echo h((string) $summary['disposed_this_year']); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="row g-3">
            <?php foreach ($dashboardStats as $stat): ?>
                <div class="col-md-6 col-xl-4 col-xxl-2">
                    <div class="dashboard-stat-card h-100">
                        <div class="dashboard-stat-icon bg-<?php echo h($stat['tone']); ?>-subtle text-<?php echo h($stat['tone']); ?>">
                            <i class="bi <?php echo h($stat['icon']); ?>"></i>
                        </div>
                        <div class="dashboard-stat-content">
                            <div class="dashboard-stat-label"><?php echo h($stat['label']); ?></div>
                            <div class="dashboard-stat-value"><?php echo h((string) $stat['value']); ?></div>
                            <div class="dashboard-stat-note"><?php echo h($stat['note']); ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card dashboard-panel overflow-auto">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Recent Purchase Orders</h5>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/purchase_orders/index.php'); ?>">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-borderless align-middle dashboard-table">
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
        <div class="card dashboard-panel overflow-auto">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Recent Distributions</h5>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/distributions/index.php'); ?>">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-borderless align-middle dashboard-table">
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
