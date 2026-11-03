<?php
require_once __DIR__ . '/../app/config/init.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$error = '';
$username = '';
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    request_schema_enforce([
        'username' => ['type' => 'string', 'max' => 100],
        'password' => ['type' => 'string', 'max' => 255],
    ]);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrf_verify()) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } elseif (rate_limit_check('login', 5, 15, true)) {
        $retryAfter = rate_limit_retry_after('login', 5, 15);
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        $retryMinutes = $retryAfter > 0 ? (int) ceil($retryAfter / 60) : 15;
        $error = 'Too many login attempts from your IP address. Please try again in ' . $retryMinutes . ' minute(s).';
    } else {
        rate_limit_record('login');
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
$body_class = 'ua-auth';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="ua-auth-shell">
    <section class="ua-auth-brand">
        <div class="ua-auth-brand-inner">
            <img class="ua-auth-logo" src="<?php echo base_url('assets/img/ua-logo.png'); ?>" alt="University of Antique logo">
            <span class="ua-auth-kicker">University of Antique</span>
            <h1 class="ua-auth-title">Supply and Property Asset Management System</h1>
            <p class="ua-auth-copy">A professional workspace for receiving, accountability, registry, and reporting across University of Antique supply and property operations.</p>
            <ul class="ua-auth-points list-unstyled">
                <li><i class="bi bi-box-seam"></i><span>Receiving, issuance, returns, transfer, and disposal in one workspace.</span></li>
                <li><i class="bi bi-qr-code-scan"></i><span>Asset registry, QR workflows, counts, and RPCPPE support.</span></li>
                <li><i class="bi bi-shield-check"></i><span>Role-based access with monitored logins and temporary account locks.</span></li>
            </ul>
        </div>
    </section>

    <section class="ua-auth-form-panel">
        <div class="ua-auth-card card card-md">
            <div class="card-body">
                <h2 class="h2 text-center mb-1">Sign in to continue</h2>
                <p class="text-secondary text-center mb-4">Use your assigned username or email address to access SPAMS.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
                <?php endif; ?>

                <form method="post" action="" data-no-confirm autocomplete="on">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username or Email</label>
                        <div class="input-icon">
                            <span class="input-icon-addon"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo h($username); ?>" required autocomplete="username" autofocus>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-icon">
                            <span class="input-icon-addon"><i class="bi bi-key"></i></span>
                            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                        </div>
                    </div>
                    <div class="form-footer">
                        <button class="btn btn-primary w-100" type="submit">Sign In</button>
                    </div>
                </form>

                <div class="text-center mt-3">
                    <a href="<?php echo base_url('auth/forgot_password.php'); ?>">Forgot your password?</a>
                    <div class="text-secondary small mt-1">Need help? Contact your system administrator.</div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

