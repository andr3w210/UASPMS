<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Transport Officer');

$mainDb = db();
$tripDb = trip_db();
$page_title = 'Trip Vehicles';
$flash = get_flash();
$errors = [];
$vehicles = [];
$form = [
    'id' => 0,
    'plate_no' => '',
    'vehicle_name' => '',
    'vehicle_type' => '',
    'fuel_type' => 'Diesel',
    'capacity_liters' => '',
    'is_active' => '1',
];

if (!$mainDb) {
    $errors[] = 'Unable to connect to the main database.';
}
if (!$tripDb) {
    $errors[] = 'Unable to connect to the trip ticket database. Import `database/081_trip_ticket_module.sql` first.';
}

if ($tripDb && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } elseif ($action === 'save') {
        $form['id'] = (int) ($_POST['id'] ?? 0);
        $form['plate_no'] = strtoupper(old($_POST, 'plate_no'));
        $form['vehicle_name'] = old($_POST, 'vehicle_name');
        $form['vehicle_type'] = old($_POST, 'vehicle_type');
        $form['fuel_type'] = old($_POST, 'fuel_type', 'Diesel');
        $form['capacity_liters'] = old($_POST, 'capacity_liters');
        $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

        if ($form['plate_no'] === '') {
            $errors[] = 'Plate number is required.';
        }
        if ($form['vehicle_name'] === '') {
            $errors[] = 'Vehicle name is required.';
        }

        $duplicateStmt = $tripDb->prepare("SELECT id FROM trip_vehicles WHERE plate_no = ? AND id != ? LIMIT 1");
        if ($duplicateStmt) {
            $duplicateStmt->bind_param('si', $form['plate_no'], $form['id']);
            $duplicateStmt->execute();
            if ($duplicateStmt->get_result()->fetch_assoc()) {
                $errors[] = 'Plate number already exists.';
            }
            $duplicateStmt->close();
        }

        if (!$errors) {
            $isActive = (int) $form['is_active'];
            $capacityLiters = $form['capacity_liters'] === '' ? null : (float) $form['capacity_liters'];
            $userId = current_user_id();

            if ($form['id'] > 0) {
                $stmt = $tripDb->prepare("
                    UPDATE trip_vehicles
                    SET plate_no = ?, vehicle_name = ?, vehicle_type = ?, fuel_type = ?, capacity_liters = ?, is_active = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                if ($stmt) {
                    $stmt->bind_param('ssssdiii', $form['plate_no'], $form['vehicle_name'], $form['vehicle_type'], $form['fuel_type'], $capacityLiters, $isActive, $userId, $form['id']);
                    $saved = $stmt->execute();
                    $stmt->close();
                    if ($saved) {
                        if ($mainDb) {
                            write_audit_log($mainDb, [
                                'action' => 'update',
                                'table_name' => 'trip_vehicles',
                                'record_id' => $form['id'],
                                'module_name' => 'trip_tickets',
                                'record_type' => 'trip_vehicle',
                                'action_name' => 'update_trip_vehicle',
                                'description' => 'Updated trip vehicle.',
                                'new_values' => $form,
                            ]);
                        }
                        set_flash('success', 'Vehicle updated successfully.');
                        redirect('modules/trip_tickets/vehicles.php');
                    }
                }
            } else {
                $stmt = $tripDb->prepare("
                    INSERT INTO trip_vehicles (plate_no, vehicle_name, vehicle_type, fuel_type, capacity_liters, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                if ($stmt) {
                    $stmt->bind_param('ssssdii', $form['plate_no'], $form['vehicle_name'], $form['vehicle_type'], $form['fuel_type'], $capacityLiters, $isActive, $userId);
                    $saved = $stmt->execute();
                    $newId = (int) $stmt->insert_id;
                    $stmt->close();
                    if ($saved) {
                        if ($mainDb) {
                            write_audit_log($mainDb, [
                                'action' => 'insert',
                                'table_name' => 'trip_vehicles',
                                'record_id' => $newId,
                                'module_name' => 'trip_tickets',
                                'record_type' => 'trip_vehicle',
                                'action_name' => 'create_trip_vehicle',
                                'description' => 'Created trip vehicle.',
                                'new_values' => $form,
                            ]);
                        }
                        set_flash('success', 'Vehicle created successfully.');
                        redirect('modules/trip_tickets/vehicles.php');
                    }
                }
            }

            $errors[] = 'Unable to save the vehicle.';
        }
    }
}

if ($tripDb && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $tripDb->prepare("SELECT id, plate_no, vehicle_name, vehicle_type, fuel_type, capacity_liters, is_active FROM trip_vehicles WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $form = [
                'id' => (int) $row['id'],
                'plate_no' => (string) $row['plate_no'],
                'vehicle_name' => (string) $row['vehicle_name'],
                'vehicle_type' => (string) ($row['vehicle_type'] ?? ''),
                'fuel_type' => (string) ($row['fuel_type'] ?? 'Diesel'),
                'capacity_liters' => $row['capacity_liters'] !== null ? (string) $row['capacity_liters'] : '',
                'is_active' => (string) (int) $row['is_active'],
            ];
        }
    }
}

