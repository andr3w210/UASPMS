<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_once __DIR__ . '/../../app/helpers/suppliers.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$errors = [];
$recordId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $recordId > 0;
$page_title = $isEdit ? 'Edit Supplier' : 'Add Supplier';

$form = ['id' => 0, 'supplier_code' => '', 'supplier_name' => '', 'contact_person' => '', 'contact_no' => '', 'email' => '', 'address' => '', 'tin_no' => '', 'is_active' => 1];
$existing = null;
if ($isEdit) {
    $existing = suppliers_fetch($db, $recordId);
    if (!$existing) {
        set_flash('error', 'Supplier not found.');
        redirect('modules/suppliers/index.php');
    }
    foreach ($form as $key => $default) {
        $form[$key] = $existing[$key] ?? $default;
    }
}
$generatedCode = $isEdit ? '' : preview_module_code($db, 'suppliers');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    request_schema_enforce([
        'save' => [
            'id' => ['type' => 'int', 'min' => 0],
            'supplier_name' => ['type' => 'string', 'max' => 255, 'required' => true],
            'contact_person' => ['type' => 'string', 'max' => 150],
            'contact_no' => ['type' => 'string', 'max' => 60],
            'email' => ['type' => 'string', 'max' => 150],
            'tin_no' => ['type' => 'string', 'max' => 60],
            'address' => ['type' => 'string', 'max' => 500],
            'is_active' => ['type' => 'bool'],
        ],
    ], 'action', false, 'save');
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        foreach (['supplier_name', 'contact_person', 'contact_no', 'email', 'tin_no', 'address'] as $key) {
            $form[$key] = trim((string) ($_POST[$key] ?? ''));
        }
        $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        if ($form['supplier_name'] === '') {
            $errors[] = 'Supplier name is required.';
        }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is not valid.';
        }
        $code = $isEdit ? (string) $existing['supplier_code'] : $generatedCode;
        if (!$errors && suppliers_name_exists($db, $code, $form['supplier_name'], $recordId)) {
            $errors[] = 'Supplier code or supplier name already exists.';
        }
        if (!$errors) {
            $userId = current_user_id();
            $isActive = (int) $form['is_active'];
            if ($isEdit) {
                $stmt = $db->prepare("UPDATE suppliers SET supplier_name = ?, contact_person = ?, contact_no = ?, email = ?, address = ?, tin_no = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('ssssssiii', $form['supplier_name'], $form['contact_person'], $form['contact_no'], $form['email'], $form['address'], $form['tin_no'], $isActive, $userId, $recordId);
                $ok = $stmt->execute();
                $stmt->close();
                $savedId = $recordId;
            } else {
                $code = next_module_code($db, 'suppliers');
                $stmt = $db->prepare("INSERT INTO suppliers (supplier_code, supplier_name, contact_person, contact_no, email, address, tin_no, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssssii', $code, $form['supplier_name'], $form['contact_person'], $form['contact_no'], $form['email'], $form['address'], $form['tin_no'], $isActive, $userId);
                $ok = $stmt->execute();
                $savedId = (int) $stmt->insert_id;
                $stmt->close();
            }
            if ($ok) {
                write_audit_log($db, [
                    'action' => $isEdit ? 'update' : 'insert',
                    'table_name' => 'suppliers',
                    'record_id' => $savedId,
                    'module_name' => 'suppliers',
                    'record_type' => 'supplier',
                    'action_name' => $isEdit ? 'update_supplier' : 'create_supplier',
                    'description' => $isEdit ? 'Updated supplier record.' : 'Created supplier record.',
                    'old_values' => $existing ? suppliers_audit_snapshot($existing) : null,
                    'new_values' => suppliers_audit_snapshot($form + ['supplier_code' => $code]),
                ]);
                set_flash('success', $isEdit ? 'Supplier updated.' : 'Supplier created.');
                redirect('modules/suppliers/view.php?id=' . $savedId);
            }
            $errors[] = 'Unable to save the supplier.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1"><?php echo h($page_title); ?></h4>
            <div class="text-muted">Keep one clean record per legal entity so procurement history is not split.</div>
        </div>
        <a href="<?php echo base_url($isEdit ? 'modules/suppliers/view.php?id=' . $recordId : 'modules/suppliers/index.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int) $recordId; ?>">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><h5 class="card-title mb-0">Supplier details</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Supplier code</label>
                        <input type="text" class="form-control" value="<?php echo h($isEdit ? $form['supplier_code'] : $generatedCode); ?>" readonly>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Supplier name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="supplier_name" value="<?php echo h($form['supplier_name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">TIN no.</label>
                        <input type="text" class="form-control" name="tin_no" value="<?php echo h($form['tin_no']); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"><?php echo h($form['address']); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="card shadow-sm mb-3">
            <div class="card-header"><h5 class="card-title mb-0">Contact</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Contact person</label>
                        <input type="text" class="form-control" name="contact_person" value="<?php echo h($form['contact_person']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contact number</label>
                        <input type="text" class="form-control" name="contact_no" value="<?php echo h($form['contact_no']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?php echo h($form['email']); ?>">
                    </div>
                </div>
            </div>
        </div>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" <?php echo $form['is_active'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="isActive">Active supplier</label>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <a href="<?php echo base_url($isEdit ? 'modules/suppliers/view.php?id=' . $recordId : 'modules/suppliers/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary px-4"><?php echo $isEdit ? 'Save Changes' : 'Save Supplier'; ?></button>
        </div>
    </form>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
