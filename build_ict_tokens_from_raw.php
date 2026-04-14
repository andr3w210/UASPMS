<?php
/*
Extract matching tokens from raw ICT list into exports/ict_fund01_tokens.txt
- Pull property number column when present
- Pull serial numbers from 'SN:' patterns
- Pull standalone serial-like 2nd-column values
- Also compute expected list total from amount column
*/

$in = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'ict_fund01_raw_list.txt';
$out = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'ict_fund01_tokens.txt';

$lines = file($in, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    die("Cannot read input file\n");
}

$tokens = [];
$expected = 0.0;

foreach ($lines as $line) {
    $parts = array_map('trim', explode("\t", $line));

    $desc = $parts[1] ?? '';
    $prop = $parts[2] ?? '';
    $amt = $parts[count($parts)-1] ?? '';

    // Amount parsing
    $amtNum = preg_replace('/[^0-9.]/', '', $amt);
    if ($amtNum !== '') {
        $expected += (float)$amtNum;
    }

    // Property token
    $prop = trim($prop, " \t\"'");
    if ($prop !== '') {
        $tokens[$prop] = true;
    }

    // SN extractions (multiple possible)
    if (preg_match_all('/\bSN\s*:\s*([A-Za-z0-9\.\-\/]+)/i', $desc, $m)) {
        foreach ($m[1] as $sn) {
            $sn = trim($sn);
            if ($sn !== '') {
                $tokens[$sn] = true;
            }
        }
    }

    // Standalone serial-like second-column values (e.g., 8CG8361J9Y)
    if ($desc !== '' && !preg_match('/\s/', $desc) && preg_match('/^[A-Za-z0-9\.\-]{6,}$/', $desc)) {
        $tokens[$desc] = true;
    }

    // If desc has obvious model token usable for matching
    if (preg_match('/\b(Aspire\s+XC-780|TMP216-51G-53UB|TMP216-51G-52D6|C24-1851|C27-195ES|L15150|P3\.91-13S|N9120|XN3004T)\b/i', $desc, $mm)) {
        $tokens[$mm[1]] = true;
    }
}

$tokenList = array_keys($tokens);
sort($tokenList, SORT_NATURAL | SORT_FLAG_CASE);

file_put_contents($out, implode(PHP_EOL, $tokenList) . PHP_EOL);

echo "Tokens written: " . count($tokenList) . PHP_EOL;
echo "Expected amount from raw list: " . number_format($expected, 2) . PHP_EOL;
echo "Output: $out" . PHP_EOL;
