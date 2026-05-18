<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Transport Officer');

$tripDb = trip_db();
$page_title = 'Fuel RIS Consolidated Report';
$errors = [];
$vehicles = [];
$vehicleById = [];
$hasTable = false;

$periodType = trim((string) ($_GET['period_type'] ?? 'month'));
if (!in_array($periodType, ['month', 'quarter', 'year'], true)) {
    $periodType = 'month';
}

$periodRef = trim((string) ($_GET['period_ref'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $periodRef)) {
    $periodRef = date('Y-m');
}

$selectedVehicleId = (int) ($_GET['vehicle_id'] ?? 0);
[$selectedYear, $selectedMonth] = array_map('intval', explode('-', $periodRef));
$periodStart = '';
$periodEnd = '';
$periodLabel = '';

if ($periodType === 'quarter') {
    $quarter = (int) ceil($selectedMonth / 3);
    $startMonth = (($quarter - 1) * 3) + 1;
    $periodStart = sprintf('%04d-%02d-01', $selectedYear, $startMonth);
    $periodEnd = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $selectedYear, $startMonth + 2)));
    $periodLabel = 'Q' . $quarter . ' ' . $selectedYear;
} elseif ($periodType === 'year') {
    $periodStart = sprintf('%04d-01-01', $selectedYear);
    $periodEnd = sprintf('%04d-12-31', $selectedYear);
    $periodLabel = (string) $selectedYear;
} else {
    $periodStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $periodEnd = date('Y-m-t', strtotime($periodStart));
    $periodLabel = date('F Y', strtotime($periodStart));
}

$summary = [
    'opening_balance' => 0.0,
    'purchased' => 0.0,
    'consumed' => 0.0,
    'amount' => 0.0,
    'remaining' => 0.0,
];
$vehicleRows = [];
$trendRows = [];

$buildVehicleFilterSql = static function (int $vehicleId, string $alias = 'e'): array {
    if ($vehicleId > 0) {
        return [" AND {$alias}.vehicle_id = ?", 'i', [$vehicleId]];
    }
    return ['', '', []];
};

