<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

$q = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN distribution_item_detail_id IS NOT NULL THEN 1 ELSE 0 END) AS system_linked,
        SUM(CASE WHEN legacy_asset_id IS NOT NULL THEN 1 ELSE 0 END) AS legacy_linked,
        SUM(CASE WHEN distribution_item_detail_id IS NULL AND legacy_asset_id IS NULL THEN 1 ELSE 0 END) AS unlinked
      FROM rpcppe_batch_items
      WHERE batch_id=14
        AND (account_code IS NULL OR account_name IS NULL OR fund_code IS NULL OR fund_source IS NULL OR fund_number IS NULL)";
$r = $m->query($q)->fetch_assoc();

echo 'total=' . (int)$r['total'] . PHP_EOL;
echo 'system_linked=' . (int)$r['system_linked'] . PHP_EOL;
echo 'legacy_linked=' . (int)$r['legacy_linked'] . PHP_EOL;
echo 'unlinked=' . (int)$r['unlinked'] . PHP_EOL;

$q2 = "SELECT source_type, COUNT(*) c
       FROM rpcppe_batch_items
       WHERE batch_id=14
         AND (account_code IS NULL OR account_name IS NULL OR fund_code IS NULL OR fund_source IS NULL OR fund_number IS NULL)
       GROUP BY source_type";
$res = $m->query($q2);
while($row = $res->fetch_assoc()) {
    echo 'source_' . $row['source_type'] . '=' . $row['c'] . PHP_EOL;
}

$m->close();
