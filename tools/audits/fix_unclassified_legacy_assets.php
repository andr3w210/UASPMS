<?php
/**
 * fix_unclassified_legacy_assets.php
 *
 * Recovers missing classification_id values for legacy assets.
 *
 * Strategy:
 *  1. Match against the latest submitted RPCPPE export using the exact article
 *     column and a stable tuple: item_description + unit_cost + fund number.
 *  2. For anything still unresolved, assign a generic classification using the
 *     account name, creating that classification if it does not yet exist.
 */

require_once __DIR__ . '/../bootstrap.php';

$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME), DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function fx_norm_text(string $value): string
{
    $value = trim(str_replace(["\r", "\n", "\t"], ' ', $value));
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return mb_strtolower(trim($value), 'UTF-8');
}

function fx_money_key($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function fx_fund_number_from_code(string $fundCode): string
{
    $fundCode = strtoupper(trim($fundCode));
    if ($fundCode === '') {
        return '';
    }

    $map = [
        'GAA-GAS' => '01',
        'GAA-AEP' => '01',
        'TFCF' => '05',
        'FPSY' => '05',
        'IGP' => '06',
        'DOST1' => '07',
    ];

    foreach ($map as $prefix => $fundNumber) {
        if (str_starts_with($fundCode, $prefix)) {
            return $fundNumber;
        }
    }

    return '';
}

function fx_match_key(string $description, $unitCost, string $fundNumber): string
{
    return fx_norm_text($description) . '|' . fx_money_key($unitCost) . '|' . trim($fundNumber);
}

function fx_latest_export_path(string $baseDir): ?string
{
    $paths = glob($baseDir . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'rpcppe2025_submitted_final_*.csv');
    if (!$paths) {
        return null;
    }

    rsort($paths, SORT_STRING);
    return $paths[0] ?? null;
}

function fx_load_export_lookup(?string $path): array
{
    if (!$path || !is_file($path)) {
        return [];
    }

    $handle = fopen($path, 'rb');
    if (!$handle) {
        return [];
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return [];
    }

    $lookup = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== count($header)) {
            continue;
        }

        $assoc = array_combine($header, $row);
        if (!is_array($assoc)) {
            continue;
        }

        $article = trim((string) ($assoc['article'] ?? ''));
        $description = trim((string) ($assoc['item_description'] ?? ''));
        $fundNumber = trim((string) ($assoc['fund_source'] ?? ''));
        $unitCost = (float) ($assoc['unit_cost'] ?? 0);

        if ($article === '' || $description === '' || $unitCost == 0) {
            continue;
        }

        $key = fx_match_key($description, $unitCost, $fundNumber);
        if (!isset($lookup[$key])) {
            $lookup[$key] = [];
        }
        $lookup[$key][$article] = true;
    }

    fclose($handle);
    return $lookup;
}

function fx_find_or_create_classification(PDO $pdo, string $classificationName, int $accountCodeId): int
{
    $classificationName = trim($classificationName);
    if ($classificationName === '') {
        return 0;
    }

    $select = $pdo->prepare("\n        SELECT id\n        FROM classifications\n        WHERE LOWER(TRIM(classification_name)) COLLATE utf8mb4_unicode_ci\n            = LOWER(TRIM(?)) COLLATE utf8mb4_unicode_ci\n        LIMIT 1\n    ");
    $select->execute([$classificationName]);
    $existingId = (int) ($select->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return $existingId;
    }

    $classificationCode = 'CLS-FIX-' . date('YmdHis') . '-' . substr(md5($classificationName . '|' . $accountCodeId), 0, 8);
    $insert = $pdo->prepare("\n        INSERT INTO classifications\n            (classification_code, classification_name, classification_group, account_code_id, description, is_active)\n        VALUES (?, ?, 'asset', NULLIF(?, 0), 'Auto-created from RPCPPE legacy recovery.', 1)\n    ");
    $insert->execute([$classificationCode, $classificationName, $accountCodeId]);

    return (int) $pdo->lastInsertId();
}

$exportPath = fx_latest_export_path(__DIR__);
$exportLookup = fx_load_export_lookup($exportPath);

