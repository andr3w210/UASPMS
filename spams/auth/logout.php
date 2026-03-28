<?php
require_once __DIR__ . '/../app/config/init.php';

if (is_logged_in()) {
    $db = db_connect();
    if ($db) {
        write_audit_log($db, [
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'action' => 'logout',
            'table_name' => 'users',
            'record_id' => (string) ($_SESSION['user_id'] ?? ''),
            'module_name' => 'auth',
            'record_type' => 'user_session',
            'action_name' => 'logout',
            'new_values' => [
                'username' => (string) ($_SESSION['username'] ?? ''),
            ],
            'description' => 'User logged out.',
        ]);
    }
}

// clear session and redirect to login
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();
header('Location: login.php');
exit;
