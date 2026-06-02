<?php

function module_series_defaults(): array
{
    return [
        'departments' => ['prefix' => 'DEP', 'use_year' => true, 'padding' => 4],
        'offices' => ['prefix' => 'OFF', 'use_year' => true, 'padding' => 4],
        'employees' => ['prefix' => 'EMP', 'use_year' => true, 'padding' => 4],
        'suppliers' => ['prefix' => 'SUP', 'use_year' => true, 'padding' => 4],
        'funds' => ['prefix' => 'FND', 'use_year' => true, 'padding' => 4],
        'classifications' => ['prefix' => 'CLS', 'use_year' => true, 'padding' => 4],
        'mode_of_procurements' => ['prefix' => 'MOP', 'use_year' => true, 'padding' => 4],
        'unit_of_measures' => ['prefix' => 'UOM', 'use_year' => true, 'padding' => 4],
        'brands' => ['prefix' => 'BRD', 'use_year' => true, 'padding' => 4],
        'models' => ['prefix' => 'MDL', 'use_year' => true, 'padding' => 4],
        'stock_items' => ['prefix' => 'STK', 'use_year' => true, 'padding' => 4],
        'issuances' => ['prefix' => 'ISS', 'use_year' => true, 'padding' => 4],
        'distributions' => ['prefix' => 'DST', 'use_year' => true, 'padding' => 4],
        'purchase_orders' => ['prefix' => 'POREC', 'use_year' => true, 'padding' => 4],
        'po_delivery_extensions' => ['prefix' => 'POEXT', 'use_year' => true, 'padding' => 4],
        'receivings' => ['prefix' => 'RCV', 'use_year' => true, 'padding' => 4],
        'maintenance' => ['prefix' => 'MNT', 'use_year' => true, 'padding' => 4],
        'disposals' => ['prefix' => 'DSP', 'use_year' => true, 'padding' => 4],
        'transfers' => ['prefix' => 'TRF', 'use_year' => true, 'padding' => 4],
        'transfer_batches' => ['prefix' => 'PTR', 'use_year' => true, 'padding' => 4],
        'inventory_counts' => ['prefix' => 'INV', 'use_year' => true, 'padding' => 4],
        'supply_counts' => ['prefix' => 'SCI', 'use_year' => true, 'padding' => 4],
        'stock_adjustments' => ['prefix' => 'ADJ', 'use_year' => true, 'padding' => 4],
        'returns' => ['prefix' => 'RTN', 'use_year' => true, 'padding' => 4],
        'returns_rrpe' => ['prefix' => 'RRPE', 'use_year' => true, 'padding' => 4],
    ];
}

