<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Property Custodian', 'Viewer');

$db = db();
$page_title = 'Property Card Print';

$purchaseOrderId = (int) ($_GET['purchase_order_id'] ?? 0);
$officeId = (int) ($_GET['office_id'] ?? 0);
$source = trim($_GET['source'] ?? 'all');
$itemType = trim($_GET['item_type'] ?? 'all');
$year = (int) ($_GET['year'] ?? 0);
$fundNumber = trim($_GET['fund_number'] ?? '');
if (!in_array($source, ['all', 'system', 'legacy'], true)) {
    $source = 'all';
}
if (!in_array($itemType, ['all', 'equipment', 'semi_expendable'], true)) {
    $itemType = 'all';
}
if (!in_array($fundNumber, ['', '01', '05', '06', '07'], true)) {
    $fundNumber = '';
}
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';

$purchaseOrders = [];
$offices = [];
$years = [];
$cards = [];

if ($db) {
    ensure_legacy_assets_fund_column($db);
}

function property_card_meta(array $card): array
{
    $itemType = (string) ($card['item_type'] ?? '');
    if ($itemType === 'semi_expendable') {
        return [
            'appendix' => 'Annex A.1',
            'title' => 'Semi Expendable Property Card',
            'asset_label' => 'Semi-expendable Property',
            'number_label' => 'Semi-expendable Property Number',
            'reference_label' => 'IAR Number',
            'issue_label' => 'Issue / Transfer / Disposal',
            'issue_ref_label' => 'Item No.',
            'issue_party_label' => 'Office / Officer',
        ];
    }
    return [
        'appendix' => 'Appendix 69',
        'title' => 'Property Card',
        'asset_label' => 'Property, Plant and Equipment',
        'number_label' => 'Property Number',
        'reference_label' => 'IAR Number / PAR No.',
        'issue_label' => 'Issue / Transfer / Disposal',
        'issue_party_label' => 'Office / Officer',
    ];
}

function property_card_fund_number(?string $fundCode, ?string $fundSource = null): string
{
    return fund_number_from_source($fundCode, $fundSource);
}

function property_card_normalize_value($value): string
{
    return strtolower(trim((string) $value));
}

function property_card_append_unique(array &$target, string $field, $value): void
{
    $text = trim((string) $value);
    if ($text === '') {
        return;
    }
    if (!isset($target[$field]) || !is_array($target[$field])) {
        $target[$field] = [];
    }
    if (!in_array($text, $target[$field], true)) {
        $target[$field][] = $text;
    }
}

function property_card_compose_group_key(array $card): string
{
    $parts = [
        $card['source_type'] ?? '',
        $card['po_number'] ?? '',
        $card['item_type'] ?? '',
        $card['classification_name'] ?? '',
        $card['account_name'] ?? '',
        $card['item_description'] ?? '',
        $card['brand'] ?? '',
        $card['model'] ?? '',
        $card['unit_cost'] ?? 0,
        $card['fund_number'] ?? '',
        $card['office_name'] ?? '',
        $card['accountable_person'] ?? '',
        $card['position_title'] ?? '',
        $card['rc_code'] ?? '',
        $card['accountability_no'] ?? '',
        $card['document_type'] ?? '',
    ];

    return implode('|', array_map('property_card_normalize_value', $parts));
}

