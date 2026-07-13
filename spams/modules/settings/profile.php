<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

$db = db();
$page_title = 'My Profile';
$flash = get_flash();
$errors = [];
$userId = (int) (current_user_id() ?? 0);
$form = [
    'full_name' => '',
    'email' => '',
    'username' => '',
    'profile_photo_path' => '',
];
$employeeProfile = [];
$officeAssignments = [];

function profile_accountability_counts(mysqli $db, int $officeId): array
{
    $counts = ['par' => 0, 'ics' => 0];
    if ($officeId <= 0) {
        return $counts;
    }

    $systemStmt = $db->prepare("
        SELECT d.document_type, COUNT(*) AS total
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
        WHERE COALESCE(NULLIF(did.current_office_id, 0), d.office_id) = ?
          AND d.document_type IN ('par', 'ics')
          AND did.is_distributed = 1
          AND (did.is_disposed IS NULL OR did.is_disposed = 0)
        GROUP BY d.document_type
    ");
    if ($systemStmt) {
        $systemStmt->bind_param('i', $officeId);
        $systemStmt->execute();
        $result = $systemStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $type = (string) ($row['document_type'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type] += (int) ($row['total'] ?? 0);
            }
        }
        $systemStmt->close();
    }

    $legacyDisposedClause = schema_has_column($db, 'legacy_assets', 'is_disposed')
        ? "AND (is_disposed IS NULL OR is_disposed = 0)"
        : "";
    $legacyStmt = $db->prepare("
        SELECT item_type, COUNT(*) AS total
        FROM legacy_assets
        WHERE office_id = ?
          AND item_type IN ('equipment', 'semi_expendable')
          {$legacyDisposedClause}
        GROUP BY item_type
    ");
    if ($legacyStmt) {
        $legacyStmt->bind_param('i', $officeId);
        $legacyStmt->execute();
        $result = $legacyStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $type = (string) ($row['item_type'] ?? '');
            if ($type === 'equipment') {
                $counts['par'] += (int) ($row['total'] ?? 0);
            } elseif ($type === 'semi_expendable') {
                $counts['ics'] += (int) ($row['total'] ?? 0);
            }
        }
        $legacyStmt->close();
    }

    return $counts;
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $loadStmt = $db->prepare("
        SELECT u.id, u.username, u.email, u.full_name, u.profile_photo_path, u.employee_id,
               e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name,
               e.position_title, e.email AS employee_email
        FROM users u
        LEFT JOIN employees e ON e.id = u.employee_id
        WHERE u.id = ?
        LIMIT 1
    ");
    if ($loadStmt) {
        $loadStmt->bind_param('i', $userId);
        $loadStmt->execute();
        $userRow = $loadStmt->get_result()->fetch_assoc();
        $loadStmt->close();
        if ($userRow) {
            $form = [
                'full_name' => (string) ($userRow['full_name'] ?? ''),
                'email' => (string) ($userRow['email'] ?? ''),
                'username' => (string) ($userRow['username'] ?? ''),
                'profile_photo_path' => (string) ($userRow['profile_photo_path'] ?? ''),
            ];
            $employeeId = (int) ($userRow['employee_id'] ?? 0);
            if ($employeeId > 0) {
                $employeeProfile = [
                    'id' => $employeeId,
                    'employee_no' => (string) ($userRow['employee_no'] ?? ''),
                    'name' => employee_display_name($userRow),
                    'position_title' => (string) ($userRow['position_title'] ?? ''),
                    'email' => (string) ($userRow['employee_email'] ?? ''),
                ];
                $officeAssignments = employee_fetch_assignments($db, $employeeId, true);
                foreach ($officeAssignments as &$assignment) {
                    $assignment['accountability_counts'] = profile_accountability_counts($db, (int) ($assignment['office_id'] ?? 0));
                }
                unset($assignment);
            }
        } else {
            $errors[] = 'Unable to load your account record.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } else {
            $form['full_name'] = old($_POST, 'full_name');
            $form['email'] = old($_POST, 'email');
            $removePhoto = isset($_POST['remove_photo']);
            $newPhotoPath = $form['profile_photo_path'];

            if ($form['full_name'] === '') {
                $errors[] = 'Full name is required.';
            }
            if ($form['email'] === '') {
                $errors[] = 'Email is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $duplicateStmt->bind_param('si', $form['email'], $userId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) {
                    $errors[] = 'Email is already in use by another account.';
                }
                $duplicateStmt->close();
            }

            if (!$errors && !empty($_FILES['profile_photo']['name'])) {
                $storedPath = store_uploaded_image($_FILES['profile_photo'], 'profile', $errors);
                if ($storedPath !== null) {
                    $newPhotoPath = $storedPath;
                }
            }

            if (!$errors) {
                if ($removePhoto) {
                    $newPhotoPath = null;
                }

                $saveStmt = $db->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?, profile_photo_path = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                if ($saveStmt) {
                    $saveStmt->bind_param('sssi', $form['full_name'], $form['email'], $newPhotoPath, $userId);
                    $saved = $saveStmt->execute();
                    $saveStmt->close();
                    if ($saved) {
                        if ($removePhoto && $form['profile_photo_path'] !== '') {
                            delete_uploaded_file($form['profile_photo_path']);
                        } elseif ($newPhotoPath !== null && $newPhotoPath !== $form['profile_photo_path'] && $form['profile_photo_path'] !== '') {
                            delete_uploaded_file($form['profile_photo_path']);
                        }

                        $form['profile_photo_path'] = (string) ($newPhotoPath ?? '');
                        $_SESSION['full_name'] = $form['full_name'];
                        $_SESSION['user_name'] = $form['full_name'] !== '' ? $form['full_name'] : $form['username'];
                        $_SESSION['user_photo_path'] = $form['profile_photo_path'];

                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => 'users',
                            'record_id' => $userId,
                            'module_name' => 'settings',
                            'record_type' => 'user',
                            'action_name' => 'edit_profile',
                            'description' => 'Updated own profile details.',
                            'new_values' => [
                                'full_name' => $form['full_name'],
                                'email' => $form['email'],
                                'profile_photo_path' => $form['profile_photo_path'],
                            ],
                        ]);

                        set_flash('success', 'Your profile was updated successfully.');
                        redirect('modules/settings/profile.php');
                    }
                }

                $errors[] = 'Unable to save your profile right now.';
            }
        }
    }
}

