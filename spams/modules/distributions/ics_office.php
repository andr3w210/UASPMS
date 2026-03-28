<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$officeId = (int) ($_GET['office_id'] ?? 0);
$semiType = $_GET['semi_type'] ?? 'all';
if (!in_array($semiType, ['all', 'high_value', 'low_value'], true)) {
    $semiType = 'all';
}
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
$offices = [];
$header = null;
$rows = [];

if ($db) {
    $threshold = get_active_threshold($db);
    $semiHvMin = (float) ($threshold['semi_hv_min'] ?? 5000);
    $poItemSupportsSemiType = function_exists('schema_has_column') ? schema_has_column($db, 'purchase_order_items', 'semi_expendable_type') : false;

    $officeRes = $db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeRes) {
        $offices = $officeRes->fetch_all(MYSQLI_ASSOC);
    }

    if ($officeId > 0) {
        $headStmt = $db->prepare(
            "SELECT o.id, o.office_name, o.office_code, rc.code AS rc_code,
                    e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title
             FROM offices o
             LEFT JOIN responsibility_codes rc ON rc.office_id = o.id
             LEFT JOIN employees e ON e.office_id = o.id AND e.is_unit_head = 1
             WHERE o.id = ?
             LIMIT 1"
        );
        if ($headStmt) {
            $headStmt->bind_param('i', $officeId);
            $headStmt->execute();
            $header = $headStmt->get_result()->fetch_assoc() ?: null;
            $headStmt->close();
        }

        $sql = "SELECT
                    'system' AS source_type,
                    did.property_number,
                    poi.item_description,
                    c.classification_name,
                    c.classification_family,
                    c.useful_life_years,
                    u.abbreviation,
                    ri.unit_cost,
                    did.brand,
                    did.model,
                    did.serial_no
                FROM distribution_item_details did
                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'ics'
                INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
                INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'semi_expendable'
                LEFT JOIN classifications c ON c.id = poi.classification_id
                LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
                WHERE d.office_id = ?
                  AND did.is_distributed = 1
                  AND (did.is_disposed IS NULL OR did.is_disposed = 0)";
        $types = 'i';
        $params = [$officeId];
        if ($semiType !== 'all') {
            if ($poItemSupportsSemiType) {
                $sql .= " AND poi.semi_expendable_type = ?";
                $types .= 's';
                $params[] = $semiType;
            } elseif ($semiType === 'high_value') {
                $sql .= " AND ri.unit_cost >= ?";
                $types .= 'd';
                $params[] = $semiHvMin;
            } else {
                $sql .= " AND ri.unit_cost < ?";
                $types .= 'd';
                $params[] = $semiHvMin;
            }
        }
        $sql .= " ORDER BY poi.item_description ASC, did.id ASC";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $rows[] = $row;
            }
            $stmt->close();
        }

        $legacySql = "SELECT
                        'legacy' AS source_type,
                        la.property_number,
                        la.item_description,
                        c.classification_name,
                        c.classification_family,
                        c.useful_life_years,
                        '' AS abbreviation,
                        la.unit_cost,
                        la.brand,
                        la.model,
                        la.serial_no
                      FROM legacy_assets la
                      LEFT JOIN classifications c ON c.id = la.classification_id
                      WHERE la.is_active = 1
                        AND la.item_type = 'semi_expendable'
                        AND la.office_id = ?";
        $legacyTypes = 'i';
        $legacyParams = [$officeId];
        if ($semiType === 'high_value') {
            $legacySql .= " AND la.unit_cost >= ?";
            $legacyTypes .= 'd';
            $legacyParams[] = $semiHvMin;
        } elseif ($semiType === 'low_value') {
            $legacySql .= " AND la.unit_cost < ?";
            $legacyTypes .= 'd';
            $legacyParams[] = $semiHvMin;
        }
        $legacySql .= " ORDER BY la.item_description ASC, la.id ASC";
        $legacyStmt = $db->prepare($legacySql);
        if ($legacyStmt) {
            $legacyStmt->bind_param($legacyTypes, ...$legacyParams);
            $legacyStmt->execute();
            $res = $legacyStmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $rows[] = $row;
            }
            $legacyStmt->close();
        }
    }
}

