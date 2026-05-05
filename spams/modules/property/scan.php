<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Property Custodian');

$ref = trim((string) ($_GET['ref'] ?? ''));
if ($ref === '') {
    ?><!doctype html>
    <html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Property Lookup</title></head><body>
    <div style="padding:16px;font-family:Arial, sans-serif;">Property not found for blank reference.</div>
    </body></html><?php
    exit;
}

$db = db();
$flash = get_flash();
$errors = [];
$row = null;
$inventoryMatch = null;
$inventoryConflict = false;
$latestInventoryCheck = null;
$canManageAssetUpdates = user_has_any_role('Administrator', 'Supply Officer', 'Property Officer');
$officeOptions = [];
$employeeOptions = [];

function employee_display_name_from_row(array $row): string
{
    if (function_exists('employee_display_name')) {
        return employee_display_name($row);
    }

    $parts = [trim((string) ($row['first_name'] ?? '')), trim((string) ($row['middle_name'] ?? '')), trim((string) ($row['last_name'] ?? '')), trim((string) ($row['suffix_name'] ?? ''))];
    return trim(implode(' ', array_filter($parts)));
}

function load_property_lookup_row_by_asset(mysqli $db, string $sourceType, int $assetId): ?array
{
    if ($assetId <= 0) {
        return null;
    }

    if ($sourceType === 'system') {
        $stmt = $db->prepare(
            "SELECT si.system_reference, did.property_number, si.item_description, si.item_type, si.unit_cost, si.quantity_received,
                    poi.item_description AS original_description,
                    c.classification_name,
                    ac.account_code, ac.account_name,
                    u.uom_name,
                    r.ris_no, r.received_date,
                    po.po_number,
                    s.supplier_name,
                    did.brand, did.model, did.serial_no, did.qr_tag_code,
                    d.document_no, d.document_type, d.distribution_date,
                    COALESCE(did.current_office_id, d.office_id) AS office_id,
                    o.office_name,
                    COALESCE(did.current_employee_id, d.employee_id) AS employee_id,
                    e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title,
                    '' AS condition_status,
                    did.id AS distribution_item_detail_id,
                    0 AS legacy_asset_id,
                    'system' AS source_type
             FROM distribution_item_details did
             LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
             LEFT JOIN stock_items si ON si.id = rid.stock_item_id
             LEFT JOIN receiving_items ri ON ri.id = rid.receiving_item_id
             LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
             LEFT JOIN classifications c ON c.id = COALESCE(poi.classification_id, si.classification_id)
             LEFT JOIN account_codes ac ON ac.id = COALESCE(poi.account_code_id, si.account_code_id)
             LEFT JOIN unit_of_measures u ON u.id = COALESCE(poi.unit_of_measure_id, si.unit_of_measure_id)
             LEFT JOIN receivings r ON r.id = COALESCE(ri.receiving_id, si.receiving_id)
             LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
             LEFT JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
             LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
             LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
             WHERE did.id = ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $assetId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            return $row;
        }
    }

    if ($sourceType === 'legacy') {
        $stmt = $db->prepare(
            "SELECT la.system_reference, la.property_number, la.property_number AS item_description, 'equipment' AS item_type, la.acquisition_cost AS unit_cost, 1 AS quantity_received,
                    la.item_description AS original_description, c.classification_name, ac.account_code, ac.account_name, '' AS uom_name,
                    '' AS ris_no, la.acquisition_date AS received_date, la.po_number, '' AS supplier_name,
                    la.brand, la.model, la.serial_no, la.qr_tag_code, 'Beginning Balance' AS document_no, 'legacy' AS document_type,
                    la.acquisition_date AS distribution_date, la.office_id,
                    o.office_name, la.employee_id,
                    e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title,
                    la.condition_status,
                    0 AS distribution_item_detail_id,
                    la.id AS legacy_asset_id,
                    'legacy' AS source_type
             FROM legacy_assets la
             LEFT JOIN classifications c ON c.id = la.classification_id
             LEFT JOIN account_codes ac ON ac.id = la.account_code_id
             LEFT JOIN offices o ON o.id = la.office_id
             LEFT JOIN employees e ON e.id = la.employee_id
             WHERE la.id = ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $assetId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            return $row;
        }
    }

    return null;
}

