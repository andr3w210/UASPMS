<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$officeId = isset($_GET['office_id']) ? (int) $_GET['office_id'] : 0;
$itemType = isset($_GET['item_type']) ? trim($_GET['item_type']) : '';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$rows = [];
if ($db) {
    $sql = "SELECT did.id AS detail_id, did.property_number AS property_no, COALESCE(poi.item_description, si.item_description) AS description, did.brand, did.model, did.serial_no, o.office_name, e.first_name, e.middle_name, e.last_name, e.suffix_name, d.distribution_date, poi.item_type, d.document_no AS document_no FROM distribution_item_details did INNER JOIN distribution_items di ON di.id = did.distribution_item_id INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id LEFT JOIN stock_items si ON si.id = rid.stock_item_id LEFT JOIN offices o ON o.id = d.office_id LEFT JOIN employees e ON e.id = d.employee_id WHERE 1=1";
    $params = [];
    $types = '';
    if ($officeId > 0) { $sql .= " AND d.office_id = ?"; $types .= 'i'; $params[] = $officeId; }
    if ($itemType !== '') { $sql .= " AND poi.item_type = ?"; $types .= 's'; $params[] = $itemType; }
    if ($dateFrom !== '') { $sql .= " AND d.distribution_date >= ?"; $types .= 's'; $params[] = $dateFrom; }
    if ($dateTo !== '') { $sql .= " AND d.distribution_date <= ?"; $types .= 's'; $params[] = $dateTo; }
    $sql .= " ORDER BY d.distribution_date DESC, did.id DESC";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        if ($types !== '') {
            $refs = [];
            foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
            array_unshift($refs, $types);
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
    }
}

function employee_display_name_from_row($row) {
    if (function_exists('employee_display_name')) return employee_display_name($row);
    $parts = [trim($row['first_name'] ?? ''), trim($row['middle_name'] ?? ''), trim($row['last_name'] ?? ''), trim($row['suffix_name'] ?? '')];
    return trim(implode(' ', array_filter($parts)));
}

// Load offices for filter
$offices = [];
if ($db) {
    $res = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($res) $offices = $res->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">Property Register</h5>
                    <div class="small text-muted"><?php echo count($rows); ?> record(s)</div>
                </div>

                <form method="get" class="row g-2 mb-3 align-items-end">
                    <div class="col-auto">
                        <label class="form-label mb-0">Office</label>
                        <select name="office_id" class="form-select form-select-sm">
                            <option value="">All Offices</option>
                            <?php foreach ($offices as $o): ?>
                                <option value="<?php echo (int)$o['id']; ?>" <?php echo $officeId === (int)$o['id'] ? 'selected' : ''; ?>><?php echo h($o['office_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label mb-0">Item Type</label>
                        <select name="item_type" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="equipment" <?php echo $itemType === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                            <option value="semi_expendable" <?php echo $itemType === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                            <option value="supply" <?php echo $itemType === 'supply' ? 'selected' : ''; ?>>Supply</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label mb-0">From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo h($dateFrom); ?>">
                    </div>
                    <div class="col-auto">
                        <label class="form-label mb-0">To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo h($dateTo); ?>">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-primary">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Property No.</th>
                                <th>Description</th>
                                <th>Brand / Model</th>
                                <th>Serial No.</th>
                                <th>Office</th>
                                <th>Accountable</th>
                                <th>Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo h($r['property_no']); ?></td>
                                    <td><?php echo h($r['description']); ?></td>
                                    <td><?php echo h(trim(($r['brand'] ?? '') . ' ' . ($r['model'] ?? ''))); ?></td>
                                    <td><?php echo h($r['serial_no'] ?? ''); ?></td>
                                    <td><?php echo h($r['office_name'] ?? ''); ?></td>
                                    <td><?php echo h(employee_display_name_from_row($r)); ?></td>
                                    <td><?php echo h(!empty($r['distribution_date']) ? date('M d, Y', strtotime($r['distribution_date'])) : ''); ?></td>
                                    <td class="text-end">
                                        <a href="<?php echo base_url('modules/property/scan.php?ref=' . urlencode($r['property_no'])); ?>" class="btn btn-sm btn-outline-primary me-1" target="_blank">View</a>
                                        <a href="<?php echo base_url('modules/property/tags.php?detail_id=' . (int)$r['detail_id']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank">Print Tag</a>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">No distributed items found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
