<?php
require_once __DIR__ . '/constants.php';

function trip_db(): ?mysqli
{
    static $mysqli = null;
    static $attempted = false;

    if ($attempted) {
        return $mysqli;
    }

    $attempted = true;
    $mysqli = new mysqli(TRIP_DB_HOST, TRIP_DB_USER, TRIP_DB_PASS, TRIP_DB_NAME);
    if ($mysqli->connect_errno) {
        error_log('Trip DB connect error: ' . $mysqli->connect_error);
        $mysqli = null;
        return null;
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function trip_db_connect(): ?mysqli
{
    return trip_db();
}

?>
