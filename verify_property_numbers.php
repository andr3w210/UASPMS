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
$query = "
    SELECT 
        ac.account_code,
        ac.account_name,
        COUNT(did.id) AS item_count,
        GROUP_CONCAT(DISTINCT did.property_number ORDER BY did.property_number SEPARATOR ', ') AS property_numbers
    FROM distribution_item_details did
    LEFT JOIN distribution_items di    ON di.id  = did.distribution_item_id
    LEFT JOIN receiving_items ri       ON ri.id  = di.receiving_item_id
    LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
    LEFT JOIN account_codes ac         ON ac.id  = poi.account_code_id
    WHERE ac.account_code LIKE '1.06.05.%' OR ac.account_code LIKE '5.02.03.210%'
    GROUP BY ac.account_code, ac.account_name
    ORDER BY ac.account_code
";
$res = $m->query($query);
if (!$res) {
    die("Query failed: " . $m->error);
}
$header = sprintf("%-20s %-50s %s\n", "ACCOUNT CODE", "ACCOUNT NAME", "COUNT");
echo $header;
echo str_repeat("-", 120) . "\n";
while ($r = $res->fetch_assoc()) {
    $row = sprintf("%-20s %-50s %d\n", $r["account_code"], $r["account_name"], $r["item_count"]);
    echo $row;
    echo "    Property Numbers: " . $r["property_numbers"] . "\n\n";
}
?>
