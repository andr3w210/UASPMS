<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db();
$page_title = 'University President';
$flash = get_flash();
$errors = [];
$universityPresidentName = $db ? get_system_setting($db, 'university_president_name', '') : '';
$universityPresidentTitle = $db ? get_system_setting($db, 'university_president_title', 'University President') : 'University President';
$universityPresidentAppointmentDate = $db ? get_system_setting($db, 'university_president_appointment_date', '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $universityPresidentName = trim((string) ($_POST['university_president_name'] ?? ''));
        $universityPresidentTitle = trim((string) ($_POST['university_president_title'] ?? ''));
        $universityPresidentAppointmentDate = trim((string) ($_POST['university_president_appointment_date'] ?? ''));

        if ($universityPresidentTitle === '') {
            $universityPresidentTitle = 'University President';
        }

        if ($universityPresidentAppointmentDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $universityPresidentAppointmentDate)) {
            $errors[] = 'University President appointment date must be a valid date.';
        }

        if ($db && empty($errors)) {
            $userId = current_user_id();
            $stmt = $db->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
            );

            if ($stmt) {
                $settingsToSave = [
                    'university_president_name' => $universityPresidentName,
                    'university_president_title' => $universityPresidentTitle,
                    'university_president_appointment_date' => $universityPresidentAppointmentDate,
                ];

                $ok = true;
                foreach ($settingsToSave as $settingKey => $settingValue) {
                    $stmt->bind_param('ssi', $settingKey, $settingValue, $userId);
                    if (!$stmt->execute()) {
                        $ok = false;
                        break;
                    }
                }
                $stmt->close();

                if ($ok) {
                    if (function_exists('spams_cache_forget_prefix')) {
                        spams_cache_forget_prefix('system_setting:');
                    }
                    if (function_exists('write_audit_log')) {
                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => 'system_settings',
                            'record_id' => 0,
                            'module_name' => 'settings',
                            'record_type' => 'system_setting',
                            'action_name' => 'save_university_president',
                            'new_values' => $settingsToSave,
                        ]);
                    }
                    set_flash('success', 'University President settings saved successfully.');
                    redirect('modules/settings/university_president.php');
                } else {
                    $errors[] = 'Database error while saving University President settings.';
                }
            } else {
                $errors[] = 'Unable to prepare University President settings statement.';
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-xl-7 col-lg-9">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
                    <?php echo h($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo h($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <div class="mb-4">
                        <div class="text-uppercase small text-muted fw-semibold">Document Settings</div>
                        <h4 class="mb-2">University President</h4>
                        <p class="text-muted mb-0">Set the University President details used in reports such as RPCPPE.</p>
                    </div>

                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <div class="mb-3">
                            <label for="university_president_name" class="form-label">Name</label>
                            <input type="text" id="university_president_name" name="university_president_name" class="form-control" value="<?php echo h($universityPresidentName); ?>" placeholder="Enter University President name">
                        </div>
                        <div class="mb-3">
                            <label for="university_president_title" class="form-label">Position Title</label>
                            <input type="text" id="university_president_title" name="university_president_title" class="form-control" value="<?php echo h($universityPresidentTitle); ?>" placeholder="University President">
                        </div>
                        <div class="mb-3">
                            <label for="university_president_appointment_date" class="form-label">Appointment Date</label>
                            <input type="date" id="university_president_appointment_date" name="university_president_appointment_date" class="form-control" value="<?php echo h($universityPresidentAppointmentDate); ?>">
                        </div>
                        <div class="d-grid gap-2 d-sm-flex">
                            <button type="submit" class="btn btn-primary">Save University President</button>
                            <a href="<?php echo base_url('modules/settings/index.php'); ?>" class="btn btn-outline-secondary">Back to Settings</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
