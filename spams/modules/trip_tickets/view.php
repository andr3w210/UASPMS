<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Transport Officer');

$tripDb = trip_db();
$page_title = 'Trip Ticket Details';
$errors = [];
$ticket = null;
$passengers = [];
$ticketId = (int) ($_GET['id'] ?? 0);

if (!$tripDb) {
    $errors[] = 'Unable to connect to the trip ticket database. Import `database/081_trip_ticket_module.sql` first.';
} elseif ($ticketId <= 0) {
    $errors[] = 'Invalid trip ticket ID.';
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
    } else {
        $passengerStmt = $tripDb->prepare("SELECT passenger_name FROM trip_ticket_passengers WHERE trip_ticket_id = ? ORDER BY sort_order ASC, id ASC");
        if ($passengerStmt) {
            $passengerStmt->bind_param('i', $ticketId);
            $passengerStmt->execute();
            $passengers = $passengerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $passengerStmt->close();
        }
    }
}

$hasFuelRis = $ticket && ((float) ($ticket['liters_requested'] ?? 0) > 0) && trim((string) ($ticket['ris_no'] ?? '')) !== '';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Trip Ticket Details</h4>
            <div class="text-muted">Review the saved trip ticket and print the forms.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo base_url('modules/trip_tickets/index.php'); ?>" class="btn btn-outline-secondary">Back to List</a>
            <?php if ($ticket): ?>
                <div class="dropdown">
                    <button class="btn btn-dark dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Actions
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?php echo base_url('modules/trip_tickets/create.php?id=' . (int) $ticket['id']); ?>">Edit</a></li>
                        <li><a class="dropdown-item" href="<?php echo base_url('modules/trip_tickets/print.php?id=' . (int) $ticket['id']); ?>" target="_blank">Print Forms</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-success" href="<?php echo base_url('modules/trip_tickets/complete.php?id=' . (int) $ticket['id']); ?>">Complete Trip</a></li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <?php if ($ticket): ?>
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Trip Ticket</h5>
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Trip Ticket No.</dt>
                            <dd class="col-sm-8"><?php echo h($ticket['trip_ticket_no']); ?></dd>
                            <dt class="col-sm-4">Status</dt>
                            <dd class="col-sm-8">
                                <span class="badge <?php echo ($ticket['status'] ?? 'scheduled') === 'completed' ? 'text-bg-success' : (($ticket['status'] ?? 'scheduled') === 'ongoing' ? 'text-bg-warning' : 'text-bg-secondary'); ?>">
                                    <?php echo h(ucfirst((string) ($ticket['status'] ?? 'scheduled'))); ?>
                                </span>
                            </dd>
                            <dt class="col-sm-4">Date</dt>
                            <dd class="col-sm-8"><?php echo h(format_date($ticket['departure_date'])); ?></dd>
                            <dt class="col-sm-4">Return Date</dt>
                            <dd class="col-sm-8">
                                <?php if (!empty($ticket['return_date'])): ?>
                                    <?php echo h(format_date((string) $ticket['return_date'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">Same day / not set</span>
                                <?php endif; ?>
                            </dd>
                            <dt class="col-sm-4">Departure Time</dt>
                            <dd class="col-sm-8"><?php echo h(date('g:i A', strtotime((string) $ticket['departure_time']))); ?></dd>
                            <dt class="col-sm-4">Driver</dt>
                            <dd class="col-sm-8"><?php echo h($ticket['driver_name']); ?></dd>
                            <dt class="col-sm-4">Vehicle</dt>
                            <dd class="col-sm-8"><?php echo h($ticket['vehicle_plate_no'] . ' - ' . $ticket['vehicle_name']); ?></dd>
                            <dt class="col-sm-4">Destination</dt>
                            <dd class="col-sm-8"><?php echo nl2br(h($ticket['destination'])); ?></dd>
                            <dt class="col-sm-4">Google Maps</dt>
                            <dd class="col-sm-8">
                                <a href="https://www.google.com/maps/search/?api=1&query=<?php echo rawurlencode(($ticket['map_latitude'] !== null && $ticket['map_longitude'] !== null) ? ((string) $ticket['map_latitude'] . ',' . (string) $ticket['map_longitude']) : (string) $ticket['destination']); ?>" target="_blank" rel="noopener">Open in Google Maps</a>
                            </dd>
                            <dt class="col-sm-4">Pinned Coordinates</dt>
                            <dd class="col-sm-8">
                                <?php if ($ticket['map_latitude'] !== null && $ticket['map_longitude'] !== null): ?>
                                    <?php echo h(number_format((float) $ticket['map_latitude'], 7) . ', ' . number_format((float) $ticket['map_longitude'], 7)); ?>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </dd>
                            <dt class="col-sm-4">Purpose</dt>
                            <dd class="col-sm-8"><?php echo nl2br(h($ticket['purpose'])); ?></dd>
                            <dt class="col-sm-4">Arrival Time</dt>
                            <dd class="col-sm-8"><?php echo !empty($ticket['arrival_time']) ? h(date('g:i A', strtotime((string) $ticket['arrival_time']))) : '<span class="text-muted">Not set</span>'; ?></dd>
                            <dt class="col-sm-4">Return Departure</dt>
                            <dd class="col-sm-8"><?php echo !empty($ticket['return_departure_time']) ? h(date('g:i A', strtotime((string) $ticket['return_departure_time']))) : '<span class="text-muted">Not set</span>'; ?></dd>
                            <dt class="col-sm-4">Return Arrival</dt>
                            <dd class="col-sm-8"><?php echo !empty($ticket['return_arrival_time']) ? h(date('g:i A', strtotime((string) $ticket['return_arrival_time']))) : '<span class="text-muted">Not set</span>'; ?></dd>
                            <dt class="col-sm-4">Beginning Odometer</dt>
                            <dd class="col-sm-8"><?php echo $ticket['odometer_start'] !== null ? h(number_format((float) $ticket['odometer_start'], 2)) : '<span class="text-muted">Not set</span>'; ?></dd>
                            <dt class="col-sm-4">Ending Odometer</dt>
                            <dd class="col-sm-8"><?php echo $ticket['odometer_end'] !== null ? h(number_format((float) $ticket['odometer_end'], 2)) : '<span class="text-muted">Not set</span>'; ?></dd>
                            <dt class="col-sm-4">Distance Traveled</dt>
                            <dd class="col-sm-8"><?php echo $ticket['distance_traveled'] !== null ? h(number_format((float) $ticket['distance_traveled'], 2) . ' km') : '<span class="text-muted">Not set</span>'; ?></dd>
                            <dt class="col-sm-4">Fuel Purchased</dt>
                            <dd class="col-sm-8"><?php echo h(number_format((float) ($ticket['fuel_purchased'] ?? 0), 2) . ' L'); ?></dd>
                            <dt class="col-sm-4">Fuel Consumed</dt>
                            <dd class="col-sm-8"><?php echo $ticket['fuel_consumed'] !== null ? h(number_format((float) $ticket['fuel_consumed'], 2) . ' L') : '<span class="text-muted">Not set</span>'; ?></dd>
                            <dt class="col-sm-4">Oil / Grease Used</dt>
                            <dd class="col-sm-8"><?php echo h(number_format((float) ($ticket['oil_used'] ?? 0), 2) . ' / ' . number_format((float) ($ticket['grease_used'] ?? 0), 2)); ?></dd>
                            <dt class="col-sm-4">Completion Remarks</dt>
                            <dd class="col-sm-8"><?php echo !empty($ticket['completion_remarks']) ? nl2br(h((string) $ticket['completion_remarks'])) : '<span class="text-muted">None</span>'; ?></dd>
                            <dt class="col-sm-4">Passengers</dt>
                            <dd class="col-sm-8">
                                <?php if (!$passengers): ?>
                                    <span class="text-muted">None</span>
                                <?php else: ?>
                                    <ol class="mb-0 ps-3">
                                        <?php foreach ($passengers as $passenger): ?>
                                            <li><?php echo h($passenger['passenger_name']); ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
            <?php if ($hasFuelRis): ?>
                <div class="col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Fuel RIS</h5>
                            <dl class="row mb-0">
                                <dt class="col-sm-5">RIS No.</dt>
                                <dd class="col-sm-7"><?php echo h($ticket['ris_no']); ?></dd>
                                <dt class="col-sm-5">Office</dt>
                                <dd class="col-sm-7"><?php echo h((string) ($ticket['office_name'] ?? '')); ?></dd>
                                <dt class="col-sm-5">RC Code</dt>
                                <dd class="col-sm-7"><?php echo h((string) ($ticket['responsibility_code'] ?? '')); ?></dd>
                                <dt class="col-sm-5">Fuel Type</dt>
                                <dd class="col-sm-7"><?php echo h($ticket['fuel_type']); ?></dd>
                                <dt class="col-sm-5">Liters</dt>
                                <dd class="col-sm-7"><?php echo number_format((float) $ticket['liters_requested'], 2); ?></dd>
                                <dt class="col-sm-5">Requested By</dt>
                                <dd class="col-sm-7"><?php echo h((string) ($ticket['requested_by_name'] ?? '')); ?></dd>
                                <dt class="col-sm-5">Approved By</dt>
                                <dd class="col-sm-7"><?php echo h((string) ($ticket['approved_by_name'] ?? '')); ?></dd>
                                <dt class="col-sm-5">Issued By</dt>
                                <dd class="col-sm-7"><?php echo h((string) ($ticket['issued_by_name'] ?? '')); ?></dd>
                                <dt class="col-sm-5">Received By</dt>
                                <dd class="col-sm-7"><?php echo h((string) ($ticket['received_by_name'] ?? '')); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Map Preview</h5>
                            <a href="https://www.google.com/maps/search/?api=1&query=<?php echo rawurlencode(($ticket['map_latitude'] !== null && $ticket['map_longitude'] !== null) ? ((string) $ticket['map_latitude'] . ',' . (string) $ticket['map_longitude']) : (string) $ticket['destination']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Open in Google Maps</a>
                        </div>
                        <div class="ratio ratio-21x9 border rounded overflow-hidden bg-light">
                            <iframe
                                src="https://www.google.com/maps?q=<?php echo rawurlencode(($ticket['map_latitude'] !== null && $ticket['map_longitude'] !== null) ? ((string) $ticket['map_latitude'] . ',' . (string) $ticket['map_longitude']) : (string) $ticket['destination']); ?>&output=embed"
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                                allowfullscreen
                            ></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
