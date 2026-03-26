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
    $returnDate = trim($_POST['return_date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($detailId <= 0) $errors[] = 'Select an item to return.';
    if ($returnDate === '') $errors[] = 'Return date is required.';

    if (empty($errors)) {
        // Ensure returns table exists
        $db->query("CREATE TABLE IF NOT EXISTS returns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            system_reference VARCHAR(50) NOT NULL,
            return_date DATE NOT NULL,
            distribution_item_detail_id INT UNSIGNED NOT NULL,
            reason TEXT NULL,
            remarks TEXT NULL,
            status ENUM('posted','cancelled') DEFAULT 'posted',
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $systemRef = next_module_code($db, 'returns');
        $userId = current_user_id();
        $ins = $db->prepare("INSERT INTO returns (system_reference, return_date, distribution_item_detail_id, reason, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        if ($ins) {
            $ins->bind_param('ssissi', $systemRef, $returnDate, $detailId, $reason, $remarks, $userId);
            $ins->execute();
            $ins->close();

            // find receiving_item_detail_id and mark it as not distributed
            $q = $db->prepare("SELECT receiving_item_detail_id FROM distribution_item_details WHERE id = ? LIMIT 1");
            if ($q) {
                $q->bind_param('i', $detailId);
                $q->execute();
                $row = $q->get_result()->fetch_assoc();
                $q->close();
                if ($row && !empty($row['receiving_item_detail_id'])) {
                    $rid = (int) $row['receiving_item_detail_id'];
                    $u = $db->prepare("UPDATE receiving_item_details SET is_distributed = 0 WHERE id = ?");
                    if ($u) { $u->bind_param('i', $rid); $u->execute(); $u->close(); }
                }
            }

            $success = 'Return recorded successfully.';
        } else {
            $errors[] = 'Unable to record return.';
        }
    }
}

$available = [];
$rows = [];
if ($db) {
    // items available to return: distributed details (is_distributed = 1) and not disposed
    $stmt = $db->prepare("SELECT did.id, did.property_number, did.brand, did.model, did.serial_no, d.document_no, o.office_name FROM distribution_item_details did INNER JOIN distribution_items di ON di.id = did.distribution_item_id INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' LEFT JOIN offices o ON o.id = d.office_id WHERE did.id IS NOT NULL AND did.id > 0 AND did.is_distributed = 1 AND (did.is_disposed IS NULL OR did.is_disposed = 0)");
    if ($stmt) {
        $stmt->execute();
        $available = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // list recent returns
    $rStmt = $db->prepare("SELECT rt.id, rt.system_reference, rt.return_date, rt.reason, rt.remarks, did.property_number, d.document_no, o.office_name FROM returns rt LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id LEFT JOIN distribution_items di ON di.id = did.distribution_item_id LEFT JOIN distributions d ON d.id = di.distribution_id LEFT JOIN offices o ON o.id = d.office_id ORDER BY rt.created_at DESC");
    if ($rStmt) {
        $rStmt->execute();
        $rows = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rStmt->close();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<div class="container">
    <h3>Returns</h3>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php echo h(implode('<br>', $errors)); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo h($success); ?></div>
    <?php endif; ?>

    <form method="post" class="mb-4">
        <?php echo csrf_input(); ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Item</label>
                <select name="distribution_item_detail_id" class="form-select form-select-sm">
                    <option value="">Select distributed item</option>
                    <?php foreach ($available as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>"><?php echo h($a['property_number'] ?: ($a['brand'] . ' ' . $a['model'])); ?> — <?php echo h($a['document_no'] ?? ''); ?> (<?php echo h($a['office_name'] ?? ''); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Return Date</label>
                <input type="date" name="return_date" class="form-control form-control-sm" value="<?php echo h(date('Y-m-d')); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Reason</label>
                <input name="reason" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary">Record Return</button>
            </div>
        </div>
    </form>

    <h5>Recent Returns</h5>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr><th>Ref</th><th>Date</th><th>Item</th><th>Doc</th><th>Office</th><th>Reason</th></tr>
            </thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $rr): ?>
                <tr>
                    <td><?php echo h($rr['system_reference']); ?></td>
                    <td><?php echo h(!empty($rr['return_date']) ? date('M d, Y', strtotime($rr['return_date'])) : ''); ?></td>
                    <td><?php echo h($rr['property_number'] ?? ''); ?></td>
                    <td><?php echo h($rr['document_no'] ?? ''); ?></td>
                    <td><?php echo h($rr['office_name'] ?? ''); ?></td>
                    <td><?php echo h($rr['reason'] ?? ''); ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6" class="text-center text-muted">No returns recorded.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
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
    $returnDate = trim($_POST['return_date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($detailId <= 0) $errors[] = 'Select an item to return.';
    if ($returnDate === '') $errors[] = 'Return date is required.';

    if (empty($errors)) {
        // Ensure returns table exists
        $db->query("CREATE TABLE IF NOT EXISTS returns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            system_reference VARCHAR(50) NOT NULL,
            return_date DATE NOT NULL,
            distribution_item_detail_id INT UNSIGNED NOT NULL,
            reason TEXT NULL,
            remarks TEXT NULL,
            status ENUM('posted','cancelled') DEFAULT 'posted',
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $systemRef = next_module_code($db, 'returns');
        $userId = current_user_id();
        $ins = $db->prepare("INSERT INTO returns (system_reference, return_date, distribution_item_detail_id, reason, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        if ($ins) {
            $ins->bind_param('ssis si', $systemRef, $returnDate, $detailId, $reason, $remarks, $userId);
            // correct types: s s i s s i -> fix bind param string
        }
        // Workaround: use proper bind with types
        if ($ins) {
            $ins->bind_param('ssissi', $systemRef, $returnDate, $detailId, $reason, $remarks, $userId);
            $ins->execute();
            $ins->close();

            // find receiving_item_detail_id and mark it as not distributed
            $q = $db->prepare("SELECT receiving_item_detail_id FROM distribution_item_details WHERE id = ? LIMIT 1");
            if ($q) {
                $q->bind_param('i', $detailId);
                $q->execute();
                $row = $q->get_result()->fetch_assoc();
                $q->close();
                if ($row && !empty($row['receiving_item_detail_id'])) {
                    $rid = (int) $row['receiving_item_detail_id'];
                    $u = $db->prepare("UPDATE receiving_item_details SET is_distributed = 0 WHERE id = ?");
                    if ($u) { $u->bind_param('i', $rid); $u->execute(); $u->close(); }
                }
            }

            $success = 'Return recorded successfully.';
        } else {
            $errors[] = 'Unable to record return.';
        }
    }
}

$available = [];
$rows = [];
if ($db) {
    // items available to return: distributed details (is_distributed = 1)
    $stmt = $db->prepare("SELECT did.id, did.property_number, did.brand, did.model, did.serial_no, d.document_no, o.office_name FROM distribution_item_details did INNER JOIN distribution_items di ON di.id = did.distribution_item_id INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' LEFT JOIN offices o ON o.id = d.office_id WHERE did.id IS NOT NULL AND did.id > 0 AND did.is_distributed = 1 AND (did.is_disposed IS NULL OR did.is_disposed = 0)");
    if ($stmt) {
        $stmt->execute();
        $available = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // list recent returns
    $rStmt = $db->prepare("SELECT rt.id, rt.system_reference, rt.return_date, rt.reason, rt.remarks, did.property_number, d.document_no, o.office_name FROM returns rt LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id LEFT JOIN distribution_items di ON di.id = did.distribution_item_id LEFT JOIN distributions d ON d.id = di.distribution_id LEFT JOIN offices o ON o.id = d.office_id ORDER BY rt.created_at DESC");
    if ($rStmt) {
        $rStmt->execute();
        $rows = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rStmt->close();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<div class="container">
    <h3>Returns</h3>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php echo h(implode('<br>', $errors)); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo h($success); ?></div>
    <?php endif; ?>

    <form method="post" class="mb-4">
        <?php echo csrf_input(); ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Item</label>
                <select name="distribution_item_detail_id" class="form-select form-select-sm">
                    <option value="">Select distributed item</option>
                    <?php foreach ($available as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>"><?php echo h($a['property_number'] ?: ($a['brand'] . ' ' . $a['model'])); ?> — <?php echo h($a['document_no'] ?? ''); ?> (<?php echo h($a['office_name'] ?? ''); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Return Date</label>
                <input type="date" name="return_date" class="form-control form-control-sm" value="<?php echo h(date('Y-m-d')); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Reason</label>
                <input name="reason" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary">Record Return</button>
            </div>
        </div>
    </form>

    <h5>Recent Returns</h5>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr><th>Ref</th><th>Date</th><th>Item</th><th>Doc</th><th>Office</th><th>Reason</th></tr>
            </thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $rr): ?>
                <tr>
                    <td><?php echo h($rr['system_reference']); ?></td>
                    <td><?php echo h(!empty($rr['return_date']) ? date('M d, Y', strtotime($rr['return_date'])) : ''); ?></td>
                    <td><?php echo h($rr['property_number'] ?? ''); ?></td>
                    <td><?php echo h($rr['document_no'] ?? ''); ?></td>
                    <td><?php echo h($rr['office_name'] ?? ''); ?></td>
                    <td><?php echo h($rr['reason'] ?? ''); ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6" class="text-center text-muted">No returns recorded.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$page_title = 'Returns';
$flash = get_flash();
$errors = [];
$returns = [];
$issuances = [];
$issuanceItems = [];
$form = [
    'system_reference' => '',
    'return_date' => date('Y-m-d'),
    'issuance_id' => '',
    'remarks' => '',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $form['system_reference'] = preview_module_code($db, 'returns');

    $issResult = $db->query("SELECT i.id, i.system_reference, i.issuance_date, o.office_name FROM issuances i INNER JOIN offices o ON o.id = i.office_id ORDER BY i.issuance_date DESC, i.id DESC");
    if ($issResult) {
        $issuances = $issResult->fetch_all(MYSQLI_ASSOC);
    }

    // If an issuance is selected, load its items
    $selectedIssuanceId = isset($_GET['issuance_id']) ? (int) $_GET['issuance_id'] : 0;
    if ($selectedIssuanceId > 0) {
        $itemStmt = $db->prepare("SELECT ii.id, ii.stock_item_id, ii.quantity_issued, si.system_reference, si.item_description, si.quantity_on_hand FROM issuance_items ii INNER JOIN stock_items si ON si.id = ii.stock_item_id WHERE ii.issuance_id = ? ORDER BY ii.id ASC");
        if ($itemStmt) {
            $itemStmt->bind_param('i', $selectedIssuanceId);
            $itemStmt->execute();
            $res = $itemStmt->get_result();
            if ($res) {
                $issuanceItems = $res->fetch_all(MYSQLI_ASSOC);
            }
            $itemStmt->close();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['system_reference'] = preview_module_code($db, 'returns');
        $form['return_date'] = old($_POST, 'return_date', date('Y-m-d'));
        $form['issuance_id'] = old($_POST, 'issuance_id');
        $form['remarks'] = old($_POST, 'remarks');

        if ($form['return_date'] === '') {
            $errors[] = 'Return date is required.';
        }
        if ($form['issuance_id'] === '') {
            $errors[] = 'Issuance is required.';
        }

        $issuanceId = (int) ($form['issuance_id'] !== '' ? $form['issuance_id'] : 0);

        $postedItems = $_POST['items'] ?? [];
        $validatedItems = [];

        foreach ($postedItems as $issuanceItemId => $posted) {
            $issuanceItemId = (int) $issuanceItemId;
            $returnQty = isset($posted['return_quantity']) ? (float) $posted['return_quantity'] : 0;
            $lineRemarks = trim((string) ($posted['remarks'] ?? ''));

            if ($returnQty <= 0) {
                continue;
            }

            // fetch issuance_item to validate
            $iiStmt = $db->prepare("SELECT id, stock_item_id, quantity_issued FROM issuance_items WHERE id = ? LIMIT 1");
            if (!$iiStmt) {
                $errors[] = 'Unable to validate issuance item.';
                break;
            }
            $iiStmt->bind_param('i', $issuanceItemId);
            $iiStmt->execute();
            $iiRes = $iiStmt->get_result();
            $iiRow = $iiRes ? $iiRes->fetch_assoc() : null;
            $iiStmt->close();

            if (!$iiRow) {
                $errors[] = 'Invalid issuance item selected.';
                continue;
            }

            $available = (float) $iiRow['quantity_issued'];
            if ($returnQty > $available) {
                $errors[] = 'Return quantity cannot exceed issued quantity for issuance item ID ' . $issuanceItemId . '.';
                continue;
            }

            $validatedItems[] = [
                'issuance_item_id' => $issuanceItemId,
                'stock_item_id' => (int) $iiRow['stock_item_id'],
                'return_quantity' => $returnQty,
                'remarks' => $lineRemarks,
            ];
        }

        if (empty($validatedItems)) {
            $errors[] = 'Enter at least one quantity to return.';
        }

        if (empty($errors)) {
            $db->begin_transaction();
            try {
                $systemReference = next_module_code($db, 'returns');
                $userId = current_user_id();

                $headerStmt = $db->prepare("INSERT INTO returns (system_reference, return_date, issuance_id, remarks, created_by) VALUES (?, ?, ?, ?, ?)");
                $itemStmt = $db->prepare("INSERT INTO return_items (return_id, issuance_item_id, stock_item_id, quantity_returned, remarks) VALUES (?, ?, ?, ?, ?)");
                $stockUpdateStmt = $db->prepare("UPDATE stock_items SET quantity_on_hand = quantity_on_hand + ?, quantity_issued = quantity_issued - ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $movementStmt = $db->prepare("INSERT INTO stock_movements (stock_item_id, movement_type, movement_date, reference_type, reference_id, quantity_in, quantity_out, balance_after, remarks, created_by) VALUES (?, 'return', ?, 'return', ?, ?, 0.00, ?, ?, ?)");

                if (!$headerStmt || !$itemStmt || !$stockUpdateStmt || !$movementStmt) {
                    throw new RuntimeException('Unable to prepare return statements.');
                }

                $headerStmt->bind_param('siiis', $systemReference, $form['return_date'], $issuanceId, $form['remarks'], $userId);
                $headerStmt->execute();
                $returnId = (int) $headerStmt->insert_id;
                $headerStmt->close();

                foreach ($validatedItems as $item) {
                    // get current on hand for balance calculation
                    $qStmt = $db->prepare("SELECT quantity_on_hand FROM stock_items WHERE id = ? LIMIT 1");
                    $qStmt->bind_param('i', $item['stock_item_id']);
                    $qStmt->execute();
                    $qRes = $qStmt->get_result();
                    $qRow = $qRes ? $qRes->fetch_assoc() : null;
                    $qStmt->close();
                    $before = $qRow ? (int) $qRow['quantity_on_hand'] : 0;

                    $itemStmt->bind_param('iiids', $returnId, $item['issuance_item_id'], $item['stock_item_id'], $item['return_quantity'], $item['remarks']);
                    $itemStmt->execute();

                    $stockUpdateStmt->bind_param('diii', $item['return_quantity'], $item['return_quantity'], $userId, $item['stock_item_id']);
                    $stockUpdateStmt->execute();

                    $balanceAfter = $before + $item['return_quantity'];
                    $movementStmt->bind_param('isidssi', $item['stock_item_id'], $form['return_date'], $returnId, $item['return_quantity'], $balanceAfter, $item['remarks'], $userId);
                    $movementStmt->execute();
                }

                $movementStmt->close();
                $stockUpdateStmt->close();
                $itemStmt->close();
                $db->commit();
                set_flash('success', 'Return posted successfully.');
                redirect('modules/returns/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to save the return.';
            }
        }
    }

    $returnResult = $db->query("SELECT r.id, r.system_reference, r.return_date, o.office_name, i.system_reference AS issuance_reference FROM returns r INNER JOIN issuances i ON i.id = r.issuance_id INNER JOIN offices o ON o.id = i.office_id ORDER BY r.return_date DESC, r.id DESC");
    if ($returnResult) {
        $returns = $returnResult->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="card-title mb-3">Encode Return</h5>
                <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>

                <form method="post">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">System Reference</label>
                            <input type="text" class="form-control" value="<?php echo h($form['system_reference']); ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label for="return_date" class="form-label">Return Date</label>
                            <input type="date" class="form-control" id="return_date" name="return_date" value="<?php echo h($form['return_date']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="issuance_id" class="form-label">Select Issuance</label>
                            <select class="form-select" id="issuance_id" name="issuance_id" required onchange="if(this.value) location.href='?issuance_id='+this.value;">
                                <option value="">Select issuance</option>
                                <?php foreach ($issuances as $iss): ?>
                                    <option value="<?php echo (int) $iss['id']; ?>" <?php echo $selectedIssuanceId === (int) $iss['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($iss['system_reference'] . ' - ' . date('M d, Y', strtotime($iss['issuance_date'])) . ' | ' . $iss['office_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive mb-4">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Issuance Item</th>
                                    <th class="text-end">Quantity Issued</th>
                                    <th class="text-end">On Hand</th>
                                    <th style="width: 150px;">Return Qty</th>
                                    <th style="width: 220px;">Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($issuanceItems): ?>
                                    <?php foreach ($issuanceItems as $it): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo h($it['system_reference']); ?></div>
                                                <div><?php echo h($it['item_description']); ?></div>
                                            </td>
                                            <td class="text-end"><?php echo h(number_format((float) $it['quantity_issued'], 2)); ?></td>
                                            <td class="text-end fw-semibold"><?php echo h(number_format((float) $it['quantity_on_hand'], 2)); ?></td>
                                            <td>
                                                <input type="number" class="form-control form-control-sm" step="0.01" min="0" max="<?php echo h((string) $it['quantity_issued']); ?>" name="items[<?php echo (int) $it['id']; ?>][return_quantity]" value="0.00">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="items[<?php echo (int) $it['id']; ?>][remarks]" value="">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">Select an issuance to list its items for return.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary" <?php echo $issuanceItems ? '' : 'disabled'; ?>>Post Return</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Return Records</h5>
                    <span class="badge text-bg-light"><?php echo count($returns); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Office</th>
                                <th>Issuance Ref</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($returns): ?>
                                <?php foreach ($returns as $r): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($r['system_reference']); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($r['return_date']))); ?></td>
                                        <td><?php echo h($r['office_name']); ?></td>
                                        <td class="fw-semibold"><?php echo h($r['issuance_reference']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No return records yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<?php
require_once __DIR__ . '/../../app/config/init.php';
if (empty($_SESSION['user_id'])) { header('Location: ../../auth/login.php'); exit; }
$page_title = 'Returns';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<main class="py-4 container">
  <h2>Returns</h2>
  <p>Module placeholder — asset returns management.</p>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
