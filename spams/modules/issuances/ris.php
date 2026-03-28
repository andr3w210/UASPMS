<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$issuanceId = (int) ($_GET['id'] ?? 0);

if (!$db || $issuanceId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

// HEADER QUERY
$headerStmt = $db->prepare(
    "SELECT i.id, i.system_reference, i.issuance_date, i.purpose, i.remarks,\n" .
    "       o.office_name, o.office_code,\n" .
    "       dep.name AS division_name,\n" .
    "       rc.code AS responsibility_center_code,\n" .
    "       f.fund_code,\n" .
    "       e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name,\n" .
    "       e.position_title\n" .
    "FROM issuances i\n" .
    "LEFT JOIN offices o ON o.id = i.office_id\n" .
    "LEFT JOIN departments dep ON dep.id = o.department_id\n" .
    "LEFT JOIN responsibility_codes rc ON rc.office_id = o.id\n" .
    "LEFT JOIN employees e ON e.id = i.employee_id\n" .
    "LEFT JOIN funds f ON f.id = (\n" .
    "    SELECT po.fund_id\n" .
    "    FROM issuance_items ii\n" .
    "    INNER JOIN stock_items si ON si.id = ii.stock_item_id\n" .
    "    LEFT JOIN receivings r ON r.id = si.receiving_id\n" .
    "    LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id\n" .
    "    WHERE ii.issuance_id = i.id\n" .
    "    LIMIT 1\n" .
    ")\n" .
    "WHERE i.id = ?\n" .
    "LIMIT 1"
);

$header = null;
if ($headerStmt) {
    $headerStmt->bind_param('i', $issuanceId);
    $headerStmt->execute();
    $header = $headerStmt->get_result()->fetch_assoc() ?: null;
    $headerStmt->close();
}

if (!$header) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

// ITEMS QUERY
$itemStmt = $db->prepare(
    "SELECT ii.id, ii.quantity_issued, ii.remarks,\n" .
    "       si.system_reference AS stock_no,\n" .
    "       si.item_description, si.unit_cost, si.item_type,\n" .
    "       u.uom_name, u.abbreviation\n" .
    "FROM issuance_items ii\n" .
    "INNER JOIN stock_items si ON si.id = ii.stock_item_id\n" .
    "LEFT JOIN unit_of_measures u ON u.id = si.unit_of_measure_id\n" .
    "WHERE ii.issuance_id = ?\n" .
    "ORDER BY ii.id ASC"
);

$items = [];
if ($itemStmt) {
    $itemStmt->bind_param('i', $issuanceId);
    $itemStmt->execute();
    $res = $itemStmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $items[] = $r;
    }
    $itemStmt->close();
}

