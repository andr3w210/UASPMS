<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_once __DIR__ . '/../../app/helpers/employees.php';
require_once __DIR__ . '/../../app/helpers/suppliers.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$recordId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$supplier = $recordId > 0 ? suppliers_fetch($db, $recordId) : null;
if (!$supplier) {
    set_flash('error', 'Supplier not found.');
    redirect('modules/suppliers/index.php');
}
$page_title = 'Supplier: ' . $supplier['supplier_name'];
$isAdministrator = current_user_role() === 'Administrator';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    request_schema_enforce([
        'toggle_active' => [
            'id' => ['type' => 'int', 'min' => 1, 'required' => true],
            'make_active' => ['type' => 'int', 'min' => 0, 'max' => 1, 'required' => true],
        ],
        'hard_delete' => [
            'id' => ['type' => 'int', 'min' => 1, 'required' => true],
        ],
    ], 'action', false, 'toggle_active');
    if (!csrf_verify()) {
        set_flash('error', 'Invalid CSRF token.');
    } elseif ($_POST['action'] === 'hard_delete') {
        if (!$isAdministrator) {
            set_flash('error', 'Only administrators can permanently delete records.');
        } elseif (suppliers_has_reference($db, $recordId)) {
            set_flash('error', 'Cannot delete: supplier is used in existing purchase orders. Deactivate it instead.');
        } elseif (suppliers_hard_delete($db, $recordId)) {
            set_flash('success', 'Supplier permanently deleted.');
            redirect('modules/suppliers/index.php');
        } else {
            set_flash('error', 'Unable to delete the supplier.');
        }
    } else {
        $makeActive = (int) $_POST['make_active'];
        $ok = suppliers_set_active($db, $recordId, $makeActive);
        set_flash($ok ? 'success' : 'error', $ok ? ($makeActive ? 'Supplier reactivated.' : 'Supplier deactivated.') : 'Unable to update the supplier.');
    }
    redirect('modules/suppliers/view.php?id=' . $recordId);
}
$flash = get_flash();

$stmt = $db->prepare("SELECT po.id, po.po_number, po.po_date, po.status, po.total_amount, po.expected_delivery_date
    FROM purchase_orders po WHERE po.supplier_id = ? ORDER BY po.po_date DESC, po.id DESC LIMIT 200");
$stmt->bind_param('i', $recordId);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$orderTotal = array_sum(array_map(static fn($o) => (float) $o['total_amount'], $orders));
$active = (int) $supplier['is_active'] === 1;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <?php echo employees_avatar_html(['first_name' => $supplier['supplier_name'], 'last_name' => '', 'photo_path' => ''], 72); ?>
                    <div>
                        <h4 class="mb-1"><?php echo h($supplier['supplier_name']); ?></h4>
                        <div class="text-muted"><?php echo h($supplier['supplier_code']); ?></div>
                        <span class="badge <?php echo $active ? 'bg-success-lt' : 'bg-secondary-lt'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?php echo base_url('modules/suppliers/form.php?id=' . $recordId); ?>" class="btn btn-primary"><i class="bi bi-pencil-square me-1"></i>Edit</a>
                    <a href="<?php echo base_url('modules/suppliers/index.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">More</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?php echo $recordId; ?>">
                                    <input type="hidden" name="make_active" value="<?php echo $active ? 0 : 1; ?>">
                                    <button type="submit" class="dropdown-item"><?php echo $active ? 'Deactivate' : 'Reactivate'; ?></button>
                                </form>
                            </li>
                            <?php if ($isAdministrator): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" class="m-0" onsubmit="return confirm('Permanently delete this supplier? This cannot be undone.');">
                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="hard_delete">
                                        <input type="hidden" name="id" value="<?php echo $recordId; ?>">
                                        <button type="submit" class="dropdown-item text-danger">Delete permanently</button>
                                    </form>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">Overview</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-orders" type="button">Purchase Orders <span class="badge bg-blue-lt"><?php echo count($orders); ?></span></button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-overview">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100"><div class="card-body">
                        <h6 class="text-uppercase text-muted small">Contact</h6>
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Contact person</dt><dd class="col-sm-8"><?php echo h($supplier['contact_person'] ?: '—'); ?></dd>
                            <dt class="col-sm-4">Number</dt><dd class="col-sm-8"><?php echo h($supplier['contact_no'] ?: '—'); ?></dd>
                            <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?php echo h($supplier['email'] ?: '—'); ?></dd>
                            <dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?php echo h($supplier['address'] ?: '—'); ?></dd>
                        </dl>
                    </div></div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100"><div class="card-body">
                        <h6 class="text-uppercase text-muted small">Business</h6>
                        <dl class="row mb-0">
                            <dt class="col-sm-4">TIN</dt><dd class="col-sm-8"><?php echo h($supplier['tin_no'] ?: '—'); ?></dd>
                            <dt class="col-sm-4">Purchase orders</dt><dd class="col-sm-8"><?php echo count($orders); ?></dd>
                            <dt class="col-sm-4">Total value</dt><dd class="col-sm-8">₱<?php echo number_format($orderTotal, 2); ?></dd>
                            <dt class="col-sm-4">Created</dt><dd class="col-sm-8"><?php echo h(date('M d, Y', strtotime((string) $supplier['created_at']))); ?> by <?php echo h($supplier['creator_name'] ?: 'System'); ?></dd>
                        </dl>
                    </div></div>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="tab-orders">
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" data-no-table-search="true">
                        <thead><tr><th>PO No.</th><th>Date</th><th>Expected delivery</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        <?php if (!$orders): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No purchase orders for this supplier.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><a href="<?php echo base_url('modules/purchase_orders/view.php?id=' . (int) $order['id']); ?>"><?php echo h($order['po_number']); ?></a></td>
                                <td><?php echo $order['po_date'] ? h(date('M d, Y', strtotime((string) $order['po_date']))) : '—'; ?></td>
                                <td><?php echo $order['expected_delivery_date'] ? h(date('M d, Y', strtotime((string) $order['expected_delivery_date']))) : '—'; ?></td>
                                <td><span class="badge bg-blue-lt"><?php echo h(ucwords(str_replace('_', ' ', (string) $order['status']))); ?></span></td>
                                <td class="text-end">₱<?php echo number_format((float) $order['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
