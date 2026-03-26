<?php
require_once __DIR__ . '/spams/app/config/init.php';
$db = db_connect();
$ref = '';
if ($db) {
    $res = $db->query("SELECT system_reference FROM stock_items WHERE system_reference IS NOT NULL LIMIT 1");
    if ($res) {
        $r = $res->fetch_assoc();
        if ($r && !empty($r['system_reference'])) {
            $ref = $r['system_reference'];
        }
    }
}
echo $ref;
