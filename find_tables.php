<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/spams/app/config/constants.php';

$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($m->connect_error) {
    die("Connection failed: " . $m->connect_error);
}
$res = $m->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'property_number'");
echo "Tables with property_number column:\n";
echo str_repeat('-', 40) . "\n";
while ($r = $res->fetch_assoc()) {
    $table = $r['TABLE_NAME'];
    $countRes = $m->query("SELECT COUNT(*) as cnt FROM $table WHERE property_number IS NOT NULL");
    if ($countRes) {
        $countRow = $countRes->fetch_assoc();
        printf("%-35s %d rows\n", $table, $countRow['cnt']);
    } else {
        printf("%-35s Error querying table\n", $table);
    }
}
?>
