<?php
require_once __DIR__ . '/../app/config/init.php';
require_login();

$page_title = 'Dashboard';
$db = db();
$displayName = trim((string) ($_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'));
$roleName = trim((string) ($_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'User'));
$isAdministrator = $roleName === 'Administrator';
$summary = [
    'active_pos' => 0,
    'pending_receivings' => 0,
    'pending_distribution_units' => 0,
    'distributed_items' => 0,
    'disposed_this_year' => 0,
    'returned_this_year' => 0,
    'open_inventory_counts' => 0,
    'unresolved_property_discrepancies' => 0,
    'open_supply_counts' => 0,
    'pending_stock_adjustments' => 0,
    'unserviceable_review_items' => 0,
];
$recentPurchaseOrders = [];
$recentDistributions = [];
$lowStockItems = [];
$lowStockThreshold = defined('LOW_STOCK_THRESHOLD') ? max(0, (int) LOW_STOCK_THRESHOLD) : 5;

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
        'open_inventory_counts' => "
            SELECT COUNT(*) AS total
            FROM inventory_count_sessions
            WHERE status = 'open'
        ",
        'unresolved_property_discrepancies' => "
            SELECT COUNT(*) AS total
            FROM inventory_count_items
            WHERE status IN ('missing', 'for_repair', 'for_disposal', 'wrong_office', 'wrong_accountable')
              AND resolution_status = 'unresolved'
        ",
        'open_supply_counts' => "
            SELECT COUNT(*) AS total
            FROM supply_count_sessions
            WHERE status = 'open'
        ",
        'pending_stock_adjustments' => "
            SELECT COUNT(*) AS total
            FROM stock_adjustments
            WHERE status = 'pending'
        ",
        'unserviceable_review_items' => "
            SELECT COUNT(*) AS total
            FROM inventory_count_items
            WHERE status IN ('for_repair', 'for_disposal')
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

    $lowStockStmt = $db->prepare("
        SELECT
            COALESCE(sc.stock_no, si.system_reference) AS stock_no,
            COALESCE(sc.item_name, si.item_description) AS item_name,
            COALESCE(c.classification_name, '') AS classification_name,
            SUM(si.quantity_on_hand) AS quantity_on_hand
        FROM stock_items si
        LEFT JOIN stock_catalog sc ON sc.id = si.stock_catalog_id
        LEFT JOIN classifications c ON c.id = COALESCE(sc.classification_id, si.classification_id)
        WHERE si.item_type = 'supply'
        GROUP BY
            COALESCE(sc.id, 0),
            COALESCE(sc.stock_no, si.system_reference),
            COALESCE(sc.item_name, si.item_description),
            COALESCE(c.classification_name, '')
        HAVING SUM(si.quantity_on_hand) <= ?
        ORDER BY SUM(si.quantity_on_hand) ASC, COALESCE(sc.item_name, si.item_description) ASC
        LIMIT 5
    ");
    if ($lowStockStmt) {
        $lowStockStmt->bind_param('i', $lowStockThreshold);
        $lowStockStmt->execute();
        $lowStockItems = $lowStockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lowStockStmt->close();
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
    [
        'label' => 'Open Inventory Counts',
        'value' => $summary['open_inventory_counts'],
        'note' => 'Property count sessions still in progress',
        'icon' => 'bi-clipboard-check',
        'tone' => 'info',
        'href' => base_url('modules/property/inventory_counts.php'),
        'cta' => 'Open Counts',
    ],
    [
        'label' => 'Pending Stock Adjustments',
        'value' => $summary['pending_stock_adjustments'],
        'note' => 'Supply adjustments waiting for approval',
        'icon' => 'bi-sliders2-vertical',
        'tone' => 'danger',
        'href' => base_url('modules/property/stock_adjustments.php'),
        'cta' => 'Review Adjustments',
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
    [
        'label' => 'Property Discrepancies',
        'value' => $summary['unresolved_property_discrepancies'],
        'note' => 'Unresolved count exceptions',
        'icon' => 'bi-exclamation-diamond',
        'tone' => 'warning',
    ],
    [
        'label' => 'Supply Count Sessions',
        'value' => $summary['open_supply_counts'],
        'note' => 'Open supply count workspaces',
        'icon' => 'bi-boxes',
        'tone' => 'secondary',
    ],
    [
        'label' => 'Unserviceable Review',
        'value' => $summary['unserviceable_review_items'],
        'note' => 'Assets flagged for repair/disposal',
        'icon' => 'bi-tools',
        'tone' => 'dark',
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
                <div class="dashboard-command-eyebrow"><?php echo h($displayName . ' · ' . $roleName); ?></div>
                <h2 class="dashboard-command-title">Operations Dashboard</h2>
                <p class="dashboard-command-text">
                    Use this page as your working control center for procurement, receiving, accountability, and asset movement.
                </p>
                <div class="dashboard-command-actions d-flex flex-wrap gap-2">
                    <a class="btn btn-primary" href="<?php echo base_url('modules/distributions/index.php'); ?>">Open Distribution</a>
                    <a class="btn btn-outline-primary" href="<?php echo base_url('modules/receivings/index.php'); ?>">Open Receiving</a>
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/property/index.php'); ?>">Asset Registry</a>
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/property/inventory_counts.php'); ?>">Inventory Counts</a>
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/property/stock_adjustments.php'); ?>">Stock Adjustments</a>
                    <?php if ($isAdministrator): ?>
                        <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/audit_log/index.php'); ?>">Audit Log</a>
                    <?php endif; ?>
                </div>
                <div class="dashboard-command-points">
                    <div class="dashboard-command-point">
                        <span class="dashboard-command-point-label">Main goal</span>
                        <strong>Clear the pending queues first</strong>
                    </div>
                    <div class="dashboard-command-point">
                        <span class="dashboard-command-point-label">Best next check</span>
                        <strong>Review counts, discrepancies, and pending approvals</strong>
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
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/property/inventory_reconciliation.php'); ?>">Open Control Queue</a>
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

    <?php if ($lowStockItems): ?>
        <div class="col-12">
            <div class="card border-warning shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                        <div>
                            <div class="text-uppercase small fw-semibold text-warning-emphasis">Stock Alert</div>
                            <h5 class="card-title mb-1">Low Stock Supplies</h5>
                            <div class="text-muted">
                                The following supply items are at or below the low stock threshold of <?php echo h((string) $lowStockThreshold); ?>.
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-warning" href="<?php echo base_url('modules/stock_catalog/index.php'); ?>">
                            Open Stock Catalog
                        </a>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($lowStockItems as $item): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="border rounded-3 p-3 bg-warning-subtle h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="fw-semibold"><?php echo h((string) ($item['item_name'] ?? 'Supply Item')); ?></div>
                                            <div class="small text-muted">
                                                <?php echo h((string) ($item['stock_no'] ?? '')); ?>
                                                <?php if (!empty($item['classification_name'])): ?>
                                                    · <?php echo h((string) $item['classification_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="small text-muted">On Hand</div>
                                            <div class="fs-5 fw-semibold text-warning-emphasis">
                                                <?php echo h(format_quantity($item['quantity_on_hand'] ?? 0)); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($summary['unresolved_property_discrepancies'] > 0 || $summary['pending_stock_adjustments'] > 0 || $summary['unserviceable_review_items'] > 0): ?>
        <div class="col-12">
            <div class="card border-danger-subtle shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                        <div>
                            <div class="text-uppercase small fw-semibold text-danger-emphasis">Control Alerts</div>
                            <h5 class="card-title mb-1">Inventory Follow-up Needs Action</h5>
                            <div class="text-muted">
                                These control items need review so counts, discrepancies, and adjustments are fully closed.
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-danger" href="<?php echo base_url('modules/property/inventory_reconciliation.php'); ?>">
                            Open Reconciliation
                        </a>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <a class="text-decoration-none" href="<?php echo base_url('modules/property/inventory_reconciliation.php?resolution=unresolved'); ?>">
                                <div class="border rounded-3 p-3 bg-danger-subtle h-100">
                                    <div class="small text-muted">Unresolved Property Discrepancies</div>
                                    <div class="fs-4 fw-semibold text-danger-emphasis"><?php echo number_format($summary['unresolved_property_discrepancies']); ?></div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a class="text-decoration-none" href="<?php echo base_url('modules/property/stock_adjustments.php'); ?>">
                                <div class="border rounded-3 p-3 bg-warning-subtle h-100">
                                    <div class="small text-muted">Pending Stock Adjustments</div>
                                    <div class="fs-4 fw-semibold text-warning-emphasis"><?php echo number_format($summary['pending_stock_adjustments']); ?></div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a class="text-decoration-none" href="<?php echo base_url('modules/property/unserviceable_review.php'); ?>">
                                <div class="border rounded-3 p-3 bg-secondary-subtle h-100">
                                    <div class="small text-muted">Repair / Disposal Review Items</div>
                                    <div class="fs-4 fw-semibold text-dark"><?php echo number_format($summary['unserviceable_review_items']); ?></div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

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
