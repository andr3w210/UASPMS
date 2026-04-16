<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Transport Officer');

$mainDb = db();
$tripDb = trip_db();
$ticketId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$page_title = 'Complete Trip';
$errors = [];
$flash = get_flash();
$ticket = null;

$form = [
    'arrival_time' => '',
    'return_departure_time' => '',
    'return_arrival_time' => '',
    'odometer_start' => '',
    'odometer_end' => '',
    'distance_traveled' => '',
    'fuel_purchased' => '0.00',
    'fuel_consumed' => '',
    'oil_used' => '0.00',
    'grease_used' => '0.00',
    'completion_remarks' => '',
];

if (!$tripDb) {
    $errors[] = 'Unable to connect to the trip ticket database. Import `database/081_trip_ticket_module.sql` first.';
} elseif ($ticketId <= 0) {
    $errors[] = 'Invalid trip ticket selected.';
} else {
    $stmt = $tripDb->prepare("SELECT * FROM trip_tickets WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$ticket) {
        $errors[] = 'Trip ticket not found.';
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $form = [
            'arrival_time' => substr((string) ($ticket['arrival_time'] ?? ''), 0, 5),
            'return_departure_time' => substr((string) ($ticket['return_departure_time'] ?? ''), 0, 5),
            'return_arrival_time' => substr((string) ($ticket['return_arrival_time'] ?? ''), 0, 5),
            'odometer_start' => $ticket['odometer_start'] !== null ? (string) $ticket['odometer_start'] : '',
            'odometer_end' => $ticket['odometer_end'] !== null ? (string) $ticket['odometer_end'] : '',
            'distance_traveled' => $ticket['distance_traveled'] !== null ? (string) $ticket['distance_traveled'] : '',
            'fuel_purchased' => $ticket['fuel_purchased'] !== null ? (string) $ticket['fuel_purchased'] : '0.00',
            'fuel_consumed' => $ticket['fuel_consumed'] !== null ? (string) $ticket['fuel_consumed'] : '',
            'oil_used' => $ticket['oil_used'] !== null ? (string) $ticket['oil_used'] : '0.00',
            'grease_used' => $ticket['grease_used'] !== null ? (string) $ticket['grease_used'] : '0.00',
            'completion_remarks' => (string) ($ticket['completion_remarks'] ?? ''),
        ];
    }
}

