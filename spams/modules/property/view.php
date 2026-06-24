<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/roles.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

$db = db();
if ($db && function_exists('ensure_receiving_item_variance_columns')) {
    ensure_receiving_item_variance_columns($db);
}
$page_title = 'Asset Details';
$flash = get_flash();
$source = trim((string) ($_GET['source'] ?? ''));
$id = (int) ($_GET['id'] ?? 0);
$asset = null;
$notices = [];
$assetPhotos = [];
$timeline = [];
$transfers = [];
$maintenanceRows = [];
$returnRows = [];
$disposalRows = [];
$latestInventoryCheck = null;
$brandOptions = [];
$brandQuickAddOptions = [];
$modelOptions = [];
$classificationOptions = [];
$accountCodeOptions = [];
$fundOptions = [];
$supplierOptions = [];
$unitOfMeasureOptions = [];
$locationOptions = [];
$officeOptions = [];
$employeeOptions = [];
$responsibilityCodeOptions = [];
$assetOfficeId = 0;
$resolvedLocationId = 0;
$resolvedManualLocation = '';
$resolvedLocationLat = null;
$resolvedLocationLng = null;

if (!in_array($source, ['system', 'legacy'], true) || $id <= 0) {
    http_response_code(404);
    exit('Asset not found.');
}

if ($source === 'legacy') {
    ensure_legacy_assets_unit_of_measure_column($db);
}

function asset_view_person(array $row, string $prefix = ''): string
{
    if ($prefix === '' && function_exists('employee_display_name')) {
        return employee_display_name($row);
    }

    $parts = [
        trim((string) ($row[$prefix . 'first_name'] ?? '')),
        trim((string) ($row[$prefix . 'middle_name'] ?? '')),
        trim((string) ($row[$prefix . 'last_name'] ?? '')),
        trim((string) ($row[$prefix . 'suffix_name'] ?? '')),
    ];

    return trim(implode(' ', array_filter($parts)));
}

function asset_view_type_label(string $itemType): string
{
    return $itemType === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment';
}

function asset_view_source_label(string $source): string
{
    return $source === 'legacy' ? 'Beginning Balance' : 'System Transaction';
}

function asset_view_can_manage_photos(): bool
{
    return in_array((string) ($_SESSION['user_role'] ?? ''), ['Administrator', 'Property Officer', 'Supply Officer'], true);
}

function asset_view_can_edit_details(): bool
{
    return in_array((string) ($_SESSION['user_role'] ?? ''), ['Administrator', 'Property Officer', 'Supply Officer'], true);
}

function asset_view_can_delete_legacy(): bool
{
    return in_array((string) ($_SESSION['user_role'] ?? ''), ['Administrator', 'Property Officer', 'Supply Officer'], true);
}

function asset_view_can_edit_source_po(): bool
{
    return in_array((string) ($_SESSION['user_role'] ?? ''), ['Administrator', 'Supply Officer'], true);
}

function asset_view_classification(array $row): string
{
    return trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
}

function asset_view_sort_timeline(array &$entries): void
{
    usort($entries, static function (array $a, array $b): int {
        $aDate = (string) ($a['date'] ?? '');
        $bDate = (string) ($b['date'] ?? '');
        if ($aDate === $bDate) {
            return strcmp((string) ($b['reference'] ?? ''), (string) ($a['reference'] ?? ''));
        }
        return strcmp($bDate, $aDate);
    });
}

function asset_view_parse_coordinate(string $value, float $min, float $max): ?float
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    $number = (float) $value;
    if ($number < $min || $number > $max) {
        return null;
    }

    return round($number, 7);
}

function asset_view_latest_inventory_check(mysqli $db, string $propertyNumber): ?array
{
    $propertyNumber = trim($propertyNumber);
    if ($propertyNumber === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT ici.id,
                ici.status,
                ici.remarks,
                ici.checked_at,
                ici.session_id,
                ics.system_reference AS session_reference,
                ics.count_type,
                ics.count_date,
                o.office_name
         FROM inventory_count_items ici
         INNER JOIN inventory_count_sessions ics ON ics.id = ici.session_id
         LEFT JOIN offices o ON o.id = ics.office_id
         WHERE ici.property_number = ?
           AND ici.checked_at IS NOT NULL
         ORDER BY ici.checked_at DESC, ici.id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $propertyNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row ?: null;
}

function asset_view_clean_office_suffix(string $officeCode): string
{
    $suffix = strtoupper(trim($officeCode));
    $suffix = preg_replace('/[^A-Z0-9]/', '', $suffix) ?? '';
    return $suffix !== '' ? $suffix : 'GEN';
}

function asset_view_property_number_with_office_suffix(string $propertyNumber, string $officeCode): string
{
    $propertyNumber = trim($propertyNumber);
    $officeSuffix = asset_view_clean_office_suffix($officeCode);

    if ($propertyNumber === '') {
        return $propertyNumber;
    }

    $lastDash = strrpos($propertyNumber, '-');
    if ($lastDash === false) {
        return $propertyNumber . '-' . $officeSuffix;
    }

    return substr($propertyNumber, 0, $lastDash + 1) . $officeSuffix;
}

function asset_view_account_code_short(string $accountCode): string
{
    $accountCode = trim($accountCode);
    if ($accountCode === '') {
        return '';
    }

    $acctParts = explode('.', $accountCode);
    if (isset($acctParts[2], $acctParts[3]) && $acctParts[2] === '03' && $acctParts[3] === '210' && isset($acctParts[4])) {
        return trim($acctParts[2] . '.' . $acctParts[3] . '.' . $acctParts[4]);
    }

    if (isset($acctParts[2], $acctParts[3])) {
        return trim($acctParts[2] . '.' . $acctParts[3]);
    }

    return $accountCode;
}

function asset_view_rebuild_property_number_sequence(
    string $originalPropertyNumber,
    string $year,
    string $fundCode,
    string $accountCode,
    string $officeCode
): string {
    $originalPropertyNumber = trim($originalPropertyNumber);
    if (!preg_match('/-(\d{4})-[^-]+$/', $originalPropertyNumber, $matches)) {
        return $originalPropertyNumber;
    }

    $fundSegment = preg_replace('/[^0-9]/', '', trim($fundCode)) ?? '';
    if ($fundSegment !== '') {
        $fundSegment = str_pad(substr($fundSegment, -2), 2, '0', STR_PAD_LEFT);
    }
    if ($fundSegment === '') {
        $fundSegment = 'GEN';
    }

    $accountShort = asset_view_account_code_short($accountCode);
    if ($accountShort === '') {
        $accountShort = 'GEN';
    }

    return trim($year)
        . '-' . $fundSegment
        . '-' . $accountShort
        . '-' . $matches[1]
        . '-' . asset_view_clean_office_suffix($officeCode);
}

function asset_view_generate_unique_property_number(
    mysqli $db,
    string $year,
    string $fundCode,
    string $accountCode,
    string $officeCode,
    string $currentSource,
    int $currentId
): string {
    $propertyNumber = '';
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $propertyNumber = generate_property_number($db, $year, $fundCode, $accountCode, $officeCode);
        if (!asset_identifier_conflict($db, 'property_number', $propertyNumber, $currentSource, $currentId)) {
            break;
        }
    }

    return $propertyNumber;
}

function asset_view_sync_system_property_number(mysqli $db, int $detailId, string $propertyNumber): bool
{
    if (
        schema_has_column($db, 'distribution_item_details', 'distribution_item_id')
        && schema_has_column($db, 'distribution_items', 'id')
        && schema_has_column($db, 'distribution_items', 'property_number')
    ) {
        $parentStmt = $db->prepare(
            'UPDATE distribution_items di
             INNER JOIN distribution_item_details did ON did.distribution_item_id = di.id
             SET di.property_number = ?
             WHERE did.id = ?'
        );
        if (!$parentStmt) {
            return false;
        }

        $parentStmt->bind_param('si', $propertyNumber, $detailId);
        $parentOk = $parentStmt->execute();
        $parentStmt->close();

        if (!$parentOk) {
            return false;
        }
    }

    $syncTargets = [
        ['table' => 'rpcppe_batch_items', 'id_column' => 'distribution_item_detail_id'],
        ['table' => 'inventory_count_items', 'id_column' => 'distribution_item_detail_id'],
        ['table' => 'asset_transfers', 'id_column' => 'distribution_item_detail_id'],
        ['table' => 'transfer_batch_items', 'id_column' => 'distribution_item_detail_id'],
    ];

    foreach ($syncTargets as $target) {
        $table = $target['table'];
        $idColumn = $target['id_column'];
        if (
            !schema_has_column($db, $table, 'property_number')
            || !schema_has_column($db, $table, $idColumn)
        ) {
            continue;
        }

        $stmt = $db->prepare("UPDATE {$table} SET property_number = ? WHERE {$idColumn} = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $propertyNumber, $detailId);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return false;
        }
    }

    return true;
}

if (!$db) {
    http_response_code(500);
    exit('Unable to connect to the database.');
}

ensure_asset_location_tracking_schema($db);

$locationRes = $db->query("SELECT id, location_code, location_name, description FROM locations WHERE is_active = 1 ORDER BY location_name ASC");
if ($locationRes instanceof mysqli_result) {
    while ($row = $locationRes->fetch_assoc()) {
        $locationOptions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'location_code' => (string) ($row['location_code'] ?? ''),
            'location_name' => (string) ($row['location_name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
        ];
    }
}

$officeRes = $db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
if ($officeRes instanceof mysqli_result) {
    while ($row = $officeRes->fetch_assoc()) {
        $officeOptions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'office_name' => (string) ($row['office_name'] ?? ''),
            'office_code' => (string) ($row['office_code'] ?? ''),
            'is_active' => 1,
        ];
    }
}

$employeeRes = $db->query("SELECT id, employee_no, office_id, responsibility_code_id, first_name, middle_name, last_name, suffix_name, is_unit_head FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC");
if ($employeeRes instanceof mysqli_result) {
    while ($row = $employeeRes->fetch_assoc()) {
        $employeeOptions[] = $row;
    }
}

$rcRes = $db->query("SELECT id, office_id, code, description FROM responsibility_codes WHERE is_active = 1 ORDER BY office_id ASC, code ASC");
if ($rcRes instanceof mysqli_result) {
    while ($row = $rcRes->fetch_assoc()) {
        $responsibilityCodeOptions[] = $row;
    }
}

$brandRes = $db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC");
if ($brandRes instanceof mysqli_result) {
    while ($row = $brandRes->fetch_assoc()) {
        $name = trim((string) ($row['brand_name'] ?? ''));
        if ($name !== '') {
            $brandOptions[] = $name;
            $brandQuickAddOptions[] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => $name,
            ];
        }
    }
}

$modelRes = $db->query("SELECT model_name FROM models WHERE is_active = 1 ORDER BY model_name ASC");
if ($modelRes instanceof mysqli_result) {
    while ($row = $modelRes->fetch_assoc()) {
        $name = trim((string) ($row['model_name'] ?? ''));
        if ($name !== '') {
            $modelOptions[] = $name;
        }
    }
}

$classificationRes = $db->query("SELECT id, classification_name, classification_family, classification_group, account_code_id FROM classifications WHERE is_active = 1 ORDER BY COALESCE(classification_family, ''), classification_name ASC");
if ($classificationRes instanceof mysqli_result) {
    while ($row = $classificationRes->fetch_assoc()) {
        $classificationOptions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'classification_name' => (string) ($row['classification_name'] ?? ''),
            'classification_family' => (string) ($row['classification_family'] ?? ''),
            'classification_group' => (string) ($row['classification_group'] ?? ''),
            'account_code_id' => (int) ($row['account_code_id'] ?? 0),
        ];
    }
}

