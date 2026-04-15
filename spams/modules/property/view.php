<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/roles.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

$db = db();
$page_title = 'Asset Details';
$flash = get_flash();
$source = trim((string) ($_GET['source'] ?? ''));
$id = (int) ($_GET['id'] ?? 0);
$asset = null;
$notices = [];
$assetPhotos = [];
$timeline = [];
$transfers = [];
$maintenanceRows = [];
$returnRows = [];
$disposalRows = [];
$brandOptions = [];
$modelOptions = [];
$accountCodeOptions = [];
$fundOptions = [];

if (!in_array($source, ['system', 'legacy'], true) || $id <= 0) {
    http_response_code(404);
    exit('Asset not found.');
}

function asset_view_person(array $row, string $prefix = ''): string
{
    if ($prefix === '' && function_exists('employee_display_name')) {
        return employee_display_name($row);
    }

    $parts = [
        trim((string) ($row[$prefix . 'first_name'] ?? '')),
        trim((string) ($row[$prefix . 'middle_name'] ?? '')),
        trim((string) ($row[$prefix . 'last_name'] ?? '')),
        trim((string) ($row[$prefix . 'suffix_name'] ?? '')),
    ];

    return trim(implode(' ', array_filter($parts)));
}

function asset_view_type_label(string $itemType): string
{
    return $itemType === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment';
}

function asset_view_source_label(string $source): string
{
    return $source === 'legacy' ? 'Beginning Balance' : 'System Transaction';
}

function asset_view_can_manage_photos(): bool
{
    return in_array((string) ($_SESSION['user_role'] ?? ''), ['Administrator', 'Property Officer', 'Supply Officer'], true);
}

function asset_view_can_edit_details(): bool
{
    return in_array((string) ($_SESSION['user_role'] ?? ''), ['Administrator', 'Property Officer', 'Supply Officer'], true);
}

function asset_view_can_delete_legacy(): bool
{
    return in_array((string) ($_SESSION['user_role'] ?? ''), ['Administrator', 'Property Officer', 'Supply Officer'], true);
}

function asset_view_can_edit_source_po(): bool
{
    return in_array((string) ($_SESSION['user_role'] ?? ''), ['Administrator', 'Supply Officer'], true);
}

function asset_view_classification(array $row): string
{
    return trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
}

function asset_view_sort_timeline(array &$entries): void
{
    usort($entries, static function (array $a, array $b): int {
        $aDate = (string) ($a['date'] ?? '');
        $bDate = (string) ($b['date'] ?? '');
        if ($aDate === $bDate) {
            return strcmp((string) ($b['reference'] ?? ''), (string) ($a['reference'] ?? ''));
        }
        return strcmp($bDate, $aDate);
    });
}

if (!$db) {
    http_response_code(500);
    exit('Unable to connect to the database.');
}

$brandRes = $db->query("SELECT brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC");
if ($brandRes instanceof mysqli_result) {
    while ($row = $brandRes->fetch_assoc()) {
        $name = trim((string) ($row['brand_name'] ?? ''));
        if ($name !== '') {
            $brandOptions[] = $name;
        }
    }
}

$modelRes = $db->query("SELECT model_name FROM models WHERE is_active = 1 ORDER BY model_name ASC");
if ($modelRes instanceof mysqli_result) {
    while ($row = $modelRes->fetch_assoc()) {
        $name = trim((string) ($row['model_name'] ?? ''));
        if ($name !== '') {
            $modelOptions[] = $name;
        }
    }
}

