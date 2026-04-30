<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$receivingId = (int) ($_GET['receiving_id'] ?? 0);

function property_card_single_fund_number(?string $fundCode, ?string $fundSource = null): string
{
    return fund_number_from_source($fundCode, $fundSource);
}

function property_card_single_normalize_value($value): string
{
    return strtolower(trim((string) $value));
}

function property_card_single_append_unique(array &$target, string $field, $value): void
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

if (!$db || $receivingId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$stmt = $db->prepare(
    "SELECT si.id, si.system_reference AS stk_ref,
           rid.brand, rid.model, rid.serial_no,
           poi.item_description,
           ri.unit_cost, r.received_date, COALESCE(NULLIF(r.ris_no, ''), r.system_reference) AS iar_ref,
           c.useful_life_years,
           ac.account_name,
           d.document_no AS par_no, d.distribution_date,
           o.office_name, e.first_name, e.last_name, e.position_title,
           rc.code AS rc_code,
           did.property_number,
           f.fund_code,
           f.fund_source
    FROM receiving_items ri
    INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
    INNER JOIN receivings r ON r.id = ri.receiving_id
    INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
    LEFT JOIN funds f ON f.id = po.fund_id
    LEFT JOIN classifications c ON c.id = poi.classification_id
    LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
    LEFT JOIN receiving_item_details rid ON rid.receiving_item_id = ri.id
    LEFT JOIN stock_items si ON si.id = rid.stock_item_id
    LEFT JOIN distribution_item_details did ON did.receiving_item_detail_id = rid.id
    LEFT JOIN distribution_items ditem ON ditem.id = did.distribution_item_id
    LEFT JOIN distributions d ON d.id = ditem.distribution_id AND d.document_type = 'par'
    LEFT JOIN offices o ON o.id = d.office_id
    LEFT JOIN employees e ON e.id = d.employee_id
    LEFT JOIN responsibility_codes rc ON rc.office_id = o.id
    WHERE ri.receiving_id = ?
      AND poi.item_type = 'equipment'"
);

$rows = [];
if ($stmt) {
    $stmt->bind_param('i', $receivingId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = $r;
    }
    $stmt->close();
}

// Group same items into one card.
$cards = [];
foreach ($rows as $r) {
    $groupKey = implode('|', [
        property_card_single_normalize_value($r['item_description'] ?? ''),
        property_card_single_normalize_value($r['brand'] ?? ''),
        property_card_single_normalize_value($r['model'] ?? ''),
        property_card_single_normalize_value($r['unit_cost'] ?? 0),
        property_card_single_normalize_value($r['account_name'] ?? ''),
        property_card_single_normalize_value($r['par_no'] ?? ''),
        property_card_single_normalize_value($r['office_name'] ?? ''),
        property_card_single_normalize_value(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))),
        property_card_single_normalize_value($r['fund_code'] ?? ''),
        property_card_single_normalize_value($r['fund_source'] ?? ''),
    ]);
    if (!isset($cards[$groupKey])) {
        $cards[$groupKey] = [
            'stk_ref' => $r['stk_ref'] ?? '',
            'brand' => $r['brand'] ?? '',
            'model' => $r['model'] ?? '',
            'serial_no' => '',
            'item_description' => $r['item_description'] ?? '',
            'unit_cost' => $r['unit_cost'] ?? 0,
            'received_date' => $r['received_date'] ?? null,
            'iar_ref' => $r['iar_ref'] ?? '',
            'useful_life_years' => $r['useful_life_years'] ?? null,
            'account_name' => $r['account_name'] ?? '',
            'par_no' => $r['par_no'] ?? null,
            'par_date' => $r['distribution_date'] ?? null,
            'office_name' => $r['office_name'] ?? '',
            'accountable_person' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'position_title' => $r['position_title'] ?? '',
            'rc_code' => $r['rc_code'] ?? '',
            'property_number' => '',
            'property_numbers' => [],
            'serial_numbers' => [],
            'fund_number' => property_card_single_fund_number($r['fund_code'] ?? '', $r['fund_source'] ?? ''),
            'ledger' => [],
        ];
    }

    property_card_single_append_unique($cards[$groupKey], 'property_numbers', $r['property_number'] ?? '');
    property_card_single_append_unique($cards[$groupKey], 'serial_numbers', $r['serial_no'] ?? '');

    if (empty($cards[$groupKey]['ledger'])) {
        $cards[$groupKey]['ledger'][] = [
            'date' => $r['received_date'] ?? null,
            'reference' => $r['iar_ref'] ?? '',
            'receipt_qty' => 0,
            'receipt_cost' => (float) ($r['unit_cost'] ?? 0),
            'issue_qty' => 0,
            'remarks' => 'IAR Number',
        ];
    }

    $cards[$groupKey]['ledger'][0]['receipt_qty'] = (float) ($cards[$groupKey]['ledger'][0]['receipt_qty'] ?? 0) + 1;
    $cards[$groupKey]['ledger'][0]['receipt_cost'] = (float) ($cards[$groupKey]['ledger'][0]['receipt_cost'] ?? 0) + (float) ($r['unit_cost'] ?? 0);

    if (!empty($r['par_no'])) {
        $issueRowFound = false;
        foreach ($cards[$groupKey]['ledger'] as &$ledgerRow) {
            if (($ledgerRow['reference'] ?? '') !== ($r['par_no'] ?? '')
                || ($ledgerRow['remarks'] ?? '') !== 'Issued (PAR)'
                || ($ledgerRow['date'] ?? '') !== ($r['distribution_date'] ?? null)) {
                continue;
            }
            $ledgerRow['issue_qty'] = (float) ($ledgerRow['issue_qty'] ?? 0) + 1;
            $issueRowFound = true;
            break;
        }
        unset($ledgerRow);

        if (!$issueRowFound) {
            $cards[$groupKey]['ledger'][] = [
                'date' => $r['distribution_date'] ?? null,
                'reference' => $r['par_no'],
                'receipt_qty' => 0,
                'receipt_cost' => 0,
                'issue_qty' => 1,
                'remarks' => 'Issued (PAR)',
            ];
        }
    }
}

