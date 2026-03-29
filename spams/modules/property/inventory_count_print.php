<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$sessionId = (int) ($_GET['session_id'] ?? 0);
$page_title = 'Inventory Count Result';
$session = null;
$items = [];
$statusLabels = [
    'pending' => 'Pending',
    'found' => 'Found',
    'missing' => 'Missing',
    'for_repair' => 'For Repair',
    'for_disposal' => 'For Disposal',
    'wrong_office' => 'Wrong Office',
    'wrong_accountable' => 'Wrong Accountable',
];
$summary = [
    'total' => 0,
    'pending' => 0,
    'found' => 0,
    'missing' => 0,
    'for_repair' => 0,
    'for_disposal' => 0,
    'wrong_office' => 0,
    'wrong_accountable' => 0,
    'resolved' => 0,
    'unresolved' => 0,
];

if ($db && $sessionId > 0) {
    $sessionStmt = $db->prepare("
        SELECT ics.*, o.office_name
        FROM inventory_count_sessions ics
        INNER JOIN offices o ON o.id = ics.office_id
        WHERE ics.id = ?
        LIMIT 1
    ");
    if ($sessionStmt) {
        $sessionStmt->bind_param('i', $sessionId);
        $sessionStmt->execute();
        $session = $sessionStmt->get_result()->fetch_assoc();
        $sessionStmt->close();
    }

    if ($session) {
        $itemsStmt = $db->prepare("
            SELECT property_number, item_type, classification_name, item_description, brand, model, serial_no, office_name, accountable_name, status, remarks, source_type, checked_at, resolution_status, resolution_action, resolution_notes, resolved_at
            FROM inventory_count_items ici
            LEFT JOIN offices o ON o.id = ici.office_id
            WHERE ici.session_id = ?
            ORDER BY FIELD(status, 'missing', 'wrong_office', 'wrong_accountable', 'for_repair', 'for_disposal', 'pending', 'found'), item_type ASC, item_description ASC, property_number ASC
        ");
        if ($itemsStmt) {
            $itemsStmt->bind_param('i', $sessionId);
            $itemsStmt->execute();
            $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();
        }

        foreach ($items as $item) {
            $summary['total']++;
            $status = (string) ($item['status'] ?? 'pending');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
            if (($item['status'] ?? 'pending') !== 'found') {
                if (($item['resolution_status'] ?? 'unresolved') === 'resolved') {
                    $summary['resolved']++;
                } else {
                    $summary['unresolved']++;
                }
            }
        }
    }
}

if (!$session) {
    http_response_code(404);
    echo 'Inventory count session not found.';
    exit;
}

$exceptions = array_filter($items, static function (array $item): bool {
    return ($item['status'] ?? 'pending') !== 'found';
});
$resolvedExceptions = array_filter($exceptions, static function (array $item): bool {
    return ($item['resolution_status'] ?? 'unresolved') === 'resolved';
});
$unresolvedExceptions = array_filter($exceptions, static function (array $item): bool {
    return ($item['resolution_status'] ?? 'unresolved') !== 'resolved';
});
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo h($session['system_reference']); ?> - Inventory Count Result</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-size: 12px; }
        .summary-grid { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .summary-card { border:1px solid #dfe3eb; border-radius: 12px; padding: 12px; }
        .summary-label { color:#6b7280; font-size:11px; text-transform: uppercase; letter-spacing: .04em; }
        .summary-value { font-size: 24px; font-weight: 700; color:#0b3b8c; }
        @media print {
            .no-print { display:none !important; }
            body { font-size: 11px; }
            .summary-grid { gap: 8px; }
        }
    </style>
</head>
<body>
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button>
        <button class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
    </div>

    <div class="text-center mb-4">
        <div class="small text-muted">SPAMS Inventory Count Result</div>
        <h4 class="mb-1"><?php echo h($session['system_reference']); ?></h4>
        <div>
            <?php echo h(ucfirst((string) $session['count_type'])); ?> |
            <?php echo h($session['office_name']); ?> |
            <?php echo h(date('M d, Y', strtotime((string) $session['count_date']))); ?>
        </div>
        <div>Status: <?php echo h(ucfirst((string) $session['status'])); ?></div>
    </div>

    <div class="summary-grid mb-4">
        <div class="summary-card"><div class="summary-label">Loaded Assets</div><div class="summary-value"><?php echo number_format($summary['total']); ?></div></div>
        <div class="summary-card"><div class="summary-label">Found</div><div class="summary-value"><?php echo number_format($summary['found']); ?></div></div>
        <div class="summary-card"><div class="summary-label">Pending</div><div class="summary-value"><?php echo number_format($summary['pending']); ?></div></div>
        <div class="summary-card"><div class="summary-label">Exceptions</div><div class="summary-value"><?php echo number_format(count($exceptions)); ?></div></div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach (['missing', 'wrong_office', 'wrong_accountable', 'for_repair', 'for_disposal'] as $statusKey): ?>
            <div class="col-md-2 col-6">
                <div class="summary-card h-100">
                    <div class="summary-label"><?php echo h($statusLabels[$statusKey]); ?></div>
                    <div class="summary-value"><?php echo number_format($summary[$statusKey]); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="summary-grid mb-4">
        <div class="summary-card"><div class="summary-label">Resolved Exceptions</div><div class="summary-value"><?php echo number_format($summary['resolved']); ?></div></div>
        <div class="summary-card"><div class="summary-label">Unresolved Exceptions</div><div class="summary-value"><?php echo number_format($summary['unresolved']); ?></div></div>
        <div class="summary-card"><div class="summary-label">Session Status</div><div class="summary-value" style="font-size:18px;"><?php echo h(ucfirst((string) $session['status'])); ?></div></div>
        <div class="summary-card"><div class="summary-label">Office</div><div class="summary-value" style="font-size:18px;"><?php echo h($session['office_name']); ?></div></div>
    </div>

    <?php if (!empty($session['notes'])): ?>
        <div class="border rounded p-3 mb-4">
            <div class="small text-muted mb-1">Session Notes</div>
            <div><?php echo nl2br(h((string) $session['notes'])); ?></div>
        </div>
    <?php endif; ?>

    <h5 class="mb-2">Unresolved Discrepancies</h5>
    <table class="table table-bordered align-middle">
        <thead>
            <tr>
                <th>Property No.</th>
                <th>Asset</th>
                <th>Assignment</th>
                <th>Status</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($unresolvedExceptions): ?>
                <?php foreach ($unresolvedExceptions as $item): ?>
                    <?php
                    $brandModel = trim(implode(' / ', array_filter([
                        trim((string) ($item['brand'] ?? '')),
                        trim((string) ($item['model'] ?? '')),
                    ])));
                    $typeLabel = ($item['item_type'] ?? '') === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment';
                    ?>
                    <tr>
                        <td><?php echo h($item['property_number']); ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo h($item['item_description']); ?></div>
                            <div class="small text-muted">
                                <?php echo h(trim(implode(' | ', array_filter([
                                    $typeLabel,
                                    $item['classification_name'] ?? '',
                                    $brandModel,
                                    !empty($item['serial_no']) ? 'SN: ' . $item['serial_no'] : '',
                                    ($item['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System',
                                ])))); ?>
                            </div>
                        </td>
                        <td>
                            <div><?php echo h($item['office_name'] ?? '-'); ?></div>
                            <div class="small text-muted"><?php echo h($item['accountable_name'] ?? ''); ?></div>
                        </td>
                        <td><?php echo h($statusLabels[$item['status']] ?? ucfirst((string) $item['status'])); ?></td>
                        <td><?php echo h(trim(implode(' | ', array_filter([
                            (string) ($item['remarks'] ?? ''),
                            (string) ($item['resolution_notes'] ?? ''),
                        ])))); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No unresolved discrepancies. All exception items already have a recorded follow-up.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h5 class="mb-2 mt-4">Resolved Follow-up Items</h5>
    <table class="table table-bordered align-middle">
        <thead>
            <tr>
                <th>Property No.</th>
                <th>Asset</th>
                <th>Status</th>
                <th>Resolution</th>
                <th>Resolved At</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($resolvedExceptions): ?>
                <?php foreach ($resolvedExceptions as $item): ?>
                    <?php $brandModel = trim(implode(' / ', array_filter([trim((string) ($item['brand'] ?? '')), trim((string) ($item['model'] ?? ''))]))); ?>
                    <tr>
                        <td><?php echo h($item['property_number']); ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo h($item['item_description']); ?></div>
                            <div class="small text-muted"><?php echo h(trim(implode(' | ', array_filter([$item['classification_name'] ?? '', $brandModel])))); ?></div>
                        </td>
                        <td><?php echo h($statusLabels[$item['status']] ?? ucfirst((string) $item['status'])); ?></td>
                        <td><?php echo h(trim(implode(' | ', array_filter([
                            ucwords(str_replace('_', ' ', (string) ($item['resolution_action'] ?? ''))),
                            (string) ($item['resolution_notes'] ?? ''),
                        ])))); ?></td>
                        <td><?php echo h(!empty($item['resolved_at']) ? date('M d, Y h:i A', strtotime((string) $item['resolved_at'])) : '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No resolved exception items recorded yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
