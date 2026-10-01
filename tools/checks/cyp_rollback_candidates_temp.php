<?php
require __DIR__ . '/../bootstrap.php';
$db = tools_db();
foreach ([
 'legacy' => "SELECT id, property_number, item_description, office_id, employee_id FROM legacy_assets WHERE office_id=97 AND property_number LIKE '%-CYP' ORDER BY id",
 'distribution_details' => "SELECT d.id AS distribution_id, d.document_no, d.updated_at, did.id AS detail_id, did.property_number, d.office_id FROM distribution_item_details did JOIN distribution_items di ON di.id=did.distribution_item_id JOIN distributions d ON d.id=di.distribution_id WHERE d.office_id=97 AND did.property_number LIKE '%-CYP' ORDER BY d.id,did.id",
 'pins' => "SELECT * FROM office_location_pins WHERE office_id=97",
 'legacy_rc' => "SELECT id, property_number, item_description, office_id, responsibility_code_id, created_at FROM legacy_assets WHERE office_id=97 AND responsibility_code_id IN (76,97) ORDER BY id",
] as $label => $sql) {
 echo "--- {$label} ---\n";
 $r=$db->query($sql); if(!$r){echo $db->error.PHP_EOL;continue;} foreach($r->fetch_all(MYSQLI_ASSOC) as $row){echo json_encode($row,JSON_UNESCAPED_UNICODE).PHP_EOL;}
}
