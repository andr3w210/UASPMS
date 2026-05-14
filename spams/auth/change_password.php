<?php
require_once __DIR__ . '/../app/config/init.php';
require_once __DIR__ . '/../app/helpers/audit.php';
require_login();

$db = db();
$page_title = 'Change Password';
$errors = [];
$flash = get_flash();
$isFirstLogin = !empty($_SESSION['must_change_password']) || (($_GET['first_login'] ?? '') === '1');

if (!$db) {
    $errors[] = 'Database connection error.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($newPassword === '' || $confirmPassword === '') {
            $errors[] = 'New password and confirmation are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }

        if (strlen($newPassword) > 0) {
            if (strlen($newPassword) < 8) {
                $errors[] = 'Password must be at least 8 characters long.';
            }
            if (!preg_match('/[A-Za-z]/', $newPassword)) {
                $errors[] = 'Password must contain at least one letter.';
            }
            if (!preg_match('/\d/', $newPassword)) {
                $errors[] = 'Password must contain at least one number.';
            }
            if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
                $errors[] = 'Password must contain at least one special character.';
            }
        }

        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $userRow = null;
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (!$userRow) {
            $errors[] = 'User account not found.';
        } elseif (!password_verify($currentPassword, (string) ($userRow['password_hash'] ?? ''))) {
            $errors[] = 'Current password is incorrect.';
        }

        if (!$errors) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $hasForceColumn = schema_has_column($db, 'users', 'must_change_password');
            $sql = $hasForceColumn
                ? "UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?"
                : "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?";
            $updateStmt = $db->prepare($sql);
            if ($updateStmt) {
                $updateStmt->bind_param('si', $newHash, $userId);
                $saved = $updateStmt->execute();
                $updateStmt->close();

                if ($saved) {
                    $_SESSION['must_change_password'] = false;

                    write_audit_log($db, [
                        'user_id' => $userId,
                        'action' => 'update',
                        'table_name' => 'users',
                        'record_id' => $userId,
                        'module_name' => 'auth',
                        'record_type' => 'user',
                        'action_name' => 'change_password',
                        'description' => $isFirstLogin ? 'Changed password on first login.' : 'Changed account password.',
                        'new_values' => [
                            'must_change_password' => 0,
                            'password_changed' => true,
                        ],
                    ]);

                    set_flash('success', $isFirstLogin ? 'Password changed successfully. You can now use the system.' : 'Password changed successfully.');
                    redirect('dashboard/index.php');
                }
            }

            $errors[] = 'Unable to update your password.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<main class="min-vh-100 d-flex align-items-center justify-content-center px-3 py-5">
    <div class="card shadow-sm" style="max-width: 460px; width: 100%;">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="topbar-avatar mx-auto mb-3">S</div>
                <h1 class="h3 mb-1">Change Password</h1>
                <p class="text-muted mb-0">
                    <?php echo $isFirstLogin ? 'A new account must set a personal password before continuing.' : 'Update your account password.'; ?>
                </p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger"><?php echo h($error); ?></div>
            <?php endforeach; ?>

            <form method="post">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <div class="mb-3">
                    <label for="current_password" class="form-label">Current Password</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>
                <div class="form-text mb-3">Use at least 8 characters with both letters and numbers.</div>
                <div class="d-grid">
                    <button class="btn btn-primary" type="submit">Save New Password</button>
                </div>
            </form>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