function ensure_series_row(mysqli $db, string $moduleKey): void
{
    $defaults = module_series_defaults()[$moduleKey] ?? null;
    if (!$defaults) {
        return;
    }

    $yearValue = !empty($defaults['use_year']) ? (int) date('Y') : null;
    $stmt = $db->prepare("
        INSERT INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
        VALUES (?, ?, ?, 0, ?)
        ON DUPLICATE KEY UPDATE module_key = module_key
    ");

    if ($stmt) {
        $stmt->bind_param('ssii', $moduleKey, $defaults['prefix'], $yearValue, $defaults['padding']);
        $stmt->execute();
        $stmt->close();
    }
}

function preview_module_code(mysqli $db, string $moduleKey, ?string $customPrefix = null, ?int $customYear = null): string
{
    ensure_series_row($db, $moduleKey);

    $stmt = $db->prepare("SELECT prefix, year_value, current_value, padding_length FROM series_numbers WHERE module_key = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('s', $moduleKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return '';
    }

    $prefix = $customPrefix ?: $row['prefix'];
    $yearValue = $customYear ?? ($row['year_value'] !== null ? (int) $row['year_value'] : null);
    $nextValue = ((int) $row['current_value']) + 1;
    $padding = (int) $row['padding_length'];

    return build_series_code($prefix, $yearValue, $nextValue, $padding);
}

function next_module_code(mysqli $db, string $moduleKey): string
{
    ensure_series_row($db, $moduleKey);

    $stmt = $db->prepare("SELECT prefix, year_value, current_value, padding_length FROM series_numbers WHERE module_key = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('s', $moduleKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return '';
    }

    $prefix = $row['prefix'];
    $yearValue = $row['year_value'] !== null ? (int) $row['year_value'] : null;
    $currentYear = (int) date('Y');
    $padding = (int) $row['padding_length'];

    if ($row['year_value'] !== null && (int) $row['year_value'] !== $currentYear) {
        $resetStmt = $db->prepare("UPDATE series_numbers SET year_value = ?, current_value = 0 WHERE module_key = ?");
        if ($resetStmt) {
            $resetStmt->bind_param('is', $currentYear, $moduleKey);
            $resetStmt->execute();
            $resetStmt->close();
        }

        $yearValue = $currentYear;
    }

    $updateStmt = $db->prepare("UPDATE series_numbers SET current_value = LAST_INSERT_ID(current_value + 1) WHERE module_key = ?");
    if (!$updateStmt) {
        return '';
    }

    $updateStmt->bind_param('s', $moduleKey);
    $updateStmt->execute();
    $updateStmt->close();

    $lastStmt = $db->prepare("SELECT LAST_INSERT_ID() AS last_id");
    if (!$lastStmt) {
        return '';
    }

    $lastStmt->execute();
    $lastResult = $lastStmt->get_result();
    $lastRow = $lastResult ? $lastResult->fetch_assoc() : null;
    $lastStmt->close();

    if (!$lastRow || !isset($lastRow['last_id'])) {
        return '';
    }

    $assigned = (int) $lastRow['last_id'];

    return build_series_code($prefix, $yearValue, $assigned, $padding);
}

function next_year_series_number(mysqli $db, string $moduleKey): string
{
    ensure_series_row($db, $moduleKey);

    $stmt = $db->prepare("SELECT year_value, current_value, padding_length FROM series_numbers WHERE module_key = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('s', $moduleKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return '';
    }

    $currentYear = (int) date('Y');
    $yearValue = $row['year_value'] !== null ? (int) $row['year_value'] : $currentYear;
    if ($yearValue !== $currentYear) {
        $resetStmt = $db->prepare("UPDATE series_numbers SET year_value = ?, current_value = 0 WHERE module_key = ?");
        if ($resetStmt) {
            $resetStmt->bind_param('is', $currentYear, $moduleKey);
            $resetStmt->execute();
            $resetStmt->close();
        }
        $yearValue = $currentYear;
    }

    $updateStmt = $db->prepare("UPDATE series_numbers SET current_value = LAST_INSERT_ID(current_value + 1) WHERE module_key = ?");
    if (!$updateStmt) {
        return '';
    }

    $updateStmt->bind_param('s', $moduleKey);
    $updateStmt->execute();
    $updateStmt->close();

    $lastStmt = $db->prepare("SELECT LAST_INSERT_ID() AS last_id");
    if (!$lastStmt) {
        return '';
    }

    $lastStmt->execute();
    $lastResult = $lastStmt->get_result();
    $lastRow = $lastResult ? $lastResult->fetch_assoc() : null;
    $lastStmt->close();

    $assigned = (int) ($lastRow['last_id'] ?? 0);
    if ($assigned <= 0) {
        return '';
    }

    $padding = (int) ($row['padding_length'] ?? 4);

    return $yearValue . '-' . str_pad((string) $assigned, $padding, '0', STR_PAD_LEFT);
}

function build_series_code(string $prefix, ?int $yearValue, int $number, int $padding): string
{
    $parts = [$prefix];

    if ($yearValue !== null) {
        $parts[] = (string) $yearValue;
    }

    $parts[] = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);

    return implode('-', $parts);
}
