<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/employees.php';
require_login();
require_role('Administrator', 'Transport Officer');

$db = db();
$errors = [];
$recordId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $recordId > 0;
$page_title = $isEdit ? 'Edit Employee' : 'Add Employee';

$blank = ['id' => 0, 'employee_no' => '', 'name_prefix' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'suffix_name' => '', 'sex' => '', 'birth_date' => '', 'email' => '', 'contact_no' => '', 'address' => '', 'emergency_contact_name' => '', 'emergency_contact_no' => '', 'employment_status' => '', 'date_hired' => '', 'is_driver' => 0, 'is_active' => 1, 'photo_path' => ''];
$form = $blank;
$init = ['office_id' => '', 'role_title' => '', 'is_unit_head' => '0'];

$offices = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC")->fetch_all(MYSQLI_ASSOC);
$titles = array_column($db->query("SELECT DISTINCT role_title FROM employee_assignments WHERE role_title <> '' ORDER BY role_title ASC")->fetch_all(MYSQLI_ASSOC), 'role_title');
$statusOptions = employees_recommended_statuses();

if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        set_flash('error', 'Employee not found.');
        redirect('modules/employees/index.php');
    }
    foreach ($blank as $key => $default) {
        $form[$key] = $row[$key] ?? $default;
    }
    foreach ($form as $key => $value) {
        if ($value === null) {
            $form[$key] = '';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    request_schema_enforce([
        'save' => [
            'id' => ['type' => 'int', 'min' => 0],
            'name_prefix' => ['type' => 'string', 'max' => 20],
            'first_name' => ['type' => 'string', 'max' => 100],
            'middle_name' => ['type' => 'string', 'max' => 100],
            'last_name' => ['type' => 'string', 'max' => 100],
            'suffix_name' => ['type' => 'string', 'max' => 20],
            'sex' => ['type' => 'string', 'max' => 10],
            'birth_date' => ['type' => 'date'],
            'email' => ['type' => 'string', 'max' => 150],
            'contact_no' => ['type' => 'string', 'max' => 40],
            'address' => ['type' => 'string', 'max' => 255],
            'emergency_contact_name' => ['type' => 'string', 'max' => 150],
            'emergency_contact_no' => ['type' => 'string', 'max' => 40],
            'employment_status' => ['type' => 'string', 'max' => 60],
            'date_hired' => ['type' => 'date'],
            'is_driver' => ['type' => 'bool'],
            'is_active' => ['type' => 'bool'],
            'remove_photo' => ['type' => 'bool'],
            'init_office_id' => ['type' => 'int', 'min' => 0],
            'init_role_title' => ['type' => 'string', 'max' => 150],
            'init_is_unit_head' => ['type' => 'bool'],
        ],
    ], 'action', false, 'save');

    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        foreach (['name_prefix', 'first_name', 'middle_name', 'last_name', 'suffix_name', 'sex', 'birth_date', 'email', 'contact_no', 'address', 'emergency_contact_name', 'emergency_contact_no', 'date_hired'] as $field) {
            $form[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $form['employment_status'] = employees_normalize_status((string) ($_POST['employment_status'] ?? ''));
        $form['is_driver'] = isset($_POST['is_driver']) ? 1 : 0;
        $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        $init = [
            'office_id' => (string) (int) ($_POST['init_office_id'] ?? 0),
            'role_title' => trim((string) ($_POST['init_role_title'] ?? '')),
            'is_unit_head' => isset($_POST['init_is_unit_head']) ? '1' : '0',
        ];

        if ($form['first_name'] === '') {
            $errors[] = 'First name is required.';
        }
        if ($form['last_name'] === '') {
            $errors[] = 'Last name is required.';
        }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (employees_email_exists($db, $form['email'], $recordId)) {
            $errors[] = 'Email already exists.';
        }
        if (!in_array($form['sex'], ['', 'Male', 'Female'], true)) {
            $errors[] = 'Invalid sex value.';
        }
        if (!$isEdit && ($init['office_id'] === '0') !== ($init['role_title'] === '')) {
            $errors[] = 'Enter both the office and the designation, or leave both blank.';
        }

        $photoPath = (string) $form['photo_path'];
        $previousPhoto = $photoPath;
        if (!$errors && !empty($_FILES['photo']['name'])) {
            $stored = store_uploaded_image($_FILES['photo'], 'employees', $errors);
            if ($stored !== null) {
                $photoPath = $stored;
            }
        } elseif (isset($_POST['remove_photo'])) {
            $photoPath = '';
        }

        if (!$errors) {
            $userId = current_user_id();
            $f = $form;
            $nullable = static fn(string $v): ?string => $v === '' ? null : $v;
            $sex = $nullable($f['sex']);
            $birth = $nullable($f['birth_date']);
            $hired = $nullable($f['date_hired']);
            $address = $nullable($f['address']);
            $contact = $nullable($f['contact_no']);
            $ecName = $nullable($f['emergency_contact_name']);
            $ecNo = $nullable($f['emergency_contact_no']);
            $email = $nullable($f['email']);
            $isDriver = (int) $f['is_driver'];
            $isActive = (int) $f['is_active'];

            if ($isEdit) {
                $before = employees_audit_snapshot($db, $recordId);
                $stmt = $db->prepare("UPDATE employees SET name_prefix = ?, first_name = ?, middle_name = ?, last_name = ?, suffix_name = ?, sex = ?, birth_date = ?, email = ?, contact_no = ?, address = ?, emergency_contact_name = ?, emergency_contact_no = ?, employment_status = ?, date_hired = ?, is_driver = ?, is_active = ?, photo_path = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('ssssssssssssssiisii', $f['name_prefix'], $f['first_name'], $f['middle_name'], $f['last_name'], $f['suffix_name'], $sex, $birth, $email, $contact, $address, $ecName, $ecNo, $f['employment_status'], $hired, $isDriver, $isActive, $photoPath, $userId, $recordId);
                $ok = $stmt->execute();
                $dbError = $stmt->error;
                $stmt->close();
                if ($ok) {
                    if ($previousPhoto !== '' && $previousPhoto !== $photoPath) {
                        delete_uploaded_file($previousPhoto);
                    }
                    write_audit_log($db, ['action' => 'update', 'table_name' => 'employees', 'record_id' => $recordId, 'module_name' => 'employees', 'record_type' => 'employee', 'action_name' => 'update_employee', 'description' => 'Updated employee record.', 'old_values' => $before, 'new_values' => employees_audit_snapshot($db, $recordId)]);
                    set_flash('success', 'Employee updated.');
                    redirect('modules/employees/view.php?id=' . $recordId);
                }
                error_log('Employee update failed: ' . $dbError);
                $errors[] = 'Unable to update the employee.';
            } else {
                $employeeNo = '';
                for ($i = 0; $i < 25; $i++) {
                    $candidate = next_module_code($db, 'employees');
                    if ($candidate !== '' && !employees_employee_no_exists($db, $candidate)) {
                        $employeeNo = $candidate;
                        break;
                    }
                }
                if ($employeeNo === '') {
                    $errors[] = 'Unable to generate a unique employee number.';
                } else {
                    $stmt = $db->prepare("INSERT INTO employees (employee_no, name_prefix, first_name, middle_name, last_name, suffix_name, sex, birth_date, email, contact_no, address, emergency_contact_name, emergency_contact_no, employment_status, date_hired, is_driver, is_active, photo_path, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sssssssssssssssiisi', $employeeNo, $f['name_prefix'], $f['first_name'], $f['middle_name'], $f['last_name'], $f['suffix_name'], $sex, $birth, $email, $contact, $address, $ecName, $ecNo, $f['employment_status'], $hired, $isDriver, $isActive, $photoPath, $userId);
                    $ok = $stmt->execute();
                    $dbError = $stmt->error;
                    $newId = (int) $stmt->insert_id;
                    $stmt->close();
                    if ($ok && $newId > 0) {
                        $designationWarning = '';
                        if ($init['role_title'] !== '') {
                            $designationErrors = employees_save_designation($db, $newId, ['office_id' => $init['office_id'], 'role_title' => $init['role_title'], 'is_unit_head' => $init['is_unit_head'] === '1', 'is_primary' => true], $userId);
                            $designationWarning = implode(' ', $designationErrors);
                        }
                        write_audit_log($db, ['action' => 'insert', 'table_name' => 'employees', 'record_id' => $newId, 'module_name' => 'employees', 'record_type' => 'employee', 'action_name' => 'create_employee', 'description' => 'Created employee record.', 'new_values' => employees_audit_snapshot($db, $newId)]);
                        if ($designationWarning !== '') {
                            set_flash('error', 'Employee added, but the designation was not saved: ' . $designationWarning);
                        } else {
                            set_flash('success', 'Employee added. More designations can be added from the profile.');
                        }
                        redirect('modules/employees/view.php?id=' . $newId);
                    }
                    error_log('Employee insert failed: ' . $dbError);
                    $errors[] = 'Unable to add the employee.';
                }
            }
        }
    }
}

if ($form['employment_status'] !== '' && !in_array($form['employment_status'], $statusOptions, true)) {
    $statusOptions[] = $form['employment_status'];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1"><?php echo h($page_title); ?></h4>
            <div class="text-muted"><?php echo $isEdit ? 'Update personal, contact and employment details. Designations are managed on the profile page.' : 'Enter the employee details. More designations can be added from the profile afterwards.'; ?></div>
        </div>
        <a href="<?php echo base_url($isEdit ? 'modules/employees/view.php?id=' . $recordId : 'modules/employees/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <form method="post" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int) $recordId; ?>">

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header"><h5 class="card-title mb-0">Profile Photo</h5></div>
                    <div class="card-body text-center">
                        <div id="employeePhotoPreviewContainer" class="mb-3">
                            <?php echo employees_avatar_html($form, 120); ?>
                        </div>
                        <input type="file" class="form-control" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="form-text">JPG, PNG, GIF or WebP, up to 5 MB.</div>
                        <?php if ($form['photo_path'] !== ''): ?>
                            <div class="form-check mt-2 d-inline-block text-start">
                                <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="remove_photo">
                                <label class="form-check-label" for="remove_photo">Remove current photo</label>
                            </div>
                        <?php endif; ?>
                        <hr>
                        <div class="form-check form-switch text-start">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" <?php echo $form['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active employee</label>
                        </div>
                        <div class="form-check form-switch text-start mt-2">
                            <input class="form-check-input" type="checkbox" name="is_driver" value="1" id="is_driver" <?php echo $form['is_driver'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_driver">Official driver (appears on trip tickets)</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card shadow-sm mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Personal Information</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-2"><label class="form-label">Prefix</label><input class="form-control" name="name_prefix" maxlength="20" placeholder="Dr." value="<?php echo h($form['name_prefix']); ?>"></div>
                            <div class="col-md-4"><label class="form-label">First name <span class="text-danger">*</span></label><input class="form-control" name="first_name" maxlength="100" required value="<?php echo h($form['first_name']); ?>"></div>
                            <div class="col-md-3"><label class="form-label">Middle name</label><input class="form-control" name="middle_name" maxlength="100" value="<?php echo h($form['middle_name']); ?>"></div>
                            <div class="col-md-3"><label class="form-label">Last name <span class="text-danger">*</span></label><input class="form-control" name="last_name" maxlength="100" required value="<?php echo h($form['last_name']); ?>"></div>
                            <div class="col-md-2"><label class="form-label">Suffix</label><input class="form-control" name="suffix_name" maxlength="20" placeholder="Jr." value="<?php echo h($form['suffix_name']); ?>"></div>
                            <div class="col-md-3">
                                <label class="form-label">Sex</label>
                                <select name="sex" class="form-select">
                                    <option value="">—</option>
                                    <?php foreach (['Male', 'Female'] as $s): ?><option <?php echo $form['sex'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="form-label">Birth date</label><input type="date" class="form-control" name="birth_date" value="<?php echo h($form['birth_date']); ?>"></div>
                            <div class="col-md-4"><label class="form-label">Employee No.</label><input class="form-control" value="<?php echo h($isEdit ? $form['employee_no'] : 'Auto-generated'); ?>" disabled></div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Contact</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Contact number</label><input class="form-control" name="contact_no" maxlength="40" placeholder="09XX XXX XXXX" value="<?php echo h($form['contact_no']); ?>"></div>
                            <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" maxlength="150" value="<?php echo h($form['email']); ?>"></div>
                            <div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" maxlength="255" value="<?php echo h($form['address']); ?>"></div>
                            <div class="col-md-6"><label class="form-label">Emergency contact person</label><input class="form-control" name="emergency_contact_name" maxlength="150" value="<?php echo h($form['emergency_contact_name']); ?>"></div>
                            <div class="col-md-6"><label class="form-label">Emergency contact number</label><input class="form-control" name="emergency_contact_no" maxlength="40" value="<?php echo h($form['emergency_contact_no']); ?>"></div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Employment</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Employment status</label>
                                <input class="form-control" name="employment_status" list="statusOptions" maxlength="60" value="<?php echo h($form['employment_status']); ?>">
                                <datalist id="statusOptions"><?php foreach ($statusOptions as $s): ?><option value="<?php echo h($s); ?>"></option><?php endforeach; ?></datalist>
                            </div>
                            <div class="col-md-6"><label class="form-label">Date hired</label><input type="date" class="form-control" name="date_hired" value="<?php echo h($form['date_hired']); ?>"></div>
                        </div>
                    </div>
                </div>

                <?php if (!$isEdit): ?>
                <div class="card shadow-sm mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Designation</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Office</label>
                                <select name="init_office_id" class="form-select">
                                    <option value="0">— None yet —</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $init['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Designation / position</label>
                                <input class="form-control" name="init_role_title" list="titleOptions" maxlength="150" value="<?php echo h($init['role_title']); ?>">
                                <datalist id="titleOptions"><?php foreach ($titles as $t): ?><option value="<?php echo h($t); ?>"></option><?php endforeach; ?></datalist>
                            </div>
                            <div class="col-12">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="init_is_unit_head" value="1" id="init_is_unit_head" <?php echo $init['is_unit_head'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="init_is_unit_head">Unit head of this office</label></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?php echo $isEdit ? 'Save Changes' : 'Add Employee'; ?></button>
                </div>
            </div>
        </div>
    </form>
</section>
<script>
if (window.attachImagePreview) {
    window.attachImagePreview('input[name="photo"]', '#employeePhotoPreviewContainer');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