$legacyRows = $pdo->query("\n    SELECT\n        la.id,\n        la.property_number,\n        la.item_description,\n        la.unit_cost,\n        la.quantity,\n        COALESCE(ac.id, 0) AS account_code_id,\n        COALESCE(ac.account_code, '') AS account_code,\n        COALESCE(ac.account_name, '') AS account_name,\n        COALESCE(f.fund_code, '') AS fund_code\n    FROM legacy_assets la\n    LEFT JOIN account_codes ac ON ac.id = la.account_code_id\n    LEFT JOIN funds f ON f.id = la.fund_id\n    WHERE la.classification_id IS NULL\n      AND la.item_type IN ('equipment', 'semi_expendable')\n    ORDER BY la.id ASC\n")->fetchAll(PDO::FETCH_ASSOC);

$previewExport = [];
$previewFallbackRows = [];
$ambiguousRows = [];
$unresolvedRows = [];

foreach ($legacyRows as $row) {
    $fundNumber = fx_fund_number_from_code((string) ($row['fund_code'] ?? ''));
    $key = fx_match_key((string) ($row['item_description'] ?? ''), (float) ($row['unit_cost'] ?? 0), $fundNumber);
    $articleMatches = array_keys($exportLookup[$key] ?? []);

    if (count($articleMatches) === 1) {
        $row['article_name'] = $articleMatches[0];
        $previewExport[] = $row;
        continue;
    }

    if (count($articleMatches) > 1) {
        $row['article_options'] = implode(' | ', $articleMatches);
        $ambiguousRows[] = $row;
        continue;
    }

    $unresolvedRows[] = $row;
}

