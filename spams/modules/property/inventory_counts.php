<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$page_title = 'Inventory Count Workspace';
$flash = get_flash();
$errors = [];
$offices = [];
$sessions = [];
$selectedSession = null;
$sessionItems = [];
$sessionStats = [
    'total' => 0,
    'pending' => 0,
    'found' => 0,
    'exceptions' => 0,
];
$statusLabels = [
    'pending' => 'Pending',
    'found' => 'Found',
    'missing' => 'Missing',
    'for_repair' => 'For Repair',
    'for_disposal' => 'For Disposal',
    'wrong_office' => 'Wrong Office',
    'wrong_accountable' => 'Wrong Accountable',
];
$statusBadgeClasses = [
    'pending' => 'text-bg-secondary',
    'found' => 'text-bg-success',
    'missing' => 'text-bg-danger',
    'for_repair' => 'text-bg-warning',
    'for_disposal' => 'text-bg-dark',
    'wrong_office' => 'text-bg-info',
    'wrong_accountable' => 'text-bg-primary',
];
$countTypes = [
    'annual' => 'Annual Inventory',
    'surprise' => 'Surprise Check',
];
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$allowedStatusFilters = array_merge([''], array_keys($statusLabels));
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}

$selectedSessionId = (int) ($_GET['session_id'] ?? 0);
$highlightItemId = (int) ($_GET['highlight_item_id'] ?? 0);
$scanFeedback = trim((string) ($_GET['scan_feedback'] ?? ''));
$referencePreview = $db ? preview_module_code($db, 'inventory_counts') : '';

