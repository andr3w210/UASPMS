<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Transport Officer');

$tripDb = trip_db();
$page_title = 'Annual Vehicle Fuel Consumption Summary';
$errors = [];
$rows = [];
$monthTotals = array_fill(1, 12, 0.0);
$grandTotalConsumed = 0.0;
$grandTotalPurchased = 0.0;
$grandTotalAmount = 0.0;
$entryCount = 0;

$selectedYear = (int) ($_GET['year'] ?? date('Y'));
if ($selectedYear < 2000 || $selectedYear > ((int) date('Y') + 1)) {
    $selectedYear = (int) date('Y');
}

$yearStart = sprintf('%04d-01-01', $selectedYear);
$yearEnd = sprintf('%04d-12-31', $selectedYear);

if (!$tripDb) {
    $errors[] = 'Unable to connect to trip ticket database. Please verify trip DB settings.';
} else {
    $tableCheck = $tripDb->query("SHOW TABLES LIKE 'trip_fuel_ris_entries'");
    $hasFuelTable = $tableCheck && $tableCheck->num_rows > 0;
    if (!$hasFuelTable) {
        $errors[] = 'Fuel RIS table is missing. Run database/097_trip_fuel_ris_entries.sql first.';
    }

    $hasQuantity = false;
    if ($hasFuelTable) {
        $quantityCheck = $tripDb->query("SHOW COLUMNS FROM trip_fuel_ris_entries LIKE 'quantity'");
        $hasQuantity = $quantityCheck && $quantityCheck->num_rows > 0;
    }

    if ($hasFuelTable && !$errors) {
        $consumedExpr = $hasQuantity
            ? 'CASE WHEN COALESCE(e.liters_consumed, 0) > 0 THEN e.liters_consumed WHEN COALESCE(e.quantity, 0) > 0 THEN e.quantity ELSE 0 END'
            : 'COALESCE(e.liters_consumed, 0)';

        $sql = "
            SELECT
                v.id AS vehicle_id,
                v.plate_no,
                v.vehicle_name,
                v.vehicle_type,
                v.fuel_type,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 1 THEN {$consumedExpr} ELSE 0 END), 0) AS jan,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 2 THEN {$consumedExpr} ELSE 0 END), 0) AS feb,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 3 THEN {$consumedExpr} ELSE 0 END), 0) AS mar,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 4 THEN {$consumedExpr} ELSE 0 END), 0) AS apr,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 5 THEN {$consumedExpr} ELSE 0 END), 0) AS may,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 6 THEN {$consumedExpr} ELSE 0 END), 0) AS jun,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 7 THEN {$consumedExpr} ELSE 0 END), 0) AS jul,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 8 THEN {$consumedExpr} ELSE 0 END), 0) AS aug,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 9 THEN {$consumedExpr} ELSE 0 END), 0) AS sep,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 10 THEN {$consumedExpr} ELSE 0 END), 0) AS oct,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 11 THEN {$consumedExpr} ELSE 0 END), 0) AS nov,
                COALESCE(SUM(CASE WHEN MONTH(e.ris_date) = 12 THEN {$consumedExpr} ELSE 0 END), 0) AS dece,
                COALESCE(SUM({$consumedExpr}), 0) AS total_consumed,
                COALESCE(SUM(e.liters_purchased), 0) AS total_purchased,
                COALESCE(SUM(e.amount), 0) AS total_amount,
                COUNT(e.id) AS entry_count
            FROM trip_vehicles v
            LEFT JOIN trip_fuel_ris_entries e
                ON e.vehicle_id = v.id
               AND e.ris_date BETWEEN ? AND ?
            WHERE v.is_active = 1
            GROUP BY v.id, v.plate_no, v.vehicle_name, v.vehicle_type, v.fuel_type
            ORDER BY v.vehicle_name ASC, v.plate_no ASC
        ";

        $stmt = $tripDb->prepare($sql);
        if (!$stmt) {
            $errors[] = 'Unable to prepare the annual fuel consumption summary query.';
        } else {
            $stmt->bind_param('ss', $yearStart, $yearEnd);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}

$monthKeys = [
    1 => 'jan',
    2 => 'feb',
    3 => 'mar',
    4 => 'apr',
    5 => 'may',
    6 => 'jun',
    7 => 'jul',
    8 => 'aug',
    9 => 'sep',
    10 => 'oct',
    11 => 'nov',
    12 => 'dece',
];
$monthLabels = [
    1 => 'Jan',
    2 => 'Feb',
    3 => 'Mar',
    4 => 'Apr',
    5 => 'May',
    6 => 'Jun',
    7 => 'Jul',
    8 => 'Aug',
    9 => 'Sep',
    10 => 'Oct',
    11 => 'Nov',
    12 => 'Dec',
];

