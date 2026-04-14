<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
if ($m->connect_error) { die('Connection failed: '.$m->connect_error.PHP_EOL); }

$raw = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'techsci_fund01_raw_list.txt';
$target = 19159114.48;
$lines = file($raw, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) { die('Cannot read raw list'.PHP_EOL); }

$makeTokens = static function(string $item, string $desc): array {
    $tokens = [];
    $full = trim($item . ' ' . $desc);

    if (preg_match_all('/\bSN\s*:\s*([A-Za-z0-9\-\.\/]+)/i', $full, $m)) {
        foreach ($m[1] as $sn) {
            $sn = trim($sn);
            if ($sn !== '' && strcasecmp($sn, 'SN') !== 0) $tokens[] = $sn;
        }
    }

    if (preg_match_all('/\b([A-Z]{2,}[A-Z0-9\-\.]{2,})\b/', $full, $m)) {
        foreach ($m[1] as $code) {
            if (strlen($code) >= 5) $tokens[] = $code;
        }
    }

    $phrases = [
        trim($item),
        trim($desc),
    ];
    foreach ($phrases as $p) {
        if ($p !== '') $tokens[] = $p;
    }

    $tokens = array_values(array_unique(array_filter(array_map('trim', $tokens), static fn($t)=>$t!=='')));
    usort($tokens, static fn($a,$b)=>strlen($b)<=>strlen($a));
    return array_slice($tokens, 0, 6);
};

$search = $m->prepare("SELECT id, account_code, fund_code, fund_source, fund_number, item_description, serial_no,
                              ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) AS total
                       FROM rpcppe_batch_items
                       WHERE batch_id=14 AND (serial_no LIKE ? OR item_description LIKE ?)
                       ORDER BY id");

$matchedIds = [];
$unmatched = [];
$lineMatches = [];

foreach ($lines as $line) {
    $parts = array_map('trim', explode("\t", $line, 2));
    $item = $parts[0] ?? '';
    $desc = $parts[1] ?? '';
    $tokens = $makeTokens($item, $desc);

    $best = null;
    foreach ($tokens as $tok) {
        if (strlen($tok) < 3) continue;
        $like = '%' . $tok . '%';
        $search->bind_param('ss', $like, $like);
        $search->execute();
        $res = $search->get_result();
        while ($r = $res->fetch_assoc()) {
            $score = 0;
            if (stripos($r['item_description'], $item) !== false) $score += 4;
            if ($desc !== '' && stripos($r['item_description'], substr($desc,0,40)) !== false) $score += 3;
            if (stripos($r['serial_no'], $tok) !== false) $score += 5;
            if (stripos($r['item_description'], $tok) !== false) $score += 2;
            if ($best === null || $score > $best['score']) {
                $best = ['row'=>$r, 'score'=>$score, 'tok'=>$tok];
            }
        }
    }

    if ($best !== null && $best['score'] >= 4) {
        $id = (int)$best['row']['id'];
        $matchedIds[$id] = $best['row'];
        $lineMatches[] = [$item, $best['tok'], $id, (float)$best['row']['total']];
    } else {
        $unmatched[] = $line;
    }
}
$search->close();

$total = 0.0;
foreach ($matchedIds as $r) { $total += (float)$r['total']; }

echo "Input lines: " . count($lines) . PHP_EOL;
echo "Matched unique rows: " . count($matchedIds) . PHP_EOL;
echo "Matched total: " . number_format($total,2) . PHP_EOL;
echo "Target: " . number_format($target,2) . PHP_EOL;
echo "Delta: " . number_format($total - $target,2) . PHP_EOL;
echo "Unmatched lines: " . count($unmatched) . PHP_EOL . PHP_EOL;

echo "Matched rows (id | account | total):" . PHP_EOL;
foreach ($matchedIds as $id => $r) {
    echo $id . " | " . $r['account_code'] . " | " . number_format((float)$r['total'],2) . " | SN " . $r['serial_no'] . PHP_EOL;
}

if (!empty($unmatched)) {
    echo PHP_EOL . "Unmatched lines:" . PHP_EOL;
    foreach ($unmatched as $u) echo "- " . $u . PHP_EOL;
}

$m->close();
