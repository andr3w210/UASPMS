<?php
require_once __DIR__ . '/../../app/config/init.php';

require_role('Administrator', 'Supply Officer');

function po_edit_schema_has_column(mysqli $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $escapedTable = str_replace('`', '``', $table);
    $escapedColumn = $db->real_escape_string($column);
    $result = $db->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    $cache[$key] = $result ? $result->num_rows > 0 : false;

    return $cache[$key];
}

function po_edit_bind_dynamic_params(mysqli_stmt $stmt, string $types, array $params): bool
{
    $bindParams = [$types];
    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    return call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

$db = db();
$flash = get_flash();
$errors = [];
$page_title = 'Edit Purchase Order';
$suppliers = [];
$funds = [];
$procurementModes = [];
$accountCodes = [];
$catalogItems = [];
$classifications = [];
$unitOfMeasures = [];
$catalogById = [];
$activeThreshold = ['equipment_min' => 50000.00, 'semi_hv_min' => 5000.01];
$poItemSupportsSemiType = false;
$id = (int) ($_GET['id'] ?? 0);

$form = [
    'id' => 0,
    'system_reference' => '',
    'po_number' => '',
    'po_date' => date('Y-m-d'),
    'supplier_id' => '',
    'fund_id' => '',
    'supplier_address' => '',
    'mode_of_procurement_id' => '',
    'place_of_delivery' => 'University of Antique',
    'delivery_term_days' => '',
    'expected_delivery_date' => '',
    'document_total_amount' => '',
    'status' => 'encoded',
];
$itemRows = [];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} elseif ($id <= 0) {
    $errors[] = 'Purchase order not found.';
} else {
    $supplierResult = $db->query("SELECT id, supplier_name, supplier_code, address FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
    if ($supplierResult) $suppliers = $supplierResult->fetch_all(MYSQLI_ASSOC);

    $fundResult = $db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC");
    if ($fundResult) $funds = $fundResult->fetch_all(MYSQLI_ASSOC);

    $colRes = $db->query("SHOW COLUMNS FROM mode_of_procurements LIKE 'mode_code'");
    if ($colRes && $colRes->num_rows > 0) {
        $procurementModeResult = $db->query("SELECT id, mode_code, mode_name FROM mode_of_procurements WHERE is_active = 1 ORDER BY mode_name ASC");
    } else {
        $procurementModeResult = $db->query("SELECT id, mode_name FROM mode_of_procurements WHERE is_active = 1 ORDER BY mode_name ASC");
    }
    if ($procurementModeResult) $procurementModes = $procurementModeResult->fetch_all(MYSQLI_ASSOC);

    $classificationResult = $db->query("SELECT id, classification_code, classification_name, classification_family, classification_group, account_code_id FROM classifications WHERE is_active = 1 ORDER BY COALESCE(classification_family, ''), classification_name ASC");
    if ($classificationResult) $classifications = $classificationResult->fetch_all(MYSQLI_ASSOC);

    $accountCodeResult = $db->query("SELECT id, account_code, account_name, account_group FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
    if ($accountCodeResult) $accountCodes = $accountCodeResult->fetch_all(MYSQLI_ASSOC);

    $catalogResult = $db->query("
        SELECT sc.id, sc.stock_no, sc.item_name, sc.item_description,
               sc.item_type, sc.account_code_id, sc.classification_id,
               sc.unit_of_measure_id,
               ac.account_code, ac.account_name,
               c.classification_name,
               u.abbreviation AS uom_abbr
        FROM stock_catalog sc
        LEFT JOIN account_codes ac ON ac.id = sc.account_code_id
        LEFT JOIN classifications c ON c.id = sc.classification_id
        LEFT JOIN unit_of_measures u ON u.id = sc.unit_of_measure_id
        WHERE sc.is_active = 1
        ORDER BY sc.item_type ASC, sc.stock_no ASC
    ");
    if ($catalogResult) $catalogItems = $catalogResult->fetch_all(MYSQLI_ASSOC);
    foreach ($catalogItems as $catalogItem) {
        $catalogById[(int) $catalogItem['id']] = $catalogItem;
    }

    $uomResult = $db->query("SELECT id, uom_name, abbreviation FROM unit_of_measures WHERE is_active = 1 ORDER BY uom_name ASC");
    if ($uomResult) $unitOfMeasures = $uomResult->fetch_all(MYSQLI_ASSOC);

    $activeThreshold = get_active_threshold($db);
    $poItemSupportsSemiType = po_edit_schema_has_column($db, 'purchase_order_items', 'semi_expendable_type');
    $poSupportsDocumentTotal = function_exists('schema_has_column')
        ? schema_has_column($db, 'purchase_orders', 'document_total_amount')
        : false;

    $headerSql = "
        SELECT id, system_reference, po_number, po_date, supplier_id, fund_id,
               supplier_address, mode_of_procurement_id, place_of_delivery,
               delivery_term_days, expected_delivery_date, status, is_partial_entry";
    if ($poSupportsDocumentTotal) {
        $headerSql .= ", document_total_amount";
    }
    $headerSql .= "
        FROM purchase_orders
        WHERE id = ?
        LIMIT 1
    ";
    $headerStmt = $db->prepare($headerSql);
    if ($headerStmt) {
        $headerStmt->bind_param('i', $id);
        $headerStmt->execute();
        $headerResult = $headerStmt->get_result();
        $existingPo = $headerResult ? $headerResult->fetch_assoc() : null;
        $headerStmt->close();
    } else {
        $existingPo = null;
    }

    if (!$existingPo) {
        $errors[] = 'Purchase order not found.';
    } else {
        $form = [
            'id' => (int) $existingPo['id'],
            'system_reference' => (string) ($existingPo['system_reference'] ?? ''),
            'po_number' => (string) ($existingPo['po_number'] ?? ''),
            'po_date' => (string) ($existingPo['po_date'] ?? date('Y-m-d')),
            'supplier_id' => (string) ($existingPo['supplier_id'] ?? ''),
            'fund_id' => (string) ($existingPo['fund_id'] ?? ''),
            'supplier_address' => (string) ($existingPo['supplier_address'] ?? ''),
            'mode_of_procurement_id' => (string) ($existingPo['mode_of_procurement_id'] ?? ''),
            'place_of_delivery' => (string) ($existingPo['place_of_delivery'] ?? 'University of Antique'),
            'delivery_term_days' => $existingPo['delivery_term_days'] !== null ? (string) $existingPo['delivery_term_days'] : '',
            'expected_delivery_date' => (string) ($existingPo['expected_delivery_date'] ?? ''),
            'document_total_amount' => $poSupportsDocumentTotal && $existingPo['document_total_amount'] !== null ? (string) $existingPo['document_total_amount'] : '',
            'status' => (string) ($existingPo['status'] ?? 'encoded'),
            'is_partial_entry' => (int) ($existingPo['is_partial_entry'] ?? 0),
        ];

        $itemSql = "
            SELECT id, line_no, item_type, stock_catalog_id, account_code_id,
                   classification_id, item_description, quantity,
                   unit_of_measure_id, unit_cost, line_total
                   " . ($poItemSupportsSemiType ? ", semi_expendable_type" : "") . "
            FROM purchase_order_items
            WHERE purchase_order_id = ?
            ORDER BY line_no ASC, id ASC
        ";
        $itemStmt = $db->prepare($itemSql);
        if ($itemStmt) {
            $itemStmt->bind_param('i', $id);
            $itemStmt->execute();
            $itemResult = $itemStmt->get_result();
            if ($itemResult) {
                while ($row = $itemResult->fetch_assoc()) {
                    $itemRows[] = [
                        'id' => (string) ($row['id'] ?? ''),
                        'item_type' => (string) ($row['item_type'] ?? 'supply'),
                        'semi_expendable_type' => (string) ($row['semi_expendable_type'] ?? ''),
                        'stock_catalog_id' => (string) ($row['stock_catalog_id'] ?? ''),
                        'account_code_id' => (string) ($row['account_code_id'] ?? ''),
                        'classification_id' => (string) ($row['classification_id'] ?? ''),
                        'item_description' => (string) ($row['item_description'] ?? ''),
                        'quantity' => (string) ($row['quantity'] ?? '1'),
                        'unit_of_measure_id' => (string) ($row['unit_of_measure_id'] ?? ''),
                        'unit_cost' => (string) ($row['unit_cost'] ?? '0.00'),
                        'line_total' => (string) ($row['line_total'] ?? '0.00'),
                        'is_existing' => true,
                    ];
                }
            }
            $itemStmt->close();
        }

        if (!$itemRows) {
            $itemRows[] = ['id' => '', 'item_type' => 'supply', 'semi_expendable_type' => '', 'stock_catalog_id' => '', 'account_code_id' => '', 'classification_id' => '', 'item_description' => '', 'quantity' => '1', 'unit_of_measure_id' => '', 'unit_cost' => '0.00', 'line_total' => '0.00', 'is_existing' => false];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'update')) {
            if (!csrf_verify()) {
                $errors[] = 'Invalid CSRF token.';
            }

            $form['po_number'] = old($_POST, 'po_number');
            $form['po_date'] = old($_POST, 'po_date', date('Y-m-d'));
            $form['supplier_id'] = old($_POST, 'supplier_id');
            $form['fund_id'] = old($_POST, 'fund_id');
            $form['supplier_address'] = old($_POST, 'supplier_address');
            $form['mode_of_procurement_id'] = old($_POST, 'mode_of_procurement_id');
            $form['place_of_delivery'] = old($_POST, 'place_of_delivery', 'University of Antique');
            $form['delivery_term_days'] = old($_POST, 'delivery_term_days');
            $form['expected_delivery_date'] = old($_POST, 'expected_delivery_date');
            $form['document_total_amount'] = old($_POST, 'document_total_amount');
            $form['is_partial_entry'] = !empty($_POST['is_partial_entry']) ? 1 : 0;
            $existingIsPartial = !empty($existingPo['is_partial_entry']);

            $postedRows = $_POST['items'] ?? [];
            if ($postedRows && is_array($postedRows)) {
                $itemRows = $postedRows;
            }

            if (!is_array($postedRows) || count($postedRows) === 0) {
                $errors[] = 'Unable to capture PO line items from the page. Please refresh the page and try saving again.';
            }

            if ($form['po_date'] === '') $errors[] = 'PO date is required.';
            if ($form['supplier_id'] === '') $errors[] = 'Supplier is required.';
            if ($form['fund_id'] === '') $errors[] = 'Fund is required.';
            if ($form['supplier_address'] === '') $errors[] = 'Supplier address is required.';
            if ($form['mode_of_procurement_id'] === '') $errors[] = 'Mode of procurement is required.';
            if ($form['place_of_delivery'] === '') $errors[] = 'Place of delivery is required.';
            if ($form['delivery_term_days'] !== '' && (!ctype_digit($form['delivery_term_days']) || (int) $form['delivery_term_days'] < 0)) {
                $errors[] = 'Delivery term must be a non-negative whole number.';
            }
            if ($form['document_total_amount'] !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $form['document_total_amount'])) {
                $errors[] = 'PO total amount must be a valid number with up to 2 decimal places.';
            }

            if ($form['po_number'] !== '') {
                $dupStmt = $db->prepare("SELECT id FROM purchase_orders WHERE po_number = ? AND id != ? LIMIT 1");
                if ($dupStmt) {
                    $dupStmt->bind_param('si', $form['po_number'], $id);
                    $dupStmt->execute();
                    if ($dupStmt->get_result()->fetch_assoc()) {
                        $errors[] = 'PO number already exists.';
                    }
                    $dupStmt->close();
                }
            }

            if ($form['po_date'] !== '') {
                try {
                    $baseDate = new DateTimeImmutable($form['po_date']);
                    $daysToAdd = $form['delivery_term_days'] !== '' ? (int) $form['delivery_term_days'] : 0;
                    $form['expected_delivery_date'] = $baseDate->modify('+' . $daysToAdd . ' days')->format('Y-m-d');
                } catch (Exception $e) {
                    $errors[] = 'PO date is invalid.';
                }
            }

            $validatedItems = [];
            $totalAmount = 0.0;
            $lineNo = 0;

            foreach (($postedRows ?: []) as $row) {
                // Skip items already in DB — partial mode preserves them as-is
                if ($existingIsPartial && !empty($row['is_existing'])) {
                    continue;
                }
                $description = trim((string) ($row['item_description'] ?? ''));
                $itemId = (int) ($row['id'] ?? 0);
                $itemType = trim((string) ($row['item_type'] ?? 'supply'));
                $stockCatalogId = trim((string) ($row['stock_catalog_id'] ?? ''));
                $accountCodeId = trim((string) ($row['account_code_id'] ?? ''));
                $classificationId = trim((string) ($row['classification_id'] ?? ''));
                $quantity = (float) ($row['quantity'] ?? 0);
                $unitOfMeasureId = trim((string) ($row['unit_of_measure_id'] ?? ''));
                $unitCost = (float) ($row['unit_cost'] ?? 0);
                $semiExpendableType = trim((string) ($row['semi_expendable_type'] ?? ''));

                if ($description === '' && $quantity <= 0 && $unitCost <= 0) {
                    continue;
                }

                $lineNo++;
                if (!in_array($itemType, ['supply', 'semi_expendable', 'equipment'], true)) {
                    $errors[] = 'Invalid item type on line ' . $lineNo . '.';
                }

                if ($itemType === 'supply') {
                    if ($stockCatalogId === '') {
                        $errors[] = 'Supply line ' . $lineNo . ' must be selected from the stock catalog.';
                    } else {
                        $catalogRow = $catalogById[(int) $stockCatalogId] ?? null;
                        if (!$catalogRow || ($catalogRow['item_type'] ?? '') !== 'supply') {
                            $errors[] = 'Selected catalog item is invalid on line ' . $lineNo . '.';
                        } else {
                            $description = trim((string) ($catalogRow['item_description'] ?? ''));
                            if ($description === '') $description = trim((string) ($catalogRow['item_name'] ?? ''));
                            $accountCodeId = (string) ($catalogRow['account_code_id'] ?? '');
                            $classificationId = (string) ($catalogRow['classification_id'] ?? '');
                            $unitOfMeasureId = (string) ($catalogRow['unit_of_measure_id'] ?? '');
                        }
                    }
                } else {
                    $stockCatalogId = '';
                }

                if ($description === '') $errors[] = 'Description is required on line ' . $lineNo . '.';
                if ($quantity <= 0) $errors[] = 'Quantity must be greater than zero on line ' . $lineNo . '.';
                if ($accountCodeId === '') $errors[] = 'Account code is required on line ' . $lineNo . '.';
                if ($unitOfMeasureId === '') $errors[] = 'Unit is required on line ' . $lineNo . '.';
                if ($unitCost < 0) $errors[] = 'Unit cost cannot be negative on line ' . $lineNo . '.';

                if ($itemType === 'semi_expendable') {
                    $semiExpendableType = classify_item_by_cost($unitCost, $activeThreshold);
                } else {
                    $semiExpendableType = '';
                }

                $lineTotal = round($quantity * $unitCost, 2);
                $totalAmount += $lineTotal;

                $validatedItems[] = [
                    'id' => $itemId,
                    'stock_catalog_id' => $stockCatalogId !== '' ? (int) $stockCatalogId : 0,
                    'item_type' => $itemType,
                    'semi_expendable_type' => $semiExpendableType,
                    'account_code_id' => (int) $accountCodeId,
                    'classification_id' => $classificationId !== '' ? (int) $classificationId : null,
                    'item_description' => $description,
                    'quantity' => $quantity,
                    'unit_of_measure_id' => (int) $unitOfMeasureId,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ];
            }

            if (!$validatedItems && !$existingIsPartial) {
                $errors[] = 'At least one PO line is required.';
            }

            $documentTotalAmount = null;
            if ($form['document_total_amount'] !== '') {
                $documentTotalAmount = round((float) $form['document_total_amount'], 2);
            }

            if (!$errors) {
                $db->begin_transaction();
                try {
                    if ($poSupportsDocumentTotal) {
                        $updateStmt = $db->prepare("
                        UPDATE purchase_orders
                        SET po_number = ?, po_date = ?, supplier_id = ?, fund_id = ?,
                            supplier_address = ?, mode_of_procurement_id = ?, place_of_delivery = ?,
                            delivery_term_days = ?, expected_delivery_date = ?, total_amount = ?,
                            document_total_amount = NULLIF(?, ''), is_partial_entry = ?,
                            updated_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    } else {
                        $updateStmt = $db->prepare("
                        UPDATE purchase_orders
                        SET po_number = ?, po_date = ?, supplier_id = ?, fund_id = ?,
                            supplier_address = ?, mode_of_procurement_id = ?, place_of_delivery = ?,
                            delivery_term_days = ?, expected_delivery_date = ?, total_amount = ?,
                            is_partial_entry = ?,
                            updated_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    }
                    if (!$updateStmt) {
                        throw new RuntimeException('Unable to prepare PO update.');
                    }

                    $supplierId = (int) $form['supplier_id'];
                    $fundId = (int) $form['fund_id'];
                    $modeId = (int) $form['mode_of_procurement_id'];
                    $deliveryTermDays = $form['delivery_term_days'] !== '' ? (int) $form['delivery_term_days'] : null;
                    $expectedDelivery = $form['expected_delivery_date'] !== '' ? $form['expected_delivery_date'] : null;
                    $userId = current_user_id();
                    $isPartialEntry = $form['is_partial_entry'];
                    $poNumberForSave = $form['po_number'] !== '' ? $form['po_number'] : 'NO-PO-' . $form['system_reference'];

                    // For partial POs, add existing items' total to new items total
                    if ($existingIsPartial) {
                        $existingTotalStmt = $db->prepare('SELECT COALESCE(SUM(line_total), 0) FROM purchase_order_items WHERE purchase_order_id = ?');
                        if ($existingTotalStmt) {
                            $existingTotalStmt->bind_param('i', $id);
                            $existingTotalStmt->execute();
                            $existingTotalResult = $existingTotalStmt->get_result();
                            if ($existingTotalResult) {
                                $totalAmount += (float) ($existingTotalResult->fetch_row()[0] ?? 0);
                            }
                            $existingTotalStmt->close();
                        }
                    }

                    if ($documentTotalAmount !== null && empty($isPartialEntry) && abs($documentTotalAmount - $totalAmount) > 0.009) {
                        throw new RuntimeException('Encoded line total (' . number_format($totalAmount, 2) . ') does not match the hard copy PO total (' . number_format($documentTotalAmount, 2) . ').');
                    }

                    if ($poSupportsDocumentTotal) {
                        $updateStmt->bind_param(
                            'ssiisisisdsiii',
                            $poNumberForSave,
                            $form['po_date'],
                            $supplierId,
                            $fundId,
                            $form['supplier_address'],
                            $modeId,
                            $form['place_of_delivery'],
                            $deliveryTermDays,
                            $expectedDelivery,
                            $totalAmount,
                            $form['document_total_amount'],
                            $isPartialEntry,
                            $userId,
                            $id
                        );
                    } else {
                        $updateStmt->bind_param(
                            'ssiisisisdiii',
                            $poNumberForSave,
                            $form['po_date'],
                            $supplierId,
                            $fundId,
                            $form['supplier_address'],
                            $modeId,
                            $form['place_of_delivery'],
                            $deliveryTermDays,
                            $expectedDelivery,
                            $totalAmount,
                            $isPartialEntry,
                            $userId,
                            $id
                        );
                    }
                    if (!$updateStmt->execute()) {
                        throw new RuntimeException('Unable to update purchase order header: ' . $updateStmt->error);
                    }
                    $updateStmt->close();

                    if ($validatedItems) {
                        $existingItemIds = [];
                        $existingItemIdsByPosition = [];
                        $existingItemStmt = $db->prepare('SELECT id FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY line_no ASC, id ASC');
                        if ($existingItemStmt) {
                            $existingItemStmt->bind_param('i', $id);
                            $existingItemStmt->execute();
                            $existingItemResult = $existingItemStmt->get_result();
                            if ($existingItemResult) {
                                while ($existingItemRow = $existingItemResult->fetch_assoc()) {
                                    $existingItemId = (int) $existingItemRow['id'];
                                    $existingItemIds[$existingItemId] = true;
                                    $existingItemIdsByPosition[] = $existingItemId;
                                }
                            }
                            $existingItemStmt->close();
                        }

                        if ($poItemSupportsSemiType) {
                            $updateItemStmt = $db->prepare("
                                UPDATE purchase_order_items
                                SET stock_catalog_id = NULLIF(?,0), line_no = ?, item_type = ?,
                                    semi_expendable_type = NULLIF(?, ''), account_code_id = ?,
                                    classification_id = NULLIF(?,0), item_description = ?, quantity = ?,
                                    unit_of_measure_id = ?, unit_cost = ?, line_total = ?
                                WHERE id = ? AND purchase_order_id = ?
                            ");
                            $insertItemStmt = $db->prepare("
                                INSERT INTO purchase_order_items
                                  (purchase_order_id, stock_catalog_id, line_no, item_type, semi_expendable_type, account_code_id,
                                   classification_id, item_description, quantity,
                                   unit_of_measure_id, unit_cost, line_total)
                                VALUES (?, NULLIF(?,0), ?, ?, NULLIF(?, ''), ?, NULLIF(?,0), ?, ?, ?, ?, ?)
                            ");
                        } else {
                            $updateItemStmt = $db->prepare("
                                UPDATE purchase_order_items
                                SET stock_catalog_id = NULLIF(?,0), line_no = ?, item_type = ?,
                                    account_code_id = ?, classification_id = NULLIF(?,0), item_description = ?,
                                    quantity = ?, unit_of_measure_id = ?, unit_cost = ?, line_total = ?
                                WHERE id = ? AND purchase_order_id = ?
                            ");
                            $insertItemStmt = $db->prepare("
                                INSERT INTO purchase_order_items
                                  (purchase_order_id, stock_catalog_id, line_no, item_type, account_code_id,
                                   classification_id, item_description, quantity,
                                   unit_of_measure_id, unit_cost, line_total)
                                VALUES (?, NULLIF(?,0), ?, ?, ?, NULLIF(?,0), ?, ?, ?, ?, ?)
                            ");
                        }
                        if (!$updateItemStmt || !$insertItemStmt) {
                            throw new RuntimeException('Unable to prepare item update.');
                        }

                        $keptItemIds = [];
                        $lineNoOffset = 0;
                        if ($existingIsPartial) {
                            $maxLineStmt = $db->prepare('SELECT COALESCE(MAX(line_no), 0) FROM purchase_order_items WHERE purchase_order_id = ?');
                            if ($maxLineStmt) {
                                $maxLineStmt->bind_param('i', $id);
                                $maxLineStmt->execute();
                                $maxLineResult = $maxLineStmt->get_result();
                                if ($maxLineResult) {
                                    $lineNoOffset = (int) $maxLineResult->fetch_row()[0];
                                }
                                $maxLineStmt->close();
                            }
                        }

                        foreach ($validatedItems as $index => $item) {
                            $ln = $existingIsPartial ? ($lineNoOffset + $index + 1) : ($index + 1);
                            if (!$existingIsPartial && $item['id'] <= 0 && isset($existingItemIdsByPosition[$index])) {
                                $item['id'] = $existingItemIdsByPosition[$index];
                            }
                            if ($item['id'] > 0 && isset($existingItemIds[$item['id']])) {
                                $keptItemIds[$item['id']] = true;
                                if ($poItemSupportsSemiType) {
                                    $updateItemStmt->bind_param(
                                        'iissiisdiddii',
                                        $item['stock_catalog_id'],
                                        $ln,
                                        $item['item_type'],
                                        $item['semi_expendable_type'],
                                        $item['account_code_id'],
                                        $item['classification_id'],
                                        $item['item_description'],
                                        $item['quantity'],
                                        $item['unit_of_measure_id'],
                                        $item['unit_cost'],
                                        $item['line_total'],
                                        $item['id'],
                                        $id
                                    );
                                } else {
                                    $updateItemStmt->bind_param(
                                        'iisiisdiddii',
                                        $item['stock_catalog_id'],
                                        $ln,
                                        $item['item_type'],
                                        $item['account_code_id'],
                                        $item['classification_id'],
                                        $item['item_description'],
                                        $item['quantity'],
                                        $item['unit_of_measure_id'],
                                        $item['unit_cost'],
                                        $item['line_total'],
                                        $item['id'],
                                        $id
                                    );
                                }
                                if (!$updateItemStmt->execute()) {
                                    throw new RuntimeException('Unable to update purchase order line item: ' . $updateItemStmt->error);
                                }
                                continue;
                            }

                            if ($poItemSupportsSemiType) {
                                $insertItemStmt->bind_param(
                                    'iiissiisdidd',
                                    $id,
                                    $item['stock_catalog_id'],
                                    $ln,
                                    $item['item_type'],
                                    $item['semi_expendable_type'],
                                    $item['account_code_id'],
                                    $item['classification_id'],
                                    $item['item_description'],
                                    $item['quantity'],
                                    $item['unit_of_measure_id'],
                                    $item['unit_cost'],
                                    $item['line_total']
                                );
                            } else {
                                $insertItemStmt->bind_param(
                                    'iiisiisdidd',
                                    $id,
                                    $item['stock_catalog_id'],
                                    $ln,
                                    $item['item_type'],
                                    $item['account_code_id'],
                                    $item['classification_id'],
                                    $item['item_description'],
                                    $item['quantity'],
                                    $item['unit_of_measure_id'],
                                    $item['unit_cost'],
                                    $item['line_total']
                                );
                            }
                            if (!$insertItemStmt->execute()) {
                                throw new RuntimeException('Unable to insert purchase order line item: ' . $insertItemStmt->error);
                            }
                            $keptItemIds[(int) $insertItemStmt->insert_id] = true;
                        }
                        $updateItemStmt->close();
                        $insertItemStmt->close();

                        if (!$existingIsPartial) {
                            $removedItemIds = array_values(array_diff(array_keys($existingItemIds), array_keys($keptItemIds)));
                            if ($removedItemIds) {
                                $placeholders = implode(',', array_fill(0, count($removedItemIds), '?'));
                                $types = str_repeat('i', count($removedItemIds));

                                $linkedCheckStmt = $db->prepare("
                                    SELECT COUNT(*)
                                    FROM receiving_items
                                    WHERE purchase_order_item_id IN ($placeholders)
                                ");
                                if (!$linkedCheckStmt) {
                                    throw new RuntimeException('Unable to validate removed PO lines.');
                                }
                                po_edit_bind_dynamic_params($linkedCheckStmt, $types, $removedItemIds);
                                $linkedCheckStmt->execute();
                                $linkedCheckResult = $linkedCheckStmt->get_result();
                                $linkedRemovedCount = $linkedCheckResult ? (int) ($linkedCheckResult->fetch_row()[0] ?? 0) : 0;
                                $linkedCheckStmt->close();

                                if ($linkedRemovedCount > 0) {
                                    throw new RuntimeException('This PO already has receiving records. Received line items cannot be removed from the source PO.');
                                }

                                $deleteStmt = $db->prepare("
                                    DELETE FROM purchase_order_items
                                    WHERE purchase_order_id = ? AND id IN ($placeholders)
                                ");
                                if (!$deleteStmt) {
                                    throw new RuntimeException('Unable to remove deleted PO lines.');
                                }
                                $deleteTypes = 'i' . $types;
                                $deleteParams = array_merge([$id], $removedItemIds);
                                po_edit_bind_dynamic_params($deleteStmt, $deleteTypes, $deleteParams);
                                $deleteStmt->execute();
                                $deleteStmt->close();
                            }
                        }
                    } // end if ($validatedItems)

                    $db->commit();
                    set_flash('success', 'Purchase order updated successfully.');
                    redirect('modules/purchase_orders/index.php');
                } catch (Throwable $e) {
                    $db->rollback();
                    error_log('Purchase order update failed for ID ' . $id . ': ' . $e->getMessage());
                    $message = $e->getMessage();
                    $safeMessages = [
                        'This PO already has receiving records. Received line items cannot be removed from the source PO.',
                    ];
                    $errors[] = in_array($message, $safeMessages, true) || str_starts_with($message, 'Encoded line total')
                        ? $message
                        : 'Unable to update the purchase order. Please try again.';
                }
            }
        }
    }
}

$page_title = $form['system_reference'] !== ''
    ? 'Edit Purchase Order - ' . $form['system_reference']
    : 'Edit Purchase Order';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="card-title mb-3">Edit Purchase Order</h5>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
                <?php endif; ?>

                <form id="purchaseOrderForm" method="post" action="<?php echo base_url('modules/purchase_orders/edit.php?id=' . $id); ?>" data-submit-loading="1">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="update">
                    <div id="purchaseOrderFormSummary" class="alert alert-danger d-none mb-3" role="alert" aria-live="polite"></div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="po_number" class="form-label">Hard Copy PO Number</label>
                            <input type="text" class="form-control" id="po_number" name="po_number" value="<?php echo h(strpos((string) $form['po_number'], 'NO-PO-') === 0 ? '' : $form['po_number']); ?>" placeholder="Leave blank if none">
                            <div class="form-text">Optional. If the hard copy has no PO number, the system reference will keep the record unique.</div>
                        </div>
                        <div class="col-md-3">
                            <label for="po_date" class="form-label">PO Date</label>
                            <input type="date" class="form-control" id="po_date" name="po_date" value="<?php echo h($form['po_date']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="supplier_id" class="form-label">Supplier</label>
                            <select class="form-select" id="supplier_id" name="supplier_id" required>
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo (int) $supplier['id']; ?>" data-address="<?php echo h($supplier['address'] ?? ''); ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>><?php echo h($supplier['supplier_name'] . ' (' . $supplier['supplier_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="fund_id" class="form-label">Fund</label>
                            <select class="form-select" id="fund_id" name="fund_id" required>
                                <option value="">Select fund</option>
                                <?php foreach ($funds as $fund): ?>
                                    <option value="<?php echo (int) $fund['id']; ?>" <?php echo $form['fund_id'] === (string) $fund['id'] ? 'selected' : ''; ?>><?php echo h($fund['fund_code'] . ' - ' . $fund['fund_name'] . ($fund['fund_source'] !== null && $fund['fund_source'] !== '' ? ' - ' . $fund['fund_source'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="supplier_address" class="form-label">Supplier Address</label>
                            <input type="text" class="form-control" id="supplier_address" name="supplier_address" value="<?php echo h($form['supplier_address']); ?>">
                        </div>

                        <div class="col-md-4">
                            <label for="mode_of_procurement" class="form-label">Mode of Procurement</label>
                            <select class="form-select" id="mode_of_procurement" name="mode_of_procurement_id">
                                <option value="">Select mode</option>
                                <?php foreach ($procurementModes as $procurementMode): ?>
                                    <option value="<?php echo (int) $procurementMode['id']; ?>" <?php echo $form['mode_of_procurement_id'] === (string) $procurementMode['id'] ? 'selected' : ''; ?>><?php echo h($procurementMode['mode_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="place_of_delivery" class="form-label">Place of Delivery</label>
                            <input type="text" class="form-control" id="place_of_delivery" name="place_of_delivery" value="<?php echo h($form['place_of_delivery']); ?>">
                        </div>

                        <div class="col-md-2">
                            <label for="delivery_term_days" class="form-label">Delivery Term (Days)</label>
                            <input type="number" class="form-control" id="delivery_term_days" name="delivery_term_days" min="0" step="1" value="<?php echo h($form['delivery_term_days']); ?>">
                        </div>

                        <div class="col-md-2">
                            <label for="expected_delivery_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="expected_delivery_date" name="expected_delivery_date" value="<?php echo h($form['expected_delivery_date']); ?>" readonly>
                        </div>

                        <div class="col-md-3">
                            <label for="document_total_amount" class="form-label">PO Hard Copy Total</label>
                            <input type="number" class="form-control" id="document_total_amount" name="document_total_amount" min="0" step="0.01" value="<?php echo h($form['document_total_amount']); ?>" placeholder="0.00">
                            <div class="form-text">Optional printed PO total for cross-checking.</div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 mt-4">
                        <h6 class="mb-0">PO Items</h6>
                        <div class="d-flex gap-2 flex-wrap justify-content-end">
                            <button class="btn btn-success btn-sm add-line-btn" type="button" data-type="supply">+ Supply</button>
                            <button class="btn btn-primary btn-sm add-line-btn" type="button" data-type="semi_expendable">+ Semi-Expendable</button>
                            <button class="btn btn-warning btn-sm add-line-btn" type="button" data-type="equipment">+ Equipment</button>
                        </div>
                    </div>

                    <div class="row g-3 mb-4" id="poSplitPanel">
                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body p-3 d-flex flex-column" style="gap:10px;">
                                    <div class="d-flex gap-2 flex-wrap">
                                        <div class="input-group input-group-sm" style="max-width:160px;">
                                            <input type="text" class="form-control form-control-sm" id="lineSearchInput" placeholder="Search lines...">
                                        </div>
                                        <div class="input-group input-group-sm" style="max-width:140px;">
                                            <input type="number" class="form-control form-control-sm" id="jumpLineInput" min="1" placeholder="Line #">
                                            <button class="btn btn-outline-secondary" type="button" id="jumpLineBtn">Go</button>
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn active" data-filter="all" type="button">All</button>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="supply" type="button">Supply</button>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="semi_expendable" type="button">Semi</button>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="equipment" type="button">Equipment</button>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="done" type="button">Complete</button>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="empty" type="button">Incomplete</button>
                                    </div>

                                    <div id="poLineListScroll" style="flex:1; overflow-y:auto; max-height:380px; display:flex; flex-direction:column; gap:2px;"></div>

                                    <div style="border-top:0.5px solid var(--bs-border-color); padding-top:8px; font-size:12px;">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted">Completed</span>
                                            <span id="lineCompletedCount" class="text-success fw-semibold">0 / 0</span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Total so far</span>
                                            <span id="lineTotalSoFar" class="fw-semibold">0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="card h-100">
                                <div class="card-body p-3" id="poLineEditor">
                                    <div id="poEditorEmpty" class="text-center text-muted py-5">
                                        <div class="mb-2">No lines yet.</div>
                                        <div class="small">Use the add buttons above to create your first PO line.</div>
                                    </div>
                                    <div id="poEditorContent" style="display:none;">
                                        <div class="d-flex align-items-center gap-2 mb-3">
                                            <span class="fw-semibold" id="editorLineLabel">Line 1</span>
                                            <span class="badge" id="editorTypeBadge">Supply</span>
                                            <span class="badge text-bg-secondary" id="editorSemiTypeBadge" style="display:none;">LV</span>
                                            <div class="flex-fill"></div>
                                            <span class="small text-muted" id="editorLineCounter">1 of 1</span>
                                        </div>

                                        <div class="alert alert-light border py-2 px-3 mb-3" id="editorWorkflowHelp" style="font-size:12px;"></div>

                                        <div class="mb-3" id="editorCatalogSection">
                                            <label class="form-label" style="font-size:12px;">
                                                Select from Stock Catalog
                                                <span class="text-muted fw-normal" id="editorCatalogMeta">required for supplies</span>
                                            </label>
                                            <select class="form-select form-select-sm" id="editorCatalogSearch" data-placeholder="Search stock no. or item name...">
                                                <option value="">-- Type to search catalog --</option>
                                            </select>
                                            <div id="editorCatalogHint" class="small text-muted mt-1" style="display:none;">
                                                Catalog defaults loaded. You can still refine the PO description below if needed.
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:11px;">Account Code <span class="text-danger">*</span></label>
                                            <select class="form-select form-select-sm" id="editorAccountCode" name="_editor_account_code" style="font-size:13px;"></select>
                                            <input type="text" class="form-control form-control-sm bg-light" id="editorAccountCodeText" style="font-size:13px; display:none;" readonly>
                                            <div class="mt-1" id="editorAccountCodeAddBtn">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="openAccountCodeQuickAdd" style="font-size:11px;">Add Account Code</button>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:11px;">Item Classification <span class="text-muted" style="font-size:10px;">(optional)</span></label>
                                            <select class="form-select form-select-sm" id="editorClassification" name="_editor_classification" style="font-size:13px;"></select>
                                            <input type="text" class="form-control form-control-sm bg-light" id="editorClassificationText" style="font-size:13px; display:none;" readonly>
                                            <div class="mt-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="openClassificationQuickAdd">Add Classification</button>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:11px;" id="editorDescriptionLabel">Description <span class="text-danger">*</span></label>
                                            <textarea class="form-control form-control-sm" id="editorDescription" rows="5" placeholder="Item description from hard copy PO" style="font-size:13px; border-left:3px solid var(--bs-primary-border-subtle); border-radius:0 4px 4px 0;"></textarea>
                                            <div class="small text-muted mt-1" id="editorDescriptionHint">Paste the PO description exactly as written on the hard copy when needed.</div>
                                        </div>

                                        <div class="row g-2 mb-2">
                                            <div class="col-3">
                                                <label class="form-label" style="font-size:11px;">Quantity <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control form-control-sm text-center" id="editorQty" min="0.01" step="0.01" value="1" style="font-size:13px;">
                                            </div>
                                            <div class="col-5">
                                                <label class="form-label" style="font-size:11px;">Unit</label>
                                                <select class="form-select form-select-sm" id="editorUom" style="font-size:13px;"></select>
                                                <input type="text" class="form-control form-control-sm bg-light" id="editorUomText" style="font-size:13px; display:none;" readonly>
                                                <div class="mt-1" id="editorUomAddBtn">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="openUomQuickAdd" style="font-size:11px;">Add Unit</button>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <label class="form-label" style="font-size:11px;">Unit Cost</label>
                                                <input type="number" class="form-control form-control-sm text-end" id="editorUnitCost" min="0" step="0.01" value="0.00" style="font-size:13px;">
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-end align-items-baseline gap-2 mb-3">
                                            <span class="text-muted small">Amount:</span>
                                            <span id="editorAmount" class="fw-semibold" style="font-size:16px;">0.00</span>
                                        </div>

                                        <div style="border-top:0.5px solid var(--bs-border-color); padding-top:10px;">
                                            <div class="progress mb-2" style="height:4px;">
                                                <div class="progress-bar" id="editorProgress" style="width:0%; transition:width .3s;"></div>
                                            </div>
                                            <div class="d-flex gap-2 align-items-center">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="editorPrev">← Prev</button>
                                                <div class="flex-fill text-center small text-muted" id="editorProgressLabel">0 / 0 completed</div>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="editorNext">Next →</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" id="editorDeleteLine">Remove</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="poHiddenInputs"></div>

                    <div style="position:sticky; bottom:0; z-index:10; background:var(--bs-body-bg); border-top:0.5px solid var(--bs-border-color); padding:10px 0; margin-top:4px;">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span class="text-muted small" id="footerLineCount">0 line(s)</span>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="small text-muted">PO total: <span id="poDocumentTotalDisplay"><?php echo h($form['document_total_amount'] !== '' ? number_format((float) $form['document_total_amount'], 2) : '—'); ?></span></span>
                                <span class="small text-muted">Computed total: <span id="poGrandTotal">0.00</span></span>
                                <span class="small text-muted">Delta: <span id="poTotalDelta">—</span></span>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox" id="is_partial_entry" name="is_partial_entry" value="1" <?php echo !empty($form['is_partial_entry']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label small" for="is_partial_entry">Partial Entry <span class="text-muted">(more items to add later)</span></label>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Update Purchase Order</button>
                            </div>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="accountCodeQuickAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Account Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 px-3" id="accountCodeQuickAddError" style="display:none; font-size:13px;"></div>
                <div class="row g-3">
                    <div class="col-12">
                        <label for="quickAccountCodeItemType" class="form-label">Item Type</label>
                        <input type="text" class="form-control" id="quickAccountCodeItemType" readonly>
                        <input type="hidden" id="quickAccountCodeGroup">
                    </div>
                    <div class="col-md-5">
                        <label for="quickAccountCode" class="form-label">Account Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="quickAccountCode" placeholder="e.g. 10602010-00">
                    </div>
                    <div class="col-md-7">
                        <label for="quickAccountName" class="form-label">Account Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="quickAccountName" placeholder="e.g. Office Supplies">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveAccountCodeQuickAdd">Save Account Code</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="uomQuickAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Unit of Measure</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 px-3" id="uomQuickAddError" style="display:none; font-size:13px;"></div>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label for="quickUomName" class="form-label">Unit Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="quickUomName" placeholder="e.g. Piece">
                    </div>
                    <div class="col-md-4">
                        <label for="quickUomAbbreviation" class="form-label">Abbreviation <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="quickUomAbbreviation" placeholder="e.g. pc">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveUomQuickAdd">Save Unit</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="classificationQuickAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Item Classification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 px-3" id="classificationQuickAddError" style="display:none; font-size:13px;"></div>
                <div class="row g-3">
                    <div class="col-12">
                        <label for="quickClassificationType" class="form-label">Item Type</label>
                        <input type="text" class="form-control" id="quickClassificationType" readonly>
                    </div>
                    <div class="col-12">
                        <label for="quickClassificationName" class="form-label">Classification Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="quickClassificationName">
                    </div>
                    <div class="col-12">
                        <label for="quickClassificationAccountCode" class="form-label">Default Account Code</label>
                        <select class="form-select" id="quickClassificationAccountCode"></select>
                        <div class="form-text">Classification family will copy this account code name.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="quickClassificationUsefulLife" class="form-label">Useful Life (Years)</label>
                        <input type="number" class="form-control" id="quickClassificationUsefulLife" min="0" step="1">
                    </div>
                    <div class="col-12">
                        <label for="quickClassificationDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="quickClassificationDescription" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveClassificationQuickAdd">Save Classification</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function formatNumber(n) { return parseFloat(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    var accountCodes = <?php echo json_encode($accountCodes, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var catalogItems = <?php echo json_encode($catalogItems ?? [], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var classifications = <?php echo json_encode($classifications, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var units = <?php echo json_encode($unitOfMeasures, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var semiHvMin = <?php echo json_encode((float) (($activeThreshold['semi_hv_min'] ?? 5000.01))); ?>;

    var poLinesFromPhp = <?php echo json_encode(array_values($itemRows), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var isPartialMode = <?php echo !empty($existingPo['is_partial_entry']) ? 'true' : 'false'; ?>;
    <?php
    // If the PO was loaded and $form populated server-side, update the page title
    if (!empty($form['system_reference'])) {
        $page_title = 'Edit Purchase Order — ' . h($form['system_reference']);
    }
    ?>
    var poLines = [];
    var activeIndex = -1;
    var currentFilter = 'all';
    var searchTerm = '';

    var el = {
        lineListScroll: document.getElementById('poLineListScroll'),
        lineSearchInput: document.getElementById('lineSearchInput'),
        editorEmpty: document.getElementById('poEditorEmpty'),
        editorContent: document.getElementById('poEditorContent'),
        editorLineLabel: document.getElementById('editorLineLabel'),
        editorTypeBadge: document.getElementById('editorTypeBadge'),
        editorSemiTypeBadge: document.getElementById('editorSemiTypeBadge'),
        editorLineCounter: document.getElementById('editorLineCounter'),
        editorWorkflowHelp: document.getElementById('editorWorkflowHelp'),
        editorCatalogSection: document.getElementById('editorCatalogSection'),
        editorCatalogMeta: document.getElementById('editorCatalogMeta'),
        editorCatalogSearch: document.getElementById('editorCatalogSearch'),
        editorCatalogHint: document.getElementById('editorCatalogHint'),
        editorAccountCode: document.getElementById('editorAccountCode'),
        editorAccountCodeText: document.getElementById('editorAccountCodeText'),
        editorClassification: document.getElementById('editorClassification'),
        editorClassificationText: document.getElementById('editorClassificationText'),
        editorDescriptionLabel: document.getElementById('editorDescriptionLabel'),
        editorDescription: document.getElementById('editorDescription'),
        editorDescriptionHint: document.getElementById('editorDescriptionHint'),
        editorQty: document.getElementById('editorQty'),
        editorUom: document.getElementById('editorUom'),
        editorUomText: document.getElementById('editorUomText'),
        editorUnitCost: document.getElementById('editorUnitCost'),
        editorAmount: document.getElementById('editorAmount'),
        editorProgress: document.getElementById('editorProgress'),
        editorProgressLabel: document.getElementById('editorProgressLabel'),
        editorPrev: document.getElementById('editorPrev'),
        editorNext: document.getElementById('editorNext'),
        editorDeleteLine: document.getElementById('editorDeleteLine'),
        lineCompletedCount: document.getElementById('lineCompletedCount'),
        lineTotalSoFar: document.getElementById('lineTotalSoFar'),
        footerLineCount: document.getElementById('footerLineCount'),
        poGrandTotal: document.getElementById('poGrandTotal'),
        poHiddenInputs: document.getElementById('poHiddenInputs'),
        openClassificationQuickAdd: document.getElementById('openClassificationQuickAdd'),
        classificationQuickAddModal: document.getElementById('classificationQuickAddModal'),
        classificationQuickAddError: document.getElementById('classificationQuickAddError'),
        quickClassificationType: document.getElementById('quickClassificationType'),
        quickClassificationName: document.getElementById('quickClassificationName'),
        quickClassificationAccountCode: document.getElementById('quickClassificationAccountCode'),
        quickClassificationUsefulLife: document.getElementById('quickClassificationUsefulLife'),
        quickClassificationDescription: document.getElementById('quickClassificationDescription'),
        saveClassificationQuickAdd: document.getElementById('saveClassificationQuickAdd'),
        openAccountCodeQuickAdd: document.getElementById('openAccountCodeQuickAdd'),
        accountCodeQuickAddModal: document.getElementById('accountCodeQuickAddModal'),
        accountCodeQuickAddError: document.getElementById('accountCodeQuickAddError'),
        quickAccountCodeItemType: document.getElementById('quickAccountCodeItemType'),
        quickAccountCodeGroup: document.getElementById('quickAccountCodeGroup'),
        quickAccountCode: document.getElementById('quickAccountCode'),
        quickAccountName: document.getElementById('quickAccountName'),
        saveAccountCodeQuickAdd: document.getElementById('saveAccountCodeQuickAdd'),
        openUomQuickAdd: document.getElementById('openUomQuickAdd'),
        uomQuickAddModal: document.getElementById('uomQuickAddModal'),
        uomQuickAddError: document.getElementById('uomQuickAddError'),
        quickUomName: document.getElementById('quickUomName'),
        quickUomAbbreviation: document.getElementById('quickUomAbbreviation'),
        saveUomQuickAdd: document.getElementById('saveUomQuickAdd'),
        editorAccountCodeAddBtn: document.getElementById('editorAccountCodeAddBtn'),
        editorUomAddBtn: document.getElementById('editorUomAddBtn')
    };
    var classificationQuickAddModal = (window.bootstrap && el.classificationQuickAddModal) ? new bootstrap.Modal(el.classificationQuickAddModal) : null;
    var accountCodeQuickAddModal = (window.bootstrap && el.accountCodeQuickAddModal) ? new bootstrap.Modal(el.accountCodeQuickAddModal) : null;
    var uomQuickAddModal = (window.bootstrap && el.uomQuickAddModal) ? new bootstrap.Modal(el.uomQuickAddModal) : null;

    function lineIsComplete(line) {
        if (!line) return false;
        var hasCatalog = !lineUsesCatalog(line) || (line.stock_catalog_id || '') !== '';
        return hasCatalog && (line.account_code_id || '') !== '' && (String(line.item_description || '').trim() !== '') && (parseFloat(line.quantity || 0) > 0);
    }
    function typeBadgeClass(t) { if (t === 'equipment') return 'bg-warning text-dark'; if (t === 'semi_expendable') return 'bg-primary'; if (t === 'supply') return 'bg-success'; return 'bg-secondary'; }
    function typeLabel(t) { if (t === 'equipment') return 'Equipment'; if (t === 'semi_expendable') return 'Semi-Expendable'; return 'Supply'; }
    function typeShortLabel(t) { if (t === 'equipment') return 'Equip'; if (t === 'semi_expendable') return 'Semi'; return 'Supply'; }
    function lineUsesCatalog(line) { return !!line && line.item_type === 'supply'; }
    function expectedAccountGroup(itemType) { return itemType === 'equipment' ? 'asset' : itemType; }
    function getSemiType(unitCost) { return parseFloat(unitCost || 0) >= semiHvMin ? 'high_value' : 'low_value'; }
    function lineNeedsSemiType(line) { return !!line && line.item_type === 'semi_expendable'; }
    function classificationMatchesType(classification, itemType) { return !classification || !classification.classification_group || classification.classification_group === expectedAccountGroup(itemType); }
    function classificationDisplayName(classification) { if (!classification) return ''; var family = String(classification.classification_family || '').trim(); var name = String(classification.classification_name || '').trim(); return family ? (family + ' / ' + name) : name; }
    function accountCodeMatchesType(accountCode, itemType) { if (itemType === 'supply') return true; return !accountCode || !accountCode.account_group || accountCode.account_group === expectedAccountGroup(itemType); }
    function accountCodeLabelById(id) { for (var i = 0; i < accountCodes.length; i++) { if (String(accountCodes[i].id) === String(id)) return (accountCodes[i].account_code || '') + ' - ' + (accountCodes[i].account_name || ''); } return ''; }
    function classificationNameById(id) { for (var i = 0; i < classifications.length; i++) { if (String(classifications[i].id) === String(id)) return classificationDisplayName(classifications[i]); } return ''; }
    function hasClassificationMatchForType(itemType) { for (var i = 0; i < classifications.length; i++) { if (classificationMatchesType(classifications[i], itemType)) return true; } return false; }
    function uomLabelById(id) { for (var i = 0; i < units.length; i++) { if (String(units[i].id) === String(id)) return (units[i].uom_name || '') + ((units[i].abbreviation || '') ? ' (' + units[i].abbreviation + ')' : ''); } return ''; }
    function syncLineMode(line) {
        if (!line) return;
        if (!lineUsesCatalog(line)) {
            line.stock_catalog_id = '';
        }
    }

    function updateSemiTypeBadge(line) {
        if (!el.editorSemiTypeBadge) return;
        if (!lineNeedsSemiType(line)) {
            el.editorSemiTypeBadge.style.display = 'none';
            el.editorSemiTypeBadge.textContent = '';
            el.editorSemiTypeBadge.className = 'badge text-bg-secondary';
            return;
        }
        line.semi_expendable_type = getSemiType(line.unit_cost || 0);
        el.editorSemiTypeBadge.style.display = '';
        el.editorSemiTypeBadge.textContent = line.semi_expendable_type === 'high_value' ? 'HV' : 'LV';
        el.editorSemiTypeBadge.className = line.semi_expendable_type === 'high_value' ? 'badge text-bg-danger' : 'badge text-bg-info';
    }

    function updateEditorMode(line) {
        if (!line) return;
        var usesCatalog = lineUsesCatalog(line);
        if (el.editorWorkflowHelp) {
            if (usesCatalog) {
                el.editorWorkflowHelp.className = 'alert alert-success border py-2 px-3 mb-3';
                el.editorWorkflowHelp.innerHTML = '<strong>Supply workflow:</strong> keep this line tied to the stock catalog, then adjust the PO description only if the hard copy adds extra detail.';
            } else if (line.item_type === 'semi_expendable') {
                el.editorWorkflowHelp.className = 'alert alert-primary border py-2 px-3 mb-3';
                el.editorWorkflowHelp.innerHTML = '<strong>Manual workflow:</strong> update this semi-expendable item from the hard copy PO. HV/LV is still computed from unit cost.';
            } else {
                el.editorWorkflowHelp.className = 'alert alert-warning border py-2 px-3 mb-3';
                el.editorWorkflowHelp.innerHTML = '<strong>Manual workflow:</strong> update this equipment item directly from the hard copy PO, including the full specification when needed.';
            }
        }
        if (el.editorCatalogSection) el.editorCatalogSection.style.display = usesCatalog ? '' : 'none';
        if (el.editorCatalogMeta) el.editorCatalogMeta.textContent = usesCatalog ? 'required for supplies' : 'not used for manual lines';
        if (el.editorCatalogHint) el.editorCatalogHint.style.display = usesCatalog && line.stock_catalog_id ? '' : 'none';
        if (el.editorDescriptionLabel) {
            el.editorDescriptionLabel.innerHTML = usesCatalog
                ? 'Description / PO Detail <span class="text-danger">*</span>'
                : 'Detailed PO Description <span class="text-danger">*</span>';
        }
        if (el.editorDescriptionHint) {
            el.editorDescriptionHint.textContent = usesCatalog
                ? 'This description comes directly from the selected stock catalog item.'
                : 'Paste the hard copy PO description/specification here, even if it is long.';
        }
        if (el.editorDescription) {
            el.editorDescription.rows = usesCatalog ? 4 : 8;
            el.editorDescription.placeholder = usesCatalog
                ? 'Description from stock catalog'
                : 'Detailed description from the hard copy PO';
            el.editorDescription.readOnly = usesCatalog;
            el.editorDescription.classList.toggle('bg-light', usesCatalog);
        }
        if (el.editorAccountCode) {
            el.editorAccountCode.style.display = usesCatalog ? 'none' : '';
            if (window.jQuery) window.jQuery(el.editorAccountCode).nextAll('.select2').first().toggle(!usesCatalog);
        }
        if (el.editorAccountCodeText) {
            el.editorAccountCodeText.style.display = usesCatalog ? '' : 'none';
            el.editorAccountCodeText.value = usesCatalog ? accountCodeLabelById(line.account_code_id || '') : '';
        }
        if (el.editorClassification) {
            el.editorClassification.style.display = usesCatalog ? 'none' : '';
            if (window.jQuery) window.jQuery(el.editorClassification).nextAll('.select2').first().toggle(!usesCatalog);
        }
        if (el.editorClassificationText) {
            el.editorClassificationText.style.display = usesCatalog ? '' : 'none';
            el.editorClassificationText.value = usesCatalog ? classificationNameById(line.classification_id || '') : '';
        }
        if (el.editorUom) {
            el.editorUom.style.display = usesCatalog ? 'none' : '';
            if (window.jQuery) window.jQuery(el.editorUom).nextAll('.select2').first().toggle(!usesCatalog);
        }
        if (el.editorUomText) {
            el.editorUomText.style.display = usesCatalog ? '' : 'none';
            el.editorUomText.value = usesCatalog ? uomLabelById(line.unit_of_measure_id || '') : '';
        }
        if (el.openAccountCodeQuickAdd) { el.openAccountCodeQuickAdd.style.display = usesCatalog ? 'none' : ''; }
        if (el.editorAccountCodeAddBtn) { el.editorAccountCodeAddBtn.style.display = usesCatalog ? 'none' : ''; }
        if (el.openUomQuickAdd) { el.openUomQuickAdd.style.display = usesCatalog ? 'none' : ''; }
        if (el.editorUomAddBtn) { el.editorUomAddBtn.style.display = usesCatalog ? 'none' : ''; }
    }

    function populateCatalogSelect(selectedId) {
        var sel = el.editorCatalogSearch;
        if (!sel) return;
        sel.innerHTML = '<option value="">-- Search catalog --</option>';
        catalogItems.forEach(function(ci) {
            var line = (activeIndex >= 0 && activeIndex < poLines.length) ? poLines[activeIndex] : null;
            if (lineUsesCatalog(line) && ci.item_type !== 'supply') return;
            var opt = document.createElement('option');
            opt.value = ci.id;
            opt.setAttribute('data-item', JSON.stringify(ci));
            opt.textContent = (ci.stock_no || 'NO-STOCK-NO') + ' - ' + ci.item_name + ' [' + typeLabel(ci.item_type) + ']';
            if (String(ci.id) === String(selectedId)) opt.selected = true;
            sel.appendChild(opt);
        });
        if (window.jQuery && jQuery.fn.select2) {
            var $sel = window.jQuery(sel);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
            $sel.select2({ placeholder: 'Search stock no. or item name...', allowClear: true, width: '100%', dropdownParent: window.jQuery(document.body) });
        }
    }

    function rebuildAccountCodeSelect(itemType, selectedId) {
        var sel = el.editorAccountCode; if (!sel) return; sel.innerHTML = '<option value="">Select account code</option>';
        accountCodes.forEach(function(ac){ if (!accountCodeMatchesType(ac, itemType)) return; var opt = document.createElement('option'); opt.value = ac.id; opt.textContent = ac.account_code + ' - ' + ac.account_name; if (String(ac.id) === String(selectedId)) opt.selected = true; sel.appendChild(opt); });
        if (window.jQuery && jQuery.fn.select2) { var $sel = window.jQuery(sel); if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy'); $sel.select2({ placeholder: 'Select account code', allowClear: true, width: '100%', dropdownParent: window.jQuery(document.body) }); }
    }

    function rebuildClassificationSelect(itemType, selectedId) { var sel = el.editorClassification; if (!sel) return; sel.innerHTML = '<option value="">Select item classification</option>'; var useFallbackList = !hasClassificationMatchForType(itemType); classifications.forEach(function(cl){ if (!useFallbackList && !classificationMatchesType(cl, itemType)) return; var opt = document.createElement('option'); opt.value = cl.id; opt.textContent = classificationDisplayName(cl); opt.setAttribute('data-item-type', cl.classification_group === 'asset' ? 'equipment' : (cl.classification_group || '')); if (String(cl.id) === String(selectedId)) opt.selected = true; sel.appendChild(opt); }); if (window.jQuery && jQuery.fn.select2) { var $sel = window.jQuery(sel); if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy'); $sel.select2({ placeholder: 'Select item classification', allowClear: true, width: '100%', dropdownParent: window.jQuery(document.body) }); } }

    function rebuildUomSelect(selectedId) { var sel = el.editorUom; if (!sel) return; sel.innerHTML = '<option value="">Select unit</option>'; units.forEach(function(u){ var opt = document.createElement('option'); opt.value = u.id; opt.textContent = u.uom_name + ' (' + u.abbreviation + ')'; if (String(u.id) === String(selectedId)) opt.selected = true; sel.appendChild(opt); }); if (window.jQuery && jQuery.fn.select2) { var $sel = window.jQuery(sel); if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy'); $sel.select2({ placeholder: 'Select unit', allowClear: true, width: '100%', dropdownParent: window.jQuery(document.body) }); } }

    function rebuildClassificationQuickAddAccountCodes(itemType, selectedId) { if (!el.quickClassificationAccountCode) return; el.quickClassificationAccountCode.innerHTML = '<option value="">No default account code</option>'; accountCodes.forEach(function(ac){ if (!accountCodeMatchesType(ac, itemType)) return; var opt = document.createElement('option'); opt.value = ac.id; opt.textContent = ac.account_code + ' - ' + ac.account_name; if (String(ac.id) === String(selectedId)) opt.selected = true; el.quickClassificationAccountCode.appendChild(opt); }); if (window.jQuery && jQuery.fn.select2) { var $sel = window.jQuery(el.quickClassificationAccountCode); if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy'); $sel.select2({ placeholder: 'No default account code', allowClear: true, width: '100%', dropdownParent: window.jQuery(el.classificationQuickAddModal) }); } }

    function showClassificationQuickAddError(message) { if (!el.classificationQuickAddError) return; el.classificationQuickAddError.textContent = message || ''; el.classificationQuickAddError.style.display = message ? '' : 'none'; }

    function upsertClassification(classification) { if (!classification || !classification.id) return; var idx = -1; for (var i = 0; i < classifications.length; i++) { if (String(classifications[i].id) === String(classification.id)) { idx = i; break; } } if (idx >= 0) { classifications[idx] = Object.assign({}, classifications[idx], classification); } else { classifications.push(classification); } classifications.sort(function(a, b) { return classificationDisplayName(a).localeCompare(classificationDisplayName(b)); }); }

    function upsertAccountCode(ac) { if (!ac || !ac.id) return; var idx = -1; for (var i = 0; i < accountCodes.length; i++) { if (String(accountCodes[i].id) === String(ac.id)) { idx = i; break; } } if (idx >= 0) { accountCodes[idx] = Object.assign({}, accountCodes[idx], ac); } else { accountCodes.push(ac); } accountCodes.sort(function(a, b) { return (a.account_code || '').localeCompare(b.account_code || ''); }); }

    function upsertUom(u) { if (!u || !u.id) return; var idx = -1; for (var i = 0; i < units.length; i++) { if (String(units[i].id) === String(u.id)) { idx = i; break; } } if (idx >= 0) { units[idx] = Object.assign({}, units[idx], u); } else { units.push(u); } units.sort(function(a, b) { return (a.uom_name || '').localeCompare(b.uom_name || ''); }); }

    function showQuickAddError(errorId, message) { var errorEl = document.getElementById(errorId); if (!errorEl) return; errorEl.textContent = message || ''; errorEl.style.display = message ? '' : 'none'; }
    function clearQuickAddError(errorId) { showQuickAddError(errorId, ''); }

    function bindQuickAddModal(config) {
        var modalEl = document.getElementById(config.modalId);
        var saveBtn = document.getElementById(config.saveBtnId);
        if (!modalEl || !saveBtn) return;

        modalEl.addEventListener('show.bs.modal', function () {
            clearQuickAddError(config.errorId);
            if (typeof config.onShow === 'function') {
                config.onShow();
            }
        });

        saveBtn.addEventListener('click', async function () {
            clearQuickAddError(config.errorId);
            var payload = typeof config.buildPayload === 'function' ? config.buildPayload() : null;
            if (!payload) {
                showQuickAddError(config.errorId, 'Please complete required fields.');
                return;
            }

            saveBtn.disabled = true;
            try {
                var response = await fetch(config.endpoint, { method: 'POST', body: payload });
                var result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || 'Unable to save item.');
                }
                if (typeof config.onSuccess === 'function') {
                    config.onSuccess(result);
                }
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            } catch (err) {
                showQuickAddError(config.errorId, err.message || 'Unable to save item.');
            } finally {
                saveBtn.disabled = false;
            }
        });
    }

    function seedClassificationQuickAdd() { var line = (activeIndex >= 0 && activeIndex < poLines.length) ? poLines[activeIndex] : null; var itemType = line ? (line.item_type || 'supply') : 'supply'; if (el.quickClassificationType) { el.quickClassificationType.value = typeLabel(itemType); el.quickClassificationType.setAttribute('data-item-type', itemType); } if (el.quickClassificationName) el.quickClassificationName.value = ''; if (el.quickClassificationDescription) el.quickClassificationDescription.value = ''; if (el.quickClassificationUsefulLife) el.quickClassificationUsefulLife.value = itemType === 'supply' ? '' : '3'; rebuildClassificationQuickAddAccountCodes(itemType, line ? line.account_code_id : ''); showClassificationQuickAddError(''); }
    function escapeHtml(s) { return String(s || '').replace(/[&<>\"]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];}); }

    function renderLineList() { var container = el.lineListScroll; if (!container) return; container.innerHTML = ''; var done = 0; var total = poLines.length; var sum = 0; for (var i = 0; i < poLines.length; i++) { var ln = poLines[i]; syncLineMode(ln); ln.semi_expendable_type = lineNeedsSemiType(ln) ? getSemiType(ln.unit_cost || 0) : ''; ln.is_complete = lineIsComplete(ln); if (ln.is_complete) done++; sum += parseFloat(ln.line_total || 0); if (currentFilter === 'done' && !ln.is_complete) continue; if (currentFilter === 'empty' && ln.is_complete) continue; if (currentFilter === 'supply' && ln.item_type !== 'supply') continue; if (currentFilter === 'semi_expendable' && ln.item_type !== 'semi_expendable') continue; if (currentFilter === 'equipment' && ln.item_type !== 'equipment') continue; var searchBlob = [ln.item_description || '', ln.item_type || '', classificationNameById(ln.classification_id || ''), uomLabelById(ln.unit_of_measure_id || ''), accountCodeLabelById(ln.account_code_id || '')].join(' ').toLowerCase(); if (searchTerm && searchBlob.indexOf(searchTerm) === -1) continue; var dotColor = (i === activeIndex) ? '#0d6efd' : (ln.is_complete ? '#198754' : '#adb5bd'); var badgeClass = (ln.item_type === 'equipment') ? 'text-bg-warning-subtle' : (ln.item_type === 'semi_expendable' ? 'text-bg-primary-subtle' : 'text-bg-success-subtle'); var shortType = typeShortLabel(ln.item_type); var desc = (ln.item_description || 'New item'); var amt = (parseFloat(ln.line_total || 0) !== 0) ? formatNumber(ln.line_total) : '�'; var row = document.createElement('div'); row.className = 'po-line-list-item'; row.setAttribute('data-index', i); row.style.cssText = 'display:flex; align-items:center; gap:6px; padding:6px 8px; border-radius:6px; cursor:pointer; font-size:12px; border:0.5px solid transparent;'; row.innerHTML = '<span style="width:20px; text-align:center; color:var(--bs-body-color); opacity:0.5; font-size:11px;">' + (i+1) + '</span>' + (isPartialMode && ln.is_existing ? '<span title="Existing \u2013 locked" style="font-size:10px; opacity:0.5; flex-shrink:0;">&#128274;</span>' : '') + '<span class="po-line-status-dot" style="width:8px; height:8px; border-radius:50%; flex-shrink:0; background:' + dotColor + ';"></span>' + '<span class="badge ' + badgeClass + '" style="font-size:9px; padding:1px 5px; flex-shrink:0;">' + shortType + '</span>' + '<span style="flex:1; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; margin-left:6px; color:var(--bs-body-color);">' + escapeHtml(desc) + '</span>' + '<span style="font-size:11px; color:var(--bs-body-color); opacity:0.65; flex-shrink:0; margin-left:8px;">' + amt + '</span>'; (function(index){ row.addEventListener('click', function(){ loadLineEditor(index); }); })(i); if (i === activeIndex) { row.style.background = 'var(--bs-primary-bg-subtle)'; row.style.borderColor = 'var(--bs-primary-border-subtle)'; } container.appendChild(row); } el.lineCompletedCount && (el.lineCompletedCount.textContent = done + ' / ' + total); el.lineTotalSoFar && (el.lineTotalSoFar.textContent = formatNumber(sum)); el.footerLineCount && (el.footerLineCount.textContent = total + ' line(s)'); el.poGrandTotal && (el.poGrandTotal.textContent = formatNumber(sum)); }

    function renderLineList() {
        var container = el.lineListScroll;
        if (!container) return;
        container.innerHTML = '';
        var done = 0;
        var total = poLines.length;
        var sum = 0;

        for (var i = 0; i < poLines.length; i++) {
            var ln = poLines[i];
            ln.index = i;
            syncLineMode(ln);
            ln.semi_expendable_type = lineNeedsSemiType(ln) ? getSemiType(ln.unit_cost || 0) : '';
            ln.is_complete = lineIsComplete(ln);
            if (ln.is_complete) done++;
            sum += parseFloat(ln.line_total || 0);

            if (currentFilter === 'done' && !ln.is_complete) continue;
            if (currentFilter === 'empty' && ln.is_complete) continue;
            if (currentFilter === 'supply' && ln.item_type !== 'supply') continue;
            if (currentFilter === 'semi_expendable' && ln.item_type !== 'semi_expendable') continue;
            if (currentFilter === 'equipment' && ln.item_type !== 'equipment') continue;

            var searchBlob = [ln.item_description || '', ln.item_type || '', classificationNameById(ln.classification_id || ''), uomLabelById(ln.unit_of_measure_id || ''), accountCodeLabelById(ln.account_code_id || '')].join(' ').toLowerCase();
            if (searchTerm && searchBlob.indexOf(searchTerm) === -1) continue;

            var isLockedExisting = isPartialMode && !!ln.is_existing;
            var canMoveUp = !isLockedExisting && i > 0 && !(isPartialMode && poLines[i - 1] && poLines[i - 1].is_existing);
            var canMoveDown = !isLockedExisting && i < poLines.length - 1 && !(isPartialMode && poLines[i + 1] && poLines[i + 1].is_existing);
            var dotColor = (i === activeIndex) ? '#0d6efd' : (ln.is_complete ? '#198754' : '#adb5bd');
            var badgeClass = (ln.item_type === 'equipment') ? 'text-bg-warning-subtle' : (ln.item_type === 'semi_expendable' ? 'text-bg-primary-subtle' : 'text-bg-success-subtle');
            var shortType = typeShortLabel(ln.item_type);
            var desc = (ln.item_description || 'New item');
            var classificationName = classificationNameById(ln.classification_id || '');
            var amt = (parseFloat(ln.line_total || 0) !== 0) ? formatNumber(ln.line_total) : '-';
            var lockIcon = isLockedExisting ? '<span title="Existing locked line" style="font-size:10px; opacity:0.5; flex-shrink:0;">&#128274;</span>' : '';

            var row = document.createElement('div');
            row.className = 'po-line-list-item';
            row.setAttribute('data-index', i);
            row.style.cssText = 'display:flex; align-items:center; gap:6px; padding:6px 8px; border-radius:6px; cursor:pointer; font-size:12px; border:0.5px solid transparent;';
            row.innerHTML = '<span style="width:20px; text-align:center; color:var(--bs-body-color); opacity:0.5; font-size:11px;">' + (i + 1) + '</span>' +
                '<span style="display:flex; flex-direction:column; gap:1px; flex-shrink:0;">' +
                '<button type="button" class="btn btn-light btn-sm po-line-move-btn" data-direction="-1" title="Move line up" aria-label="Move line ' + (i + 1) + ' up" style="width:20px; height:16px; line-height:1; padding:0; font-size:10px;" ' + (canMoveUp ? '' : 'disabled') + '>&#8593;</button>' +
                '<button type="button" class="btn btn-light btn-sm po-line-move-btn" data-direction="1" title="Move line down" aria-label="Move line ' + (i + 1) + ' down" style="width:20px; height:16px; line-height:1; padding:0; font-size:10px;" ' + (canMoveDown ? '' : 'disabled') + '>&#8595;</button>' +
                '</span>' +
                lockIcon +
                '<span class="po-line-status-dot" style="width:8px; height:8px; border-radius:50%; flex-shrink:0; background:' + dotColor + ';"></span>' +
                '<span class="badge ' + badgeClass + '" style="font-size:9px; padding:1px 5px; flex-shrink:0;">' + shortType + '</span>' +
                '<span style="flex:1; min-width:0; overflow:hidden; margin-left:6px; color:var(--bs-body-color);">' +
                '<span style="display:block; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">' + escapeHtml(desc) + '</span>' +
                '<span style="display:block; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; font-size:10px; color:var(--bs-secondary-color);">' + (classificationName ? escapeHtml(classificationName) : 'No classification') + '</span>' +
                '</span>' +
                '<span style="font-size:11px; color:var(--bs-body-color); opacity:0.65; flex-shrink:0; margin-left:8px;">' + amt + '</span>';

            (function(index){ row.addEventListener('click', function(){ loadLineEditor(index); }); })(i);
            Array.from(row.querySelectorAll('.po-line-move-btn')).forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var listRow = this.closest('.po-line-list-item');
                    var rowIndex = listRow ? parseInt(listRow.getAttribute('data-index'), 10) : -1;
                    moveLine(rowIndex, parseInt(this.getAttribute('data-direction'), 10) || 0);
                });
            });
            if (i === activeIndex) {
                row.style.background = 'var(--bs-primary-bg-subtle)';
                row.style.borderColor = 'var(--bs-primary-border-subtle)';
            }
            container.appendChild(row);
        }

        el.lineCompletedCount && (el.lineCompletedCount.textContent = done + ' / ' + total);
        el.lineTotalSoFar && (el.lineTotalSoFar.textContent = formatNumber(sum));
        el.footerLineCount && (el.footerLineCount.textContent = total + ' line(s)');
        el.poGrandTotal && (el.poGrandTotal.textContent = formatNumber(sum));
        buildHiddenInputs();
    }

    function loadLineEditor(index) { if (poLines.length === 0) { el.editorEmpty.style.display = ''; el.editorContent.style.display = 'none'; activeIndex = -1; renderLineList(); return; } activeIndex = index; var line = poLines[index]; syncLineMode(line); el.editorEmpty.style.display = 'none'; el.editorContent.style.display = ''; el.editorLineLabel.textContent = 'Line ' + (index + 1); el.editorTypeBadge.className = 'badge ' + typeBadgeClass(line.item_type); el.editorTypeBadge.textContent = typeLabel(line.item_type); updateSemiTypeBadge(line); el.editorLineCounter.textContent = (index + 1) + ' of ' + poLines.length; populateCatalogSelect(line.stock_catalog_id || ''); updateEditorMode(line); rebuildAccountCodeSelect(line.item_type, line.account_code_id); rebuildClassificationSelect(line.item_type, line.classification_id); rebuildUomSelect(line.unit_of_measure_id); updateEditorMode(line); if (lineUsesCatalog(line) && !line.item_description && line.stock_catalog_id) { for (var ciIdx = 0; ciIdx < catalogItems.length; ciIdx++) { if (String(catalogItems[ciIdx].id) === String(line.stock_catalog_id)) { line.item_description = catalogItems[ciIdx].item_description || catalogItems[ciIdx].item_name || ''; break; } } } el.editorDescription.value = line.item_description || ''; el.editorQty.value = line.quantity || '1'; el.editorUnitCost.value = line.unit_cost || '0.00'; el.editorAmount.textContent = formatNumber(line.line_total || 0); var isExistingLine = isPartialMode && !!line.is_existing; ['editorDescription','editorQty','editorUnitCost'].forEach(function(id){ var n = document.getElementById(id); if (n) { n.disabled = isExistingLine; n.readOnly = false; } }); if (el.editorCatalogSearch) el.editorCatalogSearch.disabled = isExistingLine; if (el.editorAccountCode) el.editorAccountCode.disabled = isExistingLine; if (el.editorClassification) el.editorClassification.disabled = isExistingLine; if (el.editorUom) el.editorUom.disabled = isExistingLine; if (el.editorDeleteLine) el.editorDeleteLine.style.display = isExistingLine ? 'none' : ''; if (isExistingLine && el.editorWorkflowHelp) { el.editorWorkflowHelp.className = 'alert alert-secondary border py-2 px-3 mb-3'; el.editorWorkflowHelp.textContent = 'Existing item \u2014 read only. Items already in the PO cannot be modified.'; } el.editorPrev.disabled = (index === 0); el.editorNext.disabled = (index === poLines.length - 1); var done = poLines.filter(lineIsComplete).length; var pct = poLines.length ? Math.round((done / poLines.length) * 100) : 0; el.editorProgress.style.width = pct + '%'; el.editorProgressLabel.textContent = done + ' / ' + poLines.length + ' completed'; renderLineList(); if (window.SPAMS && typeof window.SPAMS.initSelect2 === 'function') window.SPAMS.initSelect2(document.getElementById('poLineEditor')); }

    function saveCurrentLine() { if (activeIndex < 0 || activeIndex >= poLines.length) return; var ln = poLines[activeIndex]; if (isPartialMode && ln.is_existing) return; ln.stock_catalog_id = lineUsesCatalog(ln) && el.editorCatalogSearch ? (el.editorCatalogSearch.value || '') : ''; ln.account_code_id = el.editorAccountCode.value || ''; var currentClassOpt = el.editorClassification ? el.editorClassification.options[el.editorClassification.selectedIndex] : null; var currentClassType = currentClassOpt ? currentClassOpt.getAttribute('data-item-type') : ''; if (currentClassType && currentClassType !== ln.item_type) { el.editorClassification.value = ''; } ln.classification_id = el.editorClassification ? (el.editorClassification.value || '') : ''; if (lineUsesCatalog(ln)) { var supplyCatalog = null; for (var catIdx = 0; catIdx < catalogItems.length; catIdx++) { if (String(catalogItems[catIdx].id) === String(ln.stock_catalog_id)) { supplyCatalog = catalogItems[catIdx]; break; } } ln.item_description = supplyCatalog ? ((supplyCatalog.item_description || supplyCatalog.item_name || '').trim()) : ''; ln.account_code_id = supplyCatalog ? String(supplyCatalog.account_code_id || '') : ''; ln.classification_id = supplyCatalog ? String(supplyCatalog.classification_id || '') : ''; ln.unit_of_measure_id = supplyCatalog ? String(supplyCatalog.unit_of_measure_id || '') : ''; if (el.editorDescription) { el.editorDescription.value = ln.item_description; } if (el.editorAccountCodeText) el.editorAccountCodeText.value = accountCodeLabelById(ln.account_code_id || ''); if (el.editorClassificationText) el.editorClassificationText.value = classificationNameById(ln.classification_id || ''); if (el.editorUomText) el.editorUomText.value = uomLabelById(ln.unit_of_measure_id || ''); } else { ln.item_description = (el.editorDescription.value || '').trim(); ln.unit_of_measure_id = el.editorUom.value || ''; } ln.quantity = el.editorQty.value || '0'; ln.unit_cost = el.editorUnitCost.value || '0'; ln.semi_expendable_type = lineNeedsSemiType(ln) ? getSemiType(ln.unit_cost) : ''; syncLineMode(ln); ln.line_total = Math.round((parseFloat(ln.quantity || 0) * parseFloat(ln.unit_cost || 0)) * 100) / 100; ln.is_complete = lineIsComplete(ln); el.editorAmount.textContent = formatNumber(ln.line_total || 0); updateSemiTypeBadge(ln); renderLineList(); updateGrandTotal(); }

    function updateEditorAmount() { var q = parseFloat(el.editorQty.value || 0) || 0; var c = parseFloat(el.editorUnitCost.value || 0) || 0; el.editorAmount.textContent = formatNumber(Math.round(q * c * 100) / 100); }

    function renumberPoLines() {
        poLines.forEach(function(line, index) {
            line.index = index;
        });
    }

    function moveLine(index, direction) {
        if (direction !== -1 && direction !== 1) return;
        if (index < 0 || index >= poLines.length) return;
        if (isPartialMode && poLines[index] && poLines[index].is_existing) return;
        var targetIndex = index + direction;
        if (targetIndex < 0 || targetIndex >= poLines.length) return;
        if (isPartialMode && poLines[targetIndex] && poLines[targetIndex].is_existing) return;

        saveCurrentLine();
        var activeLine = activeIndex >= 0 && activeIndex < poLines.length ? poLines[activeIndex] : null;
        var movedLine = poLines.splice(index, 1)[0];
        poLines.splice(targetIndex, 0, movedLine);
        renumberPoLines();
        activeIndex = activeLine ? poLines.indexOf(activeLine) : targetIndex;
        loadLineEditor(activeIndex >= 0 ? activeIndex : targetIndex);
        updateGrandTotal();
    }

    function deleteLine(idx) { if (isPartialMode && poLines[idx] && poLines[idx].is_existing) { showFormSummary('Existing items cannot be removed. Only new items added in this session can be deleted.'); return; } if (poLines.length <= 1) { showFormSummary('At least one line is required.'); return; } poLines.splice(idx,1); poLines.forEach(function(l,i){ l.index = i; }); var nextIndex = Math.min(idx, poLines.length-1); renderLineList(); loadLineEditor(nextIndex); }

    function buildHiddenInputs() { var container = el.poHiddenInputs; if (!container) return; container.innerHTML = ''; poLines.forEach(function(ln,i){ var fields = { id: ln.id || '', item_type: ln.item_type, semi_expendable_type: ln.semi_expendable_type, stock_catalog_id: ln.stock_catalog_id, account_code_id: ln.account_code_id, classification_id: ln.classification_id, item_description: ln.item_description, quantity: ln.quantity, unit_of_measure_id: ln.unit_of_measure_id, unit_cost: ln.unit_cost, is_existing: ln.is_existing ? '1' : '0' }; Object.keys(fields).forEach(function(k){ var inp = document.createElement('input'); inp.type='hidden'; inp.name='items['+i+']['+k+']'; inp.value = fields[k] || ''; container.appendChild(inp); }); }); }

    function addLine(itemType) { var validTypes = ['supply', 'semi_expendable', 'equipment']; if (validTypes.indexOf(itemType) === -1) itemType = 'supply'; poLines.push({ id: '', index: poLines.length, item_type: itemType, semi_expendable_type: itemType === 'semi_expendable' ? 'low_value' : '', stock_catalog_id: '', account_code_id: '', classification_id: '', item_description: '', quantity: '1', unit_of_measure_id: '', unit_cost: '0.00', line_total: 0, is_complete: false, is_existing: false }); renderLineList(); loadLineEditor(poLines.length - 1); }

    function updateGrandTotal() {
        var total = poLines.reduce(function(acc,ln){ return acc + (parseFloat(ln.line_total||0)); },0);
        el.poGrandTotal && (el.poGrandTotal.textContent = formatNumber(total));
        el.lineTotalSoFar && (el.lineTotalSoFar.textContent = formatNumber(total));

        var documentTotalInput = document.getElementById('document_total_amount');
        var documentTotalDisplay = document.getElementById('poDocumentTotalDisplay');
        var totalDelta = document.getElementById('poTotalDelta');
        var partialEntryInput = document.getElementById('is_partial_entry');
        var isPartialEntry = partialEntryInput ? partialEntryInput.checked : false;
        var documentTotalRaw = documentTotalInput ? String(documentTotalInput.value || '').trim() : '';
        var documentTotal = documentTotalRaw !== '' ? parseFloat(documentTotalRaw) : NaN;
        var hasDocumentTotal = documentTotalRaw !== '' && !isNaN(documentTotal);

        if (documentTotalDisplay) {
            documentTotalDisplay.textContent = hasDocumentTotal ? formatNumber(documentTotal) : '—';
        }

        if (totalDelta) {
            if (hasDocumentTotal) {
                var delta = Math.round((total - documentTotal) * 100) / 100;
                totalDelta.textContent = formatNumber(delta);
                totalDelta.className = Math.abs(delta) > 0.009
                    ? (isPartialEntry ? 'text-warning fw-semibold' : 'text-danger fw-semibold')
                    : 'text-success fw-semibold';
            } else {
                totalDelta.textContent = '—';
                totalDelta.className = 'text-muted';
            }
        }
    }

    Array.from(document.querySelectorAll('.add-line-btn')).forEach(function(b){ b.addEventListener('click', function(){ addLine(b.dataset.type || 'supply'); }); });
    el.lineSearchInput && el.lineSearchInput.addEventListener('input', function(){ searchTerm = (this.value||'').trim().toLowerCase(); renderLineList(); });
    document.getElementById('jumpLineBtn') && document.getElementById('jumpLineBtn').addEventListener('click', function(){ var raw = document.getElementById('jumpLineInput') ? document.getElementById('jumpLineInput').value : ''; var target = parseInt(raw, 10); if (!isNaN(target) && target >= 1 && target <= poLines.length) loadLineEditor(target - 1); });
    document.getElementById('jumpLineInput') && document.getElementById('jumpLineInput').addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); var target = parseInt(this.value, 10); if (!isNaN(target) && target >= 1 && target <= poLines.length) loadLineEditor(target - 1); } });
    Array.from(document.querySelectorAll('.po-filter-btn')).forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('.po-filter-btn').forEach(function(bb){ bb.classList.remove('active'); }); b.classList.add('active'); currentFilter = b.dataset.filter || 'all'; renderLineList(); }); });

    el.editorPrev && el.editorPrev.addEventListener('click', function(){ saveCurrentLine(); if (activeIndex>0) loadLineEditor(activeIndex-1); });
    el.editorNext && el.editorNext.addEventListener('click', function(){ saveCurrentLine(); if (activeIndex < poLines.length-1) loadLineEditor(activeIndex+1); });
    el.editorDeleteLine && el.editorDeleteLine.addEventListener('click', function(){ if (activeIndex>=0) deleteLine(activeIndex); });

    ['editorCatalogSearch','editorAccountCode','editorClassification','editorDescription','editorQty','editorUom','editorUnitCost'].forEach(function(id){ var node = document.getElementById(id); if (!node) return; node.addEventListener('change', saveCurrentLine); node.addEventListener('input', function(){ if (id==='editorQty' || id==='editorUnitCost') updateEditorAmount(); saveCurrentLine(); }); });

    if (window.jQuery) { window.jQuery(document).on('select2:select select2:clear','#editorCatalogSearch, #editorAccountCode, #editorClassification, #editorUom', function() { updateEditorAmount(); saveCurrentLine(); }); }

    if (el.editorCatalogSearch) {
        el.editorCatalogSearch.addEventListener('change', function() {
            if (activeIndex < 0 || activeIndex >= poLines.length) return;
            if (!lineUsesCatalog(poLines[activeIndex])) return;
            var opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) {
                if (el.editorCatalogHint) el.editorCatalogHint.style.display = 'none';
                poLines[activeIndex].stock_catalog_id = '';
                saveCurrentLine();
                return;
            }
            var ci = {};
            try { ci = JSON.parse(opt.getAttribute('data-item') || '{}'); } catch (e) { ci = {}; }
            if (!ci.id) return;
            if (el.editorDescription) { el.editorDescription.value = ci.item_description || ci.item_name || ''; }
            poLines[activeIndex].stock_catalog_id = String(ci.id);
            poLines[activeIndex].item_type = 'supply';
            poLines[activeIndex].account_code_id = ci.account_code_id || '';
            poLines[activeIndex].classification_id = ci.classification_id || '';
            poLines[activeIndex].unit_of_measure_id = ci.unit_of_measure_id || '';
            rebuildAccountCodeSelect(poLines[activeIndex].item_type, ci.account_code_id || '');
            rebuildClassificationSelect(poLines[activeIndex].item_type, ci.classification_id || '');
            rebuildUomSelect(ci.unit_of_measure_id || '');
            updateEditorMode(poLines[activeIndex]);
            if (window.jQuery && jQuery.fn.select2) {
                window.jQuery(el.editorAccountCode).val(String(ci.account_code_id || '')).trigger('change.select2');
                window.jQuery(el.editorClassification).val(String(ci.classification_id || '')).trigger('change.select2');
                window.jQuery(el.editorUom).val(String(ci.unit_of_measure_id || '')).trigger('change.select2');
            } else {
                if (el.editorAccountCode) el.editorAccountCode.value = String(ci.account_code_id || '');
                if (el.editorClassification) el.editorClassification.value = String(ci.classification_id || '');
                if (el.editorUom) el.editorUom.value = String(ci.unit_of_measure_id || '');
            }
            if (el.editorTypeBadge) { el.editorTypeBadge.className = 'badge ' + typeBadgeClass(poLines[activeIndex].item_type); el.editorTypeBadge.textContent = typeLabel(poLines[activeIndex].item_type); }
            updateSemiTypeBadge(poLines[activeIndex]);
            if (el.editorCatalogHint) el.editorCatalogHint.style.display = '';
            saveCurrentLine();
        });
    }

    if (el.openClassificationQuickAdd) { el.openClassificationQuickAdd.addEventListener('click', function() { seedClassificationQuickAdd(); if (classificationQuickAddModal) classificationQuickAddModal.show(); }); }

    bindQuickAddModal({
        modalId: 'classificationQuickAddModal',
        saveBtnId: 'saveClassificationQuickAdd',
        errorId: 'classificationQuickAddError',
        endpoint: '<?php echo base_url('modules/purchase_orders/classification_quick_add.php'); ?>',
        buildPayload: function () {
            var itemType = el.quickClassificationType ? (el.quickClassificationType.getAttribute('data-item-type') || 'supply') : 'supply';
            var classificationName = el.quickClassificationName ? el.quickClassificationName.value.trim() : '';
            if (classificationName === '') return null;
            var payload = new FormData();
            payload.append('_csrf', <?php echo json_encode(csrf_token(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>);
            payload.append('item_type', itemType);
            payload.append('classification_name', classificationName);
            payload.append('account_code_id', el.quickClassificationAccountCode ? (el.quickClassificationAccountCode.value || '') : '');
            payload.append('useful_life_years', el.quickClassificationUsefulLife ? (el.quickClassificationUsefulLife.value || '') : '');
            payload.append('description', el.quickClassificationDescription ? el.quickClassificationDescription.value.trim() : '');
            return payload;
        },
        onSuccess: function (result) {
            upsertClassification(result.classification);
            if (activeIndex >= 0 && activeIndex < poLines.length) {
                poLines[activeIndex].classification_id = String(result.classification.id);
                rebuildClassificationSelect(poLines[activeIndex].item_type, result.classification.id);
                if (window.jQuery && jQuery.fn.select2) { window.jQuery(el.editorClassification).val(String(result.classification.id)).trigger('change'); }
                else if (el.editorClassification) { el.editorClassification.value = String(result.classification.id); }
            }
        }
    });

    function showAccountCodeQuickAddError(message) { if (!el.accountCodeQuickAddError) return; el.accountCodeQuickAddError.textContent = message || ''; el.accountCodeQuickAddError.style.display = message ? '' : 'none'; }
    function showUomQuickAddError(message) { if (!el.uomQuickAddError) return; el.uomQuickAddError.textContent = message || ''; el.uomQuickAddError.style.display = message ? '' : 'none'; }
    function seedAccountCodeQuickAdd() { var line = (activeIndex >= 0 && activeIndex < poLines.length) ? poLines[activeIndex] : null; var itemType = line ? (line.item_type || 'supply') : 'supply'; var accountGroup = itemType === 'equipment' ? 'asset' : itemType; if (el.quickAccountCodeItemType) { el.quickAccountCodeItemType.value = typeLabel(itemType); } if (el.quickAccountCodeGroup) { el.quickAccountCodeGroup.value = accountGroup; } if (el.quickAccountCode) el.quickAccountCode.value = ''; if (el.quickAccountName) el.quickAccountName.value = ''; showAccountCodeQuickAddError(''); }
    function seedUomQuickAdd() { if (el.quickUomName) el.quickUomName.value = ''; if (el.quickUomAbbreviation) el.quickUomAbbreviation.value = ''; showUomQuickAddError(''); }

    if (el.openAccountCodeQuickAdd) { el.openAccountCodeQuickAdd.addEventListener('click', function() { seedAccountCodeQuickAdd(); if (accountCodeQuickAddModal) accountCodeQuickAddModal.show(); }); }
    if (el.openUomQuickAdd) { el.openUomQuickAdd.addEventListener('click', function() { seedUomQuickAdd(); if (uomQuickAddModal) uomQuickAddModal.show(); }); }

    bindQuickAddModal({
        modalId: 'accountCodeQuickAddModal',
        saveBtnId: 'saveAccountCodeQuickAdd',
        errorId: 'accountCodeQuickAddError',
        endpoint: '<?php echo base_url('modules/purchase_orders/po_masterdata_quickadd.php'); ?>',
        buildPayload: function () {
            var accountCode = el.quickAccountCode ? el.quickAccountCode.value.trim() : '';
            var accountName = el.quickAccountName ? el.quickAccountName.value.trim() : '';
            if (accountCode === '' || accountName === '') return null;
            var payload = new FormData();
            payload.append('_csrf', <?php echo json_encode(csrf_token(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>);
            payload.append('action', 'add_account_code');
            payload.append('account_code', accountCode);
            payload.append('account_name', accountName);
            payload.append('account_group', el.quickAccountCodeGroup ? el.quickAccountCodeGroup.value : 'supply');
            return payload;
        },
        onSuccess: function (result) {
            upsertAccountCode(result.account_code);
            if (activeIndex >= 0 && activeIndex < poLines.length) {
                poLines[activeIndex].account_code_id = String(result.account_code.id);
                rebuildAccountCodeSelect(poLines[activeIndex].item_type, result.account_code.id);
                if (window.jQuery && jQuery.fn.select2) { window.jQuery(el.editorAccountCode).val(String(result.account_code.id)).trigger('change'); }
                else if (el.editorAccountCode) { el.editorAccountCode.value = String(result.account_code.id); }
            }
            if (el.quickClassificationType) { rebuildClassificationQuickAddAccountCodes(el.quickClassificationType.getAttribute('data-item-type') || 'supply', ''); }
        }
    });

    bindQuickAddModal({
        modalId: 'uomQuickAddModal',
        saveBtnId: 'saveUomQuickAdd',
        errorId: 'uomQuickAddError',
        endpoint: '<?php echo base_url('modules/purchase_orders/po_masterdata_quickadd.php'); ?>',
        buildPayload: function () {
            var uomName = el.quickUomName ? el.quickUomName.value.trim() : '';
            var abbreviation = el.quickUomAbbreviation ? el.quickUomAbbreviation.value.trim() : '';
            if (uomName === '' || abbreviation === '') return null;
            var payload = new FormData();
            payload.append('_csrf', <?php echo json_encode(csrf_token(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>);
            payload.append('action', 'add_uom');
            payload.append('uom_name', uomName);
            payload.append('abbreviation', abbreviation);
            return payload;
        },
        onSuccess: function (result) {
            upsertUom(result.uom);
            if (activeIndex >= 0 && activeIndex < poLines.length) {
                poLines[activeIndex].unit_of_measure_id = String(result.uom.id);
                rebuildUomSelect(result.uom.id);
                if (window.jQuery && jQuery.fn.select2) { window.jQuery(el.editorUom).val(String(result.uom.id)).trigger('change'); }
                else if (el.editorUom) { el.editorUom.value = String(result.uom.id); }
            }
        }
    });

    var form = document.getElementById('purchaseOrderForm');
    var formSummary = document.getElementById('purchaseOrderFormSummary');

    function showFormSummary(message) {
        if (!formSummary) {
            console.warn(message);
            return;
        }
        formSummary.textContent = message;
        formSummary.classList.remove('d-none');
        formSummary.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function clearFormSummary() {
        if (!formSummary) return;
        formSummary.textContent = '';
        formSummary.classList.add('d-none');
    }

    function resetFormValidationState() {
        if (!form) return;
        clearFormSummary();
        form.classList.remove('was-validated');
        form.removeAttribute('data-show-required-summary');
        Array.from(form.querySelectorAll('.is-invalid, .is-valid')).forEach(function (field) {
            field.classList.remove('is-invalid', 'is-valid');
        });
    }

    resetFormValidationState();
    window.addEventListener('pageshow', function () {
        resetFormValidationState();
    });

    if (form) {
        form.addEventListener('submit', function(e) {
            saveCurrentLine();
            buildHiddenInputs();
            clearFormSummary();

            if (poLines.length === 0) {
                e.preventDefault();
                showFormSummary('Please add at least one PO line before saving.');
                return;
            }

            var emptyLines = poLines.filter(function(ln) {
                return !ln.is_existing && (!ln.item_description || ln.item_description.trim() === '');
            });
            if (emptyLines.length > 0) {
                e.preventDefault();
                showFormSummary('Line ' + (emptyLines[0].index + 1) + ' has no description. Please fill in all lines before saving.');
                loadLineEditor(emptyLines[0].index);
                return;
            }

            var missingCatalogLine = poLines.find(function(ln) {
                return !ln.is_existing && lineUsesCatalog(ln) && (!ln.stock_catalog_id || String(ln.stock_catalog_id).trim() === '');
            });
            if (missingCatalogLine) {
                e.preventDefault();
                showFormSummary('Supply line ' + (missingCatalogLine.index + 1) + ' must be selected from the stock catalog before saving.');
                loadLineEditor(missingCatalogLine.index);
                return;
            }

            var documentTotalInput = document.getElementById('document_total_amount');
            var partialEntryInput = document.getElementById('is_partial_entry');
            var isPartialEntry = partialEntryInput ? partialEntryInput.checked : false;
            var documentTotalRaw = documentTotalInput ? String(documentTotalInput.value || '').trim() : '';
            if (documentTotalRaw !== '') {
                var documentTotal = parseFloat(documentTotalRaw);
                var lineTotal = poLines.reduce(function(acc, ln) { return acc + (parseFloat(ln.line_total || 0) || 0); }, 0);
                if (isNaN(documentTotal)) {
                    e.preventDefault();
                    showFormSummary('PO hard copy total must be a valid amount.');
                    documentTotalInput && documentTotalInput.focus();
                    return;
                }
                if (!isPartialEntry && Math.abs(lineTotal - documentTotal) > 0.009) {
                    e.preventDefault();
                    showFormSummary('Encoded line total (' + formatNumber(lineTotal) + ') does not match the hard copy PO total (' + formatNumber(documentTotal) + ').');
                    documentTotalInput && documentTotalInput.focus();
                    return;
                }
            }
        });
    }
    var documentTotalInput = document.getElementById('document_total_amount');
    if (documentTotalInput) { documentTotalInput.addEventListener('input', updateGrandTotal); }
    var partialEntryInput = document.getElementById('is_partial_entry');
    if (partialEntryInput) { partialEntryInput.addEventListener('change', updateGrandTotal); }
    if (typeof poLinesFromPhp !== 'undefined' && poLinesFromPhp.length > 0) {
        poLines = poLinesFromPhp.slice().map(function(line) {
            var normalized = Object.assign({}, line);
            var hasLineTotal = normalized.line_total !== undefined && normalized.line_total !== null && String(normalized.line_total).trim() !== '';
            if (!hasLineTotal || isNaN(parseFloat(normalized.line_total))) {
                normalized.line_total = Math.round((parseFloat(normalized.quantity || 0) * parseFloat(normalized.unit_cost || 0)) * 100) / 100;
            }
            return normalized;
        });
        renderLineList();
        loadLineEditor(0);
    } else if (poLines.length === 0) { addLine('supply'); }

    if (window.SPAMS && window.SPAMS.initSelect2) { window.SPAMS.initSelect2(document.getElementById('poLineEditor')); }
    if (poLines.length > 0 && activeIndex >= 0 && activeIndex < poLines.length) { updateEditorMode(poLines[activeIndex]); setTimeout(function() { if (poLines.length > 0 && activeIndex >= 0 && activeIndex < poLines.length) { updateEditorMode(poLines[activeIndex]); } }, 50); }

    var poDateInput = document.getElementById('po_date');
    var deliveryTermInput = document.getElementById('delivery_term_days');
    var expectedDeliveryInput = document.getElementById('expected_delivery_date');
    function computeExpectedDate() { if (!expectedDeliveryInput) return; var pdVal = poDateInput && poDateInput.value ? poDateInput.value : ''; var days = parseInt(deliveryTermInput && deliveryTermInput.value, 10); days = isNaN(days) ? 0 : days; if (!pdVal) { expectedDeliveryInput.value = ''; return; } var parts = pdVal.split('-'); if (parts.length !== 3) { expectedDeliveryInput.value = ''; return; } var d = new Date(parts[0], parseInt(parts[1],10) - 1, parts[2]); d.setDate(d.getDate() + days); var yyyy = d.getFullYear(); var mm = String(d.getMonth() + 1).padStart(2, '0'); var dd = String(d.getDate()).padStart(2, '0'); expectedDeliveryInput.value = yyyy + '-' + mm + '-' + dd; }
    if (poDateInput) poDateInput.addEventListener('change', computeExpectedDate); if (deliveryTermInput) deliveryTermInput.addEventListener('input', computeExpectedDate); computeExpectedDate();
    updateGrandTotal();

    var supplierSelect = document.getElementById('supplier_id'); var supplierAddressInput = document.getElementById('supplier_address'); function syncSupplierAddress() { if (!supplierSelect || !supplierAddressInput) return; var selectedValue = supplierSelect.value; var addr = ''; Array.from(supplierSelect.options).forEach(function(opt) { if (opt.value === selectedValue) { addr = (opt.getAttribute('data-address') || '').trim(); } }); supplierAddressInput.value = addr; supplierAddressInput.placeholder = addr ? '' : 'No address on file — type manually'; }
    if (supplierSelect && supplierAddressInput) { supplierSelect.addEventListener('change', syncSupplierAddress); setTimeout(function(){ if (window.jQuery && jQuery.fn.select2) { window.jQuery(supplierSelect).off('select2:select select2:clear').on('select2:select select2:clear', syncSupplierAddress); } syncSupplierAddress(); }, 400); }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>