function load_property_lookup_row_by_reference(mysqli $db, string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT si.system_reference, did.property_number, si.item_description, si.item_type, si.unit_cost, si.quantity_received,
                poi.item_description AS original_description,
                c.classification_name,
                ac.account_code, ac.account_name,
                u.uom_name,
                r.ris_no, r.received_date,
                po.po_number,
                s.supplier_name,
                did.brand, did.model, did.serial_no, did.qr_tag_code,
                d.document_no, d.document_type, d.distribution_date,
                COALESCE(did.current_office_id, d.office_id) AS office_id,
                o.office_name,
                COALESCE(did.current_employee_id, d.employee_id) AS employee_id,
                e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title,
                '' AS condition_status,
                did.id AS distribution_item_detail_id,
                0 AS legacy_asset_id,
                'system' AS source_type
         FROM stock_items si
         LEFT JOIN receiving_items ri ON ri.id = si.receiving_item_id
         LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
         LEFT JOIN classifications c ON c.id = si.classification_id
         LEFT JOIN account_codes ac ON ac.id = si.account_code_id
         LEFT JOIN unit_of_measures u ON u.id = si.unit_of_measure_id
         LEFT JOIN receivings r ON r.id = si.receiving_id
         LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
         LEFT JOIN suppliers s ON s.id = po.supplier_id
         LEFT JOIN distribution_item_details did ON did.receiving_item_detail_id = (
             SELECT id FROM receiving_item_details WHERE receiving_item_id = ri.id LIMIT 1
         )
         LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
         LEFT JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
         LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
         LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
         WHERE did.property_number = ? OR si.system_reference = ? OR did.serial_no = ?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('sss', $ref, $ref, $ref);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            return $row;
        }
    }

    $legacyStmt = $db->prepare(
        "SELECT la.system_reference, la.property_number, la.property_number AS item_description, 'equipment' AS item_type, la.acquisition_cost AS unit_cost, 1 AS quantity_received,
                la.item_description AS original_description, c.classification_name, ac.account_code, ac.account_name, '' AS uom_name,
                '' AS ris_no, la.acquisition_date AS received_date, la.po_number, '' AS supplier_name,
                la.brand, la.model, la.serial_no, la.qr_tag_code, 'Beginning Balance' AS document_no, 'legacy' AS document_type,
                la.acquisition_date AS distribution_date, la.office_id,
                o.office_name, la.employee_id,
                e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title,
                la.condition_status,
                0 AS distribution_item_detail_id,
                la.id AS legacy_asset_id,
                'legacy' AS source_type
         FROM legacy_assets la
         LEFT JOIN classifications c ON c.id = la.classification_id
         LEFT JOIN account_codes ac ON ac.id = la.account_code_id
         LEFT JOIN offices o ON o.id = la.office_id
         LEFT JOIN employees e ON e.id = la.employee_id
         WHERE la.property_number = ? OR la.serial_no = ?
         LIMIT 1"
    );
    if ($legacyStmt) {
        $legacyStmt->bind_param('ss', $ref, $ref);
        $legacyStmt->execute();
        $row = $legacyStmt->get_result()->fetch_assoc() ?: null;
        $legacyStmt->close();
        if ($row) {
            return $row;
        }
    }

    return null;
}

function load_property_lookup_row(mysqli $db, string $ref): ?array
{
    property_qr_ensure_schema($db);
    $payload = property_qr_parse_payload($ref);

    if ($payload['tag_code'] !== '') {
        $assetRef = property_qr_find_asset_by_tag_code($db, $payload['tag_code']);
        if ($assetRef) {
            $row = load_property_lookup_row_by_asset($db, (string) ($assetRef['source_type'] ?? ''), (int) ($assetRef['id'] ?? 0));
            if ($row) {
                return $row;
            }
        }
    }

    if ($payload['property_number'] !== '') {
        $row = load_property_lookup_row_by_reference($db, $payload['property_number']);
        if ($row) {
            return $row;
        }
    }

    if ($payload['serial_number'] !== '') {
        $row = load_property_lookup_row_by_reference($db, $payload['serial_number']);
        if ($row) {
            return $row;
        }
    }

    return load_property_lookup_row_by_reference($db, $payload['raw']);
}

function find_active_inventory_match(mysqli $db, string $propertyNumber, int $officeId = 0): array
{
    $matches = [];
    $stmt = $db->prepare(
        "SELECT ici.id, ici.status, ici.remarks, ici.proof_photo_path, ici.session_id,
                ics.system_reference AS session_reference, ics.count_type, ics.count_date, ics.office_id,
                o.office_name
         FROM inventory_count_items ici
         INNER JOIN inventory_count_sessions ics ON ics.id = ici.session_id
         LEFT JOIN offices o ON o.id = ics.office_id
         WHERE ics.status = 'open'
           AND ici.property_number = ?
           AND (? = 0 OR ics.office_id = ? OR ici.office_id = ?)
         ORDER BY ics.count_date DESC, ics.id DESC"
    );
    if (!$stmt) {
        return $matches;
    }

    $stmt->bind_param('siii', $propertyNumber, $officeId, $officeId, $officeId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $matches[] = $row;
    }
    $stmt->close();

    return $matches;
}

