<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
header('Content-Type: application/json');

$db = db_connect();
$receivingId = (int)($_GET['receiving_id'] ?? 0);
$itemType = $_GET['item_type'] ?? 'equipment';
if (!in_array($itemType, ['equipment','semi_expendable'], true)) {
  echo json_encode(['ok'=>false,'html'=>'']); exit;
}
if (!$db || $receivingId <= 0) {
  echo json_encode(['ok'=>false,'html'=>'']); exit;
}

// Load receiving header
$rStmt = $db->prepare(
  "SELECT r.system_reference, r.received_date, po.po_number, s.supplier_name
   FROM receivings r
   INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
   INNER JOIN suppliers s ON s.id = po.supplier_id
   WHERE r.id = ? LIMIT 1"
);
$rHeader = null;
if ($rStmt) {
  $rStmt->bind_param('i', $receivingId);
  $rStmt->execute();
  $rHeader = $rStmt->get_result()->fetch_assoc();
  $rStmt->close();
}

// Load items with their undistributed units
$itemStmt = $db->prepare(
     "SELECT ri.id AS ri_id, poi.item_description, ri.unit_cost,
       c.classification_name,
       rid.id AS detail_id, rid.brand, rid.model, rid.serial_no, rid.is_disposed
   FROM receiving_items ri
   INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = ?
     INNER JOIN receiving_item_details rid ON rid.receiving_item_id = ri.id AND rid.is_distributed = 0 AND (rid.is_disposed IS NULL OR rid.is_disposed = 0)
   LEFT JOIN classifications c ON c.id = poi.classification_id
   WHERE ri.receiving_id = ?
   ORDER BY ri.id ASC, rid.id ASC"
);

$groups = [];
if ($itemStmt) {
  $itemStmt->bind_param('si', $itemType, $receivingId);
  $itemStmt->execute();
  $res = $itemStmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $riId = (int)$row['ri_id'];
    if (!isset($groups[$riId])) {
      $groups[$riId] = [
        'description'       => $row['item_description'],
        'classification'    => $row['classification_name'] ?? '',
        'unit_cost'         => (float)$row['unit_cost'],
        'units'             => [],
      ];
    }
    $groups[$riId]['units'][] = [
      'id'        => (int)$row['detail_id'],
      'brand'     => $row['brand'] ?? '',
      'model'     => $row['model'] ?? '',
      'serial_no' => $row['serial_no'] ?? '',
    ];
  }
  $itemStmt->close();
}

// Build HTML
ob_start();
$unitIndex = 0;
foreach ($groups as $riId => $group):
  echo '<div class="mb-3">';
  echo '<div class="small fw-semibold mb-1">';
  echo h($group['classification'] ?: 'No class') . ' — ';
  echo h(mb_strimwidth($group['description'], 0, 80, '...'));
  echo ' <span class="text-muted fw-normal">· ₱' . number_format($group['unit_cost'], 2) . ' each</span>';
  echo '</div>';
  foreach ($group['units'] as $unit):
    $unitIndex++;
    echo '<div class="d-flex align-items-center gap-2 p-2 mb-1 rounded" ';
    echo 'style="border:0.5px solid var(--bs-border-color);">';
    echo '<input type="checkbox" class="unit-checkbox" ';
    echo 'id="unit_' . $unit['id'] . '" ';
    echo 'name="units[' . $unit['id'] . ']" value="1" ';
    echo 'data-cost="' . $group['unit_cost'] . '">';
    echo '<label for="unit_' . $unit['id'] . '" ';
    echo 'style="cursor:pointer;flex:1;margin:0;">';
    echo '<span class="badge text-bg-secondary" style="font-size:9px;">Unit #' . $unitIndex . '</span> ';
    $brandModel = trim(($unit['brand'] ?? '') . ' ' . ($unit['model'] ?? ''));
    echo '<span class="fw-semibold ms-1" style="font-size:12px;">';
    echo h($brandModel ?: 'No brand/model') . '</span>';
    echo '<div class="small mt-1"><span class="text-muted">S/N:</span> ';
    echo '<strong>' . h($unit['serial_no'] ?: 'Not recorded') . '</strong></div>';
    echo '</label>';
    echo '<input type="text" class="form-control form-control-sm" ';
    echo 'name="unit_remarks[' . $unit['id'] . ']" ';
    echo 'placeholder="Remarks" style="width:120px;">';
    echo '</div>';
  endforeach;
  echo '</div>';
endforeach;
if (empty($groups)) {
  echo '<div class="text-center text-muted py-3 small">';
  echo 'No available units for this receiving record.</div>';
}
$html = ob_get_clean();

echo json_encode([
  'ok'      => true,
  'html'    => $html,
  'header'  => [
    'system_reference' => $rHeader['system_reference'] ?? '',
    'po_number'        => $rHeader['po_number'] ?? '',
    'supplier_name'    => $rHeader['supplier_name'] ?? '',
    'received_date'    => $rHeader['received_date'] ?? '',
  ],
]);
