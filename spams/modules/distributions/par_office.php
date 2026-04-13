<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$officeId = (int) ($_GET['office_id'] ?? 0);
$legacyAssetId = (int) ($_GET['legacy_asset_id'] ?? 0);
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
$offices = [];
$header = null;
$rows = [];
$validationError = '';

if ($db) {
    $officeRes = $db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeRes) {
        $offices = $officeRes->fetch_all(MYSQLI_ASSOC);
    }

    if ($legacyAssetId > 0 && $officeId <= 0) {
        $legacyOfficeStmt = $db->prepare("SELECT office_id FROM legacy_assets WHERE id = ? LIMIT 1");
        if ($legacyOfficeStmt) {
            $legacyOfficeStmt->bind_param('i', $legacyAssetId);
            $legacyOfficeStmt->execute();
            $legacyOffice = $legacyOfficeStmt->get_result()->fetch_assoc();
            $legacyOfficeStmt->close();
            $officeId = (int) ($legacyOffice['office_id'] ?? 0);
        }
    }

    if ($legacyAssetId > 0) {
        $legacyValidationStmt = $db->prepare(
            "SELECT property_number, item_description, office_id, employee_id, item_type
             FROM legacy_assets
             WHERE id = ?
             LIMIT 1"
        );
        if ($legacyValidationStmt) {
            $legacyValidationStmt->bind_param('i', $legacyAssetId);
            $legacyValidationStmt->execute();
            $legacyRow = $legacyValidationStmt->get_result()->fetch_assoc() ?: null;
            $legacyValidationStmt->close();

            if (!$legacyRow) {
                $validationError = 'Legacy asset record not found for printing.';
            } else {
                $missing = [];
                if (trim((string) ($legacyRow['property_number'] ?? '')) === '') {
                    $missing[] = 'Property Number';
                }
                if (trim((string) ($legacyRow['item_description'] ?? '')) === '') {
                    $missing[] = 'Description';
                }
                if ((int) ($legacyRow['office_id'] ?? 0) <= 0) {
                    $missing[] = 'Office Assignment';
                }
                if ((int) ($legacyRow['employee_id'] ?? 0) <= 0) {
                    $missing[] = 'Accountable Employee';
                }
                if ($missing) {
                    $validationError = 'Printing is blocked. Complete this legacy asset first: ' . implode(', ', $missing) . '.';
                } elseif (($legacyRow['item_type'] ?? '') !== 'equipment') {
                    $validationError = 'PAR printing is allowed for legacy equipment assets only.';
                }
            }
        }
    }

    if (($officeId > 0 || $legacyAssetId > 0) && $validationError === '') {
        $headStmt = $db->prepare(
            "SELECT o.id, o.office_name, o.office_code, rc.code AS rc_code,
                    e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title
             FROM offices o
             LEFT JOIN responsibility_codes rc ON rc.office_id = o.id
             LEFT JOIN employees e ON e.office_id = o.id AND e.is_unit_head = 1
             WHERE o.id = ?
             LIMIT 1"
        );
        if ($headStmt && $officeId > 0) {
            $headStmt->bind_param('i', $officeId);
            $headStmt->execute();
            $header = $headStmt->get_result()->fetch_assoc() ?: null;
            $headStmt->close();
        }

        if (!$header && $legacyAssetId > 0) {
            $fallbackStmt = $db->prepare(
                "SELECT o.id, COALESCE(o.office_name, 'Unassigned Office') AS office_name, o.office_code
                 FROM legacy_assets la
                 LEFT JOIN offices o ON o.id = la.office_id
                 WHERE la.id = ?
                 LIMIT 1"
            );
            if ($fallbackStmt) {
                $fallbackStmt->bind_param('i', $legacyAssetId);
                $fallbackStmt->execute();
                $fallback = $fallbackStmt->get_result()->fetch_assoc() ?: [];
                $fallbackStmt->close();
                $header = [
                    'id' => (int) ($fallback['id'] ?? 0),
                    'office_name' => (string) ($fallback['office_name'] ?? 'Unassigned Office'),
                    'office_code' => (string) ($fallback['office_code'] ?? ''),
                    'rc_code' => '',
                    'first_name' => '',
                    'middle_name' => '',
                    'last_name' => '',
                    'suffix_name' => '',
                    'position_title' => '',
                ];
            }
        }

        if ($legacyAssetId <= 0) {
            $systemStmt = $db->prepare(
            "SELECT
                'system' AS source_type,
                did.property_number,
                poi.item_description,
                c.classification_name,
                c.classification_family,
                u.abbreviation,
                ri.unit_cost,
                r.received_date AS date_acquired,
                did.brand,
                did.model,
                did.serial_no
             FROM distribution_item_details did
             INNER JOIN distribution_items di ON di.id = did.distribution_item_id
             INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'par'
             INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
             INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
             INNER JOIN receivings r ON r.id = ri.receiving_id
             LEFT JOIN classifications c ON c.id = poi.classification_id
             LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
             WHERE d.office_id = ?
               AND did.is_distributed = 1
               AND (did.is_disposed IS NULL OR did.is_disposed = 0)
             ORDER BY did.property_number ASC, did.id ASC"
            );
            if ($systemStmt) {
                $systemStmt->bind_param('i', $officeId);
                $systemStmt->execute();
                $res = $systemStmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $rows[] = $row;
                }
                $systemStmt->close();
            }
        }

        if ($legacyAssetId > 0) {
            $legacyStmt = $db->prepare(
                "SELECT
                    'legacy' AS source_type,
                    la.property_number,
                    la.item_description,
                    c.classification_name,
                    c.classification_family,
                    '' AS abbreviation,
                    la.unit_cost,
                    la.acquisition_date AS date_acquired,
                    la.brand,
                    la.model,
                    la.serial_no
                 FROM legacy_assets la
                 LEFT JOIN classifications c ON c.id = la.classification_id
                 WHERE la.id = ?
                 LIMIT 1"
            );
            if ($legacyStmt) {
                $legacyStmt->bind_param('i', $legacyAssetId);
                $legacyStmt->execute();
                $res = $legacyStmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $rows[] = $row;
                }
                $legacyStmt->close();
            }
        } else {
            $legacyStmt = $db->prepare(
            "SELECT
                'legacy' AS source_type,
                la.property_number,
                la.item_description,
                c.classification_name,
                c.classification_family,
                '' AS abbreviation,
                la.unit_cost,
                la.acquisition_date AS date_acquired,
                la.brand,
                la.model,
                la.serial_no
             FROM legacy_assets la
             LEFT JOIN classifications c ON c.id = la.classification_id
             WHERE la.is_active = 1
               AND la.item_type = 'equipment'
               AND la.office_id = ?
             ORDER BY la.property_number ASC, la.id ASC"
            );
            if ($legacyStmt) {
                $legacyStmt->bind_param('i', $officeId);
                $legacyStmt->execute();
                $res = $legacyStmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $rows[] = $row;
                }
                $legacyStmt->close();
            }
        }
    }
}