function find_latest_inventory_check(mysqli $db, string $propertyNumber): ?array
{
    $propertyNumber = trim($propertyNumber);
    if ($propertyNumber === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT ici.id,
                ici.status,
                ici.remarks,
                ici.checked_at,
                ici.proof_photo_path,
                ici.session_id,
                ics.system_reference AS session_reference,
                ics.count_type,
                ics.count_date,
                o.office_name
         FROM inventory_count_items ici
         INNER JOIN inventory_count_sessions ics ON ics.id = ici.session_id
         LEFT JOIN offices o ON o.id = ics.office_id
         WHERE ici.property_number = ?
           AND ici.checked_at IS NOT NULL
         ORDER BY ici.checked_at DESC, ici.id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $propertyNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row ?: null;
}

function load_primary_asset_photo(mysqli $db, string $assetSource, int $assetId): ?array
{
    if (!in_array($assetSource, ['system', 'legacy'], true) || $assetId <= 0) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT photo_path, caption
         FROM asset_photos
         WHERE asset_source = ? AND asset_id = ?
         ORDER BY is_primary DESC, created_at DESC, id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('si', $assetSource, $assetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

if ($db) {
    $row = load_property_lookup_row($db, $ref);

    if ($row && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update_asset_profile') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif (!$canManageAssetUpdates) {
            $errors[] = 'You are not allowed to update asset assignment from this page.';
        } else {
            $sourceType = (string) ($row['source_type'] ?? 'system');
            $newOfficeId = (int) ($_POST['office_id'] ?? 0);
            $newEmployeeId = (int) ($_POST['employee_id'] ?? 0);
            $newConditionStatus = strtolower(trim((string) ($_POST['condition_status'] ?? '')));
            $mobileNote = trim((string) ($_POST['mobile_note'] ?? ''));

            if ($newOfficeId <= 0) {
                $errors[] = 'Select an office assignment.';
            }

            $allowedConditions = ['good', 'fair', 'needs_repair', 'unserviceable', 'disposed'];
            if (!in_array($newConditionStatus, $allowedConditions, true)) {
                $newConditionStatus = 'good';
            }

            if (empty($errors)) {
                $db->begin_transaction();
                try {
                    $userId = (int) (current_user_id() ?? 0);
                    $assetRef = trim((string) ($row['property_number'] ?? $row['system_reference'] ?? ''));

                    if ($sourceType === 'legacy') {
                        $legacyId = (int) ($row['legacy_asset_id'] ?? 0);
                        $stmt = $db->prepare(
                            "UPDATE legacy_assets
                             SET office_id = ?,
                                 employee_id = NULLIF(?, 0),
                                 condition_status = ?,
                                 remarks = CASE
                                     WHEN ? = '' THEN remarks
                                     WHEN remarks IS NULL OR remarks = '' THEN ?
                                     ELSE CONCAT(remarks, '\\n', ?)
                                 END
                             WHERE id = ?
                             LIMIT 1"
                        );
                        if (!$stmt) {
                            throw new RuntimeException('Unable to prepare legacy asset update.');
                        }
                        $noteLine = $mobileNote !== '' ? 'Mobile update: ' . $mobileNote : '';
                        $stmt->bind_param('iissssi', $newOfficeId, $newEmployeeId, $newConditionStatus, $noteLine, $noteLine, $noteLine, $legacyId);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $detailId = (int) ($row['distribution_item_detail_id'] ?? 0);
                        $noteLine = $mobileNote !== '' ? 'Mobile update: ' . $mobileNote : '';

                        if (schema_has_column($db, 'distribution_item_details', 'condition_status')) {
                            $stmt = $db->prepare(
                                "UPDATE distribution_item_details
                                 SET current_office_id = ?,
                                     current_employee_id = NULLIF(?, 0),
                                     condition_status = ?,
                                     remarks = CASE
                                         WHEN ? = '' THEN remarks
                                         WHEN remarks IS NULL OR remarks = '' THEN ?
                                         ELSE CONCAT(remarks, '\\n', ?)
                                     END
                                 WHERE id = ?
                                 LIMIT 1"
                            );
                            if (!$stmt) {
                                throw new RuntimeException('Unable to prepare system asset update.');
                            }
                            $stmt->bind_param('iissssi', $newOfficeId, $newEmployeeId, $newConditionStatus, $noteLine, $noteLine, $noteLine, $detailId);
                        } else {
                            $stmt = $db->prepare(
                                "UPDATE distribution_item_details
                                 SET current_office_id = ?,
                                     current_employee_id = NULLIF(?, 0),
                                     remarks = CASE
                                         WHEN ? = '' THEN remarks
                                         WHEN remarks IS NULL OR remarks = '' THEN ?
                                         ELSE CONCAT(remarks, '\\n', ?)
                                     END
                                 WHERE id = ?
                                 LIMIT 1"
                            );
                            if (!$stmt) {
                                throw new RuntimeException('Unable to prepare system asset update.');
                            }
                            $stmt->bind_param('iisssi', $newOfficeId, $newEmployeeId, $noteLine, $noteLine, $noteLine, $detailId);
                        }
                        $stmt->execute();
                        $stmt->close();
                    }

                    if (function_exists('write_audit_log')) {
                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => $sourceType === 'legacy' ? 'legacy_assets' : 'distribution_item_details',
                            'record_id' => $sourceType === 'legacy' ? (int) ($row['legacy_asset_id'] ?? 0) : (int) ($row['distribution_item_detail_id'] ?? 0),
                            'module_name' => 'property_scan',
                            'record_type' => 'asset',
                            'action_name' => 'mobile_assignment_condition_update',
                            'old_values' => [
                                'office_id' => (int) ($row['office_id'] ?? 0),
                                'employee_id' => (int) ($row['employee_id'] ?? 0),
                                'condition_status' => (string) ($row['condition_status'] ?? ''),
                            ],
                            'new_values' => [
                                'office_id' => $newOfficeId,
                                'employee_id' => $newEmployeeId,
                                'condition_status' => $newConditionStatus,
                                'mobile_note' => $mobileNote,
                                'property_reference' => $assetRef,
                            ],
                            'description' => 'Updated assignment/condition from mobile QR asset page.',
                        ]);
                    }

                    $db->commit();
                    set_flash('success', 'Asset assignment updated from mobile page.');
                    redirect('modules/property/scan.php?ref=' . urlencode($ref));
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Unable to update asset assignment.';
                }
            }
        }
    }

    if ($row && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'mark_found') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } else {
            $propertyNumber = trim((string) ($row['property_number'] ?? $row['system_reference'] ?? ''));
            $officeId = (int) ($row['office_id'] ?? 0);
            $matches = find_active_inventory_match($db, $propertyNumber, $officeId);

            if (count($matches) !== 1) {
                $errors[] = 'This asset does not have exactly one open inventory session match, so it cannot be marked as found from this page.';
            } else {
                $match = $matches[0];
                $uploadedProofFile = ($_FILES['camera_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
                    ? ($_FILES['camera_photo'] ?? [])
                    : ($_FILES['proof_photo'] ?? []);
                $photoRoot = $db ? trim(get_system_setting($db, 'inventory_photo_root', 'inventory_counts')) : 'inventory_counts';
                $photoRoot = trim(str_replace(['..', '\\'], ['', '/'], $photoRoot), " /\t\n\r\0\x0B");
                if ($photoRoot === '') {
                    $photoRoot = 'inventory_counts';
                }
                $sessionFolder = $photoRoot . '/' . date('Y') . '/session-' . (int) ($match['session_id'] ?? 0);
                $proofPhotoPath = store_uploaded_image($uploadedProofFile, $sessionFolder, $errors);

                if (($match['status'] ?? '') === 'found' && $proofPhotoPath === null) {
                    set_flash('success', 'This asset is already marked as found in the active inventory session.');
                    redirect('modules/property/scan.php?ref=' . urlencode($ref));
                }

                if (empty($errors)) {
                    $itemId = (int) ($match['id'] ?? 0);
                    $sessionId = (int) ($match['session_id'] ?? 0);
                    $userId = current_user_id();
                    $updateStmt = $db->prepare(
                        "UPDATE inventory_count_items
                         SET status = 'found',
                             checked_at = NOW(),
                             checked_by = ?,
                             proof_photo_path = COALESCE(NULLIF(?, ''), proof_photo_path),
                             remarks = CASE
                                 WHEN remarks IS NULL OR remarks = '' THEN 'Marked found via QR asset page'
                                 ELSE remarks
                             END
                         WHERE id = ? AND session_id = ?"
                    );
                    if ($updateStmt) {
                        $updateStmt->bind_param('isii', $userId, $proofPhotoPath, $itemId, $sessionId);
                        $ok = $updateStmt->execute();
                        $updateStmt->close();

                        if ($ok) {
                            if ($proofPhotoPath !== null && $proofPhotoPath !== '') {
                                $assetSource = (string) ($row['source_type'] ?? 'system');
                                $assetId = $assetSource === 'legacy'
                                    ? (int) ($row['legacy_asset_id'] ?? 0)
                                    : (int) ($row['distribution_item_detail_id'] ?? 0);
                                if ($assetId > 0) {
                                    $sessionReference = (string) ($match['session_reference'] ?? '');
                                    $countDateLabel = !empty($match['count_date']) ? date('M d, Y', strtotime((string) $match['count_date'])) : date('M d, Y');
                                    $caption = 'Annual inventory photo';
                                    if ($sessionReference !== '') {
                                        $caption .= ' - ' . $sessionReference;
                                    }
                                    $caption .= ' - ' . $countDateLabel;

                                    $existingPhoto = null;
                                    $existingPhotoStmt = $db->prepare(
                                        "SELECT id, photo_path
                                         FROM asset_photos
                                         WHERE asset_source = ? AND asset_id = ?
                                         ORDER BY is_primary DESC, created_at DESC, id DESC
                                         LIMIT 1"
                                    );
                                    if ($existingPhotoStmt) {
                                        $existingPhotoStmt->bind_param('si', $assetSource, $assetId);
                                        $existingPhotoStmt->execute();
                                        $existingPhoto = $existingPhotoStmt->get_result()->fetch_assoc() ?: null;
                                        $existingPhotoStmt->close();
                                    }

                                    if ($existingPhoto) {
                                        $resetPrimaryStmt = $db->prepare(
                                            "UPDATE asset_photos
                                             SET is_primary = 0
                                             WHERE asset_source = ? AND asset_id = ?"
                                        );
                                        if ($resetPrimaryStmt) {
                                            $resetPrimaryStmt->bind_param('si', $assetSource, $assetId);
                                            $resetPrimaryStmt->execute();
                                            $resetPrimaryStmt->close();
                                        }

                                        $updatePhotoStmt = $db->prepare(
                                            "UPDATE asset_photos
                                             SET photo_path = ?, caption = ?, uploaded_by = ?, is_primary = 1
                                             WHERE id = ? LIMIT 1"
                                        );
                                        if ($updatePhotoStmt) {
                                            $existingPhotoId = (int) ($existingPhoto['id'] ?? 0);
                                            $updatePhotoStmt->bind_param('ssii', $proofPhotoPath, $caption, $userId, $existingPhotoId);
                                            $updatePhotoStmt->execute();
                                            $updatePhotoStmt->close();

                                            $oldPhotoPath = trim((string) ($existingPhoto['photo_path'] ?? ''));
                                            if ($oldPhotoPath !== '' && $oldPhotoPath !== $proofPhotoPath) {
                                                delete_uploaded_file($oldPhotoPath);
                                            }
                                        }
                                    } else {
                                        $photoStmt = $db->prepare(
                                            "INSERT INTO asset_photos (asset_source, asset_id, photo_path, caption, is_primary, uploaded_by)
                                             VALUES (?, ?, ?, ?, 1, ?)"
                                        );
                                        if ($photoStmt) {
                                            $photoStmt->bind_param('sissi', $assetSource, $assetId, $proofPhotoPath, $caption, $userId);
                                            $photoStmt->execute();
                                            $photoStmt->close();
                                        }
                                    }
                                }
                            }

                            if (function_exists('write_audit_log')) {
                                write_audit_log($db, [
                                    'action' => 'update',
                                    'table_name' => 'inventory_count_items',
                                    'record_id' => $itemId,
                                    'module_name' => 'inventory_counts',
                                    'record_type' => 'inventory_count_item',
                                    'action_name' => 'mark_inventory_item_found_from_qr_page',
                                    'old_values' => ['status' => $match['status'] ?? 'pending'],
                                    'new_values' => [
                                        'status' => 'found',
                                        'property_number' => $propertyNumber,
                                        'proof_photo_path' => $proofPhotoPath !== null ? $proofPhotoPath : ($match['proof_photo_path'] ?? ''),
                                    ],
                                    'description' => 'Marked inventory count item as found from the QR asset page.',
                                ]);
                            }

                            set_flash('success', 'Asset ' . $propertyNumber . ' marked as found.' . ($proofPhotoPath ? ' Photo proof saved.' : ''));
                            redirect('modules/property/scan.php?ref=' . urlencode($ref));
                        }
                    }

                    $errors[] = 'Unable to mark this asset as found.';
                }
            }
        }
    }

    if ($row) {
        if ($canManageAssetUpdates) {
            $officeRes = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
            if ($officeRes) {
                $officeOptions = $officeRes->fetch_all(MYSQLI_ASSOC);
            }

            $employeeRes = $db->query("SELECT id, first_name, middle_name, last_name, suffix_name FROM employees WHERE is_active = 1 ORDER BY last_name ASC, first_name ASC");
            if ($employeeRes) {
                $employeeOptions = $employeeRes->fetch_all(MYSQLI_ASSOC);
            }
        }

        $propertyNumber = trim((string) ($row['property_number'] ?? $row['system_reference'] ?? ''));
        $officeId = (int) ($row['office_id'] ?? 0);
        $matches = find_active_inventory_match($db, $propertyNumber, $officeId);
        $latestInventoryCheck = find_latest_inventory_check($db, $propertyNumber);
        if (count($matches) === 1) {
            $inventoryMatch = $matches[0];
        } elseif (count($matches) > 1) {
            $inventoryConflict = true;
        }
    }
}

