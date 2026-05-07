<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

$target = 14859918.75;

// Tokens transcribed from the provided list image.
$tokens = [
    'Hinoki Mac No. 0671',
    'EM280789110',
    'EB 150989155',
    'ES 0505890',
    'M-2037 P4LM06',
    'NO:6677',
    'M-15142',
    '79469',
    'Mitsubishi',
    'M-IM525B',
    '5 tons cap',
    'B-310',
    'TELWIN',
    'lg-650',
    'AXH16070056',
    '10280',
    '1201317.6',
    '100616',
    '1301236.2',
    '10060N',
    '1303798.6',
    '10283',
    '1303276.20',
    '10100',
    '1601301.1',
    '10125',
    '1500742.3',
    '10285',
    '1201322.5',
    '10300A',
    '1401230.11',
    '10310',
    '1201323.28',
    '10050',
    '1203954.4',
    'UsewIndia',
    '10181810',
    '1018487',
    'ATS120R',
    'S04813',
    'QSZ13-G3',
    '93947608',
    'LSC500S3-6',
    '202312091',
    'OXITEST',
    '708681',
    'F30900248',
    'ST-15000VA',
    'S0404407',
    'MMVCSP001347028404HA1',
    'L14150',
    'Chatillon',
    'Direct Reading USA',
    'Electro-Mechanical Rotary',
    'Origin: Italy',
    'Sciencetech 2261A',
    'with tank;2 deck; 4 baking pan',
    '(XK-ZLZR1)'
];

$stmt = $m->prepare("SELECT id, account_code, fund_code, fund_source, fund_number,
                           ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
                           serial_no, item_description
                    FROM rpcppe_batch_items
                    WHERE batch_id=14
                      AND (serial_no LIKE ? OR item_description LIKE ?)
                    ORDER BY id");

$hits = [];
foreach ($tokens as $t) {
    $like = '%'.$t.'%';
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $id = (int)$r['id'];
        if (!isset($hits[$id])) {
            $hits[$id] = $r;
            $hits[$id]['matched_token'] = $t;
        }
    }
}
$stmt->close();

ksort($hits);

echo "Matched rows: ".count($hits).PHP_EOL;

$sumAll = 0.0;
$sumFund01 = 0.0;
$sumFund01OME = 0.0;

foreach ($hits as $id => $r) {
    $tot = (float)$r['total'];
    $sumAll += $tot;
    if ((string)$r['fund_source'] === '01' && (string)$r['fund_number'] === '1') {
        $sumFund01 += $tot;
        if ((string)$r['account_code'] === '1.06.05.990.00') {
            $sumFund01OME += $tot;
        }
    }

    echo $id." | acct ".$r['account_code']." | fund ".$r['fund_code']."/".$r['fund_source']."/".$r['fund_number'].
         " | ".number_format($tot,2)." | tok ".$r['matched_token']." | SN ".$r['serial_no'].PHP_EOL;
}

echo PHP_EOL;
echo "Sum (all hits): ".number_format($sumAll,2).PHP_EOL;
echo "Sum (Fund01 hits): ".number_format($sumFund01,2).PHP_EOL;
echo "Sum (Fund01 + OME acct hits): ".number_format($sumFund01OME,2).PHP_EOL;
echo "Target: ".number_format($target,2).PHP_EOL;
echo "Delta Fund01+OME vs target: ".number_format($sumFund01OME - $target,2).PHP_EOL;

// Export candidate hits for manual/automated subset selection.
$csv = __DIR__.DIRECTORY_SEPARATOR.'exports'.DIRECTORY_SEPARATOR.'ome_fund01_list_hits.csv';
$f = fopen($csv, 'w');
fputcsv($f, ['id','account_code','fund_code','fund_source','fund_number','total','serial_no','item_description','matched_token']);
foreach ($hits as $r) {
    fputcsv($f, [$r['id'],$r['account_code'],$r['fund_code'],$r['fund_source'],$r['fund_number'],$r['total'],$r['serial_no'],$r['item_description'],$r['matched_token']]);
}
fclose($f);

echo "Exported hits: $csv".PHP_EOL;

$m->close();
