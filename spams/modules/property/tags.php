<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
 $db = db_connect();

// Require distribution_id via GET
$distributionId = (int)($_GET['distribution_id'] ?? 0);
if ($distributionId <= 0) {
    http_response_code(404);
    echo 'Distribution ID is required.';
    exit;
}

// Optional: embed logo as base64 for offline/standalone printing
 $logoBase64 = '';
 $logoFile = APP_ROOT . 'assets/img/ua-logo.png';
 if (file_exists($logoFile)) {
     $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile));
 }
// Build units query: return one row per distributed receiving_item_detail
$rows = [];
$havePhpQr = false;
// detect phpqrcode library
$possible = [
    APP_ROOT . 'vendor/phpqrcode/qrlib.php',
    APP_ROOT . 'app/libs/phpqrcode/qrlib.php',
    APP_ROOT . 'includes/qrcode/qrlib.php',
    APP_ROOT . 'lib/phpqrcode/qrlib.php',
];
foreach ($possible as $p) {
    if (file_exists($p)) {
        require_once $p;
        $havePhpQr = true;
        break;
    }
}

if ($db) {
    if ($distributionId > 0) {
        $stmt = $db->prepare(
            "SELECT\n" .
            "    did.id AS did_id,\n" .
            "    si.system_reference,\n" .
            "    d.document_type,\n" .
            "    d.document_no,\n" .
            "    d.distribution_date,\n" .
            "    poi.item_description,\n" .
            "    CONCAT(e.first_name, ' ', e.last_name) AS employee_name,\n" .
            "    o.office_name,\n" .
            "    did.brand,\n" .
            "    did.model,\n" .
            "    did.serial_no\n" .
            "FROM distribution_item_details did\n" .
            "INNER JOIN distribution_items di ON di.id = did.distribution_item_id\n" .
            "INNER JOIN distributions d ON d.id = di.distribution_id\n" .
            "INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id\n" .
            "INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id\n" .
            "LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id\n" .
            "LEFT JOIN stock_items si ON si.id = rid.stock_item_id\n" .
            "LEFT JOIN employees e ON e.id = d.employee_id\n" .
            "LEFT JOIN offices o ON o.id = d.office_id\n" .
            "WHERE d.id = ?\n"
        );
        if ($stmt) {
            $stmt->bind_param('i', $distributionId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    }
}

// Normalize rows and generate QR images (base64) where possible
$units = [];
foreach ($rows as $r) {
    $sysRef = $r['system_reference'] ?? '';
    $scanUrl = rtrim(base_url('modules/property/scan.php'), '/') . '?ref=' . rawurlencode($sysRef);

    $qrBase64 = null;
    $qrUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=160x160&chl=' . rawurlencode($sysRef ?: 'NO-REF') . '&choe=UTF-8';
    if ($havePhpQr && class_exists('QRcode')) {
        ob_start();
        QRcode::png($sysRef ?: 'NO-REF', null, QR_ECLEVEL_M, 6, 2);
        $qrRaw = ob_get_clean();
        if ($qrRaw !== false && $qrRaw !== '') {
            $qrBase64 = base64_encode($qrRaw);
        }
    }
    if ($qrBase64 === null) {
        // try fetching Google Charts PNG and base64-encoding it
        $qrRaw = @file_get_contents($qrUrl);
        if ($qrRaw !== false && $qrRaw !== '') {
            $qrBase64 = base64_encode($qrRaw);
        }
    }

    $units[] = [
        'did_id' => (int) ($r['did_id'] ?? 0),
        'system_reference' => $sysRef,
        'document_type' => $r['document_type'] ?? '',
        'document_no' => $r['document_no'] ?? '',
        'distribution_date' => $r['distribution_date'] ?? '',
        'item_description' => $r['item_description'] ?? '',
        'employee_name' => $r['employee_name'] ?? '',
        'office_name' => $r['office_name'] ?? '',
        'brand' => $r['brand'] ?? '',
        'model' => $r['model'] ?? '',
        'serial_no' => $r['serial_no'] ?? '',
        'qr_base64' => $qrBase64,
        'qr_url' => $qrUrl,
        'scan_url' => $scanUrl,
    ];
}

if (empty($units)) {
    ?><!doctype html>
    <html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Property Tags</title></head><body>
    <div style="padding:16px;font-family:Arial, sans-serif;">
        <p>No units found for the selected filter.</p>
        <p><a href="javascript:history.back()">Back</a></p>
    </div>
    </body></html>
    <?php
    exit;
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Property Tags</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page {
            size: landscape;
            margin: 0;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f0f0f0;
        }
        .no-print { margin:12px 8px; }
        .label-container { width: 100%; height: 100vh; padding: 8px; box-sizing: border-box; display: flex; align-items: center; justify-content: center; }
        .label {
            width: 100%;
            height: 100%;
            background: #fff;
            border: none;
            box-sizing: border-box;
            padding: 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
        }
        .label .header { display:flex; align-items:center; gap:12px; width:100%; justify-content:center; }
        .label .header img { width:80px; height:80px; object-fit:contain; flex-shrink:0; }
        .label .prop-no { font-size:18px; font-weight:700; text-align:center; letter-spacing:0.2pt; }
        .label .desc, .label .meta, .label .brand, .label .serial { font-size:14px; text-align:center; }
        .label .small { font-size:12px; }
        .label .qr { display:flex; justify-content:center; }
        .label hr { border:none; border-top:1px solid #000; margin:6px 0; width:100%; }
        @media print { html, body { margin:0; padding:0; background:white; } .no-print { display:none !important; } .label { page-break-after: always; } .label:last-child { page-break-after: avoid; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn btn-sm btn-primary" onclick="window.print()">Print Labels</button>
        <a class="btn btn-sm btn-secondary" href="javascript:history.back()">Back</a>
        <span style="margin-left:12px;">Label count: <?php echo count($units); ?> label(s) — DK-11201 (29mm x 90mm)</span>
        <div class="mt-2 small text-muted">Set your browser print destination to Brother QL-800. Use Actual Size (100%), margins None/Minimum.</div>
        <div class="mt-1 alert alert-warning small" role="alert">Recommended: Chrome or Edge for best @page size support. Disable "Fit to page" in print dialog.</div>
    </div>

    <div class="label-container">
        <?php foreach ($units as $item):
            $brandModel = trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''));
            $desc = $item['item_description'] ?? '';
            $shortDesc = mb_strlen($desc) > 40 ? mb_substr($desc,0,37) . '...' : $desc;
            $distDate = !empty($item['distribution_date']) ? date('M d, Y', strtotime($item['distribution_date'])) : '';
            $qrSrc = '';
            if (!empty($item['qr_base64'])) {
                $qrSrc = 'data:image/png;base64,' . $item['qr_base64'];
            } else {
                $qrSrc = $item['qr_url'];
            }
        ?>
        <div style="
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            page-break-after: always; break-after: page;
        ">
            <!-- Header: Logo + University Name -->
            <div style="text-align:center; margin-bottom:16px;">
                <?php if (!empty($logoBase64)): ?>
                    <img src="<?php echo h($logoBase64); ?>" style="width:50px; height:50px; object-fit:contain;" alt="UA logo">
                <?php else: ?>
                    <img src="/UASPMS/spams/assets/img/ua-logo.png" style="width:50px; height:50px; object-fit:contain;" alt="UA logo">
                <?php endif; ?>
                <div style="font-size:14px; font-weight:bold;">University of Antique</div>
                <div style="font-size:11px;">Sibalom, Antique</div>
            </div>

            <!-- Body: QR left, Text right -->
                <div style="display:flex; align-items:center; gap:24px; width:100%; max-width:700px;"> 

                <!-- QR Code -->
                <div style="flex-shrink:0;">
                    <?php if (!empty($item['qr_base64'])): ?>
                        <img src="data:image/png;base64,<?php echo h($item['qr_base64']); ?>" style="width:150px; height:150px; display:block;">
                    <?php else: ?>
                        <img src="<?php echo h($item['qr_url']); ?>" style="width:150px; height:150px; display:block;">
                    <?php endif; ?>
                </div>

                <!-- Text Details: show PAR/ICS prominently, remove STK display -->
                <div style="flex:1; text-align:left;">
                    <div style="font-size:18px; font-weight:bold; margin-bottom:4px; word-break:break-word; white-space:normal;">
                        <?php echo (!empty($item['document_type']) && $item['document_type'] === 'par') ? 'PAR No.:' : 'ICS No.:'; ?>
                        <?php echo h($item['document_no'] ?? ''); ?>
                    </div>
                    <div style="font-size:14px; margin-bottom:4px;"><?php echo h($shortDesc); ?></div>
                    <div style="font-size:13px; margin-bottom:2px;"><?php echo h($item['employee_name'] ?? ''); ?></div>
                    <div style="font-size:13px; margin-bottom:2px;"><?php echo h($item['office_name'] ?? ''); ?></div>
                    <div style="font-size:12px; color:#555;">
                        <?php echo h($distDate); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
</body>
</html>