// Helper: employee display name (use existing helper if available)
$receivedByName = '';
if (function_exists('employee_display_name')) {
    $receivedByName = employee_display_name($header);
} else {
    $receivedByName = trim(($header['first_name'] ?? '') . ' ' . ($header['middle_name'] ?? '') . ' ' . ($header['last_name'] ?? '') . ' ' . ($header['suffix_name'] ?? ''));
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>RIS <?php echo h($header['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-size:12px; }
        table { font-size:11px; }
        .no-print { display:block; }
        @media print { .no-print { display:none; } }
        .appendix { position:absolute; right:24px; top:18px; font-size:12px; }
        .table-bordered td, .table-bordered th { border:1px solid #000 !important; }
    </style>
</head>
<body>
    <div class="container" style="max-width:1000px;">
        <div class="d-flex justify-content-between align-items-start mt-3 mb-2">
            <div>
                <a href="<?php echo base_url('modules/issuances/index.php'); ?>" class="btn btn-sm btn-outline-secondary no-print">Back</a>
                <button onclick="window.print()" class="btn btn-sm btn-primary no-print">Print</button>
            </div>
            <div class="appendix">Appendix 63</div>
        </div>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px; border-bottom:1px solid #000; padding-bottom:8px;">
            <img src="<?php echo h(LOGO_PATH); ?>" style="width:60px; height:60px; object-fit:contain;" alt="UA Logo">
            <div style="text-align:center; flex:1;">
                <div style="font-size:11pt; font-weight:bold;">University of Antique</div>
                <div style="font-size:9pt;">Sibalom, Antique</div>
                <div style="font-size:9pt;">Supply and Property Management System</div>
            </div>
            <div style="width:60px;"></div>
        </div>

        <div class="row mb-2" style="font-size:12px;">
            <div class="col-6">
                <div><strong>Entity Name:</strong> University of Antique</div>
                <div><strong>Division:</strong> <?php echo h($header['division_name'] ?? ''); ?></div>
                <div><strong>Office:</strong> <?php echo h($header['office_name'] ?? ''); ?></div>
            </div>
            <div class="col-6 text-end">
                <div><strong>Fund Cluster:</strong> <?php echo h($header['fund_code'] ?? ''); ?></div>
                <div><strong>Responsibility Center Code:</strong> <?php echo h($header['responsibility_center_code'] ?? ''); ?></div>
                <div><strong>RIS No.:</strong> <?php echo h($header['system_reference'] ?? ''); ?></div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th style="width:12%">Stock No.</th>
                        <th style="width:6%">Unit</th>
                        <th>Description</th>
                        <th style="width:10%" class="text-end">Qty Requested</th>
                        <th style="width:6%" class="text-center">Stock Available? Yes</th>
                        <th style="width:6%" class="text-center">No</th>
                        <th style="width:10%" class="text-end">Qty Issued</th>
                        <th style="width:12%">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it):
                        $unitLabel = trim((string) ($it['abbreviation'] ?? $it['uom_name'] ?? ''));
                        $qty = (float) ($it['quantity_issued'] ?? 0);
                    ?>
                    <tr>
                        <td><?php echo h($it['stock_no'] ?? ''); ?></td>
                        <td><?php echo h($unitLabel); ?></td>
                        <td><?php echo nl2br(h($it['item_description'] ?? '')); ?></td>
                        <td class="text-end"><?php echo h(number_format($qty, 2)); ?></td>
                        <td class="text-center">v</td>
                        <td class="text-center"></td>
                        <td class="text-end"><?php echo h(number_format($qty, 2)); ?></td>
                        <td><?php echo h($it['remarks'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-3"><strong>Purpose:</strong> <?php echo h($header['purpose'] ?? ''); ?></div>

        <div class="row mt-4 text-center">
            <div class="col-3">
                <div style="height:60px;border-bottom:1px solid #000;margin-bottom:6px"></div>
                <div class="small">Requested by</div>
                <div class="mt-1">&nbsp;</div>
                <div class="small">&nbsp;</div>
                <div class="small">&nbsp;</div>
            </div>
            <div class="col-3">
                <div style="height:60px;border-bottom:1px solid #000;margin-bottom:6px"></div>
                <div class="small">Approved by</div>
                <div class="mt-1">&nbsp;</div>
                <div class="small">&nbsp;</div>
                <div class="small">&nbsp;</div>
            </div>
            <div class="col-3">
                <div style="height:60px;border-bottom:1px solid #000;margin-bottom:6px"></div>
                <div class="small">Issued by</div>
                <div class="mt-1">&nbsp;</div>
                <div class="small">&nbsp;</div>
                <div class="small">&nbsp;</div>
            </div>
            <div class="col-3">
                <div style="height:60px;border-bottom:1px solid #000;margin-bottom:6px"></div>
                <div class="small">Received by</div>
                <div class="mt-1"><strong><?php echo h($receivedByName); ?></strong></div>
                <div class="small"><?php echo h($header['position_title'] ?? ''); ?></div>
                <div class="small"><?php echo h(date('M d, Y', strtotime($header['issuance_date'] ?? ''))); ?></div>
            </div>
        </div>

    </div>
</body>
</html>
