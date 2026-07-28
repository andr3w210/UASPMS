<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/employee_assignments.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

// Page metadata and UI state
$page_title = 'Distribution';
$errors = [];
$flash = get_flash();

// Database and initial state
$db = db();

// Default state variables
$offices              = [];
$employees            = [];
$employeeOfficeMap    = [];
$distributions        = [];
$iarList              = [];
$candidateItems       = [];
$distributionType     = $_GET['document_type'] ?? 'ics';
if (!in_array($distributionType, ['ics','par'], true)) {
    $distributionType = 'ics';
}
$distributionSemiType = $_GET['semi_type'] ?? 'high_value';
if (!in_array($distributionSemiType, ['high_value','low_value'], true)) {
    $distributionSemiType = 'high_value';
}
$itemTypeFilter = $distributionType === 'par' ? 'equipment' : 'semi_expendable';

$form = [
    'system_reference'  => '',
    'document_type'     => $distributionType,
    'document_no'       => '',
    'distribution_date' => date('Y-m-d'),
    'office_id'         => '',
    'employee_id'       => '',
    'purpose'           => '',
    'remarks'           => '',
];

$selectedReceivingId = (int) ($_GET['receiving_id'] ?? 0);
$distributionPhotoUploads = [];
$editingDistributionId = (int) ($_POST['edit_id'] ?? ($_GET['edit_id'] ?? 0));
$editingDistribution = null;
$editingDistributionItems = [];
$editForm = [
    'distribution_date' => date('Y-m-d'),
    'office_id' => '',
    'employee_id' => '',
    'purpose' => '',
    'remarks' => '',
];

function preview_distribution_doc_no($db, string $docType, string $date, string $semiType = 'high_value'): string {
    $timestamp = strtotime($date) ?: time();
    $year  = date('Y', $timestamp);
    $month = date('m', $timestamp);
    $seriesPrefix = '';
    if ($docType === 'par') {
        $prefix = 'PAR-' . $year . '-' . $month;
        $like = 'PAR-' . $year . '-%';
        $seriesPrefix = 'PAR-' . $year;
    } elseif ($semiType === 'low_value') {
        $prefix = 'SPLV-' . $year . '-' . $month;
        $like = 'SPLV-' . $year . '-%';
        $seriesPrefix = 'SPLV-' . $year;
    } else {
        $prefix = 'SPHV-' . $year . '-' . $month;
        $like = 'SPHV-' . $year . '-%';
        $seriesPrefix = 'SPHV-' . $year;
    }

    // Keep one running sequence per year, following counters and both old/new stored formats.
    $currentValue = 0;

    $counterKey = 'distribution_doc_no|' . $seriesPrefix;
    $counterStmt = $db->prepare("SELECT current_value FROM series_numbers WHERE module_key = ? LIMIT 1");
    if ($counterStmt) {
        $counterStmt->bind_param('s', $counterKey);
        $counterStmt->execute();
        $counterRow = $counterStmt->get_result()->fetch_assoc();
        $currentValue = max($currentValue, (int) ($counterRow['current_value'] ?? 0));
        $counterStmt->close();
    }

    $stmt = $db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(document_no, '-', -1) AS UNSIGNED)), 0) AS current_value FROM distributions WHERE document_no LIKE ?");
    if ($stmt) {
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $currentValue = max($currentValue, (int)($row['current_value'] ?? 0));
        $stmt->close();
    }

    if (function_exists('schema_has_column') && schema_has_column($db, 'legacy_assets', 'accountability_no')) {
        $legacyStmt = $db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(accountability_no, '-', -1) AS UNSIGNED)), 0) AS current_value FROM legacy_assets WHERE accountability_no LIKE ?");
        if ($legacyStmt) {
            $legacyStmt->bind_param('s', $like);
            $legacyStmt->execute();
            $legacyRow = $legacyStmt->get_result()->fetch_assoc();
            $currentValue = max($currentValue, (int) ($legacyRow['current_value'] ?? 0));
            $legacyStmt->close();
        }
    }

    $nextSeq = $currentValue + 1;
    return $prefix . '-' . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);
}

function distribution_sync_system_property_number(mysqli $db, int $detailId, string $propertyNumber): bool
{
    if (
        schema_has_column($db, 'distribution_item_details', 'distribution_item_id')
        && schema_has_column($db, 'distribution_items', 'id')
        && schema_has_column($db, 'distribution_items', 'property_number')
    ) {
        $parentStmt = $db->prepare(
            'UPDATE distribution_items di
             INNER JOIN distribution_item_details did ON did.distribution_item_id = di.id
             SET di.property_number = ?
             WHERE did.id = ?'
        );
        if (!$parentStmt) {
            return false;
        }

        $parentStmt->bind_param('si', $propertyNumber, $detailId);
        $parentOk = $parentStmt->execute();
        $parentStmt->close();

        if (!$parentOk) {
            return false;
        }
    }

    $syncTargets = [
        ['table' => 'rpcppe_batch_items', 'id_column' => 'distribution_item_detail_id'],
        ['table' => 'inventory_count_items', 'id_column' => 'distribution_item_detail_id'],
        ['table' => 'asset_transfers', 'id_column' => 'distribution_item_detail_id'],
        ['table' => 'transfer_batch_items', 'id_column' => 'distribution_item_detail_id'],
    ];

    foreach ($syncTargets as $target) {
        $table = $target['table'];
        $idColumn = $target['id_column'];
        if (
            !schema_has_column($db, $table, 'property_number')
            || !schema_has_column($db, $table, $idColumn)
        ) {
            continue;
        }

        $stmt = $db->prepare("UPDATE {$table} SET property_number = ? WHERE {$idColumn} = ?");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $propertyNumber, $detailId);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return false;
        }
    }

    return true;
}

