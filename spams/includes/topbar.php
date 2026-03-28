<?php
$displayName = $_SESSION['user_name'] ?? 'User';
$roleName = $_SESSION['role_name'] ?? 'Administrator';
$userRole = $_SESSION['user_role'] ?? 'User';
$notificationDb = (isset($db) && $db instanceof mysqli) ? $db : db();
$pendingDistributionUnits = 0;
$pendingDistributionRecords = 0;
$pendingReceivingCount = 0;
$deliveryDueSoonCount = 0;
$deliveryOverdueCount = 0;
$repeatExtensionCount = 0;
$lowStockItemCount = 0;
$unreadMessageCount = 0;
if ($notificationDb) {
    if (current_user_id()) {
        $currentTopbarUserId = (int) current_user_id();
        $unreadMessageStmt = $notificationDb->prepare("
            SELECT COUNT(*) AS total
            FROM user_messages
            WHERE recipient_user_id = ?
              AND is_read = 0
        ");
        if ($unreadMessageStmt) {
            $unreadMessageStmt->bind_param('i', $currentTopbarUserId);
            $unreadMessageStmt->execute();
            $unreadMessageCount = (int) (($unreadMessageStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $unreadMessageStmt->close();
        }

        $unreadMessageCount += message_channel_unread_count($notificationDb, 'general', $currentTopbarUserId);
    }

    $pendingUnitsStmt = $notificationDb->prepare(
        "SELECT COUNT(*) AS total
         FROM receiving_item_details rid
         INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
         INNER JOIN receivings r ON r.id = ri.receiving_id
         INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
         WHERE r.status != 'cancelled'
           AND poi.item_type IN ('semi_expendable', 'equipment')
           AND rid.is_distributed = 0
           AND COALESCE(rid.is_disposed, 0) = 0"
    );
    if ($pendingUnitsStmt) {
        $pendingUnitsStmt->execute();
        $pendingDistributionUnits = (int) (($pendingUnitsStmt->get_result()->fetch_assoc()['total'] ?? 0));
        $pendingUnitsStmt->close();
    }

    $pendingRecordsStmt = $notificationDb->prepare(
        "SELECT COUNT(DISTINCT r.id) AS total
         FROM receiving_item_details rid
         INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
         INNER JOIN receivings r ON r.id = ri.receiving_id
         INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
         WHERE r.status != 'cancelled'
           AND poi.item_type IN ('semi_expendable', 'equipment')
           AND rid.is_distributed = 0
           AND COALESCE(rid.is_disposed, 0) = 0"
    );
    if ($pendingRecordsStmt) {
        $pendingRecordsStmt->execute();
        $pendingDistributionRecords = (int) (($pendingRecordsStmt->get_result()->fetch_assoc()['total'] ?? 0));
        $pendingRecordsStmt->close();
    }

    $pendingReceivingStmt = $notificationDb->prepare(
        "SELECT COUNT(*) AS total
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
         ) pending_receiving_rows"
    );
    if ($pendingReceivingStmt) {
        $pendingReceivingStmt->execute();
        $pendingReceivingCount = (int) (($pendingReceivingStmt->get_result()->fetch_assoc()['total'] ?? 0));
        $pendingReceivingStmt->close();
    }

    $dueSoonStmt = $notificationDb->prepare(
        "SELECT
            SUM(CASE WHEN expected_delivery_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count,
            SUM(CASE WHEN expected_delivery_date >= CURDATE() AND expected_delivery_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) AS due_soon_count
         FROM purchase_orders
         WHERE status NOT IN ('completed', 'cancelled')
           AND expected_delivery_date IS NOT NULL"
    );
    if ($dueSoonStmt) {
        $dueSoonStmt->execute();
        $dueSoonRow = $dueSoonStmt->get_result()->fetch_assoc();
        $deliveryOverdueCount = (int) ($dueSoonRow['overdue_count'] ?? 0);
        $deliveryDueSoonCount = (int) ($dueSoonRow['due_soon_count'] ?? 0);
        $dueSoonStmt->close();
    }

    $repeatExtensionStmt = $notificationDb->prepare(
        "SELECT COUNT(*) AS total
         FROM (
             SELECT purchase_order_id
             FROM purchase_order_delivery_extensions
             WHERE status = 'posted'
             GROUP BY purchase_order_id
             HAVING COUNT(*) >= 2
         ) repeated_extension_rows"
    );
    if ($repeatExtensionStmt) {
        $repeatExtensionStmt->execute();
        $repeatExtensionCount = (int) (($repeatExtensionStmt->get_result()->fetch_assoc()['total'] ?? 0));
        $repeatExtensionStmt->close();
    }

    $lowStockStmt = $notificationDb->prepare(
        "SELECT COUNT(*) AS total
         FROM (
             SELECT si.stock_catalog_id
             FROM stock_items si
             WHERE si.item_type = 'supply'
             GROUP BY si.stock_catalog_id
             HAVING COALESCE(SUM(si.quantity_on_hand), 0) <= 5
         ) low_stock_rows"
    );
    if ($lowStockStmt) {
        $lowStockStmt->execute();
        $lowStockItemCount = (int) (($lowStockStmt->get_result()->fetch_assoc()['total'] ?? 0));
        $lowStockStmt->close();
    }
}
$notificationBadgeCount =
    $pendingDistributionUnits +
    $pendingReceivingCount +
    $deliveryDueSoonCount +
    $deliveryOverdueCount +
    $repeatExtensionCount +
    $lowStockItemCount;
$hasNotifications = $notificationBadgeCount > 0;
?>
<header id="header" class="header fixed-top d-flex align-items-center">
    <div class="d-flex align-items-center justify-content-between w-100">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-link text-decoration-none p-0 toggle-sidebar-btn" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="bi bi-list fs-3"></i>
            </button>
            <a href="<?php echo base_url('dashboard/index.php'); ?>" class="logo d-flex align-items-center text-decoration-none">
                <i class="bi bi-box-seam me-2"></i>
                <span>SPAMS</span>
            </a>
        </div>

        <div class="d-flex align-items-center gap-3">
            <a class="btn btn-outline-secondary btn-sm position-relative" href="<?php echo base_url('modules/messages/index.php'); ?>" title="Messages" id="topbarMessageLink">
                <i class="bi bi-chat-dots"></i>
                <span id="topbarMessageBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-primary <?php echo $unreadMessageCount > 0 ? '' : 'd-none'; ?>">
                    <?php echo h((string) $unreadMessageCount); ?>
                </span>
            </a>
            <?php if ($hasNotifications): ?>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-warning">
                            <?php echo h((string) $notificationBadgeCount); ?>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-0" style="min-width: 320px;">
                        <div class="p-3 border-bottom">
                            <div class="fw-semibold">Notifications</div>
                            <div class="small text-muted">Action items that need follow-up across purchasing, receiving, and distribution.</div>
                        </div>
                        <div class="p-3">
                            <?php $hasPreviousNotification = false; ?>

                            <?php if ($deliveryOverdueCount > 0 || $deliveryDueSoonCount > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-calendar2-event"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Delivery End Dates</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $deliveryOverdueCount); ?> overdue PO(s) and
                                            <?php echo h((string) $deliveryDueSoonCount); ?> PO(s) due within 3 day(s).
                                        </div>
                                        <a class="btn btn-sm btn-outline-danger mt-2" href="<?php echo base_url('modules/purchase_orders/extensions.php'); ?>">
                                            Manage Extensions
                                        </a>
                                    </div>
                                </div>
                                <?php $hasPreviousNotification = true; ?>
                            <?php endif; ?>

                            <?php if ($repeatExtensionCount > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-info-subtle text-info d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Repeated Delivery Extensions</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $repeatExtensionCount); ?> purchase order(s) have already been extended two or more times.
                                        </div>
                                        <a class="btn btn-sm btn-outline-info mt-2" href="<?php echo base_url('modules/purchase_orders/extensions.php'); ?>">
                                            Review Extension History
                                        </a>
                                    </div>
                                </div>
                                <?php $hasPreviousNotification = true; ?>
                            <?php endif; ?>

                            <?php if ($pendingReceivingCount > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-box-seam"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Pending Receivings</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $pendingReceivingCount); ?> purchase order(s) are still not fully received or have pending receiving activity.
                                        </div>
                                        <a class="btn btn-sm btn-outline-primary mt-2" href="<?php echo base_url('modules/receivings/index.php'); ?>">
                                            Open Receiving
                                        </a>
                                    </div>
                                </div>
                                <?php $hasPreviousNotification = true; ?>
                            <?php endif; ?>

                            <?php if ($pendingDistributionUnits > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-diagram-3"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Pending Distribution Queue</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $pendingDistributionUnits); ?> unit(s) from
                                            <?php echo h((string) $pendingDistributionRecords); ?> receiving record(s)
                                            are still waiting for ICS/PAR posting.
                                        </div>
                                        <a class="btn btn-sm btn-outline-primary mt-2" href="<?php echo base_url('modules/distributions/index.php'); ?>">
                                            Open Distribution
                                        </a>
                                    </div>
                                </div>
                                <?php $hasPreviousNotification = true; ?>
                            <?php endif; ?>

                            <?php if ($lowStockItemCount > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-secondary-subtle text-secondary d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-exclamation-triangle"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Low Stock Supplies</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $lowStockItemCount); ?> supply item(s) have an on-hand balance of 5 or fewer.
                                        </div>
                                        <a class="btn btn-sm btn-outline-secondary mt-2" href="<?php echo base_url('modules/stock_catalog/index.php'); ?>">
                                            Open Stock Catalog
                                        </a>
                                    </div>
                                </div>
                                <?php $hasPreviousNotification = true; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="dropdown d-flex align-items-center">
                <button class="btn topbar-user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="text-end d-none d-lg-flex">
                        <div class="small text-muted">Signed in as</div>
                        <div class="fw-semibold"><?php echo h($displayName); ?> <span class="text-muted small ms-2"><?php echo h($userRole); ?></span></div>
                    </div>
                    <div class="topbar-avatar">
                        <?php echo h(strtoupper(substr($displayName, 0, 1))); ?>
                    </div>
                </button>
                <div class="dropdown-menu dropdown-menu-end topbar-user-menu">
                    <div class="topbar-user-menu-head">
                        <div class="fw-semibold"><?php echo h($displayName); ?></div>
                        <div class="small text-muted"><?php echo h($roleName); ?></div>
                    </div>
                    <a class="dropdown-item text-danger" href="<?php echo base_url('auth/logout.php'); ?>">
                        <i class="bi bi-box-arrow-right me-2"></i>Sign out
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<main id="main" class="main">
    <div class="pagetitle d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><?php echo h($pageTitle ?? 'Dashboard'); ?></h1>
            <div class="text-muted small"><?php echo h($roleName); ?></div>
        </div>
    </div>
