<?php
require_once __DIR__ . '/../bootstrap.php';
/*
Tag ICT list rows for RPCPPE reporting without changing account codes.

Input file (one token per line): exports/ict_fund01_tokens.txt
- Token can be serial number or unique description fragment.

Behavior:
- Scope: batch_id=14 (all account/fund values)
- Matching: property_number LIKE %token% OR serial_no LIKE %token% OR item_description LIKE %token%
- Tag: RCPPEE_2025_ICT_FUND01_LIST
- Keeps account assignment as-is.
*/

$mysqli = tools_db();
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error . PHP_EOL);
}

$tokenFile = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'ict_fund01_tokens.txt';
$tag = 'RCPPEE_2025_ICT_FUND01_LIST';

if (!is_file($tokenFile)) {
    die('Missing input file: ' . $tokenFile . PHP_EOL);
}

$lines = file($tokenFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    die('Unable to read token file.' . PHP_EOL);
}

$tokens = [];
foreach ($lines as $line) {
    $t = trim($line);
    if ($t === '' || str_starts_with($t, '#')) {
        continue;
    }
    $tokens[$t] = true;
}
$tokens = array_keys($tokens);

if (count($tokens) === 0) {
    die('No tokens found in input file.' . PHP_EOL);
}

$batchId = 14;
$scopeWhere = "batch_id = ?";

$findSql = "SELECT id, property_number, serial_no, item_description,
                   ROUND(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1), 2) AS line_total
            FROM rpcppe_batch_items
            WHERE $scopeWhere
                            AND (property_number LIKE ? OR serial_no LIKE ? OR item_description LIKE ?)
            ORDER BY id";
$findStmt = $mysqli->prepare($findSql);

$matchedIds = [];
$tokenMatchCount = 0;
$missingTokens = [];

foreach ($tokens as $token) {
    $like = '%' . $token . '%';
    $findStmt->bind_param('isss', $batchId, $like, $like, $like);
    $findStmt->execute();
    $res = $findStmt->get_result();

    $hit = 0;
    while ($row = $res->fetch_assoc()) {
        $matchedIds[(int)$row['id']] = true;
        $hit++;
    }

    if ($hit > 0) {
        $tokenMatchCount++;
    } else {
        $missingTokens[] = $token;
    }
}

$findStmt->close();

$ids = array_keys($matchedIds);
sort($ids);

if (count($ids) === 0) {
    echo 'Tokens loaded: ' . count($tokens) . PHP_EOL;
    echo 'Matched tokens: 0' . PHP_EOL;
    echo 'Matched rows: 0' . PHP_EOL;
    echo 'No rows tagged.' . PHP_EOL;
    if (count($missingTokens) > 0) {
        echo PHP_EOL . 'Unmatched tokens:' . PHP_EOL;
        foreach ($missingTokens as $t) {
            echo '- ' . $t . PHP_EOL;
        }
    }
    $mysqli->close();
    exit(0);
}

$idCsv = implode(',', array_map('intval', $ids));

$mysqli->begin_transaction();
try {
    // Remove this exact tag from current scope to avoid stale rows.
    $clearSql = "UPDATE rpcppe_batch_items
                 SET remarks = TRIM(REPLACE(REPLACE(remarks, ' | $tag', ''), '$tag', ''))
                 WHERE $scopeWhere
                   AND remarks LIKE CONCAT('%', ?, '%')";
    $clearStmt = $mysqli->prepare($clearSql);
    $clearStmt->bind_param('is', $batchId, $tag);
    $clearStmt->execute();
    $clearStmt->close();

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

    $summarySql = "SELECT COUNT(*) AS rows_count,
                          ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
                   FROM rpcppe_batch_items
                   WHERE id IN ($idCsv)";
    $summary = $mysqli->query($summarySql)->fetch_assoc();

    $taggedScopeSql = "SELECT COUNT(*) AS rows_count,
                              ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
                       FROM rpcppe_batch_items
                       WHERE $scopeWhere
                         AND remarks LIKE CONCAT('%', ?, '%')";
    $taggedScopeStmt = $mysqli->prepare($taggedScopeSql);
    $taggedScopeStmt->bind_param('is', $batchId, $tag);
    $taggedScopeStmt->execute();
    $taggedScope = $taggedScopeStmt->get_result()->fetch_assoc();
    $taggedScopeStmt->close();

    $mysqli->commit();

    echo 'Tag applied: ' . $tag . PHP_EOL;
    echo 'Tokens loaded: ' . count($tokens) . PHP_EOL;
    echo 'Tokens matched: ' . $tokenMatchCount . PHP_EOL;
    echo 'Rows tagged (unique): ' . $summary['rows_count'] . PHP_EOL;
    echo 'Tagged total (rows selected): ' . number_format((float)$summary['total'], 2) . PHP_EOL;
    echo 'Scope tagged rows now: ' . $taggedScope['rows_count'] . PHP_EOL;
    echo 'Scope tagged total now: ' . number_format((float)$taggedScope['total'], 2) . PHP_EOL;

    if (count($missingTokens) > 0) {
        echo PHP_EOL . 'Unmatched tokens:' . PHP_EOL;
        foreach ($missingTokens as $t) {
            echo '- ' . $t . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    $mysqli->rollback();
    throw $e;
}

$mysqli->close();
