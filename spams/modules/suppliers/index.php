<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db_connect();
$page_title = 'Suppliers';
$flash = get_flash();
$errors = [];
$suppliers = [];
$form = [
    'id' => 0,
    'supplier_code' => '',
    'supplier_name' => '',
    'contact_person' => '',
    'contact_no' => '',
    'email' => '',
    'address' => '',
    'tin_no' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'suppliers');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['supplier_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'supplier_code')) : $generatedCode;
            $form['supplier_name'] = old($_POST, 'supplier_name');
            $form['contact_person'] = old($_POST, 'contact_person');
            $form['contact_no'] = old($_POST, 'contact_no');
            $form['email'] = old($_POST, 'email');
            $form['address'] = old($_POST, 'address');
            $form['tin_no'] = old($_POST, 'tin_no');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['supplier_name'] === '') {
                $errors[] = 'Supplier name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM suppliers WHERE (supplier_code = ? OR supplier_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['supplier_code'], $form['supplier_name'], $recordId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Supplier code or supplier name already exists.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE suppliers SET supplier_code = ?, supplier_name = ?, contact_person = ?, contact_no = ?, email = ?, address = ?, tin_no = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('sssssssiii', $form['supplier_code'], $form['supplier_name'], $form['contact_person'], $form['contact_no'], $form['email'], $form['address'], $form['tin_no'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Supplier updated successfully.');
                        redirect('modules/suppliers/index.php');
                    }
                } else {
                    $form['supplier_code'] = next_module_code($db, 'suppliers');
                    $stmt = $db->prepare("INSERT INTO suppliers (supplier_code, supplier_name, contact_person, contact_no, email, address, tin_no, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('sssssssii', $form['supplier_code'], $form['supplier_name'], $form['contact_person'], $form['contact_no'], $form['email'], $form['address'], $form['tin_no'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Supplier created successfully.');
                        redirect('modules/suppliers/index.php');
                    }
                }

                $errors[] = 'Unable to save the supplier.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            if ($recordId > 0) {
                $userId = current_user_id();
                $stmt = $db->prepare("UPDATE suppliers SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $userId, $recordId);
                    $stmt->execute();
                    $stmt->close();
                    set_flash('success', 'Supplier deactivated successfully.');
                    redirect('modules/suppliers/index.php');
                }
            }

            $errors[] = 'Unable to deactivate the supplier.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, supplier_code, supplier_name, contact_person, contact_no, email, address, tin_no, is_active FROM suppliers WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'supplier_code' => $record['supplier_code'],
                    'supplier_name' => $record['supplier_name'],
                    'contact_person' => $record['contact_person'] ?? '',
                    'contact_no' => $record['contact_no'] ?? '',
                    'email' => $record['email'] ?? '',
                    'address' => $record['address'] ?? '',
                    'tin_no' => $record['tin_no'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("
        SELECT s.id, s.supplier_code, s.supplier_name, s.contact_person, s.contact_no, s.email, s.address, s.tin_no, s.is_active, s.created_at,
               creator.full_name AS creator_name
        FROM suppliers s
        LEFT JOIN users creator ON creator.id = s.created_by
        ORDER BY s.supplier_name ASC
    ");
    if ($result) {
        $suppliers = $result->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Supplier' : 'Add Supplier'; ?></h5>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo h($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>">
                        <?php echo h($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">

                    <div class="mb-3">
                        <label for="supplier_code" class="form-label">Supplier Code</label>
                        <input type="text" class="form-control" id="supplier_code" name="supplier_code" maxlength="50" value="<?php echo h($form['id'] > 0 ? $form['supplier_code'] : $generatedCode); ?>" readonly>
                        <div class="form-text">Generated automatically using `SUP-YYYY-0001` format.</div>
                    </div>

                    <div class="mb-3">
                        <label for="supplier_name" class="form-label">Supplier Name</label>
                        <input type="text" class="form-control" id="supplier_name" name="supplier_name" maxlength="150" value="<?php echo h($form['supplier_name']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="contact_person" class="form-label">Contact Person</label>
                        <input type="text" class="form-control" id="contact_person" name="contact_person" maxlength="150" value="<?php echo h($form['contact_person']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="contact_no" class="form-label">Contact Number</label>
                        <input type="text" class="form-control" id="contact_no" name="contact_no" maxlength="50" value="<?php echo h($form['contact_no']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" maxlength="150" value="<?php echo h($form['email']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="tin_no" class="form-label">TIN No.</label>
                        <input type="text" class="form-control" id="tin_no" name="tin_no" maxlength="50" value="<?php echo h($form['tin_no']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="3"><?php echo h($form['address']); ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active supplier</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?>
                            <a href="<?php echo base_url('modules/suppliers/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Supplier List</h5>
                    <span class="badge text-bg-light"><?php echo count($suppliers); ?> record(s)</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Supplier</th>
                                <th>Contact</th>
                                <th>TIN</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($suppliers)): ?>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($supplier['supplier_code']); ?></td>
                                        <td>
                                            <div><?php echo h($supplier['supplier_name']); ?></div>
                                            <small class="text-muted"><?php echo h($supplier['address'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo h($supplier['contact_person'] ?? ''); ?></div>
                                            <small class="text-muted"><?php echo h(trim(($supplier['contact_no'] ?? '') . (($supplier['contact_no'] ?? '') && ($supplier['email'] ?? '') ? ' | ' : '') . ($supplier['email'] ?? ''))); ?></small>
                                        </td>
                                        <td><?php echo h($supplier['tin_no'] ?? ''); ?></td>
                                        <td>
                                            <span class="badge rounded-pill <?php echo (int) $supplier['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>">
                                                <?php echo (int) $supplier['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo h(date('M d, Y', strtotime($supplier['created_at']))); ?></div>
                                            <small class="text-muted"><?php echo h($supplier['creator_name'] ?: 'System'); ?></small>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="<?php echo base_url('modules/suppliers/index.php?edit=' . (int) $supplier['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if ((int) $supplier['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this supplier?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $supplier['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No suppliers found yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
