<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Transport Officer');

if (isset($_GET['download_template'])) {
    $templatePath = dirname(__DIR__, 3) . '/database/templates/fuel_ris_import_template.csv';
    if (!is_file($templatePath)) {
        http_response_code(404);
        exit('Fuel RIS import template not found.');
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fuel_ris_import_template.csv"');
    header('Content-Length: ' . (string) filesize($templatePath));
    readfile($templatePath);
    exit;
}

function fr_norm(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function fr_col_to_index(string $letters): int
{
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function fr_parse_csv_file(string $filePath): array
{
    $rows = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new RuntimeException('Unable to open uploaded CSV file.');
    }

    while (($csvRow = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static fn($value) => trim((string) $value), $csvRow);
    }

    fclose($handle);
    return $rows;
}

function fr_parse_xlsx_file(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('XLSX import requires PHP ZipArchive extension. Use CSV or enable extension=zip.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Unable to open uploaded XLSX file.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedDoc = simplexml_load_string($sharedXml);
        if ($sharedDoc) {
            foreach ($sharedDoc->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = trim((string) $si->t);
                } else {
                    $parts = [];
                    foreach ($si->r as $run) {
                        $parts[] = (string) $run->t;
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Unable to read the first sheet in XLSX file.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) {
        throw new RuntimeException('Unable to parse XLSX worksheet.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            preg_match('/([A-Z]+)/', $ref, $matches);
            $colIndex = isset($matches[1]) ? fr_col_to_index($matches[1]) : count($values);
            $type = (string) $cell['t'];
            $value = '';

            if ($type === 's') {
                $value = $sharedStrings[(int) $cell->v] ?? '';
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

function fr_parse_upload(array $file): array
{
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return fr_parse_csv_file((string) $file['tmp_name']);
    }
    if ($ext === 'xlsx') {
        return fr_parse_xlsx_file((string) $file['tmp_name']);
    }

    throw new RuntimeException('Only CSV and XLSX files are supported.');
}

function fr_parse_decimal(string $value): ?float
{
    $cleaned = str_replace([',', ' '], '', trim($value));
    if ($cleaned === '') {
        return null;
    }
    if (!is_numeric($cleaned)) {
        return null;
    }

    return (float) $cleaned;
}

function fr_parse_date_value(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (is_numeric($value)) {
        // Handle Excel serial date values.
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

function fr_pick(array $src, array $col, array $names): string
{
    foreach ($names as $name) {
        if (isset($col[$name])) {
            return trim((string) ($src[$col[$name]] ?? ''));
        }
    }
    return '';
}

function fr_auto_ris_no(string $dateValue, int $rowNumber): string
{
    return 'AUTO-RIS-' . date('Ymd', strtotime($dateValue)) . '-' . str_pad((string) $rowNumber, 4, '0', STR_PAD_LEFT);
}

function fr_station_key(string $value): string
{
    return fr_norm($value);
}

function fr_find_or_create_gas_station(mysqli $tripDb, array &$gasStationByName, string $stationName, int $userId): int
{
    $stationName = trim($stationName);
    if ($stationName === '') {
        return 0;
    }

    $key = fr_station_key($stationName);
    if (isset($gasStationByName[$key])) {
        return (int) ($gasStationByName[$key]['id'] ?? 0);
    }

    $select = $tripDb->prepare('SELECT id, station_name FROM trip_gas_stations WHERE LOWER(TRIM(station_name)) = LOWER(TRIM(?)) LIMIT 1');
    if ($select) {
        $select->bind_param('s', $stationName);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            $gasStationByName[$key] = $existing;
            return (int) $existing['id'];
        }
    }

    $insert = $tripDb->prepare('INSERT INTO trip_gas_stations (station_name, created_by) VALUES (?, ?)');
    if (!$insert) {
        return 0;
    }

    $insert->bind_param('si', $stationName, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();

    if (!$saved || $newId <= 0) {
        return 0;
    }

    $gasStationByName[$key] = ['id' => $newId, 'station_name' => $stationName];
    return $newId;
}

$mainDb = db();
$tripDb = trip_db();
$page_title = 'Fuel RIS Encoding';
$flash = get_flash();
$errors = [];
$importRowErrors = [];
$importSummary = null;
$entries = [];
$vehicles = [];
$vehicleById = [];
$vehicleByPlate = [];
$vehicleByName = [];
$driverEmployees = [];
$gasStations = [];
$gasStationById = [];
$gasStationByName = [];
$hasTable = false;

$form = [
    'ris_date' => date('Y-m-d'),
    'ris_no' => '',
    'gas_station_id' => '',
    'station_name' => '',
    'vehicle_id' => '',
    'fuel_type' => 'Diesel',
    'quantity' => '',
    'unit' => 'Liter',
    'purpose' => '',
    'driver_name' => '',
    'amount' => '',
    'odometer_reading' => '',
    'remarks' => '',
];

if (!$tripDb) {
    $errors[] = 'Unable to connect to trip ticket database. Please verify trip DB settings.';
} else {
    $tableCheck = $tripDb->query("SHOW TABLES LIKE 'trip_fuel_ris_entries'");
    $hasTable = $tableCheck && $tableCheck->num_rows > 0;
    if (!$hasTable) {
        $errors[] = 'Fuel RIS table is missing. Run database/097_trip_fuel_ris_entries.sql first.';
    } else {
        $requiredColumns = ['gas_station_id', 'quantity', 'unit', 'purpose', 'driver_name'];
        foreach ($requiredColumns as $requiredColumn) {
            $columnCheck = $tripDb->query("SHOW COLUMNS FROM trip_fuel_ris_entries LIKE '" . $tripDb->real_escape_string($requiredColumn) . "'");
            if (!$columnCheck || $columnCheck->num_rows === 0) {
                $errors[] = 'Fuel RIS schema is outdated. Run database/098_trip_fuel_ris_excel_fields_and_gas_stations.sql.';
                break;
            }
        }

        $stationTableCheck = $tripDb->query("SHOW TABLES LIKE 'trip_gas_stations'");
        if (!$stationTableCheck || $stationTableCheck->num_rows === 0) {
            $errors[] = 'Gasoline station table is missing. Run database/098_trip_fuel_ris_excel_fields_and_gas_stations.sql.';
        }
    }
}

if ($tripDb) {
    $vehicleResult = $tripDb->query("SELECT id, plate_no, vehicle_name, fuel_type FROM trip_vehicles WHERE is_active = 1 ORDER BY plate_no ASC");
    if ($vehicleResult) {
        $vehicles = $vehicleResult->fetch_all(MYSQLI_ASSOC);
        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) $vehicle['id'];
            $plate = strtoupper(trim((string) ($vehicle['plate_no'] ?? '')));
            $name = fr_norm((string) ($vehicle['vehicle_name'] ?? ''));
            $vehicleById[$vehicleId] = $vehicle;
            if ($plate !== '') {
                $vehicleByPlate[$plate] = $vehicle;
            }
            if ($name !== '') {
                $vehicleByName[$name] = $vehicle;
            }
        }
    }

    $stationTableCheck = $tripDb->query("SHOW TABLES LIKE 'trip_gas_stations'");
    if ($stationTableCheck && $stationTableCheck->num_rows > 0) {
        $stationResult = $tripDb->query("SELECT id, station_name FROM trip_gas_stations WHERE is_active = 1 ORDER BY station_name ASC");
        if ($stationResult) {
            $gasStations = $stationResult->fetch_all(MYSQLI_ASSOC);
            foreach ($gasStations as $station) {
                $stationId = (int) ($station['id'] ?? 0);
                $stationName = (string) ($station['station_name'] ?? '');
                if ($stationId > 0) {
                    $gasStationById[$stationId] = $station;
                }
                if (trim($stationName) !== '') {
                    $gasStationByName[fr_station_key($stationName)] = $station;
                }
            }
        }
    }
}

if ($mainDb) {
    $driverFilter = schema_has_column($mainDb, 'employees', 'is_driver') ? ' AND is_driver = 1' : '';
    $driverResult = $mainDb->query(
        "SELECT id, employee_no, first_name, middle_name, last_name, suffix_name
         FROM employees
         WHERE is_active = 1{$driverFilter}
         ORDER BY last_name ASC, first_name ASC"
    );
    if ($driverResult) {
        $driverEmployees = $driverResult->fetch_all(MYSQLI_ASSOC);
    }
}

if ($tripDb && $hasTable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } elseif ($action === 'add_station') {
        $stationNameNew = trim((string) ($_POST['station_name_new'] ?? ''));
        if ($stationNameNew === '') {
            $errors[] = 'Please enter a gasoline station name before adding.';
        } else {
            $createdStationId = fr_find_or_create_gas_station($tripDb, $gasStationByName, $stationNameNew, (int) current_user_id());
            if ($createdStationId <= 0) {
                $errors[] = 'Unable to add gasoline station at this time.';
            } else {
                set_flash('success', 'Gasoline station saved successfully.');
                redirect('modules/trip_tickets/fuel_ris.php');
            }
        }
    } elseif ($action === 'save') {
        $form['ris_date'] = trim((string) ($_POST['ris_date'] ?? ''));
        $form['ris_no'] = trim((string) ($_POST['ris_no'] ?? ''));
        $form['gas_station_id'] = trim((string) ($_POST['gas_station_id'] ?? ''));
        $form['station_name'] = trim((string) ($_POST['station_name'] ?? ''));
        $form['vehicle_id'] = trim((string) ($_POST['vehicle_id'] ?? ''));
        $form['fuel_type'] = trim((string) ($_POST['fuel_type'] ?? 'Diesel'));
        $form['quantity'] = trim((string) ($_POST['quantity'] ?? ''));
        $form['unit'] = trim((string) ($_POST['unit'] ?? 'Liter'));
        $form['purpose'] = trim((string) ($_POST['purpose'] ?? ''));
        $form['driver_name'] = trim((string) ($_POST['driver_name'] ?? ''));
        $form['amount'] = trim((string) ($_POST['amount'] ?? ''));
        $form['odometer_reading'] = trim((string) ($_POST['odometer_reading'] ?? ''));
        $form['remarks'] = trim((string) ($_POST['remarks'] ?? ''));

        $dateValue = fr_parse_date_value($form['ris_date']);
        if (!$dateValue) {
            $errors[] = 'RIS date is required and must be a valid date.';
        }

        if ($form['ris_no'] === '') {
            $errors[] = 'RIS number is required.';
        }

        if ($form['fuel_type'] === '') {
            $errors[] = 'Type is required.';
        }

        $vehicleId = (int) $form['vehicle_id'];
        $vehicle = $vehicleById[$vehicleId] ?? null;
        if ($vehicleId > 0 && !$vehicle) {
            $errors[] = 'Selected vehicle was not found.';
        }

        $gasStationId = (int) $form['gas_station_id'];
        $gasStation = $gasStationById[$gasStationId] ?? null;
        if ($gasStationId > 0 && !$gasStation) {
            $errors[] = 'Selected gasoline station was not found.';
        }

        $quantity = fr_parse_decimal($form['quantity']);
        $amount = fr_parse_decimal($form['amount']);
        $odometer = fr_parse_decimal($form['odometer_reading']);

        $quantity = $quantity ?? 0.0;

        if ($quantity <= 0) {
            $errors[] = 'Quantity is required and must be greater than zero.';
        }
        if ($amount !== null && $amount < 0) {
            $errors[] = 'Amount cannot be negative.';
        }
        if ($odometer !== null && $odometer < 0) {
            $errors[] = 'Odometer reading cannot be negative.';
        }

        if (!$errors) {
            if (!$gasStation && $form['station_name'] !== '') {
                $gasStationId = fr_find_or_create_gas_station($tripDb, $gasStationByName, $form['station_name'], (int) current_user_id());
                $gasStation = $gasStationId > 0 ? ($gasStationById[$gasStationId] ?? ['id' => $gasStationId, 'station_name' => $form['station_name']]) : null;
            }

            $vehiclePlate = $vehicle ? (string) ($vehicle['plate_no'] ?? '') : '';
            $vehicleName = $vehicle ? (string) ($vehicle['vehicle_name'] ?? '') : '';
            $fuelType = $form['fuel_type'] !== '' ? $form['fuel_type'] : ($vehicle['fuel_type'] ?? 'Diesel');
            $unit = $form['unit'] !== '' ? $form['unit'] : 'Liter';
            $litersPurchased = $quantity;
            $litersConsumed = 0.0;
            $stationName = $gasStation ? (string) ($gasStation['station_name'] ?? $form['station_name']) : $form['station_name'];
            $userId = current_user_id();

            $stmt = $tripDb->prepare(
                'INSERT INTO trip_fuel_ris_entries (
                    ris_date, ris_no, gas_station_id, station_name, vehicle_id, vehicle_plate_no, vehicle_name, fuel_type,
                    quantity, unit, purpose, driver_name,
                    liters_purchased, liters_consumed, amount, odometer_reading, remarks, source_tag, created_by
                ) VALUES (?, ?, NULLIF(?, 0), ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if (!$stmt) {
                $errors[] = 'Unable to prepare Fuel RIS insert statement.';
            } else {
                $sourceTag = 'manual';
                $stmt->bind_param(
                    'ssisisssdsssddddssi',
                    $dateValue,
                    $form['ris_no'],
                    $gasStationId,
                    $stationName,
                    $vehicleId,
                    $vehiclePlate,
                    $vehicleName,
                    $fuelType,
                    $quantity,
                    $unit,
                    $form['purpose'],
                    $form['driver_name'],
                    $litersPurchased,
                    $litersConsumed,
                    $amount,
                    $odometer,
                    $form['remarks'],
                    $sourceTag,
                    $userId
                );
                $saved = $stmt->execute();
                $newId = (int) $stmt->insert_id;
                $stmt->close();

                if (!$saved) {
                    $errors[] = 'Unable to save Fuel RIS entry.';
                } else {
                    if ($mainDb) {
                        write_audit_log($mainDb, [
                            'action' => 'insert',
                            'table_name' => 'trip_fuel_ris_entries',
                            'record_id' => $newId,
                            'module_name' => 'trip_tickets',
                            'record_type' => 'fuel_ris',
                            'action_name' => 'create_fuel_ris_entry',
                            'description' => 'Created Fuel RIS entry.',
                            'new_values' => [
                                'ris_no' => $form['ris_no'],
                                'ris_date' => $dateValue,
                                'vehicle_id' => $vehicleId,
                                'fuel_type' => $fuelType,
                                'quantity' => $quantity,
                                'unit' => $unit,
                                'purpose' => $form['purpose'],
                                'driver_name' => $form['driver_name'],
                            ],
                        ]);
                    }

                    set_flash('success', 'Fuel RIS entry saved successfully.');
                    redirect('modules/trip_tickets/fuel_ris.php');
                }
            }
        }
    } elseif ($action === 'delete') {
        $entryId = (int) ($_POST['id'] ?? 0);
        if ($entryId <= 0) {
            $errors[] = 'Invalid Fuel RIS entry selected.';
        } else {
            $oldRow = null;
            $oldStmt = $tripDb->prepare('SELECT id, ris_no, ris_date FROM trip_fuel_ris_entries WHERE id = ? LIMIT 1');
            if ($oldStmt) {
                $oldStmt->bind_param('i', $entryId);
                $oldStmt->execute();
                $oldRow = $oldStmt->get_result()->fetch_assoc() ?: null;
                $oldStmt->close();
            }

            $stmt = $tripDb->prepare('DELETE FROM trip_fuel_ris_entries WHERE id = ? LIMIT 1');
            if (!$stmt) {
                $errors[] = 'Unable to prepare delete statement.';
            } else {
                $stmt->bind_param('i', $entryId);
                $deleted = $stmt->execute();
                $stmt->close();

                if (!$deleted) {
                    $errors[] = 'Unable to delete Fuel RIS entry.';
                } else {
                    if ($mainDb) {
                        write_audit_log($mainDb, [
                            'action' => 'delete',
                            'table_name' => 'trip_fuel_ris_entries',
                            'record_id' => $entryId,
                            'module_name' => 'trip_tickets',
                            'record_type' => 'fuel_ris',
                            'action_name' => 'delete_fuel_ris_entry',
                            'description' => 'Deleted Fuel RIS entry.',
                            'old_values' => $oldRow,
                        ]);
                    }

                    set_flash('success', 'Fuel RIS entry deleted successfully.');
                    redirect('modules/trip_tickets/fuel_ris.php');
                }
            }
        }
    } elseif ($action === 'import') {
        $upload = $_FILES['import_file'] ?? null;
        $quantityMode = trim((string) ($_POST['import_quantity_mode'] ?? 'purchased'));
        if (!in_array($quantityMode, ['purchased', 'consumed'], true)) {
            $quantityMode = 'purchased';
        }
        if (!$upload || !isset($upload['tmp_name']) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload a CSV or XLSX file to import.';
        } else {
            try {
                $rows = fr_parse_upload($upload);
                if (count($rows) < 2) {
                    $errors[] = 'The uploaded file must contain a header row and at least one data row.';
                } else {
                    $header = array_map('fr_norm', $rows[0]);
                    $col = array_flip($header);
                    $requiredAny = ['ris_date', 'date'];
                    $hasDateColumn = false;
                    foreach ($requiredAny as $columnName) {
                        if (isset($col[$columnName])) {
                            $hasDateColumn = true;
                            break;
                        }
                    }
                    if (!$hasDateColumn) {
                        $errors[] = 'Missing date column. Use ris_date (or date) in your import file.';
                    }

                    if (!$errors) {
                        $insertStmt = $tripDb->prepare(
                            'INSERT INTO trip_fuel_ris_entries (
                                ris_date, ris_no, gas_station_id, station_name, vehicle_id, vehicle_plate_no, vehicle_name, fuel_type,
                                quantity, unit, purpose, driver_name,
                                liters_purchased, liters_consumed, amount, odometer_reading, remarks, source_tag, created_by
                            ) VALUES (?, ?, NULLIF(?, 0), ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );

                        if (!$insertStmt) {
                            $errors[] = 'Unable to prepare import insert statement.';
                        } else {
                            $inserted = 0;
                            $skipped = 0;
                            $userId = current_user_id();

                            for ($i = 1; $i < count($rows); $i++) {
                                $src = $rows[$i];
                                if (!array_filter($src, static fn($value) => trim((string) $value) !== '')) {
                                    continue;
                                }

                                $rowNumber = $i + 1;
                                $rawDate = fr_pick($src, $col, ['ris_date', 'date']);
                                $rawRisNo = fr_pick($src, $col, ['ris_no', 'ris_number']);
                                $rawStation = fr_pick($src, $col, ['station_name', 'station', 'gas_station', 'name']);
                                $rawPlateNo = strtoupper(fr_pick($src, $col, ['plate_no', 'vehicle_plate_no', 'plate_number']));
                                $rawVehicleName = fr_pick($src, $col, ['vehicle_name', 'vehicle']);
                                $rawFuelType = fr_pick($src, $col, ['fuel_type', 'type']);
                                $rawUnit = fr_pick($src, $col, ['unit']);
                                $rawPurchased = fr_pick($src, $col, ['liters_purchased', 'purchased_liters', 'liters']);
                                $rawConsumed = fr_pick($src, $col, ['liters_consumed', 'consumed_liters']);
                                $rawQuantity = fr_pick($src, $col, ['quantity']);
                                $rawAmount = fr_pick($src, $col, ['amount', 'total_amount', 'cost']);
                                $rawOdometer = fr_pick($src, $col, ['odometer', 'odometer_reading']);
                                $rawRemarks = fr_pick($src, $col, ['remarks', 'note', 'notes']);
                                $rawPurpose = fr_pick($src, $col, ['purpose']);
                                $rawDriver = fr_pick($src, $col, ['driver']);

                                $dateValue = fr_parse_date_value($rawDate);
                                if (!$dateValue) {
                                    $importRowErrors[] = 'Row ' . $rowNumber . ': invalid ris_date value.';
                                    $skipped++;
                                    continue;
                                }
                                if (trim($rawRisNo) === '') {
                                    $rawRisNo = fr_auto_ris_no($dateValue, $rowNumber);
                                }

                                $litersPurchased = fr_parse_decimal($rawPurchased) ?? 0.0;
                                $litersConsumed = fr_parse_decimal($rawConsumed) ?? 0.0;
                                $quantityLiters = fr_parse_decimal($rawQuantity) ?? 0.0;

                                if ($quantityLiters > 0) {
                                    if ($quantityMode === 'consumed' && $litersConsumed <= 0) {
                                        $litersConsumed = $quantityLiters;
                                    }
                                    if ($quantityMode === 'purchased' && $litersPurchased <= 0) {
                                        $litersPurchased = $quantityLiters;
                                    }
                                }

                                if ($quantityLiters <= 0) {
                                    if ($litersPurchased > 0) {
                                        $quantityLiters = $litersPurchased;
                                    } elseif ($litersConsumed > 0) {
                                        $quantityLiters = $litersConsumed;
                                    }
                                }

                                $amount = fr_parse_decimal($rawAmount);
                                $odometer = fr_parse_decimal($rawOdometer);

                                if ($litersPurchased < 0 || $litersConsumed < 0) {
                                    $importRowErrors[] = 'Row ' . $rowNumber . ': liters values cannot be negative.';
                                    $skipped++;
                                    continue;
                                }
                                if ($amount !== null && $amount < 0) {
                                    $importRowErrors[] = 'Row ' . $rowNumber . ': amount cannot be negative.';
                                    $skipped++;
                                    continue;
                                }
                                if ($odometer !== null && $odometer < 0) {
                                    $importRowErrors[] = 'Row ' . $rowNumber . ': odometer cannot be negative.';
                                    $skipped++;
                                    continue;
                                }
                                if ($litersPurchased <= 0 && $litersConsumed <= 0) {
                                    $importRowErrors[] = 'Row ' . $rowNumber . ': provide purchased or consumed liters.';
                                    $skipped++;
                                    continue;
                                }

                                $vehicle = null;
                                if ($rawPlateNo !== '' && isset($vehicleByPlate[$rawPlateNo])) {
                                    $vehicle = $vehicleByPlate[$rawPlateNo];
                                } elseif ($rawVehicleName !== '') {
                                    $normalizedVehicleName = fr_norm($rawVehicleName);
                                    if (isset($vehicleByName[$normalizedVehicleName])) {
                                        $vehicle = $vehicleByName[$normalizedVehicleName];
                                    }
                                }

                                $vehicleId = $vehicle ? (int) $vehicle['id'] : 0;
                                $vehiclePlate = $vehicle ? (string) ($vehicle['plate_no'] ?? $rawPlateNo) : $rawPlateNo;
                                $vehicleName = $vehicle ? (string) ($vehicle['vehicle_name'] ?? $rawVehicleName) : $rawVehicleName;
                                $fuelType = $rawFuelType !== ''
                                    ? $rawFuelType
                                    : ($vehicle ? (string) ($vehicle['fuel_type'] ?? 'Diesel') : 'Diesel');
                                $unit = $rawUnit !== '' ? $rawUnit : 'Liter';
                                $stationName = trim($rawStation);
                                $gasStationId = fr_find_or_create_gas_station($tripDb, $gasStationByName, $stationName, (int) $userId);
                                $sourceTag = 'import';

                                $insertStmt->bind_param(
                                    'ssisisssdsssddddssi',
                                    $dateValue,
                                    $rawRisNo,
                                    $gasStationId,
                                    $stationName,
                                    $vehicleId,
                                    $vehiclePlate,
                                    $vehicleName,
                                    $fuelType,
                                    $quantityLiters,
                                    $unit,
                                    $rawPurpose,
                                    $rawDriver,
                                    $litersPurchased,
                                    $litersConsumed,
                                    $amount,
                                    $odometer,
                                    $rawRemarks,
                                    $sourceTag,
                                    $userId
                                );

                                if (!$insertStmt->execute()) {
                                    $importRowErrors[] = 'Row ' . $rowNumber . ': failed to insert into database.';
                                    $skipped++;
                                    continue;
                                }

                                $inserted++;
                            }

                            $insertStmt->close();
                            $importSummary = [
                                'inserted' => $inserted,
                                'skipped' => $skipped,
                                'errors' => count($importRowErrors),
                            ];

                            if ($inserted > 0 && $mainDb) {
                                write_audit_log($mainDb, [
                                    'action' => 'import',
                                    'table_name' => 'trip_fuel_ris_entries',
                                    'record_id' => null,
                                    'module_name' => 'trip_tickets',
                                    'record_type' => 'fuel_ris',
                                    'action_name' => 'import_fuel_ris_entries',
                                    'description' => 'Imported Fuel RIS entries from upload.',
                                    'new_values' => $importSummary,
                                ]);
                            }
                        }
                    }
                }
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    }
}

if ($tripDb && $hasTable) {
    $result = $tripDb->query(
    'SELECT e.id, e.ris_date, e.ris_no,
        e.gas_station_id, e.station_name,
        gs.station_name AS gas_station_name,
        e.vehicle_id, e.vehicle_plate_no, e.vehicle_name,
        e.fuel_type, e.quantity, e.unit, e.purpose, e.driver_name,
        e.liters_purchased, e.liters_consumed, e.amount, e.odometer_reading, e.remarks, e.source_tag, e.created_at
     FROM trip_fuel_ris_entries e
     LEFT JOIN trip_gas_stations gs ON gs.id = e.gas_station_id
            ORDER BY e.ris_date DESC, e.id DESC
         LIMIT 300'
    );

    if ($result) {
        $entries = $result->fetch_all(MYSQLI_ASSOC);
    }
}

$entryCount = count($entries);
$totalQuantity = 0.0;
$totalAmount = 0.0;
$stationSet = [];
foreach ($entries as $entry) {
    $totalQuantity += (float) ($entry['quantity'] ?? 0);
    $totalAmount += (float) ($entry['amount'] ?? 0);
    $stationLabel = trim((string) (($entry['gas_station_name'] ?? '') !== '' ? ($entry['gas_station_name'] ?? '') : ($entry['station_name'] ?? '')));
    if ($stationLabel !== '') {
        $stationSet[fr_station_key($stationLabel)] = true;
    }
}
$stationCount = count($stationSet);
$postedAction = (string) ($_POST['action'] ?? '');
$showEntryPanel = $_SERVER['REQUEST_METHOD'] === 'POST' && $postedAction !== '' && $postedAction !== 'delete';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section fuel-ris-workspace">
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h4 class="mb-1">Fuel RIS Encoding Workspace</h4>
                <div class="text-muted">Professional and user-friendly encoding view using your Excel fields: Date, Type, Unit, Quantity, Purpose, Driver, Vehicle, and Gasoline Station.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo base_url('modules/trip_tickets/fuel_ris.php'); ?>" class="btn btn-outline-dark">
                    <i class="bi bi-list-ul me-1"></i> Back to Encoded List
                </a>
                <a href="<?php echo base_url('modules/trip_tickets/fuel_ris_report.php'); ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-graph-up-arrow me-1"></i> Consolidated Report
                </a>
                <a href="<?php echo base_url('modules/trip_tickets/fuel_ris_create.php?download_template=1'); ?>" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i> Download Template
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 metric-card">
                <div class="card-body">
                    <div class="metric-label">Visible Entries</div>
                    <div class="metric-value"><?php echo number_format($entryCount); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 metric-card">
                <div class="card-body">
                    <div class="metric-label">Total Quantity</div>
                    <div class="metric-value"><?php echo number_format($totalQuantity, 2); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 metric-card">
                <div class="card-body">
                    <div class="metric-label">Total Amount</div>
                    <div class="metric-value"><?php echo number_format($totalAmount, 2); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 metric-card">
                <div class="card-body">
                    <div class="metric-label">Gasoline Stations</div>
                    <div class="metric-value"><?php echo number_format($stationCount); ?></div>
                </div>
            </div>
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

    <?php if ($importSummary): ?>
        <div class="alert alert-info">
            Imported rows: <strong><?php echo (int) $importSummary['inserted']; ?></strong>
            | Skipped rows: <strong><?php echo (int) $importSummary['skipped']; ?></strong>
            | Row issues: <strong><?php echo (int) $importSummary['errors']; ?></strong>
        </div>
    <?php endif; ?>

    <?php if ($importRowErrors): ?>
        <div class="alert alert-warning">
            <div class="fw-semibold mb-1">Import row issues (showing first 20)</div>
            <ul class="mb-0">
                <?php foreach (array_slice($importRowErrors, 0, 20) as $rowError): ?>
                    <li><?php echo h($rowError); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div id="fuel-ris-entry-panel">
    <div class="row g-3 align-items-start mt-1">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title mb-1">Encode Fuel RIS Entry</h5>
                    <div class="text-muted small mb-3">Large input workspace for faster and cleaner data entry.</div>

                    <div class="d-flex justify-content-end mb-3 pb-3 border-bottom">
                        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#fuel-ris-add-station-modal">
                            <i class="bi bi-plus-circle me-1"></i> Add Gasoline Station
                        </button>
                    </div>

                    <form method="post" class="row g-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">

                        <div class="col-md-6">
                            <label class="form-label">RIS Date</label>
                            <input type="date" name="ris_date" class="form-control" required value="<?php echo h($form['ris_date']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">RIS No.</label>
                            <input type="text" name="ris_no" class="form-control" required value="<?php echo h($form['ris_no']); ?>" placeholder="RIS-2026-05-0001">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Gasoline Station</label>
                            <div class="d-flex gap-2 mb-2">
                                <select name="gas_station_id" class="form-select">
                                    <option value="">Select from saved stations</option>
                                    <?php foreach ($gasStations as $station): ?>
                                        <option value="<?php echo (int) $station['id']; ?>" <?php echo (string) $station['id'] === (string) $form['gas_station_id'] ? 'selected' : ''; ?>>
                                            <?php echo h((string) $station['station_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#fuel-ris-add-station-modal">Add</button>
                            </div>
                            <input type="text" name="station_name" class="form-control" value="<?php echo h($form['station_name']); ?>" placeholder="Or type a new station name">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Vehicle (optional)</label>
                            <select name="vehicle_id" class="form-select">
                                <option value="">Not linked to vehicle</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo (int) $vehicle['id']; ?>" <?php echo (string) $vehicle['id'] === (string) $form['vehicle_id'] ? 'selected' : ''; ?>>
                                        <?php echo h((string) $vehicle['plate_no'] . ' - ' . (string) $vehicle['vehicle_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Type</label>
                            <input type="text" name="fuel_type" class="form-control" value="<?php echo h($form['fuel_type']); ?>" placeholder="Diesel">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit</label>
                            <input type="text" name="unit" class="form-control" value="<?php echo h($form['unit']); ?>" placeholder="Liter">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity</label>
                            <input type="number" step="0.01" min="0" name="quantity" class="form-control" value="<?php echo h($form['quantity']); ?>" placeholder="0.00" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Purpose</label>
                            <textarea name="purpose" class="form-control" rows="2" placeholder="Purpose from RIS"><?php echo h($form['purpose']); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Driver</label>
                            <select name="driver_name" class="form-select">
                                <option value="">Select driver</option>
                                <?php foreach ($driverEmployees as $driverEmployee): ?>
                                    <?php $driverName = employee_display_name($driverEmployee); ?>
                                    <option value="<?php echo h($driverName); ?>" <?php echo $driverName === $form['driver_name'] ? 'selected' : ''; ?>>
                                        <?php echo h($driverName); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($form['driver_name'] !== '' && !in_array($form['driver_name'], array_map(static fn($row) => employee_display_name($row), $driverEmployees), true)): ?>
                                    <option value="<?php echo h($form['driver_name']); ?>" selected><?php echo h($form['driver_name']); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Amount</label>
                            <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?php echo h($form['amount']); ?>" placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Odometer Reading</label>
                            <input type="number" step="0.01" min="0" name="odometer_reading" class="form-control" value="<?php echo h($form['odometer_reading']); ?>" placeholder="0.00">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2" placeholder="Optional notes"><?php echo h($form['remarks']); ?></textarea>
                        </div>

                        <div class="col-12 d-grid d-md-flex justify-content-md-end">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-save me-1"></i> Save Entry
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm sticky-xl-top fuel-import-card">
                <div class="card-body">
                    <h5 class="card-title mb-1">Import from Excel File</h5>
                    <div class="text-muted small mb-2">Accepted formats: CSV or XLSX (first sheet). Expected columns: Date, Type, Unit, Quantity, Purpose, Driver, Vehicle.</div>
                    <form method="post" enctype="multipart/form-data" class="d-grid gap-2">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="import">
                        <label class="form-label mb-0">If the file has a <strong>Quantity</strong> column, treat it as:</label>
                        <select name="import_quantity_mode" class="form-select">
                            <option value="purchased" selected>Liters Purchased</option>
                            <option value="consumed">Liters Consumed</option>
                        </select>
                        <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx" required>
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-file-earmark-arrow-up me-1"></i> Import File
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="text-muted">Entry saved or imported? Continue adding here, or go back to the list page to review encoded records.</div>
            <a href="<?php echo base_url('modules/trip_tickets/fuel_ris.php'); ?>" class="btn btn-outline-primary">
                <i class="bi bi-list-ul me-1"></i> View Encoded List
            </a>
        </div>
    </div>
</section>

<!-- Station maintenance is intentionally modal to keep users in the entry workflow. -->
<div class="modal fade" id="fuel-ris-add-station-modal" tabindex="-1" aria-labelledby="fuel-ris-add-station-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="add_station">
                <div class="modal-header">
                    <h5 class="modal-title" id="fuel-ris-add-station-modal-label">Add Gasoline Station</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Station Name</label>
                    <input type="text" name="station_name_new" class="form-control" placeholder="Enter gasoline station name" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check2-circle me-1"></i> Save Station
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.fuel-ris-workspace .metric-card {
    background: linear-gradient(145deg, #ffffff 0%, #f5f8fb 100%);
}

.fuel-ris-workspace .metric-label {
    color: #5f6b7a;
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.fuel-ris-workspace .metric-value {
    font-size: 1.55rem;
    font-weight: 700;
    color: #12233a;
    margin-top: 0.25rem;
}

.fuel-ris-workspace .fuel-ris-table-wrap {
    max-height: 68vh;
    overflow: auto;
}

.fuel-ris-workspace .fuel-ris-table thead th {
    position: sticky;
    top: 0;
    background: #f7f9fc;
    z-index: 2;
    white-space: nowrap;
}

.fuel-ris-workspace .fuel-ris-table td {
    min-width: 110px;
}

.fuel-ris-workspace .fuel-ris-table td:nth-child(6) {
    min-width: 240px;
}

.fuel-ris-workspace .fuel-import-card {
    top: 92px;
}

@media (max-width: 1199px) {
    .fuel-ris-workspace .fuel-import-card {
        position: static !important;
    }
}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
