<?php
// Initialization for SPAMS pages
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/db.php';

ini_set('session.use_strict_mode', '1');

$isHttps = (
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
);

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

date_default_timezone_set(TIMEZONE);

// simple helper loader: load all php files from app/helpers
$helpers_dir = APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR;
if (is_dir($helpers_dir)) {
    $orderedHelpers = [
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

?>
