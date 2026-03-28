<?php
require_once __DIR__ . '/../app/config/init.php';
require_login();

$page_title = 'Dashboard';
$db = db();
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
        SELECT po.po_number, po.po_date, po.status, s.supplier_name
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
        SELECT d.document_no, d.document_type, d.distribution_date,
               o.office_name,
               e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name
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

$focusItems = [
    [
        'label' => 'Pending Distribution',
        'value' => $summary['pending_distribution_units'],
        'note' => 'Units waiting for ICS/PAR posting',
        'icon' => 'bi-hourglass-split',
        'tone' => 'warning',
        'href' => base_url('modules/distributions/index.php'),
        'cta' => 'Review Queue',
    ],
    [
        'label' => 'Pending Receivings',
        'value' => $summary['pending_receivings'],
        'note' => 'POs still waiting for complete receiving',
        'icon' => 'bi-box-seam',
        'tone' => 'warning',
        'href' => base_url('modules/receivings/index.php'),
        'cta' => 'Open Receiving',
    ],
    [
        'label' => 'Active Assets',
        'value' => $summary['distributed_items'],
        'note' => 'Equipment and semi assets in circulation',
        'icon' => 'bi-diagram-3',
        'tone' => 'success',
        'href' => base_url('modules/property/index.php'),
        'cta' => 'Open Registry',
    ],
];

$snapshotItems = [
    [
        'label' => 'Active POs',
        'value' => $summary['active_pos'],
        'note' => 'Open procurement records',
        'icon' => 'bi-journal-text',
        'tone' => 'primary',
    ],
    [
        'label' => 'Distributed Items',
        'value' => $summary['distributed_items'],
        'note' => 'Current accountable units',
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
    <div class="col-12 col-xl-7">
        <div class="dashboard-command card h-100">
            <div class="card-body p-4">
                <div class="dashboard-command-eyebrow">System Administrator</div>
                <h2 class="dashboard-command-title">Operations Dashboard</h2>
                <p class="dashboard-command-text">
                    Use this page as your working control center for procurement, receiving, accountability, and asset movement.
                </p>
                <div class="dashboard-command-actions d-flex flex-wrap gap-2">
                    <a class="btn btn-primary" href="<?php echo base_url('modules/distributions/index.php'); ?>">Open Distribution</a>
                    <a class="btn btn-outline-primary" href="<?php echo base_url('modules/receivings/index.php'); ?>">Open Receiving</a>
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/property/index.php'); ?>">Asset Registry</a>
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/audit_log/index.php'); ?>">Audit Log</a>
                </div>
                <div class="dashboard-command-points">
                    <div class="dashboard-command-point">
                        <span class="dashboard-command-point-label">Main goal</span>
                        <strong>Clear the pending queues first</strong>
                    </div>
                    <div class="dashboard-command-point">
                        <span class="dashboard-command-point-label">Best next check</span>
                        <strong>Review receiving and distribution gaps</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="dashboard-queue card h-100">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <div class="dashboard-queue-title">Urgent Queue</div>
                        <div class="dashboard-queue-copy">Open the items that need attention now.</div>
                    </div>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/distributions/index.php'); ?>">Open Queue</a>
                </div>
                <div class="dashboard-focus-list">
                    <?php foreach ($focusItems as $item): ?>
                        <a class="dashboard-focus-row tone-<?php echo h($item['tone']); ?>" href="<?php echo h($item['href']); ?>">
                            <span class="dashboard-focus-row-icon">
                                <i class="bi <?php echo h($item['icon']); ?>"></i>
                            </span>
                            <span class="dashboard-focus-row-body">
                                <span class="dashboard-focus-row-label"><?php echo h($item['label']); ?></span>
                                <span class="dashboard-focus-row-note"><?php echo h($item['note']); ?></span>
                            </span>
                            <span class="dashboard-focus-row-value"><?php echo h((string) $item['value']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="row g-3">
            <?php foreach ($snapshotItems as $item): ?>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-snapshot-card h-100">
                        <div class="dashboard-snapshot-icon bg-<?php echo h($item['tone']); ?>-subtle text-<?php echo h($item['tone']); ?>">
                            <i class="bi <?php echo h($item['icon']); ?>"></i>
                        </div>
                        <div class="dashboard-snapshot-content">
                            <div class="dashboard-snapshot-label"><?php echo h($item['label']); ?></div>
                            <div class="dashboard-snapshot-value"><?php echo h((string) $item['value']); ?></div>
                            <div class="dashboard-snapshot-note"><?php echo h($item['note']); ?></div>
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
