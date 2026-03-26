<?php
require_once __DIR__ . '/constants.php';

function db_connect()
{
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        error_log('DB connect error: ' . $mysqli->connect_error);
        return null;
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

?>