foreach ($cards as &$card) {
    $card['property_number'] = implode(', ', array_values(array_filter((array) ($card['property_numbers'] ?? []))));
    $card['serial_no'] = implode(', ', array_values(array_filter((array) ($card['serial_numbers'] ?? []))));
}
unset($card);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Property Card</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @page { size: landscape; margin: 0.35in; }
        body { color:#000; }
        .print-wrap { max-width: 1280px; }
        .coa-card {
            border: 1px solid #000;
            background: #fff;
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
            .no-print { display:none !important; }
            .print-wrap { max-width: none; }
            .coa-card { border: 0; }
        }
    </style>
</head>
<body>
    <div class="container mt-3 print-wrap">
        <div class="d-flex justify-content-between mb-2">
            <div>
                <a href="<?php echo base_url('modules/purchase_orders/view.php?receiving_id=' . $receivingId); ?>" class="btn btn-sm btn-outline-secondary no-print">Back</a>
                <button onclick="window.print()" class="btn btn-sm btn-primary no-print">Print</button>
            </div>
        </div>

        <?php foreach ($cards as $card): ?>
            <?php
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
            <div class="coa-card mb-4">
                <div class="p-3 p-lg-4 coa-sheet">
                    <div class="coa-appendix">Appendix 69</div>
                    <div class="coa-title">Property Card</div>

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
                            <td class="coa-label-cell">Property, Plant and Equipment : <span class="fw-normal"><?php echo h($card['account_name']); ?></span></td>
                            <td class="coa-label-cell" rowspan="2">Property Number: <span class="fw-normal"><?php echo h($card['property_number'] ?? ''); ?></span></td>
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
                                <th rowspan="2">IAR Number / PAR No.</th>
                                <th rowspan="2">Receipt<br>Qty.</th>
                                <th colspan="2">Issue/Transfer/ Disposal</th>
                                <th rowspan="2">Balance<br>Qty.</th>
                                <th rowspan="2">Amount</th>
                                <th rowspan="2">Remarks</th>
                            </tr>
                            <tr>
                                <th>Qty.</th>
                                <th>Office/Officer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $balQty = 0;
                            $balCost = 0.0;
                            foreach ($card['ledger'] as $row):
                                $balQty += ($row['receipt_qty'] ?? 0) - ($row['issue_qty'] ?? 0);
                                $balCost += ($row['receipt_cost'] ?? 0) - (($row['issue_qty'] ?? 0) * ($card['unit_cost'] ?? 0));
                                $issueParty = '';
                                if ((float) ($row['issue_qty'] ?? 0) > 0) {
                                    $issueParty = trim(implode(' / ', array_filter([
                                        $card['office_name'] ?? '',
                                        $card['accountable_person'] ?? '',
                                    ])));
                                }
                            ?>
                            <tr>
                                <td class="date"><?php echo h(!empty($row['date']) ? date('m/d/Y', strtotime((string) $row['date'])) : ''); ?></td>
                                <td><?php echo h($row['reference'] ?? ''); ?></td>
                                <td class="num"><?php echo h((float) ($row['receipt_qty'] ?? 0) > 0 ? format_quantity($row['receipt_qty']) : ''); ?></td>
                                <td class="num"><?php echo h((float) ($row['issue_qty'] ?? 0) > 0 ? format_quantity($row['issue_qty']) : ''); ?></td>
                                <td><?php echo h($issueParty); ?></td>
                                <td class="num"><?php echo h(format_quantity($balQty)); ?></td>
                                <td class="amt"><?php echo h(number_format($balCost, 2)); ?></td>
                                <td><?php echo h($row['remarks'] ?? ''); ?></td>
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
</body>
</html>