$fallbackGroups = [];
foreach ($unresolvedRows as $row) {
    $groupKey = (string) $row['account_code_id'] . '|' . (string) $row['account_name'];
    if (!isset($fallbackGroups[$groupKey])) {
        $fallbackGroups[$groupKey] = [
            'account_code_id' => (int) ($row['account_code_id'] ?? 0),
            'account_code' => (string) ($row['account_code'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'rows_to_fix' => 0,
            'total_amount' => 0.0,
        ];
    }
    $fallbackGroups[$groupKey]['rows_to_fix']++;
    $fallbackGroups[$groupKey]['total_amount'] += (float) (($row['unit_cost'] ?? 0) * ($row['quantity'] ?? 1));
    $previewFallbackRows[] = $row;
}

$previewFallback = array_values($fallbackGroups);
usort($previewFallback, static function (array $left, array $right): int {
    return strcmp((string) $left['account_code'], (string) $right['account_code']);
});

$batchRows = count($previewExport);
$fallbackRows = array_sum(array_column($previewFallback, 'rows_to_fix'));
$totalRows = $batchRows + $fallbackRows;

$message = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_fix'])) {
    try {
        $pdo->beginTransaction();

        $updatedFromExport = 0;
        $updateStmt = $pdo->prepare("UPDATE legacy_assets SET classification_id = ? WHERE id = ? AND classification_id IS NULL");

        foreach ($previewExport as $row) {
            $classificationId = fx_find_or_create_classification($pdo, (string) $row['article_name'], (int) ($row['account_code_id'] ?? 0));
            if ($classificationId <= 0) {
                continue;
            }

            $updateStmt->execute([$classificationId, (int) $row['id']]);
            $updatedFromExport += $updateStmt->rowCount();
        }

        $updatedFromFallback = 0;
        foreach ($previewFallbackRows as $row) {
            $accountName = trim((string) ($row['account_name'] ?? ''));
            if ($accountName === '') {
                continue;
            }

            $classificationId = fx_find_or_create_classification($pdo, $accountName, (int) ($row['account_code_id'] ?? 0));
            if ($classificationId <= 0) {
                continue;
            }

            $updateStmt->execute([$classificationId, (int) $row['id']]);
            $updatedFromFallback += $updateStmt->rowCount();
        }

        $pdo->commit();
        $success = true;
        $message = 'SUCCESS: Updated ' . $updatedFromExport . ' rows from RPCPPE article values and '
            . $updatedFromFallback . ' rows from generic account classifications.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        $message = 'ERROR: ' . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fix Unclassified Legacy Assets</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; padding: 20px; background: #f5f5f5; }
  h2 { color: #333; }
  h3 { color: #555; margin-top: 24px; }
  table { border-collapse: collapse; width: 100%; background: #fff; margin-bottom: 16px; }
  th { background: #2c3e50; color: #fff; padding: 7px 10px; text-align: left; }
  td { padding: 6px 10px; border-bottom: 1px solid #ddd; vertical-align: top; }
  tr:hover td { background: #f0f4f8; }
  .summary { background: #fff; border-left: 4px solid #2980b9; padding: 12px 16px; margin-bottom: 16px; }
  .warn { background: #fff3cd; border-left: 4px solid #e0a800; padding: 10px 14px; margin-bottom: 16px; }
  .ok { background: #d4edda; border-left: 4px solid #28a745; padding: 10px 14px; margin-bottom: 16px; }
  .err { background: #f8d7da; border-left: 4px solid #dc3545; padding: 10px 14px; margin-bottom: 16px; }
  button { background: #c0392b; color: #fff; border: none; padding: 10px 24px; font-size: 14px; border-radius: 4px; cursor: pointer; }
  button:hover { background: #a93226; }
  .amount { text-align: right; font-family: monospace; }
  .muted { color: #777; }
</style>
</head>
<body>

<h2>Fix Unclassified Legacy Assets - Classification Recovery</h2>

<?php if ($message): ?>
  <div class="<?= $success ? 'ok' : 'err' ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if (!$success): ?>
<div class="summary">
  <strong>Export source:</strong> <?= htmlspecialchars($exportPath ? basename($exportPath) : '(not found)') ?><br>
  <strong>Total rows to be fixed:</strong> <?= number_format($totalRows) ?><br>
  <strong>Resolved by exact RPCPPE article:</strong> <?= number_format($batchRows) ?><br>
  <strong>Resolved by generic account fallback:</strong> <?= number_format($fallbackRows) ?><br>
  <strong>Ambiguous export matches:</strong> <?= number_format(count($ambiguousRows)) ?>
</div>

<h3>Pass 1 - Exact RPCPPE Article Match</h3>
<?php if (!$previewExport): ?>
  <p class="muted">No rows resolved from the RPCPPE export.</p>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>Legacy ID</th>
      <th>Property Number</th>
      <th>Article</th>
      <th>Description</th>
      <th>Account</th>
      <th>Fund</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($previewExport as $row): ?>
    <tr>
      <td><?= (int) $row['id'] ?></td>
      <td><?= htmlspecialchars((string) $row['property_number']) ?></td>
      <td><?= htmlspecialchars((string) $row['article_name']) ?></td>
      <td><?= htmlspecialchars((string) $row['item_description']) ?></td>
      <td><?= htmlspecialchars((string) $row['account_code']) ?> - <?= htmlspecialchars((string) $row['account_name']) ?></td>
      <td><?= htmlspecialchars(fx_fund_number_from_code((string) ($row['fund_code'] ?? ''))) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h3>Pass 2 - Generic Account Fallback</h3>
<?php if (!$previewFallback): ?>
  <p class="muted">No rows need generic account fallback.</p>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>Account Code</th>
      <th>Account Name</th>
      <th>Rows</th>
      <th>Total Amount</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($previewFallback as $row): ?>
    <tr>
      <td><?= htmlspecialchars((string) $row['account_code']) ?></td>
      <td><?= htmlspecialchars((string) $row['account_name']) ?></td>
      <td class="amount"><?= number_format((int) $row['rows_to_fix']) ?></td>
      <td class="amount"><?= number_format((float) $row['total_amount'], 2) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($ambiguousRows): ?>
<div class="warn">
  <strong>Ambiguous rows were skipped.</strong>
  <div class="muted">These tuples matched multiple article names in the export and require manual review.</div>
</div>
<table>
  <thead>
    <tr>
      <th>Legacy ID</th>
      <th>Description</th>
      <th>Unit Cost</th>
      <th>Possible Articles</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($ambiguousRows as $row): ?>
    <tr>
      <td><?= (int) $row['id'] ?></td>
      <td><?= htmlspecialchars((string) $row['item_description']) ?></td>
      <td class="amount"><?= number_format((float) $row['unit_cost'], 2) ?></td>
      <td><?= htmlspecialchars((string) $row['article_options']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($totalRows > 0): ?>
<form method="POST">
  <p style="color:#c0392b;"><strong>This will update <?= number_format($totalRows) ?> legacy asset rows.</strong><br>
  Exact article names will be used where available. Remaining rows will receive a generic account classification.</p>
  <input type="hidden" name="confirm_fix" value="1">
  <button type="submit">Apply Fix</button>
</form>
<?php else: ?>
<div class="ok">No rows currently need repair.</div>
<?php endif; ?>

<?php else: ?>
<p>You may now refresh the affected asset pages. Any rows still unclassified after this run will need manual review.</p>
<?php endif; ?>

</body>
</html>