function distribution_extract_uploaded_file(string $fieldName, int $itemId): array
{
    $empty = [
        'name' => '',
        'type' => '',
        'tmp_name' => '',
        'error' => UPLOAD_ERR_NO_FILE,
        'size' => 0,
    ];

    $field = $_FILES[$fieldName] ?? null;
    if (!is_array($field)) {
        return $empty;
    }

    $name = $field['name'] ?? null;
    $type = $field['type'] ?? null;
    $tmpName = $field['tmp_name'] ?? null;
    $error = $field['error'] ?? null;
    $size = $field['size'] ?? null;

    // Support both scalar uploads and indexed uploads keyed by unit detail id.
    if (is_array($name)) {
        if (!array_key_exists($itemId, $name)) {
            return $empty;
        }

        return [
            'name' => (string) ($name[$itemId] ?? ''),
            'type' => (string) (is_array($type) ? ($type[$itemId] ?? '') : ''),
            'tmp_name' => (string) (is_array($tmpName) ? ($tmpName[$itemId] ?? '') : ''),
            'error' => (int) (is_array($error) ? ($error[$itemId] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE),
            'size' => (int) (is_array($size) ? ($size[$itemId] ?? 0) : 0),
        ];
    }

    return [
        'name' => (string) $name,
        'type' => (string) ($type ?? ''),
        'tmp_name' => (string) ($tmpName ?? ''),
        'error' => (int) ($error ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($size ?? 0),
    ];
}

function distribution_fetch_editable_header(mysqli $db, int $distributionId): ?array
{
    if ($distributionId <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT d.id, d.system_reference, d.document_type, d.semi_expendable_type, d.document_no, d.distribution_date,
               d.office_id, d.employee_id, d.purpose, d.remarks, d.status,
               o.office_name, o.office_code,
               e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name
        FROM distributions d
        INNER JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        WHERE d.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $distributionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function distribution_replace_office_suffix(string $propertyNumber, string $fromOfficeCode, string $toOfficeCode): string
{
    $propertyNumber = trim($propertyNumber);
    $fromOfficeCode = trim($fromOfficeCode);
    $toOfficeCode = trim($toOfficeCode);
    if ($propertyNumber === '' || $fromOfficeCode === '' || $toOfficeCode === '') {
        return $propertyNumber;
    }

    $pattern = '/-' . preg_quote($fromOfficeCode, '/') . '$/i';
    if (!preg_match($pattern, $propertyNumber)) {
        return $propertyNumber;
    }

    return (string) preg_replace($pattern, '-' . strtoupper($toOfficeCode), $propertyNumber, 1);
}

function distribution_force_office_suffix(string $propertyNumber, string $toOfficeCode): string
{
    $propertyNumber = trim($propertyNumber);
    $toOfficeCode = trim($toOfficeCode);
    if ($propertyNumber === '' || $toOfficeCode === '') {
        return $propertyNumber;
    }

    if (preg_match('/-([A-Z0-9]{2,12})$/i', $propertyNumber)) {
        return (string) preg_replace('/-([A-Z0-9]{2,12})$/i', '-' . strtoupper($toOfficeCode), $propertyNumber, 1);
    }

    return $propertyNumber;
}

function distribution_fetch_editable_items(mysqli $db, int $distributionId): array
{
    if ($distributionId <= 0) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT di.id,
               di.quantity_distributed,
               di.unit_cost,
               di.line_total,
               di.remarks,
               poi.item_description,
               poi.line_no,
               c.classification_name,
               c.classification_family
        FROM distribution_items di
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        WHERE di.distribution_id = ?
        ORDER BY COALESCE(poi.line_no, 999999) ASC, di.id ASC
    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $distributionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $detailStmt = $db->prepare("SELECT id, receiving_item_detail_id, brand, model, serial_no, property_number, remarks, is_distributed FROM distribution_item_details WHERE distribution_item_id = ? ORDER BY id ASC");

    foreach ($items as &$item) {
        $item['details'] = [];
        if ($detailStmt) {
            $distributionItemId = (int) ($item['id'] ?? 0);
            $detailStmt->bind_param('i', $distributionItemId);
            $detailStmt->execute();
            $detailResult = $detailStmt->get_result();
            $item['details'] = $detailResult ? $detailResult->fetch_all(MYSQLI_ASSOC) : [];
        }
    }
    unset($item);

    if ($detailStmt) {
        $detailStmt->close();
    }

    return $items;
}

function distribution_collect_detail_ids(array $validatedItems): array
{
    $detailIds = [];
    foreach ($validatedItems as $item) {
        foreach (($item['details'] ?? []) as $detail) {
            $detailId = (int) ($detail['id'] ?? 0);
            if ($detailId > 0) {
                $detailIds[] = $detailId;
            }
        }
    }

    $detailIds = array_values(array_unique($detailIds));
    sort($detailIds);

    return $detailIds;
}

if ($db) {
    $assignmentsEnabled = employee_assignments_enabled($db);
    $threshold    = get_active_threshold($db);
    $equipmentMin = (float)$threshold['equipment_min'];
    $semiHvMin    = (float)$threshold['semi_hv_min'];
    $poItemSupportsSemiType = function_exists('schema_has_column')
        ? schema_has_column($db, 'purchase_order_items', 'semi_expendable_type')
        : false;

    $form['system_reference'] = preview_module_code($db, 'distributions');
    $form['document_no'] = preview_distribution_doc_no(
        $db, $distributionType,
        $form['distribution_date'], $distributionSemiType
    );

    // Load offices
    $officeResult = $db->query(
        "SELECT id, office_name, office_code FROM offices
         WHERE is_active = 1 ORDER BY office_name ASC"
    );
    if ($officeResult) $offices = $officeResult->fetch_all(MYSQLI_ASSOC);

    // Load employees
    $empResult = $db->query(
        "SELECT id, office_id, employee_no, first_name, middle_name,
                last_name, suffix_name, position_title, is_unit_head
         FROM employees WHERE is_active = 1
         ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC"
    );
    if ($empResult) $employees = $empResult->fetch_all(MYSQLI_ASSOC);

    if ($assignmentsEnabled) {
        $assignmentResult = $db->query(
            "SELECT employee_id, office_id, is_primary, is_unit_head
             FROM employee_assignments
             WHERE is_active = 1
             ORDER BY employee_id ASC, is_primary DESC, id ASC"
        );
        if ($assignmentResult) {
            foreach ($assignmentResult->fetch_all(MYSQLI_ASSOC) as $assignmentRow) {
                $employeeId = (int) ($assignmentRow['employee_id'] ?? 0);
                $officeId = (int) ($assignmentRow['office_id'] ?? 0);
                if ($employeeId <= 0 || $officeId <= 0) {
                    continue;
                }
                if (!isset($employeeOfficeMap[$employeeId])) {
                    $employeeOfficeMap[$employeeId] = [
                        'office_ids' => [],
                        'primary_office_id' => 0,
                        'unit_head_office_ids' => [],
                    ];
                }
                if (!in_array($officeId, $employeeOfficeMap[$employeeId]['office_ids'], true)) {
                    $employeeOfficeMap[$employeeId]['office_ids'][] = $officeId;
                }
                if ((int) ($assignmentRow['is_primary'] ?? 0) === 1 && $employeeOfficeMap[$employeeId]['primary_office_id'] === 0) {
                    $employeeOfficeMap[$employeeId]['primary_office_id'] = $officeId;
                }
                if ((int) ($assignmentRow['is_unit_head'] ?? 0) === 1 && !in_array($officeId, $employeeOfficeMap[$employeeId]['unit_head_office_ids'], true)) {
                    $employeeOfficeMap[$employeeId]['unit_head_office_ids'][] = $officeId;
                }
            }
        }
    }

    if ($editingDistributionId > 0) {
        $editingDistribution = distribution_fetch_editable_header($db, $editingDistributionId);
        if ($editingDistribution) {
            $editForm = [
                'distribution_date' => (string) ($editingDistribution['distribution_date'] ?? date('Y-m-d')),
                'office_id' => (string) ($editingDistribution['office_id'] ?? ''),
                'employee_id' => (string) ($editingDistribution['employee_id'] ?? ''),
                'purpose' => (string) ($editingDistribution['purpose'] ?? ''),
                'remarks' => (string) ($editingDistribution['remarks'] ?? ''),
            ];
            $editingDistributionItems = distribution_fetch_editable_items($db, $editingDistributionId);
        }
    }

    // Load IAR list for split panel
    $iarSql = "SELECT r.id, r.system_reference, r.received_date, po.po_number, s.supplier_name,
                      COUNT(DISTINCT rid.id) AS available_units
               FROM receivings r
               INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
               INNER JOIN suppliers s ON s.id = po.supplier_id
               INNER JOIN receiving_items ri ON ri.receiving_id = r.id
               INNER JOIN purchase_order_items poi
                   ON poi.id = ri.purchase_order_item_id
                  AND poi.item_type = ?";
    $iarTypes = 's';
    $iarParams = [$itemTypeFilter];
    if ($distributionType === 'ics') {
        if ($poItemSupportsSemiType) {
            $iarSql .= " AND poi.semi_expendable_type = ?";
            $iarTypes .= 's';
            $iarParams[] = $distributionSemiType;
        } else {
            if ($distributionSemiType === 'high_value') {
                $iarSql .= " AND ri.unit_cost >= ?";
            } else {
                $iarSql .= " AND ri.unit_cost < ?";
            }
            $iarTypes .= 'd';
            $iarParams[] = $semiHvMin;
        }
    }
    $iarSql .= " INNER JOIN receiving_item_details rid
                    ON rid.receiving_item_id = ri.id
                   AND rid.is_distributed = 0
                WHERE r.status IN ('completed', 'partial')
                GROUP BY r.id, r.system_reference, r.received_date, po.po_number, s.supplier_name
                HAVING COUNT(DISTINCT rid.id) > 0
                ORDER BY r.received_date DESC, r.id DESC";
    $iarStmt = $db->prepare($iarSql);
    if ($iarStmt) {
        $iarStmt->bind_param($iarTypes, ...$iarParams);
        $iarStmt->execute();
        $iarList = $iarStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $iarStmt->close();
    }

    // Posted distributions list with optional filtering (moved inside $db guard)
    $filterDistType = trim($_GET['filter_type'] ?? '');
    $filterDistQ    = trim($_GET['dist_q'] ?? '');

    $distWhere  = [];
    $distParams = [];
    $distTypes  = '';

    if (in_array($filterDistType, ['ics', 'par'], true)) {
        $distWhere[] = 'd.document_type = ?';
        $distTypes .= 's';
        $distParams[] = $filterDistType;
    }

    // By default only show posted distributions that still have active distributed units.
    $distWhere[] = "d.status = 'posted'";
    $distWhere[] = "EXISTS (
        SELECT 1
        FROM distribution_items active_di
        INNER JOIN distribution_item_details active_did ON active_did.distribution_item_id = active_di.id
        WHERE active_di.distribution_id = d.id
          AND active_did.is_distributed = 1
    )";

    if ($filterDistQ !== '') {
        $distWhere[] = "(d.system_reference LIKE ? OR d.document_no LIKE ? OR o.office_name LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? )";
        $like = '%' . $filterDistQ . '%';
        $distTypes .= 'ssss';
        $distParams[] = $like;
        $distParams[] = $like;
        $distParams[] = $like;
        $distParams[] = $like;
    }

    $whereSql = $distWhere ? 'WHERE ' . implode(' AND ', $distWhere) : '';

    $itemSummaryJoin = "
        LEFT JOIN (
            SELECT
                item_names.distribution_id,
                GROUP_CONCAT(DISTINCT item_names.item_label ORDER BY item_names.item_label SEPARATOR ' || ') AS distributed_items
            FROM (
                SELECT
                    di2.distribution_id,
                    TRIM(
                        CONCAT(
                            COALESCE(NULLIF(TRIM(c.classification_name), ''), 'Unclassified'),
                            ' - ',
                            COALESCE(NULLIF(TRIM(poi.item_description), ''), 'Unnamed item')
                        )
                    ) AS item_label
                FROM distribution_items di2
                LEFT JOIN receiving_items ri2 ON ri2.id = di2.receiving_item_id
                LEFT JOIN purchase_order_items poi ON poi.id = ri2.purchase_order_item_id
                LEFT JOIN classifications c ON c.id = poi.classification_id
                WHERE COALESCE(di2.quantity_distributed, 0) > 0

                UNION

                SELECT
                    di3.distribution_id,
                    TRIM(
                        CONCAT(
                            COALESCE(NULLIF(TRIM(lc.classification_name), ''), 'Unclassified'),
                            ' - ',
                            COALESCE(NULLIF(TRIM(la.item_description), ''), 'Unnamed item')
                        )
                    ) AS item_label
                FROM distribution_items di3
                INNER JOIN distribution_item_details did3 ON did3.distribution_item_id = di3.id
                INNER JOIN legacy_assets la ON la.property_number = did3.property_number
                LEFT JOIN classifications lc ON lc.id = la.classification_id
                                WHERE COALESCE(di3.quantity_distributed, 0) > 0
                                    AND did3.is_distributed = 1
            ) item_names
            GROUP BY item_names.distribution_id
        ) item_summary ON item_summary.distribution_id = d.id";

    $sql = "SELECT d.id, d.system_reference, d.document_type, d.document_no, d.distribution_date, d.total_amount, d.status, " .
        "o.office_name, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name, " .
        "COALESCE(item_summary.distributed_items, '') AS distributed_items " .
        "FROM distributions d " .
        "INNER JOIN offices o ON o.id = d.office_id " .
        "LEFT JOIN employees e ON e.id = d.employee_id " .
        $itemSummaryJoin . " " .
        $whereSql .
        " ORDER BY d.distribution_date DESC, d.id DESC";

    if (count($distParams) > 0) {
        $distStmt = $db->prepare($sql);
        if ($distStmt) {
            $refs = [];
            $refs[] = &$distTypes;
            foreach ($distParams as $k => $v) {
                $refs[] = &$distParams[$k];
            }
            call_user_func_array([$distStmt, 'bind_param'], $refs);
            $distStmt->execute();
            $distRes = $distStmt->get_result();
            $distributions = $distRes ? $distRes->fetch_all(MYSQLI_ASSOC) : [];
            $distStmt->close();
        } else {
            $distributions = [];
        }
    } else {
        $distResult = $db->query($sql);
        $distributions = $distResult ? $distResult->fetch_all(MYSQLI_ASSOC) : [];
    }

} // end if ($db)

function distribution_doc_label(string $type): string
{
    return $type === 'par' ? 'PAR' : 'ICS';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }
    $action = trim((string) ($_POST['action'] ?? 'post_distribution'));

    if ($action === 'update_distribution') {
        $editingDistributionId = (int) ($_POST['edit_id'] ?? 0);
        $editingDistribution = $editingDistributionId > 0 ? distribution_fetch_editable_header($db, $editingDistributionId) : null;
        if (!$editingDistribution || (string) ($editingDistribution['status'] ?? '') !== 'posted') {
            $errors[] = 'The selected distribution cannot be edited.';
        }

        $editForm['distribution_date'] = old($_POST, 'distribution_date', $editForm['distribution_date']);
        $editForm['office_id'] = old($_POST, 'office_id', $editForm['office_id']);
        $editForm['employee_id'] = old($_POST, 'employee_id', $editForm['employee_id']);
        $editForm['purpose'] = old($_POST, 'purpose', $editForm['purpose']);
        $editForm['remarks'] = old($_POST, 'remarks', $editForm['remarks']);
        $postedQuantityDistributed = $_POST['quantity_distributed'] ?? [];
        $postedLineRemarks = $_POST['line_remarks'] ?? [];
        $postedDetailBrand = $_POST['detail_brand'] ?? [];
        $postedDetailModel = $_POST['detail_model'] ?? [];
        $postedDetailSerial = $_POST['detail_serial_no'] ?? [];
        $postedDetailRemarks = $_POST['detail_remarks'] ?? [];
        $postedDetailPropertyNumber = $_POST['detail_property_number'] ?? [];

        if ($editForm['distribution_date'] === '') {
            add_validation_error($errors, 'Distribution date is required.');
        } elseif (!is_valid_date_string($editForm['distribution_date'])) {
            add_validation_error($errors, 'Distribution date format is invalid.');
        }
        if ($editForm['office_id'] === '') {
            add_validation_error($errors, 'Office is required.');
        }

        $officeId = (int) ($editForm['office_id'] !== '' ? $editForm['office_id'] : 0);
        $employeeId = (int) ($editForm['employee_id'] !== '' ? $editForm['employee_id'] : 0);
        if ($employeeId <= 0) {
            add_validation_error($errors, 'Accountable employee is required.');
        }
        $oldOfficeId = (int) ($editingDistribution['office_id'] ?? 0);
        $oldEmployeeId = (int) ($editingDistribution['employee_id'] ?? 0);
        $oldOfficeCode = '';
        $newOfficeCode = '';
        foreach ($offices as $officeRow) {
            $rowOfficeId = (int) ($officeRow['id'] ?? 0);
            if ($rowOfficeId === $oldOfficeId) {
                $oldOfficeCode = trim((string) ($officeRow['office_code'] ?? ''));
            }
            if ($rowOfficeId === $officeId) {
                $newOfficeCode = trim((string) ($officeRow['office_code'] ?? ''));
            }
        }
        if ($officeId > 0 && $newOfficeCode === '') {
            add_validation_error($errors, 'Selected office is invalid.');
        }
        if ($employeeId > 0) {
            $employeeValid = false;
            $employeeFound = false;
            foreach ($employees as $employee) {
                if ((int) $employee['id'] === $employeeId) {
                    $employeeFound = true;
                    $employeeOfficeIds = array_map('intval', (array) ($employeeOfficeMap[$employeeId]['office_ids'] ?? []));
                    if (!$employeeOfficeIds && !empty($employee['office_id'])) {
                        $employeeOfficeIds = [(int) $employee['office_id']];
                    }
                    if (in_array($officeId, $employeeOfficeIds, true)) {
                        $employeeValid = true;
                        break;
                    }
                }
            }
            if ($employeeFound && !$employeeValid) {
                $errors[] = 'Selected employee does not belong to the chosen office.';
            } elseif (!$employeeFound) {
                $errors[] = 'Selected employee is invalid.';
            }
        }

        if (!$errors && $editingDistribution) {
            $editingDistributionItems = distribution_fetch_editable_items($db, $editingDistributionId);
            $db->begin_transaction();
            try {
                $stmt = $db->prepare("UPDATE distributions SET distribution_date = ?, office_id = ?, employee_id = NULLIF(?, 0), purpose = ?, remarks = ? WHERE id = ? AND status = 'posted' LIMIT 1");
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare the distribution update.');
                }
                $stmt->bind_param(
                    'siissi',
                    $editForm['distribution_date'],
                    $officeId,
                    $employeeId,
                    $editForm['purpose'],
                    $editForm['remarks'],
                    $editingDistributionId
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException('Unable to update the distribution header.');
                }
                $stmt->close();

                // Keep current assignment in sync for assets still on the original header assignment.
                $assignmentStmt = $db->prepare("
                    UPDATE distribution_item_details did
                    INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                    SET did.current_office_id = ?, did.current_employee_id = NULLIF(?, 0)
                    WHERE di.distribution_id = ?
                      AND (did.current_office_id IS NULL OR did.current_office_id = ?)
                      AND (did.current_employee_id IS NULL OR did.current_employee_id = ?)
                ");
                if (!$assignmentStmt) {
                    throw new RuntimeException('Unable to prepare accountability sync update.');
                }
                $assignmentStmt->bind_param('iiiii', $officeId, $employeeId, $editingDistributionId, $oldOfficeId, $oldEmployeeId);
                if (!$assignmentStmt->execute()) {
                    throw new RuntimeException('Unable to sync accountability on distributed assets.');
                }
                $assignmentStmt->close();

                $lineStmt = $db->prepare("UPDATE distribution_items SET quantity_distributed = ?, remarks = ? WHERE id = ? AND distribution_id = ?");
                $detailStmt = $db->prepare("UPDATE distribution_item_details SET brand = ?, model = ?, serial_no = ?, property_number = ?, remarks = ? WHERE id = ? AND distribution_item_id = ?");
                $dupPropertyStmt = $db->prepare("SELECT id FROM distribution_item_details WHERE property_number = ? AND id <> ? AND is_distributed = 1 LIMIT 1");
                $releaseReceivingDetailStmt = $db->prepare("UPDATE receiving_item_details SET is_distributed = 0 WHERE id = ?");
                $claimReceivingDetailStmt = $db->prepare("UPDATE receiving_item_details SET is_distributed = 1 WHERE id = ?");
                $releaseDistributionDetailStmt = $db->prepare("UPDATE distribution_item_details SET is_distributed = 0, current_office_id = NULL, current_employee_id = NULL WHERE id = ? AND distribution_item_id = ?");
                $claimDistributionDetailStmt = $db->prepare("UPDATE distribution_item_details SET is_distributed = 1, current_office_id = ?, current_employee_id = NULLIF(?, 0) WHERE id = ? AND distribution_item_id = ?");
                if (!$lineStmt || !$detailStmt || !$dupPropertyStmt || !$releaseReceivingDetailStmt || !$claimReceivingDetailStmt || !$releaseDistributionDetailStmt || !$claimDistributionDetailStmt) {
                    throw new RuntimeException('Unable to prepare distribution item updates.');
                }

                foreach ($editingDistributionItems as $itemRow) {
                    $distributionItemId = (int) ($itemRow['id'] ?? 0);
                    $quantityDistributed = (float) (trim((string) ($postedQuantityDistributed[$distributionItemId] ?? ($itemRow['quantity_distributed'] ?? 0))));
                    if ($quantityDistributed < 0) {
                        $quantityDistributed = 0;
                    }
                    $lineRemarks = trim((string) ($postedLineRemarks[$distributionItemId] ?? ($itemRow['remarks'] ?? '')));
                    $lineStmt->bind_param('dsii', $quantityDistributed, $lineRemarks, $distributionItemId, $editingDistributionId);
                    if (!$lineStmt->execute()) {
                        throw new RuntimeException('Unable to update a distribution line.');
                    }

                    foreach (($itemRow['details'] ?? []) as $detailRow) {
                        $detailId = (int) ($detailRow['id'] ?? 0);
                        $receivingItemDetailId = (int) ($detailRow['receiving_item_detail_id'] ?? 0);
                        $brand = trim((string) ($postedDetailBrand[$detailId] ?? ($detailRow['brand'] ?? '')));
                        $model = trim((string) ($postedDetailModel[$detailId] ?? ($detailRow['model'] ?? '')));
                        $serial = trim((string) ($postedDetailSerial[$detailId] ?? ($detailRow['serial_no'] ?? '')));
                        $existingPropertyNumber = trim((string) ($detailRow['property_number'] ?? ''));
                        $propertyNumber = trim((string) ($postedDetailPropertyNumber[$detailId] ?? $existingPropertyNumber));
                        if ($propertyNumber === $existingPropertyNumber) {
                            $propertyNumber = $existingPropertyNumber;
                        }
                        $detailRemarks = trim((string) ($postedDetailRemarks[$detailId] ?? ($detailRow['remarks'] ?? '')));
                        if ($propertyNumber !== '') {
                            $dupPropertyStmt->bind_param('si', $propertyNumber, $detailId);
                            if (!$dupPropertyStmt->execute()) {
                                throw new RuntimeException('Unable to validate property number updates.');
                            }
                            $dupRow = $dupPropertyStmt->get_result()->fetch_assoc();
                            if ($dupRow) {
                                throw new RuntimeException('Property number already exists: ' . $propertyNumber);
                            }
                        }
                        $detailStmt->bind_param('sssssii', $brand, $model, $serial, $propertyNumber, $detailRemarks, $detailId, $distributionItemId);
                        if (!$detailStmt->execute()) {
                            throw new RuntimeException('Unable to update distributed unit details.');
                        }
                        if (!distribution_sync_system_property_number($db, $detailId, $propertyNumber)) {
                            throw new RuntimeException('Unable to sync distributed unit property number.');
                        }

                        if ($quantityDistributed <= 0) {
                            $releaseDistributionDetailStmt->bind_param('ii', $detailId, $distributionItemId);
                            if (!$releaseDistributionDetailStmt->execute()) {
                                throw new RuntimeException('Unable to release the distributed unit.');
                            }
                            if ($receivingItemDetailId > 0) {
                                $releaseReceivingDetailStmt->bind_param('i', $receivingItemDetailId);
                                if (!$releaseReceivingDetailStmt->execute()) {
                                    throw new RuntimeException('Unable to return the receiving unit to the available pool.');
                                }
                            }
                        } else {
                            $claimDistributionDetailStmt->bind_param('iiii', $officeId, $employeeId, $detailId, $distributionItemId);
                            if (!$claimDistributionDetailStmt->execute()) {
                                throw new RuntimeException('Unable to keep the distributed unit assigned.');
                            }
                            if ($receivingItemDetailId > 0) {
                                $claimReceivingDetailStmt->bind_param('i', $receivingItemDetailId);
                                if (!$claimReceivingDetailStmt->execute()) {
                                    throw new RuntimeException('Unable to keep the receiving unit reserved.');
                                }
                            }
                        }
                    }
                }

                $lineStmt->close();
                $detailStmt->close();
                $dupPropertyStmt->close();
                $releaseReceivingDetailStmt->close();
                $claimReceivingDetailStmt->close();
                $releaseDistributionDetailStmt->close();
                $claimDistributionDetailStmt->close();

                // Track quantity changes for audit
                $quantityChanges = [];
                foreach ($editingDistributionItems as $itemRow) {
                    $distributionItemId = (int) ($itemRow['id'] ?? 0);
                    $oldQty = (float) ($itemRow['quantity_distributed'] ?? 0);
                    $newQty = (float) (trim((string) ($postedQuantityDistributed[$distributionItemId] ?? $oldQty)));
                    if ($newQty < 0) $newQty = 0;
                    if ($oldQty !== $newQty) {
                        $quantityChanges[] = [
                            'item_id' => $distributionItemId,
                            'old_qty' => $oldQty,
                            'new_qty' => $newQty,
                        ];
                    }
                }

                write_audit_log($db, [
                    'action' => 'update',
                    'table_name' => 'distributions',
                    'record_id' => $editingDistributionId,
                    'module_name' => 'distributions',
                    'record_type' => 'distribution',
                    'action_name' => 'update_distribution_header',
                    'old_values' => [
                        'distribution_date' => $editingDistribution['distribution_date'] ?? '',
                        'office_id' => $editingDistribution['office_id'] ?? '',
                        'employee_id' => $editingDistribution['employee_id'] ?? '',
                        'purpose' => $editingDistribution['purpose'] ?? '',
                        'remarks' => $editingDistribution['remarks'] ?? '',
                    ],
                    'new_values' => [
                        'distribution_date' => $editForm['distribution_date'],
                        'office_id' => $officeId,
                        'employee_id' => $employeeId,
                        'purpose' => $editForm['purpose'],
                        'remarks' => $editForm['remarks'],
                        'line_count' => count($editingDistributionItems),
                        'quantity_changes' => $quantityChanges,
                    ],
                    'description' => 'Updated posted distribution header details' . (count($quantityChanges) > 0 ? ' and item quantities' : '') . '.',
                ]);

                $db->commit();
                if ($editingDistributionId > 0) {
                    $editingDistribution = distribution_fetch_editable_header($db, $editingDistributionId);
                    $editingDistributionItems = distribution_fetch_editable_items($db, $editingDistributionId);
                }
                set_flash('success', 'Distribution details updated successfully.');
                redirect('modules/distributions/index.php?document_type=' . urlencode($distributionType));
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = $e->getMessage();
            }
        }
    } else {
    $selectedReceivingId = (int) ($_POST['receiving_id'] ?? 0);
        $form['document_type'] = $_POST['document_type'] ?? 'ics';
        if (!in_array($form['document_type'], ['ics', 'par'], true)) {
            $form['document_type'] = 'ics';
        }
        $threshold    = get_active_threshold($db);
        $equipmentMin = (float)$threshold['equipment_min'];
        $semiHvMin    = (float)$threshold['semi_hv_min'];
        $form['system_reference'] = preview_module_code($db, 'distributions');
        $form['distribution_date'] = old($_POST, 'distribution_date', date('Y-m-d'));
        $postedSemiType = $_POST['semi_type'] ?? $distributionSemiType;
        if (!in_array($postedSemiType, ['high_value', 'low_value'], true)) {
            $postedSemiType = 'high_value';
        }
        $form['document_no'] = preview_distribution_doc_no($db, $form['document_type'], $form['distribution_date'], $postedSemiType);
        $form['office_id'] = old($_POST, 'office_id');
        $form['employee_id'] = old($_POST, 'employee_id');
        $form['purpose'] = old($_POST, 'purpose');
        $form['remarks'] = old($_POST, 'remarks');

        if ($form['distribution_date'] === '') {
            add_validation_error($errors, 'Distribution date is required.');
        } elseif (!is_valid_date_string($form['distribution_date'])) {
            add_validation_error($errors, 'Distribution date format is invalid.');
        }
        if ($form['office_id'] === '') {
            add_validation_error($errors, 'Office is required.');
        }

        $officeId = (int) ($form['office_id'] !== '' ? $form['office_id'] : 0);
        $employeeId = (int) ($form['employee_id'] !== '' ? $form['employee_id'] : 0);
        if ($employeeId <= 0) {
            add_validation_error($errors, 'Accountable employee is required.');
        }
        if ($officeId > 0) {
            $officeValid = false;
            foreach ($offices as $officeRow) {
                if ((int) ($officeRow['id'] ?? 0) === $officeId) {
                    $officeValid = true;
                    break;
                }
            }
            if (!$officeValid) {
                add_validation_error($errors, 'Selected office is invalid.');
            }
        }
        if ($employeeId > 0) {
            $employeeValid = false;
            $employeeFound = false;
            foreach ($employees as $employee) {
                if ((int) $employee['id'] === $employeeId) {
                    $employeeFound = true;
                    $employeeOfficeIds = array_map('intval', (array) ($employeeOfficeMap[$employeeId]['office_ids'] ?? []));
                    if (!$employeeOfficeIds && !empty($employee['office_id'])) {
                        $employeeOfficeIds = [(int) $employee['office_id']];
                    }
                    if (in_array($officeId, $employeeOfficeIds, true)) {
                        $employeeValid = true;
                        break;
                    }
                }
            }
            if ($employeeFound && !$employeeValid) {
                $errors[] = 'Selected employee does not belong to the chosen office.';
            } elseif (!$employeeFound) {
                $errors[] = 'Selected employee is invalid.';
            }
        }

        $postedItems = $_POST['items'] ?? [];
        $validatedItems = [];
        $totalAmount = 0.00;

        if ($selectedReceivingId > 0) {
            // Unit-level selection: user checks individual receiving_item_details
            $selectedUnits = array_keys(array_filter($_POST['units'] ?? []));
            $unitRemarks = $_POST['unit_remarks'] ?? [];
            if (empty($selectedUnits)) {
                $errors[] = 'Select at least one unit to distribute.';
            } else {
                $detailCheckStmt = $db->prepare("SELECT rid.id AS detail_id, rid.receiving_item_id, rid.brand, rid.model, rid.serial_no, rid.remarks AS detail_remarks, ri.unit_cost, poi.item_type, poi.line_no, poi.item_description, ac.account_code, c.classification_name, u.abbreviation, r.system_reference AS receiving_reference, r.received_date, po.po_number, rid.is_distributed FROM receiving_item_details rid INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id LEFT JOIN account_codes ac ON ac.id = poi.account_code_id LEFT JOIN classifications c ON c.id = poi.classification_id LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id INNER JOIN receivings r ON r.id = ri.receiving_id LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id WHERE rid.id = ? AND r.status IN ('completed', 'partial')");
                if (!$detailCheckStmt) {
                    $errors[] = 'Internal error validating selected units.';
                } else {
                    foreach ($selectedUnits as $did) {
                        $detailId = (int) $did;
                        $detailCheckStmt->bind_param('i', $detailId);
                        $detailCheckStmt->execute();
                        $row = $detailCheckStmt->get_result()->fetch_assoc();
                        if (!$row) {
                            $errors[] = 'Selected unit not found or already distributed: ' . h($detailId);
                            continue;
                        }
                        if (($row['item_type'] ?? '') !== $itemTypeFilter) {
                            $errors[] = 'Selected unit is not of the expected item type.';
                            continue;
                        }
                        if (!empty($row['is_distributed'])) {
                            $errors[] = 'Selected unit has already been distributed: ' . h($detailId);
                            continue;
                        }

                        $issuanceItemId = 0;
                        $originReceivingItemId = (int) $row['receiving_item_id'];
                        $unitCost = (float) $row['unit_cost'];
                        $lineTotal = round($unitCost * 1, 2);
                        $totalAmount += $lineTotal;

                        $validatedItems[] = [
                            'issuance_item_id' => $issuanceItemId,
                            'origin_receiving_item_id' => $originReceivingItemId,
                            'quantity_distributed' => 1,
                            'unit_cost' => $unitCost,
                            'line_total' => $lineTotal,
                            'remarks' => trim((string) ($unitRemarks[$detailId] ?? '')),
                            'details' => [[
                                'id' => $detailId,
                                'brand' => $row['brand'] ?? '',
                                'model' => $row['model'] ?? '',
                                'serial_no' => $row['serial_no'] ?? '',
                                'remarks' => $row['detail_remarks'] ?? '',
                                'photo_file' => distribution_extract_uploaded_file('unit_photo', $detailId),
                            ]],
                        ];
                    }
                    $detailCheckStmt->close();
                }
            }
        } else {
            foreach ($candidateItems as $candidate) {
                // Guard: skip candidates that don't match current item type filter (prevent supplies from slipping through)
                if (($candidate['item_type'] ?? '') !== $itemTypeFilter) {
                    continue;
                }
                $candidateId = (int) $candidate['id'];
                $posted = isset($postedItems[$candidateId]) && is_array($postedItems[$candidateId]) ? $postedItems[$candidateId] : [];
                $distributeQty = isset($posted['quantity_distributed']) ? (float) $posted['quantity_distributed'] : 0;
                $lineRemarks = trim((string) ($posted['remarks'] ?? ''));
                $remainingQty = (float) $candidate['remaining_distribution_qty'];

                if ($distributeQty <= 0) {
                    continue;
                }
                if ($distributeQty > $remainingQty + 0.001) {
                    $lineNo = isset($candidate['line_no']) ? $candidate['line_no'] : 'N/A';
                    $errors[] = 'Quantity to distribute cannot exceed remaining quantity (' . format_quantity($remainingQty) . ') for item on line ' . $lineNo . '.';
                    continue;
                }

                $lineTotal = round($distributeQty * (float) $candidate['unit_cost'], 2);
                $totalAmount += $lineTotal;
                // Determine whether candidate came from an issuance (issuance_items) or directly from receiving_items
                $isIssuanceCandidate = isset($candidate['issuance_id']) && $candidate['issuance_id'];
                if ($isIssuanceCandidate) {
                    $issuanceItemId = $candidateId;
                    $originReceivingItemId = (int) ($candidate['receiving_item_id'] ?? 0);
                } else {
                    // Candidate from receiving_items: set issuance_item_id to 0 and origin_receiving_item_id to the receiving_item id
                    $issuanceItemId = 0;
                    $originReceivingItemId = $candidateId;
                }

                $validatedItems[] = [
                    'issuance_item_id' => $issuanceItemId,
                    'origin_receiving_item_id' => $originReceivingItemId,
                    'quantity_distributed' => $distributeQty,
                    'unit_cost' => (float) $candidate['unit_cost'],
                    'line_total' => $lineTotal,
                    'remarks' => $lineRemarks,
                    'details' => $candidate['details'],
                ];
            }
        }

        if (!$validatedItems) {
            $errors[] = 'Select at least one line to distribute.';
        }

        if (!$errors) {
            $savedDistributionPhotoPaths = [];
            $db->begin_transaction();
            try {
                $detailIdsToLock = distribution_collect_detail_ids($validatedItems);
                if ($detailIdsToLock) {
                    $lockStmt = $db->prepare("SELECT id, is_distributed FROM receiving_item_details WHERE id = ? FOR UPDATE");
                    if (!$lockStmt) {
                        throw new RuntimeException('Unable to lock selected units for posting.');
                    }

                    foreach ($detailIdsToLock as $detailIdToLock) {
                        $lockStmt->bind_param('i', $detailIdToLock);
                        $lockStmt->execute();
                        $lockRow = $lockStmt->get_result()->fetch_assoc();
                        if (!$lockRow) {
                            $lockStmt->close();
                            throw new RuntimeException('A selected unit no longer exists. Please reload and retry.');
                        }
                        if ((int) ($lockRow['is_distributed'] ?? 0) === 1) {
                            $lockStmt->close();
                            throw new RuntimeException('A selected unit was already distributed by another encoder. Please refresh and retry.');
                        }
                    }

                    $lockStmt->close();
                }

                $systemReference = next_module_code($db, 'distributions');
                // Determine semi type for this save: prefer POSTed value, fall back to detected value
                $postSemi = $_POST['semi_type'] ?? $distributionSemiType;
                if (!in_array($postSemi, ['high_value', 'low_value'], true)) {
                    $postSemi = null;
                }
                $documentNo = preview_distribution_doc_no($db, $form['document_type'], $form['distribution_date'], $postSemi);
                $userId = current_user_id();

                $headerStmt = $db->prepare("INSERT INTO distributions (system_reference, document_type, semi_expendable_type, document_no, distribution_date, office_id, employee_id, purpose, remarks, status, total_amount, created_by) VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, NULLIF(?, 0), ?, ?, 'posted', ?, ?)");
                $itemStmt = $db->prepare("INSERT INTO distribution_items (distribution_id, issuance_item_id, receiving_item_id, quantity_distributed, unit_cost, line_total, remarks) VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?)");
                $detailStmt = $db->prepare("INSERT INTO distribution_item_details (distribution_item_id, receiving_item_detail_id, brand, model, serial_no, remarks, property_number) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?)");
                $priorPropertyStmt = $db->prepare("SELECT id, property_number FROM distribution_item_details WHERE receiving_item_detail_id = ? AND property_number IS NOT NULL AND property_number <> '' ORDER BY id DESC LIMIT 1");
                if (!$headerStmt || !$itemStmt || !$detailStmt || !$priorPropertyStmt) {
                    throw new RuntimeException('Unable to prepare distribution statements.');
                }

                $semiForBind = $postSemi ?? '';
                $headerStmt->bind_param('sssssiissdi', $systemReference, $form['document_type'], $semiForBind, $documentNo, $form['distribution_date'], $officeId, $employeeId, $form['purpose'], $form['remarks'], $totalAmount, $userId);
                if (!$headerStmt->execute()) {
                    throw new RuntimeException('Unable to save the distribution header.');
                }
                $distributionId = (int) $headerStmt->insert_id;
                $headerStmt->close();

                // Prepare statement to mark a receiving item detail as distributed when assigned
                $markDetailStmt = $db->prepare("UPDATE receiving_item_details SET is_distributed = 1 WHERE id = ? AND is_distributed = 0");
                if (!$markDetailStmt) {
                    throw new RuntimeException('Unable to prepare mark-detail statement.');
                }

                // Prepare statement to fetch fund/account/rc for property number generation
                $fundStmt = $db->prepare(
                    "SELECT f.fund_source, f.fund_code, ac.account_code, o.office_code
                     FROM receiving_items ri
                     INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                     INNER JOIN receivings r ON r.id = ri.receiving_id
                     INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
                     LEFT JOIN funds f ON f.id = po.fund_id
                     LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
                     LEFT JOIN offices o ON o.id = ?
                     WHERE ri.id = ?
                     LIMIT 1"
                );

                foreach ($validatedItems as $item) {
                    // When candidate is from an issuance, the validated 'receiving_item_id' holds the issuance_item id.
                    // We also pass the original receiving_item_id (if available) into the second parameter to preserve linkage.
                    $issuanceItemId = (int) ($item['issuance_item_id'] ?? 0);
                    $originReceivingItemId = (int) ($item['origin_receiving_item_id'] ?? 0);
                    $itemStmt->bind_param('iiiddds', $distributionId, $issuanceItemId, $originReceivingItemId, $item['quantity_distributed'], $item['unit_cost'], $item['line_total'], $item['remarks']);
                    if (!$itemStmt->execute()) {
                        throw new RuntimeException('Unable to save distribution line items.');
                    }
                    $distributionItemId = (int) $itemStmt->insert_id;

                    foreach ($item['details'] as $detail) {
                        $detailId = (int) ($detail['id'] ?? 0);
                        $propertyNo = '';
                        if ($fundStmt) {
                            $fundStmt->bind_param('ii', $officeId, $originReceivingItemId);
                            $fundStmt->execute();
                            $fundRow = $fundStmt->get_result()->fetch_assoc();
                            $year        = date('Y', strtotime($form['distribution_date']));
                            $fundCode    = $fundRow['fund_source'] ?? ($fundRow['fund_code'] ?? '');
                            $accountCode = $fundRow['account_code'] ?? '';
                            $officeCode = $fundRow['office_code'] ?? '';
                            if ($detailId > 0) {
                                $priorPropertyStmt->bind_param('i', $detailId);
                                $priorPropertyStmt->execute();
                                $priorPropertyRow = $priorPropertyStmt->get_result()->fetch_assoc() ?: null;
                                if ($priorPropertyRow && trim((string) ($priorPropertyRow['property_number'] ?? '')) !== '') {
                                    $propertyNo = trim((string) $priorPropertyRow['property_number']);
                                }
                            }
                            if ($propertyNo === '') {
                                $propertyNo = generate_property_number($db, $year, $fundCode, $accountCode, $officeCode);
                            }
                        }

                        $detailStmt->bind_param('iisssss', $distributionItemId, $detailId, $detail['brand'], $detail['model'], $detail['serial_no'], $detail['remarks'], $propertyNo);
                        if (!$detailStmt->execute()) {
                            throw new RuntimeException('Unable to save distributed unit details.');
                        }
                        $distributionDetailId = (int) $detailStmt->insert_id;
                        $photoFile = is_array($detail['photo_file'] ?? null) ? $detail['photo_file'] : ['error' => UPLOAD_ERR_NO_FILE];
                        $photoErrors = [];
                        $photoPath = store_uploaded_image($photoFile, 'assets/' . date('Y') . '/distribution-' . $distributionId, $photoErrors);
                        if ($photoErrors) {
                            throw new RuntimeException(implode(' ', $photoErrors));
                        }

                        if ($photoPath !== null && $photoPath !== '') {
                            $savedDistributionPhotoPaths[] = $photoPath;
                            $photoCaption = 'Distribution photo - ' . $documentNo;
                            $photoStmt = $db->prepare("INSERT INTO asset_photos (asset_source, asset_id, photo_path, caption, is_primary, uploaded_by) VALUES ('system', ?, ?, ?, 1, ?)");
                            if (!$photoStmt) {
                                throw new RuntimeException('Unable to prepare asset photo save.');
                            }
                            $photoStmt->bind_param('issi', $distributionDetailId, $photoPath, $photoCaption, $userId);
                            if (!$photoStmt->execute()) {
                                $photoStmt->close();
                                throw new RuntimeException('Unable to save the asset photo.');
                            }
                            $photoStmt->close();
                        }
                        // If this detail references a receiving_item_detail, mark that unit as distributed
                        if ($detailId > 0) {
                            $markDetailStmt->bind_param('i', $detailId);
                            if (!$markDetailStmt->execute()) {
                                throw new RuntimeException('Unable to mark receiving units as distributed.');
                            }
                            if ($markDetailStmt->affected_rows < 1) {
                                throw new RuntimeException('One or more selected units were already distributed by another encoder. Please refresh and retry.');
                            }
                        }
                    }
                }

                $detailStmt->close();
                $priorPropertyStmt->close();
                if ($fundStmt) $fundStmt->close();
                $markDetailStmt->close();
                $itemStmt->close();

                $affectedPoStmt = $db->prepare(
                    "SELECT DISTINCT po.id AS purchase_order_id
                     FROM distribution_items di
                     INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
                     INNER JOIN receivings r ON r.id = ri.receiving_id
                     INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
                     WHERE di.distribution_id = ?"
                );
                if (!$affectedPoStmt) {
                    throw new RuntimeException('Unable to refresh purchase order status after posting distribution.');
                }
                $affectedPoStmt->bind_param('i', $distributionId);
                $affectedPoStmt->execute();
                $affectedPoRows = $affectedPoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $affectedPoStmt->close();

                $poUpdateStmt = $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
                if (!$poUpdateStmt) {
                    throw new RuntimeException('Unable to prepare purchase order status update after posting distribution.');
                }
                foreach ($affectedPoRows as $affectedPoRow) {
                    $affectedPoId = (int) ($affectedPoRow['purchase_order_id'] ?? 0);
                    if ($affectedPoId <= 0) {
                        continue;
                    }
                    $poStatus = recalculate_purchase_order_status($db, $affectedPoId);
                    $poUpdateStmt->bind_param('si', $poStatus, $affectedPoId);
                    if (!$poUpdateStmt->execute()) {
                        $poUpdateStmt->close();
                        throw new RuntimeException('Unable to update purchase order status after posting distribution.');
                    }
                }
                $poUpdateStmt->close();

                if (!write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'distributions',
                    'record_id' => $distributionId,
                    'module_name' => 'distributions',
                    'record_type' => 'distribution',
                    'action_name' => 'post_distribution',
                    'new_values' => [
                        'system_reference' => $systemReference,
                        'document_type' => $form['document_type'],
                        'document_no' => $documentNo,
                        'distribution_date' => $form['distribution_date'],
                        'office_id' => $officeId,
                        'employee_id' => $employeeId,
                        'semi_expendable_type' => $postSemi,
                        'total_amount' => $totalAmount,
                        'item_count' => count($validatedItems),
                    ],
                    'description' => 'Posted distribution transaction.',
                ])) {
                    throw new RuntimeException('Unable to write the distribution audit log.');
                }

                $db->commit();
                set_flash('success', strtoupper($form['document_type']) . ' distribution posted successfully.');
                // Redirect to the canonical document (ICS or PAR) with a created flag
                if ($form['document_type'] === 'par') {
                    $redirectUrl = 'modules/distributions/par.php?id=' . $distributionId . '&created=1';
                } else {
                    // ICS (include semi_type when present)
                    $redirectUrl = 'modules/distributions/ics.php?id=' . $distributionId . '&created=1';
                    if (!empty($postSemi)) {
                        $redirectUrl .= '&semi_type=' . urlencode($postSemi);
                    }
                }
                redirect($redirectUrl);
            } catch (Throwable $e) {
                $db->rollback();
                foreach ($savedDistributionPhotoPaths ?? [] as $savedPath) {
                    delete_uploaded_file($savedPath);
                }
                $errors[] = 'Unable to save the distribution.';
            }
        }
    }
    }

    // Posted distributions list with optional filtering
    $filterDistType = trim($_GET['filter_type'] ?? '');
    $filterDistQ    = trim($_GET['dist_q'] ?? '');

    $distWhere  = [];
    $distParams = [];
    $distTypes  = '';

    if (in_array($filterDistType, ['ics', 'par'], true)) {
        $distWhere[] = 'd.document_type = ?';
        $distTypes .= 's';
        $distParams[] = $filterDistType;
    }

    // By default only show posted distributions that still have active distributed units.
    $distWhere[] = "d.status = 'posted'";
    $distWhere[] = "EXISTS (
        SELECT 1
        FROM distribution_items active_di
        INNER JOIN distribution_item_details active_did ON active_did.distribution_item_id = active_di.id
        WHERE active_di.distribution_id = d.id
          AND active_did.is_distributed = 1
    )";

    if ($filterDistQ !== '') {
        $distWhere[] = "(d.system_reference LIKE ? OR d.document_no LIKE ? OR o.office_name LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? )";
        $like = '%' . $filterDistQ . '%';
        $distTypes .= 'ssss';
        $distParams[] = $like;
        $distParams[] = $like;
        $distParams[] = $like;
        $distParams[] = $like;
    }

    $whereSql = $distWhere ? 'WHERE ' . implode(' AND ', $distWhere) : '';

    $itemSummaryJoin = "
        LEFT JOIN (
            SELECT
                item_names.distribution_id,
                GROUP_CONCAT(DISTINCT item_names.item_label ORDER BY item_names.item_label SEPARATOR ' || ') AS distributed_items
            FROM (
                SELECT
                    di2.distribution_id,
                    TRIM(
                        CONCAT(
                            COALESCE(NULLIF(TRIM(c.classification_name), ''), 'Unclassified'),
                            ' - ',
                            COALESCE(NULLIF(TRIM(poi.item_description), ''), 'Unnamed item')
                        )
                    ) AS item_label
                FROM distribution_items di2
                LEFT JOIN receiving_items ri2 ON ri2.id = di2.receiving_item_id
                LEFT JOIN purchase_order_items poi ON poi.id = ri2.purchase_order_item_id
                LEFT JOIN classifications c ON c.id = poi.classification_id
                WHERE COALESCE(di2.quantity_distributed, 0) > 0

                UNION

                SELECT
                    di3.distribution_id,
                    TRIM(
                        CONCAT(
                            COALESCE(NULLIF(TRIM(lc.classification_name), ''), 'Unclassified'),
                            ' - ',
                            COALESCE(NULLIF(TRIM(la.item_description), ''), 'Unnamed item')
                        )
                    ) AS item_label
                FROM distribution_items di3
                INNER JOIN distribution_item_details did3 ON did3.distribution_item_id = di3.id
                INNER JOIN legacy_assets la ON la.property_number = did3.property_number
                LEFT JOIN classifications lc ON lc.id = la.classification_id
                                WHERE COALESCE(di3.quantity_distributed, 0) > 0
                                    AND did3.is_distributed = 1
            ) item_names
            GROUP BY item_names.distribution_id
        ) item_summary ON item_summary.distribution_id = d.id";

    $sql = "SELECT d.id, d.system_reference, d.document_type, d.document_no, d.distribution_date, d.total_amount, d.status, " .
        "o.office_name, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name, " .
        "COALESCE(item_summary.distributed_items, '') AS distributed_items " .
        "FROM distributions d " .
        "INNER JOIN offices o ON o.id = d.office_id " .
        "LEFT JOIN employees e ON e.id = d.employee_id " .
        $itemSummaryJoin . " " .
        $whereSql .
        " ORDER BY d.distribution_date DESC, d.id DESC";

    if (count($distParams) > 0) {
        $distStmt = $db->prepare($sql);
        if ($distStmt) {
            $refs = [];
            $refs[] = &$distTypes;
            foreach ($distParams as $k => $v) {
                $refs[] = &$distParams[$k];
            }
            call_user_func_array([$distStmt, 'bind_param'], $refs);
            $distStmt->execute();
            $distRes = $distStmt->get_result();
            $distributions = $distRes ? $distRes->fetch_all(MYSQLI_ASSOC) : [];
            $distStmt->close();
        } else {
            $distributions = [];
        }
    } else {
        $distResult = $db->query($sql);
        $distributions = $distResult ? $distResult->fetch_all(MYSQLI_ASSOC) : [];
    }