if (!$tripDb) {
    $errors[] = 'Unable to connect to trip ticket database. Please verify trip DB settings.';
} else {
    $tableCheck = $tripDb->query("SHOW TABLES LIKE 'trip_fuel_ris_entries'");
    $hasTable = $tableCheck && $tableCheck->num_rows > 0;
    if (!$hasTable) {
        $errors[] = 'Fuel RIS table is missing. Run database/097_trip_fuel_ris_entries.sql first.';
    }

    $vehicleResult = $tripDb->query('SELECT id, plate_no, vehicle_name, fuel_type FROM trip_vehicles WHERE is_active = 1 ORDER BY plate_no ASC');
    if ($vehicleResult) {
        $vehicles = $vehicleResult->fetch_all(MYSQLI_ASSOC);
        foreach ($vehicles as $vehicle) {
            $vehicleById[(int) $vehicle['id']] = $vehicle;
        }
    }

    if ($selectedVehicleId > 0 && !isset($vehicleById[$selectedVehicleId])) {
        $errors[] = 'Selected vehicle was not found.';
        $selectedVehicleId = 0;
    }

    if ($hasTable && !$errors) {
        [$vehicleSql, $vehicleTypes, $vehicleParams] = $buildVehicleFilterSql($selectedVehicleId);

        $openingSql = 'SELECT COALESCE(SUM(liters_purchased - liters_consumed), 0) AS opening_balance FROM trip_fuel_ris_entries e WHERE e.ris_date < ?' . $vehicleSql;
        $openingStmt = $tripDb->prepare($openingSql);
        if (!$openingStmt) {
            $errors[] = 'Unable to prepare opening balance query.';
        } else {
            $types = 's' . $vehicleTypes;
            $params = array_merge([$periodStart], $vehicleParams);
            $openingStmt->bind_param($types, ...$params);
            $openingStmt->execute();
            $openingRow = $openingStmt->get_result()->fetch_assoc() ?: [];
            $openingStmt->close();
            $summary['opening_balance'] = (float) ($openingRow['opening_balance'] ?? 0);
        }

        $periodSql = 'SELECT
                COALESCE(SUM(liters_purchased), 0) AS purchased,
                COALESCE(SUM(liters_consumed), 0) AS consumed,
                COALESCE(SUM(amount), 0) AS amount
            FROM trip_fuel_ris_entries e
            WHERE e.ris_date BETWEEN ? AND ?' . $vehicleSql;
        $periodStmt = $tripDb->prepare($periodSql);
        if (!$periodStmt) {
            $errors[] = 'Unable to prepare period summary query.';
        } else {
            $types = 'ss' . $vehicleTypes;
            $params = array_merge([$periodStart, $periodEnd], $vehicleParams);
            $periodStmt->bind_param($types, ...$params);
            $periodStmt->execute();
            $periodRow = $periodStmt->get_result()->fetch_assoc() ?: [];
            $periodStmt->close();

            $summary['purchased'] = (float) ($periodRow['purchased'] ?? 0);
            $summary['consumed'] = (float) ($periodRow['consumed'] ?? 0);
            $summary['amount'] = (float) ($periodRow['amount'] ?? 0);
        }

        $summary['remaining'] = $summary['opening_balance'] + $summary['purchased'] - $summary['consumed'];

        $vehicleSql = "SELECT
                COALESCE(e.vehicle_id, 0) AS vehicle_id,
                COALESCE(NULLIF(TRIM(e.vehicle_plate_no), ''), 'Unassigned') AS vehicle_plate_no,
                COALESCE(NULLIF(TRIM(e.vehicle_name), ''), 'No vehicle linked') AS vehicle_name,
                COALESCE(NULLIF(TRIM(e.fuel_type), ''), 'Fuel') AS fuel_type,
                COALESCE(SUM(e.liters_purchased), 0) AS purchased,
                COALESCE(SUM(e.liters_consumed), 0) AS consumed,
                COALESCE(SUM(e.amount), 0) AS amount
            FROM trip_fuel_ris_entries e
            WHERE e.ris_date BETWEEN ? AND ?" . ($selectedVehicleId > 0 ? ' AND e.vehicle_id = ?' : '') . "
            GROUP BY vehicle_id, vehicle_plate_no, vehicle_name, fuel_type
            ORDER BY consumed DESC, purchased DESC, vehicle_name ASC";

        $vehicleStmt = $tripDb->prepare($vehicleSql);
        if ($vehicleStmt) {
            if ($selectedVehicleId > 0) {
                $vehicleStmt->bind_param('ssi', $periodStart, $periodEnd, $selectedVehicleId);
            } else {
                $vehicleStmt->bind_param('ss', $periodStart, $periodEnd);
            }
            $vehicleStmt->execute();
            $vehicleRows = $vehicleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $vehicleStmt->close();
        }

        $trendSql = "SELECT
                DATE_FORMAT(e.ris_date, '%Y-%m') AS month_key,
                COALESCE(SUM(e.liters_purchased), 0) AS purchased,
                COALESCE(SUM(e.liters_consumed), 0) AS consumed,
                COALESCE(SUM(e.amount), 0) AS amount
            FROM trip_fuel_ris_entries e
            WHERE e.ris_date BETWEEN ? AND ?" . ($selectedVehicleId > 0 ? ' AND e.vehicle_id = ?' : '') . "
            GROUP BY month_key
            ORDER BY month_key ASC";

        $trendStmt = $tripDb->prepare($trendSql);
        if ($trendStmt) {
            if ($selectedVehicleId > 0) {
                $trendStmt->bind_param('ssi', $periodStart, $periodEnd, $selectedVehicleId);
            } else {
                $trendStmt->bind_param('ss', $periodStart, $periodEnd);
            }
            $trendStmt->execute();
            $trendRows = $trendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $trendStmt->close();
        }
    }
}

