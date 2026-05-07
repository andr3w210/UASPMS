<?php
require_once __DIR__ . '/../bootstrap.php';
/*
Option 2: Repair/import missing ICT list rows into batch 14, then tag list rows.
- Keeps existing account codes as-is for existing rows.
- Inserts missing rows as legacy rows under Fund 01 context for reporting.
- Applies tag: RCPPEE_2025_ICT_FUND01_LIST
*/

$mysqli = tools_db();
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error . PHP_EOL);
}

$rawFile = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'ict_fund01_raw_list.txt';
$tag = 'RCPPEE_2025_ICT_FUND01_LIST';
$batchId = 14;

if (!is_file($rawFile)) {
    die('Missing raw list file: ' . $rawFile . PHP_EOL);
}

$lines = file($rawFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    die('Unable to read raw list file.' . PHP_EOL);
}

$accountMap = [];
$accRes = $mysqli->query("SELECT id, account_code, account_name FROM account_codes");
while ($r = $accRes->fetch_assoc()) {
    $accountMap[$r['account_code']] = ['id' => (int)$r['id'], 'name' => (string)$r['account_name']];
}

$deriveAccountCode = static function (string $prop): ?string {
    $prop = trim($prop);
    if ($prop === '') {
        return null;
    }
    if (preg_match('/05[.-](\d{3})[.-]/', $prop, $m)) {
        return '1.06.05.' . $m[1] . '.00';
    }
    return null;
};

$extractSerial = static function (string $desc): string {
    if (preg_match_all('/\bSN\s*:\s*([A-Za-z0-9\.\-\/]+)/i', $desc, $m) && !empty($m[1])) {
        return trim($m[1][0]);
    }
    $t = trim($desc);
    if ($t !== '' && preg_match('/^[A-Za-z0-9\.\-]{6,}$/', $t)) {
        return $t;
    }
    return '';
};

$parseAmount = static function (string $line): float {
    if (preg_match('/([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2}))\s*$/', $line, $m)) {
        return (float)str_replace(',', '', $m[1]);
    }
    return 0.0;
};

$findByTokenStmt = $mysqli->prepare(
    "SELECT id, unit_cost, qty_physical_count, property_number, serial_no
     FROM rpcppe_batch_items
     WHERE batch_id = ?
       AND (property_number LIKE ? OR serial_no LIKE ? OR item_description LIKE ?)
     ORDER BY id"
);

$insertStmt = $mysqli->prepare(
    "INSERT INTO rpcppe_batch_items (
        batch_id, source_type, property_number, item_description, description_detail,
        unit_cost, qty_property_card, qty_physical_count, serial_no,
        account_code_id, account_code, account_name,
        fund_code, fund_source, fund_number,
        remarks, is_included, is_disposed, created_at, updated_at
     ) VALUES (
        ?, 'legacy', ?, ?, ?,
        ?, 1, 1, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, 1, 0, NOW(), NOW()
     )"
);

$taggedIds = [];
$insertedCount = 0;
$matchedExisting = 0;
$expectedTotal = 0.0;
$lineCount = 0;

