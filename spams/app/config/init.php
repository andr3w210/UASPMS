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

function spams_log_server_error(string $message): void
{
    error_log('[SPAMS] ' . $message);
}

function spams_render_error_page(int $statusCode, string $title, string $message): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
    }

    $homeUrl = function_exists('base_url') ? base_url('index.php') : '/';
    $isLoggedIn = function_exists('is_logged_in') && is_logged_in();
    $actionUrl = $isLoggedIn ? $homeUrl : (function_exists('base_url') ? base_url('auth/login.php') : '/');
    $actionLabel = $isLoggedIn ? 'Go to dashboard' : 'Go to login';
    $statusText = $statusCode === 404 ? 'Page not found' : 'Something went wrong';
    $GLOBALS['spams_error_page_rendered'] = true;

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . h($title) . ' | SPAMS</title>';
    echo '<style>';
    echo 'body{font-family:Inter,Segoe UI,Arial,sans-serif;background:#f4f7fb;color:#243043;margin:0;padding:0;}';
    echo '.error-shell{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}';
    echo '.error-card{background:#fff;border-radius:18px;box-shadow:0 16px 48px rgba(15,23,42,.12);max-width:560px;width:100%;padding:32px;border:1px solid #e5eaf2;}';
    echo '.error-badge{display:inline-block;padding:8px 12px;border-radius:999px;background:#eef2ff;color:#4f46e5;font-weight:700;font-size:.85rem;margin-bottom:16px;}';
    echo 'h1{margin:0 0 12px;font-size:2rem;color:#111827;}';
    echo 'p{margin:0 0 16px;line-height:1.6;color:#4b5563;}';
    echo 'a{display:inline-block;padding:10px 16px;background:#4154f1;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;}';
    echo 'a:hover{background:#3142c7;}';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="error-shell">';
    echo '<div class="error-card">';
    echo '<div class="error-badge">' . h($statusText) . '</div>';
    echo '<h1>' . h($title) . '</h1>';
    echo '<p>' . h($message) . '</p>';
    echo '<p><a href="' . h($actionUrl) . '">' . h($actionLabel) . '</a></p>';
    echo '</div>';
    echo '</div>';
    echo '</body></html>';
    exit;
}

function spams_handle_exception(Throwable $exception): void
{
    spams_log_server_error('Unhandled exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    spams_render_error_page(500, 'Something went wrong', 'We could not complete your request right now. Please try again in a moment.');
}

function spams_handle_error(int $severity, string $message, string $file = '', int $line = 0): bool
{
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $fatalSeverities = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (in_array($severity, $fatalSeverities, true)) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    spams_log_server_error('PHP warning: ' . $message . ' in ' . $file . ':' . $line);
    return true;
}

function spams_handle_shutdown(): void
{
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalSeverities = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($error['type'] ?? 0), $fatalSeverities, true)) {
        return;
    }

    spams_log_server_error('Fatal PHP error: ' . ($error['message'] ?? 'Unknown error') . ' in ' . ($error['file'] ?? 'unknown') . ':' . ($error['line'] ?? 0));
    if (empty($GLOBALS['spams_error_page_rendered'])) {
        spams_render_error_page(500, 'Something went wrong', 'We could not complete your request right now. Please try again in a moment.');
    }
}

set_exception_handler('spams_handle_exception');
set_error_handler('spams_handle_error');
register_shutdown_function('spams_handle_shutdown');

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
