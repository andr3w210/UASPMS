<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer');

function receiving_status_badge(string $status): string
{
    return operational_status_badge('receiving', $status);
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
    return ['brand_id' => '', 'model_id' => '', 'brand' => '', 'model' => '', 'serial_no' => '', 'remarks' => '', 'no_brand_model' => '0', 'no_serial_no' => '0', 'no_remarks' => '0'];
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
            'no_brand_model' => !empty($row['no_brand_model']) ? '1' : '0',
            'no_serial_no' => !empty($row['no_serial_no']) ? '1' : '0',
            'no_remarks' => !empty($row['no_remarks']) ? '1' : '0',
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

$db = db();
$page_title = 'Receiving';
$flash = get_flash();
$errors = [];
$receivings = [];
$purchaseOrders = [];
$receivingItems = [];
$brands = [];
$models = [];
$semiHighValueMin = 5000.0;
$selectedPurchaseOrder = null;
$selectedPurchaseOrderId = (int) ($_GET['po_id'] ?? ($_POST['purchase_order_id'] ?? 0));
$form = [
    'system_reference' => '',
    'purchase_order_id' => $selectedPurchaseOrderId > 0 ? (string) $selectedPurchaseOrderId : '',
    'ris_no' => '',
    'received_date' => date('Y-m-d'),
    'delivery_receipt_no' => '',
    'invoice_no' => '',
    'inspected_by' => '',
    'remarks' => '',
    'confirm_physical_receipt' => '0',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $poItemHasSemiType = function_exists('schema_has_column')
        ? schema_has_column($db, 'purchase_order_items', 'semi_expendable_type')
        : false;
    $activeThreshold = get_active_threshold($db);
    $semiHighValueMin = (float) ($activeThreshold['semi_hv_min'] ?? 5000);
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
                    $item['deliver_quantity'] = '0.00';
                    $item['accept_quantity'] = '0.00';
                    $item['reject_quantity'] = '0.00';
                    $item['item_condition'] = 'Good Condition';
                    $item['remarks'] = '';
                    $item['bulk_no_brand_model'] = '0';
                    $item['bulk_no_serial_no'] = '0';
                    $item['bulk_no_remarks'] = '0';
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
        $form['inspected_by'] = old($_POST, 'inspected_by');
        $form['remarks'] = old($_POST, 'remarks');
        $form['confirm_physical_receipt'] = !empty($_POST['confirm_physical_receipt']) ? '1' : '0';
        $postedItems = $_POST['items'] ?? [];
        $validatedItems = [];
        $remainingAfterSave = [];
        $postedSerialNumbers = [];
        $totalReceivedAmount = 0.00;
        $requiresPhysicalCheckConfirmation = false;
        $requiresPhysicalCheckLineNos = [];

        if ($form['purchase_order_id'] === '') {
            add_validation_error($errors, 'Purchase order is required.');
        }
        if ($form['received_date'] === '') {
            add_validation_error($errors, 'Received date is required.');
        } elseif (!is_valid_date_string($form['received_date'])) {
            add_validation_error($errors, 'Received date format is invalid.');
        }

        if ($form['purchase_order_id'] !== '') {
            $selectedPoExists = false;
            $postedPoId = (int) $form['purchase_order_id'];
            foreach ($purchaseOrders as $poRow) {
                if ((int) ($poRow['id'] ?? 0) === $postedPoId) {
                    $selectedPoExists = true;
                    break;
                }
            }
            if (!$selectedPoExists) {
                add_validation_error($errors, 'Selected purchase order is invalid or unavailable for receiving.');
            }
        }

        foreach ($receivingItems as &$item) {
            $itemId = (int) $item['id'];
            $posted = isset($postedItems[$itemId]) && is_array($postedItems[$itemId]) ? $postedItems[$itemId] : [];
            $item['deliver_quantity'] = old($posted, 'deliver_quantity', $item['deliver_quantity']);
            $item['accept_quantity'] = old($posted, 'accept_quantity', $item['accept_quantity']);
            $item['reject_quantity'] = old($posted, 'reject_quantity', $item['reject_quantity']);
            $item['item_condition'] = old($posted, 'item_condition', $item['item_condition']);
            $item['remarks'] = old($posted, 'remarks');
            $item['bulk_no_brand_model'] = !empty($posted['bulk_no_brand_model']) ? '1' : '0';
            $item['bulk_no_serial_no'] = !empty($posted['bulk_no_serial_no']) ? '1' : '0';
            $item['bulk_no_remarks'] = !empty($posted['bulk_no_remarks']) ? '1' : '0';
            $item['detail_rows'] = receiving_tracks_identity((string) $item['item_type'])
                ? receiving_normalize_details($posted['details'] ?? $item['detail_rows'])
                : [];
            if (receiving_tracks_identity((string) $item['item_type']) && empty($item['detail_rows'])) {
                $item['detail_rows'] = [receiving_blank_detail()];
            }

            $delivered = (float) ($posted['deliver_quantity'] ?? 0);
            $accepted = (float) ($posted['accept_quantity'] ?? 0);
            $rejected = (float) ($posted['reject_quantity'] ?? 0);
            $remaining = (float) $item['remaining_quantity'];
            $condition = trim((string) ($posted['item_condition'] ?? ''));
            $details = receiving_normalize_details($posted['details'] ?? []);
            $allDetailsHaveNoBrandModelValues = !empty($details);
            foreach ($details as $detailCheck) {
                $hasBrandModelValue = trim((string) ($detailCheck['brand_id'] ?? '')) !== ''
                    || trim((string) ($detailCheck['model_id'] ?? '')) !== ''
                    || trim((string) ($detailCheck['brand'] ?? '')) !== ''
                    || trim((string) ($detailCheck['model'] ?? '')) !== '';
                if ($hasBrandModelValue) {
                    $allDetailsHaveNoBrandModelValues = false;
                    break;
                }
            }

            if (!empty($posted['bulk_no_brand_model'])) {
                foreach ($details as &$detail) {
                    $detail['no_brand_model'] = '1';
                    $detail['brand_id'] = '';
                    $detail['model_id'] = '';
                    $detail['brand'] = '';
                    $detail['model'] = '';
                }
                unset($detail);
            }
            if ($allDetailsHaveNoBrandModelValues) {
                foreach ($details as &$detail) {
                    $detail['no_brand_model'] = '1';
                }
                unset($detail);
            }
            if (!empty($posted['bulk_no_serial_no'])) {
                foreach ($details as &$detail) {
                    $detail['no_serial_no'] = '1';
                    $detail['serial_no'] = '';
                }
                unset($detail);
            }
            if (!empty($posted['bulk_no_remarks'])) {
                foreach ($details as &$detail) {
                    $detail['no_remarks'] = '1';
                    $detail['remarks'] = '';
                }
                unset($detail);
            }

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

            $itemType = (string) ($item['item_type'] ?? '');
            $isEquipment = $itemType === 'equipment';
            $isHighValueSemi = false;
            if ($itemType === 'semi_expendable') {
                $semiType = (string) ($item['semi_expendable_type'] ?? '');
                if (!in_array($semiType, ['high_value', 'low_value'], true)) {
                    $semiType = ((float) ($item['unit_cost'] ?? 0) >= $semiHighValueMin) ? 'high_value' : 'low_value';
                }
                $isHighValueSemi = $semiType === 'high_value';
            }
            if ($accepted > 0 && ($isEquipment || $isHighValueSemi)) {
                $requiresPhysicalCheckConfirmation = true;
                $requiresPhysicalCheckLineNos[] = (string) ($item['line_no'] ?? '');
            }

            $detailRows = [];
            foreach ($details as $detail) {
                $noBrandModel = !empty($detail['no_brand_model']);
                $noSerialNo = !empty($detail['no_serial_no']);
                $noRemarks = !empty($detail['no_remarks']);
                if ($noBrandModel) {
                    $detail['brand_id'] = '';
                    $detail['model_id'] = '';
                    $detail['brand'] = '';
                    $detail['model'] = '';
                }
                if ($noSerialNo) {
                    $detail['serial_no'] = '';
                }
                if ($noRemarks) {
                    $detail['remarks'] = '';
                }
                $brandId = (int) ($detail['brand_id'] !== '' ? $detail['brand_id'] : 0);
                $modelId = (int) ($detail['model_id'] !== '' ? $detail['model_id'] : 0);

                if (!$noBrandModel && receiving_tracks_identity((string) $item['item_type']) && $accepted > 0) {
                    if ($brandId <= 0) {
                        $errors[] = 'Brand is required for line ' . $item['line_no'] . ' unless "No brand/model" is checked.';
                        continue 2;
                    }
                }

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

                if ($noBrandModel || $detail['brand_id'] !== '' || $detail['model_id'] !== '' || $detail['brand'] !== '' || $detail['model'] !== '' || $detail['serial_no'] !== '' || $detail['remarks'] !== '') {
                    $detailRows[] = $detail;
                }
            }
            if (receiving_tracks_identity((string) $item['item_type']) && $accepted > 0 && !$detailRows) {
                $expectedDetailRows = max(1, (int) round($accepted));
                for ($detailIndex = 0; $detailIndex < $expectedDetailRows; $detailIndex++) {
                    $detailRows[] = receiving_blank_detail();
                }
            }
            if (receiving_tracks_identity((string) $item['item_type']) && $accepted > 0) {
                $expectedDetailRows = max(1, (int) round($accepted));
                $currentDetailRows = count($detailRows);

                if ($currentDetailRows > $expectedDetailRows) {
                    $detailRows = array_slice($detailRows, 0, $expectedDetailRows);
                } elseif ($currentDetailRows < $expectedDetailRows) {
                    $detailTemplate = $currentDetailRows > 0
                        ? $detailRows[$currentDetailRows - 1]
                        : receiving_blank_detail();

                    for ($detailIndex = $currentDetailRows; $detailIndex < $expectedDetailRows; $detailIndex++) {
                        $detailRows[] = [
                            'brand_id' => (string) ($detailTemplate['brand_id'] ?? ''),
                            'model_id' => (string) ($detailTemplate['model_id'] ?? ''),
                            'brand' => (string) ($detailTemplate['brand'] ?? ''),
                            'model' => (string) ($detailTemplate['model'] ?? ''),
                            'serial_no' => '',
                            'remarks' => (string) ($detailTemplate['remarks'] ?? ''),
                            'no_brand_model' => !empty($detailTemplate['no_brand_model']) ? '1' : '0',
                            'no_serial_no' => !empty($detailTemplate['no_serial_no']) ? '1' : '0',
                            'no_remarks' => !empty($detailTemplate['no_remarks']) ? '1' : '0',
                        ];
                    }
                }
            }

            foreach ($detailRows as $detailRow) {
                $serialNo = trim((string) ($detailRow['serial_no'] ?? ''));
                if ($serialNo === '') {
                    continue;
                }
                $serialKey = strtoupper($serialNo);
                if (isset($postedSerialNumbers[$serialKey])) {
                    $errors[] = 'Duplicate serial number in this receiving: ' . $serialNo . '.';
                    continue 2;
                }
                $postedSerialNumbers[$serialKey] = true;

                $serialStmt = $db->prepare("
                    SELECT source_name FROM (
                        SELECT 'receiving detail' AS source_name FROM receiving_item_details WHERE serial_no = ?
                        UNION ALL
                        SELECT 'distributed asset' AS source_name FROM distribution_item_details WHERE serial_no = ?
                        UNION ALL
                        SELECT 'beginning balance asset' AS source_name FROM legacy_assets WHERE serial_no = ?
                    ) matches
                    LIMIT 1
                ");
                if ($serialStmt) {
                    $serialStmt->bind_param('sss', $serialNo, $serialNo, $serialNo);
                    $serialStmt->execute();
                    $serialExists = $serialStmt->get_result()->fetch_assoc();
                    $serialStmt->close();
                    if ($serialExists) {
                        $errors[] = 'Serial number already exists in ' . $serialExists['source_name'] . ' records: ' . $serialNo . '.';
                        continue 2;
                    }
                }
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

        if ($requiresPhysicalCheckConfirmation && $form['confirm_physical_receipt'] !== '1') {
            $lineSummary = implode(', ', array_filter(array_unique($requiresPhysicalCheckLineNos)));
            add_validation_error($errors, 'Please confirm physical verification before posting accepted equipment/high-value semi-expendable items' . ($lineSummary !== '' ? ' (lines: ' . $lineSummary . ')' : '') . '.');
        }

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
                $headerStmt = $db->prepare("INSERT INTO receivings (system_reference, purchase_order_id, ris_no, received_date, delivery_receipt_no, invoice_no, inspected_by, status, remarks, total_received_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$headerStmt) {
                    throw new RuntimeException('Unable to prepare receiving header insert.');
                }
                $purchaseOrderId = (int) $form['purchase_order_id'];
                $headerStmt->bind_param('sisssssssdi', $systemReference, $purchaseOrderId, $form['ris_no'], $form['received_date'], $form['delivery_receipt_no'], $form['invoice_no'], $form['inspected_by'], $status, $form['remarks'], $totalReceivedAmount, $userId);
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

                // Keep PO status in sync with both delivery completion and distribution completion.
                $poStatus = recalculate_purchase_order_status($db, $purchaseOrderId);
                $poUpdateStmt = $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
                if ($poUpdateStmt) {
                    $poUpdateStmt->bind_param('si', $poStatus, $purchaseOrderId);
                    $poUpdateStmt->execute();
                    $poUpdateStmt->close();
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

                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'receivings',
                    'record_id' => $receivingId,
                    'module_name' => 'receivings',
                    'record_type' => 'receiving',
                    'action_name' => 'post_receiving',
                    'new_values' => [
                        'system_reference' => $systemReference,
                        'purchase_order_id' => $purchaseOrderId,
                        'received_date' => $form['received_date'],
                        'status' => $receivingStatus,
                        'total_received_amount' => $totalReceivedAmount,
                        'item_count' => count($validatedItems),
                    ],
                    'description' => 'Posted receiving transaction.',
                ]);

                $db->commit();
                set_flash('success', 'Receiving record saved successfully.');
                $hasDistributableUnits = false;
                foreach ($validatedItems as $postedItem) {
                    if (in_array((string) ($postedItem['item_type'] ?? ''), ['semi_expendable', 'equipment'], true)
                        && (float) ($postedItem['quantity_accepted'] ?? 0) > 0
                    ) {
                        $hasDistributableUnits = true;
                        break;
                    }
                }

                if ($hasDistributableUnits) {
                    redirect('modules/distributions/index.php?receiving_id=' . $receivingId);
                }

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
        if ($filterStatus === 'rejected') {
            $recWhere[] = 'EXISTS (
                SELECT 1
                FROM receiving_items fri
                WHERE fri.receiving_id = r.id
                  AND COALESCE(fri.quantity_rejected, 0) > 0
            )';
        } else {
            $recWhere[] = 'r.status = ?';
            $recParams[] = $filterStatus;
            $recTypes .= 's';
        }
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
               po.id AS purchase_order_id,
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

if (($_GET['export'] ?? '') === 'csv') {
    stream_csv_download(
        'receiving_records_' . date('Ymd_His') . '.csv',
        ['Reference', 'RIS No.', 'Received Date', 'PO Number', 'Supplier', 'Delivery Receipt No.', 'Status', 'Total Received Amount'],
        $receivings,
        static function (array $receiving): array {
            return [
                $receiving['system_reference'] ?? '',
                $receiving['ris_no'] ?? '',
                $receiving['received_date'] ?? '',
                $receiving['po_number'] ?? '',
                $receiving['supplier_name'] ?? '',
                $receiving['delivery_receipt_no'] ?? '',
                operational_status_label('receiving', (string) ($receiving['status'] ?? '')),
                number_format((float) ($receiving['total_received_amount'] ?? 0), 2, '.', ''),
            ];
        }
    );
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

.receiving-verification-badge {
    display: inline-flex;
    align-items: center;
    margin-left: 8px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: #842029;
    background: #f8d7da;
    border: 1px solid #f5c2c7;
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

.receiving-workspace > * {
    min-width: 0;
}

.receiving-workspace > div:last-child {
    min-width: 0;
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

.receiving-items-table {
    min-width: 1320px;
}

.receiving-detail-panel {
    overflow-x: auto;
}

.receiving-detail-row.is-no-brand-model .receiving-brand-model-group {
    display: none;
}

.receiving-detail-row.is-no-serial-no .receiving-detail-serial-group {
    display: none;
}

.receiving-detail-row.is-no-remarks .receiving-detail-remarks-group {
    display: none;
}

.receiving-detail-row.is-no-brand-model .receiving-detail-serial-group {
    flex: 0 0 50%;
    max-width: 50%;
}

.receiving-detail-row.is-no-brand-model .receiving-detail-remarks-group {
    flex: 0 0 33.333333%;
    max-width: 33.333333%;
}

.receiving-detail-row.is-no-brand-model .receiving-detail-actions-group {
    flex: 0 0 16.666667%;
    max-width: 16.666667%;
}

.receiving-detail-row.is-no-brand-model.is-no-serial-no .receiving-detail-remarks-group {
    flex: 0 0 83.333333%;
    max-width: 83.333333%;
}

.receiving-detail-row.is-no-brand-model.is-no-remarks .receiving-detail-serial-group {
    flex: 0 0 83.333333%;
    max-width: 83.333333%;
}

.receiving-detail-row.is-no-brand-model.is-no-serial-no.is-no-remarks .receiving-detail-actions-group {
    flex: 0 0 100%;
    max-width: 100%;
}

.receiving-detail-compact-summary {
    display: none;
}

.receiving-detail-panel.is-compact .receiving-detail-rows {
    display: none;
}

.receiving-detail-panel.is-compact .receiving-detail-compact-summary {
    display: block;
}

@media (max-width: 991.98px) {
    .receiving-detail-row.is-no-brand-model .receiving-detail-serial-group,
    .receiving-detail-row.is-no-brand-model .receiving-detail-remarks-group,
    .receiving-detail-row.is-no-brand-model .receiving-detail-actions-group {
        flex: 0 0 100%;
        max-width: 100%;
    }
}

.receiving-workspace .table-responsive.mobile-table-frame {
    display: block;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    width: 100%;
    -webkit-overflow-scrolling: touch;
}

.receiving-po-overview {
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
    border: 1px solid rgba(95, 111, 137, 0.14);
    border-radius: 1rem;
    padding: 1rem;
}

.receiving-po-overview-grid {
    display: grid;
    gap: 0.9rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.receiving-po-overview-item {
    background: #ffffff;
    border: 1px solid rgba(95, 111, 137, 0.14);
    border-radius: 0.9rem;
    min-width: 0;
    padding: 0.9rem 1rem;
}

.receiving-po-overview-item.is-wide {
    grid-column: span 2;
}

.receiving-po-overview-label {
    color: var(--bs-secondary-color);
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    margin-bottom: 0.3rem;
    text-transform: uppercase;
}

.receiving-po-overview-value {
    color: var(--bs-body-color);
    font-size: 0.98rem;
    font-weight: 600;
    line-height: 1.45;
    overflow-wrap: anywhere;
    word-break: normal;
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
    .receiving-po-overview-grid,
    .receiving-review-grid,
    .receiving-workspace {
        grid-template-columns: 1fr;
    }

    .receiving-po-overview-item.is-wide {
        grid-column: span 1;
    }
}

@media (min-width: 992px) and (max-width: 1199.98px) {
    .receiving-po-overview-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
<section class="row g-4 page-section">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="workspace-header mb-3">
                    <div class="workspace-header-copy">
                        <p class="page-kicker mb-1">Supply Operations</p>
                        <h5 class="page-title mb-1">Encode Receiving</h5>
                        <p class="text-muted mb-0">Capture deliveries, inspect accepted quantities, and prepare IAR output from a receiving workspace that stays usable on smaller screens.</p>
                    </div>
                </div>
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

                                                    <div class="workspace-actions mb-2">
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
                                                        <div class="table-responsive mobile-table-frame" style="max-height:240px;overflow-y:auto;">
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
                                        <div class="workspace-header mb-3 p-3 rounded-3" style="background:var(--bs-secondary-bg); font-size:12px;">
                                            <div class="workspace-header-copy">
                                                <span class="fw-semibold"><?php echo h($selectedPurchaseOrder['po_number']); ?></span>
                                                <span class="text-muted ms-2"><?php echo h($selectedPurchaseOrder['supplier_name']); ?></span>
                                                <span class="text-muted ms-2"><?php echo h(date('M d, Y', strtotime($selectedPurchaseOrder['po_date']))); ?></span>
                                            </div>
                                            <div class="workspace-actions">
                                                <a href="<?php echo base_url('modules/receivings/index.php'); ?>" class="btn btn-sm btn-outline-secondary">← Change PO</a>
                                            </div>
                                        </div>
                                        <div class="alert alert-info mb-4">
                                            <div class="fw-semibold">Workflow cue</div>
                                            <div class="small">After saving this receiving, print the IAR. If equipment or semi-expendable units were accepted, continue to Distribution for PAR or ICS posting. If supplies were accepted, review RIS and stock cards.</div>
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
                                                <div class="receiving-step-title">Review & Save <span id="receivingVerificationBadge" class="receiving-verification-badge" style="display:none;">Verification Required</span></div>
                                                <div class="receiving-step-copy">Finish the review and post the batch.</div>
                                            </button>
                                        </div>
                                        <div class="receiving-po-overview mb-4" id="receivingPoOverview">
                                                <div class="receiving-po-overview-grid">
                                                        <div class="receiving-po-overview-item">
                                                            <div class="receiving-po-overview-label">PO Number</div>
                                                            <div class="receiving-po-overview-value"><?php echo h($selectedPurchaseOrder['po_number']); ?></div>
                                                        </div>
                                                        <div class="receiving-po-overview-item">
                                                            <div class="receiving-po-overview-label">PO Date</div>
                                                            <div class="receiving-po-overview-value"><?php echo h(date('M d, Y', strtotime($selectedPurchaseOrder['po_date']))); ?></div>
                                                        </div>
                                                        <div class="receiving-po-overview-item">
                                                            <div class="receiving-po-overview-label">Supplier</div>
                                                            <div class="receiving-po-overview-value"><?php echo h($selectedPurchaseOrder['supplier_name']); ?></div>
                                                        </div>
                                                        <div class="receiving-po-overview-item">
                                                            <div class="receiving-po-overview-label">Fund</div>
                                                            <div class="receiving-po-overview-value"><?php echo h($selectedPurchaseOrder['fund_code']); ?></div>
                                                        </div>
                                                        <div class="receiving-po-overview-item is-wide">
                                                            <div class="receiving-po-overview-label">Supplier Address</div>
                                                            <div class="receiving-po-overview-value"><?php echo h($selectedPurchaseOrder['supplier_address'] ?: ''); ?></div>
                                                        </div>
                                                        <div class="receiving-po-overview-item">
                                                            <div class="receiving-po-overview-label">Mode of Procurement</div>
                                                            <div class="receiving-po-overview-value"><?php echo h($selectedPurchaseOrder['mode_name'] ?: ''); ?></div>
                                                        </div>
                                                        <div class="receiving-po-overview-item">
                                                            <div class="receiving-po-overview-label">Place of Delivery</div>
                                                            <div class="receiving-po-overview-value"><?php echo h($selectedPurchaseOrder['place_of_delivery'] ?: ''); ?></div>
                                                        </div>
                                                        <div class="receiving-po-overview-item">
                                                            <div class="receiving-po-overview-label">Total Lines</div>
                                                            <div class="receiving-po-overview-value"><?php echo count($receivingItems); ?> item(s)</div>
                                                        </div>
                                                        <div class="receiving-po-overview-item">
                                                            <div class="receiving-po-overview-label">Remaining Items</div>
                                                            <div class="receiving-po-overview-value text-warning"><?php echo count(array_filter($receivingItems, function($i) { return (float)$i['remaining_quantity'] > 0; })); ?> item(s)</div>
                                                        </div>
                                                </div>
                                        </div>

                                        <form method="post" id="receivingForm" data-semi-hv-min="<?php echo h(number_format($semiHighValueMin, 2, '.', '')); ?>">
                            <input type="hidden" name="action" value="save">
                            <?php echo '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; ?>
                            <input type="hidden" name="purchase_order_id" value="<?php echo (int) $selectedPurchaseOrder['id']; ?>">
                        <div class="row g-3 mb-4 workspace-filter-panel" id="receivingHeaderSection">
                            <div class="col-12">
                                <div id="receiving_form_feedback" class="alert alert-danger small py-2 px-3 mb-0 d-none" role="alert" aria-live="polite"></div>
                            </div>
                            <div class="col-md-3"><label class="form-label">System Reference</label><input type="text" class="form-control" value="<?php echo h($form['system_reference']); ?>" readonly></div>
                            <div class="col-md-3"><label for="ris_no" class="form-label">RIS Number</label><input type="text" class="form-control" id="ris_no" name="ris_no" value="<?php echo h($form['ris_no']); ?>" readonly><div class="form-text">Generated as `RIS-YEAR-MONTH-SERIES`.</div></div>
                            <div class="col-md-3"><label for="received_date" class="form-label">Received Date <span class="text-danger">*</span></label><input type="date" class="form-control" id="received_date" name="received_date" value="<?php echo h($form['received_date']); ?>" required><div id="received_date_feedback" class="small text-danger mt-1 d-none">Received date is required.</div></div>
                            <div class="col-md-3"><label for="delivery_receipt_no" class="form-label">Delivery Receipt No.</label><input type="text" class="form-control" id="delivery_receipt_no" name="delivery_receipt_no" value="<?php echo h($form['delivery_receipt_no']); ?>"></div>
                            <div class="col-md-3"><label for="invoice_no" class="form-label">Invoice No.</label><input type="text" class="form-control" id="invoice_no" name="invoice_no" value="<?php echo h($form['invoice_no']); ?>"></div>
                            <div class="col-md-3"><label for="inspected_by" class="form-label">Inspected By</label><input type="text" class="form-control" id="inspected_by" name="inspected_by" value="<?php echo h($form['inspected_by']); ?>" placeholder="Inspection officer / committee"></div>
                            <div class="col-12"><label for="remarks" class="form-label">Receiving Remarks</label><textarea class="form-control" id="remarks" name="remarks" rows="2"><?php echo h($form['remarks']); ?></textarea></div>
                        </div>

                        <div class="receiving-summary-strip mb-3 workspace-form-section">
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
                                                <span>Rem: <?php echo h(format_quantity($item['remaining_quantity'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </aside>

                            <div>
                        <div class="workspace-header mb-3">
                            <div class="workspace-header-copy">
                                <div class="small text-muted">Use the compact grid below for fast encoding. Expand details only for semi-expendable and equipment lines.</div>
                            </div>
                            <div class="workspace-actions workspace-toolbar-cluster">
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn active" data-filter="all">All</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn" data-filter="supply">Supplies</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn" data-filter="semi_expendable">Semi</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn" data-filter="equipment">Equipment</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary receiving-filter-btn" data-filter="remaining">Remaining Only</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="receivingPrevLineBtn">Previous</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="receivingNextLineBtn">Next</button>
                            </div>
                        </div>

                        <div class="table-responsive mobile-table-frame">
                            <table class="table table-sm align-middle receiving-items-table" data-no-table-search>
                                <thead>
                                    <tr>
                                        <th style="width: 48px;">Line</th>
                                        <th style="min-width: 280px;">Item</th>
                                        <th style="width: 110px;">Type</th>
                                        <th class="text-end" style="width: 90px;">Ordered</th>
                                        <th class="text-end" style="width: 90px;">Received</th>
                                        <th class="text-end" style="width: 90px;">Remaining</th>
                                        <th style="width: 120px;">Delivered</th>
                                        <th style="width: 120px;">Accepted<br><span class="small text-danger">Verify physically</span></th>
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
                                        <tr class="receiving-line-row <?php echo $itemId === (int) ($receivingItems[0]['id'] ?? 0) ? 'table-primary' : ''; ?>" data-line-id="<?php echo $itemId; ?>" data-line-no="<?php echo (int) $item['line_no']; ?>" data-unit-cost="<?php echo h(number_format((float) $item['unit_cost'], 2, '.', '')); ?>" data-item-type="<?php echo h($item['item_type']); ?>" data-semi-type="<?php echo h((string) ($item['semi_expendable_type'] ?? '')); ?>" data-has-remaining="<?php echo (float) $item['remaining_quantity'] > 0 ? '1' : '0'; ?>">
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
                                            <td class="text-end"><?php echo h(format_quantity($item['quantity'])); ?></td>
                                            <td class="text-end"><?php echo h(format_quantity($item['quantity_already_received'])); ?></td>
                                            <td class="text-end fw-semibold"><?php echo h(format_quantity($item['remaining_quantity'])); ?></td>
                                            <td><input type="number" class="form-control form-control-sm receiving-deliver-input" step="1" min="0" max="<?php echo h((string) floor((float) $item['remaining_quantity'])); ?>" name="items[<?php echo $itemId; ?>][deliver_quantity]" value="<?php echo h((string) round((float) $item['deliver_quantity'])); ?>"></td>
                                            <td><input type="number" class="form-control form-control-sm receiving-accept-input" step="1" min="0" max="<?php echo h((string) floor((float) $item['remaining_quantity'])); ?>" name="items[<?php echo $itemId; ?>][accept_quantity]" value="<?php echo h((string) round((float) $item['accept_quantity'])); ?>"></td>
                                            <td><input type="number" class="form-control form-control-sm" step="1" min="0" max="<?php echo h((string) floor((float) $item['remaining_quantity'])); ?>" name="items[<?php echo $itemId; ?>][reject_quantity]" value="<?php echo h((string) round((float) $item['reject_quantity'])); ?>"></td>
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
                                                    <div class="border rounded-3 p-3 my-2 receiving-detail-panel">
                                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                            <div>
                                                                <div class="fw-semibold">Brand / Model / Serial Details</div>
                                                                <div class="small text-muted">Add one detail row per accepted item. Brand is required unless you check "No brand/model". Model, serial number, and remarks are optional.</div>
                                                            </div>
                                                            <div class="d-flex align-items-center flex-wrap gap-3">
                                                                <div class="form-check m-0">
                                                                    <input class="form-check-input receiving-bulk-no-brand-model" type="checkbox" id="bulk-no-brand-model-<?php echo $itemId; ?>" name="items[<?php echo $itemId; ?>][bulk_no_brand_model]" value="1" data-item-id="<?php echo $itemId; ?>" <?php echo !empty($item['bulk_no_brand_model']) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label small" for="bulk-no-brand-model-<?php echo $itemId; ?>">Check all as no brand/model</label>
                                                                </div>
                                                                <div class="form-check m-0">
                                                                    <input class="form-check-input receiving-bulk-no-serial-no" type="checkbox" id="bulk-no-serial-no-<?php echo $itemId; ?>" name="items[<?php echo $itemId; ?>][bulk_no_serial_no]" value="1" data-item-id="<?php echo $itemId; ?>" <?php echo !empty($item['bulk_no_serial_no']) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label small" for="bulk-no-serial-no-<?php echo $itemId; ?>">Hide all serial no.</label>
                                                                </div>
                                                                <div class="form-check m-0">
                                                                    <input class="form-check-input receiving-bulk-no-remarks" type="checkbox" id="bulk-no-remarks-<?php echo $itemId; ?>" name="items[<?php echo $itemId; ?>][bulk_no_remarks]" value="1" data-item-id="<?php echo $itemId; ?>" <?php echo !empty($item['bulk_no_remarks']) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label small" for="bulk-no-remarks-<?php echo $itemId; ?>">Hide all remarks</label>
                                                                </div>
                                                                <div class="small text-muted detail-row-status" data-item-id="<?php echo $itemId; ?>">0 detail row(s)</div>
                                                            </div>
                                                        </div>
                                                        <div class="row g-2 align-items-end mb-3 receiving-bulk-apply-panel" data-item-id="<?php echo $itemId; ?>">
                                                            <div class="col-12 col-lg-3">
                                                                <label class="form-label">Apply Brand to All</label>
                                                                <select class="form-select receiving-bulk-brand-select" data-item-id="<?php echo $itemId; ?>" data-placeholder="Select brand">
                                                                    <option value="">Select brand</option>
                                                                    <?php foreach ($brands as $brand): ?>
                                                                        <option value="<?php echo (int) $brand['id']; ?>"><?php echo h($brand['brand_name']); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-12 col-lg-3">
                                                                <label class="form-label">Apply Model to All</label>
                                                                <select class="form-select receiving-bulk-model-select" data-item-id="<?php echo $itemId; ?>" data-placeholder="Select model">
                                                                    <option value="">Select model</option>
                                                                </select>
                                                            </div>
                                                            <div class="col-12 col-lg-2">
                                                                <button type="button" class="btn btn-outline-primary w-100 receiving-apply-brand-model-btn" data-item-id="<?php echo $itemId; ?>">Apply brand/model</button>
                                                            </div>
                                                            <div class="col-12 col-lg-3">
                                                                <label class="form-label">Apply Remarks to All</label>
                                                                <input type="text" class="form-control receiving-bulk-remarks-input" data-item-id="<?php echo $itemId; ?>" placeholder="Common remarks">
                                                            </div>
                                                            <div class="col-12 col-lg-1">
                                                                <button type="button" class="btn btn-outline-secondary w-100 receiving-apply-remarks-btn" data-item-id="<?php echo $itemId; ?>">Apply</button>
                                                            </div>
                                                        </div>
                                                        <div class="receiving-detail-rows" data-item-id="<?php echo $itemId; ?>">
                                                            <?php foreach ($item['detail_rows'] as $detailIndex => $detail): ?>
                                                                <div class="row g-2 align-items-end receiving-detail-row mb-2 <?php echo !empty($detail['no_brand_model']) ? 'is-no-brand-model' : ''; ?> <?php echo !empty($detail['no_serial_no']) ? 'is-no-serial-no' : ''; ?> <?php echo !empty($detail['no_remarks']) ? 'is-no-remarks' : ''; ?>">
                                                                    <div class="col-12 col-lg-3 receiving-brand-model-group"><label class="form-label">Brand</label><select class="form-select receiving-brand-select" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][brand_id]" data-placeholder="Select brand" <?php echo !empty($detail['no_brand_model']) ? 'disabled' : ''; ?>><option value="">Select brand</option><?php foreach ($brands as $brand): ?><option value="<?php echo (int) $brand['id']; ?>" <?php echo $detail['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>><?php echo h($brand['brand_name']); ?></option><?php endforeach; ?></select><input type="hidden" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][brand]" value="<?php echo h($detail['brand']); ?>"></div>
                                                                    <div class="col-12 col-lg-3 receiving-brand-model-group"><label class="form-label">Model</label><select class="form-select receiving-model-select" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][model_id]" data-placeholder="Select model" <?php echo !empty($detail['no_brand_model']) ? 'disabled' : ''; ?>><option value="">Select model</option><?php foreach ($models as $model): ?><option value="<?php echo (int) $model['id']; ?>" data-brand-id="<?php echo (int) $model['brand_id']; ?>" <?php echo $detail['model_id'] === (string) $model['id'] ? 'selected' : ''; ?>><?php echo h($model['model_name']); ?></option><?php endforeach; ?></select><input type="hidden" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][model]" value="<?php echo h($detail['model']); ?>"></div>
                                                                    <div class="col-12 col-lg-3 receiving-detail-serial-group"><label class="form-label">Serial Number</label><input type="text" class="form-control" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][serial_no]" value="<?php echo h($detail['serial_no']); ?>"></div>
                                                                    <div class="col-12 col-lg-2 receiving-detail-remarks-group"><label class="form-label">Remarks</label><input type="text" class="form-control" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][remarks]" value="<?php echo h($detail['remarks']); ?>"></div>
                                                                    <div class="col-12 col-lg-1 receiving-detail-actions-group">
                                                                        <div class="form-check mb-2">
                                                                            <input class="form-check-input receiving-no-brand-model" type="checkbox" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][no_brand_model]" value="1" <?php echo !empty($detail['no_brand_model']) ? 'checked' : ''; ?>>
                                                                            <label class="form-check-label small">No brand/model</label>
                                                                        </div>
                                                                        <div class="form-check mb-2">
                                                                            <input class="form-check-input receiving-no-serial-no" type="checkbox" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][no_serial_no]" value="1" <?php echo !empty($detail['no_serial_no']) ? 'checked' : ''; ?>>
                                                                            <label class="form-check-label small">No serial no.</label>
                                                                        </div>
                                                                        <div class="form-check mb-2">
                                                                            <input class="form-check-input receiving-no-remarks" type="checkbox" name="items[<?php echo $itemId; ?>][details][<?php echo $detailIndex; ?>][no_remarks]" value="1" <?php echo !empty($detail['no_remarks']) ? 'checked' : ''; ?>>
                                                                            <label class="form-check-label small">No remarks</label>
                                                                        </div>
                                                                        <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-detail-row">Remove</button>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <div class="alert alert-secondary py-2 px-3 mb-0 receiving-detail-compact-summary" data-item-id="<?php echo $itemId; ?>">
                                                            Compact mode is active. Detail rows are hidden for this line.
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

                        <div class="alert alert-warning py-2 px-3 mb-3 small">
                            Accepted quantity creates stock and asset records. Set accepted to 0 for items not physically received.
                        </div>
                        <div class="form-check mb-3" id="receivingPhysicalConfirmWrap">
                            <input class="form-check-input" type="checkbox" value="1" id="confirm_physical_receipt" name="confirm_physical_receipt" <?php echo $form['confirm_physical_receipt'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="confirm_physical_receipt">
                                I confirm all accepted equipment and high-value semi-expendable items were physically verified.
                            </label>
                            <div class="form-text" id="receivingPhysicalConfirmHint">Required only when accepted qty exists for equipment/high-value semi-expendable lines.</div>
                            <div class="small text-danger mt-1 d-none" id="confirm_physical_receipt_feedback">Please confirm physical verification before saving.</div>
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
                <div class="workspace-header mb-3">
                    <div class="workspace-header-copy">
                        <h5 class="card-title mb-0">Receiving Records</h5>
                    </div>
                    <div class="workspace-actions">
                        <a href="<?php echo h(base_url('modules/receivings/index.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))); ?>" class="btn btn-outline-success btn-sm">Export CSV</a>
                        <span class="badge text-bg-light"><?php echo count($receivings); ?> record(s)</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="small text-muted fw-semibold">Quick filters:</span>
                    <a href="<?php echo base_url('modules/receivings/index.php?filter_status=partial'); ?>" class="btn btn-sm <?php echo $filterStatus === 'partial' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Partial</a>
                    <a href="<?php echo base_url('modules/receivings/index.php?filter_status=completed'); ?>" class="btn btn-sm <?php echo $filterStatus === 'completed' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Completed</a>
                    <a href="<?php echo base_url('modules/receivings/index.php?filter_status=rejected'); ?>" class="btn btn-sm <?php echo $filterStatus === 'rejected' ? 'btn-primary' : 'btn-outline-secondary'; ?>">With Rejected Items</a>
                    <a href="<?php echo base_url('modules/receivings/index.php?filter_status=cancelled'); ?>" class="btn btn-sm <?php echo $filterStatus === 'cancelled' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Cancelled</a>
                </div>

                <form method="get" class="row g-2 align-items-end mb-3 workspace-filter-panel">
                    <div class="col-md-5">
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search PO number, supplier, or reference..." value="<?php echo h($filterPoNumber); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="filter_status" class="form-select form-select-sm" data-no-select2>
                            <option value="">All statuses</option>
                            <option value="partial"   <?php echo $filterStatus==='partial'   ?'selected':'' ?>>Partial</option>
                            <option value="completed" <?php echo $filterStatus==='completed' ?'selected':'' ?>>Completed</option>
                            <option value="rejected"  <?php echo $filterStatus==='rejected'  ?'selected':'' ?>>With Rejected Items</option>
                            <option value="cancelled" <?php echo $filterStatus==='cancelled' ?'selected':'' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-auto">
                        <div class="d-grid gap-2 d-sm-flex">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                            <a href="<?php echo base_url('modules/receivings/index.php'); ?>" class="btn btn-link btn-sm">Clear</a>
                        </div>
                    </div>
                </form>

                <div class="table-responsive mobile-table-frame">
                    <table class="table align-middle">
                        <thead><tr><th data-sort="ref">Reference <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="ris">RIS No. <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="date">Received Date <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="po">PO Number <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="supplier">Supplier <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="dr">DR No. <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="text-end" data-sort="amount">Amount <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="text-end">Actions</th></tr></thead>
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
                                    <td class="text-end"><?php if (in_array($receiving['status'], ['completed', 'partial'], true)): ?><a href="<?php echo base_url('modules/receivings/iar.php?id=' . (int) $receiving['id']); ?>" class="btn btn-sm btn-outline-primary me-1" target="_blank">Print IAR</a><a href="<?php echo base_url('modules/receivings/iar_po.php?po_id=' . (int) $receiving['purchase_order_id']); ?>" class="btn btn-sm btn-outline-secondary me-1" target="_blank">Final IAR by PO</a><a href="<?php echo base_url('modules/receivings/correct_receiving.php?id=' . (int) $receiving['id']); ?>" class="btn btn-sm btn-outline-warning">Correct</a><?php else: ?><span class="text-muted small">No items received yet</span><?php endif; ?></td>
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

    function selectedOptionText(select) {
        if (!select || !select.options || select.selectedIndex < 0) {
            return '';
        }
        return select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
    }

    function initReceivingSelect2(select) {
        if (!select || !window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }
        var $select = window.jQuery(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
        }
        $select.select2({
            width: '100%',
            placeholder: select.getAttribute('data-placeholder') || 'Select an option',
            allowClear: true,
            dropdownParent: window.jQuery(select.parentElement || document.body)
        });
    }

    function syncModelOptions(row) {
        var brandSelect = row.querySelector('.receiving-brand-select');
        var modelSelect = row.querySelector('.receiving-model-select');
        var noBrandModelCheckbox = row.querySelector('.receiving-no-brand-model');
        var hiddenModel = row.querySelector('input[name$="[model]"]');
        var hiddenBrand = row.querySelector('input[name$="[brand]"]');
        var noBrandModel = !!(noBrandModelCheckbox && noBrandModelCheckbox.checked);
        var currentModelValue = modelSelect ? modelSelect.value : '';
        var selectedBrand = brandSelect ? brandSelect.value : '';
        if (!modelSelect) return;

        if (noBrandModel) {
            row.classList.add('is-no-brand-model');
            if (brandSelect) {
                brandSelect.value = '';
                brandSelect.disabled = true;
            }
            modelSelect.innerHTML = modelOptions('', '');
            modelSelect.value = '';
            modelSelect.disabled = true;
            if (hiddenBrand) hiddenBrand.value = '';
            if (hiddenModel) hiddenModel.value = '';
            return;
        }

        row.classList.remove('is-no-brand-model');
        if (brandSelect) {
            brandSelect.disabled = false;
        }
        var matchingModelExists = !selectedBrand || models.some(function (model) {
            return String(model.id) === String(currentModelValue) && String(model.brand_id) === String(selectedBrand);
        });
        if (!matchingModelExists) {
            currentModelValue = '';
            if (hiddenModel) hiddenModel.value = '';
        }

        modelSelect.innerHTML = modelOptions(currentModelValue, selectedBrand);
        modelSelect.disabled = false;
        initReceivingSelect2(modelSelect);
    }

    function syncOptionalFieldVisibility(row) {
        var noSerialNoCheckbox = row.querySelector('.receiving-no-serial-no');
        var noRemarksCheckbox = row.querySelector('.receiving-no-remarks');
        var serialInput = row.querySelector('input[name$="[serial_no]"]');
        var remarksInput = row.querySelector('input[name$="[remarks]"]');

        if (noSerialNoCheckbox && noSerialNoCheckbox.checked) {
            row.classList.add('is-no-serial-no');
            if (serialInput) {
                serialInput.value = '';
            }
        } else {
            row.classList.remove('is-no-serial-no');
        }

        if (noRemarksCheckbox && noRemarksCheckbox.checked) {
            row.classList.add('is-no-remarks');
            if (remarksInput) {
                remarksInput.value = '';
            }
        } else {
            row.classList.remove('is-no-remarks');
        }
    }

    function syncBulkModelOptions(itemId, selectedModelId) {
        var brandSelect = document.querySelector('.receiving-bulk-brand-select[data-item-id="' + itemId + '"]');
        var modelSelect = document.querySelector('.receiving-bulk-model-select[data-item-id="' + itemId + '"]');
        if (!brandSelect || !modelSelect) return;
        var brandId = brandSelect.value || '';
        modelSelect.innerHTML = modelOptions(selectedModelId || '', brandId);
        initReceivingSelect2(modelSelect);
    }

    function rowMarkup(itemId, index) {
        var bulkNoBrandCheckbox = document.querySelector('.receiving-bulk-no-brand-model[data-item-id="' + itemId + '"]');
        var bulkNoSerialCheckbox = document.querySelector('.receiving-bulk-no-serial-no[data-item-id="' + itemId + '"]');
        var bulkNoRemarksCheckbox = document.querySelector('.receiving-bulk-no-remarks[data-item-id="' + itemId + '"]');
        var noBrandChecked = !!(bulkNoBrandCheckbox && bulkNoBrandCheckbox.checked);
        var noSerialChecked = !!(bulkNoSerialCheckbox && bulkNoSerialCheckbox.checked);
        var noRemarksChecked = !!(bulkNoRemarksCheckbox && bulkNoRemarksCheckbox.checked);
        return '<div class="row g-2 align-items-end receiving-detail-row mb-2' + (noBrandChecked ? ' is-no-brand-model' : '') + (noSerialChecked ? ' is-no-serial-no' : '') + (noRemarksChecked ? ' is-no-remarks' : '') + '">' +
            '<div class="col-12 col-lg-3 receiving-brand-model-group"><label class="form-label">Brand</label><select class="form-select receiving-brand-select" name="items[' + itemId + '][details][' + index + '][brand_id]" data-placeholder="Select brand" data-no-select2' + (noBrandChecked ? ' disabled' : '') + '>' + brandOptions('') + '</select><input type="hidden" name="items[' + itemId + '][details][' + index + '][brand]" value=""></div>' +
            '<div class="col-12 col-lg-3 receiving-brand-model-group"><label class="form-label">Model</label><select class="form-select receiving-model-select" name="items[' + itemId + '][details][' + index + '][model_id]" data-placeholder="Select model" data-no-select2' + (noBrandChecked ? ' disabled' : '') + '>' + modelOptions('', '') + '</select><input type="hidden" name="items[' + itemId + '][details][' + index + '][model]" value=""></div>' +
            '<div class="col-12 col-lg-3 receiving-detail-serial-group"><label class="form-label">Serial Number</label><input type="text" class="form-control" name="items[' + itemId + '][details][' + index + '][serial_no]"' + (noSerialChecked ? ' value=""' : '') + '></div>' +
            '<div class="col-12 col-lg-2 receiving-detail-remarks-group"><label class="form-label">Remarks</label><input type="text" class="form-control" name="items[' + itemId + '][details][' + index + '][remarks]"' + (noRemarksChecked ? ' value=""' : '') + '></div>' +
            '<div class="col-12 col-lg-1 receiving-detail-actions-group"><div class="form-check mb-2"><input class="form-check-input receiving-no-brand-model" type="checkbox" name="items[' + itemId + '][details][' + index + '][no_brand_model]" value="1"' + (noBrandChecked ? ' checked' : '') + '><label class="form-check-label small">No brand/model</label></div><div class="form-check mb-2"><input class="form-check-input receiving-no-serial-no" type="checkbox" name="items[' + itemId + '][details][' + index + '][no_serial_no]" value="1"' + (noSerialChecked ? ' checked' : '') + '><label class="form-check-label small">No serial no.</label></div><div class="form-check mb-2"><input class="form-check-input receiving-no-remarks" type="checkbox" name="items[' + itemId + '][details][' + index + '][no_remarks]" value="1"' + (noRemarksChecked ? ' checked' : '') + '><label class="form-check-label small">No remarks</label></div><button type="button" class="btn btn-outline-danger btn-sm w-100 remove-detail-row">Remove</button></div>' +
        '</div>';
    }

    function syncBulkNoBrandModel(itemId) {
        var container = document.querySelector('.receiving-detail-rows[data-item-id="' + itemId + '"]');
        var bulkCheckbox = document.querySelector('.receiving-bulk-no-brand-model[data-item-id="' + itemId + '"]');
        if (!container || !bulkCheckbox) return;
        var detailCheckboxes = container.querySelectorAll('.receiving-no-brand-model');
        if (detailCheckboxes.length === 0) {
            bulkCheckbox.checked = false;
            bulkCheckbox.indeterminate = false;
            return;
        }
        var checkedCount = Array.from(detailCheckboxes).filter(function (checkbox) {
            return checkbox.checked;
        }).length;
        bulkCheckbox.checked = checkedCount === detailCheckboxes.length;
        bulkCheckbox.indeterminate = checkedCount > 0 && checkedCount < detailCheckboxes.length;
    }

    function applyBulkNoBrandModel(itemId, checked) {
        var container = document.querySelector('.receiving-detail-rows[data-item-id="' + itemId + '"]');
        if (!container) return;
        container.querySelectorAll('.receiving-detail-row').forEach(function (detailRow) {
            var checkbox = detailRow.querySelector('.receiving-no-brand-model');
            if (checkbox) {
                checkbox.checked = checked;
            }
            syncModelOptions(detailRow);
        });
        syncBulkNoBrandModel(itemId);
    }

    function syncBulkCheckboxState(itemId, rowSelector, bulkSelector) {
        var container = document.querySelector('.receiving-detail-rows[data-item-id="' + itemId + '"]');
        var bulkCheckbox = document.querySelector(bulkSelector + '[data-item-id="' + itemId + '"]');
        if (!container || !bulkCheckbox) return;
        var detailCheckboxes = container.querySelectorAll(rowSelector);
        if (detailCheckboxes.length === 0) {
            bulkCheckbox.checked = false;
            bulkCheckbox.indeterminate = false;
            return;
        }
        var checkedCount = Array.from(detailCheckboxes).filter(function (checkbox) {
            return checkbox.checked;
        }).length;
        bulkCheckbox.checked = checkedCount === detailCheckboxes.length;
        bulkCheckbox.indeterminate = checkedCount > 0 && checkedCount < detailCheckboxes.length;
    }

    function syncCompactDetailPanel(itemId) {
        var panel = document.querySelector('.receiving-detail-rows[data-item-id="' + itemId + '"]');
        var summary = document.querySelector('.receiving-detail-compact-summary[data-item-id="' + itemId + '"]');
        if (!panel || !summary) return;
        var wrapper = panel.closest('.receiving-detail-panel');
        var bulkNoBrand = document.querySelector('.receiving-bulk-no-brand-model[data-item-id="' + itemId + '"]');
        var bulkNoSerial = document.querySelector('.receiving-bulk-no-serial-no[data-item-id="' + itemId + '"]');
        var bulkNoRemarks = document.querySelector('.receiving-bulk-no-remarks[data-item-id="' + itemId + '"]');
        var acceptInput = document.querySelector('.receiving-accept-input[name="items[' + itemId + '][accept_quantity]"]');
        var acceptedCount = acceptInput ? Math.max(0, Math.round(parseNum(acceptInput.value || 0))) : 0;
        var isCompact = !!(bulkNoBrand && bulkNoBrand.checked && bulkNoSerial && bulkNoSerial.checked && bulkNoRemarks && bulkNoRemarks.checked);
        if (wrapper) {
            wrapper.classList.toggle('is-compact', isCompact);
        }
        summary.textContent = acceptedCount > 0
            ? ('Compact mode is active. ' + acceptedCount + ' unit(s) will be saved with no brand/model, no serial number, and no remarks.')
            : 'Compact mode is active. Detail rows are hidden for this line.';
    }

    function applyBulkToggle(itemId, rowSelector, bulkSelector, syncCallback) {
        var container = document.querySelector('.receiving-detail-rows[data-item-id="' + itemId + '"]');
        var bulkCheckbox = document.querySelector(bulkSelector + '[data-item-id="' + itemId + '"]');
        if (!container || !bulkCheckbox) return;
        container.querySelectorAll('.receiving-detail-row').forEach(function (detailRow) {
            var checkbox = detailRow.querySelector(rowSelector);
            if (checkbox) {
                checkbox.checked = bulkCheckbox.checked;
            }
            syncCallback(detailRow);
        });
        syncBulkCheckboxState(itemId, rowSelector, bulkSelector);
        syncCompactDetailPanel(itemId);
    }

    function applyBrandModelToAll(itemId) {
        var container = document.querySelector('.receiving-detail-rows[data-item-id="' + itemId + '"]');
        var bulkBrandSelect = document.querySelector('.receiving-bulk-brand-select[data-item-id="' + itemId + '"]');
        var bulkModelSelect = document.querySelector('.receiving-bulk-model-select[data-item-id="' + itemId + '"]');
        if (!container || !bulkBrandSelect || !bulkModelSelect) return;

        var brandId = bulkBrandSelect.value || '';
        var modelId = bulkModelSelect.value || '';
        if (!brandId && !modelId) return;

        if (!brandId && modelId) {
            var selectedModel = models.find(function (model) {
                return String(model.id) === String(modelId);
            });
            if (selectedModel) {
                brandId = String(selectedModel.brand_id || '');
                bulkBrandSelect.value = brandId;
                syncBulkModelOptions(itemId, modelId);
            }
        }

        var brandText = selectedOptionText(bulkBrandSelect);
        var modelText = selectedOptionText(bulkModelSelect);

        function setSelectValue(select, value) {
            if (!select) return;
            select.value = value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(select).trigger('change.select2');
            }
        }

        container.querySelectorAll('.receiving-detail-row').forEach(function (detailRow) {
            var noBrandCheckbox = detailRow.querySelector('.receiving-no-brand-model');
            if (noBrandCheckbox) {
                noBrandCheckbox.checked = false;
            }

            syncModelOptions(detailRow);

            var brandSelect = detailRow.querySelector('.receiving-brand-select');
            var modelSelect = detailRow.querySelector('.receiving-model-select');
            var hiddenBrand = detailRow.querySelector('input[name$="[brand]"]');
            var hiddenModel = detailRow.querySelector('input[name$="[model]"]');

            if (brandSelect) {
                setSelectValue(brandSelect, brandId);
                if (hiddenBrand) hiddenBrand.value = brandText;
                syncModelOptions(detailRow);
            }
            if (modelSelect) {
                setSelectValue(modelSelect, modelId);
                if (hiddenModel) hiddenModel.value = modelId ? modelText : '';
                initReceivingSelect2(modelSelect);
            }
        });

        syncBulkNoBrandModel(itemId);
        syncCompactDetailPanel(itemId);
    }

    function applyRemarksToAll(itemId) {
        var container = document.querySelector('.receiving-detail-rows[data-item-id="' + itemId + '"]');
        var bulkRemarksInput = document.querySelector('.receiving-bulk-remarks-input[data-item-id="' + itemId + '"]');
        if (!container || !bulkRemarksInput) return;

        var remarksValue = bulkRemarksInput.value || '';
        container.querySelectorAll('.receiving-detail-row').forEach(function (detailRow) {
            var noRemarksCheckbox = detailRow.querySelector('.receiving-no-remarks');
            var remarksInput = detailRow.querySelector('input[name$="[remarks]"]');
            if (!remarksInput) return;
            if (remarksValue !== '') {
                if (noRemarksCheckbox) {
                    noRemarksCheckbox.checked = false;
                }
                remarksInput.value = remarksValue;
            } else {
                remarksInput.value = '';
            }
            syncOptionalFieldVisibility(detailRow);
        });

        syncBulkCheckboxState(itemId, '.receiving-no-remarks', '.receiving-bulk-no-remarks');
        syncCompactDetailPanel(itemId);
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
            initReceivingSelect2(detailRow.querySelector('.receiving-brand-select'));
            syncModelOptions(detailRow);
            syncOptionalFieldVisibility(detailRow);
        });

        syncBulkNoBrandModel(itemId);
        syncBulkCheckboxState(itemId, '.receiving-no-serial-no', '.receiving-bulk-no-serial-no');
        syncBulkCheckboxState(itemId, '.receiving-no-remarks', '.receiving-bulk-no-remarks');
        syncCompactDetailPanel(itemId);
        updateDetailRowStatus(itemId);
    }

    document.querySelectorAll('.receiving-detail-row').forEach(function (row) {
        initReceivingSelect2(row.querySelector('.receiving-brand-select'));
        syncModelOptions(row);
        syncOptionalFieldVisibility(row);
    });
    document.querySelectorAll('.receiving-bulk-brand-select, .receiving-bulk-model-select').forEach(function (select) {
        initReceivingSelect2(select);
    });
    document.querySelectorAll('.receiving-detail-rows[data-item-id]').forEach(function (container) {
        var itemId = container.getAttribute('data-item-id');
        syncBulkNoBrandModel(itemId);
        syncBulkCheckboxState(itemId, '.receiving-no-serial-no', '.receiving-bulk-no-serial-no');
        syncBulkCheckboxState(itemId, '.receiving-no-remarks', '.receiving-bulk-no-remarks');
        syncCompactDetailPanel(itemId);
        syncBulkModelOptions(itemId);
    });
    document.addEventListener('click', function (event) {
        if (event.target.classList.contains('receiving-apply-brand-model-btn')) {
            applyBrandModelToAll(event.target.getAttribute('data-item-id'));
            updateWorkspaceSummary();
            return;
        }
        if (event.target.classList.contains('receiving-apply-remarks-btn')) {
            applyRemarksToAll(event.target.getAttribute('data-item-id'));
            updateWorkspaceSummary();
            return;
        }
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
        if (event.target.classList.contains('receiving-bulk-brand-select')) {
            syncBulkModelOptions(event.target.getAttribute('data-item-id'));
            return;
        }
        if (event.target.classList.contains('receiving-bulk-no-brand-model')) {
            applyBulkNoBrandModel(event.target.getAttribute('data-item-id'), event.target.checked);
            syncCompactDetailPanel(event.target.getAttribute('data-item-id'));
            updateWorkspaceSummary();
            return;
        }
        if (event.target.classList.contains('receiving-bulk-no-serial-no')) {
            applyBulkToggle(event.target.getAttribute('data-item-id'), '.receiving-no-serial-no', '.receiving-bulk-no-serial-no', syncOptionalFieldVisibility);
            updateWorkspaceSummary();
            return;
        }
        if (event.target.classList.contains('receiving-bulk-no-remarks')) {
            applyBulkToggle(event.target.getAttribute('data-item-id'), '.receiving-no-remarks', '.receiving-bulk-no-remarks', syncOptionalFieldVisibility);
            updateWorkspaceSummary();
            return;
        }
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
        if (event.target.classList.contains('receiving-no-brand-model')) {
            syncModelOptions(row);
            var container = row.closest('.receiving-detail-rows');
            if (container) {
                var itemId = container.getAttribute('data-item-id');
                syncBulkNoBrandModel(itemId);
                syncCompactDetailPanel(itemId);
            }
        }
        if (event.target.classList.contains('receiving-no-serial-no') || event.target.classList.contains('receiving-no-remarks')) {
            syncOptionalFieldVisibility(row);
            var container = row.closest('.receiving-detail-rows');
            if (container) {
                var itemId = container.getAttribute('data-item-id');
                syncBulkCheckboxState(itemId, '.receiving-no-serial-no', '.receiving-bulk-no-serial-no');
                syncBulkCheckboxState(itemId, '.receiving-no-remarks', '.receiving-bulk-no-remarks');
                syncCompactDetailPanel(itemId);
            }
        }
        updateWorkspaceSummary();
    });
    if (window.jQuery) {
        window.jQuery(document).on('select2:select select2:clear', '.receiving-bulk-brand-select', function () {
            syncBulkModelOptions(this.getAttribute('data-item-id'));
        });
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

    var receivingRequiredValidation = null;

    function updatePhysicalConfirmRequirement() {
        var form = document.getElementById('receivingForm');
        var checkbox = document.getElementById('confirm_physical_receipt');
        var hint = document.getElementById('receivingPhysicalConfirmHint');
        var badge = document.getElementById('receivingVerificationBadge');
        var feedback = document.getElementById('confirm_physical_receipt_feedback');
        if (!form || !checkbox || !hint) {
            return;
        }
        var semiHvMin = parseNum(form.getAttribute('data-semi-hv-min') || '5000');
        var requiresConfirmation = Array.from(document.querySelectorAll('.receiving-line-row')).some(function (row) {
            var acceptedInput = row.querySelector('.receiving-accept-input');
            var acceptedQty = parseNum((acceptedInput && acceptedInput.value) ? acceptedInput.value : 0);
            if (acceptedQty <= 0) {
                return false;
            }
            var itemType = String(row.getAttribute('data-item-type') || '');
            if (itemType === 'equipment') {
                return true;
            }
            if (itemType !== 'semi_expendable') {
                return false;
            }
            var semiType = String(row.getAttribute('data-semi-type') || '');
            if (semiType === 'high_value') {
                return true;
            }
            if (semiType === 'low_value') {
                return false;
            }
            var unitCost = parseNum(row.getAttribute('data-unit-cost') || '0');
            return unitCost >= semiHvMin;
        });

        checkbox.required = requiresConfirmation;
        if (!requiresConfirmation) {
            checkbox.classList.remove('is-invalid');
            if (feedback) {
                feedback.classList.add('d-none');
            }
        }
        if (badge) {
            badge.style.display = requiresConfirmation ? 'inline-flex' : 'none';
        }
        hint.textContent = requiresConfirmation
            ? 'Required now: at least one equipment/high-value semi-expendable line has accepted quantity.'
            : 'Required only when accepted qty exists for equipment/high-value semi-expendable lines.';

        if (receivingRequiredValidation && form) {
            receivingRequiredValidation.render(form.getAttribute('data-show-required-summary') === '1');
        }
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
            updatePhysicalConfirmRequirement();
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
            updatePhysicalConfirmRequirement();
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
            updatePhysicalConfirmRequirement();
            return;
        }
    });

    var receivingForm = document.getElementById('receivingForm');
    if (receivingForm && window.SPAMS && typeof window.SPAMS.setupRequiredSummaryValidation === 'function') {
        receivingRequiredValidation = window.SPAMS.setupRequiredSummaryValidation({
            form: receivingForm,
            summaryId: 'receiving_form_feedback',
            requiredFields: [
                { id: 'received_date', label: 'Received date', feedbackId: 'received_date_feedback' },
                {
                    id: 'confirm_physical_receipt',
                    label: 'Physical verification confirmation',
                    feedbackId: 'confirm_physical_receipt_feedback',
                    useSelect2: false,
                    requiredWhen: function (field) {
                        return !!field.required;
                    },
                    isMissing: function (field) {
                        return !field.checked;
                    }
                }
            ],
            beforeValidate: function () {
                updatePhysicalConfirmRequirement();
            }
        });
    }
    updatePhysicalConfirmRequirement();

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
