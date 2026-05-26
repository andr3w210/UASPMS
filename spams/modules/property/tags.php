<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/roles.php';

require_login();

$db = db();
$detailId = (int) ($_GET['detail_id'] ?? 0);
$distributionId = (int) ($_GET['distribution_id'] ?? 0);
$legacyAssetId = (int) ($_GET['legacy_asset_id'] ?? 0);

if ($detailId <= 0 && $distributionId <= 0 && $legacyAssetId <= 0) {
    http_response_code(404);
    echo 'Distribution ID, detail ID, or legacy asset ID is required.';
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
    property_qr_ensure_schema($db);
    if ($legacyAssetId > 0) {
        $stmt = $db->prepare("SELECT
            la.id AS did_id,
            la.property_number,
            la.brand,
            la.model,
            la.serial_no,
            la.qr_tag_code,
            la.system_reference,
            'legacy' AS document_type,
            'Beginning Balance' AS document_no,
            la.acquisition_date AS distribution_date,
            la.acquisition_date AS date_acquired,
            c.classification_name,
            la.item_description,
            o.office_name,
            TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))) AS employee_name
        FROM legacy_assets la
        LEFT JOIN classifications c ON c.id = la.classification_id
        LEFT JOIN offices o ON o.id = la.office_id
        LEFT JOIN employees e ON e.id = la.employee_id
        WHERE la.id = ?
          AND la.is_active = 1
        LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $legacyAssetId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } else {
        $sql =
            "SELECT
            did.id AS did_id,
            did.property_number,
            did.brand,
            did.model,
            did.serial_no,
            did.qr_tag_code,
            si.system_reference,
            d.document_type,
            d.document_no,
            d.distribution_date,
            r.received_date AS date_acquired,
            c.classification_name,
            poi.item_description,
            COALESCE(curr_o.office_name, o.office_name) AS office_name,
            CONCAT(COALESCE(curr_e.first_name, e.first_name, ''), ' ', COALESCE(curr_e.last_name, e.last_name, '')) AS employee_name
         FROM distribution_item_details did
         INNER JOIN distribution_items di ON di.id = did.distribution_item_id
         INNER JOIN distributions d ON d.id = di.distribution_id
         INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
         INNER JOIN receivings r ON r.id = ri.receiving_id
         INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
         LEFT JOIN classifications c ON c.id = poi.classification_id
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
}

