<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$page_title = 'Transfer of Accountability';
$flash = get_flash();
$errors = [];
$offices = [];
$employees = [];
$responsibilityCodes = [];
$assets = [];
$transfers = [];
$transferBatches = [];
$transferHistoryStatus = trim((string) ($_GET['transfer_history_status'] ?? 'posted'));
$documentHistoryStatus = trim((string) ($_GET['document_history_status'] ?? 'posted'));
$assetSearch = trim($_GET['q'] ?? '');
$assetSourceFilter = trim($_GET['source'] ?? '');
$assetTypeFilter = trim($_GET['item_type'] ?? '');
$preselectedAssetKey = trim((string) ($_GET['asset_key'] ?? ''));
$transferMode = trim((string) ($_POST['mode'] ?? $_GET['mode'] ?? 'direct'));
if (!in_array($transferMode, ['direct', 'bulk', 'search'], true)) {
    $transferMode = 'direct';
}
if (!in_array($transferHistoryStatus, ['posted', 'cancelled', 'all'], true)) {
    $transferHistoryStatus = 'posted';
}
if (!in_array($documentHistoryStatus, ['posted', 'cancelled', 'all'], true)) {
    $documentHistoryStatus = 'posted';
}
$form = [
    'asset_key' => '',
    'transfer_date' => date('Y-m-d'),
    'to_office_id' => '',
    'to_employee_id' => '',
    'to_responsibility_code_id' => '',
    'reason' => '',
    'remarks' => '',
];
$bulkForm = [
    'source_office_id' => '',
    'source_employee_id' => '',
    'transfer_date' => date('Y-m-d'),
    'to_office_id' => '',
    'to_employee_id' => '',
    'to_responsibility_code_id' => '',
    'reason' => '',
    'remarks' => '',
];
$searchForm = [
    'query' => trim((string) ($_GET['search_query'] ?? '')),
    'source_type' => trim((string) ($_GET['search_source_type'] ?? '')),
    'item_type' => trim((string) ($_GET['search_item_type'] ?? '')),
    'current_office_id' => trim((string) ($_GET['search_current_office_id'] ?? '')),
    'current_employee_id' => trim((string) ($_GET['search_current_employee_id'] ?? '')),
    'transfer_date' => date('Y-m-d'),
    'to_office_id' => '',
    'to_employee_id' => '',
    'to_responsibility_code_id' => '',
    'reason' => '',
    'remarks' => '',
];

function transfer_name(array $row, string $prefix = ''): string
{
    if ($prefix === '' && isset($row['first_name'])) {
        return trim(implode(' ', array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['middle_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
            trim((string) ($row['suffix_name'] ?? '')),
        ])));
    }
    return trim(implode(' ', array_filter([
        trim((string) ($row[$prefix . 'first_name'] ?? '')),
        trim((string) ($row[$prefix . 'middle_name'] ?? '')),
        trim((string) ($row[$prefix . 'last_name'] ?? '')),
        trim((string) ($row[$prefix . 'suffix_name'] ?? '')),
    ])));
}

function transfer_post_asset(
    mysqli $db,
    array $asset,
    string $transferDate,
    int $toOfficeId,
    int $toEmployeeId,
    int $toRcId,
    string $reason,
    string $remarks,
    int $userId,
    ?int $batchId = null
): int {
    $ref = next_module_code($db, 'transfers');
    $sourceType = (string) ($asset['source_type'] ?? '');
    $distributionItemDetailId = $sourceType === 'system' ? (int) ($asset['source_id'] ?? 0) : 0;
    $legacyAssetId = $sourceType === 'legacy' ? (int) ($asset['source_id'] ?? 0) : 0;
    $propertyNumber = (string) ($asset['property_number'] ?? '');
    $fromOfficeId = (int) ($asset['current_office_id'] ?? 0);
    $fromEmployeeId = (int) ($asset['current_employee_id'] ?? 0);
    $fromRcId = (int) ($asset['current_rc_id'] ?? 0);

    $stmt = $db->prepare("INSERT INTO asset_transfers (system_reference, transfer_date, source_type, distribution_item_detail_id, legacy_asset_id, batch_id, property_number, from_office_id, from_employee_id, from_responsibility_code_id, to_office_id, to_employee_id, to_responsibility_code_id, reason, remarks, created_by) VALUES (?, ?, ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), ?, ?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare transfer insert.');
    }
    $stmt->bind_param('sssiiisiiiiiissi', $ref, $transferDate, $sourceType, $distributionItemDetailId, $legacyAssetId, $batchId, $propertyNumber, $fromOfficeId, $fromEmployeeId, $fromRcId, $toOfficeId, $toEmployeeId, $toRcId, $reason, $remarks, $userId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save transfer record: ' . $err);
    }
    $transferId = (int) $stmt->insert_id;
    $stmt->close();

    if ($sourceType === 'system') {
        $stmt = $db->prepare("UPDATE distribution_item_details SET current_office_id = ?, current_employee_id = NULLIF(?,0), current_responsibility_code_id = NULLIF(?,0) WHERE id = ?");
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare system accountability update.');
        }
        $stmt->bind_param('iiii', $toOfficeId, $toEmployeeId, $toRcId, $distributionItemDetailId);
    } else {
        $stmt = $db->prepare("UPDATE legacy_assets SET office_id = ?, employee_id = NULLIF(?,0), responsibility_code_id = NULLIF(?,0) WHERE id = ?");
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare legacy accountability update.');
        }
        $stmt->bind_param('iiii', $toOfficeId, $toEmployeeId, $toRcId, $legacyAssetId);
    }
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to update asset accountability: ' . $err);
    }
    $stmt->close();

    write_audit_log($db, [
        'action' => 'insert',
        'table_name' => 'asset_transfers',
        'record_id' => $transferId,
        'module_name' => 'transfers',
        'record_type' => 'asset_transfer',
        'action_name' => 'post_transfer',
        'new_values' => [
            'system_reference' => $ref,
            'transfer_date' => $transferDate,
            'source_type' => $sourceType,
            'property_number' => $propertyNumber,
            'batch_id' => $batchId,
            'from_office_id' => $fromOfficeId,
            'from_employee_id' => $fromEmployeeId,
            'from_responsibility_code_id' => $fromRcId,
            'to_office_id' => $toOfficeId,
            'to_employee_id' => $toEmployeeId,
            'to_responsibility_code_id' => $toRcId,
        ],
        'description' => 'Posted transfer of accountability.',
    ]);

    return $transferId;
}

function transfer_document_type(string $itemType): string
{
    return $itemType === 'semi_expendable' ? 'itr' : 'ptr';
}

function transfer_batch_reference(mysqli $db, string $documentType): string
{
    $base = next_module_code($db, 'transfer_batches');
    if ($documentType === 'itr' && str_starts_with($base, 'PTR-')) {
        return 'ITR-' . substr($base, 4);
    }
    return $base;
}

function transfer_create_batch(
    mysqli $db,
    string $documentType,
    string $transferDate,
    int $sourceOfficeId,
    int $sourceEmployeeId,
    int $toOfficeId,
    int $toEmployeeId,
    int $toRcId,
    string $reason,
    string $remarks,
    int $userId
): array {
    $reference = transfer_batch_reference($db, $documentType);
    $stmt = $db->prepare("
        INSERT INTO transfer_batches
            (system_reference, document_type, transfer_date, source_office_id, source_employee_id, to_office_id, to_employee_id, to_responsibility_code_id, reason, remarks, created_by)
        VALUES
            (?, ?, ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), ?, ?, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare transfer batch insert.');
    }
    $stmt->bind_param('sssiiiiissi', $reference, $documentType, $transferDate, $sourceOfficeId, $sourceEmployeeId, $toOfficeId, $toEmployeeId, $toRcId, $reason, $remarks, $userId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save transfer batch: ' . $err);
    }
    $batchId = (int) $stmt->insert_id;
    $stmt->close();

    return ['id' => $batchId, 'system_reference' => $reference, 'document_type' => $documentType];
}

