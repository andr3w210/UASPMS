<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$officeId = (int) ($_GET['office_id'] ?? 0);
$legacyAssetId = (int) ($_GET['legacy_asset_id'] ?? 0);
$printFormat = (($_GET['print_format'] ?? 'long') === 'short') ? 'short' : 'long';
$isShort = $printFormat === 'short';
$semiType = $_GET['semi_type'] ?? 'all';
if (!in_array($semiType, ['all', 'high_value', 'low_value'], true)) {
    $semiType = 'all';
}
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
$requestedDocumentNo = trim((string) ($_GET['document_no'] ?? ''));
$viewMode = (($_GET['view_mode'] ?? 'grouped') === 'detailed') ? 'detailed' : 'grouped';
$isGrouped = $viewMode === 'grouped';
$extraRows = max(0, min(50, (int) ($_GET['extra_rows'] ?? 0)));
$copyCount = max(1, min(20, (int) ($_GET['copies'] ?? 1)));
$offices = [];
$header = null;
$rows = [];
$validationError = '';
$selectedOfficeName = '';
$documentPrintNo = '';
$canViewAllOffices = false;

$deriveLegacyDocumentNo = static function (string $propertyNumber): string {
    $propertyNumber = trim($propertyNumber);
    if ($propertyNumber === '') {
        return '';
    }

    $parts = explode('-', $propertyNumber);
    if (count($parts) >= 5) {
        return implode('-', array_slice($parts, 0, 4));
    }

    return $propertyNumber;
};

$resolveOfficeHead = static function (mysqli $db, int $officeId): array {
    return employee_resolve_office_head($db, $officeId);
};

$resolveSupplyOfficeHead = static function (mysqli $db): array {
    return employee_resolve_supply_office_head($db);
};

$signatoryDisplayName = static function (array $person): string {
    $suffix = trim((string) ($person['suffix_name'] ?? ''));
    $middle = trim((string) ($person['middle_name'] ?? ''));
    $middleInitial = $middle !== '' ? strtoupper(substr(rtrim($middle, '.'), 0, 1)) . '.' : '';
    $nameParts = array_filter([
        trim((string) ($person['name_prefix'] ?? '')),
        trim((string) ($person['first_name'] ?? '')),
        $middleInitial,
        trim((string) ($person['last_name'] ?? '')),
    ]);
    $name = strtoupper(trim(implode(' ', $nameParts)));
    if ($suffix !== '') {
        $name .= ', ' . $suffix;
    }
    return $name;
};

$buildPropertyRange = static function (array $propertyNumbers): string {
    $values = array_values(array_filter(array_map('trim', $propertyNumbers), static function ($value) {
        return $value !== '';
    }));
    if (!$values) {
        return '';
    }
    if (count($values) === 1) {
        return $values[0];
    }

    $first = $values[0];
    $last = $values[count($values) - 1];
    if ($first === $last) {
        return $first;
    }

    if (preg_match('/^(.*?)(\d+)$/', $first, $firstMatches) && preg_match('/^(.*?)(\d+)$/', $last, $lastMatches) && $firstMatches[1] === $lastMatches[1]) {
        return $first . ' to ' . $last;
    }

    return $first . ' to ' . $last;
};

