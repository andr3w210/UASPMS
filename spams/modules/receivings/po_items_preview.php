<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$db = db();
$poId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$hasSemiTypeColumn = $db && function_exists('schema_has_column')
    ? schema_has_column($db, 'purchase_order_items', 'semi_expendable_type')
    : false;
if (!$db || $poId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

$stmt = $db->prepare(
    "SELECT poi.id, poi.line_no, poi.item_type, " . ($hasSemiTypeColumn ? "poi.semi_expendable_type" : "NULL AS semi_expendable_type") . ", poi.item_description, poi.quantity, poi.unit_cost,
            c.classification_name,
            COALESCE(SUM(CASE WHEN r.status != 'cancelled' THEN ri.quantity_accepted ELSE 0 END), 0) AS quantity_already_received
     FROM purchase_order_items poi
     LEFT JOIN classifications c ON c.id = poi.classification_id
     LEFT JOIN receiving_items ri ON ri.purchase_order_item_id = poi.id
     LEFT JOIN receivings r ON r.id = ri.receiving_id
     WHERE poi.purchase_order_id = ?
     GROUP BY poi.id, poi.line_no, poi.item_type, " . ($hasSemiTypeColumn ? "poi.semi_expendable_type" : "semi_expendable_type") . ", poi.item_description, poi.quantity, poi.unit_cost, c.classification_name
     ORDER BY poi.line_no ASC, poi.id ASC"
);
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'Query prepare failed']);
    exit;
}

$stmt->bind_param('i', $poId);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) {
    $remaining = max(0, (float) $row['quantity'] - (float) $row['quantity_already_received']);
    $items[] = [
        'id' => (int) $row['id'],
        'line_no' => (int) $row['line_no'],
        'item_type' => $row['item_type'],
        'semi_expendable_type' => $row['semi_expendable_type'],
        'classification_name' => $row['classification_name'],
        'description' => $row['item_description'],
        'ordered' => (float) $row['quantity'],
        'received' => (float) $row['quantity_already_received'],
        'remaining' => $remaining,
        'unit_cost' => (float) $row['unit_cost'],
    ];
}
$stmt->close();

// Get simple PO header info
$poStmt = $db->prepare("SELECT po.id, po.po_number, po.po_date, s.supplier_name, f.fund_code, f.fund_name, po.status FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id INNER JOIN funds f ON f.id = po.fund_id WHERE po.id = ? LIMIT 1");
$header = null;
if ($poStmt) {
    $poStmt->bind_param('i', $poId);
    $poStmt->execute();
    $header = $poStmt->get_result()->fetch_assoc() ?: null;
    $poStmt->close();
}

if ($header) {
    $fundParts = array_filter([
        trim((string) ($header['fund_code'] ?? '')),
        trim((string) ($header['fund_name'] ?? '')),
    ], static function (string $value): bool {
        return $value !== '';
    });
    $header['supplier'] = (string) ($header['supplier_name'] ?? '');
    $header['fund'] = implode(' - ', $fundParts);
    $header['po_date'] = !empty($header['po_date']) ? date('M d, Y', strtotime((string) $header['po_date'])) : '';
}

echo json_encode(['ok' => true, 'po' => $header, 'items' => $items]);
exit;
