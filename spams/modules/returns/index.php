<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

function return_asset_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));

    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

function return_asset_person_label(array $row): string
{
    return trim((string) employee_display_name([
        'first_name' => $row['first_name'] ?? '',
        'middle_name' => $row['middle_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'suffix_name' => $row['suffix_name'] ?? '',
    ]));
}

function return_asset_document_label(array $row): string
{
    return trim(implode(' / ', array_filter([
        $row['document_type'] ?? '',
        $row['document_no'] ?? '',
    ])));
}

function return_next_rrpe_no(mysqli $db): string
{
    return next_year_series_number($db, 'returns_rrpe');
}

function return_next_reference(mysqli $db, string $itemType): string
{
    if ($itemType === 'equipment') {
        $rrpeNo = return_next_rrpe_no($db);
        if ($rrpeNo !== '') {
            return $rrpeNo;
        }
    }

    return next_module_code($db, 'returns');
}

function return_resolve_spmu_office_id(mysqli $db): int
{
    $stmt = $db->prepare("
        SELECT id
        FROM offices
        WHERE is_active = 1
          AND (
              office_code IN ('SPM', 'SPMU')
              OR office_name LIKE '%Supply and Property Management Unit%'
              OR office_name LIKE '%SPMU%'
          )
        ORDER BY
            CASE
                WHEN office_code = 'SPMU' THEN 0
                WHEN office_code = 'SPM' THEN 1
                ELSE 2
            END,
            id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

function return_resolve_spmu_employee_id(mysqli $db, int $spmuOfficeId): int
{
    if ($spmuOfficeId <= 0) {
        return 0;
    }

    if (function_exists('employee_resolve_office_head')) {
        $head = employee_resolve_office_head($db, $spmuOfficeId);
        if (!empty($head['id'])) {
            return (int) $head['id'];
        }
    }

    $stmt = $db->prepare("
        SELECT id
        FROM employees
        WHERE office_id = ?
          AND is_active = 1
        ORDER BY is_unit_head DESC, last_name ASC, first_name ASC, id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $spmuOfficeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

$db = db();
$page_title = 'Returns';
$flash = get_flash();
$errors = [];
$success = '';
$available = [];
$rows = [];
$selectedLegacyAsset = null;
$legacyAvailable = [];
$typeFilter = trim((string) ($_GET['item_type'] ?? 'all'));
$sourceFilter = trim((string) ($_GET['source_filter'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));
$preselectedDetailId = (int) ($_GET['detail_id'] ?? 0);
$preselectedLegacyAssetId = (int) ($_GET['legacy_asset_id'] ?? 0);
$preselectedSourceType = trim((string) ($_GET['source'] ?? ''));
$form = [
    'source_type' => 'system',
    'distribution_item_detail_id' => '',
    'distribution_item_detail_ids' => [],
    'legacy_asset_id' => '',
    'return_date' => date('Y-m-d'),
    'reason' => '',
    'remarks' => '',
];
$returnReasonOptions = [
    'replacement' => 'Replacement',
    'repair' => 'Repair',
    'safekeeping' => 'Safekeeping',
    'disposal' => 'Disposal',
];

if (!in_array($typeFilter, ['all', 'semi_expendable', 'equipment'], true)) {
    $typeFilter = 'all';
}
if (!in_array($sourceFilter, ['all', 'system', 'legacy'], true)) {
    $sourceFilter = 'all';
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    if (!schema_has_column($db, 'returns', 'source_type')) {
        $errors[] = 'Database schema is outdated: returns.source_type is missing. Apply latest migrations before continuing.';
    }
    if (!schema_has_column($db, 'returns', 'legacy_asset_id')) {
        $errors[] = 'Database schema is outdated: returns.legacy_asset_id is missing. Apply latest migrations before continuing.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['source_type'] = trim((string) ($_POST['source_type'] ?? 'system'));
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }

        $form['distribution_item_detail_id'] = trim((string) ($_POST['distribution_item_detail_id'] ?? ''));
        $form['distribution_item_detail_ids'] = array_values(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, (array) ($_POST['distribution_item_detail_ids'] ?? [])), static function ($value): bool {
            return $value !== '';
        }));
        $form['legacy_asset_id'] = trim((string) ($_POST['legacy_asset_id'] ?? ''));
        $form['return_date'] = trim((string) ($_POST['return_date'] ?? date('Y-m-d')));
        $form['reason'] = trim((string) ($_POST['reason'] ?? ''));
        $form['remarks'] = trim((string) ($_POST['remarks'] ?? ''));

        if (!in_array($form['source_type'], ['system', 'legacy'], true)) {
            $form['source_type'] = 'system';
        }

        $sourceType = $form['source_type'];
        $detailId = (int) ($form['distribution_item_detail_id'] !== '' ? $form['distribution_item_detail_id'] : 0);
        $detailIds = array_values(array_unique(array_map('intval', $form['distribution_item_detail_ids'])));
        $legacyAssetId = (int) ($form['legacy_asset_id'] !== '' ? $form['legacy_asset_id'] : 0);

        if ($sourceType === 'system' && $detailId > 0 && !in_array($detailId, $detailIds, true)) {
            $detailIds[] = $detailId;
        }

        if ($sourceType === 'legacy') {
            if ($legacyAssetId <= 0) {
                $errors[] = 'Select a legacy asset to return.';
            }
        } elseif (!$detailIds) {
            $errors[] = 'Select at least one accountable asset to return.';
        }
        if ($form['return_date'] === '') {
            $errors[] = 'Return date is required.';
        } elseif (!is_valid_date_string($form['return_date'])) {
            $errors[] = 'Return date format is invalid.';
        }

        $assets = [];
        if (!$errors) {
            if ($sourceType === 'legacy') {
                $assetStmt = $db->prepare("
                    SELECT id, office_id AS current_office_id, employee_id AS current_employee_id, item_type
                    FROM legacy_assets
                    WHERE id = ? AND is_active = 1
                    LIMIT 1
                ");
                if ($assetStmt) {
                    $assetStmt->bind_param('i', $legacyAssetId);
                    $assetStmt->execute();
                    $asset = $assetStmt->get_result()->fetch_assoc() ?: null;
                    $assetStmt->close();
                }

                if (!$asset) {
                    $errors[] = 'The selected legacy asset could not be found.';
                } else {
                    $dupStmt = $db->prepare("SELECT id FROM returns WHERE source_type = 'legacy' AND legacy_asset_id = ? AND status = 'posted' LIMIT 1");
                    if ($dupStmt) {
                        $dupStmt->bind_param('i', $legacyAssetId);
                        $dupStmt->execute();
                        $existing = $dupStmt->get_result()->fetch_assoc();
                        $dupStmt->close();
                        if ($existing) {
                            $errors[] = 'A posted return already exists for the selected legacy asset.';
                        }
                    }
                }
            } else {
                $assetStmt = $db->prepare("
                    SELECT
                        did.id,
                        did.current_office_id,
                        did.current_employee_id,
                        did.is_distributed,
                        did.is_disposed,
                        COALESCE(poi.item_type, si.item_type) AS item_type
                    FROM distribution_item_details did
                    INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                    LEFT JOIN issuance_items ii ON ii.id = di.issuance_item_id
                    LEFT JOIN stock_items si ON si.id = ii.stock_item_id
                    LEFT JOIN receiving_items ri ON ri.id = COALESCE(di.receiving_item_id, si.receiving_item_id)
                    LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                    WHERE did.id = ?
                    LIMIT 1
                ");
                $dupStmt = $db->prepare("SELECT id FROM returns WHERE source_type = 'system' AND distribution_item_detail_id = ? AND status = 'posted' LIMIT 1");

                foreach ($detailIds as $selectedDetailId) {
                    $asset = null;
                    if ($assetStmt) {
                        $assetStmt->bind_param('i', $selectedDetailId);
                        $assetStmt->execute();
                        $asset = $assetStmt->get_result()->fetch_assoc() ?: null;
                    }

                    if (!$asset) {
                        $errors[] = 'One selected asset could not be found.';
                        continue;
                    }
                    if ((int) ($asset['is_disposed'] ?? 0) === 1) {
                        $errors[] = 'Disposed assets can no longer be returned.';
                        continue;
                    }
                    if ((int) ($asset['is_distributed'] ?? 0) !== 1) {
                        $errors[] = 'One selected asset is no longer marked as distributed.';
                        continue;
                    }

                    if ($dupStmt) {
                        $dupStmt->bind_param('i', $selectedDetailId);
                        $dupStmt->execute();
                        $existing = $dupStmt->get_result()->fetch_assoc();
                        if ($existing) {
                            $errors[] = 'One selected asset already has a posted return.';
                            continue;
                        }
                    }

                    $assets[] = $asset;
                }

                if ($assetStmt) {
                    $assetStmt->close();
                }
                if ($dupStmt) {
                    $dupStmt->close();
                }
            }
        }

        if (!$errors && ($sourceType === 'legacy' ? !empty($asset) : !empty($assets))) {
            $spmuOfficeId = return_resolve_spmu_office_id($db);
            if ($spmuOfficeId <= 0) {
                $errors[] = 'SPMU office record could not be found. Please add or activate the Supply and Property Management Unit office first.';
            }
            $spmuEmployeeId = $spmuOfficeId > 0 ? return_resolve_spmu_employee_id($db, $spmuOfficeId) : 0;
            if ($spmuEmployeeId <= 0) {
                $errors[] = 'SPMU accountable employee could not be found. Please assign an active SPMU office head first.';
            }
        }

        if (!$errors && ($sourceType === 'legacy' ? !empty($asset) : !empty($assets))) {
            $db->begin_transaction();
            try {
                $userId = current_user_id();

                $ins = $db->prepare("
                    INSERT INTO returns (
                        system_reference,
                        source_type,
                        return_date,
                        distribution_item_detail_id,
                        legacy_asset_id,
                        office_id,
                        employee_id,
                        reason,
                        remarks,
                        status,
                        created_by
                    ) VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, 'posted', ?)
                ");

                if (!$ins) {
                    throw new RuntimeException('Unable to prepare the return insert statement.');
                }
                if ($sourceType === 'legacy') {
                    $systemRef = return_next_reference($db, (string) ($asset['item_type'] ?? ''));
                    $officeId = (int) ($asset['current_office_id'] ?? 0);
                    $employeeId = (int) ($asset['current_employee_id'] ?? 0);
                    $detailIdToSave = null;
                    $legacyIdToSave = $legacyAssetId;

                    $ins->bind_param('sssiiiissi', $systemRef, $sourceType, $form['return_date'], $detailIdToSave, $legacyIdToSave, $officeId, $employeeId, $form['reason'], $form['remarks'], $userId);
                    $ins->execute();
                    $returnId = (int) $ins->insert_id;

                    $upd = $db->prepare("
                        UPDATE legacy_assets
                        SET office_id = ?, employee_id = ?, responsibility_code_id = NULL
                        WHERE id = ?
                    ");
                    if (!$upd) {
                        throw new RuntimeException('Unable to update legacy asset accountability state.');
                    }
                    $upd->bind_param('iii', $spmuOfficeId, $spmuEmployeeId, $legacyAssetId);
                    $upd->execute();
                    $upd->close();

                    write_audit_log($db, [
                        'action' => 'insert',
                        'table_name' => 'returns',
                        'record_id' => $returnId,
                        'module_name' => 'returns',
                        'record_type' => 'return',
                        'action_name' => 'post_return',
                        'new_values' => [
                            'system_reference' => $systemRef,
                            'source_type' => $sourceType,
                            'return_date' => $form['return_date'],
                            'distribution_item_detail_id' => $detailIdToSave,
                            'legacy_asset_id' => $legacyIdToSave,
                            'office_id' => $officeId,
                            'employee_id' => $employeeId,
                            'reason' => $form['reason'],
                        ],
                        'description' => 'Posted asset return.',
                    ]);
                } else {
                    $upd = $db->prepare("
                        UPDATE distribution_item_details
                        SET
                            is_distributed = 0,
                            current_office_id = ?,
                            current_employee_id = ?,
                            current_responsibility_code_id = NULL
                        WHERE id = ?
                    ");
                    if (!$upd) {
                        throw new RuntimeException('Unable to update the asset accountability state.');
                    }

                    foreach ($assets as $assetRow) {
                        $systemRef = return_next_reference($db, (string) ($assetRow['item_type'] ?? ''));
                        $officeId = (int) ($assetRow['current_office_id'] ?? 0);
                        $employeeId = (int) ($assetRow['current_employee_id'] ?? 0);
                        $detailIdToSave = (int) ($assetRow['id'] ?? 0);
                        $legacyIdToSave = null;

                        $ins->bind_param('sssiiiissi', $systemRef, $sourceType, $form['return_date'], $detailIdToSave, $legacyIdToSave, $officeId, $employeeId, $form['reason'], $form['remarks'], $userId);
                        $ins->execute();
                        $returnId = (int) $ins->insert_id;

                        $upd->bind_param('iii', $spmuOfficeId, $spmuEmployeeId, $detailIdToSave);
                        $upd->execute();

                        write_audit_log($db, [
                            'action' => 'insert',
                            'table_name' => 'returns',
                            'record_id' => $returnId,
                            'module_name' => 'returns',
                            'record_type' => 'return',
                            'action_name' => 'post_return',
                            'new_values' => [
                                'system_reference' => $systemRef,
                                'source_type' => $sourceType,
                                'return_date' => $form['return_date'],
                                'distribution_item_detail_id' => $detailIdToSave,
                                'legacy_asset_id' => $legacyIdToSave,
                                'office_id' => $officeId,
                                'employee_id' => $employeeId,
                                'reason' => $form['reason'],
                            ],
                            'description' => 'Posted asset return.',
                        ]);
                    }
                    $upd->close();
                }
                $ins->close();

                $db->commit();
                $returnCount = $sourceType === 'legacy' ? 1 : count($assets);
                set_flash('success', $returnCount > 1 ? ('Bulk return recorded successfully for ' . number_format($returnCount) . ' assets.') : 'Return recorded successfully.');
                redirect('modules/returns/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to record the return.';
            }
        }
    }

    if ($sourceFilter !== 'legacy') {
    $availableSql = "
        SELECT
            did.id,
            did.property_number,
            did.brand,
            did.model,
            did.serial_no,
            COALESCE(poi.item_type, si.item_type) AS item_type,
            COALESCE(poi.item_description, si.item_description) AS item_description,
            c.classification_name,
            c.classification_family,
            d.document_no,
            d.document_type,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
                LEFT JOIN issuance_items ii ON ii.id = di.issuance_item_id
                LEFT JOIN stock_items si ON si.id = ii.stock_item_id
                LEFT JOIN receiving_items ri ON ri.id = COALESCE(di.receiving_item_id, si.receiving_item_id)
                LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                LEFT JOIN classifications c ON c.id = COALESCE(poi.classification_id, si.classification_id)
        LEFT JOIN offices base_o ON base_o.id = d.office_id
        LEFT JOIN employees base_e ON base_e.id = d.employee_id
        LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
        LEFT JOIN returns rt ON rt.distribution_item_detail_id = did.id AND rt.status = 'posted'
        WHERE did.is_distributed = 1
          AND (did.is_disposed IS NULL OR did.is_disposed = 0)
          AND rt.id IS NULL
                    AND COALESCE(poi.item_type, si.item_type) IN ('semi_expendable', 'equipment')
    ";
    $types = '';
    $params = [];
    if ($typeFilter !== 'all') {
        $availableSql .= " AND COALESCE(poi.item_type, si.item_type) = ?";
        $types .= 's';
        $params[] = $typeFilter;
    }
    if ($search !== '') {
        $availableSql .= " AND (
            did.property_number LIKE CONCAT('%', ?, '%')
            OR did.serial_no LIKE CONCAT('%', ?, '%')
            OR poi.item_description LIKE CONCAT('%', ?, '%')
            OR did.brand LIKE CONCAT('%', ?, '%')
            OR did.model LIKE CONCAT('%', ?, '%')
            OR o.office_name LIKE CONCAT('%', ?, '%')
        )";
        $types .= 'ssssss';
        array_push($params, $search, $search, $search, $search, $search, $search);
    }
    $availableSql .= " ORDER BY poi.item_type ASC, poi.item_description ASC, did.property_number ASC, did.serial_no ASC";

    $availableStmt = $db->prepare($availableSql);
    if ($availableStmt) {
        if ($params) {
            $availableStmt->bind_param($types, ...$params);
        }
        $availableStmt->execute();
        $available = $availableStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $availableStmt->close();
    }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $preselectedDetailId > 0) {
        foreach ($available as $assetRow) {
            if ((int) ($assetRow['id'] ?? 0) === $preselectedDetailId) {
                $form['source_type'] = 'system';
                $form['distribution_item_detail_id'] = (string) $preselectedDetailId;
                $form['distribution_item_detail_ids'] = [(string) $preselectedDetailId];
                break;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $preselectedLegacyAssetId > 0 && $preselectedSourceType === 'legacy') {
        $legacyAssetStmt = $db->prepare("
            SELECT
                la.id,
                la.property_number,
                la.serial_no,
                la.item_type,
                la.item_description,
                la.brand,
                la.model,
                c.classification_name,
                c.classification_family,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name
            FROM legacy_assets la
            LEFT JOIN classifications c ON c.id = la.classification_id
            LEFT JOIN offices o ON o.id = la.office_id
            LEFT JOIN employees e
              ON e.id = la.employee_id
             AND (
                 e.office_id = la.office_id
                 OR EXISTS (
                     SELECT 1
                     FROM employee_assignments ea
                     WHERE ea.employee_id = e.id
                       AND ea.office_id = la.office_id
                       AND ea.is_active = 1
                 )
             )
            WHERE la.id = ? AND la.is_active = 1
            LIMIT 1
        ");
        if ($legacyAssetStmt) {
            $legacyAssetStmt->bind_param('i', $preselectedLegacyAssetId);
            $legacyAssetStmt->execute();
            $legacyAsset = $legacyAssetStmt->get_result()->fetch_assoc();
            $legacyAssetStmt->close();
            if ($legacyAsset) {
                $form['source_type'] = 'legacy';
                $form['legacy_asset_id'] = (string) $preselectedLegacyAssetId;
                $selectedLegacyAsset = $legacyAsset;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $sourceFilter !== 'system' && $search !== '') {
        $legacyListSql = "
            SELECT
                la.id,
                la.property_number,
                la.serial_no,
                la.item_type,
                la.item_description,
                la.brand,
                la.model,
                c.classification_name,
                c.classification_family,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name
            FROM legacy_assets la
            LEFT JOIN returns rt
              ON rt.source_type = 'legacy'
             AND rt.legacy_asset_id = la.id
             AND rt.status = 'posted'
            LEFT JOIN classifications c ON c.id = la.classification_id
            LEFT JOIN offices o ON o.id = la.office_id
            LEFT JOIN employees e
              ON e.id = la.employee_id
             AND (
                 e.office_id = la.office_id
                 OR EXISTS (
                     SELECT 1
                     FROM employee_assignments ea
                     WHERE ea.employee_id = e.id
                       AND ea.office_id = la.office_id
                       AND ea.is_active = 1
                 )
             )
            WHERE la.is_active = 1
              AND la.item_type IN ('equipment', 'semi_expendable')
              AND rt.id IS NULL
              AND (
                  la.property_number LIKE CONCAT('%', ?, '%')
                  OR la.serial_no LIKE CONCAT('%', ?, '%')
                  OR la.qr_tag_code LIKE CONCAT('%', ?, '%')
                  OR la.item_description LIKE CONCAT('%', ?, '%')
                  OR la.brand LIKE CONCAT('%', ?, '%')
                  OR la.model LIKE CONCAT('%', ?, '%')
                  OR o.office_name LIKE CONCAT('%', ?, '%')
              )
        ";
        $legacyTypes = 'sssssss';
        $legacyParams = [$search, $search, $search, $search, $search, $search, $search];
        if ($typeFilter !== 'all') {
            $legacyListSql .= " AND la.item_type = ?";
            $legacyTypes .= 's';
            $legacyParams[] = $typeFilter;
        }
        $legacyListSql .= " ORDER BY la.item_type ASC, la.item_description ASC, la.property_number ASC LIMIT 50";

        $legacyListStmt = $db->prepare($legacyListSql);
        if ($legacyListStmt) {
            $legacyListStmt->bind_param($legacyTypes, ...$legacyParams);
            $legacyListStmt->execute();
            $legacyAvailable = $legacyListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $legacyListStmt->close();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $sourceFilter !== 'system' && $form['source_type'] !== 'legacy' && $search !== '') {
        $legacySearchStmt = $db->prepare("
            SELECT
                la.id,
                la.property_number,
                la.serial_no,
                la.item_type,
                la.item_description,
                la.brand,
                la.model,
                c.classification_name,
                c.classification_family,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name
            FROM legacy_assets la
            LEFT JOIN returns rt
              ON rt.source_type = 'legacy'
             AND rt.legacy_asset_id = la.id
             AND rt.status = 'posted'
            LEFT JOIN classifications c ON c.id = la.classification_id
            LEFT JOIN offices o ON o.id = la.office_id
            LEFT JOIN employees e
              ON e.id = la.employee_id
             AND (
                 e.office_id = la.office_id
                 OR EXISTS (
                     SELECT 1
                     FROM employee_assignments ea
                     WHERE ea.employee_id = e.id
                       AND ea.office_id = la.office_id
                       AND ea.is_active = 1
                 )
             )
            WHERE la.is_active = 1
              AND la.item_type IN ('equipment', 'semi_expendable')
              AND rt.id IS NULL
              AND (
                  la.property_number = ?
                  OR la.serial_no = ?
                  OR la.qr_tag_code = ?
              )
            LIMIT 1
        ");
        if ($legacySearchStmt) {
            $legacySearchStmt->bind_param('sss', $search, $search, $search);
            $legacySearchStmt->execute();
            $legacySearch = $legacySearchStmt->get_result()->fetch_assoc();
            $legacySearchStmt->close();
            if ($legacySearch) {
                $form['source_type'] = 'legacy';
                $form['legacy_asset_id'] = (string) (int) ($legacySearch['id'] ?? 0);
                $selectedLegacyAsset = $legacySearch;
            }
        }
    }

    $rowsSql = "
        SELECT
            rt.id,
            rt.system_reference,
            rt.source_type,
            rt.return_date,
            rt.reason,
            rt.remarks,
            COALESCE(did.property_number, la.property_number) AS property_number,
            COALESCE(did.serial_no, la.serial_no) AS serial_no,
            COALESCE(poi.item_type, si.item_type, la.item_type) AS item_type,
            COALESCE(poi.item_description, si.item_description, la.item_description) AS item_description,
            COALESCE(c.classification_name, lc.classification_name) AS classification_name,
            COALESCE(c.classification_family, lc.classification_family) AS classification_family,
            COALESCE(d.document_no, 'Beginning Balance') AS document_no,
            COALESCE(d.document_type, 'legacy') AS document_type,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name
        FROM returns rt
        LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
        LEFT JOIN legacy_assets la ON la.id = rt.legacy_asset_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN distributions d ON d.id = di.distribution_id
        LEFT JOIN issuance_items ii ON ii.id = di.issuance_item_id
        LEFT JOIN stock_items si ON si.id = ii.stock_item_id
        LEFT JOIN receiving_items ri ON ri.id = COALESCE(di.receiving_item_id, si.receiving_item_id)
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = COALESCE(poi.classification_id, si.classification_id)
        LEFT JOIN classifications lc ON lc.id = la.classification_id
        LEFT JOIN offices o ON o.id = COALESCE(rt.office_id, did.current_office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(rt.employee_id, did.current_employee_id, d.employee_id)
        ORDER BY rt.return_date DESC, rt.id DESC
    ";
    $rowsResult = $db->query($rowsSql);
    if ($rowsResult) {
        $rows = $rowsResult->fetch_all(MYSQLI_ASSOC);
    }
}

$availableCount = count($available);
$legacyAvailableCount = count($legacyAvailable);
$recentCount = count($rows);
$equipmentAvailable = 0;
$semiAvailable = 0;
foreach ($available as $assetRow) {
    if (($assetRow['item_type'] ?? '') === 'equipment') {
        $equipmentAvailable++;
    } elseif (($assetRow['item_type'] ?? '') === 'semi_expendable') {
        $semiAvailable++;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="report-page-shell">
                    <div class="report-toolbar">
                        <div>
                            <h5 class="report-toolbar-title mb-0">Returns</h5>
                            <p class="report-toolbar-copy">Accept distributed semi-expendable or equipment assets back from the end-user, receive them into the Supply Office pool, and keep the audit trail plus the correct COA/GAM return form.</p>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
                    <?php endif; ?>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
                    <?php endif; ?>

                    <div class="report-summary-grid">
                        <div class="report-summary-card">
                            <div class="report-summary-label">Available Assets</div>
                            <div class="report-summary-value"><?php echo number_format($availableCount); ?></div>
                            <div class="report-summary-note">Distributed assets that can still be received back into Supply Office.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Semi-Expendable</div>
                            <div class="report-summary-value"><?php echo number_format($semiAvailable); ?></div>
                            <div class="report-summary-note">Semi assets currently available for return.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Equipment</div>
                            <div class="report-summary-value"><?php echo number_format($equipmentAvailable); ?></div>
                            <div class="report-summary-note">Equipment assets currently available for return.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Recent Records</div>
                            <div class="report-summary-value"><?php echo number_format($recentCount); ?></div>
                            <div class="report-summary-note">Posted return transactions already recorded.</div>
                        </div>
                    </div>

                    <ul class="nav nav-pills return-module-tabs" id="returnsModuleTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="return-desk-tab" data-bs-toggle="pill" data-bs-target="#return-desk" type="button" role="tab" aria-controls="return-desk" aria-selected="true">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Return Desk
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="posted-returns-tab" data-bs-toggle="pill" data-bs-target="#posted-returns" type="button" role="tab" aria-controls="posted-returns" aria-selected="false">
                                <i class="bi bi-clock-history me-1"></i>Posted Returns
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="return-forms-tab" data-bs-toggle="pill" data-bs-target="#return-forms" type="button" role="tab" aria-controls="return-forms" aria-selected="false">
                                <i class="bi bi-file-earmark-text me-1"></i>Forms
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content return-module-tab-content">
                        <div class="tab-pane fade show active" id="return-desk" role="tabpanel" aria-labelledby="return-desk-tab">
                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Find Returnable Assets</h6>
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Inventory Type</label>
                                <select name="item_type" class="form-select">
                                    <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All accountable assets</option>
                                    <option value="semi_expendable" <?php echo $typeFilter === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                                    <option value="equipment" <?php echo $typeFilter === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Source</label>
                                <select name="source_filter" class="form-select">
                                    <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>All sources</option>
                                    <option value="system" <?php echo $sourceFilter === 'system' ? 'selected' : ''; ?>>System assets</option>
                                    <option value="legacy" <?php echo $sourceFilter === 'legacy' ? 'selected' : ''; ?>>Legacy assets</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="q" class="form-control" value="<?php echo h($search); ?>" placeholder="Property no., serial no., description, brand, model, accountable office, or accountable person">
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100">Apply</button>
                                <a href="<?php echo base_url('modules/returns/index.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>

                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Record Return</h6>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="source_type" id="returnSourceType" value="<?php echo h($form['source_type']); ?>">
                            <input type="hidden" name="legacy_asset_id" id="returnLegacyAssetId" value="<?php echo h($form['legacy_asset_id']); ?>">
                            <div class="col-lg-7">
                                <?php if ($form['source_type'] === 'legacy' && $form['legacy_asset_id'] !== ''): ?>
                                    <div class="return-workspace-panel h-100">
                                        <div class="return-workspace-head">
                                            <div>
                                                <div class="return-workspace-eyebrow">Selected Source</div>
                                                <h6 class="mb-1">Legacy Asset Return</h6>
                                                <div class="text-muted small">This return was opened directly from the asset details page.</div>
                                            </div>
                                        </div>
                                        <?php if ($selectedLegacyAsset): ?>
                                            <div class="return-selection-card">
                                                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                                    <div>
                                                        <div class="fw-semibold"><?php echo h(return_asset_label($selectedLegacyAsset)); ?></div>
                                                        <div class="small text-muted"><?php echo h(trim(implode(' | ', array_filter([$selectedLegacyAsset['property_number'] ?? '', $selectedLegacyAsset['serial_no'] ?? ''])))); ?></div>
                                                    </div>
                                                    <span class="badge <?php echo ($selectedLegacyAsset['item_type'] ?? '') === 'equipment' ? 'badge-soft-info' : 'badge-soft-success'; ?>">
                                                        <?php echo h(($selectedLegacyAsset['item_type'] ?? '') === 'equipment' ? 'Equipment' : 'Semi-Expendable'); ?>
                                                    </span>
                                                </div>
                                                <div class="return-selection-grid">
                                                    <div><span class="return-selection-label">Accountability</span><strong><?php echo h(trim(implode(' / ', array_filter([$selectedLegacyAsset['office_name'] ?? '', return_asset_person_label($selectedLegacyAsset)]))) ?: '-'); ?></strong></div>
                                                    <div><span class="return-selection-label">Brand / Model</span><strong><?php echo h(trim(implode(' / ', array_filter([$selectedLegacyAsset['brand'] ?? '', $selectedLegacyAsset['model'] ?? '']))) ?: '-'); ?></strong></div>
                                                    <div><span class="return-selection-label">Classification</span><strong><?php echo h(trim(implode(' / ', array_filter([$selectedLegacyAsset['classification_family'] ?? '', $selectedLegacyAsset['classification_name'] ?? '']))) ?: '-'); ?></strong></div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="return-selection-empty">
                                                <i class="bi bi-archive"></i>
                                                <div class="fw-semibold">Legacy asset #<?php echo h($form['legacy_asset_id']); ?></div>
                                                <div class="small">Review the return details on the right, then receive this asset back into Supply Office.</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="return-workspace-panel h-100">
                                        <div class="return-workspace-head">
                                            <div>
                                                <div class="return-workspace-eyebrow">Item Browser</div>
                                                <h6 class="mb-1">Select Assets To Receive</h6>
                                                <div class="text-muted small">Search the loaded list, tick one or more assets, and review the details before posting the return.</div>
                                            </div>
                                            <span class="badge rounded-pill text-bg-light" id="returnSelectionCount"><?php echo count($form['distribution_item_detail_ids']) > 0 ? number_format(count($form['distribution_item_detail_ids'])) : '0'; ?> selected</span>
                                        </div>
                                        <div class="return-picker-toolbar">
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                                <input type="search" class="form-control" id="returnAssetPickerSearch" placeholder="Search inside loaded assets">
                                            </div>
                                        </div>
                                        <div class="return-picker-list" id="returnAssetPickerList">
                                            <?php if ($available): ?>
                                                <?php foreach ($available as $asset): ?>
                                                    <?php
                                                    $isSelected = in_array((string) $asset['id'], array_map('strval', $form['distribution_item_detail_ids']), true) || $form['distribution_item_detail_id'] === (string) $asset['id'];
                                                    $payload = [
                                                        'id' => (int) ($asset['id'] ?? 0),
                                                        'item_type' => (string) ($asset['item_type'] ?? ''),
                                                        'property_number' => (string) ($asset['property_number'] ?? ''),
                                                        'serial_no' => (string) ($asset['serial_no'] ?? ''),
                                                        'asset_label' => return_asset_label($asset),
                                                        'office_name' => (string) ($asset['office_name'] ?? ''),
                                                        'accountable_person' => return_asset_person_label($asset),
                                                        'document_label' => return_asset_document_label($asset),
                                                        'brand' => (string) ($asset['brand'] ?? ''),
                                                        'model' => (string) ($asset['model'] ?? ''),
                                                        'classification_family' => (string) ($asset['classification_family'] ?? ''),
                                                        'classification_name' => (string) ($asset['classification_name'] ?? ''),
                                                    ];
                                                    $searchBlob = strtolower(trim(implode(' ', array_filter([
                                                        $asset['item_type'] ?? '',
                                                        $asset['property_number'] ?? '',
                                                        $asset['serial_no'] ?? '',
                                                        $asset['item_description'] ?? '',
                                                        $asset['classification_name'] ?? '',
                                                        $asset['classification_family'] ?? '',
                                                        $asset['brand'] ?? '',
                                                        $asset['model'] ?? '',
                                                        $asset['office_name'] ?? '',
                                                        return_asset_person_label($asset),
                                                    ]))));
                                                    ?>
                                                    <label class="return-picker-item<?php echo $isSelected ? ' is-selected' : ''; ?>" data-search="<?php echo h($searchBlob); ?>">
                                                        <input class="return-picker-checkbox" type="checkbox" name="distribution_item_detail_ids[]" value="<?php echo (int) $asset['id']; ?>" <?php echo $isSelected ? 'checked' : ''; ?> data-asset="<?php echo h((string) json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)); ?>">
                                                        <span class="return-picker-item-body">
                                                            <span class="return-picker-item-top">
                                                                <span class="return-picker-title"><?php echo h(return_asset_label($asset)); ?></span>
                                                                <span class="badge <?php echo ($asset['item_type'] ?? '') === 'equipment' ? 'badge-soft-info' : 'badge-soft-success'; ?>"><?php echo h(($asset['item_type'] ?? '') === 'equipment' ? 'Equipment' : 'Semi-Expendable'); ?></span>
                                                            </span>
                                                            <span class="return-picker-meta"><?php echo h(trim(implode(' | ', array_filter([$asset['property_number'] ?? '', $asset['serial_no'] ?? '', return_asset_document_label($asset)])))); ?></span>
                                                            <span class="return-picker-submeta"><?php echo h(trim(implode(' / ', array_filter([$asset['office_name'] ?? '', return_asset_person_label($asset)])))); ?></span>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="return-selection-empty">
                                                    <i class="bi bi-inbox"></i>
                                                    <div class="fw-semibold">No assets match the current filter</div>
                                                    <div class="small">Adjust the inventory type or search keywords above to load more returnable items.</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="return-picker-empty d-none" id="returnAssetPickerEmpty">No loaded assets match the local search.</div>
                                        <?php if ($legacyAvailable): ?>
                                            <div class="return-legacy-results">
                                                <div class="return-workspace-eyebrow">Legacy Matches</div>
                                                <div class="return-legacy-list">
                                                    <?php foreach ($legacyAvailable as $asset): ?>
                                                        <?php
                                                        $payload = [
                                                            'id' => (int) ($asset['id'] ?? 0),
                                                            'item_type' => (string) ($asset['item_type'] ?? ''),
                                                            'property_number' => (string) ($asset['property_number'] ?? ''),
                                                            'serial_no' => (string) ($asset['serial_no'] ?? ''),
                                                            'asset_label' => return_asset_label($asset),
                                                            'office_name' => (string) ($asset['office_name'] ?? ''),
                                                            'accountable_person' => return_asset_person_label($asset),
                                                            'document_label' => 'Beginning Balance',
                                                            'brand' => (string) ($asset['brand'] ?? ''),
                                                            'model' => (string) ($asset['model'] ?? ''),
                                                            'classification_family' => (string) ($asset['classification_family'] ?? ''),
                                                            'classification_name' => (string) ($asset['classification_name'] ?? ''),
                                                        ];
                                                        ?>
                                                        <label class="return-picker-item return-legacy-item">
                                                            <input class="return-legacy-radio" type="radio" name="legacy_pick" value="<?php echo (int) $asset['id']; ?>" data-asset="<?php echo h((string) json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)); ?>">
                                                            <span class="return-picker-item-body">
                                                                <span class="return-picker-item-top">
                                                                    <span class="return-picker-title"><?php echo h(return_asset_label($asset)); ?></span>
                                                                    <span class="badge <?php echo ($asset['item_type'] ?? '') === 'equipment' ? 'badge-soft-info' : 'badge-soft-success'; ?>"><?php echo h(($asset['item_type'] ?? '') === 'equipment' ? 'Equipment' : 'Semi-Expendable'); ?></span>
                                                                </span>
                                                                <span class="return-picker-meta"><?php echo h(trim(implode(' | ', array_filter([$asset['property_number'] ?? '', $asset['serial_no'] ?? '', 'Legacy'])))); ?></span>
                                                                <span class="return-picker-submeta"><?php echo h(trim(implode(' / ', array_filter([$asset['office_name'] ?? '', return_asset_person_label($asset)])))); ?></span>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php elseif ($sourceFilter === 'legacy' && $search !== ''): ?>
                                            <div class="return-picker-empty">No legacy assets match the current search.</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-lg-5">
                                <div class="return-workspace-panel h-100">
                                    <div class="return-workspace-head">
                                        <div>
                                            <div class="return-workspace-eyebrow">Selection Preview</div>
                                            <h6 class="mb-1">Selected Item Details</h6>
                                            <div class="text-muted small">The panel updates as users tick assets for single or bulk return.</div>
                                        </div>
                                    </div>
                                    <?php if ($form['source_type'] === 'legacy' && $selectedLegacyAsset): ?>
                                        <div id="returnSelectionPreview" class="return-selection-card">
                                            <div class="fw-semibold">Ready to receive return</div>
                                            <div class="small text-muted mt-1"><?php echo h(($selectedLegacyAsset['property_number'] ?? '') . (!empty($selectedLegacyAsset['serial_no']) ? ' | ' . $selectedLegacyAsset['serial_no'] : '')); ?></div>
                                            <div class="return-selection-grid">
                                                <div><span class="return-selection-label">Destination</span><strong>SPMU accountability</strong></div>
                                                <div><span class="return-selection-label">Return Form</span><strong><?php echo ($selectedLegacyAsset['item_type'] ?? '') === 'semi_expendable' ? 'Receipt of Returned Semi-Expendable Property' : 'RRPE'; ?></strong></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div id="returnSelectionPreview" class="return-selection-empty">
                                            <i class="bi bi-check2-square"></i>
                                            <div class="fw-semibold">No asset selected yet</div>
                                            <div class="small">Choose an asset from the left to preview its details here before receiving the return.</div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="row g-3 mt-1">
                                        <div class="col-md-6">
                                            <label class="form-label">Return Date</label>
                                            <input type="date" name="return_date" class="form-control" value="<?php echo h($form['return_date']); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Reason</label>
                                            <select name="reason" class="form-select">
                                                <option value="">Select reason</option>
                                                <?php foreach ($returnReasonOptions as $reasonValue => $reasonLabel): ?>
                                                    <option value="<?php echo h($reasonValue); ?>" <?php echo $form['reason'] === $reasonValue ? 'selected' : ''; ?>><?php echo h($reasonLabel); ?></option>
                                                <?php endforeach; ?>
                                                <?php if ($form['reason'] !== '' && !isset($returnReasonOptions[$form['reason']])): ?>
                                                    <option value="<?php echo h($form['reason']); ?>" selected><?php echo h($form['reason']); ?></option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Remarks</label>
                                            <input type="text" name="remarks" class="form-control" value="<?php echo h($form['remarks']); ?>" placeholder="Optional notes for the return record">
                                        </div>
                                        <div class="col-12 d-grid">
                                            <button class="btn btn-primary">Receive Return</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                        </div>
                        <div class="tab-pane fade" id="posted-returns" role="tabpanel" aria-labelledby="posted-returns-tab">
                    <div class="report-table-card table-responsive mobile-table-frame">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Asset</th>
                                    <th>Document</th>
                                    <th>From Office / Officer</th>
                                    <th>Reason</th>
                                    <th>COA/GAM Form</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo h($row['system_reference']); ?></td>
                                            <td><?php echo h(!empty($row['return_date']) ? date('M d, Y', strtotime((string) $row['return_date'])) : ''); ?></td>
                                            <td>
                                                <div class="fw-semibold d-flex align-items-center gap-2 flex-wrap">
                                                    <span><?php echo h($row['property_number'] ?? ''); ?></span>
                                                    <?php if (($row['source_type'] ?? 'system') === 'legacy'): ?>
                                                        <span class="badge text-bg-secondary">Legacy</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-success">System</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div><?php echo h(return_asset_label($row)); ?></div>
                                                <?php if (!empty($row['serial_no'])): ?><div class="small text-muted"><?php echo h($row['serial_no']); ?></div><?php endif; ?>
                                            </td>
                                            <td><?php echo h(trim(implode(' / ', array_filter([$row['document_type'] ?? '', $row['document_no'] ?? ''])))); ?></td>
                                            <td><?php echo h(trim(implode(' / ', array_filter([$row['office_name'] ?? '', employee_display_name($row)])))); ?></td>
                                            <td><?php echo h(trim(implode(' | ', array_filter([$row['reason'] ?? '', $row['remarks'] ?? ''])))); ?></td>
                                            <td>
                                                <?php if (($row['item_type'] ?? '') === 'semi_expendable'): ?>
                                                    <a class="btn btn-outline-primary btn-sm" href="<?php echo h(base_url('modules/reports/semi_rrsp.php?return_id=' . (int) $row['id'])); ?>">Receipt of Returned Semi-Expendable Property</a>
                                                <?php elseif (($row['item_type'] ?? '') === 'equipment'): ?>
                                                    <a class="btn btn-outline-primary btn-sm" href="<?php echo h(base_url('modules/reports/property_return_slip.php?return_id=' . (int) $row['id'])); ?>">RRPE</a>
                                                <?php else: ?>
                                                    <span class="text-muted">No form</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">No return records yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                        </div>
                        <div class="tab-pane fade" id="return-forms" role="tabpanel" aria-labelledby="return-forms-tab">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="return-form-card">
                                        <div class="return-workspace-eyebrow">Annex 28</div>
                                        <h6>Return and Receipt of Property/Equipment</h6>
                                        <div class="text-muted small">Equipment return records use RRPE numbers in year-series format.</div>
                                        <a class="btn btn-outline-primary btn-sm mt-3" href="<?php echo h(base_url('modules/reports/property_return_slip.php')); ?>">Open RRPE</a>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="return-form-card">
                                        <div class="return-workspace-eyebrow">Annex A.6</div>
                                        <h6>Receipt of Returned Semi-Expendable Property</h6>
                                        <div class="text-muted small">Semi-expendable return records print through the RRSP report.</div>
                                        <a class="btn btn-outline-primary btn-sm mt-3" href="<?php echo h(base_url('modules/reports/semi_rrsp.php')); ?>">Open RRSP</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var pickerList = document.getElementById('returnAssetPickerList');
    var searchInput = document.getElementById('returnAssetPickerSearch');
    var preview = document.getElementById('returnSelectionPreview');
    var countBadge = document.getElementById('returnSelectionCount');
    var emptySearch = document.getElementById('returnAssetPickerEmpty');
    var sourceInput = document.getElementById('returnSourceType');
    var legacyInput = document.getElementById('returnLegacyAssetId');

    if (!pickerList || !preview) {
        return;
    }

    var checkboxes = Array.prototype.slice.call(pickerList.querySelectorAll('.return-picker-checkbox'));
    var items = Array.prototype.slice.call(pickerList.querySelectorAll('.return-picker-item'));
    var legacyRadios = Array.prototype.slice.call(document.querySelectorAll('.return-legacy-radio'));

    function parseAsset(checkbox) {
        try {
            return JSON.parse(checkbox.getAttribute('data-asset') || '{}');
        } catch (error) {
            return {};
        }
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function selectedCheckboxes() {
        return checkboxes.filter(function (checkbox) {
            return checkbox.checked;
        });
    }

    function selectedLegacyRadio() {
        return legacyRadios.find(function (radio) {
            return radio.checked;
        }) || null;
    }

    function renderAssetCard(asset, sourceLabel) {
        var typeLabel = asset.item_type === 'equipment' ? 'Equipment' : 'Semi-Expendable';
        return '<div class="return-selection-card">'
            + '<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">'
            + '<div class="fw-semibold">' + escapeHtml(asset.asset_label) + '</div>'
            + '<span class="badge ' + (asset.item_type === 'equipment' ? 'badge-soft-info' : 'badge-soft-success') + '">' + escapeHtml(typeLabel) + '</span>'
            + '</div>'
            + '<div class="small text-muted mt-1">' + escapeHtml([asset.property_number, asset.serial_no, sourceLabel].filter(Boolean).join(' | ')) + '</div>'
            + '<div class="small text-muted">' + escapeHtml([asset.office_name, asset.accountable_person].filter(Boolean).join(' / ')) + '</div>'
            + '<div class="return-selection-grid">'
            + '<div><span class="return-selection-label">Document</span><strong>' + escapeHtml(asset.document_label || '-') + '</strong></div>'
            + '<div><span class="return-selection-label">Brand / Model</span><strong>' + escapeHtml([asset.brand, asset.model].filter(Boolean).join(' / ') || '-') + '</strong></div>'
            + '<div><span class="return-selection-label">Classification</span><strong>' + escapeHtml([asset.classification_family, asset.classification_name].filter(Boolean).join(' / ') || '-') + '</strong></div>'
            + '</div>'
            + '</div>';
    }

    function renderPreview() {
        var legacyRadio = selectedLegacyRadio();
        if (legacyRadio) {
            var legacyAsset = parseAsset(legacyRadio);
            if (countBadge) {
                countBadge.textContent = '1 selected';
            }
            preview.className = '';
            preview.innerHTML = renderAssetCard(legacyAsset, 'Legacy');
            return;
        }

        var selected = selectedCheckboxes();

        items.forEach(function (item) {
            var checkbox = item.querySelector('.return-picker-checkbox');
            item.classList.toggle('is-selected', !!checkbox && checkbox.checked);
        });

        if (countBadge) {
            countBadge.textContent = selected.length + ' selected';
        }

        if (selected.length === 0) {
            preview.className = 'return-selection-empty';
            preview.innerHTML = '<i class="bi bi-check2-square"></i><div class="fw-semibold">No asset selected yet</div><div class="small">Choose an asset from the left to preview its details here before receiving the return.</div>';
            return;
        }

        var summaryCards = selected.slice(0, 3).map(function (checkbox) {
            return renderAssetCard(parseAsset(checkbox), 'System');
        }).join('');

        if (selected.length > 3) {
            summaryCards += '<div class="return-selection-more">+' + (selected.length - 3) + ' more selected item(s)</div>';
        }

        preview.className = '';
        preview.innerHTML = summaryCards;
    }

    function applyLocalSearch() {
        if (!searchInput) {
            return;
        }

        var keyword = searchInput.value.trim().toLowerCase();
        var visibleCount = 0;

        items.forEach(function (item) {
            var haystack = (item.getAttribute('data-search') || '').toLowerCase();
            var matches = keyword === '' || haystack.indexOf(keyword) !== -1;
            item.classList.toggle('d-none', !matches);
            if (matches) {
                visibleCount += 1;
            }
        });

        if (emptySearch) {
            emptySearch.classList.toggle('d-none', visibleCount !== 0);
        }
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            if (checkbox.checked) {
                legacyRadios.forEach(function (radio) {
                    radio.checked = false;
                    radio.closest('.return-picker-item')?.classList.remove('is-selected');
                });
                if (sourceInput) {
                    sourceInput.value = 'system';
                }
                if (legacyInput) {
                    legacyInput.value = '';
                }
            }
            renderPreview();
        });
    });

    legacyRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!radio.checked) {
                return;
            }
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = false;
            });
            legacyRadios.forEach(function (other) {
                var item = other.closest('.return-picker-item');
                if (item) {
                    item.classList.toggle('is-selected', other.checked);
                }
            });
            if (sourceInput) {
                sourceInput.value = 'legacy';
            }
            if (legacyInput) {
                legacyInput.value = radio.value;
            }
            renderPreview();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyLocalSearch);
    }

    renderPreview();
    applyLocalSearch();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
