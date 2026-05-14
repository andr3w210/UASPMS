<?php
require_once __DIR__ . '/../app/config/init.php';
require_once __DIR__ . '/../app/helpers/audit.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$db = db();
$page_title = 'Reset Password';
$flash = get_flash();
$error = '';
$success = '';
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!csrf_verify()) {
        $error = 'Invalid CSRF token.';
    } elseif (rate_limit_check('reset_password')) {
        $retryAfter = rate_limit_retry_after('reset_password');
        $retryMinutes = $retryAfter > 0 ? (int) ceil($retryAfter / 60) : 15;
        $error = 'Too many requests from your IP address. Please try again in ' . $retryMinutes . ' minute(s).';
    } elseif ($token === '') {
        $error = 'Reset token is missing.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Za-z]/', $newPassword)) {
        $error = 'New password must contain at least one letter.';
    } elseif (!preg_match('/\d/', $newPassword)) {
        $error = 'New password must contain at least one number.';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        $error = 'New password must contain at least one special character.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } elseif (!$db) {
        $error = 'Database connection error.';
    } else {
        rate_limit_record('reset_password');
        $tokenHash = hash('sha256', $token);
        $stmt = $db->prepare("
            SELECT pr.id, pr.user_id, pr.email, pr.expires_at, pr.used_at, u.username, u.full_name
            FROM password_resets pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = ?
              AND pr.used_at IS NULL
              AND pr.expires_at >= NOW()
              AND u.is_active = 1
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('s', $tokenHash);
            $stmt->execute();
            $resetRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$resetRow) {
                $error = 'This password reset link is invalid or has expired.';
            } else {
                $userId = (int) $resetRow['user_id'];
                $resetId = (int) $resetRow['id'];
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $db->begin_transaction();
                try {
                    $updateUserStmt = $db->prepare("
                        UPDATE users
                        SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL, updated_at = NOW()
                        WHERE id = ?
                    ");
                    if (!$updateUserStmt) {
                        throw new RuntimeException('Unable to prepare user password update.');
                    }
                    $updateUserStmt->bind_param('si', $passwordHash, $userId);
                    if (!$updateUserStmt->execute()) {
                        $updateUserStmt->close();
                        throw new RuntimeException('Unable to update the user password.');
                    }
                    $updateUserStmt->close();

                    $markUsedStmt = $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
                    if (!$markUsedStmt) {
                        throw new RuntimeException('Unable to prepare reset token update.');
                    }
                    $markUsedStmt->bind_param('i', $resetId);
                    if (!$markUsedStmt->execute()) {
                        $markUsedStmt->close();
                        throw new RuntimeException('Unable to mark the reset token as used.');
                    }
                    $markUsedStmt->close();

                    write_audit_log($db, [
                        'user_id' => $userId,
                        'action' => 'update',
                        'table_name' => 'users',
                        'record_id' => $userId,
                        'module_name' => 'auth',
                        'record_type' => 'user',
                        'action_name' => 'reset_password',
                        'description' => 'Password reset completed using an emailed reset link.',
                        'new_values' => [
                            'password_changed' => true,
                            'reset_id' => $resetId,
                        ],
                    ]);

                    $db->commit();
                    set_flash('success', 'Your password has been reset. You can now sign in.');
                    redirect('auth/login.php');
                } catch (Throwable $e) {
                    $db->rollback();
                    $error = 'Unable to reset your password right now.';
                }
            }
        } else {
            $error = 'Unable to validate the reset token.';
        }
    }
}

$isTokenPresent = $token !== '';
if ($isTokenPresent && !$error && !$success && $db) {
    $tokenHash = hash('sha256', $token);
    $checkStmt = $db->prepare("
        SELECT 1
        FROM password_resets pr
        INNER JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ?
          AND pr.used_at IS NULL
          AND pr.expires_at >= NOW()
          AND u.is_active = 1
        LIMIT 1
    ");
    if ($checkStmt) {
        $checkStmt->bind_param('s', $tokenHash);
        $checkStmt->execute();
        $validToken = (bool) $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        if (!$validToken) {
            $error = 'This password reset link is invalid or has expired.';
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
                <h1 class="h3 mb-1">Reset Password</h1>
                <p class="text-muted mb-0">Choose a new password for your SPAMS account.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo h($error); ?></div>
            <?php endif; ?>

            <?php if ($token !== '' && !$error): ?>
                <form method="post" action="">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="token" value="<?php echo h($token); ?>">
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required>
                        <div class="form-text">Use at least 8 characters.</div>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" required>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" type="submit">Reset Password</button>
                        <a class="btn btn-outline-secondary" href="<?php echo base_url('auth/login.php'); ?>">Back to Login</a>
                    </div>
                </form>
            <?php else: ?>
                <div class="d-grid">
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('auth/forgot_password.php'); ?>">Request a New Reset Link</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
