<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer');

$db = db();
$sessionId = (int) ($_GET['session_id'] ?? 0);
$session = null;
$items = [];
$stats = [
    'total_items' => 0,
    'system_qty' => 0,
    'counted_qty' => 0,
    'variance_qty' => 0,
    'matched' => 0,
    'exceptions' => 0,
    'shortage' => 0,
    'overage' => 0,
    'not_counted' => 0,
];
$adjustmentSummary = [
    'pending' => 0,
    'approved' => 0,
    'cancelled' => 0,
];

if ($sessionId > 0) {
    $sessionStmt = $db->prepare("
        SELECT scs.*, cu.full_name AS created_by_name, xu.full_name AS closed_by_name
        FROM supply_count_sessions scs
        LEFT JOIN users cu ON cu.id = scs.created_by
        LEFT JOIN users xu ON xu.id = scs.closed_by
        WHERE scs.id = ?
        LIMIT 1
    ");
    if ($sessionStmt) {
        $sessionStmt->bind_param('i', $sessionId);
        $sessionStmt->execute();
        $session = $sessionStmt->get_result()->fetch_assoc();
        $sessionStmt->close();
    }

    $statsStmt = $db->prepare("
        SELECT
            COUNT(*) AS total_items,
            COALESCE(SUM(system_quantity), 0) AS system_qty,
            COALESCE(SUM(COALESCE(counted_quantity, 0)), 0) AS counted_qty,
            COALESCE(SUM(COALESCE(variance_quantity, 0)), 0) AS variance_qty,
            SUM(CASE WHEN count_status = 'match' THEN 1 ELSE 0 END) AS matched,
            SUM(CASE WHEN count_status IN ('shortage', 'overage', 'not_counted') THEN 1 ELSE 0 END) AS exceptions
        FROM supply_count_items
        WHERE session_id = ?
    ");
    if ($statsStmt) {
        $statsStmt->bind_param('i', $sessionId);
        $statsStmt->execute();
        $row = $statsStmt->get_result()->fetch_assoc();
        $statsStmt->close();
        if ($row) {
            $stats = array_merge($stats, $row);
        }
    }

    $itemsStmt = $db->prepare("
        SELECT *
        FROM supply_count_items
        WHERE session_id = ?
          AND count_status IN ('shortage', 'overage', 'not_counted')
        ORDER BY item_description ASC, stock_no ASC, stock_reference ASC
    ");
    if ($itemsStmt) {
        $itemsStmt->bind_param('i', $sessionId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();
    }

    foreach ($items as $item) {
        $status = (string) ($item['count_status'] ?? '');
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }

    $adjustStmt = $db->prepare("
        SELECT status, COUNT(*) AS total_count
        FROM stock_adjustments
        WHERE supply_count_session_id = ?
        GROUP BY status
    ");
    if ($adjustStmt) {
        $adjustStmt->bind_param('i', $sessionId);
        $adjustStmt->execute();
        $adjustRows = $adjustStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $adjustStmt->close();
        foreach ($adjustRows as $adjustRow) {
            $status = (string) ($adjustRow['status'] ?? '');
            if (isset($adjustmentSummary[$status])) {
                $adjustmentSummary[$status] = (int) ($adjustRow['total_count'] ?? 0);
            }
        }
    }
}

if (!$session) {
    http_response_code(404);
    exit('Supply count session not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Supply Count Result</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111827; margin: 24px; }
        h1, h2, h3, p { margin: 0; }
        .header { margin-bottom: 20px; }
        .meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 24px; margin-top: 12px; }
        .meta div { font-size: 13px; }
        .summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 18px 0; }
        .summary-card { border: 1px solid #d1d5db; padding: 12px; border-radius: 6px; }
        .summary-card .label { font-size: 12px; color: #6b7280; text-transform: uppercase; }
        .summary-card .value { font-size: 24px; font-weight: 700; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; font-size: 12px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; }
        .muted { color: #6b7280; }
        .text-end { text-align: right; }
        @media print {
            body { margin: 12mm; }
        }
    
            <?php echo print_page_number_css(); ?></style>
</head>
<body onload="window.print()">
    <div class="header">
        <h1>Supply Physical Count Result</h1>
        <p class="muted">Discrepancy and exception summary for the completed supply count session.</p>
        <div class="meta">
            <div><strong>Reference:</strong> <?php echo h($session['system_reference']); ?></div>
            <div><strong>Count Type:</strong> <?php echo h(ucfirst((string) $session['count_type'])); ?></div>
            <div><strong>Count Date:</strong> <?php echo h(format_date($session['count_date'])); ?></div>
            <div><strong>Status:</strong> <?php echo h(ucfirst((string) $session['status'])); ?></div>
            <div><strong>Created By:</strong> <?php echo h($session['created_by_name'] ?: 'Unknown User'); ?></div>
            <div><strong>Closed By:</strong> <?php echo h($session['closed_by_name'] ?: '-'); ?></div>
        </div>
    </div>

    <div class="summary">
        <div class="summary-card"><div class="label">Loaded Lines</div><div class="value"><?php echo number_format((float) $stats['total_items']); ?></div></div>
        <div class="summary-card"><div class="label">System Quantity</div><div class="value"><?php echo format_quantity($stats['system_qty']); ?></div></div>
        <div class="summary-card"><div class="label">Counted Quantity</div><div class="value"><?php echo format_quantity($stats['counted_qty']); ?></div></div>
        <div class="summary-card"><div class="label">Matched Lines</div><div class="value"><?php echo number_format((float) $stats['matched']); ?></div></div>
        <div class="summary-card"><div class="label">Exception Lines</div><div class="value"><?php echo number_format((float) $stats['exceptions']); ?></div></div>
        <div class="summary-card"><div class="label">Net Variance</div><div class="value"><?php echo format_quantity($stats['variance_qty']); ?></div></div>
    </div>

    <div class="summary">
        <div class="summary-card"><div class="label">Shortage Lines</div><div class="value"><?php echo number_format((float) $stats['shortage']); ?></div></div>
        <div class="summary-card"><div class="label">Overage Lines</div><div class="value"><?php echo number_format((float) $stats['overage']); ?></div></div>
        <div class="summary-card"><div class="label">Not Counted</div><div class="value"><?php echo number_format((float) $stats['not_counted']); ?></div></div>
        <div class="summary-card"><div class="label">Pending Adjustments</div><div class="value"><?php echo number_format((float) $adjustmentSummary['pending']); ?></div></div>
        <div class="summary-card"><div class="label">Approved Adjustments</div><div class="value"><?php echo number_format((float) $adjustmentSummary['approved']); ?></div></div>
        <div class="summary-card"><div class="label">Cancelled Adjustments</div><div class="value"><?php echo number_format((float) $adjustmentSummary['cancelled']); ?></div></div>
    </div>

    <h3>Exception List</h3>
    <table>
        <thead>
            <tr>
                <th>Supply Item</th>
                <th>Reference</th>
                <th class="text-end">System Qty</th>
                <th class="text-end">Counted Qty</th>
                <th class="text-end">Variance</th>
                <th>Status</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items): ?>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <strong><?php echo h($item['item_description']); ?></strong><br>
                            <span class="muted"><?php echo h(trim(implode(' | ', array_filter([$item['classification_name'] ?? '', $item['unit_of_measure'] ?? ''])))); ?></span>
                        </td>
                        <td>
                            <?php echo h($item['stock_no'] ?: '-'); ?><br>
                            <span class="muted"><?php echo h($item['stock_reference']); ?></span>
                        </td>
                        <td class="text-end"><?php echo format_quantity($item['system_quantity']); ?></td>
                        <td class="text-end"><?php echo format_quantity($item['counted_quantity'] ?? 0); ?></td>
                        <td class="text-end"><?php echo format_quantity($item['variance_quantity'] ?? 0); ?></td>
                        <td><?php echo h(ucfirst(str_replace('_', ' ', (string) $item['count_status']))); ?></td>
                        <td><?php echo h((string) ($item['remarks'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="text-center">No discrepancies recorded for this session.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

<?php render_print_page_number(); ?></body>
</html>
