<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
if ($m->connect_error) die('DB connect failed'.PHP_EOL);

$raw = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'techsci_fund01_raw_list.txt';
$lines = file($raw, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) die('Cannot read list'.PHP_EOL);

// Candidate pool: ONLY Fund 01 + Technical & Scientific account
$candidates = [];
$res = $m->query("SELECT id, item_description, serial_no,
                         ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total
                  FROM rpcppe_batch_items
                  WHERE batch_id=14
                    AND fund_source='01'
                    AND fund_number='1'
                    AND account_code='1.06.05.140.00'
                  ORDER BY id");
while($r=$res->fetch_assoc()){
    $candidates[] = $r;
}

$normalize = static function(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
};

$extractTokens = static function(string $item, string $desc): array {
    $full = $item . ' ' . $desc;
    $tokens = [];

    if (preg_match_all('/\bSN\s*:\s*([A-Za-z0-9\-\.\/]+)/i', $full, $m)) {
        foreach($m[1] as $sn){
            $sn = trim($sn);
            if ($sn !== '' && strcasecmp($sn,'SN') !== 0) $tokens[] = $sn;
        }
    }

    if (preg_match_all('/\b([A-Za-z]+\d+[A-Za-z0-9\-\.]*)\b/', $full, $m)) {
        foreach($m[1] as $code){
            if (strlen($code) >= 3) $tokens[] = $code;
        }
    }

    // key phrase parts
    $tokens[] = trim($item);
    if ($desc !== '') {
        $tokens[] = trim(substr($desc, 0, 80));
    }

    $tokens = array_values(array_unique(array_filter(array_map('trim',$tokens), static fn($t)=>$t!=='')));
    usort($tokens, static fn($a,$b)=>strlen($b)<=>strlen($a));
    return array_slice($tokens,0,8);
};

$used = [];
$matches = [];
$unmatched = [];

foreach($lines as $line){
    $parts = array_map('trim', explode("\t", $line, 2));
    $item = $parts[0] ?? '';
    $desc = $parts[1] ?? '';
    $tokens = $extractTokens($item,$desc);

    $best = null;
    foreach($candidates as $cand){
        $id = (int)$cand['id'];
        if (isset($used[$id])) continue;

        $score = 0;
        $descNorm = $normalize((string)$cand['item_description']);
        $serialNorm = $normalize((string)$cand['serial_no']);

        if ($item !== '' && str_contains($descNorm, $normalize($item))) $score += 4;

        foreach($tokens as $t){
            $tn = $normalize($t);
            if ($tn === '') continue;
            if (str_contains($serialNorm, $tn)) $score += 7;
            if (str_contains($descNorm, $tn)) $score += 2;
        }

        if ($best === null || $score > $best['score']) {
            $best = ['score'=>$score,'cand'=>$cand];
        }
    }

    if ($best !== null && $best['score'] >= 5) {
        $id = (int)$best['cand']['id'];
        $used[$id] = true;
        $matches[] = [
            'line'=>$line,
            'id'=>$id,
            'score'=>$best['score'],
            'total'=>(float)$best['cand']['total'],
            'serial'=>$best['cand']['serial_no'],
            'desc'=>$best['cand']['item_description'],
        ];
    } else {
        $unmatched[] = $line;
    }
}

$total = 0.0;
foreach($matches as $mch) $total += $mch['total'];

echo 'Input lines: '.count($lines).PHP_EOL;
echo 'Matched lines: '.count($matches).PHP_EOL;
echo 'Unmatched lines: '.count($unmatched).PHP_EOL;
echo 'Matched total: '.number_format($total,2).PHP_EOL;
echo 'Target: 19,159,114.48'.PHP_EOL;
echo 'Delta: '.number_format($total - 19159114.48,2).PHP_EOL.PHP_EOL;

foreach($matches as $mch){
    echo 'ID '.$mch['id'].' | score '.$mch['score'].' | '.number_format($mch['total'],2).' | SN '.$mch['serial'].PHP_EOL;
}

if (!empty($unmatched)) {
    echo PHP_EOL.'Unmatched list lines:'.PHP_EOL;
    foreach($unmatched as $u) echo '- '.$u.PHP_EOL;
}

$m->close();
