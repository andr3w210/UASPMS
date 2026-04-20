<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$transferId = (int) ($_GET['id'] ?? 0);
$batchId = (int) ($_GET['batch_id'] ?? 0);

if (!$db || ($transferId <= 0 && $batchId <= 0)) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

function itr_name(array $row, string $prefix): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row[$prefix . 'first_name'] ?? '')),
        trim((string) ($row[$prefix . 'middle_name'] ?? '')),
        trim((string) ($row[$prefix . 'last_name'] ?? '')),
        trim((string) ($row[$prefix . 'suffix_name'] ?? '')),
    ])));
}

function itr_reason_flags(string $reasonText): array
{
    $reasonNormalized = strtolower(trim($reasonText));
    $isDonation = str_contains($reasonNormalized, 'donation');
    $isRelocate = str_contains($reasonNormalized, 'relocate');
    $isReassignment = str_contains($reasonNormalized, 'reassignment');

    return [
        'donation' => $isDonation,
        'relocate' => $isRelocate,
        'reassignment' => $isReassignment,
        'others' => !$isDonation && !$isRelocate && !$isReassignment,
    ];
}

function itr_item_description(array $row): string
{
    $classificationPrefix = trim((string) ($row['classification_name'] ?: $row['classification_family'] ?: ''));
    return trim(($classificationPrefix !== '' ? $classificationPrefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

function itr_item_meta(array $row): string
{
    return trim(implode(' | ', array_filter([
        trim(trim((string) ($row['brand'] ?? '')) . ' ' . trim((string) ($row['model'] ?? ''))),
        !empty($row['serial_no']) ? 'SN ' . $row['serial_no'] : null,
    ])));
}

$header = null;
$items = [];

if ($batchId > 0) {
    $stmt = $db->prepare("
        SELECT
            tb.id,
            tb.system_reference,
            tb.transfer_date,
            tb.reason,
            tb.remarks,
            from_o.office_name AS from_office_name,
            to_o.office_name AS to_office_name,
            from_e.first_name AS from_first_name,
            from_e.middle_name AS from_middle_name,
            from_e.last_name AS from_last_name,
            from_e.suffix_name AS from_suffix_name,
            from_e.position_title AS from_position_title,
            to_e.first_name AS to_first_name,
            to_e.middle_name AS to_middle_name,
            to_e.last_name AS to_last_name,
            to_e.suffix_name AS to_suffix_name,
            to_e.position_title AS to_position_title
        FROM transfer_batches tb
        LEFT JOIN offices from_o ON from_o.id = tb.source_office_id
        LEFT JOIN offices to_o ON to_o.id = tb.to_office_id
        LEFT JOIN employees from_e ON from_e.id = tb.source_employee_id
        LEFT JOIN employees to_e ON to_e.id = tb.to_employee_id
        WHERE tb.id = ?
          AND tb.status = 'posted'
          AND tb.document_type = 'itr'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    if ($header) {
        $stmt = $db->prepare("
            SELECT
                at.property_number,
                CASE WHEN at.source_type = 'system' THEN poi.item_description ELSE la.item_description END AS item_description,
                CASE WHEN at.source_type = 'system' THEN poi.item_type ELSE la.item_type END AS item_type,
                CASE WHEN at.source_type = 'system' THEN did.brand ELSE la.brand END AS brand,
                CASE WHEN at.source_type = 'system' THEN did.model ELSE la.model END AS model,
                CASE WHEN at.source_type = 'system' THEN did.serial_no ELSE la.serial_no END AS serial_no,
                CASE WHEN at.source_type = 'system' THEN ri.unit_cost ELSE la.unit_cost END AS amount,
                CASE WHEN at.source_type = 'system' THEN r.received_date ELSE la.acquisition_date END AS date_acquired,
                CASE WHEN at.source_type = 'system' THEN f.fund_code ELSE '' END AS fund_code,
                CASE WHEN at.source_type = 'system' THEN d.system_reference ELSE '' END AS ics_no,
                CASE WHEN at.source_type = 'system' THEN d.distribution_date ELSE la.acquisition_date END AS ics_date,
                CASE WHEN at.source_type = 'system' THEN c.classification_name ELSE lc.classification_name END AS classification_name,
                CASE WHEN at.source_type = 'system' THEN c.classification_family ELSE lc.classification_family END AS classification_family
            FROM transfer_batch_items tbi
            INNER JOIN asset_transfers at ON at.id = tbi.asset_transfer_id
            LEFT JOIN distribution_item_details did ON did.id = at.distribution_item_detail_id
            LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
            LEFT JOIN distributions d ON d.id = di.distribution_id
            LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
            LEFT JOIN receivings r ON r.id = ri.receiving_id
            LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
            LEFT JOIN funds f ON f.id = po.fund_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN legacy_assets la ON la.id = at.legacy_asset_id
            LEFT JOIN classifications lc ON lc.id = la.classification_id
            WHERE tbi.batch_id = ?
            ORDER BY at.property_number ASC, at.id ASC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $batchId);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
} else {
    $stmt = $db->prepare("
        SELECT
            at.id,
            at.system_reference,
            at.transfer_date,
            at.source_type,
            at.property_number,
            at.reason,
            at.remarks,
            from_o.office_name AS from_office_name,
            to_o.office_name AS to_office_name,
            from_e.first_name AS from_first_name,
            from_e.middle_name AS from_middle_name,
            from_e.last_name AS from_last_name,
            from_e.suffix_name AS from_suffix_name,
            from_e.position_title AS from_position_title,
            to_e.first_name AS to_first_name,
            to_e.middle_name AS to_middle_name,
            to_e.last_name AS to_last_name,
            to_e.suffix_name AS to_suffix_name,
            to_e.position_title AS to_position_title,
            CASE WHEN at.source_type = 'system' THEN poi.item_description ELSE la.item_description END AS item_description,
            CASE WHEN at.source_type = 'system' THEN poi.item_type ELSE la.item_type END AS item_type,
            CASE WHEN at.source_type = 'system' THEN did.brand ELSE la.brand END AS brand,
            CASE WHEN at.source_type = 'system' THEN did.model ELSE la.model END AS model,
            CASE WHEN at.source_type = 'system' THEN did.serial_no ELSE la.serial_no END AS serial_no,
            CASE WHEN at.source_type = 'system' THEN ri.unit_cost ELSE la.unit_cost END AS amount,
            CASE WHEN at.source_type = 'system' THEN r.received_date ELSE la.acquisition_date END AS date_acquired,
            CASE WHEN at.source_type = 'system' THEN d.system_reference ELSE '' END AS ics_no,
            CASE WHEN at.source_type = 'system' THEN d.distribution_date ELSE la.acquisition_date END AS ics_date,
            CASE WHEN at.source_type = 'system' THEN f.fund_code ELSE '' END AS fund_code,
            CASE WHEN at.source_type = 'system' THEN c.classification_name ELSE lc.classification_name END AS classification_name,
            CASE WHEN at.source_type = 'system' THEN c.classification_family ELSE lc.classification_family END AS classification_family
        FROM asset_transfers at
        LEFT JOIN offices from_o ON from_o.id = at.from_office_id
        LEFT JOIN offices to_o ON to_o.id = at.to_office_id
        LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
        LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
        LEFT JOIN distribution_item_details did ON did.id = at.distribution_item_detail_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN distributions d ON d.id = di.distribution_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN receivings r ON r.id = ri.receiving_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN legacy_assets la ON la.id = at.legacy_asset_id
        LEFT JOIN classifications lc ON lc.id = la.classification_id
        WHERE at.id = ?
          AND at.status = 'posted'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    if ($header && ($header['item_type'] ?? '') === 'semi_expendable') {
        $items[] = $header;
    }
}

if (!$header || !$items) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$fromOfficer = trim(itr_name($header, 'from_') . (!empty($header['from_office_name']) ? ' / ' . $header['from_office_name'] : ''));
$toOfficer = trim(itr_name($header, 'to_') . (!empty($header['to_office_name']) ? ' / ' . $header['to_office_name'] : ''));
$reasonText = trim((string) ($header['reason'] ?? ''));
$flags = itr_reason_flags($reasonText);
$fundCode = '';
foreach ($items as $item) {
    if (!empty($item['fund_code'])) {
        $fundCode = (string) $item['fund_code'];
        break;
    }
}
$totalAmount = array_sum(array_map(static fn(array $item): float => (float) ($item['amount'] ?? 0), $items));
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ITR <?php echo h($header['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { color:#000; font-family:"Times New Roman", Times, serif; font-size:12px; }
        .itr-wrap { margin:0 auto; max-width:980px; }
        .itr-appendix { font-size:11px; font-style:italic; text-align:right; }
        .itr-title { font-size:18px; font-weight:700; text-align:center; text-transform:uppercase; }
        .itr-table, .itr-table td, .itr-table th { border:1px solid #000 !important; border-collapse:collapse; }
        .itr-table { width:100%; }
        .itr-table td, .itr-table th { padding:.3rem .35rem; vertical-align:top; }
        .itr-line { border-bottom:1px solid #000; min-height:20px; }
        .no-print { display:block; }
        @media print {
            .no-print { display:none !important; }
            @page { size: legal portrait; margin:0.4in; }
            body { margin:0; }
        }
    </style>
</head>
<body>
<div class="container itr-wrap">
    <div class="d-flex justify-content-between align-items-center my-3 no-print">
        <div>
            <a href="<?php echo base_url('modules/transfers/index.php'); ?>" class="btn btn-sm btn-outline-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-sm btn-primary">Print</button>
        </div>
    </div>

    <div class="itr-appendix">Annex A.5</div>
    <div class="itr-title">Inventory Transfer Report</div>

    <table class="itr-table mt-3 mb-3">
        <tr>
            <td colspan="4"><strong>Entity Name:</strong> University of Antique</td>
            <td colspan="2"><strong>Fund Cluster:</strong> <?php echo h($fundCode); ?></td>
        </tr>
        <tr>
            <td colspan="4"><strong>From Accountable Officer/Agency/Fund Cluster</strong> <?php echo h($fromOfficer); ?></td>
            <td><strong>ITR :</strong></td>
            <td><?php echo h($header['system_reference']); ?></td>
        </tr>
        <tr>
            <td colspan="4"><strong>To Accountable Officer/Agency/Fund Cluster</strong> <?php echo h($toOfficer); ?></td>
            <td><strong>Date :</strong></td>
            <td><?php echo h(!empty($header['transfer_date']) ? date('M d, Y', strtotime((string) $header['transfer_date'])) : ''); ?></td>
        </tr>
        <tr>
            <td colspan="6">
                <strong>Transfer Type:</strong> (check only one)
                <span class="ms-3">[<?php echo $flags['donation'] ? '/' : ' '; ?>] Donation</span>
                <span class="ms-3">[<?php echo $flags['relocate'] ? '/' : ' '; ?>] Relocate</span>
                <span class="ms-3">[<?php echo $flags['reassignment'] ? '/' : ' '; ?>] Reassignment</span>
                <span class="ms-3">[<?php echo $flags['others'] ? '/' : ' '; ?>] Others (Specify) <?php echo h($flags['others'] ? $reasonText : ''); ?></span>
            </td>
        </tr>
    </table>

    <table class="itr-table mb-3">
        <thead>
            <tr>
                <th style="width:14%;">Date Acquired</th>
                <th style="width:12%;">Item No.</th>
                <th style="width:14%;">ICS No./Date</th>
                <th>Description</th>
                <th style="width:12%;" class="text-end">Amount</th>
                <th style="width:16%;">Condition of Inventory</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <?php $meta = itr_item_meta($item); ?>
                <tr>
                    <td><?php echo h(!empty($item['date_acquired']) ? date('M d, Y', strtotime((string) $item['date_acquired'])) : ''); ?></td>
                    <td><?php echo h($item['property_number'] ?? ''); ?></td>
                    <td>
                        <?php echo h($item['ics_no'] ?? ''); ?>
                        <?php if (!empty($item['ics_date'])): ?>
                            <div class="small text-muted"><?php echo h(date('M d, Y', strtotime((string) $item['ics_date']))); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?php echo nl2br(h(itr_item_description($item))); ?></div>
                        <?php if ($meta !== ''): ?>
                            <div class="small text-muted mt-2"><?php echo h($meta); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo h(number_format((float) ($item['amount'] ?? 0), 2)); ?></td>
                    <td>Good</td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="4" class="text-end fw-bold">TOTAL</td>
                <td class="text-end fw-bold"><?php echo h(number_format($totalAmount, 2)); ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="mb-3">
        <strong>Reason/s for Transfer</strong>
        <div class="itr-line mt-1"><?php echo h($reasonText); ?></div>
        <div class="itr-line"><?php echo h($header['remarks'] ?? ''); ?></div>
        <div class="itr-line">&nbsp;</div>
        <div class="itr-line">&nbsp;</div>
    </div>

    <table class="itr-table">
        <tr>
            <td style="width:33%;"><strong>Approved by:</strong></td>
            <td style="width:33%;"><strong>Released/Issued by:</strong></td>
            <td style="width:34%;"><strong>Received by:</strong></td>
        </tr>
        <tr><td>Signature :</td><td>Signature :</td><td>Signature :</td></tr>
        <tr><td>Printed Name :</td><td>Printed Name :</td><td>Printed Name : <?php echo h(itr_name($header, 'to_')); ?></td></tr>
        <tr><td>Designation :</td><td>Designation :</td><td>Designation : <?php echo h($header['to_position_title'] ?? ''); ?></td></tr>
        <tr><td>Date :</td><td>Date :</td><td>Date : <?php echo h(!empty($header['transfer_date']) ? date('M d, Y', strtotime((string) $header['transfer_date'])) : ''); ?></td></tr>
    </table>
</div>
</body>
</html>
