<?php
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/config/db.php';

$db = db_connect();
if (!$db) { echo "DB connect failed\n"; exit(1); }
$cols = ['supplier_address','place_of_delivery','delivery_term_days','expected_delivery_date','mode_of_procurement_id','purpose','remarks','updated_by','updated_at'];
foreach ($cols as $c) {
    $res = $db->query("SHOW COLUMNS FROM purchase_orders LIKE '" . $db->real_escape_string($c) . "'");
    if ($res && $res->num_rows > 0) echo "$c: FOUND\n"; else echo "$c: NOT FOUND\n";
}
$db->close();
?>