function property_card_merge_cards(array $cards): array
{
    $grouped = [];

    foreach ($cards as $card) {
        $groupKey = property_card_compose_group_key($card);
        if (!isset($grouped[$groupKey])) {
            $card['property_numbers'] = array_values(array_filter([trim((string) ($card['property_number'] ?? ''))]));
            $card['serial_numbers'] = array_values(array_filter([trim((string) ($card['serial_no'] ?? ''))]));
            $grouped[$groupKey] = $card;
            continue;
        }

        property_card_append_unique($grouped[$groupKey], 'property_numbers', $card['property_number'] ?? '');
        property_card_append_unique($grouped[$groupKey], 'serial_numbers', $card['serial_no'] ?? '');
        $grouped[$groupKey]['card_key'] = (string) ($grouped[$groupKey]['card_key'] ?? $groupKey);

        foreach ((array) ($card['ledger'] ?? []) as $entry) {
            $ledgerKey = implode('|', [
                property_card_normalize_value($entry['date'] ?? ''),
                property_card_normalize_value($entry['reference'] ?? ''),
                property_card_normalize_value($entry['issue_reference'] ?? ''),
                property_card_normalize_value($entry['issue_party'] ?? ''),
                property_card_normalize_value($entry['remarks'] ?? ''),
                property_card_normalize_value($entry['receipt_unit_cost'] ?? 0),
            ]);

            $matched = false;
            foreach ($grouped[$groupKey]['ledger'] as &$existingEntry) {
                $existingKey = implode('|', [
                    property_card_normalize_value($existingEntry['date'] ?? ''),
                    property_card_normalize_value($existingEntry['reference'] ?? ''),
                    property_card_normalize_value($existingEntry['issue_reference'] ?? ''),
                    property_card_normalize_value($existingEntry['issue_party'] ?? ''),
                    property_card_normalize_value($existingEntry['remarks'] ?? ''),
                    property_card_normalize_value($existingEntry['receipt_unit_cost'] ?? 0),
                ]);

                if ($existingKey !== $ledgerKey) {
                    continue;
                }

                $existingEntry['receipt_qty'] = (float) ($existingEntry['receipt_qty'] ?? 0) + (float) ($entry['receipt_qty'] ?? 0);
                $existingEntry['receipt_cost'] = (float) ($existingEntry['receipt_cost'] ?? 0) + (float) ($entry['receipt_cost'] ?? 0);
                $existingEntry['issue_qty'] = (float) ($existingEntry['issue_qty'] ?? 0) + (float) ($entry['issue_qty'] ?? 0);
                $matched = true;
                break;
            }
            unset($existingEntry);

            if (!$matched) {
                $grouped[$groupKey]['ledger'][] = $entry;
            }
        }
    }

    foreach ($grouped as &$card) {
        $card['property_numbers'] = array_values(array_filter((array) ($card['property_numbers'] ?? [])));
        $card['serial_numbers'] = array_values(array_filter((array) ($card['serial_numbers'] ?? [])));
        $card['property_number'] = implode(', ', $card['property_numbers']);
        $card['serial_no'] = implode(', ', $card['serial_numbers']);
    }
    unset($card);

    return array_values($grouped);
}

