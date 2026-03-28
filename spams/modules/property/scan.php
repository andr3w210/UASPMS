<?php
// Public property lookup for QR scans — no login required
require_once __DIR__ . '/../../app/config/init.php';

$ref = trim((string) ($_GET['ref'] ?? ''));
if ($ref === '') {
    ?><!doctype html>
    <html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Property Lookup</title></head><body>
    <div style="padding:16px;font-family:Arial, sans-serif;">Property not found for blank reference.</div>
    </body></html>
    <?php
    exit;
}

$db = db_connect();
$row = null;
if ($db) {
    $stmt = $db->prepare(
        "SELECT si.system_reference, si.item_description, si.item_type, si.unit_cost, si.quantity_received,\n" .
        "       poi.item_description AS original_description,\n" .
        "       c.classification_name,\n" .
        "       ac.account_code, ac.account_name,\n" .
        "       u.uom_name,\n" .
        "       r.ris_no, r.received_date,\n" .
        "       po.po_number,\n" .
        "       s.supplier_name,\n" .
        "       did.brand, did.model, did.serial_no,\n" .
        "       d.document_no, d.document_type, d.distribution_date,\n" .
        "       o.office_name,\n" .
        "       e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title\n" .
        "FROM stock_items si\n" .
        "LEFT JOIN receiving_items ri ON ri.id = si.receiving_item_id\n" .
        "LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id\n" .
        "LEFT JOIN classifications c ON c.id = si.classification_id\n" .
        "LEFT JOIN account_codes ac ON ac.id = si.account_code_id\n" .
        "LEFT JOIN unit_of_measures u ON u.id = si.unit_of_measure_id\n" .
        "LEFT JOIN receivings r ON r.id = si.receiving_id\n" .
        "LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id\n" .
        "LEFT JOIN suppliers s ON s.id = po.supplier_id\n" .
        "LEFT JOIN distribution_item_details did ON did.receiving_item_detail_id = (\n" .
        "    SELECT id FROM receiving_item_details WHERE receiving_item_id = ri.id LIMIT 1\n" .
        ")\n" .
        "LEFT JOIN distribution_items di ON di.id = did.distribution_item_id\n" .
        "LEFT JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'\n" .
        "LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)\n" .
        "LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)\n" .
        "WHERE si.system_reference = ?\n" .
        "LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('s', $ref);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if (!$row) {
        $legacyStmt = $db->prepare(
            "SELECT la.system_reference, la.property_number AS item_description, 'equipment' AS item_type, la.acquisition_cost AS unit_cost, 1 AS quantity_received,
                    la.item_description AS original_description, c.classification_name, ac.account_code, ac.account_name, '' AS uom_name,
                    '' AS ris_no, la.acquisition_date AS received_date, '' AS po_number, '' AS supplier_name,
                    la.brand, la.model, la.serial_no, 'Beginning Balance' AS document_no, 'legacy' AS document_type,
                    la.acquisition_date AS distribution_date, o.office_name,
                    e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title
             FROM legacy_assets la
             LEFT JOIN classifications c ON c.id = la.classification_id
             LEFT JOIN account_codes ac ON ac.id = la.account_code_id
             LEFT JOIN offices o ON o.id = la.office_id
             LEFT JOIN employees e ON e.id = la.employee_id
             WHERE la.property_number = ?
             LIMIT 1"
        );
        if ($legacyStmt) {
            $legacyStmt->bind_param('s', $ref);
            $legacyStmt->execute();
            $legacyRes = $legacyStmt->get_result();
            $row = $legacyRes ? $legacyRes->fetch_assoc() : null;
            $legacyStmt->close();
        }
    }
}

function employee_display_name_from_row($row) {
    if (function_exists('employee_display_name')) {
        return employee_display_name($row);
    }
    $parts = [trim($row['first_name'] ?? ''), trim($row['middle_name'] ?? ''), trim($row['last_name'] ?? ''), trim($row['suffix_name'] ?? '')];
    return trim(implode(' ', array_filter($parts)));
}

if (!$row) {
    ?><!doctype html>
    <html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Property Not Found</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body>
    <div class="container py-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Property not found</h5>
                <p class="card-text">Reference: <?php echo h($ref); ?></p>
                <a href="javascript:history.back()" class="btn btn-sm btn-secondary">Back</a>
            </div>
        </div>
    </div>
    </body></html>
    <?php
    exit;
}

$empName = employee_display_name_from_row($row);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Property <?php echo h($row['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{padding:12px;font-family:Arial, sans-serif;} .card{max-width:760px;margin:0 auto;} .kv {font-weight:600;width:140px;display:inline-block}</style>
</head>
<body>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">University of Antique — Property Information</h5>
            <hr>
            <div class="mb-2"><span class="kv">Property No:</span> <?php echo h($row['system_reference'] ?: $ref); ?></div>
            <div class="mb-2"><span class="kv">Description:</span> <?php echo h($row['item_description'] ?? $row['original_description']); ?></div>
            <div class="mb-2"><span class="kv">Classification:</span> <?php echo h($row['classification_name'] ?? ''); ?></div>
            <div class="mb-2"><span class="kv">Account Code:</span> <?php echo h($row['account_code'] ?? '') . ' ' . h($row['account_name'] ?? ''); ?></div>
            <div class="mb-2"><span class="kv">Type:</span> <?php echo h($row['item_type'] ?? ''); ?></div>
            <div class="mb-2"><span class="kv">Brand / Model:</span> <?php echo h(trim(($row['brand'] ?? '') . ' / ' . ($row['model'] ?? ''))); ?></div>
            <div class="mb-2"><span class="kv">Serial No:</span> <?php echo h($row['serial_no'] ?? ''); ?></div>
            <div class="mb-2"><span class="kv">Unit Cost:</span> <?php echo isset($row['unit_cost']) ? h(number_format((float)$row['unit_cost'],2)) : ''; ?></div>
            <div class="mb-2"><span class="kv">Date Acquired:</span> <?php echo h(!empty($row['received_date']) ? date('M d, Y', strtotime($row['received_date'])) : ''); ?></div>
            <div class="mb-2"><span class="kv">Supplier:</span> <?php echo h($row['supplier_name'] ?? ''); ?></div>
            <div class="mb-2"><span class="kv">PO Number:</span> <?php echo h($row['po_number'] ?? ''); ?></div>

            <hr>
            <h6>Current Assignment</h6>
            <div class="mb-2"><span class="kv">Document:</span> <?php echo h($row['document_type'] ? ($row['document_type'] . ' No. ' . ($row['document_no'] ?? '')) : ''); ?></div>
            <div class="mb-2"><span class="kv">Office:</span> <?php echo h($row['office_name'] ?? ''); ?></div>
            <div class="mb-2"><span class="kv">Accountable:</span> <?php echo h($empName); ?></div>
            <div class="mb-2"><span class="kv">Position:</span> <?php echo h($row['position_title'] ?? ''); ?></div>
            <div class="mb-2"><span class="kv">Issued:</span> <?php echo h(!empty($row['distribution_date']) ? date('M d, Y', strtotime($row['distribution_date'])) : ''); ?></div>
        </div>
    </div>
</body>
</html>