if ($db) {
    ensure_legacy_assets_rpcppe_tracking_columns($db);
    ensure_distribution_item_rpcppe_tracking_columns($db);
    ensure_legacy_assets_accountability_no_column($db);
    ensure_legacy_assets_accountability_tracking_columns($db);

    $threshold = get_active_threshold($db);
    $semiHvMin = (float) ($threshold['semi_hv_min'] ?? 5000);
    $poItemSupportsSemiType = function_exists('schema_has_column') ? schema_has_column($db, 'purchase_order_items', 'semi_expendable_type') : false;

    $canViewAllOffices = rbac_has_full_accountability_access();
    $allowedOfficeIds = $canViewAllOffices ? [] : current_user_active_designated_office_ids($db);
    $officeRes = $db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeRes) {
        $offices = $officeRes->fetch_all(MYSQLI_ASSOC);
        if (!$canViewAllOffices) {
            $offices = array_values(array_filter($offices, static function (array $office) use ($allowedOfficeIds): bool {
                return in_array((int) ($office['id'] ?? 0), $allowedOfficeIds, true);
            }));
        }
    }

    foreach ($offices as $office) {
        if ((int) ($office['id'] ?? 0) === $officeId) {
            $selectedOfficeName = (string) ($office['office_name'] ?? '');
            break;
        }
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

    if ($officeId > 0 && !user_has_accountability_office_access($db, $officeId)) {
        $validationError = 'You can only view ICS records for offices where you have an active designation.';
    }

    if ($legacyAssetId > 0) {
        $legacyValidationStmt = $db->prepare(
            "SELECT property_number, item_description, office_id, employee_id, item_type, system_reference, po_number, accountability_no, unit_cost, acquisition_date, accountability_status
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
                $documentPrintNo = ensure_legacy_asset_accountability_no(
                    $db,
                    $legacyAssetId,
                    (string) ($legacyRow['item_type'] ?? ''),
                    (float) ($legacyRow['unit_cost'] ?? 0),
                    (string) ($legacyRow['accountability_no'] ?? ''),
                    (string) ($legacyRow['acquisition_date'] ?? '')
                );
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
                } elseif (($legacyRow['accountability_status'] ?? 'active') === 'for_reconciliation') {
                    $validationError = 'Printing is blocked. This legacy asset is marked For Reconciliation and has no current accountability.';
                } elseif (($legacyRow['item_type'] ?? '') !== 'semi_expendable') {
                    $validationError = 'ICS printing is allowed for legacy semi-expendable assets only.';
                }
            }
        }
    }

    if (($officeId > 0 || $legacyAssetId > 0) && $validationError === '') {
        $headStmt = $db->prepare(
            "SELECT o.id, o.office_name, o.office_code, rc.code AS rc_code
             FROM offices o
             LEFT JOIN responsibility_codes rc ON rc.office_id = o.id
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
                ];
            }
        }

        if ($officeId > 0 && $legacyAssetId <= 0) {
            $sql = "SELECT
                    'system' AS source_type,
                    did.property_number,
                    did.brand,
                    did.model,
                    did.serial_no,
                    poi.item_description,
                    c.classification_name,
                    c.classification_family,
                    c.useful_life_years,
                    u.abbreviation,
                    ri.unit_cost,
                    COALESCE(r.received_date, d.distribution_date) AS date_acquired
                FROM distribution_item_details did
                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'ics'
                INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
                INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'semi_expendable'
                INNER JOIN receivings r ON r.id = ri.receiving_id
                LEFT JOIN classifications c ON c.id = poi.classification_id
                LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
                WHERE COALESCE(NULLIF(did.current_office_id, 0), d.office_id) = ?
                  AND did.is_distributed = 1
                  AND (did.is_disposed IS NULL OR did.is_disposed = 0)
                  AND UPPER(poi.item_description) NOT LIKE '%RPCPPE%RECONCILIATION%ADJUSTMENT%'
                  AND UPPER(TRIM(poi.item_description)) NOT IN ('SUBTOTAL', 'TOTAL', 'GRAND TOTAL')";
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
        }

        $legacySql = null;
        $legacyTypes = '';
        $legacyParams = [];

        if ($officeId > 0 && $legacyAssetId <= 0) {
            $legacySql = "SELECT
                        'legacy' AS source_type,
                        la.property_number,
                        la.brand,
                        la.model,
                        la.serial_no,
                        la.item_description,
                        c.classification_name,
                        c.classification_family,
                        c.useful_life_years,
                        '' AS abbreviation,
                        la.unit_cost,
                        COALESCE(
                            la.acquisition_date,
                            rbi.acquisition_date,
                            CASE
                                WHEN la.property_number REGEXP '^[0-9]{4}[-.]' THEN STR_TO_DATE(CONCAT(LEFT(la.property_number, 4), '-01-01'), '%Y-%m-%d')
                                ELSE DATE(la.created_at)
                            END
                        ) AS date_acquired
                      FROM legacy_assets la
                      LEFT JOIN classifications c ON c.id = la.classification_id
                      LEFT JOIN (
                        SELECT legacy_asset_id, MAX(acquisition_date) AS acquisition_date
                        FROM rpcppe_batch_items
                        WHERE legacy_asset_id IS NOT NULL AND acquisition_date IS NOT NULL
                        GROUP BY legacy_asset_id
                      ) rbi ON rbi.legacy_asset_id = la.id
                      WHERE la.is_active = 1
                        AND la.item_type = 'semi_expendable'
                        AND UPPER(la.item_description) NOT LIKE '%RPCPPE%RECONCILIATION%ADJUSTMENT%'
                        AND UPPER(TRIM(la.item_description)) NOT IN ('SUBTOTAL', 'TOTAL', 'GRAND TOTAL')
                        AND COALESCE(la.accountability_status, 'active') = 'active'
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
        }

        if ($legacyAssetId > 0) {
            $legacySql = "SELECT
                        'legacy' AS source_type,
                        la.property_number,
                        la.brand,
                        la.model,
                        la.serial_no,
                        la.item_description,
                        c.classification_name,
                        c.classification_family,
                        c.useful_life_years,
                        '' AS abbreviation,
                        la.unit_cost,
                        COALESCE(
                            la.acquisition_date,
                            rbi.acquisition_date,
                            CASE
                                WHEN la.property_number REGEXP '^[0-9]{4}[-.]' THEN STR_TO_DATE(CONCAT(LEFT(la.property_number, 4), '-01-01'), '%Y-%m-%d')
                                ELSE DATE(la.created_at)
                            END
                        ) AS date_acquired
                      FROM legacy_assets la
                      LEFT JOIN classifications c ON c.id = la.classification_id
                      LEFT JOIN (
                        SELECT legacy_asset_id, MAX(acquisition_date) AS acquisition_date
                        FROM rpcppe_batch_items
                        WHERE legacy_asset_id IS NOT NULL AND acquisition_date IS NOT NULL
                        GROUP BY legacy_asset_id
                      ) rbi ON rbi.legacy_asset_id = la.id
                      WHERE la.id = ?
                      LIMIT 1";
            $legacyTypes = 'i';
            $legacyParams = [$legacyAssetId];
        }
        if (!empty($legacySql)) {
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
}

