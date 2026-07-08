<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

function disposal_asset_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));

    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

$db = db();
$page_title = 'Disposals';
$flash = get_flash();
$errors = [];
$available = [];
$rows = [];
$employees = [];
$typeFilter = trim((string) ($_GET['item_type'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));
$preselectedDetailId = (int) ($_GET['detail_id'] ?? 0);
$preselectedLegacyAssetId = (int) ($_GET['legacy_asset_id'] ?? 0);
$preselectedSourceType = trim((string) ($_GET['source'] ?? ''));
$form = [
    'source_type' => 'system',
    'return_id' => '',
    'return_ids' => [],
    'legacy_asset_id' => '',
    'disposal_date' => date('Y-m-d'),
    'reason' => 'unserviceable',
    'approved_by' => '',
    'remarks' => '',
];
$reasonOptions = coa_disposal_reason_options();

if (!in_array($typeFilter, ['all', 'semi_expendable', 'equipment'], true)) {
    $typeFilter = 'all';
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    if (!schema_has_column($db, 'disposals', 'source_type')) {
        $errors[] = 'Database schema is outdated: disposals.source_type is missing. Apply latest migrations before continuing.';
    }
    if (!schema_has_column($db, 'disposals', 'legacy_asset_id')) {
        $errors[] = 'Database schema is outdated: disposals.legacy_asset_id is missing. Apply latest migrations before continuing.';
    }

    $employeeResult = $db->query("SELECT id, first_name, middle_name, last_name, suffix_name FROM employees WHERE is_active = 1 ORDER BY last_name ASC, first_name ASC");
    if ($employeeResult) {
        $employees = $employeeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }

        $form['source_type'] = trim((string) ($_POST['source_type'] ?? 'system'));
        $form['return_id'] = trim((string) ($_POST['return_id'] ?? ''));
        $postedReturnIds = $_POST['return_ids'] ?? [];
        if (!is_array($postedReturnIds)) {
            $postedReturnIds = [];
        }
        $form['return_ids'] = array_values(array_unique(array_filter(array_map(static function ($value): int {
            return (int) $value;
        }, $postedReturnIds), static function (int $value): bool {
            return $value > 0;
        })));
        $form['legacy_asset_id'] = trim((string) ($_POST['legacy_asset_id'] ?? ''));
        $form['disposal_date'] = trim((string) ($_POST['disposal_date'] ?? date('Y-m-d')));
        $form['reason'] = normalize_disposal_reason((string) ($_POST['reason'] ?? 'unserviceable'));
        $form['approved_by'] = trim((string) ($_POST['approved_by'] ?? ''));
        $form['remarks'] = trim((string) ($_POST['remarks'] ?? ''));

        if (!in_array($form['source_type'], ['system', 'legacy'], true)) {
            $form['source_type'] = 'system';
        }

        $sourceType = $form['source_type'];
        $returnId = (int) ($form['return_id'] !== '' ? $form['return_id'] : 0);
        if ($returnId > 0 && !$form['return_ids']) {
            $form['return_ids'] = [$returnId];
        }
        $returnIds = $form['return_ids'];
        $legacyAssetId = (int) ($form['legacy_asset_id'] !== '' ? $form['legacy_asset_id'] : 0);
        $approvedBy = (int) ($form['approved_by'] !== '' ? $form['approved_by'] : 0);

        if ($sourceType === 'legacy') {
            if ($legacyAssetId <= 0) {
                $errors[] = 'Select a legacy asset to dispose.';
            }
        } elseif (!$returnIds) {
            $errors[] = 'Select at least one returned asset to dispose.';
        } elseif (count($returnIds) > 100) {
            $errors[] = 'You can post up to 100 returned assets per disposal transaction.';
        }
        if ($form['disposal_date'] === '') {
            $errors[] = 'Disposal date is required.';
        } elseif (!is_valid_date_string($form['disposal_date'])) {
            $errors[] = 'Disposal date format is invalid.';
        }
        if ($form['reason'] === '') {
            $errors[] = 'Disposal reason is required.';
        } elseif (!array_key_exists($form['reason'], $reasonOptions)) {
            $errors[] = 'Select a valid disposal reason based on COA disposal rules.';
        }
        if ($approvedBy > 0) {
            $approverExists = false;
            foreach ($employees as $employeeRow) {
                if ((int) ($employeeRow['id'] ?? 0) === $approvedBy) {
                    $approverExists = true;
                    break;
                }
            }
            if (!$approverExists) {
                $errors[] = 'Selected approver is invalid.';
            }
        }

        $asset = null;
        $systemAssets = [];
        if (!$errors) {
            if ($sourceType === 'legacy') {
                $assetStmt = $db->prepare("SELECT id, item_type FROM legacy_assets WHERE id = ? AND is_active = 1 LIMIT 1");
                if ($assetStmt) {
                    $assetStmt->bind_param('i', $legacyAssetId);
                    $assetStmt->execute();
                    $asset = $assetStmt->get_result()->fetch_assoc() ?: null;
                    $assetStmt->close();
                }

                if (!$asset) {
                    $errors[] = 'The selected legacy asset could not be found.';
                } else {
                    $dupStmt = $db->prepare("SELECT id FROM disposals WHERE source_type = 'legacy' AND legacy_asset_id = ? AND status = 'posted' LIMIT 1");
                    if ($dupStmt) {
                        $dupStmt->bind_param('i', $legacyAssetId);
                        $dupStmt->execute();
                        $existing = $dupStmt->get_result()->fetch_assoc();
                        $dupStmt->close();
                        if ($existing) {
                            $errors[] = 'A posted disposal already exists for the selected legacy asset.';
                        }
                    }
                }
            } else {
                $assetStmt = $db->prepare(" 
                    SELECT
                        rt.id AS return_id,
                        rt.return_date,
                        did.id,
                        did.property_number,
                        did.serial_no,
                        did.is_distributed,
                        did.is_disposed,
                        COALESCE(poi.item_type, si.item_type) AS item_type,
                        COALESCE(poi.item_description, si.item_description) AS item_description
                    FROM returns rt
                    INNER JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
                    INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                    LEFT JOIN issuance_items ii ON ii.id = di.issuance_item_id
                    LEFT JOIN stock_items si ON si.id = ii.stock_item_id
                    LEFT JOIN receiving_items ri ON ri.id = COALESCE(di.receiving_item_id, si.receiving_item_id)
                    LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                    WHERE rt.id = ?
                      AND rt.status = 'posted'
                    LIMIT 1
                ");

                $dupStmt = $db->prepare("SELECT id FROM disposals WHERE source_type = 'system' AND distribution_item_detail_id = ? AND status = 'posted' LIMIT 1");

                if (!$assetStmt || !$dupStmt) {
                    $errors[] = 'Unable to prepare disposal validation.';
                } else {
                    foreach ($returnIds as $selectedReturnId) {
                        $assetStmt->bind_param('i', $selectedReturnId);
                        $assetStmt->execute();
                        $assetRow = $assetStmt->get_result()->fetch_assoc() ?: null;

                        if (!$assetRow) {
                            $errors[] = 'Returned asset #' . $selectedReturnId . ' could not be found.';
                            continue;
                        }

                        if ((int) ($assetRow['is_disposed'] ?? 0) === 1) {
                            $errors[] = 'Asset ' . ((string) ($assetRow['property_number'] ?? ('#' . $selectedReturnId))) . ' is already marked as disposed.';
                            continue;
                        }

                        if ((int) ($assetRow['is_distributed'] ?? 0) !== 0) {
                            $errors[] = 'Asset ' . ((string) ($assetRow['property_number'] ?? ('#' . $selectedReturnId))) . ' is not yet returned to Supply Office.';
                            continue;
                        }

                        $detailId = (int) ($assetRow['id'] ?? 0);
                        $dupStmt->bind_param('i', $detailId);
                        $dupStmt->execute();
                        $existing = $dupStmt->get_result()->fetch_assoc();
                        if ($existing) {
                            $errors[] = 'A posted disposal already exists for asset ' . ((string) ($assetRow['property_number'] ?? ('#' . $selectedReturnId))) . '.';
                            continue;
                        }

                        $systemAssets[] = $assetRow;
                    }

                    $assetStmt->close();
                    $dupStmt->close();
                }

                if (!$errors && !$systemAssets) {
                    $errors[] = 'No valid returned assets were selected for disposal.';
                }
            }
        }

        if (!$errors && ($asset || $systemAssets)) {
            $db->begin_transaction();
            try {
                $userId = current_user_id();
                $postedCount = 0;

                $ins = $db->prepare("
                    INSERT INTO disposals (
                        system_reference,
                        source_type,
                        disposal_date,
                        distribution_item_detail_id,
                        legacy_asset_id,
                        disposal_type,
                        reason,
                        approved_by,
                        remarks,
                        status,
                        created_by
                    ) VALUES (?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, NULLIF(?, 0), ?, 'posted', ?)
                ");
                if (!$ins) {
                    throw new RuntimeException('Unable to prepare the disposal insert statement.');
                }

                $legacyUpd = null;
                $systemUpd = null;
                if ($sourceType === 'legacy') {
                    $legacyUpd = $db->prepare("UPDATE legacy_assets SET is_active = 0, condition_status = 'unserviceable' WHERE id = ?");
                    if (!$legacyUpd) {
                        throw new RuntimeException('Unable to update legacy asset disposal state.');
                    }
                } else {
                    $systemUpd = $db->prepare(" 
                        UPDATE distribution_item_details
                        SET
                            is_disposed = 1,
                            is_distributed = 0
                        WHERE id = ?
                    ");
                    if (!$systemUpd) {
                        throw new RuntimeException('Unable to update the asset disposal state.');
                    }
                }

                $targets = $sourceType === 'legacy' ? [$asset] : $systemAssets;
                foreach ($targets as $targetAsset) {
                    $systemRef = next_module_code($db, 'disposals');
                    if ($systemRef === '') {
                        throw new RuntimeException('Unable to generate disposal reference number.');
                    }
                    $disposalType = ($targetAsset['item_type'] ?? '') === 'semi_expendable' ? 'semi_expendable' : 'equipment';
                    $detailIdToSave = $sourceType === 'system' ? (int) ($targetAsset['id'] ?? 0) : 0;
                    $legacyIdToSave = $sourceType === 'legacy' ? (int) $legacyAssetId : 0;

                    $ins->bind_param('sssiissisi', $systemRef, $sourceType, $form['disposal_date'], $detailIdToSave, $legacyIdToSave, $disposalType, $form['reason'], $approvedBy, $form['remarks'], $userId);
                    $ins->execute();

                    if ($sourceType === 'legacy' && $legacyUpd) {
                        $legacyUpd->bind_param('i', $legacyAssetId);
                        $legacyUpd->execute();
                    }

                    if ($sourceType === 'system' && $systemUpd) {
                        $systemUpd->bind_param('i', $detailIdToSave);
                        $systemUpd->execute();
                    }

                    $disposalId = (int) $db->insert_id;
                    write_audit_log($db, [
                        'action' => 'insert',
                        'table_name' => 'disposals',
                        'record_id' => $disposalId,
                        'module_name' => 'disposals',
                        'record_type' => 'disposal',
                        'action_name' => 'post_disposal',
                        'new_values' => [
                            'system_reference' => $systemRef,
                            'source_type' => $sourceType,
                            'disposal_date' => $form['disposal_date'],
                            'distribution_item_detail_id' => $detailIdToSave,
                            'legacy_asset_id' => $legacyIdToSave,
                            'disposal_type' => $disposalType,
                            'reason' => $form['reason'],
                            'approved_by' => $approvedBy,
                        ],
                        'description' => 'Posted asset disposal.',
                    ]);

                    $postedCount++;
                }

                $ins->close();
                if ($legacyUpd) {
                    $legacyUpd->close();
                }
                if ($systemUpd) {
                    $systemUpd->close();
                }

                $db->commit();
                set_flash('success', $postedCount > 1
                    ? ('Disposals recorded successfully for ' . number_format($postedCount) . ' assets.')
                    : 'Disposal recorded successfully.');
                redirect('modules/disposals/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to record the disposal.';
                if (in_array((string) ($_SESSION['user_role'] ?? ''), ['Administrator'], true)) {
                    $errors[] = 'Technical detail: ' . $e->getMessage();
                }
            }
        }
    }

    $availableSql = "
        SELECT
            rt.id AS return_id,
            rt.system_reference AS return_reference,
            rt.return_date,
            did.id,
            did.property_number,
            did.brand,
            did.model,
            did.serial_no,
            COALESCE(poi.item_type, si.item_type) AS item_type,
            COALESCE(poi.item_description, si.item_description) AS item_description,
            COALESCE(c.classification_name, sc.classification_name) AS classification_name,
            COALESCE(c.classification_family, sc.classification_family) AS classification_family,
            d.document_no,
            d.document_type,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name
        FROM returns rt
        INNER JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
        LEFT JOIN issuance_items ii ON ii.id = di.issuance_item_id
        LEFT JOIN stock_items si ON si.id = ii.stock_item_id
        LEFT JOIN receiving_items ri ON ri.id = COALESCE(di.receiving_item_id, si.receiving_item_id)
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN classifications sc ON sc.id = si.classification_id
        LEFT JOIN offices o ON o.id = COALESCE(rt.office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(rt.employee_id, d.employee_id)
        WHERE rt.status = 'posted'
          AND did.is_distributed = 0
          AND (did.is_disposed IS NULL OR did.is_disposed = 0)
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
            rt.system_reference LIKE CONCAT('%', ?, '%')
            OR did.property_number LIKE CONCAT('%', ?, '%')
            OR did.serial_no LIKE CONCAT('%', ?, '%')
            OR COALESCE(poi.item_description, si.item_description) LIKE CONCAT('%', ?, '%')
            OR did.brand LIKE CONCAT('%', ?, '%')
            OR did.model LIKE CONCAT('%', ?, '%')
            OR o.office_name LIKE CONCAT('%', ?, '%')
        )";
        $types .= 'sssssss';
        array_push($params, $search, $search, $search, $search, $search, $search, $search);
    }
    $availableSql .= " ORDER BY rt.return_date DESC, COALESCE(poi.item_type, si.item_type) ASC, COALESCE(poi.item_description, si.item_description) ASC, did.property_number ASC, did.serial_no ASC";

    $availableStmt = $db->prepare($availableSql);
    if ($availableStmt) {
        if ($params) {
            $availableStmt->bind_param($types, ...$params);
        }
        $availableStmt->execute();
        $available = $availableStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $availableStmt->close();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $preselectedDetailId > 0) {
        foreach ($available as $assetRow) {
            if ((int) ($assetRow['id'] ?? 0) === $preselectedDetailId) {
                $form['source_type'] = 'system';
                $form['return_id'] = (string) ($assetRow['return_id'] ?? '');
                $form['return_ids'] = [(int) ($assetRow['return_id'] ?? 0)];
                break;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $preselectedLegacyAssetId > 0 && $preselectedSourceType === 'legacy') {
        $legacyAssetStmt = $db->prepare("SELECT id FROM legacy_assets WHERE id = ? AND is_active = 1 LIMIT 1");
        if ($legacyAssetStmt) {
            $legacyAssetStmt->bind_param('i', $preselectedLegacyAssetId);
            $legacyAssetStmt->execute();
            $legacyAsset = $legacyAssetStmt->get_result()->fetch_assoc();
            $legacyAssetStmt->close();
            if ($legacyAsset) {
                $form['source_type'] = 'legacy';
                $form['legacy_asset_id'] = (string) $preselectedLegacyAssetId;
            }
        }
    }

    $rowsSql = "
        SELECT
            dp.id,
            dp.system_reference,
            dp.source_type,
            dp.disposal_date,
            dp.disposal_type,
            dp.reason,
            dp.remarks,
            COALESCE(did.property_number, la.property_number) AS property_number,
            COALESCE(did.serial_no, la.serial_no) AS serial_no,
            COALESCE(poi.item_type, la.item_type) AS item_type,
            COALESCE(poi.item_description, la.item_description) AS item_description,
            COALESCE(c.classification_name, lc.classification_name) AS classification_name,
            COALESCE(c.classification_family, lc.classification_family) AS classification_family,
            COALESCE(d.document_no, 'Beginning Balance') AS document_no,
            COALESCE(d.document_type, 'legacy') AS document_type,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name,
            ap.first_name AS approved_first_name,
            ap.middle_name AS approved_middle_name,
            ap.last_name AS approved_last_name,
            ap.suffix_name AS approved_suffix_name
        FROM disposals dp
        LEFT JOIN distribution_item_details did ON did.id = dp.distribution_item_detail_id
        LEFT JOIN legacy_assets la ON la.id = dp.legacy_asset_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN distributions d ON d.id = di.distribution_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN classifications lc ON lc.id = la.classification_id
        LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
        LEFT JOIN employees ap ON ap.id = dp.approved_by
        ORDER BY dp.disposal_date DESC, dp.id DESC
    ";
    $rowsResult = $db->query($rowsSql);
    if ($rowsResult) {
        $rows = $rowsResult->fetch_all(MYSQLI_ASSOC);
    }
}

$availableCount = count($available);
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
                            <h5 class="report-toolbar-title mb-0">Disposals</h5>
                            <p class="report-toolbar-copy">Select items already returned and received back by the Supply Office, then record which returned assets will proceed to final disposal.</p>
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
                            <div class="report-summary-note">Returned items in Supply Office still open for disposal posting.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Semi-Expendable</div>
                            <div class="report-summary-value"><?php echo number_format($semiAvailable); ?></div>
                            <div class="report-summary-note">Returned semi assets available for disposal.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Equipment</div>
                            <div class="report-summary-value"><?php echo number_format($equipmentAvailable); ?></div>
                            <div class="report-summary-note">Returned equipment assets available for disposal.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Recent Records</div>
                            <div class="report-summary-value"><?php echo number_format($recentCount); ?></div>
                            <div class="report-summary-note">Recorded disposal transactions in the module list.</div>
                        </div>
                    </div>

                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Find Returned Items For Disposal</h6>
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Inventory Type</label>
                                <select name="item_type" class="form-select">
                                    <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All returned assets</option>
                                    <option value="semi_expendable" <?php echo $typeFilter === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                                    <option value="equipment" <?php echo $typeFilter === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                </select>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">Search</label>
                                <input type="text" name="q" class="form-control" value="<?php echo h($search); ?>" placeholder="Return ref, property no., serial no., description, brand, model, or office">
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100">Apply</button>
                                <a href="<?php echo base_url('modules/disposals/index.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>

                    <div class="report-filter-card">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-2">
                            <div>
                                <h6 class="report-filter-title mb-1">Post Disposal (3 Steps)</h6>
                                <div class="text-muted small">Step 1: Choose source. Step 2: Select asset. Step 3: Complete disposal details and post.</div>
                            </div>
                        </div>
                        <form method="post" class="row g-3 align-items-end" id="disposalForm">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <div class="col-md-3">
                                <label class="form-label">Asset Source</label>
                                <select name="source_type" class="form-select" id="sourceTypeSelect">
                                    <option value="system" <?php echo $form['source_type'] === 'system' ? 'selected' : ''; ?>>Returned System Asset</option>
                                    <option value="legacy" <?php echo $form['source_type'] === 'legacy' ? 'selected' : ''; ?>>Legacy Asset (Beginning Balance)</option>
                                </select>
                                <div class="form-text">Pick where the asset record came from.</div>
                            </div>

                            <div class="col-md-9" id="systemAssetBlock">
                                <label class="form-label">Step 2: Returned Asset</label>
                                <select name="return_ids[]" id="returnIdSelect" class="form-select" multiple size="8">
                                    <option value="">Select returned asset</option>
                                    <?php foreach ($available as $asset): ?>
                                        <?php $assetLabel = disposal_asset_label($asset); ?>
                                        <option
                                            value="<?php echo (int) $asset['return_id']; ?>"
                                            data-return-ref="<?php echo h((string) ($asset['return_reference'] ?? '')); ?>"
                                            data-property="<?php echo h((string) ($asset['property_number'] ?? '')); ?>"
                                            data-serial="<?php echo h((string) ($asset['serial_no'] ?? '')); ?>"
                                            data-type="<?php echo h((string) ($asset['item_type'] ?? '')); ?>"
                                            data-label="<?php echo h($assetLabel); ?>"
                                            data-office="<?php echo h((string) ($asset['office_name'] ?? '')); ?>"
                                            data-return-date="<?php echo h(!empty($asset['return_date']) ? date('M d, Y', strtotime((string) $asset['return_date'])) : ''); ?>"
                                            <?php echo in_array((int) $asset['return_id'], $form['return_ids'], true) ? 'selected' : ''; ?>
                                        >
                                            <?php
                                            echo h(trim(implode(' | ', array_filter([
                                                $asset['return_reference'] ?? '',
                                                $asset['property_number'] ?? '',
                                                $assetLabel,
                                            ]))));
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Choose one or more returned assets to dispose. Use Ctrl/Command + click for multiple selection.</div>
                                <div id="selectedAssetPreview" class="border rounded-2 p-2 mt-2 bg-light-subtle small text-muted">No asset selected yet.</div>
                            </div>

                            <div class="col-md-9" id="legacyAssetBlock">
                                <label class="form-label">Step 2: Legacy Asset</label>
                                <?php if ($form['legacy_asset_id'] !== ''): ?>
                                    <input type="hidden" name="legacy_asset_id" value="<?php echo h($form['legacy_asset_id']); ?>">
                                    <input type="text" class="form-control" value="Legacy asset #<?php echo h($form['legacy_asset_id']); ?> selected from Asset Details" readonly>
                                <?php else: ?>
                                    <input type="number" name="legacy_asset_id" min="1" class="form-control" value="<?php echo h($form['legacy_asset_id']); ?>" placeholder="Enter legacy asset ID">
                                <?php endif; ?>
                                <div class="form-text">You can open Asset Details first, then click Dispose to auto-fill this field.</div>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Step 3: Disposal Date</label>
                                <input type="date" name="disposal_date" class="form-control" value="<?php echo h($form['disposal_date']); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Reason</label>
                                <select name="reason" class="form-select">
                                    <?php foreach ($reasonOptions as $reasonValue => $reasonLabel): ?>
                                        <option value="<?php echo h($reasonValue); ?>" <?php echo $form['reason'] === $reasonValue ? 'selected' : ''; ?>><?php echo h($reasonLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Approved By</label>
                                <select name="approved_by" class="form-select">
                                    <option value="">Select approver</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?php echo (int) $employee['id']; ?>" <?php echo $form['approved_by'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                            <?php echo h(employee_display_name($employee)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-10">
                                <label class="form-label">Remarks</label>
                                <input type="text" name="remarks" class="form-control" value="<?php echo h($form['remarks']); ?>" placeholder="Optional disposal notes or basis">
                            </div>
                            <div class="col-md-2 d-grid">
                                <button class="btn btn-danger">Post Disposal</button>
                            </div>
                        </form>
                    </div>

                    <div class="report-table-card table-responsive mobile-table-frame">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th data-sort="ref">Reference <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                    <th data-sort="date">Date <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                    <th data-sort="asset">Asset <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                    <th data-sort="doc">Document <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                    <th data-sort="reason">Reason <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                    <th data-sort="approved">Approved By <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo h($row['system_reference']); ?></td>
                                            <td><?php echo h(!empty($row['disposal_date']) ? date('M d, Y', strtotime((string) $row['disposal_date'])) : ''); ?></td>
                                            <td>
                                                <div class="fw-semibold d-flex align-items-center gap-2 flex-wrap">
                                                    <span><?php echo h($row['property_number'] ?? ''); ?></span>
                                                    <?php if (($row['source_type'] ?? 'system') === 'legacy'): ?>
                                                        <span class="badge text-bg-secondary">Legacy</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-success">System</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div><?php echo h(disposal_asset_label($row)); ?></div>
                                                <?php if (!empty($row['serial_no'])): ?><div class="small text-muted"><?php echo h($row['serial_no']); ?></div><?php endif; ?>
                                            </td>
                                            <td><?php echo h(trim(implode(' / ', array_filter([$row['document_type'] ?? '', $row['document_no'] ?? ''])))); ?></td>
                                            <td>
                                                <div class="fw-semibold text-capitalize"><?php echo h((string) ($row['disposal_type'] ?? '')); ?></div>
                                                <div class="small"><?php echo h(disposal_reason_label($row['reason'] ?? '')); ?></div>
                                                <?php if (!empty($row['remarks'])): ?><div class="small text-muted"><?php echo h((string) $row['remarks']); ?></div><?php endif; ?>
                                            </td>
                                            <td><?php echo h(employee_display_name([
                                                'first_name' => $row['approved_first_name'] ?? '',
                                                'middle_name' => $row['approved_middle_name'] ?? '',
                                                'last_name' => $row['approved_last_name'] ?? '',
                                                'suffix_name' => $row['approved_suffix_name'] ?? '',
                                            ])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No disposal records yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sourceType = document.getElementById('sourceTypeSelect');
    var systemBlock = document.getElementById('systemAssetBlock');
    var legacyBlock = document.getElementById('legacyAssetBlock');
    var returnSelect = document.getElementById('returnIdSelect');
    var preview = document.getElementById('selectedAssetPreview');

    function setBlockVisibility() {
        var useLegacy = sourceType && sourceType.value === 'legacy';
        if (systemBlock) {
            systemBlock.style.display = useLegacy ? 'none' : '';
        }
        if (legacyBlock) {
            legacyBlock.style.display = useLegacy ? '' : 'none';
        }
        if (returnSelect) {
            returnSelect.required = !useLegacy;
        }
    }

    function updatePreview() {
        if (!preview || !returnSelect) {
            return;
        }

        var selectedOptions = Array.prototype.filter.call(returnSelect.options, function (opt) {
            return opt.selected && !!opt.value;
        });

        if (!selectedOptions.length) {
            preview.textContent = 'No asset selected yet.';
            return;
        }

        if (selectedOptions.length === 1) {
            var option = selectedOptions[0];
            var parts = [];
            var ref = option.getAttribute('data-return-ref') || '';
            var propertyNo = option.getAttribute('data-property') || '';
            var serialNo = option.getAttribute('data-serial') || '';
            var type = option.getAttribute('data-type') || '';
            var label = option.getAttribute('data-label') || '';
            var office = option.getAttribute('data-office') || '';
            var returnDate = option.getAttribute('data-return-date') || '';

            if (ref) parts.push('Return Ref: ' + ref);
            if (propertyNo) parts.push('Property No: ' + propertyNo);
            if (serialNo) parts.push('Serial: ' + serialNo);
            if (type) parts.push('Type: ' + type.replace('_', ' '));
            if (label) parts.push('Asset: ' + label);
            if (office) parts.push('Office: ' + office);
            if (returnDate) parts.push('Returned: ' + returnDate);

            preview.textContent = parts.join(' | ');
            return;
        }

        var previewLines = selectedOptions.slice(0, 4).map(function (option) {
            var propertyNo = option.getAttribute('data-property') || '';
            var label = option.getAttribute('data-label') || '';
            var ref = option.getAttribute('data-return-ref') || '';
            return [ref, propertyNo, label].filter(Boolean).join(' | ');
        });

        var text = selectedOptions.length + ' assets selected.';
        if (previewLines.length) {
            text += ' Preview: ' + previewLines.join(' || ');
        }
        if (selectedOptions.length > 4) {
            text += ' || +' + (selectedOptions.length - 4) + ' more';
        }
        preview.textContent = text;
    }

    if (sourceType) {
        sourceType.addEventListener('change', setBlockVisibility);
    }
    if (returnSelect) {
        returnSelect.addEventListener('change', updatePreview);
    }

    setBlockVisibility();
    updatePreview();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