foreach ($rows as $row) {
    foreach ($monthKeys as $monthNo => $key) {
        $monthTotals[$monthNo] += (float) ($row[$key] ?? 0);
    }
    $grandTotalConsumed += (float) ($row['total_consumed'] ?? 0);
    $grandTotalPurchased += (float) ($row['total_purchased'] ?? 0);
    $grandTotalAmount += (float) ($row['total_amount'] ?? 0);
    $entryCount += (int) ($row['entry_count'] ?? 0);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section annual-fuel-summary-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3 no-print">
        <div>
            <h4 class="mb-1">Annual Vehicle Fuel Consumption Summary</h4>
            <div class="text-muted">Printable all-vehicle summary based on encoded Fuel RIS consumption.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?php echo base_url('modules/trip_tickets/fuel_ris.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-journal-plus me-1"></i> Fuel RIS Encoding
            </a>
            <a href="<?php echo base_url('modules/trip_tickets/fuel_ris_report.php?period_type=year&period_ref=' . urlencode((string) $selectedYear . '-01')); ?>" class="btn btn-outline-primary">
                <i class="bi bi-graph-up-arrow me-1"></i> Consolidated
            </a>
            <?php if (!$errors): ?>
                <button type="button" class="btn btn-primary" onclick="window.print();">
                    <i class="bi bi-printer me-1"></i> Print Form
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
                    <label class="form-label">Report Year</label>
                    <input type="number" name="year" class="form-control" min="2000" max="<?php echo (int) date('Y') + 1; ?>" value="<?php echo h((string) $selectedYear); ?>" required>
                </div>
                <div class="col-md-9 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">Load Summary</button>
                    <a href="<?php echo base_url('modules/trip_tickets/annual_fuel_consumption_summary.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$errors): ?>
        <div class="annual-fuel-print-wrap">
            <div class="annual-fuel-sheet">
                <div class="annual-fuel-header">
                    <div>Republic of the Philippines</div>
                    <div><strong>UNIVERSITY OF ANTIQUE</strong></div>
                    <div>Sibalom, Antique</div>
                </div>

                <div class="annual-fuel-title">
                    ANNUAL VEHICLE FUEL CONSUMPTION SUMMARY
                    <div>For Calendar Year <span class="annual-fuel-line"><?php echo h((string) $selectedYear); ?></span></div>
                </div>

                <div class="annual-fuel-meta">
                    <div><strong>Source:</strong> Fuel RIS Encoding</div>
                    <div><strong>Coverage:</strong> All active vehicles</div>
                    <div><strong>Printed:</strong> <?php echo h(date('F j, Y')); ?></div>
                </div>

                <table class="annual-fuel-table">
                    <thead>
                        <tr>
                            <th style="width: 3%;">No.</th>
                            <th style="width: 13%;">Vehicle</th>
                            <th style="width: 8%;">Plate No.</th>
                            <th style="width: 7%;">Fuel</th>
                            <?php foreach ($monthLabels as $label): ?>
                                <th><?php echo h($label); ?></th>
                            <?php endforeach; ?>
                            <th style="width: 7%;">Total Consumed</th>
                            <th style="width: 7%;">Purchased</th>
                            <th style="width: 8%;">Amount</th>
                            <th style="width: 7%;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <tr>
                                    <td class="text-center"><?php echo $i; ?></td>
                                    <td>&nbsp;</td>
                                    <td></td>
                                    <td></td>
                                    <?php foreach ($monthLabels as $unused): ?><td></td><?php endforeach; ?>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            <?php endfor; ?>
                        <?php else: ?>
                            <?php foreach ($rows as $index => $row): ?>
                                <tr>
                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                    <td><?php echo h(trim((string) ($row['vehicle_name'] ?? '')) !== '' ? (string) $row['vehicle_name'] : (string) ($row['vehicle_type'] ?? '')); ?></td>
                                    <td><?php echo h((string) ($row['plate_no'] ?? '')); ?></td>
                                    <td><?php echo h((string) ($row['fuel_type'] ?? '')); ?></td>
                                    <?php foreach ($monthKeys as $key): ?>
                                        <?php $monthValue = (float) ($row[$key] ?? 0); ?>
                                        <td class="text-end"><?php echo $monthValue > 0 ? number_format($monthValue, 2) : ''; ?></td>
                                    <?php endforeach; ?>
                                    <td class="text-end fw-semibold"><?php echo number_format((float) ($row['total_consumed'] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($row['total_purchased'] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($row['total_amount'] ?? 0), 2); ?></td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php for ($i = count($rows); $i < 12; $i++): ?>
                                <tr>
                                    <td class="text-center"><?php echo $i + 1; ?></td>
                                    <td>&nbsp;</td>
                                    <td></td>
                                    <td></td>
                                    <?php foreach ($monthLabels as $unused): ?><td></td><?php endforeach; ?>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-end">TOTAL</th>
                            <?php foreach ($monthTotals as $total): ?>
                                <th class="text-end"><?php echo $total > 0 ? number_format($total, 2) : ''; ?></th>
                            <?php endforeach; ?>
                            <th class="text-end"><?php echo number_format($grandTotalConsumed, 2); ?></th>
                            <th class="text-end"><?php echo number_format($grandTotalPurchased, 2); ?></th>
                            <th class="text-end"><?php echo number_format($grandTotalAmount, 2); ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>

                <div class="annual-fuel-summary">
                    <div><strong>Vehicles Listed:</strong> <?php echo number_format(count($rows)); ?></div>
                    <div><strong>Encoded Entries:</strong> <?php echo number_format($entryCount); ?></div>
                    <div><strong>Total Liters Consumed:</strong> <?php echo number_format($grandTotalConsumed, 2); ?></div>
                </div>

                <table class="annual-fuel-signatures">
                    <tr>
                        <td>
                            <div>Prepared by:</div>
                            <div class="annual-fuel-sign-space"></div>
                            <div class="annual-fuel-sign-line"></div>
                            <div class="annual-fuel-sign-caption">Transport Officer / Authorized Personnel</div>
                        </td>
                        <td>
                            <div>Checked by:</div>
                            <div class="annual-fuel-sign-space"></div>
                            <div class="annual-fuel-sign-line"></div>
                            <div class="annual-fuel-sign-caption">Supply Officer</div>
                        </td>
                        <td>
                            <div>Approved by:</div>
                            <div class="annual-fuel-sign-space"></div>
                            <div class="annual-fuel-sign-line"></div>
                            <div class="annual-fuel-sign-caption">Head of Office</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>

<style>
.annual-fuel-print-wrap {
    overflow-x: auto;
}
.annual-fuel-sheet {
    width: 13in;
    min-height: 8.5in;
    margin: 0 auto;
    padding: 0.25in;
    background: #fff;
    color: #000;
    font-family: "Times New Roman", serif;
    font-size: 11px;
}
.annual-fuel-header,
.annual-fuel-title {
    text-align: center;
}
.annual-fuel-header {
    line-height: 1.2;
}
.annual-fuel-title {
    margin: 0.14in 0 0.12in;
    font-size: 17px;
    font-weight: bold;
    line-height: 1.25;
}
.annual-fuel-title div {
    font-size: 12px;
    font-weight: normal;
}
.annual-fuel-line {
    display: inline-block;
    min-width: 0.8in;
    border-bottom: 1px solid #000;
    font-weight: bold;
}
.annual-fuel-meta,
.annual-fuel-summary {
    display: flex;
    justify-content: space-between;
    gap: 0.2in;
    margin: 0.08in 0;
}
.annual-fuel-table,
.annual-fuel-signatures {
    width: 100%;
    border-collapse: collapse;
}
.annual-fuel-table th,
.annual-fuel-table td {
    border: 1px solid #000;
    padding: 3px 4px;
    vertical-align: middle;
}
.annual-fuel-table th {
    text-align: center;
    font-weight: bold;
}
.annual-fuel-table tbody td {
    height: 24px;
}
.annual-fuel-table tfoot th {
    background: #f2f2f2;
}
.annual-fuel-signatures {
    margin-top: 0.28in;
}
.annual-fuel-signatures td {
    width: 33.33%;
    padding: 0 0.2in;
    vertical-align: top;
}
.annual-fuel-sign-space {
    height: 0.42in;
}
.annual-fuel-sign-line {
    border-bottom: 1px solid #000;
}
.annual-fuel-sign-caption {
    text-align: center;
    font-size: 10px;
    margin-top: 3px;
}
@media print {
    @page {
        size: landscape;
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
    .annual-fuel-print-wrap {
        overflow: visible;
    }
    .annual-fuel-sheet {
        width: 100%;
        min-height: auto;
        padding: 0;
        font-size: 9px;
        transform-origin: top left;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .annual-fuel-table {
        table-layout: fixed;
        width: 100%;
    }
    .annual-fuel-table th,
    .annual-fuel-table td {
        padding: 2px 2px;
        line-height: 1.1;
        word-break: break-word;
    }
    .annual-fuel-title {
        margin: 0.08in 0 0.06in;
        font-size: 14px;
    }
    .annual-fuel-title div,
    .annual-fuel-meta,
    .annual-fuel-summary,
    .annual-fuel-sign-caption {
        font-size: 9px;
    }
    .annual-fuel-signatures {
        margin-top: 0.16in;
    }
    .annual-fuel-sign-space {
        height: 0.28in;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
