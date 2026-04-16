<?php
require_once __DIR__ . '/../app/config/init.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$error = '';
$username = '';
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $db = db();
        if (!$db) {
            $error = 'Database connection error.';
        } else {
            $roleNameExpr = roles_name_expression($db, 'r');
            $mustChangePasswordExpr = schema_has_column($db, 'users', 'must_change_password') ? 'u.must_change_password' : '0';
            $sql = "SELECT u.id, u.username, u.email, u.password_hash, u.full_name, u.profile_photo_path, u.failed_login_attempts, u.locked_until, {$mustChangePasswordExpr} AS must_change_password, {$roleNameExpr} AS role_name
                    FROM users u
                    LEFT JOIN roles r ON r.id = u.role_id
                    WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ss', $username, $username);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $userId = (int) $row['id'];
                    $failedAttempts = (int) ($row['failed_login_attempts'] ?? 0);
                    $lockedUntilRaw = $row['locked_until'] ?? null;
                    $lockedUntilTs = !empty($lockedUntilRaw) ? strtotime((string) $lockedUntilRaw) : false;

                    if ($lockedUntilTs && $lockedUntilTs <= time()) {
                        $resetLockStmt = $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
                        if ($resetLockStmt) {
                            $resetLockStmt->bind_param('i', $userId);
                            $resetLockStmt->execute();
                            $resetLockStmt->close();
                        }
                        $failedAttempts = 0;
                        $lockedUntilRaw = null;
                        $lockedUntilTs = false;
                    }

                    $isLocked = $lockedUntilTs && $lockedUntilTs > time();

                    if ($isLocked) {
                        $remainingMinutes = (int) ceil(($lockedUntilTs - time()) / 60);
                        write_audit_log($db, [
                            'action' => 'login_locked',
                            'table_name' => 'users',
                            'record_id' => (string) $userId,
                            'module_name' => 'auth',
                            'record_type' => 'user_session',
                            'action_name' => 'login_locked',
                            'new_values' => [
                                'username_or_email' => $username,
                                'locked_until' => $lockedUntilRaw,
                            ],
                            'description' => 'Blocked login attempt on a locked account.',
                        ]);
                        $error = 'Account locked. Try again in about ' . max(1, $remainingMinutes) . ' minute(s).';
                    } elseif (password_verify($password, $row['password_hash'])) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $row['id'];
                        $_SESSION['username'] = $row['username'];
                        $_SESSION['full_name'] = $row['full_name'];
                        $_SESSION['user_name'] = $row['full_name'] ?: $row['username'];
                        $_SESSION['role_name'] = $row['role_name'] ?: 'User';
                        $_SESSION['user_photo_path'] = $row['profile_photo_path'] ?? '';
                        // Keep both a display name and a machine-friendly role key
                        $_SESSION['user_role'] = $row['role_name'] ?: 'User';
                        $_SESSION['must_change_password'] = (int) ($row['must_change_password'] ?? 0) === 1;
                        $_SESSION['last_activity_at'] = time();

                        $updateStmt = $db->prepare("UPDATE users SET last_login_at = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
                        if ($updateStmt) {
                            $updateStmt->bind_param('i', $userId);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }

                        write_audit_log($db, [
                            'user_id' => (int) $row['id'],
                            'action' => 'login',
                            'table_name' => 'users',
                            'record_id' => (string) $row['id'],
                            'module_name' => 'auth',
                            'record_type' => 'user_session',
                            'action_name' => 'login_success',
                            'new_values' => [
                                'username' => $row['username'],
                                'role_name' => $row['role_name'] ?: 'User',
                            ],
                            'description' => 'User logged in successfully.',
                        ]);

                        if (!empty($_SESSION['must_change_password'])) {
                            redirect('auth/change_password.php?first_login=1');
                        }

                        redirect('dashboard/index.php');
                    } else {
                        $newFailedAttempts = $failedAttempts + 1;
                        $shouldLock = $newFailedAttempts >= 5;
                        $lockUntil = $shouldLock ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;

                        $failureStmt = $db->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?");
                        if ($failureStmt) {
                            $failureStmt->bind_param('isi', $newFailedAttempts, $lockUntil, $userId);
                            $failureStmt->execute();
                            $failureStmt->close();
                        }

                        write_audit_log($db, [
                            'action' => 'login_failed',
                            'table_name' => 'users',
                            'record_id' => (string) $userId,
                            'module_name' => 'auth',
                            'record_type' => 'user_session',
                            'action_name' => $shouldLock ? 'login_locked_after_failures' : 'login_failed',
                            'new_values' => [
                                'username_or_email' => $username,
                                'reason' => 'invalid_password',
                                'failed_login_attempts' => $newFailedAttempts,
                                'locked_until' => $lockUntil,
                            ],
                            'description' => $shouldLock
                                ? 'Failed login attempt: invalid password. Account locked for 15 minutes.'
                                : 'Failed login attempt: invalid password.',
                        ]);
                        $error = $shouldLock
                            ? 'Account locked for 15 minutes after too many failed attempts.'
                            : 'Invalid credentials.';
                    }
                } else {
                    write_audit_log($db, [
                        'action' => 'login_failed',
                        'table_name' => 'users',
                        'module_name' => 'auth',
                        'record_type' => 'user_session',
                        'action_name' => 'login_failed',
                        'new_values' => [
                            'username_or_email' => $username,
                            'reason' => 'user_not_found',
                        ],
                        'description' => 'Failed login attempt: user not found.',
                    ]);
                    $error = 'Invalid credentials.';
                }
                $stmt->close();
            } else {
                $error = 'Query error.';
            }
            $db->close();
        }
    }
}

$page_title = 'Login';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="min-vh-100 d-flex align-items-center justify-content-center px-3 py-5">
    <div class="card shadow-sm" style="max-width: 420px; width: 100%;">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="topbar-avatar mx-auto mb-3">S</div>
                <h1 class="h3 mb-1">SPAMS Login</h1>
                <p class="text-muted mb-0">Supply and Property Asset Management System</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo h($error); ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="mb-3">
                    <label for="username" class="form-label">Username or Email</label>
                    <input type="text" class="form-control" id="username" name="username" value="<?php echo h($username); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="d-grid">
                    <button class="btn btn-primary" type="submit">Sign In</button>
                </div>
            </form>
            <div class="text-center mt-3">
                <a href="<?php echo base_url('auth/forgot_password.php'); ?>" class="small text-decoration-none">Forgot your password?</a>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

