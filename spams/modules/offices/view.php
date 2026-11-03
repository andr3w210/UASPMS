<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/employees.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$recordId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM offices WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $recordId);
$stmt->execute();
$office = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$office) {
    set_flash('error', 'Office not found.');
    redirect('modules/offices/index.php');
}
$page_title = 'Office: ' . $office['office_name'];
$flash = get_flash();
$active = (int) $office['is_active'] === 1;

$stmt = $db->prepare("SELECT ea.employee_id, ea.role_title, ea.is_unit_head, ea.is_oic, ea.is_primary, rc.code AS responsibility_code,
        e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.photo_path, e.is_active
    FROM employee_assignments ea
    JOIN employees e ON e.id = ea.employee_id
    LEFT JOIN responsibility_codes rc ON rc.id = ea.responsibility_code_id
    WHERE ea.office_id = ? AND ea.is_active = 1
    ORDER BY ea.is_unit_head DESC, e.last_name ASC, e.first_name ASC");
$stmt->bind_param('i', $recordId);
$stmt->execute();
$staff = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $db->prepare("SELECT code, description, is_active FROM responsibility_codes WHERE office_id = ? ORDER BY is_active DESC, code ASC");
$stmt->bind_param('i', $recordId);
$stmt->execute();
$codes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $db->prepare("SELECT document_type, COUNT(*) AS c FROM distributions WHERE office_id = ? AND status = 'posted' GROUP BY document_type");
$stmt->bind_param('i', $recordId);
$stmt->execute();
$docCounts = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'c', 'document_type');
$stmt->close();

$head = null;
foreach ($staff as $member) {
    if (!empty($member['is_unit_head'])) {
        $head = $member;
        break;
    }
}

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
                <?php echo employees_avatar_html(['first_name' => $office['office_code'], 'last_name' => '', 'photo_path' => ''], 72); ?>
                <div>
                    <h4 class="mb-1"><?php echo h($office['office_name']); ?></h4>
                    <div class="text-muted"><?php echo h($office['office_code']); ?></div>
                    <span class="badge <?php echo $active ? 'bg-success-lt' : 'bg-secondary-lt'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo base_url('modules/offices/form.php?id=' . $recordId); ?>" class="btn btn-primary"><i class="bi bi-pencil-square me-1"></i>Edit</a>
                <a href="<?php echo base_url('modules/offices/index.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">Overview</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-staff" type="button">Employees <span class="badge bg-blue-lt"><?php echo count($staff); ?></span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-codes" type="button">Responsibility Codes <span class="badge bg-blue-lt"><?php echo count($codes); ?></span></button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-overview">
            <div class="row g-3">
                <div class="col-md-6"><div class="card shadow-sm h-100"><div class="card-body">
                    <h6 class="text-uppercase text-muted small">Details</h6>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Office head</dt>
                        <dd class="col-sm-8"><?php echo $head ? '<a href="' . h(base_url('modules/employees/view.php?id=' . (int) $head['employee_id'])) . '">' . h(employee_display_name($head)) . '</a>' : '<span class="text-muted">Not assigned</span>'; ?></dd>
                        <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?php echo h($office['description'] ?: '—'); ?></dd>
                        <dt class="col-sm-4">Employees</dt><dd class="col-sm-8"><?php echo count($staff); ?></dd>
                    </dl>
                </div></div></div>
                <div class="col-md-6"><div class="card shadow-sm h-100"><div class="card-body">
                    <h6 class="text-uppercase text-muted small">Issued property documents</h6>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">PAR</dt><dd class="col-sm-8"><?php echo (int) ($docCounts['par'] ?? 0); ?></dd>
                        <dt class="col-sm-4">ICS</dt><dd class="col-sm-8"><?php echo (int) ($docCounts['ics'] ?? 0); ?></dd>
                    </dl>
                </div></div></div>
            </div>
        </div>
        <div class="tab-pane fade" id="tab-staff">
            <div class="card shadow-sm"><div class="table-responsive">
                <table class="table table-hover align-middle mb-0" data-no-table-search="true">
                    <thead><tr><th>Employee</th><th>Designation</th><th>Resp. code</th></tr></thead>
                    <tbody>
                    <?php if (!$staff): ?><tr><td colspan="3" class="text-center text-muted py-4">No employees are assigned to this office.</td></tr><?php endif; ?>
                    <?php foreach ($staff as $member): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php echo employees_avatar_html($member, 36); ?>
                                    <div>
                                        <a class="fw-semibold text-reset" href="<?php echo base_url('modules/employees/view.php?id=' . (int) $member['employee_id']); ?>"><?php echo h(employee_display_name($member)); ?></a>
                                        <div class="text-muted small"><?php echo h($member['employee_no']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php echo h(employee_format_title_with_oic((string) $member['role_title'], !empty($member['is_oic']))); ?>
                                <?php if (!empty($member['is_unit_head'])): ?><span class="badge bg-green-lt">Unit Head</span><?php endif; ?>
                                <?php if (!empty($member['is_primary'])): ?><span class="badge bg-blue-lt">Primary</span><?php endif; ?>
                            </td>
                            <td><?php echo h($member['responsibility_code'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
        <div class="tab-pane fade" id="tab-codes">
            <div class="card shadow-sm"><div class="table-responsive">
                <table class="table table-hover align-middle mb-0" data-no-table-search="true">
                    <thead><tr><th>Code</th><th>Description</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$codes): ?><tr><td colspan="3" class="text-center text-muted py-4">No responsibility codes for this office.</td></tr><?php endif; ?>
                    <?php foreach ($codes as $code): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo h($code['code']); ?></td>
                            <td><?php echo h($code['description'] ?? ''); ?></td>
                            <td><span class="badge <?php echo $code['is_active'] ? 'bg-success-lt' : 'bg-secondary-lt'; ?>"><?php echo $code['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
