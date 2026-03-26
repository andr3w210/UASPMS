<?php
// E2E smoke test: performs receiving -> distribution -> issuance flows inside a DB transaction
// and rolls back at the end so no persistent changes remain.
require_once __DIR__ . '/../spams/app/config/init.php';

$db = db_connect();
if (!$db) {
    echo "No DB connection\n";
    exit(1);
}

try {
    // Find an active purchase order with at least one item.
    // If a PO id is passed as CLI arg use it, otherwise pick a random PO to vary selection.
    $selectedPoId = isset($argv[1]) ? (int) $argv[1] : 0;
    if ($selectedPoId > 0) {
        $stmt = $db->prepare("SELECT po.id AS po_id, poi.id AS poi_id, poi.line_no FROM purchase_orders po JOIN purchase_order_items poi ON poi.purchase_order_id = po.id WHERE po.id = ? LIMIT 1");
        $stmt->bind_param('i', $selectedPoId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    } else {
        $row = $db->query("SELECT po.id AS po_id, poi.id AS poi_id, poi.line_no FROM purchase_orders po JOIN purchase_order_items poi ON poi.purchase_order_id = po.id WHERE po.status != 'cancelled' ORDER BY RAND() LIMIT 1")->fetch_assoc() ?: null;
    }
    if (!$row) {
        echo "No purchase order with items found. Create a PO first.\n";
        exit(1);
    }
    $poId = (int) $row['po_id'];
    $poiId = (int) $row['poi_id'];

    $db->begin_transaction();

    echo "Using PO ID: $poId, POI ID: $poiId\n";

    // 1) Create a receiving
    $systemRef = next_module_code($db, 'receivings');
    $risNo = 'RIS-' . date('YmdHis') . '-TEST';
    $receivedDate = date('Y-m-d');
    $userId = current_user_id() ?: 1;

    $stmt = $db->prepare("INSERT INTO receivings (system_reference, purchase_order_id, ris_no, received_date, delivery_receipt_no, invoice_no, status, remarks, total_received_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, 'completed', '', 0.00, ?)");
    $dr = 'DR-' . time();
    $inv = 'INV-' . time();
    $stmt->bind_param('sissssi', $systemRef, $poId, $risNo, $receivedDate, $dr, $inv, $userId);
    $stmt->execute();
    $receivingId = (int) $stmt->insert_id;
    $stmt->close();
    echo "Created receiving #$receivingId ($systemRef)\n";

    // 2) Add a receiving_item for the found purchase_order_item
    $qtyDelivered = 1.00;
    $qtyAccepted = 1.00;
    $qtyRejected = 0.00;
    $itemCondition = 'Good Condition';
    // fetch unit_cost from purchase_order_items
    $poi = $db->prepare("SELECT unit_cost, item_type FROM purchase_order_items WHERE id = ? LIMIT 1");
    $poi->bind_param('i', $poiId);
    $poi->execute();
    $poiRow = $poi->get_result()->fetch_assoc();
    $poi->close();
    $unitCost = isset($poiRow['unit_cost']) ? (float)$poiRow['unit_cost'] : 100.00;
    $itemType = $poiRow['item_type'] ?? 'supply';
    $lineTotal = round($qtyAccepted * $unitCost, 2);

    $rit = $db->prepare("INSERT INTO receiving_items (receiving_id, purchase_order_item_id, quantity_delivered, quantity_accepted, quantity_rejected, item_condition, unit_cost, line_total, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '')");
    $rit->bind_param('iiiddsdd', $receivingId, $poiId, $qtyDelivered, $qtyAccepted, $qtyRejected, $itemCondition, $unitCost, $lineTotal);
    $rit->execute();
    $receivingItemId = (int) $rit->insert_id;
    $rit->close();
    echo "Created receiving_item #$receivingItemId\n";

    // 3) Insert receiving_item_details if equipment/semi_expendable
    if ($itemType === 'equipment' || $itemType === 'semi_expendable') {
        $dstmt = $db->prepare("INSERT INTO receiving_item_details (receiving_item_id, brand, model, serial_no, remarks) VALUES (?, ?, ?, ?, '')");
        $brand = 'TestBrand'; $model = 'TestModel'; $serial = 'SN' . time();
        $dstmt->bind_param('isss', $receivingItemId, $brand, $model, $serial);
        $dstmt->execute();
        $dstmt->close();
        echo "Inserted receiving_item_details for #$receivingItemId\n";
    }

    // 4) Create stock_items & stock_movements (only for supply/semi_expendable/equipment) - mimic app behavior
    if (in_array($itemType, ['supply','semi_expendable','equipment'], true) && $qtyAccepted > 0) {
        $stockRef = next_module_code($db, 'stock_items');
        $accountCodeId = 0;
        $classificationId = 0;
        $uomId = 0;
        $description = 'Auto-created stock for E2E test';
        $stockInsert = $db->prepare("INSERT INTO stock_items (system_reference, receiving_id, receiving_item_id, purchase_order_item_id, item_type, account_code_id, classification_id, unit_of_measure_id, item_description, unit_cost, quantity_received, quantity_issued, quantity_on_hand, created_by) VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, 0.00, ?, ?)");
        $stockInsert->bind_param('siiisiiisdddi', $stockRef, $receivingId, $receivingItemId, $poiId, $itemType, $accountCodeId, $classificationId, $uomId, $description, $unitCost, $qtyAccepted, $qtyAccepted, $userId);
        $stockInsert->execute();
        $stockItemId = (int) $stockInsert->insert_id;
        $stockInsert->close();
        echo "Created stock_item #$stockItemId\n";

        $moveStmt = $db->prepare("INSERT INTO stock_movements (stock_item_id, movement_type, movement_date, reference_type, reference_id, quantity_in, quantity_out, balance_after, remarks, created_by) VALUES (?, 'receipt', ?, 'receiving', ?, ?, 0.00, ?, '', ?)");
        $moveStmt->bind_param('isiddi', $stockItemId, $receivedDate, $receivingId, $qtyAccepted, $qtyAccepted, $userId);
        $moveStmt->execute();
        $moveStmt->close();
        echo "Inserted stock_movement for stock #$stockItemId\n";
    }

    // 5) Create a distribution for that receiving_item (mimic full distribution of accepted qty)
    $distSystemRef = next_module_code($db, 'distributions');
    $docNo = 'DIST-' . time();
    $distDate = date('Y-m-d');
    // pick an existing office and employee
    $officeRow = $db->query("SELECT id FROM offices WHERE is_active = 1 LIMIT 1")->fetch_assoc() ?: $db->query("SELECT id FROM offices LIMIT 1")->fetch_assoc();
    $employeeRow = $db->query("SELECT id FROM employees WHERE is_active = 1 LIMIT 1")->fetch_assoc() ?: $db->query("SELECT id FROM employees LIMIT 1")->fetch_assoc();
    $officeId = $officeRow ? (int) $officeRow['id'] : 0;
    $employeeId = $employeeRow ? (int) $employeeRow['id'] : 0;
    // If no office exists, create a temporary one (will be rolled back)
    if ($officeId === 0) {
        $oname = 'E2E Test Office ' . time();
        $db->query(sprintf("INSERT INTO offices (office_name, is_active) VALUES ('%s', 1)", $db->real_escape_string($oname)));
        $officeId = (int) $db->insert_id;
        echo "Inserted temporary office #$officeId\n";
    }
    // If no employee exists, create a temporary one attached to the office
    if ($employeeId === 0) {
        $eno = 'E2E' . time();
        $fname = 'E2E';
        $lname = 'User';
        $db->query(sprintf("INSERT INTO employees (office_id, employee_no, first_name, last_name, is_active) VALUES (%d, '%s', '%s', '%s', 1)", $officeId, $db->real_escape_string($eno), $db->real_escape_string($fname), $db->real_escape_string($lname)));
        $employeeId = (int) $db->insert_id;
        echo "Inserted temporary employee #$employeeId\n";
    }
    $totalAmount = $lineTotal;
    $q = sprintf(
        "INSERT INTO distributions (system_reference, document_type, document_no, distribution_date, office_id, employee_id, purpose, remarks, status, total_amount, created_by) VALUES ('%s', 'ics', '%s', '%s', NULLIF(%d,0), NULLIF(%d,0), '', '', 'posted', %F, %d)",
        $db->real_escape_string($distSystemRef),
        $db->real_escape_string($docNo),
        $db->real_escape_string($distDate),
        $officeId,
        $employeeId,
        $totalAmount,
        $userId
    );
    $db->query($q);
    $distributionId = (int) $db->insert_id;
    echo "Created distribution #$distributionId\n";

    // Check whether the DB has the issuance_item_id column (migration may not have been applied)
    $colCheck = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'distribution_items' AND COLUMN_NAME = 'issuance_item_id'");
    $hasIssuanceItemCol = $colCheck && $colCheck->fetch_assoc();
    if ($hasIssuanceItemCol) {
        // We have the column: insert issuance_item_id (NULL for this test)
        $itemIns = $db->prepare("INSERT INTO distribution_items (distribution_id, issuance_item_id, receiving_item_id, quantity_distributed, unit_cost, line_total, remarks) VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, '')");
        // bind: distributionId (i), issuanceItemId (i, 0), originReceivingItemId (i), qty (d), unitCost (d), lineTotal (d)
        $issuanceItemIdForInsert = 0;
        $originReceivingItemIdForInsert = $receivingItemId;
        $itemIns->bind_param('iiiddd', $distributionId, $issuanceItemIdForInsert, $originReceivingItemIdForInsert, $qtyAccepted, $unitCost, $lineTotal);
        $itemIns->execute();
    } else {
        // Migration not applied; fall back to inserting without issuance_item_id
        $itemIns = $db->prepare("INSERT INTO distribution_items (distribution_id, receiving_item_id, quantity_distributed, unit_cost, line_total, remarks) VALUES (?, ?, ?, ?, ?, '')");
        $itemIns->bind_param('iiddd', $distributionId, $receivingItemId, $qtyAccepted, $unitCost, $lineTotal);
        $itemIns->execute();
    }
    $distItemId = (int) $itemIns->insert_id;
    $itemIns->close();
    echo "Created distribution_item #$distItemId\n";

    // 6) Create an issuance for the stock item (if stock created)
    if (!empty($stockItemId)) {
        $issRef = next_module_code($db, 'issuances');
        $issDate = date('Y-m-d');
        // Insert issuance header via escaped query
        $db->query(sprintf("INSERT INTO issuances (system_reference, issuance_date, office_id, employee_id, purpose, remarks, status, total_amount, created_by) VALUES ('%s','%s', NULLIF(%d,0), NULLIF(%d,0), '', '', 'posted', %F, %d)", $db->real_escape_string($issRef), $db->real_escape_string($issDate), $officeId, $employeeId, $totalAmount, $userId));
        $issuanceId = (int) $db->insert_id;
        echo "Created issuance #$issuanceId\n";

        // issuance_item
        $db->query(sprintf("INSERT INTO issuance_items (issuance_id, stock_item_id, quantity_issued, unit_cost, line_total, remarks) VALUES (%d, %d, %F, %F, %F, '')", $issuanceId, $stockItemId, $qtyAccepted, $unitCost, $lineTotal));
        $issItemId = (int) $db->insert_id;
        echo "Created issuance_item #$issItemId\n";

        // update stock_items
        $db->query(sprintf("UPDATE stock_items SET quantity_issued = quantity_issued + %F, quantity_on_hand = quantity_on_hand - %F WHERE id = %d", $qtyAccepted, $qtyAccepted, $stockItemId));
        echo "Updated stock on hand for #$stockItemId\n";

        // stock movement for issuance
        $db->query(sprintf("INSERT INTO stock_movements (stock_item_id, movement_type, movement_date, reference_type, reference_id, quantity_in, quantity_out, balance_after, remarks, created_by) VALUES (%d, 'issue', '%s', 'issuance', %d, 0.00, %F, %F, '', %d)", $stockItemId, $db->real_escape_string($issDate), $issuanceId, $qtyAccepted, ($qtyAccepted * -1), $userId));
        echo "Inserted issuance movement for stock #$stockItemId\n";
    }

    // Summary queries
    $counts = [];
    foreach (['receivings','receiving_items','stock_items','stock_movements','distributions','distribution_items','issuances','issuance_items'] as $tbl) {
        $r = $db->query("SELECT COUNT(*) AS c FROM " . $tbl . "");
        $counts[$tbl] = $r->fetch_assoc()['c'] ?? 0;
    }

    echo "Summary counts (db-wide):\n";
    foreach ($counts as $k=>$v) echo " - $k: $v\n";

    // Rollback to leave DB unchanged
    $db->rollback();
    echo "Rolled back transaction — no changes persisted.\n";

    // Mark todos done
    echo "E2E smoke test completed successfully (rolled back).\n";
    } catch (Throwable $e) {
    if ($db) $db->rollback();
    echo "Error during smoke test: " . $e->getMessage() . "\n";
    exit(1);
}

