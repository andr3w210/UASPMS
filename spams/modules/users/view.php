<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/employees.php';
require_role('Administrator');

$db = db();
$userId = (int) ($_GET['id'] ?? 0);
$row = [];
if ($db && $userId > 0) {
    $roleExpr = roles_name_expression($db, 'r');
    $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.created_at, u.updated_at,
            u.last_login_at, u.failed_login_attempts, u.locked_until, u.must_change_password,
            {$roleExpr} AS role_name, o.office_name, e.id AS emp_id, e.employee_no,
            e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        LEFT JOIN offices o ON o.id = u.office_id
        LEFT JOIN employees e ON e.id = u.employee_id
        WHERE u.id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }
}
if (!$row) {
    set_flash('error', 'User not found.');
    redirect('modules/users/index.php');
}

$page_title = 'User: ' . ($row['full_name'] ?: $row['username']);
$flash = get_flash();
$active = (int) $row['is_active'] === 1;
$locked = !empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time();
$fmt = static function ($v): string {
    return !empty($v) ? date('M d, Y h:i A', strtotime((string) $v)) : '—';
};
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <?php echo employees_avatar_html(['first_name' => (string) ($row['full_name'] ?: $row['username']), 'last_name' => '', 'photo_path' => ''], 64); ?>
                <div>
                    <h4 class="mb-1"><?php echo h($row['full_name'] ?: $row['username']); ?></h4>
                    <div class="text-muted">
                        <?php echo h($row['role_name'] ?? ''); ?> &middot; @<?php echo h($row['username']); ?>
                        &nbsp;<span class="badge <?php echo $active ? 'bg-success-lt' : 'bg-secondary-lt'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span>
                        <?php if ($locked): ?><span class="badge bg-danger-lt">Locked</span><?php endif; ?>
                        <?php if ((int) ($row['must_change_password'] ?? 0) === 1): ?><span class="badge bg-warning-lt">Password change required</span><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo h(base_url('modules/users/index.php')); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to list</a>
                <a href="<?php echo h(base_url('modules/users/index.php?edit=' . (int) $row['id'])); ?>" class="btn btn-primary"><i class="bi bi-pencil-square me-1"></i>Edit</a>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100"><div class="card-header"><h5 class="card-title mb-0">Account</h5></div>
                <div class="card-body"><div class="row g-3">
                    <div class="col-sm-6"><div class="text-muted small">Username</div><div class="fw-semibold"><?php echo h($row['username']); ?></div></div>
                    <div class="col-sm-6"><div class="text-muted small">Email</div><div class="fw-semibold"><?php echo h($row['email'] ?: '—'); ?></div></div>
                    <div class="col-sm-6"><div class="text-muted small">Role</div><div class="fw-semibold"><?php echo h($row['role_name'] ?: '—'); ?></div></div>
                    <div class="col-sm-6"><div class="text-muted small">Office</div><div class="fw-semibold"><?php echo h($row['office_name'] ?: '—'); ?></div></div>
                </div></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100"><div class="card-header"><h5 class="card-title mb-0">Security &amp; Activity</h5></div>
                <div class="card-body"><div class="row g-3">
                    <div class="col-sm-6"><div class="text-muted small">Last login</div><div class="fw-semibold"><?php echo h($fmt($row['last_login_at'])); ?></div></div>
                    <div class="col-sm-6"><div class="text-muted small">Failed attempts</div><div class="fw-semibold"><?php echo (int) $row['failed_login_attempts']; ?></div></div>
                    <div class="col-sm-6"><div class="text-muted small">Locked until</div><div class="fw-semibold"><?php echo $locked ? h($fmt($row['locked_until'])) : '—'; ?></div></div>
                    <div class="col-sm-6"><div class="text-muted small">Created</div><div class="fw-semibold"><?php echo h($fmt($row['created_at'])); ?></div></div>
                </div></div>
            </div>
        </div>
        <div class="col-12">
            <div class="card shadow-sm"><div class="card-header"><h5 class="card-title mb-0">Linked Employee</h5></div>
                <div class="card-body">
                    <?php if (!empty($row['emp_id'])): ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <div class="fw-semibold"><?php echo h(employee_display_name($row)); ?></div>
                                <div class="text-muted small"><?php echo h(trim(($row['employee_no'] ?? '') . ' · ' . ($row['position_title'] ?? ''), ' ·')); ?></div>
                            </div>
                            <a class="btn btn-outline-primary btn-sm" href="<?php echo h(base_url('modules/employees/view.php?id=' . (int) $row['emp_id'])); ?>">Open employee profile</a>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">This account is not linked to an employee record.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
