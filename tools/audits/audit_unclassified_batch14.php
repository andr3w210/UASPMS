<?php
require_once __DIR__ . '/../bootstrap.php';
/**
 * Audit batch 14 for blank/null account_codes (unclassified items)
 * and show full account breakdown.
 */
$m = tools_db();
if ($m->connect_error) die('Connection failed: ' . $m->connect_error);

header('Content-Type: text/plain; charset=utf-8');

$batchId = 14;

// ─── 1. Blank / null account rows ────────────────────────────────────────────
$r = $m->query("
    SELECT
        COALESCE(NULLIF(account_code,''),'(blank)') AS account_code,
        COALESCE(NULLIF(account_name,''),'(blank)') AS account_name,
        COUNT(*) AS cnt,
        ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
    FROM rpcppe_batch_items
    WHERE batch_id = $batchId
      AND (account_code IS NULL OR account_code = ''
           OR account_name IS NULL OR account_name = '')
    GROUP BY account_code, account_name
    ORDER BY total DESC
");
echo "== BLANK / NULL account rows in batch $batchId ==\n";
echo implode("\t", ['account_code','account_name','rows','total']) . "\n";
$grandBlank = 0; $cntBlank = 0;
while ($row = $r->fetch_assoc()) {
    $grandBlank += (float)$row['total'];
    $cntBlank   += (int)$row['cnt'];
    echo $row['account_code'] . "\t" . $row['account_name'] . "\t"
       . $row['cnt'] . "\t" . number_format((float)$row['total'], 2) . "\n";
}
if ($cntBlank === 0) echo "(none — all rows have account codes)\n";
echo "SUBTOTAL: {$cntBlank} rows  /  " . number_format($grandBlank, 2) . "\n\n";

// ─── 2. Explicit "Unclassified" label ────────────────────────────────────────
$r2 = $m->query("
    SELECT account_code, account_name,
           COUNT(*) AS cnt,
           ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
    FROM rpcppe_batch_items
    WHERE batch_id = $batchId
      AND (LOWER(account_name) LIKE '%unclass%'
           OR LOWER(account_code) LIKE '%unclass%')
    GROUP BY account_code, account_name
");
echo "== Rows with 'Unclassified' in account_name / account_code ==\n";
if ($r2->num_rows === 0) {
    echo "(none found)\n\n";
} else {
    while ($row = $r2->fetch_assoc()) {
        echo $row['account_code'] . "\t" . $row['account_name'] . "\t"
           . $row['cnt'] . "\t" . number_format((float)$row['total'], 2) . "\n";
    }
    echo "\n";
}

// ─── 3. Full account breakdown ───────────────────────────────────────────────
$r3 = $m->query("
    SELECT
        COALESCE(NULLIF(fund_number,''),'(blank)')   AS fund_number,
        COALESCE(NULLIF(account_code,''),'(blank)')  AS account_code,
        COALESCE(NULLIF(account_name,''),'(blank)')  AS account_name,
        COUNT(*) AS cnt,
        ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
    FROM rpcppe_batch_items
    WHERE batch_id = $batchId
    GROUP BY fund_number, account_code, account_name
    ORDER BY fund_number ASC, total DESC
");
echo "== FULL batch $batchId account breakdown (fund_number / account) ==\n";
echo implode("\t", ['fund','account_code','account_name','rows','total']) . "\n";
$gt = 0; $gRows = 0;
while ($row = $r3->fetch_assoc()) {
    $gt    += (float)$row['total'];
    $gRows += (int)$row['cnt'];
    echo $row['fund_number'] . "\t" . $row['account_code'] . "\t"
       . $row['account_name'] . "\t" . $row['cnt'] . "\t"
       . number_format((float)$row['total'], 2) . "\n";
}
echo "GRAND TOTAL: {$gRows} rows  /  " . number_format($gt, 2) . "\n";

$m->close();