if ($db) {
    $poRes = $db->query("SELECT id, po_number FROM purchase_orders ORDER BY po_date DESC, id DESC");
    if ($poRes) {
        $purchaseOrders = $poRes->fetch_all(MYSQLI_ASSOC);
    }

    $officeRes = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeRes) {
        $offices = $officeRes->fetch_all(MYSQLI_ASSOC);
    }

    $yearRes = $db->query(
        "SELECT report_year
         FROM (
            SELECT DISTINCT YEAR(r.received_date) AS report_year
            FROM receivings r
            WHERE r.received_date IS NOT NULL
            UNION
            SELECT DISTINCT YEAR(la.acquisition_date) AS report_year
            FROM legacy_assets la
            WHERE la.acquisition_date IS NOT NULL
         ) year_pool
         WHERE report_year IS NOT NULL
         ORDER BY report_year DESC"
    );
    if ($yearRes) {
        while ($yearRow = $yearRes->fetch_assoc()) {
            $years[] = (int) ($yearRow['report_year'] ?? 0);
        }
    }

    if ($source !== 'legacy') {
        $sql = "SELECT
                    si.id AS card_key,
                    'system' AS source_type,
                    si.system_reference AS stock_ref,
                    rid.brand,
                    rid.model,
                    rid.serial_no,
                    poi.item_description,
                    ri.unit_cost,
                    r.received_date,
                    COALESCE(NULLIF(r.ris_no, ''), r.system_reference) AS iar_ref,
                    c.useful_life_years,
                    d.document_no AS accountability_no,
                    d.document_type,
                    d.distribution_date,
                    o.office_name,
                    e.first_name,
                    e.middle_name,
                    e.last_name,
                    e.suffix_name,
                    e.position_title,
                    rc.code AS rc_code,
                    did.property_number,
                    f.fund_code,
                    f.fund_source,
                    po.po_number,
                    poi.item_type,
                    c.classification_name,
                    c.classification_family,
                    ac.account_name
                FROM distribution_item_details did
                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
                INNER JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
                INNER JOIN stock_items si ON si.id = rid.stock_item_id
                INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
                INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                INNER JOIN receivings r ON r.id = ri.receiving_id
                INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
                LEFT JOIN funds f ON f.id = po.fund_id
                LEFT JOIN classifications c ON c.id = poi.classification_id
                LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
                LEFT JOIN offices o ON o.id = d.office_id
                LEFT JOIN employees e ON e.id = d.employee_id
                LEFT JOIN responsibility_codes rc ON rc.office_id = d.office_id
                WHERE poi.item_type IN ('equipment', 'semi_expendable')";

        $types = '';
        $params = [];
        if ($purchaseOrderId > 0) {
            $sql .= " AND po.id = ?";
            $types .= 'i';
            $params[] = $purchaseOrderId;
        }
        if ($officeId > 0) {
            $sql .= " AND d.office_id = ?";
            $types .= 'i';
            $params[] = $officeId;
        }
        if ($itemType !== 'all') {
            $sql .= " AND poi.item_type = ?";
            $types .= 's';
            $params[] = $itemType;
        }
        if ($year > 0) {
            $sql .= " AND YEAR(r.received_date) = ?";
            $types .= 'i';
            $params[] = $year;
        }
        if ($fundNumber !== '') {
            $sql .= " AND (f.fund_code LIKE ? OR f.fund_source LIKE ?)";
            $types .= 'ss';
            $params[] = '%' . $fundNumber . '%';
            $params[] = '%' . $fundNumber . '%';
        }
        $sql .= " ORDER BY poi.item_type ASC, po.po_number ASC, did.property_number ASC, si.id ASC";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                $refs = [$types];
                foreach ($params as $k => $v) {
                    $refs[] = &$params[$k];
                }
                call_user_func_array([$stmt, 'bind_param'], $refs);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $cards[] = [
                    'card_key' => 'system-' . (int) $row['card_key'],
                    'source_type' => 'system',
                    'po_number' => $row['po_number'] ?? '',
                    'item_type' => $row['item_type'] ?? '',
                    'classification_name' => $row['classification_name'] ?? '',
                    'classification_family' => $row['classification_family'] ?? '',
                    'fund_number' => property_card_fund_number($row['fund_code'] ?? '', $row['fund_source'] ?? ''),
                    'account_name' => $row['account_name'] ?? '',
                    'accountability_no' => $row['accountability_no'] ?? '',
                    'document_type' => strtoupper((string) ($row['document_type'] ?? '')),
                    'property_number' => $row['property_number'] ?? '',
                    'item_description' => $row['item_description'] ?? '',
                    'brand' => $row['brand'] ?? '',
                    'model' => $row['model'] ?? '',
                    'serial_no' => $row['serial_no'] ?? '',
                    'useful_life_years' => $row['useful_life_years'] ?? '',
                    'office_name' => $row['office_name'] ?? '',
                    'accountable_person' => employee_display_name($row),
                    'position_title' => $row['position_title'] ?? '',
                    'rc_code' => $row['rc_code'] ?? '',
                    'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                    'ledger' => [
                        [
                            'date' => $row['received_date'] ?? null,
                            'reference' => $row['iar_ref'] ?? '',
                            'receipt_qty' => 1,
                            'receipt_unit_cost' => (float) ($row['unit_cost'] ?? 0),
                            'receipt_cost' => (float) ($row['unit_cost'] ?? 0),
                            'issue_qty' => 0,
                            'issue_reference' => '',
                            'issue_party' => '',
                            'remarks' => 'IAR Number',
                        ],
                        [
                            'date' => $row['distribution_date'] ?? null,
                            'reference' => $row['accountability_no'] ?? '',
                            'receipt_qty' => 0,
                            'receipt_unit_cost' => 0,
                            'receipt_cost' => 0,
                            'issue_qty' => 1,
                            'issue_reference' => $row['property_number'] ?? '',
                            'issue_party' => trim(implode(' / ', array_filter([
                                $row['office_name'] ?? '',
                                employee_display_name($row),
                            ]))),
                            'remarks' => 'Issued (' . strtoupper((string) ($row['document_type'] ?? '')) . ')',
                        ],
                    ],
                ];
            }
            $stmt->close();
        }
    }

    if ($source !== 'system' && $purchaseOrderId === 0) {
        $legacySql = "SELECT
                        la.id AS card_key,
                        'legacy' AS source_type,
                        la.system_reference AS stock_ref,
                        la.brand,
                        la.model,
                        la.serial_no,
                        la.item_description,
                        la.unit_cost,
                        la.acquisition_date AS received_date,
                        la.system_reference AS iar_ref,
                        NULL AS useful_life_years,
                        '' AS accountability_no,
                        la.item_type AS document_type,
                        la.acquisition_date AS distribution_date,
                        o.office_name,
                        e.first_name,
                        e.middle_name,
                        e.last_name,
                        e.suffix_name,
                        e.position_title,
                        rc.code AS rc_code,
                        la.property_number,
                        f.fund_code,
                        f.fund_source,
                        '' AS po_number,
                        la.item_type,
                        c.classification_name,
                        c.classification_family,
                        ac.account_name
                    FROM legacy_assets la
                    LEFT JOIN classifications c ON c.id = la.classification_id
                    LEFT JOIN account_codes ac ON ac.id = la.account_code_id
                    LEFT JOIN funds f ON f.id = la.fund_id
                    LEFT JOIN offices o ON o.id = la.office_id
                    LEFT JOIN employees e ON e.id = la.employee_id
                    LEFT JOIN responsibility_codes rc ON rc.id = la.responsibility_code_id
                    WHERE la.is_active = 1";
        $legacyTypes = '';
        $legacyParams = [];
        if ($officeId > 0) {
            $legacySql .= " AND la.office_id = ?";
            $legacyTypes .= 'i';
            $legacyParams[] = $officeId;
        }
        if ($itemType !== 'all') {
            $legacySql .= " AND la.item_type = ?";
            $legacyTypes .= 's';
            $legacyParams[] = $itemType;
        }
        if ($year > 0) {
            $legacySql .= " AND YEAR(la.acquisition_date) = ?";
            $legacyTypes .= 'i';
            $legacyParams[] = $year;
        }
        if ($fundNumber !== '') {
            $legacySql .= " AND (f.fund_code LIKE ? OR f.fund_source LIKE ?)";
            $legacyTypes .= 'ss';
            $legacyParams[] = '%' . $fundNumber . '%';
            $legacyParams[] = '%' . $fundNumber . '%';
        }
        $legacySql .= " ORDER BY la.item_type ASC, la.property_number ASC, la.id ASC";

        $legacyStmt = $db->prepare($legacySql);
        if ($legacyStmt) {
            if ($legacyTypes !== '') {
                $refs = [$legacyTypes];
                foreach ($legacyParams as $k => $v) {
                    $refs[] = &$legacyParams[$k];
                }
                call_user_func_array([$legacyStmt, 'bind_param'], $refs);
            }
            $legacyStmt->execute();
            $res = $legacyStmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $cards[] = [
                    'card_key' => 'legacy-' . (int) $row['card_key'],
                    'source_type' => 'legacy',
                    'po_number' => '',
                    'item_type' => $row['item_type'] ?? '',
                    'classification_name' => $row['classification_name'] ?? '',
                    'classification_family' => $row['classification_family'] ?? '',
                    'fund_number' => property_card_fund_number($row['fund_code'] ?? '', $row['fund_source'] ?? ''),
                    'account_name' => $row['account_name'] ?? '',
                    'accountability_no' => 'Beginning Balance',
                    'document_type' => 'LEGACY',
                    'property_number' => $row['property_number'] ?? '',
                    'item_description' => $row['item_description'] ?? '',
                    'brand' => $row['brand'] ?? '',
                    'model' => $row['model'] ?? '',
                    'serial_no' => $row['serial_no'] ?? '',
                    'useful_life_years' => '',
                    'office_name' => $row['office_name'] ?? '',
                    'accountable_person' => employee_display_name($row),
                    'position_title' => $row['position_title'] ?? '',
                    'rc_code' => $row['rc_code'] ?? '',
                    'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                    'ledger' => [
                        [
                            'date' => $row['received_date'] ?? null,
                            'reference' => 'Beginning Balance',
                            'receipt_qty' => 1,
                            'receipt_unit_cost' => (float) ($row['unit_cost'] ?? 0),
                            'receipt_cost' => (float) ($row['unit_cost'] ?? 0),
                            'issue_qty' => 0,
                            'issue_reference' => '',
                            'issue_party' => '',
                            'remarks' => 'Opening balance',
                        ],
                    ],
                ];
            }
            $legacyStmt->close();
        }
    }

    if ($cards) {
        $cards = property_card_merge_cards($cards);
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Property Card Print</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page { size: landscape; margin: 0.35in; }
        body { color: #000; }
        .print-wrap { max-width: 1280px; }
        .coa-card {
            position: relative;
            border: 1px solid #000;
            padding: 0;
            background: #fff;
        }
        .coa-card-page {
            page-break-after: always;
            break-after: page;
        }
        .coa-card-page:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        .coa-sheet {
            position: relative;
            padding-top: 24px;
        }
        .coa-appendix {
            position: absolute;
            top: 0;
            right: 0;
            font-size: 14px;
            font-style: italic;
        }
        .coa-title {
            text-align: center;
            font-family: "Times New Roman", serif;
            font-size: 22px;
            font-weight: 700;
            text-transform: uppercase;
            margin: 12px 0 28px;
        }
        .coa-meta-line {
            display: grid;
            grid-template-columns: 1fr 250px;
            gap: 22px;
            margin-bottom: 10px;
            font-size: 14px;
            align-items: end;
        }
        .coa-line-field {
            display: flex;
            align-items: end;
            gap: 6px;
            min-width: 0;
        }
        .coa-line-label {
            font-weight: 700;
            white-space: nowrap;
        }
        .coa-line-fill {
            flex: 1;
            min-height: 20px;
            border-bottom: 1px solid #000;
            padding: 0 4px 2px;
            overflow-wrap: anywhere;
        }
        .coa-details,
        .coa-ledger {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .coa-details td,
        .coa-ledger th,
        .coa-ledger td {
            border: 1px solid #000;
            padding: 4px 6px;
            font-size: 13px;
            vertical-align: middle;
        }
        .coa-details td {
            height: 32px;
        }
        .coa-label-cell {
            font-weight: 700;
        }
        .coa-ledger thead th {
            font-weight: 700;
            text-align: center;
            line-height: 1.15;
        }
        .coa-ledger tbody td {
            height: 29px;
            vertical-align: top;
        }
        .coa-ledger .num {
            text-align: center;
        }
        .coa-ledger .amt {
            text-align: right;
            white-space: nowrap;
        }
        .coa-ledger .date {
            white-space: nowrap;
            text-align: center;
        }
        @media print {
            .no-print { display: none !important; }
            .print-wrap { max-width: none; }
            .coa-card {
                border: 0;
            }
        }
    </style>
</head>
<body>
<div class="container mt-3 print-wrap">
    <div class="no-print mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h4 class="mb-0">Property Card Print</h4>
                <div class="text-muted small">Print property cards by PO, office, or source.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo base_url('modules/property/index.php'); ?>" class="btn btn-outline-secondary">Back to Property Register</a>
                <?php if ($cards): ?>
                    <a href="<?php echo h(base_url('modules/property/property_card_print.php?' . http_build_query(array_filter([
                        'purchase_order_id' => $purchaseOrderId ?: null,
                        'office_id' => $officeId ?: null,
                        'source' => $source !== 'all' ? $source : null,
                        'item_type' => $itemType !== 'all' ? $itemType : null,
                        'year' => $year ?: null,
                        'fund_number' => $fundNumber !== '' ? $fundNumber : null,
                        'print' => 1,
                    ])))); ?>" class="btn btn-primary">Print Current Result</a>
                <?php endif; ?>
            </div>
        </div>

        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Purchase Order</label>
                <select name="purchase_order_id" class="form-select">
                    <option value="">All Purchase Orders</option>
                    <?php foreach ($purchaseOrders as $po): ?>
                        <option value="<?php echo (int) $po['id']; ?>" <?php echo $purchaseOrderId === (int) $po['id'] ? 'selected' : ''; ?>>
                            <?php echo h($po['po_number']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Legacy assets do not have a linked PO unless they were encoded through the new transaction flow.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Office</label>
                <select name="office_id" class="form-select">
                    <option value="">All Offices</option>
                    <?php foreach ($offices as $office): ?>
                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>>
                            <?php echo h($office['office_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Source</label>
                <select name="source" class="form-select">
                    <option value="all" <?php echo $source === 'all' ? 'selected' : ''; ?>>All Sources</option>
                    <option value="system" <?php echo $source === 'system' ? 'selected' : ''; ?>>System Transactions</option>
                    <option value="legacy" <?php echo $source === 'legacy' ? 'selected' : ''; ?>>Beginning Balance</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Item Type</label>
                <select name="item_type" class="form-select">
                    <option value="all" <?php echo $itemType === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="equipment" <?php echo $itemType === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                    <option value="semi_expendable" <?php echo $itemType === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Fund No.</label>
                <select name="fund_number" class="form-select">
                    <option value="">All</option>
                    <option value="01" <?php echo $fundNumber === '01' ? 'selected' : ''; ?>>01</option>
                    <option value="05" <?php echo $fundNumber === '05' ? 'selected' : ''; ?>>05</option>
                    <option value="06" <?php echo $fundNumber === '06' ? 'selected' : ''; ?>>06</option>
                    <option value="07" <?php echo $fundNumber === '07' ? 'selected' : ''; ?>>07</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Year</label>
                <select name="year" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($years as $yearOption): ?>
                        <option value="<?php echo (int) $yearOption; ?>" <?php echo $year === (int) $yearOption ? 'selected' : ''; ?>>
                            <?php echo h((string) $yearOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Load Cards</button>
                <a href="<?php echo base_url('modules/property/property_card_print.php'); ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>

        <div class="small text-muted mt-3"><?php echo count($cards); ?> card(s) found.</div>
    </div>

    <?php if (!$cards): ?>
        <div class="alert alert-info">No property cards found for the current filter.</div>
    <?php endif; ?>

    <?php foreach ($cards as $card): ?>
        <?php $meta = property_card_meta($card); ?>
        <?php
            $assetName = trim((string) ($card['classification_name'] ?: $card['classification_family']));
            if ($assetName === '') {
                $assetName = trim((string) ($card['item_description'] ?? ''));
            }
            $descriptionParts = array_filter([
                trim((string) ($card['item_description'] ?? '')),
                trim((string) ($card['brand'] ?? '')),
                trim((string) ($card['model'] ?? '')),
                trim((string) ($card['serial_no'] ?? '')),
            ]);
            $description = implode(' | ', $descriptionParts);
            $targetRows = 16;
            $blankRows = max(0, $targetRows - count($card['ledger']));
        ?>
        <div class="coa-card coa-card-page mb-4">
            <div class="p-3 p-lg-4 coa-sheet">
                <div class="coa-appendix"><?php echo h($meta['appendix']); ?></div>
                <div class="coa-title"><?php echo h($meta['title']); ?></div>

                <div class="coa-meta-line">
                    <div class="coa-line-field">
                        <div class="coa-line-label">Entity Name :</div>
                        <div class="coa-line-fill"><?php echo h(APP_NAME); ?></div>
                    </div>
                    <div class="coa-line-field">
                        <div class="coa-line-label">Fund Number:</div>
                        <div class="coa-line-fill"><?php echo h($card['fund_number']); ?></div>
                    </div>
                </div>

                <table class="coa-details">
                    <colgroup>
                        <col style="width:72%">
                        <col style="width:28%">
                    </colgroup>
                    <tr>
                        <td class="coa-label-cell"><?php echo h($meta['asset_label']); ?> : <span class="fw-normal"><?php echo h($card['account_name']); ?></span></td>
                        <td class="coa-label-cell" rowspan="2"><?php echo h($meta['number_label']); ?>: <span class="fw-normal"><?php echo h($card['property_number']); ?></span></td>
                    </tr>
                    <tr>
                        <td class="coa-label-cell">Description : <span class="fw-normal"><?php echo h($description); ?></span></td>
                    </tr>
                </table>

                <table class="coa-ledger">
                    <colgroup>
                        <col style="width:8.5%">
                        <col style="width:11.5%">
                        <col style="width:8.5%">
                        <col style="width:8.5%">
                        <col style="width:27%">
                        <col style="width:8.5%">
                        <col style="width:13.5%">
                        <col style="width:14%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th rowspan="2">Date</th>
                            <th rowspan="2"><?php echo h($meta['reference_label']); ?></th>
                            <th rowspan="2">Receipt<br>Qty.</th>
                            <th colspan="2"><?php echo h($meta['issue_label']); ?></th>
                            <th rowspan="2">Balance<br>Qty.</th>
                            <th rowspan="2">Amount</th>
                            <th rowspan="2">Remarks</th>
                        </tr>
                        <tr>
                            <th>Qty.</th>
                            <th><?php echo h($meta['issue_party_label']); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $balQty = 0;
                        $balCost = 0.0;
                        foreach ($card['ledger'] as $row):
                            $balQty += ($row['receipt_qty'] ?? 0) - ($row['issue_qty'] ?? 0);
                            $balCost += ($row['receipt_cost'] ?? 0) - (($row['issue_qty'] ?? 0) * ($card['unit_cost'] ?? 0));
                            $remarks = (string) ($row['remarks'] ?? '');
                            if (($row['reference'] ?? '') === 'Beginning Balance') {
                                $remarks = 'Beginning Balance';
                            }
                        ?>
                        <tr>
                            <td class="date"><?php echo h(!empty($row['date']) ? date('m/d/Y', strtotime((string) $row['date'])) : ''); ?></td>
                            <td><?php echo h($row['reference'] ?? ''); ?></td>
                            <td class="num"><?php echo h((float) ($row['receipt_qty'] ?? 0) > 0 ? format_quantity($row['receipt_qty']) : ''); ?></td>
                            <td class="num"><?php echo h((float) ($row['issue_qty'] ?? 0) > 0 ? format_quantity($row['issue_qty']) : ''); ?></td>
                            <td><?php echo h($row['issue_party'] ?? ''); ?></td>
                            <td class="num"><?php echo h(format_quantity($balQty)); ?></td>
                            <td class="amt"><?php echo h(number_format($balCost, 2)); ?></td>
                            <td><?php echo h($remarks); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php for ($i = 0; $i < $blankRows; $i++): ?>
                        <tr>
                            <td>&nbsp;</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($autoPrint && $cards): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>
</body>
</html>