$accountRes = $db->query("SELECT id, account_code, account_name FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
if ($accountRes instanceof mysqli_result) {
    while ($row = $accountRes->fetch_assoc()) {
        $accountCodeOptions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'account_code' => (string) ($row['account_code'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
        ];
    }
}

$fundRes = $db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC");
if ($fundRes instanceof mysqli_result) {
    while ($row = $fundRes->fetch_assoc()) {
        $fundOptions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'fund_code' => (string) ($row['fund_code'] ?? ''),
            'fund_name' => (string) ($row['fund_name'] ?? ''),
            'fund_source' => (string) ($row['fund_source'] ?? ''),
        ];
    }
}

if ($source === 'system') {
    $stmt = $db->prepare("
        SELECT
            did.id,
            did.property_number,
            did.brand,
            did.model,
            did.serial_no,
            did.current_office_id,
            did.current_employee_id,
            did.current_responsibility_code_id,
            poi.item_type,
            poi.item_description,
            c.classification_name,
            c.classification_family,
            ac.id AS account_code_id,
            ac.account_code,
            ac.account_name,
            ri.unit_cost,
            r.id AS receiving_id,
            r.system_reference AS receiving_reference,
            r.ris_no,
            r.received_date,
            r.delivery_receipt_no,
            r.invoice_no,
            po.id AS purchase_order_id,
            po.po_number,
            po.po_date,
            s.supplier_name,
            f.id AS fund_id,
            f.fund_name,
            f.fund_code,
            f.fund_source,
            d.id AS distribution_id,
            d.system_reference AS distribution_reference,
            d.document_no,
            d.document_type,
            d.distribution_date,
            d.purpose,
            d.remarks AS distribution_remarks,
            COALESCE(curr_o.office_name, base_o.office_name) AS office_name,
            COALESCE(curr_e.employee_no, base_e.employee_no) AS employee_no,
            COALESCE(curr_e.first_name, base_e.first_name) AS first_name,
            COALESCE(curr_e.middle_name, base_e.middle_name) AS middle_name,
            COALESCE(curr_e.last_name, base_e.last_name) AS last_name,
            COALESCE(curr_e.suffix_name, base_e.suffix_name) AS suffix_name,
            COALESCE(curr_e.position_title, base_e.position_title) AS position_title,
            COALESCE(curr_rc.code, base_rc.code) AS rc_code,
            si.system_reference AS stock_reference,
            CASE WHEN did.is_disposed IS NULL OR did.is_disposed = 0 THEN 0 ELSE 1 END AS is_disposed,
            did.is_distributed
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
        INNER JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
        LEFT JOIN stock_items si ON si.id = rid.stock_item_id
        INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        INNER JOIN receivings r ON r.id = ri.receiving_id
        INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
        LEFT JOIN offices base_o ON base_o.id = d.office_id
        LEFT JOIN employees base_e ON base_e.id = d.employee_id
        LEFT JOIN responsibility_codes base_rc ON base_rc.office_id = d.office_id
        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
        LEFT JOIN responsibility_codes curr_rc ON curr_rc.id = did.current_responsibility_code_id
        WHERE did.id = ?
          AND poi.item_type IN ('equipment', 'semi_expendable')
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $asset = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
} else {
    $stmt = $db->prepare("
        SELECT
            la.id,
            la.system_reference,
            la.property_number,
            la.item_type,
            la.item_description,
            la.office_id,
            la.account_code_id,
            la.fund_id,
            la.brand,
            la.model,
            la.serial_no,
            la.acquisition_date,
            la.quantity,
            la.unit_cost,
            la.acquisition_cost,
            la.condition_status,
            la.remarks,
            s.supplier_name,
            c.classification_name,
            c.classification_family,
            ac.account_code,
            ac.account_name,
            f.fund_name,
            f.fund_code,
            f.fund_source,
            o.office_name,
            e.employee_no,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name,
            e.position_title,
            rc.code AS rc_code
        FROM legacy_assets la
        LEFT JOIN suppliers s ON s.id = la.supplier_id
        LEFT JOIN classifications c ON c.id = la.classification_id
        LEFT JOIN account_codes ac ON ac.id = la.account_code_id
        LEFT JOIN funds f ON f.id = la.fund_id
        LEFT JOIN offices o ON o.id = la.office_id
        LEFT JOIN employees e ON e.id = la.employee_id
        LEFT JOIN responsibility_codes rc ON rc.id = la.responsibility_code_id
        WHERE la.id = ?
          AND la.is_active = 1
          AND la.item_type IN ('equipment', 'semi_expendable')
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $asset = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

if (!$asset) {
    http_response_code(404);
    exit('Asset not found.');
}

$canManagePhotos = asset_view_can_manage_photos();
$canEditDetails = asset_view_can_edit_details();
$canDeleteLegacy = asset_view_can_delete_legacy();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($canManagePhotos || $canEditDetails)) {
    if (!csrf_verify()) {
        set_flash('error', 'Invalid CSRF token.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_asset_details') {
        if (!$canEditDetails || $source !== 'legacy') {
            set_flash('error', 'You are not allowed to edit asset details.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        $propertyNumber = trim((string) ($_POST['property_number'] ?? ''));
        $brand = trim((string) ($_POST['brand'] ?? ''));
        $model = trim((string) ($_POST['model'] ?? ''));
        $serialNo = trim((string) ($_POST['serial_no'] ?? ''));
        $accountCodeIdInput = (int) ($_POST['account_code_id'] ?? 0);
        $fundIdInput = (int) ($_POST['fund_id'] ?? 0);

        if ($propertyNumber === '') {
            set_flash('error', 'Property number is required.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        if ($source === 'legacy' && $accountCodeIdInput > 0) {
            $checkAccountStmt = $db->prepare("SELECT id FROM account_codes WHERE id = ? AND is_active = 1 LIMIT 1");
            if ($checkAccountStmt) {
                $checkAccountStmt->bind_param('i', $accountCodeIdInput);
                $checkAccountStmt->execute();
                $exists = $checkAccountStmt->get_result()->fetch_assoc();
                $checkAccountStmt->close();
                if (!$exists) {
                    set_flash('error', 'Selected account code is invalid.');
                    redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
                }
            }
        }

        if ($source === 'legacy' && $fundIdInput > 0) {
            $checkFundStmt = $db->prepare("SELECT id FROM funds WHERE id = ? AND is_active = 1 LIMIT 1");
            if ($checkFundStmt) {
                $checkFundStmt->bind_param('i', $fundIdInput);
                $checkFundStmt->execute();
                $exists = $checkFundStmt->get_result()->fetch_assoc();
                $checkFundStmt->close();
                if (!$exists) {
                    set_flash('error', 'Selected fund is invalid.');
                    redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
                }
            }
        }

        if ($source === 'system') {
            $db->begin_transaction();
            $saved = false;

            $stmt = $db->prepare("UPDATE distribution_item_details
                                  SET property_number = ?,
                                      brand = ?,
                                      model = ?,
                                      serial_no = ?
                                  WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ssssi', $propertyNumber, $brand, $model, $serialNo, $id);
                $saved = (bool) $stmt->execute();
                $stmt->close();
            }

            if ($saved) {
                $db->commit();
                write_audit_log($db, [
                    'action' => 'update',
                    'table_name' => 'distribution_item_details',
                    'record_id' => $id,
                    'module_name' => 'property',
                    'record_type' => 'asset',
                    'action_name' => 'edit_asset_details',
                    'description' => 'Updated asset details from Asset Details page.',
                    'new_values' => [
                        'property_number' => $propertyNumber,
                        'brand' => $brand,
                        'model' => $model,
                        'serial_no' => $serialNo,
                    ],
                ]);
                set_flash('success', 'Asset details updated successfully.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }

            $db->rollback();
            set_flash('error', 'Unable to update asset details right now.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        $description = trim((string) ($_POST['item_description'] ?? ''));
        $acquisitionDate = trim((string) ($_POST['acquisition_date'] ?? ''));
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $unitCostInput = trim((string) ($_POST['unit_cost'] ?? '0'));
        $unitCost = is_numeric($unitCostInput) ? round((float) $unitCostInput, 2) : 0.0;
        if ($unitCost < 0) {
            $unitCost = 0.0;
        }
        $acquisitionCost = round($unitCost * $quantity, 2);
        $conditionStatus = trim((string) ($_POST['condition_status'] ?? 'serviceable'));
        $remarksInput = trim((string) ($_POST['remarks'] ?? ''));

        $stmt = $db->prepare("UPDATE legacy_assets
                              SET property_number = ?,
                                  item_description = ?,
                                  brand = ?,
                                  model = ?,
                                  serial_no = ?,
                                  acquisition_date = NULLIF(?, ''),
                                  account_code_id = CASE WHEN ? > 0 THEN ? ELSE account_code_id END,
                                  fund_id = CASE WHEN ? > 0 THEN ? ELSE fund_id END,
                                  quantity = ?,
                                  unit_cost = ?,
                                  acquisition_cost = ?,
                                  condition_status = ?,
                                  remarks = ?
                              WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param(
                'ssssssiiiiiddssi',
                $propertyNumber,
                $description,
                $brand,
                $model,
                $serialNo,
                $acquisitionDate,
                $accountCodeIdInput,
                $accountCodeIdInput,
                $fundIdInput,
                $fundIdInput,
                $quantity,
                $unitCost,
                $acquisitionCost,
                $conditionStatus,
                $remarksInput,
                $id
            );
            $saved = $stmt->execute();
            $stmt->close();
            if ($saved) {
                write_audit_log($db, [
                    'action' => 'update',
                    'table_name' => 'legacy_assets',
                    'record_id' => $id,
                    'module_name' => 'property',
                    'record_type' => 'legacy_asset',
                    'action_name' => 'edit_asset_details',
                    'description' => 'Updated legacy asset details from Asset Details page.',
                    'new_values' => [
                        'property_number' => $propertyNumber,
                        'item_description' => $description,
                        'brand' => $brand,
                        'model' => $model,
                        'serial_no' => $serialNo,
                        'acquisition_date' => $acquisitionDate,
                        'account_code_id' => $accountCodeIdInput > 0 ? $accountCodeIdInput : ($asset['account_code_id'] ?? null),
                        'fund_id' => $fundIdInput > 0 ? $fundIdInput : ($asset['fund_id'] ?? null),
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'acquisition_cost' => $acquisitionCost,
                        'condition_status' => $conditionStatus,
                        'remarks' => $remarksInput,
                    ],
                ]);
                set_flash('success', 'Asset details updated successfully.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
        }

        set_flash('error', 'Unable to update asset details right now.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    if ($action === 'delete_legacy_asset') {
        if ($source !== 'legacy' || !$canDeleteLegacy) {
            set_flash('error', 'You are not allowed to delete this asset.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        $deleteStmt = $db->prepare("UPDATE legacy_assets SET is_active = 0 WHERE id = ? LIMIT 1");
        if ($deleteStmt) {
            $deleteStmt->bind_param('i', $id);
            $deleted = $deleteStmt->execute();
            $deleteStmt->close();
            if ($deleted) {
                write_audit_log($db, [
                    'action' => 'delete',
                    'table_name' => 'legacy_assets',
                    'record_id' => $id,
                    'module_name' => 'property',
                    'record_type' => 'legacy_asset',
                    'action_name' => 'delete_legacy_asset',
                    'description' => 'Soft-deleted legacy asset from Asset Details page.',
                    'old_values' => $asset,
                    'new_values' => ['is_active' => 0],
                ]);
                set_flash('success', 'Legacy asset deleted.');
                redirect('modules/property/legacy_assets.php');
            }
        }

        set_flash('error', 'Unable to delete the legacy asset right now.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    if ($action === 'upload_photo') {
        if (!$canManagePhotos) {
            set_flash('error', 'You are not allowed to upload photos.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        $caption = old($_POST, 'caption');
        $uploadErrors = [];
        $storedPhoto = store_uploaded_image($_FILES['asset_photo'] ?? [], 'assets', $uploadErrors);
        if ($storedPhoto === null) {
            set_flash('error', $uploadErrors ? implode(' ', $uploadErrors) : 'Please choose an image to upload.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM asset_photos WHERE asset_source = ? AND asset_id = ?");
        $existingCount = 0;
        if ($countStmt) {
            $countStmt->bind_param('si', $source, $id);
            $countStmt->execute();
            $existingCount = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $countStmt->close();
        }

        $insertStmt = $db->prepare("
            INSERT INTO asset_photos (asset_source, asset_id, photo_path, caption, is_primary, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($insertStmt) {
            $isPrimary = $existingCount === 0 ? 1 : 0;
            $userId = (int) (current_user_id() ?? 0);
            $insertStmt->bind_param('sissii', $source, $id, $storedPhoto, $caption, $isPrimary, $userId);
            $saved = $insertStmt->execute();
            $newPhotoId = (int) $insertStmt->insert_id;
            $insertStmt->close();
            if ($saved) {
                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'asset_photos',
                    'record_id' => $newPhotoId,
                    'module_name' => 'property',
                    'record_type' => 'asset_photo',
                    'action_name' => 'upload_asset_photo',
                    'description' => 'Uploaded an asset photo.',
                    'new_values' => [
                        'asset_source' => $source,
                        'asset_id' => $id,
                        'caption' => $caption,
                        'is_primary' => $isPrimary,
                    ],
                ]);
                set_flash('success', 'Asset photo uploaded successfully.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
        }

        delete_uploaded_file($storedPhoto);
        set_flash('error', 'Unable to upload asset photo right now.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    if ($action === 'set_primary_photo') {
        if (!$canManagePhotos) {
            set_flash('error', 'You are not allowed to update photos.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $db->query("START TRANSACTION");
        $resetStmt = $db->prepare("UPDATE asset_photos SET is_primary = 0 WHERE asset_source = ? AND asset_id = ?");
        $setStmt = $db->prepare("UPDATE asset_photos SET is_primary = 1 WHERE id = ? AND asset_source = ? AND asset_id = ?");
        $ok = false;
        if ($resetStmt && $setStmt) {
            $resetStmt->bind_param('si', $source, $id);
            $setStmt->bind_param('isi', $photoId, $source, $id);
            $ok = $resetStmt->execute() && $setStmt->execute();
            $resetStmt->close();
            $setStmt->close();
        }
        if ($ok) {
            $db->commit();
            set_flash('success', 'Primary asset photo updated.');
        } else {
            $db->rollback();
            set_flash('error', 'Unable to update the primary photo right now.');
        }
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    if ($action === 'delete_photo') {
        if (!$canManagePhotos) {
            set_flash('error', 'You are not allowed to delete photos.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $photoStmt = $db->prepare("
            SELECT id, photo_path, is_primary
            FROM asset_photos
            WHERE id = ? AND asset_source = ? AND asset_id = ?
            LIMIT 1
        ");
        $photoRow = null;
        if ($photoStmt) {
            $photoStmt->bind_param('isi', $photoId, $source, $id);
            $photoStmt->execute();
            $photoRow = $photoStmt->get_result()->fetch_assoc();
            $photoStmt->close();
        }

        if ($photoRow) {
            $deleteStmt = $db->prepare("DELETE FROM asset_photos WHERE id = ? LIMIT 1");
            if ($deleteStmt) {
                $deleteStmt->bind_param('i', $photoId);
                $saved = $deleteStmt->execute();
                $deleteStmt->close();
                if ($saved) {
                    delete_uploaded_file((string) $photoRow['photo_path']);
                    if ((int) ($photoRow['is_primary'] ?? 0) === 1) {
                        $nextStmt = $db->prepare("
                            UPDATE asset_photos
                            SET is_primary = 1
                            WHERE asset_source = ? AND asset_id = ?
                            ORDER BY created_at DESC, id DESC
                            LIMIT 1
                        ");
                        if ($nextStmt) {
                            $nextStmt->bind_param('si', $source, $id);
                            $nextStmt->execute();
                            $nextStmt->close();
                        }
                    }
                    set_flash('success', 'Asset photo deleted.');
                    redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
                }
            }
        }

        set_flash('error', 'Unable to delete that asset photo.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }
}

$photoStmt = $db->prepare("
    SELECT id, photo_path, caption, is_primary, created_at
    FROM asset_photos
    WHERE asset_source = ? AND asset_id = ?
    ORDER BY is_primary DESC, created_at DESC, id DESC
");
if ($photoStmt) {
    $photoStmt->bind_param('si', $source, $id);
    $photoStmt->execute();
    $assetPhotos = $photoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $photoStmt->close();
}

if ($source === 'system') {
    $timeline[] = [
        'date' => $asset['received_date'] ?? '',
        'event' => 'Received',
        'reference' => trim(implode(' / ', array_filter([
            $asset['receiving_reference'] ?? '',
            $asset['ris_no'] ?? '',
        ]))),
        'details' => trim(implode(' | ', array_filter([
            !empty($asset['supplier_name']) ? 'Supplier: ' . $asset['supplier_name'] : '',
            !empty($asset['po_number']) ? 'PO: ' . $asset['po_number'] : '',
            isset($asset['unit_cost']) ? 'Unit cost: ' . number_format((float) $asset['unit_cost'], 2) : '',
        ]))),
    ];

    $timeline[] = [
        'date' => $asset['distribution_date'] ?? '',
        'event' => strtoupper((string) ($asset['document_type'] ?? '')) . ' posted',
        'reference' => trim((string) ($asset['document_no'] ?? $asset['distribution_reference'] ?? '')),
        'details' => trim(implode(' | ', array_filter([
            !empty($asset['office_name']) ? 'Office: ' . $asset['office_name'] : '',
            ($person = asset_view_person($asset)) !== '' ? 'Accountable: ' . $person : '',
            !empty($asset['rc_code']) ? 'RC: ' . $asset['rc_code'] : '',
        ]))),
    ];

    if (schema_has_column($db, 'asset_transfers', 'distribution_item_detail_id')) {
        $stmt = $db->prepare("
            SELECT
                at.system_reference,
                at.transfer_date,
                at.reason,
                at.remarks,
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
                to_rc.code AS to_rc_code
            FROM asset_transfers at
            LEFT JOIN offices from_o ON from_o.id = at.from_office_id
            LEFT JOIN offices to_o ON to_o.id = at.to_office_id
            LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
            LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
            LEFT JOIN responsibility_codes from_rc ON from_rc.id = at.from_responsibility_code_id
            LEFT JOIN responsibility_codes to_rc ON to_rc.id = at.to_responsibility_code_id
            WHERE at.distribution_item_detail_id = ?
              AND at.status = 'posted'
            ORDER BY at.transfer_date DESC, at.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    if (schema_has_column($db, 'maintenance_logs', 'distribution_item_detail_id')) {
        $stmt = $db->prepare("
            SELECT system_reference, maintenance_date, work_description, performed_by, cost, remarks
            FROM maintenance_logs
            WHERE distribution_item_detail_id = ?
              AND status = 'posted'
            ORDER BY maintenance_date DESC, id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $maintenanceRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    if (schema_has_column($db, 'returns', 'distribution_item_detail_id')) {
        $stmt = $db->prepare("
            SELECT
                rt.system_reference,
                rt.return_date,
                rt.reason,
                rt.remarks,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name
            FROM returns rt
            LEFT JOIN offices o ON o.id = rt.office_id
            LEFT JOIN employees e ON e.id = rt.employee_id
            WHERE rt.distribution_item_detail_id = ?
              AND rt.status = 'posted'
            ORDER BY rt.return_date DESC, rt.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $returnRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } else {
        $notices[] = 'Return history is limited until the newer return linkage column is present in the database.';
    }

    if (schema_has_column($db, 'disposals', 'distribution_item_detail_id')) {
        $stmt = $db->prepare("
            SELECT
                dp.system_reference,
                dp.disposal_date,
                dp.disposal_type,
                dp.reason,
                dp.remarks,
                dp.approved_by
            FROM disposals dp
            WHERE dp.distribution_item_detail_id = ?
            ORDER BY dp.disposal_date DESC, dp.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $disposalRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } else {
        $notices[] = 'Disposal history is limited until the newer disposal linkage column is present in the database.';
    }
} else {
    $timeline[] = [
        'date' => $asset['acquisition_date'] ?? '',
        'event' => 'Beginning balance entry',
        'reference' => trim((string) ($asset['system_reference'] ?? '')),
        'details' => trim(implode(' | ', array_filter([
            !empty($asset['supplier_name']) ? 'Supplier: ' . $asset['supplier_name'] : '',
            isset($asset['quantity']) ? 'Qty: ' . number_format((float) $asset['quantity'], 0) : '',
            isset($asset['unit_cost']) ? 'Unit cost: ' . number_format((float) $asset['unit_cost'], 2) : '',
        ]))),
    ];

    if (schema_has_column($db, 'asset_transfers', 'legacy_asset_id')) {
        $stmt = $db->prepare("
            SELECT
                at.system_reference,
                at.transfer_date,
                at.reason,
                at.remarks,
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
                to_rc.code AS to_rc_code
            FROM asset_transfers at
            LEFT JOIN offices from_o ON from_o.id = at.from_office_id
            LEFT JOIN offices to_o ON to_o.id = at.to_office_id
            LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
            LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
            LEFT JOIN responsibility_codes from_rc ON from_rc.id = at.from_responsibility_code_id
            LEFT JOIN responsibility_codes to_rc ON to_rc.id = at.to_responsibility_code_id
            WHERE at.legacy_asset_id = ?
              AND at.status = 'posted'
            ORDER BY at.transfer_date DESC, at.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    if (schema_has_column($db, 'returns', 'legacy_asset_id') && schema_has_column($db, 'returns', 'source_type')) {
        $stmt = $db->prepare("
            SELECT
                rt.system_reference,
                rt.return_date,
                rt.reason,
                rt.remarks,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name
            FROM returns rt
            LEFT JOIN offices o ON o.id = rt.office_id
            LEFT JOIN employees e ON e.id = rt.employee_id
            WHERE rt.source_type = 'legacy'
              AND rt.legacy_asset_id = ?
              AND rt.status = 'posted'
            ORDER BY rt.return_date DESC, rt.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $returnRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    if (schema_has_column($db, 'disposals', 'legacy_asset_id') && schema_has_column($db, 'disposals', 'source_type')) {
        $stmt = $db->prepare("
            SELECT
                dp.system_reference,
                dp.disposal_date,
                dp.disposal_type,
                dp.reason,
                dp.remarks,
                ap.first_name,
                ap.middle_name,
                ap.last_name,
                ap.suffix_name
            FROM disposals dp
            LEFT JOIN employees ap ON ap.id = dp.approved_by
            WHERE dp.source_type = 'legacy'
              AND dp.legacy_asset_id = ?
              AND dp.status = 'posted'
            ORDER BY dp.disposal_date DESC, dp.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $disposalRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}

foreach ($transfers as $row) {
    $timeline[] = [
        'date' => $row['transfer_date'] ?? '',
        'event' => 'Transferred',
        'reference' => (string) ($row['system_reference'] ?? ''),
        'details' => trim(implode(' | ', array_filter([
            !empty($row['from_office_name']) ? 'From: ' . $row['from_office_name'] : '',
            ($fromPerson = asset_view_person($row, 'from_')) !== '' ? $fromPerson : '',
            !empty($row['from_rc_code']) ? $row['from_rc_code'] : '',
            !empty($row['to_office_name']) ? 'To: ' . $row['to_office_name'] : '',
            ($toPerson = asset_view_person($row, 'to_')) !== '' ? $toPerson : '',
            !empty($row['to_rc_code']) ? $row['to_rc_code'] : '',
            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
        ]))),
    ];
}

foreach ($maintenanceRows as $row) {
    $timeline[] = [
        'date' => $row['maintenance_date'] ?? '',
        'event' => 'Maintenance',
        'reference' => (string) ($row['system_reference'] ?? ''),
        'details' => trim(implode(' | ', array_filter([
            $row['work_description'] ?? '',
            !empty($row['performed_by']) ? 'By: ' . $row['performed_by'] : '',
            isset($row['cost']) ? 'Cost: ' . number_format((float) $row['cost'], 2) : '',
        ]))),
    ];
}

foreach ($returnRows as $row) {
    $timeline[] = [
        'date' => $row['return_date'] ?? '',
        'event' => 'Returned',
        'reference' => (string) ($row['system_reference'] ?? ''),
        'details' => trim(implode(' | ', array_filter([
            !empty($row['office_name']) ? 'Office: ' . $row['office_name'] : '',
            ($returnPerson = asset_view_person($row)) !== '' ? 'Accountable: ' . $returnPerson : '',
            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
        ]))),
    ];
}

foreach ($disposalRows as $row) {
    $approvedByLabel = asset_view_person($row);
    $timeline[] = [
        'date' => $row['disposal_date'] ?? '',
        'event' => 'Disposed',
        'reference' => (string) ($row['system_reference'] ?? ''),
        'details' => trim(implode(' | ', array_filter([
            !empty($row['disposal_type']) ? 'Type: ' . $row['disposal_type'] : '',
            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
            $approvedByLabel !== '' ? 'Approved by: ' . $approvedByLabel : (!empty($row['approved_by']) ? 'Approved by: ' . $row['approved_by'] : ''),
        ]))),
    ];
}

asset_view_sort_timeline($timeline);

$classificationLabel = asset_view_classification($asset);
$accountCodeLabel = trim(implode(' - ', array_filter([
    $asset['account_code'] ?? '',
    $asset['account_name'] ?? '',
])));
$brandModel = trim(implode(' / ', array_filter([
    trim((string) ($asset['brand'] ?? '')),
    trim((string) ($asset['model'] ?? '')),
])));
$accountableName = asset_view_person($asset);
$detailTitle = asset_view_type_label((string) ($asset['item_type'] ?? '')) . ' Details';
$publicLookupUrl = base_url('modules/property/scan.php?ref=' . urlencode((string) ($asset['property_number'] ?? '')));
$historyCount = count($transfers) + count($maintenanceRows) + count($returnRows) + count($disposalRows);
$canEditSourcePo = asset_view_can_edit_source_po();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                            <h4 class="mb-0"><?php echo h($detailTitle); ?></h4>
                            <?php if (($asset['item_type'] ?? '') === 'semi_expendable'): ?>
                                <span class="badge text-bg-info">Semi-Expendable</span>
                            <?php else: ?>
                                <span class="badge text-bg-primary">Equipment</span>
                            <?php endif; ?>
                            <?php if ($source === 'legacy'): ?>
                                <span class="badge text-bg-secondary">Beginning Balance</span>
                            <?php else: ?>
                                <span class="badge text-bg-success">System Transaction</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">Complete asset profile, accountability assignment, and lifecycle history.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php $assetKey = $source . ':' . (int) $id; ?>
                        <a href="<?php echo base_url('modules/property/index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back to Registry</a>
                        <?php if ($source === 'system' && !empty($asset['purchase_order_id'])): ?>
                            <a href="<?php echo base_url('modules/purchase_orders/view.php?id=' . (int) $asset['purchase_order_id']); ?>" class="btn btn-outline-info btn-sm">Source PO</a>
                            <?php if ($canEditSourcePo): ?>
                                <a href="<?php echo base_url('modules/purchase_orders/edit.php?id=' . (int) $asset['purchase_order_id']); ?>" class="btn btn-outline-primary btn-sm">Edit Source PO</a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($canEditDetails && $source === 'legacy'): ?>
                            <button class="btn btn-outline-warning btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#assetEditPanel" aria-expanded="false" aria-controls="assetEditPanel">Edit</button>
                        <?php endif; ?>
                        <a href="<?php echo $publicLookupUrl; ?>" class="btn btn-outline-primary btn-sm" target="_blank">Public Lookup</a>
                        <div class="dropdown">
                            <button class="btn btn-dark btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                Asset Actions
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?php echo base_url('modules/transfers/index.php?asset_key=' . urlencode($assetKey)); ?>">Transfer</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <?php if ($source === 'system'): ?>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/maintenance/index.php?detail_id=' . (int) $asset['id']); ?>">Maintenance</a></li>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/returns/index.php?detail_id=' . (int) $asset['id']); ?>">Return</a></li>
                                    <?php if ((int) ($asset['is_disposed'] ?? 0) === 0): ?>
                                        <li><a class="dropdown-item text-danger" href="<?php echo base_url('modules/disposals/index.php?detail_id=' . (int) $asset['id']); ?>">Disposal</a></li>
                                    <?php else: ?>
                                        <li><span class="dropdown-item-text text-muted small">Disposal already posted for this asset.</span></li>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <li><span class="dropdown-item-text text-muted small">Maintenance: available for system assets only.</span></li>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/returns/index.php?source=legacy&legacy_asset_id=' . (int) $asset['id']); ?>">Return</a></li>
                                    <li><a class="dropdown-item text-danger" href="<?php echo base_url('modules/disposals/index.php?source=legacy&legacy_asset_id=' . (int) $asset['id']); ?>">Disposal</a></li>
                                    <?php if ($canDeleteLegacy): ?>
                                        <li>
                                            <form method="post" onsubmit="return confirm('Delete this legacy asset? This will hide it from the active legacy list.');">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete_legacy_asset">
                                                <button type="submit" class="dropdown-item text-danger">Delete Legacy Asset</button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <li><hr class="dropdown-divider"></li>
                                <?php if ($source === 'system' && !empty($asset['distribution_id'])): ?>
                                    <?php
                                    $docType = (string) ($asset['document_type'] ?? '');
                                    $docUrl = $docType === 'par'
                                        ? base_url('modules/distributions/par.php?id=' . (int) $asset['distribution_id'])
                                        : base_url('modules/distributions/ics.php?id=' . (int) $asset['distribution_id']);
                                    ?>
                                    <li><a class="dropdown-item" href="<?php echo $docUrl; ?>" target="_blank">Print <?php echo h(strtoupper($docType)); ?></a></li>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/property/tags.php?detail_id=' . (int) $asset['id']); ?>" target="_blank">Print Tag</a></li>
                                <?php elseif ($source === 'legacy'): ?>
                                    <?php if (($asset['item_type'] ?? '') === 'semi_expendable'): ?>
                                        <li><a class="dropdown-item" href="<?php echo base_url('modules/distributions/ics_office.php?legacy_asset_id=' . (int) $asset['id'] . '&semi_type=all&print=1'); ?>" target="_blank">Print ICS</a></li>
                                        <li><span class="dropdown-item-text text-muted small">PAR is for equipment assets only.</span></li>
                                    <?php else: ?>
                                        <li><a class="dropdown-item" href="<?php echo base_url('modules/distributions/par_office.php?legacy_asset_id=' . (int) $asset['id'] . '&print=1'); ?>" target="_blank">Print PAR</a></li>
                                        <li><span class="dropdown-item-text text-muted small">ICS is for semi-expendable assets only.</span></li>
                                    <?php endif; ?>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/property/tags.php?legacy_asset_id=' . (int) $asset['id']); ?>" target="_blank">Print Tag</a></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <?php if ($notices): ?>
                    <div class="alert alert-warning">
                        <?php foreach ($notices as $notice): ?>
                            <div><?php echo h($notice); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
                        <?php echo h($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($canEditDetails && $source === 'legacy'): ?>
                    <div class="collapse mb-4" id="assetEditPanel">
                        <div class="card border border-warning-subtle">
                            <div class="card-body">
                                <h6 class="mb-3">Edit Asset Details</h6>
                                <form method="post" class="row g-3">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="save_asset_details">

                                    <div class="col-md-4">
                                        <label class="form-label">Property Number</label>
                                        <input type="text" name="property_number" class="form-control" value="<?php echo h((string) ($asset['property_number'] ?? '')); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Brand</label>
                                        <input type="text" name="brand" class="form-control" list="assetBrandOptions" value="<?php echo h((string) ($asset['brand'] ?? '')); ?>" placeholder="Type or select brand">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Model</label>
                                        <input type="text" name="model" class="form-control" list="assetModelOptions" value="<?php echo h((string) ($asset['model'] ?? '')); ?>" placeholder="Type or select model">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Serial No.</label>
                                        <input type="text" name="serial_no" class="form-control" value="<?php echo h((string) ($asset['serial_no'] ?? '')); ?>">
                                    </div>

                                    <?php if ($source === 'legacy'): ?>
                                        <div class="col-md-6">
                                            <label class="form-label">Account Code</label>
                                            <select name="account_code_id" class="form-select">
                                                <option value="0">Keep current account code</option>
                                                <?php foreach ($accountCodeOptions as $option): ?>
                                                    <?php $optionId = (int) ($option['id'] ?? 0); ?>
                                                    <?php $isSelected = $optionId > 0 && $optionId === (int) ($asset['account_code_id'] ?? 0); ?>
                                                    <option value="<?php echo h((string) $optionId); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                        <?php echo h(trim(($option['account_code'] ?? '') . ' - ' . ($option['account_name'] ?? ''))); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Fund</label>
                                            <select name="fund_id" class="form-select">
                                                <option value="0">Keep current fund</option>
                                                <?php foreach ($fundOptions as $option): ?>
                                                    <?php $optionId = (int) ($option['id'] ?? 0); ?>
                                                    <?php $isSelected = $optionId > 0 && $optionId === (int) ($asset['fund_id'] ?? 0); ?>
                                                    <?php $fundLabel = trim(($option['fund_code'] ?? '') . ' - ' . ($option['fund_name'] ?? '')); ?>
                                                    <?php if (!empty($option['fund_source'])) { $fundLabel .= ' (' . $option['fund_source'] . ')'; } ?>
                                                    <option value="<?php echo h((string) $optionId); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                        <?php echo h($fundLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Description</label>
                                            <textarea name="item_description" class="form-control" rows="3"><?php echo h((string) ($asset['item_description'] ?? '')); ?></textarea>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Acquisition Date</label>
                                            <input type="date" name="acquisition_date" class="form-control" value="<?php echo h((string) ($asset['acquisition_date'] ?? '')); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Quantity</label>
                                            <input type="number" name="quantity" min="1" step="1" class="form-control" value="<?php echo h((string) ((int) ($asset['quantity'] ?? 1))); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Unit Cost</label>
                                            <input type="number" name="unit_cost" min="0" step="0.01" class="form-control" value="<?php echo h((string) number_format((float) ($asset['unit_cost'] ?? 0), 2, '.', '')); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Condition</label>
                                            <?php $conditionValue = trim((string) ($asset['condition_status'] ?? 'serviceable')); ?>
                                            <select name="condition_status" class="form-select">
                                                <option value="serviceable" <?php echo $conditionValue === 'serviceable' ? 'selected' : ''; ?>>Serviceable</option>
                                                <option value="unserviceable" <?php echo $conditionValue === 'unserviceable' ? 'selected' : ''; ?>>Unserviceable</option>
                                                <option value="disposed" <?php echo $conditionValue === 'disposed' ? 'selected' : ''; ?>>Disposed</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Remarks</label>
                                            <textarea name="remarks" class="form-control" rows="2"><?php echo h((string) ($asset['remarks'] ?? '')); ?></textarea>
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-12 d-flex justify-content-end">
                                        <button type="submit" class="btn btn-warning">Save Changes</button>
                                    </div>
                                </form>

                                <datalist id="assetBrandOptions">
                                    <?php foreach ($brandOptions as $option): ?>
                                        <option value="<?php echo h($option); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <datalist id="assetModelOptions">
                                    <?php foreach ($modelOptions as $option): ?>
                                        <option value="<?php echo h($option); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Property Number</div>
                            <div class="fw-semibold"><?php echo h($asset['property_number'] ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Current Office</div>
                            <div class="fw-semibold"><?php echo h($asset['office_name'] ?? 'Unassigned'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Accountable Person</div>
                            <div class="fw-semibold"><?php echo h($accountableName !== '' ? $accountableName : 'Unassigned'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">History Entries</div>
                            <div class="fw-semibold"><?php echo h((string) $historyCount); ?></div>
                        </div>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h6 class="mb-0">Asset Photos</h6>
                                <span class="badge text-bg-light"><?php echo count($assetPhotos); ?></span>
                            </div>
                            <div class="card-body">
                                <div class="row g-4">
                                    <div class="col-lg-7">
                                        <?php $primaryPhoto = $assetPhotos[0] ?? null; ?>
                                        <div class="asset-photo-main">
                                            <?php if ($primaryPhoto): ?>
                                                <img src="<?php echo h(upload_url($primaryPhoto['photo_path'])); ?>" alt="<?php echo h($detailTitle); ?>">
                                            <?php else: ?>
                                                <div class="asset-photo-empty">
                                                    <i class="bi bi-camera"></i>
                                                    <div>No asset photo uploaded yet.</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($assetPhotos): ?>
                                            <div class="asset-photo-grid mt-3">
                                                <?php foreach ($assetPhotos as $photo): ?>
                                                    <div class="asset-photo-thumb-card">
                                                        <img src="<?php echo h(upload_url($photo['photo_path'])); ?>" alt="<?php echo h($photo['caption'] ?: $detailTitle); ?>">
                                                        <div class="small mt-2 text-muted"><?php echo h($photo['caption'] ?: (($photo['is_primary'] ?? 0) ? 'Primary photo' : 'Asset photo')); ?></div>
                                                        <?php if ($canManagePhotos): ?>
                                                            <div class="d-flex gap-2 mt-2 flex-wrap">
                                                                <?php if ((int) ($photo['is_primary'] ?? 0) !== 1): ?>
                                                                    <form method="post">
                                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                                        <input type="hidden" name="action" value="set_primary_photo">
                                                                        <input type="hidden" name="photo_id" value="<?php echo (int) $photo['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Make Primary</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <span class="badge text-bg-primary">Primary</span>
                                                                <?php endif; ?>
                                                                <form method="post" onsubmit="return confirm('Delete this asset photo?');">
                                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                                    <input type="hidden" name="action" value="delete_photo">
                                                                    <input type="hidden" name="photo_id" value="<?php echo (int) $photo['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                                </form>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-lg-5">
                                        <?php if ($canManagePhotos): ?>
                                            <form method="post" enctype="multipart/form-data" class="border rounded-3 p-3 bg-light-subtle">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="upload_photo">
                                                <div class="fw-semibold mb-2">Upload New Photo</div>
                                                <div class="text-muted small mb-3">Add clear equipment or semi-expendable photos for verification, audit support, and physical inventory reference.</div>
                                                <div class="mb-3">
                                                    <label class="form-label">Asset Photo</label>
                                                    <input type="file" class="form-control" name="asset_photo" accept="image/jpeg,image/png,image/gif,image/webp" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Caption</label>
                                                    <input type="text" class="form-control" name="caption" maxlength="255" placeholder="Front view, serial plate, room photo, etc.">
                                                </div>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-upload me-2"></i>Upload Photo
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <div class="border rounded-3 p-3 bg-light-subtle text-muted small">
                                                Only Administrator, Supply Officer, and Property Officer accounts can manage asset photos.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card h-100">
                            <div class="card-header bg-white">
                                <h6 class="mb-0">Asset Profile</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="small text-muted">Description</div>
                                        <div><?php echo h($asset['item_description'] ?? ''); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Classification</div>
                                        <div><?php echo h($classificationLabel); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Brand / Model</div>
                                        <div><?php echo h($brandModel); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Serial Number</div>
                                        <div><?php echo h($asset['serial_no'] ?? ''); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Account Code</div>
                                        <div><?php echo h($accountCodeLabel); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Source</div>
                                        <div><?php echo h(asset_view_source_label($source)); ?></div>
                                    </div>
                                    <?php if ($source === 'legacy'): ?>
                                        <div class="col-md-6">
                                            <div class="small text-muted">Supplier</div>
                                            <div><?php echo h($asset['supplier_name'] ?? ''); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small text-muted">Condition</div>
                                            <div><?php echo h($asset['condition_status'] ?? ''); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card h-100">
                            <div class="card-header bg-white">
                                <h6 class="mb-0">Acquisition & Accountability</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="small text-muted">Office / Employee / RC</div>
                                    <div class="fw-semibold"><?php echo h($asset['office_name'] ?? 'Unassigned'); ?></div>
                                    <div><?php echo h($accountableName !== '' ? $accountableName : 'Unassigned'); ?></div>
                                    <div class="text-muted small"><?php echo h($asset['position_title'] ?? ''); ?><?php echo !empty($asset['rc_code']) ? ' | ' . h($asset['rc_code']) : ''; ?></div>
                                </div>
                                <?php if ($source === 'system'): ?>
                                    <div class="mb-2"><span class="text-muted small d-block">Purchase Order</span><?php echo h($asset['po_number'] ?? ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Supplier</span><?php echo h($asset['supplier_name'] ?? ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Received</span><?php echo h(!empty($asset['received_date']) ? date('M d, Y', strtotime((string) $asset['received_date'])) : ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Receiving Reference</span><?php echo h(trim(implode(' / ', array_filter([$asset['receiving_reference'] ?? '', $asset['ris_no'] ?? ''])))); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block"><?php echo h(strtoupper((string) ($asset['document_type'] ?? ''))); ?> Reference</span><?php echo h($asset['document_no'] ?? ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Unit Cost</span><?php echo h(number_format((float) ($asset['unit_cost'] ?? 0), 2)); ?></div>
                                <?php else: ?>
                                    <div class="mb-2"><span class="text-muted small d-block">Beginning Balance Reference</span><?php echo h($asset['system_reference'] ?? ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Acquisition Date</span><?php echo h(!empty($asset['acquisition_date']) ? date('M d, Y', strtotime((string) ($asset['acquisition_date'] ?? ''))) : ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Quantity</span><?php echo h(number_format((float) ($asset['quantity'] ?? 0), 0)); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Unit Cost</span><?php echo h(number_format((float) ($asset['unit_cost'] ?? 0), 2)); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Total Cost</span><?php echo h(number_format((float) ($asset['acquisition_cost'] ?? 0), 2)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0">Lifecycle Timeline</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive mobile-table-frame">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 140px;">Date</th>
                                        <th style="width: 180px;">Event</th>
                                        <th style="width: 180px;">Reference</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($timeline): ?>
                                        <?php foreach ($timeline as $entry): ?>
                                            <tr>
                                                <td><?php echo h(!empty($entry['date']) ? date('M d, Y', strtotime((string) $entry['date'])) : ''); ?></td>
                                                <td><?php echo h($entry['event'] ?? ''); ?></td>
                                                <td><?php echo h($entry['reference'] ?? ''); ?></td>
                                                <td><?php echo h($entry['details'] ?? ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No lifecycle history found yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mt-1">
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Transfers</h6>
                                <span class="badge text-bg-light"><?php echo count($transfers); ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive mobile-table-frame">
                                    <table class="table table-sm mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Reference</th>
                                                <th>Movement</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($transfers): ?>
                                                <?php foreach ($transfers as $row): ?>
                                                    <tr>
                                                        <td><?php echo h(!empty($row['transfer_date']) ? date('M d, Y', strtotime((string) $row['transfer_date'])) : ''); ?></td>
                                                        <td><?php echo h($row['system_reference'] ?? ''); ?></td>
                                                        <td><?php echo h(trim(implode(' | ', array_filter([
                                                            !empty($row['from_office_name']) ? 'From: ' . $row['from_office_name'] : '',
                                                            !empty($row['to_office_name']) ? 'To: ' . $row['to_office_name'] : '',
                                                            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
                                                        ])))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4">No transfer history.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Returns</h6>
                                <span class="badge text-bg-light"><?php echo count($returnRows); ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive mobile-table-frame">
                                    <table class="table table-sm mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Reference</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($returnRows): ?>
                                                <?php foreach ($returnRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo h(!empty($row['return_date']) ? date('M d, Y', strtotime((string) $row['return_date'])) : ''); ?></td>
                                                        <td><?php echo h($row['system_reference'] ?? ''); ?></td>
                                                        <td><?php echo h(trim(implode(' | ', array_filter([
                                                            !empty($row['office_name']) ? $row['office_name'] : '',
                                                            ($person = asset_view_person($row)) !== '' ? $person : '',
                                                            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
                                                        ])))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4">No return history.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Disposals</h6>
                                <span class="badge text-bg-light"><?php echo count($disposalRows); ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive mobile-table-frame">
                                    <table class="table table-sm mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Reference</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($disposalRows): ?>
                                                <?php foreach ($disposalRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo h(!empty($row['disposal_date']) ? date('M d, Y', strtotime((string) $row['disposal_date'])) : ''); ?></td>
                                                        <td><?php echo h($row['system_reference'] ?? ''); ?></td>
                                                        <td><?php echo h(trim(implode(' | ', array_filter([
                                                            !empty($row['disposal_type']) ? $row['disposal_type'] : '',
                                                            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
                                                            !empty($row['approved_by']) ? 'Approved by: ' . $row['approved_by'] : '',
                                                        ])))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4">No disposal history.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Maintenance</h6>
                                <span class="badge text-bg-light"><?php echo count($maintenanceRows); ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive mobile-table-frame">
                                    <table class="table table-sm mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Reference</th>
                                                <th>Work Done</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($maintenanceRows): ?>
                                                <?php foreach ($maintenanceRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo h(!empty($row['maintenance_date']) ? date('M d, Y', strtotime((string) $row['maintenance_date'])) : ''); ?></td>
                                                        <td><?php echo h($row['system_reference'] ?? ''); ?></td>
                                                        <td><?php echo h(trim(implode(' | ', array_filter([
                                                            $row['work_description'] ?? '',
                                                            !empty($row['performed_by']) ? 'By: ' . $row['performed_by'] : '',
                                                            isset($row['cost']) ? 'Cost: ' . number_format((float) $row['cost'], 2) : '',
                                                        ])))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4">No maintenance history.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
