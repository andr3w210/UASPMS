<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer');

$page_title = 'Supplier Delivery Calendar';
$db = db();
$errors = [];
$purchaseOrders = [];
$extensionsByPo = [];
$performance = [];

$today = new DateTimeImmutable('today');
$requestedMonth = (string) ($_GET['month'] ?? $today->format('Y-m'));
$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $requestedMonth);
if (!$monthDate || $monthDate->format('Y-m') !== $requestedMonth) {
    $monthDate = new DateTimeImmutable($today->format('Y-m') . '-01');
}
$monthStart = $monthDate->modify('first day of this month');
$monthEnd = $monthDate->modify('last day of this month');
$calendarStart = $monthStart->modify('monday this week');
$calendarEnd = $monthEnd->modify('sunday this week');

$rangeStart = (string) ($_GET['from'] ?? $today->format('Y-01-01'));
$rangeEnd = (string) ($_GET['to'] ?? $today->format('Y-12-31'));
foreach (['rangeStart' => $rangeStart, 'rangeEnd' => $rangeEnd] as $name => $value) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        ${$name} = $name === 'rangeStart' ? $today->format('Y-01-01') : $today->format('Y-12-31');
    }
}
if ($rangeStart > $rangeEnd) {
    [$rangeStart, $rangeEnd] = [$rangeEnd, $rangeStart];
}

$formatDate = static function (?string $value): string {
    return $value ? date('M d, Y', strtotime($value)) : 'Not set';
};
$calendarDays = [];
$cursor = $calendarStart;
while ($cursor <= $calendarEnd) {
    $calendarDays[$cursor->format('Y-m-d')] = [];
    $cursor = $cursor->modify('+1 day');
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $poStmt = $db->prepare(
        "SELECT po.id, po.po_number, po.system_reference, po.po_date, po.expected_delivery_date, po.status,
                s.supplier_name, s.contact_person, s.contact_no,
                EXISTS (
                    SELECT 1
                    FROM purchase_order_items poi
                    INNER JOIN receiving_items ri ON ri.purchase_order_item_id = poi.id
                    INNER JOIN receivings r ON r.id = ri.receiving_id
                    WHERE poi.purchase_order_id = po.id
                      AND r.status != 'cancelled'
                      AND COALESCE(ri.quantity_delivered, 0) > 0
                ) AS has_receiving,
                EXISTS (
                    SELECT 1
                    FROM purchase_order_delivery_extensions ext
                    WHERE ext.purchase_order_id = po.id
                      AND ext.status = 'posted'
                ) AS has_extension
         FROM purchase_orders po
         INNER JOIN suppliers s ON s.id = po.supplier_id
         WHERE po.expected_delivery_date BETWEEN ? AND ?
           AND po.status NOT IN ('cancelled', 'void')
         ORDER BY po.expected_delivery_date ASC, s.supplier_name ASC, po.po_number ASC"
    );
    if ($poStmt) {
        $calendarStartValue = $calendarStart->format('Y-m-d');
        $calendarEndValue = $calendarEnd->format('Y-m-d');
        $poStmt->bind_param('ss', $calendarStartValue, $calendarEndValue);
        $poStmt->execute();
        $purchaseOrders = $poStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $poStmt->close();
    } else {
        $errors[] = 'Unable to load purchase order delivery dates.';
    }

    $extensionStmt = $db->prepare(
        "SELECT ext.purchase_order_id, ext.old_expected_delivery_date, ext.new_expected_delivery_date,
                ext.reason, ext.remarks, ext.created_at, u.full_name AS posted_by
         FROM purchase_order_delivery_extensions ext
         LEFT JOIN users u ON u.id = ext.created_by
         WHERE ext.status = 'posted'
           AND ext.purchase_order_id IN (
               SELECT po.id
               FROM purchase_orders po
               WHERE po.expected_delivery_date BETWEEN ? AND ?
                 AND po.status NOT IN ('cancelled', 'void')
           )
         ORDER BY ext.purchase_order_id ASC, ext.created_at DESC, ext.id DESC"
    );
    if ($extensionStmt) {
        $extensionStmt->bind_param('ss', $calendarStartValue, $calendarEndValue);
        $extensionStmt->execute();
        foreach ($extensionStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $extension) {
            $extensionsByPo[(int) $extension['purchase_order_id']][] = $extension;
        }
        $extensionStmt->close();
    }

    $performanceStmt = $db->prepare(
        "SELECT s.supplier_name,
                COUNT(DISTINCT po.id) AS total_pos,
                COUNT(DISTINCT CASE WHEN receiving_summary.first_received_date <= po.expected_delivery_date THEN po.id END) AS on_time_pos,
                COUNT(DISTINCT CASE WHEN receiving_summary.first_received_date > po.expected_delivery_date THEN po.id END) AS late_pos
         FROM purchase_orders po
         INNER JOIN suppliers s ON s.id = po.supplier_id
         LEFT JOIN (
             SELECT poi.purchase_order_id, MIN(r.received_date) AS first_received_date
             FROM purchase_order_items poi
             INNER JOIN receiving_items ri ON ri.purchase_order_item_id = poi.id
             INNER JOIN receivings r ON r.id = ri.receiving_id
             WHERE r.status != 'cancelled'
               AND COALESCE(ri.quantity_delivered, 0) > 0
             GROUP BY poi.purchase_order_id
         ) receiving_summary ON receiving_summary.purchase_order_id = po.id
         WHERE po.po_date BETWEEN ? AND ?
           AND po.status NOT IN ('cancelled', 'void')
         GROUP BY s.id, s.supplier_name
         ORDER BY total_pos DESC, s.supplier_name ASC"
    );
    if ($performanceStmt) {
        $performanceStmt->bind_param('ss', $rangeStart, $rangeEnd);
        $performanceStmt->execute();
        $performance = $performanceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $performanceStmt->close();
    }
}

