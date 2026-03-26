<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$db = db_connect();
$poId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
if (!$db || $poId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

$stmt = $db->prepare(
    "SELECT poi.id, poi.line_no, poi.item_type, poi.item_description, poi.quantity, poi.unit_cost,
            COALESCE(SUM(CASE WHEN r.status != 'cancelled' THEN ri.quantity_accepted ELSE 0 END), 0) AS quantity_already_received
     FROM purchase_order_items poi
     LEFT JOIN receiving_items ri ON ri.purchase_order_item_id = poi.id
     LEFT JOIN receivings r ON r.id = ri.receiving_id
     WHERE poi.purchase_order_id = ?
     GROUP BY poi.id, poi.line_no, poi.item_type, poi.item_description, poi.quantity, poi.unit_cost
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

echo json_encode(['ok' => true, 'po' => $header, 'items' => $items]);
exit;
