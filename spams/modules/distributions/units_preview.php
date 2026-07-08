<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

header('Content-Type: application/json');

$db = db();
$receivingId = (int) ($_GET['receiving_id'] ?? 0);
$itemType = (string) ($_GET['item_type'] ?? 'equipment');
$semiType = (string) ($_GET['semi_type'] ?? '');

if (!in_array($itemType, ['equipment', 'semi_expendable'], true)) {
    echo json_encode(['ok' => false, 'html' => '']);
    exit;
}

if ($itemType === 'semi_expendable' && !in_array($semiType, ['high_value', 'low_value'], true)) {
    $semiType = 'high_value';
}

if (!$db || $receivingId <= 0) {
    echo json_encode(['ok' => false, 'html' => '']);
    exit;
}

$threshold = get_active_threshold($db);
$semiHvMin = (float) ($threshold['semi_hv_min'] ?? 5000);
$poItemSupportsSemiType = function_exists('schema_has_column')
    ? schema_has_column($db, 'purchase_order_items', 'semi_expendable_type')
    : false;

$rStmt = $db->prepare(
    "SELECT r.system_reference, r.received_date, po.po_number, s.supplier_name
     FROM receivings r
     INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
     INNER JOIN suppliers s ON s.id = po.supplier_id
    WHERE r.id = ?
    AND r.status IN ('completed', 'partial')
     LIMIT 1"
);

$rHeader = null;
if ($rStmt) {
    $rStmt->bind_param('i', $receivingId);
    $rStmt->execute();
    $rHeader = $rStmt->get_result()->fetch_assoc();
    $rStmt->close();
}

$itemSql = "SELECT ri.id AS ri_id,
                   poi.item_description,
                   ri.unit_cost,
                   c.classification_name,
                   c.classification_family,
                   rid.id AS detail_id,
                   rid.brand,
                   rid.model,
                   rid.serial_no,
                   rid.is_disposed
            FROM receiving_items ri
                INNER JOIN receivings rcv
                    ON rcv.id = ri.receiving_id
                  AND rcv.status IN ('completed', 'partial')
            INNER JOIN purchase_order_items poi
               ON poi.id = ri.purchase_order_item_id
              AND poi.item_type = ?";
if ($itemType === 'semi_expendable') {
    if ($poItemSupportsSemiType) {
        $itemSql .= " AND poi.semi_expendable_type = ?";
    } elseif ($semiType === 'high_value') {
        $itemSql .= " AND ri.unit_cost >= ?";
    } else {
        $itemSql .= " AND ri.unit_cost < ?";
    }
}
$itemSql .= " INNER JOIN receiving_item_details rid
                 ON rid.receiving_item_id = ri.id
                AND rid.is_distributed = 0
                AND (rid.is_disposed IS NULL OR rid.is_disposed = 0)
              LEFT JOIN classifications c ON c.id = poi.classification_id
              WHERE ri.receiving_id = ?
              ORDER BY ri.id ASC, rid.id ASC";

$itemStmt = $db->prepare($itemSql);

