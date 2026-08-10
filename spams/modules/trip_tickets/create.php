<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Transport Officer');

$mainDb = db();
$tripDb = trip_db();
$ticketId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $ticketId > 0;
$page_title = $isEdit ? 'Edit Trip Ticket' : 'New Trip Ticket';
$flash = get_flash();
$errors = [];
$employees = [];
$signatoryEmployees = [];
$vehicles = [];
$ticket = null;
$currentUser = $mainDb ? trip_ticket_current_user_context($mainDb) : null;
$signatoryTitleMap = [];
$currentRole = function_exists('current_user_role') ? current_user_role() : trim((string) ($_SESSION['user_role'] ?? ($_SESSION['role_name'] ?? '')));
$isAdministrator = $currentRole === 'Administrator';
$form = [
    'id' => 0,
    'departure_date' => date('Y-m-d'),
    'return_date' => '',
    'departure_time' => date('H:i'),
    'vehicle_id' => '',
    'driver_employee_id' => '',
    'destination' => '',
    'map_latitude' => '',
    'map_longitude' => '',
    'use_map_picker' => '0',
    'purpose' => '',
    'liters_requested' => '',
    'requested_by_name' => '',
    'requested_by_title' => '',
    'approved_by_name' => '',
    'approved_by_title' => '',
    'issued_by_name' => $currentUser['name'] ?? '',
    'issued_by_title' => $currentUser['position_title'] ?? '',
    'received_by_name' => '',
    'received_by_title' => '',
    'remarks' => '',
];
$passengerRows = [['passenger_name' => '']];

if (!function_exists('trip_ticket_signatory_options_html')) {
    function trip_ticket_signatory_options_html(array $employees, string $selectedName = ''): string
    {
        $html = '<option value="">Select employee</option>';
        $normalizedSelected = mb_strtolower(trim($selectedName));
        $matched = false;

        foreach ($employees as $employee) {
            $name = employee_display_name($employee);
            $title = (string) ($employee['position_title'] ?? '');
            $isSelected = $normalizedSelected !== '' && mb_strtolower(trim($name)) === $normalizedSelected;
            if ($isSelected) {
                $matched = true;
            }

            $html .= '<option value="' . h($name) . '" data-title="' . h($title) . '"' . ($isSelected ? ' selected' : '') . '>'
                . h($name . ($title !== '' ? ' - ' . $title : ''))
                . '</option>';
        }

        if (!$matched && trim($selectedName) !== '') {
            $html .= '<option value="' . h($selectedName) . '" selected>' . h($selectedName) . '</option>';
        }

        return $html;
    }
}

if (!$mainDb) {
    $errors[] = 'Unable to connect to the main database.';
}
if (!$tripDb) {
    $errors[] = 'Unable to connect to the trip ticket database. Import `database/081_trip_ticket_module.sql` first.';
}

