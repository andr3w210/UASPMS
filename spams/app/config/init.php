<?php
// Initialization for SPAMS pages
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/trip_db.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');

$isCli = PHP_SAPI === 'cli';
$isHttps = (
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
);

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');

    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if ($isCli) {
    $_SESSION = $_SESSION ?? [];
} else {
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 8,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

date_default_timezone_set(TIMEZONE);

// simple helper loader: load all php files from app/helpers
$helpers_dir = APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR;
if (is_dir($helpers_dir)) {
    $orderedHelpers = [
        'cache.php',
        'common.php',
        'auth.php',
        'pagination.php',
        'series.php',
        'messaging.php',
    ];

    foreach ($orderedHelpers as $helper) {
        $path = $helpers_dir . $helper;
        if (is_file($path)) {
            require_once $path;
        }
    }

    foreach (glob($helpers_dir . '*.php') as $file) {
        if (in_array(basename($file), $orderedHelpers, true)) {
            continue;
        }
        require_once $file;
    }
}

if (function_exists('request_guard_superglobals')) {
    request_guard_superglobals();
}

if (!empty($_SESSION['user_id'])) {
    $timeoutMinutes = 30;
    if (function_exists('db') && function_exists('get_system_setting')) {
        $timeoutDb = db();
        if ($timeoutDb) {
            $configuredTimeout = (int) get_system_setting($timeoutDb, 'session_timeout_minutes', '30');
            if ($configuredTimeout >= 5 && $configuredTimeout <= 480) {
                $timeoutMinutes = $configuredTimeout;
            }
        }
    }

    $lastActivityAt = (int) ($_SESSION['last_activity_at'] ?? 0);
    if ($lastActivityAt > 0 && (time() - $lastActivityAt) > ($timeoutMinutes * 60)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        if (function_exists('request_expects_json') && request_expects_json()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Your session has expired. Please log in again.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Location: ' . base_url('auth/login.php?expired=1'));
        exit;
    }

    $_SESSION['last_activity_at'] = time();
}

if (!empty($_SESSION['user_id']) && function_exists('db') && function_exists('roles_name_expression')) {
    $authDb = db();
    if ($authDb) {
        $roleNameExpr = roles_name_expression($authDb, 'r');
        $stmt = $authDb->prepare("
            SELECT u.username, u.full_name, u.profile_photo_path, {$roleNameExpr} AS role_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $userId = (int) $_SESSION['user_id'];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $sessionUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($sessionUser) {
                $_SESSION['username'] = $sessionUser['username'] ?? ($_SESSION['username'] ?? '');
                $_SESSION['full_name'] = $sessionUser['full_name'] ?? ($_SESSION['full_name'] ?? '');
                $_SESSION['user_name'] = ($sessionUser['full_name'] ?? '') !== ''
                    ? $sessionUser['full_name']
                    : ($_SESSION['username'] ?? '');
                $_SESSION['user_photo_path'] = $sessionUser['profile_photo_path'] ?? ($_SESSION['user_photo_path'] ?? '');
                $_SESSION['role_name'] = trim((string) ($sessionUser['role_name'] ?? 'User'));
                $_SESSION['user_role'] = trim((string) ($sessionUser['role_name'] ?? 'User'));
            }
        }
    }
}

if (!empty($_SESSION['user_id']) && function_exists('db') && function_exists('audit_auto_log_request')) {
    register_shutdown_function(static function (): void {
        $auditDb = db();
        if ($auditDb instanceof mysqli) {
            audit_auto_log_request($auditDb);
        }
    });
}

?>
