<?php
require_once __DIR__ . '/../bootstrap.php';
/**
 * Audit legacy_assets with NULL classification_id (shown as "Unclassified" in registry).
 * Also shows their account_code and fund so we can identify the correct classification.
 */
$m = tools_db();
if ($m->connect_error) die('Connection failed: ' . $m->connect_error);
header('Content-Type: text/plain; charset=utf-8');

// ─── 1. Count summary ────────────────────────────────────────────────────────
$summary = $m->query("
    SELECT
        COUNT(*) AS total_unclassified,
        ROUND(SUM(unit_cost * COALESCE(NULLIF(quantity,0),1)), 2) AS total_amount
    FROM legacy_assets
    WHERE classification_id IS NULL
      AND item_type IN ('equipment','semi_expendable')
")->fetch_assoc();

echo "== Summary: Legacy assets with NULL classification_id ==\n";
echo "Total rows : " . $summary['total_unclassified'] . "\n";
echo "Total amount: " . number_format((float)$summary['total_amount'], 2) . "\n\n";

// ─── 2. Breakdown by account code ────────────────────────────────────────────
$r2 = $m->query("
    SELECT
        COALESCE(NULLIF(ac.account_code,''),'(no account)') AS account_code,
        COALESCE(NULLIF(ac.account_name,''),'(no account)') AS account_name,
        COUNT(*) AS cnt,
        ROUND(SUM(la.unit_cost * COALESCE(NULLIF(la.quantity,0),1)), 2) AS total
    FROM legacy_assets la
    LEFT JOIN account_codes ac ON ac.id = la.account_code_id
    WHERE la.classification_id IS NULL
      AND la.item_type IN ('equipment','semi_expendable')
    GROUP BY ac.account_code, ac.account_name
    ORDER BY total DESC
");
echo "== Breakdown by account code ==\n";
echo implode("\t", ['account_code','account_name','rows','total']) . "\n";
while ($row = $r2->fetch_assoc()) {
    echo $row['account_code'] . "\t" . $row['account_name'] . "\t"
       . $row['cnt'] . "\t" . number_format((float)$row['total'], 2) . "\n";
}

// ─── 3. Full list of unclassified items ──────────────────────────────────────
$r3 = $m->query("
    SELECT
        la.id,
        la.property_number,
        la.item_description,
        la.item_type,
        la.unit_cost,
        la.quantity,
        ROUND(la.unit_cost * COALESCE(NULLIF(la.quantity,0),1), 2) AS line_total,
        la.acquisition_date,
        la.created_at,
        COALESCE(NULLIF(ac.account_code,''),'(none)') AS account_code,
        COALESCE(NULLIF(ac.account_name,''),'(none)') AS account_name,
        COALESCE(NULLIF(f.fund_code,''),'(none)')    AS fund_code,
        COALESCE(NULLIF(f.fund_name,''),'(none)')    AS fund_name,
        la.remarks
    FROM legacy_assets la
    LEFT JOIN account_codes ac ON ac.id = la.account_code_id
    LEFT JOIN funds f ON f.id = la.fund_id
    WHERE la.classification_id IS NULL
      AND la.item_type IN ('equipment','semi_expendable')
    ORDER BY la.account_code_id, la.id
");
echo "\n== Full list of unclassified legacy assets ==\n";
echo implode("\t", ['id','property_number','item_description','item_type','unit_cost','qty','line_total','acq_date','created_at','account_code','account_name','fund_code','fund_name','remarks']) . "\n";
$count = 0;
while ($row = $r3->fetch_assoc()) {
    echo $row['id'] . "\t"
       . $row['property_number'] . "\t"
       . substr($row['item_description'], 0, 60) . "\t"
       . $row['item_type'] . "\t"
       . number_format((float)$row['unit_cost'], 2) . "\t"
       . $row['quantity'] . "\t"
       . number_format((float)$row['line_total'], 2) . "\t"
       . $row['acquisition_date'] . "\t"
       . $row['created_at'] . "\t"
       . $row['account_code'] . "\t"
       . $row['account_name'] . "\t"
       . $row['fund_code'] . "\t"
       . $row['fund_name'] . "\t"
       . substr((string)$row['remarks'], 0, 60) . "\n";
    $count++;
}
echo "\nTotal unclassified rows listed: $count\n";

$m->close();
