<?php
// Initialization for SPAMS pages
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set(TIMEZONE);

// simple helper loader: load all php files from app/helpers
$helpers_dir = APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR;
if (is_dir($helpers_dir)) {
    foreach (glob($helpers_dir . '*.php') as $file) {
        require_once $file;
    }
}

?>
