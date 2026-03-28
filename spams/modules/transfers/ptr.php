<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$transferId = (int) ($_GET['id'] ?? 0);

if (!$db || $transferId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

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
        CASE WHEN at.source_type = 'system' THEN did.brand ELSE la.brand END AS brand,
        CASE WHEN at.source_type = 'system' THEN did.model ELSE la.model END AS model,
        CASE WHEN at.source_type = 'system' THEN did.serial_no ELSE la.serial_no END AS serial_no,
        CASE WHEN at.source_type = 'system' THEN ri.unit_cost ELSE la.unit_cost END AS amount,
        CASE WHEN at.source_type = 'system' THEN r.received_date ELSE la.acquisition_date END AS date_acquired,
        CASE WHEN at.source_type = 'system' THEN f.fund_code ELSE '' END AS fund_code
    FROM asset_transfers at
    LEFT JOIN offices from_o ON from_o.id = at.from_office_id
    LEFT JOIN offices to_o ON to_o.id = at.to_office_id
    LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
    LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
    LEFT JOIN distribution_item_details did ON did.id = at.distribution_item_detail_id
    LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
    LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
    LEFT JOIN receivings r ON r.id = ri.receiving_id
    LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
    LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
    LEFT JOIN funds f ON f.id = po.fund_id
    LEFT JOIN legacy_assets la ON la.id = at.legacy_asset_id
    WHERE at.id = ?
      AND at.status = 'posted'
    LIMIT 1
");

$transfer = null;
if ($stmt) {
    $stmt->bind_param('i', $transferId);
    $stmt->execute();
    $transfer = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if (!$transfer) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

function ptr_name(array $row, string $prefix): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row[$prefix . 'first_name'] ?? '')),
        trim((string) ($row[$prefix . 'middle_name'] ?? '')),
        trim((string) ($row[$prefix . 'last_name'] ?? '')),
        trim((string) ($row[$prefix . 'suffix_name'] ?? '')),
    ])));
}