if ($tripDb) {
    $result = $tripDb->query("SELECT id, plate_no, vehicle_name, vehicle_type, fuel_type, capacity_liters, is_active FROM trip_vehicles ORDER BY plate_no ASC");
    if ($result) {
        $vehicles = $result->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Trip Vehicles</h4>
            <div class="text-muted">Vehicle master for the trip ticket module.</div>
        </div>
        <a href="<?php echo base_url('modules/trip_tickets/index.php'); ?>" class="btn btn-outline-secondary">Back to Trip Tickets</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>">
            <?php echo h($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title"><?php echo $form['id'] > 0 ? 'Edit Vehicle' : 'Add Vehicle'; ?></h5>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                        <div class="mb-3">
                            <label class="form-label">Plate No.</label>
                            <input type="text" name="plate_no" class="form-control" value="<?php echo h($form['plate_no']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Vehicle Name</label>
                            <input type="text" name="vehicle_name" class="form-control" value="<?php echo h($form['vehicle_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Vehicle Type</label>
                            <input type="text" name="vehicle_type" class="form-control" value="<?php echo h($form['vehicle_type']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Fuel Type</label>
                            <select name="fuel_type" class="form-select">
                                <?php foreach (['Diesel', 'Gasoline', 'Other'] as $fuelType): ?>
                                    <option value="<?php echo h($fuelType); ?>" <?php echo $form['fuel_type'] === $fuelType ? 'selected' : ''; ?>><?php echo h($fuelType); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Capacity (Liters)</label>
                            <input type="number" step="0.01" min="0" name="capacity_liters" class="form-control" value="<?php echo h($form['capacity_liters']); ?>">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="vehicle-active" name="is_active" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="vehicle-active">Active</label>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save Vehicle</button>
                            <a href="<?php echo base_url('modules/trip_tickets/vehicles.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Vehicle List</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Plate No.</th>
                                    <th>Vehicle</th>
                                    <th>Type</th>
                                    <th>Fuel</th>
                                    <th class="text-end">Capacity</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$vehicles): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">No vehicles found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <tr>
                                            <td><?php echo h($vehicle['plate_no']); ?></td>
                                            <td><?php echo h($vehicle['vehicle_name']); ?></td>
                                            <td><?php echo h($vehicle['vehicle_type'] ?? ''); ?></td>
                                            <td><?php echo h($vehicle['fuel_type']); ?></td>
                                            <td class="text-end"><?php echo $vehicle['capacity_liters'] !== null ? number_format((float) $vehicle['capacity_liters'], 2) : ''; ?></td>
                                            <td><span class="badge bg-<?php echo (int) $vehicle['is_active'] === 1 ? 'success' : 'secondary'; ?>"><?php echo (int) $vehicle['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                            <td class="text-end"><a href="<?php echo base_url('modules/trip_tickets/vehicles.php?edit=' . (int) $vehicle['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