function transfer_attach_batch_item(mysqli $db, int $batchId, int $transferId, array $asset): void
{
    $sourceType = (string) ($asset['source_type'] ?? '');
    $distributionItemDetailId = $sourceType === 'system' ? (int) ($asset['source_id'] ?? 0) : 0;
    $legacyAssetId = $sourceType === 'legacy' ? (int) ($asset['source_id'] ?? 0) : 0;
    $propertyNumber = (string) ($asset['property_number'] ?? '');
    $itemType = (string) ($asset['item_type'] ?? 'equipment');

    $stmt = $db->prepare("
        INSERT INTO transfer_batch_items
            (batch_id, asset_transfer_id, source_type, distribution_item_detail_id, legacy_asset_id, property_number, item_type)
        VALUES
            (?, ?, ?, NULLIF(?,0), NULLIF(?,0), ?, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare transfer batch item insert.');
    }
    $stmt->bind_param('iisiiss', $batchId, $transferId, $sourceType, $distributionItemDetailId, $legacyAssetId, $propertyNumber, $itemType);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save transfer batch item: ' . $err);
    }
    $stmt->close();
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $res = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($res) $offices = $res->fetch_all(MYSQLI_ASSOC);
    $res = $db->query("SELECT id, office_id, employee_no, first_name, middle_name, last_name, suffix_name, position_title, is_unit_head FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC");
    if ($res) $employees = $res->fetch_all(MYSQLI_ASSOC);
    $res = $db->query("SELECT id, office_id, code, description FROM responsibility_codes WHERE is_active = 1 ORDER BY code ASC");
    if ($res) $responsibilityCodes = $res->fetch_all(MYSQLI_ASSOC);

    $sql = "SELECT CONCAT('system:', did.id) AS asset_key, 'system' AS source_type, did.id AS source_id, did.property_number,
                   poi.item_type, poi.item_description, c.classification_name, c.classification_family, did.brand, did.model, did.serial_no,
                   COALESCE(curr_o.office_name, base_o.office_name) AS current_office_name,
                   COALESCE(curr_e.employee_no, base_e.employee_no) AS employee_no,
                   COALESCE(curr_e.first_name, base_e.first_name) AS first_name,
                   COALESCE(curr_e.middle_name, base_e.middle_name) AS middle_name,
                   COALESCE(curr_e.last_name, base_e.last_name) AS last_name,
                   COALESCE(curr_e.suffix_name, base_e.suffix_name) AS suffix_name,
                   COALESCE(curr_rc.code, base_rc.code) AS current_rc_code,
                   COALESCE(did.current_office_id, d.office_id) AS current_office_id,
                   COALESCE(did.current_employee_id, d.employee_id) AS current_employee_id,
                   COALESCE(did.current_responsibility_code_id, base_rc.id) AS current_rc_id
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN offices base_o ON base_o.id = d.office_id
            LEFT JOIN employees base_e ON base_e.id = d.employee_id
            LEFT JOIN responsibility_codes base_rc ON base_rc.office_id = d.office_id
            LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
            LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
            LEFT JOIN responsibility_codes curr_rc ON curr_rc.id = did.current_responsibility_code_id
            WHERE poi.item_type IN ('equipment','semi_expendable')
              AND did.is_distributed = 1
              AND (did.is_disposed IS NULL OR did.is_disposed = 0)";
    $res = $db->query($sql);
    if ($res) while ($row = $res->fetch_assoc()) $assets[] = $row;

    $sql = "SELECT CONCAT('legacy:', la.id) AS asset_key, 'legacy' AS source_type, la.id AS source_id, la.property_number,
                   la.item_type, la.item_description, c.classification_name, c.classification_family, la.brand, la.model, la.serial_no,
                   o.office_name AS current_office_name, e.employee_no,
                   e.first_name, e.middle_name, e.last_name, e.suffix_name, rc.code AS current_rc_code,
                   la.office_id AS current_office_id, la.employee_id AS current_employee_id, la.responsibility_code_id AS current_rc_id
            FROM legacy_assets la
            LEFT JOIN classifications c ON c.id = la.classification_id
            LEFT JOIN offices o ON o.id = la.office_id
            LEFT JOIN employees e ON e.id = la.employee_id
            LEFT JOIN responsibility_codes rc ON rc.id = la.responsibility_code_id
            WHERE la.is_active = 1
              AND la.item_type IN ('equipment','semi_expendable')";
    $res = $db->query($sql);
    if ($res) while ($row = $res->fetch_assoc()) $assets[] = $row;

    usort($assets, static function ($a, $b) {
        return strcmp((string) ($a['property_number'] ?? ''), (string) ($b['property_number'] ?? ''));
    });

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $preselectedAssetKey !== '') {
        foreach ($assets as $candidate) {
            if (($candidate['asset_key'] ?? '') === $preselectedAssetKey) {
                $form['asset_key'] = $preselectedAssetKey;
                break;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? 'direct_transfer'));
        if ($action === 'direct_transfer') {
            foreach ($form as $k => $v) {
                $form[$k] = trim((string) ($_POST[$k] ?? ''));
            }
            $transferMode = 'direct';
        } elseif ($action === 'bulk_transfer') {
            foreach ($bulkForm as $k => $v) {
                $bulkForm[$k] = trim((string) ($_POST[$k] ?? ''));
            }
            $transferMode = 'bulk';
        } else {
            foreach ($searchForm as $k => $v) {
                $searchForm[$k] = trim((string) ($_POST[$k] ?? ''));
            }
            $transferMode = 'search';
        }

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'direct_transfer') {
            if ($form['asset_key'] === '') $errors[] = 'Select an asset to transfer.';
            if ($form['transfer_date'] === '') $errors[] = 'Transfer date is required.';
            if ($form['to_office_id'] === '') $errors[] = 'Receiving office is required.';

            $asset = null;
            foreach ($assets as $candidate) {
                if (($candidate['asset_key'] ?? '') === $form['asset_key']) { $asset = $candidate; break; }
            }
            if (!$asset) $errors[] = 'Selected asset was not found.';

            $toOfficeId = (int) ($form['to_office_id'] ?: 0);
            $toEmployeeId = (int) ($form['to_employee_id'] ?: 0);
            $toRcId = (int) ($form['to_responsibility_code_id'] ?: 0);

            if ($toEmployeeId > 0) {
                $ok = false;
                foreach ($employees as $employee) if ((int) $employee['id'] === $toEmployeeId) $ok = (int) ($employee['office_id'] ?? 0) === $toOfficeId;
                if (!$ok) $errors[] = 'Selected accountable employee does not belong to the chosen office.';
            }
            if ($toRcId > 0) {
                $ok = false;
                foreach ($responsibilityCodes as $rc) if ((int) $rc['id'] === $toRcId) $ok = (int) ($rc['office_id'] ?? 0) === $toOfficeId;
                if (!$ok) $errors[] = 'Selected responsibility code does not belong to the chosen office.';
            }
            if ($asset && (int) ($asset['current_office_id'] ?? 0) === $toOfficeId && (int) ($asset['current_employee_id'] ?? 0) === $toEmployeeId && (int) ($asset['current_rc_id'] ?? 0) === $toRcId) {
                $errors[] = 'The new accountability assignment is the same as the current assignment.';
            }

            if (!$errors && $asset) {
                $db->begin_transaction();
                try {
                    transfer_post_asset($db, $asset, $form['transfer_date'], $toOfficeId, $toEmployeeId, $toRcId, $form['reason'], $form['remarks'], (int) current_user_id());
                    $db->commit();
                    set_flash('success', 'Transfer of accountability posted successfully.');
                    redirect('modules/transfers/index.php');
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Unable to save transfer: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'bulk_transfer') {
            if ($bulkForm['source_office_id'] === '') $errors[] = 'Source office is required.';
            if ($bulkForm['transfer_date'] === '') $errors[] = 'Transfer date is required.';
            if ($bulkForm['to_office_id'] === '') $errors[] = 'Receiving office is required.';
            $selectedAssetKeys = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), (array) ($_POST['asset_keys'] ?? []))));

            $toOfficeId = (int) ($bulkForm['to_office_id'] ?: 0);
            $toEmployeeId = (int) ($bulkForm['to_employee_id'] ?: 0);
            $toRcId = (int) ($bulkForm['to_responsibility_code_id'] ?: 0);
            $sourceOfficeId = (int) ($bulkForm['source_office_id'] ?: 0);
            $sourceEmployeeId = (int) ($bulkForm['source_employee_id'] ?: 0);

            if ($toEmployeeId > 0) {
                $ok = false;
                foreach ($employees as $employee) if ((int) $employee['id'] === $toEmployeeId) $ok = (int) ($employee['office_id'] ?? 0) === $toOfficeId;
                if (!$ok) $errors[] = 'Selected new accountable employee does not belong to the chosen receiving office.';
            }
            if ($toRcId > 0) {
                $ok = false;
                foreach ($responsibilityCodes as $rc) if ((int) $rc['id'] === $toRcId) $ok = (int) ($rc['office_id'] ?? 0) === $toOfficeId;
                if (!$ok) $errors[] = 'Selected new responsibility code does not belong to the chosen receiving office.';
            }

            $bulkCandidates = array_values(array_filter($assets, static function (array $asset) use ($sourceOfficeId, $sourceEmployeeId): bool {
                if ((int) ($asset['current_office_id'] ?? 0) !== $sourceOfficeId) {
                    return false;
                }
                if ($sourceEmployeeId > 0 && (int) ($asset['current_employee_id'] ?? 0) !== $sourceEmployeeId) {
                    return false;
                }
                return true;
            }));

            $bulkCandidates = array_values(array_filter($bulkCandidates, static function (array $asset) use ($toOfficeId, $toEmployeeId, $toRcId): bool {
                return !(
                    (int) ($asset['current_office_id'] ?? 0) === $toOfficeId &&
                    (int) ($asset['current_employee_id'] ?? 0) === $toEmployeeId &&
                    (int) ($asset['current_rc_id'] ?? 0) === $toRcId
                );
            }));

            if ($selectedAssetKeys) {
                $selectedLookup = array_fill_keys($selectedAssetKeys, true);
                $bulkCandidates = array_values(array_filter($bulkCandidates, static function (array $asset) use ($selectedLookup): bool {
                    return isset($selectedLookup[(string) ($asset['asset_key'] ?? '')]);
                }));
            }

            if (!$bulkCandidates) {
                $errors[] = $selectedAssetKeys
                    ? 'No selected assets matched the office turnover criteria.'
                    : 'No assets matched the selected office turnover criteria.';
            }

            if (!$errors) {
                $db->begin_transaction();
                try {
                    $groupedCandidates = [];
                    foreach ($bulkCandidates as $asset) {
                        $groupedCandidates[transfer_document_type((string) ($asset['item_type'] ?? 'equipment'))][] = $asset;
                    }

                    $postedDocuments = [];
                    foreach ($groupedCandidates as $documentType => $documentAssets) {
                        $batch = transfer_create_batch(
                            $db,
                            $documentType,
                            $bulkForm['transfer_date'],
                            $sourceOfficeId,
                            $sourceEmployeeId,
                            $toOfficeId,
                            $toEmployeeId,
                            $toRcId,
                            $bulkForm['reason'],
                            $bulkForm['remarks'],
                            (int) current_user_id()
                        );
                        foreach ($documentAssets as $asset) {
                            $transferId = transfer_post_asset(
                                $db,
                                $asset,
                                $bulkForm['transfer_date'],
                                $toOfficeId,
                                $toEmployeeId,
                                $toRcId,
                                $bulkForm['reason'],
                                $bulkForm['remarks'],
                                (int) current_user_id(),
                                (int) $batch['id']
                            );
                            transfer_attach_batch_item($db, (int) $batch['id'], $transferId, $asset);
                        }
                        $postedDocuments[] = strtoupper((string) $batch['document_type']) . ' ' . $batch['system_reference'] . ' (' . count($documentAssets) . ' item' . (count($documentAssets) === 1 ? '' : 's') . ')';
                    }
                    $db->commit();
                    set_flash('success', 'Bulk transfer posted for ' . count($bulkCandidates) . ' asset(s): ' . implode('; ', $postedDocuments) . '.');
                    redirect('modules/transfers/index.php?mode=bulk&source_office_id=' . $sourceOfficeId);
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Unable to save bulk transfer: ' . $e->getMessage();
                }
            }
        } else {
            if ($searchForm['transfer_date'] === '') $errors[] = 'Transfer date is required.';
            if ($searchForm['to_office_id'] === '') $errors[] = 'Receiving office is required.';

            $selectedAssetKeys = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), (array) ($_POST['asset_keys'] ?? []))));
            if (!$selectedAssetKeys) {
                $errors[] = 'Select at least one asset to transfer.';
            }

            $toOfficeId = (int) ($searchForm['to_office_id'] ?: 0);
            $toEmployeeId = (int) ($searchForm['to_employee_id'] ?: 0);
            $toRcId = (int) ($searchForm['to_responsibility_code_id'] ?: 0);

            if ($toEmployeeId > 0) {
                $ok = false;
                foreach ($employees as $employee) if ((int) $employee['id'] === $toEmployeeId) $ok = (int) ($employee['office_id'] ?? 0) === $toOfficeId;
                if (!$ok) $errors[] = 'Selected new accountable employee does not belong to the chosen receiving office.';
            }
            if ($toRcId > 0) {
                $ok = false;
                foreach ($responsibilityCodes as $rc) if ((int) $rc['id'] === $toRcId) $ok = (int) ($rc['office_id'] ?? 0) === $toOfficeId;
                if (!$ok) $errors[] = 'Selected new responsibility code does not belong to the chosen receiving office.';
            }

            $selectedLookup = array_fill_keys($selectedAssetKeys, true);
            $searchCandidates = array_values(array_filter($assets, static function (array $asset) use ($selectedLookup, $toOfficeId, $toEmployeeId, $toRcId): bool {
                if (!isset($selectedLookup[(string) ($asset['asset_key'] ?? '')])) {
                    return false;
                }
                return !(
                    (int) ($asset['current_office_id'] ?? 0) === $toOfficeId &&
                    (int) ($asset['current_employee_id'] ?? 0) === $toEmployeeId &&
                    (int) ($asset['current_rc_id'] ?? 0) === $toRcId
                );
            }));

            if (!$searchCandidates) {
                $errors[] = 'No selected assets are eligible for transfer.';
            }

            if (!$errors) {
                $db->begin_transaction();
                try {
                    $groupedCandidates = [];
                    foreach ($searchCandidates as $asset) {
                        $groupedCandidates[transfer_document_type((string) ($asset['item_type'] ?? 'equipment'))][] = $asset;
                    }

                    $postedDocuments = [];
                    foreach ($groupedCandidates as $documentType => $documentAssets) {
                        $batch = transfer_create_batch(
                            $db,
                            $documentType,
                            $searchForm['transfer_date'],
                            0,
                            0,
                            $toOfficeId,
                            $toEmployeeId,
                            $toRcId,
                            $searchForm['reason'],
                            $searchForm['remarks'],
                            (int) current_user_id()
                        );
                        foreach ($documentAssets as $asset) {
                            $transferId = transfer_post_asset(
                                $db,
                                $asset,
                                $searchForm['transfer_date'],
                                $toOfficeId,
                                $toEmployeeId,
                                $toRcId,
                                $searchForm['reason'],
                                $searchForm['remarks'],
                                (int) current_user_id(),
                                (int) $batch['id']
                            );
                            transfer_attach_batch_item($db, (int) $batch['id'], $transferId, $asset);
                        }
                        $postedDocuments[] = strtoupper((string) $batch['document_type']) . ' ' . $batch['system_reference'] . ' (' . count($documentAssets) . ' item' . (count($documentAssets) === 1 ? '' : 's') . ')';
                    }

                    $db->commit();
                    set_flash('success', 'Search transfer posted for ' . count($searchCandidates) . ' asset(s): ' . implode('; ', $postedDocuments) . '.');
                    redirect('modules/transfers/index.php?mode=search');
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Unable to save search transfer: ' . $e->getMessage();
                }
            }
        }
    }

    $transferStatusSql = $transferHistoryStatus === 'all' ? '' : "WHERE at.status = '" . $db->real_escape_string($transferHistoryStatus) . "'";
    $stmt = $db->prepare("
        SELECT
            at.id,
            at.system_reference,
            at.transfer_date,
            at.status,
            at.property_number,
            at.source_type,
            at.reason,
            from_o.office_name AS from_office_name,
            to_o.office_name AS to_office_name,
            from_e.first_name AS from_first_name,
            from_e.middle_name AS from_middle_name,
            from_e.last_name AS from_last_name,
            from_e.suffix_name AS from_suffix_name,
            to_e.first_name AS to_first_name,
            to_e.middle_name AS to_middle_name,
            to_e.last_name AS to_last_name,
            to_e.suffix_name AS to_suffix_name,
            from_rc.code AS from_rc_code,
            to_rc.code AS to_rc_code,
            CASE
                WHEN at.source_type = 'system' THEN poi.item_description
                ELSE la.item_description
            END AS item_description,
            CASE
                WHEN at.source_type = 'system' THEN poi.item_type
                ELSE la.item_type
            END AS item_type,
            CASE
                WHEN at.source_type = 'system' THEN did.brand
                ELSE la.brand
            END AS brand,
            CASE
                WHEN at.source_type = 'system' THEN did.model
                ELSE la.model
            END AS model,
            CASE
                WHEN at.source_type = 'system' THEN did.serial_no
                ELSE la.serial_no
            END AS serial_no,
            CASE
                WHEN at.source_type = 'system' THEN c.classification_name
                ELSE lc.classification_name
            END AS classification_name,
            CASE
                WHEN at.source_type = 'system' THEN c.classification_family
                ELSE lc.classification_family
            END AS classification_family
        FROM asset_transfers at
        LEFT JOIN offices from_o ON from_o.id = at.from_office_id
        LEFT JOIN offices to_o ON to_o.id = at.to_office_id
        LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
        LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
        LEFT JOIN responsibility_codes from_rc ON from_rc.id = at.from_responsibility_code_id
        LEFT JOIN responsibility_codes to_rc ON to_rc.id = at.to_responsibility_code_id
        LEFT JOIN distribution_item_details did ON did.id = at.distribution_item_detail_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN legacy_assets la ON la.id = at.legacy_asset_id
        LEFT JOIN classifications lc ON lc.id = la.classification_id
        {$transferStatusSql}
        ORDER BY at.transfer_date DESC, at.id DESC
        LIMIT 100
    ");
    if ($stmt) {
        $stmt->execute();
        $transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $batchStatusSql = $documentHistoryStatus === 'all' ? '' : "WHERE tb.status = '" . $db->real_escape_string($documentHistoryStatus) . "'";
    $stmt = $db->prepare("
        SELECT
            tb.id,
            tb.system_reference,
            tb.document_type,
            tb.transfer_date,
            tb.status,
            tb.reason,
            from_o.office_name AS from_office_name,
            to_o.office_name AS to_office_name,
            from_e.first_name AS from_first_name,
            from_e.middle_name AS from_middle_name,
            from_e.last_name AS from_last_name,
            from_e.suffix_name AS from_suffix_name,
            to_e.first_name AS to_first_name,
            to_e.middle_name AS to_middle_name,
            to_e.last_name AS to_last_name,
            to_e.suffix_name AS to_suffix_name,
            COUNT(tbi.id) AS item_count
        FROM transfer_batches tb
        LEFT JOIN offices from_o ON from_o.id = tb.source_office_id
        LEFT JOIN offices to_o ON to_o.id = tb.to_office_id
        LEFT JOIN employees from_e ON from_e.id = tb.source_employee_id
        LEFT JOIN employees to_e ON to_e.id = tb.to_employee_id
        LEFT JOIN transfer_batch_items tbi ON tbi.batch_id = tb.id
        {$batchStatusSql}
        GROUP BY tb.id
        ORDER BY tb.transfer_date DESC, tb.id DESC
        LIMIT 25
    ");
    if ($stmt) {
        $stmt->execute();
        $transferBatches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$bulkPreviewAssets = [];
$bulkSourceOfficeId = (int) (($bulkForm['source_office_id'] !== '' ? $bulkForm['source_office_id'] : ($_GET['source_office_id'] ?? 0)));
$bulkSourceEmployeeId = (int) (($bulkForm['source_employee_id'] !== '' ? $bulkForm['source_employee_id'] : ($_GET['source_employee_id'] ?? 0)));
$bulkForm['source_office_id'] = $bulkSourceOfficeId > 0 ? (string) $bulkSourceOfficeId : $bulkForm['source_office_id'];
$bulkForm['source_employee_id'] = $bulkSourceEmployeeId > 0 ? (string) $bulkSourceEmployeeId : $bulkForm['source_employee_id'];

if ($bulkSourceOfficeId > 0) {
    $bulkPreviewAssets = array_values(array_filter($assets, static function (array $asset) use ($bulkSourceOfficeId, $bulkSourceEmployeeId): bool {
        if ((int) ($asset['current_office_id'] ?? 0) !== $bulkSourceOfficeId) {
            return false;
        }
        if ($bulkSourceEmployeeId > 0 && (int) ($asset['current_employee_id'] ?? 0) !== $bulkSourceEmployeeId) {
            return false;
        }
        return true;
    }));
}

$bulkPreviewByType = [
    'equipment' => count(array_filter($bulkPreviewAssets, static fn(array $asset): bool => ($asset['item_type'] ?? '') === 'equipment')),
    'semi_expendable' => count(array_filter($bulkPreviewAssets, static fn(array $asset): bool => ($asset['item_type'] ?? '') === 'semi_expendable')),
];

$searchQuery = trim((string) $searchForm['query']);
$searchSourceType = in_array($searchForm['source_type'], ['', 'system', 'legacy'], true) ? $searchForm['source_type'] : '';
$searchItemType = in_array($searchForm['item_type'], ['', 'equipment', 'semi_expendable'], true) ? $searchForm['item_type'] : '';
$searchCurrentOfficeId = (int) ($searchForm['current_office_id'] !== '' ? $searchForm['current_office_id'] : 0);
$searchCurrentEmployeeId = (int) ($searchForm['current_employee_id'] !== '' ? $searchForm['current_employee_id'] : 0);
$searchForm['source_type'] = $searchSourceType;
$searchForm['item_type'] = $searchItemType;
$searchPreviewAssets = array_values(array_filter($assets, static function (array $asset) use ($searchQuery, $searchSourceType, $searchItemType, $searchCurrentOfficeId, $searchCurrentEmployeeId): bool {
    if ($searchSourceType !== '' && (string) ($asset['source_type'] ?? '') !== $searchSourceType) {
        return false;
    }
    if ($searchItemType !== '' && (string) ($asset['item_type'] ?? '') !== $searchItemType) {
        return false;
    }
    if ($searchCurrentOfficeId > 0 && (int) ($asset['current_office_id'] ?? 0) !== $searchCurrentOfficeId) {
        return false;
    }
    if ($searchCurrentEmployeeId > 0 && (int) ($asset['current_employee_id'] ?? 0) !== $searchCurrentEmployeeId) {
        return false;
    }
    if ($searchQuery === '') {
        return true;
    }
    $haystack = strtolower(implode(' ', array_filter([
        (string) ($asset['property_number'] ?? ''),
        (string) ($asset['item_description'] ?? ''),
        (string) ($asset['classification_name'] ?? ''),
        (string) ($asset['classification_family'] ?? ''),
        (string) ($asset['brand'] ?? ''),
        (string) ($asset['model'] ?? ''),
        (string) ($asset['serial_no'] ?? ''),
        (string) ($asset['current_office_name'] ?? ''),
        (string) ($asset['employee_no'] ?? ''),
        transfer_name($asset),
    ])));
    return str_contains($haystack, strtolower($searchQuery));
}));
$searchPreviewByType = [
    'system' => count(array_filter($searchPreviewAssets, static fn(array $asset): bool => ($asset['source_type'] ?? '') === 'system')),
    'legacy' => count(array_filter($searchPreviewAssets, static fn(array $asset): bool => ($asset['source_type'] ?? '') === 'legacy')),
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<style>
.transfer-filter-card,
.transfer-summary-card,
.transfer-panel {
    border: 1px solid var(--bs-border-color);
    border-radius: 1rem;
}

.transfer-filter-card {
    background: var(--bs-secondary-bg);
    padding: 1rem;
}

.transfer-summary-grid {
    display: grid;
    gap: 0.85rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.transfer-summary-card {
    background: rgba(255,255,255,.7);
    padding: 1rem;
}

.transfer-panel {
    background: #fff;
    padding: 1rem;
    height: 100%;
}

.transfer-panel-title {
    font-size: 0.95rem;
    font-weight: 700;
    margin-bottom: 0.85rem;
}

.transfer-current-copy {
    background: var(--bs-secondary-bg);
    border: 1px dashed var(--bs-border-color);
    border-radius: 0.85rem;
    padding: 0.9rem;
}

.transfer-current-copy .label {
    color: var(--bs-secondary-color);
    display: block;
    font-size: 0.76rem;
    margin-bottom: 0.15rem;
    text-transform: uppercase;
}

.transfer-current-copy .value {
    font-weight: 600;
    margin-bottom: 0.65rem;
}

.transfer-form-actions {
    border-top: 1px solid var(--bs-border-color);
    margin-top: 1rem;
    padding-top: 1rem;
}

@media (max-width: 991.98px) {
    .transfer-summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">Transfer of Accountability</h5>
                        <div class="small text-muted">Use direct transfer for one asset or office turnover for bulk accountability changes.</div>
                    </div>
                    <span class="badge text-bg-light">
                        <?php if ($transferMode === 'bulk'): ?>
                            <?php echo count($bulkPreviewAssets); ?> matched asset(s)
                        <?php else: ?>
                            <span id="filteredAssetCount"><?php echo count($assets); ?></span> asset(s)
                        <?php endif; ?>
                    </span>
                </div>
                <div class="nav nav-pills gap-2 mb-4">
                    <a class="btn <?php echo $transferMode === 'direct' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo base_url('modules/transfers/index.php?mode=direct'); ?>">Direct Transfer</a>
                    <a class="btn <?php echo $transferMode === 'search' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo base_url('modules/transfers/index.php?mode=search'); ?>">Search Assets</a>
                    <a class="btn <?php echo $transferMode === 'bulk' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo base_url('modules/transfers/index.php?mode=bulk'); ?>">Office Turnover</a>
                </div>

                <?php if ($flash): ?><div class="alert alert-success"><?php echo h($flash['message']); ?></div><?php endif; ?>
                <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>

                <?php if ($transferMode === 'direct'): ?>
                <div class="transfer-filter-card mb-4">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-5">
                            <label class="form-label mb-0">Search Asset</label>
                            <select name="asset_key" id="asset_key" class="form-select" data-placeholder="Search property no., serial no., description, brand, model, office, employee..." required>
                                <option value="">Search asset</option>
                                <?php foreach ($assets as $asset): ?>
                                    <?php
                                    $assetLabelParts = [];
                                    $assetLabelParts[] = (string) ($asset['property_number'] ?? '');
                                    $assetLabelParts[] = (string) ($asset['item_description'] ?? '');
                                    $brandModel = trim(trim((string) ($asset['brand'] ?? '')) . ' ' . trim((string) ($asset['model'] ?? '')));
                                    if ($brandModel !== '') {
                                        $assetLabelParts[] = $brandModel;
                                    }
                                    if (!empty($asset['serial_no'])) {
                                        $assetLabelParts[] = 'SN ' . (string) $asset['serial_no'];
                                    }
                                    if (!empty($asset['current_office_name'])) {
                                        $assetLabelParts[] = (string) $asset['current_office_name'];
                                    }
                                    $typeLabel = ($asset['item_type'] ?? '') === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment';
                                    $sourceLabel = ($asset['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System';
                                    $assetLabelParts[] = $typeLabel;
                                    $assetLabelParts[] = $sourceLabel;
                                    $assetSearchText = strtolower(implode(' ', array_filter([
                                        (string) ($asset['property_number'] ?? ''),
                                        (string) ($asset['item_description'] ?? ''),
                                        (string) ($asset['classification_name'] ?? ''),
                                        (string) ($asset['classification_family'] ?? ''),
                                        (string) ($asset['brand'] ?? ''),
                                        (string) ($asset['model'] ?? ''),
                                        (string) ($asset['serial_no'] ?? ''),
                                        (string) ($asset['current_office_name'] ?? ''),
                                        (string) ($asset['employee_no'] ?? ''),
                                        transfer_name($asset),
                                        $typeLabel,
                                        $sourceLabel,
                                    ])));
                                    ?>
                                    <option value="<?php echo h($asset['asset_key']); ?>"
                                            data-source="<?php echo h((string) ($asset['source_type'] ?? '')); ?>"
                                            data-type="<?php echo h((string) ($asset['item_type'] ?? '')); ?>"
                                            data-search="<?php echo h($assetSearchText); ?>"
                                            <?php echo $form['asset_key'] === ($asset['asset_key'] ?? '') ? 'selected' : ''; ?>>
                                        <?php echo h(implode(' | ', array_filter($assetLabelParts))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-2">
                            <label class="form-label mb-0">Source</label>
                            <select id="assetFilterSource" class="form-select">
                                <option value="">All Sources</option>
                                <option value="system" <?php echo $assetSourceFilter === 'system' ? 'selected' : ''; ?>>System</option>
                                <option value="legacy" <?php echo $assetSourceFilter === 'legacy' ? 'selected' : ''; ?>>Beginning Balance</option>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-2">
                            <label class="form-label mb-0">Item Type</label>
                            <select id="assetFilterType" class="form-select">
                                <option value="">All Types</option>
                                <option value="equipment" <?php echo $assetTypeFilter === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                <option value="semi_expendable" <?php echo $assetTypeFilter === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-2 d-grid">
                            <button type="button" id="assetFilterClear" class="btn btn-outline-secondary">Clear Filters</button>
                        </div>
                        <div class="col-lg-2">
                            <div class="small text-muted">Use one searchable asset field only.</div>
                        </div>
                    </div>
                </div>

                <div class="transfer-summary-grid mb-4">
                    <div class="transfer-summary-card">
                        <div class="text-muted small">Filtered Assets</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format(count($assets))); ?></div>
                    </div>
                    <div class="transfer-summary-card">
                        <div class="text-muted small">System Assets</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format(count(array_filter($assets, static fn($asset) => ($asset['source_type'] ?? '') === 'system')))); ?></div>
                    </div>
                    <div class="transfer-summary-card">
                        <div class="text-muted small">Beginning Balance Assets</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format(count(array_filter($assets, static fn($asset) => ($asset['source_type'] ?? '') === 'legacy')))); ?></div>
                    </div>
                </div>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="direct_transfer">
                    <input type="hidden" name="mode" value="direct">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Transfer Date</label>
                            <input type="date" name="transfer_date" class="form-control" value="<?php echo h($form['transfer_date']); ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Reason</label>
                            <input type="text" name="reason" class="form-control" value="<?php echo h($form['reason']); ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-lg-4">
                            <div class="transfer-panel">
                                <div class="transfer-panel-title">Current Accountability</div>
                                <div class="transfer-current-copy" id="currentAssignmentCard">
                                    <span class="label">Property / Asset</span>
                                    <div class="value" id="currentAssetName">Select an asset</div>
                                    <span class="label">Current Office</span>
                                    <div class="value" id="currentOfficeName">—</div>
                                    <span class="label">Current Accountable Employee</span>
                                    <div class="value" id="currentEmployeeName">—</div>
                                    <span class="label">Current Responsibility Code</span>
                                    <div class="value mb-0" id="currentRcCode">—</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="transfer-panel">
                                <div class="transfer-panel-title">New Accountability</div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">New Office</label>
                                        <select name="to_office_id" id="to_office_id" class="form-select" data-placeholder="Select office" required>
                                            <option value="">Select office</option>
                                            <?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $form['to_office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">New Accountable Employee</label>
                                        <select name="to_employee_id" id="to_employee_id" class="form-select" data-placeholder="Select employee">
                                            <option value="">Select employee</option>
                                            <?php foreach ($employees as $employee): ?>
                                                <option value="<?php echo (int) $employee['id']; ?>" data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>" <?php echo $form['to_employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h(transfer_name($employee) . ' - ' . ($employee['employee_no'] ?? '') . (!empty($employee['position_title']) ? ' (' . $employee['position_title'] . ')' : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">New Responsibility Code</label>
                                        <select name="to_responsibility_code_id" id="to_responsibility_code_id" class="form-select" data-placeholder="Select responsibility code">
                                            <option value="">Select responsibility code</option>
                                            <?php foreach ($responsibilityCodes as $rc): ?>
                                                <option value="<?php echo (int) $rc['id']; ?>" data-office-id="<?php echo (int) ($rc['office_id'] ?? 0); ?>" <?php echo $form['to_responsibility_code_id'] === (string) $rc['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h(($rc['code'] ?? '') . (!empty($rc['description']) ? ' - ' . $rc['description'] : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Remarks</label>
                                        <textarea name="remarks" class="form-control" rows="2"><?php echo h($form['remarks']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end transfer-form-actions">
                        <button type="submit" class="btn btn-primary">Post Transfer</button>
                    </div>
                </form>
                <?php elseif ($transferMode === 'bulk'): ?>
                <form method="get" class="transfer-filter-card mb-4">
                    <input type="hidden" name="mode" value="bulk">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label mb-0">Office to Turn Over</label>
                            <select name="source_office_id" class="form-select" data-placeholder="Select office">
                                <option value="">Select office</option>
                                <?php foreach ($offices as $office): ?>
                                    <option value="<?php echo (int) $office['id']; ?>" <?php echo $bulkForm['source_office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label mb-0">Current Accountable Employee</label>
                            <select name="source_employee_id" class="form-select" data-placeholder="All employees">
                                <option value="">All employees in office</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo (int) $employee['id']; ?>" data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" <?php echo $bulkForm['source_employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(transfer_name($employee) . ' - ' . ($employee['employee_no'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-4 col-md-6">
                            <div class="small text-muted border rounded-3 px-3 py-2 bg-white">
                                Office turnover previews all accountable assets in the selected office, including both equipment and semi-expendable items.
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-6 d-grid">
                            <button type="submit" class="btn btn-outline-primary">Load Preview</button>
                        </div>
                    </div>
                </form>

                <div class="transfer-summary-grid mb-4">
                    <div class="transfer-summary-card">
                        <div class="text-muted small">Turnover Candidates</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format(count($bulkPreviewAssets))); ?></div>
                    </div>
                    <div class="transfer-summary-card">
                        <div class="text-muted small">Equipment</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format($bulkPreviewByType['equipment'])); ?></div>
                    </div>
                    <div class="transfer-summary-card">
                        <div class="text-muted small">Semi-Expendable</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format($bulkPreviewByType['semi_expendable'])); ?></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-xl-5">
                            <div class="transfer-panel">
                                <div class="transfer-panel-title">New Accountability for Selected Office Assets</div>
                            <form method="post" id="bulkTransferForm">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="bulk_transfer">
                                <input type="hidden" name="mode" value="bulk">
                                <input type="hidden" name="source_office_id" value="<?php echo h($bulkForm['source_office_id']); ?>">
                                <input type="hidden" name="source_employee_id" value="<?php echo h($bulkForm['source_employee_id']); ?>">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Transfer Date</label>
                                        <input type="date" name="transfer_date" class="form-control" value="<?php echo h($bulkForm['transfer_date']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Receiving Office</label>
                                        <select name="to_office_id" id="bulk_to_office_id" class="form-select" data-placeholder="Select office" required>
                                            <option value="">Select office</option>
                                            <?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $bulkForm['to_office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">New Accountable Employee</label>
                                        <select name="to_employee_id" id="bulk_to_employee_id" class="form-select" data-placeholder="Select employee">
                                            <option value="">Select employee</option>
                                            <?php foreach ($employees as $employee): ?>
                                                <option value="<?php echo (int) $employee['id']; ?>" data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>" <?php echo $bulkForm['to_employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h(transfer_name($employee) . ' - ' . ($employee['employee_no'] ?? '') . (!empty($employee['position_title']) ? ' (' . $employee['position_title'] . ')' : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">New Responsibility Code</label>
                                        <select name="to_responsibility_code_id" id="bulk_to_responsibility_code_id" class="form-select" data-placeholder="Select responsibility code">
                                            <option value="">Select responsibility code</option>
                                            <?php foreach ($responsibilityCodes as $rc): ?>
                                                <option value="<?php echo (int) $rc['id']; ?>" data-office-id="<?php echo (int) ($rc['office_id'] ?? 0); ?>" <?php echo $bulkForm['to_responsibility_code_id'] === (string) $rc['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h(($rc['code'] ?? '') . (!empty($rc['description']) ? ' - ' . $rc['description'] : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Reason</label>
                                        <input type="text" name="reason" class="form-control" value="<?php echo h($bulkForm['reason']); ?>" placeholder="Example: office turnover / unit head change">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Remarks</label>
                                        <textarea name="remarks" class="form-control" rows="3"><?php echo h($bulkForm['remarks']); ?></textarea>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end transfer-form-actions">
                                    <button type="submit" class="btn btn-primary" <?php echo $bulkPreviewAssets ? '' : 'disabled'; ?>>Post Bulk Transfer</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="col-xl-7">
                        <div class="transfer-panel">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <div class="transfer-panel-title mb-0">Bulk Transfer Preview</div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" id="bulkSelectAll" <?php echo $bulkPreviewAssets ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="bulkSelectAll">Select all</label>
                                    </div>
                                    <span class="badge text-bg-light"><span id="bulkSelectedCount"><?php echo count($bulkPreviewAssets); ?></span> selected</span>
                                </div>
                            </div>
                            <div class="small text-muted mb-3">This preview includes all equipment and semi-expendable assets currently accountable to the selected office and optional current accountable employee filter. Uncheck any asset you do not want to transfer.</div>
                            <div class="table-responsive mobile-table-frame">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th style="width:1%;"><input type="checkbox" class="form-check-input" id="bulkSelectAllTable" <?php echo $bulkPreviewAssets ? 'checked' : ''; ?>></th>
                                            <th>Property No.</th>
                                            <th>Asset</th>
                                            <th>Current Accountability</th>
                                            <th>Source</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($bulkPreviewAssets): foreach (array_slice($bulkPreviewAssets, 0, 100) as $asset): ?>
                                            <tr>
                                                <td>
                                                    <input
                                                        type="checkbox"
                                                        class="form-check-input bulk-asset-checkbox"
                                                        name="asset_keys[]"
                                                        value="<?php echo h((string) ($asset['asset_key'] ?? '')); ?>"
                                                        form="bulkTransferForm"
                                                        checked
                                                    >
                                                </td>
                                                <td class="fw-semibold"><?php echo h($asset['property_number'] ?? ''); ?></td>
                                                <td>
                                                    <div><?php echo h($asset['item_description'] ?? ''); ?></div>
                                                    <div class="small text-muted"><?php echo h(trim((string) ($asset['classification_name'] ?? ''))); ?></div>
                                                </td>
                                                <td>
                                                    <div><?php echo h($asset['current_office_name'] ?? ''); ?></div>
                                                    <div class="small text-muted"><?php echo h(transfer_name($asset)); ?><?php echo !empty($asset['current_rc_code']) ? ' | ' . h($asset['current_rc_code']) : ''; ?></div>
                                                </td>
                                                <td><?php echo h(($asset['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System Transaction'); ?></td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">Select an office to preview the assets for turnover.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (count($bulkPreviewAssets) > 100): ?>
                                <div class="small text-muted mt-2">Showing first 100 of <?php echo count($bulkPreviewAssets); ?> asset(s) in the preview. Posting still applies to the full filtered set.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($transferMode === 'search'): ?>
                <form method="get" class="transfer-filter-card mb-4">
                    <input type="hidden" name="mode" value="search">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-4">
                            <label class="form-label mb-0">Search Assets</label>
                            <input type="search" name="search_query" class="form-control" value="<?php echo h($searchForm['query']); ?>" placeholder="Property no., description, serial no., office, employee...">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label mb-0">Source</label>
                            <select name="search_source_type" class="form-select">
                                <option value="">All sources</option>
                                <option value="system" <?php echo $searchForm['source_type'] === 'system' ? 'selected' : ''; ?>>System</option>
                                <option value="legacy" <?php echo $searchForm['source_type'] === 'legacy' ? 'selected' : ''; ?>>Beginning Balance</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label mb-0">Item Type</label>
                            <select name="search_item_type" class="form-select">
                                <option value="">All types</option>
                                <option value="equipment" <?php echo $searchForm['item_type'] === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                <option value="semi_expendable" <?php echo $searchForm['item_type'] === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label mb-0">Current Office</label>
                            <select name="search_current_office_id" id="search_current_office_id" class="form-select" data-placeholder="All offices">
                                <option value="">All offices</option>
                                <?php foreach ($offices as $office): ?>
                                    <option value="<?php echo (int) $office['id']; ?>" <?php echo $searchForm['current_office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label mb-0">Current Employee</label>
                            <select name="search_current_employee_id" id="search_current_employee_id" class="form-select" data-placeholder="All employees">
                                <option value="">All employees</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo (int) $employee['id']; ?>" data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" <?php echo $searchForm['current_employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(transfer_name($employee) . ' - ' . ($employee['employee_no'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6 d-grid">
                            <button type="submit" class="btn btn-outline-primary">Load Results</button>
                        </div>
                    </div>
                </form>

                <div class="transfer-summary-grid mb-4">
                    <div class="transfer-summary-card">
                        <div class="text-muted small">Matched Assets</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format(count($searchPreviewAssets))); ?></div>
                    </div>
                    <div class="transfer-summary-card">
                        <div class="text-muted small">System Assets</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format($searchPreviewByType['system'])); ?></div>
                    </div>
                    <div class="transfer-summary-card">
                        <div class="text-muted small">Beginning Balance Assets</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format($searchPreviewByType['legacy'])); ?></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-xl-5">
                        <div class="transfer-panel">
                            <div class="transfer-panel-title">New Accountability for Selected Search Results</div>
                            <form method="post" id="searchTransferForm">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="search_transfer">
                                <input type="hidden" name="mode" value="search">
                                <input type="hidden" name="query" value="<?php echo h($searchForm['query']); ?>">
                                <input type="hidden" name="source_type" value="<?php echo h($searchForm['source_type']); ?>">
                                <input type="hidden" name="item_type" value="<?php echo h($searchForm['item_type']); ?>">
                                <input type="hidden" name="current_office_id" value="<?php echo h($searchForm['current_office_id']); ?>">
                                <input type="hidden" name="current_employee_id" value="<?php echo h($searchForm['current_employee_id']); ?>">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Transfer Date</label>
                                        <input type="date" name="transfer_date" class="form-control" value="<?php echo h($searchForm['transfer_date']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Receiving Office</label>
                                        <select name="to_office_id" id="search_to_office_id" class="form-select" data-placeholder="Select office" required>
                                            <option value="">Select office</option>
                                            <?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $searchForm['to_office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">New Accountable Employee</label>
                                        <select name="to_employee_id" id="search_to_employee_id" class="form-select" data-placeholder="Select employee">
                                            <option value="">Select employee</option>
                                            <?php foreach ($employees as $employee): ?>
                                                <option value="<?php echo (int) $employee['id']; ?>" data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>" <?php echo $searchForm['to_employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h(transfer_name($employee) . ' - ' . ($employee['employee_no'] ?? '') . (!empty($employee['position_title']) ? ' (' . $employee['position_title'] . ')' : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">New Responsibility Code</label>
                                        <select name="to_responsibility_code_id" id="search_to_responsibility_code_id" class="form-select" data-placeholder="Select responsibility code">
                                            <option value="">Select responsibility code</option>
                                            <?php foreach ($responsibilityCodes as $rc): ?>
                                                <option value="<?php echo (int) $rc['id']; ?>" data-office-id="<?php echo (int) ($rc['office_id'] ?? 0); ?>" <?php echo $searchForm['to_responsibility_code_id'] === (string) $rc['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h(($rc['code'] ?? '') . (!empty($rc['description']) ? ' - ' . $rc['description'] : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Reason</label>
                                        <input type="text" name="reason" class="form-control" value="<?php echo h($searchForm['reason']); ?>" placeholder="Example: targeted reassignment">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Remarks</label>
                                        <textarea name="remarks" class="form-control" rows="3"><?php echo h($searchForm['remarks']); ?></textarea>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end transfer-form-actions">
                                    <button type="submit" class="btn btn-primary" <?php echo $searchPreviewAssets ? '' : 'disabled'; ?>>Post Search Transfer</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="col-xl-7">
                        <div class="transfer-panel">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <div class="transfer-panel-title mb-0">Search Results Preview</div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" id="searchSelectAll" <?php echo $searchPreviewAssets ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="searchSelectAll">Select all</label>
                                    </div>
                                    <span class="badge text-bg-light"><span id="searchSelectedCount"><?php echo count($searchPreviewAssets); ?></span> selected</span>
                                </div>
                            </div>
                            <div class="small text-muted mb-3">Use this workspace for targeted transfers across legacy and system assets, without relying on office turnover filters.</div>
                            <div class="table-responsive mobile-table-frame">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th style="width:1%;"><input type="checkbox" class="form-check-input" id="searchSelectAllTable" <?php echo $searchPreviewAssets ? 'checked' : ''; ?>></th>
                                            <th>Property No.</th>
                                            <th>Asset</th>
                                            <th>Current Accountability</th>
                                            <th>Source</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($searchPreviewAssets): foreach (array_slice($searchPreviewAssets, 0, 150) as $asset): ?>
                                            <tr>
                                                <td>
                                                    <input
                                                        type="checkbox"
                                                        class="form-check-input search-asset-checkbox"
                                                        name="asset_keys[]"
                                                        value="<?php echo h((string) ($asset['asset_key'] ?? '')); ?>"
                                                        form="searchTransferForm"
                                                        checked
                                                    >
                                                </td>
                                                <td class="fw-semibold"><?php echo h($asset['property_number'] ?? ''); ?></td>
                                                <td>
                                                    <div><?php echo h($asset['item_description'] ?? ''); ?></div>
                                                    <div class="small text-muted"><?php echo h(trim((string) ($asset['classification_name'] ?? ''))); ?></div>
                                                </td>
                                                <td>
                                                    <div><?php echo h($asset['current_office_name'] ?? ''); ?></div>
                                                    <div class="small text-muted"><?php echo h(transfer_name($asset)); ?><?php echo !empty($asset['current_rc_code']) ? ' | ' . h($asset['current_rc_code']) : ''; ?></div>
                                                </td>
                                                <td><?php echo h(($asset['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System Transaction'); ?></td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">No assets matched the current search criteria.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (count($searchPreviewAssets) > 150): ?>
                                <div class="small text-muted mt-2">Showing first 150 of <?php echo count($searchPreviewAssets); ?> matched asset(s). Posting still applies only to the selected checked rows in the current result set.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">Recent Transfer Documents</h5>
                        <div class="small text-muted">Batch-level PTR and ITR history.</div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <form method="get" class="d-flex align-items-center gap-2">
                            <input type="hidden" name="mode" value="<?php echo h($transferMode); ?>">
                            <label class="small text-muted mb-0" for="document_history_status">Status</label>
                            <select name="document_history_status" id="document_history_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="posted" <?php echo $documentHistoryStatus === 'posted' ? 'selected' : ''; ?>>Posted</option>
                                <option value="cancelled" <?php echo $documentHistoryStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="all" <?php echo $documentHistoryStatus === 'all' ? 'selected' : ''; ?>>All</option>
                            </select>
                            <input type="hidden" name="transfer_history_status" value="<?php echo h($transferHistoryStatus); ?>">
                        </form>
                        <span class="badge text-bg-light"><?php echo count($transferBatches); ?> record(s)</span>
                    </div>
                </div>
                <div class="table-responsive mobile-table-frame">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Document No.</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Items</th>
                                <th>Reason</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transferBatches): foreach ($transferBatches as $batch): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo h($batch['system_reference']); ?></td>
                                    <td><?php echo h(!empty($batch['transfer_date']) ? date('M d, Y', strtotime((string) $batch['transfer_date'])) : ''); ?></td>
                                    <td><?php echo h(strtoupper((string) ($batch['document_type'] ?? ''))); ?></td>
                                    <td>
                                        <span class="badge <?php echo ($batch['status'] ?? '') === 'cancelled' ? 'text-bg-danger' : 'text-bg-success'; ?>">
                                            <?php echo h(strtoupper((string) ($batch['status'] ?? 'posted'))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo h($batch['from_office_name'] ?? ''); ?></div>
                                        <div class="small text-muted"><?php echo h(transfer_name($batch, 'from_')); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo h($batch['to_office_name'] ?? ''); ?></div>
                                        <div class="small text-muted"><?php echo h(transfer_name($batch, 'to_')); ?></div>
                                    </td>
                                    <td><?php echo h(number_format((int) ($batch['item_count'] ?? 0))); ?></td>
                                    <td><?php echo h($batch['reason'] ?? ''); ?></td>
                                    <td class="text-end">
                                        <a href="<?php echo base_url('modules/transfers/view.php?id=' . (int) ($batch['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                        <?php if (($batch['document_type'] ?? '') === 'itr'): ?>
                                            <a href="<?php echo base_url('modules/transfers/itr.php?batch_id=' . (int) ($batch['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Print ITR</a>
                                        <?php else: ?>
                                            <a href="<?php echo base_url('modules/transfers/ptr.php?batch_id=' . (int) ($batch['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Print PTR</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">No transfer documents found for the selected status.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">Recent Transfers</h5>
                        <div class="small text-muted">Item-level accountability movement log.</div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <form method="get" class="d-flex align-items-center gap-2">
                            <input type="hidden" name="mode" value="<?php echo h($transferMode); ?>">
                            <label class="small text-muted mb-0" for="transfer_history_status">Status</label>
                            <select name="transfer_history_status" id="transfer_history_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="posted" <?php echo $transferHistoryStatus === 'posted' ? 'selected' : ''; ?>>Posted</option>
                                <option value="cancelled" <?php echo $transferHistoryStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="all" <?php echo $transferHistoryStatus === 'all' ? 'selected' : ''; ?>>All</option>
                            </select>
                            <input type="hidden" name="document_history_status" value="<?php echo h($documentHistoryStatus); ?>">
                        </form>
                        <span class="badge text-bg-light"><?php echo count($transfers); ?> record(s)</span>
                    </div>
                </div>
                <div class="table-responsive mobile-table-frame">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Asset</th>
                                <th>Source</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Reason</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transfers): foreach ($transfers as $transfer): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo h($transfer['system_reference']); ?></td>
                                    <td><?php echo h(!empty($transfer['transfer_date']) ? date('M d, Y', strtotime((string) $transfer['transfer_date'])) : ''); ?></td>
                                    <td>
                                        <span class="badge <?php echo ($transfer['status'] ?? '') === 'cancelled' ? 'text-bg-danger' : 'text-bg-success'; ?>">
                                            <?php echo h(strtoupper((string) ($transfer['status'] ?? 'posted'))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo h($transfer['property_number'] ?? ''); ?></div>
                                        <div><?php echo h($transfer['item_description'] ?? ''); ?></div>
                                        <div class="small text-muted">
                                            <?php
                                            $transferTypeLabel = ($transfer['item_type'] ?? '') === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment';
                                            $transferClassLabel = trim((!empty($transfer['classification_family']) ? $transfer['classification_family'] . ' / ' : '') . ($transfer['classification_name'] ?? ''));
                                            $transferBrandModel = trim(trim((string) ($transfer['brand'] ?? '')) . ' ' . trim((string) ($transfer['model'] ?? '')));
                                            $transferMeta = array_filter([
                                                $transferTypeLabel,
                                                $transferClassLabel !== '' ? $transferClassLabel : null,
                                                $transferBrandModel !== '' ? $transferBrandModel : null,
                                                !empty($transfer['serial_no']) ? 'SN ' . $transfer['serial_no'] : null,
                                            ]);
                                            echo h(implode(' | ', $transferMeta));
                                            ?>
                                        </div>
                                    </td>
                                    <td><?php echo h(($transfer['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System Transaction'); ?></td>
                                    <td><div><?php echo h($transfer['from_office_name'] ?? ''); ?></div><div class="small text-muted"><?php echo h(transfer_name($transfer, 'from_')); ?><?php echo !empty($transfer['from_rc_code']) ? ' | ' . h($transfer['from_rc_code']) : ''; ?></div></td>
                                    <td><div><?php echo h($transfer['to_office_name'] ?? ''); ?></div><div class="small text-muted"><?php echo h(transfer_name($transfer, 'to_')); ?><?php echo !empty($transfer['to_rc_code']) ? ' | ' . h($transfer['to_rc_code']) : ''; ?></div></td>
                                    <td><?php echo h($transfer['reason'] ?? ''); ?></td>
                                    <td class="text-end">
                                        <?php if (($transfer['item_type'] ?? '') === 'semi_expendable'): ?>
                                            <a href="<?php echo base_url('modules/transfers/itr.php?id=' . (int) ($transfer['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Print ITR</a>
                                        <?php else: ?>
                                            <a href="<?php echo base_url('modules/transfers/ptr.php?id=' . (int) ($transfer['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Print PTR</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">No transfers found for the selected status.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var assetSelect = document.getElementById('asset_key');
    var assetFilterSource = document.getElementById('assetFilterSource');
    var assetFilterType = document.getElementById('assetFilterType');
    var assetFilterClear = document.getElementById('assetFilterClear');
    var filteredAssetCount = document.getElementById('filteredAssetCount');
    var officeSelect = document.getElementById('to_office_id');
    var employeeSelect = document.getElementById('to_employee_id');
    var rcSelect = document.getElementById('to_responsibility_code_id');
    var currentAssetName = document.getElementById('currentAssetName');
    var currentOfficeName = document.getElementById('currentOfficeName');
    var currentEmployeeName = document.getElementById('currentEmployeeName');
    var currentRcCode = document.getElementById('currentRcCode');
    var bulkSourceOfficeSelect = document.querySelector('select[name="source_office_id"]');
    var bulkSourceEmployeeSelect = document.querySelector('select[name="source_employee_id"]');
    var bulkOfficeSelect = document.getElementById('bulk_to_office_id');
    var bulkEmployeeSelect = document.getElementById('bulk_to_employee_id');
    var bulkRcSelect = document.getElementById('bulk_to_responsibility_code_id');
    var bulkAssetCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.bulk-asset-checkbox'));
    var bulkSelectAll = document.getElementById('bulkSelectAll');
    var bulkSelectAllTable = document.getElementById('bulkSelectAllTable');
    var bulkSelectedCount = document.getElementById('bulkSelectedCount');
    var searchOfficeFilterSelect = document.getElementById('search_current_office_id');
    var searchEmployeeFilterSelect = document.getElementById('search_current_employee_id');
    var searchOfficeSelect = document.getElementById('search_to_office_id');
    var searchEmployeeSelect = document.getElementById('search_to_employee_id');
    var searchRcSelect = document.getElementById('search_to_responsibility_code_id');
    var searchAssetCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.search-asset-checkbox'));
    var searchSelectAll = document.getElementById('searchSelectAll');
    var searchSelectAllTable = document.getElementById('searchSelectAllTable');
    var searchSelectedCount = document.getElementById('searchSelectedCount');
    function refreshSelect(select) { if (window.SPAMS && window.SPAMS.refreshSelect2) window.SPAMS.refreshSelect2(select); }
    function applyAssetFilter() {
        if (!assetSelect) return;
        var source = assetFilterSource?.value || '';
        var type = assetFilterType?.value || '';
        var visibleCount = 0;
        var currentVisible = false;

        Array.prototype.forEach.call(assetSelect.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            var optionSearch = (option.getAttribute('data-search') || '').toLowerCase();
            var optionSource = option.getAttribute('data-source') || '';
            var optionType = option.getAttribute('data-type') || '';
            var matches = (!source || optionSource === source) &&
                          (!type || optionType === type);
            option.hidden = !matches;
            if (matches) {
                visibleCount++;
                if (option.selected) currentVisible = true;
            }
        });

        if (filteredAssetCount) {
            filteredAssetCount.textContent = String(visibleCount);
        }
        if (!currentVisible && assetSelect.value) {
            assetSelect.value = '';
            updateCurrentCard();
        }
        refreshSelect(assetSelect);
    }
    function updateCurrentCard() {
        if (!assetSelect) return;
        var option = assetSelect.options[assetSelect.selectedIndex];
        if (!option || !option.value) {
            if (currentAssetName) currentAssetName.textContent = 'Select an asset';
            if (currentOfficeName) currentOfficeName.textContent = '—';
            if (currentEmployeeName) currentEmployeeName.textContent = '—';
            if (currentRcCode) currentRcCode.textContent = '—';
            return;
        }
        var label = option.textContent || '';
        if (currentAssetName) currentAssetName.textContent = label;
        var match = <?php echo json_encode(array_values(array_map(static function (array $asset): array {
            return [
                'asset_key' => (string) ($asset['asset_key'] ?? ''),
                'current_office_name' => (string) ($asset['current_office_name'] ?? ''),
                'current_employee_name' => transfer_name($asset),
                'current_rc_code' => (string) ($asset['current_rc_code'] ?? ''),
            ];
        }, $assets)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>.find(function (asset) { return asset.asset_key === option.value; });
        if (match) {
            if (currentOfficeName) currentOfficeName.textContent = match.current_office_name || '—';
            if (currentEmployeeName) currentEmployeeName.textContent = match.current_employee_name || '—';
            if (currentRcCode) currentRcCode.textContent = match.current_rc_code || '—';
        }
    }
    function findUnitHead(select, officeId) {
        if (!select) return null;
        return Array.prototype.find.call(select.options, function (option) {
            return option.value && option.getAttribute('data-office-id') === officeId && option.getAttribute('data-is-unit-head') === '1';
        }) || null;
    }
    function filterEmployeeOptions(select, officeId, autoSelect) {
        if (!select) return;
        var stillValid = false;
        Array.prototype.forEach.call(select.options, function (option) {
            if (!option.value) { option.hidden = false; return; }
            var matches = !officeId || option.getAttribute('data-office-id') === officeId;
            option.hidden = !matches;
            if (matches && option.value === select.value) stillValid = true;
        });
        if (!stillValid) select.value = '';
        if (autoSelect && officeId) {
            var unitHead = findUnitHead(select, officeId);
            if (unitHead) select.value = unitHead.value;
        }
        refreshSelect(select);
    }
    function filterRcOptions(select, officeId, autoSelect) {
        if (!select) return;
        var stillValid = false;
        var firstMatch = null;
        Array.prototype.forEach.call(select.options, function (option) {
            if (!option.value) { option.hidden = false; return; }
            var matches = !officeId || option.getAttribute('data-office-id') === officeId;
            option.hidden = !matches;
            if (!firstMatch && matches) firstMatch = option;
            if (matches && option.value === select.value) stillValid = true;
        });
        if (!stillValid) select.value = '';
        if (autoSelect && officeId && firstMatch) select.value = firstMatch.value;
        refreshSelect(select);
    }
    if (officeSelect) {
        officeSelect.addEventListener('change', function () { filterEmployeeOptions(employeeSelect, officeSelect.value, true); filterRcOptions(rcSelect, officeSelect.value, true); });
        if (window.jQuery) window.jQuery(officeSelect).on('select2:select select2:clear', function () { filterEmployeeOptions(employeeSelect, officeSelect.value, true); filterRcOptions(rcSelect, officeSelect.value, true); });
        filterEmployeeOptions(employeeSelect, officeSelect.value, true);
        filterRcOptions(rcSelect, officeSelect.value, true);
    }
    if (bulkSourceOfficeSelect && bulkSourceEmployeeSelect) {
        bulkSourceOfficeSelect.addEventListener('change', function () { filterEmployeeOptions(bulkSourceEmployeeSelect, bulkSourceOfficeSelect.value, false); });
        if (window.jQuery) window.jQuery(bulkSourceOfficeSelect).on('select2:select select2:clear', function () { filterEmployeeOptions(bulkSourceEmployeeSelect, bulkSourceOfficeSelect.value, false); });
        filterEmployeeOptions(bulkSourceEmployeeSelect, bulkSourceOfficeSelect.value, false);
    }
    if (bulkOfficeSelect) {
        bulkOfficeSelect.addEventListener('change', function () { filterEmployeeOptions(bulkEmployeeSelect, bulkOfficeSelect.value, true); filterRcOptions(bulkRcSelect, bulkOfficeSelect.value, true); });
        if (window.jQuery) window.jQuery(bulkOfficeSelect).on('select2:select select2:clear', function () { filterEmployeeOptions(bulkEmployeeSelect, bulkOfficeSelect.value, true); filterRcOptions(bulkRcSelect, bulkOfficeSelect.value, true); });
        filterEmployeeOptions(bulkEmployeeSelect, bulkOfficeSelect.value, true);
        filterRcOptions(bulkRcSelect, bulkOfficeSelect.value, true);
    }
    if (searchOfficeFilterSelect && searchEmployeeFilterSelect) {
        searchOfficeFilterSelect.addEventListener('change', function () { filterEmployeeOptions(searchEmployeeFilterSelect, searchOfficeFilterSelect.value, false); });
        if (window.jQuery) window.jQuery(searchOfficeFilterSelect).on('select2:select select2:clear', function () { filterEmployeeOptions(searchEmployeeFilterSelect, searchOfficeFilterSelect.value, false); });
        filterEmployeeOptions(searchEmployeeFilterSelect, searchOfficeFilterSelect.value, false);
    }
    if (searchOfficeSelect) {
        searchOfficeSelect.addEventListener('change', function () { filterEmployeeOptions(searchEmployeeSelect, searchOfficeSelect.value, true); filterRcOptions(searchRcSelect, searchOfficeSelect.value, true); });
        if (window.jQuery) window.jQuery(searchOfficeSelect).on('select2:select select2:clear', function () { filterEmployeeOptions(searchEmployeeSelect, searchOfficeSelect.value, true); filterRcOptions(searchRcSelect, searchOfficeSelect.value, true); });
        filterEmployeeOptions(searchEmployeeSelect, searchOfficeSelect.value, true);
        filterRcOptions(searchRcSelect, searchOfficeSelect.value, true);
    }
    if (assetFilterSource) assetFilterSource.addEventListener('change', applyAssetFilter);
    if (assetFilterType) assetFilterType.addEventListener('change', applyAssetFilter);
    if (assetFilterClear) {
        assetFilterClear.addEventListener('click', function () {
            if (assetFilterSource) assetFilterSource.value = '';
            if (assetFilterType) assetFilterType.value = '';
            applyAssetFilter();
        });
    }
    if (assetSelect) {
        assetSelect.addEventListener('change', updateCurrentCard);
        applyAssetFilter();
        updateCurrentCard();
    }
    function setBulkSelectionState(checked) {
        bulkAssetCheckboxes.forEach(function (checkbox) { checkbox.checked = checked; });
        updateBulkSelectionState();
    }
    function updateBulkSelectionState() {
        if (!bulkAssetCheckboxes.length) {
            if (bulkSelectedCount) bulkSelectedCount.textContent = '0';
            if (bulkSelectAll) bulkSelectAll.checked = false;
            if (bulkSelectAllTable) bulkSelectAllTable.checked = false;
            return;
        }
        var checkedCount = bulkAssetCheckboxes.filter(function (checkbox) { return checkbox.checked; }).length;
        var allChecked = checkedCount === bulkAssetCheckboxes.length;
        if (bulkSelectedCount) bulkSelectedCount.textContent = String(checkedCount);
        if (bulkSelectAll) bulkSelectAll.checked = allChecked;
        if (bulkSelectAllTable) bulkSelectAllTable.checked = allChecked;
    }
    if (bulkSelectAll) {
        bulkSelectAll.addEventListener('change', function () { setBulkSelectionState(bulkSelectAll.checked); });
    }
    if (bulkSelectAllTable) {
        bulkSelectAllTable.addEventListener('change', function () { setBulkSelectionState(bulkSelectAllTable.checked); });
    }
    bulkAssetCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateBulkSelectionState);
    });
    updateBulkSelectionState();

    function setSearchSelectionState(checked) {
        searchAssetCheckboxes.forEach(function (checkbox) { checkbox.checked = checked; });
        updateSearchSelectionState();
    }
    function updateSearchSelectionState() {
        if (!searchAssetCheckboxes.length) {
            if (searchSelectedCount) searchSelectedCount.textContent = '0';
            if (searchSelectAll) searchSelectAll.checked = false;
            if (searchSelectAllTable) searchSelectAllTable.checked = false;
            return;
        }
        var checkedCount = searchAssetCheckboxes.filter(function (checkbox) { return checkbox.checked; }).length;
        var allChecked = checkedCount === searchAssetCheckboxes.length;
        if (searchSelectedCount) searchSelectedCount.textContent = String(checkedCount);
        if (searchSelectAll) searchSelectAll.checked = allChecked;
        if (searchSelectAllTable) searchSelectAllTable.checked = allChecked;
    }
    if (searchSelectAll) {
        searchSelectAll.addEventListener('change', function () { setSearchSelectionState(searchSelectAll.checked); });
    }
    if (searchSelectAllTable) {
        searchSelectAllTable.addEventListener('change', function () { setSearchSelectionState(searchSelectAllTable.checked); });
    }
    searchAssetCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSearchSelectionState);
    });
    updateSearchSelectionState();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
