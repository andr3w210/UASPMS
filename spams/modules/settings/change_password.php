<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

$db = db();
$page_title = 'Change Password';
$flash = get_flash();
$errors = [];

$form = [
    'current_password' => '',
    'new_password' => '',
    'confirm_password' => '',
];

function change_password_validation_errors(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'New password must be at least 8 characters long.';
    }
    return $errors;
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['current_password'] = (string) ($_POST['current_password'] ?? '');
    $form['new_password'] = (string) ($_POST['new_password'] ?? '');
    $form['confirm_password'] = (string) ($_POST['confirm_password'] ?? '');

    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }

    if ($form['current_password'] === '') {
        $errors[] = 'Current password is required.';
    }
    if ($form['new_password'] === '') {
        $errors[] = 'New password is required.';
    }
    if ($form['confirm_password'] === '') {
        $errors[] = 'Please confirm the new password.';
    }

    if ($form['new_password'] !== '' && $form['confirm_password'] !== '' && $form['new_password'] !== $form['confirm_password']) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if ($form['new_password'] !== '') {
        $errors = array_merge($errors, change_password_validation_errors($form['new_password']));
    }

    $userId = (int) (current_user_id() ?? 0);
    $userRow = null;

    if (!$errors) {
        $stmt = $db->prepare("SELECT id, username, email, full_name, password_hash FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (!$userRow) {
            $errors[] = 'Unable to load your account details.';
        } elseif (!password_verify($form['current_password'], (string) $userRow['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        }
    }

    if (!$errors && $userRow) {
        $newPasswordHash = password_hash($form['new_password'], PASSWORD_DEFAULT);
        $updateStmt = $db->prepare("
            UPDATE users
            SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL, updated_at = NOW()
            WHERE id = ?
        ");

        if ($updateStmt) {
            $updateStmt->bind_param('si', $newPasswordHash, $userId);
            $saved = $updateStmt->execute();
            $updateStmt->close();

            if ($saved) {
                write_audit_log($db, [
                    'action' => 'update',
                    'table_name' => 'users',
                    'record_id' => $userId,
                    'module_name' => 'settings',
                    'record_type' => 'user',
                    'action_name' => 'change_password',
                    'description' => 'Changed account password.',
                    'new_values' => [
                        'password_changed' => true,
                    ],
                ]);

                set_flash('success', 'Your password has been changed successfully.');
                redirect('modules/settings/change_password.php');
            }
        }

        $errors[] = 'Unable to change your password right now.';
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-xl-7 col-lg-9">
            <?php if (!empty($flash)): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
                    <?php echo h($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <div class="fw-semibold mb-2">Please fix the following:</div>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo h($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <div class="mb-4">
                        <div class="text-uppercase small text-muted fw-semibold">Account Security</div>
                        <h4 class="mb-2">Change Password</h4>
                        <p class="text-muted mb-0">Enter your current password to confirm your identity, then choose a new password with at least 8 characters.</p>
                    </div>

                    <form method="post" novalidate>
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">

                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input
                                type="password"
                                class="form-control"
                                id="current_password"
                                name="current_password"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input
                                type="password"
                                class="form-control"
                                id="new_password"
                                name="new_password"
                                minlength="8"
                                autocomplete="new-password"
                                aria-describedby="passwordHelp passwordStrength"
                                required
                            >
                            <div id="passwordHelp" class="form-text">Use at least 8 characters.</div>
                            <div id="passwordStrength" class="small mt-2 text-muted">Strength: waiting for input</div>
                        </div>

                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input
                                type="password"
                                class="form-control"
                                id="confirm_password"
                                name="confirm_password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-shield-lock me-2"></i>Update Password
                            </button>
                            <a href="<?php echo base_url('dashboard/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const passwordInput = document.getElementById('new_password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthText = document.getElementById('passwordStrength');

    if (!passwordInput || !strengthText) {
        return;
    }

    function updateStrength() {
        const value = passwordInput.value || '';
        const confirmValue = confirmInput ? confirmInput.value || '' : '';

        let message = 'Strength: waiting for input';
        let className = 'text-muted';

        if (value.length > 0 && value.length < 8) {
            message = 'Strength: too short';
            className = 'text-danger';
        } else if (value.length >= 8 && value.length < 12) {
            message = 'Strength: acceptable';
            className = 'text-warning';
        } else if (value.length >= 12) {
            message = 'Strength: strong';
            className = 'text-success';
        }

        if (value.length >= 8 && confirmInput && confirmValue.length > 0) {
            if (value === confirmValue) {
                message += ' | confirmation matches';
            } else {
                message += ' | confirmation does not match';
                className = 'text-danger';
            }
        }

        strengthText.className = 'small mt-2 ' + className;
        strengthText.textContent = message;
    }

    passwordInput.addEventListener('input', updateStrength);
    if (confirmInput) {
        confirmInput.addEventListener('input', updateStrength);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