$groups = [];
if ($itemStmt) {
    if ($itemType === 'semi_expendable') {
        if ($poItemSupportsSemiType) {
            $itemStmt->bind_param('ssi', $itemType, $semiType, $receivingId);
        } else {
            $itemStmt->bind_param('sdi', $itemType, $semiHvMin, $receivingId);
        }
    } else {
        $itemStmt->bind_param('si', $itemType, $receivingId);
    }
    $itemStmt->execute();
    $res = $itemStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $riId = (int) $row['ri_id'];
        if (!isset($groups[$riId])) {
            $groups[$riId] = [
                'receiving_item_id' => $riId,
                'description' => (string) ($row['item_description'] ?? ''),
                'classification' => (string) ($row['classification_name'] ?? ''),
                'classification_family' => (string) ($row['classification_family'] ?? ''),
                'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                'units' => [],
            ];
        }

        $groups[$riId]['units'][] = [
            'id' => (int) $row['detail_id'],
            'brand' => (string) ($row['brand'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'serial_no' => (string) ($row['serial_no'] ?? ''),
        ];
    }
    $itemStmt->close();
}

ob_start();
$groupNumber = 0;
if (!empty($groups)) {
    echo '<div class="alert alert-light border small py-2">Brand, model, and serial number are optional when unavailable.</div>';
}
foreach ($groups as $group) {
    $groupNumber++;
    $groupId = 'dist-unit-group-' . $groupNumber;
    $collapseId = $groupId . '-body';
    $groupLabel = trim((!empty($group['classification_family']) ? $group['classification_family'] . ' / ' : '') . ($group['classification'] ?: 'No classification'));
    $unitCount = count($group['units']);
    $groupTotal = (float) $group['unit_cost'] * $unitCount;
    $startExpanded = $unitCount <= 20;
    ?>
    <div class="card shadow-sm mb-3 unit-group"
         data-group-id="<?php echo (int) $group['receiving_item_id']; ?>"
         data-group-unit-count="<?php echo $unitCount; ?>"
         data-group-total="<?php echo h(number_format($groupTotal, 2, '.', '')); ?>">
        <div class="card-header bg-body py-3">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge text-bg-primary">Item Group <?php echo $groupNumber; ?></span>
                        <span class="badge text-bg-light"><?php echo $unitCount; ?> unit<?php echo $unitCount !== 1 ? 's' : ''; ?></span>
                        <span class="badge text-bg-light">Php <?php echo number_format((float) $group['unit_cost'], 2); ?> each</span>
                    </div>
                    <div class="fw-semibold"><?php echo h($groupLabel); ?></div>
                    <div class="small text-muted"><?php echo h(mb_strimwidth($group['description'], 0, 140, '...')); ?></div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label class="small mb-0">
                        <input type="checkbox"
                               class="form-check-input me-1 group-select-all"
                               data-group-target="<?php echo h($groupId); ?>">
                        Select group
                    </label>
                    <button class="btn btn-sm btn-outline-secondary"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?php echo h($collapseId); ?>"
                            aria-expanded="<?php echo $startExpanded ? 'true' : 'false'; ?>"
                            aria-controls="<?php echo h($collapseId); ?>">
                        <?php echo $startExpanded ? 'Hide units' : 'Show units'; ?>
                    </button>
                </div>
            </div>
        </div>
        <div class="collapse <?php echo $startExpanded ? 'show' : ''; ?>" id="<?php echo h($collapseId); ?>">
            <div class="card-body p-3">
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-12 col-lg-5">
                        <label class="form-label small mb-1">Apply Remarks to This Group</label>
                        <input type="text"
                               class="form-control form-control-sm group-remarks-input"
                               data-group-target="<?php echo h($groupId); ?>"
                               placeholder="Common remarks for selected units">
                    </div>
                    <div class="col-12 col-lg-2">
                        <button type="button"
                                class="btn btn-outline-secondary btn-sm w-100 apply-group-remarks-btn"
                                data-group-target="<?php echo h($groupId); ?>">
                            Apply remarks
                        </button>
                    </div>
                </div>
                <div class="row g-2">
                    <?php foreach ($group['units'] as $index => $unit): ?>
                        <?php
                        $brandModel = trim(($unit['brand'] ?? '') . ' ' . ($unit['model'] ?? ''));
                        ?>
                        <div class="col-12">
                            <div class="border rounded-3 p-2 d-flex flex-wrap align-items-center gap-2 unit-row"
                                 data-group-member="<?php echo h($groupId); ?>">
                                <div class="form-check mb-0">
                                    <input type="checkbox"
                                           class="form-check-input unit-checkbox"
                                           id="unit_<?php echo (int) $unit['id']; ?>"
                                           name="units[<?php echo (int) $unit['id']; ?>]"
                                           value="1"
                                           data-cost="<?php echo h(number_format((float) $group['unit_cost'], 2, '.', '')); ?>"
                                           data-group-id="<?php echo h($groupId); ?>">
                                    <label class="form-check-label" for="unit_<?php echo (int) $unit['id']; ?>">
                                        Unit <?php echo $index + 1; ?>
                                    </label>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="small fw-semibold"><?php echo h($brandModel ?: 'No brand / model recorded'); ?></div>
                                    <div class="small text-muted">Serial No.: <?php echo h($unit['serial_no'] ?: 'Not recorded'); ?></div>
                                </div>
                                <div style="width: 180px; max-width: 100%;">
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           name="unit_remarks[<?php echo (int) $unit['id']; ?>]"
                                           data-group-id="<?php echo h($groupId); ?>"
                                           placeholder="Unit remarks">
                                </div>
                                <div style="width: 240px; max-width: 100%;">
                                    <label class="form-label small mb-1" for="unit_photo_<?php echo (int) $unit['id']; ?>">Take / Upload Photo</label>
                                    <input type="file"
                                           class="form-control form-control-sm"
                                           id="unit_photo_<?php echo (int) $unit['id']; ?>"
                                           name="unit_photo[<?php echo (int) $unit['id']; ?>]"
                                           accept="image/*"
                                           capture="environment">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

if (empty($groups)) {
    echo '<div class="text-center text-muted py-4 small">No available units for this receiving record.</div>';
}

$html = ob_get_clean();

echo json_encode([
    'ok' => true,
    'html' => $html,
    'header' => [
        'system_reference' => $rHeader['system_reference'] ?? '',
        'po_number' => $rHeader['po_number'] ?? '',
        'supplier_name' => $rHeader['supplier_name'] ?? '',
        'received_date' => $rHeader['received_date'] ?? '',
    ],
]);