$accountRes = $db->query("SELECT id, account_code, account_name, account_group FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
if ($accountRes instanceof mysqli_result) {
    while ($row = $accountRes->fetch_assoc()) {
        $accountCodeOptions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'account_code' => (string) ($row['account_code'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'account_group' => (string) ($row['account_group'] ?? ''),
        ];
    }
}

$fundRes = $db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC");
if ($fundRes instanceof mysqli_result) {
    while ($row = $fundRes->fetch_assoc()) {
        $fundOptions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'fund_code' => (string) ($row['fund_code'] ?? ''),
            'fund_name' => (string) ($row['fund_name'] ?? ''),
            'fund_source' => (string) ($row['fund_source'] ?? ''),
        ];
    }
}

$supplierRes = $db->query("SELECT id, supplier_name, supplier_code, is_active FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
if ($supplierRes instanceof mysqli_result) {
    while ($row = $supplierRes->fetch_assoc()) {
        $supplierOptions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'supplier_code' => (string) ($row['supplier_code'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0),
        ];
    }
}

$unitRes = $db->query("SELECT id, uom_name, abbreviation, is_active FROM unit_of_measures WHERE is_active = 1 ORDER BY uom_name ASC");
if ($unitRes instanceof mysqli_result) {
    while ($row = $unitRes->fetch_assoc()) {
        $unitOfMeasureOptions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'uom_name' => (string) ($row['uom_name'] ?? ''),
            'abbreviation' => (string) ($row['abbreviation'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0),
        ];
    }
}

if ($source === 'system') {
    $stmt = $db->prepare("
        SELECT
            did.id,
            did.property_number,
            did.brand,
            did.model,
            did.serial_no,
            did.current_office_id,
            did.current_employee_id,
            did.current_responsibility_code_id,
            did.manual_location,
            did.location_id,
            did.location_lat,
            did.location_lng,
            loc.location_code,
            loc.location_name,
            loc.description AS location_description,
            poi.item_type,
            COALESCE(NULLIF(ri.actual_item_description, ''), poi.item_description) AS item_description,
            poi.item_description AS ordered_item_description,
            ri.variance_type,
            ri.variance_note,
            ri.accepted_no_additional_cost,
            c.classification_name,
            c.classification_family,
            ac.id AS account_code_id,
            ac.account_code,
            ac.account_name,
            ri.unit_cost,
            r.id AS receiving_id,
            r.system_reference AS receiving_reference,
            r.ris_no,
            r.received_date,
            r.delivery_receipt_no,
            r.invoice_no,
            po.id AS purchase_order_id,
            po.po_number,
            po.po_date,
            po.supplier_id,
            s.supplier_name,
            f.id AS fund_id,
            f.fund_name,
            f.fund_code,
            f.fund_source,
            d.id AS distribution_id,
            d.office_id,
            d.employee_id,
            d.system_reference AS distribution_reference,
            d.document_no,
            d.document_type,
            d.distribution_date,
            d.purpose,
            d.remarks AS distribution_remarks,
            COALESCE(curr_o.office_name, base_o.office_name) AS office_name,
            COALESCE(curr_e.employee_no, base_e.employee_no) AS employee_no,
            COALESCE(curr_e.first_name, base_e.first_name) AS first_name,
            COALESCE(curr_e.middle_name, base_e.middle_name) AS middle_name,
            COALESCE(curr_e.last_name, base_e.last_name) AS last_name,
            COALESCE(curr_e.suffix_name, base_e.suffix_name) AS suffix_name,
            COALESCE(curr_e.position_title, base_e.position_title) AS position_title,
            COALESCE(curr_rc.code, base_rc.code) AS rc_code,
            si.system_reference AS stock_reference,
            CASE WHEN did.is_disposed IS NULL OR did.is_disposed = 0 THEN 0 ELSE 1 END AS is_disposed,
            did.is_distributed
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
        INNER JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
        LEFT JOIN stock_items si ON si.id = rid.stock_item_id
        INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        INNER JOIN receivings r ON r.id = ri.receiving_id
        INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
        LEFT JOIN offices base_o ON base_o.id = d.office_id
        LEFT JOIN employees base_e ON base_e.id = d.employee_id
        LEFT JOIN responsibility_codes base_rc ON base_rc.office_id = d.office_id
        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
        LEFT JOIN responsibility_codes curr_rc ON curr_rc.id = did.current_responsibility_code_id
        LEFT JOIN locations loc ON loc.id = did.location_id
        WHERE did.id = ?
          AND poi.item_type IN ('equipment', 'semi_expendable')
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $asset = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
} else {
    $stmt = $db->prepare("
        SELECT
            la.id,
            la.system_reference,
            la.property_number,
            la.item_type,
            la.item_description,
            la.item_name,
            la.item_name_id,
            la.classification_id,
            la.office_id,
            la.employee_id,
            la.responsibility_code_id,
            la.account_code_id,
            la.fund_id,
            la.manual_location,
            la.location_id,
            la.location_lat,
            la.location_lng,
            loc.location_code,
            loc.location_name,
            loc.description AS location_description,
            la.brand,
            la.model,
            la.serial_no,
            la.supplier_id,
            la.acquisition_date,
            la.quantity,
            la.unit_of_measure_id,
            la.unit_cost,
            la.acquisition_cost,
            la.condition_status,
            la.remarks,
            s.supplier_name,
            c.classification_name,
            c.classification_family,
            ac.account_code,
            ac.account_name,
            f.fund_name,
            f.fund_code,
            f.fund_source,
            u.uom_name,
            u.abbreviation AS unit_abbreviation,
            o.office_name,
            o.office_code,
            e.employee_no,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name,
            e.position_title,
            rc.code AS rc_code
        FROM legacy_assets la
        LEFT JOIN suppliers s ON s.id = la.supplier_id
        LEFT JOIN classifications c ON c.id = la.classification_id
        LEFT JOIN account_codes ac ON ac.id = la.account_code_id
        LEFT JOIN funds f ON f.id = la.fund_id
        LEFT JOIN unit_of_measures u ON u.id = la.unit_of_measure_id
        LEFT JOIN offices o ON o.id = la.office_id
        LEFT JOIN employees e ON e.id = la.employee_id
        LEFT JOIN responsibility_codes rc ON rc.id = la.responsibility_code_id
        LEFT JOIN locations loc ON loc.id = la.location_id
        WHERE la.id = ?
          AND la.is_active = 1
          AND la.item_type IN ('equipment', 'semi_expendable')
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $asset = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($asset) {
    $assetOfficeId = $source === 'system'
        ? (int) (($asset['current_office_id'] ?? 0) ?: ($asset['office_id'] ?? 0))
        : (int) ($asset['office_id'] ?? 0);

    $resolvedLocationId = (int) ($asset['location_id'] ?? 0);
    $resolvedManualLocation = trim((string) ($asset['manual_location'] ?? ''));
    if ($resolvedManualLocation === '' && !empty($asset['location_name'])) {
        $resolvedManualLocation = trim((string) $asset['location_name']);
    }

    if (isset($asset['location_lat']) && $asset['location_lat'] !== null && $asset['location_lat'] !== '') {
        $resolvedLocationLat = (float) $asset['location_lat'];
    }

    if (isset($asset['location_lng']) && $asset['location_lng'] !== null && $asset['location_lng'] !== '') {
        $resolvedLocationLng = (float) $asset['location_lng'];
    }
}

if ($assetOfficeId > 0) {
    $hasAssetOfficeOption = false;
    foreach ($officeOptions as $officeOption) {
        if ((int) ($officeOption['id'] ?? 0) === $assetOfficeId) {
            $hasAssetOfficeOption = true;
            break;
        }
    }

    if (!$hasAssetOfficeOption) {
        $assetOfficeStmt = $db->prepare("SELECT id, office_name, office_code, is_active FROM offices WHERE id = ? LIMIT 1");
        if ($assetOfficeStmt) {
            $assetOfficeStmt->bind_param('i', $assetOfficeId);
            $assetOfficeStmt->execute();
            $assetOfficeRow = $assetOfficeStmt->get_result()->fetch_assoc();
            $assetOfficeStmt->close();

            if ($assetOfficeRow) {
                $officeOptions[] = [
                    'id' => (int) ($assetOfficeRow['id'] ?? 0),
                    'office_name' => (string) ($assetOfficeRow['office_name'] ?? ''),
                    'office_code' => (string) ($assetOfficeRow['office_code'] ?? ''),
                    'is_active' => (int) ($assetOfficeRow['is_active'] ?? 0),
                ];
            }
        }
    }
}

if ($asset) {
    $assetSupplierId = (int) ($asset['supplier_id'] ?? 0);
    if ($assetSupplierId > 0) {
        $hasAssetSupplierOption = false;
        foreach ($supplierOptions as $supplierOption) {
            if ((int) ($supplierOption['id'] ?? 0) === $assetSupplierId) {
                $hasAssetSupplierOption = true;
                break;
            }
        }

        if (!$hasAssetSupplierOption) {
            $assetSupplierStmt = $db->prepare("SELECT id, supplier_name, supplier_code, is_active FROM suppliers WHERE id = ? LIMIT 1");
            if ($assetSupplierStmt) {
                $assetSupplierStmt->bind_param('i', $assetSupplierId);
                $assetSupplierStmt->execute();
                $assetSupplierRow = $assetSupplierStmt->get_result()->fetch_assoc();
                $assetSupplierStmt->close();

                if ($assetSupplierRow) {
                    $supplierOptions[] = [
                        'id' => (int) ($assetSupplierRow['id'] ?? 0),
                        'supplier_name' => (string) ($assetSupplierRow['supplier_name'] ?? ''),
                        'supplier_code' => (string) ($assetSupplierRow['supplier_code'] ?? ''),
                        'is_active' => (int) ($assetSupplierRow['is_active'] ?? 0),
                    ];
                }
            }
        }
    }
}

if ($asset && $source === 'legacy') {
    $assetUnitOfMeasureId = (int) ($asset['unit_of_measure_id'] ?? 0);
    if ($assetUnitOfMeasureId > 0) {
        $hasAssetUnitOption = false;
        foreach ($unitOfMeasureOptions as $unitOption) {
            if ((int) ($unitOption['id'] ?? 0) === $assetUnitOfMeasureId) {
                $hasAssetUnitOption = true;
                break;
            }
        }

        if (!$hasAssetUnitOption) {
            $assetUnitStmt = $db->prepare("SELECT id, uom_name, abbreviation, is_active FROM unit_of_measures WHERE id = ? LIMIT 1");
            if ($assetUnitStmt) {
                $assetUnitStmt->bind_param('i', $assetUnitOfMeasureId);
                $assetUnitStmt->execute();
                $assetUnitRow = $assetUnitStmt->get_result()->fetch_assoc();
                $assetUnitStmt->close();

                if ($assetUnitRow) {
                    $unitOfMeasureOptions[] = [
                        'id' => (int) ($assetUnitRow['id'] ?? 0),
                        'uom_name' => (string) ($assetUnitRow['uom_name'] ?? ''),
                        'abbreviation' => (string) ($assetUnitRow['abbreviation'] ?? ''),
                        'is_active' => (int) ($assetUnitRow['is_active'] ?? 0),
                    ];
                }
            }
        }
    }
}

if (!$asset) {
    http_response_code(404);
    exit('Asset not found.');
}

$canManagePhotos = asset_view_can_manage_photos();
$canEditDetails = asset_view_can_edit_details();
$canDeleteLegacy = asset_view_can_delete_legacy();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($canManagePhotos || $canEditDetails)) {
    if (!csrf_verify()) {
        set_flash('error', 'Invalid CSRF token.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_asset_details') {
        if (!$canEditDetails) {
            set_flash('error', 'You are not allowed to edit asset details.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        $originalPropertyNumber = trim((string) ($asset['property_number'] ?? ''));
        $originalSerialNo = trim((string) ($asset['serial_no'] ?? ''));
        $propertyNumber = trim((string) ($_POST['property_number'] ?? ''));
        $propertyNumberWasAutoManaged = false;
        $canGenerateOfficialPropertyNumber = false;
        $effectiveYear = '';
        $effectiveFundCode = '';
        $effectiveAccountCode = '';
        $brand = trim((string) ($_POST['brand'] ?? ''));
        $model = trim((string) ($_POST['model'] ?? ''));
        $serialNo = trim((string) ($_POST['serial_no'] ?? ''));
        $supplierIdInput = (int) ($_POST['supplier_id'] ?? 0);
        $officeIdInput = (int) ($_POST['office_id'] ?? 0);
        $employeeIdInput = (int) ($_POST['employee_id'] ?? 0);
        $responsibilityCodeIdInput = (int) ($_POST['responsibility_code_id'] ?? 0);
        $locationIdInput = (int) ($_POST['location_id'] ?? 0);
        $manualLocation = '';
        $locationLat = null;
        $locationLng = null;
        $classificationIdInput = (int) ($_POST['classification_id'] ?? 0);
        $accountCodeIdInput = (int) ($_POST['account_code_id'] ?? 0);
        $fundIdInput = (int) ($_POST['fund_id'] ?? 0);
        $rawAcquisitionDate = trim((string) ($_POST['acquisition_date'] ?? ''));
        $acquisitionDate = normalize_date_string($rawAcquisitionDate);
        $legacyItemTypeInput = trim((string) ($_POST['item_type'] ?? ($asset['item_type'] ?? 'equipment')));

        if ($propertyNumber === '' && $serialNo !== '') {
            $propertyNumber = $serialNo;
        }

        if ($propertyNumber === '') {
            set_flash('error', 'Property number is required.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        if ($source === 'legacy' && $rawAcquisitionDate !== '' && $acquisitionDate === '') {
            set_flash('error', 'Acquisition date must be a valid date from 1900 to 2100.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        if ($source === 'legacy' && !in_array($legacyItemTypeInput, ['equipment', 'semi_expendable'], true)) {
            set_flash('error', 'Inventory type must be Equipment or Semi-Expendable.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        if ($serialNo !== '') {
            $serialConflict = asset_identifier_conflict($db, 'serial_no', $serialNo, $source, $id);
            if ($serialConflict) {
                set_flash('error', 'Serial number already exists in ' . $serialConflict['label'] . ' #' . $serialConflict['id'] . '.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }

            if (
                $serialNo !== $originalSerialNo
                && $propertyNumber === $originalPropertyNumber
                && ($originalPropertyNumber === '' || strcasecmp($originalPropertyNumber, $originalSerialNo) === 0)
            ) {
                $propertyNumber = $serialNo;
            }
        }

        if ($officeIdInput <= 0) {
            set_flash('error', 'Office assignment is required.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        if ($supplierIdInput > 0) {
            $supplierStmt = $db->prepare("SELECT id, is_active FROM suppliers WHERE id = ? LIMIT 1");
            $supplierRow = null;
            if ($supplierStmt) {
                $supplierStmt->bind_param('i', $supplierIdInput);
                $supplierStmt->execute();
                $supplierRow = $supplierStmt->get_result()->fetch_assoc();
                $supplierStmt->close();
            }
            if (!$supplierRow) {
                set_flash('error', 'Selected supplier is invalid.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
            if ((int) ($supplierRow['is_active'] ?? 0) !== 1 && $supplierIdInput !== (int) ($asset['supplier_id'] ?? 0)) {
                set_flash('error', 'Selected supplier is inactive. Choose an active supplier.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
        }

        $officeStmt = $db->prepare("SELECT id, office_name, office_code, is_active FROM offices WHERE id = ? LIMIT 1");
        $officeRow = null;
        if ($officeStmt) {
            $officeStmt->bind_param('i', $officeIdInput);
            $officeStmt->execute();
            $officeRow = $officeStmt->get_result()->fetch_assoc();
            $officeStmt->close();
        }
        if (!$officeRow) {
            set_flash('error', 'Selected office is invalid.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        if ((int) ($officeRow['is_active'] ?? 0) !== 1 && $officeIdInput !== $assetOfficeId) {
            set_flash('error', 'Selected office is inactive. Choose an active office assignment.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        $employeeNameSnapshot = '';
        if ($employeeIdInput > 0) {
            $employeeStmt = $db->prepare(
                "SELECT e.id, e.office_id, e.first_name, e.middle_name, e.last_name, e.suffix_name,
                        EXISTS (
                            SELECT 1
                            FROM employee_assignments ea
                            WHERE ea.employee_id = e.id
                              AND ea.office_id = ?
                              AND ea.is_active = 1
                        ) AS has_office_assignment
                 FROM employees e
                 WHERE e.id = ? AND e.is_active = 1
                 LIMIT 1"
            );
            $employeeRow = null;
            if ($employeeStmt) {
                $employeeStmt->bind_param('ii', $officeIdInput, $employeeIdInput);
                $employeeStmt->execute();
                $employeeRow = $employeeStmt->get_result()->fetch_assoc();
                $employeeStmt->close();
            }
            if (!$employeeRow || ((int) ($employeeRow['office_id'] ?? 0) !== $officeIdInput && (int) ($employeeRow['has_office_assignment'] ?? 0) !== 1)) {
                set_flash('error', 'Selected accountable employee does not belong to the selected office.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
            $employeeNameSnapshot = trim(implode(' ', array_filter([
                trim((string) ($employeeRow['first_name'] ?? '')),
                trim((string) ($employeeRow['middle_name'] ?? '')),
                trim((string) ($employeeRow['last_name'] ?? '')),
                trim((string) ($employeeRow['suffix_name'] ?? '')),
            ])));
        }

        if ($responsibilityCodeIdInput > 0) {
            $rcStmt = $db->prepare("SELECT id FROM responsibility_codes WHERE id = ? AND office_id = ? AND is_active = 1 LIMIT 1");
            $rcRow = null;
            if ($rcStmt) {
                $rcStmt->bind_param('ii', $responsibilityCodeIdInput, $officeIdInput);
                $rcStmt->execute();
                $rcRow = $rcStmt->get_result()->fetch_assoc();
                $rcStmt->close();
            }
            if (!$rcRow) {
                set_flash('error', 'Selected responsibility code does not belong to the selected office.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
        }

        if ($locationIdInput > 0) {
            $locationStmt = $db->prepare("SELECT location_code, location_name FROM locations WHERE id = ? AND is_active = 1 LIMIT 1");
            if ($locationStmt) {
                $locationStmt->bind_param('i', $locationIdInput);
                $locationStmt->execute();
                $locationRow = $locationStmt->get_result()->fetch_assoc();
                $locationStmt->close();
                if (!$locationRow) {
                    set_flash('error', 'Selected location is invalid.');
                    redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
                }
                $manualLocation = trim((string) ($locationRow['location_name'] ?? ''));
            }
        }

        if ($source === 'legacy' && $accountCodeIdInput > 0) {
            $expectedAccountGroups = $legacyItemTypeInput === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
            $checkAccountStmt = $db->prepare("SELECT id, account_group FROM account_codes WHERE id = ? AND is_active = 1 LIMIT 1");
            if ($checkAccountStmt) {
                $checkAccountStmt->bind_param('i', $accountCodeIdInput);
                $checkAccountStmt->execute();
                $exists = $checkAccountStmt->get_result()->fetch_assoc();
                $checkAccountStmt->close();
                if (!$exists) {
                    set_flash('error', 'Selected account code is invalid.');
                    redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
                }
                if (!in_array(trim((string) ($exists['account_group'] ?? '')), $expectedAccountGroups, true)) {
                    set_flash('error', 'Selected account code does not match the inventory type.');
                    redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
                }
            }
        }

        $classificationNameSnapshot = '';
        $classificationFamilySnapshot = '';
        if ($source === 'legacy' && $classificationIdInput > 0) {
            $checkClassificationStmt = $db->prepare("SELECT classification_name, classification_family, classification_group, account_code_id FROM classifications WHERE id = ? AND is_active = 1 LIMIT 1");
            $classificationRow = null;
            if ($checkClassificationStmt) {
                $checkClassificationStmt->bind_param('i', $classificationIdInput);
                $checkClassificationStmt->execute();
                $classificationRow = $checkClassificationStmt->get_result()->fetch_assoc();
                $checkClassificationStmt->close();
            }
            if (!$classificationRow) {
                set_flash('error', 'Selected item classification is invalid.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
            $classificationGroup = trim((string) ($classificationRow['classification_group'] ?? ''));
            if ($classificationGroup === '' && (int) ($classificationRow['account_code_id'] ?? 0) > 0) {
                foreach ($accountCodeOptions as $option) {
                    if ((int) ($option['id'] ?? 0) === (int) ($classificationRow['account_code_id'] ?? 0)) {
                        $classificationGroup = trim((string) ($option['account_group'] ?? ''));
                        break;
                    }
                }
            }
            $expectedClassificationGroups = $legacyItemTypeInput === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
            if (!in_array($classificationGroup, $expectedClassificationGroups, true)) {
                set_flash('error', 'Selected item classification does not match the inventory type.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
            $classificationNameSnapshot = trim((string) ($classificationRow['classification_name'] ?? ''));
            $classificationFamilySnapshot = trim((string) ($classificationRow['classification_family'] ?? ''));
        }

        if ($source === 'legacy' && $fundIdInput > 0) {
            $checkFundStmt = $db->prepare("SELECT id FROM funds WHERE id = ? AND is_active = 1 LIMIT 1");
            if ($checkFundStmt) {
                $checkFundStmt->bind_param('i', $fundIdInput);
                $checkFundStmt->execute();
                $exists = $checkFundStmt->get_result()->fetch_assoc();
                $checkFundStmt->close();
                if (!$exists) {
                    set_flash('error', 'Selected fund is invalid.');
                    redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
                }
            }
        }

        if ($source === 'legacy') {
            $effectiveAccountCodeId = $accountCodeIdInput > 0 ? $accountCodeIdInput : (int) ($asset['account_code_id'] ?? 0);
            $effectiveFundId = $fundIdInput > 0 ? $fundIdInput : (int) ($asset['fund_id'] ?? 0);
            $effectiveAccountCode = '';
            $effectiveAccountGroup = '';
            $effectiveFundCode = '';
            $effectiveOfficeCode = trim((string) ($officeRow['office_code'] ?? ''));
            $effectiveYear = $acquisitionDate !== ''
                ? date('Y', strtotime($acquisitionDate))
                : (($asset['acquisition_date'] ?? '') !== '' ? date('Y', strtotime((string) $asset['acquisition_date'])) : '');
            $currentYear = ($asset['acquisition_date'] ?? '') !== ''
                ? date('Y', strtotime((string) $asset['acquisition_date']))
                : '';

            foreach ($accountCodeOptions as $option) {
                if ((int) ($option['id'] ?? 0) === $effectiveAccountCodeId) {
                    $effectiveAccountCode = trim((string) ($option['account_code'] ?? ''));
                    $effectiveAccountGroup = trim((string) ($option['account_group'] ?? ''));
                    break;
                }
            }
            $expectedEffectiveAccountGroups = $legacyItemTypeInput === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
            if ($effectiveAccountCodeId > 0 && !in_array($effectiveAccountGroup, $expectedEffectiveAccountGroups, true)) {
                set_flash('error', 'Selected account code does not match the inventory type.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
            $effectiveClassificationId = $classificationIdInput > 0 ? $classificationIdInput : (int) ($asset['classification_id'] ?? 0);
            $effectiveClassificationGroup = '';
            $effectiveClassificationAccountCodeId = 0;
            foreach ($classificationOptions as $option) {
                if ((int) ($option['id'] ?? 0) === $effectiveClassificationId) {
                    $effectiveClassificationGroup = trim((string) ($option['classification_group'] ?? ''));
                    $effectiveClassificationAccountCodeId = (int) ($option['account_code_id'] ?? 0);
                    break;
                }
            }
            if ($effectiveClassificationGroup === '' && $effectiveClassificationAccountCodeId > 0) {
                foreach ($accountCodeOptions as $option) {
                    if ((int) ($option['id'] ?? 0) === $effectiveClassificationAccountCodeId) {
                        $effectiveClassificationGroup = trim((string) ($option['account_group'] ?? ''));
                        break;
                    }
                }
            }
            if ($effectiveClassificationId > 0 && $effectiveClassificationGroup !== '' && !in_array($effectiveClassificationGroup, $expectedEffectiveAccountGroups, true)) {
                set_flash('error', 'Selected item classification does not match the inventory type.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
            foreach ($fundOptions as $option) {
                if ((int) ($option['id'] ?? 0) === $effectiveFundId) {
                    $effectiveFundCode = fund_number_from_source((string) ($option['fund_code'] ?? ''), (string) ($option['fund_source'] ?? ''));
                    if ($effectiveFundCode === '') {
                        $effectiveFundCode = trim((string) ($option['fund_code'] ?? ''));
                    }
                    break;
                }
            }

            $propertyIdentityInputsChanged = $propertyNumber === $originalPropertyNumber
                && (
                    $effectiveYear !== $currentYear
                    || $effectiveAccountCodeId !== (int) ($asset['account_code_id'] ?? 0)
                    || $effectiveFundId !== (int) ($asset['fund_id'] ?? 0)
                );
            $canGenerateOfficialPropertyNumber = $effectiveYear !== '' && $effectiveAccountCode !== '' && $effectiveFundCode !== '';

            if (
                (stripos($propertyNumber, 'TEMP-') === 0 || $propertyIdentityInputsChanged)
                && $canGenerateOfficialPropertyNumber
            ) {
                $propertyNumber = asset_view_generate_unique_property_number(
                    $db,
                    $effectiveYear,
                    $effectiveFundCode,
                    $effectiveAccountCode,
                    $effectiveOfficeCode,
                    $source,
                    $id
                );
                $propertyNumberWasAutoManaged = true;
            } elseif (
                $propertyNumber === $originalPropertyNumber
                && $officeIdInput !== $assetOfficeId
                && stripos($originalPropertyNumber, 'TEMP-') !== 0
            ) {
                $propertyNumber = asset_view_property_number_with_office_suffix(
                    $originalPropertyNumber,
                    $effectiveOfficeCode
                );
                $propertyNumberWasAutoManaged = true;
            }
        }

        if (strcasecmp($propertyNumber, $originalPropertyNumber) !== 0) {
            $propertyConflict = asset_identifier_conflict($db, 'property_number', $propertyNumber, $source, $id);
            if ($propertyConflict && $source === 'legacy' && $propertyNumberWasAutoManaged && $canGenerateOfficialPropertyNumber) {
                $propertyNumber = asset_view_generate_unique_property_number(
                    $db,
                    $effectiveYear,
                    $effectiveFundCode,
                    $effectiveAccountCode,
                    (string) ($officeRow['office_code'] ?? ''),
                    $source,
                    $id
                );
                $propertyConflict = asset_identifier_conflict($db, 'property_number', $propertyNumber, $source, $id);
            }

            if ($propertyConflict) {
                set_flash('error', 'Property number already exists in ' . $propertyConflict['label'] . ' #' . $propertyConflict['id'] . '.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
        }

        if ($source === 'system') {
            $db->begin_transaction();
            $saved = false;
            $userId = current_user_id();

            $stmt = $db->prepare("UPDATE distribution_item_details
                                  SET property_number = ?,
                                      brand = ?,
                                      model = ?,
                                      serial_no = ?,
                                      current_office_id = ?,
                                      current_employee_id = NULLIF(?, 0),
                                      current_responsibility_code_id = NULLIF(?, 0)
                                  WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ssssiiii', $propertyNumber, $brand, $model, $serialNo, $officeIdInput, $employeeIdInput, $responsibilityCodeIdInput, $id);
                $saved = (bool) $stmt->execute();
                $stmt->close();
            }

            if ($saved) {
                $saved = asset_view_sync_system_property_number($db, $id, $propertyNumber);
            }

            if ($saved) {
                $purchaseOrderId = (int) ($asset['purchase_order_id'] ?? 0);
                if ($purchaseOrderId > 0) {
                    $poStmt = $db->prepare("UPDATE purchase_orders SET supplier_id = NULLIF(?, 0), updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($poStmt) {
                        $poStmt->bind_param('iii', $supplierIdInput, $userId, $purchaseOrderId);
                        $saved = (bool) $poStmt->execute();
                        $poStmt->close();
                    } else {
                        $saved = false;
                    }
                }
            }

            if ($saved) {
                $saved = update_asset_location_snapshot(
                    $db,
                    'system',
                    $id,
                    $manualLocation,
                    $locationLat,
                    $locationLng,
                    (int) $userId,
                    'asset_details_edit',
                    null,
                    null,
                    $locationIdInput
                );
            }

            if ($saved) {
                $db->commit();
                write_audit_log($db, [
                    'action' => 'update',
                    'table_name' => 'distribution_item_details',
                    'record_id' => $id,
                    'module_name' => 'property',
                    'record_type' => 'asset',
                    'action_name' => 'edit_asset_details',
                    'description' => 'Updated asset details from Asset Details page.',
                    'old_values' => [
                        'property_number' => $asset['property_number'] ?? null,
                        'brand' => $asset['brand'] ?? null,
                        'model' => $asset['model'] ?? null,
                        'serial_no' => $asset['serial_no'] ?? null,
                        'supplier_id' => $asset['supplier_id'] ?? null,
                        'office_id' => $assetOfficeId ?: null,
                        'employee_id' => ($asset['current_employee_id'] ?? 0) ?: ($asset['employee_id'] ?? null),
                        'responsibility_code_id' => $asset['current_responsibility_code_id'] ?? null,
                        'location_id' => $asset['location_id'] ?? null,
                        'manual_location' => $asset['manual_location'] ?? null,
                    ],
                    'new_values' => [
                        'property_number' => $propertyNumber,
                        'brand' => $brand,
                        'model' => $model,
                        'serial_no' => $serialNo,
                        'supplier_id' => $supplierIdInput > 0 ? $supplierIdInput : null,
                        'office_id' => $officeIdInput,
                        'employee_id' => $employeeIdInput > 0 ? $employeeIdInput : null,
                        'responsibility_code_id' => $responsibilityCodeIdInput > 0 ? $responsibilityCodeIdInput : null,
                        'location_id' => $locationIdInput,
                        'manual_location' => $manualLocation,
                    ],
                ]);
                set_flash('success', 'Asset details updated successfully.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }

            $db->rollback();
            set_flash('error', 'Unable to update asset details right now.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        $description = trim((string) ($_POST['item_description'] ?? ''));
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $unitCostInput = trim((string) ($_POST['unit_cost'] ?? '0'));
        $unitCost = is_numeric($unitCostInput) ? round((float) $unitCostInput, 2) : 0.0;
        if ($unitCost < 0) {
            $unitCost = 0.0;
        }
        $acquisitionCost = round($unitCost * $quantity, 2);
        $conditionStatus = trim((string) ($_POST['condition_status'] ?? 'serviceable'));
        $remarksInput = trim((string) ($_POST['remarks'] ?? ''));
        $unitOfMeasureIdInput = max(0, (int) ($_POST['unit_of_measure_id'] ?? 0));

        if ($unitOfMeasureIdInput > 0) {
            $unitStmt = $db->prepare("SELECT id, is_active FROM unit_of_measures WHERE id = ? LIMIT 1");
            $unitRow = null;
            if ($unitStmt) {
                $unitStmt->bind_param('i', $unitOfMeasureIdInput);
                $unitStmt->execute();
                $unitRow = $unitStmt->get_result()->fetch_assoc();
                $unitStmt->close();
            }
            if (!$unitRow) {
                set_flash('error', 'Selected unit type is invalid.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
            if ((int) ($unitRow['is_active'] ?? 0) !== 1 && $unitOfMeasureIdInput !== (int) ($asset['unit_of_measure_id'] ?? 0)) {
                set_flash('error', 'Selected unit type is inactive. Choose an active unit type.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
        }

        $stmt = $db->prepare("UPDATE legacy_assets
                              SET property_number = ?,
                                  item_type = ?,
                                  item_description = ?,
                                  brand = ?,
                                  model = ?,
                                  serial_no = ?,
                                  supplier_id = NULLIF(?, 0),
                                  classification_id = CASE WHEN ? > 0 THEN ? ELSE classification_id END,
                                  office_id = ?,
                                  employee_id = NULLIF(?, 0),
                                  responsibility_code_id = NULLIF(?, 0),
                                  acquisition_date = NULLIF(?, ''),
                                  account_code_id = CASE WHEN ? > 0 THEN ? ELSE account_code_id END,
                                  fund_id = CASE WHEN ? > 0 THEN ? ELSE fund_id END,
                                  quantity = ?,
                                  unit_of_measure_id = NULLIF(?, 0),
                                  unit_cost = ?,
                                  acquisition_cost = ?,
                                  condition_status = ?,
                                  remarks = ?
                              WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param(
                'ssssssiiiiiisiiiiiiddssi',
                $propertyNumber,
                $legacyItemTypeInput,
                $description,
                $brand,
                $model,
                $serialNo,
                $supplierIdInput,
                $classificationIdInput,
                $classificationIdInput,
                $officeIdInput,
                $employeeIdInput,
                $responsibilityCodeIdInput,
                $acquisitionDate,
                $accountCodeIdInput,
                $accountCodeIdInput,
                $fundIdInput,
                $fundIdInput,
                $quantity,
                $unitOfMeasureIdInput,
                $unitCost,
                $acquisitionCost,
                $conditionStatus,
                $remarksInput,
                $id
            );
            $saved = $stmt->execute();
            $stmt->close();
            if ($saved) {
                $classificationItemNameId = 0;
                if ($classificationIdInput > 0 && schema_has_column($db, 'legacy_assets', 'item_name')) {
                    if (schema_has_column($db, 'legacy_assets', 'item_name_id')) {
                        $itemNameLookupStmt = $db->prepare("SELECT id FROM item_names WHERE normalized_name = LOWER(TRIM(?)) LIMIT 1");
                        if ($itemNameLookupStmt) {
                            $itemNameLookupStmt->bind_param('s', $classificationNameSnapshot);
                            $itemNameLookupStmt->execute();
                            $itemNameRow = $itemNameLookupStmt->get_result()->fetch_assoc();
                            $itemNameLookupStmt->close();
                            $classificationItemNameId = (int) ($itemNameRow['id'] ?? 0);
                        }
                    }

                    $itemNameSql = schema_has_column($db, 'legacy_assets', 'item_name_id')
                        ? "UPDATE legacy_assets SET item_name = NULLIF(?, ''), item_name_id = NULLIF(?, 0) WHERE id = ?"
                        : "UPDATE legacy_assets SET item_name = NULLIF(?, '') WHERE id = ?";
                    $itemNameStmt = $db->prepare($itemNameSql);
                    if ($itemNameStmt) {
                        if (schema_has_column($db, 'legacy_assets', 'item_name_id')) {
                            $itemNameStmt->bind_param('sii', $classificationNameSnapshot, $classificationItemNameId, $id);
                        } else {
                            $itemNameStmt->bind_param('si', $classificationNameSnapshot, $id);
                        }
                        $itemNameStmt->execute();
                        $itemNameStmt->close();
                    }
                }

                $syncStmt = $db->prepare(
                    "UPDATE rpcppe_batch_items
                     SET property_number = ?,
                         office_id = ?,
                         office_name = ?,
                         employee_id = NULLIF(?, 0),
                         employee_name = NULLIF(?, ''),
                         acquisition_date = NULLIF(?, ''),
                         item_name = CASE WHEN ? > 0 THEN NULLIF(?, '') ELSE item_name END,
                         item_name_id = CASE WHEN ? > 0 THEN NULLIF(?, 0) ELSE item_name_id END,
                         classification_name = CASE WHEN ? > 0 THEN NULLIF(?, '') ELSE classification_name END,
                         classification_family = CASE WHEN ? > 0 THEN NULLIF(?, '') ELSE classification_family END,
                         item_description = ?,
                         updated_at = NOW()
                     WHERE legacy_asset_id = ?"
                );
                if ($syncStmt) {
                    $officeNameSnapshot = (string) ($officeRow['office_name'] ?? '');
                    $syncStmt->bind_param(
                        'sisissisiiisissi',
                        $propertyNumber,
                        $officeIdInput,
                        $officeNameSnapshot,
                        $employeeIdInput,
                        $employeeNameSnapshot,
                        $acquisitionDate,
                        $classificationIdInput,
                        $classificationNameSnapshot,
                        $classificationIdInput,
                        $classificationItemNameId,
                        $classificationIdInput,
                        $classificationNameSnapshot,
                        $classificationIdInput,
                        $classificationFamilySnapshot,
                        $description,
                        $id
                    );
                    $syncStmt->execute();
                    $syncStmt->close();
                }

                $countStmt = $db->prepare(
                    "UPDATE inventory_count_items
                     SET property_number = ?,
                         item_type = ?,
                         office_id = ?,
                         employee_id = (SELECT e.id FROM employees e WHERE e.id = NULLIF(?, 0) LIMIT 1),
                         accountable_name = NULLIF(?, ''),
                         classification_name = CASE WHEN ? > 0 THEN NULLIF(?, '') ELSE classification_name END,
                         item_description = ?
                     WHERE legacy_asset_id = ?"
                );
                if ($countStmt) {
                    $countStmt->bind_param('ssiisissi', $propertyNumber, $legacyItemTypeInput, $officeIdInput, $employeeIdInput, $employeeNameSnapshot, $classificationIdInput, $classificationNameSnapshot, $description, $id);
                    $countStmt->execute();
                    $countStmt->close();
                }

                $transferStmt = $db->prepare("UPDATE asset_transfers SET property_number = ? WHERE legacy_asset_id = ?");
                if ($transferStmt) {
                    $transferStmt->bind_param('si', $propertyNumber, $id);
                    $transferStmt->execute();
                    $transferStmt->close();
                }

                $batchTransferStmt = $db->prepare("UPDATE transfer_batch_items SET property_number = ? WHERE legacy_asset_id = ?");
                if ($batchTransferStmt) {
                    $batchTransferStmt->bind_param('si', $propertyNumber, $id);
                    $batchTransferStmt->execute();
                    $batchTransferStmt->close();
                }

                update_asset_location_snapshot(
                    $db,
                    'legacy',
                    $id,
                    $manualLocation,
                    $locationLat,
                    $locationLng,
                    (int) current_user_id(),
                    'asset_details_edit',
                    null,
                    null,
                    $locationIdInput
                );
                write_audit_log($db, [
                    'action' => 'update',
                    'table_name' => 'legacy_assets',
                    'record_id' => $id,
                    'module_name' => 'property',
                    'record_type' => 'legacy_asset',
                    'action_name' => 'edit_asset_details',
                    'description' => 'Updated legacy asset details from Asset Details page.',
                    'old_values' => [
                        'property_number' => $asset['property_number'] ?? null,
                        'item_type' => $asset['item_type'] ?? null,
                        'item_description' => $asset['item_description'] ?? null,
                        'brand' => $asset['brand'] ?? null,
                        'model' => $asset['model'] ?? null,
                        'serial_no' => $asset['serial_no'] ?? null,
                        'supplier_id' => $asset['supplier_id'] ?? null,
                        'office_id' => $asset['office_id'] ?? null,
                        'employee_id' => $asset['employee_id'] ?? null,
                        'responsibility_code_id' => $asset['responsibility_code_id'] ?? null,
                        'acquisition_date' => $asset['acquisition_date'] ?? null,
                        'account_code_id' => $asset['account_code_id'] ?? null,
                        'fund_id' => $asset['fund_id'] ?? null,
                        'quantity' => $asset['quantity'] ?? null,
                        'unit_of_measure_id' => $asset['unit_of_measure_id'] ?? null,
                        'unit_cost' => $asset['unit_cost'] ?? null,
                        'acquisition_cost' => $asset['acquisition_cost'] ?? null,
                        'condition_status' => $asset['condition_status'] ?? null,
                        'remarks' => $asset['remarks'] ?? null,
                        'location_id' => $asset['location_id'] ?? null,
                        'manual_location' => $asset['manual_location'] ?? null,
                    ],
                    'new_values' => [
                        'property_number' => $propertyNumber,
                        'item_type' => $legacyItemTypeInput,
                        'item_description' => $description,
                        'brand' => $brand,
                        'model' => $model,
                        'serial_no' => $serialNo,
                        'supplier_id' => $supplierIdInput > 0 ? $supplierIdInput : null,
                        'office_id' => $officeIdInput,
                        'employee_id' => $employeeIdInput > 0 ? $employeeIdInput : null,
                        'responsibility_code_id' => $responsibilityCodeIdInput > 0 ? $responsibilityCodeIdInput : null,
                        'acquisition_date' => $acquisitionDate,
                        'account_code_id' => $accountCodeIdInput > 0 ? $accountCodeIdInput : ($asset['account_code_id'] ?? null),
                        'fund_id' => $fundIdInput > 0 ? $fundIdInput : ($asset['fund_id'] ?? null),
                        'quantity' => $quantity,
                        'unit_of_measure_id' => $unitOfMeasureIdInput > 0 ? $unitOfMeasureIdInput : null,
                        'unit_cost' => $unitCost,
                        'acquisition_cost' => $acquisitionCost,
                        'condition_status' => $conditionStatus,
                        'remarks' => $remarksInput,
                        'location_id' => $locationIdInput,
                        'manual_location' => $manualLocation,
                    ],
                ]);
                set_flash('success', 'Asset details updated successfully.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
        }

        set_flash('error', 'Unable to update asset details right now.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    if ($action === 'delete_legacy_asset') {
        if ($source !== 'legacy' || !$canDeleteLegacy) {
            set_flash('error', 'You are not allowed to delete this asset.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        $deleteStmt = $db->prepare("UPDATE legacy_assets SET is_active = 0 WHERE id = ? LIMIT 1");
        if ($deleteStmt) {
            $deleteStmt->bind_param('i', $id);
            $deleted = $deleteStmt->execute();
            $deleteStmt->close();
            if ($deleted) {
                write_audit_log($db, [
                    'action' => 'delete',
                    'table_name' => 'legacy_assets',
                    'record_id' => $id,
                    'module_name' => 'property',
                    'record_type' => 'legacy_asset',
                    'action_name' => 'delete_legacy_asset',
                    'description' => 'Soft-deleted legacy asset from Asset Details page.',
                    'old_values' => $asset,
                    'new_values' => ['is_active' => 0],
                ]);
                set_flash('success', 'Legacy asset deleted.');
                redirect('modules/property/legacy_assets.php');
            }
        }

        set_flash('error', 'Unable to delete the legacy asset right now.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    if ($action === 'upload_photo') {
        if (!$canManagePhotos) {
            set_flash('error', 'You are not allowed to upload photos.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        $caption = old($_POST, 'caption');
        $uploadErrors = [];
        $storedPhoto = store_uploaded_image($_FILES['asset_photo'] ?? [], 'assets', $uploadErrors);
        if ($storedPhoto === null) {
            set_flash('error', $uploadErrors ? implode(' ', $uploadErrors) : 'Please choose an image to upload.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }

        $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM asset_photos WHERE asset_source = ? AND asset_id = ?");
        $existingCount = 0;
        if ($countStmt) {
            $countStmt->bind_param('si', $source, $id);
            $countStmt->execute();
            $existingCount = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
            $countStmt->close();
        }

        $insertStmt = $db->prepare("
            INSERT INTO asset_photos (asset_source, asset_id, photo_path, caption, is_primary, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($insertStmt) {
            $isPrimary = $existingCount === 0 ? 1 : 0;
            $userId = (int) (current_user_id() ?? 0);
            $insertStmt->bind_param('sissii', $source, $id, $storedPhoto, $caption, $isPrimary, $userId);
            $saved = $insertStmt->execute();
            $newPhotoId = (int) $insertStmt->insert_id;
            $insertStmt->close();
            if ($saved) {
                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'asset_photos',
                    'record_id' => $newPhotoId,
                    'module_name' => 'property',
                    'record_type' => 'asset_photo',
                    'action_name' => 'upload_asset_photo',
                    'description' => 'Uploaded an asset photo.',
                    'new_values' => [
                        'asset_source' => $source,
                        'asset_id' => $id,
                        'caption' => $caption,
                        'is_primary' => $isPrimary,
                    ],
                ]);
                set_flash('success', 'Asset photo uploaded successfully.');
                redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
            }
        }

        delete_uploaded_file($storedPhoto);
        set_flash('error', 'Unable to upload asset photo right now.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    if ($action === 'set_primary_photo') {
        if (!$canManagePhotos) {
            set_flash('error', 'You are not allowed to update photos.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $db->query("START TRANSACTION");
        $resetStmt = $db->prepare("UPDATE asset_photos SET is_primary = 0 WHERE asset_source = ? AND asset_id = ?");
        $setStmt = $db->prepare("UPDATE asset_photos SET is_primary = 1 WHERE id = ? AND asset_source = ? AND asset_id = ?");
        $ok = false;
        if ($resetStmt && $setStmt) {
            $resetStmt->bind_param('si', $source, $id);
            $setStmt->bind_param('isi', $photoId, $source, $id);
            $ok = $resetStmt->execute() && $setStmt->execute();
            $resetStmt->close();
            $setStmt->close();
        }
        if ($ok) {
            $db->commit();
            set_flash('success', 'Primary asset photo updated.');
        } else {
            $db->rollback();
            set_flash('error', 'Unable to update the primary photo right now.');
        }
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }

    if ($action === 'delete_photo') {
        if (!$canManagePhotos) {
            set_flash('error', 'You are not allowed to delete photos.');
            redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
        }
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $photoStmt = $db->prepare("
            SELECT id, photo_path, is_primary
            FROM asset_photos
            WHERE id = ? AND asset_source = ? AND asset_id = ?
            LIMIT 1
        ");
        $photoRow = null;
        if ($photoStmt) {
            $photoStmt->bind_param('isi', $photoId, $source, $id);
            $photoStmt->execute();
            $photoRow = $photoStmt->get_result()->fetch_assoc();
            $photoStmt->close();
        }

        if ($photoRow) {
            $deleteStmt = $db->prepare("DELETE FROM asset_photos WHERE id = ? LIMIT 1");
            if ($deleteStmt) {
                $deleteStmt->bind_param('i', $photoId);
                $saved = $deleteStmt->execute();
                $deleteStmt->close();
                if ($saved) {
                    delete_uploaded_file((string) $photoRow['photo_path']);
                    if ((int) ($photoRow['is_primary'] ?? 0) === 1) {
                        $nextStmt = $db->prepare("
                            UPDATE asset_photos
                            SET is_primary = 1
                            WHERE asset_source = ? AND asset_id = ?
                            ORDER BY created_at DESC, id DESC
                            LIMIT 1
                        ");
                        if ($nextStmt) {
                            $nextStmt->bind_param('si', $source, $id);
                            $nextStmt->execute();
                            $nextStmt->close();
                        }
                    }
                    set_flash('success', 'Asset photo deleted.');
                    redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
                }
            }
        }

        set_flash('error', 'Unable to delete that asset photo.');
        redirect('modules/property/view.php?source=' . urlencode($source) . '&id=' . $id);
    }
}

$photoStmt = $db->prepare("
    SELECT id, photo_path, caption, is_primary, created_at
    FROM asset_photos
    WHERE asset_source = ? AND asset_id = ?
    ORDER BY is_primary DESC, created_at DESC, id DESC
");
if ($photoStmt) {
    $photoStmt->bind_param('si', $source, $id);
    $photoStmt->execute();
    $assetPhotos = $photoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $photoStmt->close();
}

if ($source === 'system') {
    $timeline[] = [
        'date' => $asset['received_date'] ?? '',
        'event' => 'Received',
        'reference' => trim(implode(' / ', array_filter([
            $asset['receiving_reference'] ?? '',
            $asset['ris_no'] ?? '',
        ]))),
        'details' => trim(implode(' | ', array_filter([
            !empty($asset['supplier_name']) ? 'Supplier: ' . $asset['supplier_name'] : '',
            !empty($asset['po_number']) ? 'PO: ' . $asset['po_number'] : '',
            isset($asset['unit_cost']) ? 'Unit cost: ' . number_format((float) $asset['unit_cost'], 2) : '',
        ]))),
    ];

    $timeline[] = [
        'date' => $asset['distribution_date'] ?? '',
        'event' => strtoupper((string) ($asset['document_type'] ?? '')) . ' posted',
        'reference' => trim((string) ($asset['document_no'] ?? $asset['distribution_reference'] ?? '')),
        'details' => trim(implode(' | ', array_filter([
            !empty($asset['office_name']) ? 'Office: ' . $asset['office_name'] : '',
            ($person = asset_view_person($asset)) !== '' ? 'Accountable: ' . $person : '',
            !empty($asset['rc_code']) ? 'RC: ' . $asset['rc_code'] : '',
        ]))),
    ];

    if (schema_has_column($db, 'asset_transfers', 'distribution_item_detail_id')) {
        $stmt = $db->prepare("
            SELECT
                at.system_reference,
                at.transfer_date,
                at.reason,
                at.remarks,
                from_o.office_name AS from_office_name,
                to_o.office_name AS to_office_name,
                from_e.first_name AS from_first_name,
                from_e.middle_name AS from_middle_name,
                from_e.last_name AS from_last_name,
                from_e.suffix_name AS from_suffix_name,
                to_e.first_name AS to_first_name,
                to_e.middle_name AS to_middle_name,
                to_e.last_name AS to_last_name,
                to_e.suffix_name AS to_suffix_name,
                from_rc.code AS from_rc_code,
                to_rc.code AS to_rc_code
            FROM asset_transfers at
            LEFT JOIN offices from_o ON from_o.id = at.from_office_id
            LEFT JOIN offices to_o ON to_o.id = at.to_office_id
            LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
            LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
            LEFT JOIN responsibility_codes from_rc ON from_rc.id = at.from_responsibility_code_id
            LEFT JOIN responsibility_codes to_rc ON to_rc.id = at.to_responsibility_code_id
            WHERE at.distribution_item_detail_id = ?
              AND at.status = 'posted'
            ORDER BY at.transfer_date DESC, at.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    if (schema_has_column($db, 'maintenance_logs', 'distribution_item_detail_id')) {
        $stmt = $db->prepare("
            SELECT system_reference, maintenance_date, work_description, performed_by, cost, remarks
            FROM maintenance_logs
            WHERE distribution_item_detail_id = ?
              AND status = 'posted'
            ORDER BY maintenance_date DESC, id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $maintenanceRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    if (schema_has_column($db, 'returns', 'distribution_item_detail_id')) {
        $stmt = $db->prepare("
            SELECT
                rt.id AS return_id,
                rt.system_reference,
                rt.return_date,
                rt.reason,
                rt.remarks,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name
            FROM returns rt
            LEFT JOIN offices o ON o.id = rt.office_id
            LEFT JOIN employees e ON e.id = rt.employee_id
            WHERE rt.distribution_item_detail_id = ?
              AND rt.status = 'posted'
            ORDER BY rt.return_date DESC, rt.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $returnRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } else {
        $notices[] = 'Return history is limited until the newer return linkage column is present in the database.';
    }

    if (schema_has_column($db, 'disposals', 'distribution_item_detail_id')) {
        $stmt = $db->prepare("
            SELECT
                dp.system_reference,
                dp.disposal_date,
                dp.disposal_type,
                dp.reason,
                dp.remarks,
                dp.approved_by
            FROM disposals dp
            WHERE dp.distribution_item_detail_id = ?
            ORDER BY dp.disposal_date DESC, dp.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $disposalRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } else {
        $notices[] = 'Disposal history is limited until the newer disposal linkage column is present in the database.';
    }
} else {
    $timeline[] = [
        'date' => $asset['acquisition_date'] ?? '',
        'event' => 'Beginning balance entry',
        'reference' => trim((string) ($asset['system_reference'] ?? '')),
        'details' => trim(implode(' | ', array_filter([
            !empty($asset['supplier_name']) ? 'Supplier: ' . $asset['supplier_name'] : '',
            isset($asset['quantity']) ? 'Qty: ' . number_format((float) $asset['quantity'], 0) : '',
            isset($asset['unit_cost']) ? 'Unit cost: ' . number_format((float) $asset['unit_cost'], 2) : '',
        ]))),
    ];

    if (schema_has_column($db, 'asset_transfers', 'legacy_asset_id')) {
        $stmt = $db->prepare("
            SELECT
                at.system_reference,
                at.transfer_date,
                at.reason,
                at.remarks,
                from_o.office_name AS from_office_name,
                to_o.office_name AS to_office_name,
                from_e.first_name AS from_first_name,
                from_e.middle_name AS from_middle_name,
                from_e.last_name AS from_last_name,
                from_e.suffix_name AS from_suffix_name,
                to_e.first_name AS to_first_name,
                to_e.middle_name AS to_middle_name,
                to_e.last_name AS to_last_name,
                to_e.suffix_name AS to_suffix_name,
                from_rc.code AS from_rc_code,
                to_rc.code AS to_rc_code
            FROM asset_transfers at
            LEFT JOIN offices from_o ON from_o.id = at.from_office_id
            LEFT JOIN offices to_o ON to_o.id = at.to_office_id
            LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
            LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
            LEFT JOIN responsibility_codes from_rc ON from_rc.id = at.from_responsibility_code_id
            LEFT JOIN responsibility_codes to_rc ON to_rc.id = at.to_responsibility_code_id
            WHERE at.legacy_asset_id = ?
              AND at.status = 'posted'
            ORDER BY at.transfer_date DESC, at.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    if (schema_has_column($db, 'returns', 'legacy_asset_id') && schema_has_column($db, 'returns', 'source_type')) {
        $stmt = $db->prepare("
            SELECT
                rt.id AS return_id,
                rt.system_reference,
                rt.return_date,
                rt.reason,
                rt.remarks,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name
            FROM returns rt
            LEFT JOIN offices o ON o.id = rt.office_id
            LEFT JOIN employees e ON e.id = rt.employee_id
            WHERE rt.source_type = 'legacy'
              AND rt.legacy_asset_id = ?
              AND rt.status = 'posted'
            ORDER BY rt.return_date DESC, rt.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $returnRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    if (schema_has_column($db, 'disposals', 'legacy_asset_id') && schema_has_column($db, 'disposals', 'source_type')) {
        $stmt = $db->prepare("
            SELECT
                dp.system_reference,
                dp.disposal_date,
                dp.disposal_type,
                dp.reason,
                dp.remarks,
                ap.first_name,
                ap.middle_name,
                ap.last_name,
                ap.suffix_name
            FROM disposals dp
            LEFT JOIN employees ap ON ap.id = dp.approved_by
            WHERE dp.source_type = 'legacy'
              AND dp.legacy_asset_id = ?
              AND dp.status = 'posted'
            ORDER BY dp.disposal_date DESC, dp.id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $disposalRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}

foreach ($transfers as $row) {
    $timeline[] = [
        'date' => $row['transfer_date'] ?? '',
        'event' => 'Transferred',
        'reference' => (string) ($row['system_reference'] ?? ''),
        'details' => trim(implode(' | ', array_filter([
            !empty($row['from_office_name']) ? 'From: ' . $row['from_office_name'] : '',
            ($fromPerson = asset_view_person($row, 'from_')) !== '' ? $fromPerson : '',
            !empty($row['from_rc_code']) ? $row['from_rc_code'] : '',
            !empty($row['to_office_name']) ? 'To: ' . $row['to_office_name'] : '',
            ($toPerson = asset_view_person($row, 'to_')) !== '' ? $toPerson : '',
            !empty($row['to_rc_code']) ? $row['to_rc_code'] : '',
            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
        ]))),
    ];
}

foreach ($maintenanceRows as $row) {
    $timeline[] = [
        'date' => $row['maintenance_date'] ?? '',
        'event' => 'Maintenance',
        'reference' => (string) ($row['system_reference'] ?? ''),
        'details' => trim(implode(' | ', array_filter([
            $row['work_description'] ?? '',
            !empty($row['performed_by']) ? 'By: ' . $row['performed_by'] : '',
            isset($row['cost']) ? 'Cost: ' . number_format((float) $row['cost'], 2) : '',
        ]))),
    ];
}

foreach ($returnRows as $row) {
    $timeline[] = [
        'date' => $row['return_date'] ?? '',
        'event' => 'Returned',
        'reference' => (string) ($row['system_reference'] ?? ''),
        'details' => trim(implode(' | ', array_filter([
            !empty($row['office_name']) ? 'Office: ' . $row['office_name'] : '',
            ($returnPerson = asset_view_person($row)) !== '' ? 'Accountable: ' . $returnPerson : '',
            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
        ]))),
    ];
}

foreach ($disposalRows as $row) {
    $approvedByLabel = asset_view_person($row);
    $timeline[] = [
        'date' => $row['disposal_date'] ?? '',
        'event' => 'Disposed',
        'reference' => (string) ($row['system_reference'] ?? ''),
        'details' => trim(implode(' | ', array_filter([
            !empty($row['disposal_type']) ? 'Type: ' . $row['disposal_type'] : '',
            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
            $approvedByLabel !== '' ? 'Approved by: ' . $approvedByLabel : (!empty($row['approved_by']) ? 'Approved by: ' . $row['approved_by'] : ''),
        ]))),
    ];
}

asset_view_sort_timeline($timeline);

$classificationLabel = asset_view_classification($asset);
$accountCodeLabel = trim(implode(' - ', array_filter([
    $asset['account_code'] ?? '',
    $asset['account_name'] ?? '',
])));
$brandModel = trim(implode(' / ', array_filter([
    trim((string) ($asset['brand'] ?? '')),
    trim((string) ($asset['model'] ?? '')),
])));
$accountableName = asset_view_person($asset);
$detailTitle = asset_view_type_label((string) ($asset['item_type'] ?? '')) . ' Details';
$publicLookupUrl = base_url('modules/property/scan.php?ref=' . urlencode((string) ($asset['property_number'] ?? '')));
$historyCount = count($transfers) + count($maintenanceRows) + count($returnRows) + count($disposalRows);
$canEditSourcePo = asset_view_can_edit_source_po();
$latestInventoryCheck = asset_view_latest_inventory_check($db, (string) ($asset['property_number'] ?? ''));

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                            <h4 class="mb-0"><?php echo h($detailTitle); ?></h4>
                            <?php if (($asset['item_type'] ?? '') === 'semi_expendable'): ?>
                                <span class="badge text-bg-info">Semi-Expendable</span>
                            <?php else: ?>
                                <span class="badge text-bg-primary">Equipment</span>
                            <?php endif; ?>
                            <?php if ($source === 'legacy'): ?>
                                <span class="badge text-bg-secondary">Beginning Balance</span>
                            <?php else: ?>
                                <span class="badge text-bg-success">System Transaction</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">Complete asset profile, accountability assignment, and lifecycle history.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php $assetKey = $source . ':' . (int) $id; ?>
                        <a href="<?php echo base_url('modules/property/index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back to Registry</a>
                        <div class="dropdown">
                            <button class="btn btn-dark btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                Actions
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($source === 'system'): ?>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/returns/index.php?detail_id=' . (int) $asset['id']); ?>">Return</a></li>
                                <?php else: ?>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/returns/index.php?source=legacy&legacy_asset_id=' . (int) $asset['id']); ?>">Return</a></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" href="<?php echo base_url('modules/transfers/index.php?mode=direct&asset_key=' . urlencode($assetKey)); ?>">Transfer Accountability</a></li>
                                <li><hr class="dropdown-divider"></li>

                                <?php if ($canEditDetails): ?>
                                    <li><button class="dropdown-item" type="button" data-bs-toggle="collapse" data-bs-target="#assetEditPanel" aria-expanded="false" aria-controls="assetEditPanel">Edit Asset Details</button></li>
                                <?php endif; ?>

                                <?php if ($source === 'system'): ?>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/maintenance/index.php?detail_id=' . (int) $asset['id']); ?>">Maintenance</a></li>
                                    <?php if (!empty($asset['purchase_order_id'])): ?>
                                        <li><a class="dropdown-item" href="<?php echo base_url('modules/purchase_orders/view.php?id=' . (int) $asset['purchase_order_id']); ?>">View Source PO</a></li>
                                        <?php if ($canEditSourcePo): ?>
                                            <li><a class="dropdown-item" href="<?php echo base_url('modules/purchase_orders/edit.php?id=' . (int) $asset['purchase_order_id']); ?>">Edit Source PO</a></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <li><a class="dropdown-item" href="<?php echo $publicLookupUrl; ?>" target="_blank">Public Lookup</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <?php if ((int) ($asset['is_disposed'] ?? 0) === 0): ?>
                                        <li><a class="dropdown-item text-danger" href="<?php echo base_url('modules/disposals/index.php?detail_id=' . (int) $asset['id']); ?>">Disposal</a></li>
                                    <?php else: ?>
                                        <li><span class="dropdown-item-text text-muted small">Disposal already posted for this asset.</span></li>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <li><span class="dropdown-item-text text-muted small">Maintenance: available for system assets only.</span></li>
                                    <li><a class="dropdown-item" href="<?php echo $publicLookupUrl; ?>" target="_blank">Public Lookup</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger" href="<?php echo base_url('modules/disposals/index.php?source=legacy&legacy_asset_id=' . (int) $asset['id']); ?>">Disposal</a></li>
                                    <?php if ($canDeleteLegacy): ?>
                                        <li>
                                            <form method="post" onsubmit="return confirm('Delete this legacy asset? This will hide it from the active legacy list.');">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete_legacy_asset">
                                                <button type="submit" class="dropdown-item text-danger">Delete Legacy Asset</button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <li><hr class="dropdown-divider"></li>
                                <?php if ($source === 'system' && !empty($asset['distribution_id'])): ?>
                                    <?php
                                    $docType = (string) ($asset['document_type'] ?? '');
                                    $docUrl = $docType === 'par'
                                        ? base_url('modules/distributions/par.php?id=' . (int) $asset['distribution_id'] . '&detail_id=' . (int) $asset['id'] . '&view_mode=detailed')
                                        : base_url('modules/distributions/ics.php?id=' . (int) $asset['distribution_id'] . '&detail_id=' . (int) $asset['id'] . '&view_mode=detailed');
                                    ?>
                                    <li><a class="dropdown-item" href="<?php echo $docUrl; ?>" target="_blank">Print <?php echo h(strtoupper($docType)); ?></a></li>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/property/tags.php?detail_id=' . (int) $asset['id']); ?>" target="_blank">Print Tag</a></li>
                                <?php elseif ($source === 'legacy'): ?>
                                    <?php if (($asset['item_type'] ?? '') === 'semi_expendable'): ?>
                                        <li><a class="dropdown-item" href="<?php echo base_url('modules/distributions/ics_office.php?legacy_asset_id=' . (int) $asset['id'] . '&semi_type=all&view_mode=detailed'); ?>" target="_blank">Print ICS</a></li>
                                        <li><span class="dropdown-item-text text-muted small">PAR is for equipment assets only.</span></li>
                                    <?php else: ?>
                                        <li><a class="dropdown-item" href="<?php echo base_url('modules/distributions/par_office.php?legacy_asset_id=' . (int) $asset['id'] . '&print_format=long&view_mode=detailed'); ?>" target="_blank">Print PAR</a></li>
                                        <li><span class="dropdown-item-text text-muted small">ICS is for semi-expendable assets only.</span></li>
                                    <?php endif; ?>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/property/tags.php?legacy_asset_id=' . (int) $asset['id']); ?>" target="_blank">Print Tag</a></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <?php if ($notices): ?>
                    <div class="alert alert-warning">
                        <?php foreach ($notices as $notice): ?>
                            <div><?php echo h($notice); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
                        <?php echo h($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($canEditDetails): ?>
                    <div class="collapse mb-4" id="assetEditPanel">
                        <div class="card border border-warning-subtle">
                            <div class="card-body">
                                <h6 class="mb-3">Edit Asset Details</h6>
                                <?php if ($source === 'system'): ?>
                                    <div class="alert alert-light border small">
                                        Update the core asset identity fields here for registry corrections. Other acquisition and source transaction details stay tied to the original PO and receiving records.
                                    </div>
                                <?php elseif (stripos((string) ($asset['property_number'] ?? ''), 'TEMP-') === 0): ?>
                                    <div class="alert alert-light border small">
                                        This asset has a temporary property number. Once acquisition date, fund, and account code are complete, saving this form will generate the official property number.
                                    </div>
                                <?php endif; ?>
                                <form method="post" id="assetEditForm" class="row g-3">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="save_asset_details">

                                    <div class="col-12">
                                        <div id="asset_edit_form_feedback" class="alert alert-danger small py-2 px-3 mb-0 d-none" role="alert" aria-live="polite"></div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Property Number <span class="text-danger">*</span></label>
                                        <input type="text" id="asset_property_number" name="property_number" class="form-control" value="<?php echo h((string) ($asset['property_number'] ?? '')); ?>" required>
                                        <div id="asset_property_number_feedback" class="small text-danger mt-1 d-none">Property number is required.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Brand</label>
                                        <div class="input-group">
                                            <input type="text" id="asset_brand" name="brand" class="form-control" list="assetBrandOptions" value="<?php echo h((string) ($asset['brand'] ?? '')); ?>" placeholder="Type or select brand">
                                            <button type="button" class="btn btn-outline-success" id="assetAddBrandBtn"><i class="bi bi-plus-circle"></i> Brand</button>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Model</label>
                                        <div class="input-group">
                                            <input type="text" id="asset_model" name="model" class="form-control" list="assetModelOptions" value="<?php echo h((string) ($asset['model'] ?? '')); ?>" placeholder="Type or select model">
                                            <button type="button" class="btn btn-outline-success" id="assetAddModelBtn"><i class="bi bi-plus-circle"></i> Model</button>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Serial No.</label>
                                        <input type="text" name="serial_no" class="form-control" value="<?php echo h((string) ($asset['serial_no'] ?? '')); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Supplier</label>
                                        <?php $selectedSupplierId = (int) ($asset['supplier_id'] ?? 0); ?>
                                        <select name="supplier_id" class="form-select">
                                            <option value="0">Unassigned</option>
                                            <?php foreach ($supplierOptions as $supplierOption): ?>
                                                <?php $supplierOptionId = (int) ($supplierOption['id'] ?? 0); ?>
                                                <?php $supplierLabel = trim(($supplierOption['supplier_name'] ?? '') . (!empty($supplierOption['supplier_code']) ? ' (' . $supplierOption['supplier_code'] . ')' : '')); ?>
                                                <?php if ((int) ($supplierOption['is_active'] ?? 1) !== 1): ?>
                                                    <?php $supplierLabel .= ' (Inactive)'; ?>
                                                <?php endif; ?>
                                                <option value="<?php echo $supplierOptionId; ?>" <?php echo $supplierOptionId === $selectedSupplierId ? 'selected' : ''; ?>>
                                                    <?php echo h($supplierLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Office <span class="text-danger">*</span></label>
                                        <select name="office_id" id="asset_office_id" class="form-select" required>
                                            <option value="">Select office</option>
                                            <?php foreach ($officeOptions as $officeOption): ?>
                                                <?php $officeOptionId = (int) ($officeOption['id'] ?? 0); ?>
                                                <?php $officeLabel = trim(($officeOption['office_code'] ?? '') . ' - ' . ($officeOption['office_name'] ?? '')); ?>
                                                <?php if ((int) ($officeOption['is_active'] ?? 1) !== 1): ?>
                                                    <?php $officeLabel .= ' (Inactive)'; ?>
                                                <?php endif; ?>
                                                <option value="<?php echo $officeOptionId; ?>" <?php echo $officeOptionId === $assetOfficeId ? 'selected' : ''; ?>>
                                                    <?php echo h($officeLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Accountable Employee</label>
                                        <?php $selectedEmployeeId = $source === 'system' ? (int) (($asset['current_employee_id'] ?? 0) ?: ($asset['employee_id'] ?? 0)) : (int) ($asset['employee_id'] ?? 0); ?>
                                        <select name="employee_id" id="asset_employee_id" class="form-select">
                                            <option value="0" data-office-id="0">Unassigned</option>
                                            <?php foreach ($employeeOptions as $employeeOption): ?>
                                                <?php $employeeOptionId = (int) ($employeeOption['id'] ?? 0); ?>
                                                <?php $employeeOfficeId = (int) ($employeeOption['office_id'] ?? 0); ?>
                                                <?php $employeeLabel = employee_choice_label($employeeOption); ?>
                                                <option value="<?php echo $employeeOptionId; ?>" data-office-id="<?php echo $employeeOfficeId; ?>" data-responsibility-code-id="<?php echo (int) ($employeeOption['responsibility_code_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employeeOption['is_unit_head'] ?? 0); ?>" <?php echo $employeeOptionId === $selectedEmployeeId ? 'selected' : ''; ?>>
                                                    <?php echo h($employeeLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Responsibility Code</label>
                                        <?php $selectedRcId = $source === 'system' ? (int) ($asset['current_responsibility_code_id'] ?? 0) : (int) ($asset['responsibility_code_id'] ?? 0); ?>
                                        <select name="responsibility_code_id" id="asset_responsibility_code_id" class="form-select">
                                            <option value="0" data-office-id="0">Unassigned</option>
                                            <?php foreach ($responsibilityCodeOptions as $rcOption): ?>
                                                <?php $rcOptionId = (int) ($rcOption['id'] ?? 0); ?>
                                                <?php $rcOfficeId = (int) ($rcOption['office_id'] ?? 0); ?>
                                                <?php $rcLabel = trim(($rcOption['code'] ?? '') . (!empty($rcOption['description']) ? ' - ' . $rcOption['description'] : '')); ?>
                                                <option value="<?php echo $rcOptionId; ?>" data-office-id="<?php echo $rcOfficeId; ?>" <?php echo $rcOptionId === $selectedRcId ? 'selected' : ''; ?>>
                                                    <?php echo h($rcLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Location</label>
                                        <select name="location_id" class="form-select" data-placeholder="Select location">
                                            <option value="0">Unassigned</option>
                                            <?php foreach ($locationOptions as $option): ?>
                                                <?php $optionId = (int) ($option['id'] ?? 0); ?>
                                                <?php $label = trim(($option['location_code'] ?? '') . ' - ' . ($option['location_name'] ?? '')); ?>
                                                <option value="<?php echo h((string) $optionId); ?>" <?php echo $optionId > 0 && $optionId === $resolvedLocationId ? 'selected' : ''; ?>>
                                                    <?php echo h($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!$locationOptions): ?>
                                            <div class="form-text">Add locations first in System Setup > Locations.</div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($source === 'legacy'): ?>
                                        <div class="col-md-6">
                                            <label class="form-label">Inventory Type</label>
                                            <?php $legacyItemTypeValue = (string) ($asset['item_type'] ?? 'equipment'); ?>
                                            <select name="item_type" id="asset_item_type" class="form-select">
                                                <option value="equipment" <?php echo $legacyItemTypeValue === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                                <option value="semi_expendable" <?php echo $legacyItemTypeValue === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Item Classification</label>
                                            <div class="d-flex gap-2 align-items-start">
                                                <div class="flex-grow-1" style="min-width: 0;">
                                                    <select name="classification_id" id="asset_classification_id" class="form-select">
                                                <option value="0">Keep current classification</option>
                                                <?php foreach ($classificationOptions as $option): ?>
                                                    <?php $optionId = (int) ($option['id'] ?? 0); ?>
                                                    <?php $isSelected = $optionId > 0 && $optionId === (int) ($asset['classification_id'] ?? 0); ?>
                                                    <?php $classificationOptionLabel = trim(($option['classification_family'] ?? '') . (($option['classification_family'] ?? '') !== '' ? ' / ' : '') . ($option['classification_name'] ?? '')); ?>
                                                    <option value="<?php echo h((string) $optionId); ?>" data-account-code-id="<?php echo (int) ($option['account_code_id'] ?? 0); ?>" data-classification-group="<?php echo h((string) ($option['classification_group'] ?? '')); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                        <?php echo h($classificationOptionLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <button type="button" class="btn btn-outline-success flex-shrink-0" id="assetAddClassificationBtn"><i class="bi bi-plus-circle"></i> Class</button>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Account Code</label>
                                            <select name="account_code_id" id="asset_account_code_id" class="form-select">
                                                <option value="0">Keep current account code</option>
                                                <?php foreach ($accountCodeOptions as $option): ?>
                                                    <?php $optionId = (int) ($option['id'] ?? 0); ?>
                                                    <?php $isSelected = $optionId > 0 && $optionId === (int) ($asset['account_code_id'] ?? 0); ?>
                                                    <option value="<?php echo h((string) $optionId); ?>" data-account-group="<?php echo h((string) ($option['account_group'] ?? '')); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                        <?php echo h(trim(($option['account_code'] ?? '') . ' - ' . ($option['account_name'] ?? ''))); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Fund</label>
                                            <select name="fund_id" class="form-select">
                                                <option value="0">Keep current fund</option>
                                                <?php foreach ($fundOptions as $option): ?>
                                                    <?php $optionId = (int) ($option['id'] ?? 0); ?>
                                                    <?php $isSelected = $optionId > 0 && $optionId === (int) ($asset['fund_id'] ?? 0); ?>
                                                    <?php $fundLabel = trim(($option['fund_code'] ?? '') . ' - ' . ($option['fund_name'] ?? '')); ?>
                                                    <?php if (!empty($option['fund_source'])) { $fundLabel .= ' (' . $option['fund_source'] . ')'; } ?>
                                                    <option value="<?php echo h((string) $optionId); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                        <?php echo h($fundLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Description</label>
                                            <textarea name="item_description" class="form-control" rows="3"><?php echo h((string) ($asset['item_description'] ?? '')); ?></textarea>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Acquisition Date</label>
                                            <input type="date" name="acquisition_date" class="form-control" value="<?php echo h(normalize_date_string((string) ($asset['acquisition_date'] ?? ''))); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Quantity</label>
                                            <input type="number" id="asset_quantity" name="quantity" min="1" step="1" class="form-control" value="<?php echo h((string) ((int) ($asset['quantity'] ?? 1))); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Unit Type</label>
                                            <?php $selectedUnitOfMeasureId = (int) ($asset['unit_of_measure_id'] ?? 0); ?>
                                            <select name="unit_of_measure_id" class="form-select">
                                                <option value="0">Unassigned</option>
                                                <?php foreach ($unitOfMeasureOptions as $option): ?>
                                                    <?php
                                                    $unitOptionId = (int) ($option['id'] ?? 0);
                                                    $unitLabel = trim(($option['uom_name'] ?? '') . (($option['abbreviation'] ?? '') !== '' ? ' (' . $option['abbreviation'] . ')' : ''));
                                                    if ((int) ($option['is_active'] ?? 1) !== 1) {
                                                        $unitLabel .= ' (Inactive)';
                                                    }
                                                    ?>
                                                    <option value="<?php echo h((string) $unitOptionId); ?>" <?php echo $unitOptionId === $selectedUnitOfMeasureId ? 'selected' : ''; ?>>
                                                        <?php echo h($unitLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Unit Cost</label>
                                            <input type="number" id="asset_unit_cost" name="unit_cost" min="0" step="0.01" class="form-control" value="<?php echo h((string) number_format((float) ($asset['unit_cost'] ?? 0), 2, '.', '')); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Amount</label>
                                            <input type="number" id="asset_acquisition_cost" name="acquisition_cost" min="0" step="0.01" class="form-control" value="<?php echo h((string) number_format((float) ($asset['acquisition_cost'] ?? 0), 2, '.', '')); ?>" readonly>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Condition</label>
                                            <?php $conditionValue = trim((string) ($asset['condition_status'] ?? 'serviceable')); ?>
                                            <select name="condition_status" class="form-select">
                                                <option value="serviceable" <?php echo $conditionValue === 'serviceable' ? 'selected' : ''; ?>>Serviceable</option>
                                                <option value="unserviceable" <?php echo $conditionValue === 'unserviceable' ? 'selected' : ''; ?>>Unserviceable</option>
                                                <option value="disposed" <?php echo $conditionValue === 'disposed' ? 'selected' : ''; ?>>Disposed</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Remarks</label>
                                            <textarea name="remarks" class="form-control" rows="2"><?php echo h((string) ($asset['remarks'] ?? '')); ?></textarea>
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-12 d-flex justify-content-end">
                                        <button type="submit" class="btn btn-warning">Save Changes</button>
                                    </div>
                                </form>

                                <datalist id="assetBrandOptions">
                                    <?php foreach ($brandOptions as $option): ?>
                                        <option value="<?php echo h($option); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <datalist id="assetModelOptions">
                                    <?php foreach ($modelOptions as $option): ?>
                                        <option value="<?php echo h($option); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Property Number</div>
                            <div class="fw-semibold"><?php echo h($asset['property_number'] ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Current Office</div>
                            <div class="fw-semibold"><?php echo h($asset['office_name'] ?? 'Unassigned'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Location</div>
                            <div class="fw-semibold"><?php echo h($resolvedManualLocation !== '' ? $resolvedManualLocation : 'Unassigned'); ?></div>
                            <?php if (!empty($asset['location_code'])): ?>
                                <div class="small text-muted"><?php echo h((string) $asset['location_code']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($asset['location_description'])): ?>
                                <div class="small text-muted mt-1"><?php echo h((string) $asset['location_description']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Accountable Person</div>
                            <div class="fw-semibold"><?php echo h($accountableName !== '' ? $accountableName : 'Unassigned'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Last Checked</div>
                            <?php if ($latestInventoryCheck): ?>
                                <div class="fw-semibold"><?php echo h(!empty($latestInventoryCheck['checked_at']) ? date('M d, Y g:i A', strtotime((string) $latestInventoryCheck['checked_at'])) : ''); ?></div>
                                <div class="small text-muted"><?php echo h(ucfirst((string) ($latestInventoryCheck['status'] ?? 'pending'))); ?><?php echo !empty($latestInventoryCheck['session_reference']) ? ' | ' . h((string) $latestInventoryCheck['session_reference']) : ''; ?></div>
                            <?php else: ?>
                                <div class="fw-semibold">Not checked yet</div>
                                <div class="small text-muted">No completed inventory check recorded.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h6 class="mb-0">Asset Photos</h6>
                                <span class="badge text-bg-light"><?php echo count($assetPhotos); ?></span>
                            </div>
                            <div class="card-body">
                                <div class="row g-4">
                                    <div class="col-lg-7">
                                        <?php $primaryPhoto = $assetPhotos[0] ?? null; ?>
                                        <div class="asset-photo-main">
                                            <?php if ($primaryPhoto): ?>
                                                <img src="<?php echo h(upload_url($primaryPhoto['photo_path'])); ?>" alt="<?php echo h($detailTitle); ?>">
                                            <?php else: ?>
                                                <div class="asset-photo-empty">
                                                    <i class="bi bi-camera"></i>
                                                    <div>No asset photo uploaded yet.</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($assetPhotos): ?>
                                            <div class="asset-photo-grid mt-3">
                                                <?php foreach ($assetPhotos as $photo): ?>
                                                    <div class="asset-photo-thumb-card">
                                                        <img src="<?php echo h(upload_url($photo['photo_path'])); ?>" alt="<?php echo h($photo['caption'] ?: $detailTitle); ?>">
                                                        <div class="small mt-2 text-muted"><?php echo h($photo['caption'] ?: (($photo['is_primary'] ?? 0) ? 'Primary photo' : 'Asset photo')); ?></div>
                                                        <?php if ($canManagePhotos): ?>
                                                            <div class="d-flex gap-2 mt-2 flex-wrap">
                                                                <?php if ((int) ($photo['is_primary'] ?? 0) !== 1): ?>
                                                                    <form method="post">
                                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                                        <input type="hidden" name="action" value="set_primary_photo">
                                                                        <input type="hidden" name="photo_id" value="<?php echo (int) $photo['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Make Primary</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <span class="badge text-bg-primary">Primary</span>
                                                                <?php endif; ?>
                                                                <form method="post" onsubmit="return confirm('Delete this asset photo?');">
                                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                                    <input type="hidden" name="action" value="delete_photo">
                                                                    <input type="hidden" name="photo_id" value="<?php echo (int) $photo['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                                </form>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-lg-5">
                                        <?php if ($canManagePhotos): ?>
                                            <form method="post" enctype="multipart/form-data" class="border rounded-3 p-3 bg-light-subtle">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="upload_photo">
                                                <div class="fw-semibold mb-2">Upload New Photo</div>
                                                <div class="text-muted small mb-3">Add clear equipment or semi-expendable photos for verification, audit support, and physical inventory reference.</div>
                                                <div class="mb-3">
                                                    <label class="form-label">Asset Photo</label>
                                                    <input type="file" class="form-control" name="asset_photo" accept="image/*" capture="environment" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Caption</label>
                                                    <input type="text" class="form-control" name="caption" maxlength="255" placeholder="Front view, serial plate, room photo, etc.">
                                                </div>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-upload me-2"></i>Upload Photo
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <div class="border rounded-3 p-3 bg-light-subtle text-muted small">
                                                Only Administrator, Supply Officer, and Property Officer accounts can manage asset photos.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card h-100">
                            <div class="card-header bg-white">
                                <h6 class="mb-0">Asset Profile</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="small text-muted">Description</div>
                                        <div><?php echo h($asset['item_description'] ?? ''); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Classification</div>
                                        <div><?php echo h($classificationLabel); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Brand / Model</div>
                                        <div><?php echo h($brandModel); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Serial Number</div>
                                        <div><?php echo h($asset['serial_no'] ?? ''); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Account Code</div>
                                        <div><?php echo h($accountCodeLabel); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted">Source</div>
                                        <div><?php echo h(asset_view_source_label($source)); ?></div>
                                    </div>
                                    <?php if ($source === 'legacy'): ?>
                                        <div class="col-md-6">
                                            <div class="small text-muted">Supplier</div>
                                            <div><?php echo h($asset['supplier_name'] ?? ''); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small text-muted">Condition</div>
                                            <div><?php echo h($asset['condition_status'] ?? ''); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card h-100">
                            <div class="card-header bg-white">
                                <h6 class="mb-0">Acquisition & Accountability</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="small text-muted">Office / Employee / RC</div>
                                    <div class="fw-semibold"><?php echo h($asset['office_name'] ?? 'Unassigned'); ?></div>
                                    <div><?php echo h($accountableName !== '' ? $accountableName : 'Unassigned'); ?></div>
                                    <div class="text-muted small"><?php echo h($asset['position_title'] ?? ''); ?><?php echo !empty($asset['rc_code']) ? ' | ' . h($asset['rc_code']) : ''; ?></div>
                                </div>
                                <div class="mb-3">
                                    <div class="small text-muted">Location Details</div>
                                    <div class="fw-semibold"><?php echo h($resolvedManualLocation !== '' ? $resolvedManualLocation : 'Unassigned'); ?></div>
                                    <?php if (!empty($asset['location_code'])): ?>
                                        <div class="text-muted small">Code: <?php echo h((string) $asset['location_code']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($asset['location_description'])): ?>
                                        <div class="text-muted small mt-1"><?php echo h((string) $asset['location_description']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($latestInventoryCheck): ?>
                                    <div class="mb-3">
                                        <div class="small text-muted">Latest Inventory Check</div>
                                        <div class="fw-semibold"><?php echo h(!empty($latestInventoryCheck['checked_at']) ? date('M d, Y g:i A', strtotime((string) $latestInventoryCheck['checked_at'])) : ''); ?></div>
                                        <div class="text-muted small"><?php echo h(ucfirst((string) ($latestInventoryCheck['status'] ?? 'pending'))); ?><?php echo !empty($latestInventoryCheck['office_name']) ? ' | ' . h((string) $latestInventoryCheck['office_name']) : ''; ?><?php echo !empty($latestInventoryCheck['session_reference']) ? ' | ' . h((string) $latestInventoryCheck['session_reference']) : ''; ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($source === 'system'): ?>
                                    <div class="mb-2"><span class="text-muted small d-block">Purchase Order</span><?php echo h($asset['po_number'] ?? ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Supplier</span><?php echo h($asset['supplier_name'] ?? ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Received</span><?php echo h(format_date($asset['received_date'] ?? null)); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Receiving Reference</span><?php echo h(trim(implode(' / ', array_filter([$asset['receiving_reference'] ?? '', $asset['ris_no'] ?? ''])))); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block"><?php echo h(strtoupper((string) ($asset['document_type'] ?? ''))); ?> Reference</span><?php echo h($asset['document_no'] ?? ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Unit Cost</span><?php echo h(number_format((float) ($asset['unit_cost'] ?? 0), 2)); ?></div>
                                <?php else: ?>
                                    <div class="mb-2"><span class="text-muted small d-block">Beginning Balance Reference</span><?php echo h($asset['system_reference'] ?? ''); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Acquisition Date</span><?php echo h(format_date($asset['acquisition_date'] ?? null)); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Quantity</span><?php echo h(number_format((float) ($asset['quantity'] ?? 0), 0)); ?></div>
                                    <?php $unitTypeLabel = trim(($asset['uom_name'] ?? '') . (($asset['unit_abbreviation'] ?? '') !== '' ? ' (' . $asset['unit_abbreviation'] . ')' : '')); ?>
                                    <div class="mb-2"><span class="text-muted small d-block">Unit Type</span><?php echo h($unitTypeLabel !== '' ? $unitTypeLabel : 'Unassigned'); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Unit Cost</span><?php echo h(number_format((float) ($asset['unit_cost'] ?? 0), 2)); ?></div>
                                    <div class="mb-2"><span class="text-muted small d-block">Total Cost</span><?php echo h(number_format((float) ($asset['acquisition_cost'] ?? 0), 2)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0">Lifecycle Timeline</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive mobile-table-frame">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 140px;">Date</th>
                                        <th style="width: 180px;">Event</th>
                                        <th style="width: 180px;">Reference</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($timeline): ?>
                                        <?php foreach ($timeline as $entry): ?>
                                            <tr>
                                                <td><?php echo h(format_date($entry['date'] ?? null)); ?></td>
                                                <td><?php echo h($entry['event'] ?? ''); ?></td>
                                                <td><?php echo h($entry['reference'] ?? ''); ?></td>
                                                <td><?php echo h($entry['details'] ?? ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No lifecycle history found yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mt-1">
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Transfers</h6>
                                <span class="badge text-bg-light"><?php echo count($transfers); ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive mobile-table-frame">
                                    <table class="table table-sm mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Reference</th>
                                                <th>Movement</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($transfers): ?>
                                                <?php foreach ($transfers as $row): ?>
                                                    <tr>
                                                        <td><?php echo h(!empty($row['transfer_date']) ? date('M d, Y', strtotime((string) $row['transfer_date'])) : ''); ?></td>
                                                        <td><?php echo h($row['system_reference'] ?? ''); ?></td>
                                                        <td><?php echo h(trim(implode(' | ', array_filter([
                                                            !empty($row['from_office_name']) ? 'From: ' . $row['from_office_name'] : '',
                                                            !empty($row['to_office_name']) ? 'To: ' . $row['to_office_name'] : '',
                                                            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
                                                        ])))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4">No transfer history.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Returns</h6>
                                <span class="badge text-bg-light"><?php echo count($returnRows); ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive mobile-table-frame">
                                    <table class="table table-sm mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Reference</th>
                                                <th>Details</th>
                                                <th>Form</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($returnRows): ?>
                                                <?php foreach ($returnRows as $row): ?>
                                                    <?php
                                                    $returnFormUrl = '';
                                                    $returnFormLabel = '';
                                                    if ($source === 'system') {
                                                        if (($asset['item_type'] ?? '') === 'semi_expendable') {
                                                            $returnFormUrl = base_url('modules/reports/semi_rrsp.php?return_id=' . (int) ($row['return_id'] ?? 0) . '&print=1');
                                                            $returnFormLabel = 'Returned Semi-Expendable Property';
                                                        } elseif (($asset['item_type'] ?? '') === 'equipment') {
                                                            $returnFormUrl = base_url('modules/reports/property_return_slip.php?return_id=' . (int) ($row['return_id'] ?? 0) . '&print=1');
                                                            $returnFormLabel = 'RRPE';
                                                        }
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td><?php echo h(!empty($row['return_date']) ? date('M d, Y', strtotime((string) $row['return_date'])) : ''); ?></td>
                                                        <td><?php echo h($row['system_reference'] ?? ''); ?></td>
                                                        <td><?php echo h(trim(implode(' | ', array_filter([
                                                            !empty($row['office_name']) ? $row['office_name'] : '',
                                                            ($person = asset_view_person($row)) !== '' ? $person : '',
                                                            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
                                                        ])))); ?></td>
                                                        <td>
                                                            <?php if ($returnFormUrl !== ''): ?>
                                                                <a class="btn btn-sm btn-outline-primary" href="<?php echo h($returnFormUrl); ?>" target="_blank"><?php echo h($returnFormLabel); ?></a>
                                                            <?php else: ?>
                                                                <span class="text-muted small">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="4" class="text-center text-muted py-4">No return history.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Disposals</h6>
                                <span class="badge text-bg-light"><?php echo count($disposalRows); ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive mobile-table-frame">
                                    <table class="table table-sm mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Reference</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($disposalRows): ?>
                                                <?php foreach ($disposalRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo h(!empty($row['disposal_date']) ? date('M d, Y', strtotime((string) $row['disposal_date'])) : ''); ?></td>
                                                        <td><?php echo h($row['system_reference'] ?? ''); ?></td>
                                                        <td><?php echo h(trim(implode(' | ', array_filter([
                                                            !empty($row['disposal_type']) ? $row['disposal_type'] : '',
                                                            !empty($row['reason']) ? 'Reason: ' . $row['reason'] : '',
                                                            !empty($row['approved_by']) ? 'Approved by: ' . $row['approved_by'] : '',
                                                        ])))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4">No disposal history.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Maintenance</h6>
                                <span class="badge text-bg-light"><?php echo count($maintenanceRows); ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive mobile-table-frame">
                                    <table class="table table-sm mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Reference</th>
                                                <th>Work Done</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($maintenanceRows): ?>
                                                <?php foreach ($maintenanceRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo h(!empty($row['maintenance_date']) ? date('M d, Y', strtotime((string) $row['maintenance_date'])) : ''); ?></td>
                                                        <td><?php echo h($row['system_reference'] ?? ''); ?></td>
                                                        <td><?php echo h(trim(implode(' | ', array_filter([
                                                            $row['work_description'] ?? '',
                                                            !empty($row['performed_by']) ? 'By: ' . $row['performed_by'] : '',
                                                            isset($row['cost']) ? 'Cost: ' . number_format((float) $row['cost'], 2) : '',
                                                        ])))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4">No maintenance history.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var quickAddEndpoint = <?php echo json_encode(base_url('modules/property/legacy_assets_quickadd.php')); ?>;
    var classificationQuickAddEndpoint = <?php echo json_encode(base_url('modules/purchase_orders/classification_quick_add.php')); ?>;
    var csrfToken = <?php echo json_encode(csrf_token()); ?>;
    var currentAssetAccountCodeId = <?php echo json_encode((string) ((int) ($asset['account_code_id'] ?? 0))); ?>;
    var brandInput = document.getElementById('asset_brand');
    var modelInput = document.getElementById('asset_model');
    var brandDatalist = document.getElementById('assetBrandOptions');
    var modelDatalist = document.getElementById('assetModelOptions');
    var brandsByName = {};
    <?php foreach ($brandQuickAddOptions as $brandOption): ?>
    brandsByName[<?php echo json_encode(strtolower((string) $brandOption['label'])); ?>] = <?php echo json_encode($brandOption, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    <?php endforeach; ?>
    var accountCodeOptions = <?php
        $accountCodeDataset = array_map(static function ($option) {
            return [
                'value' => (string) ($option['id'] ?? ''),
                'text' => trim((string) ($option['account_code'] ?? '') . ' - ' . (string) ($option['account_name'] ?? '')),
                'accountGroup' => (string) ($option['account_group'] ?? ''),
            ];
        }, $accountCodeOptions);
        echo json_encode($accountCodeDataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    var classificationOptions = <?php
        $classificationDataset = array_map(static function ($option) {
            return [
                'value' => (string) ($option['id'] ?? ''),
                'text' => trim((string) ($option['classification_family'] ?? '') . (($option['classification_family'] ?? '') !== '' ? ' / ' : '') . (string) ($option['classification_name'] ?? '')),
                'accountCodeId' => (string) ($option['account_code_id'] ?? ''),
                'classificationGroup' => (string) ($option['classification_group'] ?? ''),
            ];
        }, $classificationOptions);
        echo json_encode($classificationDataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;

    function appendDatalistOption(datalist, value) {
        if (!datalist || !value) {
            return;
        }
        var exists = Array.prototype.some.call(datalist.options, function (option) {
            return option.value.toLowerCase() === value.toLowerCase();
        });
        if (!exists) {
            var option = document.createElement('option');
            option.value = value;
            datalist.appendChild(option);
        }
    }

    function postQuickAdd(payload) {
        payload._csrf = csrfToken;
        return fetch(quickAddEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams(payload).toString()
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data.success) {
                throw new Error(data.error || 'Unable to save record.');
            }
            return data;
        });
    }

    function resolveBrandId(name) {
        var key = String(name || '').trim().toLowerCase();
        if (!key) {
            return Promise.reject(new Error('Enter a brand first.'));
        }
        if (brandsByName[key]) {
            if (brandInput) {
                brandInput.value = brandsByName[key].label;
            }
            return Promise.resolve(brandsByName[key].id);
        }
        return postQuickAdd({ action: 'add_brand', brand_name: name }).then(function (data) {
            brandsByName[String(data.label || name).trim().toLowerCase()] = { id: data.id, label: data.label || name };
            appendDatalistOption(brandDatalist, data.label || name);
            if (brandInput) {
                brandInput.value = data.label || name;
            }
            return data.id;
        });
    }

    var addBrandBtn = document.getElementById('assetAddBrandBtn');
    if (addBrandBtn && brandInput) {
        addBrandBtn.addEventListener('click', function () {
            var name = window.prompt('Brand name', brandInput.value.trim());
            if (!name || !name.trim()) {
                return;
            }
            resolveBrandId(name.trim()).catch(function (error) {
                window.alert(error.message);
            });
        });
    }

    var addModelBtn = document.getElementById('assetAddModelBtn');
    if (addModelBtn && brandInput && modelInput) {
        addModelBtn.addEventListener('click', function () {
            var brandName = brandInput.value.trim();
            if (!brandName) {
                window.alert('Enter or quick-add a brand first.');
                brandInput.focus();
                return;
            }
            var modelName = window.prompt('Model name', modelInput.value.trim());
            if (!modelName || !modelName.trim()) {
                return;
            }
            resolveBrandId(brandName).then(function (brandId) {
                return postQuickAdd({ action: 'add_model', brand_id: brandId, model_name: modelName.trim() });
            }).then(function (data) {
                appendDatalistOption(modelDatalist, data.label || modelName.trim());
                modelInput.value = data.label || modelName.trim();
            }).catch(function (error) {
                window.alert(error.message);
            });
        });
    }

    var quantityInput = document.getElementById('asset_quantity');
    var unitCostInput = document.getElementById('asset_unit_cost');
    var amountInput = document.getElementById('asset_acquisition_cost');

    function updateAcquisitionAmount() {
        if (!quantityInput || !unitCostInput || !amountInput) {
            return;
        }
        var quantity = parseFloat(quantityInput.value || '0');
        var unitCost = parseFloat(unitCostInput.value || '0');
        if (!isFinite(quantity) || quantity < 0) {
            quantity = 0;
        }
        if (!isFinite(unitCost) || unitCost < 0) {
            unitCost = 0;
        }
        amountInput.value = (quantity * unitCost).toFixed(2);
    }

    if (quantityInput && unitCostInput && amountInput) {
        quantityInput.addEventListener('input', updateAcquisitionAmount);
        unitCostInput.addEventListener('input', updateAcquisitionAmount);
        updateAcquisitionAmount();
    }

    var itemTypeSelect = document.getElementById('asset_item_type');
    var accountCodeSelect = document.getElementById('asset_account_code_id');
    var classificationSelect = document.getElementById('asset_classification_id');
    var addClassificationBtn = document.getElementById('assetAddClassificationBtn');

    function refreshSelect(select) {
        if (select && window.SPAMS && typeof window.SPAMS.refreshSelect2 === 'function') {
            window.SPAMS.refreshSelect2(select);
        }
    }

    function showClassificationError(message) {
        window.alert(message);
    }

    function selectedAccountCodeIdForClassification() {
        if (!accountCodeSelect) {
            return '0';
        }
        return accountCodeSelect.value && accountCodeSelect.value !== '0'
            ? accountCodeSelect.value
            : currentAssetAccountCodeId;
    }

    function classificationItemType() {
        return itemTypeSelect && itemTypeSelect.value === 'semi_expendable' ? 'semi_expendable' : 'equipment';
    }

    function expectedAssetGroups() {
        return classificationItemType() === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
    }

    function classificationGroupMatches(optionData, expectedGroups) {
        if (expectedGroups.indexOf(optionData.classificationGroup) !== -1) {
            return true;
        }
        if (!optionData.classificationGroup && optionData.accountCodeId) {
            var accountCode = accountCodeOptions.find(function (accountData) {
                return accountData.value === optionData.accountCodeId;
            });
            return !!accountCode && expectedGroups.indexOf(accountCode.accountGroup) !== -1;
        }
        return false;
    }

    function filterClassifications() {
        if (!itemTypeSelect || !classificationSelect) {
            return;
        }
        var previousValue = classificationSelect.value || '0';
        var expectedGroups = expectedAssetGroups();
        classificationSelect.innerHTML = '';
        classificationSelect.add(new Option('Keep current classification', '0', false, false));
        classificationOptions.forEach(function (optionData) {
            if (!classificationGroupMatches(optionData, expectedGroups)) { return; }
            var option = new Option(optionData.text, optionData.value, false, optionData.value === previousValue);
            option.setAttribute('data-account-code-id', optionData.accountCodeId || '0');
            option.setAttribute('data-classification-group', optionData.classificationGroup || '');
            classificationSelect.add(option);
        });
        if (previousValue !== '0' && !Array.from(classificationSelect.options).some(function (option) { return option.value === previousValue; })) {
            classificationSelect.value = '0';
        }
        refreshSelect(classificationSelect);
    }

    if (addClassificationBtn && classificationSelect) {
        addClassificationBtn.addEventListener('click', function () {
            var name = window.prompt('Classification name', '');
            if (name === null) {
                return;
            }
            name = name.trim();
            var accountCodeId = selectedAccountCodeIdForClassification();
            if (!name) {
                showClassificationError('Classification name is required.');
                return;
            }
            if (!accountCodeId || accountCodeId === '0') {
                showClassificationError('Select an account code before adding a classification.');
                if (accountCodeSelect) {
                    accountCodeSelect.focus();
                }
                return;
            }

            addClassificationBtn.disabled = true;
            fetch(classificationQuickAddEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken
                },
                body: new URLSearchParams({
                    _csrf: csrfToken,
                    item_type: classificationItemType(),
                    classification_name: name,
                    account_code_id: accountCodeId,
                    useful_life_years: '',
                    description: ''
                }).toString()
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                if (!data.ok || !data.classification) {
                    throw new Error(data.error || 'Unable to save classification.');
                }
                var classification = data.classification;
                var label = [
                    String(classification.classification_family || '').trim(),
                    String(classification.classification_name || '').trim()
                ].filter(Boolean).join(' / ');
                var value = String(classification.id || '');
                if (!value) {
                    throw new Error('Classification was saved but no ID was returned.');
                }
                var existingOption = Array.prototype.find.call(classificationSelect.options, function (option) {
                    return option.value === value;
                });
                if (!existingOption) {
                    existingOption = new Option(label || name, value, false, false);
                    existingOption.setAttribute('data-account-code-id', String(classification.account_code_id || accountCodeId));
                    existingOption.setAttribute('data-classification-group', String(classification.classification_group || ''));
                    classificationSelect.add(existingOption);
                } else {
                    existingOption.textContent = label || name;
                    existingOption.setAttribute('data-account-code-id', String(classification.account_code_id || accountCodeId));
                    existingOption.setAttribute('data-classification-group', String(classification.classification_group || ''));
                }
                classificationOptions.push({
                    value: value,
                    text: label || name,
                    accountCodeId: String(classification.account_code_id || accountCodeId),
                    classificationGroup: String(classification.classification_group || '')
                });
                filterClassifications();
                if (Array.from(classificationSelect.options).some(function (option) { return option.value === value; })) {
                    classificationSelect.value = value;
                }
                refreshSelect(classificationSelect);
            }).catch(function (error) {
                showClassificationError(error.message || 'Unable to save classification.');
            }).finally(function () {
                addClassificationBtn.disabled = false;
            });
        });
    }

    if (itemTypeSelect && accountCodeSelect) {
        var initialAccountCodeValue = accountCodeSelect.value || '0';

        function refreshAccountCodeSelect() {
            refreshSelect(accountCodeSelect);
        }

        function filterAccountCodes() {
            var expectedGroups = itemTypeSelect.value === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
            var previousValue = accountCodeSelect.value || initialAccountCodeValue;
            accountCodeSelect.innerHTML = '';
            accountCodeSelect.add(new Option('Keep current account code', '0', false, false));
            accountCodeOptions.forEach(function(optionData) {
                if (expectedGroups.indexOf(optionData.accountGroup) === -1) { return; }
                var option = new Option(optionData.text, optionData.value, false, optionData.value === previousValue);
                option.setAttribute('data-account-group', optionData.accountGroup);
                accountCodeSelect.add(option);
            });
            if (previousValue !== '0' && !Array.from(accountCodeSelect.options).some(function(option) { return option.value === previousValue; })) {
                accountCodeSelect.value = '0';
            }
            initialAccountCodeValue = '0';
            refreshAccountCodeSelect();
            filterClassifications();
        }

        filterAccountCodes();
        window.setTimeout(filterAccountCodes, 0);
        window.setTimeout(filterAccountCodes, 250);
        itemTypeSelect.addEventListener('change', filterAccountCodes);
        if (window.jQuery) {
            jQuery(itemTypeSelect)
                .off('select2:select.assetAccountFilter select2:clear.assetAccountFilter change.assetAccountFilter')
                .on('select2:select.assetAccountFilter select2:clear.assetAccountFilter change.assetAccountFilter', filterAccountCodes);
        }
    }

    if (!window.SPAMS || typeof window.SPAMS.setupRequiredSummaryValidation !== 'function') {
        return;
    }

    var officeSelect = document.getElementById('asset_office_id');
    var employeeSelect = document.getElementById('asset_employee_id');
    var rcSelect = document.getElementById('asset_responsibility_code_id');

    function filterResponsibilityCodes() {
        if (!officeSelect || !employeeSelect || !rcSelect) {
            return;
        }
        var officeId = String(officeSelect.value || '0');
        var selectedEmployeeOption = employeeSelect.options[employeeSelect.selectedIndex];
        var employeeRcId = selectedEmployeeOption ? String(selectedEmployeeOption.getAttribute('data-responsibility-code-id') || '0') : '0';
        var preferredRcId = '0';
        Array.prototype.forEach.call(rcSelect.options, function (option) {
            var optionOfficeId = String(option.getAttribute('data-office-id') || '0');
            var isPlaceholder = option.value === '' || option.value === '0';
            var shouldShow = isPlaceholder || optionOfficeId === officeId;
            option.hidden = !shouldShow;
            option.disabled = !shouldShow;
            if (shouldShow && !isPlaceholder && employeeRcId !== '0' && option.value === employeeRcId) {
                preferredRcId = option.value;
            }
            if (shouldShow && !isPlaceholder && preferredRcId === '0') {
                preferredRcId = option.value;
            }
        });
        if (rcSelect.selectedOptions.length && rcSelect.selectedOptions[0].disabled) {
            rcSelect.value = '0';
        }
        if (officeId !== '0' && rcSelect.value === '0' && preferredRcId !== '0') {
            rcSelect.value = preferredRcId;
        }
        refreshSelect(rcSelect);
    }

    function filterEmployees() {
        if (!officeSelect || !employeeSelect) {
            return;
        }
        var officeId = String(officeSelect.value || '0');
        var preferredEmployeeId = '0';
        var firstEmployeeId = '0';
        Array.prototype.forEach.call(employeeSelect.options, function (option) {
            var optionOfficeId = String(option.getAttribute('data-office-id') || '0');
            var isPlaceholder = option.value === '' || option.value === '0';
            var shouldShow = isPlaceholder || optionOfficeId === officeId;
            option.hidden = !shouldShow;
            option.disabled = !shouldShow;
            if (shouldShow && !isPlaceholder && firstEmployeeId === '0') {
                firstEmployeeId = option.value;
            }
            if (shouldShow && !isPlaceholder && option.getAttribute('data-is-unit-head') === '1' && preferredEmployeeId === '0') {
                preferredEmployeeId = option.value;
            }
        });
        if (employeeSelect.selectedOptions.length && employeeSelect.selectedOptions[0].disabled) {
            employeeSelect.value = '0';
        }
        if (preferredEmployeeId === '0') {
            preferredEmployeeId = firstEmployeeId;
        }
        if (officeId !== '0' && employeeSelect.value === '0' && preferredEmployeeId !== '0') {
            employeeSelect.value = preferredEmployeeId;
        }
        refreshSelect(employeeSelect);
        filterResponsibilityCodes();
    }

    function syncOfficeFromEmployee() {
        if (!officeSelect || !employeeSelect) {
            return;
        }
        var selectedOption = employeeSelect.options[employeeSelect.selectedIndex];
        if (!selectedOption || !selectedOption.value || selectedOption.value === '0') {
            filterResponsibilityCodes();
            return;
        }
        var selectedOfficeId = String(selectedOption.getAttribute('data-office-id') || '0');
        if (selectedOfficeId !== '0' && officeSelect.value !== selectedOfficeId) {
            officeSelect.value = selectedOfficeId;
            refreshSelect(officeSelect);
            filterEmployees();
            employeeSelect.value = selectedOption.value;
            refreshSelect(employeeSelect);
        }
        filterResponsibilityCodes();
    }

    if (officeSelect) {
        officeSelect.addEventListener('change', filterEmployees);
        if (window.jQuery) {
            jQuery(officeSelect)
                .off('select2:select.assetOfficeFilter select2:clear.assetOfficeFilter change.assetOfficeFilter')
                .on('select2:select.assetOfficeFilter select2:clear.assetOfficeFilter change.assetOfficeFilter', filterEmployees);
        }
        filterEmployees();
    }

    if (employeeSelect) {
        employeeSelect.addEventListener('change', syncOfficeFromEmployee);
        if (window.jQuery) {
            jQuery(employeeSelect)
                .off('select2:select.assetEmployeeFilter select2:clear.assetEmployeeFilter change.assetEmployeeFilter')
                .on('select2:select.assetEmployeeFilter select2:clear.assetEmployeeFilter change.assetEmployeeFilter', syncOfficeFromEmployee);
        }
    }

    window.SPAMS.setupRequiredSummaryValidation({
        formId: 'assetEditForm',
        summaryId: 'asset_edit_form_feedback',
        requiredFields: [
            { id: 'asset_property_number', label: 'Property number', feedbackId: 'asset_property_number_feedback', events: ['input', 'change'] }
        ]
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
