<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Transport Officer');

$tripDb = trip_db();
$page_title = 'Report of Fuel Consumption';
$errors = [];
$rows = [];
$reportGenerated = false;

$selectedMonth = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

[$selectedYear, $selectedMonthNumber] = array_map('intval', explode('-', $selectedMonth));
$monthStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonthNumber);
$monthEnd = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F 1-t, Y', strtotime($monthStart));

$preparedByName = '';
$preparedByTitle = '';
$approvedByName = '';
$approvedByTitle = '';

if (!$tripDb) {
    $errors[] = 'Unable to connect to the trip ticket database. Import `database/081_trip_ticket_module.sql` first.';
} elseif (isset($_GET['month'])) {
    $reportGenerated = true;

    $stmt = $tripDb->prepare("
        SELECT
            v.id,
            v.vehicle_name,
            v.vehicle_type,
            v.plate_no,
            v.fuel_type,
            COALESCE(SUM(COALESCE(t.distance_traveled, 0)), 0) AS total_distance_traveled,
            COALESCE(SUM(COALESCE(t.fuel_consumed, 0)), 0) AS total_fuel_used,
            MAX(COALESCE(t.issued_by_name, '')) AS prepared_by_name,
            MAX(COALESCE(t.issued_by_title, '')) AS prepared_by_title,
            MAX(COALESCE(t.approved_by_name, '')) AS approved_by_name,
            MAX(COALESCE(t.approved_by_title, '')) AS approved_by_title
        FROM trip_vehicles v
        LEFT JOIN trip_tickets t
            ON t.vehicle_id = v.id
           AND t.departure_date BETWEEN ? AND ?
           AND t.status = 'completed'
        WHERE v.is_active = 1
        GROUP BY
            v.id,
            v.vehicle_name,
            v.vehicle_type,
            v.plate_no,
            v.fuel_type
        ORDER BY v.vehicle_name ASC, v.plate_no ASC
    ");

    if (!$stmt) {
        $errors[] = 'Unable to prepare the fuel consumption report query.';
    } else {
        $stmt->bind_param('ss', $monthStart, $monthEnd);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    foreach ($rows as $row) {
        if ($preparedByName === '' && trim((string) ($row['prepared_by_name'] ?? '')) !== '') {
            $preparedByName = trim((string) $row['prepared_by_name']);
            $preparedByTitle = trim((string) ($row['prepared_by_title'] ?? ''));
        }
        if ($approvedByName === '' && trim((string) ($row['approved_by_name'] ?? '')) !== '') {
            $approvedByName = trim((string) $row['approved_by_name']);
            $approvedByTitle = trim((string) ($row['approved_by_title'] ?? ''));
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div>
            <h4 class="mb-1">Report of Fuel Consumption</h4>
            <div class="text-muted">Printable monthly vehicle fuel consumption summary.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo base_url('modules/trip_tickets/index.php'); ?>" class="btn btn-outline-secondary">Back to Trip Tickets</a>
            <?php if ($reportGenerated && !$errors): ?>
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
                <div class="col-md-4">
                    <label class="form-label">Month</label>
                    <input type="month" name="month" class="form-control" value="<?php echo h($selectedMonth); ?>" required>
                </div>
                <div class="col-md-8 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Generate</button>
                    <a href="<?php echo base_url('modules/trip_tickets/fuel_consumption_report.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($reportGenerated && !$errors): ?>
        <div class="card shadow-sm">
            <div class="card-body fuel-report-sheet">
                <div class="text-center report-header">
                    <div>Republic of the Philippines</div>
                    <div><strong>UNIVERSITY OF ANTIQUE</strong></div>
                    <div>Sibalom, Antique</div>
                </div>

                <div class="report-title text-center">
                    REPORT OF FUEL CONSUMPTION
                    <div class="subtitle">For the month of <span class="text-decoration-underline"><?php echo h($monthLabel); ?></span></div>
                </div>

                <table class="fuel-report-table">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width: 16%;">Type of Vehicle</th>
                            <th rowspan="2" style="width: 8%;">Plate Number</th>
                            <th rowspan="2" style="width: 8%;">Number of Cylinder</th>
                            <th colspan="2" style="width: 14%;">ODOMETER READING</th>
                            <th rowspan="2" style="width: 11%;">Total Distance Traveled<br>A</th>
                            <th rowspan="2" style="width: 11%;">Total Fuel Used<br>B</th>
                            <th rowspan="2" style="width: 10%;">Distance Traveled per Liter<br>C=(A/B)</th>
                            <th rowspan="2" style="width: 10%;">Normal Travel Km. per Liter<br>D</th>
                            <th rowspan="2" style="width: 11%;">Total Liters Consumed Plus 10% Allowance<br>E=(A/D)(1.1)</th>
                            <th rowspan="2" style="width: 8%;">Excess<br>F=B-E</th>
                            <th rowspan="2" style="width: 13%;">Remarks</th>
                        </tr>
                        <tr>
                            <th style="width: 7%;">Beginning</th>
                            <th style="width: 7%;">Ending</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <?php for ($i = 0; $i < 14; $i++): ?>
                                <tr>
                                    <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                                </tr>
                            <?php endfor; ?>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $fuelUsed = (float) ($row['total_fuel_used'] ?? 0);
                                $distanceTraveled = (float) ($row['total_distance_traveled'] ?? 0);
                                $distancePerLiter = ($fuelUsed > 0 && $distanceTraveled > 0) ? ($distanceTraveled / $fuelUsed) : null;
                                ?>
                                <tr>
                                    <td><?php echo h(trim((string) ($row['vehicle_name'] ?? '')) !== '' ? (string) $row['vehicle_name'] : (string) ($row['vehicle_type'] ?? '')); ?></td>
                                    <td><?php echo h((string) ($row['plate_no'] ?? '')); ?></td>
                                    <td class="text-center"></td>
                                    <td class="text-center"></td>
                                    <td class="text-center"></td>
                                    <td class="text-end"><?php echo number_format($distanceTraveled, 2); ?></td>
                                    <td class="text-end"><?php echo number_format($fuelUsed, 3); ?></td>
                                    <td class="text-end"><?php echo $distancePerLiter !== null ? number_format($distancePerLiter, 3) : ''; ?></td>
                                    <td class="text-center"></td>
                                    <td class="text-end"></td>
                                    <td class="text-end"></td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php for ($i = count($rows); $i < 14; $i++): ?>
                                <tr>
                                    <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                                </tr>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <table class="report-signatures">
                    <tr>
                        <td style="width: 50%;">
                            <div>Prepared by:</div>
                            <div class="signature-space"></div>
                            <div class="signature-line-name"><?php echo h($preparedByName); ?></div>
                            <div><?php echo h($preparedByTitle); ?></div>
                        </td>
                        <td style="width: 50%;">
                            <div>APPROVED:</div>
                            <div class="signature-space"></div>
                            <div class="signature-line-name"><?php echo h($approvedByName); ?></div>
                            <div><?php echo h($approvedByTitle); ?></div>
                        </td>
                    </tr>
                </table>

                <div class="report-footer">
                    <span>SO-FM-008</span>
                    <span>Rev.01/02-04-20</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>

<style>
.fuel-report-sheet {
    width: 8.5in;
    min-height: 13in;
    margin: 0 auto;
    padding: 0.2in 0.05in;
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
.fuel-report-table,
.report-signatures {
    width: 100%;
    border-collapse: collapse;
}
.fuel-report-table th,
.fuel-report-table td {
    border: 1px solid #000;
    padding: 4px 5px;
    vertical-align: middle;
}
.fuel-report-table thead th {
    text-align: center;
    font-weight: bold;
}
.fuel-report-table tbody td {
    height: 26px;
}
.report-signatures {
    margin-top: 0.3in;
}
.report-signatures td {
    vertical-align: top;
    padding: 0 20px;
}
.signature-space {
    height: 0.45in;
}
.signature-line-name {
    border-bottom: 1px solid #000;
    font-weight: bold;
    padding-bottom: 2px;
    text-align: center;
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
    .fuel-report-sheet {
        width: 8.2in;
        min-height: auto;
        padding: 0;
    }
}

            <?php echo print_page_number_css(); ?></style>

<?php render_print_page_number(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
