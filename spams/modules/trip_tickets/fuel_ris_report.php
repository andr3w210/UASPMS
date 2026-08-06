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
$hasGasStationTable = false;
$dieselBalanceAsOfDate = '2026-04-30';
$dieselBalanceAsOfLiters = 6173.32;

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
$detailRows = [];
$monthlyConsumptionRows = [];
$quarterlyConsumptionRows = [];
$annualConsumptionRows = [];
$monthlyChartData = ['labels' => [], 'datasets' => []];
$quarterlyChartData = ['labels' => [], 'datasets' => []];
$annualChartData = ['labels' => [], 'datasets' => []];

$buildVehicleFilterSql = static function (int $vehicleId, string $alias = 'e') use (&$hasVehicleId): array {
    if ($vehicleId > 0 && $hasVehicleId) {
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

    $fuelColumns = [];
    $hasVehicleId = false;
    $hasVehiclePlateNo = false;
    $hasVehicleName = false;
    $hasFuelType = false;
    $hasLitersPurchased = false;
    $hasLitersConsumed = false;
    $hasAmount = false;
    $hasQuantity = false;
    $hasUnit = false;
    $hasPurpose = false;
    $hasDriverName = false;
    $hasSourceTag = false;
    $hasGasStationId = false;
    $hasGasStationName = false;
    $hasRisNo = false;

    if ($hasTable && !$errors) {
        $columnResult = $tripDb->query("SHOW COLUMNS FROM trip_fuel_ris_entries");
        if ($columnResult) {
            while ($column = $columnResult->fetch_assoc()) {
                $fieldName = strtolower((string) ($column['Field'] ?? ''));
                if ($fieldName !== '') {
                    $fuelColumns[$fieldName] = true;
                }
            }
        }

        $hasRisDate = isset($fuelColumns['ris_date']);
        $hasVehicleId = isset($fuelColumns['vehicle_id']);
        $hasVehiclePlateNo = isset($fuelColumns['vehicle_plate_no']);
        $hasVehicleName = isset($fuelColumns['vehicle_name']);
        $hasFuelType = isset($fuelColumns['fuel_type']);
        $hasLitersPurchased = isset($fuelColumns['liters_purchased']);
        $hasLitersConsumed = isset($fuelColumns['liters_consumed']);
        $hasAmount = isset($fuelColumns['amount']);
        $hasQuantity = isset($fuelColumns['quantity']);
        $hasUnit = isset($fuelColumns['unit']);
        $hasPurpose = isset($fuelColumns['purpose']);
        $hasDriverName = isset($fuelColumns['driver_name']);
        $hasSourceTag = isset($fuelColumns['source_tag']);
        $hasGasStationId = isset($fuelColumns['gas_station_id']);
        $hasGasStationName = isset($fuelColumns['gas_station_name']);
        $hasRisNo = isset($fuelColumns['ris_no']);

        if (!$hasRisDate || !$hasLitersPurchased || !$hasLitersConsumed || !$hasAmount) {
            $errors[] = 'Fuel RIS table is missing core columns required by reports.';
        }

        if ($selectedVehicleId > 0 && !$hasVehicleId) {
            $selectedVehicleId = 0;
        }

        $stationTableCheck = $tripDb->query("SHOW TABLES LIKE 'trip_gas_stations'");
        $hasGasStationTable = $stationTableCheck && $stationTableCheck->num_rows > 0;
    }

    if ($hasTable && !$errors) {
        try {
            [$vehicleSql, $vehicleTypes, $vehicleParams] = $buildVehicleFilterSql($selectedVehicleId);
            $consumedExpr = $hasQuantity
                ? 'CASE WHEN COALESCE(e.liters_consumed, 0) > 0 THEN e.liters_consumed WHEN COALESCE(e.quantity, 0) > 0 THEN e.quantity WHEN COALESCE(e.liters_purchased, 0) > 0 THEN e.liters_purchased ELSE 0 END'
                : 'CASE WHEN COALESCE(e.liters_consumed, 0) > 0 THEN e.liters_consumed WHEN COALESCE(e.liters_purchased, 0) > 0 THEN e.liters_purchased ELSE 0 END';
            $purchasedExpr = $hasQuantity
                ? 'CASE WHEN COALESCE(e.liters_consumed, 0) <= 0 AND COALESCE(e.quantity, 0) > 0 AND ABS(COALESCE(e.liters_purchased, 0) - COALESCE(e.quantity, 0)) < 0.0001 THEN 0 ELSE COALESCE(e.liters_purchased, 0) END'
                : 'COALESCE(e.liters_purchased, 0)';
            $dieselSql = $hasFuelType ? " AND LOWER(TRIM(COALESCE(e.fuel_type, ''))) = 'diesel'" : '';
            $movementSql = static function (mysqli $tripDb, string $startDate, string $endDate, string $purchasedExpr, string $consumedExpr, string $dieselSql): float {
                if ($startDate > $endDate) {
                    return 0.0;
                }

                $sql = 'SELECT COALESCE(SUM(' . $purchasedExpr . ' - ' . $consumedExpr . '), 0) AS movement
                    FROM trip_fuel_ris_entries e
                    WHERE e.ris_date BETWEEN ? AND ?' . $dieselSql;
                $stmt = $tripDb->prepare($sql);
                if (!$stmt) {
                    return 0.0;
                }

                $stmt->bind_param('ss', $startDate, $endDate);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();

                return (float) ($row['movement'] ?? 0);
            };
            $balanceAsOf = static function (mysqli $tripDb, string $targetDate, string $anchorDate, float $anchorBalance, string $purchasedExpr, string $consumedExpr, string $dieselSql) use ($movementSql): float {
                if ($targetDate === $anchorDate) {
                    return $anchorBalance;
                }

                if ($targetDate > $anchorDate) {
                    $startDate = date('Y-m-d', strtotime($anchorDate . ' +1 day'));
                    return $anchorBalance + $movementSql($tripDb, $startDate, $targetDate, $purchasedExpr, $consumedExpr, $dieselSql);
                }

                $startDate = date('Y-m-d', strtotime($targetDate . ' +1 day'));
                return $anchorBalance - $movementSql($tripDb, $startDate, $anchorDate, $purchasedExpr, $consumedExpr, $dieselSql);
            };

            $openingSql = 'SELECT COALESCE(SUM(' . $purchasedExpr . ' - ' . $consumedExpr . '), 0) AS opening_balance FROM trip_fuel_ris_entries e WHERE e.ris_date < ?' . $vehicleSql;
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
                COALESCE(SUM(' . $purchasedExpr . '), 0) AS purchased,
                COALESCE(SUM(' . $consumedExpr . '), 0) AS consumed,
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

            if ($selectedVehicleId === 0) {
                $openingAsOfDate = date('Y-m-d', strtotime($periodStart . ' -1 day'));
                $summary['opening_balance'] = $balanceAsOf($tripDb, $openingAsOfDate, $dieselBalanceAsOfDate, $dieselBalanceAsOfLiters, $purchasedExpr, $consumedExpr, $dieselSql);
                $summary['remaining'] = $balanceAsOf($tripDb, $periodEnd, $dieselBalanceAsOfDate, $dieselBalanceAsOfLiters, $purchasedExpr, $consumedExpr, $dieselSql);
            } else {
                $summary['remaining'] = $summary['opening_balance'] + $summary['purchased'] - $summary['consumed'];
            }

            $vehicleSql = "SELECT
                " . ($hasVehicleId ? "COALESCE(e.vehicle_id, 0)" : "0") . " AS vehicle_id,
                " . ($hasVehiclePlateNo ? "COALESCE(NULLIF(TRIM(e.vehicle_plate_no), ''), 'Unassigned')" : "'Unassigned'") . " AS vehicle_plate_no,
                " . ($hasVehicleName ? "COALESCE(NULLIF(TRIM(e.vehicle_name), ''), 'No vehicle linked')" : "'No vehicle linked'") . " AS vehicle_name,
                " . ($hasFuelType ? "COALESCE(NULLIF(TRIM(e.fuel_type), ''), 'Fuel')" : "'Fuel'") . " AS fuel_type,
                COALESCE(SUM({$purchasedExpr}), 0) AS purchased,
                COALESCE(SUM({$consumedExpr}), 0) AS consumed,
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
                COALESCE(SUM({$purchasedExpr}), 0) AS purchased,
                COALESCE(SUM({$consumedExpr}), 0) AS consumed,
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

            if ($hasGasStationTable && $hasGasStationId) {
                if ($hasGasStationName) {
                    $stationSelectExpr = "COALESCE(NULLIF(TRIM(gs.station_name), ''), COALESCE(NULLIF(TRIM(e.gas_station_name), ''), '-'))";
                } else {
                    $stationSelectExpr = "COALESCE(NULLIF(TRIM(gs.station_name), ''), '-')";
                }
            } else {
                $stationSelectExpr = $hasGasStationName
                    ? "COALESCE(NULLIF(TRIM(e.gas_station_name), ''), '-')"
                    : "'-'";
            }
            $stationJoinSql = ($hasGasStationTable && $hasGasStationId)
                ? "LEFT JOIN trip_gas_stations gs ON gs.id = e.gas_station_id"
                : "";

            $detailSql = "SELECT
                e.ris_date,
                " . ($hasRisNo ? "e.ris_no" : "''") . " AS ris_no,
                " . ($hasVehiclePlateNo ? "COALESCE(NULLIF(TRIM(e.vehicle_plate_no), ''), '-')" : "'-'") . " AS vehicle_plate_no,
                " . ($hasVehicleName ? "COALESCE(NULLIF(TRIM(e.vehicle_name), ''), 'No vehicle linked')" : "'No vehicle linked'") . " AS vehicle_name,
                " . ($hasFuelType ? "COALESCE(NULLIF(TRIM(e.fuel_type), ''), 'Fuel')" : "'Fuel'") . " AS fuel_type,
                {$stationSelectExpr} AS gas_station_name,
                {$purchasedExpr} AS liters_purchased,
                {$consumedExpr} AS liters_consumed,
                COALESCE(e.amount, 0) AS amount,
                " . ($hasQuantity ? "COALESCE(e.quantity, 0)" : "0") . " AS quantity,
                " . ($hasUnit ? "COALESCE(NULLIF(TRIM(e.unit), ''), '-')" : "'-'") . " AS unit,
                " . ($hasPurpose ? "COALESCE(NULLIF(TRIM(e.purpose), ''), '-')" : "'-'") . " AS purpose,
                " . ($hasDriverName ? "COALESCE(NULLIF(TRIM(e.driver_name), ''), '-')" : "'-'") . " AS driver_name,
                " . ($hasSourceTag ? "COALESCE(NULLIF(TRIM(e.source_tag), ''), '-')" : "'-'") . " AS source_tag
            FROM trip_fuel_ris_entries e
            {$stationJoinSql}
            WHERE e.ris_date BETWEEN ? AND ?" . ($selectedVehicleId > 0 ? ' AND e.vehicle_id = ?' : '') . "
            ORDER BY e.ris_date DESC, e.id DESC";

        $detailStmt = $tripDb->prepare($detailSql);
        if ($detailStmt) {
            if ($selectedVehicleId > 0) {
                $detailStmt->bind_param('ssi', $periodStart, $periodEnd, $selectedVehicleId);
            } else {
                $detailStmt->bind_param('ss', $periodStart, $periodEnd);
            }
            $detailStmt->execute();
            $detailRows = $detailStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $detailStmt->close();
        }

            $monthlySql = "SELECT
                " . ($hasVehicleId ? "COALESCE(e.vehicle_id, 0)" : "0") . " AS vehicle_id,
                " . ($hasVehiclePlateNo ? "COALESCE(NULLIF(TRIM(e.vehicle_plate_no), ''), 'Unassigned')" : "'Unassigned'") . " AS vehicle_plate_no,
                " . ($hasVehicleName ? "COALESCE(NULLIF(TRIM(e.vehicle_name), ''), 'No vehicle linked')" : "'No vehicle linked'") . " AS vehicle_name,
                MONTH(e.ris_date) AS month_no,
                COALESCE(SUM({$consumedExpr}), 0) AS consumed
            FROM trip_fuel_ris_entries e
            WHERE YEAR(e.ris_date) = ?" . ($selectedVehicleId > 0 ? ' AND e.vehicle_id = ?' : '') . "
            GROUP BY vehicle_id, vehicle_plate_no, vehicle_name, month_no
            ORDER BY vehicle_name ASC, month_no ASC";
        $monthlyStmt = $tripDb->prepare($monthlySql);
        if ($monthlyStmt) {
            if ($selectedVehicleId > 0) {
                $monthlyStmt->bind_param('ii', $selectedYear, $selectedVehicleId);
            } else {
                $monthlyStmt->bind_param('i', $selectedYear);
            }
            $monthlyStmt->execute();
            $monthlyConsumptionRows = $monthlyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $monthlyStmt->close();
        }

            $quarterlySql = "SELECT
                " . ($hasVehicleId ? "COALESCE(e.vehicle_id, 0)" : "0") . " AS vehicle_id,
                " . ($hasVehiclePlateNo ? "COALESCE(NULLIF(TRIM(e.vehicle_plate_no), ''), 'Unassigned')" : "'Unassigned'") . " AS vehicle_plate_no,
                " . ($hasVehicleName ? "COALESCE(NULLIF(TRIM(e.vehicle_name), ''), 'No vehicle linked')" : "'No vehicle linked'") . " AS vehicle_name,
                QUARTER(e.ris_date) AS quarter_no,
                COALESCE(SUM({$consumedExpr}), 0) AS consumed
            FROM trip_fuel_ris_entries e
            WHERE YEAR(e.ris_date) = ?" . ($selectedVehicleId > 0 ? ' AND e.vehicle_id = ?' : '') . "
            GROUP BY vehicle_id, vehicle_plate_no, vehicle_name, quarter_no
            ORDER BY vehicle_name ASC, quarter_no ASC";
        $quarterlyStmt = $tripDb->prepare($quarterlySql);
        if ($quarterlyStmt) {
            if ($selectedVehicleId > 0) {
                $quarterlyStmt->bind_param('ii', $selectedYear, $selectedVehicleId);
            } else {
                $quarterlyStmt->bind_param('i', $selectedYear);
            }
            $quarterlyStmt->execute();
            $quarterlyConsumptionRows = $quarterlyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $quarterlyStmt->close();
        }

        $annualStartYear = max(2000, $selectedYear - 4);
            $annualSql = "SELECT
                " . ($hasVehicleId ? "COALESCE(e.vehicle_id, 0)" : "0") . " AS vehicle_id,
                " . ($hasVehiclePlateNo ? "COALESCE(NULLIF(TRIM(e.vehicle_plate_no), ''), 'Unassigned')" : "'Unassigned'") . " AS vehicle_plate_no,
                " . ($hasVehicleName ? "COALESCE(NULLIF(TRIM(e.vehicle_name), ''), 'No vehicle linked')" : "'No vehicle linked'") . " AS vehicle_name,
                YEAR(e.ris_date) AS year_no,
                COALESCE(SUM({$consumedExpr}), 0) AS consumed
            FROM trip_fuel_ris_entries e
            WHERE YEAR(e.ris_date) BETWEEN ? AND ?" . ($selectedVehicleId > 0 ? ' AND e.vehicle_id = ?' : '') . "
            GROUP BY vehicle_id, vehicle_plate_no, vehicle_name, year_no
            ORDER BY year_no ASC, vehicle_name ASC";
        $annualStmt = $tripDb->prepare($annualSql);
        if ($annualStmt) {
            if ($selectedVehicleId > 0) {
                $annualStmt->bind_param('iii', $annualStartYear, $selectedYear, $selectedVehicleId);
            } else {
                $annualStmt->bind_param('ii', $annualStartYear, $selectedYear);
            }
            $annualStmt->execute();
            $annualConsumptionRows = $annualStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $annualStmt->close();
        }

            $palette = ['#1f77b4', '#e4572e', '#2e933c', '#7b5ea7', '#ff9f1c', '#17a2b8', '#6f42c1', '#d63384', '#198754', '#fd7e14'];
            $buildVehicleLabel = static function (array $row): string {
                return trim((string) ($row['vehicle_plate_no'] ?? '-') . ' - ' . (string) ($row['vehicle_name'] ?? 'No vehicle linked'));
            };
            $buildDatasets = static function (array $seriesMap, array $palette): array {
                $datasets = [];
                $index = 0;
                foreach ($seriesMap as $vehicleLabel => $values) {
                    $color = $palette[$index % count($palette)];
                    $datasets[] = [
                        'label' => $vehicleLabel,
                        'data' => array_map(static function ($value) {
                            return round((float) $value, 2);
                        }, $values),
                        'backgroundColor' => $color,
                        'borderColor' => $color,
                        'borderWidth' => 2,
                        'tension' => 0.25,
                    ];
                    $index++;
                }
                return $datasets;
            };

            $monthlyLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $monthlySeriesMap = [];
            foreach ($monthlyConsumptionRows as $row) {
                $vehicleLabel = $buildVehicleLabel($row);
                if (!isset($monthlySeriesMap[$vehicleLabel])) {
                    $monthlySeriesMap[$vehicleLabel] = array_fill(0, 12, 0.0);
                }
                $monthNo = (int) ($row['month_no'] ?? 0);
                if ($monthNo >= 1 && $monthNo <= 12) {
                    $monthlySeriesMap[$vehicleLabel][$monthNo - 1] = (float) ($row['consumed'] ?? 0);
                }
            }
            $monthlyChartData = [
                'labels' => $monthlyLabels,
                'datasets' => $buildDatasets($monthlySeriesMap, $palette),
            ];

            $quarterlyLabels = ['Q1', 'Q2', 'Q3', 'Q4'];
            $quarterlySeriesMap = [];
            foreach ($quarterlyConsumptionRows as $row) {
                $vehicleLabel = $buildVehicleLabel($row);
                if (!isset($quarterlySeriesMap[$vehicleLabel])) {
                    $quarterlySeriesMap[$vehicleLabel] = array_fill(0, 4, 0.0);
                }
                $quarterNo = (int) ($row['quarter_no'] ?? 0);
                if ($quarterNo >= 1 && $quarterNo <= 4) {
                    $quarterlySeriesMap[$vehicleLabel][$quarterNo - 1] = (float) ($row['consumed'] ?? 0);
                }
            }
            $quarterlyChartData = [
                'labels' => $quarterlyLabels,
                'datasets' => $buildDatasets($quarterlySeriesMap, $palette),
            ];

            $annualLabels = [];
            for ($y = $annualStartYear; $y <= $selectedYear; $y++) {
                $annualLabels[] = (string) $y;
            }
            $annualSeriesMap = [];
            foreach ($annualConsumptionRows as $row) {
                $vehicleLabel = $buildVehicleLabel($row);
                if (!isset($annualSeriesMap[$vehicleLabel])) {
                    $annualSeriesMap[$vehicleLabel] = array_fill(0, count($annualLabels), 0.0);
                }
                $yearNo = (int) ($row['year_no'] ?? 0);
                $yearIndex = array_search((string) $yearNo, $annualLabels, true);
                if ($yearIndex !== false) {
                    $annualSeriesMap[$vehicleLabel][$yearIndex] = (float) ($row['consumed'] ?? 0);
                }
            }
            $annualChartData = [
                'labels' => $annualLabels,
                'datasets' => $buildDatasets($annualSeriesMap, $palette),
            ];
        } catch (Throwable $exception) {
            $errors[] = 'Unable to load Fuel RIS analytics. ' . $exception->getMessage();
            $vehicleRows = [];
            $trendRows = [];
            $detailRows = [];
            $monthlyConsumptionRows = [];
            $quarterlyConsumptionRows = [];
            $annualConsumptionRows = [];
            $monthlyChartData = ['labels' => [], 'datasets' => []];
            $quarterlyChartData = ['labels' => [], 'datasets' => []];
            $annualChartData = ['labels' => [], 'datasets' => []];
        }
    }
}

$selectedVehicle = $selectedVehicleId > 0 ? ($vehicleById[$selectedVehicleId] ?? null) : null;
$periodChartRows = [
    'Opening Balance' => $summary['opening_balance'],
    'Purchased' => $summary['purchased'],
    'Consumed' => $summary['consumed'],
    'Remaining Balance' => max(0, $summary['remaining']),
];
$maxPeriodChartValue = $periodChartRows ? max($periodChartRows) : 0.0;
$vehicleChartRows = [];
foreach (array_slice($vehicleRows, 0, 6) as $row) {
    $vehicleLabel = trim((string) ($row['vehicle_plate_no'] ?? 'Unassigned') . ' - ' . (string) ($row['vehicle_name'] ?? 'No vehicle linked'));
    $vehicleChartRows[$vehicleLabel] = (float) ($row['consumed'] ?? 0);
}
$maxVehicleChartValue = $vehicleChartRows ? max($vehicleChartRows) : 0.0;
$trendChartRows = [];
foreach ($trendRows as $row) {
    $trendLabel = date('M Y', strtotime((string) ($row['month_key'] ?? '') . '-01'));
    $trendChartRows[$trendLabel] = (float) ($row['consumed'] ?? 0);
}
$maxTrendChartValue = $trendChartRows ? max($trendChartRows) : 0.0;

if (($_GET['export'] ?? '') === 'csv' && $hasTable && !$errors) {
    stream_csv_download(
        'fuel_ris_report_' . date('Ymd_His') . '.csv',
        ['RIS Date', 'RIS No', 'Vehicle Plate No', 'Vehicle Name', 'Fuel Type', 'Gas Station', 'Liters Purchased', 'Liters Consumed', 'Amount', 'Quantity', 'Unit', 'Driver', 'Purpose', 'Source'],
        $detailRows,
        static function (array $row): array {
            return [
                $row['ris_date'] ?? '',
                $row['ris_no'] ?? '',
                $row['vehicle_plate_no'] ?? '',
                $row['vehicle_name'] ?? '',
                $row['fuel_type'] ?? '',
                $row['gas_station_name'] ?? '',
                number_format((float) ($row['liters_purchased'] ?? 0), 2, '.', ''),
                number_format((float) ($row['liters_consumed'] ?? 0), 2, '.', ''),
                number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                number_format((float) ($row['quantity'] ?? 0), 2, '.', ''),
                $row['unit'] ?? '',
                $row['driver_name'] ?? '',
                $row['purpose'] ?? '',
                $row['source_tag'] ?? '',
            ];
        }
    );
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section fuel-report-page">
    <div class="fuel-report-toolbar no-print">
        <div>
            <h4 class="mb-1">Fuel RIS Report</h4>
            <div class="text-muted">Clear monthly, quarterly, and annual fuel movement summary.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?php echo base_url('modules/trip_tickets/fuel_ris.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-fuel-pump me-1"></i> Fuel RIS Encoding
            </a>
            <a href="<?php echo h(base_url('modules/trip_tickets/fuel_ris_report.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))); ?>" class="btn btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
            </a>
            <button type="button" class="btn btn-primary" onclick="window.print();">
                <i class="bi bi-printer me-1"></i> Print Report
            </button>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <div class="fuel-report-filter no-print">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Report Period</label>
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
                <button type="submit" class="btn btn-primary">Load Report</button>
            </div>
        </form>
    </div>

    <?php if ($hasTable && !$errors): ?>
        <article class="fuel-print-sheet">
            <header class="fuel-print-header">
                <div>
                    <div class="fuel-print-kicker">Fuel RIS Consolidated Report</div>
                    <h2><?php echo h($periodLabel); ?></h2>
                    <div class="fuel-print-meta">
                        <?php echo h(format_date($periodStart)); ?> to <?php echo h(format_date($periodEnd)); ?>
                        <span class="fuel-print-dot"></span>
                        <?php echo h($selectedVehicle ? (string) $selectedVehicle['plate_no'] . ' - ' . (string) $selectedVehicle['vehicle_name'] : 'All vehicles'); ?>
                    </div>
                </div>
                <div class="fuel-print-aside">
                    <span>Prepared</span>
                    <strong><?php echo h(date('F j, Y')); ?></strong>
                </div>
            </header>

            <?php if (!$selectedVehicle): ?>
                <div class="fuel-report-note">
                    Diesel inventory balance is anchored to the physical record as of
                    <strong><?php echo h(format_date($dieselBalanceAsOfDate)); ?></strong>:
                    <strong><?php echo number_format($dieselBalanceAsOfLiters, 2); ?> L</strong>.
                </div>
            <?php endif; ?>

            <section class="fuel-summary-grid">
                <div class="fuel-summary-card">
                    <span>Opening Balance</span>
                    <strong><?php echo number_format($summary['opening_balance'], 2); ?> L</strong>
                </div>
                <div class="fuel-summary-card">
                    <span>Purchased</span>
                    <strong><?php echo number_format($summary['purchased'], 2); ?> L</strong>
                </div>
                <div class="fuel-summary-card">
                    <span>Consumed</span>
                    <strong><?php echo number_format($summary['consumed'], 2); ?> L</strong>
                </div>
                <div class="fuel-summary-card emphasis">
                    <span>Remaining Balance</span>
                    <strong><?php echo number_format($summary['remaining'], 2); ?> L</strong>
                </div>
            </section>

            <section class="fuel-chart-grid">
                <div class="fuel-chart-card">
                    <h5>Period Movement</h5>
                    <?php foreach ($periodChartRows as $label => $value): ?>
                        <?php $barWidth = $maxPeriodChartValue > 0 ? max(3, min(100, ($value / $maxPeriodChartValue) * 100)) : 0; ?>
                        <div class="fuel-bar-row">
                            <span class="fuel-bar-label"><?php echo h((string) $label); ?></span>
                            <span class="fuel-bar-track"><span class="fuel-bar-fill tone-blue" style="width: <?php echo h(number_format($barWidth, 2, '.', '')); ?>%;"></span></span>
                            <strong><?php echo number_format((float) $value, 2); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="fuel-chart-card">
                    <h5>Consumption by Vehicle</h5>
                    <?php if (!$vehicleChartRows): ?>
                        <div class="fuel-empty">No vehicle consumption for this period.</div>
                    <?php else: ?>
                        <?php foreach ($vehicleChartRows as $label => $value): ?>
                            <?php $barWidth = $maxVehicleChartValue > 0 ? max(3, min(100, ($value / $maxVehicleChartValue) * 100)) : 0; ?>
                            <div class="fuel-bar-row">
                                <span class="fuel-bar-label"><?php echo h((string) $label); ?></span>
                                <span class="fuel-bar-track"><span class="fuel-bar-fill tone-green" style="width: <?php echo h(number_format($barWidth, 2, '.', '')); ?>%;"></span></span>
                                <strong><?php echo number_format((float) $value, 2); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="fuel-chart-card">
                    <h5>Consumption Trend</h5>
                    <?php if (!$trendChartRows): ?>
                        <div class="fuel-empty">No trend data for this period.</div>
                    <?php else: ?>
                        <?php foreach ($trendChartRows as $label => $value): ?>
                            <?php $barWidth = $maxTrendChartValue > 0 ? max(3, min(100, ($value / $maxTrendChartValue) * 100)) : 0; ?>
                            <div class="fuel-bar-row">
                                <span class="fuel-bar-label"><?php echo h((string) $label); ?></span>
                                <span class="fuel-bar-track"><span class="fuel-bar-fill tone-amber" style="width: <?php echo h(number_format($barWidth, 2, '.', '')); ?>%;"></span></span>
                                <strong><?php echo number_format((float) $value, 2); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="fuel-table-card">
                <div class="fuel-section-title">
                    <h5>Vehicle Consumption Summary</h5>
                    <span><?php echo number_format(count($detailRows)); ?> encoded entries</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle fuel-report-table">
                        <thead>
                            <tr>
                                <th>Vehicle</th>
                                <th>Fuel</th>
                                <th class="text-end">Purchased (L)</th>
                                <th class="text-end">Consumed (L)</th>
                                <th class="text-end">Net Movement (L)</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$vehicleRows): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No vehicle consumption for this period.</td>
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
                                            <strong><?php echo h((string) ($row['vehicle_plate_no'] ?? 'Unassigned')); ?></strong>
                                            <div class="text-muted small"><?php echo h((string) ($row['vehicle_name'] ?? 'No vehicle linked')); ?></div>
                                        </td>
                                        <td><?php echo h((string) ($row['fuel_type'] ?? 'Fuel')); ?></td>
                                        <td class="text-end"><?php echo number_format($purchased, 2); ?></td>
                                        <td class="text-end fw-semibold"><?php echo number_format($consumed, 2); ?></td>
                                        <td class="text-end <?php echo $remaining < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo number_format($remaining, 2); ?></td>
                                        <td class="text-end"><?php echo number_format($amount, 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </article>
    <?php endif; ?>
</section>

<style>
.fuel-report-page {
    color: #12233a;
}

.fuel-report-toolbar,
.fuel-print-header,
.fuel-section-title {
    align-items: flex-start;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
}

.fuel-report-filter,
.fuel-print-sheet,
.fuel-summary-card,
.fuel-chart-card,
.fuel-table-card {
    background: #ffffff;
    border: 1px solid rgba(95, 111, 137, 0.14);
    border-radius: 14px;
    box-shadow: 0 0 28px rgba(1, 41, 112, 0.07);
}

.fuel-report-filter,
.fuel-print-sheet {
    margin-top: 1rem;
    padding: 1rem;
}

.fuel-print-header {
    border-bottom: 1px solid rgba(95, 111, 137, 0.16);
    margin-bottom: 0.85rem;
    padding-bottom: 0.85rem;
}

.fuel-print-kicker,
.fuel-summary-card span,
.fuel-section-title span {
    color: #64748b;
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.fuel-print-header h2 {
    color: #12233a;
    font-size: 1.7rem;
    font-weight: 800;
    margin: 0.2rem 0;
}

.fuel-print-meta {
    color: #475569;
    font-size: 0.95rem;
}

.fuel-print-dot::before {
    content: " | ";
    color: #94a3b8;
}

.fuel-print-aside {
    text-align: right;
}

.fuel-print-aside span {
    color: #64748b;
    display: block;
    font-size: 0.78rem;
}

.fuel-report-note {
    background: #f8fafc;
    border: 1px solid rgba(95, 111, 137, 0.16);
    border-radius: 10px;
    color: #334155;
    margin-bottom: 0.85rem;
    padding: 0.7rem 0.85rem;
}

.fuel-summary-grid {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin-bottom: 0.85rem;
}

.fuel-summary-card {
    box-shadow: none;
    padding: 0.85rem;
}

.fuel-summary-card strong {
    color: #12233a;
    display: block;
    font-size: 1.22rem;
    font-weight: 800;
    margin-top: 0.25rem;
}

.fuel-summary-card.emphasis {
    background: #eef6ff;
    border-color: rgba(37, 99, 235, 0.22);
}

.fuel-chart-grid {
    display: grid;
    gap: 0.85rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-bottom: 0.85rem;
}

.fuel-chart-card {
    box-shadow: none;
    padding: 0.9rem;
}

.fuel-chart-card h5,
.fuel-section-title h5 {
    color: #12233a;
    font-size: 1rem;
    font-weight: 800;
    margin: 0 0 0.75rem;
}

.fuel-bar-row {
    align-items: center;
    display: grid;
    gap: 0.55rem;
    grid-template-columns: minmax(115px, 0.9fr) minmax(110px, 1fr) minmax(74px, auto);
    margin-bottom: 0.62rem;
}

.fuel-bar-label {
    color: #334155;
    font-size: 0.82rem;
    font-weight: 700;
    line-height: 1.2;
    min-width: 0;
    overflow-wrap: anywhere;
}

.fuel-bar-track {
    background: #e2e8f0;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    height: 14px;
    overflow: hidden;
}

.fuel-bar-fill {
    border-radius: inherit;
    display: block;
    height: 100%;
    min-width: 4px;
}

.fuel-bar-fill.tone-blue {
    background: #2563eb;
}

.fuel-bar-fill.tone-green {
    background: #16a34a;
}

.fuel-bar-fill.tone-amber {
    background: #d97706;
}

.fuel-bar-row strong {
    color: #12233a;
    font-size: 0.82rem;
    text-align: right;
    white-space: nowrap;
}

.fuel-empty {
    color: #64748b;
    font-size: 0.9rem;
    padding: 1rem 0;
}

.fuel-table-card {
    box-shadow: none;
    padding: 0.9rem;
}

.fuel-report-table {
    margin-bottom: 0;
}

.fuel-report-table thead th {
    background: #f8fafc;
    color: #475569;
}

@media (max-width: 1199px) {
    .fuel-summary-grid,
    .fuel-chart-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 767px) {
    .fuel-report-toolbar,
    .fuel-print-header,
    .fuel-section-title {
        display: block;
    }

    .fuel-print-aside {
        margin-top: 0.75rem;
        text-align: left;
    }

    .fuel-summary-grid,
    .fuel-chart-grid {
        grid-template-columns: 1fr;
    }
}

@media print {
    @page {
        margin: 0.45in;
        size: letter portrait;
    }

    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .no-print,
    .header,
    .sidebar,
    .topbar,
    .pagetitle,
    .breadcrumb,
    .footer,
    .chat-widget-container {
        display: none !important;
    }

    body {
        background: #ffffff !important;
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

    .fuel-print-sheet,
    .fuel-summary-card,
    .fuel-chart-card,
    .fuel-table-card {
        border: 1px solid #cbd5e1 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }

    .fuel-print-sheet {
        padding: 0 !important;
    }

    .fuel-summary-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .fuel-chart-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .fuel-summary-card,
    .fuel-chart-card,
    .fuel-table-card {
        break-inside: avoid;
    }

    .fuel-print-header,
    .fuel-report-note,
    .fuel-summary-grid,
    .fuel-chart-grid,
    .fuel-table-card {
        margin: 0 0 0.18in !important;
    }

    .fuel-print-header {
        padding: 0 0 0.15in !important;
    }

    .fuel-print-header h2 {
        font-size: 18pt;
    }

    .fuel-summary-card {
        padding: 0.12in;
    }

    .fuel-summary-card strong {
        font-size: 13pt;
    }

    .fuel-chart-card {
        padding: 0.12in;
    }

    .fuel-bar-row {
        grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr) minmax(0.55in, auto);
        gap: 0.08in;
        margin-bottom: 0.08in;
        page-break-inside: avoid;
    }

    .fuel-bar-track {
        height: 12px;
    }

    .table-responsive {
        overflow: visible !important;
    }

    .table {
        font-size: 8.5pt;
    }
}

            <?php echo print_page_number_css(); ?></style>
<?php render_print_page_number(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
