<?php
require_once __DIR__ . '/../app/config/init.php';
require_once __DIR__ . '/../app/helpers/audit.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$db = db();
$page_title = 'Forgot Password';
$flash = get_flash();
$error = '';
$success = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));

    if (!csrf_verify()) {
        $error = 'Invalid CSRF token.';
    } elseif ($identifier === '') {
        $error = 'Please enter your username or email.';
    } elseif (!$db) {
        $error = 'Database connection error.';
    } else {
        $roleNameExpr = roles_name_expression($db, 'r');
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.email, u.full_name, {$roleNameExpr} AS role_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
            LIMIT 1
        ");

        $success = 'If an active account matches that username or email, a password reset link has been sent.';

        if ($stmt) {
            $stmt->bind_param('ss', $identifier, $identifier);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user) {
                $userId = (int) $user['id'];
                $email = trim((string) ($user['email'] ?? ''));

                if ($email !== '') {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                    $cleanupStmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ? OR expires_at < NOW() OR used_at IS NOT NULL");
                    if ($cleanupStmt) {
                        $cleanupStmt->bind_param('i', $userId);
                        $cleanupStmt->execute();
                        $cleanupStmt->close();
                    }

                    $insertStmt = $db->prepare("
                        INSERT INTO password_resets (user_id, email, token_hash, expires_at, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");

                    if ($insertStmt) {
                        $insertStmt->bind_param('isss', $userId, $email, $tokenHash, $expiresAt);
                        $saved = $insertStmt->execute();
                        $resetId = (int) $insertStmt->insert_id;
                        $insertStmt->close();

                        if ($saved) {
                            $resetUrl = base_url('auth/reset_password.php?token=' . urlencode($token));
                            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                            $mailSubject = 'SPAMS Password Reset';
                            $mailBody = "Hello " . ($user['full_name'] ?: $user['username']) . ",\n\n"
                                . "A password reset was requested for your SPAMS account.\n\n"
                                . "Open this link to reset your password:\n"
                                . $resetUrl . "\n\n"
                                . "This link will expire in 1 hour.\n"
                                . "If you did not request this, you can ignore this message.\n\n"
                                . "SPAMS";
                            $headers = [
                                'From: SPAMS <no-reply@' . $host . '>',
                                'Reply-To: no-reply@' . $host,
                                'Content-Type: text/plain; charset=UTF-8',
                            ];
                            $mailSent = @mail($email, $mailSubject, $mailBody, implode("\r\n", $headers));

                            write_audit_log($db, [
                                'user_id' => $userId,
                                'action' => 'insert',
                                'table_name' => 'password_resets',
                                'record_id' => $resetId,
                                'module_name' => 'auth',
                                'record_type' => 'password_reset',
                                'action_name' => 'request_password_reset',
                                'description' => $mailSent
                                    ? 'Generated and emailed a password reset link.'
                                    : 'Generated a password reset link but email delivery failed.',
                                'new_values' => [
                                    'email' => $email,
                                    'expires_at' => $expiresAt,
                                    'mail_sent' => $mailSent,
                                ],
                            ]);
                        }
                    }
                }
            } else {
                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'password_resets',
                    'module_name' => 'auth',
                    'record_type' => 'password_reset',
                    'action_name' => 'request_password_reset_unknown_user',
                    'description' => 'Password reset requested for an unknown username or email.',
                    'new_values' => [
                        'identifier' => $identifier,
                    ],
                ]);
            }
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
                <h1 class="h3 mb-1">Forgot Password</h1>
                <p class="text-muted mb-0">Enter your username or email to request a reset link.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo h($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo h($success); ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <div class="mb-3">
                    <label for="identifier" class="form-label">Username or Email</label>
                    <input type="text" class="form-control" id="identifier" name="identifier" value="<?php echo h($identifier); ?>" required>
                </div>
                <div class="d-grid gap-2">
                    <button class="btn btn-primary" type="submit">Send Reset Link</button>
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('auth/login.php'); ?>">Back to Login</a>
                </div>
            </form>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
