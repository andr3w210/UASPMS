<?php

function trip_ticket_month_prefix(?string $dateValue = null): string
{
    $baseDate = $dateValue && strtotime($dateValue) ? $dateValue : date('Y-m-d');
    return date('Y-m', strtotime($baseDate));
}

function trip_ticket_year_prefix(?string $dateValue = null): string
{
    $baseDate = $dateValue && strtotime($dateValue) ? $dateValue : date('Y-m-d');
    return date('Y', strtotime($baseDate));
}

function trip_ticket_next_number(mysqli $tripDb, ?string $dateValue = null): string
{
    $yearPrefix = trip_ticket_year_prefix($dateValue);
    $monthPrefix = trip_ticket_month_prefix($dateValue);
    $series = 1;

    $stmt = $tripDb->prepare("
        SELECT trip_ticket_no
        FROM trip_tickets
        WHERE trip_ticket_no LIKE CONCAT(?, '-%')
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('s', $yearPrefix);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && preg_match('/(\d{4})-\d{2}-(\d+)$/', (string) $row['trip_ticket_no'], $matches)) {
            $series = ((int) $matches[2]) + 1;
        }
    }

    return $monthPrefix . '-' . str_pad((string) $series, 4, '0', STR_PAD_LEFT);
}

function trip_ris_next_number(mysqli $tripDb, ?string $dateValue = null): string
{
    return 'RIS-' . trip_ticket_next_number($tripDb, $dateValue);
}

function trip_ticket_current_user_context(mysqli $mainDb): ?array
{
    $userId = current_user_id();
    if (!$userId) {
        return null;
    }

    $stmt = $mainDb->prepare("
        SELECT
            u.id AS user_id,
            u.full_name,
            u.employee_id,
            e.name_prefix,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name,
            e.position_title,
            o.id AS office_id,
            o.office_name,
            rc.id AS responsibility_code_id,
            rc.code AS responsibility_code
        FROM users u
        LEFT JOIN employees e ON e.id = u.employee_id
        LEFT JOIN offices o ON o.id = e.office_id
        LEFT JOIN responsibility_codes rc ON rc.id = e.responsibility_code_id
        WHERE u.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $displayName = trim((string) ($row['full_name'] ?? ''));
    if ($displayName === '' && !empty($row['employee_id'])) {
        $displayName = employee_display_name($row);
    }

    return [
        'user_id' => (int) ($row['user_id'] ?? 0),
        'employee_id' => (int) ($row['employee_id'] ?? 0),
        'name' => $displayName,
        'position_title' => trim((string) ($row['position_title'] ?? '')),
        'office_id' => (int) ($row['office_id'] ?? 0),
        'office_name' => trim((string) ($row['office_name'] ?? '')),
        'responsibility_code_id' => (int) ($row['responsibility_code_id'] ?? 0),
        'responsibility_code' => trim((string) ($row['responsibility_code'] ?? '')),
    ];
}

function trip_ticket_employee_context(mysqli $mainDb, int $employeeId): ?array
{
    if ($employeeId <= 0) {
        return null;
    }

    $stmt = $mainDb->prepare("
        SELECT
            e.id,
            e.employee_no,
            e.name_prefix,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name,
            e.position_title,
            e.office_id,
            o.office_name,
            rc.id AS responsibility_code_id,
            rc.code AS responsibility_code
        FROM employees e
        LEFT JOIN offices o ON o.id = e.office_id
        LEFT JOIN responsibility_codes rc ON rc.id = e.responsibility_code_id
        WHERE e.id = ? AND e.is_active = 1
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'employee_id' => (int) $row['id'],
        'employee_no' => trim((string) ($row['employee_no'] ?? '')),
        'name' => employee_display_name($row),
        'position_title' => trim((string) ($row['position_title'] ?? '')),
        'office_id' => (int) ($row['office_id'] ?? 0),
        'office_name' => trim((string) ($row['office_name'] ?? '')),
        'responsibility_code_id' => (int) ($row['responsibility_code_id'] ?? 0),
        'responsibility_code' => trim((string) ($row['responsibility_code'] ?? '')),
    ];
}

function trip_ticket_format_passengers(array $rows): array
{
    $passengers = [];
    foreach ($rows as $index => $row) {
        $name = trim((string) ($row['passenger_name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $passengers[] = [
            'passenger_name' => $name,
            'sort_order' => $index + 1,
        ];
    }

    return $passengers;
}