$photoUrl = upload_url($form['profile_photo_path']);
$profileTitle = $officeAssignments ? 'My Profile' : 'Edit Profile';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-11">
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

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4 p-lg-5">
                    <div class="mb-4">
                        <div class="text-uppercase small text-muted fw-semibold">Account Profile</div>
                        <h4 class="mb-2"><?php echo h($profileTitle); ?></h4>
                        <p class="text-muted mb-0">Update your display details and profile photo used across the system header.</p>
                    </div>

                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <div class="row g-4 align-items-start">
                            <div class="col-lg-4">
                                <div class="profile-photo-card text-center">
                                    <div class="profile-photo-preview mx-auto mb-3">
                                        <?php if ($photoUrl !== ''): ?>
                                            <img src="<?php echo h($photoUrl); ?>" alt="<?php echo h($form['full_name'] ?: $form['username']); ?>">
                                        <?php else: ?>
                                            <span><?php echo h(strtoupper(substr($form['full_name'] !== '' ? $form['full_name'] : $form['username'], 0, 1))); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted mb-3">JPG, PNG, GIF, or WEBP up to 5 MB.</div>
                                    <input type="file" class="form-control" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                                    <?php if ($form['profile_photo_path'] !== ''): ?>
                                        <div class="form-check mt-3 text-start">
                                            <input class="form-check-input" type="checkbox" value="1" id="remove_photo" name="remove_photo">
                                            <label class="form-check-label" for="remove_photo">Remove current photo</label>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-lg-8">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Username</label>
                                        <input type="text" class="form-control" value="<?php echo h($form['username']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" name="email" value="<?php echo h($form['email']); ?>" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" class="form-control" name="full_name" value="<?php echo h($form['full_name']); ?>" required>
                                    </div>
                                    <div class="col-12 d-flex gap-2 pt-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check2-circle me-2"></i>Save Profile
                                        </button>
                                        <a href="<?php echo base_url('dashboard/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                        <div>
                            <div class="text-uppercase small text-muted fw-semibold">Office Accountability</div>
                            <h4 class="mb-2">My Office PAR / ICS</h4>
                            <p class="text-muted mb-0">View accountability records for offices where you have an active designation.</p>
                        </div>
                        <?php if ($employeeProfile): ?>
                            <div class="text-lg-end">
                                <div class="fw-semibold"><?php echo h($employeeProfile['name'] ?: $form['full_name']); ?></div>
                                <div class="small text-muted">
                                    <?php echo h(implode(' - ', array_filter([$employeeProfile['employee_no'], $employeeProfile['position_title']]))); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$employeeProfile): ?>
                        <div class="alert alert-info mb-0">
                            Your user account is not linked to an employee record yet. Ask an administrator to link your account to your employee profile to show office PAR and ICS records here.
                        </div>
                    <?php elseif (!$officeAssignments): ?>
                        <div class="alert alert-info mb-0">
                            No active office designation is recorded for your employee profile yet.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($officeAssignments as $assignment): ?>
                                <?php
                                $officeId = (int) ($assignment['office_id'] ?? 0);
                                $counts = $assignment['accountability_counts'] ?? ['par' => 0, 'ics' => 0];
                                $badges = [];
                                if (!empty($assignment['is_primary'])) {
                                    $badges[] = ['Primary', 'text-bg-primary'];
                                }
                                if (!empty($assignment['is_unit_head'])) {
                                    $badges[] = ['Unit Head', 'text-bg-success'];
                                }
                                if (!empty($assignment['is_oic'])) {
                                    $badges[] = ['OIC', 'text-bg-warning'];
                                }
                                ?>
                                <div class="col-12">
                                    <div class="border rounded-3 p-3">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                            <div>
                                                <div class="fw-semibold"><?php echo h($assignment['office_name'] ?? 'Assigned Office'); ?></div>
                                                <div class="small text-muted">
                                                    <?php echo h(implode(' - ', array_filter([$assignment['office_code'] ?? '', $assignment['role_title'] ?? '']))); ?>
                                                </div>
                                                <?php if ($badges): ?>
                                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                                        <?php foreach ($badges as $badge): ?>
                                                            <span class="badge <?php echo h($badge[1]); ?>"><?php echo h($badge[0]); ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="d-flex flex-wrap align-items-center justify-content-lg-end gap-2">
                                                <span class="badge text-bg-light border"><?php echo number_format((int) ($counts['par'] ?? 0)); ?> PAR item(s)</span>
                                                <span class="badge text-bg-light border"><?php echo number_format((int) ($counts['ics'] ?? 0)); ?> ICS item(s)</span>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 mt-3">
                                            <a href="<?php echo h(base_url('modules/property/index.php?office_id=' . $officeId)); ?>" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-list-check me-1"></i>Registry
                                            </a>
                                            <a href="<?php echo h(base_url('modules/distributions/par_office.php?office_id=' . $officeId . '&view_mode=grouped')); ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="bi bi-file-earmark-text me-1"></i>PAR
                                            </a>
                                            <a href="<?php echo h(base_url('modules/distributions/ics_office.php?office_id=' . $officeId . '&semi_type=all&view_mode=grouped')); ?>" class="btn btn-sm btn-outline-success" target="_blank">
                                                <i class="bi bi-file-earmark-spreadsheet me-1"></i>ICS
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