foreach ($purchaseOrders as &$purchaseOrder) {
    $expectedDate = (string) $purchaseOrder['expected_delivery_date'];
    $hasReceiving = (int) ($purchaseOrder['has_receiving'] ?? 0) === 1;
    if (!$hasReceiving && $expectedDate < $today->format('Y-m-d')) {
        $purchaseOrder['delivery_tone'] = 'danger';
        $purchaseOrder['delivery_label'] = 'Overdue';
    } elseif ($expectedDate <= $today->modify('+3 days')->format('Y-m-d')) {
        $purchaseOrder['delivery_tone'] = 'warning';
        $purchaseOrder['delivery_label'] = 'Due soon';
    } else {
        $purchaseOrder['delivery_tone'] = 'info';
        $purchaseOrder['delivery_label'] = 'Scheduled';
    }
    $calendarDays[$expectedDate][] = $purchaseOrder;
}
unset($purchaseOrder);

$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$calendarQuery = static function (string $month) use ($rangeStart, $rangeEnd): string {
    return base_url('modules/suppliers/monitoring_calendar.php?month=' . rawurlencode($month)
        . '&from=' . rawurlencode($rangeStart) . '&to=' . rawurlencode($rangeEnd));
};

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="supplier-monitoring-page">
    <div class="supplier-monitoring-header">
        <div>
            <div class="page-kicker">Procurement control</div>
            <h1 class="page-title mb-1">Supplier delivery monitoring</h1>
            <p class="text-muted mb-0">Track current PO deadlines, extension history, and supplier delivery performance.</p>
        </div>
        <div class="supplier-monitoring-actions">
            <a class="btn btn-outline-secondary" href="<?php echo h(base_url('modules/suppliers/index.php')); ?>"><i class="bi bi-truck me-1"></i>Supplier Directory</a>
            <a class="btn btn-primary" href="<?php echo h(base_url('modules/purchase_orders/extensions.php')); ?>"><i class="bi bi-calendar2-plus me-1"></i>Manage Extensions</a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="supplier-monitoring-layout">
        <article class="card supplier-calendar-card">
            <div class="card-body p-3 p-lg-4">
                <div class="supplier-calendar-toolbar">
                    <div>
                        <div class="text-muted small">Delivery deadlines</div>
                        <h2 class="h4 mb-0"><?php echo h($monthStart->format('F Y')); ?></h2>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($calendarQuery($previousMonth)); ?>" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo h($calendarQuery($today->format('Y-m'))); ?>">Today</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($calendarQuery($nextMonth)); ?>" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>
                <div class="supplier-calendar-legend" aria-label="Calendar legend">
                    <span><i class="supplier-calendar-dot tone-info"></i>Scheduled</span>
                    <span><i class="supplier-calendar-dot tone-warning"></i>Due soon</span>
                    <span><i class="supplier-calendar-dot tone-danger"></i>Overdue without receiving</span>
                    <span><i class="bi bi-calendar2-plus"></i>Extended</span>
                </div>
                <div class="supplier-calendar-weekdays" aria-hidden="true">
                    <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $weekday): ?><span><?php echo h($weekday); ?></span><?php endforeach; ?>
                </div>
                <div class="supplier-calendar-grid">
                    <?php foreach ($calendarDays as $day => $dayOrders): ?>
                        <?php $dayDate = new DateTimeImmutable($day); $outsideMonth = $dayDate->format('Y-m') !== $monthStart->format('Y-m'); ?>
                        <div class="supplier-calendar-day<?php echo $outsideMonth ? ' is-outside-month' : ''; ?><?php echo $day === $today->format('Y-m-d') ? ' is-today' : ''; ?>">
                            <div class="supplier-calendar-day-number"><?php echo h($dayDate->format('j')); ?></div>
                            <div class="supplier-calendar-day-entries">
                                <?php if ($dayOrders): ?>
                                    <?php foreach ($dayOrders as $order): ?>
                                        <?php $poId = (int) $order['id']; $detailsId = 'po-detail-' . $poId; ?>
                                        <button type="button" class="supplier-calendar-entry tone-<?php echo h($order['delivery_tone']); ?>" aria-expanded="false" aria-controls="<?php echo h($detailsId); ?>" data-calendar-entry="<?php echo h($detailsId); ?>">
                                            <span class="supplier-calendar-entry-title"><?php echo h((string) ($order['supplier_name'] ?? 'Supplier')); ?></span>
                                            <span class="supplier-calendar-entry-po"><?php echo h((string) ($order['po_number'] ?: $order['system_reference'])); ?></span>
                                            <?php if ((int) ($order['has_extension'] ?? 0) === 1): ?><span class="supplier-calendar-extended"><i class="bi bi-calendar2-plus"></i> Extended</span><?php endif; ?>
                                        </button>
                                        <div class="supplier-calendar-detail" id="<?php echo h($detailsId); ?>" hidden>
                                            <div class="supplier-calendar-detail-head"><strong><?php echo h((string) ($order['po_number'] ?: $order['system_reference'])); ?></strong><span class="badge tone-<?php echo h($order['delivery_tone']); ?>"><?php echo h($order['delivery_label']); ?></span></div>
                                            <dl>
                                                <dt>Supplier</dt><dd><?php echo h((string) $order['supplier_name']); ?></dd>
                                                <dt>PO date</dt><dd><?php echo h($formatDate($order['po_date'])); ?></dd>
                                                <dt>Expected delivery</dt><dd><?php echo h($formatDate($order['expected_delivery_date'])); ?></dd>
                                            </dl>
                                            <?php if (!empty($extensionsByPo[$poId])): ?>
                                                <div class="supplier-calendar-history-title">Posted extension history</div>
                                                <div class="supplier-calendar-history">
                                                    <?php foreach ($extensionsByPo[$poId] as $extension): ?>
                                                        <div class="supplier-calendar-history-row">
                                                            <div><strong><?php echo h($formatDate($extension['old_expected_delivery_date'])); ?> → <?php echo h($formatDate($extension['new_expected_delivery_date'])); ?></strong><div><?php echo h((string) ($extension['reason'] ?? 'No reason provided')); ?></div></div>
                                                            <small><?php echo h((string) ($extension['posted_by'] ?: 'System')); ?> · <?php echo h($formatDate(substr((string) $extension['created_at'], 0, 10))); ?></small>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="supplier-calendar-empty-day">No deadlines</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>

        <aside class="card supplier-performance-card">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-3"><div class="text-muted small">Delivery performance</div><h2 class="h5 mb-1">Supplier scorecard</h2><p class="small text-muted mb-0">Receiving date compared with the current PO deadline.</p></div>
                <form class="supplier-performance-filter" method="get">
                    <input type="hidden" name="month" value="<?php echo h($monthStart->format('Y-m')); ?>">
                    <div><label class="form-label small" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?php echo h($rangeStart); ?>"></div>
                    <div><label class="form-label small" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?php echo h($rangeEnd); ?>"></div>
                    <button class="btn btn-sm btn-primary" type="submit">Apply range</button>
                </form>
                <?php if ($performance): ?>
                    <div class="supplier-performance-list">
                        <?php foreach ($performance as $row): ?>
                            <?php $total = max(1, (int) $row['total_pos']); $onTime = (int) $row['on_time_pos']; $late = (int) $row['late_pos']; $unknown = max(0, (int) $row['total_pos'] - $onTime - $late); $onTimePercent = (int) round(($onTime / $total) * 100); ?>
                            <div class="supplier-performance-row">
                                <div class="d-flex justify-content-between gap-2"><strong><?php echo h((string) $row['supplier_name']); ?></strong><span class="small text-muted"><?php echo number_format((int) $row['total_pos']); ?> POs</span></div>
                                <div class="supplier-performance-track"><span class="supplier-performance-on-time" style="width: <?php echo $onTimePercent; ?>%"></span></div>
                                <div class="supplier-performance-meta"><span class="text-success">On time <?php echo number_format($onTime); ?></span><span class="text-danger">Late <?php echo number_format($late); ?></span><?php if ($unknown > 0): ?><span class="text-muted">No receiving <?php echo number_format($unknown); ?></span><?php endif; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?><div class="supplier-monitoring-empty">No supplier PO performance data for this date range.</div><?php endif; ?>
            </div>
        </aside>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-calendar-entry]').forEach(function (entry) {
        entry.addEventListener('click', function () {
            var detail = document.getElementById(entry.getAttribute('data-calendar-entry'));
            if (!detail) return;
            var isOpen = !detail.hidden;
            detail.hidden = isOpen;
            entry.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