if ($mainDb) {
    $driverFilter = schema_has_column($mainDb, 'employees', 'is_driver') ? "AND is_driver = 1" : "";
    $employeeResult = $mainDb->query("
        SELECT id, employee_no, first_name, middle_name, last_name, suffix_name, position_title
        FROM employees
        WHERE is_active = 1 {$driverFilter}
        ORDER BY last_name ASC, first_name ASC
    ");
    if ($employeeResult) {
        $employees = $employeeResult->fetch_all(MYSQLI_ASSOC);
    }

    $signatoryResult = $mainDb->query("
        SELECT id, employee_no, first_name, middle_name, last_name, suffix_name, position_title
        FROM employees
        WHERE is_active = 1
        ORDER BY last_name ASC, first_name ASC
    ");
    if ($signatoryResult) {
        $signatoryEmployees = $signatoryResult->fetch_all(MYSQLI_ASSOC);
        foreach ($signatoryEmployees as $signatoryEmployee) {
            $signatoryTitleMap[mb_strtolower(trim(employee_display_name($signatoryEmployee)))] = (string) ($signatoryEmployee['position_title'] ?? '');
        }
    }
}

if ($tripDb) {
    $vehicleResult = $tripDb->query("
        SELECT id, plate_no, vehicle_name, vehicle_type, fuel_type, capacity_liters
        FROM trip_vehicles
        WHERE is_active = 1
        ORDER BY plate_no ASC
    ");
    if ($vehicleResult) {
        $vehicles = $vehicleResult->fetch_all(MYSQLI_ASSOC);
    }
}

if ($tripDb && $isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
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
        $form = [
            'id' => (int) $ticket['id'],
            'departure_date' => (string) $ticket['departure_date'],
            'return_date' => (string) ($ticket['return_date'] ?? ''),
            'departure_time' => substr((string) $ticket['departure_time'], 0, 5),
            'vehicle_id' => (string) $ticket['vehicle_id'],
            'driver_employee_id' => (string) $ticket['driver_employee_id'],
            'destination' => (string) $ticket['destination'],
            'map_latitude' => $ticket['map_latitude'] !== null ? (string) $ticket['map_latitude'] : '',
            'map_longitude' => $ticket['map_longitude'] !== null ? (string) $ticket['map_longitude'] : '',
            'use_map_picker' => ($ticket['map_latitude'] !== null && $ticket['map_longitude'] !== null) ? '1' : '0',
            'purpose' => (string) $ticket['purpose'],
            'liters_requested' => (string) $ticket['liters_requested'],
            'requested_by_name' => (string) ($ticket['requested_by_name'] ?? ''),
            'requested_by_title' => (string) ($ticket['requested_by_title'] ?? ''),
            'approved_by_name' => (string) ($ticket['approved_by_name'] ?? ''),
            'approved_by_title' => (string) ($ticket['approved_by_title'] ?? ''),
            'issued_by_name' => (string) ($ticket['issued_by_name'] ?? ''),
            'issued_by_title' => (string) ($ticket['issued_by_title'] ?? ''),
            'received_by_name' => (string) ($ticket['received_by_name'] ?? ''),
            'received_by_title' => (string) ($ticket['received_by_title'] ?? ''),
            'remarks' => (string) ($ticket['remarks'] ?? ''),
        ];

        $passengerStmt = $tripDb->prepare("SELECT passenger_name FROM trip_ticket_passengers WHERE trip_ticket_id = ? ORDER BY sort_order ASC, id ASC");
        if ($passengerStmt) {
            $passengerStmt->bind_param('i', $ticketId);
            $passengerStmt->execute();
            $rows = $passengerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $passengerStmt->close();
            if ($rows) {
                $passengerRows = $rows;
            }
        }
    }
}

if ($tripDb && $mainDb && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = trim((string) ($_POST['action'] ?? 'save'));
        $ticketId = (int) ($_POST['id'] ?? 0);
        $isEdit = $ticketId > 0;
        $page_title = $isEdit ? 'Edit Trip Ticket' : 'New Trip Ticket';
        $form['id'] = $ticketId;

        if ($action === 'delete') {
            if (!$isAdministrator) {
                $errors[] = 'Only administrators can delete trip tickets.';
            } elseif ($ticketId <= 0) {
                $errors[] = 'Invalid trip ticket selected for deletion.';
            } else {
                $existingStmt = $tripDb->prepare("SELECT id, trip_ticket_no, ris_no FROM trip_tickets WHERE id = ? LIMIT 1");
                if ($existingStmt) {
                    $existingStmt->bind_param('i', $ticketId);
                    $existingStmt->execute();
                    $ticket = $existingStmt->get_result()->fetch_assoc();
                    $existingStmt->close();
                }

                if (!$ticket) {
                    $errors[] = 'Trip ticket not found.';
                } else {
                    $tripDb->begin_transaction();
                    try {
                        $deletePassengersStmt = $tripDb->prepare("DELETE FROM trip_ticket_passengers WHERE trip_ticket_id = ?");
                        if (!$deletePassengersStmt) {
                            throw new RuntimeException('Unable to prepare passenger delete: ' . $tripDb->error);
                        }
                        $deletePassengersStmt->bind_param('i', $ticketId);
                        if (!$deletePassengersStmt->execute()) {
                            throw new RuntimeException('Unable to delete passenger rows: ' . $deletePassengersStmt->error);
                        }
                        $deletePassengersStmt->close();

                        $deleteTicketStmt = $tripDb->prepare("DELETE FROM trip_tickets WHERE id = ? LIMIT 1");
                        if (!$deleteTicketStmt) {
                            throw new RuntimeException('Unable to prepare trip ticket delete: ' . $tripDb->error);
                        }
                        $deleteTicketStmt->bind_param('i', $ticketId);
                        if (!$deleteTicketStmt->execute()) {
                            throw new RuntimeException('Unable to delete trip ticket: ' . $deleteTicketStmt->error);
                        }
                        $deleteTicketStmt->close();

                        $tripDb->commit();

                        write_audit_log($mainDb, [
                            'action' => 'delete',
                            'table_name' => 'trip_tickets',
                            'record_id' => $ticketId,
                            'module_name' => 'trip_tickets',
                            'record_type' => 'trip_ticket',
                            'action_name' => 'delete_trip_ticket',
                            'description' => 'Deleted trip ticket.',
                            'old_values' => [
                                'trip_ticket_no' => (string) ($ticket['trip_ticket_no'] ?? ''),
                                'ris_no' => (string) ($ticket['ris_no'] ?? ''),
                            ],
                        ]);

                        set_flash('success', 'Trip ticket deleted successfully.');
                        redirect('modules/trip_tickets/index.php');
                    } catch (Throwable $e) {
                        $tripDb->rollback();
                        $errors[] = 'Unable to delete the trip ticket: ' . $e->getMessage();
                    }
                }
            }
        }

        if ($action !== 'save') {
            goto render_trip_ticket_form;
        }

        $form['departure_date'] = old($_POST, 'departure_date', date('Y-m-d'));
        $form['return_date'] = old($_POST, 'return_date');
        $form['departure_time'] = old($_POST, 'departure_time', date('H:i'));
        $form['vehicle_id'] = old($_POST, 'vehicle_id');
        $form['driver_employee_id'] = old($_POST, 'driver_employee_id');
        $form['destination'] = old($_POST, 'destination');
        $form['map_latitude'] = old($_POST, 'map_latitude');
        $form['map_longitude'] = old($_POST, 'map_longitude');
        $form['use_map_picker'] = isset($_POST['use_map_picker']) ? '1' : '0';
        $form['purpose'] = old($_POST, 'purpose');
        $form['liters_requested'] = old($_POST, 'liters_requested');
        $form['requested_by_name'] = old($_POST, 'requested_by_name');
        $form['requested_by_title'] = old($_POST, 'requested_by_title');
        $form['approved_by_name'] = old($_POST, 'approved_by_name');
        $form['approved_by_title'] = old($_POST, 'approved_by_title');
        $form['issued_by_name'] = old($_POST, 'issued_by_name', $form['issued_by_name']);
        $form['issued_by_title'] = old($_POST, 'issued_by_title', $form['issued_by_title']);
        $form['received_by_name'] = old($_POST, 'received_by_name');
        $form['received_by_title'] = old($_POST, 'received_by_title');
        $form['remarks'] = old($_POST, 'remarks');
        $passengerRows = is_array($_POST['passengers'] ?? null) ? $_POST['passengers'] : [['passenger_name' => '']];

        $resolveSignatoryTitle = static function (string $name, string $fallback = '') use ($signatoryTitleMap): string {
            $key = mb_strtolower(trim($name));
            if ($key === '') {
                return $fallback;
            }
            return $signatoryTitleMap[$key] ?? $fallback;
        };

        $form['requested_by_title'] = $resolveSignatoryTitle($form['requested_by_name'], $form['requested_by_title']);
        $form['approved_by_title'] = $resolveSignatoryTitle($form['approved_by_name'], $form['approved_by_title']);
        $form['issued_by_title'] = $resolveSignatoryTitle($form['issued_by_name'], $form['issued_by_title']);
        $form['received_by_title'] = $resolveSignatoryTitle($form['received_by_name'], $form['received_by_title']);

        if ($isEdit) {
            $existingStmt = $tripDb->prepare("SELECT * FROM trip_tickets WHERE id = ? LIMIT 1");
            if ($existingStmt) {
                $existingStmt->bind_param('i', $ticketId);
                $existingStmt->execute();
                $ticket = $existingStmt->get_result()->fetch_assoc();
                $existingStmt->close();
            }
            if (!$ticket) {
                $errors[] = 'Trip ticket not found.';
            }
        }

        if ($form['departure_date'] === '') $errors[] = 'Departure date is required.';
        if ($form['return_date'] !== '' && $form['return_date'] < $form['departure_date']) $errors[] = 'Return date cannot be earlier than departure date.';
        if ($form['departure_time'] === '') $errors[] = 'Departure time is required.';
        if ($form['vehicle_id'] === '') $errors[] = 'Vehicle is required.';
        if ($form['driver_employee_id'] === '') $errors[] = 'Driver is required.';
        if ($form['destination'] === '') $errors[] = 'Destination is required.';
        if ($form['purpose'] === '') $errors[] = 'Purpose is required.';
        if ($form['liters_requested'] !== '' && (float) $form['liters_requested'] < 0) $errors[] = 'Fuel liters cannot be negative.';

        $driver = $mainDb ? trip_ticket_employee_context($mainDb, (int) $form['driver_employee_id']) : null;
        if (!$driver) {
            $errors[] = 'Selected driver is invalid.';
        }

        $selectedVehicle = null;
        foreach ($vehicles as $vehicle) {
            if ((int) $vehicle['id'] === (int) $form['vehicle_id']) {
                $selectedVehicle = $vehicle;
                break;
            }
        }
        if (!$selectedVehicle) {
            $errors[] = 'Selected vehicle is invalid.';
        }

        $passengers = trip_ticket_format_passengers($passengerRows);

        if (!$errors) {
            $tripTicketNo = $isEdit ? (string) $ticket['trip_ticket_no'] : trip_ticket_next_number($tripDb, $form['departure_date']);
            $userId = current_user_id();
            $vehicleId = (int) $selectedVehicle['id'];
            $driverEmployeeId = (int) $driver['employee_id'];
            $officeId = $driver['office_id'] > 0 ? $driver['office_id'] : null;
            $responsibilityCodeId = $driver['responsibility_code_id'] > 0 ? $driver['responsibility_code_id'] : null;
            $litersRequested = $form['liters_requested'] !== '' ? (float) $form['liters_requested'] : 0.0;
            $currentRisNo = $isEdit ? trim((string) ($ticket['ris_no'] ?? '')) : '';
            $risNo = $litersRequested > 0
                ? ($isEdit && $currentRisNo !== '' ? $currentRisNo : trip_ris_next_number($tripDb, $form['departure_date']))
                : null;
            $returnDate = $form['return_date'] !== '' ? $form['return_date'] : null;
            $mapLatitude = $form['map_latitude'] !== '' ? (float) $form['map_latitude'] : null;
            $mapLongitude = $form['map_longitude'] !== '' ? (float) $form['map_longitude'] : null;

            $tripDb->begin_transaction();
            try {
                if ($isEdit) {
                    $stmt = $tripDb->prepare("
                        UPDATE trip_tickets
                        SET departure_date = ?, return_date = ?, departure_time = ?,
                            vehicle_id = ?, vehicle_plate_no = ?, vehicle_name = ?, vehicle_type = ?, fuel_type = ?,
                            driver_employee_id = ?, driver_name = ?, driver_position_title = ?,
                            office_id = ?, office_name = ?, responsibility_code_id = ?, responsibility_code = ?,
                            destination = ?, map_latitude = ?, map_longitude = ?, purpose = ?, liters_requested = ?,
                            approved_by_name = ?, approved_by_title = ?,
                            issued_by_name = ?, issued_by_title = ?,
                            requested_by_name = ?, requested_by_title = ?,
                            received_by_name = ?, received_by_title = ?,
                            remarks = ?, updated_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");

                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare trip ticket update: ' . $tripDb->error);
                    }

                    $stmt->bind_param(
                        'sssissssissisissddsdsssssssssii',
                        $form['departure_date'],
                        $returnDate,
                        $form['departure_time'],
                        $vehicleId,
                        $selectedVehicle['plate_no'],
                        $selectedVehicle['vehicle_name'],
                        $selectedVehicle['vehicle_type'],
                        $selectedVehicle['fuel_type'],
                        $driverEmployeeId,
                        $driver['name'],
                        $driver['position_title'],
                        $officeId,
                        $driver['office_name'],
                        $responsibilityCodeId,
                        $driver['responsibility_code'],
                        $form['destination'],
                        $mapLatitude,
                        $mapLongitude,
                        $form['purpose'],
                        $litersRequested,
                        $form['approved_by_name'],
                        $form['approved_by_title'],
                        $form['issued_by_name'],
                        $form['issued_by_title'],
                        $form['requested_by_name'],
                        $form['requested_by_title'],
                        $form['received_by_name'],
                        $form['received_by_title'],
                        $form['remarks'],
                        $userId,
                        $ticketId
                    );

                    if (!$stmt->execute()) {
                        throw new RuntimeException('Unable to save trip ticket changes: ' . $stmt->error);
                    }
                    $stmt->close();

                    $deleteStmt = $tripDb->prepare("DELETE FROM trip_ticket_passengers WHERE trip_ticket_id = ?");
                    if (!$deleteStmt) {
                        throw new RuntimeException('Unable to prepare passenger reset: ' . $tripDb->error);
                    }
                    $deleteStmt->bind_param('i', $ticketId);
                    if (!$deleteStmt->execute()) {
                        throw new RuntimeException('Unable to reset passenger rows: ' . $deleteStmt->error);
                    }
                    $deleteStmt->close();
                } else {
                    $stmt = $tripDb->prepare("
                        INSERT INTO trip_tickets (
                            trip_ticket_no, ris_no, departure_date, return_date, departure_time,
                            vehicle_id, vehicle_plate_no, vehicle_name, vehicle_type, fuel_type,
                            driver_employee_id, driver_name, driver_position_title,
                            office_id, office_name, responsibility_code_id, responsibility_code,
                            destination, map_latitude, map_longitude, purpose, liters_requested,
                            approved_by_name, approved_by_title,
                            issued_by_name, issued_by_title,
                            requested_by_name, requested_by_title,
                            received_by_name, received_by_title,
                            remarks, created_by
                        ) VALUES (
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?, ?, ?,
                            ?, ?,
                            ?, ?,
                            ?, ?,
                            ?, ?,
                            ?, ?
                        )
                    ");

                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare trip ticket insert: ' . $tripDb->error);
                    }

                    $stmt->bind_param(
                        'sssssissssissisissddsdsssssssssi',
                        $tripTicketNo,
                        $risNo,
                        $form['departure_date'],
                        $returnDate,
                        $form['departure_time'],
                        $vehicleId,
                        $selectedVehicle['plate_no'],
                        $selectedVehicle['vehicle_name'],
                        $selectedVehicle['vehicle_type'],
                        $selectedVehicle['fuel_type'],
                        $driverEmployeeId,
                        $driver['name'],
                        $driver['position_title'],
                        $officeId,
                        $driver['office_name'],
                        $responsibilityCodeId,
                        $driver['responsibility_code'],
                        $form['destination'],
                        $mapLatitude,
                        $mapLongitude,
                        $form['purpose'],
                        $litersRequested,
                        $form['approved_by_name'],
                        $form['approved_by_title'],
                        $form['issued_by_name'],
                        $form['issued_by_title'],
                        $form['requested_by_name'],
                        $form['requested_by_title'],
                        $form['received_by_name'],
                        $form['received_by_title'],
                        $form['remarks'],
                        $userId
                    );

                    if (!$stmt->execute()) {
                        throw new RuntimeException('Unable to save trip ticket header: ' . $stmt->error);
                    }

                    $ticketId = (int) $stmt->insert_id;
                    $stmt->close();
                }

                if ($passengers) {
                    $passengerStmt = $tripDb->prepare("INSERT INTO trip_ticket_passengers (trip_ticket_id, passenger_name, sort_order) VALUES (?, ?, ?)");
                    if (!$passengerStmt) {
                        throw new RuntimeException('Unable to prepare passenger insert: ' . $tripDb->error);
                    }
                    foreach ($passengers as $passenger) {
                        $sortOrder = (int) $passenger['sort_order'];
                        $passengerName = $passenger['passenger_name'];
                        $passengerStmt->bind_param('isi', $ticketId, $passengerName, $sortOrder);
                        if (!$passengerStmt->execute()) {
                            throw new RuntimeException('Unable to save passenger row: ' . $passengerStmt->error);
                        }
                    }
                    $passengerStmt->close();
                }

                $tripDb->commit();

                write_audit_log($mainDb, [
                    'action' => $isEdit ? 'update' : 'insert',
                    'table_name' => 'trip_tickets',
                    'record_id' => $ticketId,
                    'module_name' => 'trip_tickets',
                    'record_type' => 'trip_ticket',
                    'action_name' => $isEdit ? 'update_trip_ticket' : 'create_trip_ticket',
                    'description' => $isEdit ? 'Updated trip ticket.' : 'Created trip ticket.',
                    'new_values' => [
                        'trip_ticket_no' => $tripTicketNo,
                        'ris_no' => $risNo,
                        'departure_date' => $form['departure_date'],
                        'return_date' => $form['return_date'],
                        'departure_time' => $form['departure_time'],
                        'vehicle_plate_no' => $selectedVehicle['plate_no'],
                        'driver_name' => $driver['name'],
                        'destination' => $form['destination'],
                        'map_latitude' => $form['map_latitude'],
                        'map_longitude' => $form['map_longitude'],
                        'purpose' => $form['purpose'],
                        'liters_requested' => $litersRequested,
                        'requested_by_name' => $form['requested_by_name'],
                        'received_by_name' => $form['received_by_name'],
                        'passenger_count' => count($passengers),
                    ],
                ]);

                set_flash('success', $isEdit ? 'Trip ticket updated successfully.' : 'Trip ticket created successfully.');
                redirect('modules/trip_tickets/view.php?id=' . $ticketId);
            } catch (Throwable $e) {
                $tripDb->rollback();
                $errors[] = 'Unable to save the trip ticket: ' . $e->getMessage();
            }
        }
    }
}

render_trip_ticket_form:
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><?php echo $isEdit ? 'Edit Trip Ticket' : 'New Trip Ticket'; ?></h4>
            <div class="text-muted">Phase 1 captures the trip ticket and its fuel RIS in one entry.</div>
        </div>
        <a href="<?php echo base_url('modules/trip_tickets/index.php'); ?>" class="btn btn-outline-secondary">Back to List</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (!$vehicles): ?>
                <div class="alert alert-warning">No active vehicles found. Add vehicles first in <a href="<?php echo base_url('modules/trip_tickets/vehicles.php'); ?>">Trip Vehicles</a>.</div>
            <?php endif; ?>
            <form method="post" id="trip-ticket-form" data-submit-loading="1">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                <div id="tripTicketRequiredSummary" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Departure Date</label>
                        <input type="date" id="departure_date" name="departure_date" class="form-control" value="<?php echo h($form['departure_date']); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Return Date <span class="text-muted">(Optional)</span></label>
                        <input type="date" id="return_date" name="return_date" class="form-control" value="<?php echo h($form['return_date']); ?>" min="<?php echo h($form['departure_date']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Departure Time</label>
                        <input type="time" id="departure_time" name="departure_time" class="form-control" value="<?php echo h($form['departure_time']); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Vehicle Plate Number</label>
                        <select name="vehicle_id" id="vehicle_id" class="form-select" required>
                            <option value="">Select vehicle</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo (int) $vehicle['id']; ?>" <?php echo (int) $form['vehicle_id'] === (int) $vehicle['id'] ? 'selected' : ''; ?>><?php echo h($vehicle['plate_no'] . ' - ' . $vehicle['vehicle_name'] . ' (' . $vehicle['fuel_type'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Driver</label>
                        <select name="driver_employee_id" id="driver_employee_id" class="form-select" required>
                            <option value="">Select driver</option>
                            <?php foreach ($employees as $employee): ?>
                                <option
                                    value="<?php echo (int) $employee['id']; ?>"
                                    data-driver-name="<?php echo h(employee_display_name($employee)); ?>"
                                    data-driver-title="<?php echo h((string) ($employee['position_title'] ?? '')); ?>"
                                    <?php echo (int) $form['driver_employee_id'] === (int) $employee['id'] ? 'selected' : ''; ?>
                                ><?php echo h(employee_display_name($employee) . ' - ' . ((string) ($employee['position_title'] ?? '') !== '' ? $employee['position_title'] : 'Employee')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Fuel Requested (Liters)</label>
                        <input type="number" step="0.01" min="0" name="liters_requested" id="liters_requested" class="form-control" value="<?php echo h($form['liters_requested']); ?>">
                        <div class="form-text">Leave blank or set to 0 if this trip does not need a fuel RIS.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Place to Visit / Location to Travel</label>
                        <textarea name="destination" id="destination" class="form-control" rows="2" required><?php echo h($form['destination']); ?></textarea>
                        <div class="form-text">Keep the readable destination text here. This remains the printed source of truth.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="use_map_picker" name="use_map_picker" value="1" <?php echo $form['use_map_picker'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="use_map_picker">Use map picker for exact location</label>
                        </div>
                        <div class="form-text">Leave this off if users only need to encode the destination text.</div>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="search-destination-map">Open Destination in Google Maps</button>
                    </div>
                    <div id="map-picker-panel" class="col-12 <?php echo $form['use_map_picker'] === '1' ? '' : 'd-none'; ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Pinned Latitude</label>
                                <input type="text" name="map_latitude" id="map_latitude" class="form-control" value="<?php echo h($form['map_latitude']); ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Pinned Longitude</label>
                                <input type="text" name="map_longitude" id="map_longitude" class="form-control" value="<?php echo h($form['map_longitude']); ?>" readonly>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Map Preview</label>
                        <div class="d-flex gap-2 mb-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="use-current-location">Use Current Location</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="clear-map-pin">Clear Pin</button>
                        </div>
                        <div id="leaflet-map" class="border rounded mb-2" style="height: 240px;"></div>
                        <div class="form-text">Click the map to pin the exact point, or use current location. If map picker is off, users can just encode the destination text.</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Purpose of Travel</label>
                        <textarea name="purpose" id="purpose" class="form-control" rows="3" required><?php echo h($form['purpose']); ?></textarea>
                    </div>
                </div>

                <hr class="my-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h5 class="mb-1">Passengers</h5>
                        <div class="text-muted small">Add one row per passenger. You can choose from the employee list or type a name manually.</div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-passenger-row"><i class="bi bi-plus-circle me-1"></i> Add Passenger</button>
                </div>
                <datalist id="passenger_employee_list">
                    <?php foreach ($signatoryEmployees as $employee): ?>
                        <option value="<?php echo h(employee_display_name($employee)); ?>">
                            <?php echo h((string) ($employee['position_title'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </datalist>
                <div id="passenger-rows" class="d-flex flex-column gap-2 mb-4">
                    <?php foreach ($passengerRows as $index => $passengerRow): ?>
                        <div class="input-group passenger-row">
                            <span class="input-group-text"><?php echo (int) $index + 1; ?></span>
                            <input type="text" name="passengers[<?php echo (int) $index; ?>][passenger_name]" class="form-control" list="passenger_employee_list" value="<?php echo h((string) ($passengerRow['passenger_name'] ?? '')); ?>" placeholder="Type passenger name or choose from employee list">
                            <button type="button" class="btn btn-outline-danger remove-passenger-row">Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr class="my-4">
                <div class="row g-3" id="ris-signatories-section">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1">RIS Signatories</h5>
                                <div class="text-muted small">This appears only when fuel liters is greater than zero.</div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="sync-driver-people">
                                Use driver for RIS requested by and received by
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">RIS Requested By</label>
                        <select name="requested_by_name" id="requested_by_name" class="form-select" data-placeholder="Select employee" aria-describedby="ris-signatory-title-help">
                            <?php echo trip_ticket_signatory_options_html($signatoryEmployees, $form['requested_by_name']); ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">RIS Received By</label>
                        <select name="received_by_name" id="received_by_name" class="form-select" data-placeholder="Select employee" aria-describedby="ris-signatory-title-help">
                            <?php echo trip_ticket_signatory_options_html($signatoryEmployees, $form['received_by_name']); ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Approved By</label>
                        <select name="approved_by_name" id="approved_by_name" class="form-select" data-placeholder="Select employee" aria-describedby="ris-signatory-title-help">
                            <?php echo trip_ticket_signatory_options_html($signatoryEmployees, $form['approved_by_name']); ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Issued By</label>
                        <select name="issued_by_name" id="issued_by_name" class="form-select" data-placeholder="Select employee" aria-describedby="ris-signatory-title-help">
                            <?php echo trip_ticket_signatory_options_html($signatoryEmployees, $form['issued_by_name']); ?>
                        </select>
                    </div>
                    <input type="hidden" name="requested_by_title" id="requested_by_title" value="<?php echo h($form['requested_by_title']); ?>">
                    <input type="hidden" name="approved_by_title" id="approved_by_title" value="<?php echo h($form['approved_by_title']); ?>">
                    <input type="hidden" name="issued_by_title" id="issued_by_title" value="<?php echo h($form['issued_by_title']); ?>">
                    <input type="hidden" name="received_by_title" id="received_by_title" value="<?php echo h($form['received_by_title']); ?>">
                    <div class="col-12">
                        <div id="ris-signatory-title-help" class="form-text">Signatory titles are auto-filled from the selected employee records.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2"><?php echo h($form['remarks']); ?></textarea>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary" <?php echo !$vehicles ? 'disabled' : ''; ?>><?php echo $isEdit ? 'Update Trip Ticket' : 'Save Trip Ticket'; ?></button>
                    <?php if ($isEdit && $isAdministrator): ?>
                        <button
                            type="submit"
                            class="btn btn-outline-danger"
                            formnovalidate
                            onclick="this.form.action.value='delete'; return confirm('Delete this trip ticket? This cannot be undone.');"
                        >
                            Delete
                        </button>
                    <?php endif; ?>
                    <?php if ($isEdit): ?>
                        <a href="<?php echo base_url('modules/trip_tickets/view.php?id=' . $ticketId); ?>" class="btn btn-outline-secondary">Cancel</a>
                    <?php else: ?>
                        <a href="<?php echo base_url('modules/trip_tickets/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</section>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tripTicketForm = document.getElementById('trip-ticket-form');
    const tripTicketRequiredSummary = document.getElementById('tripTicketRequiredSummary');
    const rowsContainer = document.getElementById('passenger-rows');
    const addButton = document.getElementById('add-passenger-row');
    const departureDateField = document.querySelector('input[name="departure_date"]');
    const returnDateField = document.querySelector('input[name="return_date"]');
    const driverField = document.getElementById('driver_employee_id');
    const risSignatoriesSection = document.getElementById('ris-signatories-section');
    const requestedByNameField = document.getElementById('requested_by_name');
    const requestedByTitleField = document.getElementById('requested_by_title');
    const approvedByNameField = document.getElementById('approved_by_name');
    const approvedByTitleField = document.getElementById('approved_by_title');
    const issuedByNameField = document.getElementById('issued_by_name');
    const issuedByTitleField = document.getElementById('issued_by_title');
    const receivedByNameField = document.getElementById('received_by_name');
    const receivedByTitleField = document.getElementById('received_by_title');
    const syncDriverPeopleButton = document.getElementById('sync-driver-people');
    const destinationField = document.querySelector('textarea[name="destination"]');
    const litersRequestedField = document.getElementById('liters_requested');
    const useMapPickerField = document.getElementById('use_map_picker');
    const latitudeField = document.getElementById('map_latitude');
    const longitudeField = document.getElementById('map_longitude');
    const searchDestinationButton = document.getElementById('search-destination-map');
    const mapPickerPanel = document.getElementById('map-picker-panel');
    const useCurrentLocationButton = document.getElementById('use-current-location');
    const clearMapPinButton = document.getElementById('clear-map-pin');
    const mapCanvas = document.getElementById('leaflet-map');
        if (window.SPAMS && typeof window.SPAMS.setupRequiredSummaryValidation === 'function' && tripTicketForm && tripTicketRequiredSummary) {
            window.SPAMS.setupRequiredSummaryValidation({
                form: tripTicketForm,
                summary: tripTicketRequiredSummary,
                summaryPrefix: 'Please complete required fields: ',
                requiredFields: [
                    { id: 'departure_date', label: 'Departure Date' },
                    { id: 'departure_time', label: 'Departure Time' },
                    { id: 'vehicle_id', label: 'Vehicle Plate Number' },
                    { id: 'driver_employee_id', label: 'Driver' },
                    { id: 'destination', label: 'Place to Visit / Location to Travel' },
                    { id: 'purpose', label: 'Purpose of Travel' }
                ]
            });
        }

    let leafletMap = null;
    let marker = null;

    function bindSignatoryLookup(nameField, titleField) {
        if (!nameField || !titleField) {
            return;
        }

        const applyMatchedTitle = function () {
            const matchedTitle = nameField.selectedOptions?.[0]?.dataset?.title || '';
            if (matchedTitle !== '') {
                titleField.value = matchedTitle;
            }
        };

        nameField.addEventListener('change', applyMatchedTitle);
    }

    function syncSelect2Value(field) {
        if (!field || !window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }
        window.jQuery(field).trigger('change.select2');
    }

    function syncRisSectionVisibility() {
        const litersValue = parseFloat((litersRequestedField?.value || '').trim());
        const hasFuel = !Number.isNaN(litersValue) && litersValue > 0;
        if (risSignatoriesSection) {
            risSignatoriesSection.classList.toggle('d-none', !hasFuel);
        }
    }

    function syncReturnDateConstraints() {
        if (!departureDateField || !returnDateField) {
            return;
        }
        returnDateField.min = departureDateField.value || '';
        if (returnDateField.value !== '' && departureDateField.value !== '' && returnDateField.value < departureDateField.value) {
            returnDateField.value = departureDateField.value;
        }
    }

    function applyDriverToRisSignatories(force = false) {
        const selectedOption = driverField?.selectedOptions?.[0] || null;
        const driverName = selectedOption?.dataset?.driverName || '';
        const driverTitle = selectedOption?.dataset?.driverTitle || '';

        if (!force) {
            const hasRequestedValue = (requestedByNameField?.value || '').trim() !== '' || (requestedByTitleField?.value || '').trim() !== '';
            const hasReceivedValue = (receivedByNameField?.value || '').trim() !== '' || (receivedByTitleField?.value || '').trim() !== '';
            if (hasRequestedValue || hasReceivedValue) {
                return;
            }
        }

        if (requestedByNameField) {
            requestedByNameField.value = driverName;
            syncSelect2Value(requestedByNameField);
        }
        if (requestedByTitleField) {
            requestedByTitleField.value = driverTitle;
        }
        if (receivedByNameField) {
            receivedByNameField.value = driverName;
            syncSelect2Value(receivedByNameField);
        }
        if (receivedByTitleField) {
            receivedByTitleField.value = driverTitle;
        }
    }

    function toggleMapPickerPanel() {
        const useMap = !!useMapPickerField?.checked;
        if (mapPickerPanel) {
            mapPickerPanel.classList.toggle('d-none', !useMap);
        }
        if (useMap && leafletMap) {
            setTimeout(function () {
                leafletMap.invalidateSize();
            }, 0);
        }
    }

    function updateMapPreview() {
        const destination = (destinationField?.value || '').trim();
        const latitude = (latitudeField?.value || '').trim();
        const longitude = (longitudeField?.value || '').trim();
        if (!leafletMap) {
            return;
        }

        if (latitude !== '' && longitude !== '') {
            const lat = parseFloat(latitude);
            const lng = parseFloat(longitude);
            if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
                if (marker) {
                    marker.setLatLng([lat, lng]);
                }
                leafletMap.setView([lat, lng], Math.max(leafletMap.getZoom(), 15));
            }
            return;
        }

        if (destination !== '' && typeof fetch === 'function') {
            fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' + encodeURIComponent(destination))
                .then(response => response.ok ? response.json() : [])
                .then(results => {
                    if (!Array.isArray(results) || results.length === 0) {
                        return;
                    }
                    const lat = parseFloat(results[0].lat);
                    const lng = parseFloat(results[0].lon);
                    if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
                        leafletMap.setView([lat, lng], 15);
                    }
                })
                .catch(() => {});
        }
    }

    function setMarker(lat, lng, recenter = true) {
        if (!leafletMap) return;
        if (!marker) {
            marker = L.marker([lat, lng], { draggable: true }).addTo(leafletMap);
            marker.on('dragend', function (event) {
                const pos = event.target.getLatLng();
                latitudeField.value = pos.lat.toFixed(7);
                longitudeField.value = pos.lng.toFixed(7);
                updateMapPreview();
            });
        } else {
            marker.setLatLng([lat, lng]);
        }

        latitudeField.value = Number(lat).toFixed(7);
        longitudeField.value = Number(lng).toFixed(7);
        if (recenter) {
            leafletMap.setView([lat, lng], Math.max(leafletMap.getZoom(), 15));
        }
        updateMapPreview();
    }

    function clearMarker() {
        if (marker && leafletMap) {
            leafletMap.removeLayer(marker);
            marker = null;
        }
        latitudeField.value = '';
        longitudeField.value = '';
        updateMapPreview();
    }

    if (mapCanvas && typeof L !== 'undefined') {
        const defaultLat = parseFloat(latitudeField?.value || '11.0086');
        const defaultLng = parseFloat(longitudeField?.value || '122.0951');
        leafletMap = L.map(mapCanvas).setView([defaultLat, defaultLng], (latitudeField?.value && longitudeField?.value) ? 15 : 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(leafletMap);

        leafletMap.on('click', function (event) {
            setMarker(event.latlng.lat, event.latlng.lng, false);
        });

        if ((latitudeField?.value || '') !== '' && (longitudeField?.value || '') !== '') {
            setMarker(parseFloat(latitudeField.value), parseFloat(longitudeField.value));
        }
    }

    function refreshPassengerRowLabels() {
        rowsContainer.querySelectorAll('.passenger-row').forEach(function (row, index) {
            const label = row.querySelector('.input-group-text');
            const input = row.querySelector('input[name*="[passenger_name]"]');
            if (label) label.textContent = String(index + 1);
            if (input) input.name = 'passengers[' + index + '][passenger_name]';
        });
    }

    addButton?.addEventListener('click', function () {
        const index = rowsContainer.querySelectorAll('.passenger-row').length;
        const wrapper = document.createElement('div');
        wrapper.className = 'input-group passenger-row';
        wrapper.innerHTML = '<span class="input-group-text">' + (index + 1) + '</span><input type="text" name="passengers[' + index + '][passenger_name]" class="form-control" list="passenger_employee_list" placeholder="Type passenger name or choose from employee list"><button type="button" class="btn btn-outline-danger remove-passenger-row">Remove</button>';
        rowsContainer.appendChild(wrapper);
    });

    rowsContainer?.addEventListener('click', function (event) {
        if (!event.target.classList.contains('remove-passenger-row')) return;
        const rows = rowsContainer.querySelectorAll('.passenger-row');
        if (rows.length === 1) {
            const input = rows[0].querySelector('input');
            if (input) input.value = '';
            return;
        }
        event.target.closest('.passenger-row')?.remove();
        refreshPassengerRowLabels();
    });

    searchDestinationButton?.addEventListener('click', function () {
        const destination = (destinationField?.value || '').trim();
        if (!destination) {
            alert('Enter the destination first.');
            return;
        }
        window.open('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(destination), '_blank', 'noopener');
    });

    useCurrentLocationButton?.addEventListener('click', function () {
        if (!navigator.geolocation) {
            alert('Geolocation is not available in this browser.');
            return;
        }
        navigator.geolocation.getCurrentPosition(function (position) {
            setMarker(position.coords.latitude, position.coords.longitude);
        }, function () {
            alert('Unable to get current location.');
        });
    });

    clearMapPinButton?.addEventListener('click', function () {
        clearMarker();
    });

    litersRequestedField?.addEventListener('input', syncRisSectionVisibility);
    litersRequestedField?.addEventListener('change', syncRisSectionVisibility);
    departureDateField?.addEventListener('change', syncReturnDateConstraints);

    bindSignatoryLookup(requestedByNameField, requestedByTitleField);
    bindSignatoryLookup(receivedByNameField, receivedByTitleField);
    bindSignatoryLookup(approvedByNameField, approvedByTitleField);
    bindSignatoryLookup(issuedByNameField, issuedByTitleField);

    driverField?.addEventListener('change', function () {
        applyDriverToRisSignatories(false);
    });
    syncDriverPeopleButton?.addEventListener('click', function () {
        applyDriverToRisSignatories(true);
    });
    tripTicketForm?.addEventListener('submit', function (event) {
        if (event.defaultPrevented) {
            return;
        }

        const litersValue = parseFloat((litersRequestedField?.value || '').trim());
        if (!Number.isNaN(litersValue) && litersValue > 0) {
            return;
        }

        event.preventDefault();
        if (!window.confirmAction) {
            if (litersRequestedField && (litersRequestedField.value || '').trim() === '') {
                litersRequestedField.value = '0';
            }
            tripTicketForm?.submit();
            return;
        }
        window.confirmAction({
            title: 'Confirm action',
            message: 'Fuel requested is 0 liters or blank. Save this trip ticket without creating a fuel RIS?',
            confirmText: 'Confirm',
            onConfirm: function () {
                if (litersRequestedField && (litersRequestedField.value || '').trim() === '') {
                    litersRequestedField.value = '0';
                }
                tripTicketForm?.submit();
            }
        });
        litersRequestedField?.focus();
    });

    destinationField?.addEventListener('input', updateMapPreview);
    useMapPickerField?.addEventListener('change', toggleMapPickerPanel);
    applyDriverToRisSignatories(false);
    syncRisSectionVisibility();
    syncReturnDateConstraints();
    toggleMapPickerPanel();
    updateMapPreview();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
