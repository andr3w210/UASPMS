<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$errors = [];
$success = '';

if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }
    $detailId = (int) ($_POST['distribution_item_detail_id'] ?? 0);
    $disposalDate = trim($_POST['disposal_date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $approvedBy = (int) ($_POST['approved_by'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($detailId <= 0) $errors[] = 'Select an item to dispose.';
    if ($disposalDate === '') $errors[] = 'Disposal date is required.';

    if (empty($errors)) {
        // Ensure disposals table exists
        $db->query("CREATE TABLE IF NOT EXISTS disposals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            system_reference VARCHAR(50) NOT NULL,
            disposal_date DATE NOT NULL,
            distribution_item_detail_id INT UNSIGNED NOT NULL,
            reason VARCHAR(64) NOT NULL,
            approved_by INT UNSIGNED NULL,
            remarks TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Add is_disposed column if missing
        $colRes = $db->query("SHOW COLUMNS FROM distribution_item_details LIKE 'is_disposed'");
        if ($colRes && $colRes->num_rows === 0) {
            $db->query("ALTER TABLE distribution_item_details ADD COLUMN is_disposed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_distributed");
        }

        $systemRef = next_module_code($db, 'disposals');
        $userId = current_user_id();
        $ins = $db->prepare("INSERT INTO disposals (system_reference, disposal_date, distribution_item_detail_id, reason, approved_by, remarks, created_by) VALUES (?, ?, ?, ?, NULLIF(?,0), ?, ?)");
        if ($ins) {
            $ins->bind_param('ssisssi', $systemRef, $disposalDate, $detailId, $reason, $approvedBy, $remarks, $userId);
            $ins->execute();
            $ins->close();

            // mark detail as disposed
            $u = $db->prepare("UPDATE distribution_item_details SET is_disposed = 1 WHERE id = ?");
            if ($u) { $u->bind_param('i', $detailId); $u->execute(); $u->close(); }

            $success = 'Disposal recorded.';
        } else {
            $errors[] = 'Unable to save disposal.';
        }
    }
}

$available = [];
$rows = [];
$emps = [];
if ($db) {
    // distributed, not yet disposed items
    $stmt = $db->prepare("SELECT did.id, did.property_number, did.brand, did.model, did.serial_no, d.document_no, o.office_name FROM distribution_item_details did INNER JOIN distribution_items di ON di.id = did.distribution_item_id INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' LEFT JOIN offices o ON o.id = d.office_id WHERE did.is_distributed = 1 AND (did.is_disposed IS NULL OR did.is_disposed = 0)");
    if ($stmt) { $stmt->execute(); $available = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); }

    $rStmt = $db->prepare("SELECT dp.id, dp.system_reference, dp.disposal_date, dp.reason, dp.remarks, did.property_number, d.document_no, o.office_name FROM disposals dp LEFT JOIN distribution_item_details did ON did.id = dp.distribution_item_detail_id LEFT JOIN distribution_items di ON di.id = did.distribution_item_id LEFT JOIN distributions d ON d.id = di.distribution_id LEFT JOIN offices o ON o.id = d.office_id ORDER BY dp.created_at DESC");
    if ($rStmt) { $rStmt->execute(); $rows = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC); $rStmt->close(); }

    // load employees for approved_by
    $eStmt = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE is_active = 1 ORDER BY last_name ASC");
    if ($eStmt) { $eStmt->execute(); $emps = $eStmt->get_result()->fetch_all(MYSQLI_ASSOC); $eStmt->close(); }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<div class="container">
    <h3>Disposals</h3>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php echo h(implode('<br>', $errors)); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo h($success); ?></div>
    <?php endif; ?>

    <form method="post" class="mb-4">
        <?php echo csrf_input(); ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Item</label>
                <select name="distribution_item_detail_id" class="form-select form-select-sm">
                    <option value="">Select distributed item</option>
                    <?php foreach ($available as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>"><?php echo h($a['property_number'] ?: ($a['brand'] . ' ' . $a['model'])); ?> — <?php echo h($a['document_no'] ?? ''); ?> (<?php echo h($a['office_name'] ?? ''); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Disposal Date</label>
                <input type="date" name="disposal_date" class="form-control form-control-sm" value="<?php echo h(date('Y-m-d')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Reason</label>
                <select name="reason" class="form-select form-select-sm">
                    <option value="unserviceable">Unserviceable</option>
                    <option value="obsolete">Obsolete</option>
                    <option value="lost">Lost</option>
                    <option value="beyond_repair">Beyond Repair</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Approved By</label>
                <select name="approved_by" class="form-select form-select-sm">
                    <option value="0">--</option>
                    <?php foreach ($emps as $em): ?>
                        <option value="<?php echo (int)$em['id']; ?>"><?php echo h(trim($em['first_name'] . ' ' . $em['last_name'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mt-3">
            <div class="row">
                <div class="col-md-10">
                    <label class="form-label">Remarks</label>
                    <input type="text" name="remarks" class="form-control form-control-sm">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-danger w-100">Record Disposal</button>
                </div>
            </div>
        </div>
    </form>

    <h5>Recent Disposals</h5>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Ref</th><th>Date</th><th>Item</th><th>Doc</th><th>Office</th><th>Reason</th></tr></thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $rw): ?>
                    <tr>
                        <td><?php echo h($rw['system_reference']); ?></td>
                        <td><?php echo h(!empty($rw['disposal_date']) ? date('M d, Y', strtotime($rw['disposal_date'])) : ''); ?></td>
                        <td><?php echo h($rw['property_number'] ?? ''); ?></td>
                        <td><?php echo h($rw['document_no'] ?? ''); ?></td>
                        <td><?php echo h($rw['office_name'] ?? ''); ?></td>
                        <td><?php echo h($rw['reason'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center text-muted">No disposals recorded.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