$mysqli->begin_transaction();
try {
    // Clear current tag in batch 14 before re-applying.
    $clearStmt = $mysqli->prepare(
        "UPDATE rpcppe_batch_items
         SET remarks = TRIM(REPLACE(REPLACE(remarks, ' | $tag', ''), '$tag', ''))
         WHERE batch_id = ? AND remarks LIKE CONCAT('%', ?, '%')"
    );
    $clearStmt->bind_param('is', $batchId, $tag);
    $clearStmt->execute();
    $clearStmt->close();

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $lineCount++;
        $expectedTotal += $parseAmount($line);

        $parts = array_map('trim', explode("\t", $line));
        $itemType = $parts[0] ?? '';
        $desc = $parts[1] ?? '';
        $prop = $parts[2] ?? '';
        $amount = $parseAmount($line);

        $prop = trim($prop, " \t\"'");
        $serial = $extractSerial($desc);

        $searchToken = $serial !== '' ? $serial : ($prop !== '' ? $prop : $desc);
        $like = '%' . $searchToken . '%';

        $candidateId = null;
        $findByTokenStmt->bind_param('isss', $batchId, $like, $like, $like);
        $findByTokenStmt->execute();
        $res = $findByTokenStmt->get_result();

        $bestScore = -1;
        while ($r = $res->fetch_assoc()) {
            $score = 0;
            $unit = (float)$r['unit_cost'];
            if (abs($unit - $amount) < 0.005) {
                $score += 5;
            }
            if ($serial !== '' && stripos((string)$r['serial_no'], $serial) !== false) {
                $score += 4;
            }
            if ($prop !== '' && stripos((string)$r['property_number'], $prop) !== false) {
                $score += 3;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $candidateId = (int)$r['id'];
            }
        }

        if ($candidateId !== null && $bestScore >= 4) {
            $taggedIds[$candidateId] = true;
            $matchedExisting++;
            continue;
        }

        // Insert missing line
        $combinedDesc = trim($itemType . '; ' . $desc, '; ');
        if ($combinedDesc === '') {
            $combinedDesc = $itemType;
        }

        $accountCode = $deriveAccountCode($prop) ?? '1.06.05.030.00';
        $accountId = null;
        $accountName = null;
        if (isset($accountMap[$accountCode])) {
            $accountId = $accountMap[$accountCode]['id'];
            $accountName = $accountMap[$accountCode]['name'];
        }

        // Fund 01 context for inserted rows
        $fundCode = 'GAA-AEP';
        $fundSource = '01';
        $fundNumber = '1';
        $remarks = $tag;

        $descriptionDetail = $desc !== '' ? $desc : null;
        $accountIdParam = $accountId;

        $insertStmt->bind_param(
            'isssdsissssss',
            $batchId,
            $prop,
            $combinedDesc,
            $descriptionDetail,
            $amount,
            $serial,
            $accountIdParam,
            $accountCode,
            $accountName,
            $fundCode,
            $fundSource,
            $fundNumber,
            $remarks
        );
        $insertStmt->execute();
        $newId = (int)$mysqli->insert_id;
        $taggedIds[$newId] = true;
        $insertedCount++;
    }

    // Apply tag to all selected IDs (including existing matches)
    if (!empty($taggedIds)) {
        $idCsv = implode(',', array_map('intval', array_keys($taggedIds)));
        $tagSql = "UPDATE rpcppe_batch_items
                   SET remarks = CASE
                        WHEN remarks IS NULL OR remarks = '' THEN '$tag'
                        WHEN remarks LIKE CONCAT('%', '$tag', '%') THEN remarks
                        ELSE CONCAT(remarks, ' | $tag')
                   END,
                   is_included = 1,
                   updated_at = NOW()
                   WHERE id IN ($idCsv)";
        $mysqli->query($tagSql);

        $sum = $mysqli->query(
            "SELECT COUNT(*) AS rows_count,
                    ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
             FROM rpcppe_batch_items
             WHERE id IN ($idCsv)"
        )->fetch_assoc();

        $mysqli->commit();

        echo 'Tag: ' . $tag . PHP_EOL;
        echo 'Input lines: ' . $lineCount . PHP_EOL;
        echo 'Expected total from list: ' . number_format($expectedTotal, 2) . PHP_EOL;
        echo 'Matched existing rows: ' . $matchedExisting . PHP_EOL;
        echo 'Inserted missing rows: ' . $insertedCount . PHP_EOL;
        echo 'Final tagged rows: ' . $sum['rows_count'] . PHP_EOL;
        echo 'Final tagged total: ' . number_format((float)$sum['total'], 2) . PHP_EOL;
        echo 'Delta vs expected: ' . number_format((float)$sum['total'] - $expectedTotal, 2) . PHP_EOL;
    } else {
        $mysqli->commit();
        echo 'No rows matched and no inserts were made.' . PHP_EOL;
    }
} catch (Throwable $e) {
    $mysqli->rollback();
    throw $e;
}

$findByTokenStmt->close();
$insertStmt->close();
$mysqli->close();
