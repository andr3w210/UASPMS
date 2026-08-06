<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db();
$page_title = 'RIS Approver';
$flash = get_flash();
$errors = [];
$risApprovedBy = $db ? get_system_setting($db, 'ris_approved_by', '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $risApprovedBy = trim((string) ($_POST['ris_approved_by'] ?? ''));

        if ($db && empty($errors)) {
            $userId = current_user_id();
            $stmt = $db->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, updated_by)
                 VALUES ('ris_approved_by', ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
            );
            if ($stmt) {
                $stmt->bind_param('si', $risApprovedBy, $userId);
                $ok = $stmt->execute();
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
                            'action_name' => 'save_ris_approver',
                            'new_values' => [
                                'setting_key' => 'ris_approved_by',
                                'setting_value' => $risApprovedBy,
                            ],
                        ]);
                    }
                    set_flash('success', 'RIS approver saved successfully.');
                    redirect('modules/settings/ris_approver.php');
                } else {
                    $errors[] = 'Database error while saving RIS approver.';
                }
            } else {
                $errors[] = 'Unable to prepare RIS approver statement.';
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
                        <h4 class="mb-2">RIS Approver</h4>
                        <p class="text-muted mb-0">Set the name used in the Approved by block of the RIS form.</p>
                    </div>

                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <div class="mb-3">
                            <label for="ris_approved_by" class="form-label">Approved by</label>
                            <input type="text" id="ris_approved_by" name="ris_approved_by" class="form-control" value="<?php echo h($risApprovedBy); ?>" placeholder="Enter officer name">
                        </div>
                        <div class="d-grid gap-2 d-sm-flex">
                            <button type="submit" class="btn btn-primary">Save RIS Approver</button>
                            <a href="<?php echo base_url('modules/settings/index.php'); ?>" class="btn btn-outline-secondary">Back to Settings</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