$fromOfficer = trim(ptr_name($transfer, 'from_') . (!empty($transfer['from_office_name']) ? ' / ' . $transfer['from_office_name'] : ''));
$toOfficer = trim(ptr_name($transfer, 'to_') . (!empty($transfer['to_office_name']) ? ' / ' . $transfer['to_office_name'] : ''));
$reasonText = trim((string) ($transfer['reason'] ?? ''));
$reasonNormalized = strtolower($reasonText);
$isDonation = str_contains($reasonNormalized, 'donation');
$isRelocate = str_contains($reasonNormalized, 'relocate');
$isReassignment = str_contains($reasonNormalized, 'reassignment');
$isOthers = !$isDonation && !$isRelocate && !$isReassignment;
$description = trim((string) ($transfer['item_description'] ?? ''));
$brandModelSerial = trim(implode(' | ', array_filter([
    trim(trim((string) ($transfer['brand'] ?? '')) . ' ' . trim((string) ($transfer['model'] ?? ''))),
    !empty($transfer['serial_no']) ? 'SN ' . $transfer['serial_no'] : null,
])));
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PTR <?php echo h($transfer['system_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { color:#000; font-family:"Times New Roman", Times, serif; font-size:12px; }
        .ptr-wrap { margin:0 auto; max-width:980px; }
        .ptr-appendix { font-size:11px; font-style:italic; text-align:right; }
        .ptr-title { font-size:18px; font-weight:700; text-align:center; text-transform:uppercase; }
        .ptr-table, .ptr-table td, .ptr-table th { border:1px solid #000 !important; border-collapse:collapse; }
        .ptr-table { width:100%; }
        .ptr-table td, .ptr-table th { padding:.3rem .35rem; vertical-align:top; }
        .ptr-line { border-bottom:1px solid #000; min-height:20px; }
        .no-print { display:block; }
        @media print {
            .no-print { display:none !important; }
            @page { size: legal portrait; margin:0.4in; }
            body { margin:0; }
        }
    </style>
</head>
<body>
<div class="container ptr-wrap">
    <div class="d-flex justify-content-between align-items-center my-3 no-print">
        <div>
            <a href="<?php echo base_url('modules/transfers/index.php'); ?>" class="btn btn-sm btn-outline-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-sm btn-primary">Print</button>
        </div>
    </div>

    <div class="ptr-appendix">Appendix 76</div>
    <div class="ptr-title">Property Transfer Report</div>

    <table class="ptr-table mt-3 mb-3">
        <tr>
            <td colspan="5"><strong>Entity Name :</strong> University of Antique</td>
            <td colspan="2"><strong>Fund Cluster :</strong> <?php echo h($transfer['fund_code'] ?? ''); ?></td>
        </tr>
        <tr>
            <td colspan="5"><strong>From Accountable Officer/Agency/Fund Cluster :</strong> <?php echo h($fromOfficer); ?></td>
            <td><strong>PTR No. :</strong></td>
            <td><?php echo h($transfer['system_reference']); ?></td>
        </tr>
        <tr>
            <td colspan="5"><strong>To Accountable Officer/Agency/Fund Cluster :</strong> <?php echo h($toOfficer); ?></td>
            <td><strong>Date :</strong></td>
            <td><?php echo h(!empty($transfer['transfer_date']) ? date('M d, Y', strtotime((string) $transfer['transfer_date'])) : ''); ?></td>
        </tr>
        <tr>
            <td colspan="7">
                <strong>Transfer Type:</strong> (check only one)
                <span class="ms-3">[<?php echo $isDonation ? '/' : ' '; ?>] Donation</span>
                <span class="ms-3">[<?php echo $isRelocate ? '/' : ' '; ?>] Relocate</span>
                <span class="ms-3">[<?php echo $isReassignment ? '/' : ' '; ?>] Reassignment</span>
                <span class="ms-3">[<?php echo $isOthers ? '/' : ' '; ?>] Others (Specify) <?php echo h($isOthers ? $reasonText : ''); ?></span>
            </td>
        </tr>
    </table>

    <table class="ptr-table mb-3">
        <thead>
            <tr>
                <th style="width:16%;">Date Acquired</th>
                <th style="width:16%;">Property No.</th>
                <th>Description</th>
                <th style="width:14%;" class="text-end">Amount</th>
                <th style="width:18%;">Condition of PPE</th>
            </tr>
        </thead>
        <tbody>
            <tr style="height:260px;">
                <td><?php echo h(!empty($transfer['date_acquired']) ? date('M d, Y', strtotime((string) $transfer['date_acquired'])) : ''); ?></td>
                <td><?php echo h($transfer['property_number'] ?? ''); ?></td>
                <td>
                    <div><?php echo nl2br(h($description)); ?></div>
                    <?php if ($brandModelSerial !== ''): ?>
                        <div class="small text-muted mt-2"><?php echo h($brandModelSerial); ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?php echo h(number_format((float) ($transfer['amount'] ?? 0), 2)); ?></td>
                <td>Good</td>
            </tr>
        </tbody>
    </table>

    <div class="mb-3">
        <strong>Reason for Transfer:</strong>
        <div class="ptr-line mt-1"><?php echo h($reasonText); ?></div>
        <div class="ptr-line"><?php echo h($transfer['remarks'] ?? ''); ?></div>
        <div class="ptr-line">&nbsp;</div>
        <div class="ptr-line">&nbsp;</div>
        <div class="ptr-line">&nbsp;</div>
    </div>

    <table class="ptr-table">
        <tr>
            <td style="width:33%;"><strong>Approved by:</strong></td>
            <td style="width:33%;"><strong>Released/Issued by:</strong></td>
            <td style="width:34%;"><strong>Received by:</strong></td>
        </tr>
        <tr><td>Signature :</td><td>Signature :</td><td>Signature :</td></tr>
        <tr><td>Printed Name :</td><td>Printed Name :</td><td>Printed Name : <?php echo h(ptr_name($transfer, 'to_')); ?></td></tr>
        <tr><td>Designation :</td><td>Designation :</td><td>Designation : <?php echo h($transfer['to_position_title'] ?? ''); ?></td></tr>
        <tr><td>Date :</td><td>Date :</td><td>Date : <?php echo h(!empty($transfer['transfer_date']) ? date('M d, Y', strtotime((string) $transfer['transfer_date'])) : ''); ?></td></tr>
    </table>
</div>
</body>
</html>
