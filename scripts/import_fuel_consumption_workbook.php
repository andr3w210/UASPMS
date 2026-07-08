<?php
require_once __DIR__ . '/../spams/app/config/constants.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

action_main($argv);

function action_main(array $argv): void
{
    $filePathArg = $argv[1] ?? '';
    if ($filePathArg === '') {
        fwrite(STDERR, "Usage: php scripts/import_fuel_consumption_workbook.php <xlsx-or-csv-path> [--quantity-mode=purchased|consumed] [--apply]\n");
        exit(1);
    }

    $quantityMode = 'purchased';
    $apply = in_array('--apply', $argv, true);
    foreach ($argv as $arg) {
        if (strpos($arg, '--quantity-mode=') === 0) {
            $value = trim(substr($arg, strlen('--quantity-mode=')));
            if (in_array($value, ['purchased', 'consumed'], true)) {
                $quantityMode = $value;
            }
        }
    }

    $filePath = $filePathArg;
    if (!preg_match('/^[A-Za-z]:\\\\/', $filePath)) {
        $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\\\'], DIRECTORY_SEPARATOR, $filePathArg);
    }

    if (!is_file($filePath)) {
        fwrite(STDERR, "File not found: {$filePath}\n");
        exit(1);
    }

    $tripDb = new mysqli(TRIP_DB_HOST, TRIP_DB_USER, TRIP_DB_PASS, TRIP_DB_NAME);
    $tripDb->set_charset('utf8mb4');
    if (!$apply) {
        echo "Dry-run only. Re-run with --apply to persist fuel consumption import rows." . PHP_EOL;
    }

    if (!table_exists($tripDb, 'trip_fuel_ris_entries')) {
        fwrite(STDERR, "Table trip_fuel_ris_entries not found. Run database/097_trip_fuel_ris_entries.sql first.\n");
        exit(1);
    }
    if (!table_exists($tripDb, 'trip_gas_stations')) {
        fwrite(STDERR, "Table trip_gas_stations not found. Run database/098_trip_fuel_ris_excel_fields_and_gas_stations.sql first.\n");
        exit(1);
    }

    $rows = parse_upload($filePath);
    if (count($rows) < 2) {
        fwrite(STDERR, "No data rows found in workbook.\n");
        exit(1);
    }

    $header = array_map('norm', $rows[0]);
    $col = array_flip($header);

    $dateColumns = ['ris_date', 'date'];
    $hasDate = false;
    foreach ($dateColumns as $name) {
        if (isset($col[$name])) {
            $hasDate = true;
            break;
        }
    }
    if (!$hasDate) {
        fwrite(STDERR, "Missing date column. Expected Date or ris_date.\n");
        exit(1);
    }

    $vehicleMap = build_vehicle_map($tripDb);
    $gasStationMap = build_gas_station_map($tripDb);

    $insert = $tripDb->prepare(
        'INSERT INTO trip_fuel_ris_entries (
            ris_date, ris_no, gas_station_id, station_name, vehicle_id, vehicle_plate_no, vehicle_name, fuel_type,
            quantity, unit, purpose, driver_name,
            liters_purchased, liters_consumed, amount, odometer_reading, remarks, source_tag, created_by
        ) VALUES (?, ?, NULLIF(?, 0), ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $inserted = 0;
    $skipped = 0;
    $rowErrors = [];

    $tripDb->begin_transaction();

    for ($i = 1; $i < count($rows); $i++) {
        $src = $rows[$i];
        if (!array_filter($src, static fn($v) => trim((string) $v) !== '')) {
            continue;
        }

        $rowNumber = $i + 1;
        $rawDate = pick($src, $col, ['ris_date', 'date']);
        $rawRisNo = pick($src, $col, ['ris_no', 'ris_number']);
        $rawStation = pick($src, $col, ['station_name', 'station', 'gas_station', 'name']);
        $rawPlate = strtoupper(pick($src, $col, ['plate_no', 'vehicle_plate_no', 'plate_number']));
        $rawVehicle = pick($src, $col, ['vehicle_name', 'vehicle']);
        $rawFuelType = pick($src, $col, ['fuel_type', 'type']);
        $rawUnit = pick($src, $col, ['unit']);
        $rawPurchased = pick($src, $col, ['liters_purchased', 'purchased_liters', 'liters']);
        $rawConsumed = pick($src, $col, ['liters_consumed', 'consumed_liters']);
        $rawQuantity = pick($src, $col, ['quantity']);
        $rawAmount = pick($src, $col, ['amount', 'total_amount', 'cost']);
        $rawOdometer = pick($src, $col, ['odometer', 'odometer_reading']);
        $rawRemarks = pick($src, $col, ['remarks', 'note', 'notes']);
        $rawPurpose = pick($src, $col, ['purpose']);
        $rawDriver = pick($src, $col, ['driver']);

        $dateValue = parse_date_value($rawDate);
        if ($dateValue === null) {
            $rowErrors[] = "Row {$rowNumber}: invalid date.";
            $skipped++;
            continue;
        }

        if (trim($rawRisNo) === '') {
            $rawRisNo = auto_ris_no($dateValue, $rowNumber);
        }

        $purchased = parse_decimal($rawPurchased) ?? 0.0;
        $consumed = parse_decimal($rawConsumed) ?? 0.0;
        $quantity = parse_decimal($rawQuantity) ?? 0.0;

        if ($quantity > 0) {
            if ($quantityMode === 'purchased' && $purchased <= 0) {
                $purchased = $quantity;
            }
            if ($quantityMode === 'consumed' && $consumed <= 0) {
                $consumed = $quantity;
            }
        }

        if ($quantity <= 0) {
            if ($purchased > 0) {
                $quantity = $purchased;
            } elseif ($consumed > 0) {
                $quantity = $consumed;
            }
        }

        $amount = parse_decimal($rawAmount);
        $odometer = parse_decimal($rawOdometer);

        if ($purchased < 0 || $consumed < 0) {
            $rowErrors[] = "Row {$rowNumber}: liters cannot be negative.";
            $skipped++;
            continue;
        }
        if ($amount !== null && $amount < 0) {
            $rowErrors[] = "Row {$rowNumber}: amount cannot be negative.";
            $skipped++;
            continue;
        }
        if ($odometer !== null && $odometer < 0) {
            $rowErrors[] = "Row {$rowNumber}: odometer cannot be negative.";
            $skipped++;
            continue;
        }
        if ($purchased <= 0 && $consumed <= 0) {
            $rowErrors[] = "Row {$rowNumber}: missing quantity/liters.";
            $skipped++;
            continue;
        }

        $vehicle = resolve_vehicle($rawPlate, $rawVehicle, $vehicleMap);
        $vehicleId = $vehicle['id'] ?? 0;
        $vehiclePlate = $vehicle['plate_no'] ?? $rawPlate;
        $vehicleName = $vehicle['vehicle_name'] ?? $rawVehicle;
        $fuelType = $rawFuelType !== '' ? $rawFuelType : ($vehicle['fuel_type'] ?? 'Diesel');
        $unit = trim($rawUnit) !== '' ? trim($rawUnit) : 'Liter';
        $gasStationId = find_or_create_gas_station($tripDb, $gasStationMap, $rawStation, $createdBy = 1);
        $stationName = trim($rawStation);

        $remarksParts = [];
        if ($rawRemarks !== '') {
            $remarksParts[] = $rawRemarks;
        }
        if ($rawPurpose !== '') {
            $remarksParts[] = 'Purpose: ' . $rawPurpose;
        }
        if ($rawDriver !== '') {
            $remarksParts[] = 'Driver: ' . $rawDriver;
        }
        $remarks = trim(implode(' | ', $remarksParts));

        $sourceTag = 'import';
        $createdBy = 1;

        try {
            $insert->bind_param(
                'ssisisssdsssddddssi',
                $dateValue,
                $rawRisNo,
                $gasStationId,
                $stationName,
                $vehicleId,
                $vehiclePlate,
                $vehicleName,
                $fuelType,
                $quantity,
                $unit,
                $rawPurpose,
                $rawDriver,
                $purchased,
                $consumed,
                $amount,
                $odometer,
                $remarks,
                $sourceTag,
                $createdBy
            );
            $insert->execute();
            $inserted++;
        } catch (Throwable $e) {
            $rowErrors[] = "Row {$rowNumber}: " . $e->getMessage();
            $skipped++;
        }
    }

    $insert->close();
    if ($apply) {
        $tripDb->commit();
    } else {
        $tripDb->rollback();
    }

    echo $apply ? "Import complete\n" : "Dry run complete; no changes were saved.\n";
    echo "File: {$filePath}\n";
    echo "Quantity mode: {$quantityMode}\n";
    echo ($apply ? "Inserted: " : "Rows that would insert: ") . "{$inserted}\n";
    echo "Skipped: {$skipped}\n";
    echo "Errors: " . count($rowErrors) . "\n";

    if ($rowErrors) {
        echo "First issues:\n";
        foreach (array_slice($rowErrors, 0, 15) as $err) {
            echo "- {$err}\n";
        }
    }
}

function table_exists(mysqli $db, string $table): bool
{
    $res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    return $res && $res->num_rows > 0;
}

function parse_upload(string $filePath): array
{
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return parse_csv_file($filePath);
    }
    if ($ext === 'xlsx') {
        return parse_xlsx_file($filePath);
    }

    throw new RuntimeException('Only CSV and XLSX are supported.');
}

