<?php
require __DIR__ . '/../bootstrap.php';
$db=tools_db();
$assetId=(int)($argv[1]??0);if($assetId<=0){exit("Pass legacy asset ID\n");}
foreach(['legacy_assets','inventory_count_items'] as $table){echo "--- {$table} ---\n";$r=$db->query("SHOW COLUMNS FROM {$table}");foreach($r->fetch_all(MYSQLI_ASSOC) as $row){if(in_array($row['Field'],['id','office_id','employee_id','responsibility_code_id','legacy_asset_id','accountable_name','accountability_status'],true))print_r($row);}}
$r=$db->query("SELECT id,office_id,employee_id,responsibility_code_id,accountability_status FROM legacy_assets WHERE id={$assetId}");print_r($r->fetch_assoc());
$r=$db->query("SELECT id,session_id,office_id,employee_id,accountable_name,status FROM inventory_count_items WHERE legacy_asset_id={$assetId}");foreach($r->fetch_all(MYSQLI_ASSOC) as $row)print_r($row);
