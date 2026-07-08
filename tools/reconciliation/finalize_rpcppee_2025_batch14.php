<?php

require_once __DIR__ . '/../bootstrap.php';
$apply = in_array('--apply', $argv, true);

$db = tools_db();
if ($db->connect_error) {
    fwrite(STDERR, "Connection failed: {$db->connect_error}\n");
    exit(1);
}

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to replace RPCPPEE 2025 final adjustment rows in batch 14." . PHP_EOL;
    $db->close();
    exit(0);
}

$batchId = 14;
$adjustmentPrefix = 'ADJ-RPCPPEE2025-FINAL-';
$adjustmentRemark = 'RPCPPEE2025-FINAL-RECON';
$adjustmentDescription = 'RPCPPEE 2025 final summary reconciliation adjustment';
$timestamp = date('Y-m-d H:i:s');

$targets = [
    '01' => [
        '1.06.01.010.00' => 24522936.00,
        '1.06.02.990.00' => 37535052.26,
        '1.06.03.040.00' => 559659.66,
        '1.06.03.050.00' => 4688170.92,
        '1.06.04.010.00' => 146379514.73,
        '1.06.04.020.00' => 181058566.73,
        '1.06.04.990.00' => 26862983.60,
        '1.06.05.010.00' => 11853860.00,
        '1.06.05.020.00' => 12344704.00,
        '1.06.05.030.00' => 17339297.00,
        '1.06.05.070.00' => 391800.00,
        '1.06.05.090.00' => 889920.00,
        '1.06.05.130.00' => 464600.00,
        '1.06.05.140.00' => 19159114.48,
        '1.06.05.990.00' => 14859918.75,
        '1.06.06.010.00' => 1640000.00,
        '1.06.07.010.00' => 923555.99,
        '1.08.01.020.00' => 7000000.00,
    ],
    '05' => [
        '1.06.01.010.00' => 12000000.00,
        '1.06.02.990.00' => 12251471.42,
        '1.06.03.040.00' => 2226131.61,
        '1.06.03.050.00' => 12710009.13,
        '1.06.04.010.00' => 26027285.00,
        '1.06.04.020.00' => 50839255.35,
        '1.06.04.990.00' => 22198580.57,
        '1.06.05.010.00' => 1856538.00,
        '1.06.05.020.00' => 19961019.00,
        '1.06.05.030.00' => 17402861.73,
        '1.06.05.070.00' => 1520860.40,
        '1.06.05.090.00' => 645000.00,
        '1.06.05.110.00' => 579249.67,
        '1.06.05.130.00' => 264800.00,
        '1.06.05.140.00' => 47198308.98,
        '1.06.05.990.00' => 9155563.28,
        '1.06.06.010.00' => 12842000.00,
        '1.06.07.010.00' => 14641371.80,
        '1.08.01.020.00' => 9700937.00,
    ],
    '06' => [
        '1.06.05.020.00' => 55000.00,
        '1.06.05.990.00' => 347440.00,
    ],
    '07' => [
        '1.06.04.010.00' => 9954264.93,
        '1.06.05.140.00' => 90376646.00,
        '1.06.05.990.00' => 58272.00,
    ],
];

$preferredFundCodes = [
    '01' => 'GAA-AEP',
    '05' => 'FPSY',
    '06' => 'IGP',
    '07' => '07',
];

$accountMap = [];
$accountRes = $db->query("SELECT id, account_code, account_name FROM account_codes");
while ($row = $accountRes->fetch_assoc()) {
    $accountMap[$row['account_code']] = [
        'id' => (int) $row['id'],
        'name' => $row['account_name'],
    ];
}

$insertSql = "INSERT INTO rpcppe_batch_items (
    batch_id, source_type, distribution_item_detail_id, legacy_asset_id,
    property_number, item_name, item_name_id, item_description, description_detail,
    classification_name, classification_family, uom_name, abbreviation,
    unit_cost, acquisition_date, qty_property_card, qty_physical_count,
    brand, model, serial_no, office_id, office_name, employee_id, employee_name,
    account_code_id, account_code, account_name, fund_code, fund_source, fund_number,
    remarks, is_included, reconciliation_status, submitted_to_accounting_at, reconciled_at,
    is_disposed, disposed_at, created_at, updated_at
) VALUES (
    ?, 'legacy', NULL, NULL,
    ?, 'Reconciliation Adjustment', NULL, ?, NULL,
    NULL, NULL, 'lot', NULL,
    ?, NULL, 1, 1,
    NULL, NULL, NULL, NULL, NULL, NULL, NULL,
    ?, ?, ?, ?, ?, '1',
    ?, 1, 'reconciled', ?, ?,
    0, NULL, ?, ?
)";

$insertStmt = $db->prepare($insertSql);
if (!$insertStmt) {
    fwrite(STDERR, "Prepare failed: {$db->error}\n");
    exit(1);
}

$summaryRows = [];
$insertedRows = 0;
$insertedTotal = 0.0;

$db->begin_transaction();