$units = [];
foreach ($rows as $row) {
    $sourceType = ($legacyAssetId > 0) ? 'legacy' : 'system';
    $assetId = (int) ($row['did_id'] ?? 0);
    $systemReference = trim((string) ($row['system_reference'] ?? ''));
    $propertyNumber = trim((string) ($row['property_number'] ?? ''));
    $serialNumber = trim((string) ($row['serial_no'] ?? ''));
    $dateAcquiredRaw = trim((string) ($row['date_acquired'] ?? ''));
    $dateAcquiredQr = normalize_date_string($dateAcquiredRaw);
    $displayName = trim((string) ($row['classification_name'] ?? '')) !== ''
        ? trim((string) ($row['classification_name'] ?? ''))
        : trim((string) ($row['item_description'] ?? ''));
    $officeName = trim((string) ($row['office_name'] ?? ''));
    $employeeName = trim((string) ($row['employee_name'] ?? ''));
    $tagCode = property_qr_resolve_tag_code($db, $sourceType, $assetId, (string) ($row['qr_tag_code'] ?? ''));
    $qrPayload = property_qr_build_payload($tagCode, $propertyNumber, $serialNumber, $dateAcquiredQr, $displayName, $officeName, $employeeName);
    $lookupRef = $tagCode !== '' ? $tagCode : ($propertyNumber !== '' ? $propertyNumber : $systemReference);
    $scanUrl = base_url('modules/property/scan.php?ref=' . rawurlencode($lookupRef));

    $qrBase64 = null;
    $qrUrl = 'https://quickchart.io/qr?size=180&text=' . rawurlencode($qrPayload !== '' ? $qrPayload : $lookupRef);

    if ($havePhpQr && class_exists('QRcode') && is_callable(['QRcode', 'png'])) {
        $qrLevel = defined('QR_ECLEVEL_M') ? constant('QR_ECLEVEL_M') : 'M';
        ob_start();
        call_user_func(['QRcode', 'png'], $qrPayload !== '' ? $qrPayload : $lookupRef, null, $qrLevel, 6, 2);
        $qrRaw = ob_get_clean();
        if ($qrRaw !== false && $qrRaw !== '') {
            $qrBase64 = base64_encode($qrRaw);
        }
    }

    $units[] = [
        'did_id' => (int) ($row['did_id'] ?? 0),
        'source_type' => $sourceType,
        'property_number' => $propertyNumber,
        'tag_code' => $tagCode,
        'lookup_ref' => $lookupRef,
        'system_reference' => $systemReference,
        'document_type' => (string) ($row['document_type'] ?? ''),
        'document_no' => (string) ($row['document_no'] ?? ''),
        'distribution_date' => (string) ($row['distribution_date'] ?? ''),
        'date_acquired' => (string) ($row['date_acquired'] ?? ''),
        'classification_name' => (string) ($row['classification_name'] ?? ''),
        'item_description' => (string) ($row['item_description'] ?? ''),
        'employee_name' => $employeeName,
        'office_name' => $officeName,
        'brand' => (string) ($row['brand'] ?? ''),
        'model' => (string) ($row['model'] ?? ''),
        'serial_no' => $serialNumber,
        'qr_base64' => $qrBase64,
        'qr_url' => $qrUrl,
        'qr_payload' => $qrPayload,
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

function property_tag_shorten(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '' || $limit < 1) {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value) > $limit ? rtrim(mb_substr($value, 0, $limit - 3)) . '...' : $value;
    }

    return strlen($value) > $limit ? rtrim(substr($value, 0, $limit - 1)) . '...' : $value;
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
            grid-template-columns: 1fr 30.5mm;
            align-items: center;
            column-gap: 1.1mm;
            padding: 0.35mm 2.5mm 0.35mm 2.9mm;
            overflow: hidden;
            page-break-after: always;
            break-after: page;
        }
        .tag-sheet:last-child {
            page-break-after: avoid;
            break-after: auto;
        }
        .tag-info {
            min-width: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 0.25mm;
            padding: 0.05mm 0.5mm 0.05mm 0;
            text-align: left;
            line-height: 1.04;
        }
        .tag-head {
            display: flex;
            align-items: center;
            gap: 1.1mm;
            min-width: 0;
        }
        .tag-head-logo {
            width: 5.4mm;
            height: 5.4mm;
            object-fit: contain;
            flex: 0 0 auto;
            display: block;
        }
        .tag-head-copy {
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .tag-kicker {
            font-size: 1.95mm;
            font-weight: 700;
            letter-spacing: 0.14mm;
            text-transform: uppercase;
            color: #0f2438;
            margin-bottom: 0.05mm;
        }
        .tag-brandline {
            font-size: 1.62mm;
            font-weight: 700;
            letter-spacing: 0.06mm;
            text-transform: uppercase;
            color: #5b6570;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .tag-number-box {
            border: 0.18mm solid #0f2438;
            border-radius: 1.2mm;
            padding: 0.55mm 0.9mm 0.6mm;
            background: #f7fafc;
        }
        .tag-number-label {
            font-size: 1.58mm;
            font-weight: 700;
            letter-spacing: 0.08mm;
            text-transform: uppercase;
            color: #5f6975;
            margin-bottom: 0.15mm;
        }
        .tag-property-number {
            font-size: 3.35mm;
            font-weight: 700;
            line-height: 1.02;
            color: #081522;
            word-break: break-word;
        }
        .tag-item {
            font-size: 2.58mm;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #18222d;
        }
        .tag-meta-row {
            display: flex;
            align-items: center;
            gap: 0.9mm;
            white-space: nowrap;
            overflow: hidden;
            min-width: 0;
        }
        .tag-meta {
            min-width: 0;
            flex: 1 1 0;
            font-size: 2.04mm;
            color: #334150;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .tag-meta strong {
            font-weight: 700;
            color: #12202f;
        }
        .tag-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1.2mm;
            min-width: 0;
        }
        .tag-pill {
            display: inline-flex;
            align-items: center;
            border: 0.15mm solid #a8b4c0;
            border-radius: 99px;
            padding: 0.2mm 0.9mm 0.25mm;
            font-size: 1.72mm;
            font-weight: 700;
            letter-spacing: 0.04mm;
            text-transform: uppercase;
            color: #34404c;
            background: #fff;
            flex: 0 0 auto;
        }
        .tag-code {
            font-size: 1.84mm;
            font-weight: 700;
            color: #444;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
        }
        .tag-qr {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            height: 100%;
            padding-right: 0;
        }
        .tag-qr-code {
            width: 25.8mm;
            height: 25.8mm;
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
        <div class="mt-2 small text-muted">This QR now encodes the internal tag code with backup property and serial data. No server IP or direct URL is exposed on the label.</div>
    </div>

    <div class="tag-list">
        <?php foreach ($units as $item):
            $propertyLabel = (string) ($item['property_number'] !== '' ? $item['property_number'] : ($item['system_reference'] ?? 'UNASSIGNED'));
            $serialLabel = property_tag_shorten((string) ($item['serial_no'] !== '' ? $item['serial_no'] : 'N/A'), 24);
            $displayName = trim((string) ($item['classification_name'] ?? '')) !== ''
                ? (string) ($item['classification_name'] ?? '')
                : (string) ($item['item_description'] ?? 'Property Asset');
            $itemLabel = property_tag_shorten(ucwords(strtolower($displayName)), 30);
            $dateAcquiredLabel = format_date($item['date_acquired'] ?? null, 'm/d/Y');
            $dateAcquiredLabel = $dateAcquiredLabel !== '' ? $dateAcquiredLabel : 'N/A';
            $qrSrc = !empty($item['qr_base64'])
                ? 'data:image/png;base64,' . $item['qr_base64']
                : $item['qr_url'];
            $uaLogoSrc = $logoBase64 !== '' ? $logoBase64 : '/UASPMS/spams/assets/img/ua-logo.png';
        ?>
            <div class="tag-sheet">
                <div class="tag-info">
                    <div class="tag-head">
                        <img src="<?php echo h($uaLogoSrc); ?>" alt="UA logo" class="tag-head-logo">
                        <div class="tag-head-copy">
                            <div class="tag-kicker">UA Property</div>
                            <div class="tag-brandline">University Of Antique</div>
                        </div>
                    </div>
                    <div class="tag-number-box">
                        <div class="tag-number-label">Property Number</div>
                        <div class="tag-property-number"><?php echo h($propertyLabel); ?></div>
                    </div>
                    <div class="tag-item"><?php echo h($itemLabel); ?></div>
                    <div class="tag-meta-row">
                        <div class="tag-meta"><strong>SN:</strong> <?php echo h($serialLabel); ?></div>
                    </div>
                    <div class="tag-footer">
                        <span class="tag-pill">Scan To Verify</span>
                        <span class="tag-code"><?php echo h('Acq: ' . $dateAcquiredLabel); ?></span>
                    </div>
                </div>

                <div class="tag-qr">
                    <img src="<?php echo h($qrSrc); ?>" alt="QR Code" class="tag-qr-code" crossorigin="anonymous">
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
