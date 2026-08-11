<?php
require_once __DIR__ . '/../../app/config/init.php';

require_role('Administrator', 'Supply Officer');

$db = db();
$purchaseOrderId = (int) ($_GET['id'] ?? 0);

if (!$db || $purchaseOrderId <= 0) {
    http_response_code(404);
    echo 'Purchase order not found.';
    exit;
}

$stmt = $db->prepare(
    "SELECT po.id, po.system_reference, po.po_number, po.po_date, po.expected_delivery_date, po.delivery_term_days,
            po.place_of_delivery, po.purpose, po.status, s.supplier_name, po.supplier_address
     FROM purchase_orders po
     INNER JOIN suppliers s ON s.id = po.supplier_id
     WHERE po.id = ?
     LIMIT 1"
);
$purchaseOrder = null;
if ($stmt) {
    $stmt->bind_param('i', $purchaseOrderId);
    $stmt->execute();
    $purchaseOrder = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$isOverdue = $purchaseOrder
    && !empty($purchaseOrder['expected_delivery_date'])
    && $purchaseOrder['expected_delivery_date'] < date('Y-m-d')
    && in_array((string) $purchaseOrder['status'], ['encoded', 'partial'], true);

if (!$isOverdue) {
    http_response_code(403);
    echo '<p>A demand letter is available only for an overdue purchase order that is still pending or partially received.</p>';
    echo '<p><a href="' . h(base_url('modules/purchase_orders/index.php')) . '">Back to purchase orders</a></p>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_sent') {
    if (!csrf_verify()) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        exit;
    }
    $sentDate = (string) ($_POST['sent_date'] ?? '');
    $sentDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $sentDate);
    if (!$sentDateObject || $sentDateObject->format('Y-m-d') !== $sentDate) {
        http_response_code(422);
        echo 'A valid sent date is required.';
        exit;
    }
    $logStmt = $db->prepare('INSERT INTO purchase_order_demand_letters (purchase_order_id, sent_date, sent_by) VALUES (?, ?, ?)');
    if (!$logStmt) {
        http_response_code(500);
        echo 'Demand-letter tracking is unavailable. Apply database migration 107.';
        exit;
    }
    $userId = current_user_id();
    $logStmt->bind_param('isi', $purchaseOrderId, $sentDate, $userId);
    $logStmt->execute();
    $letterId = (int) $db->insert_id;
    $logStmt->close();
    redirect('modules/purchase_orders/demand_letter.php?id=' . $purchaseOrderId . '&letter_id=' . $letterId);
}

$letterCount = 0;
$countStmt = $db->prepare('SELECT COUNT(*) AS total FROM purchase_order_demand_letters WHERE purchase_order_id = ?');
if ($countStmt) {
    $countStmt->bind_param('i', $purchaseOrderId);
    $countStmt->execute();
    $letterCount = (int) (($countStmt->get_result()->fetch_assoc() ?: [])['total'] ?? 0);
    $countStmt->close();
}

$deliveryDate = new DateTimeImmutable((string) $purchaseOrder['expected_delivery_date']);
$today = new DateTimeImmutable('today');
$daysOverdue = (int) $deliveryDate->diff($today)->format('%a');
$supplyHead = function_exists('employee_resolve_supply_office_head') ? employee_resolve_supply_office_head($db) : [];
$signatoryName = !empty($supplyHead) && function_exists('employee_display_name')
    ? employee_display_name($supplyHead)
    : '';
