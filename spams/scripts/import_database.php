<?php
// Simple DB import script — run from command line: php scripts/import_database.php
require_once __DIR__ . '/../app/config/constants.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$dbName = DB_NAME;

// resolve workspace root and database directory
$workspaceRoot = dirname(APP_ROOT);
$sqlDir = $workspaceRoot . DIRECTORY_SEPARATOR . 'database';

echo "SQL directory: " . $sqlDir . PHP_EOL;
if (!is_dir($sqlDir)) {
    echo "Database directory not found: " . $sqlDir . PHP_EOL;
    exit(1);
}

// connect without selecting a database to allow creation
$conn = new mysqli($host, $user, $pass);
if ($conn->connect_errno) {
    echo "Unable to connect to MySQL: " . $conn->connect_error . PHP_EOL;
    exit(1);
}

// create database if not exists
$createSql = sprintf("CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci", $conn->real_escape_string($dbName));
if (!$conn->query($createSql)) {
    echo "Failed to create database {$dbName}: " . $conn->error . PHP_EOL;
    exit(1);
}

// select DB
if (!$conn->select_db($dbName)) {
    echo "Unable to select database {$dbName}: " . $conn->error . PHP_EOL;
    exit(1);
}

// gather SQL files sorted by name
$files = glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql');
usort($files, function($a, $b) { return strnatcmp(basename($a), basename($b)); });

foreach ($files as $file) {
    echo "\n--- Importing: " . basename($file) . " ---\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "Unable to read file: " . $file . PHP_EOL;
        continue;
    }

    // execute as multi_query to allow multiple statements
    if (!$conn->multi_query($sql)) {
        echo "Error executing " . basename($file) . ": " . $conn->error . PHP_EOL;
        // try to continue to next file
        while ($conn->more_results() && $conn->next_result()) { /* flush */ }
        continue;
    }

    // drain results
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    echo "Imported " . basename($file) . "\n";
}

echo "\nDatabase import finished.\n";
echo "Verify connection settings in spams/app/config/constants.php if errors occur.\n";

$conn->close();

?>
