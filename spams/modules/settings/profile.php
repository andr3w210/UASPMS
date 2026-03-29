<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

$db = db();
$page_title = 'Edit Profile';
$flash = get_flash();
$errors = [];
$userId = (int) (current_user_id() ?? 0);
$form = [
    'full_name' => '',
    'email' => '',
    'username' => '',
    'profile_photo_path' => '',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $loadStmt = $db->prepare("SELECT id, username, email, full_name, profile_photo_path FROM users WHERE id = ? LIMIT 1");
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

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-xl-8 col-lg-10">
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
                        <div class="text-uppercase small text-muted fw-semibold">Account Profile</div>
                        <h4 class="mb-2">Edit Profile</h4>
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
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
