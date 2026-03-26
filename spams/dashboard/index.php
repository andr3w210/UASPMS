<?php
require_once __DIR__ . '/../app/config/init.php';

require_login();

$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';

$db = db_connect();
$totals = [
    'pos' => 0,
    'receivings' => 0,
    'distributions' => 0,
    'property_items' => 0,
];
$recentDistributions = [];
if ($db) {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM purchase_orders WHERE status != 'cancelled'");
    if ($stmt) { $stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); $totals['pos'] = (int)($r['cnt'] ?? 0); $stmt->close(); }

    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM receivings WHERE status != 'cancelled'");
    if ($stmt) { $stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); $totals['receivings'] = (int)($r['cnt'] ?? 0); $stmt->close(); }

    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM distributions WHERE status = 'posted'");
    if ($stmt) { $stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); $totals['distributions'] = (int)($r['cnt'] ?? 0); $stmt->close(); }

    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM distribution_item_details");
    if ($stmt) { $stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); $totals['property_items'] = (int)($r['cnt'] ?? 0); $stmt->close(); }

    $stmt = $db->prepare("SELECT d.system_reference, d.document_no, d.document_type, o.office_name, d.distribution_date, d.total_amount FROM distributions d INNER JOIN offices o ON o.id = d.office_id ORDER BY d.distribution_date DESC, d.id DESC LIMIT 10");
    if ($stmt) { $stmt->execute(); $recentDistributions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); }
}
?>
<section class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="col-12">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-muted small">Total POs</div>
                                <div class="fw-semibold fs-4"><?php echo h((string)$totals['pos']); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-muted small">Total Receivings</div>
                                <div class="fw-semibold fs-4"><?php echo h((string)$totals['receivings']); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-muted small">Total Distributions</div>
                                <div class="fw-semibold fs-4"><?php echo h((string)$totals['distributions']); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-muted small">Total Property Items</div>
                                <div class="fw-semibold fs-4"><?php echo h((string)$totals['property_items']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-body">
                        <h5 class="card-title">Recent Distributions</h5>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Ref</th>
                                        <th>Doc No.</th>
                                        <th>Type</th>
                                        <th>Office</th>
                                        <th>Date</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentDistributions)): foreach ($recentDistributions as $d): ?>
                                        <tr>
                                            <td><?php echo h($d['system_reference'] ?? ''); ?></td>
                                            <td><?php echo h($d['document_no'] ?? ''); ?></td>
                                            <td><?php echo h(strtoupper($d['document_type'] ?? '')); ?></td>
                                            <td><?php echo h($d['office_name'] ?? ''); ?></td>
                                            <td><?php echo h(!empty($d['distribution_date']) ? date('M d, Y', strtotime($d['distribution_date'])) : ''); ?></td>
                                            <td class="text-end"><?php echo h(number_format((float)($d['total_amount'] ?? 0),2)); ?></td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="6" class="text-center text-muted">No distributions found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