$unitHeadName = $header ? employee_display_name($header) : '';
$subtypeLabel = $semiType === 'low_value' ? 'Low Value Semi-Expendable' : ($semiType === 'high_value' ? 'High Value Semi-Expendable' : 'All Semi-Expendable');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ICS by Office</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-size:12px; }
        table { font-size:11px; }
        .appendix { position:absolute; right:24px; top:18px; font-size:12px; }
        .table-bordered td, .table-bordered th { border:1px solid #000 !important; }
        @media print { .no-print { display:none !important; } }
    </style>
</head>
<body>
<div class="container" style="max-width:1000px;">
    <div class="no-print mt-3 mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h4 class="mb-0">ICS Print by Office</h4>
                <div class="small text-muted">Bulk print semi-expendable items currently accountable to one office.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo base_url('modules/distributions/index.php?document_type=ics'); ?>" class="btn btn-outline-secondary">Back to Distribution</a>
                <?php if ($officeId > 0 && $rows): ?>
                    <a href="<?php echo h(base_url('modules/distributions/ics_office.php?office_id=' . $officeId . '&semi_type=' . urlencode($semiType) . '&print=1')); ?>" class="btn btn-primary">Print Current Result</a>
                <?php endif; ?>
            </div>
        </div>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Office</label>
                <select name="office_id" class="form-select" required>
                    <option value="">Select office</option>
                    <?php foreach ($offices as $office): ?>
                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>>
                            <?php echo h($office['office_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Subtype</label>
                <select name="semi_type" class="form-select">
                    <option value="all" <?php echo $semiType === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="high_value" <?php echo $semiType === 'high_value' ? 'selected' : ''; ?>>High Value</option>
                    <option value="low_value" <?php echo $semiType === 'low_value' ? 'selected' : ''; ?>>Low Value</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Load ICS</button>
                <a href="<?php echo base_url('modules/distributions/ics_office.php'); ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>

    <?php if ($officeId > 0 && $header): ?>
        <div class="d-flex justify-content-between align-items-start mt-3 mb-2">
            <div class="appendix">Appendix 59</div>
        </div>
        <div style="text-align:center; margin-bottom:12px; border-bottom:1px solid #000; padding-bottom:8px;">
            <img src="<?php echo h(LOGO_PATH); ?>" style="width:60px; height:60px; object-fit:contain;" alt="UA Logo">
            <div style="font-size:11pt; font-weight:bold; margin-top:4px;">University of Antique</div>
            <div style="font-size:9pt;">Sibalom, Antique</div>
            <div style="font-size:9pt;">Supply and Property Management System</div>
        </div>

        <div class="row mb-2" style="font-size:12px;">
            <div class="col-6">
                <div><strong>Entity Name:</strong> University of Antique</div>
                <div><strong>Fund Cluster:</strong></div>
            </div>
            <div class="col-6 text-end">
                <div><strong>ICS No.:</strong> Office Bulk Print</div>
                <div style="font-size:10pt; color:#555;"><strong>Type:</strong> <?php echo h($subtypeLabel); ?></div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th style="width:8%">Qty</th>
                        <th style="width:6%">Unit</th>
                        <th style="width:10%" class="text-end">Unit Cost</th>
                        <th style="width:10%" class="text-end">Total Cost</th>
                        <th>Description</th>
                        <th style="width:12%">Inventory Item No.</th>
                        <th style="width:12%">Estimated Useful Life</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="text-end">1.00</td>
                            <td><?php echo h($row['abbreviation'] ?: 'unit'); ?></td>
                            <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                            <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                            <td>
                                <?php
                                    $label = trim((!empty($row['classification_family']) ? $row['classification_family'] . ' / ' : '') . ($row['classification_name'] ?? ''));
                                    $desc = trim(($label !== '' ? $label . ' - ' : '') . ($row['item_description'] ?? ''));
                                ?>
                                <?php echo nl2br(h($desc)); ?>
                                <div class="small text-muted">
                                    Brand: <?php echo h($row['brand'] ?? ''); ?> | Model: <?php echo h($row['model'] ?? ''); ?> | Serial No.: <?php echo h($row['serial_no'] ?? ''); ?>
                                </div>
                            </td>
                            <td><?php echo h($row['property_number'] ?? ''); ?></td>
                            <td>
                                <?php
                                    $useful = '—';
                                    if (!empty($row['useful_life_years'])) {
                                        $useful = $row['useful_life_years'] . ' yr' . ((int) $row['useful_life_years'] > 1 ? 's' : '');
                                    }
                                    echo h($useful);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="row mt-4">
            <div class="col-md-6 text-center">
                <div><strong>Received from:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;margin:12px 40px;"></div>
                <div>Signature Over Printed Name</div>
                <div>Name: <?php echo h($unitHeadName); ?></div>
                <div>Position/Office: <?php echo h($header['position_title'] ?? ''); ?> / <?php echo h($header['office_name'] ?? ''); ?></div>
            </div>
            <div class="col-md-6 text-center">
                <div><strong>Received by:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;margin:12px 40px;"></div>
                <div>Signature Over Printed Name</div>
                <div>Position/Office</div>
            </div>
        </div>
    <?php elseif ($officeId > 0): ?>
        <div class="alert alert-info">No ICS items found for the selected office.</div>
    <?php endif; ?>
</div>
<?php if ($autoPrint && $rows): ?><script>window.addEventListener('load', function(){ window.print(); });</script><?php endif; ?>
</body>
</html>
