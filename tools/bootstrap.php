<?php
/**
 * Shared bootstrap for standalone CLI tool scripts.
 *
 * Loads the application .env so credentials are never hardcoded in tool files.
 * Provides tools_db() which returns a connected mysqli instance or exits.
 *
 * Usage from any tools/<subdir>/script.php:
 *   require_once __DIR__ . '/../bootstrap.php';
 *   $db = tools_db();
 */

require_once dirname(__DIR__) . '/spams/app/config/constants.php';

function tools_db(): mysqli
{
    $host   = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $user   = defined('DB_USER') ? DB_USER : 'root';
    $pass   = defined('DB_PASS') ? DB_PASS : '';
    $dbname = defined('DB_NAME') ? DB_NAME : 'spamsdb';

    $m = new mysqli($host, $user, $pass, $dbname);
    if ($m->connect_errno) {
        fwrite(STDERR, 'DB connection failed: ' . $m->connect_error . PHP_EOL);
        exit(1);
    }
    $m->set_charset('utf8mb4');
    return $m;
}
