<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Transport Officer');

$tripDb = trip_db();
$page_title = 'Monthly Report of Official Travels';
$errors = [];
$vehicles = [];
$reportRows = [];
$reportGenerated = false;

$selectedMonth = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$selectedVehicleId = (int) ($_GET['vehicle_id'] ?? 0);

[$selectedYear, $selectedMonthNumber] = array_map('intval', explode('-', $selectedMonth));
$monthStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonthNumber);
$monthEnd = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F 1-t, Y', strtotime($monthStart));

$selectedVehicle = null;
$preparedByName = '';
$preparedByTitle = '';
$approvedByName = '';
$approvedByTitle = '';

if (!$tripDb) {
    $errors[] = 'Unable to connect to the trip ticket database. Import `database/081_trip_ticket_module.sql` first.';
} else {
    $vehicleResult = $tripDb->query("
        SELECT id, plate_no, vehicle_name, vehicle_type, fuel_type, capacity_liters
        FROM trip_vehicles
        WHERE is_active = 1
        ORDER BY plate_no ASC
    ");
    if ($vehicleResult) {
        $vehicles = $vehicleResult->fetch_all(MYSQLI_ASSOC);
    }

    foreach ($vehicles as $vehicle) {
        if ((int) $vehicle['id'] === $selectedVehicleId) {
            $selectedVehicle = $vehicle;
            break;
        }
    }

    if (isset($_GET['month']) || isset($_GET['vehicle_id'])) {
        $reportGenerated = true;
        if ($selectedVehicleId <= 0 || !$selectedVehicle) {
            $errors[] = 'Please select a vehicle to generate the monthly report.';
        } else {
            $stmt = $tripDb->prepare("
                SELECT
                    t.id,
                    t.departure_date,
                    t.departure_time,
                    t.fuel_consumed,
                    t.oil_used,
                    t.grease_used,
                    t.distance_traveled,
                    t.purpose,
                    t.issued_by_name,
                    t.issued_by_title,
                    t.approved_by_name,
                    t.approved_by_title,
                    COALESCE(GROUP_CONCAT(p.passenger_name ORDER BY p.sort_order ASC SEPARATOR ', '), '') AS passenger_names
                FROM trip_tickets t
                LEFT JOIN trip_ticket_passengers p ON p.trip_ticket_id = t.id
                WHERE t.vehicle_id = ?
                  AND t.departure_date BETWEEN ? AND ?
                GROUP BY
                    t.id,
                    t.departure_date,
                    t.departure_time,
                    t.fuel_consumed,
                    t.oil_used,
                    t.grease_used,
                    t.distance_traveled,
                    t.purpose,
                    t.issued_by_name,
                    t.issued_by_title,
                    t.approved_by_name,
                    t.approved_by_title
                ORDER BY t.departure_date ASC, t.departure_time ASC, t.id ASC
            ");

            if (!$stmt) {
                $errors[] = 'Unable to prepare the monthly report query.';
            } else {
                $stmt->bind_param('iss', $selectedVehicleId, $monthStart, $monthEnd);
                $stmt->execute();
                $reportRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }

            foreach ($reportRows as $row) {
                if ($preparedByName === '' && trim((string) ($row['issued_by_name'] ?? '')) !== '') {
                    $preparedByName = trim((string) $row['issued_by_name']);
                    $preparedByTitle = trim((string) ($row['issued_by_title'] ?? ''));
                }
                if ($approvedByName === '' && trim((string) ($row['approved_by_name'] ?? '')) !== '') {
                    $approvedByName = trim((string) $row['approved_by_name']);
                    $approvedByTitle = trim((string) ($row['approved_by_title'] ?? ''));
                }
            }
        }
    }
}

$fuelLabel = $selectedVehicle ? trim((string) ($selectedVehicle['fuel_type'] ?? 'Fuel')) : 'Fuel';
$fuelIssuedTotal = 0.0;
$fuelConsumedTotal = 0.0;
$distanceTotal = 0.0;
$oilUsedTotal = 0.0;
$greaseUsedTotal = 0.0;

foreach ($reportRows as $row) {
    $fuelIssuedTotal += (float) ($row['fuel_consumed'] ?? 0);
    $fuelConsumedTotal += (float) ($row['fuel_consumed'] ?? 0);
    $distanceTotal += (float) ($row['distance_traveled'] ?? 0);
    $oilUsedTotal += (float) ($row['oil_used'] ?? 0);
    $greaseUsedTotal += (float) ($row['grease_used'] ?? 0);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div>
            <h4 class="mb-1">Monthly Report of Official Travels</h4>
            <div class="text-muted">Printable monthly travel report for each vehicle.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo base_url('modules/trip_tickets/index.php'); ?>" class="btn btn-outline-secondary">Back to Trip Tickets</a>
            <?php if ($reportGenerated && !$errors && $selectedVehicle): ?>
                <button type="button" class="btn btn-primary" onclick="window.print();">
                    <i class="bi bi-printer me-1"></i> Print Report
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger no-print"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm no-print mb-3">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Month</label>
                    <input type="month" name="month" class="form-control" value="<?php echo h($selectedMonth); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Vehicle</label>
                    <select name="vehicle_id" class="form-select" required>
                        <option value="">Select vehicle</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo (int) $vehicle['id']; ?>" <?php echo $selectedVehicleId === (int) $vehicle['id'] ? 'selected' : ''; ?>>
                                <?php echo h($vehicle['plate_no'] . ' - ' . $vehicle['vehicle_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">Generate</button>
                    <a href="<?php echo base_url('modules/trip_tickets/monthly_report.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($reportGenerated && !$errors && $selectedVehicle): ?>
        <div class="card shadow-sm">
            <div class="card-body report-sheet">
                <div class="text-center report-header">
                    <div>Republic of the Philippines</div>
                    <div><strong>UNIVERSITY OF ANTIQUE</strong></div>
                    <div>Sibalom, Antique</div>
                </div>

                <div class="report-title text-center">
                    MONTHLY REPORT OF OFFICIAL TRAVELS
                    <div class="subtitle">(To be accomplished for each vehicle)</div>
                </div>

                <table class="report-meta">
                    <tr>
                        <td><strong>Vehicle Plate No.:</strong> <?php echo h((string) $selectedVehicle['plate_no']); ?></td>
                        <td class="text-end"><strong>Month of:</strong> <span class="text-decoration-underline"><?php echo h($monthLabel); ?></span></td>
                    </tr>
                </table>

                <table class="report-table">
                    <thead>
                        <tr>
                            <th style="width: 10%;">Date</th>
                            <th style="width: 11%;"><?php echo h($fuelLabel); ?><br>Issued/Purchased</th>
                            <th style="width: 11%;"><?php echo h($fuelLabel); ?><br>Consumed</th>
                            <th style="width: 10%;">Distance<br>Traveled<br>(in kms)</th>
                            <th style="width: 10%;">Oil Used<br>(in liters)</th>
                            <th style="width: 8%;">Grease<br>Used</th>
                            <th style="width: 20%;">Name of Passenger(s)</th>
                            <th style="width: 20%;">Purpose of Travel</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$reportRows): ?>
                            <?php for ($i = 0; $i < 18; $i++): ?>
                                <tr>
                                    <?php if ($i === 0): ?>
                                        <td colspan="8" class="text-center"><?php echo h('NO TRIP ' . strtoupper(date('F', strtotime($monthStart)))); ?></td>
                                    <?php else: ?>
                                        <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endfor; ?>
                        <?php else: ?>
                            <?php foreach ($reportRows as $row): ?>
                                <tr>
                                    <td><?php echo h(format_date((string) $row['departure_date'], 'm/d/Y')); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($row['fuel_consumed'] ?? 0), 3); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($row['fuel_consumed'] ?? 0), 3); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($row['distance_traveled'] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($row['oil_used'] ?? 0), 3); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($row['grease_used'] ?? 0), 3); ?></td>
                                    <td><?php echo h((string) ($row['passenger_names'] ?? '')); ?></td>
                                    <td><?php echo h((string) ($row['purpose'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php for ($i = count($reportRows); $i < 18; $i++): ?>
                                <tr>
                                    <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                                </tr>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>TOTALS</th>
                            <th class="text-end"><?php echo number_format($fuelIssuedTotal, 3); ?></th>
                            <th class="text-end"><?php echo number_format($fuelConsumedTotal, 3); ?></th>
                            <th class="text-end"><?php echo number_format($distanceTotal, 0); ?></th>
                            <th class="text-end"><?php echo number_format($oilUsedTotal, 3); ?></th>
                            <th class="text-end"><?php echo number_format($greaseUsedTotal, 0); ?></th>
                            <th></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>

                <table class="report-summary">
                    <tr><td>Balance in tank at the beginning of the month</td><td class="text-end">0.000</td><td>liters</td></tr>
                    <tr><td>Quantity issued from stock during the month</td><td class="text-end"><?php echo number_format($fuelIssuedTotal, 3); ?></td><td>liters</td></tr>
                    <tr><td>Quantity purchased during the month (on the way)</td><td class="text-end">0.000</td><td>liters</td></tr>
                    <tr><td>Total balance in tank (issued or purchased)</td><td class="text-end"><?php echo number_format($fuelIssuedTotal, 3); ?></td><td>liters</td></tr>
                    <tr><td>Quantity consumed during the month</td><td class="text-end"><?php echo number_format($fuelConsumedTotal, 3); ?></td><td>liters</td></tr>
                    <tr><td>Balance in tank at the end of the month</td><td class="text-end">0.000</td><td>liters</td></tr>
                </table>

                <div class="report-certification">
                    I hereby certify to the correctness of the above statement and that the motor vehicle was used on strictly official business only.
                </div>

                <table class="report-signatures">
                    <tr>
                        <td class="text-center">
                            <div>Prepared by:</div>
                            <div class="signature-space"></div>
                            <div class="signature-name"><?php echo h($preparedByName); ?></div>
                            <div><?php echo h($preparedByTitle); ?></div>
                        </td>
                        <td class="text-center">
                            <div>APPROVED:</div>
                            <div class="signature-space"></div>
                            <div class="signature-name"><?php echo h($approvedByName); ?></div>
                            <div><?php echo h($approvedByTitle); ?></div>
                        </td>
                    </tr>
                </table>

                <div class="report-footer">
                    <span>SO-FM-009</span>
                    <span>Rev. 01/02-04-20</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>

<style>
.report-sheet {
    width: 8.5in;
    min-height: 13in;
    margin: 0 auto;
    padding: 0.2in 0.15in;
    color: #000;
    font-family: "Times New Roman", serif;
    font-size: 12px;
}
.report-header {
    line-height: 1.2;
    margin-bottom: 0.12in;
}
.report-title {
    font-size: 18px;
    font-weight: bold;
    margin: 0.12in 0 0.08in;
}
.report-title .subtitle {
    font-size: 12px;
    font-weight: normal;
}
.report-meta,
.report-table,
.report-summary,
.report-signatures {
    width: 100%;
    border-collapse: collapse;
}
.report-meta td {
    border-top: 2px solid #000;
    border-bottom: 2px solid #000;
    padding: 6px 8px;
    font-size: 13px;
}
.report-table th,
.report-table td {
    border: 1px solid #000;
    padding: 4px 5px;
    vertical-align: top;
}
.report-table thead th,
.report-table tfoot th {
    text-align: center;
    font-weight: bold;
}
.report-table tbody td {
    height: 28px;
}
.report-summary {
    margin-top: 0.08in;
}
.report-summary td {
    padding: 2px 6px;
}
.report-summary td:nth-child(2) {
    width: 1.6in;
    border-bottom: 1px solid #000;
}
.report-summary td:last-child {
    width: 0.8in;
}
.report-certification {
    margin-top: 0.18in;
}
.report-signatures {
    margin-top: 0.24in;
}
.report-signatures td {
    width: 50%;
    vertical-align: top;
    padding: 0 20px;
}
.signature-space {
    height: 0.45in;
}
.signature-name {
    border-bottom: 1px solid #000;
    font-weight: bold;
    padding-bottom: 2px;
}
.report-footer {
    display: flex;
    justify-content: space-between;
    margin-top: 0.2in;
    font-size: 11px;
}

@media print {
    @page {
        size: 8.5in 13in;
        margin: 0.15in;
    }
    body {
        background: #fff !important;
    }
    .no-print,
    .header,
    .sidebar,
    .topbar,
    .pagetitle,
    .breadcrumb,
    .section > .d-flex:first-child,
    .btn,
    .chat-widget-container {
        display: none !important;
    }
    main,
    #main,
    .main {
        margin: 0 !important;
        padding: 0 !important;
    }
    .card {
        border: 0 !important;
        box-shadow: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    .report-sheet {
        width: 8.2in;
        min-height: auto;
        padding: 0;
    }
}

            <?php echo print_page_number_css(); ?></style>

<?php render_print_page_number(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
