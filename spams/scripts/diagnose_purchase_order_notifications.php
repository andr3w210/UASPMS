<?php
require_once __DIR__ . '/../app/config/init.php';

$db = db();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$result = $db->query("SELECT status, COUNT(*) AS total, SUM(expected_delivery_date < CURDATE()) AS overdue, SUM(expected_delivery_date IS NULL) AS missing_delivery_date FROM purchase_orders GROUP BY status ORDER BY status");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo ($row['status'] ?: '(empty)') . ' | total: ' . $row['total'] . ' | overdue: ' . ($row['overdue'] ?? 0) . ' | no delivery date: ' . ($row['missing_delivery_date'] ?? 0) . PHP_EOL;
    }
}
echo 'Notifications enabled: ' . get_system_setting($db, 'po_notifications_enabled', '1') . PHP_EOL;
echo 'Internal recipients: ' . implode(', ', po_notification_recipients($db)) . PHP_EOL;
