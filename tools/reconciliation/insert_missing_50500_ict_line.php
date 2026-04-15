<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
$tag = 'RCPPEE_2025_ICT_FUND01_LIST';

// Insert the one remaining missing line from the provided list
// Duplicate MSI line with different property number.
$accountCode = '1.06.05.030.00';
$acc = $m->query("SELECT id, account_name FROM account_codes WHERE account_code = '$accountCode' LIMIT 1")->fetch_assoc();
$accountId = $acc ? (int)$acc['id'] : null;
$accountName = $acc ? $acc['account_name'] : 'Information and Communication Technology Equipment';

$prop = '2025-01-05.030-004-219';
$descDetail = 'Intel core i5, 12MB cache, 2.1GHz-4.6GHz Clock Rate; Memory: 8GB DDR4-3200MHz, expandable to 32GB; Storage: 1TB NVMe SSD; MSI - Thin 15 B13UCX SN: K2406N0023807';
$itemDesc = 'Laptop; ' . $descDetail;
$serial = 'K2406N0023807';
$unitCost = 50500.00;

$stmt = $m->prepare(
    "INSERT INTO rpcppe_batch_items (
        batch_id, source_type, property_number, item_description, description_detail,
        unit_cost, qty_property_card, qty_physical_count, serial_no,
        account_code_id, account_code, account_name,
        fund_code, fund_source, fund_number,
        remarks, is_included, is_disposed, created_at, updated_at
     ) VALUES (
        14, 'legacy', ?, ?, ?,
        ?, 1, 1, ?,
        ?, ?, ?,
        'GAA-AEP', '01', '1',
        ?, 1, 0, NOW(), NOW()
     )"
);
$stmt->bind_param('sssdsisss', $prop, $itemDesc, $descDetail, $unitCost, $serial, $accountId, $accountCode, $accountName, $tag);
$stmt->execute();
$newId = $m->insert_id;
$stmt->close();

$sum = $m->query("SELECT COUNT(*) c, ROUND(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),2) t
                  FROM rpcppe_batch_items
                  WHERE batch_id=14 AND remarks LIKE '%$tag%'")->fetch_assoc();

echo "Inserted ID: $newId\n";
echo "Tagged rows: {$sum['c']}\n";
echo "Tagged total: " . number_format((float)$sum['t'],2) . "\n";
echo "Expected: 17,339,297.00\n";
echo "Delta: " . number_format((float)$sum['t'] - 17339297.00,2) . "\n";

$m->close();