function build_inventory_count_url(array $overrides = []): string
{
    $params = [
        'session_id' => $_GET['session_id'] ?? '',
        'status' => $_GET['status'] ?? '',
        'highlight_item_id' => $_GET['highlight_item_id'] ?? '',
        'scan_feedback' => $_GET['scan_feedback'] ?? '',
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    return '?' . http_build_query(array_filter($params, static function ($value) {
        return $value !== '' && $value !== null;
    }));
}

function normalize_inventory_count_status(string $status): string
{
    $allowed = ['pending', 'found', 'missing', 'for_repair', 'for_disposal', 'wrong_office', 'wrong_accountable'];
    return in_array($status, $allowed, true) ? $status : 'pending';
}

function extract_scanned_property_reference(string $rawValue): string
{
    $rawValue = trim($rawValue);
    if ($rawValue === '') {
        return '';
    }

    if (filter_var($rawValue, FILTER_VALIDATE_URL)) {
        $query = parse_url($rawValue, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $ref = trim((string) ($params['ref'] ?? ''));
            if ($ref !== '') {
                return $ref;
            }
        }
    }

    return $rawValue;
}

if ($db) {
    $officeResult = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult instanceof mysqli_result) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }

        if (empty($errors) && $action === 'create_session') {
            $countType = trim((string) ($_POST['count_type'] ?? 'annual'));
            $officeId = (int) ($_POST['office_id'] ?? 0);
            $countDate = trim((string) ($_POST['count_date'] ?? date('Y-m-d')));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if (!isset($countTypes[$countType])) {
                $errors[] = 'Invalid count type.';
            }
            if ($officeId <= 0) {
                $errors[] = 'Select an office for the count session.';
            }
            if ($countDate === '') {
                $errors[] = 'Count date is required.';
            }

            if (empty($errors)) {
                $db->begin_transaction();

                try {
                    $reference = next_module_code($db, 'inventory_counts');
                    $userId = current_user_id();

                    $sessionStmt = $db->prepare("
                        INSERT INTO inventory_count_sessions
                            (system_reference, count_type, office_id, count_date, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    if (!$sessionStmt) {
                        throw new RuntimeException('Unable to create count session.');
                    }

                    $sessionStmt->bind_param('ssissi', $reference, $countType, $officeId, $countDate, $notes, $userId);
                    if (!$sessionStmt->execute()) {
                        $sessionStmt->close();
                        throw new RuntimeException('Unable to save count session.');
                    }

                    $sessionId = (int) $sessionStmt->insert_id;
                    $sessionStmt->close();

                    $systemAssets = [];
                    $systemStmt = $db->prepare("
                        SELECT
                            did.id AS source_id,
                            did.property_number,
                            poi.item_type,
                            c.classification_name,
                            poi.item_description,
                            did.brand,
                            did.model,
                            did.serial_no,
                            COALESCE(curr_o.id, d.office_id) AS office_id,
                            COALESCE(curr_e.id, d.employee_id) AS employee_id,
                            TRIM(CONCAT_WS(' ',
                                COALESCE(curr_e.first_name, base_e.first_name),
                                COALESCE(curr_e.middle_name, base_e.middle_name),
                                COALESCE(curr_e.last_name, base_e.last_name),
                                COALESCE(curr_e.suffix_name, base_e.suffix_name)
                            )) AS accountable_name
                        FROM distribution_item_details did
                        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
                        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
                        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                        LEFT JOIN classifications c ON c.id = poi.classification_id
                        LEFT JOIN employees base_e ON base_e.id = d.employee_id
                        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
                        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
                        WHERE did.is_distributed = 1
                          AND (did.is_disposed IS NULL OR did.is_disposed = 0)
                          AND poi.item_type IN ('equipment', 'semi_expendable')
                          AND COALESCE(did.current_office_id, d.office_id) = ?
                        ORDER BY poi.item_type ASC, poi.item_description ASC, did.property_number ASC
                    ");

                    if ($systemStmt) {
                        $systemStmt->bind_param('i', $officeId);
                        $systemStmt->execute();
                        $systemAssets = $systemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $systemStmt->close();
                    }

                    $legacyAssets = [];
                    $legacyStmt = $db->prepare("
                        SELECT
                            la.id AS source_id,
                            la.property_number,
                            la.item_type,
                            c.classification_name,
                            la.item_description,
                            la.brand,
                            la.model,
                            la.serial_no,
                            la.office_id,
                            la.employee_id,
                            TRIM(CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name, e.suffix_name)) AS accountable_name
                        FROM legacy_assets la
                        LEFT JOIN classifications c ON c.id = la.classification_id
                        LEFT JOIN employees e ON e.id = la.employee_id
                        WHERE la.is_active = 1
                          AND la.item_type IN ('equipment', 'semi_expendable')
                          AND la.office_id = ?
                        ORDER BY la.item_type ASC, la.item_description ASC, la.property_number ASC
                    ");

                    if ($legacyStmt) {
                        $legacyStmt->bind_param('i', $officeId);
                        $legacyStmt->execute();
                        $legacyAssets = $legacyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $legacyStmt->close();
                    }

                    $itemStmt = $db->prepare("
                        INSERT INTO inventory_count_items
                            (session_id, source_type, distribution_item_detail_id, legacy_asset_id, property_number, item_type, office_id, employee_id, classification_name, item_description, brand, model, serial_no, accountable_name)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    if (!$itemStmt) {
                        throw new RuntimeException('Unable to prepare count items.');
                    }

                    $loadedCount = 0;
                    foreach ($systemAssets as $asset) {
                        $sourceType = 'system';
                        $distributionDetailId = (int) $asset['source_id'];
                        $legacyAssetId = null;
                        $propertyNumber = (string) $asset['property_number'];
                        $itemTypeValue = (string) $asset['item_type'];
                        $itemOfficeId = (int) ($asset['office_id'] ?? 0);
                        $itemEmployeeId = !empty($asset['employee_id']) ? (int) $asset['employee_id'] : null;
                        $classificationName = (string) ($asset['classification_name'] ?? '');
                        $itemDescription = (string) $asset['item_description'];
                        $brand = (string) ($asset['brand'] ?? '');
                        $model = (string) ($asset['model'] ?? '');
                        $serialNo = (string) ($asset['serial_no'] ?? '');
                        $accountableName = (string) ($asset['accountable_name'] ?? '');
                        $itemStmt->bind_param('isiissiissssss', $sessionId, $sourceType, $distributionDetailId, $legacyAssetId, $propertyNumber, $itemTypeValue, $itemOfficeId, $itemEmployeeId, $classificationName, $itemDescription, $brand, $model, $serialNo, $accountableName);
                        if (!$itemStmt->execute()) {
                            throw new RuntimeException('Unable to preload system assets.');
                        }
                        $loadedCount++;
                    }

                    foreach ($legacyAssets as $asset) {
                        $sourceType = 'legacy';
                        $distributionDetailId = null;
                        $legacyAssetId = (int) $asset['source_id'];
                        $propertyNumber = (string) $asset['property_number'];
                        $itemTypeValue = (string) $asset['item_type'];
                        $itemOfficeId = (int) ($asset['office_id'] ?? 0);
                        $itemEmployeeId = !empty($asset['employee_id']) ? (int) $asset['employee_id'] : null;
                        $classificationName = (string) ($asset['classification_name'] ?? '');
                        $itemDescription = (string) $asset['item_description'];
                        $brand = (string) ($asset['brand'] ?? '');
                        $model = (string) ($asset['model'] ?? '');
                        $serialNo = (string) ($asset['serial_no'] ?? '');
                        $accountableName = (string) ($asset['accountable_name'] ?? '');
                        $itemStmt->bind_param('isiissiissssss', $sessionId, $sourceType, $distributionDetailId, $legacyAssetId, $propertyNumber, $itemTypeValue, $itemOfficeId, $itemEmployeeId, $classificationName, $itemDescription, $brand, $model, $serialNo, $accountableName);
                        if (!$itemStmt->execute()) {
                            throw new RuntimeException('Unable to preload legacy assets.');
                        }
                        $loadedCount++;
                    }

                    $itemStmt->close();

                    write_audit_log($db, [
                        'action' => 'insert',
                        'table_name' => 'inventory_count_sessions',
                        'record_id' => $sessionId,
                        'module_name' => 'inventory_counts',
                        'record_type' => 'inventory_count_session',
                        'action_name' => 'create_inventory_count_session',
                        'new_values' => [
                            'system_reference' => $reference,
                            'count_type' => $countType,
                            'office_id' => $officeId,
                            'count_date' => $countDate,
                            'loaded_items' => $loadedCount,
                        ],
                        'description' => 'Created inventory count session and preloaded office assets.',
                    ]);

                    $db->commit();
                    set_flash('success', 'Inventory count session created with ' . number_format($loadedCount) . ' preloaded asset(s).');
                    redirect('modules/property/inventory_counts.php?session_id=' . $sessionId);
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = $e->getMessage();
                }
            }
        }

        if (empty($errors) && $action === 'update_item_status') {
            $sessionId = (int) ($_POST['session_id'] ?? 0);
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $newStatus = normalize_inventory_count_status((string) ($_POST['status'] ?? 'pending'));
            $remarks = trim((string) ($_POST['remarks'] ?? ''));

            if ($sessionId <= 0 || $itemId <= 0) {
                $errors[] = 'Invalid count item update.';
            } else {
                $lookupStmt = $db->prepare("
                    SELECT ici.id, ici.status, ics.status AS session_status
                    FROM inventory_count_items ici
                    INNER JOIN inventory_count_sessions ics ON ics.id = ici.session_id
                    WHERE ici.id = ? AND ici.session_id = ?
                    LIMIT 1
                ");

                if ($lookupStmt) {
                    $lookupStmt->bind_param('ii', $itemId, $sessionId);
                    $lookupStmt->execute();
                    $itemRow = $lookupStmt->get_result()->fetch_assoc();
                    $lookupStmt->close();

                    if (!$itemRow) {
                        $errors[] = 'Count item not found.';
                    } elseif (($itemRow['session_status'] ?? '') !== 'open') {
                        $errors[] = 'This count session is already closed.';
                    } else {
                        $updateStmt = $db->prepare("
                            UPDATE inventory_count_items
                            SET status = ?, remarks = ?, checked_at = NOW(), checked_by = ?
                            WHERE id = ? AND session_id = ?
                        ");

                        if ($updateStmt) {
                            $userId = current_user_id();
                            $updateStmt->bind_param('ssiii', $newStatus, $remarks, $userId, $itemId, $sessionId);
                            $ok = $updateStmt->execute();
                            $updateStmt->close();

                            if ($ok) {
                                write_audit_log($db, [
                                    'action' => 'update',
                                    'table_name' => 'inventory_count_items',
                                    'record_id' => $itemId,
                                    'module_name' => 'inventory_counts',
                                    'record_type' => 'inventory_count_item',
                                    'action_name' => 'update_inventory_count_item_status',
                                    'old_values' => ['status' => $itemRow['status'] ?? 'pending'],
                                    'new_values' => ['status' => $newStatus, 'remarks' => $remarks],
                                    'description' => 'Updated inventory count item status.',
                                ]);

                                set_flash('success', 'Count item updated.');
                                redirect('modules/property/inventory_counts.php?session_id=' . $sessionId . ($statusFilter !== '' ? '&status=' . urlencode($statusFilter) : ''));
                            }
                        }

                        $errors[] = 'Unable to update the count item.';
                    }
                }
            }
        }

        if (empty($errors) && $action === 'scan_asset') {
            $sessionId = (int) ($_POST['session_id'] ?? 0);
            $rawScanValue = trim((string) ($_POST['scan_value'] ?? ''));
            $propertyReference = extract_scanned_property_reference($rawScanValue);

            if ($sessionId <= 0) {
                $errors[] = 'Invalid count session.';
            } elseif ($propertyReference === '') {
                $errors[] = 'Scan a QR code or enter a property number.';
            } else {
                $sessionStmt = $db->prepare("
                    SELECT id, office_id, status
                    FROM inventory_count_sessions
                    WHERE id = ?
                    LIMIT 1
                ");

                $sessionRow = null;
                if ($sessionStmt) {
                    $sessionStmt->bind_param('i', $sessionId);
                    $sessionStmt->execute();
                    $sessionRow = $sessionStmt->get_result()->fetch_assoc();
                    $sessionStmt->close();
                }

                if (!$sessionRow) {
                    $errors[] = 'Count session not found.';
                } elseif (($sessionRow['status'] ?? '') !== 'open') {
                    $errors[] = 'This count session is already closed.';
                } else {
                    $matchStmt = $db->prepare("
                        SELECT id, status, property_number
                        FROM inventory_count_items
                        WHERE session_id = ? AND property_number = ?
                        LIMIT 1
                    ");

                    $matchedItem = null;
                    if ($matchStmt) {
                        $matchStmt->bind_param('is', $sessionId, $propertyReference);
                        $matchStmt->execute();
                        $matchedItem = $matchStmt->get_result()->fetch_assoc();
                        $matchStmt->close();
                    }

                    if ($matchedItem) {
                        $userId = current_user_id();
                        $updateStmt = $db->prepare("
                            UPDATE inventory_count_items
                            SET status = 'found', checked_at = NOW(), checked_by = ?, remarks = CASE
                                WHEN remarks IS NULL OR remarks = '' THEN 'Marked found via QR scan'
                                ELSE remarks
                            END
                            WHERE id = ? AND session_id = ?
                        ");

                        if ($updateStmt) {
                            $itemId = (int) $matchedItem['id'];
                            $updateStmt->bind_param('iii', $userId, $itemId, $sessionId);
                            $ok = $updateStmt->execute();
                            $updateStmt->close();

                            if ($ok) {
                                write_audit_log($db, [
                                    'action' => 'update',
                                    'table_name' => 'inventory_count_items',
                                    'record_id' => $itemId,
                                    'module_name' => 'inventory_counts',
                                    'record_type' => 'inventory_count_item',
                                    'action_name' => 'scan_inventory_count_item',
                                    'old_values' => ['status' => $matchedItem['status'] ?? 'pending'],
                                    'new_values' => ['status' => 'found', 'property_number' => $matchedItem['property_number']],
                                    'description' => 'Marked inventory count item as found via QR scan.',
                                ]);

                                set_flash('success', 'Scanned asset ' . $matchedItem['property_number'] . ' marked as found.');
                                $redirectUrl = 'modules/property/inventory_counts.php?session_id=' . $sessionId
                                    . ($statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '')
                                    . '&highlight_item_id=' . $itemId
                                    . '&scan_feedback=success';
                                redirect($redirectUrl);
                            }
                        }

                        $errors[] = 'Unable to mark scanned asset as found.';
                    } else {
                        $globalMatchStmt = $db->prepare("
                            SELECT property_number, office_name
                            FROM (
                                SELECT
                                    did.property_number AS property_number,
                                    COALESCE(curr_o.office_name, o.office_name) AS office_name
                                FROM distribution_item_details did
                                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                                INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
                                INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
                                INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                                LEFT JOIN offices o ON o.id = d.office_id
                                LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
                                WHERE did.property_number = ?
                                  AND did.is_distributed = 1
                                  AND (did.is_disposed IS NULL OR did.is_disposed = 0)
                                  AND poi.item_type IN ('equipment', 'semi_expendable')
                                UNION ALL
                                SELECT
                                    la.property_number AS property_number,
                                    o.office_name AS office_name
                                FROM legacy_assets la
                                LEFT JOIN offices o ON o.id = la.office_id
                                WHERE la.property_number = ?
                                  AND la.is_active = 1
                                  AND la.item_type IN ('equipment', 'semi_expendable')
                            ) assets
                            LIMIT 1
                        ");

                        $globalMatch = null;
                        if ($globalMatchStmt) {
                            $globalMatchStmt->bind_param('ss', $propertyReference, $propertyReference);
                            $globalMatchStmt->execute();
                            $globalMatch = $globalMatchStmt->get_result()->fetch_assoc();
                            $globalMatchStmt->close();
                        }

                        if ($globalMatch) {
                            $errors[] = 'Scanned asset ' . $propertyReference . ' exists, but it is not part of this office count session. Current office: ' . ($globalMatch['office_name'] ?: 'Unknown office') . '.';
                        } else {
                            $errors[] = 'Scanned asset ' . $propertyReference . ' was not found in active property records.';
                        }
                    }
                }
            }
        }

        if (empty($errors) && $action === 'close_session') {
            $sessionId = (int) ($_POST['session_id'] ?? 0);

            if ($sessionId <= 0) {
                $errors[] = 'Invalid count session.';
            } else {
                $closeStmt = $db->prepare("
                    UPDATE inventory_count_sessions
                    SET status = 'closed', closed_by = ?, closed_at = NOW()
                    WHERE id = ? AND status = 'open'
                ");

                if ($closeStmt) {
                    $userId = current_user_id();
                    $closeStmt->bind_param('ii', $userId, $sessionId);
                    $closeStmt->execute();
                    $affected = $closeStmt->affected_rows;
                    $closeStmt->close();

                    if ($affected > 0) {
                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => 'inventory_count_sessions',
                            'record_id' => $sessionId,
                            'module_name' => 'inventory_counts',
                            'record_type' => 'inventory_count_session',
                            'action_name' => 'close_inventory_count_session',
                            'old_values' => ['status' => 'open'],
                            'new_values' => ['status' => 'closed'],
                            'description' => 'Closed inventory count session.',
                        ]);

                        set_flash('success', 'Inventory count session closed.');
                        redirect('modules/property/inventory_counts.php?session_id=' . $sessionId);
                    }
                }

                $errors[] = 'Unable to close this count session.';
            }
        }
    }

    $sessionListStmt = $db->prepare("
        SELECT
            ics.id,
            ics.system_reference,
            ics.count_type,
            ics.count_date,
            ics.status,
            ics.created_at,
            o.office_name,
            SUM(CASE WHEN ici.status = 'found' THEN 1 ELSE 0 END) AS found_count,
            SUM(CASE WHEN ici.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN ici.status IN ('missing', 'for_repair', 'for_disposal', 'wrong_office', 'wrong_accountable') THEN 1 ELSE 0 END) AS exception_count,
            COUNT(ici.id) AS total_count
        FROM inventory_count_sessions ics
        INNER JOIN offices o ON o.id = ics.office_id
        LEFT JOIN inventory_count_items ici ON ici.session_id = ics.id
        GROUP BY ics.id, ics.system_reference, ics.count_type, ics.count_date, ics.status, ics.created_at, o.office_name
        ORDER BY CASE WHEN ics.status = 'open' THEN 0 ELSE 1 END, ics.count_date DESC, ics.id DESC
        LIMIT 20
    ");
    if ($sessionListStmt) {
        $sessionListStmt->execute();
        $sessions = $sessionListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sessionListStmt->close();
    }

    if ($selectedSessionId <= 0 && !empty($sessions)) {
        $selectedSessionId = (int) $sessions[0]['id'];
    }

    if ($selectedSessionId > 0) {
        $selectedStmt = $db->prepare("
            SELECT
                ics.*,
                o.office_name
            FROM inventory_count_sessions ics
            INNER JOIN offices o ON o.id = ics.office_id
            WHERE ics.id = ?
            LIMIT 1
        ");
        if ($selectedStmt) {
            $selectedStmt->bind_param('i', $selectedSessionId);
            $selectedStmt->execute();
            $selectedSession = $selectedStmt->get_result()->fetch_assoc();
            $selectedStmt->close();
        }

        if ($selectedSession) {
            $itemsSql = "
                SELECT
                    ici.*,
                    o.office_name
                FROM inventory_count_items ici
                LEFT JOIN offices o ON o.id = ici.office_id
                WHERE ici.session_id = ?
            ";
            $itemTypes = 'i';
            $itemParams = [$selectedSessionId];
            if ($statusFilter !== '') {
                $itemsSql .= " AND ici.status = ?";
                $itemTypes .= 's';
                $itemParams[] = $statusFilter;
            }
            $itemsSql .= " ORDER BY FIELD(ici.status, 'pending', 'missing', 'wrong_office', 'wrong_accountable', 'for_repair', 'for_disposal', 'found'), ici.item_type ASC, ici.item_description ASC, ici.property_number ASC";

            $itemStmt = $db->prepare($itemsSql);
            if ($itemStmt) {
                $refs = [$itemTypes];
                foreach ($itemParams as $key => $value) {
                    $refs[] = &$itemParams[$key];
                }
                call_user_func_array([$itemStmt, 'bind_param'], $refs);
                $itemStmt->execute();
                $sessionItems = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $itemStmt->close();
            }

            $statsStmt = $db->prepare("
                SELECT
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'found' THEN 1 ELSE 0 END) AS found_count,
                    SUM(CASE WHEN status IN ('missing', 'for_repair', 'for_disposal', 'wrong_office', 'wrong_accountable') THEN 1 ELSE 0 END) AS exception_count
                FROM inventory_count_items
                WHERE session_id = ?
            ");
            if ($statsStmt) {
                $statsStmt->bind_param('i', $selectedSessionId);
                $statsStmt->execute();
                $statsRow = $statsStmt->get_result()->fetch_assoc();
                $statsStmt->close();
                if ($statsRow) {
                    $sessionStats = [
                        'total' => (int) ($statsRow['total_count'] ?? 0),
                        'pending' => (int) ($statsRow['pending_count'] ?? 0),
                        'found' => (int) ($statsRow['found_count'] ?? 0),
                        'exceptions' => (int) ($statsRow['exception_count'] ?? 0),
                    ];
                }
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="page-section">
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card workspace-list-card">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="card-title mb-0">Start Count Session</h5>
                    <div class="text-muted small">Preload one office and use it as your annual or surprise check working list.</div>
                </div>
                <span class="badge text-bg-light"><?php echo h($referencePreview ?: 'INV reference pending'); ?></span>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo h($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'error' ? 'danger' : 'info'); ?>">
                        <?php echo h($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="row g-3">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_session">

                    <div class="col-md-6">
                        <label for="count_type" class="form-label">Count Type</label>
                        <select class="form-select" id="count_type" name="count_type" required>
                            <?php foreach ($countTypes as $value => $label): ?>
                                <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="count_date" class="form-label">Count Date</label>
                        <input type="date" class="form-control" id="count_date" name="count_date" value="<?php echo h(date('Y-m-d')); ?>" required>
                    </div>

                    <div class="col-12">
                        <label for="office_id" class="form-label">Office</label>
                        <select class="form-select" id="office_id" name="office_id" required>
                            <option value="">Select office</option>
                            <?php foreach ($offices as $office): ?>
                                <option value="<?php echo (int) $office['id']; ?>"><?php echo h($office['office_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Optional instructions for the count team or remarks for this session."></textarea>
                    </div>

                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-clipboard-data me-1"></i>Create Session and Preload Assets
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-4 workspace-list-card">
            <div class="card-header">
                <h5 class="card-title mb-0">Recent Sessions</h5>
            </div>
            <div class="card-body p-0">
                <?php if ($sessions): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($sessions as $session): ?>
                            <?php $isActive = (int) $session['id'] === $selectedSessionId; ?>
                            <a class="list-group-item list-group-item-action <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo h(base_url('modules/property/inventory_counts.php' . build_inventory_count_url(['session_id' => (int) $session['id']]))); ?>">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="fw-semibold"><?php echo h($session['system_reference']); ?></div>
                                        <div class="small <?php echo $isActive ? 'text-white-50' : 'text-muted'; ?>">
                                            <?php echo h($countTypes[$session['count_type']] ?? ucfirst((string) $session['count_type'])); ?> | <?php echo h($session['office_name']); ?>
                                        </div>
                                    </div>
                                    <span class="badge <?php echo ($session['status'] ?? '') === 'open' ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                        <?php echo h(ucfirst((string) $session['status'])); ?>
                                    </span>
                                </div>
                                <div class="small mt-2 <?php echo $isActive ? 'text-white-50' : 'text-muted'; ?>">
                                    <?php echo h(date('M d, Y', strtotime((string) $session['count_date']))); ?> |
                                    <?php echo (int) ($session['found_count'] ?? 0); ?> found |
                                    <?php echo (int) ($session['exception_count'] ?? 0); ?> exceptions
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="p-4 text-muted text-center">No count sessions yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <?php if ($selectedSession): ?>
            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="workspace-header mb-3">
                        <div>
                            <div class="small text-muted">Inventory Count Workspace</div>
                            <h4 class="mb-1"><?php echo h($selectedSession['system_reference']); ?></h4>
                            <div class="text-muted">
                                <?php echo h($countTypes[$selectedSession['count_type']] ?? ucfirst((string) $selectedSession['count_type'])); ?>
                                | <?php echo h($selectedSession['office_name']); ?>
                                | <?php echo h(date('M d, Y', strtotime((string) $selectedSession['count_date']))); ?>
                            </div>
                        </div>
                        <div class="workspace-actions">
                            <span class="badge <?php echo ($selectedSession['status'] ?? '') === 'open' ? 'text-bg-success' : 'text-bg-secondary'; ?> align-self-center">
                                <?php echo h(ucfirst((string) $selectedSession['status'])); ?>
                            </span>
                            <a href="<?php echo h(base_url('modules/property/inventory_count_print.php?session_id=' . (int) $selectedSession['id'])); ?>" target="_blank" class="btn btn-outline-secondary">Print Result</a>
                            <a href="<?php echo h(base_url('modules/property/inventory_reconciliation.php?session_id=' . (int) $selectedSession['id'] . '&resolution=unresolved')); ?>" class="btn btn-outline-secondary">Open Reconciliation</a>
                            <?php if (($selectedSession['status'] ?? '') === 'open'): ?>
                                <a href="#scan_value" class="btn btn-outline-secondary">Go to Scan</a>
                            <?php endif; ?>
                            <?php if (($selectedSession['status'] ?? '') === 'open'): ?>
                                <form method="post" onsubmit="return confirm('Close this count session?');">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="close_session">
                                    <input type="hidden" name="session_id" value="<?php echo (int) $selectedSession['id']; ?>">
                                    <button type="submit" class="btn btn-outline-primary">Close Session</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="workspace-summary-grid">
                        <div>
                            <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                                <div class="text-muted small">Assets Loaded</div>
                                <div class="fs-4 fw-semibold"><?php echo number_format($sessionStats['total']); ?></div>
                            </div>
                        </div>
                        <div>
                            <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                                <div class="text-muted small">Pending Verification</div>
                                <div class="fs-4 fw-semibold text-secondary"><?php echo number_format($sessionStats['pending']); ?></div>
                            </div>
                        </div>
                        <div>
                            <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                                <div class="text-muted small">Found</div>
                                <div class="fs-4 fw-semibold text-success"><?php echo number_format($sessionStats['found']); ?></div>
                            </div>
                        </div>
                        <div>
                            <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                                <div class="text-muted small">Exceptions</div>
                                <div class="fs-4 fw-semibold text-danger"><?php echo number_format($sessionStats['exceptions']); ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($selectedSession['notes'])): ?>
                        <div class="alert alert-light border mt-3 mb-0">
                            <div class="small text-muted">Session Notes</div>
                            <div><?php echo nl2br(h((string) $selectedSession['notes'])); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (($selectedSession['status'] ?? '') === 'open'): ?>
                <div class="card mb-4 border-warning-subtle">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h5 class="card-title mb-0">Scan Asset</h5>
                                <div class="text-muted small">Use this workspace to manage count sessions and review checklist results. Scanning a printed QR tag from your phone opens the asset page, where you can mark the item as found.</div>
                            </div>
                            <span class="badge text-bg-warning">Live Count</span>
                        </div>
                        <form method="post" class="workspace-filter-grid">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="action" value="scan_asset">
                            <input type="hidden" name="session_id" value="<?php echo (int) $selectedSession['id']; ?>">
                            <div class="workspace-filter-wide">
                                <label for="scan_value" class="form-label">QR or Property Number</label>
                                <input
                                    type="text"
                                    class="form-control form-control-lg"
                                    id="scan_value"
                                    name="scan_value"
                                    placeholder="Scan QR here or enter property number"
                                    autocomplete="off"
                                    autofocus
                                >
                            </div>
                            <div>
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-qr-code-scan me-1"></i>Scan Asset
                                </button>
                            </div>
                        </form>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary" id="startCameraScan">
                                <i class="bi bi-camera-video me-1"></i>Start QR Scanner
                            </button>
                            <button type="button" class="btn btn-outline-secondary d-none" id="stopCameraScan">
                                <i class="bi bi-stop-circle me-1"></i>Stop Camera
                            </button>
                            <label for="scanImageFile" class="btn btn-outline-secondary mb-0">
                                <i class="bi bi-image me-1"></i>Upload QR Image
                            </label>
                            <input type="file" id="scanImageFile" class="d-none" accept="image/*">
                            <span class="small text-muted align-self-center" id="cameraScanStatus">
                                You can paste the property number here, scan the printed QR tag from your phone camera, or upload a QR image.
                            </span>
                        </div><div class="inventory-camera-panel d-none mt-3" id="cameraScanPanel">
                            <div class="ratio ratio-16x9 rounded overflow-hidden bg-dark">
                                <video id="cameraScanVideo" autoplay playsinline muted></video>
                            </div>
                            <div class="small text-muted mt-2">
                                Point the camera at the QR tag. When a code is detected, the property number will be filled and submitted automatically.
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="card">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="card-title mb-0">Count Checklist</h5>
                            <div class="text-muted small">Use this office-based list to verify what is found, missing, or needs follow-up.</div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="<?php echo h(build_inventory_count_url(['session_id' => $selectedSessionId, 'status' => ''])); ?>" class="btn btn-sm <?php echo $statusFilter === '' ? 'btn-primary' : 'btn-outline-secondary'; ?>">All</a>
                            <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                                <a href="<?php echo h(build_inventory_count_url(['session_id' => $selectedSessionId, 'status' => $statusKey])); ?>" class="btn btn-sm <?php echo $statusFilter === $statusKey ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                    <?php echo h($statusLabel); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="table-responsive mobile-table-frame">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th style="min-width: 180px;">Property No.</th>
                                    <th style="min-width: 280px;">Asset</th>
                                    <th style="min-width: 180px;">Assignment</th>
                                    <th style="min-width: 140px;">Status</th>
                                    <th style="min-width: 320px;">Quick Mark</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($sessionItems): ?>
                                    <?php foreach ($sessionItems as $item): ?>
                                        <?php
                                        $brandModel = trim(implode(' / ', array_filter([
                                            trim((string) ($item['brand'] ?? '')),
                                            trim((string) ($item['model'] ?? '')),
                                        ])));
                                        $typeLabel = ($item['item_type'] ?? '') === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment';
                                        $statusKey = normalize_inventory_count_status((string) ($item['status'] ?? 'pending'));
                                        ?>
                                        <tr id="count-item-<?php echo (int) $item['id']; ?>" class="<?php echo $highlightItemId === (int) $item['id'] ? 'inventory-count-highlight' : ''; ?>">
                                            <td>
                                                <div class="fw-semibold"><?php echo h($item['property_number']); ?></div>
                                                <div class="small text-muted"><?php echo h($item['source_type'] === 'legacy' ? 'Beginning Balance' : 'System Transaction'); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo h($item['item_description']); ?></div>
                                                <div class="small text-muted">
                                                    <?php echo h(trim(implode(' | ', array_filter([
                                                        $typeLabel,
                                                        $item['classification_name'] ?? '',
                                                        $brandModel,
                                                        !empty($item['serial_no']) ? 'SN: ' . $item['serial_no'] : '',
                                                    ])))); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div><?php echo h($item['office_name'] ?? '-'); ?></div>
                                                <div class="small text-muted"><?php echo h($item['accountable_name'] ?: 'No accountable employee'); ?></div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo h($statusBadgeClasses[$statusKey] ?? 'text-bg-secondary'); ?>">
                                                    <?php echo h($statusLabels[$statusKey] ?? ucfirst($statusKey)); ?>
                                                </span>
                                                <?php if (!empty($item['remarks'])): ?>
                                                    <div class="small text-muted mt-1"><?php echo h($item['remarks']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($item['proof_photo_path'])): ?>
                                                    <div class="mt-2">
                                                        <a href="<?php echo h(upload_url((string) $item['proof_photo_path'])); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">View Photo</a>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (($selectedSession['status'] ?? '') === 'open'): ?>
                                                    <form method="post" class="row g-2">
                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="update_item_status">
                                                        <input type="hidden" name="session_id" value="<?php echo (int) $selectedSession['id']; ?>">
                                                        <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                                        <div class="col-md-5">
                                                            <select class="form-select form-select-sm" name="status">
                                                                <?php foreach ($statusLabels as $value => $label): ?>
                                                                    <option value="<?php echo h($value); ?>" <?php echo $statusKey === $value ? 'selected' : ''; ?>>
                                                                        <?php echo h($label); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-5">
                                                            <input type="text" class="form-control form-control-sm" name="remarks" value="<?php echo h((string) ($item['remarks'] ?? '')); ?>" placeholder="Optional note">
                                                        </div>
                                                        <div class="col-md-2 d-grid">
                                                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                                        </div>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="text-muted small">Session closed</div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No assets in this view yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body p-5 text-center text-muted">
                    Create and open a count session first. The QR scan box appears inside an open session so you can scan assets directly into that office checklist.
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var scanInput = document.getElementById('scan_value');
    var scanForm = scanInput ? scanInput.closest('form') : null;
    var startCameraButton = document.getElementById('startCameraScan');
    var stopCameraButton = document.getElementById('stopCameraScan');
    var scanImageFile = document.getElementById('scanImageFile');
    var cameraPanel = document.getElementById('cameraScanPanel');
    var cameraVideo = document.getElementById('cameraScanVideo');
    var cameraStatus = document.getElementById('cameraScanStatus');
    var cameraDiagSummary = document.getElementById('cameraDiagSummary');
    var cameraDiagSecure = document.getElementById('cameraDiagSecure');
    var cameraDiagMedia = document.getElementById('cameraDiagMedia');
    var cameraDiagPermission = document.getElementById('cameraDiagPermission');
    var cameraDiagFallback = document.getElementById('cameraDiagFallback');
    var cameraStream = null;
    var cameraDetector = null;
    var cameraScanTimer = null;
    var cameraActive = false;
    var html5QrScanner = null;

    function setCameraStatus(message, tone) {
        if (cameraStatus) {
            cameraStatus.textContent = message;
        }

        if (tone === 'success') {
            playTone(880, 0.18);
        } else if (tone === 'warning') {
            playTone(440, 0.22);
        }
    }

    function playTone(frequency, duration) {
        try {
            var audioContext = new (window.AudioContext || window.webkitAudioContext)();
            var oscillator = audioContext.createOscillator();
            var gainNode = audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(frequency, audioContext.currentTime);
            gainNode.gain.setValueAtTime(0.001, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.08, audioContext.currentTime + 0.01);
            gainNode.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + duration);
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.start();
            oscillator.stop(audioContext.currentTime + duration);
        } catch (error) {
            // Audio feedback is optional.
        }
    }

    function stopCameraScanner(resetMessage) {
        cameraActive = false;
        if (cameraScanTimer) {
            window.clearInterval(cameraScanTimer);
            cameraScanTimer = null;
        }
        if (html5QrScanner) {
            try {
                html5QrScanner.stop().catch(function () {}).finally(function () {
                    try {
                        html5QrScanner.clear();
                    } catch (error) {
                        // Ignore cleanup failures.
                    }
                });
            } catch (error) {
                // Ignore scanner cleanup failures.
            }
            html5QrScanner = null;
        }
        if (cameraStream) {
            cameraStream.getTracks().forEach(function (track) {
                track.stop();
            });
            cameraStream = null;
        }
        if (cameraVideo) {
            cameraVideo.srcObject = null;
        }
        if (cameraPanel) {
            cameraPanel.classList.add('d-none');
        }
        if (startCameraButton) {
            startCameraButton.classList.remove('d-none');
        }
        if (stopCameraButton) {
            stopCameraButton.classList.add('d-none');
        }
        if (resetMessage) {
            setCameraStatus('You can paste the property number here, scan the printed QR tag from your phone camera, or upload a QR image.');
        }
    }

    function submitScannedValue(rawValue) {
        if (!rawValue || !scanInput || !scanForm) {
            return;
        }

        cameraActive = false;
        scanInput.value = rawValue.trim();
        setCameraStatus('QR detected. Submitting scanned asset...', 'success');
        stopCameraScanner(false);
        scanForm.submit();
    }

    async function scanFrameOnce() {
        if (!cameraActive || !cameraDetector || !cameraVideo || cameraVideo.readyState < 2) {
            return;
        }

        try {
            var barcodes = await cameraDetector.detect(cameraVideo);
            if (!barcodes || !barcodes.length) {
                return;
            }

            var rawValue = (barcodes[0].rawValue || '').trim();
            if (!rawValue) {
                return;
            }

            submitScannedValue(rawValue);
        } catch (error) {
            setCameraStatus('Camera scan is active, but the browser could not read this frame yet.');
        }
    }

    async function startHtml5QrFallback() {
        if (!window.Html5Qrcode || !cameraPanel) {
            setCameraStatus('Fallback QR scanner is not available on this browser.', 'warning');
            return;
        }

        var readerId = 'cameraScanReader';
        var readerNode = document.getElementById(readerId);
        if (!readerNode) {
            readerNode = document.createElement('div');
            readerNode.id = readerId;
            readerNode.className = 'inventory-camera-reader';
            cameraPanel.appendChild(readerNode);
        }

        html5QrScanner = new window.Html5Qrcode(readerId);
        await html5QrScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 220, height: 220 } },
            function (decodedText) {
                if (decodedText) {
                    submitScannedValue(decodedText);
                }
            },
            function () {
                // Ignore per-frame decode misses.
            }
        );
        setCameraStatus('Fallback camera scanner is live. Point the QR tag inside the frame.');
    }

    async function startCameraScanner() {
        if (!scanInput || !scanForm) {
            return;
        }

        if (!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia) {
            setCameraStatus('Camera scanning is not available on this browser. Check the diagnostics below for the likely cause.', 'warning');
            return;
        }

        try {
            cameraActive = true;
            if (cameraPanel) {
                cameraPanel.classList.remove('d-none');
            }
            if (startCameraButton) {
                startCameraButton.classList.add('d-none');
            }
            if (stopCameraButton) {
                stopCameraButton.classList.remove('d-none');
            }

            if ('BarcodeDetector' in window) {
                cameraDetector = new window.BarcodeDetector({ formats: ['qr_code'] });
                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' }
                    },
                    audio: false
                });

                if (cameraVideo) {
                    cameraVideo.classList.remove('d-none');
                    cameraVideo.srcObject = cameraStream;
                }

                setCameraStatus('Camera scanner is live. Point the QR tag inside the frame.');
                cameraScanTimer = window.setInterval(scanFrameOnce, 700);
            } else {
                if (cameraVideo) {
                    cameraVideo.classList.add('d-none');
                }
                await startHtml5QrFallback();
            }
        } catch (error) {
            stopCameraScanner(false);
            setCameraStatus('Unable to start the camera. Check browser camera permission and try again.', 'warning');
        }
    }

    async function decodeUploadedQrImage(file) {
        if (!file) {
            return;
        }

        if (!window.Html5Qrcode) {
            setCameraStatus('QR image upload is not available on this browser.', 'warning');
            return;
        }

        stopCameraScanner(false);
        setCameraStatus('Reading uploaded QR image...');

        var tempReaderId = 'inventoryQrImageReader';
        var tempReaderNode = document.getElementById(tempReaderId);
        if (!tempReaderNode) {
            tempReaderNode = document.createElement('div');
            tempReaderNode.id = tempReaderId;
            tempReaderNode.className = 'd-none';
            document.body.appendChild(tempReaderNode);
        }

        var imageScanner = new window.Html5Qrcode(tempReaderId);

        try {
            var decodedText = await imageScanner.scanFile(file, false);
            if (decodedText) {
                submitScannedValue(decodedText);
                return;
            }

            setCameraStatus('The uploaded image did not contain a readable QR code.', 'warning');
        } catch (error) {
            setCameraStatus('The uploaded image could not be decoded. Try a clearer QR image.', 'warning');
        } finally {
            try {
                imageScanner.clear();
            } catch (error) {
                // Ignore cleanup failures.
            }

            if (scanImageFile) {
                scanImageFile.value = '';
            }
        }
    }

    if (scanInput) {
        scanInput.focus();
        scanInput.select();
    }

    refreshCameraDiagnostics();

    var highlightedRow = document.querySelector('.inventory-count-highlight');
    if (highlightedRow) {
        highlightedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (<?php echo json_encode($scanFeedback === 'success'); ?>) {
        playTone(880, 0.18);
    }

    if (startCameraButton) {
        startCameraButton.addEventListener('click', function () {
            startCameraScanner();
        });
    }

    if (stopCameraButton) {
        stopCameraButton.addEventListener('click', function () {
            stopCameraScanner(true);
        });
    }

    if (scanImageFile) {
        scanImageFile.addEventListener('change', function (event) {
            var file = event.target && event.target.files ? event.target.files[0] : null;
            decodeUploadedQrImage(file);
        });
    }

    window.addEventListener('beforeunload', function () {
        stopCameraScanner(false);
    });
});
</script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<style>
.inventory-camera-panel video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.inventory-camera-reader {
    width: 100%;
    min-height: 240px;
}

.inventory-count-highlight {
    animation: inventoryCountPulse 1.5s ease-in-out 2;
    box-shadow: inset 0 0 0 9999px rgba(25, 135, 84, 0.12);
}

@keyframes inventoryCountPulse {
    0% {
        box-shadow: inset 0 0 0 9999px rgba(25, 135, 84, 0.04);
    }
    50% {
        box-shadow: inset 0 0 0 9999px rgba(25, 135, 84, 0.22);
    }
    100% {
        box-shadow: inset 0 0 0 9999px rgba(25, 135, 84, 0.12);
    }
}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


