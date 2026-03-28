<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer');

function receiving_status_badge(string $status): string
{
    $map = [
        'completed' => ['text-bg-success',   'Completed'],
        'partial'   => ['text-bg-warning',   'Partial'],
        'cancelled' => ['text-bg-danger',    'Cancelled'],
    ];
    [$class, $label] = $map[$status] ?? ['text-bg-secondary', ucfirst($status)];
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

function receiving_type_label(string $type): string
{
    return $type === 'equipment' ? 'Equipment' : ($type === 'semi_expendable' ? 'Semi-Expendable' : 'Supplies');
}

function receiving_tracks_identity(string $type): bool
{
    return $type === 'equipment' || $type === 'semi_expendable';
}

function receiving_blank_detail(): array
{
    return ['brand_id' => '', 'model_id' => '', 'brand' => '', 'model' => '', 'serial_no' => '', 'remarks' => ''];
}

function receiving_normalize_details($rows): array
{
    $details = [];
    if (!is_array($rows)) {
        return $details;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $details[] = [
            'brand_id' => trim((string) ($row['brand_id'] ?? '')),
            'model_id' => trim((string) ($row['model_id'] ?? '')),
            'brand' => trim((string) ($row['brand'] ?? '')),
            'model' => trim((string) ($row['model'] ?? '')),
            'serial_no' => trim((string) ($row['serial_no'] ?? '')),
            'remarks' => trim((string) ($row['remarks'] ?? '')),
        ];
    }

    return $details;
}

function preview_ris_number(mysqli $db, string $referenceDate): string
{
    $dateValue = strtotime($referenceDate);
    if ($dateValue === false) {
        $dateValue = time();
    }

    $year = date('Y', $dateValue);
    $month = date('m', $dateValue);
    $prefix = 'RIS-' . $year . '-' . $month . '-';
    $stmt = $db->prepare("SELECT ris_no FROM receivings WHERE ris_no LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        return $prefix . '0001';
    }

    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $nextNumber = 1;
    if ($row && !empty($row['ris_no'])) {
        $parts = explode('-', (string) $row['ris_no']);
        $lastPart = end($parts);
        if (ctype_digit((string) $lastPart)) {
            $nextNumber = ((int) $lastPart) + 1;
        }
    }

    return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
}

$db = db_connect();
$page_title = 'Receiving';
$flash = get_flash();
$errors = [];
$receivings = [];
$purchaseOrders = [];
$receivingItems = [];
$brands = [];
$models = [];
$selectedPurchaseOrder = null;
$selectedPurchaseOrderId = (int) ($_GET['po_id'] ?? ($_POST['purchase_order_id'] ?? 0));
$form = [
    'system_reference' => '',
    'purchase_order_id' => $selectedPurchaseOrderId > 0 ? (string) $selectedPurchaseOrderId : '',
    'ris_no' => '',
    'received_date' => date('Y-m-d'),
    'delivery_receipt_no' => '',
    'invoice_no' => '',
    'remarks' => '',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $poItemHasSemiType = function_exists('schema_has_column')
        ? schema_has_column($db, 'purchase_order_items', 'semi_expendable_type')
        : false;
    $form['system_reference'] = preview_module_code($db, 'receivings');
    $form['ris_no'] = preview_ris_number($db, $form['received_date']);

                $poList = $db->prepare(" 
                        SELECT po.id, po.po_number, po.po_date, po.status,
                                     s.supplier_name, f.fund_code, f.fund_name,
                                     mop.mode_name,
                                     po.place_of_delivery, po.supplier_address,
                                     COUNT(DISTINCT poi.id) AS total_lines,
                                     COALESCE(SUM(poi.quantity), 0) AS total_qty,
                                     COALESCE((
                                         SELECT SUM(ri2.quantity_accepted)
                                         FROM receiving_items ri2
                                         INNER JOIN receivings r2 ON r2.id = ri2.receiving_id
                                             AND r2.status != 'cancelled'
                                         INNER JOIN purchase_order_items poi2
                                             ON poi2.id = ri2.purchase_order_item_id
                                         WHERE poi2.purchase_order_id = po.id
                                     ), 0) AS total_received_qty
                        FROM purchase_orders po
                        INNER JOIN suppliers s ON s.id = po.supplier_id
                        INNER JOIN funds f ON f.id = po.fund_id
                        LEFT JOIN mode_of_procurements mop ON mop.id = po.mode_of_procurement_id
                        LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
                        WHERE po.status IN ('encoded', 'partial')
                        GROUP BY po.id, po.po_number, po.po_date, po.status,
                                         s.supplier_name, f.fund_code, f.fund_name,
                                         mop.mode_name, po.place_of_delivery, po.supplier_address
                        ORDER BY
                            CASE po.status WHEN 'partial' THEN 0 ELSE 1 END ASC,
                            po.po_date DESC, po.id DESC
                ");
                if ($poList) {
                    $poList->execute();
                    $poResult = $poList->get_result();
                    $purchaseOrders = $poResult ? $poResult->fetch_all(MYSQLI_ASSOC) : [];
                    if ($poResult) $poResult->free();
                    $poList->close();

                    // Compute percent received per PO
                    foreach ($purchaseOrders as &$po) {
                        $po['pct'] = $po['total_qty'] > 0 ? min(100, (int) round(($po['total_received_qty'] / $po['total_qty']) * 100)) : 0;
                    }
                    unset($po);
                }
    $brandResult = $db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC");
    if ($brandResult) {
        $brands = $brandResult->fetch_all(MYSQLI_ASSOC);
    }

    $modelResult = $db->query("SELECT id, brand_id, model_name FROM models WHERE is_active = 1 ORDER BY model_name ASC");
    if ($modelResult) {
        $models = $modelResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($selectedPurchaseOrderId > 0) {
        $poStmt = $db->prepare("
            SELECT po.id, po.po_number, po.po_date, po.place_of_delivery, po.supplier_address,
                   s.supplier_name, f.fund_code, mop.mode_name AS mode_name
            FROM purchase_orders po
            INNER JOIN suppliers s ON s.id = po.supplier_id
            INNER JOIN funds f ON f.id = po.fund_id
            LEFT JOIN mode_of_procurements mop ON mop.id = po.mode_of_procurement_id
            WHERE po.id = ?
            LIMIT 1
        ");
        if ($poStmt) {
            $poStmt->bind_param('i', $selectedPurchaseOrderId);
            $poStmt->execute();
            $selectedPurchaseOrder = $poStmt->get_result()->fetch_assoc() ?: null;
            $poStmt->close();
        }

        if ($selectedPurchaseOrder) {
            $itemStmt = $db->prepare("
                SELECT poi.id, poi.line_no, poi.item_type, " . ($poItemHasSemiType ? "poi.semi_expendable_type" : "NULL AS semi_expendable_type") . ", poi.item_description, poi.quantity, poi.unit_cost,
                       poi.account_code_id, poi.classification_id, poi.unit_of_measure_id,
                       ac.account_code, ac.account_name, c.classification_name, u.uom_name, u.abbreviation,
                       sc.stock_no AS catalog_stock_no, sc.item_name AS catalog_item_name,
                       COALESCE(SUM(CASE WHEN r.status != 'cancelled' THEN ri.quantity_accepted ELSE 0 END), 0) AS quantity_already_received
                FROM purchase_order_items poi
                LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
                LEFT JOIN classifications c ON c.id = poi.classification_id
                LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
                LEFT JOIN stock_catalog sc ON sc.id = poi.stock_catalog_id
                LEFT JOIN receiving_items ri ON ri.purchase_order_item_id = poi.id
                LEFT JOIN receivings r ON r.id = ri.receiving_id
                WHERE poi.purchase_order_id = ?
                GROUP BY poi.id, poi.line_no, poi.item_type, " . ($poItemHasSemiType ? "poi.semi_expendable_type" : "semi_expendable_type") . ", poi.item_description, poi.quantity, poi.unit_cost,
                         poi.account_code_id, poi.classification_id, poi.unit_of_measure_id,
                         ac.account_code, ac.account_name, c.classification_name, u.uom_name, u.abbreviation,
                         sc.stock_no, sc.item_name
                ORDER BY poi.line_no ASC, poi.id ASC
            ");
            if ($itemStmt) {
                $itemStmt->bind_param('i', $selectedPurchaseOrderId);
                $itemStmt->execute();
                $itemResult = $itemStmt->get_result();
                while ($itemResult && ($item = $itemResult->fetch_assoc())) {
                    $remaining = max(0, (float) $item['quantity'] - (float) $item['quantity_already_received']);
                    $item['remaining_quantity'] = $remaining;
                    $item['deliver_quantity'] = $remaining > 0 ? number_format($remaining, 2, '.', '') : '0.00';
                    $item['accept_quantity'] = $item['deliver_quantity'];
                    $item['reject_quantity'] = '0.00';
                    $item['item_condition'] = 'Good Condition';
                    $item['remarks'] = '';
                    $item['detail_rows'] = receiving_tracks_identity((string) $item['item_type']) ? [receiving_blank_detail()] : [];
                    $receivingItems[] = $item;
                }
                $itemStmt->close();
            }
        } else {
            $errors[] = 'Selected purchase order was not found.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save') === 'save') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }
        $form['purchase_order_id'] = old($_POST, 'purchase_order_id');
        $form['received_date'] = old($_POST, 'received_date', date('Y-m-d'));
        $form['ris_no'] = preview_ris_number($db, $form['received_date']);
        $form['delivery_receipt_no'] = old($_POST, 'delivery_receipt_no');
        $form['invoice_no'] = old($_POST, 'invoice_no');
        $form['remarks'] = old($_POST, 'remarks');
        $postedItems = $_POST['items'] ?? [];
        $validatedItems = [];
        $remainingAfterSave = [];
        $totalReceivedAmount = 0.00;

        if ($form['purchase_order_id'] === '') {
            $errors[] = 'Purchase order is required.';
        }
        if ($form['received_date'] === '') {
            $errors[] = 'Received date is required.';
        }

        foreach ($receivingItems as &$item) {
            $itemId = (int) $item['id'];
            $posted = isset($postedItems[$itemId]) && is_array($postedItems[$itemId]) ? $postedItems[$itemId] : [];
            $item['deliver_quantity'] = old($posted, 'deliver_quantity', $item['deliver_quantity']);
            $item['accept_quantity'] = old($posted, 'accept_quantity', $item['accept_quantity']);
            $item['reject_quantity'] = old($posted, 'reject_quantity', $item['reject_quantity']);
            $item['item_condition'] = old($posted, 'item_condition', $item['item_condition']);
            $item['remarks'] = old($posted, 'remarks');
            $item['detail_rows'] = receiving_tracks_identity((string) $item['item_type'])
                ? receiving_normalize_details($posted['details'] ?? $item['detail_rows'])
                : [];
            if (receiving_tracks_identity((string) $item['item_type']) && empty($item['detail_rows'])) {
                $item['detail_rows'] = [receiving_blank_detail()];
            }

            $delivered = (float) ($posted['deliver_quantity'] ?? 0);
            $accepted = (float) ($posted['accept_quantity'] ?? 0);
            $rejected = (float) ($posted['reject_quantity'] ?? 0);
            // If user provided delivered quantity but left accept/reject blank, assume full acceptance.
            if ($delivered > 0 && round($accepted, 6) === 0.0 && round($rejected, 6) === 0.0) {
                $accepted = $delivered;
            }
            $remaining = (float) $item['remaining_quantity'];
            $condition = trim((string) ($posted['item_condition'] ?? ''));
            $details = receiving_normalize_details($posted['details'] ?? []);

            if ($delivered <= 0 && $accepted <= 0 && $rejected <= 0) {
                $remainingAfterSave[$itemId] = $remaining;
                continue;
            }
            if ($delivered <= 0) {
                $errors[] = 'Delivered quantity is required for line ' . $item['line_no'] . '.';
                continue;
            }
            if ($delivered > $remaining) {
                $errors[] = 'Delivered quantity cannot exceed remaining quantity for line ' . $item['line_no'] . '.';
                continue;
            }
            if ($accepted < 0 || $rejected < 0) {
                $errors[] = 'Accepted and rejected quantities cannot be negative for line ' . $item['line_no'] . '.';
                continue;
            }
            if (round($accepted + $rejected, 2) !== round($delivered, 2)) {
                $errors[] = 'Accepted plus rejected quantity must equal delivered quantity for line ' . $item['line_no'] . '.';
                continue;
            }
            if ($condition === '') {
                $errors[] = 'Condition is required for line ' . $item['line_no'] . '.';
                continue;
            }

            $detailRows = [];
            foreach ($details as $detail) {
                $brandId = (int) ($detail['brand_id'] !== '' ? $detail['brand_id'] : 0);
                $modelId = (int) ($detail['model_id'] !== '' ? $detail['model_id'] : 0);
                if ($brandId > 0) {
                    foreach ($brands as $brandRecord) {
                        if ((int) $brandRecord['id'] === $brandId) {
                            $detail['brand'] = $brandRecord['brand_name'];
                            break;
                        }
                    }
                }
                if ($modelId > 0) {
                    foreach ($models as $modelRecord) {
                        if ((int) $modelRecord['id'] === $modelId) {
                            if ($brandId > 0 && (int) $modelRecord['brand_id'] !== $brandId) {
                                $errors[] = 'Selected model does not belong to the selected brand for line ' . $item['line_no'] . '.';
                                continue 2;
                            }
                            $detail['model'] = $modelRecord['model_name'];
                            break;
                        }
                    }
                }

                if ($detail['brand_id'] !== '' || $detail['model_id'] !== '' || $detail['brand'] !== '' || $detail['model'] !== '' || $detail['serial_no'] !== '' || $detail['remarks'] !== '') {
                    $detailRows[] = $detail;
                }
            }
            if (receiving_tracks_identity((string) $item['item_type']) && $accepted > 0 && !$detailRows) {
                $errors[] = 'Add brand, model, or serial details for line ' . $item['line_no'] . '.';
                continue;
            }

            $lineTotal = round($accepted * (float) $item['unit_cost'], 2);
            $totalReceivedAmount += $lineTotal;
            $remainingAfterSave[$itemId] = max(0, $remaining - $accepted);
            $validatedItems[] = [
                'purchase_order_item_id' => $itemId,
                'item_type' => (string) $item['item_type'],
                'account_code_id' => (int) ($item['account_code_id'] ?? 0),
                'classification_id' => (int) ($item['classification_id'] ?? 0),
                'unit_of_measure_id' => (int) ($item['unit_of_measure_id'] ?? 0),
                'item_description' => (string) $item['item_description'],
                'quantity_delivered' => $delivered,
                'quantity_accepted' => $accepted,
                'quantity_rejected' => $rejected,
                'item_condition' => $condition,
                'unit_cost' => (float) $item['unit_cost'],
                'line_total' => $lineTotal,
                'remarks' => trim((string) ($posted['remarks'] ?? '')),
                'details' => $detailRows,
            ];
        }
        unset($item);

        if (!$validatedItems) {
            $errors[] = 'Enter at least one delivered item.';
        }

        if (!$errors) {
            foreach ($receivingItems as $item) {
                $itemId = (int) $item['id'];
                if (!isset($remainingAfterSave[$itemId])) {
                    $remainingAfterSave[$itemId] = (float) $item['remaining_quantity'];
                }
            }
            $status = 'completed';
            foreach ($remainingAfterSave as $remaining) {
                if ($remaining > 0.0001) {
                    $status = 'partial';
                    break;
                }
            }

            $db->begin_transaction();
            try {
                $systemReference = next_module_code($db, 'receivings');
                $userId = current_user_id();
                $headerStmt = $db->prepare("INSERT INTO receivings (system_reference, purchase_order_id, ris_no, received_date, delivery_receipt_no, invoice_no, status, remarks, total_received_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$headerStmt) {
                    throw new RuntimeException('Unable to prepare receiving header insert.');
                }
                $purchaseOrderId = (int) $form['purchase_order_id'];
                $headerStmt->bind_param('sissssssdi', $systemReference, $purchaseOrderId, $form['ris_no'], $form['received_date'], $form['delivery_receipt_no'], $form['invoice_no'], $status, $form['remarks'], $totalReceivedAmount, $userId);
                $headerStmt->execute();
                $receivingId = (int) $headerStmt->insert_id;
                $headerStmt->close();

                $itemStmt = $db->prepare("INSERT INTO receiving_items (receiving_id, purchase_order_item_id, quantity_delivered, quantity_accepted, quantity_rejected, item_condition, unit_cost, line_total, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $detailStmt = $db->prepare("INSERT INTO receiving_item_details (receiving_item_id, brand_id, model_id, brand, model, serial_no, remarks) VALUES (?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?)");
                $stockStmt = $db->prepare("INSERT INTO stock_items (system_reference, stock_catalog_id, receiving_id, receiving_item_id, purchase_order_item_id, item_type, semi_expendable_type, account_code_id, classification_id, unit_of_measure_id, item_description, unit_cost, quantity_received, quantity_issued, quantity_on_hand, created_by) VALUES (?, NULLIF(?,0), ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, 0.00, ?, ?)");
                $movementStmt = $db->prepare("INSERT INTO stock_movements (stock_item_id, movement_type, movement_date, reference_type, reference_id, quantity_in, quantity_out, balance_after, remarks, created_by) VALUES (?, 'receipt', ?, 'receiving', ?, ?, 0.00, ?, ?, ?)");
                if (!$itemStmt || !$detailStmt || !$stockStmt || !$movementStmt) {
                    throw new RuntimeException('Unable to prepare receiving detail insert.');
                }

                // Load active thresholds once per receiving
                $threshold = get_active_threshold($db);

                foreach ($validatedItems as $item) {
                    $itemStmt->bind_param('iidddsdds', $receivingId, $item['purchase_order_item_id'], $item['quantity_delivered'], $item['quantity_accepted'], $item['quantity_rejected'], $item['item_condition'], $item['unit_cost'], $item['line_total'], $item['remarks']);
                    $itemStmt->execute();
                    $receivingItemId = (int) $itemStmt->insert_id;
                    $catalogId = 0;
                    if (!empty($item['purchase_order_item_id'])) {
                        $catStmt = $db->prepare("SELECT stock_catalog_id FROM purchase_order_items WHERE id = ? LIMIT 1");
                        if ($catStmt) {
                            $purchaseOrderItemId = (int) $item['purchase_order_item_id'];
                            $catStmt->bind_param('i', $purchaseOrderItemId);
                            $catStmt->execute();
                            $catRow = $catStmt->get_result()->fetch_assoc();
                            $catStmt->close();
                            $catalogId = (int) ($catRow['stock_catalog_id'] ?? 0);
                        }
                    }

                    // Insert receiving_item_details and create stock items.
                    $isTracked = in_array($item['item_type'], ['equipment', 'semi_expendable'], true);
                    $detailCount = count($item['details']);

                    if ($isTracked && $detailCount > 0) {
                        // For tracked items, create one receiving_item_details row and one stock_item per detail (unit)
                        foreach ($item['details'] as $detail) {
                            $brandId = (string) $detail['brand_id'];
                            $modelId = (string) $detail['model_id'];
                            $detailStmt->bind_param('issssss', $receivingItemId, $brandId, $modelId, $detail['brand'], $detail['model'], $detail['serial_no'], $detail['remarks']);
                                $detailStmt->execute();
                                $detailId = (int) $detailStmt->insert_id;

                                // Create one stock_item per unit/detail
                                $stockReference = next_module_code($db, 'stock_items');
                            $accountCodeId = (int) $item['account_code_id'];
                            $classificationId = (int) $item['classification_id'];
                            $unitOfMeasureId = (int) $item['unit_of_measure_id'];
                            $semiType = '';
                            if ((string) ($item['item_type'] ?? '') === 'semi_expendable') {
                                $semiType = in_array((string) ($item['semi_expendable_type'] ?? ''), ['high_value', 'low_value'], true)
                                    ? (string) $item['semi_expendable_type']
                                    : (((float) ($item['unit_cost'] ?? 0) >= (float) $threshold['semi_hv_min']) ? 'high_value' : 'low_value');
                            }

                            $stockQty = 1.0;
                            $stockStmt->bind_param(
                                'siiiissiiisdddi',
                                $stockReference,
                                $catalogId,
                                $receivingId,
                                $receivingItemId,
                                $item['purchase_order_item_id'],
                                $item['item_type'],
                                $semiType,
                                $accountCodeId,
                                $classificationId,
                                $unitOfMeasureId,
                                $item['item_description'],
                                $item['unit_cost'],
                                $stockQty,
                                $stockQty,
                                $userId
                            );
                            $stockStmt->execute();
                            $stockItemId = (int) $stockStmt->insert_id;

                            // Link stock_item back to receiving_item_details
                            $linkStmt = $db->prepare("UPDATE receiving_item_details SET stock_item_id = ? WHERE id = ?");
                            if ($linkStmt) {
                                $linkStmt->bind_param('ii', $stockItemId, $detailId);
                                $linkStmt->execute();
                                $linkStmt->close();
                            }

                            $movementRemarks = 'Received from ' . $systemReference;
                            $movementStmt->bind_param(
                                'isiddsi',
                                $stockItemId,
                                $form['received_date'],
                                $receivingId,
                                $stockQty,
                                $stockQty,
                                $movementRemarks,
                                $userId
                            );
                            $movementStmt->execute();
                        }
                    } else {
                        // Non-tracked items (supplies) or no detail rows: create aggregated stock entry if accepted > 0
                        if (in_array($item['item_type'], ['supply', 'semi_expendable', 'equipment'], true) && $item['quantity_accepted'] > 0) {
                            $stockReference = next_module_code($db, 'stock_items');
                            $accountCodeId = (int) $item['account_code_id'];
                            $classificationId = (int) $item['classification_id'];
                            $unitOfMeasureId = (int) $item['unit_of_measure_id'];
                            $semiType = '';
                            if ((string) ($item['item_type'] ?? '') === 'semi_expendable') {
                                $semiType = ((float) ($item['unit_cost'] ?? 0) >= (float) $threshold['semi_hv_min']) ? 'high_value' : 'low_value';
                            }
                            $stockStmt->bind_param(
                                'siiiissiiisdddi',
                                $stockReference,
                                $catalogId,
                                $receivingId,
                                $receivingItemId,
                                $item['purchase_order_item_id'],
                                $item['item_type'],
                                $semiType,
                                $accountCodeId,
                                $classificationId,
                                $unitOfMeasureId,
                                $item['item_description'],
                                $item['unit_cost'],
                                $item['quantity_accepted'],
                                $item['quantity_accepted'],
                                $userId
                            );
                            $stockStmt->execute();
                            $stockItemId = (int) $stockStmt->insert_id;

                            $movementRemarks = 'Received from ' . $systemReference;
                            $movementStmt->bind_param(
                                'isiddsi',
                                $stockItemId,
                                $form['received_date'],
                                $receivingId,
                                $item['quantity_accepted'],
                                $item['quantity_accepted'],
                                $movementRemarks,
                                $userId
                            );
                            $movementStmt->execute();
                        }
                    }
                }

                $movementStmt->close();
                $stockStmt->close();
                $detailStmt->close();
                $itemStmt->close();

                // After inserting receiving items, recalculate PO status across all receivings (excluding cancelled)
                $poStatusStmt = $db->prepare(
                    "SELECT\n" .
                    "    SUM(poi.quantity) AS total_ordered,\n" .
                    "    COALESCE((SELECT SUM(ri2.quantity_delivered)\n" .
                    "              FROM receiving_items ri2\n" .
                    "              INNER JOIN receivings r2 ON r2.id = ri2.receiving_id\n" .
                    "              WHERE r2.purchase_order_id = po.id AND r2.status != 'cancelled'), 0) AS total_delivered\n" .
                    "FROM purchase_order_items poi\n" .
                    "INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id\n" .
                    "WHERE poi.purchase_order_id = ?"
                );
                if ($poStatusStmt) {
                    $poStatusStmt->bind_param('i', $purchaseOrderId);
                    $poStatusStmt->execute();
                    $poRow = $poStatusStmt->get_result()->fetch_assoc();
                    $poStatusStmt->close();

                    $totalOrdered = (float) ($poRow['total_ordered'] ?? 0);
                    $totalDelivered = (float) ($poRow['total_delivered'] ?? 0);

                    if ($totalOrdered > 0 && $totalDelivered >= $totalOrdered) {
                        $poStatus = 'completed';
                    } elseif ($totalDelivered > 0) {
                        $poStatus = 'partial';
                    } else {
                        $poStatus = 'encoded';
                    }

                    $poUpdateStmt = $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
                    if ($poUpdateStmt) {
                        $poUpdateStmt->bind_param('si', $poStatus, $purchaseOrderId);
                        $poUpdateStmt->execute();
                        $poUpdateStmt->close();
                    }
                }

                // Set receiving status: 'completed' if this receiving left no remaining quantities, otherwise 'partial'
                $allFull = true;
                foreach ($remainingAfterSave as $rem) {
                    if ((float) $rem > 0.0001) {
                        $allFull = false;
                        break;
                    }
                }
                $receivingStatus = $allFull ? 'completed' : 'partial';
                $updRecvStmt = $db->prepare("UPDATE receivings SET status = ? WHERE id = ?");
                if ($updRecvStmt) {
                    $updRecvStmt->bind_param('si', $receivingStatus, $receivingId);
                    $updRecvStmt->execute();
                    $updRecvStmt->close();
                }

                $db->commit();
                set_flash('success', 'Receiving record saved successfully.');
                redirect('modules/receivings/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = $e->getMessage();
                $errors[] = 'File: ' . $e->getFile() . ' Line: ' . $e->getLine();
            }
        }
    }

    // Receiving records filters
    $filterPoNumber = trim($_GET['q'] ?? '');
    $filterStatus = $_GET['filter_status'] ?? '';

    $recWhere = [];
    $recParams = [];
    $recTypes = '';
    if ($filterStatus !== '') {
        $recWhere[] = 'r.status = ?';
        $recParams[] = $filterStatus;
        $recTypes .= 's';
    }
    if ($filterPoNumber !== '') {
        $recWhere[] = '(po.po_number LIKE ? OR s.supplier_name LIKE ? OR r.system_reference LIKE ?)';
        $like = '%' . $filterPoNumber . '%';
        $recParams = array_merge($recParams, [$like, $like, $like]);
        $recTypes .= 'sss';
    }
    $recWhereSql = $recWhere ? 'WHERE ' . implode(' AND ', $recWhere) : '';

    $recSql = "
        SELECT r.id, r.system_reference, r.ris_no, r.received_date, r.delivery_receipt_no, r.status, r.total_received_amount,
               po.po_number, s.supplier_name
        FROM receivings r
        INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
        INNER JOIN suppliers s ON s.id = po.supplier_id
        $recWhereSql
        ORDER BY r.received_date DESC, r.id DESC
    ";

    if ($recParams) {
        $recStmt = $db->prepare($recSql);
        if ($recStmt) {
            $recStmt->bind_param($recTypes, ...$recParams);
            $recStmt->execute();
            $receivingResult2 = $recStmt->get_result();
            if ($receivingResult2) $receivings = $receivingResult2->fetch_all(MYSQLI_ASSOC);
            $recStmt->close();
        }
    } else {
        $receivingResult = $db->query($recSql);
        if ($receivingResult) {
            $receivings = $receivingResult->fetch_all(MYSQLI_ASSOC);
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<style>
.receiving-stepper {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.receiving-step {
    background: var(--bs-secondary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 0.9rem;
    padding: 0.85rem 1rem;
}

.receiving-step.active {
    background: rgba(13, 110, 253, 0.08);
    border-color: rgba(13, 110, 253, 0.35);
}

.receiving-step.done {
    background: rgba(25, 135, 84, 0.08);
    border-color: rgba(25, 135, 84, 0.28);
}

.receiving-step-number {
    align-items: center;
    background: #fff;
    border: 1px solid var(--bs-border-color);
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 700;
    height: 1.65rem;
    justify-content: center;
    margin-bottom: 0.45rem;
    width: 1.65rem;
}

.receiving-step.active .receiving-step-number {
    background: var(--bs-primary);
    border-color: var(--bs-primary);
    color: #fff;
}

.receiving-step.done .receiving-step-number {
    background: var(--bs-success);
    border-color: var(--bs-success);
    color: #fff;
}

.receiving-step-title {
    font-size: 0.92rem;
    font-weight: 700;
}

.receiving-step-copy {
    color: var(--bs-secondary-color);
    font-size: 0.78rem;
    line-height: 1.35;
}

.receiving-step-panel {
    display: none;
}

.receiving-step-panel.active {
    display: block;
}

.receiving-workspace {
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(280px, 340px) minmax(0, 1fr);
}

.receiving-line-list {
    background: var(--bs-secondary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 1rem;
    padding: 0.9rem;
}

.receiving-line-scroll {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    max-height: 560px;
    overflow-y: auto;
}

.receiving-line-card {
    background: #fff;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.85rem;
    cursor: pointer;
    padding: 0.75rem 0.85rem;
}

.receiving-line-card.active {
    border-color: rgba(13, 110, 253, 0.45);
    box-shadow: 0 0 0 0.18rem rgba(13, 110, 253, 0.12);
}

.receiving-line-card.done {
    background: rgba(25, 135, 84, 0.05);
}

.receiving-line-card.needs-details {
    border-color: rgba(220, 53, 69, 0.28);
}

.receiving-line-title {
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1.35;
}

.receiving-line-meta {
    color: var(--bs-secondary-color);
    font-size: 0.76rem;
}

.receiving-line-card {
    background: #fff;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.85rem;
    cursor: pointer;
    padding: 0.8rem 0.85rem;
    transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
}

.receiving-line-card:hover {
    border-color: rgba(13, 110, 253, 0.3);
    transform: translateY(-1px);
}

.receiving-line-card.active {
    border-color: rgba(13, 110, 253, 0.5);
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.12);
}

.receiving-line-card.done {
    background: rgba(25, 135, 84, 0.05);
}

.receiving-line-card.needs-details {
    border-color: rgba(220, 53, 69, 0.3);
}

.receiving-line-meta {
    color: var(--bs-secondary-color);
    font-size: 0.75rem;
}

.receiving-line-title {
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1.35;
}

.receiving-editor-card {
    border: 1px solid var(--bs-border-color);
    border-radius: 1rem;
    padding: 1rem;
}

.receiving-summary-strip {
    background: var(--bs-secondary-bg);
    border-radius: 0.85rem;
    padding: 0.85rem 1rem;
}

.receiving-detail-box {
    background: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 0.9rem;
    padding: 0.9rem;
}

.receiving-review-grid {
    display: grid;
    gap: 0.85rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.receiving-review-card {
    background: var(--bs-secondary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 0.9rem;
    padding: 0.9rem 1rem;
}

.receiving-review-label {
    color: var(--bs-secondary-color);
    font-size: 0.74rem;
    margin-bottom: 0.2rem;
    text-transform: uppercase;
}

.receiving-review-value {
    font-size: 1rem;
    font-weight: 700;
}

@media (max-width: 991.98px) {
    .receiving-stepper,
    .receiving-review-grid,
    .receiving-workspace {
        grid-template-columns: 1fr;
    }
}
</style>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="card-title mb-3">Encode Receiving</h5>
                <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>

                                <?php if (!$selectedPurchaseOrderId): ?>
                                <!-- PO SELECTOR — shown when no PO is selected yet -->
                                <?php
                                $partialCount = 0;
                                $newCount = 0;
                                if (is_array($purchaseOrders)) {
                                    foreach ($purchaseOrders as $p) {
                                        if (isset($p['status']) && $p['status'] === 'partial') $partialCount++;
                                        if (isset($p['status']) && $p['status'] === 'encoded') $newCount++;
                                    }
                                }
                                ?>
                                <div class="row g-3 mb-4" id="poSelectorPanel">

                                        <!-- LEFT: PO list -->
                                        <div class="col-lg-4">
                                            <div class="card h-100">
                                                <div class="card-body p-3">

                                                    <div class="mb-2">
                                                        <input type="text" id="poSearchInput" class="form-control form-control-sm" placeholder="Search PO number or supplier...">
                                                    </div>

                                                    <div class="d-flex gap-2 mb-2">
                                                        <button type="button" class="btn btn-sm btn-outline-warning po-filter-btn active" data-filter="partial">
                                                            Partial (<?= (int)$partialCount ?>)
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-success po-filter-btn" data-filter="encoded">
                                                            New (<?= (int)$newCount ?>)
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="all">
                                                            All (<?= count($purchaseOrders) ?>)
                                                        </button>
                                                    </div>

                                                    <div id="poListScroll" style="max-height:400px; overflow-y:auto; display:flex; flex-direction:column; gap:2px;">
                                                        <?php foreach ($purchaseOrders as $po): 
                                                            $pct = $po['pct'] ?? 0;
                                                            $isPartial = $po['status'] === 'partial';
                                                            $badgeClass = $isPartial ? 'text-bg-warning' : 'text-bg-success';
                                                            $badgeLabel = $isPartial ? 'Partial' : 'New';
                                                            $barColor   = $isPartial ? '#EF9F27' : '#198754';
                                                        ?>
                                                            <div class="po-list-row" data-po-id="<?= (int)$po['id'] ?>" data-status="<?= h($po['status']) ?>" data-po='<?= json_encode([
                                                                    "id"          => (int)$po["id"],
                                                                    "po_number"   => $po["po_number"],
                                                                    "po_date"     => date("M d, Y", strtotime($po["po_date"])),
                                                                    "supplier"    => $po["supplier_name"],
                                                                    "fund"        => $po["fund_code"] . " - " . $po["fund_name"],
                                                                    "mode"        => $po["mode_name"] ?? "",
                                                                    "address"     => $po["supplier_address"] ?? "",
                                                                    "delivery"    => $po["place_of_delivery"] ?? "",
                                                                    "total_lines" => (int)$po["total_lines"],
                                                                    "pct"         => $pct,
                                                                    "status"      => $po["status"],
                                                                ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>'
                                                                style="padding:8px 10px; border-radius:6px; cursor:pointer; border:0.5px solid transparent; margin-bottom:2px;">
                                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                                    <span class="badge <?= $badgeClass ?>" style="font-size:9px;"><?= $badgeLabel ?></span>
                                                                    <span class="fw-semibold" style="font-size:12px; flex:1;"><?= h($po['po_number']) ?></span>
                                                                    <span style="font-size:11px; color:var(--bs-secondary-color);"><?= $pct ?>%</span>
                                                                </div>
                                                                <div style="font-size:11px; color:var(--bs-secondary-color); overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
                                                                    <?= h($po['supplier_name']) ?> · <?= h($po['fund_code']) ?> · <?= date('M d', strtotime($po['po_date'])) ?>
                                                                </div>
                                                                <div style="height:3px; background:var(--bs-border-color); border-radius:2px; margin-top:4px;">
                                                                    <div style="height:3px; border-radius:2px; background:<?= $barColor ?>; width:<?= $pct ?>%;"></div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>

                                                        <?php if (empty($purchaseOrders)): ?>
                                                            <div class="text-center text-muted py-4" style="font-size:12px;">All purchase orders have been fully received.</div>
                                                        <?php endif; ?>
                                                    </div>

                                                </div>
                                            </div>
                                        </div>

                                        <!-- RIGHT: PO detail preview -->
                                        <div class="col-lg-8">
                                            <div class="card h-100">
                                                <div class="card-body p-3" id="poDetailPanel">

                                                    <!-- Empty state -->
                                                    <div id="poDetailEmpty" class="text-center text-muted py-5">
                                                        <div class="mb-1">Select a purchase order from the list</div>
                                                        <div style="font-size:12px;">Click any PO on the left to preview its items</div>
                                                    </div>

                                                    <!-- Detail content (hidden until PO selected) -->
                                                    <div id="poDetailContent" style="display:none;">

                                                        <!-- PO summary -->
                                                        <div class="row g-2 mb-3 p-2 rounded-3" style="background:var(--bs-secondary-bg); font-size:12px;">
                                                            <div class="col-6 col-md-3"><div class="text-muted" style="font-size:10px;">PO Number</div><div class="fw-semibold" id="detailPoNumber"></div></div>
                                                            <div class="col-6 col-md-3"><div class="text-muted" style="font-size:10px;">PO Date</div><div class="fw-semibold" id="detailPoDate"></div></div>
                                                            <div class="col-6 col-md-3"><div class="text-muted" style="font-size:10px;">Supplier</div><div class="fw-semibold" id="detailSupplier"></div></div>
                                                            <div class="col-6 col-md-3"><div class="text-muted" style="font-size:10px;">Fund</div><div class="fw-semibold" id="detailFund"></div></div>
                                                            <div class="col-6 col-md-3"><div class="text-muted" style="font-size:10px;">Mode</div><div class="fw-semibold" id="detailMode"></div></div>
                                                            <div class="col-6 col-md-3"><div class="text-muted" style="font-size:10px;">Progress</div><div class="fw-semibold" id="detailProgress"></div></div>
                                                        </div>

                                                        <!-- Items preview table -->
                                                        <div style="font-size:11px; font-weight:500; color:var(--bs-secondary-color); text-transform:uppercase; letter-spacing:.3px; margin-bottom:6px;">Line items</div>
                                                        <div class="table-responsive" style="max-height:240px;overflow-y:auto;">
                                                            <table class="table table-sm" style="font-size:12px;" data-no-table-search>
                                                                <thead>
                                                                    <tr>
                                                                        <th style="width:40px">Line</th>
                                                                        <th>Description</th>
                                                                        <th style="width:70px">Type</th>
                                                                        <th class="text-end" style="width:75px">Ordered</th>
                                                                        <th class="text-end" style="width:75px">Received</th>
                                                                        <th class="text-end" style="width:75px">Remaining</th>
                                                                        <th style="width:70px">Status</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody id="detailItemsBody"></tbody>
                                                            </table>
                                                        </div>

                                                        <!-- Proceed button -->
                                                        <div class="d-flex justify-content-end mt-3">
                                                            <a href="#" id="proceedToReceiveBtn" class="btn btn-primary">Proceed to Receive this PO →</a>
                                                        </div>

                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                </div>
                                <?php endif; ?>

                                <?php if ($selectedPurchaseOrder): ?>
                                        <div class="d-flex align-items-center gap-3 flex-wrap mb-3 p-3 rounded-3" style="background:var(--bs-secondary-bg); font-size:12px;">
                                            <div>
                                                <span class="fw-semibold"><?php echo h($selectedPurchaseOrder['po_number']); ?></span>
                                                <span class="text-muted ms-2"><?php echo h($selectedPurchaseOrder['supplier_name']); ?></span>
                                                <span class="text-muted ms-2"><?php echo h(date('M d, Y', strtotime($selectedPurchaseOrder['po_date']))); ?></span>
                                            </div>
                                            <div class="ms-auto d-flex gap-2">
                                                <a href="<?php echo base_url('modules/receivings/index.php'); ?>" class="btn btn-sm btn-outline-secondary">← Change PO</a>
                                                <a href="<?php echo base_url('modules/messages/index.php?related_table=purchase_orders&related_id=' . (int) $selectedPurchaseOrder['id']); ?>" class="btn btn-sm btn-outline-info">PO Discussion</a>
                                            </div>
                                        </div>
                                <?php endif; ?>

                                <?php if ($selectedPurchaseOrder): ?>
                                        <div class="receiving-stepper mb-4">
                                            <button type="button" class="receiving-step active text-start" data-scroll-target="receivingPoOverview">
                                                <div class="receiving-step-number">1</div>
                                                <div class="receiving-step-title">PO Overview</div>
                                                <div class="receiving-step-copy">Review the selected PO and remaining lines.</div>
                                            </button>
                                            <button type="button" class="receiving-step text-start" data-scroll-target="receivingHeaderSection">
                                                <div class="receiving-step-number">2</div>
                                                <div class="receiving-step-title">Receiving Header</div>
                                                <div class="receiving-step-copy">Encode the received date and delivery references.</div>
                                            </button>
                                            <button type="button" class="receiving-step text-start" data-scroll-target="receivingItemsSection">
                                                <div class="receiving-step-number">3</div>
                                                <div class="receiving-step-title">Items Workspace</div>
                                                <div class="receiving-step-copy">Work line-by-line with filters and tracked details.</div>
                                            </button>
                                            <button type="button" class="receiving-step text-start" data-scroll-target="receivingSaveSection">
                                                <div class="receiving-step-number">4</div>
                                                <div class="receiving-step-title">Review & Save</div>
                                                <div class="receiving-step-copy">Finish the review and post the batch.</div>
                                            </button>
                                        </div>
                                        <div class="border rounded-3 p-3 mb-4 bg-light-subtle" id="receivingPoOverview">
                                                <div class="row g-3">
                                                        <div class="col-md-3"><div class="small text-muted">PO Number</div><div class="fw-semibold"><?php echo h($selectedPurchaseOrder['po_number']); ?></div></div>
                                                        <div class="col-md-3"><div class="small text-muted">PO Date</div><div class="fw-semibold"><?php echo h(date('M d, Y', strtotime($selectedPurchaseOrder['po_date']))); ?></div></div>
                                                        <div class="col-md-3"><div class="small text-muted">Supplier</div><div class="fw-semibold"><?php echo h($selectedPurchaseOrder['supplier_name']); ?></div></div>
                                                        <div class="col-md-3"><div class="small text-muted">Fund</div><div class="fw-semibold"><?php echo h($selectedPurchaseOrder['fund_code']); ?></div></div>
                                                        <div class="col-md-6"><div class="small text-muted">Supplier Address</div><div class="fw-semibold"><?php echo h($selectedPurchaseOrder['supplier_address'] ?: ''); ?></div></div>
                                                        <div class="col-md-3"><div class="small text-muted">Mode of Procurement</div><div class="fw-semibold"><?php echo h($selectedPurchaseOrder['mode_name'] ?: ''); ?></div></div>
                                                        <div class="col-md-3"><div class="small text-muted">Place of Delivery</div><div class="fw-semibold"><?php echo h($selectedPurchaseOrder['place_of_delivery'] ?: ''); ?></div></div>
                                                        <div class="col-md-3"><div class="small text-muted">Total Lines</div><div class="fw-semibold"><?php echo count($receivingItems); ?> item(s)</div></div>
                                                        <div class="col-md-3"><div class="small text-muted">Remaining Items</div><div class="fw-semibold text-warning"><?php echo count(array_filter($receivingItems, function($i) { return (float)$i['remaining_quantity'] > 0; })); ?> item(s)</div></div>
                                                </div>
                                        </div>

                                        <form method="post">
                            <input type="hidden" name="action" value="save">
                            <?php echo '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; ?>
                            <input type="hidden" name="purchase_order_id" value="<?php echo (int) $selectedPurchaseOrder['id']; ?>">
                        <div class="row g-3 mb-4" id="receivingHeaderSection">
                            <div class="col-md-3"><label class="form-label">System Reference</label><input type="text" class="form-control" value="<?php echo h($form['system_reference']); ?>" readonly></div>
                            <div class="col-md-3"><label for="ris_no" class="form-label">RIS Number</label><input type="text" class="form-control" id="ris_no" name="ris_no" value="<?php echo h($form['ris_no']); ?>" readonly><div class="form-text">Generated as `RIS-YEAR-MONTH-SERIES`.</div></div>
                            <div class="col-md-3"><label for="received_date" class="form-label">Received Date</label><input type="date" class="form-control" id="received_date" name="received_date" value="<?php echo h($form['received_date']); ?>" required></div>
                            <div class="col-md-3"><label for="delivery_receipt_no" class="form-label">Delivery Receipt No.</label><input type="text" class="form-control" id="delivery_receipt_no" name="delivery_receipt_no" value="<?php echo h($form['delivery_receipt_no']); ?>"></div>
                            <div class="col-md-3"><label for="invoice_no" class="form-label">Invoice No.</label><input type="text" class="form-control" id="invoice_no" name="invoice_no" value="<?php echo h($form['invoice_no']); ?>"></div>
                            <div class="col-12"><label for="remarks" class="form-label">Receiving Remarks</label><textarea class="form-control" id="remarks" name="remarks" rows="2"><?php echo h($form['remarks']); ?></textarea></div>
                        </div>

                        <div class="receiving-summary-strip mb-3">
                            <div class="row g-3 align-items-center">
                                <div class="col-md-4"><div class="small text-muted">Lines ready</div><div class="fw-semibold" id="workspaceProgressLabel">0 of <?php echo count($receivingItems); ?> lines</div></div>
                                <div class="col-md-4"><div class="small text-muted">Tracked lines missing details</div><div class="fw-semibold text-danger" id="workspaceMissingDetailsLabel">0 line(s)</div></div>
                                <div class="col-md-4"><div class="small text-muted">Accepted amount so far</div><div class="fw-semibold" id="workspaceAcceptedAmountLabel">0.00</div></div>
                            </div>
                        </div>

                        <div class="receiving-workspace mb-4" id="receivingItemsSection">
                            <aside class="receiving-line-list">
                                <div class="mb-2"><input type="search" id="receivingLineSearch" class="form-control form-control-sm" placeholder="Search line, stock no, description..."></div>
                                <div class="d-flex gap-2 mb-2">
                                    <input type="number" id="receivingJumpLine" class="form-control form-control-sm" placeholder="Line #" min="1">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="receivingJumpBtn">Go</button>
                                </div>
                                <div class="receiving-line-scroll" id="receivingLineList">
                                    <?php foreach ($receivingItems as $navIndex => $item): ?>
                                        <?php $navItemId = (int) $item['id']; ?>
                                        <div class="receiving-line-card <?php echo $navIndex === 0 ? 'active' : ''; ?>" data-line-id="<?php echo $navItemId; ?>" data-line-no="<?php echo (int) $item['line_no']; ?>" data-item-type="<?php echo h($item['item_type']); ?>" data-has-remaining="<?php echo (float) $item['remaining_quantity'] > 0 ? '1' : '0'; ?>">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="badge text-bg-light">Line <?php echo (int) $item['line_no']; ?></span>
                                                <span class="badge text-bg-primary-subtle text-primary"><?php echo h(receiving_type_label((string) $item['item_type'])); ?></span>
                                            </div>
                                            <div class="receiving-line-title"><?php echo h(!empty($item['catalog_item_name']) ? $item['catalog_item_name'] : ($item['classification_name'] ?: 'Unclassified Item')); ?></div>
                                            <div class="receiving-line-meta"><?php echo h(mb_strimwidth(str_replace(["\r", "\n"], ' ', $item['item_description']), 0, 90, '...')); ?></div>
                                            <div class="d-flex justify-content-between mt-2 receiving-line-meta">
                                                <span><?php echo h($item['catalog_stock_no'] ?: ($item['account_code'] ?: '')); ?></span>
                                                <span>Rem: <?php echo h(number_format((float) $item['remaining_quantity'], 2)); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </aside>

                            <div>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div class="small text-muted">Use the compact grid below for fast encoding. Expand details only for semi-expendable and equipment lines.</div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn active" data-filter="all">All</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn" data-filter="supply">Supplies</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn" data-filter="semi_expendable">Semi</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn" data-filter="equipment">Equipment</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn" data-filter="remaining">Remaining Only</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="receivingPrevLineBtn">Previous</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="receivingNextLineBtn">Next</button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle" data-no-table-search>
                                <thead>
                                    <tr>
                                        <th style="width: 48px;">Line</th>
                                        <th style="min-width: 280px;">Item</th>
                                        <th style="width: 110px;">Type</th>
                                        <th class="text-end" style="width: 90px;">Ordered</th>
                                        <th class="text-end" style="width: 90px;">Received</th>
                                        <th class="text-end" style="width: 90px;">Remaining</th>
                                        <th style="width: 120px;">Delivered</th>
                                        <th style="width: 120px;">Accepted</th>
                                        <th style="width: 120px;">Rejected</th>
                                        <th style="min-width: 140px;">Condition</th>
                                        <th style="min-width: 180px;">Remarks</th>
                                        <th style="width: 110px;">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($receivingItems as $item): ?>
                                        <?php
                                        $itemId = (int) $item['id'];
                                        $uomLabel = trim((string) ($item['uom_name'] ?? ''));
                                        if ($uomLabel === '' && !empty($item['abbreviation'])) {
                                            $uomLabel = $item['abbreviation'];
                                        } elseif (!empty($item['abbreviation'])) {
                                            $uomLabel .= ' (' . $item['abbreviation'] . ')';
                                        }
                                        $trackIdentity = receiving_tracks_identity((string) $item['item_type']);
                                        ?>
                                        <tr class="receiving-line-row <?php echo $itemId === (int) ($receivingItems[0]['id'] ?? 0) ? 'table-primary' : ''; ?>" data-line-id="<?php echo $itemId; ?>" data-line-no="<?php echo (int) $item['line_no']; ?>" data-unit-cost="<?php echo h(number_format((float) $item['unit_cost'], 2, '.', '')); ?>" data-item-type="<?php echo h($item['item_type']); ?>" data-has-remaining="<?php echo (float) $item['remaining_quantity'] > 0 ? '1' : '0'; ?>">
                                            <td><?php echo (int) $item['line_no']; ?></td>
                                            <td>
                                                <?php if (!empty($item['catalog_stock_no'])): ?>
                                                    <div class="d-flex align-items-center gap-2 mb-1">
                                                        <span class="badge text-bg-secondary" style="font-size:10px;font-family:monospace;">
                                                            <?php echo h($item['catalog_stock_no']); ?>
                                                        </span>
                                                        <span class="fw-semibold">
                                                            <?php echo h($item['catalog_item_name']); ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="fw-semibold"><?php echo h($item['classification_name'] ?: 'No inventory class'); ?></div>
                                                <?php endif; ?>
                                                <div class="small"><?php echo h(mb_strimwidth(str_replace(["\r", "\n"], ' ', $item['item_description']), 0, 120, '...')); ?></div>
                                                <div class="small text-muted"><?php echo h($item['account_code'] ?: ''); ?><?php echo $item['account_name'] ? ' - ' . h($item['account_name']) : ''; ?><?php echo $uomLabel ? ' | ' . h($uomLabel) : ''; ?></div>
                                            </td>
                                            <td>
                                                <span class="badge text-bg-primary-subtle text-primary"><?php echo h(receiving_type_label((string) $item['item_type'])); ?></span>
                                            </td>
                                            <td class="text-end"><?php echo h(number_format((float) $item['quantity'], 2)); ?></td>
                                            <td class="text-end"><?php echo h(number_format((float) $item['quantity_already_received'], 2)); ?></td>
                                            <td class="text-end fw-semibold"><?php echo h(number_format((float) $item['remaining_quantity'], 2)); ?></td>
                                            <td><input type="number" class="form-control form-control-sm receiving-deliver-input" step="0.01" min="0" max="<?php echo h((string) $item['remaining_quantity']); ?>" name="items[<?php echo $itemId; ?>][deliver_quantity]" value="<?php echo h($item['deliver_quantity']); ?>"></td>
                                            <td><input type="number" class="form-control form-control-sm receiving-accept-input" step="0.01" min="0" max="<?php echo h((string) $item['remaining_quantity']); ?>" name="items[<?php echo $itemId; ?>][accept_quantity]" value="<?php echo h($item['accept_quantity']); ?>"></td>
                                            <td><input type="number" class="form-control form-control-sm" step="0.01" min="0" max="<?php echo h((string) $item['remaining_quantity']); ?>" name="items[<?php echo $itemId; ?>][reject_quantity]" value="<?php echo h($item['reject_quantity']); ?>"></td>
                                            <td>
                                                <?php
                                                $condition = trim((string) $item['item_condition']);
                                                $conditionOptions = ['Good Condition', 'Fair Condition', 'Damaged', 'Defective'];
                                                if (!in_array($condition, $conditionOptions, true)) {
                                                    $condition = 'Good Condition';
                                                }
                                                ?>
                                                <select class="form-select form-select-sm" name="items[<?php echo $itemId; ?>][item_condition]">
                                                    <?php foreach ($conditionOptions as $opt): ?>
                                                        <option value="<?php echo h($opt); ?>" <?php echo $condition === $opt ? 'selected' : ''; ?>><?php echo h($opt); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><input type="text" class="form-control form-control-sm" name="items[<?php echo $itemId; ?>][remarks]" value="<?php echo h($item['remarks']); ?>"></td>
                                            <td>
                                                <?php if ($trackIdentity): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#receiving-details-<?php echo $itemId; ?>" aria-expanded="false">Details</button>
                                                <?php else: ?>
                                                    <span class="small text-success">Stock</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php if ($trackIdentity): ?>
                                        <tr class="collapse receiving-detail-wrapper <?php echo $itemId === (int) ($receivingItems[0]['id'] ?? 0) ? 'show' : ''; ?>" id="receiving-details-<?php echo $itemId; ?>" data-line-id="<?php echo $itemId; ?>" data-item-type="<?php echo h($item['item_type']); ?>" data-has-remaining="<?php echo (float) $item['remaining_quantity'] > 0 ? '1' : '0'; ?>">
                                                <td colspan="12" class="bg-light-subtle">
                                                    <div class="border rounded-3 p-3 my-2">
                                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                            <div>
                                                                <div class="fw-semibold">Brand / Model / Serial Details</div>
                                                                <div class="small text-muted">Add at least one detail row for accepted semi-expendable and equipment items.</div>
                                                            </div>
                                                            <div class="small text-muted detail-row-status" data-item-id="<?php echo $itemId; ?>">0 detail row(s)</div>
                                                        </div>
                                                        <div class="receiving-detail-rows" data-item-id="<?php echo $itemId; ?>">
                                                            <?php foreach ($item['detail_rows'] as $detailIndex => $detail): ?>
                                                                <div class="row g-2 align-items-end receiving-detail-row mb-2">
                                                                    <div class="col-md-3"><label class="form-label">Brand</label><select class="form-select receiving-brand-select" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][brand_id]" data-placeholder="Select brand"><option value="">Select brand</option><?php foreach ($brands as $brand): ?><option value="<?php echo (int) $brand['id']; ?>" <?php echo $detail['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>><?php echo h($brand['brand_name']); ?></option><?php endforeach; ?></select><input type="hidden" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][brand]" value="<?php echo h($detail['brand']); ?>"></div>
                                                                    <div class="col-md-3"><label class="form-label">Model</label><select class="form-select receiving-model-select" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][model_id]" data-placeholder="Select model"><option value="">Select model</option><?php foreach ($models as $model): ?><option value="<?php echo (int) $model['id']; ?>" data-brand-id="<?php echo (int) $model['brand_id']; ?>" <?php echo $detail['model_id'] === (string) $model['id'] ? 'selected' : ''; ?>><?php echo h($model['model_name']); ?></option><?php endforeach; ?></select><input type="hidden" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][model]" value="<?php echo h($detail['model']); ?>"></div>
                                                                    <div class="col-md-3"><label class="form-label">Serial Number</label><input type="text" class="form-control" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][serial_no]" value="<?php echo h($detail['serial_no']); ?>"></div>
                                                                    <div class="col-md-2"><label class="form-label">Remarks</label><input type="text" class="form-control" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][remarks]" value="<?php echo h($detail['remarks']); ?>"></div>
                                                                    <div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm w-100 remove-detail-row">Remove</button></div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end" id="receivingSaveSection"><button type="submit" class="btn btn-primary">Save Receiving</button></div>
                    </form>
                <?php else: ?>
                    <div class="text-muted">Select a purchase order first to load items for receiving.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Receiving Records</h5>
                    <span class="badge text-bg-light"><?php echo count($receivings); ?> record(s)</span>
                </div>

                <form method="get" class="row g-2 align-items-end mb-3">
                    <div class="col-md-5">
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search PO number, supplier, or reference..." value="<?php echo h($filterPoNumber); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="filter_status" class="form-select form-select-sm" data-no-select2>
                            <option value="">All statuses</option>
                            <option value="partial"   <?php echo $filterStatus==='partial'   ?'selected':'' ?>>Partial</option>
                            <option value="completed" <?php echo $filterStatus==='completed' ?'selected':'' ?>>Completed</option>
                            <option value="cancelled" <?php echo $filterStatus==='cancelled' ?'selected':'' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                        <a href="<?php echo base_url('modules/receivings/index.php'); ?>" class="btn btn-link btn-sm">Clear</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Reference</th><th>RIS No.</th><th>Received Date</th><th>PO Number</th><th>Supplier</th><th>DR No.</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php if ($receivings): foreach ($receivings as $receiving): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo h($receiving['system_reference']); ?></td>
                                    <td><?php echo h($receiving['ris_no'] ?? ''); ?></td>
                                    <td><?php echo h(date('M d, Y', strtotime($receiving['received_date']))); ?></td>
                                    <td><?php echo h($receiving['po_number']); ?></td>
                                    <td><?php echo h($receiving['supplier_name']); ?></td>
                                    <td><?php echo h($receiving['delivery_receipt_no'] ?? ''); ?></td>
                                    <td><?php echo receiving_status_badge($receiving['status']); ?></td>
                                    <td class="text-end"><?php echo h(number_format((float) $receiving['total_received_amount'], 2)); ?></td>
                                    <td class="text-end"><a href="<?php echo base_url('modules/messages/index.php?related_table=receivings&related_id=' . (int) $receiving['id']); ?>" class="btn btn-sm btn-outline-info me-1">Discussion</a><?php if (in_array($receiving['status'], ['completed', 'partial'], true)): ?><a href="<?php echo base_url('modules/receivings/iar.php?id=' . (int) $receiving['id']); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Print IAR</a><?php else: ?><span class="text-muted small">No items received yet</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">No receiving records yet.</td></tr>
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
    var brands = <?php echo json_encode($brands, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var models = <?php echo json_encode($models, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    // PO quick summary UI
    var poSelect = document.getElementById('po_id');
    var poQuickSummary = document.getElementById('poQuickSummary');
    function updatePoQuickSummary() {
        if (!poSelect || !poQuickSummary) return;
        var opt = poSelect.options[poSelect.selectedIndex];
        if (!opt || !opt.value) { poQuickSummary.style.display = 'none'; return; }
        var status = opt.getAttribute('data-status') || '';
        var pct = opt.getAttribute('data-pct') || '0';
        var msg = '';
        if (status === 'partial') {
            msg = 'This PO has a partial delivery on record (' + pct + '% received). Loading will show remaining items only.';
        } else {
            msg = 'New PO — no deliveries recorded yet. All items will be shown for receiving.';
        }
        poQuickSummary.textContent = msg;
        poQuickSummary.style.display = 'block';
    }
    if (poSelect) {
        poSelect.addEventListener('change', updatePoQuickSummary);
        if (window.jQuery) {
            window.jQuery(poSelect).on('select2:select select2:clear', updatePoQuickSummary);
        }
        updatePoQuickSummary();
    }

    function brandOptions(selectedId) {
        var html = '<option value="">Select brand</option>';
        brands.forEach(function (brand) {
            html += '<option value="' + brand.id + '"' + (String(selectedId) === String(brand.id) ? ' selected' : '') + '>' + brand.brand_name + '</option>';
        });
        return html;
    }

    function modelOptions(selectedId, brandId) {
        var html = '<option value="">Select model</option>';
        models.forEach(function (model) {
            if (brandId && String(model.brand_id) !== String(brandId)) {
                return;
            }
            html += '<option value="' + model.id + '" data-brand-id="' + model.brand_id + '"' + (String(selectedId) === String(model.id) ? ' selected' : '') + '>' + model.model_name + '</option>';
        });
        return html;
    }

    function syncModelOptions(row) {
        var brandSelect = row.querySelector('.receiving-brand-select');
        var modelSelect = row.querySelector('.receiving-model-select');
        var hiddenModel = row.querySelector('input[name$="[model]"]');
        var currentModelValue = modelSelect ? modelSelect.value : '';
        var selectedBrand = brandSelect ? brandSelect.value : '';
        if (!modelSelect) return;
        var matchingModelExists = !selectedBrand || models.some(function (model) {
            return String(model.id) === String(currentModelValue) && String(model.brand_id) === String(selectedBrand);
        });
        if (!matchingModelExists) {
            currentModelValue = '';
            if (hiddenModel) hiddenModel.value = '';
        }

        modelSelect.innerHTML = modelOptions(currentModelValue, selectedBrand);
        modelSelect.disabled = false;

        if (window.jQuery && window.jQuery.fn.select2) {
            var $modelSelect = window.jQuery(modelSelect);
            if ($modelSelect.hasClass('select2-hidden-accessible')) {
                $modelSelect.select2('destroy');
                modelSelect.removeAttribute('data-select2-initialized');
            }
            if (window.SPAMS && window.SPAMS.initSelect2) {
                window.SPAMS.initSelect2(modelSelect.parentElement || row);
            }
        }
    }

    function rowMarkup(itemId, index) {
        return '<div class="row g-2 align-items-end receiving-detail-row mb-2">' +
            '<div class="col-md-3"><label class="form-label">Brand</label><select class="form-select receiving-brand-select" name="items[' + itemId + '][details][' + index + '][brand_id]" data-placeholder="Select brand">' + brandOptions('') + '</select><input type="hidden" name="items[' + itemId + '][details][' + index + '][brand]" value=""></div>' +
            '<div class="col-md-3"><label class="form-label">Model</label><select class="form-select receiving-model-select" name="items[' + itemId + '][details][' + index + '][model_id]" data-placeholder="Select model">' + modelOptions('', '') + '</select><input type="hidden" name="items[' + itemId + '][details][' + index + '][model]" value=""></div>' +
            '<div class="col-md-3"><label class="form-label">Serial Number</label><input type="text" class="form-control" name="items[' + itemId + '][details][' + index + '][serial_no]"></div>' +
            '<div class="col-md-2"><label class="form-label">Remarks</label><input type="text" class="form-control" name="items[' + itemId + '][details][' + index + '][remarks]"></div>' +
            '<div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm w-100 remove-detail-row">Remove</button></div>' +
        '</div>';
    }

    function updateDetailRowStatus(itemId) {
        var container = document.querySelector('.receiving-detail-rows[data-item-id="' + itemId + '"]');
        var status = document.querySelector('.detail-row-status[data-item-id="' + itemId + '"]');
        var acceptInput = document.querySelector('.receiving-accept-input[name="items[' + itemId + '][accept_quantity]"]');
        if (!container || !status || !acceptInput) return;
        var expected = Math.max(0, Math.round(parseNum(acceptInput.value || 0)));
        var actual = container.querySelectorAll('.receiving-detail-row').length;
        status.textContent = actual + ' of ' + expected + ' detail row(s)';
    }

    function ensureTrackedDetailRows(itemId) {
        var container = document.querySelector('.receiving-detail-rows[data-item-id="' + itemId + '"]');
        var acceptInput = document.querySelector('.receiving-accept-input[name="items[' + itemId + '][accept_quantity]"]');
        if (!container || !acceptInput) return;

        var expected = Math.max(0, Math.round(parseNum(acceptInput.value || 0)));
        var rows = container.querySelectorAll('.receiving-detail-row');

        while (rows.length < expected) {
            container.insertAdjacentHTML('beforeend', rowMarkup(itemId, rows.length));
            rows = container.querySelectorAll('.receiving-detail-row');
        }

        while (rows.length > expected && rows.length > 0) {
            rows[rows.length - 1].remove();
            rows = container.querySelectorAll('.receiving-detail-row');
        }

        if (rows.length === 0) {
            container.insertAdjacentHTML('beforeend', rowMarkup(itemId, 0));
            rows = container.querySelectorAll('.receiving-detail-row');
        }

        Array.from(rows).forEach(function (detailRow) {
            if (window.SPAMS && window.SPAMS.refreshSelect2) {
                window.SPAMS.refreshSelect2(detailRow);
            }
            syncModelOptions(detailRow);
        });

        updateDetailRowStatus(itemId);
    }

    document.querySelectorAll('.receiving-detail-row').forEach(syncModelOptions);
    document.addEventListener('click', function (event) {
        if (!event.target.classList.contains('remove-detail-row')) return;
        var row = event.target.closest('.receiving-detail-row');
        var container = row ? row.parentElement : null;
        if (!row || !container) return;
        row.remove();
        var itemId = container.getAttribute('data-item-id');
        if (itemId) {
            ensureTrackedDetailRows(itemId);
        }
        updateWorkspaceSummary();
    });
    document.addEventListener('change', function (event) {
        var row = event.target.closest('.receiving-detail-row');
        if (!row) return;
        if (event.target.classList.contains('receiving-brand-select')) {
            var brandText = event.target.options[event.target.selectedIndex] ? event.target.options[event.target.selectedIndex].text : '';
            var hiddenBrand = row.querySelector('input[name$="[brand]"]');
            if (hiddenBrand) hiddenBrand.value = event.target.value ? brandText : '';
            syncModelOptions(row);
        }
        if (event.target.classList.contains('receiving-model-select')) {
            var modelText = event.target.options[event.target.selectedIndex] ? event.target.options[event.target.selectedIndex].text : '';
            var hiddenModel = row.querySelector('input[name$="[model]"]');
            if (hiddenModel) hiddenModel.value = event.target.value ? modelText : '';
        }
        updateWorkspaceSummary();
    });
    if (window.jQuery) {
        window.jQuery(document).on('select2:select select2:clear', '.receiving-brand-select', function () {
            var row = this.closest('.receiving-detail-row');
            if (!row) return;
            var hiddenBrand = row.querySelector('input[name$="[brand]"]');
            var brandText = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : '';
            if (hiddenBrand) hiddenBrand.value = this.value ? brandText : '';
            syncModelOptions(row);
        });
        window.jQuery(document).on('select2:select select2:clear', '.receiving-model-select', function () {
            var row = this.closest('.receiving-detail-row');
            if (!row) return;
            var hiddenModel = row.querySelector('input[name$="[model]"]');
            var modelText = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : '';
            if (hiddenModel) hiddenModel.value = this.value ? modelText : '';
        });
    }

    document.querySelectorAll('.receiving-filter-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            var filter = button.getAttribute('data-filter');
            document.querySelectorAll('.receiving-filter-btn').forEach(function (btn) {
                btn.classList.toggle('active', btn === button);
            });
            document.querySelectorAll('.receiving-line-row, .receiving-detail-wrapper, .receiving-line-card').forEach(function (row) {
                var itemType = row.getAttribute('data-item-type');
                var hasRemaining = row.getAttribute('data-has-remaining') === '1';
                var show = filter === 'all'
                    || (filter === 'remaining' && hasRemaining)
                    || itemType === filter;
                row.style.display = show ? '' : 'none';
            });
            var visibleCards = visibleLineCards();
            if (visibleCards.length > 0) {
                setActiveLine(visibleCards[0].getAttribute('data-line-id'));
            }
        });
    });

    var receivedDateInput = document.getElementById('received_date');
    var risNoInput = document.getElementById('ris_no');
    if (receivedDateInput && risNoInput) {
        receivedDateInput.addEventListener('change', function () {
            if (!receivedDateInput.value) return;
            var parts = receivedDateInput.value.split('-');
            if (parts.length !== 3) return;
            var year = parts[0];
            var month = parts[1];
            var currentValue = risNoInput.value || '';
            var series = '0001';
            var currentParts = currentValue.split('-');
            if (currentParts.length === 4 && currentParts[1] === year && currentParts[2] === month) {
                series = currentParts[3];
            }
            risNoInput.value = 'RIS-' + year + '-' + month + '-' + series;
        });
    }

    // Sync Delivered / Accepted / Rejected fields for better UX
    function parseNum(val) {
        var n = parseFloat(String(val).replace(/[^0-9.-]/g, ''));
        return Number.isFinite(n) ? n : 0.0;
    }

    function toFixed2(v) { return (Math.round((v + Number.EPSILON) * 100) / 100).toFixed(2); }

    function setActiveLine(lineId) {
        document.querySelectorAll('.receiving-line-card').forEach(function (card) {
            card.classList.toggle('active', card.getAttribute('data-line-id') === String(lineId));
        });
        document.querySelectorAll('.receiving-line-row').forEach(function (row) {
            row.classList.toggle('table-primary', row.getAttribute('data-line-id') === String(lineId));
        });
        document.querySelectorAll('.receiving-detail-wrapper').forEach(function (wrapper) {
            var active = wrapper.getAttribute('data-line-id') === String(lineId);
            wrapper.classList.toggle('show', active);
            wrapper.classList.toggle('d-none', !active);
        });
    }

    function visibleLineCards() {
        return Array.from(document.querySelectorAll('.receiving-line-card')).filter(function (card) {
            return card.style.display !== 'none';
        });
    }

    function updateWorkspaceSummary() {
        var rows = Array.from(document.querySelectorAll('.receiving-line-row'));
        var ready = 0;
        var acceptedAmount = 0;
        var missingDetails = 0;

        rows.forEach(function (row) {
            var lineId = row.getAttribute('data-line-id');
            var deliver = parseNum((row.querySelector('.receiving-deliver-input') || {}).value || 0);
            var accept = parseNum((row.querySelector('.receiving-accept-input') || {}).value || 0);
            var unitCost = parseNum(row.getAttribute('data-unit-cost') || 0);
            if (deliver > 0 || accept > 0) {
                ready++;
            }
            acceptedAmount += accept * unitCost;

            var detailContainer = document.querySelector('.receiving-detail-rows[data-item-id="' + lineId + '"]');
            if (detailContainer) {
                var expected = Math.max(0, Math.round(accept));
                if (expected > detailContainer.querySelectorAll('.receiving-detail-row').length) {
                    missingDetails++;
                }
            }
        });

        var progressLabel = document.getElementById('workspaceProgressLabel');
        var missingLabel = document.getElementById('workspaceMissingDetailsLabel');
        var amountLabel = document.getElementById('workspaceAcceptedAmountLabel');
        if (progressLabel) progressLabel.textContent = ready + ' of ' + rows.length + ' lines';
        if (missingLabel) missingLabel.textContent = missingDetails + ' line(s)';
        if (amountLabel) amountLabel.textContent = toFixed2(acceptedAmount);

        document.querySelectorAll('.receiving-line-card').forEach(function (card) {
            var lineId = card.getAttribute('data-line-id');
            var row = document.querySelector('.receiving-line-row[data-line-id="' + lineId + '"]');
            var deliver = parseNum((row && row.querySelector('.receiving-deliver-input') ? row.querySelector('.receiving-deliver-input').value : 0));
            card.classList.toggle('done', deliver > 0);
        });
    }

    document.addEventListener('input', function (ev) {
        var t = ev.target;
        if (!t || t.tagName !== 'INPUT' || t.type !== 'number') return;
        var row = t.closest('tr.receiving-line-row');
        if (!row) return;
        var deliverInput = row.querySelector('input[name$="[deliver_quantity]"]');
        var acceptInput = row.querySelector('input[name$="[accept_quantity]"]');
        var rejectInput = row.querySelector('input[name$="[reject_quantity]"]');
        if (!deliverInput || !acceptInput || !rejectInput) return;

        var deliver = parseNum(deliverInput.value);
        var accept = parseNum(acceptInput.value);
        var reject = parseNum(rejectInput.value);
        var max = parseNum(deliverInput.max) || null;

        // When user types delivered: sync accepted/rejected intelligently
        if (t === deliverInput) {
            if (deliver > 0) {
                // If user hasn't manually set a rejection, assume full acceptance
                if (parseNum(rejectInput.value) === 0) {
                    acceptInput.value = toFixed2(deliver);
                    rejectInput.value = '0.00';
                } else {
                    // Recompute reject based on new delivered and current accepted
                    var newReject = Math.max(0, deliver - parseNum(acceptInput.value));
                    rejectInput.value = toFixed2(newReject);
                }
            } else {
                // cleared delivered -> reset accepted/rejected
                acceptInput.value = '0.00';
                rejectInput.value = '0.00';
            }
            var deliverDetailContainer = document.querySelector('.receiving-detail-rows[data-item-id="' + row.querySelector('input[name$="[accept_quantity]"]').name.match(/items\[(\d+)\]/)[1] + '"]');
            if (deliverDetailContainer) {
                ensureTrackedDetailRows(deliverDetailContainer.getAttribute('data-item-id'));
            }
            updateWorkspaceSummary();
            return;
        }

        // When user edits accept, compute reject = deliver - accept (if deliver provided)
        if (t === acceptInput) {
            if (deliver > 0) {
                var newReject = Math.max(0, deliver - accept);
                if (max !== null && accept > max) {
                    accept = max;
                    acceptInput.value = toFixed2(accept);
                    newReject = Math.max(0, deliver - accept);
                }
                rejectInput.value = toFixed2(newReject);
            }
            var detailContainer = document.querySelector('.receiving-detail-rows[data-item-id="' + row.querySelector('input[name$="[accept_quantity]"]').name.match(/items\[(\d+)\]/)[1] + '"]');
            if (detailContainer) {
                ensureTrackedDetailRows(detailContainer.getAttribute('data-item-id'));
            }
            updateWorkspaceSummary();
            return;
        }

        // When user edits reject, compute accept = deliver - reject (if deliver provided)
        if (t === rejectInput) {
            if (deliver > 0) {
                var newAccept = Math.max(0, deliver - reject);
                if (max !== null && newAccept > max) {
                    newAccept = max;
                }
                acceptInput.value = toFixed2(newAccept);
            }
            var rejectDetailContainer = document.querySelector('.receiving-detail-rows[data-item-id="' + row.querySelector('input[name$="[accept_quantity]"]').name.match(/items\[(\d+)\]/)[1] + '"]');
            if (rejectDetailContainer) {
                ensureTrackedDetailRows(rejectDetailContainer.getAttribute('data-item-id'));
            }
            updateWorkspaceSummary();
            return;
        }
    });

    // PO selector interactions (left list -> preview)
    function renderPoDetail(po, items) {
        document.getElementById('poDetailEmpty').style.display = 'none';
        var content = document.getElementById('poDetailContent');
        content.style.display = '';
        document.getElementById('detailPoNumber').textContent = po.po_number || '';
        document.getElementById('detailPoDate').textContent = po.po_date || '';
        document.getElementById('detailSupplier').textContent = po.supplier || '';
        document.getElementById('detailFund').textContent = po.fund || '';
        document.getElementById('detailMode').textContent = po.mode || '';
        document.getElementById('detailProgress').textContent = (po.pct || 0) + '%';

        var tbody = document.getElementById('detailItemsBody');
        tbody.innerHTML = '';
        items.forEach(function (it) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + it.line_no + '</td>' +
                '<td>' + (it.description ? it.description : '') + '</td>' +
                '<td>' + (it.item_type ? it.item_type : '') + '</td>' +
                '<td class="text-end">' + (it.ordered !== undefined ? it.ordered.toFixed(2) : '0.00') + '</td>' +
                '<td class="text-end">' + (it.received !== undefined ? it.received.toFixed(2) : '0.00') + '</td>' +
                '<td class="text-end fw-semibold">' + (it.remaining !== undefined ? it.remaining.toFixed(2) : '0.00') + '</td>' +
                '<td>' + (it.remaining > 0 ? '<span class="badge text-bg-warning">Remaining</span>' : '<span class="badge text-bg-success">Done</span>') + '</td>';
            tbody.appendChild(tr);
        });
    }

    document.querySelectorAll('.po-list-row').forEach(function (el) {
        el.addEventListener('click', function () {
            var poJson = el.getAttribute('data-po');
            var po = {};
            try { po = JSON.parse(poJson); } catch (e) { po = {}; }
            // attach pct from attribute if not present
            if (!po.pct) {
                po.pct = el.querySelector('span') ? parseInt(el.querySelector('span').nextSibling ? el.querySelector('span').nextSibling.textContent.trim() : '0') : 0;
            }
            // visually highlight
            document.querySelectorAll('.po-list-row').forEach(function (r) { r.style.borderColor = 'transparent'; r.style.background = ''; });
            el.style.borderColor = 'rgba(0,0,0,0.06)';
            el.style.background = 'rgba(0,0,0,0.02)';

            // fetch items via AJAX
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'po_items_preview.php?po_id=' + encodeURIComponent(el.getAttribute('data-po-id')));
            xhr.responseType = 'json';
            xhr.onload = function () {
                var data = xhr.response;
                if (!data || !data.ok) {
                    alert('Unable to load PO preview.');
                    return;
                }
                // enrich po object with pct if present
                if (data.po) {
                    data.po.pct = po.pct || data.po.pct || 0;
                }
                renderPoDetail(data.po || po, data.items || []);
                // set proceed button
                var btn = document.getElementById('proceedToReceiveBtn');
                if (btn) {
                    btn.setAttribute('href', 'index.php?po_id=' + encodeURIComponent(el.getAttribute('data-po-id')) );
                }
            };
            xhr.send();
        });
    });

    // Search/filter within PO list
    var poSearch = document.getElementById('poSearchInput');
    if (poSearch) {
        poSearch.addEventListener('input', function () {
            var q = poSearch.value.trim().toLowerCase();
            document.querySelectorAll('.po-list-row').forEach(function (r) {
                var text = r.textContent.toLowerCase();
                r.style.display = text.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    document.querySelectorAll('.po-filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.po-filter-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var f = btn.getAttribute('data-filter');
            document.querySelectorAll('.po-list-row').forEach(function (r) {
                var status = r.getAttribute('data-status') || '';
                if (f === 'all') { r.style.display = ''; return; }
                r.style.display = (f === status) ? '' : 'none';
            });
        });
    });

    document.querySelectorAll('.receiving-step[data-scroll-target]').forEach(function (stepBtn) {
        stepBtn.addEventListener('click', function () {
            var target = document.getElementById(stepBtn.getAttribute('data-scroll-target') || '');
            document.querySelectorAll('.receiving-step[data-scroll-target]').forEach(function (btn) {
                btn.classList.remove('active');
            });
            stepBtn.classList.add('active');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    document.querySelectorAll('.receiving-line-card').forEach(function (card) {
        card.addEventListener('click', function () {
            var lineId = card.getAttribute('data-line-id');
            setActiveLine(lineId);
            var row = document.querySelector('.receiving-line-row[data-line-id="' + lineId + '"]');
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    });

    document.getElementById('receivingPrevLineBtn')?.addEventListener('click', function () {
        var cards = visibleLineCards();
        var active = document.querySelector('.receiving-line-card.active');
        var index = cards.indexOf(active);
        if (index > 0) {
            cards[index - 1].click();
        }
    });

    document.getElementById('receivingNextLineBtn')?.addEventListener('click', function () {
        var cards = visibleLineCards();
        var active = document.querySelector('.receiving-line-card.active');
        var index = cards.indexOf(active);
        if (index > -1 && index < cards.length - 1) {
            cards[index + 1].click();
        }
    });

    document.getElementById('receivingLineSearch')?.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        document.querySelectorAll('.receiving-line-card').forEach(function (card) {
            card.style.display = card.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
        });
    });

    document.getElementById('receivingJumpBtn')?.addEventListener('click', function () {
        var lineNo = String((document.getElementById('receivingJumpLine') || {}).value || '');
        var targetCard = document.querySelector('.receiving-line-card[data-line-no="' + lineNo + '"]');
        if (targetCard) {
            targetCard.click();
        }
    });

    document.querySelectorAll('.receiving-detail-rows[data-item-id]').forEach(function (container) {
        ensureTrackedDetailRows(container.getAttribute('data-item-id'));
    });
    updateWorkspaceSummary();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
