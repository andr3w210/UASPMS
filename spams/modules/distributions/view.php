<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

function distribution_doc_title(string $type): string
{
    return strtoupper($type) === 'PAR' ? 'PROPERTY ACKNOWLEDGMENT RECEIPT' : 'INVENTORY CUSTODIAN SLIP';
}

$db = db_connect();
$distributionId = (int) ($_GET['id'] ?? 0);
$distribution = null;
$items = [];

if ($db && $distributionId > 0) {
    $headerStmt = $db->prepare("
        SELECT d.id, d.system_reference, d.document_type, d.document_no, d.distribution_date, d.total_amount, d.purpose, d.remarks,
               o.office_name, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name
        FROM distributions d
        INNER JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        WHERE d.id = ?
        LIMIT 1
    ");
    if ($headerStmt) {
        $headerStmt->bind_param('i', $distributionId);
        $headerStmt->execute();
        $distribution = $headerStmt->get_result()->fetch_assoc() ?: null;
        $headerStmt->close();
    }

    if ($distribution) {
        $itemStmt = $db->prepare("
            SELECT di.id, di.quantity_distributed, di.unit_cost, di.line_total, di.remarks,
                   ri.item_condition, poi.line_no, poi.item_type, poi.item_description, ac.account_code, c.classification_name,
                   u.uom_name, u.abbreviation, r.system_reference AS receiving_reference
            FROM distribution_items di
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            INNER JOIN receivings r ON r.id = ri.receiving_id
            LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
            WHERE di.distribution_id = ?
            ORDER BY poi.line_no ASC, di.id ASC
        ");
        if ($itemStmt) {
            $itemStmt->bind_param('i', $distributionId);
            $itemStmt->execute();
            $itemResult = $itemStmt->get_result();
            while ($itemResult && ($item = $itemResult->fetch_assoc())) {
                $detailStmt = $db->prepare("SELECT brand, model, serial_no, remarks FROM distribution_item_details WHERE distribution_item_id = ? ORDER BY id ASC");
                $item['details'] = [];
                if ($detailStmt) {
                    $itemId = (int) $item['id'];
                    $detailStmt->bind_param('i', $itemId);
                    $detailStmt->execute();
                    $detailResult = $detailStmt->get_result();
                    if ($detailResult) {
                        $item['details'] = $detailResult->fetch_all(MYSQLI_ASSOC);
                    }
                    $detailStmt->close();
                }
                $items[] = $item;
            }
            $itemStmt->close();
        }
    }
}

if (!$distribution) {
    http_response_code(404);
    echo 'Distribution record not found.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($distribution['document_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{font-size:12px;background:#fff}
        .print-wrap{max-width:1050px;margin:24px auto;padding:24px}
        .table th,.table td{font-size:12px;vertical-align:top}
        @media print {.no-print{display:none}.print-wrap{margin:0;max-width:none;padding:0}}
    </style>
</head>
<body>
    <div class="print-wrap">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <a href="<?php echo base_url('modules/distributions/index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
        </div>
        <div class="text-center mb-4">
            <div class="fw-bold"><?php echo h(distribution_doc_title((string) $distribution['document_type'])); ?></div>
            <div>University of Antique</div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-4"><strong>Reference:</strong> <?php echo h($distribution['system_reference']); ?></div>
            <div class="col-4"><strong>Document No.:</strong> <?php echo h($distribution['document_no']); ?></div>
            <div class="col-4"><strong>Date:</strong> <?php echo h(date('M d, Y', strtotime($distribution['distribution_date']))); ?></div>
            <div class="col-6"><strong>Office:</strong> <?php echo h($distribution['office_name']); ?></div>
            <div class="col-6"><strong>Accountable Employee:</strong> <?php echo $distribution['employee_no'] ? h(employee_display_name($distribution)) . ' - ' . h($distribution['employee_no']) : 'Not specified'; ?></div>
            <div class="col-12"><strong>Purpose:</strong> <?php echo h($distribution['purpose'] ?: ''); ?></div>
        </div>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th style="width:5%;">Line</th>
                    <th>Description</th>
                    <th style="width:10%;">Qty</th>
                    <th style="width:10%;">Unit Cost</th>
                    <th style="width:10%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $uomLabel = trim((string) ($item['uom_name'] ?? ''));
                    if ($uomLabel === '' && !empty($item['abbreviation'])) {
                        $uomLabel = $item['abbreviation'];
                    } elseif (!empty($item['abbreviation'])) {
                        $uomLabel .= ' (' . $item['abbreviation'] . ')';
                    }
                    ?>
                    <tr>
                        <td><?php echo (int) $item['line_no']; ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo h($item['classification_name'] ?: 'No inventory class'); ?></div>
                            <div><?php echo nl2br(h($item['item_description'])); ?></div>
                            <div class="text-muted"><?php echo h($item['account_code'] ?: ''); ?><?php echo $uomLabel ? ' | ' . h($uomLabel) : ''; ?><?php echo $item['receiving_reference'] ? ' | ' . h($item['receiving_reference']) : ''; ?></div>
                            <div class="text-muted">Condition: <?php echo h($item['item_condition'] ?: ''); ?></div>
                            <?php if (!empty($item['details'])): ?>
                                <div class="mt-2">
                                    <?php foreach ($item['details'] as $detail): ?>
                                        <div>Brand: <?php echo h($detail['brand']); ?> | Model: <?php echo h($detail['model']); ?> | Serial: <?php echo h($detail['serial_no']); ?><?php echo $detail['remarks'] !== '' ? ' | ' . h($detail['remarks']) : ''; ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?php echo h(number_format((float) $item['quantity_distributed'], 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format((float) $item['unit_cost'], 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format((float) $item['line_total'], 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="text-end">Total</th>
                    <th class="text-end"><?php echo h(number_format((float) $distribution['total_amount'], 2)); ?></th>
                </tr>
            </tfoot>
        </table>

        <div class="row mt-5">
            <div class="col-6 text-center">
                <?php if (!empty($distribution['employee_no'])): ?>
                    <div class="fw-semibold"><?php echo h(employee_display_name($distribution)) . ' - ' . h($distribution['employee_no']); ?></div>
                <?php else: ?>
                    <div class="fw-semibold text-muted">Not specified</div>
                <?php endif; ?>
                <div class="border-top pt-2"></div>
                <div class="small text-muted">Signature over Printed Name</div>
            </div>
            <div class="col-6 text-center">
                <div class="border-top pt-2"></div>
                <div class="small text-muted">Supply Officer</div>
            </div>
        </div>
    </div>
</body>
</html>
