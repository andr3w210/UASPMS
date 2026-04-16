<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Transport Officer');

$mainDb = db();
$tripDb = trip_db();
$page_title = 'Trip Tickets';
$flash = get_flash();
$errors = [];
$tickets = [];

if (!$mainDb) {
    $errors[] = 'Unable to connect to the main database.';
}
if (!$tripDb) {
    $errors[] = 'Unable to connect to the trip ticket database. Import `database/081_trip_ticket_module.sql` first.';
}

if ($tripDb) {
    $sql = "
        SELECT
            t.id,
            t.trip_ticket_no,
            t.ris_no,
            t.departure_date,
            t.return_date,
            t.departure_time,
            t.vehicle_plate_no,
            t.vehicle_name,
            t.driver_name,
            t.destination,
            t.status,
            t.distance_traveled,
            t.liters_requested,
            COUNT(p.id) AS passenger_count
        FROM trip_tickets t
        LEFT JOIN trip_ticket_passengers p ON p.trip_ticket_id = t.id
        GROUP BY
            t.id,
            t.trip_ticket_no,
            t.ris_no,
            t.departure_date,
            t.return_date,
            t.departure_time,
            t.vehicle_plate_no,
            t.vehicle_name,
            t.driver_name,
            t.destination,
            t.status,
            t.distance_traveled,
            t.liters_requested
        ORDER BY t.departure_date DESC, t.departure_time DESC, t.id DESC
    ";
    $result = $tripDb->query($sql);
    if ($result) {
        $tickets = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $errors[] = 'Unable to load trip tickets.';
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Trip Tickets</h4>
            <div class="text-muted">Driver’s trip ticket with embedded fuel RIS.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo base_url('modules/trip_tickets/monthly_report.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-bar-graph me-1"></i> Monthly Report
            </a>
            <a href="<?php echo base_url('modules/trip_tickets/fuel_consumption_report.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-fuel-pump me-1"></i> Fuel Report
            </a>
            <a href="<?php echo base_url('modules/trip_tickets/schedules.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-calendar3 me-1"></i> Schedule Calendar
            </a>
            <a href="<?php echo base_url('modules/trip_tickets/vehicles.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-truck-front me-1"></i> Vehicles
            </a>
            <a href="<?php echo base_url('modules/trip_tickets/create.php'); ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> New Trip Ticket
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>">
            <?php echo h($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Trip Ticket No.</th>
                            <th>RIS No.</th>
                            <th>Travel Dates</th>
                            <th>Vehicle</th>
                            <th>Driver</th>
                            <th>Destination</th>
                            <th>Status</th>
                            <th class="text-end">Passengers</th>
                            <th class="text-end">Distance</th>
                            <th class="text-end">Fuel (L)</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$tickets): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">No trip tickets found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td><?php echo h($ticket['trip_ticket_no']); ?></td>
                                    <td><?php echo h($ticket['ris_no']); ?></td>
                                    <td>
                                        <div><?php echo h(format_date($ticket['departure_date'])); ?></div>
                                        <?php if (!empty($ticket['return_date'])): ?>
                                            <small class="text-muted d-block">to <?php echo h(format_date($ticket['return_date'])); ?></small>
                                        <?php endif; ?>
                                        <small class="text-muted"><?php echo h(date('g:i A', strtotime((string) $ticket['departure_time']))); ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo h($ticket['vehicle_plate_no']); ?></div>
                                        <small class="text-muted"><?php echo h($ticket['vehicle_name']); ?></small>
                                    </td>
                                    <td><?php echo h($ticket['driver_name']); ?></td>
                                    <td><?php echo h($ticket['destination']); ?></td>
                                    <td>
                                        <span class="badge <?php echo ($ticket['status'] ?? 'scheduled') === 'completed' ? 'text-bg-success' : (($ticket['status'] ?? 'scheduled') === 'ongoing' ? 'text-bg-warning' : 'text-bg-secondary'); ?>">
                                            <?php echo h(ucfirst((string) ($ticket['status'] ?? 'scheduled'))); ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?php echo number_format((float) ($ticket['passenger_count'] ?? 0), 0); ?></td>
                                    <td class="text-end"><?php echo $ticket['distance_traveled'] !== null ? number_format((float) $ticket['distance_traveled'], 2) : '-'; ?></td>
                                    <td class="text-end"><?php echo number_format((float) $ticket['liters_requested'], 2); ?></td>
                                    <td class="text-end">
                                        <a href="<?php echo base_url('modules/trip_tickets/create.php?id=' . (int) $ticket['id']); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                        <a href="<?php echo base_url('modules/trip_tickets/complete.php?id=' . (int) $ticket['id']); ?>" class="btn btn-sm btn-outline-success">Complete</a>
                                        <a href="<?php echo base_url('modules/trip_tickets/view.php?id=' . (int) $ticket['id']); ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        <a href="<?php echo base_url('modules/trip_tickets/print.php?id=' . (int) $ticket['id']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank">Print</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
