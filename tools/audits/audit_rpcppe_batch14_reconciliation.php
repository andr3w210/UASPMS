<?php
$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($m->connect_error) {
    die("Connection failed\n");
}

$targets = [
    [
        'label' => 'Office Equipment authoritative list',
        'account_code' => '1.06.05.020.00',
        'account_name' => 'Office Equipment',
        'fund_source' => '01',
        'expected_total' => 12344704.00,
        'where' => "batch_id = 14 AND remarks LIKE '%RPCPPE_2025_LIST%'",
    ],
    [
        'label' => 'ICT tagged set',
        'account_code' => '1.06.05.030.00',
        'account_name' => 'Information and Communication Technology Equipment',
        'fund_source' => '01',
        'expected_total' => 17339297.00,
        'where' => "batch_id = 14 AND remarks LIKE '%RPCPPE_2025_ICT_FUND01_LIST%'",
    ],
    [
        'label' => 'Technical and Scientific Equipment fund 01',
        'account_code' => '1.06.05.140.00',
        'account_name' => 'Technical and Scientific Equipment',
        'fund_source' => '01',
        'expected_total' => 19159114.48,
        'where' => "batch_id = 14 AND fund_source = '01' AND fund_number = '1' AND account_code = '1.06.05.140.00'",
    ],
    [
        'label' => 'Other Machinery and Equipment fund 01',
        'account_code' => '1.06.05.990.00',
        'account_name' => 'Other Machinery and Equipment',
        'fund_source' => '01',
        'expected_total' => 14859918.75,
        'where' => "batch_id = 14 AND fund_source = '01' AND fund_number = '1' AND account_code = '1.06.05.990.00'",
    ],
    [
        'label' => 'Computer Software fund 01',
        'account_code' => '1.08.01.020.00',
        'account_name' => 'Computer Software',
        'fund_source' => '01',
        'expected_total' => 7000000.00,
        'where' => "batch_id = 14 AND fund_source = '01' AND fund_number = '1' AND account_code = '1.08.01.020.00'",
    ],
];

$tagAudits = [
    'RPCPPE_2025_LIST',
    'RPCPPE_2025_ICT_FUND01_LIST',
    'RCPPEE_2025_ICT_FUND01_LIST',
    'RPCPPE_2025_COMM_FUND01_LIST',
    'RCPPEE_2025_COMM_FUND01_LIST',
];

echo "Final Reconciliation Table\n";
echo str_repeat('=', 140) . "\n";
echo "account_code\taccount_name\tfund_source\trow_count\tfinal_total\texpected_total\tstatus\tset\n";

foreach ($targets as $target) {
    $sql = "SELECT COUNT(*) AS row_count,
                   ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS final_total
            FROM rpcppe_batch_items
            WHERE {$target['where']}";
    $row = $m->query($sql)->fetch_assoc();
    $finalTotal = (float) ($row['final_total'] ?? 0);
    $expected = (float) $target['expected_total'];
    $status = abs($finalTotal - $expected) < 0.0001 ? 'OK' : 'MISMATCH';

    echo implode("\t", [
        $target['account_code'],
        $target['account_name'],
        $target['fund_source'],
        (string) $row['row_count'],
        number_format($finalTotal, 2, '.', ','),
        number_format($expected, 2, '.', ','),
        $status,
        $target['label'],
    ]) . "\n";
}

echo "\nRPCPPE/RCPPEE Tag Audit\n";
echo str_repeat('=', 140) . "\n";
echo "tag\trows\ttotal\tblank_account_rows\tblank_fund_rows\tdistinct_accounts\tdistinct_funds\n";
foreach ($tagAudits as $tag) {
    $sql = "SELECT COUNT(*) AS row_count,
                   ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total,
                   SUM(CASE WHEN account_code IS NULL OR account_code = '' THEN 1 ELSE 0 END) AS blank_account_rows,
                   SUM(CASE WHEN fund_source IS NULL OR fund_source = '' OR fund_number IS NULL OR fund_number = '' THEN 1 ELSE 0 END) AS blank_fund_rows,
                   COUNT(DISTINCT COALESCE(account_code, '(blank)')) AS distinct_accounts,
                   COUNT(DISTINCT CONCAT(COALESCE(fund_code,'(blank)'), '|', COALESCE(fund_source,'(blank)'), '|', COALESCE(fund_number,'(blank)'))) AS distinct_funds
            FROM rpcppe_batch_items
            WHERE batch_id = 14
              AND remarks LIKE '%{$tag}%'";
    $row = $m->query($sql)->fetch_assoc();
    echo implode("\t", [
        $tag,
        (string) $row['row_count'],
        number_format((float) ($row['total'] ?? 0), 2, '.', ','),
        (string) $row['blank_account_rows'],
        (string) $row['blank_fund_rows'],
        (string) $row['distinct_accounts'],
        (string) $row['distinct_funds'],
    ]) . "\n";

    $distSql = "SELECT COALESCE(account_code, '(blank)') AS account_code,
                       COALESCE(account_name, '(blank)') AS account_name,
                       COALESCE(fund_code, '(blank)') AS fund_code,
                       COALESCE(fund_source, '(blank)') AS fund_source,
                       COALESCE(fund_number, '(blank)') AS fund_number,
                       COUNT(*) AS row_count,
                       ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
                FROM rpcppe_batch_items
                WHERE batch_id = 14
                  AND remarks LIKE '%{$tag}%'
                GROUP BY account_code, account_name, fund_code, fund_source, fund_number
                ORDER BY fund_source, account_code";
    $dist = $m->query($distSql);
    while ($r = $dist->fetch_assoc()) {
        echo '  ' . implode(' | ', [
            $r['fund_code'],
            $r['fund_source'],
            $r['fund_number'],
            $r['account_code'],
            $r['account_name'],
            'rows=' . $r['row_count'],
            'total=' . number_format((float) $r['total'], 2, '.', ','),
        ]) . "\n";
    }
}

$m->close();
