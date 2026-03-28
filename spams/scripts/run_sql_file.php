<?php
// Usage: php run_sql_file.php path/to/file.sql
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/config/db.php';

$pathArg = $argv[1] ?? 'database/spamsdb_upgrade.sql';
$sqlFile = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $pathArg;

if (!file_exists($sqlFile)) {
    echo "SQL file not found: " . $sqlFile . PHP_EOL;
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    echo "Unable to read SQL file: " . $sqlFile . PHP_EOL;
    exit(1);
}

$db = db();
if (!$db) {
    echo "Unable to connect to DB.\n";
    exit(1);
}

echo "Executing SQL file: " . $sqlFile . PHP_EOL;
$statements = preg_split('/;\s*\n/', $sql);
$counter = 0;
foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    $counter++;
    if ($db->query($stmt) === false) {
        // report and continue
        echo "[WARN] Statement #{$counter} failed: " . $db->error . PHP_EOL;
        // continue to next statement
    }
}
echo "Execution finished. Statements processed (attempted): " . $counter . PHP_EOL;
$db->close();

?>