// Ensure expected variables exist for the template and SPA compatibility
$selectedPoId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$selectedReceivingId = isset($_POST['receiving_id']) ? (int) $_POST['receiving_id'] : (isset($_GET['receiving_id']) ? (int) $_GET['receiving_id'] : 0);
$purchaseOrders = $purchaseOrders ?? [];
$iarList = $iarList ?? [];
$selectedReceiving = $selectedReceiving ?? null;
$candidateItems = $candidateItems ?? [];
$distributions = $distributions ?? [];
$employees = $employees ?? [];
$offices = $offices ?? [];
$filterDistType = $filterDistType ?? ($_GET['filter_type'] ?? null);
$filterDistQ = $filterDistQ ?? ($_GET['dist_q'] ?? null);
$itemTypeFilter = $itemTypeFilter ?? 'equipment';
$distributionType = $distributionType ?? ($_GET['document_type'] ?? 'ics');
$distributionSemiType = $distributionSemiType ?? ($_GET['semi_type'] ?? null);
$form = $form ?? ['system_reference' => '', 'document_no' => '', 'distribution_date' => date('Y-m-d'), 'office_id' => '', 'employee_id' => '', 'purpose' => '', 'remarks' => ''];

if (($_GET['export'] ?? '') === 'csv') {
    stream_csv_download(
        'posted_distributions_' . date('Ymd_His') . '.csv',
        ['Reference', 'Document No.', 'Distribution Date', 'Type', 'Items', 'Office', 'Employee', 'Employee No.', 'Status', 'Total Amount'],
        $distributions,
        static function (array $distribution): array {
            return [
                $distribution['system_reference'] ?? '',
                $distribution['document_no'] ?? '',
                $distribution['distribution_date'] ?? '',
                strtoupper((string) ($distribution['document_type'] ?? '')),
                str_replace(' || ', '; ', (string) ($distribution['distributed_items'] ?? '')),
                $distribution['office_name'] ?? '',
                trim(employee_display_name($distribution)),
                $distribution['employee_no'] ?? '',
                operational_status_label('posted_transaction', (string) ($distribution['status'] ?? 'posted')),
                number_format((float) ($distribution['total_amount'] ?? 0), 2, '.', ''),
            ];
        }
    );
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4 page-section">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                                <div class="workspace-header mb-3">
                                    <div class="workspace-header-copy">
                                        <p class="page-kicker mb-1">Property Operations</p>
                                        <h5 class="page-title mb-1">Distribution Workspace</h5>
                                        <p class="text-muted mb-0">Choose the correct accountability document, assign units, and post distributions from one responsive workspace.</p>
                                    </div>
                                </div>
                                <div class="alert alert-info mb-3">
                                    <div class="fw-semibold">Workflow cue</div>
                                    <div class="small">Start from completed receiving records, choose the right accountability document, assign the accountable office and employee, then print PAR/ICS and QR tags after posting.</div>
                                </div>
                                <!-- SPA: Step 1 + Split panel editor -->
                                <div class="card mb-3 workspace-form-section">
                                    <div class="card-body p-3">
                                        <div class="workspace-header">
                                            <div class="workspace-header-copy">
                                                <div class="small fw-semibold text-muted mb-1">Step 1: Choose distribution document</div>
                                                <div class="small text-muted">Pick the accountability flow first, then choose the receiving record and units to assign.</div>
                                            </div>
                                            <div class="workspace-actions workspace-toolbar-cluster">
                                                <span class="badge text-bg-light"><?php echo count($iarList); ?> source record(s)</span>
                                            </div>
                                        </div>
                                        <div class="workspace-actions workspace-toolbar-cluster mt-3">
                                            <a href="?document_type=par" class="btn btn-sm <?php echo $distributionType==='par' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                                PAR
                                                <span class="d-block" style="font-size:10px;font-weight:400;">Equipment ≥ ₱<?php echo number_format($equipmentMin,0,'.',','); ?></span>
                                            </a>
                                            <a href="?document_type=ics&semi_type=high_value" class="btn btn-sm <?php echo ($distributionType==='ics' && $distributionSemiType==='high_value') ? 'btn-success' : 'btn-outline-secondary'; ?>">
                                                ICS – High Value
                                                <span class="d-block" style="font-size:10px;font-weight:400;">₱<?php echo number_format($semiHvMin+0.01,2,'.',','); ?> – ₱<?php echo number_format($equipmentMin-0.01,2,'.',','); ?></span>
                                            </a>
                                            <a href="?document_type=ics&semi_type=low_value" class="btn btn-sm <?php echo ($distributionType==='ics' && $distributionSemiType==='low_value') ? 'btn-warning' : 'btn-outline-secondary'; ?>">
                                                ICS – Low Value
                                                <span class="d-block" style="font-size:10px;font-weight:400;">₱<?php echo number_format($semiHvMin,2,'.',','); ?> and below</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-lg-4">
                                        <div class="card h-100">
                                            <div class="card-body p-3">
                                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                                    <div>
                                                        <div class="small fw-semibold text-muted">Step 2: Choose source receiving</div>
                                                        <div class="small text-muted">Search the IAR list and open the record that still has units ready for distribution.</div>
                                                    </div>
                                                    <span class="badge text-bg-light" id="iarVisibleCount"><?php echo count($iarList); ?> shown</span>
                                                </div>
                                                <div class="row g-2 mb-3">
                                                    <div class="col-sm-8">
                                                        <input type="text" id="iarSearchInput" class="form-control form-control-sm" placeholder="Search IAR, PO no., or supplier...">
                                                    </div>
                                                    <div class="col-sm-4">
                                                        <select id="iarUnitsFilter" class="form-select form-select-sm">
                                                            <option value="">All sizes</option>
                                                            <option value="1-4">1 to 4 units</option>
                                                            <option value="5-9">5 to 9 units</option>
                                                            <option value="10+">10+ units</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div id="iarListScroll" style="max-height:560px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;">
                                                    <?php foreach ($iarList as $iar): $unitCount = (int)($iar['available_units'] ?? 0); ?>
                                                        <div class="iar-list-row" data-iar-id="<?= (int)$iar['id'] ?>" data-units="<?= $unitCount ?>" style="padding:10px 12px;border-radius:10px;cursor:pointer;border:1px solid var(--bs-border-color);">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <span class="badge text-bg-light"><?= h($iar['system_reference']) ?></span>
                                                                <span class="badge <?= $distributionType==='par' ? 'text-bg-primary' : 'text-bg-success' ?>"><?= h(distribution_doc_label($distributionType)) ?></span>
                                                                <span class="badge text-bg-secondary ms-auto"><?= $unitCount ?> unit<?= $unitCount!==1?'s':'' ?></span>
                                                            </div>
                                                            <div class="fw-semibold mb-1"><?= h($iar['po_number']) ?></div>
                                                            <div class="small text-muted text-truncate"><?= h($iar['supplier_name']) ?></div>
                                                            <div class="small text-muted mt-1">Received <?= h(date('M d, Y', strtotime($iar['received_date']))) ?></div>
                                                            <div style="display:none;">
                                                                <?= h($iar['supplier_name']) ?> · <?= h(date('M d, Y', strtotime($iar['received_date']))) ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($iarList)): ?>
                                                        <div class="text-center text-muted py-4" style="font-size:12px;">No receiving records with available <?= $distributionType==='par' ? 'equipment' : 'semi-expendable' ?> units.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-8">
                                        <div id="distEditorEmpty" class="card h-100">
                                            <div class="card-body d-flex align-items-center justify-content-center text-muted py-5">
                                                <div class="text-center">
                                                    <div class="mb-1">Select a receiving record from the list</div>
                                                    <div style="font-size:12px;">Units available for distribution will appear here</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="distEditorContent" style="display:none;">
                                            <form method="post" id="distributionForm" enctype="multipart/form-data">
                                                <input type="hidden" name="document_type" value="<?= h($distributionType) ?>">
                                                <input type="hidden" name="semi_type" value="<?= h($distributionSemiType) ?>">
                                                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="receiving_id" id="hiddenReceivingId" value="">

                                                <div class="card mb-3 position-sticky workspace-sticky-bar" style="top:90px;z-index:10;">
                                                    <div class="card-body p-3 workspace-editor-shell">
                                                        <div class="workspace-header">
                                                            <div class="workspace-header-copy">
                                                                <div class="small fw-semibold text-muted mb-1">Workspace progress</div>
                                                                <div id="distIarSummary" class="small text-muted"></div>
                                                            </div>
                                                            <div class="workspace-header-meta text-sm-end">
                                                                <div class="small text-muted">Step 3: Select units and assign accountability</div>
                                                                <div class="fw-semibold">
                                                                    <span id="selectedUnitCount">0</span> unit(s) selected
                                                                    <span class="text-muted">across</span>
                                                                    <span id="selectedGroupCount">0</span> group(s)
                                                                </div>
                                                                <div class="small">Total: <strong id="distTotal">Php 0.00</strong></div>
                                                                <div class="small mt-1" id="distReadyText">Select units to continue.</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="card mb-3 workspace-editor-shell">
                                                    <div class="card-body p-3">
                                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                            <div>
                                                                <div class="small fw-semibold text-muted">Step 3A: Units to distribute</div>
                                                                <div class="small text-muted">Use the group cards for bulk selection, then fine-tune at the unit level.</div>
                                                                <div class="small text-muted">Brand, model, and serial number are optional when not available.</div>
                                                            </div>
                                                            <label class="small" style="cursor:pointer;"><input type="checkbox" id="selectAllUnits" class="me-1"> Select all units</label>
                                                        </div>
                                                        <div id="distUnitsContainer"></div>
                                                        <div id="distUnitsLoading" class="text-center text-muted py-3" style="font-size:12px;display:none;">Loading units...</div>
                                                    </div>
                                                </div>

                                                <div class="card workspace-editor-shell">
                                                    <div class="card-body p-3">
                                                        <div class="small fw-semibold text-muted mb-3">Step 3B: Assign accountability</div>
                                                        <div class="row g-3 mb-3 workspace-filter-panel">
                                                            <div class="col-12">
                                                                <div id="distribution_form_feedback" class="alert alert-danger small py-2 px-3 mb-0 d-none" role="alert" aria-live="polite"></div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Office *</label>
                                                                <select class="form-select" id="office_id" name="office_id" required data-placeholder="Select office">
                                                                    <option value="">Select office</option>
                                                                    <?php foreach ($offices as $office): ?>
                                                                        <option value="<?= (int)$office['id'] ?>" <?php echo $form['office_id'] === (string)($office['id'] ?? '') ? 'selected' : ''; ?>><?= h($office['office_name']) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <div id="office_id_feedback" class="small text-danger mt-1 d-none">Office is required.</div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Accountable Employee <span class="text-danger">*</span></label>
                                                                <select class="form-select" id="employee_id" name="employee_id" data-placeholder="Select employee" required>
                                                                    <option value="">Select employee</option>
                                                                    <?php foreach ($employees as $emp): ?>
                                                                        <?php
                                                                        $employeeId = (int) ($emp['id'] ?? 0);
                                                                        $officeIds = array_map('intval', (array) ($employeeOfficeMap[$employeeId]['office_ids'] ?? []));
                                                                        if (!$officeIds && !empty($emp['office_id'])) {
                                                                            $officeIds = [(int) $emp['office_id']];
                                                                        }
                                                                        $primaryOfficeId = (int) ($employeeOfficeMap[$employeeId]['primary_office_id'] ?? 0);
                                                                        if ($primaryOfficeId <= 0 && !empty($emp['office_id'])) {
                                                                            $primaryOfficeId = (int) $emp['office_id'];
                                                                        }
                                                                        $unitHeadOfficeIds = array_map('intval', (array) ($employeeOfficeMap[$employeeId]['unit_head_office_ids'] ?? []));
                                                                        ?>
                                                                        <option value="<?= (int)$emp['id'] ?>"
                                                                                data-office-ids="<?= h(implode(',', $officeIds)) ?>"
                                                                                data-primary-office-id="<?= (int) $primaryOfficeId ?>"
                                                                                data-unit-head-office-ids="<?= h(implode(',', $unitHeadOfficeIds)) ?>"
                                                                                data-is-unit-head="<?= (!empty($unitHeadOfficeIds) || (int)($emp['is_unit_head'] ?? 0) === 1) ? '1' : '0' ?>"
                                                                                data-position-title="<?= h($emp['position_title'] ?? '') ?>"
                                                                                <?php echo $form['employee_id'] === (string)($emp['id'] ?? '') ? 'selected' : ''; ?>>
                                                                            <?= h(employee_display_name($emp) . ' - ' . $emp['employee_no'] . (!empty($emp['position_title']) ? ' (' . $emp['position_title'] . ')' : '')) ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <div id="employee_id_feedback" class="small text-danger mt-1 d-none">Accountable employee is required.</div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label">Distribution Date *</label>
                                                                <input type="date" class="form-control" id="distribution_date" name="distribution_date" value="<?= h($form['distribution_date']) ?>" required>
                                                                <div id="distribution_date_feedback" class="small text-danger mt-1 d-none">Distribution date is required.</div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label">System Reference</label>
                                                                <input type="text" class="form-control" value="<?= h($form['system_reference']) ?>" readonly>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label"><?= h(distribution_doc_label($distributionType)) ?> Number</label>
                                                                <input type="text" class="form-control" value="<?= h($form['document_no']) ?>" readonly>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Purpose</label>
                                                                <textarea class="form-control" name="purpose" rows="2"><?= h($form['purpose']) ?></textarea>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Remarks</label>
                                                                <textarea class="form-control" name="remarks" rows="2"><?= h($form['remarks']) ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="workspace-header">
                                                            <div class="small text-muted d-none"><span>0</span> unit(s) selected · Total: <strong>₱0.00</strong></div>
                                                            <div class="small text-muted">Step 4: Review the summary above, then post the final <?= h(distribution_doc_label($distributionType)) ?>.</div>
                                                            <div class="workspace-actions workspace-toolbar-cluster">
                                                                <button type="submit" class="btn btn-primary" id="postDistBtn" disabled>Post <?= h(distribution_doc_label($distributionType)) ?></button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="workspace-header mb-3">
                    <div class="workspace-header-copy">
                        <h5 class="card-title mb-0">Posted Distributions</h5>
                        <p class="text-muted mb-0">Review posted accountability documents and correct the header details when the assigned office, employee, or notes were entered incorrectly.</p>
                    </div>
                    <div class="workspace-actions">
                        <a href="<?php echo h(base_url('modules/distributions/index.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))); ?>" class="btn btn-sm btn-outline-success">Export CSV</a>
                        <a href="<?php echo base_url('modules/distributions/par_office.php'); ?>" class="btn btn-sm btn-outline-primary" target="_blank">PAR by Office</a>
                        <a href="<?php echo base_url('modules/distributions/ics_office.php'); ?>" class="btn btn-sm btn-outline-success" target="_blank">ICS by Office</a>
                        <span class="badge text-bg-light"><?php echo count($distributions); ?> record(s)</span>
                    </div>
                </div>

                <?php if ($editingDistribution): ?>
                    <div class="card mb-3 workspace-editor-shell border-primary-subtle" id="distribution-edit-panel">
                        <div class="card-body p-3">
                            <div class="workspace-header mb-3">
                                <div class="workspace-header-copy">
                                    <div class="small fw-semibold text-muted mb-1">Edit posted distribution</div>
                                    <div class="small text-muted">
                                        You can correct posted details for
                                        <strong><?php echo h((string) ($editingDistribution['document_no'] ?? '')); ?></strong>.
                                        If office changes and property numbers still end with the old office code, the suffix is updated automatically (for example, <code>-PLAN</code> to <code>-PMU</code>).
                                    </div>
                                </div>
                                <div class="workspace-actions">
                                    <span class="badge text-bg-light"><?php echo h(strtoupper((string) ($editingDistribution['document_type'] ?? ''))); ?></span>
                                    <a href="<?php echo base_url('modules/distributions/index.php?document_type=' . urlencode((string) $distributionType)); ?>" class="btn btn-sm btn-outline-secondary">Close</a>
                                </div>
                            </div>
                            <form method="post" id="editDistributionForm" class="row g-3 workspace-filter-panel">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="update_distribution">
                                <input type="hidden" name="edit_id" value="<?php echo (int) ($editingDistribution['id'] ?? 0); ?>">
                                <div class="col-12">
                                    <div id="edit_distribution_form_feedback" class="alert alert-danger small py-2 px-3 mb-0 d-none" role="alert" aria-live="polite"></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Document Number</label>
                                    <input type="text" class="form-control" value="<?php echo h((string) ($editingDistribution['document_no'] ?? '')); ?>" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">System Reference</label>
                                    <input type="text" class="form-control" value="<?php echo h((string) ($editingDistribution['system_reference'] ?? '')); ?>" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Distribution Date *</label>
                                    <input type="date" class="form-control" id="edit_distribution_date" name="distribution_date" value="<?php echo h((string) ($editForm['distribution_date'] ?? '')); ?>" required>
                                    <div id="edit_distribution_date_feedback" class="small text-danger mt-1 d-none">Distribution date is required.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Office *</label>
                                    <select class="form-select" name="office_id" id="edit_office_id" required data-placeholder="Select office">
                                        <option value="">Select office</option>
                                        <?php foreach ($offices as $office): ?>
                                            <option value="<?php echo (int) $office['id']; ?>" <?php echo (string) ($editForm['office_id'] ?? '') === (string) ($office['id'] ?? '') ? 'selected' : ''; ?>>
                                                <?php echo h((string) ($office['office_name'] ?? '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="edit_office_id_feedback" class="small text-danger mt-1 d-none">Office is required.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Accountable Employee <span class="text-danger">*</span></label>
                                    <select class="form-select" name="employee_id" id="edit_employee_id" data-placeholder="Select employee" required>
                                        <option value="">Select employee</option>
                                        <?php foreach ($employees as $emp): ?>
                                            <?php
                                            $employeeId = (int) ($emp['id'] ?? 0);
                                            $officeIds = array_map('intval', (array) ($employeeOfficeMap[$employeeId]['office_ids'] ?? []));
                                            if (!$officeIds && !empty($emp['office_id'])) {
                                                $officeIds = [(int) $emp['office_id']];
                                            }
                                            $primaryOfficeId = (int) ($employeeOfficeMap[$employeeId]['primary_office_id'] ?? 0);
                                            if ($primaryOfficeId <= 0 && !empty($emp['office_id'])) {
                                                $primaryOfficeId = (int) $emp['office_id'];
                                            }
                                            $unitHeadOfficeIds = array_map('intval', (array) ($employeeOfficeMap[$employeeId]['unit_head_office_ids'] ?? []));
                                            ?>
                                            <option value="<?php echo (int) $emp['id']; ?>"
                                                data-office-ids="<?php echo h(implode(',', $officeIds)); ?>"
                                                data-primary-office-id="<?php echo (int) $primaryOfficeId; ?>"
                                                data-unit-head-office-ids="<?php echo h(implode(',', $unitHeadOfficeIds)); ?>"
                                                <?php echo (string) ($editForm['employee_id'] ?? '') === (string) ($emp['id'] ?? '') ? 'selected' : ''; ?>>
                                                <?php echo h(employee_display_name($emp) . ' - ' . ($emp['employee_no'] ?? '') . (!empty($emp['position_title']) ? ' (' . $emp['position_title'] . ')' : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="edit_employee_id_feedback" class="small text-danger mt-1 d-none">Accountable employee is required.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Purpose</label>
                                    <textarea class="form-control" name="purpose" rows="2"><?php echo h((string) ($editForm['purpose'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Remarks</label>
                                    <textarea class="form-control" name="remarks" rows="2"><?php echo h((string) ($editForm['remarks'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <div class="card border-0 bg-light-subtle">
                                        <div class="card-body p-3">
                                            <div class="small fw-semibold text-muted mb-2">Posted items and unit details</div>
                                            <div class="small text-muted mb-2">Brand, model, and serial number are optional when unavailable.</div>
                                            <?php if ($editingDistributionItems): ?>
                                                <?php foreach ($editingDistributionItems as $idx => $itemRow): ?>
                                                    <?php
                                                        $lineNo = (int) ($itemRow['line_no'] ?? 0);
                                                        $labelParts = [];
                                                        if (!empty($itemRow['classification_name'])) {
                                                            $labelParts[] = (string) $itemRow['classification_name'];
                                                        }
                                                        if (!empty($itemRow['item_description'])) {
                                                            $labelParts[] = (string) $itemRow['item_description'];
                                                        }
                                                        $itemLabel = trim(implode(' - ', $labelParts));
                                                        if ($itemLabel === '') {
                                                            $itemLabel = 'Item line #' . ((int) $idx + 1);
                                                        }
                                                        $distributionItemId = (int) ($itemRow['id'] ?? 0);
                                                    ?>
                                                    <div class="border rounded-3 p-3 mb-3 bg-white">
                                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                                            <div>
                                                                <div class="fw-semibold"><?php echo h($itemLabel); ?></div>
                                                                <div class="small text-muted">
                                                                    <?php echo $lineNo > 0 ? 'Line ' . h((string) $lineNo) . ' | ' : ''; ?>
                                                                    Unit Cost: <?php echo h(number_format((float) ($itemRow['unit_cost'] ?? 0), 2)); ?> |
                                                                    Amount: <?php echo h(number_format((float) ($itemRow['line_total'] ?? 0), 2)); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row g-2 mb-2">
                                                            <div class="col-md-3">
                                                                <label class="form-label small mb-1">Quantity Distributed *</label>
                                                                <input type="number" class="form-control form-control-sm" name="quantity_distributed[<?php echo $distributionItemId; ?>]" value="<?php echo h(format_quantity((float) ($itemRow['quantity_distributed'] ?? 0))); ?>" min="0" step="0.01" required>
                                                            </div>
                                                            <div class="col-md-9">
                                                                <label class="form-label small mb-1">Line Remarks</label>
                                                                <textarea class="form-control form-control-sm" name="line_remarks[<?php echo $distributionItemId; ?>]" rows="2"><?php echo h((string) ($itemRow['remarks'] ?? '')); ?></textarea>
                                                            </div>
                                                        </div>

                                                        <?php if (!empty($itemRow['details'])): ?>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm align-middle mb-0">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Property No.</th>
                                                                            <th>Brand</th>
                                                                            <th>Model</th>
                                                                            <th>Serial No.</th>
                                                                            <th>Unit Remarks</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($itemRow['details'] as $detailRow): ?>
                                                                            <?php $detailId = (int) ($detailRow['id'] ?? 0); ?>
                                                                            <tr>
                                                                                <td><input type="text" class="form-control form-control-sm fw-semibold" name="detail_property_number[<?php echo $detailId; ?>]" value="<?php echo h((string) ($detailRow['property_number'] ?? '')); ?>"></td>
                                                                                <td><input type="text" class="form-control form-control-sm" name="detail_brand[<?php echo $detailId; ?>]" value="<?php echo h((string) ($detailRow['brand'] ?? '')); ?>"></td>
                                                                                <td><input type="text" class="form-control form-control-sm" name="detail_model[<?php echo $detailId; ?>]" value="<?php echo h((string) ($detailRow['model'] ?? '')); ?>"></td>
                                                                                <td><input type="text" class="form-control form-control-sm" name="detail_serial_no[<?php echo $detailId; ?>]" value="<?php echo h((string) ($detailRow['serial_no'] ?? '')); ?>"></td>
                                                                                <td><input type="text" class="form-control form-control-sm" name="detail_remarks[<?php echo $detailId; ?>]" value="<?php echo h((string) ($detailRow['remarks'] ?? '')); ?>"></td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="small text-muted">No unit-level rows were saved for this line.</div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="text-muted small">No item rows found for this distribution record.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                    <a href="<?php echo base_url('modules/distributions/index.php?document_type=' . urlencode((string) $distributionType)); ?>" class="btn btn-outline-secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="small text-muted fw-semibold">Quick filters:</span>
                    <a href="<?php echo base_url('modules/distributions/index.php?document_type=' . urlencode((string) $distributionType)); ?>" class="btn btn-sm <?php echo empty($filterDistType) ? 'btn-primary' : 'btn-outline-secondary'; ?>">All Posted</a>
                    <a href="<?php echo base_url('modules/distributions/index.php?document_type=' . urlencode((string) $distributionType) . '&filter_type=ics'); ?>" class="btn btn-sm <?php echo ($filterDistType ?? '') === 'ics' ? 'btn-primary' : 'btn-outline-secondary'; ?>">ICS</a>
                    <a href="<?php echo base_url('modules/distributions/index.php?document_type=' . urlencode((string) $distributionType) . '&filter_type=par'); ?>" class="btn btn-sm <?php echo ($filterDistType ?? '') === 'par' ? 'btn-primary' : 'btn-outline-secondary'; ?>">PAR</a>
                </div>

                <form method="get" class="row g-2 align-items-center mb-3 workspace-filter-panel">
                    <input type="hidden" name="document_type" value="<?php echo h($distributionType); ?>">
                    <div class="col-auto">
                        <select name="filter_type" class="form-select form-select-sm">
                            <option value="">All types</option>
                            <option value="ics" <?php echo (isset($filterDistType) && $filterDistType === 'ics') ? 'selected' : ''; ?>>ICS</option>
                            <option value="par" <?php echo (isset($filterDistType) && $filterDistType === 'par') ? 'selected' : ''; ?>>PAR</option>
                        </select>
                    </div>
                    <div class="col">
                        <input type="search" name="dist_q" class="form-control form-control-sm" placeholder="Search reference, document no, office, employee..." value="<?php echo h($filterDistQ ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-auto">
                        <div class="d-grid gap-2 d-sm-flex">
                            <button type="submit" class="btn btn-sm btn-primary">Search</button>
                            <a href="<?php echo base_url('modules/distributions/index.php?document_type=' . urlencode((string) $distributionType)); ?>" class="btn btn-sm btn-link">Clear</a>
                        </div>
                    </div>
                </form>
                <form method="get" action="<?php echo base_url('modules/distributions/consolidated_print.php'); ?>" target="_blank" class="mb-0">
                <div class="d-flex flex-wrap align-items-end gap-2 mb-3 p-3 border rounded bg-light">
                    <div>
                        <label for="consolidated_print_date" class="form-label small mb-1">Acceptance / print date</label>
                        <input type="date" class="form-control form-control-sm" id="consolidated_print_date" name="print_date" value="<?php echo h(date('Y-m-d')); ?>" required>
                    </div>
                    <div>
                        <label for="consolidated_extra_rows" class="form-label small mb-1">Extra rows</label>
                        <input type="number" class="form-control form-control-sm" id="consolidated_extra_rows" name="extra_rows" value="0" min="0" max="35" step="1" style="width:90px;">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">
                        Print Selected Acceptance
                    </button>
                    <div class="small text-muted">
                        Select posted distributions from the same PO, office, employee, supplier, fund, and document type.
                    </div>
                </div>
                <div class="table-responsive mobile-table-frame">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="width:36px;"><span class="visually-hidden">Select</span></th>
                                <th data-sort="ref">Reference <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="docno">Document No. <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="date">Date <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="type">Type <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="items">Items <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="office">Office <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="employee">Employee <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                                <th class="text-end" data-sort="amount">Amount <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($distributions): ?>
                                <?php foreach ($distributions as $distribution): ?>
                                    <tr>
                                        <td>
                                            <input class="form-check-input" type="checkbox" name="distribution_ids[]" value="<?php echo (int) $distribution['id']; ?>" aria-label="Select <?php echo h((string) $distribution['document_no']); ?>">
                                        </td>
                                        <td class="fw-semibold"><?php echo h($distribution['system_reference']); ?></td>
                                        <td><?php echo h($distribution['document_no']); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($distribution['distribution_date']))); ?></td>
                                        <td><?php echo h(strtoupper($distribution['document_type'])); ?></td>
                                        <td style="min-width:260px;">
                                            <?php
                                                $itemNames = array_values(array_filter(array_map('trim', explode(' || ', (string) ($distribution['distributed_items'] ?? '')))));
                                                $visibleItems = array_slice($itemNames, 0, 3);
                                                $remainingItems = max(0, count($itemNames) - count($visibleItems));
                                            ?>
                                            <?php if ($visibleItems): ?>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php foreach ($visibleItems as $itemName): ?>
                                                        <span class="badge text-bg-light text-wrap text-start"><?php echo h($itemName); ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if ($remainingItems > 0): ?>
                                                        <span class="badge text-bg-secondary">+<?php echo h((string) $remainingItems); ?> more</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">No item summary</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo h($distribution['office_name']); ?></td>
                                        <td><?php echo $distribution['employee_no'] ? h(employee_display_name($distribution)) . ' - ' . h($distribution['employee_no']) : '<span class="text-muted">Not specified</span>'; ?></td>
                                        <td><?php echo operational_status_badge('posted_transaction', (string) ($distribution['status'] ?? 'posted')); ?></td>
                                        <td class="text-end">
                                            <a href="<?php echo base_url('modules/distributions/view.php?id=' . (int) $distribution['id']); ?>" class="btn btn-sm btn-outline-dark me-1">View</a>
                                            <a href="<?php echo base_url('modules/distributions/index.php?document_type=' . urlencode((string) $distributionType) . '&edit_id=' . (int) $distribution['id'] . '#distribution-edit-panel'); ?>" class="btn btn-sm btn-outline-secondary me-1">Edit</a>
                                            <?php if (($distribution['document_type'] ?? '') === 'par'): ?>
                                                <a href="<?php echo base_url('modules/distributions/par.php?id=' . (int)$distribution['id']); ?>" class="btn btn-sm btn-outline-primary me-1" target="_blank">Print PAR</a>
                                            <?php else: ?>
                                                <a href="<?php echo base_url('modules/distributions/ics.php?id=' . (int)$distribution['id']); ?>" class="btn btn-sm btn-outline-primary me-1" target="_blank">Print ICS</a>
                                            <?php endif; ?>
                                            <a href="<?php echo base_url('modules/property/tags.php?distribution_id=' . (int)$distribution['id']); ?>" class="btn btn-outline-secondary btn-sm me-1" target="_blank">QR Tags</a>
                                            <!-- "View / Print" removed: Print PAR/ICS and QR Tags are sufficient -->
                                        </td>
                                        <td class="text-end"><?php echo h(number_format((float) $distribution['total_amount'], 2)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="11" class="text-center text-muted py-4">No distributions posted yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </form>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var officeSelect = document.getElementById('office_id');
    var employeeSelect = document.getElementById('employee_id');
    var editOfficeSelect = document.getElementById('edit_office_id');
    var editEmployeeSelect = document.getElementById('edit_employee_id');

    function filterEmployeesFor(officeField, employeeField) {
        if (!officeField || !employeeField) return;
        var selectedOffice = officeField.value;
        Array.prototype.forEach.call(employeeField.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            var matches = !selectedOffice || option.getAttribute('data-office-id') === selectedOffice;
            option.hidden = !matches;
            if (!matches && option.selected) {
                employeeField.value = '';
            }
        });
        if (window.SPAMS && window.SPAMS.refreshSelect2) {
            window.SPAMS.refreshSelect2(employeeField);
        }
    }

    if (officeSelect) {
        officeSelect.addEventListener('change', function () {
            filterEmployeesFor(officeSelect, employeeSelect);
        });
        if (window.jQuery) {
            window.jQuery(officeSelect).on('select2:select select2:clear', function () {
                filterEmployeesFor(officeSelect, employeeSelect);
            });
        }
        filterEmployeesFor(officeSelect, employeeSelect);
    }

    if (editOfficeSelect) {
        editOfficeSelect.addEventListener('change', function () {
            filterEmployeesFor(editOfficeSelect, editEmployeeSelect);
        });
        if (window.jQuery) {
            window.jQuery(editOfficeSelect).on('select2:select select2:clear', function () {
                filterEmployeesFor(editOfficeSelect, editEmployeeSelect);
            });
        }
        filterEmployeesFor(editOfficeSelect, editEmployeeSelect);
    }

    // Select-All Units checkbox for distributions unit rows
    var selectAll = document.getElementById('selectAllUnits');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checked = this.checked;
            document.querySelectorAll('input[name^="units["]').forEach(function (cb) {
                cb.checked = checked;
            });
        });
    }

    // Per-item Select-All: toggle unit checkboxes for the candidate that owns the header
    document.querySelectorAll('.select-all-units').forEach(function(cb) {
        cb.addEventListener('change', function () {
            var row = cb.closest('tr');
            var sibling = row;
            while ((sibling = sibling.nextElementSibling)) {
                var unitCb = sibling.querySelector('.unit-checkbox');
                if (!unitCb) break;
                unitCb.checked = cb.checked;
            }
        });
    });

    if (window.location.hash === '#distribution-edit-panel') {
        var editPanel = document.getElementById('distribution-edit-panel');
        if (editPanel) {
            setTimeout(function () {
                editPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 120);
        }
    }
});
// SPA: bind IAR list and AJAX unit loading
document.addEventListener('DOMContentLoaded', function () {
        return;
        var iarRows = document.querySelectorAll('.iar-list-row');
        var editorEmpty = document.getElementById('distEditorEmpty');
        var editorContent = document.getElementById('distEditorContent');
        var unitsContainer = document.getElementById('distUnitsContainer');
        var unitsLoading = document.getElementById('distUnitsLoading');
        var iarSummary = document.getElementById('distIarSummary');
        var hiddenRid = document.getElementById('hiddenReceivingId');
        var postBtn = document.getElementById('postDistBtn');
        var countLabel = document.getElementById('selectedUnitCount');
        var totalLabel = document.getElementById('distTotal');
        var itemType = '<?= h($itemTypeFilter) ?>';

        function updateTotal() {
                var checked = document.querySelectorAll('.unit-checkbox:checked');
                var total = 0;
                checked.forEach(function(cb) { total += parseFloat(cb.dataset.cost || 0); });
                countLabel.textContent = checked.length;
                totalLabel.textContent = '₱' + total.toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2});
                if (postBtn) postBtn.disabled = checked.length === 0;
        }

        function loadUnits(iarId) {
                if (!hiddenRid) return;
                hiddenRid.value = iarId;
                unitsContainer.innerHTML = '';
                unitsLoading.style.display = 'block';
                editorEmpty.style.display = 'none';
                editorContent.style.display = '';

                fetch('units_preview.php?receiving_id=' + iarId + '&item_type=' + encodeURIComponent(itemType))
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        unitsLoading.style.display = 'none';
                        if (!data.ok) { unitsContainer.innerHTML = '<div class="text-danger small py-2">Failed to load units.</div>'; return; }
                        unitsContainer.innerHTML = data.html;
                        var h = data.header || {};
                        iarSummary.innerHTML = '<span class="fw-semibold">' + (h.system_reference||'') + '</span>' +
                            '<span class="text-muted ms-2">' + (h.po_number||'') + '</span>' +
                            '<span class="text-muted ms-2">' + (h.supplier_name||'') + '</span>';
                        document.querySelectorAll('.unit-checkbox').forEach(function(cb){ cb.addEventListener('change', updateTotal); });
                        updateTotal();
                }).catch(function(){ unitsLoading.style.display = 'none'; unitsContainer.innerHTML = '<div class="text-danger small py-2">Network error.</div>'; });
        }

        iarRows.forEach(function(row){ row.addEventListener('click', function(){ iarRows.forEach(function(r){ r.style.background=''; r.style.borderColor='transparent'; }); row.style.background='var(--bs-primary-bg-subtle)'; row.style.borderColor='var(--bs-primary-border-subtle)'; loadUnits(row.dataset.iarId); }); });

        var selectAllBtn = document.getElementById('selectAllUnits');
        if (selectAllBtn) {
            selectAllBtn.addEventListener('change', function(){ document.querySelectorAll('.unit-checkbox').forEach(function(cb){ cb.checked = selectAllBtn.checked; }); updateTotal(); });
        }

        var iarSearch = document.getElementById('iarSearchInput');
        if (iarSearch) {
            iarSearch.addEventListener('input', function(){ var q = this.value.trim().toLowerCase(); document.querySelectorAll('.iar-list-row').forEach(function(r){ r.style.display = (!q || r.textContent.toLowerCase().includes(q)) ? '' : 'none'; }); });
        }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var officeSelect = document.getElementById('office_id');
    var employeeSelect = document.getElementById('employee_id');
    var iarRows = Array.prototype.slice.call(document.querySelectorAll('.iar-list-row'));
    var iarSearch = document.getElementById('iarSearchInput');
    var iarUnitsFilter = document.getElementById('iarUnitsFilter');
    var iarVisibleCount = document.getElementById('iarVisibleCount');
    var editorEmpty = document.getElementById('distEditorEmpty');
    var editorContent = document.getElementById('distEditorContent');
    var unitsContainer = document.getElementById('distUnitsContainer');
    var unitsLoading = document.getElementById('distUnitsLoading');
    var iarSummary = document.getElementById('distIarSummary');
    var hiddenRid = document.getElementById('hiddenReceivingId');
    var postBtn = document.getElementById('postDistBtn');
    var countLabel = document.getElementById('selectedUnitCount');
    var groupCountLabel = document.getElementById('selectedGroupCount');
    var totalLabel = document.getElementById('distTotal');
    var readyText = document.getElementById('distReadyText');
    var selectAllBtn = document.getElementById('selectAllUnits');
    var itemType = '<?= h($itemTypeFilter) ?>';
    var semiType = '<?= h($distributionSemiType) ?>';
    var selectedIarId = '<?= (int) $selectedReceivingId ?>';
    var syncingAssignment = false;

    function optionOfficeIds(option) {
        return (option.getAttribute('data-office-ids') || '').split(',').map(function (value) {
            return value.trim();
        }).filter(Boolean);
    }

    function optionUnitHeadOfficeIds(option) {
        return (option.getAttribute('data-unit-head-office-ids') || '').split(',').map(function (value) {
            return value.trim();
        }).filter(Boolean);
    }

    function refreshSelectWidget(select) {
        if (window.SPAMS && window.SPAMS.refreshSelect2) {
            window.SPAMS.refreshSelect2(select);
        }
    }

    function findUnitHeadOption(officeId) {
        if (!officeId || !employeeSelect) {
            return null;
        }

        return Array.prototype.find.call(employeeSelect.options, function (option) {
            return option.value &&
                optionUnitHeadOfficeIds(option).indexOf(officeId) !== -1;
        }) || null;
    }

    function refreshEmployeeFilter(autoSelectHead) {
        if (!officeSelect || !employeeSelect) {
            return;
        }

        var selectedOffice = officeSelect.value;
        var currentEmployeeStillValid = false;
        Array.prototype.forEach.call(employeeSelect.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }

            var matches = !selectedOffice || optionOfficeIds(option).indexOf(selectedOffice) !== -1;
            option.hidden = !matches;
            if (matches && option.value === employeeSelect.value) {
                currentEmployeeStillValid = true;
            }
        });

        if (!currentEmployeeStillValid && employeeSelect.value) {
            employeeSelect.value = '';
        }

        if (autoSelectHead && selectedOffice) {
            var headOption = findUnitHeadOption(selectedOffice);
            if (headOption) {
                employeeSelect.value = headOption.value;
            }
        }

        refreshSelectWidget(employeeSelect);
    }

    function syncOfficeFromEmployee() {
        if (!officeSelect || !employeeSelect || !employeeSelect.value) {
            return;
        }

        var selectedOption = employeeSelect.options[employeeSelect.selectedIndex];
        if (!selectedOption) {
            return;
        }

        var officeIds = optionOfficeIds(selectedOption);
        var primaryOfficeId = selectedOption.getAttribute('data-primary-office-id') || '';
        var currentOfficeId = officeSelect.value || '';
        var nextOfficeId = '';

        if (!currentOfficeId) {
            nextOfficeId = primaryOfficeId || (officeIds[0] || '');
        } else if (officeIds.length > 0 && officeIds.indexOf(currentOfficeId) === -1) {
            nextOfficeId = primaryOfficeId || (officeIds[0] || '');
        }

        if (nextOfficeId && officeSelect.value !== nextOfficeId) {
            officeSelect.value = nextOfficeId;
            refreshSelectWidget(officeSelect);
        }
    }

    function applyIarFilters() {
        var searchTerm = (iarSearch && iarSearch.value ? iarSearch.value : '').trim().toLowerCase();
        var unitsFilter = iarUnitsFilter ? iarUnitsFilter.value : '';
        var visibleCount = 0;

        iarRows.forEach(function (row) {
            var textMatch = !searchTerm || row.textContent.toLowerCase().indexOf(searchTerm) !== -1;
            var unitCount = parseInt(row.getAttribute('data-units') || '0', 10);
            var unitsMatch = true;

            if (unitsFilter === '1-4') {
                unitsMatch = unitCount >= 1 && unitCount <= 4;
            } else if (unitsFilter === '5-9') {
                unitsMatch = unitCount >= 5 && unitCount <= 9;
            } else if (unitsFilter === '10+') {
                unitsMatch = unitCount >= 10;
            }

            var isVisible = textMatch && unitsMatch;
            row.style.display = isVisible ? '' : 'none';
            if (isVisible) {
                visibleCount += 1;
            }
        });

        if (iarVisibleCount) {
            iarVisibleCount.textContent = visibleCount + ' shown';
        }
    }

    function refreshSummary() {
        var checked = Array.prototype.slice.call(document.querySelectorAll('#distUnitsContainer .unit-checkbox:checked'));
        var total = 0;
        var selectedGroups = {};

        checked.forEach(function (checkbox) {
            total += parseFloat(checkbox.getAttribute('data-cost') || '0');
            var groupId = checkbox.getAttribute('data-group-id') || '';
            if (groupId) {
                selectedGroups[groupId] = true;
            }
        });

        if (countLabel) {
            countLabel.textContent = checked.length;
        }
        if (groupCountLabel) {
            groupCountLabel.textContent = Object.keys(selectedGroups).length;
        }
        if (totalLabel) {
            totalLabel.textContent = 'Php ' + total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        Array.prototype.forEach.call(document.querySelectorAll('#distUnitsContainer .group-select-all'), function (checkbox) {
            var target = checkbox.getAttribute('data-group-target');
            var groupUnits = Array.prototype.slice.call(document.querySelectorAll('#distUnitsContainer .unit-checkbox[data-group-id="' + target + '"]'));
            var checkedUnits = groupUnits.filter(function (unitCheckbox) {
                return unitCheckbox.checked;
            });
            checkbox.checked = groupUnits.length > 0 && checkedUnits.length === groupUnits.length;
        });

        var notes = [];
        if (!checked.length) {
            notes.push('Select at least one unit.');
        }
        if (officeSelect && !officeSelect.value) {
            notes.push('Choose an office.');
        }

        if (readyText) {
            readyText.textContent = notes.length ? notes.join(' ') : 'Ready to post this distribution.';
            readyText.className = 'small mt-1 ' + (notes.length ? 'text-warning' : 'text-success');
        }

        if (postBtn) {
            postBtn.disabled = notes.length > 0;
        }

        if (selectAllBtn) {
            var allUnitCheckboxes = Array.prototype.slice.call(document.querySelectorAll('#distUnitsContainer .unit-checkbox'));
            selectAllBtn.checked = allUnitCheckboxes.length > 0 && checked.length === allUnitCheckboxes.length;
        }
    }

    function bindUnitHandlers() {
        Array.prototype.forEach.call(document.querySelectorAll('#distUnitsContainer .unit-checkbox'), function (checkbox) {
            checkbox.addEventListener('change', refreshSummary);
        });

        Array.prototype.forEach.call(document.querySelectorAll('#distUnitsContainer .group-select-all'), function (checkbox) {
            checkbox.addEventListener('change', function () {
                var target = checkbox.getAttribute('data-group-target');
                Array.prototype.forEach.call(document.querySelectorAll('#distUnitsContainer .unit-checkbox[data-group-id="' + target + '"]'), function (unitCheckbox) {
                    unitCheckbox.checked = checkbox.checked;
                });
                refreshSummary();
            });
        });

        Array.prototype.forEach.call(document.querySelectorAll('#distUnitsContainer .apply-group-remarks-btn'), function (button) {
            button.addEventListener('click', function () {
                var target = button.getAttribute('data-group-target');
                var remarksInput = document.querySelector('#distUnitsContainer .group-remarks-input[data-group-target="' + target + '"]');
                var remarksValue = remarksInput ? remarksInput.value : '';
                Array.prototype.forEach.call(document.querySelectorAll('#distUnitsContainer input[name^="unit_remarks["][data-group-id="' + target + '"]'), function (unitRemarksInput) {
                    unitRemarksInput.value = remarksValue;
                });
            });
        });
    }

    function setActiveSource(row) {
        iarRows.forEach(function (item) {
            item.classList.remove('shadow-sm');
            item.style.background = '';
            item.style.borderColor = 'var(--bs-border-color)';
        });

        row.classList.add('shadow-sm');
        row.style.background = 'var(--bs-primary-bg-subtle)';
        row.style.borderColor = 'var(--bs-primary-border-subtle)';
    }

    function loadUnits(iarId) {
        if (!hiddenRid) {
            return;
        }

        hiddenRid.value = iarId;
        unitsContainer.innerHTML = '';
        unitsLoading.style.display = 'block';
        editorEmpty.style.display = 'none';
        editorContent.style.display = '';

        fetch('units_preview.php?receiving_id=' + iarId + '&item_type=' + encodeURIComponent(itemType) + '&semi_type=' + encodeURIComponent(semiType))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                unitsLoading.style.display = 'none';
                if (!data.ok) {
                    unitsContainer.innerHTML = '<div class="text-danger small py-2">Failed to load units.</div>';
                    return;
                }

                unitsContainer.innerHTML = data.html;
                var header = data.header || {};
                iarSummary.innerHTML =
                    '<div class="fw-semibold">' + (header.system_reference || '') + '</div>' +
                    '<div class="small text-muted">' + (header.po_number || '') + ' &middot; ' + (header.supplier_name || '') + '</div>' +
                    '<div class="small text-muted">Received ' + (header.received_date || '') + '</div>';

                bindUnitHandlers();
                refreshSummary();
            })
            .catch(function () {
                unitsLoading.style.display = 'none';
                unitsContainer.innerHTML = '<div class="text-danger small py-2">Network error.</div>';
            });
    }

    if (officeSelect) {
        officeSelect.addEventListener('change', function () {
            if (syncingAssignment) {
                return;
            }
            syncingAssignment = true;
            refreshEmployeeFilter(true);
            refreshSummary();
            syncingAssignment = false;
        });

        if (window.jQuery) {
            window.jQuery(officeSelect).on('select2:select select2:clear', function () {
                if (syncingAssignment) {
                    return;
                }
                syncingAssignment = true;
                refreshEmployeeFilter(true);
                refreshSummary();
                syncingAssignment = false;
            });
        }
    }

    if (employeeSelect) {
        employeeSelect.addEventListener('change', function () {
            if (syncingAssignment) {
                return;
            }
            syncingAssignment = true;
            syncOfficeFromEmployee();
            refreshEmployeeFilter(false);
            refreshSummary();
            syncingAssignment = false;
        });

        if (window.jQuery) {
            window.jQuery(employeeSelect).on('select2:select select2:clear', function () {
                if (syncingAssignment) {
                    return;
                }
                syncingAssignment = true;
                syncOfficeFromEmployee();
                refreshEmployeeFilter(false);
                refreshSummary();
                syncingAssignment = false;
            });
        }
    }

    if (selectAllBtn) {
        selectAllBtn.addEventListener('change', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#distUnitsContainer .unit-checkbox'), function (checkbox) {
                checkbox.checked = selectAllBtn.checked;
            });
            Array.prototype.forEach.call(document.querySelectorAll('#distUnitsContainer .group-select-all'), function (checkbox) {
                checkbox.checked = selectAllBtn.checked;
            });
            refreshSummary();
        });
    }

    iarRows.forEach(function (row) {
        row.addEventListener('click', function () {
            setActiveSource(row);
            loadUnits(row.getAttribute('data-iar-id'));
        });
    });

    if (iarSearch) {
        iarSearch.addEventListener('input', applyIarFilters);
    }
    if (iarUnitsFilter) {
        iarUnitsFilter.addEventListener('change', applyIarFilters);
    }

    refreshEmployeeFilter(!$form['employee_id']);
    applyIarFilters();
    refreshSummary();

    if (selectedIarId) {
        var defaultRow = document.querySelector('.iar-list-row[data-iar-id="' + selectedIarId + '"]');
        if (defaultRow) {
            setActiveSource(defaultRow);
            loadUnits(selectedIarId);
        }
    }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.SPAMS || typeof window.SPAMS.setupRequiredSummaryValidation !== 'function') {
        return;
    }

    window.SPAMS.setupRequiredSummaryValidation({
        formId: 'distributionForm',
        summaryId: 'distribution_form_feedback',
        requiredFields: [
            { id: 'office_id', label: 'Office', feedbackId: 'office_id_feedback' },
            { id: 'employee_id', label: 'Accountable employee', feedbackId: 'employee_id_feedback' },
            { id: 'distribution_date', label: 'Distribution date', feedbackId: 'distribution_date_feedback', useSelect2: false }
        ]
    });

    window.SPAMS.setupRequiredSummaryValidation({
        formId: 'editDistributionForm',
        summaryId: 'edit_distribution_form_feedback',
        requiredFields: [
            { id: 'edit_office_id', label: 'Office', feedbackId: 'edit_office_id_feedback' },
            { id: 'edit_employee_id', label: 'Accountable employee', feedbackId: 'edit_employee_id_feedback' },
            { id: 'edit_distribution_date', label: 'Distribution date', feedbackId: 'edit_distribution_date_feedback', useSelect2: false }
        ]
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


