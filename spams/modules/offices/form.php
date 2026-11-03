<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$errors = [];
$recordId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $recordId > 0;
$page_title = $isEdit ? 'Edit Office' : 'Add Office';
$snapshotFields = ['office_code', 'office_name', 'office_head_employee_id', 'description', 'is_active'];

$form = ['office_code' => '', 'office_name' => '', 'description' => '', 'is_active' => 1];
if ($isEdit) {
    $stmt = $db->prepare("SELECT office_code, office_name, description, is_active FROM offices WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        set_flash('error', 'Office not found.');
        redirect('modules/offices/index.php');
    }
    $form = array_merge($form, array_map(static fn($v) => $v ?? '', $row));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    request_schema_enforce([
        'save' => [
            'id' => ['type' => 'int', 'min' => 0],
            'office_code' => ['type' => 'string', 'max' => 50, 'required' => true],
            'office_name' => ['type' => 'string', 'max' => 255, 'required' => true],
            'description' => ['type' => 'string', 'max' => 1000],
            'is_active' => ['type' => 'bool'],
        ],
    ], 'action', false, 'save');
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $form['office_code'] = strtoupper(trim((string) ($_POST['office_code'] ?? '')));
        $form['office_name'] = trim((string) ($_POST['office_name'] ?? ''));
        $form['description'] = trim((string) ($_POST['description'] ?? ''));
        $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        if ($form['office_code'] === '') {
            $errors[] = 'Office code is required.';
        }
        if ($form['office_name'] === '') {
            $errors[] = 'Office name is required.';
        }
        $stmt = $db->prepare("SELECT id FROM offices WHERE (office_code = ? OR office_name = ?) AND id != ? LIMIT 1");
        $stmt->bind_param('ssi', $form['office_code'], $form['office_name'], $recordId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'Office code or office name already exists.';
        }
        $stmt->close();

        if (!$errors) {
            $userId = current_user_id();
            $isActive = (int) $form['is_active'];
            if ($isEdit) {
                $before = audit_fetch_row_snapshot($db, 'offices', $recordId, $snapshotFields);
                $stmt = $db->prepare("UPDATE offices SET office_code = ?, office_name = ?, department_id = NULL, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('sssiii', $form['office_code'], $form['office_name'], $form['description'], $isActive, $userId, $recordId);
                $ok = $stmt->execute();
                $stmt->close();
                $savedId = $recordId;
            } else {
                $before = null;
                $stmt = $db->prepare("INSERT INTO offices (office_code, office_name, department_id, office_head_employee_id, description, is_active, created_by) VALUES (?, ?, NULL, NULL, ?, ?, ?)");
                $stmt->bind_param('sssii', $form['office_code'], $form['office_name'], $form['description'], $isActive, $userId);
                $ok = $stmt->execute();
                $savedId = (int) $stmt->insert_id;
                $stmt->close();
            }
            if ($ok) {
                write_audit_log($db, [
                    'action' => $isEdit ? 'update' : 'insert',
                    'table_name' => 'offices',
                    'record_id' => $savedId,
                    'module_name' => 'offices',
                    'record_type' => 'office',
                    'action_name' => $isEdit ? 'update_office' : 'create_office',
                    'description' => $isEdit ? 'Updated office record.' : 'Created office record.',
                    'old_values' => $before,
                    'new_values' => [
                        'office_code' => $form['office_code'],
                        'office_name' => $form['office_name'],
                        'description' => $form['description'],
                        'is_active' => $isActive,
                    ],
                ]);
                set_flash('success', $isEdit ? 'Office updated.' : 'Office created.');
                redirect('modules/offices/view.php?id=' . $savedId);
            }
            $errors[] = 'Unable to save the office.';
        }
    }
}

$backUrl = base_url($isEdit ? 'modules/offices/view.php?id=' . $recordId : 'modules/offices/index.php');
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1"><?php echo h($page_title); ?></h4>
            <div class="text-muted">The office head comes from the employee designation marked Unit Head.</div>
        </div>
        <a href="<?php echo $backUrl; ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo $recordId; ?>">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><h5 class="card-title mb-0">Office details</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Office code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="office_code" value="<?php echo h($form['office_code']); ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Office name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="office_name" value="<?php echo h($form['office_name']); ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" <?php echo $form['is_active'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="isActive">Active office</label>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <a href="<?php echo $backUrl; ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary px-4"><?php echo $isEdit ? 'Save Changes' : 'Save Office'; ?></button>
        </div>
    </form>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