$unitHeadName = $header ? employee_display_name($header) : '';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PAR by Office</title>
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
                <h4 class="mb-0">PAR Print by Office</h4>
                <div class="small text-muted">Bulk print equipment currently accountable to one office.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo base_url('modules/distributions/index.php?document_type=par'); ?>" class="btn btn-outline-secondary">Back to Distribution</a>
                <?php if (($officeId > 0 || $legacyAssetId > 0) && $rows): ?>
                    <a href="<?php echo h(base_url('modules/distributions/par_office.php?office_id=' . $officeId . ($legacyAssetId > 0 ? '&legacy_asset_id=' . $legacyAssetId : '') . '&print=1')); ?>" class="btn btn-primary">Print Current Result</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($validationError !== ''): ?>
            <div class="alert alert-warning"><?php echo h($validationError); ?></div>
        <?php endif; ?>
        <form method="get" class="row g-3 align-items-end">
            <?php if ($legacyAssetId > 0): ?>
                <input type="hidden" name="legacy_asset_id" value="<?php echo (int) $legacyAssetId; ?>">
            <?php endif; ?>
            <div class="col-md-8">
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
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Load PAR</button>
                <a href="<?php echo base_url('modules/distributions/par_office.php'); ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>

    <?php if (($officeId > 0 || $legacyAssetId > 0) && $header): ?>
        <div class="d-flex justify-content-between align-items-start mt-3 mb-2">
            <div class="appendix">Appendix 71</div>
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
                <div><strong>Responsibility Center Code:</strong> <?php echo h($header['rc_code'] ?? ''); ?></div>
            </div>
            <div class="col-6 text-end">
                <div><strong>Office:</strong> <?php echo h($header['office_name']); ?></div>
                <div><strong>PAR No.:</strong> Office Bulk Print</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th style="width:8%">Qty</th>
                        <th style="width:6%">Unit</th>
                        <th>Description</th>
                        <th style="width:14%">Property Number</th>
                        <th style="width:12%">Date Acquired</th>
                        <th style="width:10%" class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $total = 0.0; foreach ($rows as $row): $total += (float) ($row['unit_cost'] ?? 0); ?>
                        <tr>
                            <td class="text-end">1.00</td>
                            <td><?php echo h($row['abbreviation'] ?: 'unit'); ?></td>
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
                            <td><?php echo h(!empty($row['date_acquired']) ? date('M d, Y', strtotime($row['date_acquired'])) : ''); ?></td>
                            <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Total:</td>
                        <td class="text-end fw-semibold"><?php echo h(number_format($total, 2)); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="row mt-4">
            <div class="col-md-6 text-center">
                <div><strong>Received by:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;margin:12px 40px;"></div>
                <div>Signature over Printed Name of End User</div>
                <div>Position/Office: <?php echo h($header['position_title'] ?? ''); ?> / <?php echo h($header['office_name'] ?? ''); ?></div>
                <div>Name: <?php echo h($unitHeadName); ?></div>
            </div>
            <div class="col-md-6 text-center">
                <div><strong>Issued by:</strong></div>
                <div style="height:60px;border-bottom:1px solid #000;margin:12px 40px;"></div>
                <div>Signature over Printed Name of Supply and/or Property Custodian</div>
                <div>Position/Office:</div>
                <div>Date:</div>
            </div>
        </div>
    <?php elseif ($officeId > 0 || $legacyAssetId > 0): ?>
        <div class="alert alert-info">No PAR items found for the selected office.</div>
    <?php endif; ?>
</div>
<?php if ($autoPrint && $rows): ?><script>window.addEventListener('load', function(){ window.print(); });</script><?php endif; ?>
</body>
</html>