if ($tripDb && $ticket && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $form['arrival_time'] = old($_POST, 'arrival_time');
        $form['return_departure_time'] = old($_POST, 'return_departure_time');
        $form['return_arrival_time'] = old($_POST, 'return_arrival_time');
        $form['odometer_start'] = old($_POST, 'odometer_start');
        $form['odometer_end'] = old($_POST, 'odometer_end');
        $form['distance_traveled'] = old($_POST, 'distance_traveled');
        $form['fuel_purchased'] = old($_POST, 'fuel_purchased', '0.00');
        $form['fuel_consumed'] = old($_POST, 'fuel_consumed');
        $form['oil_used'] = old($_POST, 'oil_used', '0.00');
        $form['grease_used'] = old($_POST, 'grease_used', '0.00');
        $form['completion_remarks'] = old($_POST, 'completion_remarks');

        $odometerStart = $form['odometer_start'] !== '' ? (float) $form['odometer_start'] : null;
        $odometerEnd = $form['odometer_end'] !== '' ? (float) $form['odometer_end'] : null;
        $distanceTraveled = $form['distance_traveled'] !== '' ? (float) $form['distance_traveled'] : null;
        $fuelPurchased = $form['fuel_purchased'] !== '' ? (float) $form['fuel_purchased'] : 0.0;
        $fuelConsumed = $form['fuel_consumed'] !== '' ? (float) $form['fuel_consumed'] : null;
        $oilUsed = $form['oil_used'] !== '' ? (float) $form['oil_used'] : 0.0;
        $greaseUsed = $form['grease_used'] !== '' ? (float) $form['grease_used'] : 0.0;

        if ($odometerStart !== null && $odometerStart < 0) $errors[] = 'Beginning odometer cannot be negative.';
        if ($odometerEnd !== null && $odometerEnd < 0) $errors[] = 'Ending odometer cannot be negative.';
        if ($odometerStart !== null && $odometerEnd !== null && $odometerEnd < $odometerStart) $errors[] = 'Ending odometer cannot be lower than beginning odometer.';
        if ($distanceTraveled !== null && $distanceTraveled < 0) $errors[] = 'Distance traveled cannot be negative.';
        if ($fuelPurchased < 0) $errors[] = 'Fuel purchased cannot be negative.';
        if ($fuelConsumed !== null && $fuelConsumed < 0) $errors[] = 'Fuel consumed cannot be negative.';
        if ($oilUsed < 0) $errors[] = 'Oil used cannot be negative.';
        if ($greaseUsed < 0) $errors[] = 'Grease used cannot be negative.';

        if (!$errors) {
            if ($distanceTraveled === null && $odometerStart !== null && $odometerEnd !== null) {
                $distanceTraveled = max(0, $odometerEnd - $odometerStart);
            }

            $completedBy = current_user_id();
            $status = 'completed';
            $arrivalTime = $form['arrival_time'] !== '' ? $form['arrival_time'] : null;
            $returnDepartureTime = $form['return_departure_time'] !== '' ? $form['return_departure_time'] : null;
            $returnArrivalTime = $form['return_arrival_time'] !== '' ? $form['return_arrival_time'] : null;

            $stmt = $tripDb->prepare("
                UPDATE trip_tickets
                SET status = ?,
                    arrival_time = ?,
                    return_departure_time = ?,
                    return_arrival_time = ?,
                    odometer_start = ?,
                    odometer_end = ?,
                    distance_traveled = ?,
                    fuel_purchased = ?,
                    fuel_consumed = ?,
                    oil_used = ?,
                    grease_used = ?,
                    completion_remarks = ?,
                    completed_at = NOW(),
                    completed_by = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            if (!$stmt) {
                $errors[] = 'Unable to prepare trip completion update: ' . $tripDb->error;
            } else {
                $stmt->bind_param(
                    'ssssddddddssiii',
                    $status,
                    $arrivalTime,
                    $returnDepartureTime,
                    $returnArrivalTime,
                    $odometerStart,
                    $odometerEnd,
                    $distanceTraveled,
                    $fuelPurchased,
                    $fuelConsumed,
                    $oilUsed,
                    $greaseUsed,
                    $form['completion_remarks'],
                    $completedBy,
                    $completedBy,
                    $ticketId
                );

                if (!$stmt->execute()) {
                    $errors[] = 'Unable to complete trip: ' . $stmt->error;
                }
                $stmt->close();
            }

            if (!$errors) {
                write_audit_log($mainDb, [
                    'action' => 'update',
                    'table_name' => 'trip_tickets',
                    'record_id' => $ticketId,
                    'module_name' => 'trip_tickets',
                    'record_type' => 'trip_ticket',
                    'action_name' => 'complete_trip_ticket',
                    'description' => 'Completed trip ticket with actual trip data.',
                    'new_values' => [
                        'status' => $status,
                        'distance_traveled' => $distanceTraveled,
                        'fuel_purchased' => $fuelPurchased,
                        'fuel_consumed' => $fuelConsumed,
                        'oil_used' => $oilUsed,
                        'grease_used' => $greaseUsed,
                    ],
                ]);

                set_flash('success', 'Trip marked as completed.');
                redirect('modules/trip_tickets/view.php?id=' . $ticketId);
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Complete Trip</h4>
            <div class="text-muted">Encode actual odometer and usage details after the travel is finished.</div>
        </div>
        <a href="<?php echo base_url('modules/trip_tickets/view.php?id=' . (int) $ticketId); ?>" class="btn btn-outline-secondary">Back to Details</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <?php if ($ticket): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="mb-4">
                    <div><strong>Trip Ticket No.:</strong> <?php echo h((string) $ticket['trip_ticket_no']); ?></div>
                    <div><strong>Vehicle:</strong> <?php echo h((string) $ticket['vehicle_plate_no'] . ' - ' . (string) $ticket['vehicle_name']); ?></div>
                    <div><strong>Driver:</strong> <?php echo h((string) $ticket['driver_name']); ?></div>
                </div>

                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $ticketId; ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Arrival Time</label>
                            <input type="time" name="arrival_time" class="form-control" value="<?php echo h($form['arrival_time']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Return Departure Time</label>
                            <input type="time" name="return_departure_time" class="form-control" value="<?php echo h($form['return_departure_time']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Return Arrival Time</label>
                            <input type="time" name="return_arrival_time" class="form-control" value="<?php echo h($form['return_arrival_time']); ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Beginning Odometer</label>
                            <input type="number" step="0.01" min="0" name="odometer_start" class="form-control" value="<?php echo h($form['odometer_start']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ending Odometer</label>
                            <input type="number" step="0.01" min="0" name="odometer_end" class="form-control" value="<?php echo h($form['odometer_end']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Distance Traveled</label>
                            <input type="number" step="0.01" min="0" name="distance_traveled" class="form-control" value="<?php echo h($form['distance_traveled']); ?>">
                            <div class="form-text">Leave blank to auto-compute from odometer readings.</div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Fuel Purchased During Trip</label>
                            <input type="number" step="0.01" min="0" name="fuel_purchased" class="form-control" value="<?php echo h($form['fuel_purchased']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fuel Consumed</label>
                            <input type="number" step="0.01" min="0" name="fuel_consumed" class="form-control" value="<?php echo h($form['fuel_consumed']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Oil Used</label>
                            <input type="number" step="0.01" min="0" name="oil_used" class="form-control" value="<?php echo h($form['oil_used']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Grease Used</label>
                            <input type="number" step="0.01" min="0" name="grease_used" class="form-control" value="<?php echo h($form['grease_used']); ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Completion Remarks</label>
                            <textarea name="completion_remarks" class="form-control" rows="3"><?php echo h($form['completion_remarks']); ?></textarea>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Save Completion Details</button>
                        <a href="<?php echo base_url('modules/trip_tickets/view.php?id=' . (int) $ticketId); ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