$groupedRows = [];
if ($isGrouped) {
    foreach ($rows as $row) {
        $unitLabel = trim((string) ($row['abbreviation'] ?? 'unit'));
        $groupKey = implode('|', [
            trim((string) ($row['classification_name'] ?? '')),
            trim((string) ($row['item_description'] ?? '')),
            $unitLabel,
            trim((string) ($row['useful_life_years'] ?? '')),
            number_format((float) ($row['unit_cost'] ?? 0), 2, '.', ''),
        ]);

        if (!isset($groupedRows[$groupKey])) {
            $groupedRows[$groupKey] = [
                'abbreviation' => (string) ($row['abbreviation'] ?? 'unit'),
                'classification_name' => (string) ($row['classification_name'] ?? ''),
                'classification_family' => (string) ($row['classification_family'] ?? ''),
                'item_description' => (string) ($row['item_description'] ?? ''),
                'useful_life_years' => $row['useful_life_years'] ?? null,
                'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                'line_total' => 0.0,
                'quantity' => 0,
                'property_numbers' => [],
                'property_number' => '',
                'details' => [],
            ];
        }

        $groupedRows[$groupKey]['quantity']++;
        $groupedRows[$groupKey]['line_total'] += (float) ($row['unit_cost'] ?? 0);
        if (!empty($row['property_number'])) {
            $groupedRows[$groupKey]['property_numbers'][] = (string) $row['property_number'];
        }
        $groupedRows[$groupKey]['details'][] = [
            'brand' => (string) ($row['brand'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'serial_no' => (string) ($row['serial_no'] ?? ''),
        ];
    }

    foreach ($groupedRows as &$groupedRow) {
        $groupedRow['property_numbers'] = array_values(array_unique($groupedRow['property_numbers']));
        sort($groupedRow['property_numbers']);
        $groupedRow['property_number'] = $buildPropertyRange($groupedRow['property_numbers']);
    }
    unset($groupedRow);
}

$printRows = $isGrouped ? array_values($groupedRows) : array_map(static function (array $row): array {
    $row['quantity'] = 1;
    $row['line_total'] = (float) ($row['unit_cost'] ?? 0);
    $row['details'] = [[
        'brand' => (string) ($row['brand'] ?? ''),
        'model' => (string) ($row['model'] ?? ''),
        'serial_no' => (string) ($row['serial_no'] ?? ''),
    ]];
    return $row;
}, $rows);

$detailIdentityLine = static function (array $detail): string {
    $parts = [];
    $brand = trim((string) ($detail['brand'] ?? ''));
    $model = trim((string) ($detail['model'] ?? ''));
    $serial = trim((string) ($detail['serial_no'] ?? ''));

    if ($brand !== '') {
        $parts[] = 'Brand: ' . $brand;
    }
    if ($model !== '') {
        $parts[] = 'Model: ' . $model;
    }
    if ($serial !== '') {
        $parts[] = 'Serial: ' . $serial;
    }

    return implode(' | ', $parts);
};

$itemIdentityLines = static function (array $row) use ($detailIdentityLine): array {
    $lines = [];
    $details = array_key_exists('details', $row) ? (array) $row['details'] : [$row];
    foreach ($details as $detail) {
        $line = $detailIdentityLine((array) $detail);
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return array_values(array_unique($lines));
};

$singleAssetRecipient = [];
if ($db && $legacyAssetId > 0) {
    $recipientStmt = $db->prepare(
        "SELECT e.id, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title, o.office_name
         FROM legacy_assets la
         INNER JOIN employees e ON e.id = la.employee_id
         LEFT JOIN offices o ON o.id = la.office_id
         WHERE la.id = ? AND e.is_active = 1
         LIMIT 1"
    );
    if ($recipientStmt) {
        $recipientStmt->bind_param('i', $legacyAssetId);
        $recipientStmt->execute();
        $singleAssetRecipient = $recipientStmt->get_result()->fetch_assoc() ?: [];
        $recipientStmt->close();
    }
}

$recipientHead = $singleAssetRecipient ?: (($db && $officeId > 0) ? $resolveOfficeHead($db, $officeId) : []);
$supplyHead = $db ? $resolveSupplyOfficeHead($db) : [];

$recipientHeadName = !empty($recipientHead) ? $signatoryDisplayName($recipientHead) : '';
$recipientHeadTitle = trim((string) ($recipientHead['position_title'] ?? ''));
$recipientOfficeName = trim((string) ($header['office_name'] ?? ''));

$supplyHeadName = !empty($supplyHead) ? $signatoryDisplayName($supplyHead) : '';
$supplyHeadTitle = trim((string) ($supplyHead['position_title'] ?? ''));
$supplyOfficeName = trim((string) ($supplyHead['office_name'] ?? 'Supply Office'));

$fundCluster = '';
if ($db && $legacyAssetId > 0) {
    $fundStmt = $db->prepare(
        "SELECT COALESCE(NULLIF(TRIM(f.fund_source), ''), NULLIF(TRIM(f.fund_code), ''), NULLIF(TRIM(f.fund_name), '')) AS fund_label
         FROM legacy_assets la
         LEFT JOIN funds f ON f.id = la.fund_id
         WHERE la.id = ?
         LIMIT 1"
    );
    if ($fundStmt) {
        $fundStmt->bind_param('i', $legacyAssetId);
        $fundStmt->execute();
        $fundRow = $fundStmt->get_result()->fetch_assoc() ?: [];
        $fundCluster = trim((string) ($fundRow['fund_label'] ?? ''));
        $fundStmt->close();
    }
} elseif ($db && $officeId > 0 && $legacyAssetId <= 0) {
    $funds = [];
    $fundStmt = $db->prepare(
        "SELECT DISTINCT COALESCE(NULLIF(TRIM(f.fund_source), ''), NULLIF(TRIM(f.fund_code), '')) AS fund_label
         FROM distributions d
         INNER JOIN distribution_items di ON di.distribution_id = d.id
         INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
         INNER JOIN receivings r ON r.id = ri.receiving_id
         INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
         LEFT JOIN funds f ON f.id = po.fund_id
         INNER JOIN distribution_item_details did ON did.distribution_item_id = di.id
         WHERE COALESCE(NULLIF(did.current_office_id, 0), d.office_id) = ?
           AND d.status = 'posted'
           AND d.document_type = 'ics'"
    );
    if ($fundStmt) {
        $fundStmt->bind_param('i', $officeId);
        $fundStmt->execute();
        $fundRes = $fundStmt->get_result();
        while ($fundRes && ($fundRow = $fundRes->fetch_assoc())) {
            $label = trim((string) ($fundRow['fund_label'] ?? ''));
            if ($label !== '') {
                $funds[] = $label;
            }
        }
        $fundStmt->close();
    }
    $funds = array_values(array_unique($funds));
    if (count($funds) === 1) {
        $fundCluster = $funds[0];
    } elseif (count($funds) > 1) {
        $fundCluster = 'Various';
    }
}

if ($legacyAssetId > 0 && $validationError === '' && $documentPrintNo === '') {
    $validationError = 'Printing is blocked. Legacy ICS number could not be generated. Please retry from Asset Details.';
}

$subtypeLabel = $semiType === 'low_value' ? 'Low Value Semi-Expendable' : ($semiType === 'high_value' ? 'High Value Semi-Expendable' : 'All Semi-Expendable');
$officePrintNo = 'ICS-OFFICE-' . str_pad((string) max(1, $officeId), 4, '0', STR_PAD_LEFT);
$displayPrintNo = $legacyAssetId > 0
    ? ($documentPrintNo !== '' ? $documentPrintNo : $requestedDocumentNo)
    : ($requestedDocumentNo !== ''
        ? ($documentPrintNo !== '' ? $documentPrintNo : $requestedDocumentNo)
        : ($documentPrintNo !== '' ? $documentPrintNo : $officePrintNo));
$blankRows = $extraRows;
$shortSheetCount = (int) ceil($copyCount / 2);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ICS by Office</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page { size: 8.5in 13in; margin: <?php echo $isShort ? '0.5in 0.07in 0.07in 0.07in' : '0.5in 0.07in 0.07in 0.07in'; ?>; }
        body { margin:0; font-size:12px; font-family: "Times New Roman", serif; color:#000; }
        table { font-size:11px; }
        .no-print { display:block; font-family: Arial, sans-serif; }
        .print-shell.short { font-size: 10.5px; }
        .print-shell.short table { font-size: 10px; }
        .print-shell.short { width: 7.5in; max-width: 7.5in !important; padding: 0; }
        .short-copies { width: 7.5in; }
        .short-sheet { width: 7.5in; height: 12.5in; box-sizing: border-box; display: block; overflow: hidden; }
        .short-slot { height: 6.125in; box-sizing: border-box; display: block; overflow: hidden; }
        .short-slot + .short-slot { padding-top: 0.25in; }
        .short-copy { height: 6.125in; min-height: 6.125in; padding: 0; box-sizing: border-box; overflow: hidden; break-inside: avoid; page-break-inside: avoid; flex: 1 1 auto; }
        .ics-form { position: relative; }
        .appendix { position: absolute; right: 0; top: 0; font-style: italic; font-size: 12px; }
        .ics-title { text-align: center; font-weight: bold; font-size: 16px; text-transform: uppercase; margin: 18px 0 22px; }
        .line-value { display: inline-block; border-bottom: 1px solid #000; min-width: 150px; padding: 0 2px; line-height: 1.1; }
        .line-value.long { min-width: 220px; }
        .ics-meta, .ics-table, .ics-sign-table { width: 100%; border-collapse: collapse; }
        .ics-meta td { padding: 2px 4px; vertical-align: bottom; }
        .ics-meta .label { font-weight: bold; white-space: nowrap; }
        .ics-table th, .ics-table td, .ics-sign-table td, .ics-sign-table th { border: 1px solid #000; padding: 3px 4px; vertical-align: top; }
        .ics-table thead th { text-align: center; font-weight: bold; }
        .ics-body td { height: 25px; }
        .print-shell.short .ics-body td { height: 14px; }
        .ics-sign-table .sign-head { text-align: left; font-weight: bold; }
        .ics-sign-table .sign-box { height: 74px; text-align: center; vertical-align: top; font-size: 10px; padding-top: 8px; }
        .ics-sign-table .sign-name { font-weight: 700; font-size: 12px; letter-spacing: 0.2px; line-height: 1.1; margin: 26px 0 0; }
        .ics-sign-table .meta-box { height: 52px; text-align: center; vertical-align: top; padding-top: 6px; }
        .ics-sign-table .meta-value { margin: 10px 0 0; font-size: 10px; line-height: 1.15; }
        .ics-sign-table .meta-caption { text-align: center; font-size: 10px; }
        .ics-sign-table .underlined-value { display:inline-block; border-bottom:1px solid #000; padding:0 8px 1px; min-width:82%; }
        .ics-sign-table .meta-box .underlined-value { min-width:68%; }
        .print-shell.short .ics-sign-table .sign-box { height: 60px; font-size: 9px; padding-top: 6px; }
        .print-shell.short .ics-sign-table .sign-name { font-size: 10px; margin-top: 16px; margin-bottom: 0; }
        .print-shell.short .ics-sign-table .meta-box { height: 42px; padding-top: 4px; }
        .print-shell.short .ics-sign-table .meta-value { font-size: 9px; margin-top: 8px; }
        @media print { .no-print { display:none !important; } thead { display: table-header-group; } .print-shell.short .short-copies { width: 7.5in !important; height: auto !important; overflow: visible !important; } .print-shell.short .short-sheet { width: 7.5in !important; height: 12.5in !important; display:block !important; overflow: hidden !important; break-after: page; page-break-after: always; } .print-shell.short .short-slot { height: 6.125in !important; display: block !important; overflow: hidden !important; } .print-shell.short .short-slot + .short-slot { padding-top: 0.25in !important; } .print-shell.short .short-copy { height: 6.125in !important; min-height: 6.125in !important; } .print-shell.short .short-sheet:last-child { break-after: auto; page-break-after: auto; } }
    <?php if (!$isShort): ?>
            <?php echo print_page_number_css(); ?>
    <?php endif; ?></style>
</head>
<body>
<div class="container print-shell <?php echo $isShort ? 'short' : 'long'; ?>" style="max-width:1000px;">
    <div class="no-print mt-3 mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h4 class="mb-0">ICS Print by Office</h4>
                <div class="small text-muted">Bulk print semi-expendable items currently accountable to one office.</div>
            </div>
            <div class="d-flex gap-2">
                <?php if ($canViewAllOffices): ?>
                    <a href="<?php echo base_url('modules/distributions/index.php?document_type=ics'); ?>" class="btn btn-outline-secondary">Back to Distribution</a>
                <?php else: ?>
                    <a href="<?php echo base_url('modules/settings/profile.php'); ?>" class="btn btn-outline-secondary">Back to Profile</a>
                <?php endif; ?>
                <?php if (($officeId > 0 || $legacyAssetId > 0) && $rows): ?>
                    <a href="<?php echo h(base_url('modules/distributions/ics_office.php?office_id=' . $officeId . '&print_format=' . $printFormat . '&semi_type=' . urlencode($semiType) . '&view_mode=' . $viewMode . '&extra_rows=' . $extraRows . ($isShort ? '&copies=' . $copyCount : '') . ($legacyAssetId > 0 ? '&legacy_asset_id=' . $legacyAssetId : '') . '&print=1')); ?>" class="btn btn-primary">Print Current Result</a>
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
            <div class="col-lg-5 col-md-12">
                <label class="form-label">Office</label>
                <input type="hidden" name="office_id" id="office_id" value="<?php echo (int) $officeId; ?>">
                <input type="text" class="form-control" id="office_name" list="office_options" value="<?php echo h($selectedOfficeName); ?>" placeholder="Search office" required>
                <datalist id="office_options">
                    <?php foreach ($offices as $office): ?>
                        <option data-office-id="<?php echo (int) $office['id']; ?>" value="<?php echo h($office['office_name']); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label">Subtype</label>
                <select name="semi_type" class="form-select">
                    <option value="all" <?php echo $semiType === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="high_value" <?php echo $semiType === 'high_value' ? 'selected' : ''; ?>>High Value</option>
                    <option value="low_value" <?php echo $semiType === 'low_value' ? 'selected' : ''; ?>>Low Value</option>
                </select>
            </div>
            <div class="col-lg-3 col-md-4">
                <label class="form-label">View</label>
                <div class="btn-group w-100" role="group" aria-label="ICS view mode">
                    <a href="<?php echo h(base_url('modules/distributions/ics_office.php?office_id=' . $officeId . '&print_format=' . $printFormat . '&semi_type=' . urlencode($semiType) . '&view_mode=grouped' . ($isShort ? '&copies=' . $copyCount : '') . ($legacyAssetId > 0 ? '&legacy_asset_id=' . $legacyAssetId : ''))); ?>" class="btn btn-outline-primary <?php echo $isGrouped ? 'active' : ''; ?>">Grouped</a>
                    <a href="<?php echo h(base_url('modules/distributions/ics_office.php?office_id=' . $officeId . '&print_format=' . $printFormat . '&semi_type=' . urlencode($semiType) . '&view_mode=detailed' . ($isShort ? '&copies=' . $copyCount : '') . ($legacyAssetId > 0 ? '&legacy_asset_id=' . $legacyAssetId : ''))); ?>" class="btn btn-outline-primary <?php echo !$isGrouped ? 'active' : ''; ?>">Detailed</a>
                </div>
            </div>
            <div class="col-lg-2 col-md-4">
                <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
                <?php if ($isShort): ?><input type="hidden" name="copies" value="<?php echo (int) $copyCount; ?>"><?php endif; ?>
                <input type="hidden" name="print_format" value="<?php echo h($printFormat); ?>">
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-primary flex-fill">Load ICS</button>
                    <a href="<?php echo base_url('modules/distributions/ics_office.php'); ?>" class="btn btn-outline-secondary flex-fill">Clear</a>
                </div>
            </div>
        </form>
    </div>
    <?php if (($officeId > 0 || $legacyAssetId > 0) && $header): ?>
        <div class="d-flex justify-content-between align-items-start mt-3 mb-2 no-print">
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?php echo h(base_url('modules/distributions/ics_office.php?office_id=' . $officeId . '&print_format=short&semi_type=' . urlencode($semiType) . '&view_mode=' . $viewMode . '&extra_rows=' . $extraRows . '&copies=' . $copyCount . ($legacyAssetId > 0 ? '&legacy_asset_id=' . $legacyAssetId : ''))); ?>" class="btn btn-sm <?php echo $isShort ? 'btn-primary' : 'btn-outline-primary'; ?>">Short</a>
                <a href="<?php echo h(base_url('modules/distributions/ics_office.php?office_id=' . $officeId . '&print_format=long&semi_type=' . urlencode($semiType) . '&view_mode=' . $viewMode . '&extra_rows=' . $extraRows . ($legacyAssetId > 0 ? '&legacy_asset_id=' . $legacyAssetId : ''))); ?>" class="btn btn-sm <?php echo !$isShort ? 'btn-primary' : 'btn-outline-primary'; ?>">Long</a>
            </div>
            <form method="get" class="d-flex align-items-center gap-2 no-print ms-3">
                <input type="hidden" name="office_id" value="<?php echo (int) $officeId; ?>">
                <input type="hidden" name="print_format" value="<?php echo h($printFormat); ?>">
                <input type="hidden" name="semi_type" value="<?php echo h($semiType); ?>">
                <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
                <?php if ($isShort): ?><input type="hidden" name="copies" value="<?php echo (int) $copyCount; ?>"><?php endif; ?>
                <?php if ($legacyAssetId > 0): ?><input type="hidden" name="legacy_asset_id" value="<?php echo (int) $legacyAssetId; ?>"><?php endif; ?>
                <label for="extra_rows_ics" style="font-size:12px;color:#666;white-space:nowrap;">Extra rows</label>
                <input type="number" min="0" max="50" step="1" id="extra_rows_ics" name="extra_rows" value="<?php echo (int) $extraRows; ?>" style="width:80px;" class="form-control form-control-sm">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            </form>
        </div>
        <?php if ($isShort): ?>
        <div class="d-flex align-items-center gap-2 mt-2 no-print">
            <form method="get" class="d-flex align-items-center gap-2">
                <input type="hidden" name="office_id" value="<?php echo (int) $officeId; ?>">
                <input type="hidden" name="print_format" value="short">
                <input type="hidden" name="semi_type" value="<?php echo h($semiType); ?>">
                <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
                <input type="hidden" name="extra_rows" value="<?php echo (int) $extraRows; ?>">
                <?php if ($legacyAssetId > 0): ?><input type="hidden" name="legacy_asset_id" value="<?php echo (int) $legacyAssetId; ?>"><?php endif; ?>
                <label for="copies_ics_office" class="small text-muted mb-0">Copies on sheet</label>
                <input type="number" min="1" max="20" step="1" id="copies_ics_office" name="copies" value="<?php echo (int) $copyCount; ?>" class="form-control form-control-sm" style="width:88px;">
                <button type="submit" class="btn btn-sm btn-outline-dark">Apply</button>
            </form>
        </div>
        <?php endif; ?>
        <div class="print-copy" id="printCopy">
            <div class="ics-form">
                <div class="appendix">Appendix 59</div>
                <div class="ics-title">Inventory Custodian Slip</div>

                <table class="ics-meta">
                    <tr>
                        <td style="width:14%;" class="label">Entity Name:</td>
                        <td style="width:46%;"><span class="line-value long"><?php echo h(APP_NAME); ?></span></td>
                        <td style="width:12%;" class="label">ICS No :</td>
                        <td style="width:28%;"><span class="line-value"><?php echo h($displayPrintNo); ?></span></td>
                    </tr>
                    <tr>
                        <td class="label">Fund Cluster :</td>
                        <td><span class="line-value long"><?php echo h($fundCluster); ?></span></td>
                        <td></td>
                        <td><span style="font-size:10px;"><?php echo h($subtypeLabel); ?></span></td>
                    </tr>
                </table>

                <table class="ics-table">
                    <thead>
                        <tr>
                            <th style="width:8%;" rowspan="2">Quanti<br>ty</th>
                            <th style="width:8%;" rowspan="2">Unit</th>
                            <th style="width:16%;" colspan="2">Amount</th>
                            <th style="width:32%;" rowspan="2">Description</th>
                            <th style="width:15%;" rowspan="2">Inventory Item No.</th>
                            <th style="width:15%;" rowspan="2">Estimated Useful Life</th>
                        </tr>
                        <tr>
                            <th style="width:8%;">Unit Cost</th>
                            <th style="width:8%;">Total Cost</th>
                        </tr>
                    </thead>
                    <tbody class="ics-body">
                        <?php foreach ($printRows as $row):
                            $qty = (float) ($row['quantity'] ?? 1);
                            $unitLabel = trim((string) ($row['abbreviation'] ?: 'unit'));
                            $unitCost = (float) ($row['unit_cost'] ?? 0);
                            $totalCost = (float) ($row['line_total'] ?? 0);
                            $itemClass = trim((string) ($row['classification_name'] ?? ''));
                            $itemDescription = trim((string) ($row['item_description'] ?? ''));
                            $icsDescription = trim(($itemClass !== '' ? $itemClass : '') . ($itemClass !== '' && $itemDescription !== '' ? ' - ' : '') . $itemDescription);
                            $identityLines = $itemIdentityLines((array) $row);
                            $inventoryItemNo = trim((string) ($row['property_number'] ?? ''));
                            $useful = '';
                            if (!empty($row['useful_life_years'])) {
                                $useful = (string) ((int) $row['useful_life_years']) . ' yr' . ((int) $row['useful_life_years'] > 1 ? 's' : '');
                            }
                        ?>
                        <tr>
                            <td class="text-end"><?php echo h(format_quantity($qty)); ?></td>
                            <td><?php echo h($unitLabel); ?></td>
                            <td class="text-end"><?php echo h(number_format($unitCost, 2)); ?></td>
                            <td class="text-end"><?php echo h(number_format($totalCost, 2)); ?></td>
                            <td>
                                <?php echo nl2br(h($icsDescription)); ?>
                                <?php if (!empty($identityLines)): ?>
                                    <div class="small">
                                        <?php foreach ($identityLines as $identityLine): ?>
                                            <?php echo h($identityLine); ?><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($inventoryItemNo); ?></td>
                            <td><?php echo h($useful); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php for ($i = 0; $i < $blankRows; $i++): ?>
                        <tr>
                            <td>&nbsp;</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <table class="ics-sign-table">
                    <tr>
                        <th class="sign-head" style="width:50%;">Received from:</th>
                        <th class="sign-head" style="width:50%;">Received by:</th>
                    </tr>
                    <tr>
                        <td class="sign-box">
                            <?php if ($supplyHeadName !== ''): ?>
                                <div class="sign-name"><span class="underlined-value"><?php echo h($supplyHeadName); ?></span></div>
                            <?php endif; ?>
                            <div>Signature Over Printed Name</div>
                        </td>
                        <td class="sign-box">
                            <?php if ($recipientHeadName !== ''): ?>
                                <div class="sign-name"><span class="underlined-value"><?php echo h($recipientHeadName); ?></span></div>
                            <?php endif; ?>
                            <div>Signature Over Printed Name</div>
                        </td>
                    </tr>
                    <tr>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"><?php echo h(trim($supplyHeadTitle . ($supplyOfficeName !== '' ? ' / ' . $supplyOfficeName : ''))); ?></span></div>
                            <div class="meta-caption">Designation/Office</div>
                        </td>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"><?php echo h(trim($recipientHeadTitle . ($recipientOfficeName !== '' ? ' / ' . $recipientOfficeName : ''))); ?></span></div>
                            <div class="meta-caption">Designation/Office</div>
                        </td>
                    </tr>
                    <tr>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"></span></div>
                            <div class="meta-caption">Date</div>
                        </td>
                        <td class="meta-box">
                            <div class="meta-value"><span class="underlined-value"></span></div>
                            <div class="meta-caption">Date</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    <?php elseif ($officeId > 0 || $legacyAssetId > 0): ?>
        <div class="alert alert-info">No ICS items found for the selected office.</div>
    <?php endif; ?>
</div>
<?php if ($autoPrint && $rows): ?><script>window.addEventListener('load', function(){ window.print(); });</script><?php endif; ?>
<?php if ($isShort && (($officeId > 0 || $legacyAssetId > 0) && $header)): ?>
<script>
(function () {
    var source = document.getElementById('printCopy');
    if (!source) return;

    var copyCount = <?php echo (int) $copyCount; ?>;
    var shortSheetCount = <?php echo (int) $shortSheetCount; ?>;
    var shell = source.parentElement;
    if (!shell) return;

    var host = document.createElement('div');
    host.className = 'short-copies';

    for (var sheetIndex = 0; sheetIndex < shortSheetCount; sheetIndex++) {
        var sheet = document.createElement('div');
        sheet.className = 'short-sheet';

        for (var slotIndex = 0; slotIndex < 2; slotIndex++) {
            var copyIndex = (sheetIndex * 2) + slotIndex;
            var slot = document.createElement('div');
            slot.className = 'short-slot';

            if (copyIndex < copyCount) {
                if (copyIndex === 0) {
                    source.classList.add('short-copy');
                    slot.appendChild(source);
                } else {
                    var clone = source.cloneNode(true);
                    clone.removeAttribute('id');
                    clone.classList.add('short-copy');
                    slot.appendChild(clone);
                }
            } else {
                var blank = document.createElement('div');
                blank.className = 'print-copy short-copy';
                slot.appendChild(blank);
            }

            sheet.appendChild(slot);
        }

        host.appendChild(sheet);
    }

    shell.insertBefore(host, shell.firstChild);
})();
</script>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const officeInput = document.getElementById('office_name');
    const officeIdInput = document.getElementById('office_id');
    const officeOptions = Array.from(document.querySelectorAll('#office_options option'));

    if (!officeInput || !officeIdInput) {
        return;
    }

    const syncOfficeId = function () {
        const match = officeOptions.find(function (option) {
            return option.value === officeInput.value;
        });
        officeIdInput.value = match ? (match.dataset.officeId || '') : '';
    };

    officeInput.addEventListener('input', syncOfficeId);
    officeInput.form && officeInput.form.addEventListener('submit', function (event) {
        syncOfficeId();
        if (!officeIdInput.value) {
            event.preventDefault();
            officeInput.setCustomValidity('Please select a valid office from the list.');
            officeInput.reportValidity();
        } else {
            officeInput.setCustomValidity('');
        }
    });
});
</script>

<?php if (!$isShort) { render_print_page_number(); } ?></body>
</html>
