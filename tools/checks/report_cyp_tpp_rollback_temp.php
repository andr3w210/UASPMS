<?php
require __DIR__ . '/../bootstrap.php';
$db = tools_db();
$queries = [
    'offices' => "SELECT id, office_code, office_name, is_active, office_head_employee_id FROM offices WHERE id IN (77,97)",
    'responsibility_codes' => "SELECT id, office_id, code, description, is_active FROM responsibility_codes WHERE office_id IN (77,97) ORDER BY office_id,id",
    'assignments' => "SELECT ea.id, ea.employee_id, e.first_name, e.last_name, ea.office_id, ea.responsibility_code_id, rc.code, ea.role_title, ea.is_unit_head, ea.is_oic, ea.is_active, ea.updated_at FROM employee_assignments ea JOIN employees e ON e.id=ea.employee_id LEFT JOIN responsibility_codes rc ON rc.id=ea.responsibility_code_id WHERE ea.office_id IN (77,97) ORDER BY ea.office_id,ea.id",
    'legacy' => "SELECT la.id, la.property_number, la.item_description, la.office_id, la.employee_id, la.created_at FROM legacy_assets la WHERE la.office_id IN (77,97) ORDER BY la.office_id,la.id",
    'distributions' => "SELECT d.id, d.document_no, d.document_type, d.office_id, d.employee_id, d.updated_at FROM distributions d WHERE d.office_id IN (77,97) ORDER BY d.office_id,d.id",
    'inventory' => "SELECT id, session_id, property_number, office_id, updated_at FROM inventory_count_items WHERE office_id IN (77,97) ORDER BY office_id,id",
];
foreach ($queries as $label => $sql) {
    echo "--- {$label} ---\n";
    $result = $db->query($sql);
    if (!$result) { echo $db->error . "\n"; continue; }
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) { echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n"; }
}
