<?php
require_once __DIR__ . '/../app/config/init.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $db = db_connect();
        if (!$db) {
            $error = 'Database connection error.';
        } else {
            $roleNameExpr = roles_name_expression($db, 'r');
            $sql = "SELECT u.id, u.username, u.email, u.password_hash, u.full_name, {$roleNameExpr} AS role_name
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
                    if (password_verify($password, $row['password_hash'])) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $row['id'];
                        $_SESSION['username'] = $row['username'];
                        $_SESSION['full_name'] = $row['full_name'];
                        $_SESSION['user_name'] = $row['full_name'] ?: $row['username'];
                        $_SESSION['role_name'] = $row['role_name'] ?: 'User';
                        // Keep both a display name and a machine-friendly role key
                        $_SESSION['user_role'] = $row['role_name'] ?: 'User';

                        $updateStmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                        if ($updateStmt) {
                            $userId = (int) $row['id'];
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

                        redirect('dashboard/index.php');
                    } else {
                        write_audit_log($db, [
                            'action' => 'login_failed',
                            'table_name' => 'users',
                            'record_id' => (string) $row['id'],
                            'module_name' => 'auth',
                            'record_type' => 'user_session',
                            'action_name' => 'login_failed',
                            'new_values' => [
                                'username_or_email' => $username,
                                'reason' => 'invalid_password',
                            ],
                            'description' => 'Failed login attempt: invalid password.',
                        ]);
                        $error = 'Invalid credentials.';
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
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