if (!$row) {
    ?><!doctype html>
    <html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Property Not Found</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body>
    <div class="container py-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Property not found</h5>
                <p class="card-text">Reference: <?php echo h($ref); ?></p>
                <a href="javascript:history.back()" class="btn btn-sm btn-secondary">Back</a>
            </div>
        </div>
    </div>
    </body></html><?php
    exit;
}

$empName = employee_display_name_from_row($row);
$propertyDisplay = (string) ($row['property_number'] ?? $row['system_reference'] ?? $ref);
$descriptionDisplay = trim((string) ($row['item_description'] ?? '')) !== '' ? (string) $row['item_description'] : (string) ($row['original_description'] ?? '');
$brandModel = trim(implode(' / ', array_filter([trim((string) ($row['brand'] ?? '')), trim((string) ($row['model'] ?? ''))])));
$inventoryUrl = $inventoryMatch ? base_url('modules/property/inventory_counts.php?session_id=' . (int) $inventoryMatch['session_id'] . '&highlight_item_id=' . (int) $inventoryMatch['id']) : '';
$proofPhotoUrl = $inventoryMatch ? upload_url((string) ($inventoryMatch['proof_photo_path'] ?? '')) : '';
$latestInventoryProofUrl = $latestInventoryCheck ? upload_url((string) ($latestInventoryCheck['proof_photo_path'] ?? '')) : '';
$assetPhotoPath = '';
$assetPhotoCaption = '';
if ($db) {
    $assetSource = (string) ($row['source_type'] ?? 'system');
    $assetId = $assetSource === 'legacy'
        ? (int) ($row['legacy_asset_id'] ?? 0)
        : (int) ($row['distribution_item_detail_id'] ?? 0);
    $assetPhoto = load_primary_asset_photo($db, $assetSource, $assetId);
    if ($assetPhoto) {
        $assetPhotoPath = (string) ($assetPhoto['photo_path'] ?? '');
        $assetPhotoCaption = trim((string) ($assetPhoto['caption'] ?? ''));
    }
}
$assetPhotoUrl = upload_url($assetPhotoPath);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Property <?php echo h($propertyDisplay); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f7f9fc; padding: 16px; font-family: Arial, sans-serif; }
        .page-shell { max-width: 920px; margin: 0 auto; }
        .property-card { border: 0; box-shadow: 0 8px 30px rgba(15, 35, 95, 0.08); }
        .kv { font-weight: 600; color: #52627d; }
        .value-block { background: #fff; border: 1px solid #e6ebf3; border-radius: 12px; padding: 14px 16px; height: 100%; }
        .proof-photo { width: 100%; max-width: 260px; border-radius: 12px; border: 1px solid #dbe4f0; }
    </style>
</head>
<body>
    <div class="page-shell">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>"><?php echo h($flash['message']); ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo h($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card property-card mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <div class="text-uppercase small text-muted fw-semibold">QR Asset Page</div>
                        <h3 class="mb-1"><?php echo h(APP_NAME); ?></h3>
                        <div class="text-muted">Property details for the scanned asset.</div>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Property Number</div>
                        <div class="fs-5 fw-semibold"><?php echo h($propertyDisplay); ?></div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-8">
                        <div class="value-block">
                            <div class="kv mb-1">Description</div>
                            <div class="fw-semibold"><?php echo h($descriptionDisplay); ?></div>
                            <div class="small text-muted mt-2"><?php echo h(trim(implode(' | ', array_filter([(string) ($row['item_type'] ?? ''), (string) ($row['classification_name'] ?? ''), (string) ($row['account_code'] ?? '')])))); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="value-block">
                            <div class="kv mb-1">Current Assignment</div>
                            <div><?php echo h((string) ($row['office_name'] ?? '')); ?></div>
                            <div class="small text-muted"><?php echo h($empName !== '' ? $empName : 'No accountable employee'); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($assetPhotoUrl !== ''): ?>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="value-block">
                                <div class="kv mb-2">Asset Photo</div>
                                <a href="<?php echo h($assetPhotoUrl); ?>" target="_blank" rel="noopener">
                                    <img src="<?php echo h($assetPhotoUrl); ?>" alt="Asset photo" class="proof-photo">
                                </a>
                                <?php if ($assetPhotoCaption !== ''): ?>
                                    <div class="small text-muted mt-2"><?php echo h($assetPhotoCaption); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6 col-lg-4"><div class="value-block"><div class="kv">Brand / Model</div><div><?php echo h($brandModel !== '' ? $brandModel : 'Not recorded'); ?></div></div></div>
                    <div class="col-md-6 col-lg-4"><div class="value-block"><div class="kv">Serial Number</div><div><?php echo h((string) ($row['serial_no'] ?? '') !== '' ? (string) $row['serial_no'] : 'Not recorded'); ?></div></div></div>
                    <div class="col-md-6 col-lg-4"><div class="value-block"><div class="kv">Unit Cost</div><div><?php echo isset($row['unit_cost']) ? h(number_format((float) $row['unit_cost'], 2)) : '-'; ?></div></div></div>
                    <div class="col-md-6 col-lg-4"><div class="value-block"><div class="kv">Date Acquired</div><div><?php echo h(!empty($row['received_date']) ? date('M d, Y', strtotime((string) $row['received_date'])) : ''); ?></div></div></div>
                    <div class="col-md-6 col-lg-4"><div class="value-block"><div class="kv">Supplier</div><div><?php echo h((string) ($row['supplier_name'] ?? '') !== '' ? (string) $row['supplier_name'] : 'Not recorded'); ?></div></div></div>
                    <div class="col-md-6 col-lg-4"><div class="value-block"><div class="kv">PO Number</div><div><?php echo h((string) ($row['po_number'] ?? '') !== '' ? (string) $row['po_number'] : 'Not recorded'); ?></div></div></div>
                    <div class="col-md-6 col-lg-4"><div class="value-block"><div class="kv">Condition Status</div><div><?php echo h($row['condition_status'] !== '' ? ucwords(str_replace('_', ' ', (string) $row['condition_status'])) : 'Not recorded'); ?></div></div></div>
                </div>
            </div>
        </div>

        <?php if ($canManageAssetUpdates): ?>
            <div class="card property-card mb-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                        <div>
                            <h5 class="mb-1">Mobile Quick Update</h5>
                            <div class="text-muted small">Update assignment and condition while onsite after scanning the asset QR tag.</div>
                        </div>
                        <span class="badge text-bg-info">Field Update</span>
                    </div>
                    <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_asset_profile">
                        <div class="col-md-4">
                            <label class="form-label">Office</label>
                            <select class="form-select" name="office_id" required>
                                <option value="">Select office</option>
                                <?php foreach ($officeOptions as $officeOption): ?>
                                    <option value="<?php echo (int) $officeOption['id']; ?>" <?php echo (int) ($row['office_id'] ?? 0) === (int) $officeOption['id'] ? 'selected' : ''; ?>><?php echo h((string) $officeOption['office_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Accountable Employee</label>
                            <select class="form-select" name="employee_id">
                                <option value="0">Unassigned</option>
                                <?php foreach ($employeeOptions as $employeeOption): ?>
                                    <option value="<?php echo (int) $employeeOption['id']; ?>" <?php echo (int) ($row['employee_id'] ?? 0) === (int) $employeeOption['id'] ? 'selected' : ''; ?>><?php echo h(employee_display_name($employeeOption)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Condition</label>
                            <select class="form-select" name="condition_status">
                                <?php
                                $conditionOptions = [
                                    'good' => 'Good',
                                    'fair' => 'Fair',
                                    'needs_repair' => 'Needs Repair',
                                    'unserviceable' => 'Unserviceable',
                                    'disposed' => 'Disposed',
                                ];
                                $selectedCondition = strtolower(trim((string) ($row['condition_status'] ?? '')));
                                if (!isset($conditionOptions[$selectedCondition])) {
                                    $selectedCondition = 'good';
                                }
                                ?>
                                <?php foreach ($conditionOptions as $conditionValue => $conditionLabel): ?>
                                    <option value="<?php echo h($conditionValue); ?>" <?php echo $selectedCondition === $conditionValue ? 'selected' : ''; ?>><?php echo h($conditionLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Field Note (optional)</label>
                            <input type="text" class="form-control" name="mobile_note" maxlength="255" placeholder="Location update, condition observation, or accountability note">
                        </div>
                        <div class="col-12 d-grid d-md-flex gap-2 justify-content-md-end">
                            <button type="submit" class="btn btn-primary">Save Mobile Update</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card property-card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                    <div>
                        <h5 class="mb-1">Annual Inventory</h5>
                        <div class="text-muted small">If this asset belongs to an open inventory session, you can mark it as found from this page and optionally attach a proof photo.</div>
                    </div>
                    <?php if ($inventoryMatch): ?>
                        <span class="badge <?php echo ($inventoryMatch['status'] ?? '') === 'found' ? 'text-bg-success' : 'text-bg-warning'; ?> fs-6">
                            <?php echo h(($inventoryMatch['status'] ?? '') === 'found' ? 'Already Found' : 'Open Session Match'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($latestInventoryCheck): ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="value-block h-100">
                                <div class="kv mb-1">Last Checked</div>
                                <div class="fw-semibold"><?php echo h(!empty($latestInventoryCheck['checked_at']) ? date('M d, Y g:i A', strtotime((string) $latestInventoryCheck['checked_at'])) : ''); ?></div>
                                <div class="small text-muted mt-1"><?php echo h(ucfirst((string) ($latestInventoryCheck['status'] ?? 'pending'))); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="value-block h-100">
                                <div class="kv mb-1">Last Session</div>
                                <div class="fw-semibold"><?php echo h((string) ($latestInventoryCheck['session_reference'] ?? '')); ?></div>
                                <div class="small text-muted mt-1"><?php echo h(ucfirst((string) ($latestInventoryCheck['count_type'] ?? 'annual'))); ?> count<?php echo !empty($latestInventoryCheck['count_date']) ? ' | ' . h(date('M d, Y', strtotime((string) $latestInventoryCheck['count_date']))) : ''; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="value-block h-100">
                                <div class="kv mb-1">Last Check Office</div>
                                <div class="fw-semibold"><?php echo h((string) ($latestInventoryCheck['office_name'] ?? 'Unassigned')); ?></div>
                                <?php if (!empty($latestInventoryCheck['remarks'])): ?>
                                    <div class="small text-muted mt-1"><?php echo h((string) $latestInventoryCheck['remarks']); ?></div>
                                <?php elseif ($latestInventoryProofUrl !== ''): ?>
                                    <div class="small text-muted mt-1">Proof photo saved</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($inventoryConflict): ?>
                    <div class="alert alert-warning mb-0">More than one open inventory session matches this asset. Open the inventory workspace to update it safely.</div>
                <?php elseif ($inventoryMatch): ?>
                    <div class="row g-3 align-items-start">
                        <div class="col-lg-7">
                            <div class="value-block h-100">
                                <div class="kv mb-1">Active Session</div>
                                <div class="fw-semibold"><?php echo h((string) ($inventoryMatch['session_reference'] ?? '')); ?></div>
                                <div class="small text-muted mt-1"><?php echo h((string) ($inventoryMatch['office_name'] ?? '')); ?> | <?php echo h(ucfirst((string) ($inventoryMatch['count_type'] ?? 'annual'))); ?> count | <?php echo h(!empty($inventoryMatch['count_date']) ? date('M d, Y', strtotime((string) $inventoryMatch['count_date'])) : ''); ?></div>
                                <?php if (!empty($inventoryMatch['remarks'])): ?>
                                    <div class="small text-muted mt-2">Remarks: <?php echo h((string) $inventoryMatch['remarks']); ?></div>
                                <?php endif; ?>
                                <?php if ($proofPhotoUrl !== ''): ?>
                                    <div class="mt-3">
                                        <div class="kv mb-2">Saved Proof Photo</div>
                                        <a href="<?php echo h($proofPhotoUrl); ?>" target="_blank" rel="noopener">
                                            <img src="<?php echo h($proofPhotoUrl); ?>" alt="Proof photo" class="proof-photo">
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="value-block h-100">
                                <div class="kv mb-2">Update Count Status</div>
                                <?php if (($inventoryMatch['status'] ?? '') === 'found'): ?>
                                    <div class="alert alert-success small">This asset is already marked as found. You can still upload a proof photo to keep with the count record.</div>
                                <?php endif; ?>
                                <form method="post" enctype="multipart/form-data" class="d-grid gap-3">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="mark_found">
                                    <div>
                                        <label for="camera_photo" class="form-label">Open Camera</label>
                                        <input type="file" class="form-control" id="camera_photo" name="camera_photo" accept="image/*" capture="environment">
                                        <div class="form-text">On mobile, this opens the rear camera when the browser supports direct capture.</div>
                                    </div>
                                    <div>
                                        <label for="proof_photo" class="form-label">Upload Photo</label>
                                        <input type="file" class="form-control" id="proof_photo" name="proof_photo" accept="image/jpeg,image/png,image/webp,image/gif">
                                        <div class="form-text">Optional. JPG, PNG, GIF, or WEBP up to 5 MB.</div>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-lg"><?php echo ($inventoryMatch['status'] ?? '') === 'found' ? 'Save Proof Photo' : 'Mark as Found'; ?></button>
                                    <?php if ($inventoryUrl !== ''): ?>
                                        <a href="<?php echo h($inventoryUrl); ?>" class="btn btn-outline-secondary">Open Inventory Workspace</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary mb-0">No open inventory session currently matches this asset. You can still review the item information here.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>




