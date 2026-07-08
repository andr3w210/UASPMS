<?php
require_once __DIR__ . '/../bootstrap.php';
$apply = in_array('--apply', $argv, true);

$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to persist batch 14 legacy snapshot repairs." . PHP_EOL;
}

$batchId = 14;

$countBefore = $m->query("SELECT COUNT(*) c FROM rpcppe_batch_items
                          WHERE batch_id=$batchId
                            AND (account_code IS NULL OR account_name IS NULL OR fund_code IS NULL OR fund_source IS NULL OR fund_number IS NULL)")->fetch_assoc();
echo 'null_before=' . (int)$countBefore['c'] . PHP_EOL;

$m->begin_transaction();
try {
    // 1) Restore from directly-linked legacy asset rows.
    $sql1 = "UPDATE rpcppe_batch_items bi
             INNER JOIN legacy_assets la ON la.id = bi.legacy_asset_id
             LEFT JOIN account_codes ac ON ac.id = la.account_code_id
             LEFT JOIN funds f ON f.id = la.fund_id
             SET bi.account_code_id = COALESCE(la.account_code_id, bi.account_code_id),
                 bi.account_code = COALESCE(NULLIF(ac.account_code, ''), bi.account_code),
                 bi.account_name = COALESCE(NULLIF(ac.account_name, ''), bi.account_name),
                 bi.fund_code = COALESCE(NULLIF(f.fund_code, ''), bi.fund_code),
                 bi.fund_source = COALESCE(NULLIF(f.fund_source, ''), bi.fund_source),
                 bi.fund_number = CASE
                     WHEN f.fund_source IS NOT NULL AND f.fund_source <> '' THEN TRIM(f.fund_source)
                     WHEN f.fund_code IS NOT NULL AND f.fund_code REGEXP '^[0-9]+$' THEN TRIM(f.fund_code)
                     ELSE bi.fund_number
                 END,
                 bi.updated_at = NOW()
             WHERE bi.batch_id = $batchId
               AND bi.source_type = 'legacy'
               AND bi.legacy_asset_id IS NOT NULL
               AND (bi.account_code IS NULL OR bi.account_name IS NULL OR bi.fund_code IS NULL OR bi.fund_source IS NULL OR bi.fund_number IS NULL)";
    $m->query($sql1);
    $fixedById = $m->affected_rows;

    // 2) Restore unlinked rows by matching property number to active legacy assets.
        $sql2 = "UPDATE rpcppe_batch_items bi
                         INNER JOIN legacy_assets la
                                         ON CONVERT(la.property_number USING utf8mb4) COLLATE utf8mb4_unicode_ci
                                            = CONVERT(bi.property_number USING utf8mb4) COLLATE utf8mb4_unicode_ci
                                        AND la.is_active = 1
             LEFT JOIN account_codes ac ON ac.id = la.account_code_id
             LEFT JOIN funds f ON f.id = la.fund_id
             SET bi.legacy_asset_id = COALESCE(bi.legacy_asset_id, la.id),
                 bi.account_code_id = COALESCE(la.account_code_id, bi.account_code_id),
                 bi.account_code = COALESCE(NULLIF(ac.account_code, ''), bi.account_code),
                 bi.account_name = COALESCE(NULLIF(ac.account_name, ''), bi.account_name),
                 bi.fund_code = COALESCE(NULLIF(f.fund_code, ''), bi.fund_code),
                 bi.fund_source = COALESCE(NULLIF(f.fund_source, ''), bi.fund_source),
                 bi.fund_number = CASE
                     WHEN f.fund_source IS NOT NULL AND f.fund_source <> '' THEN TRIM(f.fund_source)
                     WHEN f.fund_code IS NOT NULL AND f.fund_code REGEXP '^[0-9]+$' THEN TRIM(f.fund_code)
                     ELSE bi.fund_number
                 END,
                 bi.updated_at = NOW()
             WHERE bi.batch_id = $batchId
               AND bi.source_type = 'legacy'
               AND bi.legacy_asset_id IS NULL
               AND bi.property_number IS NOT NULL AND bi.property_number <> ''
               AND (bi.account_code IS NULL OR bi.account_name IS NULL OR bi.fund_code IS NULL OR bi.fund_source IS NULL OR bi.fund_number IS NULL)";
    $m->query($sql2);
    $fixedByProperty = $m->affected_rows;

    // 3) Last-safe fallback for remaining rows: preserve row but avoid nulls that cause undefined.
    $sql3 = "UPDATE rpcppe_batch_items
             SET account_code = COALESCE(account_code, ''),
                 account_name = COALESCE(account_name, ''),
                 fund_code = COALESCE(fund_code, ''),
                 fund_source = COALESCE(fund_source, ''),
                 fund_number = COALESCE(fund_number, ''),
                 updated_at = NOW()
             WHERE batch_id = $batchId
               AND source_type = 'legacy'
               AND (account_code IS NULL OR account_name IS NULL OR fund_code IS NULL OR fund_source IS NULL OR fund_number IS NULL)";
    $m->query($sql3);
    $fallbackFilled = $m->affected_rows;

    $countAfter = $m->query("SELECT COUNT(*) c FROM rpcppe_batch_items
                             WHERE batch_id=$batchId
                               AND (account_code IS NULL OR account_name IS NULL OR fund_code IS NULL OR fund_source IS NULL OR fund_number IS NULL)")->fetch_assoc();

    if ($apply) {
        $m->commit();
    } else {
        $m->rollback();
    }

    echo 'fixed_by_legacy_id=' . (int)$fixedById . PHP_EOL;
    echo 'fixed_by_property=' . (int)$fixedByProperty . PHP_EOL;
    echo 'fallback_non_null=' . (int)$fallbackFilled . PHP_EOL;
    echo 'null_after=' . (int)$countAfter['c'] . PHP_EOL;
} catch (Throwable $e) {
    $m->rollback();
    throw $e;
}

// Report rows still missing semantic values (blank string) for manual follow-up.
$reportSql = "SELECT id, property_number, account_code, account_name, fund_code, fund_source, fund_number,
                     ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
                     LEFT(REPLACE(REPLACE(item_description,'\\r',' '),'\\n',' '),120) item_description
              FROM rpcppe_batch_items
              WHERE batch_id=$batchId
                AND source_type='legacy'
                AND (account_code='' OR account_name='' OR fund_code='' OR fund_source='' OR fund_number='')
              ORDER BY id";
$res = $m->query($reportSql);
$exportDir = __DIR__ . DIRECTORY_SEPARATOR . 'exports';
if (!is_dir($exportDir) && !mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
    throw new RuntimeException('Unable to create export directory: ' . $exportDir);
}
$csv = $exportDir . DIRECTORY_SEPARATOR . 'batch14_legacy_rows_still_blank_after_repair.csv';
$f = fopen($csv, 'w');
if (!$f) {
    throw new RuntimeException('Unable to open export file for writing: ' . $csv);
}
fputcsv($f, ['id','property_number','account_code','account_name','fund_code','fund_source','fund_number','total','item_description']);
$left = 0;
while($row = $res->fetch_assoc()) {
    $left++;
    fputcsv($f, [$row['id'],$row['property_number'],$row['account_code'],$row['account_name'],$row['fund_code'],$row['fund_source'],$row['fund_number'],$row['total'],$row['item_description']]);
}
fclose($f);
echo 'blank_semantic_rows=' . $left . PHP_EOL;
echo 'exported_blank_report=' . $csv . PHP_EOL;

$m->close();