$selectedVehicle = $selectedVehicleId > 0 ? ($vehicleById[$selectedVehicleId] ?? null) : null;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Fuel RIS Consolidated Report</h4>
            <div class="text-muted">Monthly, quarterly, and annual fuel purchase and consumption analytics.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo base_url('modules/trip_tickets/fuel_ris.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-fuel-pump me-1"></i> Fuel RIS Encoding
            </a>
            <button type="button" class="btn btn-primary" onclick="window.print();">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm mb-3 no-print">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Period Type</label>
                    <select name="period_type" class="form-select">
                        <option value="month" <?php echo $periodType === 'month' ? 'selected' : ''; ?>>Monthly</option>
                        <option value="quarter" <?php echo $periodType === 'quarter' ? 'selected' : ''; ?>>Quarterly</option>
                        <option value="year" <?php echo $periodType === 'year' ? 'selected' : ''; ?>>Annual</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Reference Month</label>
                    <input type="month" name="period_ref" class="form-control" value="<?php echo h($periodRef); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Vehicle</label>
                    <select name="vehicle_id" class="form-select">
                        <option value="0">All vehicles</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo (int) $vehicle['id']; ?>" <?php echo $selectedVehicleId === (int) $vehicle['id'] ? 'selected' : ''; ?>>
                                <?php echo h((string) $vehicle['plate_no'] . ' - ' . (string) $vehicle['vehicle_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">Generate</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($hasTable && !$errors): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h5 class="mb-1">Summary for <?php echo h($periodLabel); ?></h5>
                        <div class="text-muted small">
                            Coverage: <?php echo h(format_date($periodStart)); ?> to <?php echo h(format_date($periodEnd)); ?>
                            <?php if ($selectedVehicle): ?>
                                | Vehicle: <?php echo h((string) $selectedVehicle['plate_no'] . ' - ' . (string) $selectedVehicle['vehicle_name']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6 col-lg-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Opening Balance (L)</div>
                            <div class="fs-4 fw-semibold"><?php echo number_format($summary['opening_balance'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Purchased This Period (L)</div>
                            <div class="fs-4 fw-semibold text-success"><?php echo number_format($summary['purchased'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Consumed This Period (L)</div>
                            <div class="fs-4 fw-semibold text-danger"><?php echo number_format($summary['consumed'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Remaining Balance (L)</div>
                            <div class="fs-4 fw-semibold <?php echo $summary['remaining'] < 0 ? 'text-danger' : 'text-primary'; ?>">
                                <?php echo number_format($summary['remaining'], 2); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 text-muted">
                    Total amount purchased in period: <strong><?php echo number_format($summary['amount'], 2); ?></strong>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="card-title">Consumption by Vehicle</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Vehicle</th>
                                        <th>Fuel Type</th>
                                        <th class="text-end">Purchased (L)</th>
                                        <th class="text-end">Consumed (L)</th>
                                        <th class="text-end">Remaining (L)</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$vehicleRows): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">No rows found for selected period.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($vehicleRows as $row): ?>
                                            <?php
                                            $purchased = (float) ($row['purchased'] ?? 0);
                                            $consumed = (float) ($row['consumed'] ?? 0);
                                            $remaining = $purchased - $consumed;
                                            $amount = (float) ($row['amount'] ?? 0);
                                            ?>
                                            <tr>
                                                <td>
                                                    <div><?php echo h((string) ($row['vehicle_plate_no'] ?? '')); ?></div>
                                                    <small class="text-muted"><?php echo h((string) ($row['vehicle_name'] ?? '')); ?></small>
                                                </td>
                                                <td><?php echo h((string) ($row['fuel_type'] ?? '')); ?></td>
                                                <td class="text-end"><?php echo number_format($purchased, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($consumed, 2); ?></td>
                                                <td class="text-end <?php echo $remaining < 0 ? 'text-danger' : ''; ?>"><?php echo number_format($remaining, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($amount, 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="card-title">Monthly Trend (Within Selected Period)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-end">Purchased (L)</th>
                                        <th class="text-end">Consumed (L)</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$trendRows): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No trend data for selected period.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($trendRows as $row): ?>
                                            <tr>
                                                <td><?php echo h(date('F Y', strtotime((string) $row['month_key'] . '-01'))); ?></td>
                                                <td class="text-end"><?php echo number_format((float) ($row['purchased'] ?? 0), 2); ?></td>
                                                <td class="text-end"><?php echo number_format((float) ($row['consumed'] ?? 0), 2); ?></td>
                                                <td class="text-end"><?php echo number_format((float) ($row['amount'] ?? 0), 2); ?></td>
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
    <?php endif; ?>
</section>

<style>
@media print {
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

    .section {
        padding: 0 !important;
    }
}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