function parse_csv_file(string $filePath): array
{
    $rows = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new RuntimeException('Unable to open CSV file.');
    }
    while (($csv = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static fn($v) => trim((string) $v), $csv);
    }
    fclose($handle);
    return $rows;
}

function parse_xlsx_file(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension is required for XLSX import.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Unable to open XLSX file.');
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedDoc = simplexml_load_string($sharedXml);
        if ($sharedDoc) {
            foreach ($sharedDoc->si as $si) {
                if (isset($si->t)) {
                    $shared[] = trim((string) $si->t);
                } else {
                    $parts = [];
                    foreach ($si->r as $run) {
                        $parts[] = (string) $run->t;
                    }
                    $shared[] = trim(implode('', $parts));
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Unable to read first worksheet.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) {
        throw new RuntimeException('Unable to parse worksheet XML.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            preg_match('/([A-Z]+)/', $ref, $m);
            $colIndex = isset($m[1]) ? col_to_index($m[1]) : count($values);
            $type = (string) $cell['t'];
            $value = '';

            if ($type === 's') {
                $value = $shared[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = trim((string) $cell->is->t);
            } else {
                $value = isset($cell->v) ? trim((string) $cell->v) : '';
            }

            $values[$colIndex] = $value;
        }

        if ($values) {
            ksort($values);
            $max = max(array_keys($values));
            $normalized = [];
            for ($i = 0; $i <= $max; $i++) {
                $normalized[] = trim((string) ($values[$i] ?? ''));
            }
            $rows[] = $normalized;
        }
    }

    return $rows;
}

function col_to_index(string $letters): int
{
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function pick(array $src, array $col, array $names): string
{
    foreach ($names as $name) {
        if (isset($col[$name])) {
            return trim((string) ($src[$col[$name]] ?? ''));
        }
    }
    return '';
}

function norm(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function parse_decimal(string $value): ?float
{
    $clean = str_replace([',', ' '], '', trim($value));
    if ($clean === '') {
        return null;
    }
    if (!is_numeric($clean)) {
        return null;
    }
    return (float) $clean;
}

function parse_date_value(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $serial = (int) round((float) $value);
        if ($serial > 0) {
            $unix = ($serial - 25569) * 86400;
            if ($unix > 0) {
                return gmdate('Y-m-d', $unix);
            }
        }
    }

    $time = strtotime($value);
    if ($time === false) {
        return null;
    }

    return date('Y-m-d', $time);
}

function auto_ris_no(string $dateValue, int $rowNumber): string
{
    return 'AUTO-RIS-' . date('Ymd', strtotime($dateValue)) . '-' . str_pad((string) $rowNumber, 4, '0', STR_PAD_LEFT);
}

function plate_key(string $value): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($value)) ?? '');
}

function build_vehicle_map(mysqli $db): array
{
    $map = [
        'by_plate' => [],
        'by_name' => [],
    ];

    $res = $db->query('SELECT id, plate_no, vehicle_name, fuel_type FROM trip_vehicles WHERE is_active = 1');
    if (!$res) {
        return $map;
    }

    while ($row = $res->fetch_assoc()) {
        $plate = plate_key((string) ($row['plate_no'] ?? ''));
        $name = norm((string) ($row['vehicle_name'] ?? ''));
        if ($plate !== '') {
            $map['by_plate'][$plate] = $row;
        }
        if ($name !== '') {
            $map['by_name'][$name] = $row;
        }
    }

    return $map;
}

function build_gas_station_map(mysqli $db): array
{
    $map = [];
    $res = $db->query('SELECT id, station_name FROM trip_gas_stations WHERE is_active = 1');
    if (!$res) {
        return $map;
    }

    while ($row = $res->fetch_assoc()) {
        $key = norm((string) ($row['station_name'] ?? ''));
        if ($key !== '') {
            $map[$key] = ['id' => (int) $row['id'], 'station_name' => (string) $row['station_name']];
        }
    }

    return $map;
}

function find_or_create_gas_station(mysqli $db, array &$map, string $stationName, int $userId): int
{
    $stationName = trim($stationName);
    if ($stationName === '') {
        return 0;
    }

    $key = norm($stationName);
    if (isset($map[$key])) {
        return (int) ($map[$key]['id'] ?? 0);
    }

    $stmt = $db->prepare('SELECT id, station_name FROM trip_gas_stations WHERE LOWER(TRIM(station_name)) = LOWER(TRIM(?)) LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $stationName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            $map[$key] = ['id' => (int) $row['id'], 'station_name' => (string) $row['station_name']];
            return (int) $row['id'];
        }
    }

    $insert = $db->prepare('INSERT INTO trip_gas_stations (station_name, created_by) VALUES (?, ?)');
    if (!$insert) {
        return 0;
    }

    $insert->bind_param('si', $stationName, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();

    if ($saved && $newId > 0) {
        $map[$key] = ['id' => $newId, 'station_name' => $stationName];
        return $newId;
    }

    return 0;
}

function resolve_vehicle(string $rawPlate, string $rawVehicle, array $vehicleMap): ?array
{
    $plate = plate_key($rawPlate !== '' ? $rawPlate : $rawVehicle);
    if ($plate !== '' && isset($vehicleMap['by_plate'][$plate])) {
        return $vehicleMap['by_plate'][$plate];
    }

    $name = norm($rawVehicle);
    if ($name !== '' && isset($vehicleMap['by_name'][$name])) {
        return $vehicleMap['by_name'][$name];
    }

    return null;
}