try {
    $deleteStmt = $db->prepare("DELETE FROM rpcppe_batch_items WHERE batch_id = ? AND property_number LIKE CONCAT(?, '%')");
    $deleteStmt->bind_param('is', $batchId, $adjustmentPrefix);
    $deleteStmt->execute();
    $deletedRows = $deleteStmt->affected_rows;
    $deleteStmt->close();

    $currentSql = "
        SELECT ROUND(COALESCE(SUM(
            CASE
                WHEN COALESCE(qty_property_card, 0) > 1 THEN qty_property_card * unit_cost
                ELSE unit_cost
            END
        ), 0), 2) AS total
        FROM rpcppe_batch_items
        WHERE batch_id = ?
          AND reconciliation_status = 'reconciled'
          AND fund_source = ?
          AND account_code = ?
          AND property_number NOT LIKE CONCAT(?, '%')
    ";
    $currentStmt = $db->prepare($currentSql);

    foreach ($targets as $fundSource => $accountTargets) {
        foreach ($accountTargets as $accountCode => $targetAmount) {
            if (!isset($accountMap[$accountCode])) {
                throw new RuntimeException("Missing account code mapping for {$accountCode}");
            }

            $currentStmt->bind_param('isss', $batchId, $fundSource, $accountCode, $adjustmentPrefix);
            $currentStmt->execute();
            $currentRow = $currentStmt->get_result()->fetch_assoc();
            $currentAmount = (float) ($currentRow['total'] ?? 0);
            $diffAmount = round($targetAmount - $currentAmount, 2);

            $summaryRows[] = [
                'fund_source' => $fundSource,
                'account_code' => $accountCode,
                'account_name' => $accountMap[$accountCode]['name'],
                'current_amount' => $currentAmount,
                'target_amount' => $targetAmount,
                'diff_amount' => $diffAmount,
            ];

            if (abs($diffAmount) < 0.01) {
                continue;
            }

            $propertyNumber = $adjustmentPrefix . $fundSource . '-' . str_replace('.', '', $accountCode);
            $itemDescription = "{$adjustmentDescription}: {$fundSource} {$accountCode}";
            $accountCodeId = $accountMap[$accountCode]['id'];
            $accountName = $accountMap[$accountCode]['name'];
            $fundCode = $preferredFundCodes[$fundSource] ?? $fundSource;
            $remark = $adjustmentRemark;

            $insertStmt->bind_param(
                'issdisssssssss',
                $batchId,
                $propertyNumber,
                $itemDescription,
                $diffAmount,
                $accountCodeId,
                $accountCode,
                $accountName,
                $fundCode,
                $fundSource,
                $remark,
                $timestamp,
                $timestamp,
                $timestamp,
                $timestamp
            );
            $insertStmt->execute();

            $insertedRows++;
            $insertedTotal += $diffAmount;
        }
    }

    $currentStmt->close();
    $insertStmt->close();
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "Failed: {$e->getMessage()}\n");
    exit(1);
}

$verifySql = "
    SELECT fund_source, account_code, account_name, COUNT(*) AS row_count,
           ROUND(SUM(
               CASE
                   WHEN COALESCE(qty_property_card, 0) > 1 THEN qty_property_card * unit_cost
                   ELSE unit_cost
               END
           ), 2) AS final_total
    FROM rpcppe_batch_items
    WHERE batch_id = {$batchId}
      AND reconciliation_status = 'reconciled'
      AND fund_source IN ('01','05','06','07')
    GROUP BY fund_source, account_code, account_name
    ORDER BY fund_source, account_code
";
$verifyRes = $db->query($verifySql);

$csvPath = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'rpcppee_2025_final_reconcile_batch14_summary.csv';
$csv = fopen($csvPath, 'w');
fputcsv($csv, ['fund_source', 'account_code', 'account_name', 'row_count', 'final_total', 'expected_total', 'status']);

$grandTotal = 0.0;
echo "RPCPPEE 2025 Final Reconcile Batch 14\n";
echo str_repeat('=', 120) . "\n";
echo "fund\taccount_code\taccount_name\trows\tfinal_total\texpected_total\tstatus\n";

while ($row = $verifyRes->fetch_assoc()) {
    $fundSource = $row['fund_source'];
    $accountCode = $row['account_code'];
    $expected = $targets[$fundSource][$accountCode] ?? null;
    if ($expected === null) {
        continue;
    }
    $final = (float) $row['final_total'];
    $grandTotal += $final;
    $status = abs($final - $expected) < 0.01 ? 'OK' : 'MISMATCH';

    echo implode("\t", [
        $fundSource,
        $accountCode,
        $row['account_name'],
        $row['row_count'],
        number_format($final, 2, '.', ','),
        number_format($expected, 2, '.', ','),
        $status,
    ]) . "\n";

    fputcsv($csv, [
        $fundSource,
        $accountCode,
        $row['account_name'],
        $row['row_count'],
        number_format($final, 2, '.', ''),
        number_format($expected, 2, '.', ''),
        $status,
    ]);
}

fclose($csv);

echo str_repeat('-', 120) . "\n";
echo "Deleted prior adjustment rows: {$deletedRows}\n";
echo "Inserted adjustment rows: {$insertedRows}\n";
echo "Net adjustment total: " . number_format($insertedTotal, 2, '.', ',') . "\n";
echo "Grand total: " . number_format($grandTotal, 2, '.', ',') . "\n";
echo "CSV: {$csvPath}\n";

$db->close();
