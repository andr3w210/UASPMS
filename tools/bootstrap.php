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
    foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $constantName) {
        if (!defined($constantName) || trim((string) constant($constantName)) === '') {
            fwrite(STDERR, 'Missing required database configuration: ' . $constantName . PHP_EOL);
            exit(1);
        }
    }

    $host   = DB_HOST;
    $user   = DB_USER;
    $pass   = DB_PASS;
    $dbname = DB_NAME;

    $m = new mysqli($host, $user, $pass, $dbname);
    if ($m->connect_errno) {
        fwrite(STDERR, 'DB connection failed: ' . $m->connect_error . PHP_EOL);
        exit(1);
    }
    $m->set_charset('utf8mb4');
    return $m;
}
