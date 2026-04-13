<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$page_title = 'RPCPPE Batches';
$flash = get_flash();
$errors = [];
$batches = [];
$selectedBatch = null;
$selectedBatchId = (int) ($_GET['batch_id'] ?? 0);
$itemFilter = trim((string) ($_GET['item_filter'] ?? 'all'));
$search = trim((string) ($_GET['search'] ?? ''));

if (!in_array($itemFilter, ['all', 'included', 'excluded', 'disposed'], true)) {
    $itemFilter = 'all';
}

function ensure_rpcppe_batch_tables(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS rpcppe_batches (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch_year SMALLINT UNSIGNED NOT NULL,
        batch_name VARCHAR(150) NOT NULL,
        as_of_date DATE NOT NULL,
        status ENUM('draft', 'finalized') NOT NULL DEFAULT 'draft',
        notes TEXT NULL,
        created_by BIGINT UNSIGNED NULL,
        finalized_by BIGINT UNSIGNED NULL,
        finalized_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_rpcppe_batches_year_status (batch_year, status),
        KEY idx_rpcppe_batches_as_of_date (as_of_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS rpcppe_batch_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch_id BIGINT UNSIGNED NOT NULL,
        source_type ENUM('system', 'legacy') NOT NULL,
        distribution_item_detail_id BIGINT UNSIGNED NULL,
        legacy_asset_id BIGINT UNSIGNED NULL,
        property_number VARCHAR(120) NOT NULL,
        item_description TEXT NOT NULL,
        description_detail TEXT NULL,
        classification_name VARCHAR(255) NULL,
        classification_family VARCHAR(255) NULL,
        uom_name VARCHAR(120) NULL,
        abbreviation VARCHAR(60) NULL,
        unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        brand VARCHAR(200) NULL,
        model VARCHAR(200) NULL,
        serial_no VARCHAR(200) NULL,
        office_id BIGINT UNSIGNED NULL,
        office_name VARCHAR(255) NULL,
        employee_id BIGINT UNSIGNED NULL,
        employee_name VARCHAR(255) NULL,
        account_code_id BIGINT UNSIGNED NULL,
        account_code VARCHAR(100) NULL,
        account_name VARCHAR(255) NULL,
        fund_code VARCHAR(100) NULL,
        fund_source VARCHAR(150) NULL,
        fund_number VARCHAR(10) NULL,
        remarks VARCHAR(120) NULL,
        is_included TINYINT(1) NOT NULL DEFAULT 1,
        is_disposed TINYINT(1) NOT NULL DEFAULT 0,
        disposed_at DATE NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_rpcppe_batch_items_batch_include (batch_id, is_included, is_disposed),
        KEY idx_rpcppe_batch_items_property (property_number),
        KEY idx_rpcppe_batch_items_system_asset (distribution_item_detail_id),
        KEY idx_rpcppe_batch_items_legacy_asset (legacy_asset_id),
        UNIQUE KEY uq_rpcppe_batch_system_item (batch_id, distribution_item_detail_id),
        UNIQUE KEY uq_rpcppe_batch_legacy_item (batch_id, legacy_asset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function rpcppe_batch_label(array $batch): string
{
    return trim((string) ($batch['batch_name'] ?? ''));
}

function rpcppe_fetch_live_rows(mysqli $db, string $asOf): array
{
    ensure_legacy_assets_fund_column($db);
    $rows = [];

    $systemSql = "
        SELECT
            'system' AS source_type,
            did.id AS distribution_item_detail_id,
            NULL AS legacy_asset_id,
            did.property_number,
            poi.item_description,
            poi.item_description AS description_detail,
            c.classification_name,
            c.classification_family,
            u.uom_name,
            u.abbreviation,
            ri.unit_cost,
            r.received_date AS acquisition_date,
            rid.brand,
            rid.model,
            rid.serial_no,
            COALESCE(curr_o.id, o.id) AS office_id,
            COALESCE(curr_o.office_name, o.office_name) AS office_name,
            COALESCE(curr_e.id, e.id) AS employee_id,
            TRIM(CONCAT_WS(' ',
                COALESCE(curr_e.first_name, e.first_name),
                COALESCE(curr_e.middle_name, e.middle_name),
                COALESCE(curr_e.last_name, e.last_name),
                COALESCE(curr_e.suffix_name, e.suffix_name)
            )) AS employee_name,
            ac.id AS account_code_id,
            ac.account_code,
            ac.account_name,
            f.fund_code,
            f.fund_source,
            1 AS qty_property_card,
            1 AS qty_physical_count
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'par'
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
        LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
        LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
        LEFT JOIN receivings r ON r.id = ri.receiving_id
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
        LEFT JOIN disposals dp ON dp.distribution_item_detail_id = did.id AND dp.status = 'posted' AND dp.disposal_date <= ?
        LEFT JOIN returns rt ON rt.distribution_item_detail_id = did.id AND rt.status = 'posted' AND rt.return_date <= ?
        WHERE d.distribution_date <= ?
          AND dp.id IS NULL
          AND rt.id IS NULL
        ORDER BY ac.account_code ASC, c.classification_name ASC, poi.item_description ASC, did.property_number ASC
    ";

    $stmt = $db->prepare($systemSql);
    if ($stmt) {
        $stmt->bind_param('sss', $asOf, $asOf, $asOf);
        $stmt->execute();
        $systemRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($systemRows as $row) {
            $row['fund_number'] = fund_number_from_source($row['fund_code'] ?? '', $row['fund_source'] ?? '');
            $row['remarks'] = '';
            $rows[] = $row;
        }
    }

    $legacySql = "
        SELECT
            'legacy' AS source_type,
            NULL AS distribution_item_detail_id,
            la.id AS legacy_asset_id,
            la.property_number,
            la.item_description,
            la.item_description AS description_detail,
            c.classification_name,
            c.classification_family,
            '' AS uom_name,
            '' AS abbreviation,
            la.unit_cost,
            la.acquisition_date,
            la.brand,
            la.model,
            la.serial_no,
            o.id AS office_id,
            o.office_name,
            e.id AS employee_id,
            TRIM(CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name, e.suffix_name)) AS employee_name,
            ac.id AS account_code_id,
            ac.account_code,
            ac.account_name,
            f.fund_code,
            f.fund_source,
            la.quantity AS qty_property_card,
            la.quantity AS qty_physical_count
        FROM legacy_assets la
        LEFT JOIN classifications c ON c.id = la.classification_id
        LEFT JOIN account_codes ac ON ac.id = la.account_code_id
        LEFT JOIN funds f ON f.id = la.fund_id
        LEFT JOIN offices o ON o.id = la.office_id
        LEFT JOIN employees e ON e.id = la.employee_id
        WHERE la.is_active = 1
          AND la.item_type = 'equipment'
          AND (la.acquisition_date IS NULL OR la.acquisition_date <= ?)
        ORDER BY ac.account_code ASC, c.classification_name ASC, la.item_description ASC, la.property_number ASC
    ";

    $legacyStmt = $db->prepare($legacySql);
    if ($legacyStmt) {
        $legacyStmt->bind_param('s', $asOf);
        $legacyStmt->execute();
        $legacyRows = $legacyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $legacyStmt->close();
        foreach ($legacyRows as $row) {
            $row['fund_number'] = fund_number_from_source($row['fund_code'] ?? '', $row['fund_source'] ?? '');
            $row['remarks'] = 'Beginning Balance';
            $rows[] = $row;
        }
    }

    return $rows;
}

function rpcppe_sync_batch_disposals(mysqli $db, int $batchId, string $asOf): void
{
    $resetStmt = $db->prepare("UPDATE rpcppe_batch_items SET is_disposed = 0, disposed_at = NULL WHERE batch_id = ?");
    if ($resetStmt) {
        $resetStmt->bind_param('i', $batchId);
        $resetStmt->execute();
        $resetStmt->close();
    }

    $systemUpdate = $db->prepare("UPDATE rpcppe_batch_items bi
        LEFT JOIN (
            SELECT distribution_item_detail_id, MIN(disposal_date) AS disposed_date
            FROM disposals
            WHERE status = 'posted'
              AND distribution_item_detail_id IS NOT NULL
              AND disposal_date <= ?
            GROUP BY distribution_item_detail_id
        ) dp ON dp.distribution_item_detail_id = bi.distribution_item_detail_id
        LEFT JOIN (
            SELECT distribution_item_detail_id, MIN(return_date) AS returned_date
            FROM returns
            WHERE status = 'posted'
              AND distribution_item_detail_id IS NOT NULL
              AND return_date <= ?
            GROUP BY distribution_item_detail_id
        ) rt ON rt.distribution_item_detail_id = bi.distribution_item_detail_id
        SET bi.is_disposed = CASE WHEN dp.disposed_date IS NOT NULL OR rt.returned_date IS NOT NULL THEN 1 ELSE 0 END,
            bi.disposed_at = COALESCE(dp.disposed_date, rt.returned_date)
        WHERE bi.batch_id = ?
          AND bi.source_type = 'system'");
    if ($systemUpdate) {
        $systemUpdate->bind_param('ssi', $asOf, $asOf, $batchId);
        $systemUpdate->execute();
        $systemUpdate->close();
    }

    $hasDisposalSource = function_exists('schema_has_column') ? schema_has_column($db, 'disposals', 'source_type') : false;
    $hasDisposalLegacyId = function_exists('schema_has_column') ? schema_has_column($db, 'disposals', 'legacy_asset_id') : false;

    $legacySql = "UPDATE rpcppe_batch_items bi
        LEFT JOIN legacy_assets la ON la.id = bi.legacy_asset_id
        SET bi.is_disposed = CASE WHEN COALESCE(la.is_active, 1) = 0 THEN 1 ELSE 0 END,
            bi.disposed_at = CASE WHEN COALESCE(la.is_active, 1) = 0 THEN ? ELSE NULL END
        WHERE bi.batch_id = ?
          AND bi.source_type = 'legacy'";
    $legacyStmt = $db->prepare($legacySql);
    if ($legacyStmt) {
        $legacyStmt->bind_param('si', $asOf, $batchId);
        $legacyStmt->execute();
        $legacyStmt->close();
    }

    if ($hasDisposalSource && $hasDisposalLegacyId) {
        $legacyDisposals = $db->prepare("UPDATE rpcppe_batch_items bi
            INNER JOIN (
                SELECT legacy_asset_id, MIN(disposal_date) AS disposed_date
                FROM disposals
                WHERE status = 'posted'
                  AND source_type = 'legacy'
                  AND legacy_asset_id IS NOT NULL
                  AND disposal_date <= ?
                GROUP BY legacy_asset_id
            ) dp ON dp.legacy_asset_id = bi.legacy_asset_id
            SET bi.is_disposed = 1,
                bi.disposed_at = dp.disposed_date
            WHERE bi.batch_id = ?
              AND bi.source_type = 'legacy'");
        if ($legacyDisposals) {
            $legacyDisposals->bind_param('si', $asOf, $batchId);
            $legacyDisposals->execute();
            $legacyDisposals->close();
        }
    }
}

function rpcppe_insert_batch_rows(mysqli $db, int $batchId, array $rows): int
{
    $insertStmt = $db->prepare("INSERT INTO rpcppe_batch_items
        (batch_id, source_type, distribution_item_detail_id, legacy_asset_id, property_number, item_description, description_detail, classification_name, classification_family, uom_name, abbreviation, unit_cost, acquisition_date, qty_property_card, qty_physical_count, brand, model, serial_no, office_id, office_name, employee_id, employee_name, account_code_id, account_code, account_name, fund_code, fund_source, fund_number, remarks, is_included)
        VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, NULLIF(?, 0), ?, NULLIF(?, 0), ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, 1)
    ");
    if (!$insertStmt) {
        return 0;
    }

    $inserted = 0;
    foreach ($rows as $row) {
        $sourceType = (string) ($row['source_type'] ?? 'system');
        $distributionId = !empty($row['distribution_item_detail_id']) ? (int) $row['distribution_item_detail_id'] : 0;
        $legacyId = !empty($row['legacy_asset_id']) ? (int) $row['legacy_asset_id'] : 0;
        $propertyNumber = trim((string) ($row['property_number'] ?? ''));
        if ($propertyNumber === '') {
            continue;
        }

        $itemDescription = trim((string) ($row['item_description'] ?? ''));
        $descriptionDetail = trim((string) ($row['description_detail'] ?? $itemDescription));
        $classificationName = trim((string) ($row['classification_name'] ?? ''));
        $classificationFamily = trim((string) ($row['classification_family'] ?? ''));
        $uomName = trim((string) ($row['uom_name'] ?? ''));
        $abbreviation = trim((string) ($row['abbreviation'] ?? ''));
        $unitCost = (float) ($row['unit_cost'] ?? 0);
        $acquisitionDate = trim((string) ($row['acquisition_date'] ?? ''));
        $qtyPropertyCard = max(1, (int) ($row['qty_property_card'] ?? 1));
        $qtyPhysicalCount = max(1, (int) ($row['qty_physical_count'] ?? 1));
        $brand = trim((string) ($row['brand'] ?? ''));
        $model = trim((string) ($row['model'] ?? ''));
        $serialNo = trim((string) ($row['serial_no'] ?? ''));
        $officeId = !empty($row['office_id']) ? (int) $row['office_id'] : 0;
        $officeName = trim((string) ($row['office_name'] ?? ''));
        $employeeId = !empty($row['employee_id']) ? (int) $row['employee_id'] : 0;
        $employeeName = trim((string) ($row['employee_name'] ?? ''));
        $accountCodeId = !empty($row['account_code_id']) ? (int) $row['account_code_id'] : 0;
        $accountCode = trim((string) ($row['account_code'] ?? ''));
        $accountName = trim((string) ($row['account_name'] ?? ''));
        $fundCode = trim((string) ($row['fund_code'] ?? ''));
        $fundSource = trim((string) ($row['fund_source'] ?? ''));
        $fundNumber = trim((string) ($row['fund_number'] ?? ''));
        $remarks = trim((string) ($row['remarks'] ?? ''));

        $insertStmt->bind_param(
            'isiisssssssdsiissississssss',
            $batchId,
            $sourceType,
            $distributionId,
            $legacyId,
            $propertyNumber,
            $itemDescription,
            $descriptionDetail,
            $classificationName,
            $classificationFamily,
            $uomName,
            $abbreviation,
            $unitCost,
            $acquisitionDate,
            $qtyPropertyCard,
            $qtyPhysicalCount,
            $brand,
            $model,
            $serialNo,
            $officeId,
            $officeName,
            $employeeId,
            $employeeName,
            $accountCodeId,
            $accountCode,
            $accountName,
            $fundCode,
            $fundSource,
            $fundNumber,
            $remarks
        );

        if ($insertStmt->execute()) {
            $inserted++;
        }
    }

    $insertStmt->close();
    return $inserted;
}

function rpcppe_copy_carry_forward_items(mysqli $db, int $targetBatchId, int $sourceBatchId): int
{
    $copyStmt = $db->prepare("INSERT INTO rpcppe_batch_items
        (batch_id, source_type, distribution_item_detail_id, legacy_asset_id, property_number, item_description, description_detail, classification_name, classification_family, uom_name, abbreviation, unit_cost, acquisition_date, qty_property_card, qty_physical_count, brand, model, serial_no, office_id, office_name, employee_id, employee_name, account_code_id, account_code, account_name, fund_code, fund_source, fund_number, remarks, is_included, is_disposed, disposed_at)
        SELECT
            ?, source_type, distribution_item_detail_id, legacy_asset_id, property_number, item_description, description_detail, classification_name, classification_family, uom_name, abbreviation, unit_cost, acquisition_date, qty_property_card, qty_physical_count, brand, model, serial_no, office_id, office_name, employee_id, employee_name, account_code_id, account_code, account_name, fund_code, fund_source, fund_number, remarks, is_included, is_disposed, disposed_at
        FROM rpcppe_batch_items
        WHERE batch_id = ?
          AND is_included = 1
          AND is_disposed = 0
          AND (
                (distribution_item_detail_id IS NULL AND legacy_asset_id IS NOT NULL AND legacy_asset_id NOT IN (SELECT legacy_asset_id FROM rpcppe_batch_items WHERE batch_id = ? AND legacy_asset_id IS NOT NULL))
             OR (legacy_asset_id IS NULL AND distribution_item_detail_id IS NOT NULL AND distribution_item_detail_id NOT IN (SELECT distribution_item_detail_id FROM rpcppe_batch_items WHERE batch_id = ? AND distribution_item_detail_id IS NOT NULL))
          )
    ");
    if (!$copyStmt) {
        return 0;
    }

    $copyStmt->bind_param('iiii', $targetBatchId, $sourceBatchId, $targetBatchId, $targetBatchId);
    $copyStmt->execute();
    $copied = (int) $copyStmt->affected_rows;
    $copyStmt->close();

    return $copied;
}

function rpcppe_load_batches(mysqli $db): array
{
    $sql = "SELECT
            b.*,
            SUM(CASE WHEN i.id IS NOT NULL THEN 1 ELSE 0 END) AS total_items,
            SUM(CASE WHEN i.is_included = 1 THEN 1 ELSE 0 END) AS included_items,
            SUM(CASE WHEN i.is_included = 0 THEN 1 ELSE 0 END) AS excluded_items,
            SUM(CASE WHEN i.is_disposed = 1 THEN 1 ELSE 0 END) AS disposed_items
        FROM rpcppe_batches b
        LEFT JOIN rpcppe_batch_items i ON i.batch_id = b.id
        GROUP BY b.id
        ORDER BY b.batch_year DESC, b.id DESC
        LIMIT 60";
    $result = $db->query($sql);
    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function rpcppe_extract_property_numbers_from_xlsx(string $filePath): array
{
    $numbers = [];
    $stats = [
        'sheets_processed' => 0,
        'data_rows' => 0,
        'rows_with_property_number' => 0,
        'rows_without_property_number' => 0,
        'hidden_rows_skipped' => 0,
    ];
    $errors = [];

    if (!class_exists('ZipArchive')) {
        return [[], $stats, ['ZipArchive extension is not available in PHP.']];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return [[], $stats, ['Unable to open Excel file.']];
    }

    try {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            return [[], $stats, ['Excel workbook metadata is missing or unreadable.']];
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        if (!$workbook || !$rels) {
            return [[], $stats, ['Unable to parse workbook XML content.']];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sharedDoc = simplexml_load_string($sharedXml);
            if ($sharedDoc) {
                $sharedDoc->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $sharedNodes = $sharedDoc->xpath('//x:si');
                if (is_array($sharedNodes)) {
                    foreach ($sharedNodes as $si) {
                        $si->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                        $textParts = $si->xpath('.//x:t');
                        $textValue = '';
                        if (is_array($textParts)) {
                            foreach ($textParts as $part) {
                                $textValue .= (string) $part;
                            }
                        }
                        $sharedStrings[] = $textValue;
                    }
                }
            }
        }

        $workbook->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $relMap = [];
        $relNodes = $rels->xpath('//r:Relationship');
        if (is_array($relNodes)) {
            foreach ($relNodes as $relNode) {
                $id = (string) ($relNode['Id'] ?? '');
                $target = (string) ($relNode['Target'] ?? '');
                if ($id !== '' && $target !== '') {
                    $relMap[$id] = 'xl/' . ltrim($target, '/');
                }
            }
        }

        $sheetNodes = $workbook->xpath('//x:sheets/x:sheet');
        if (!is_array($sheetNodes)) {
            return [[], $stats, ['No sheets found in workbook.']];
        }

        foreach ($sheetNodes as $sheetNode) {
            $sheetName = trim((string) ($sheetNode['name'] ?? ''));
            if (in_array(strtoupper($sheetName), ['SUMMARY', 'CERTIFY'], true)) {
                continue;
            }

            $rid = (string) ($sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id ?? '');
            if ($rid === '' || !isset($relMap[$rid])) {
                continue;
            }

            $sheetXml = $zip->getFromName($relMap[$rid]);
            if ($sheetXml === false) {
                continue;
            }

            $sheet = simplexml_load_string($sheetXml);
            if (!$sheet) {
                continue;
            }

            $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rowNodes = $sheet->xpath('//x:sheetData/x:row');
            if (!is_array($rowNodes) || $rowNodes === []) {
                continue;
            }

            $stats['sheets_processed']++;
            $headerRow = 11;

            foreach ($rowNodes as $rowNode) {
                $rowIndex = (int) ($rowNode['r'] ?? 0);
                if ($rowIndex <= $headerRow) {
                    continue;
                }

                if ((string) ($rowNode['hidden'] ?? '') === '1') {
                    $stats['hidden_rows_skipped']++;
                    continue;
                }

                $cells = [];
                $rowNode->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cellNodes = $rowNode->xpath('./x:c');
                if (!is_array($cellNodes)) {
                    continue;
                }

                foreach ($cellNodes as $cellNode) {
                    $ref = (string) ($cellNode['r'] ?? '');
                    $col = preg_replace('/\d+/', '', $ref);
                    if ($col === '') {
                        continue;
                    }

                    $cellNode->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $type = (string) ($cellNode['t'] ?? '');
                    $value = '';

                    if ($type === 's') {
                        $vNodes = $cellNode->xpath('./x:v');
                        $sharedIndex = (is_array($vNodes) && isset($vNodes[0])) ? (int) ((string) $vNodes[0]) : -1;
                        if ($sharedIndex >= 0 && isset($sharedStrings[$sharedIndex])) {
                            $value = (string) $sharedStrings[$sharedIndex];
                        }
                    } elseif ($type === 'inlineStr') {
                        $tNodes = $cellNode->xpath('.//x:t');
                        if (is_array($tNodes)) {
                            foreach ($tNodes as $tNode) {
                                $value .= (string) $tNode;
                            }
                        }
                    } else {
                        $vNodes = $cellNode->xpath('./x:v');
                        if (is_array($vNodes) && isset($vNodes[0])) {
                            $value = (string) $vNodes[0];
                        }
                    }

                    $cells[$col] = trim($value);
                }

                $article = trim((string) ($cells['A'] ?? ''));
                $description = trim((string) ($cells['B'] ?? ''));
                $uom = trim((string) ($cells['D'] ?? ''));
                $unitValue = trim((string) ($cells['E'] ?? ''));

                $articleUpper = strtoupper($article);
                $descriptionUpper = strtoupper($description);
                if ($articleUpper === 'TOTAL' || $descriptionUpper === 'TOTAL' || str_starts_with($articleUpper, 'TOTAL ')) {
                    continue;
                }

                if ($article === '' && $description === '') {
                    continue;
                }
                if ($uom === '' && $unitValue === '') {
                    continue;
                }

                $stats['data_rows']++;
                $propertyNumber = strtoupper(trim((string) ($cells['C'] ?? '')));

                if ($propertyNumber === '' || $propertyNumber === '(BLANK)') {
                    $stats['rows_without_property_number']++;
                    continue;
                }

                $stats['rows_with_property_number']++;
                $numbers[$propertyNumber] = true;
            }
        }
    } finally {
        $zip->close();
    }

    return [array_keys($numbers), $stats, $errors];
}

function rpcppe_norm(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function rpcppe_sheet_account_name(string $sheetName): string
{
    $map = [
        'land' => 'Land',
        'water' => 'Water Supply Systems',
        'power' => 'Power Supply Systems',
        'building' => 'Buildings',
        'machinery' => 'Machinery',
        'office_equipment' => 'Office Equipment',
        'information_communication' => 'Information and Communication Technology Equipment',
        'communication_equipment' => 'Communication Equipment',
        'disaster' => 'Disaster Response and Rescue Equipment',
        'sports_equipment' => 'Sports Equipment',
        'technical_and_scientific' => 'Technical and Scientific Equipment',
        'other_machinery_and_equipment' => 'Other Machinery and Equipment',
        'furniture_fixture' => 'Furniture and Fixtures',
        'computer_software' => 'Computer Software',
        'motor_vehicles' => 'Motor Vehicles',
        'military' => 'Military, Police and Security Equipment',
        'medical' => 'Medical Equipment',
    ];

    $key = rpcppe_norm($sheetName);
    return $map[$key] ?? trim($sheetName);
}

function rpcppe_extract_account_code_token(string $value): string
{
    if (preg_match('/(\d+(?:[\.\-]\d+){2,})/', $value, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function rpcppe_normalize_account_code_token(string $value): string
{
    return preg_replace('/[^0-9]/', '', $value) ?? '';
}

function rpcppe_strip_leading_account_code_label(string $value): string
{
    return trim((string) (preg_replace('/^\s*\d+(?:[\.\-]\d+){2,}\s*[-:\/|]*\s*/', '', $value) ?? $value));
}

function rpcppe_excel_serial_to_date($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{4})\-(\d{1,2})\-(\d{1,2})$/', $value, $matches)) {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        if ($year >= 1900 && $year <= 2100 && checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    $normalized = str_replace('.', '-', $value);
    $formats = ['m/d/Y', 'd/m/Y', 'm-d-Y', 'd-m-Y', 'm/d/y', 'd/m/y', 'm-d-y', 'd-m-y'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $normalized);
        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d');
        }
    }

    if (!is_numeric($value)) {
        return '';
    }

    $serial = (int) floor((float) $value);
    if ($serial <= 0) {
        return '';
    }

    $base = new DateTimeImmutable('1899-12-30');
    return $base->modify('+' . $serial . ' days')->format('Y-m-d');
}

function rpcppe_quantity_from_excel_row(array $row): int
{
    $physical = (int) round((float) ($row['qty_physical_count'] ?? 0));
    if ($physical > 0) {
        return $physical;
    }
    $card = (int) round((float) ($row['qty_property_card'] ?? 0));
    return $card > 0 ? $card : 1;
}

function rpcppe_extract_asset_metadata_from_description(string $description): array
{
    $description = trim((string) preg_replace('/\s+/', ' ', $description));
    if ($description === '') {
        return ['brand' => '', 'model' => '', 'serial_no' => ''];
    }

    $clean = static function (string $value): string {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        return trim($value, " \t\n\r\0\x0B;,:-.");
    };

    $brand = '';
    $model = '';
    $serialNo = '';
    $stopWords = ['with', 'without', 'set', 'unit', 'split', 'floor', 'mounted', 'outdoor', 'indoor'];
    $looksLikeBrand = static function (string $value) use ($stopWords): bool {
        $value = trim($value);
        if ($value === '' || strlen($value) > 30) {
            return false;
        }

        if (preg_match('/\d{3,}/', $value)) {
            return false;
        }

        if (stripos($value, 'model') !== false || stripos($value, 'serial') !== false || stripos($value, 'sn') !== false) {
            return false;
        }

        $words = preg_split('/\s+/', strtolower($value)) ?: [];
        if ($words !== [] && count(array_diff($words, $stopWords)) === 0) {
            return false;
        }

        return !in_array(strtolower($value), $stopWords, true);
    };
    $cleanSerial = static function (string $value) use ($clean): string {
        $value = $clean($value);
        $value = ltrim($value, ',');
        $value = trim((string) preg_replace('/\s*Indoor\s*:.*/i', '', $value));
        return $clean($value);
    };

    if (preg_match('/\bbrand\b\s*[:#-]?\s*([^;\n]+)/i', $description, $matches)) {
        $brand = $clean((string) ($matches[1] ?? ''));
        $brand = $clean((string) (preg_replace('/\bmodel\b.*$/i', '', $brand) ?? $brand));
        if (!$looksLikeBrand($brand)) {
            $brand = '';
        }
    }

    if (preg_match('/\bmodel\b\s*[:#-]?\s*(.+?)(?=(?:\s*[,;]|\b(?:s\s*\/?\s*n|serial(?:\s*no)?)\b|$))/i', $description, $matches)) {
        $model = $clean((string) ($matches[1] ?? ''));
    }

    if ($brand === '' && preg_match('/-\s*([A-Za-z][A-Za-z0-9\-]{1,24})\s+model\b/i', $description, $matches)) {
        $candidateBrand = $clean((string) ($matches[1] ?? ''));
        if ($looksLikeBrand($candidateBrand)) {
            $brand = $candidateBrand;
        }
    }

    if (preg_match('/[,;]\s*([A-Za-z][A-Za-z0-9&\-]{1,24})\s*[-:]\s*([A-Za-z0-9\-\/]{2,30})/i', $description, $matches)) {
        $candidateBrand = $clean((string) ($matches[1] ?? ''));
        $candidateModel = $clean((string) ($matches[2] ?? ''));
        if ($brand === '' && $looksLikeBrand($candidateBrand)) {
            $brand = $candidateBrand;
        }
        if ($model === '' && $candidateModel !== '') {
            $model = $candidateModel;
        }
    }

    if ($brand === '' && preg_match('/^([A-Za-z][A-Za-z0-9&\-]{1,24}).*?;\s*model\b/i', $description, $matches)) {
        $candidateBrand = $clean((string) ($matches[1] ?? ''));
        if ($looksLikeBrand($candidateBrand)) {
            $brand = $candidateBrand;
        }
    }

    if ($brand === '' && preg_match('/;\s*([A-Za-z][A-Za-z0-9&\-\'\s]{1,40})\s*(?:,\s*)?(?:SN|S\/?N|Serial)\b/i', $description, $matches)) {
        $candidateBrand = $clean((string) ($matches[1] ?? ''));
        if ($looksLikeBrand($candidateBrand)) {
            $brand = $candidateBrand;
        }
    }

    if ($brand === '' && preg_match('/^([A-Za-z][A-Za-z0-9&\-]{1,24})\s*[;,]/', $description, $matches)) {
        $candidateBrand = $clean((string) ($matches[1] ?? ''));
        if ($looksLikeBrand($candidateBrand)) {
            $brand = $candidateBrand;
        }
    }

    if (preg_match('/\b(?:s\s*\/?\s*n|serial(?:\s*no)?)\b\.?\s*[:#-]?\s*([^;,\n]+)/i', $description, $matches)) {
        $serialNo = $cleanSerial((string) ($matches[1] ?? ''));
    }

    if ($model === '' && preg_match('/\b([A-Za-z]{1,8}[A-Za-z0-9\-\/]{1,30})\s*(?:SN|S\/?N|Serial)\b/i', $description, $matches)) {
        $candidateModel = $clean((string) ($matches[1] ?? ''));
        if (strlen($candidateModel) <= 30 && !in_array(strtolower($candidateModel), $stopWords, true)) {
            $model = $candidateModel;
        }
    }

    if (preg_match('/\b(mounted|outdoor|indoor|split|inverter|refresh)\b/i', $model)) {
        $model = '';
    }

    if (strlen($brand) > 200) {
        $brand = substr($brand, 0, 200);
    }
    if (strlen($model) > 200) {
        $model = substr($model, 0, 200);
    }
    if (strlen($serialNo) > 200) {
        $serialNo = substr($serialNo, 0, 200);
    }

    return [
        'brand' => $brand,
        'model' => $model,
        'serial_no' => $serialNo,
    ];
}

function rpcppe_parse_remark_assignment(string $remarks): array
{
    $remarks = trim($remarks);
    if ($remarks === '') {
        return ['office_token' => '', 'employee_token' => '', 'raw' => ''];
    }

    if (strpos($remarks, "\n") !== false || strpos(strtolower($remarks), 'sq.m') !== false) {
        return ['office_token' => '', 'employee_token' => '', 'raw' => $remarks];
    }

    if (preg_match('/^([^\-\/]+)[\-\/]+(.+)$/', $remarks, $matches)) {
        return [
            'office_token' => trim($matches[1]),
            'employee_token' => trim($matches[2]),
            'raw' => $remarks,
        ];
    }

    return ['office_token' => '', 'employee_token' => trim($remarks), 'raw' => $remarks];
}

function rpcppe_find_account_code_by_sheet(mysqli $db, string $sheetName): ?array
{
    $sheetName = trim($sheetName);

    $codeToken = rpcppe_extract_account_code_token($sheetName);
    $normalizedCodeToken = rpcppe_normalize_account_code_token($codeToken);
    if ($normalizedCodeToken !== '') {
        $stmt = $db->prepare("SELECT id, account_code, account_name FROM account_codes WHERE is_active = 1 AND REPLACE(REPLACE(REPLACE(account_code, '.', ''), '-', ''), ' ', '') = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $normalizedCodeToken);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }

    $targetName = rpcppe_sheet_account_name(rpcppe_strip_leading_account_code_label($sheetName));
    $stmt = $db->prepare("SELECT id, account_code, account_name FROM account_codes WHERE is_active = 1 AND LOWER(TRIM(account_name)) = LOWER(TRIM(?)) LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $targetName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function rpcppe_find_fund_by_number(mysqli $db, string $fundNumber): ?array
{
    $fundNumber = trim($fundNumber);
    if ($fundNumber === '') {
        return null;
    }

    $result = $db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY id ASC");
    if (!$result) {
        return null;
    }

    while ($row = $result->fetch_assoc()) {
        if (fund_number_from_source($row['fund_code'] ?? '', $row['fund_source'] ?? '') === $fundNumber) {
            return $row;
        }
    }

    return null;
}

function rpcppe_find_or_create_classification(mysqli $db, string $classificationName, ?int $accountCodeId, int $userId): ?array
{
    $classificationName = trim($classificationName);
    if ($classificationName === '') {
        return null;
    }

    $select = $db->prepare("SELECT id, classification_name, classification_family FROM classifications WHERE LOWER(TRIM(classification_name)) = LOWER(TRIM(?)) LIMIT 1");
    if ($select) {
        $select->bind_param('s', $classificationName);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            return $existing;
        }
    }

    $classificationCode = next_module_code($db, 'classifications');
    $group = 'asset';
    $description = 'Auto-created from RPCPPE workbook import.';
    $insert = $db->prepare("INSERT INTO classifications (classification_code, classification_name, classification_group, account_code_id, description, is_active, created_by) VALUES (?, ?, ?, NULLIF(?, 0), ?, 1, ?)");
    if (!$insert) {
        return null;
    }

    $accountCodeValue = $accountCodeId ?? 0;
    $insert->bind_param('sssisi', $classificationCode, $classificationName, $group, $accountCodeValue, $description, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();

    if (!$saved || $newId <= 0) {
        return null;
    }

    return ['id' => $newId, 'classification_name' => $classificationName, 'classification_family' => ''];
}

function rpcppe_find_or_create_office(mysqli $db, string $officeToken, int $userId): ?array
{
    $officeToken = trim($officeToken);
    if ($officeToken === '') {
        return null;
    }

    $select = $db->prepare("SELECT id, office_code, office_name FROM offices WHERE LOWER(TRIM(office_code)) = LOWER(TRIM(?)) OR LOWER(TRIM(office_name)) = LOWER(TRIM(?)) LIMIT 1");
    if ($select) {
        $select->bind_param('ss', $officeToken, $officeToken);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            return $existing;
        }
    }

    $baseCode = strtoupper(preg_replace('/[^A-Z0-9]+/', '', $officeToken) ?? '');
    if ($baseCode === '') {
        $baseCode = 'OFF';
    }
    $baseCode = substr($baseCode, 0, 20);
    $officeCode = $baseCode;
    $suffix = 1;

    while (true) {
        $dup = $db->prepare("SELECT id FROM offices WHERE office_code = ? LIMIT 1");
        if (!$dup) {
            break;
        }
        $dup->bind_param('s', $officeCode);
        $dup->execute();
        $exists = $dup->get_result()->fetch_assoc();
        $dup->close();
        if (!$exists) {
            break;
        }
        $suffix++;
        $officeCode = substr($baseCode, 0, max(1, 20 - strlen((string) $suffix))) . $suffix;
    }

    $officeName = $officeToken;
    $insert = $db->prepare("INSERT INTO offices (office_code, office_name, department_id, office_head_employee_id, description, is_active, created_by) VALUES (?, ?, NULL, NULL, '', 1, ?)");
    if (!$insert) {
        return null;
    }
    $insert->bind_param('ssi', $officeCode, $officeName, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();

    if (!$saved || $newId <= 0) {
        return null;
    }

    return ['id' => $newId, 'office_code' => $officeCode, 'office_name' => $officeName];
}

function rpcppe_find_or_create_employee(mysqli $db, string $employeeToken, ?int $officeId, int $userId): ?array
{
    $employeeToken = trim($employeeToken);
    if ($employeeToken === '') {
        return null;
    }

    $normalized = rpcppe_norm($employeeToken);
    $lastName = $employeeToken;
    $firstName = 'Unknown';
    $middleName = '';

    if (preg_match('/^([A-Za-z]\.)\s+(.+)$/', $employeeToken, $matches)) {
        $firstName = trim($matches[1]);
        $lastName = trim($matches[2]);
    } elseif (preg_match('/^(.+?)\s+([A-Za-z][A-Za-z\-\']+)$/', $employeeToken, $matches)) {
        $firstName = trim($matches[1]);
        $lastName = trim($matches[2]);
    }

    $select = $db->prepare("SELECT id, first_name, middle_name, last_name, suffix_name, office_id FROM employees WHERE is_active = 1 AND (LOWER(TRIM(last_name)) = LOWER(TRIM(?)) OR LOWER(TRIM(CONCAT_WS(' ', first_name, middle_name, last_name, suffix_name))) = LOWER(TRIM(?))) ORDER BY id ASC LIMIT 1");
    if ($select) {
        $fullName = $employeeToken;
        $select->bind_param('ss', $lastName, $fullName);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            return $existing;
        }
    }

    $employeeNo = '';
    $suffixName = '';
    $email = '';
    $photoPath = '';
    $responsibilityCodeId = null;
    $positionTitle = 'From RPCPPE Import';
    $employmentStatus = '';
    $isUnitHead = 0;
    $isActive = 1;
    $officeValue = $officeId ?? 0;
    $rcValue = $responsibilityCodeId ?? 0;

    $insert = $db->prepare("INSERT INTO employees (employee_no, first_name, middle_name, last_name, suffix_name, email, photo_path, department_id, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?)");
    if (!$insert) {
        return null;
    }
    $insert->bind_param('sssssssiiissiii', $employeeNo, $firstName, $middleName, $lastName, $suffixName, $email, $photoPath, $officeValue, $rcValue, $positionTitle, $employmentStatus, $isUnitHead, $isActive, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();

    if (!$saved || $newId <= 0) {
        return null;
    }

    return ['id' => $newId, 'first_name' => $firstName, 'middle_name' => $middleName, 'last_name' => $lastName, 'suffix_name' => '', 'office_id' => $officeId];
}

function rpcppe_extract_visible_rows_from_xlsx(string $filePath): array
{
    $stats = [
        'sheets_processed' => 0,
        'data_rows' => 0,
        'visible_rows' => 0,
        'hidden_rows_skipped' => 0,
        'rows_with_property_number' => 0,
        'rows_without_property_number' => 0,
    ];
    $errors = [];
    $parsedRows = [];

    if (!class_exists('ZipArchive')) {
        return [[], $stats, ['ZipArchive extension is not available in PHP.']];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return [[], $stats, ['Unable to open Excel file.']];
    }

    try {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            return [[], $stats, ['Excel workbook metadata is missing or unreadable.']];
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        if (!$workbook || !$rels) {
            return [[], $stats, ['Unable to parse workbook XML content.']];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sharedDoc = simplexml_load_string($sharedXml);
            if ($sharedDoc) {
                $sharedDoc->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $sharedNodes = $sharedDoc->xpath('//x:si');
                if (is_array($sharedNodes)) {
                    foreach ($sharedNodes as $si) {
                        $si->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                        $textParts = $si->xpath('.//x:t');
                        $textValue = '';
                        if (is_array($textParts)) {
                            foreach ($textParts as $part) {
                                $textValue .= (string) $part;
                            }
                        }
                        $sharedStrings[] = $textValue;
                    }
                }
            }
        }

        $workbook->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $relMap = [];
        $relNodes = $rels->xpath('//r:Relationship');
        if (is_array($relNodes)) {
            foreach ($relNodes as $relNode) {
                $id = (string) ($relNode['Id'] ?? '');
                $target = (string) ($relNode['Target'] ?? '');
                if ($id !== '' && $target !== '') {
                    $relMap[$id] = 'xl/' . ltrim($target, '/');
                }
            }
        }

        $sheetNodes = $workbook->xpath('//x:sheets/x:sheet');
        if (!is_array($sheetNodes)) {
            return [[], $stats, ['No sheets found in workbook.']];
        }

        foreach ($sheetNodes as $sheetNode) {
            $sheetName = trim((string) ($sheetNode['name'] ?? ''));
            if (in_array(strtoupper($sheetName), ['SUMMARY', 'CERTIFY'], true)) {
                continue;
            }

            $rid = (string) ($sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id ?? '');
            if ($rid === '' || !isset($relMap[$rid])) {
                continue;
            }

            $sheetXml = $zip->getFromName($relMap[$rid]);
            if ($sheetXml === false) {
                continue;
            }

            $sheet = simplexml_load_string($sheetXml);
            if (!$sheet) {
                continue;
            }

            $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rowNodes = $sheet->xpath('//x:sheetData/x:row');
            if (!is_array($rowNodes) || $rowNodes === []) {
                continue;
            }

            $stats['sheets_processed']++;

            foreach ($rowNodes as $rowNode) {
                $rowIndex = (int) ($rowNode['r'] ?? 0);
                if ($rowIndex <= 11) {
                    continue;
                }

                if ((string) ($rowNode['hidden'] ?? '') === '1') {
                    $stats['hidden_rows_skipped']++;
                    continue;
                }

                $cells = [];
                $rowNode->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cellNodes = $rowNode->xpath('./x:c');
                if (!is_array($cellNodes)) {
                    continue;
                }

                foreach ($cellNodes as $cellNode) {
                    $ref = (string) ($cellNode['r'] ?? '');
                    $col = preg_replace('/\d+/', '', $ref);
                    if ($col === '') {
                        continue;
                    }

                    $cellNode->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $type = (string) ($cellNode['t'] ?? '');
                    $value = '';
                    if ($type === 's') {
                        $vNodes = $cellNode->xpath('./x:v');
                        $sharedIndex = (is_array($vNodes) && isset($vNodes[0])) ? (int) ((string) $vNodes[0]) : -1;
                        if ($sharedIndex >= 0 && isset($sharedStrings[$sharedIndex])) {
                            $value = (string) $sharedStrings[$sharedIndex];
                        }
                    } elseif ($type === 'inlineStr') {
                        $tNodes = $cellNode->xpath('.//x:t');
                        if (is_array($tNodes)) {
                            foreach ($tNodes as $tNode) {
                                $value .= (string) $tNode;
                            }
                        }
                    } else {
                        $vNodes = $cellNode->xpath('./x:v');
                        if (is_array($vNodes) && isset($vNodes[0])) {
                            $value = (string) $vNodes[0];
                        }
                    }

                    $cells[$col] = trim($value);
                }

                $article = trim((string) ($cells['A'] ?? ''));
                $description = trim((string) ($cells['B'] ?? ''));
                $uom = trim((string) ($cells['D'] ?? ''));
                $unitValue = trim((string) ($cells['E'] ?? ''));

                $articleUpper = strtoupper($article);
                $descriptionUpper = strtoupper($description);
                if ($articleUpper === 'TOTAL' || $descriptionUpper === 'TOTAL' || str_starts_with($articleUpper, 'TOTAL ')) {
                    continue;
                }

                if ($article === '' && $description === '') {
                    continue;
                }
                if ($uom === '' && $unitValue === '') {
                    continue;
                }

                $stats['data_rows']++;
                $stats['visible_rows']++;

                $propertyNumber = strtoupper(trim((string) ($cells['C'] ?? '')));
                if ($propertyNumber === '' || $propertyNumber === '(BLANK)') {
                    $stats['rows_without_property_number']++;
                    $propertyNumber = '';
                } else {
                    $stats['rows_with_property_number']++;
                }

                $descriptionForMetadata = $description !== '' ? $description : $article;
                $metadata = rpcppe_extract_asset_metadata_from_description($descriptionForMetadata);

                $parsedRows[] = [
                    'sheet_name' => $sheetName,
                    'account_name' => rpcppe_sheet_account_name($sheetName),
                    'article' => $article,
                    'description' => $description !== '' ? $description : $article,
                    'property_number' => $propertyNumber,
                    'uom' => $uom,
                    'unit_value' => (float) ($cells['E'] ?? 0),
                    'qty_property_card' => (float) ($cells['F'] ?? 0),
                    'qty_physical_count' => (float) ($cells['G'] ?? 0),
                    'remarks' => trim((string) ($cells['J'] ?? '')),
                    'acquisition_date' => rpcppe_excel_serial_to_date((string) ($cells['K'] ?? '')),
                    'fund_number' => trim((string) ($cells['L'] ?? '')),
                    'accounting_value' => trim((string) ($cells['M'] ?? '')),
                    'status' => trim((string) ($cells['N'] ?? '')),
                    'brand' => (string) ($metadata['brand'] ?? ''),
                    'model' => (string) ($metadata['model'] ?? ''),
                    'serial_no' => (string) ($metadata['serial_no'] ?? ''),
                ];
            }
        }
    } finally {
        $zip->close();
    }

    return [$parsedRows, $stats, $errors];
}

function rpcppe_fetch_live_row_by_property_number(mysqli $db, string $propertyNumber, string $asOf): ?array
{
    $propertyNumber = trim($propertyNumber);
    if ($propertyNumber === '') {
        return null;
    }

    $systemSql = "
        SELECT
            'system' AS source_type,
            did.id AS distribution_item_detail_id,
            NULL AS legacy_asset_id,
            did.property_number,
            poi.item_description,
            poi.item_description AS description_detail,
            c.classification_name,
            c.classification_family,
            u.uom_name,
            u.abbreviation,
            ri.unit_cost,
            r.received_date AS acquisition_date,
            rid.brand,
            rid.model,
            rid.serial_no,
            COALESCE(curr_o.id, o.id) AS office_id,
            COALESCE(curr_o.office_name, o.office_name) AS office_name,
            COALESCE(curr_e.id, e.id) AS employee_id,
            TRIM(CONCAT_WS(' ', COALESCE(curr_e.first_name, e.first_name), COALESCE(curr_e.middle_name, e.middle_name), COALESCE(curr_e.last_name, e.last_name), COALESCE(curr_e.suffix_name, e.suffix_name))) AS employee_name,
            ac.id AS account_code_id,
            ac.account_code,
            ac.account_name,
            f.fund_code,
            f.fund_source,
            1 AS qty_property_card,
            1 AS qty_physical_count
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'par'
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
        LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
        LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
        LEFT JOIN receivings r ON r.id = ri.receiving_id
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
        LEFT JOIN disposals dp ON dp.distribution_item_detail_id = did.id AND dp.status = 'posted' AND dp.disposal_date <= ?
        LEFT JOIN returns rt ON rt.distribution_item_detail_id = did.id AND rt.status = 'posted' AND rt.return_date <= ?
        WHERE d.distribution_date <= ?
          AND dp.id IS NULL
          AND rt.id IS NULL
          AND did.property_number = ?
        LIMIT 1
    ";
    $stmt = $db->prepare($systemSql);
    if ($stmt) {
        $stmt->bind_param('ssss', $asOf, $asOf, $asOf, $propertyNumber);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            $row['fund_number'] = fund_number_from_source($row['fund_code'] ?? '', $row['fund_source'] ?? '');
            $row['remarks'] = '';
            return $row;
        }
    }

    $legacySql = "
        SELECT
            'legacy' AS source_type,
            NULL AS distribution_item_detail_id,
            la.id AS legacy_asset_id,
            la.property_number,
            la.item_description,
            la.item_description AS description_detail,
            c.classification_name,
            c.classification_family,
            '' AS uom_name,
            '' AS abbreviation,
            la.unit_cost,
            la.acquisition_date,
            la.brand,
            la.model,
            la.serial_no,
            o.id AS office_id,
            o.office_name,
            e.id AS employee_id,
            TRIM(CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name, e.suffix_name)) AS employee_name,
            ac.id AS account_code_id,
            ac.account_code,
            ac.account_name,
            f.fund_code,
            f.fund_source,
            la.quantity AS qty_property_card,
            la.quantity AS qty_physical_count
        FROM legacy_assets la
        LEFT JOIN classifications c ON c.id = la.classification_id
        LEFT JOIN account_codes ac ON ac.id = la.account_code_id
        LEFT JOIN funds f ON f.id = la.fund_id
        LEFT JOIN offices o ON o.id = la.office_id
        LEFT JOIN employees e ON e.id = la.employee_id
        WHERE la.is_active = 1
          AND la.item_type = 'equipment'
          AND la.property_number = ?
        LIMIT 1
    ";
    $legacyStmt = $db->prepare($legacySql);
    if ($legacyStmt) {
        $legacyStmt->bind_param('s', $propertyNumber);
        $legacyStmt->execute();
        $row = $legacyStmt->get_result()->fetch_assoc() ?: null;
        $legacyStmt->close();
        if ($row) {
            $row['fund_number'] = fund_number_from_source($row['fund_code'] ?? '', $row['fund_source'] ?? '');
            $row['remarks'] = 'Beginning Balance';
            return $row;
        }
    }

    return null;
}

function rpcppe_create_legacy_asset_from_excel_row(mysqli $db, array $excelRow, array $account, ?array $fund, ?array $classification, ?array $office, ?array $employee, int $userId): ?array
{
    $propertyNumber = trim((string) ($excelRow['property_number'] ?? ''));
    $acquisitionDate = trim((string) ($excelRow['acquisition_date'] ?? ''));
    $yearValue = $acquisitionDate !== '' ? substr($acquisitionDate, 0, 4) : date('Y');
    $fundNumber = trim((string) ($excelRow['fund_number'] ?? ''));
    $officeCode = trim((string) ($office['office_code'] ?? ''));

    if ($propertyNumber === '') {
        $propertyNumber = generate_property_number($db, $yearValue, $fundNumber, (string) ($account['account_code'] ?? ''), $officeCode);
    }

    $existing = $db->prepare("SELECT id FROM legacy_assets WHERE property_number = ? LIMIT 1");
    if ($existing) {
        $existing->bind_param('s', $propertyNumber);
        $existing->execute();
        $dup = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($dup) {
            return rpcppe_fetch_live_row_by_property_number($db, $propertyNumber, date('Y-m-d'));
        }
    }

    $systemReference = next_module_code($db, 'stock_items');
    $poNumber = $propertyNumber;
    $itemType = 'equipment';
    $itemDescription = trim((string) ($excelRow['description'] ?? ''));
    $classificationId = isset($classification['id']) ? (int) $classification['id'] : 0;
    $accountCodeId = (int) ($account['id'] ?? 0);
    $fundId = isset($fund['id']) ? (int) $fund['id'] : 0;
    $supplierId = 0;
    $brandId = 0;
    $modelId = 0;
    $metadata = rpcppe_extract_asset_metadata_from_description($itemDescription);
    $brandName = trim((string) ($excelRow['brand'] ?? ($metadata['brand'] ?? '')));
    $modelName = trim((string) ($excelRow['model'] ?? ($metadata['model'] ?? '')));
    $serialNo = trim((string) ($excelRow['serial_no'] ?? ($metadata['serial_no'] ?? '')));
    $quantity = rpcppe_quantity_from_excel_row($excelRow);
    $unitCost = (float) ($excelRow['unit_value'] ?? 0);
    $acquisitionCost = round($quantity * $unitCost, 2);
    $officeId = isset($office['id']) ? (int) $office['id'] : 0;
    $employeeId = isset($employee['id']) ? (int) $employee['id'] : 0;
    $rcId = 0;
    $conditionStatus = 'good';
    $remarks = trim((string) ($excelRow['remarks'] ?? ''));

    $insert = $db->prepare("INSERT INTO legacy_assets (system_reference, po_number, property_number, item_type, item_description, classification_id, account_code_id, fund_id, supplier_id, brand_id, model_id, brand, model, serial_no, acquisition_date, quantity, unit_cost, acquisition_cost, office_id, employee_id, responsibility_code_id, condition_status, remarks, created_by) VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, NULLIF(?, ''), ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?)");
    if (!$insert) {
        return null;
    }
    $insert->bind_param('sssssiiiiiissssidddiiissi', $systemReference, $poNumber, $propertyNumber, $itemType, $itemDescription, $classificationId, $accountCodeId, $fundId, $supplierId, $brandId, $modelId, $brandName, $modelName, $serialNo, $acquisitionDate, $quantity, $unitCost, $acquisitionCost, $officeId, $employeeId, $rcId, $conditionStatus, $remarks, $userId);
    $saved = $insert->execute();
    $insert->close();
    if (!$saved) {
        return null;
    }

    return [
        'source_type' => 'legacy',
        'distribution_item_detail_id' => null,
        'legacy_asset_id' => (int) $db->insert_id,
        'property_number' => $propertyNumber,
        'item_description' => $itemDescription,
        'description_detail' => $itemDescription,
        'classification_name' => (string) ($classification['classification_name'] ?? ($excelRow['article'] ?? '')),
        'classification_family' => (string) ($classification['classification_family'] ?? ''),
        'uom_name' => (string) ($excelRow['uom'] ?? ''),
        'abbreviation' => '',
        'unit_cost' => $unitCost,
        'acquisition_date' => $acquisitionDate,
        'qty_property_card' => $quantity,
        'qty_physical_count' => $quantity,
        'brand' => $brandName,
        'model' => $modelName,
        'serial_no' => $serialNo,
        'office_id' => $officeId > 0 ? $officeId : null,
        'office_name' => (string) ($office['office_name'] ?? ''),
        'employee_id' => $employeeId > 0 ? $employeeId : null,
        'employee_name' => $employee ? person_full_name($employee) : '',
        'account_code_id' => $accountCodeId,
        'account_code' => (string) ($account['account_code'] ?? ''),
        'account_name' => (string) ($account['account_name'] ?? ''),
        'fund_code' => (string) ($fund['fund_code'] ?? ''),
        'fund_source' => (string) ($fund['fund_source'] ?? ''),
        'fund_number' => $fundNumber,
        'remarks' => 'Beginning Balance',
    ];
}

function rpcppe_update_legacy_asset_from_excel_row(mysqli $db, int $legacyAssetId, array $excelRow, ?array $office, ?array $employee): void
{
    if ($legacyAssetId <= 0) {
        return;
    }

    $acquisitionDate = trim((string) ($excelRow['acquisition_date'] ?? ''));
    $officeId = isset($office['id']) ? (int) $office['id'] : 0;
    $employeeId = isset($employee['id']) ? (int) $employee['id'] : 0;
    $remarks = trim((string) ($excelRow['remarks'] ?? ''));
    $itemDescription = trim((string) ($excelRow['description'] ?? ''));
    $metadata = rpcppe_extract_asset_metadata_from_description($itemDescription);
    $brand = trim((string) ($excelRow['brand'] ?? ($metadata['brand'] ?? '')));
    $model = trim((string) ($excelRow['model'] ?? ($metadata['model'] ?? '')));
    $serialNo = trim((string) ($excelRow['serial_no'] ?? ($metadata['serial_no'] ?? '')));

    $stmt = $db->prepare("UPDATE legacy_assets
        SET acquisition_date = CASE WHEN ? <> '' THEN ? ELSE acquisition_date END,
            office_id = CASE WHEN ? > 0 THEN ? ELSE office_id END,
            employee_id = CASE WHEN ? > 0 THEN ? ELSE employee_id END,
            brand = CASE WHEN ? <> '' THEN ? ELSE brand END,
            model = CASE WHEN ? <> '' THEN ? ELSE model END,
            serial_no = CASE WHEN ? <> '' THEN ? ELSE serial_no END,
            remarks = CASE WHEN ? <> '' THEN ? ELSE remarks END
        WHERE id = ?");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'ssiiiisssssssi',
        $acquisitionDate,
        $acquisitionDate,
        $officeId,
        $officeId,
        $employeeId,
        $employeeId,
        $brand,
        $brand,
        $model,
        $model,
        $serialNo,
        $serialNo,
        $remarks,
        $remarks,
        $legacyAssetId
    );
    $stmt->execute();
    $stmt->close();
}

function rpcppe_find_batch_item_id(mysqli $db, int $batchId, array $row): int
{
    if (($row['source_type'] ?? '') === 'system' && !empty($row['distribution_item_detail_id'])) {
        $stmt = $db->prepare("SELECT id FROM rpcppe_batch_items WHERE batch_id = ? AND distribution_item_detail_id = ? LIMIT 1");
        if ($stmt) {
            $sourceId = (int) $row['distribution_item_detail_id'];
            $stmt->bind_param('ii', $batchId, $sourceId);
            $stmt->execute();
            $match = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($match) {
                return (int) $match['id'];
            }
        }
    }

    if (($row['source_type'] ?? '') === 'legacy' && !empty($row['legacy_asset_id'])) {
        $stmt = $db->prepare("SELECT id FROM rpcppe_batch_items WHERE batch_id = ? AND legacy_asset_id = ? LIMIT 1");
        if ($stmt) {
            $sourceId = (int) $row['legacy_asset_id'];
            $stmt->bind_param('ii', $batchId, $sourceId);
            $stmt->execute();
            $match = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($match) {
                return (int) $match['id'];
            }
        }
    }

    $propertyNumber = trim((string) ($row['property_number'] ?? ''));
    if ($propertyNumber !== '') {
        $stmt = $db->prepare("SELECT id FROM rpcppe_batch_items WHERE batch_id = ? AND property_number = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('is', $batchId, $propertyNumber);
            $stmt->execute();
            $match = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($match) {
                return (int) $match['id'];
            }
        }
    }

    return 0;
}

function rpcppe_update_batch_item_snapshot(mysqli $db, int $batchItemId, array $row, array $excelRow, ?array $office, ?array $employee): void
{
    $officeId = isset($office['id']) ? (int) $office['id'] : 0;
    $officeName = trim((string) ($office['office_name'] ?? ''));
    $employeeId = isset($employee['id']) ? (int) $employee['id'] : 0;
    $employeeName = $employee ? trim(person_full_name($employee)) : '';
    $remarks = trim((string) ($excelRow['remarks'] ?? ''));
    $accountCodeId = !empty($row['account_code_id']) ? (int) $row['account_code_id'] : 0;
    $accountCode = trim((string) ($row['account_code'] ?? ''));
    $accountName = trim((string) ($row['account_name'] ?? ''));
    $fundCode = trim((string) ($row['fund_code'] ?? ''));
    $fundSource = trim((string) ($row['fund_source'] ?? ''));
    $fundNumber = trim((string) ($row['fund_number'] ?? ''));
    $brand = trim((string) ($row['brand'] ?? ($excelRow['brand'] ?? '')));
    $model = trim((string) ($row['model'] ?? ($excelRow['model'] ?? '')));
    $serialNo = trim((string) ($row['serial_no'] ?? ($excelRow['serial_no'] ?? '')));
    if ($brand === '' || $model === '' || $serialNo === '') {
        $metadata = rpcppe_extract_asset_metadata_from_description((string) ($excelRow['description'] ?? ''));
        if ($brand === '') {
            $brand = (string) ($metadata['brand'] ?? '');
        }
        if ($model === '') {
            $model = (string) ($metadata['model'] ?? '');
        }
        if ($serialNo === '') {
            $serialNo = (string) ($metadata['serial_no'] ?? '');
        }
    }

    $acquisitionDateSnapshot = trim((string) ($excelRow['acquisition_date'] ?? ''));
    $qtyPropertyCard = max(1, (int) ($excelRow['qty_property_card'] ?? 1));
    $qtyPhysicalCount = max(1, (int) ($excelRow['qty_physical_count'] ?? 1));

    $stmt = $db->prepare("UPDATE rpcppe_batch_items
        SET is_included = 1,
            acquisition_date = CASE WHEN ? <> '' THEN ? ELSE acquisition_date END,
            qty_property_card = CASE WHEN ? > 0 THEN ? ELSE qty_property_card END,
            qty_physical_count = CASE WHEN ? > 0 THEN ? ELSE qty_physical_count END,
            office_id = CASE WHEN ? > 0 THEN ? ELSE office_id END,
            office_name = CASE WHEN ? <> '' THEN ? ELSE office_name END,
            employee_id = CASE WHEN ? > 0 THEN ? ELSE employee_id END,
            employee_name = CASE WHEN ? <> '' THEN ? ELSE employee_name END,
            account_code_id = CASE WHEN ? > 0 THEN ? ELSE account_code_id END,
            account_code = CASE WHEN ? <> '' THEN ? ELSE account_code END,
            account_name = CASE WHEN ? <> '' THEN ? ELSE account_name END,
            fund_code = CASE WHEN ? <> '' THEN ? ELSE fund_code END,
            fund_source = CASE WHEN ? <> '' THEN ? ELSE fund_source END,
            fund_number = CASE WHEN ? <> '' THEN ? ELSE fund_number END,
            remarks = CASE WHEN ? <> '' THEN ? ELSE remarks END
        WHERE id = ?");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'ssiiiiiissiissiisssssssssssssi',
        $acquisitionDateSnapshot,
        $acquisitionDateSnapshot,
        $qtyPropertyCard,
        $qtyPropertyCard,
        $qtyPhysicalCount,
        $qtyPhysicalCount,
        $officeId,
        $officeId,
        $officeName,
        $officeName,
        $employeeId,
        $employeeId,
        $employeeName,
        $employeeName,
        $accountCodeId,
        $accountCodeId,
        $accountCode,
        $accountCode,
        $accountName,
        $accountName,
        $fundCode,
        $fundCode,
        $fundSource,
        $fundSource,
        $fundNumber,
        $fundNumber,
        $remarks,
        $remarks,
        $batchItemId
    );
    $stmt->execute();
    $stmt->close();

    $metaStmt = $db->prepare("UPDATE rpcppe_batch_items
        SET brand = CASE WHEN ? <> '' THEN ? ELSE brand END,
            model = CASE WHEN ? <> '' THEN ? ELSE model END,
            serial_no = CASE WHEN ? <> '' THEN ? ELSE serial_no END
        WHERE id = ?");
    if ($metaStmt) {
        $metaStmt->bind_param('ssssssi', $brand, $brand, $model, $model, $serialNo, $serialNo, $batchItemId);
        $metaStmt->execute();
        $metaStmt->close();
    }
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    ensure_rpcppe_batch_tables($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }

        if (empty($errors) && $action === 'create_batch') {
            $batchYear = (int) ($_POST['batch_year'] ?? date('Y'));
            $asOfDate = trim((string) ($_POST['as_of_date'] ?? date('Y-12-31')));
            $batchName = trim((string) ($_POST['batch_name'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($batchYear < 2000 || $batchYear > ((int) date('Y') + 2)) {
                $errors[] = 'Invalid batch year.';
            }
            if ($asOfDate === '') {
                $errors[] = 'As of date is required.';
            }
            if ($batchName === '') {
                $batchName = 'RPCPPE ' . $batchYear;
            }

            if (empty($errors)) {
                $stmt = $db->prepare("INSERT INTO rpcppe_batches (batch_year, batch_name, as_of_date, notes, created_by) VALUES (?, ?, ?, ?, ?)");
                if ($stmt) {
                    $userId = current_user_id();
                    $stmt->bind_param('isssi', $batchYear, $batchName, $asOfDate, $notes, $userId);
                    if ($stmt->execute()) {
                        $newBatchId = (int) $stmt->insert_id;
                        $stmt->close();
                        set_flash('success', 'RPCPPE batch created.');
                        redirect('modules/reports/rpcppe_batches.php?batch_id=' . $newBatchId);
                    }
                    $stmt->close();
                }
                $errors[] = 'Unable to create RPCPPE batch.';
            }
        }

        if (empty($errors) && $action === 'create_next_year_batch') {
            $latestFinalizedStmt = $db->prepare("SELECT id, batch_year, batch_name FROM rpcppe_batches WHERE status = 'finalized' ORDER BY batch_year DESC, id DESC LIMIT 1");
            $latestFinalized = null;
            if ($latestFinalizedStmt) {
                $latestFinalizedStmt->execute();
                $latestFinalized = $latestFinalizedStmt->get_result()->fetch_assoc() ?: null;
                $latestFinalizedStmt->close();
            }

            if (!$latestFinalized) {
                $errors[] = 'No finalized batch found. Finalize at least one batch before using auto next-year creation.';
            } else {
                $nextYear = ((int) ($latestFinalized['batch_year'] ?? date('Y'))) + 1;
                $nextAsOfDate = $nextYear . '-12-31';
                $nextBatchName = 'RPCPPE ' . $nextYear;
                $nextNotes = 'Auto-created from finalized batch #' . (int) ($latestFinalized['id'] ?? 0) . ' (' . (string) ($latestFinalized['batch_name'] ?? 'RPCPPE') . ').';

                $db->begin_transaction();
                try {
                    $insertBatchStmt = $db->prepare("INSERT INTO rpcppe_batches (batch_year, batch_name, as_of_date, notes, created_by) VALUES (?, ?, ?, ?, ?)");
                    if (!$insertBatchStmt) {
                        throw new RuntimeException('Unable to create next-year batch.');
                    }

                    $userId = current_user_id();
                    $insertBatchStmt->bind_param('isssi', $nextYear, $nextBatchName, $nextAsOfDate, $nextNotes, $userId);
                    if (!$insertBatchStmt->execute()) {
                        $insertBatchStmt->close();
                        throw new RuntimeException('Unable to save next-year batch.');
                    }

                    $newBatchId = (int) $insertBatchStmt->insert_id;
                    $insertBatchStmt->close();

                    $copied = rpcppe_copy_carry_forward_items($db, $newBatchId, (int) $latestFinalized['id']);
                    rpcppe_sync_batch_disposals($db, $newBatchId, $nextAsOfDate);
                    $db->commit();

                    set_flash('success', 'Created ' . $nextBatchName . ' and carried forward ' . number_format($copied) . ' asset(s) from finalized batch #' . (int) $latestFinalized['id'] . '.');
                    redirect('modules/reports/rpcppe_batches.php?batch_id=' . $newBatchId);
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Unable to auto-create next-year batch.';
                }
            }
        }

        if (empty($errors) && $action === 'delete_batch') {
            $deleteBatchId = (int) ($_POST['batch_id'] ?? 0);
            if ($deleteBatchId <= 0) {
                $errors[] = 'Invalid batch.';
            } else {
                $batchStmt = $db->prepare("SELECT id, status, batch_name FROM rpcppe_batches WHERE id = ? LIMIT 1");
                $batchRow = null;
                if ($batchStmt) {
                    $batchStmt->bind_param('i', $deleteBatchId);
                    $batchStmt->execute();
                    $batchRow = $batchStmt->get_result()->fetch_assoc();
                    $batchStmt->close();
                }

                if (!$batchRow) {
                    $errors[] = 'Batch not found.';
                } elseif (($batchRow['status'] ?? '') !== 'draft') {
                    $errors[] = 'Only draft batches can be deleted.';
                } else {
                    $db->begin_transaction();
                    try {
                        $deleteItemsStmt = $db->prepare("DELETE FROM rpcppe_batch_items WHERE batch_id = ?");
                        if ($deleteItemsStmt) {
                            $deleteItemsStmt->bind_param('i', $deleteBatchId);
                            $deleteItemsStmt->execute();
                            $deleteItemsStmt->close();
                        }

                        $deleteBatchStmt = $db->prepare("DELETE FROM rpcppe_batches WHERE id = ? AND status = 'draft' LIMIT 1");
                        if (!$deleteBatchStmt) {
                            throw new RuntimeException('Unable to delete batch.');
                        }
                        $deleteBatchStmt->bind_param('i', $deleteBatchId);
                        $deleteBatchStmt->execute();
                        $affected = $deleteBatchStmt->affected_rows;
                        $deleteBatchStmt->close();

                        if ($affected <= 0) {
                            throw new RuntimeException('Unable to delete batch.');
                        }

                        $db->commit();
                        set_flash('success', 'Draft batch deleted successfully.');
                        redirect('modules/reports/rpcppe_batches.php');
                    } catch (Throwable $e) {
                        $db->rollback();
                        $errors[] = 'Unable to delete draft batch.';
                    }
                }
            }
        }

        $postBatchId = (int) ($_POST['batch_id'] ?? 0);
        if (empty($errors) && $postBatchId > 0 && in_array($action, ['load_live', 'sync_live_missing', 'carry_forward', 'sync_disposals', 'finalize_batch', 'import_property_numbers'], true)) {
            $batchStmt = $db->prepare("SELECT * FROM rpcppe_batches WHERE id = ? LIMIT 1");
            $postBatch = null;
            if ($batchStmt) {
                $batchStmt->bind_param('i', $postBatchId);
                $batchStmt->execute();
                $postBatch = $batchStmt->get_result()->fetch_assoc();
                $batchStmt->close();
            }

            if (!$postBatch) {
                $errors[] = 'Batch not found.';
            } elseif (($postBatch['status'] ?? '') !== 'draft') {
                $errors[] = 'Only draft batches can be modified.';
            } else {
                $asOfDate = (string) ($postBatch['as_of_date'] ?? date('Y-m-d'));

                if ($action === 'load_live') {
                    $db->begin_transaction();
                    try {
                        $deleteStmt = $db->prepare("DELETE FROM rpcppe_batch_items WHERE batch_id = ?");
                        if ($deleteStmt) {
                            $deleteStmt->bind_param('i', $postBatchId);
                            $deleteStmt->execute();
                            $deleteStmt->close();
                        }

                        $liveRows = rpcppe_fetch_live_rows($db, $asOfDate);
                        $inserted = rpcppe_insert_batch_rows($db, $postBatchId, $liveRows);
                        rpcppe_sync_batch_disposals($db, $postBatchId, $asOfDate);
                        $db->commit();

                        set_flash('success', 'Loaded ' . number_format($inserted) . ' live assets into the batch.');
                        redirect('modules/reports/rpcppe_batches.php?batch_id=' . $postBatchId);
                    } catch (Throwable $e) {
                        $db->rollback();
                        $errors[] = 'Unable to load live assets into the batch.';
                    }
                }

                if ($action === 'sync_live_missing') {
                    $liveRows = rpcppe_fetch_live_rows($db, $asOfDate);
                    $inserted = 0;

                    foreach ($liveRows as $row) {
                        $exists = false;
                        if (!empty($row['distribution_item_detail_id'])) {
                            $existsStmt = $db->prepare("SELECT id FROM rpcppe_batch_items WHERE batch_id = ? AND distribution_item_detail_id = ? LIMIT 1");
                            if ($existsStmt) {
                                $sourceId = (int) $row['distribution_item_detail_id'];
                                $existsStmt->bind_param('ii', $postBatchId, $sourceId);
                                $existsStmt->execute();
                                $exists = (bool) $existsStmt->get_result()->fetch_assoc();
                                $existsStmt->close();
                            }
                        } elseif (!empty($row['legacy_asset_id'])) {
                            $existsStmt = $db->prepare("SELECT id FROM rpcppe_batch_items WHERE batch_id = ? AND legacy_asset_id = ? LIMIT 1");
                            if ($existsStmt) {
                                $sourceId = (int) $row['legacy_asset_id'];
                                $existsStmt->bind_param('ii', $postBatchId, $sourceId);
                                $existsStmt->execute();
                                $exists = (bool) $existsStmt->get_result()->fetch_assoc();
                                $existsStmt->close();
                            }
                        }

                        if (!$exists) {
                            $inserted += rpcppe_insert_batch_rows($db, $postBatchId, [$row]);
                        }
                    }

                    rpcppe_sync_batch_disposals($db, $postBatchId, $asOfDate);
                    set_flash('success', 'Added ' . number_format($inserted) . ' new asset(s) from live records.');
                    redirect('modules/reports/rpcppe_batches.php?batch_id=' . $postBatchId);
                }

                if ($action === 'carry_forward') {
                    $sourceBatchId = (int) ($_POST['source_batch_id'] ?? 0);
                    if ($sourceBatchId <= 0) {
                        $lookupStmt = $db->prepare("SELECT id FROM rpcppe_batches WHERE status = 'finalized' AND batch_year < ? ORDER BY batch_year DESC, id DESC LIMIT 1");
                        if ($lookupStmt) {
                            $yearValue = (int) ($postBatch['batch_year'] ?? date('Y'));
                            $lookupStmt->bind_param('i', $yearValue);
                            $lookupStmt->execute();
                            $row = $lookupStmt->get_result()->fetch_assoc();
                            $lookupStmt->close();
                            if ($row) {
                                $sourceBatchId = (int) $row['id'];
                            }
                        }
                    }

                    if ($sourceBatchId <= 0) {
                        $errors[] = 'No finalized source batch available for carry-forward.';
                    } else {
                        $copied = rpcppe_copy_carry_forward_items($db, $postBatchId, $sourceBatchId);
                        rpcppe_sync_batch_disposals($db, $postBatchId, $asOfDate);
                        set_flash('success', 'Carry-forward copied ' . number_format($copied) . ' asset(s) from finalized batch #' . $sourceBatchId . '.');
                        redirect('modules/reports/rpcppe_batches.php?batch_id=' . $postBatchId);
                    }
                }

                if ($action === 'sync_disposals') {
                    rpcppe_sync_batch_disposals($db, $postBatchId, $asOfDate);
                    set_flash('success', 'Batch disposal/return statuses refreshed.');
                    redirect('modules/reports/rpcppe_batches.php?batch_id=' . $postBatchId);
                }

                if ($action === 'finalize_batch') {
                    rpcppe_sync_batch_disposals($db, $postBatchId, $asOfDate);
                    $finalizeStmt = $db->prepare("UPDATE rpcppe_batches SET status = 'finalized', finalized_by = ?, finalized_at = NOW() WHERE id = ? AND status = 'draft'");
                    if ($finalizeStmt) {
                        $userId = current_user_id();
                        $finalizeStmt->bind_param('ii', $userId, $postBatchId);
                        $finalizeStmt->execute();
                        $affected = $finalizeStmt->affected_rows;
                        $finalizeStmt->close();
                        if ($affected > 0) {
                            set_flash('success', 'Batch finalized. It is now available in the RPCPPE report filter.');
                            redirect('modules/reports/rpcppe_batches.php?batch_id=' . $postBatchId);
                        }
                    }
                    $errors[] = 'Unable to finalize this batch.';
                }

                if ($action === 'import_property_numbers') {
                    $excelPathInput = trim((string) ($_POST['excel_path'] ?? 'database/imports/RPCPPE 2025.xlsx'));
                    if ($excelPathInput === '') {
                        $errors[] = 'Excel path is required.';
                    } else {
                        $workspaceRoot = dirname(__DIR__, 3);
                        $relativePath = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $excelPathInput), DIRECTORY_SEPARATOR);
                        $absolutePath = realpath($workspaceRoot . DIRECTORY_SEPARATOR . $relativePath);

                        if ($absolutePath === false || !is_file($absolutePath)) {
                            $errors[] = 'Excel file not found: ' . $excelPathInput;
                        } elseif (strpos($absolutePath, $workspaceRoot) !== 0) {
                            $errors[] = 'Excel path is outside the workspace.';
                        } else {
                            [$excelRows, $xlsxStats, $xlsxErrors] = rpcppe_extract_visible_rows_from_xlsx($absolutePath);
                            if (!empty($xlsxErrors)) {
                                foreach ($xlsxErrors as $xlsxError) {
                                    $errors[] = $xlsxError;
                                }
                            } elseif (empty($excelRows)) {
                                $errors[] = 'No visible RPCPPE rows were found in the Excel file.';
                            } else {
                                $db->begin_transaction();
                                try {
                                    $resetStmt = $db->prepare("UPDATE rpcppe_batch_items SET is_included = 0 WHERE batch_id = ?");
                                    if (!$resetStmt) {
                                        throw new RuntimeException('Unable to reset include flags.');
                                    }
                                    $resetStmt->bind_param('i', $postBatchId);
                                    $resetStmt->execute();
                                    $resetStmt->close();

                                    $userId = current_user_id();
                                    $seenPropertyNumbers = [];
                                    $rowsIncluded = 0;
                                    $matchedExisting = 0;
                                    $createdLegacy = 0;
                                    $insertedBatchItems = 0;
                                    $accountUnmapped = 0;
                                    $fundUnmapped = 0;
                                    $duplicateRows = 0;

                                    foreach ($excelRows as $excelRow) {
                                        $propertyNumber = strtoupper(trim((string) ($excelRow['property_number'] ?? '')));
                                        if ($propertyNumber !== '') {
                                            if (isset($seenPropertyNumbers[$propertyNumber])) {
                                                $duplicateRows++;
                                                continue;
                                            }
                                            $seenPropertyNumbers[$propertyNumber] = true;
                                        }

                                        $account = rpcppe_find_account_code_by_sheet($db, (string) ($excelRow['sheet_name'] ?? ''));
                                        if (!$account) {
                                            $accountUnmapped++;
                                            continue;
                                        }

                                        $fund = rpcppe_find_fund_by_number($db, (string) ($excelRow['fund_number'] ?? ''));
                                        if (!$fund && trim((string) ($excelRow['fund_number'] ?? '')) !== '') {
                                            $fundUnmapped++;
                                        }

                                        $assignment = rpcppe_parse_remark_assignment((string) ($excelRow['remarks'] ?? ''));
                                        $office = null;
                                        $employee = null;
                                        if ($assignment['office_token'] !== '') {
                                            $office = rpcppe_find_or_create_office($db, $assignment['office_token'], $userId);
                                        }
                                        if ($assignment['employee_token'] !== '') {
                                            $employee = rpcppe_find_or_create_employee($db, $assignment['employee_token'], $office ? (int) ($office['id'] ?? 0) : null, $userId);
                                        }

                                        $classification = rpcppe_find_or_create_classification($db, (string) ($excelRow['article'] ?? ''), (int) ($account['id'] ?? 0), $userId);
                                        $liveRow = $propertyNumber !== '' ? rpcppe_fetch_live_row_by_property_number($db, $propertyNumber, $asOfDate) : null;
                                        if (!$liveRow) {
                                            $liveRow = rpcppe_create_legacy_asset_from_excel_row($db, $excelRow, $account, $fund, $classification, $office, $employee, $userId);
                                            if ($liveRow) {
                                                $createdLegacy++;
                                            }
                                        } else {
                                            $matchedExisting++;
                                            if (($liveRow['source_type'] ?? '') === 'legacy' && !empty($liveRow['legacy_asset_id'])) {
                                                rpcppe_update_legacy_asset_from_excel_row($db, (int) $liveRow['legacy_asset_id'], $excelRow, $office, $employee);
                                            }
                                        }

                                        if (!$liveRow) {
                                            continue;
                                        }

                                        if (!empty($account['id'])) {
                                            $liveRow['account_code_id'] = (int) $account['id'];
                                            $liveRow['account_code'] = (string) ($account['account_code'] ?? '');
                                            $liveRow['account_name'] = (string) ($account['account_name'] ?? '');
                                        }
                                        if ($fund) {
                                            $liveRow['fund_code'] = (string) ($fund['fund_code'] ?? '');
                                            $liveRow['fund_source'] = (string) ($fund['fund_source'] ?? '');
                                            $liveRow['fund_number'] = (string) ($excelRow['fund_number'] ?? '');
                                        }

                                        $batchItemId = rpcppe_find_batch_item_id($db, $postBatchId, $liveRow);
                                        if ($batchItemId <= 0) {
                                            $insertedBatchItems += rpcppe_insert_batch_rows($db, $postBatchId, [$liveRow]);
                                            $batchItemId = rpcppe_find_batch_item_id($db, $postBatchId, $liveRow);
                                        }

                                        if ($batchItemId > 0) {
                                            rpcppe_update_batch_item_snapshot($db, $batchItemId, $liveRow, $excelRow, $office, $employee);
                                            $rowsIncluded++;
                                        }
                                    }

                                    rpcppe_sync_batch_disposals($db, $postBatchId, $asOfDate);

                                    $db->commit();
                                    set_flash(
                                        'success',
                                        'Workbook import applied: included ' . number_format($rowsIncluded)
                                        . ' visible row(s), matched existing ' . number_format($matchedExisting)
                                        . ', created beginning-balance assets ' . number_format($createdLegacy)
                                        . ', inserted batch rows ' . number_format($insertedBatchItems)
                                        . ', duplicate property rows skipped ' . number_format($duplicateRows)
                                        . ', missing property number in file ' . number_format($xlsxStats['rows_without_property_number'])
                                        . ', unmapped accounts ' . number_format($accountUnmapped)
                                        . ', unmapped funds ' . number_format($fundUnmapped) . '.'
                                    );
                                    redirect('modules/reports/rpcppe_batches.php?batch_id=' . $postBatchId);
                                } catch (Throwable $e) {
                                    $db->rollback();
                                    $errors[] = 'Unable to import the RPCPPE workbook into this batch.';
                                }
                            }
                        }
                    }
                }
            }
        }

        if (empty($errors) && $action === 'toggle_include') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $includeValue = (int) ($_POST['include_value'] ?? 1);
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $includeValue = $includeValue === 0 ? 0 : 1;

            $statusStmt = $db->prepare("SELECT b.status FROM rpcppe_batch_items i INNER JOIN rpcppe_batches b ON b.id = i.batch_id WHERE i.id = ? AND i.batch_id = ? LIMIT 1");
            $row = null;
            if ($statusStmt) {
                $statusStmt->bind_param('ii', $itemId, $batchId);
                $statusStmt->execute();
                $row = $statusStmt->get_result()->fetch_assoc();
                $statusStmt->close();
            }

            if (!$row) {
                $errors[] = 'Batch item not found.';
            } elseif (($row['status'] ?? '') !== 'draft') {
                $errors[] = 'Only draft batch items can be changed.';
            } else {
                $toggleStmt = $db->prepare("UPDATE rpcppe_batch_items SET is_included = ? WHERE id = ? AND batch_id = ?");
                if ($toggleStmt) {
                    $toggleStmt->bind_param('iii', $includeValue, $itemId, $batchId);
                    $toggleStmt->execute();
                    $toggleStmt->close();
                    set_flash('success', $includeValue === 1 ? 'Asset included in RPCPPE.' : 'Asset excluded from RPCPPE.');
                    $query = 'modules/reports/rpcppe_batches.php?batch_id=' . $batchId;
                    if ($itemFilter !== 'all') {
                        $query .= '&item_filter=' . urlencode($itemFilter);
                    }
                    if ($search !== '') {
                        $query .= '&search=' . urlencode($search);
                    }
                    redirect($query);
                }
                $errors[] = 'Unable to update include status.';
            }
        }
    }

    $batches = rpcppe_load_batches($db);
    if ($selectedBatchId <= 0 && !empty($batches)) {
        $selectedBatchId = (int) $batches[0]['id'];
    }

    foreach ($batches as $batch) {
        if ((int) $batch['id'] === $selectedBatchId) {
            $selectedBatch = $batch;
            break;
        }
    }

    if (!$selectedBatch && !empty($batches)) {
        $selectedBatch = $batches[0];
        $selectedBatchId = (int) $selectedBatch['id'];
    }
}

$selectedItems = [];
$selectedStats = ['total' => 0, 'included' => 0, 'excluded' => 0, 'disposed' => 0];
$finalizedSources = [];

if ($db && $selectedBatch) {
    $statsStmt = $db->prepare("SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN is_included = 1 THEN 1 ELSE 0 END) AS included_count,
            SUM(CASE WHEN is_included = 0 THEN 1 ELSE 0 END) AS excluded_count,
            SUM(CASE WHEN is_disposed = 1 THEN 1 ELSE 0 END) AS disposed_count
        FROM rpcppe_batch_items
        WHERE batch_id = ?");
    if ($statsStmt) {
        $statsStmt->bind_param('i', $selectedBatchId);
        $statsStmt->execute();
        $statsRow = $statsStmt->get_result()->fetch_assoc();
        $statsStmt->close();
        if ($statsRow) {
            $selectedStats = [
                'total' => (int) ($statsRow['total_count'] ?? 0),
                'included' => (int) ($statsRow['included_count'] ?? 0),
                'excluded' => (int) ($statsRow['excluded_count'] ?? 0),
                'disposed' => (int) ($statsRow['disposed_count'] ?? 0),
            ];
        }
    }

    $itemsSql = "SELECT * FROM rpcppe_batch_items WHERE batch_id = ?";
    $types = 'i';
    $params = [$selectedBatchId];
    if ($itemFilter === 'included') {
        $itemsSql .= " AND is_included = 1";
    } elseif ($itemFilter === 'excluded') {
        $itemsSql .= " AND is_included = 0";
    } elseif ($itemFilter === 'disposed') {
        $itemsSql .= " AND is_disposed = 1";
    }

    if ($search !== '') {
        $itemsSql .= " AND (property_number LIKE ? OR item_description LIKE ? OR classification_name LIKE ? OR employee_name LIKE ? OR office_name LIKE ?)";
        $types .= 'sssss';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    $itemsSql .= " ORDER BY is_disposed ASC, is_included DESC, account_code ASC, classification_name ASC, item_description ASC, property_number ASC LIMIT 400";

    $itemStmt = $db->prepare($itemsSql);
    if ($itemStmt) {
        $refs = [$types];
        foreach ($params as $k => $v) {
            $refs[] = &$params[$k];
        }
        call_user_func_array([$itemStmt, 'bind_param'], $refs);
        $itemStmt->execute();
        $selectedItems = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemStmt->close();
    }

    $sourceStmt = $db->prepare("SELECT id, batch_year, batch_name FROM rpcppe_batches WHERE status = 'finalized' AND id <> ? ORDER BY batch_year DESC, id DESC LIMIT 20");
    if ($sourceStmt) {
        $sourceStmt->bind_param('i', $selectedBatchId);
        $sourceStmt->execute();
        $finalizedSources = $sourceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sourceStmt->close();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="report-toolbar mb-4">
                    <div>
                        <h5 class="report-toolbar-title mb-0">RPCPPE Annual Batches</h5>
                        <p class="report-toolbar-copy">Build yearly draft lists, carry forward included assets, remove disposed/returned items, then finalize a historical RPCPPE snapshot.</p>
                    </div>
                    <div class="report-toolbar-actions">
                        <a href="<?php echo h(base_url('modules/reports/rpcppe.php')); ?>" class="btn btn-outline-primary"><i class="bi bi-file-earmark-text me-1"></i>Open RPCPPE Report</a>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo h($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'error' ? 'danger' : 'info'); ?>">
                        <?php echo h($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <div class="col-xl-4">
                        <div class="border rounded-3 p-3 bg-light-subtle mb-3">
                            <h6 class="mb-3">Create New Batch</h6>
                            <form method="post" class="row g-2">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="create_batch">
                                <div class="col-6">
                                    <label class="form-label">Year</label>
                                    <input type="number" class="form-control" name="batch_year" value="<?php echo h((string) date('Y')); ?>" min="2000" max="2099" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">As Of</label>
                                    <input type="date" class="form-control" name="as_of_date" value="<?php echo h(date('Y-12-31')); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Batch Name</label>
                                    <input type="text" class="form-control" name="batch_name" placeholder="RPCPPE <?php echo h((string) date('Y')); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" name="notes" rows="2" placeholder="Optional"></textarea>
                                </div>
                                <div class="col-12 d-grid">
                                    <button type="submit" class="btn btn-primary">Create Draft Batch</button>
                                </div>
                            </form>
                            <form method="post" class="mt-2 d-grid">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="create_next_year_batch">
                                <button type="submit" class="btn btn-outline-info" onclick="return confirm('Create next-year draft from the latest finalized batch and carry forward included active assets?');">Create Next-Year Draft (Auto Carry-Forward)</button>
                            </form>
                        </div>

                        <div class="border rounded-3">
                            <div class="p-3 border-bottom"><h6 class="mb-0">Existing Batches</h6></div>
                            <div class="list-group list-group-flush">
                                <?php if ($batches): ?>
                                    <?php foreach ($batches as $batch): ?>
                                        <?php $active = (int) $batch['id'] === $selectedBatchId; ?>
                                        <a class="list-group-item list-group-item-action <?php echo $active ? 'active' : ''; ?>" href="<?php echo h(base_url('modules/reports/rpcppe_batches.php?batch_id=' . (int) $batch['id'])); ?>">
                                            <div class="d-flex justify-content-between align-items-start gap-2">
                                                <div>
                                                    <div class="fw-semibold"><?php echo h(rpcppe_batch_label($batch)); ?></div>
                                                    <div class="small <?php echo $active ? 'text-white-50' : 'text-muted'; ?>">
                                                        <?php echo h((string) $batch['batch_year']); ?> | As of <?php echo h(format_date((string) ($batch['as_of_date'] ?? ''), 'M d, Y')); ?>
                                                    </div>
                                                </div>
                                                <span class="badge <?php echo (($batch['status'] ?? '') === 'finalized') ? 'text-bg-success' : 'text-bg-warning'; ?>">
                                                    <?php echo h(ucfirst((string) $batch['status'])); ?>
                                                </span>
                                            </div>
                                            <div class="small mt-1 <?php echo $active ? 'text-white-50' : 'text-muted'; ?>">
                                                Total <?php echo number_format((int) ($batch['total_items'] ?? 0)); ?> |
                                                Included <?php echo number_format((int) ($batch['included_items'] ?? 0)); ?> |
                                                Disposed <?php echo number_format((int) ($batch['disposed_items'] ?? 0)); ?>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-3 text-muted">No RPCPPE batches yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8">
                        <?php if ($selectedBatch): ?>
                            <div class="border rounded-3 p-3 mb-3">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                    <div>
                                        <h6 class="mb-1"><?php echo h(rpcppe_batch_label($selectedBatch)); ?></h6>
                                        <div class="text-muted small">Year <?php echo h((string) ($selectedBatch['batch_year'] ?? '')); ?> | As of <?php echo h(format_date((string) ($selectedBatch['as_of_date'] ?? ''), 'F d, Y')); ?></div>
                                        <?php if (!empty($selectedBatch['notes'])): ?>
                                            <div class="small mt-1"><?php echo h((string) $selectedBatch['notes']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge <?php echo (($selectedBatch['status'] ?? '') === 'finalized') ? 'text-bg-success' : 'text-bg-warning'; ?>"><?php echo h(ucfirst((string) ($selectedBatch['status'] ?? 'draft'))); ?></span>
                                        <?php if (($selectedBatch['status'] ?? '') === 'finalized'): ?>
                                            <a href="<?php echo h(base_url('modules/reports/rpcppe.php?batch_id=' . (int) $selectedBatch['id'])); ?>" class="btn btn-sm btn-primary">Open in RPCPPE</a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-md-3"><div class="border rounded-3 p-2"><div class="small text-muted">Total</div><div class="fw-semibold"><?php echo number_format($selectedStats['total']); ?></div></div></div>
                                    <div class="col-md-3"><div class="border rounded-3 p-2"><div class="small text-muted">Included</div><div class="fw-semibold"><?php echo number_format($selectedStats['included']); ?></div></div></div>
                                    <div class="col-md-3"><div class="border rounded-3 p-2"><div class="small text-muted">Excluded</div><div class="fw-semibold"><?php echo number_format($selectedStats['excluded']); ?></div></div></div>
                                    <div class="col-md-3"><div class="border rounded-3 p-2"><div class="small text-muted">Disposed / Returned</div><div class="fw-semibold"><?php echo number_format($selectedStats['disposed']); ?></div></div></div>
                                </div>

                                <?php if (($selectedBatch['status'] ?? '') === 'draft'): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="load_live">
                                            <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                            <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirm('Reload this draft from current live assets? Existing draft items will be replaced.');">Load Live Assets</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="sync_live_missing">
                                            <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm">Add New Assets Since Draft</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="sync_disposals">
                                            <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                            <button type="submit" class="btn btn-outline-dark btn-sm">Refresh Disposed/Returned</button>
                                        </form>
                                        <form method="post" class="d-flex gap-2 align-items-center">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="import_property_numbers">
                                            <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                            <input type="text" name="excel_path" class="form-control form-control-sm" style="min-width: 240px;" value="database/imports/RPCPPE 2025.xlsx" placeholder="database/imports/RPCPPE 2025.xlsx">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Import RPCPPE Workbook</button>
                                        </form>
                                        <form method="post" class="d-flex gap-2 align-items-center">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="carry_forward">
                                            <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                            <select name="source_batch_id" class="form-select form-select-sm" style="min-width: 220px;">
                                                <option value="0">Auto-select latest finalized prior year</option>
                                                <?php foreach ($finalizedSources as $source): ?>
                                                    <option value="<?php echo (int) $source['id']; ?>"><?php echo h((string) $source['batch_year'] . ' - ' . ($source['batch_name'] ?? 'Batch')); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-outline-info btn-sm">Carry Forward</button>
                                        </form>
                                        <form method="post" class="d-inline ms-auto">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="delete_batch">
                                            <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this draft batch and all its items? This cannot be undone.');">Delete Draft Batch</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="finalize_batch">
                                            <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Finalize this batch? After finalize, items cannot be edited.');">Finalize Batch</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-success mb-0 py-2">This batch is finalized and locked for history. Use the RPCPPE report page with this batch selected.</div>
                                <?php endif; ?>
                            </div>

                            <div class="border rounded-3 p-3 mb-3">
                                <form method="get" class="row g-2 align-items-end">
                                    <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                    <div class="col-md-3">
                                        <label class="form-label">View</label>
                                        <select class="form-select" name="item_filter">
                                            <option value="all" <?php echo $itemFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                            <option value="included" <?php echo $itemFilter === 'included' ? 'selected' : ''; ?>>Included</option>
                                            <option value="excluded" <?php echo $itemFilter === 'excluded' ? 'selected' : ''; ?>>Excluded</option>
                                            <option value="disposed" <?php echo $itemFilter === 'disposed' ? 'selected' : ''; ?>>Disposed/Returned</option>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Search</label>
                                        <input type="text" class="form-control" name="search" value="<?php echo h($search); ?>" placeholder="Property no, description, office, accountable">
                                    </div>
                                    <div class="col-md-4 d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">Apply</button>
                                        <a href="<?php echo h(base_url('modules/reports/rpcppe_batches.php?batch_id=' . (int) $selectedBatchId)); ?>" class="btn btn-outline-secondary">Reset</a>
                                    </div>
                                </form>
                            </div>

                            <div class="table-responsive border rounded-3">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Property No.</th>
                                            <th>Description</th>
                                            <th>Office / Accountable</th>
                                            <th>Date Acquired</th>
                                            <th class="text-end">Unit Value</th>
                                            <th>Flags</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($selectedItems): ?>
                                            <?php foreach ($selectedItems as $item): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo h((string) ($item['property_number'] ?? '')); ?></div>
                                                        <div class="small text-muted"><?php echo h((string) ($item['account_code'] ?? '')); ?></div>
                                                    </td>
                                                    <td>
                                                        <div><?php echo h((string) ($item['item_description'] ?? '')); ?></div>
                                                        <div class="small text-muted"><?php echo h(trim(implode(', ', array_filter([(string) ($item['brand'] ?? ''), (string) ($item['model'] ?? ''), (string) ($item['serial_no'] ?? '')])))); ?></div>
                                                    </td>
                                                    <td>
                                                        <div><?php echo h((string) ($item['office_name'] ?? 'Unassigned')); ?></div>
                                                        <div class="small text-muted"><?php echo h((string) ($item['employee_name'] ?? '')); ?></div>
                                                    </td>
                                                    <td><?php $ad = (string) ($item['acquisition_date'] ?? ''); echo h($ad !== '' ? date('M d, Y', strtotime($ad)) : '—'); ?></td>
                                                    <td class="text-end"><?php echo h(number_format((float) ($item['unit_cost'] ?? 0), 2)); ?></td>
                                                    <td>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <span class="badge <?php echo ((int) ($item['is_included'] ?? 0) === 1) ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo ((int) ($item['is_included'] ?? 0) === 1) ? 'Included' : 'Excluded'; ?></span>
                                                            <span class="badge <?php echo ((int) ($item['is_disposed'] ?? 0) === 1) ? 'text-bg-danger' : 'text-bg-light'; ?>"><?php echo ((int) ($item['is_disposed'] ?? 0) === 1) ? 'Disposed/Returned' : 'Active'; ?></span>
                                                            <span class="badge text-bg-light"><?php echo h((string) (($item['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System')); ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if (($selectedBatch['status'] ?? '') === 'draft'): ?>
                                                            <?php if ((int) ($item['is_included'] ?? 0) === 1): ?>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                                    <input type="hidden" name="action" value="toggle_include">
                                                                    <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                                                    <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                                                    <input type="hidden" name="include_value" value="0">
                                                                    <button type="submit" class="btn btn-sm btn-outline-warning">Exclude</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                                    <input type="hidden" name="action" value="toggle_include">
                                                                    <input type="hidden" name="batch_id" value="<?php echo (int) $selectedBatchId; ?>">
                                                                    <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                                                    <input type="hidden" name="include_value" value="1">
                                                                    <button type="submit" class="btn btn-sm btn-outline-success">Include</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted small">Locked</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="7" class="text-center text-muted py-4">No batch items found for this filter.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">Create a batch to start yearly RPCPPE carry-forward and inclusion management.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
