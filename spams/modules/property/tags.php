<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/roles.php';

require_login();

$db = db_connect();
$detailId = (int) ($_GET['detail_id'] ?? 0);
$distributionId = (int) ($_GET['distribution_id'] ?? 0);

if ($detailId <= 0 && $distributionId <= 0) {
    http_response_code(404);
    echo 'Distribution ID or detail ID is required.';
    exit;
}

$logoBase64 = '';
$logoFile = APP_ROOT . 'assets/img/ua-logo.png';
if (file_exists($logoFile)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile));
}

$rows = [];
$havePhpQr = false;
$possible = [
    APP_ROOT . 'vendor/phpqrcode/qrlib.php',
    APP_ROOT . 'app/libs/phpqrcode/qrlib.php',
    APP_ROOT . 'includes/qrcode/qrlib.php',
    APP_ROOT . 'lib/phpqrcode/qrlib.php',
];
foreach ($possible as $path) {
    if (file_exists($path)) {
        require_once $path;
        $havePhpQr = true;
        break;
    }
}

if ($db) {
    ensure_distribution_item_runtime_columns($db);

    $sql =
        "SELECT
            did.id AS did_id,
            did.property_number,
            did.brand,
            did.model,
            did.serial_no,
            si.system_reference,
            d.document_type,
            d.document_no,
            d.distribution_date,
            r.received_date AS date_acquired,
            poi.item_description,
            COALESCE(curr_o.office_name, o.office_name) AS office_name,
            CONCAT(COALESCE(curr_e.first_name, e.first_name, ''), ' ', COALESCE(curr_e.last_name, e.last_name, '')) AS employee_name
         FROM distribution_item_details did
         INNER JOIN distribution_items di ON di.id = did.distribution_item_id
         INNER JOIN distributions d ON d.id = di.distribution_id
         INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
         INNER JOIN receivings r ON r.id = ri.receiving_id
         INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
         LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
         LEFT JOIN stock_items si ON si.id = rid.stock_item_id
         LEFT JOIN offices o ON o.id = d.office_id
         LEFT JOIN employees e ON e.id = d.employee_id
         LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
         LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id";

    if ($detailId > 0) {
        $stmt = $db->prepare($sql . " WHERE did.id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $detailId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } elseif ($distributionId > 0) {
        $stmt = $db->prepare($sql . " WHERE d.id = ?");
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

$units = [];
foreach ($rows as $row) {
    $systemReference = trim((string) ($row['system_reference'] ?? ''));
    $propertyNumber = trim((string) ($row['property_number'] ?? ''));
    $lookupRef = $propertyNumber !== '' ? $propertyNumber : $systemReference;
    $scanUrl = rtrim(base_url('modules/property/scan.php'), '/') . '?ref=' . rawurlencode($lookupRef);

    $qrBase64 = null;
    $qrUrl = 'https://quickchart.io/qr?size=180&text=' . rawurlencode($scanUrl);

    if ($havePhpQr && class_exists('QRcode')) {
        ob_start();
        QRcode::png($scanUrl, null, QR_ECLEVEL_M, 6, 2);
        $qrRaw = ob_get_clean();
        if ($qrRaw !== false && $qrRaw !== '') {
            $qrBase64 = base64_encode($qrRaw);
        }
    }

    $units[] = [
        'did_id' => (int) ($row['did_id'] ?? 0),
        'property_number' => $propertyNumber,
        'lookup_ref' => $lookupRef,
        'system_reference' => $systemReference,
        'document_type' => (string) ($row['document_type'] ?? ''),
        'document_no' => (string) ($row['document_no'] ?? ''),
        'distribution_date' => (string) ($row['distribution_date'] ?? ''),
        'date_acquired' => (string) ($row['date_acquired'] ?? ''),
        'item_description' => (string) ($row['item_description'] ?? ''),
        'employee_name' => trim((string) ($row['employee_name'] ?? '')),
        'office_name' => (string) ($row['office_name'] ?? ''),
        'brand' => (string) ($row['brand'] ?? ''),
        'model' => (string) ($row['model'] ?? ''),
        'serial_no' => (string) ($row['serial_no'] ?? ''),
        'qr_base64' => $qrBase64,
        'qr_url' => $qrUrl,
        'scan_url' => $scanUrl,
    ];
}

if (!$units) {
    ?><!doctype html>
    <html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Property Tags</title></head><body>
    <div style="padding:16px;font-family:Arial,sans-serif;">
        <p>No units found for the selected filter.</p>
        <p><a href="javascript:history.back()">Back</a></p>
    </div>
    </body></html><?php
    exit;
}

function property_tag_office_short(string $officeName): string
{
    $officeName = trim($officeName);
    if ($officeName === '') {
        return '';
    }

    $clean = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $officeName);
    $words = preg_split('/\s+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return strtoupper($officeName);
    }

    $skip = ['and', 'of', 'the', 'for', 'in', 'on', 'at'];
    $letters = '';
    foreach ($words as $word) {
        if (in_array(strtolower($word), $skip, true)) {
            continue;
        }
        $letters .= strtoupper(substr($word, 0, 1));
    }

    if ($letters === '') {
        $letters = strtoupper(substr(preg_replace('/\s+/', '', $officeName), 0, 5));
    }

    return substr($letters, 0, 6);
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Property Tags</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page { size: 90mm 29mm; margin: 0; }
        html, body {
            margin: 0;
            padding: 0;
            background: #f4f4f4;
            font-family: Arial, Helvetica, sans-serif;
        }
        .no-print { margin: 12px 10px; }
        .tag-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
            padding: 10px;
        }
        .tag-sheet {
            width: 90mm;
            height: 29mm;
            box-sizing: border-box;
            background: #fff;
            color: #000;
            border: 0.15mm solid #d8d8d8;
            display: grid;
            grid-template-columns: 17.8mm 1fr 33.8mm;
            align-items: center;
            column-gap: 0.1mm;
            padding: 0.4mm 0.8mm 0.5mm 0.8mm;
            overflow: hidden;
            page-break-after: always;
            break-after: page;
        }
        .tag-sheet:last-child {
            page-break-after: avoid;
            break-after: auto;
        }
        .tag-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }
        .tag-logo img {
            width: 17.8mm;
            height: 17.8mm;
            object-fit: contain;
        }
        .tag-info {
            min-width: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0 0.8mm;
            text-align: center;
            line-height: 1.06;
        }
        .tag-line-label {
            font-size: 2.35mm;
            font-weight: 700;
        }
        .tag-line-value {
            font-size: 2.85mm;
            font-weight: 700;
            margin-bottom: 0.45mm;
            word-break: break-word;
        }
        .tag-line-value.compact {
            font-size: 2.45mm;
            margin-bottom: 0;
        }
        .tag-qr {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding-right: 0.2mm;
        }
        .tag-qr img {
            width: 29mm;
            height: 29mm;
            object-fit: contain;
            display: block;
        }
        @media print {
            html, body {
                background: #fff;
            }
            .no-print {
                display: none !important;
            }
            .tag-list {
                padding: 0;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn btn-sm btn-primary" onclick="window.print()">Print Labels</button>
        <a class="btn btn-sm btn-secondary" href="javascript:history.back()">Back</a>
        <span style="margin-left:12px;">Label count: <?php echo count($units); ?> label(s) - DK-11201 (29mm x 90mm)</span>
        <div class="mt-2 small text-muted">QR uses the public asset lookup page and is sized for a 29mm x 90mm label.</div>
    </div>

    <div class="tag-list">
        <?php foreach ($units as $item):
            $dateAcquired = !empty($item['date_acquired']) ? date('m/d/Y', strtotime($item['date_acquired'])) : '';
            $officeLabel = property_tag_office_short((string) ($item['office_name'] ?? ''));
            $qrSrc = !empty($item['qr_base64'])
                ? 'data:image/png;base64,' . $item['qr_base64']
                : $item['qr_url'];
        ?>
            <div class="tag-sheet">
                <div class="tag-logo">
                    <?php if ($logoBase64 !== ''): ?>
                        <img src="<?php echo h($logoBase64); ?>" alt="UA logo">
                    <?php else: ?>
                        <img src="/UASPMS/spams/assets/img/ua-logo.png" alt="UA logo">
                    <?php endif; ?>
                </div>

                <div class="tag-info">
                    <div class="tag-line-label">Date Acquired</div>
                    <div class="tag-line-value"><?php echo h($dateAcquired); ?></div>
                    <div class="tag-line-label">Location</div>
                    <div class="tag-line-value compact"><?php echo h($officeLabel); ?></div>
                </div>

                <div class="tag-qr">
                    <img src="<?php echo h($qrSrc); ?>" alt="QR Code" crossorigin="anonymous">
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