$signatoryTitle = trim((string) ($supplyHead['position_title'] ?? ''));
$displayPoNumber = trim((string) $purchaseOrder['po_number']);
if (str_starts_with($displayPoNumber, 'NO-PO-')) {
    $displayPoNumber = (string) $purchaseOrder['system_reference'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demand Letter | <?php echo h($displayPoNumber); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { color: #111; font-family: Arial, sans-serif; background: #f3f4f6; }
        .toolbar { max-width: 8.5in; margin: 18px auto 10px; display: flex; justify-content: space-between; gap: 10px; }
        .letter { background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.13); margin: 0 auto 24px; max-width: 8.5in; min-height: 11in; padding: .8in .9in; font-size: 12pt; line-height: 1.65; }
        .letterhead { text-align: left; margin-bottom: 20px; line-height: 1.1; border-bottom: 1px solid #ed5f36; padding-bottom: 8px; }
        .letterhead .agency { font-weight: 700; font-size: 14pt; }
        .title { font-weight: 700; text-align: center; margin: 22px 0; letter-spacing: .03em; }
        .recipient { margin-bottom: 24px; }
        .subject { margin: 22px 0; font-weight: 700; }
        .body-copy { text-align: justify; margin: 0 0 13px; }
        .signature { margin-top: 28px; margin-left: 3.5in; }
        .signature-name { font-weight: 700; text-transform: uppercase; margin-top: 34px; }
        .detail-table { width:100%; border-collapse:collapse; margin:12px 0; font-size:10pt; text-align:center; }
        .detail-table td,.detail-table th { border:1px solid #111; padding:7px 4px; }
        .detail-table th { font-weight:400; }
        .letterhead img { width:72px; height:72px; object-fit:contain; float:left; margin-right:12px; }
        .letterhead .agency { padding-top:12px; }
        .letterhead small { font-size:9pt; }
        @media print { body { background: #fff; } .toolbar { display: none; } .letter { box-shadow: none; margin: 0; max-width: none; min-height: 0; padding: 0; } }
    </style>
</head>
<body>
    <div class="toolbar d-print-none">
        <a class="btn btn-outline-secondary" href="<?php echo h(base_url('modules/purchase_orders/view.php?id=' . $purchaseOrderId)); ?>">Back to PO</a>
        <div class="d-flex gap-2 align-items-center"><span class="badge text-bg-danger"><?php echo h((string) $letterCount); ?> sent</span><form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="record_sent"><input type="hidden" name="sent_date" value="<?php echo h(date('Y-m-d')); ?>"><button class="btn btn-danger" type="submit">Record as Sent &amp; Print</button></form><button class="btn btn-primary" type="button" onclick="window.print()">Print Preview</button></div>
    </div>
    <main class="letter">
        <header class="letterhead">
            <img src="<?php echo h(base_url('assets/img/ua-logo.png')); ?>" alt="University of Antique logo">
            <div class="agency">UNIVERSITY OF ANTIQUE</div>
            <small>Transforming Lives, Building Communities</small><br>
            <div style="clear:both"></div>
        </header>

        <div class="text-center fw-bold">Supply and Property Management Unit</div>
        <div class="text-center small fst-italic">spmu@antiquespride.edu.ph</div>

        <div><?php echo h(date('F d, Y')); ?></div>
        <div class="recipient">
            <strong><?php echo h($purchaseOrder['supplier_name']); ?></strong><br>
            <?php echo nl2br(h(trim((string) ($purchaseOrder['supplier_address'] ?? '')))); ?>
        </div>

        <p class="mt-4 mb-1">Sir:</p><p>Greetings!</p>
        <p class="body-copy">This office would like to inform you that the delivery period for the procurement specified below has lapsed for <?php echo h((string) $daysOverdue); ?> calendar day(s).</p>
        <table class="detail-table"><thead><tr><th>Purchase Order No.</th><th>PO Date</th><th>Delivery Period</th><th>Last Day of Delivery</th></tr></thead><tbody><tr><td><?php echo h($displayPoNumber); ?></td><td><?php echo h(date('F d, Y', strtotime((string) $purchaseOrder['po_date']))); ?></td><td><?php echo h((string) ((int) $purchaseOrder['delivery_term_days'])); ?> Calendar Days</td><td><?php echo h($deliveryDate->format('F d, Y')); ?></td></tr></tbody></table>
        <p class="body-copy">In this connection, we would like to request you deliver the remaining equipment within ten (10) calendar days to maintain the good standing of your business in the University.</p>
        <p class="body-copy">Please be reminded that you will be charged liquidated damages for every day of your delay. Further, liquidated damages that exceed ten (10) percent of the total contract would possibly mean termination of the contract and imposition of appropriate sanctions by the Bids and Awards Committee (BAC) of this University.</p>
        <p>For your compliance,</p>

        <div class="signature">
            <div class="signature-name"><?php echo h($signatoryName ?: '____________________________'); ?></div>
            <div><?php echo h($signatoryTitle ?: 'Supply Officer'); ?></div>
        </div>
        <div class="mt-4">Noted:</div>
        <div class="signature"><div class="signature-name">____________________________</div><div>Chief Administrative Officer – Admin Services</div></div>
        <div class="mt-4">Received by: ____________________________________<br><span style="margin-left:65px">Signature Over Printed Name</span><br><br><span style="margin-left:48px">Date: _________________________________</span></div>
    </main>
</body>
</html>
