<?php
$displayName = $_SESSION['user_name'] ?? 'User';
$roleName = $_SESSION['role_name'] ?? 'Administrator';
$userRole = $_SESSION['user_role'] ?? 'User';
$userPhotoPath = (string) ($_SESSION['user_photo_path'] ?? '');
$transportNotificationRole = function_exists('current_user_role') ? current_user_role() : trim((string) ($_SESSION['user_role'] ?? ($_SESSION['role_name'] ?? '')));
$notificationDb = (isset($db) && $db instanceof mysqli) ? $db : db();
$pendingDistributionUnits = 0;
$pendingDistributionRecords = 0;
$pendingReceivingCount = 0;
$deliveryDueSoonCount = 0;
$deliveryOverdueCount = 0;
$dueSoonNoReceivingCount = 0;
$repeatExtensionCount = 0;
$lowStockItemCount = 0;
$unreadMessageCount = 0;
$unclassifiedReceivedItemCount = 0;
$rejectedReceivingItemCount = 0;
$inventoryDiscrepancyCount = 0;
$mustChangePasswordUserCount = 0;
$nextDayTripCount = 0;
$pendingTripCompletionCount = 0;
$overdueTripCompletionCount = 0;
$vehicleConflictCount = 0;
$driverConflictCount = 0;
$returnTodayTripCount = 0;
if ($notificationDb) {
    $tableExists = static function (mysqli $connection, string $tableName): bool {
        $escapedTable = $connection->real_escape_string($tableName);
        $result = $connection->query("SHOW TABLES LIKE '{$escapedTable}'");
        if ($result instanceof mysqli_result) {
            $exists = $result->num_rows > 0;
            $result->close();
            return $exists;
        }

        return false;
    };
    $receivingDetailHasDisposedFlag = false;
    $receivingDetailDisposedCondition = '1 = 1';
    if ($tableExists($notificationDb, 'receiving_item_details')) {
        $receivingDetailDisposedColumnResult = $notificationDb->query("SHOW COLUMNS FROM receiving_item_details LIKE 'is_disposed'");
        if ($receivingDetailDisposedColumnResult instanceof mysqli_result) {
            $receivingDetailHasDisposedFlag = $receivingDetailDisposedColumnResult->num_rows > 0;
            $receivingDetailDisposedColumnResult->close();
        }
    }
    if ($receivingDetailHasDisposedFlag) {
        $receivingDetailDisposedCondition = 'COALESCE(rid.is_disposed, 0) = 0';
    }

    if ($userPhotoPath === '' && current_user_id() && $tableExists($notificationDb, 'users')) {
        $photoStmt = $notificationDb->prepare("SELECT profile_photo_path FROM users WHERE id = ? LIMIT 1");
        if ($photoStmt) {
            $currentTopbarPhotoUserId = (int) current_user_id();
            $photoStmt->bind_param('i', $currentTopbarPhotoUserId);
            $photoStmt->execute();
            $userPhotoPath = (string) (($photoStmt->get_result()->fetch_assoc()['profile_photo_path'] ?? ''));
            $photoStmt->close();
            $_SESSION['user_photo_path'] = $userPhotoPath;
        }
    }

    if (current_user_id()) {
        $currentTopbarUserId = (int) current_user_id();
        if ($tableExists($notificationDb, 'user_messages')) {
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
        }

        $unreadMessageCount += message_channel_unread_count($notificationDb, 'general', $currentTopbarUserId);
    }

    if ($tableExists($notificationDb, 'receiving_item_details')
        && $tableExists($notificationDb, 'receiving_items')
        && $tableExists($notificationDb, 'receivings')
        && $tableExists($notificationDb, 'purchase_order_items')) {
        $pendingUnitsStmt = $notificationDb->prepare(
            "SELECT COUNT(*) AS total
             FROM receiving_item_details rid
             INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
             INNER JOIN receivings r ON r.id = ri.receiving_id
             INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
             WHERE r.status != 'cancelled'
               AND poi.item_type IN ('semi_expendable', 'equipment')
               AND rid.is_distributed = 0
               AND {$receivingDetailDisposedCondition}"
        );
        if ($pendingUnitsStmt) {
            $pendingUnitsStmt->execute();
            $pendingDistributionUnits = (int) (($pendingUnitsStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $pendingUnitsStmt->close();
        }
    }

    if ($tableExists($notificationDb, 'receiving_item_details')
        && $tableExists($notificationDb, 'receiving_items')
        && $tableExists($notificationDb, 'receivings')
        && $tableExists($notificationDb, 'purchase_order_items')) {
        $pendingRecordsStmt = $notificationDb->prepare(
            "SELECT COUNT(DISTINCT r.id) AS total
             FROM receiving_item_details rid
             INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
             INNER JOIN receivings r ON r.id = ri.receiving_id
             INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
             WHERE r.status != 'cancelled'
               AND poi.item_type IN ('semi_expendable', 'equipment')
               AND rid.is_distributed = 0
               AND {$receivingDetailDisposedCondition}"
        );
        if ($pendingRecordsStmt) {
            $pendingRecordsStmt->execute();
            $pendingDistributionRecords = (int) (($pendingRecordsStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $pendingRecordsStmt->close();
        }
    }

    if ($tableExists($notificationDb, 'purchase_orders')) {
        $pendingReceivingStmt = $notificationDb->prepare(
            "SELECT COUNT(*) AS total
             FROM purchase_orders po
             WHERE COALESCE(po.status, 'encoded') IN ('encoded', 'partial')"
        );
        if ($pendingReceivingStmt) {
            $pendingReceivingStmt->execute();
            $pendingReceivingCount = (int) (($pendingReceivingStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $pendingReceivingStmt->close();
        }
    }

    if ($tableExists($notificationDb, 'purchase_orders')) {
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
    }

    if ($tableExists($notificationDb, 'purchase_orders')
        && $tableExists($notificationDb, 'purchase_order_items')
        && $tableExists($notificationDb, 'receiving_items')
        && $tableExists($notificationDb, 'receivings')) {
        $dueSoonNoReceivingStmt = $notificationDb->prepare(
            "SELECT COUNT(*) AS total
             FROM purchase_orders po
             WHERE COALESCE(po.status, 'encoded') IN ('encoded', 'partial')
               AND po.expected_delivery_date >= CURDATE()
               AND po.expected_delivery_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
               AND NOT EXISTS (
                   SELECT 1
                   FROM purchase_order_items poi
                   INNER JOIN receiving_items ri ON ri.purchase_order_item_id = poi.id
                   INNER JOIN receivings r ON r.id = ri.receiving_id
                   WHERE poi.purchase_order_id = po.id
                     AND r.status != 'cancelled'
                     AND COALESCE(ri.quantity_delivered, 0) > 0
               )"
        );
        if ($dueSoonNoReceivingStmt) {
            $dueSoonNoReceivingStmt->execute();
            $dueSoonNoReceivingCount = (int) (($dueSoonNoReceivingStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $dueSoonNoReceivingStmt->close();
        }
    }

    if ($tableExists($notificationDb, 'purchase_order_delivery_extensions')) {
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
    }

    if ($tableExists($notificationDb, 'stock_items')) {
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

    if ($tableExists($notificationDb, 'receiving_items')
        && $tableExists($notificationDb, 'receivings')
        && $tableExists($notificationDb, 'purchase_order_items')) {
        $unclassifiedReceivedStmt = $notificationDb->prepare(
            "SELECT COUNT(*) AS total
             FROM receiving_items ri
             INNER JOIN receivings r ON r.id = ri.receiving_id
             INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
             WHERE r.status != 'cancelled'
               AND COALESCE(ri.quantity_delivered, 0) > 0
               AND poi.classification_id IS NULL"
        );
        if ($unclassifiedReceivedStmt) {
            $unclassifiedReceivedStmt->execute();
            $unclassifiedReceivedItemCount = (int) (($unclassifiedReceivedStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $unclassifiedReceivedStmt->close();
        }
    }

    if ($tableExists($notificationDb, 'receiving_items')
        && $tableExists($notificationDb, 'receivings')) {
        $rejectedReceivingStmt = $notificationDb->prepare(
            "SELECT COUNT(*) AS total
             FROM receiving_items ri
             INNER JOIN receivings r ON r.id = ri.receiving_id
             WHERE r.status != 'cancelled'
               AND COALESCE(ri.quantity_rejected, 0) > 0"
        );
        if ($rejectedReceivingStmt) {
            $rejectedReceivingStmt->execute();
            $rejectedReceivingItemCount = (int) (($rejectedReceivingStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $rejectedReceivingStmt->close();
        }
    }

    if ($tableExists($notificationDb, 'inventory_count_items')) {
        $inventoryDiscrepancyStmt = $notificationDb->prepare(
            "SELECT COUNT(*) AS total
             FROM inventory_count_items
             WHERE status IN ('missing', 'for_repair', 'for_disposal', 'wrong_office', 'wrong_accountable')
               AND COALESCE(resolution_status, 'unresolved') = 'unresolved'"
        );
        if ($inventoryDiscrepancyStmt) {
            $inventoryDiscrepancyStmt->execute();
            $inventoryDiscrepancyCount = (int) (($inventoryDiscrepancyStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $inventoryDiscrepancyStmt->close();
        }
    }

    if (in_array($transportNotificationRole, ['Administrator'], true) && $tableExists($notificationDb, 'users')) {
        $mustChangePasswordStmt = $notificationDb->prepare(
            "SELECT COUNT(*) AS total
             FROM users
             WHERE is_active = 1
               AND COALESCE(must_change_password, 0) = 1"
        );
        if ($mustChangePasswordStmt) {
            $mustChangePasswordStmt->execute();
            $mustChangePasswordUserCount = (int) (($mustChangePasswordStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $mustChangePasswordStmt->close();
        }
    }
}
$tripNotificationDb = function_exists('trip_db') ? trip_db() : null;
if ($tripNotificationDb && in_array($transportNotificationRole, ['Administrator', 'Transport Officer'], true)) {
    $tripTableExists = static function (mysqli $connection, string $tableName): bool {
        $escapedTable = $connection->real_escape_string($tableName);
        $result = $connection->query("SHOW TABLES LIKE '{$escapedTable}'");
        if ($result instanceof mysqli_result) {
            $exists = $result->num_rows > 0;
            $result->close();
            return $exists;
        }
        return false;
    };

    if ($tripTableExists($tripNotificationDb, 'trip_tickets')) {
        $nextDayTripStmt = $tripNotificationDb->prepare("
            SELECT COUNT(*) AS total
            FROM trip_tickets
            WHERE departure_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              AND COALESCE(status, 'scheduled') IN ('scheduled', 'ongoing')
        ");
        if ($nextDayTripStmt) {
            $nextDayTripStmt->execute();
            $nextDayTripCount = (int) (($nextDayTripStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $nextDayTripStmt->close();
        }

        $pendingTripCompletionStmt = $tripNotificationDb->prepare("
            SELECT COUNT(*) AS total
            FROM trip_tickets
            WHERE departure_date < CURDATE()
              AND COALESCE(status, 'scheduled') <> 'completed'
        ");
        if ($pendingTripCompletionStmt) {
            $pendingTripCompletionStmt->execute();
            $pendingTripCompletionCount = (int) (($pendingTripCompletionStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $pendingTripCompletionStmt->close();
        }

        $overdueTripCompletionStmt = $tripNotificationDb->prepare("
            SELECT COUNT(*) AS total
            FROM trip_tickets
            WHERE COALESCE(return_date, departure_date) < CURDATE()
              AND COALESCE(status, 'scheduled') <> 'completed'
        ");
        if ($overdueTripCompletionStmt) {
            $overdueTripCompletionStmt->execute();
            $overdueTripCompletionCount = (int) (($overdueTripCompletionStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $overdueTripCompletionStmt->close();
        }

        $returnTodayTripStmt = $tripNotificationDb->prepare("
            SELECT COUNT(*) AS total
            FROM trip_tickets
            WHERE COALESCE(return_date, departure_date) = CURDATE()
              AND COALESCE(status, 'scheduled') IN ('scheduled', 'ongoing')
        ");
        if ($returnTodayTripStmt) {
            $returnTodayTripStmt->execute();
            $returnTodayTripCount = (int) (($returnTodayTripStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $returnTodayTripStmt->close();
        }

        $vehicleConflictStmt = $tripNotificationDb->prepare("
            SELECT COUNT(DISTINCT t1.vehicle_id) AS total
            FROM trip_tickets t1
            INNER JOIN trip_tickets t2
                ON t1.vehicle_id = t2.vehicle_id
               AND t1.id < t2.id
               AND COALESCE(t1.status, 'scheduled') IN ('scheduled', 'ongoing')
               AND COALESCE(t2.status, 'scheduled') IN ('scheduled', 'ongoing')
               AND t1.departure_date <= COALESCE(t2.return_date, t2.departure_date)
               AND t2.departure_date <= COALESCE(t1.return_date, t1.departure_date)
        ");
        if ($vehicleConflictStmt) {
            $vehicleConflictStmt->execute();
            $vehicleConflictCount = (int) (($vehicleConflictStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $vehicleConflictStmt->close();
        }

        $driverConflictStmt = $tripNotificationDb->prepare("
            SELECT COUNT(DISTINCT t1.driver_employee_id) AS total
            FROM trip_tickets t1
            INNER JOIN trip_tickets t2
                ON t1.driver_employee_id = t2.driver_employee_id
               AND t1.id < t2.id
               AND t1.driver_employee_id IS NOT NULL
               AND COALESCE(t1.status, 'scheduled') IN ('scheduled', 'ongoing')
               AND COALESCE(t2.status, 'scheduled') IN ('scheduled', 'ongoing')
               AND t1.departure_date <= COALESCE(t2.return_date, t2.departure_date)
               AND t2.departure_date <= COALESCE(t1.return_date, t1.departure_date)
        ");
        if ($driverConflictStmt) {
            $driverConflictStmt->execute();
            $driverConflictCount = (int) (($driverConflictStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $driverConflictStmt->close();
        }
    }
}
$userPhotoUrl = upload_url($userPhotoPath);
$notificationBadgeCount =
    $pendingDistributionUnits +
    $pendingReceivingCount +
    $deliveryDueSoonCount +
    $deliveryOverdueCount +
    $dueSoonNoReceivingCount +
    $repeatExtensionCount +
    $lowStockItemCount +
    $unclassifiedReceivedItemCount +
    $rejectedReceivingItemCount +
    $inventoryDiscrepancyCount +
    $mustChangePasswordUserCount;
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
                            <div class="fw-semibold">Supply And Property Notifications</div>
                            <div class="small text-muted">Action items that need follow-up across purchasing, receiving, stock, and distribution.</div>
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

                            <?php if ($dueSoonNoReceivingCount > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-hourglass-split"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Due Soon Without Receiving</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $dueSoonNoReceivingCount); ?> due-soon PO(s) still have no receiving activity recorded.
                                        </div>
                                        <a class="btn btn-sm btn-outline-warning mt-2" href="<?php echo base_url('modules/receivings/index.php'); ?>">
                                            Start Receiving
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
                                            <?php echo h((string) $pendingReceivingCount); ?> purchase order(s) are still pending or partially received.
                                        </div>
                                        <a class="btn btn-sm btn-outline-primary mt-2" href="<?php echo base_url('modules/receivings/index.php'); ?>">
                                            Open Receiving
                                        </a>
                                    </div>
                                </div>
                                <?php $hasPreviousNotification = true; ?>
                            <?php endif; ?>

                            <?php if ($rejectedReceivingItemCount > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-box-arrow-in-down-left"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Rejected Receiving Items</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $rejectedReceivingItemCount); ?> receiving line(s) include rejected quantities that need follow-up.
                                        </div>
                                        <a class="btn btn-sm btn-outline-danger mt-2" href="<?php echo base_url('modules/receivings/index.php?filter_status=rejected'); ?>">
                                            Review Rejections
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

                            <?php if ($unclassifiedReceivedItemCount > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-tags"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Received Items Still Unclassified</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $unclassifiedReceivedItemCount); ?> received item row(s) still have no classification and can block downstream distribution or reporting.
                                        </div>
                                        <a class="btn btn-sm btn-outline-warning mt-2" href="<?php echo base_url('modules/receivings/index.php'); ?>">
                                            Review Receivings
                                        </a>
                                    </div>
                                </div>
                                <?php $hasPreviousNotification = true; ?>
                            <?php endif; ?>

                            <?php if ($inventoryDiscrepancyCount > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-clipboard2-pulse"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Inventory Discrepancies Awaiting Action</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $inventoryDiscrepancyCount); ?> property count discrepancy item(s) are still unresolved.
                                        </div>
                                        <a class="btn btn-sm btn-outline-warning mt-2" href="<?php echo base_url('modules/property/inventory_reconciliation.php?resolution=unresolved'); ?>">
                                            Open Reconciliation
                                        </a>
                                    </div>
                                </div>
                                <?php $hasPreviousNotification = true; ?>
                            <?php endif; ?>

                            <?php if ($mustChangePasswordUserCount > 0): ?>
                                <?php if ($hasPreviousNotification): ?><hr class="my-3"><?php endif; ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="rounded-circle bg-secondary-subtle text-secondary d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="bi bi-key"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">Users Still on Initial Password</div>
                                        <div class="small text-muted">
                                            <?php echo h((string) $mustChangePasswordUserCount); ?> active user account(s) have not changed the initial password yet.
                                        </div>
                                        <a class="btn btn-sm btn-outline-secondary mt-2" href="<?php echo base_url('modules/users/index.php'); ?>">
                                            Review Users
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
                        <?php if ($userPhotoUrl !== ''): ?>
                            <img src="<?php echo h($userPhotoUrl); ?>" alt="<?php echo h($displayName); ?>">
                        <?php else: ?>
                            <?php echo h(strtoupper(substr($displayName, 0, 1))); ?>
                        <?php endif; ?>
                    </div>
                </button>
                <div class="dropdown-menu dropdown-menu-end topbar-user-menu">
                    <div class="topbar-user-menu-head">
                        <div class="fw-semibold"><?php echo h($displayName); ?></div>
                        <div class="small text-muted"><?php echo h($roleName); ?></div>
                    </div>
                    <a class="dropdown-item" href="<?php echo base_url('modules/settings/profile.php'); ?>">
                        <i class="bi bi-person-circle me-2"></i>Edit Profile
                    </a>
                    <a class="dropdown-item" href="<?php echo base_url('modules/settings/change_password.php'); ?>">
                        <i class="bi bi-key me-2"></i>Change Password
                    </a>
                    <div class="dropdown-divider"></div>
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
