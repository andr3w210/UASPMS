<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/spams/app/config/constants.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$csvPath = __DIR__ . DIRECTORY_SEPARATOR . 'stock_numbers.csv';

if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV file not found: {$csvPath}\n");
    exit(1);
}

$db = new mysqli(
    defined('DB_HOST') ? DB_HOST : '127.0.0.1',
    defined('DB_USER') ? DB_USER : 'root',
    defined('DB_PASS') ? DB_PASS : '',
    defined('DB_NAME') ? DB_NAME : 'spamsdb'
);
$db->set_charset('utf8mb4');

$handle = fopen($csvPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Unable to open CSV file.\n");
    exit(1);
}

$section = '';
$inserted = 0;
$updated = 0;
$skipped = 0;
$rowNo = 0;

$classificationMap = [];
$accountCodeMap = [];

function find_id_by_name(mysqli $db, string $table, string $idColumn, string $nameColumn, string $name): int
{
    $sql = "SELECT {$idColumn} AS id FROM {$table} WHERE {$nameColumn} = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

$classificationMap['Office Supplies'] = find_id_by_name(
    $db,
    'classifications',
    'id',
    'classification_name',
    'Office Supplies'
);
$classificationMap['Furniture and Fixtures'] = find_id_by_name(
    $db,
    'classifications',
    'id',
    'classification_name',
    'Furniture and Fixtures'
);
$classificationMap['IT Equipment'] = find_id_by_name(
    $db,
    'classifications',
    'id',
    'classification_name',
    'IT Equipment'
);

$accountCodeMap['Office Supplies'] = find_id_by_name(
    $db,
    'account_codes',
    'id',
    'account_name',
    'Office Supplies'
);
$accountCodeMap['Furniture and Fixtures'] = find_id_by_name(
    $db,
    'account_codes',
    'id',
    'account_name',
    'Furniture & Fixtures'
);
$accountCodeMap['IT Equipment'] = find_id_by_name(
    $db,
    'account_codes',
    'id',
    'account_name',
    'Computers & Peripherals'
);

$selectStmt = $db->prepare("SELECT id FROM stock_catalog WHERE stock_no = ? LIMIT 1");
$insertStmt = $db->prepare("
    INSERT INTO stock_catalog (
        stock_no, item_name, item_description, item_type,
        classification_id, account_code_id, is_active
    )
    VALUES (?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), 1)
");
$updateStmt = $db->prepare("
    UPDATE stock_catalog
    SET item_name = ?, item_description = ?, item_type = ?,
        classification_id = NULLIF(?, 0), account_code_id = NULLIF(?, 0),
        updated_at = NOW()
    WHERE id = ?
");

$db->begin_transaction();

try {
    while (($row = fgetcsv($handle)) !== false) {
        $rowNo++;
        $col1 = isset($row[0]) ? trim((string) $row[0]) : '';
        $col2 = isset($row[1]) ? trim((string) $row[1]) : '';
        $col3 = isset($row[2]) ? trim((string) $row[2]) : '';

        $firstThree = trim($col1 . $col2 . $col3);
        if ($firstThree === '') {
            continue;
        }

        if (strcasecmp($col1, 'Column1') === 0 || strcasecmp($col1, 'Item') === 0) {
            continue;
        }

        if ($col2 === '' && $col3 === '' && preg_match('/[A-Za-z]/', $col1)) {
            $section = $col1;
            continue;
        }

        if ($col1 === '' || $col3 === '') {
            $skipped++;
            continue;
        }

        $stockNo = trim($col3);
        if (!preg_match('/^[A-Z]{2,5}-/i', $stockNo)) {
            $prefix = 'SUP';
            $sectionUpper = strtoupper($section);
            if (strpos($sectionUpper, 'SEMI') !== false) {
                $prefix = 'SEMI';
            } elseif (
                strpos($sectionUpper, 'EQUIPMENT') !== false ||
                strpos($sectionUpper, 'FURN') !== false ||
                strpos($sectionUpper, 'ICT') !== false
            ) {
                $prefix = 'EQP';
            }
            $stockNo = $prefix . '-' . str_pad(preg_replace('/\D+/', '', $stockNo), 3, '0', STR_PAD_LEFT);
        }

        $itemName = trim($col1);
        $descriptionParts = [];
        if ($section !== '') {
            $descriptionParts[] = '[' . $section . ']';
        }
        if ($col2 !== '') {
            $descriptionParts[] = $col2;
        }
        $itemDescription = trim(implode(' ', $descriptionParts));

        $itemType = 'supply';
        $classificationId = $classificationMap['Office Supplies'] ?? 0;
        $accountCodeId = $accountCodeMap['Office Supplies'] ?? 0;
        $sectionUpper = strtoupper($section);
        if (strpos($sectionUpper, 'SEMI') !== false) {
            $itemType = 'semi_expendable';
        } elseif (
            strpos($sectionUpper, 'EQUIPMENT') !== false ||
            strpos($sectionUpper, 'FURN') !== false ||
            strpos($sectionUpper, 'ICT') !== false
        ) {
            $itemType = 'equipment';
        }

        if (strpos($sectionUpper, 'FURN') !== false) {
            $classificationId = $classificationMap['Furniture and Fixtures'] ?? 0;
            $accountCodeId = $accountCodeMap['Furniture and Fixtures'] ?? 0;
        } elseif (strpos($sectionUpper, 'ICT') !== false || strpos($sectionUpper, 'COMPUTER') !== false) {
            $classificationId = $classificationMap['IT Equipment'] ?? 0;
            $accountCodeId = $accountCodeMap['IT Equipment'] ?? 0;
        }

        $selectStmt->bind_param('s', $stockNo);
        $selectStmt->execute();
        $existing = $selectStmt->get_result()->fetch_assoc();

        if ($existing) {
            $existingId = (int) $existing['id'];
            $updateStmt->bind_param(
                'sssiii',
                $itemName,
                $itemDescription,
                $itemType,
                $classificationId,
                $accountCodeId,
                $existingId
            );
            $updateStmt->execute();
            $updated++;
            continue;
        }

        $insertStmt->bind_param(
            'ssssii',
            $stockNo,
            $itemName,
            $itemDescription,
            $itemType,
            $classificationId,
            $accountCodeId
        );
        $insertStmt->execute();
        $inserted++;
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    fclose($handle);
    fwrite(STDERR, "Import failed on row {$rowNo}: " . $e->getMessage() . "\n");
    exit(1);
}

fclose($handle);

echo "Stock catalog import complete.\n";
echo "Inserted: {$inserted}\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
