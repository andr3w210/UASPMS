<?php
require_once __DIR__ . '/../spams/app/config/constants.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$tripDb = new mysqli(TRIP_DB_HOST, TRIP_DB_USER, TRIP_DB_PASS, TRIP_DB_NAME);
$tripDb->set_charset('utf8mb4');

$sql = 'SELECT id, station_name, fuel_type, liters_purchased, liters_consumed, quantity, unit, purpose, driver_name, remarks
        FROM trip_fuel_ris_entries
        ORDER BY id ASC';
$res = $tripDb->query($sql);

$update = $tripDb->prepare('UPDATE trip_fuel_ris_entries SET quantity = ?, unit = ?, purpose = ?, driver_name = ? WHERE id = ?');
if (!$update) {
    throw new RuntimeException('Unable to prepare update statement.');
}

$updated = 0;
while ($row = $res->fetch_assoc()) {
    $id = (int) $row['id'];
    $quantity = isset($row['quantity']) ? (float) $row['quantity'] : 0.0;
    if ($quantity <= 0) {
        $quantity = (float) ($row['liters_purchased'] ?? 0);
        if ($quantity <= 0) {
            $quantity = (float) ($row['liters_consumed'] ?? 0);
        }
    }

    $unit = trim((string) ($row['unit'] ?? ''));
    if ($unit === '') {
        $unit = 'Liter';
    }

    $purpose = trim((string) ($row['purpose'] ?? ''));
    $driver = trim((string) ($row['driver_name'] ?? ''));
    $remarks = trim((string) ($row['remarks'] ?? ''));

    if ($purpose === '' && preg_match('/Purpose:\s*([^|]+)/i', $remarks, $m)) {
        $purpose = trim((string) $m[1]);
    }
    if ($driver === '' && preg_match('/Driver:\s*([^|]+)/i', $remarks, $m)) {
        $driver = trim((string) $m[1]);
    }

    $update->bind_param('dsssi', $quantity, $unit, $purpose, $driver, $id);
    if ($update->execute()) {
        $updated++;
    }
}

$update->close();

echo "Backfill complete. Updated rows: {$updated}\n";
